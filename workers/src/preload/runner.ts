/**
 * Preload runner — fetches URLs in the background and warms both the Edge
 * cache and R2 with the responses.
 */

import type { Env } from '../index';
import type { PreloadJob } from './planner';
import { buildCacheKey, buildR2Key } from '../cache/key';
import { getPolicyForRequest } from '../cache/policy';
import { putToEdgeCache } from '../cache/edgeCache';
import { putToR2 } from '../cache/r2Cache';

const MAX_URLS_PER_INVOCATION = 100;
const MAX_CONCURRENCY = 3;

/**
 * Schedules all preload jobs to run in the background via
 * `ExecutionContext.waitUntil()`.
 *
 * Limits:
 * - At most {@link MAX_URLS_PER_INVOCATION} URLs per invocation.
 * - At most {@link MAX_CONCURRENCY} in-flight fetches at once.
 */
export function runPreloadJobs(
  jobs: PreloadJob[],
  env: Env,
  ctx: ExecutionContext,
): void {
  ctx.waitUntil(executePreloads(jobs.slice(0, MAX_URLS_PER_INVOCATION), env));
}

async function executePreloads(jobs: PreloadJob[], env: Env): Promise<void> {
  // Process in batches of MAX_CONCURRENCY
  for (let i = 0; i < jobs.length; i += MAX_CONCURRENCY) {
    const batch = jobs.slice(i, i + MAX_CONCURRENCY);
    await Promise.allSettled(batch.map((job) => preloadOne(job.url, env)));
  }
}

async function preloadOne(url: string, env: Env): Promise<void> {
  const request = new Request(url, {
    headers: { 'X-Worker-Preload': '1', 'Cache-Control': 'no-store' },
  });

  let response: Response;
  try {
    response = await fetch(request);
  } catch {
    return; // Network error — skip silently
  }

  if (!response.ok) return;

  const policy = await getPolicyForRequest(request, env);
  if (policy.bypass || policy.ttl <= 0) return;

  const body = await response.text();
  const contentType = response.headers.get('Content-Type') ?? 'text/html';
  const cacheKey = buildCacheKey(request);
  const r2Key = await buildR2Key(cacheKey);

  await Promise.all([
    putToEdgeCache(
      new Request(url),
      new Response(body, { headers: { 'Content-Type': contentType } }),
      policy.ttl,
    ),
    putToR2(env.CACHE_BUCKET, r2Key, body, {
      tags: policy.tags,
      ttl: policy.ttl,
      createdAt: Date.now(),
      contentType,
    }),
  ]);
}
