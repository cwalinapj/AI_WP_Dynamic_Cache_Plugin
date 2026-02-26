/**
 * Idempotency key handling — ensures that duplicate POST requests (e.g. due
 * to network retries) produce exactly the same response without re-executing
 * the underlying side effects.
 */

const KV_IDEMPOTENCY_PREFIX = 'idempotency:';
const TTL_SECONDS = 86_400; // 24 hours

interface StoredResponse {
  status: number;
  headers: Record<string, string>;
  body: string;
}

/**
 * Returns the cached response for `key` if one exists; otherwise executes
 * `handler`, caches its result for 24 hours, and returns it.
 *
 * If `key` is empty the handler is always executed without caching.
 *
 * @param kv      KV namespace for idempotency record storage.
 * @param key     Value of the `X-Idempotency-Key` request header.
 * @param handler Async function that performs the actual operation.
 */
export async function getOrSetIdempotencyResult(
  kv: KVNamespace,
  key: string,
  handler: () => Promise<Response>,
): Promise<Response> {
  if (!key) {
    return handler();
  }

  const kvKey = `${KV_IDEMPOTENCY_PREFIX}${key}`;
  const cached = await kv.get<StoredResponse>(kvKey, 'json');

  if (cached !== null) {
    return new Response(cached.body, {
      status: cached.status,
      headers: { ...cached.headers, 'X-Idempotent-Replayed': 'true' },
    });
  }

  const response = await handler();
  const body = await response.text();

  const stored: StoredResponse = {
    status: response.status,
    headers: Object.fromEntries(response.headers.entries()),
    body,
  };

  await kv.put(kvKey, JSON.stringify(stored), { expirationTtl: TTL_SECONDS });

  return new Response(body, {
    status: response.status,
    headers: response.headers,
  });
}
