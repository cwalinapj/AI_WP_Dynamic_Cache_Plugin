import { describe, it, expect, beforeEach } from 'vitest';
import { verifySignature, hashBody } from '../src/auth/verifySignature';

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

const SECRET = 'test-signing-secret-32-bytes-ok!';

async function makeSignedRequest(
  overrides: {
    method?: string;
    url?: string;
    body?: string;
    secret?: string;
    timestamp?: string;
    nonce?: string;
  } = {},
): Promise<Request> {
  const method = overrides.method ?? 'POST';
  const url = overrides.url ?? 'https://worker.example.com/api/purge';
  const body = overrides.body ?? JSON.stringify({ tags: ['post-1'] });
  const secret = overrides.secret ?? SECRET;
  const timestamp =
    overrides.timestamp ?? String(Math.floor(Date.now() / 1000));
  const nonce = overrides.nonce ?? crypto.randomUUID();

  const bodyHash = await hashBody(body);
  const canonical = [method, url, timestamp, nonce, bodyHash].join('\n');

  const encoder = new TextEncoder();
  const keyMaterial = await crypto.subtle.importKey(
    'raw',
    encoder.encode(secret),
    { name: 'HMAC', hash: 'SHA-256' },
    false,
    ['sign'],
  );

  const sigBuffer = await crypto.subtle.sign('HMAC', keyMaterial, encoder.encode(canonical));
  const signature = Array.from(new Uint8Array(sigBuffer))
    .map((b) => b.toString(16).padStart(2, '0'))
    .join('');

  return new Request(url, {
    method,
    headers: {
      'Content-Type': 'application/json',
      'X-Timestamp': timestamp,
      'X-Nonce': nonce,
      'X-Signature': signature,
    },
    body,
  });
}

// ---------------------------------------------------------------------------
// Tests
// ---------------------------------------------------------------------------

describe('verifySignature', () => {
  it('accepts a valid signature', async () => {
    const request = await makeSignedRequest();
    expect(await verifySignature(request, SECRET)).toBe(true);
  });

  it('rejects an expired timestamp (> 300 s old)', async () => {
    const oldTimestamp = String(Math.floor(Date.now() / 1000) - 301);
    const request = await makeSignedRequest({ timestamp: oldTimestamp });
    expect(await verifySignature(request, SECRET)).toBe(false);
  });

  it('rejects a timestamp from the future beyond the window', async () => {
    const futureTimestamp = String(Math.floor(Date.now() / 1000) + 301);
    const request = await makeSignedRequest({ timestamp: futureTimestamp });
    expect(await verifySignature(request, SECRET)).toBe(false);
  });

  it('rejects a request signed with the wrong secret', async () => {
    const request = await makeSignedRequest({ secret: 'wrong-secret-value!!' });
    expect(await verifySignature(request, SECRET)).toBe(false);
  });

  it('rejects a request with no X-Signature header', async () => {
    const body = JSON.stringify({ tags: ['post-1'] });
    const request = await makeSignedRequest({ body });
    const withoutSig = new Request(request.url, {
      method: request.method,
      headers: (() => {
        const h = new Headers(request.headers);
        h.delete('X-Signature');
        return h;
      })(),
      body,
    });
    expect(await verifySignature(withoutSig, SECRET)).toBe(false);
  });

  it('rejects a request missing X-Timestamp', async () => {
    const body = JSON.stringify({ tags: ['post-1'] });
    const request = await makeSignedRequest({ body });
    const withoutTs = new Request(request.url, {
      method: request.method,
      headers: (() => {
        const h = new Headers(request.headers);
        h.delete('X-Timestamp');
        return h;
      })(),
      body,
    });
    expect(await verifySignature(withoutTs, SECRET)).toBe(false);
  });

  it('rejects a request missing X-Nonce', async () => {
    const body = JSON.stringify({ tags: ['post-1'] });
    const request = await makeSignedRequest({ body });
    const withoutNonce = new Request(request.url, {
      method: request.method,
      headers: (() => {
        const h = new Headers(request.headers);
        h.delete('X-Nonce');
        return h;
      })(),
      body,
    });
    expect(await verifySignature(withoutNonce, SECRET)).toBe(false);
  });
});

describe('hashBody', () => {
  it('returns a 64-char hex string for any input', async () => {
    const hash = await hashBody('hello world');
    expect(hash).toHaveLength(64);
    expect(hash).toMatch(/^[0-9a-f]+$/);
  });

  it('is deterministic', async () => {
    expect(await hashBody('same')).toBe(await hashBody('same'));
  });

  it('produces different hashes for different inputs', async () => {
    expect(await hashBody('a')).not.toBe(await hashBody('b'));
  });

  it('handles an empty string', async () => {
    const hash = await hashBody('');
    expect(hash).toHaveLength(64);
  });
});
