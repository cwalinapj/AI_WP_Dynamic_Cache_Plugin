/**
 * Nonce store — tracks seen nonces in KV to prevent replay attacks.
 * Each nonce is stored with a TTL matching the signature window so it
 * auto-expires once it can no longer be replayed.
 */

const KV_NONCE_PREFIX = 'nonce:';

/**
 * Checks whether `nonce` has been seen before and, if not, records it.
 *
 * @param kv         The KV namespace used for nonce storage.
 * @param nonce      The nonce string extracted from `X-Nonce`.
 * @param ttlSeconds How long (in seconds) to keep the nonce record.
 * @returns `true` if the nonce is new and was successfully stored;
 *          `false` if the nonce was already present (replay detected).
 */
export async function checkAndStoreNonce(
  kv: KVNamespace,
  nonce: string,
  ttlSeconds: number,
): Promise<boolean> {
  if (!nonce) return false;

  const key = `${KV_NONCE_PREFIX}${nonce}`;
  const existing = await kv.get(key);

  if (existing !== null) {
    // Already seen — replay
    return false;
  }

  await kv.put(key, '1', { expirationTtl: ttlSeconds });
  return true;
}
