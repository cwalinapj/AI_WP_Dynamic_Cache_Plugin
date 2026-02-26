import type { Env } from './index';
import { verifySignature } from './auth/verifySignature';
import { checkAndStoreNonce } from './auth/nonceStore';
import { getOrSetIdempotencyResult } from './auth/idempotency';
import { buildCacheKey, buildR2Key } from './cache/key';
import { getPolicyForRequest } from './cache/policy';
import { getFromEdgeCache, putToEdgeCache } from './cache/edgeCache';
import { getFromR2, putToR2 } from './cache/r2Cache';
import { revalidateInBackground, fetchFromOrigin } from './cache/revalidate';
import { handlePluginApiRequest } from './routes/pluginApi';

// ---------------------------------------------------------------------------
// Lazy-loaded API handlers (imported inline to keep top-level bundle lean)
// ---------------------------------------------------------------------------

async function HeartbeatHandler(request: Request, env: Env): Promise<Response> {
  const siteId = new URL(request.url).searchParams.get('site_id') ?? 'unknown';
  const version = new URL(request.url).searchParams.get('version') ?? '';

  await env.DB.prepare(
    `INSERT INTO heartbeats (site_id, last_seen, version, worker_url)
     VALUES (?1, ?2, ?3, ?4)
     ON CONFLICT(site_id) DO UPDATE SET last_seen = excluded.last_seen,
       version = excluded.version, worker_url = excluded.worker_url`,
  )
    .bind(siteId, Date.now(), version, request.url)
    .run();

  return Response.json({ ok: true, ts: Date.now() });
}

async function SignalsHandler(request: Request, env: Env): Promise<Response> {
  const body = (await request.json()) as Record<string, unknown>;
  const siteId = (body['site_id'] as string | undefined) ?? 'unknown';
  const eventType = (body['event_type'] as string | undefined) ?? 'unknown';
  const url = (body['url'] as string | undefined) ?? '';
  const tags: string[] = Array.isArray(body['tags']) ? (body['tags'] as string[]) : [];

  await env.DB.prepare(
    `INSERT INTO cache_events (site_id, event_type, url, tags, timestamp)
     VALUES (?1, ?2, ?3, ?4, ?5)`,
  )
    .bind(siteId, eventType, url, JSON.stringify(tags), Date.now())
    .run();

  return Response.json({ ok: true });
}

async function PurgeHandler(request: Request, env: Env): Promise<Response> {
  const body = (await request.json()) as Record<string, unknown>;
  const tags: string[] = Array.isArray(body['tags']) ? (body['tags'] as string[]) : [];
  const siteId = (body['site_id'] as string | undefined) ?? 'unknown';
  const triggeredBy = (body['triggered_by'] as string | undefined) ?? 'api';

  await env.PURGE_QUEUE.send({ tags, siteId, timestamp: Date.now() });

  await env.DB.prepare(
    `INSERT INTO purge_log (site_id, tags, triggered_by, timestamp)
     VALUES (?1, ?2, ?3, ?4)`,
  )
    .bind(siteId, JSON.stringify(tags), triggeredBy, Date.now())
    .run();

  return Response.json({ ok: true, queued: tags.length });
}

async function PreloadHandler(request: Request, env: Env): Promise<Response> {
  const body = (await request.json()) as Record<string, unknown>;
  const urls: string[] = Array.isArray(body['urls']) ? (body['urls'] as string[]) : [];
  const priority = (body['priority'] as string | undefined) ?? 'normal';
  const siteId = (body['site_id'] as string | undefined) ?? 'unknown';

  await env.PRELOAD_QUEUE.send({ urls, priority, siteId });

  return Response.json({ ok: true, queued: urls.length });
}

async function ExperimentsHandler(request: Request, env: Env): Promise<Response> {
  if (request.method === 'GET') {
    const siteId = new URL(request.url).searchParams.get('site_id') ?? '';
    const rows = await env.DB.prepare(
      `SELECT * FROM experiments WHERE site_id = ?1 ORDER BY started_at DESC LIMIT 20`,
    )
      .bind(siteId)
      .all();
    return Response.json({ experiments: rows.results });
  }

  // POST — create/update experiment
  const body = (await request.json()) as Record<string, unknown>;
  await env.DB.prepare(
    `INSERT INTO experiments (site_id, strategy, started_at, metrics_json)
     VALUES (?1, ?2, ?3, ?4)`,
  )
    .bind(
      body['site_id'] ?? '',
      body['strategy'] ?? 'full',
      Date.now(),
      JSON.stringify(body['metrics'] ?? {}),
    )
    .run();

  return Response.json({ ok: true });
}

// ---------------------------------------------------------------------------
// Auth guard — validates signature + nonce for mutating API endpoints
// ---------------------------------------------------------------------------

async function verifyRequest(request: Request, env: Env): Promise<Response | null> {
  const valid = await verifySignature(request, env.SIGNING_SECRET);
  if (!valid) {
    return Response.json({ error: 'Unauthorized' }, { status: 401 });
  }

  const nonce = request.headers.get('X-Nonce') ?? '';
  const fresh = await checkAndStoreNonce(env.CACHE_TAGS, nonce, 300);
  if (!fresh) {
    return Response.json({ error: 'Replay detected' }, { status: 409 });
  }

  return null;
}

// ---------------------------------------------------------------------------
// Cache-serving path
// ---------------------------------------------------------------------------

async function serveCached(
  request: Request,
  env: Env,
  ctx: ExecutionContext,
): Promise<Response> {
  const policy = await getPolicyForRequest(request, env);

  if (policy.bypass) {
    return fetchFromOrigin(request, env);
  }

  // 1. Edge cache
  const edgeHit = await getFromEdgeCache(request, policy.ttl);
  if (edgeHit) {
    revalidateInBackground(ctx, request, env);
    return edgeHit;
  }

  // 2. R2 cache
  const cacheKey = buildCacheKey(request);
  const r2Key = await buildR2Key(cacheKey);
  const r2Hit = await getFromR2(env.CACHE_BUCKET, r2Key);
  if (r2Hit) {
    const resp = new Response(r2Hit.body, {
      headers: {
        'Content-Type': r2Hit.metadata.contentType,
        'Cache-Control': `public, max-age=${r2Hit.metadata.ttl}`,
        'X-Cache': 'HIT-R2',
      },
    });
    ctx.waitUntil(putToEdgeCache(request, resp.clone(), policy.ttl));
    revalidateInBackground(ctx, request, env);
    return resp;
  }

  // 3. Origin
  const originResp = await fetchFromOrigin(request, env);
  if (originResp.ok) {
    const body = await originResp.text();
    ctx.waitUntil(
      putToR2(env.CACHE_BUCKET, r2Key, body, {
        tags: policy.tags,
        ttl: policy.ttl,
        createdAt: Date.now(),
        contentType: originResp.headers.get('Content-Type') ?? 'text/html',
      }),
    );
    return new Response(body, {
      status: originResp.status,
      headers: originResp.headers,
    });
  }

  return originResp;
}

// ---------------------------------------------------------------------------
// Main router
// ---------------------------------------------------------------------------

/**
 * Routes an incoming request to the appropriate handler.
 */
export async function handleRequest(
  request: Request,
  env: Env,
  ctx: ExecutionContext,
): Promise<Response> {
  const url = new URL(request.url);
  const { pathname } = url;
  const method = request.method;

  // --- Heartbeat (unauthenticated read) ---
  if (pathname === '/api/heartbeat' && method === 'GET') {
    return HeartbeatHandler(request, env);
  }

  // --- Authenticated API routes ---
  if (pathname.startsWith('/api/')) {
    // Clone request before reading body in auth so handlers can read it again
    const authReq = request.clone();
    const authError = await verifyRequest(authReq, env);
    if (authError) return authError;

    if (pathname === '/api/signals' && method === 'POST') {
      return getOrSetIdempotencyResult(
        env.CACHE_TAGS,
        request.headers.get('X-Idempotency-Key') ?? '',
        () => SignalsHandler(request, env),
      );
    }

    if (pathname === '/api/purge' && method === 'POST') {
      return PurgeHandler(request, env);
    }

    if (pathname === '/api/preload' && method === 'POST') {
      return PreloadHandler(request, env);
    }

    if (pathname === '/api/experiments' && (method === 'GET' || method === 'POST')) {
      return ExperimentsHandler(request, env);
    }

    return Response.json({ error: 'Not found' }, { status: 404 });
  }

  // --- Plugin API: benchmark scoring, sandbox scheduling, edge cache proxy ---
  if (
    pathname.startsWith('/plugin/wp/') ||
    pathname.startsWith('/edge/cache/')
  ) {
    const pluginResponse = await handlePluginApiRequest(request, env);
    if (pluginResponse) return pluginResponse;
    return Response.json({ error: 'Not found' }, { status: 404 });
  }

  // --- Cache-serving path ---
  return serveCached(request, env, ctx);
}
