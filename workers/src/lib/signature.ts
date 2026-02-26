import type { Env } from './types';

const UUID_V4_REGEX =
  /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i;

export interface VerifiedRequest {
  ok: true;
  pluginId: string;
}

export interface VerifyError {
  ok: false;
  status: number;
  error: string;
}

export async function verifySignedRequest(
  request: Request,
  rawBody: ArrayBuffer,
  path: string,
  env: Env,
): Promise<VerifiedRequest | VerifyError> {
  const pluginId = request.headers.get('X-Plugin-Id')?.trim() ?? '';
  const timestampRaw = request.headers.get('X-Plugin-Timestamp')?.trim() ?? '';
  const nonce = request.headers.get('X-Plugin-Nonce')?.trim() ?? '';
  const signature = (request.headers.get('X-Plugin-Signature')?.trim() ?? '').toLowerCase();

  if (!env.WP_PLUGIN_SHARED_SECRET) {
    return { ok: false, status: 500, error: 'worker_missing_shared_secret' };
  }

  if (pluginId === '') {
    return { ok: false, status: 401, error: 'missing_plugin_id' };
  }

  if (!UUID_V4_REGEX.test(nonce)) {
    return { ok: false, status: 401, error: 'invalid_nonce' };
  }

  const timestamp = Number.parseInt(timestampRaw, 10);
  if (!Number.isInteger(timestamp)) {
    return { ok: false, status: 401, error: 'invalid_timestamp' };
  }

  const replayWindow = Number.parseInt(env.REPLAY_WINDOW_SECONDS ?? '300', 10);
  const now = Math.floor(Date.now() / 1000);
  if (Math.abs(now - timestamp) > replayWindow) {
    return { ok: false, status: 401, error: 'timestamp_out_of_window' };
  }

  if (!/^[0-9a-f]{64}$/.test(signature)) {
    return { ok: false, status: 401, error: 'invalid_signature_format' };
  }

  if (path.startsWith('/plugin/wp/sandbox/')) {
    const capability = request.headers.get('X-Capability-Token')?.trim() ?? '';
    if (!env.CAP_TOKEN_SANDBOX_WRITE) {
      return { ok: false, status: 500, error: 'worker_missing_sandbox_capability_token' };
    }
    if (capability === '') {
      return { ok: false, status: 403, error: 'missing_capability_token' };
    }
    if (!timingSafeEqual(capability, env.CAP_TOKEN_SANDBOX_WRITE)) {
      return { ok: false, status: 403, error: 'invalid_capability_token' };
    }
  }

  const canonical = `${timestamp}.${nonce}.${request.method.toUpperCase()}.${path}.${await sha256Hex(rawBody)}`;
  const expected = await hmacSha256Hex(env.WP_PLUGIN_SHARED_SECRET, canonical);
  if (!timingSafeEqual(signature, expected)) {
    return { ok: false, status: 401, error: 'signature_mismatch' };
  }

  return { ok: true, pluginId };
}

async function sha256Hex(input: ArrayBuffer): Promise<string> {
  const digest = await crypto.subtle.digest('SHA-256', input);
  return toHex(new Uint8Array(digest));
}

async function hmacSha256Hex(secret: string, message: string): Promise<string> {
  const key = await crypto.subtle.importKey(
    'raw',
    new TextEncoder().encode(secret),
    { name: 'HMAC', hash: 'SHA-256' },
    false,
    ['sign'],
  );

  const signature = await crypto.subtle.sign('HMAC', key, new TextEncoder().encode(message));
  return toHex(new Uint8Array(signature));
}

function toHex(buffer: Uint8Array): string {
  return Array.from(buffer)
    .map((v) => v.toString(16).padStart(2, '0'))
    .join('');
}

function timingSafeEqual(a: string, b: string): boolean {
  if (a.length !== b.length) {
    return false;
  }
  let mismatch = 0;
  for (let i = 0; i < a.length; i += 1) {
    mismatch |= a.charCodeAt(i) ^ b.charCodeAt(i);
  }
  return mismatch === 0;
}
