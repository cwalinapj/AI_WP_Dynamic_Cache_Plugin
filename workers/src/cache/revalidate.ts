/**
 * Stale-while-revalidate logic — serves a (potentially stale) cached response
 * immediately and refreshes the cache in the background using
 * `ExecutionContext.waitUntil()`.
 */

import type { Env } from '../index';
import { buildCacheKey, buildR2Key } from './key';
import { getPolicyForRequest } from './policy';
import { putToEdgeCache } from './edgeCache';
import { putToR2 } from './r2Cache';

/**
 * Kicks off a background revalidation of the given request.
 *
 * The fresh response from origin is stored in both the Edge cache and R2
 * so subsequent requests are served from cache.
 */
export function revalidateInBackground(
  ctx: ExecutionContext,
  request: Request,
  env: Env,
): void {
  ctx.waitUntil(
    (async () => {
      try {
        const response = await fetchFromOrigin(request, env);
        if (!response.ok) return;

        const policy = await getPolicyForRequest(request, env);
        if (policy.ttl <= 0 || policy.bypass) return;

        const body = await response.text();
        const cacheKey = buildCacheKey(request);
        const r2Key = await buildR2Key(cacheKey);
        const contentType = response.headers.get('Content-Type') ?? 'text/html';

        // Persist to both tiers in parallel
        await Promise.all([
          putToEdgeCache(
            request,
            new Response(body, { status: response.status, headers: response.headers }),
            policy.ttl,
          ),
          putToR2(env.CACHE_BUCKET, r2Key, body, {
            tags: policy.tags,
            ttl: policy.ttl,
            createdAt: Date.now(),
            contentType,
          }),
        ]);
      } catch {
        // Background revalidation failures are silently swallowed so they
        // never affect the already-served cached response.
      }
    })(),
  );
}

/**
 * Fetches the canonical response from origin.
 *
 * Strips `X-Forwarded-For` to avoid forwarding client IPs to the origin and
 * adds a `Cache-Control: no-store` header so origin doesn't accidentally
 * cache a Worker fetch.
 */
export async function fetchFromOrigin(request: Request, _env: Env): Promise<Response> {
  const originRequest = new Request(request.url, {
    method: request.method,
    headers: (() => {
      const h = new Headers(request.headers);
      h.delete('X-Forwarded-For');
      h.set('Cache-Control', 'no-store');
      h.set('X-Worker-Fetch', '1');
      return h;
    })(),
    body: request.method !== 'GET' && request.method !== 'HEAD' ? request.body : null,
    redirect: 'follow',
  });

  return fetch(originRequest);
}
