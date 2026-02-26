/**
 * Cache tag operations — maintains a KV-backed inverted index that maps
 * cache tags to the set of R2 keys that carry those tags.
 *
 * This enables efficient tag-based purging: instead of scanning all objects,
 * a single KV read returns every key that must be purged.
 */

const TAG_KEY_PREFIX = 'tag:';

/**
 * Associates a set of cache tags with a given R2 cache key.
 *
 * For every tag the cache key is appended to the tag's index entry in KV.
 * Duplicate keys within a single index are deduplicated.
 *
 * @param kv       KV namespace for tag index storage.
 * @param cacheKey The R2 object key to register (result of `buildR2Key`).
 * @param tags     Cache tags to associate with the key.
 */
export async function storeTags(
  kv: KVNamespace,
  cacheKey: string,
  tags: string[],
): Promise<void> {
  await Promise.all(
    tags.map(async (tag) => {
      const kvKey = `${TAG_KEY_PREFIX}${tag}`;
      const existing = (await kv.get<string[]>(kvKey, 'json')) ?? [];
      if (!existing.includes(cacheKey)) {
        existing.push(cacheKey);
        await kv.put(kvKey, JSON.stringify(existing));
      }
    }),
  );
}

/**
 * Returns all cache keys associated with a given tag.
 *
 * @param kv  KV namespace for tag index storage.
 * @param tag The cache tag to look up.
 * @returns   Array of R2 object keys (may be empty).
 */
export async function getKeysForTag(kv: KVNamespace, tag: string): Promise<string[]> {
  const kvKey = `${TAG_KEY_PREFIX}${tag}`;
  return (await kv.get<string[]>(kvKey, 'json')) ?? [];
}

/**
 * Removes a cache key from the index entries for every supplied tag.
 *
 * Call this when an object is deleted from R2 so stale keys don't
 * accumulate in the tag indexes.
 *
 * @param kv       KV namespace for tag index storage.
 * @param cacheKey The R2 object key to remove from indexes.
 * @param tags     Tags whose indexes should be updated.
 */
export async function removeTagIndex(
  kv: KVNamespace,
  cacheKey: string,
  tags: string[],
): Promise<void> {
  await Promise.all(
    tags.map(async (tag) => {
      const kvKey = `${TAG_KEY_PREFIX}${tag}`;
      const existing = (await kv.get<string[]>(kvKey, 'json')) ?? [];
      const updated = existing.filter((k) => k !== cacheKey);

      if (updated.length === 0) {
        await kv.delete(kvKey);
      } else {
        await kv.put(kvKey, JSON.stringify(updated));
      }
    }),
  );
}
