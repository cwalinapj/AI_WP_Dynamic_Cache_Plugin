/**
 * Cloudflare Edge cache operations using the Cache API (`caches.default`).
 * The Edge cache is the fastest tier — a cache HIT here avoids hitting
 * R2 or origin entirely.
 */

const CACHE_HIT_HEADER = 'X-Cache';
const CACHE_CONTROL_HEADER = 'Cache-Control';

/**
 * Attempts to serve a response from the Cloudflare Edge cache.
 *
 * @returns The cached {@link Response} with an `X-Cache: HIT-EDGE` header,
 *          or `null` if the entry is absent.
 */
export async function getFromEdgeCache(
  request: Request,
  _ttl: number,
): Promise<Response | null> {
  const cache = caches.default;
  const cached = await cache.match(request);
  if (!cached) return null;

  // Attach a hit marker without mutating the original response
  const headers = new Headers(cached.headers);
  headers.set(CACHE_HIT_HEADER, 'HIT-EDGE');

  return new Response(cached.body, { status: cached.status, headers });
}

/**
 * Stores a response in the Cloudflare Edge cache.
 *
 * The `Cache-Control` header is set (or overwritten) so Cloudflare honours
 * the TTL derived from the cache policy.
 *
 * @param ttl Cache lifetime in seconds.
 */
export async function putToEdgeCache(
  request: Request,
  response: Response,
  ttl: number,
): Promise<void> {
  if (ttl <= 0) return;

  const cache = caches.default;
  const headers = new Headers(response.headers);
  headers.set(CACHE_CONTROL_HEADER, `public, max-age=${ttl}`);

  await cache.put(request, new Response(response.body, { status: response.status, headers }));
}

/**
 * Removes one or more URLs from the Cloudflare Edge cache.
 *
 * @param urls Absolute URLs to purge.
 */
export async function purgeFromEdgeCache(urls: string[]): Promise<void> {
  const cache = caches.default;
  await Promise.all(urls.map((url) => cache.delete(new Request(url))));
}
