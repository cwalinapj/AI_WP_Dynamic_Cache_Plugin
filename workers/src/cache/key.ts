/**
 * Cache key construction — produces stable, normalised keys that are
 * independent of tracking parameters and case variations in the URL.
 */

/** Query parameters that carry no cache-relevant information. */
const BLOCKED_PARAMS = new Set([
  'utm_source',
  'utm_medium',
  'utm_campaign',
  'utm_content',
  'utm_term',
  'fbclid',
  'gclid',
]);

/**
 * Builds a canonical cache key from a request URL.
 *
 * Normalisation steps:
 * 1. Lowercases scheme and host.
 * 2. Strips blocked query parameters (UTM / click-tracking).
 * 3. Sorts remaining query parameters alphabetically.
 *
 * @returns A canonical URL string suitable for use as a cache key.
 */
export function buildCacheKey(request: Request): string {
  const url = new URL(request.url);

  // Normalise scheme + host
  url.protocol = url.protocol.toLowerCase();
  url.hostname = url.hostname.toLowerCase();

  // Remove blocked params and sort the rest
  const toDelete: string[] = [];
  for (const name of url.searchParams.keys()) {
    if (BLOCKED_PARAMS.has(name)) {
      toDelete.push(name);
    }
  }
  for (const name of toDelete) {
    url.searchParams.delete(name);
  }
  url.searchParams.sort();

  return url.toString();
}

/**
 * Derives an R2 object key from a canonical cache key.
 *
 * The key is the SHA-256 hash of the cache key URL, split into a
 * directory structure for efficient prefix-based listing:
 * `{first2}/{next2}/{remaining}.cache`
 *
 * @returns An R2 path string, e.g. `ab/cd/ef01234…rest.cache`.
 */
export async function buildR2Key(cacheKey: string): Promise<string> {
  const encoder = new TextEncoder();
  const hashBuffer = await crypto.subtle.digest('SHA-256', encoder.encode(cacheKey));
  const hex = Array.from(new Uint8Array(hashBuffer))
    .map((b) => b.toString(16).padStart(2, '0'))
    .join('');

  return `${hex.slice(0, 2)}/${hex.slice(2, 4)}/${hex.slice(4)}.cache`;
}
