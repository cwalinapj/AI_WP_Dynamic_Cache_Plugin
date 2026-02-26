/**
 * HMAC-SHA256 request signature verification using the WebCrypto API.
 * The WordPress plugin signs each request so the Worker can authenticate it
 * without storing long-lived credentials in plaintext.
 */

const SIGNATURE_TTL_SECONDS = 300;

/**
 * Returns the SHA-256 hex digest of the given string.
 */
export async function hashBody(body: string): Promise<string> {
  const encoder = new TextEncoder();
  const buffer = await crypto.subtle.digest('SHA-256', encoder.encode(body));
  return bufferToHex(buffer);
}

/**
 * Verifies the HMAC-SHA256 signature attached to a signed Worker request.
 *
 * Expected headers:
 *   X-Timestamp  — Unix seconds (string)
 *   X-Nonce      — Random string to prevent replays
 *   X-Signature  — Hex-encoded HMAC-SHA256 over the canonical string
 *
 * Canonical string format:
 *   METHOD\nURL\nTIMESTAMP\nNONCE\nBODY_HASH
 *
 * @returns `true` if the signature is valid and the timestamp is fresh.
 */
export async function verifySignature(request: Request, secret: string): Promise<boolean> {
  const timestamp = request.headers.get('X-Timestamp');
  const nonce = request.headers.get('X-Nonce');
  const signature = request.headers.get('X-Signature');

  if (!timestamp || !nonce || !signature) {
    return false;
  }

  // Reject stale requests
  const ts = parseInt(timestamp, 10);
  if (isNaN(ts)) return false;
  const nowSeconds = Math.floor(Date.now() / 1000);
  if (Math.abs(nowSeconds - ts) > SIGNATURE_TTL_SECONDS) {
    return false;
  }

  // Read body (may be empty for GET requests)
  const bodyText = await request.text();
  const bodyHash = await hashBody(bodyText);

  const canonical = [request.method, request.url, timestamp, nonce, bodyHash].join('\n');

  const encoder = new TextEncoder();
  const keyMaterial = await crypto.subtle.importKey(
    'raw',
    encoder.encode(secret),
    { name: 'HMAC', hash: 'SHA-256' },
    false,
    ['sign', 'verify'],
  );

  // Decode the hex signature to a byte buffer for timing-safe comparison
  let sigBuffer: ArrayBuffer;
  try {
    sigBuffer = hexToBuffer(signature);
  } catch {
    return false;
  }

  return crypto.subtle.verify('HMAC', keyMaterial, sigBuffer, encoder.encode(canonical));
}

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function bufferToHex(buffer: ArrayBuffer): string {
  return Array.from(new Uint8Array(buffer))
    .map((b) => b.toString(16).padStart(2, '0'))
    .join('');
}

function hexToBuffer(hex: string): ArrayBuffer {
  if (hex.length % 2 !== 0) throw new Error('Invalid hex string');
  const bytes = new Uint8Array(hex.length / 2);
  for (let i = 0; i < bytes.length; i++) {
    const byte = parseInt(hex.slice(i * 2, i * 2 + 2), 16);
    if (isNaN(byte)) throw new Error('Invalid hex character');
    bytes[i] = byte;
  }
  return bytes.buffer;
}
