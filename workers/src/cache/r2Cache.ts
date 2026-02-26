/**
 * R2 object storage cache — second cache tier behind the Cloudflare Edge.
 * Objects are stored with custom metadata so TTL and cache tags can be
 * evaluated without fetching the full body.
 */

/** Custom metadata stored alongside every R2 cache object. */
export interface R2CacheMetadata {
  /** Cache tags associated with the cached response. */
  tags: string[];
  /** Cache lifetime in seconds at write time. */
  ttl: number;
  /** Unix millisecond timestamp when the object was stored. */
  createdAt: number;
  /** MIME type of the cached response body. */
  contentType: string;
}

/**
 * Retrieves a cached object from R2.
 *
 * Returns `null` when the object does not exist or has passed its TTL.
 */
export async function getFromR2(
  bucket: R2Bucket,
  key: string,
): Promise<{ body: string; metadata: R2CacheMetadata } | null> {
  const obj = await bucket.get(key);
  if (!obj) return null;

  const raw = obj.customMetadata as Partial<R2CacheMetadata> | undefined;
  if (!raw) return null;

  const createdAt = raw.createdAt ?? 0;
  const ttl = raw.ttl ?? 0;

  // Honour TTL — treat expired objects as misses
  if (ttl > 0 && Date.now() > createdAt + ttl * 1000) {
    return null;
  }

  const metadata: R2CacheMetadata = {
    tags: Array.isArray(raw.tags) ? raw.tags : [],
    ttl,
    createdAt,
    contentType: raw.contentType ?? 'text/html; charset=utf-8',
  };

  return { body: await obj.text(), metadata };
}

/**
 * Stores a response body in R2 with associated metadata.
 */
export async function putToR2(
  bucket: R2Bucket,
  key: string,
  body: string,
  metadata: R2CacheMetadata,
): Promise<void> {
  const customMetadata: Record<string, string> = {
    tags: JSON.stringify(metadata.tags),
    ttl: String(metadata.ttl),
    createdAt: String(metadata.createdAt),
    contentType: metadata.contentType,
  };

  await bucket.put(key, body, {
    httpMetadata: { contentType: metadata.contentType },
    customMetadata,
  });
}

/**
 * Deletes a single object from R2 by key.
 */
export async function deleteFromR2(bucket: R2Bucket, key: string): Promise<void> {
  await bucket.delete(key);
}

/**
 * Purges all R2 objects associated with a given cache tag.
 *
 * The tag-to-key index is maintained in KV (see `cache/tags.ts`).
 *
 * @returns The number of objects deleted.
 */
export async function purgeR2ByTag(
  bucket: R2Bucket,
  kv: KVNamespace,
  tag: string,
): Promise<number> {
  const keys = await kv.get<string[]>(`tag:${tag}`, 'json');
  if (!keys || keys.length === 0) return 0;

  // R2 batch delete: delete objects in parallel, up to 20 at a time
  const BATCH = 20;
  let deleted = 0;

  for (let i = 0; i < keys.length; i += BATCH) {
    const batch = keys.slice(i, i + BATCH);
    await Promise.all(batch.map((k) => bucket.delete(k)));
    deleted += batch.length;
  }

  // Clean up the KV index
  await kv.delete(`tag:${tag}`);

  return deleted;
}
