/**
 * Plugin API route handlers — benchmark scoring, sandbox scheduling, and edge cache proxy.
 *
 * These handlers serve the `/plugin/wp/*` and `/edge/cache/*` routes that are
 * shared with the monolithic worker implementation on the main branch.  They
 * use `lib/signature.ts` (verifySignedRequest) for HMAC auth so the WordPress
 * plugin can call them without the full SIGNING_SECRET flow.
 */

import type { BenchmarkRequestPayload, Env, StrategyCandidateInput } from '../lib/types';
import { evaluateCandidates } from '../lib/scoring';
import { verifySignedRequest } from '../lib/signature';

// ---------------------------------------------------------------------------
// Top-level dispatcher
// ---------------------------------------------------------------------------

/**
 * Dispatch a request that matches `/plugin/wp/*` or `/edge/cache/*`.
 * Returns null if the path is not handled here (caller should continue routing).
 */
export async function handlePluginApiRequest(
  request: Request,
  env: Env,
): Promise<Response | null> {
  const url = new URL(request.url);
  const pathname = url.pathname;
  const method = request.method;

  if (method === 'POST' && pathname === '/plugin/wp/cache/benchmark') {
    return handleBenchmark(request, env, pathname);
  }

  if (method === 'POST' && pathname.startsWith('/plugin/wp/sandbox/')) {
    return handleSandbox(request, env, pathname);
  }

  if (method === 'GET' && pathname === '/plugin/wp/cache/profile') {
    return handleProfileGet(request, env);
  }

  if (method === 'GET' && pathname.startsWith('/edge/cache/')) {
    return handleEdgeCacheProxy(request, env, url);
  }

  return null;
}

// ---------------------------------------------------------------------------
// Benchmark
// ---------------------------------------------------------------------------

async function handleBenchmark(request: Request, env: Env, path: string): Promise<Response> {
  const rawBody = await request.arrayBuffer();
  const auth = await verifySignedRequest(request, rawBody, path, env);
  if (!auth.ok) {
    return json({ ok: false, error: auth.error }, auth.status);
  }

  const parsed = parseBenchmarkPayload(rawBody);
  if (!parsed.ok) {
    return json({ ok: false, error: parsed.error }, 400);
  }

  const result = evaluateCandidates(parsed.payload.candidates);
  const sharedBonuses = await loadSharedStrategyBonuses(env, parsed.payload.site_id);
  const ranked = result.evaluated
    .filter((entry) => entry.hard_gate_passed)
    .map((entry) => ({
      entry,
      shared_bonus: sharedBonuses[entry.candidate.strategy] ?? 0,
      final_score: entry.score + (sharedBonuses[entry.candidate.strategy] ?? 0),
    }))
    .sort((left, right) => right.final_score - left.final_score);

  const nowIso = new Date().toISOString();
  const chosenRanked = ranked[0] ?? null;
  const chosen = chosenRanked?.entry ?? null;

  if (!chosen) {
    return json(
      {
        ok: false,
        error: 'no_candidate_passed_hard_gates',
        evaluated: result.evaluated,
      },
      409,
    );
  }

  const profileId = crypto.randomUUID();
  const vpsFingerprint = parsed.payload.vps_fingerprint;

  await env.DB.prepare(
    `INSERT INTO strategy_profiles (
      id, plugin_id, site_id, vps_fingerprint, strategy, ttl_seconds, score,
      component_latency, component_origin_load, component_cache_hit_quality, component_purge_mttr,
      hard_gate_failures_json, metrics_json, ai_summary, created_at, updated_at
    ) VALUES (?1, ?2, ?3, ?4, ?5, ?6, ?7, ?8, ?9, ?10, ?11, ?12, ?13, ?14, ?15, ?16)
    ON CONFLICT(site_id, vps_fingerprint) DO UPDATE SET
      plugin_id=excluded.plugin_id,
      strategy=excluded.strategy,
      ttl_seconds=excluded.ttl_seconds,
      score=excluded.score,
      component_latency=excluded.component_latency,
      component_origin_load=excluded.component_origin_load,
      component_cache_hit_quality=excluded.component_cache_hit_quality,
      component_purge_mttr=excluded.component_purge_mttr,
      hard_gate_failures_json=excluded.hard_gate_failures_json,
      metrics_json=excluded.metrics_json,
      ai_summary=excluded.ai_summary,
      updated_at=excluded.updated_at`,
  )
    .bind(
      profileId,
      auth.pluginId,
      parsed.payload.site_id,
      vpsFingerprint,
      chosen.candidate.strategy,
      chosen.candidate.ttl_seconds,
      chosenRanked?.final_score ?? chosen.score,
      chosen.components.latency,
      chosen.components.origin_load,
      chosen.components.cache_hit_quality,
      chosen.components.purge_mttr,
      chosen.hard_gate_failures.length > 0 ? JSON.stringify(chosen.hard_gate_failures) : null,
      JSON.stringify({
        candidates: result.evaluated,
        ranked,
      }),
      parsed.payload.ai_summary ?? null,
      nowIso,
      nowIso,
    )
    .run();

  return json({
    ok: true,
    chosen: {
      strategy: chosen.candidate.strategy,
      ttl_seconds: chosen.candidate.ttl_seconds,
      score: chosenRanked?.final_score ?? chosen.score,
      hard_gate_failures: chosen.hard_gate_failures,
    },
    evaluated: result.evaluated,
    profile_id: profileId,
  });
}

// ---------------------------------------------------------------------------
// Sandbox dispatcher
// ---------------------------------------------------------------------------

async function handleSandbox(request: Request, env: Env, path: string): Promise<Response> {
  if (path === '/plugin/wp/sandbox/request') {
    return handleSandboxRequest(request, env, path);
  }
  if (path === '/plugin/wp/sandbox/vote') {
    return handleSandboxVote(request, env, path);
  }
  if (path === '/plugin/wp/sandbox/claim') {
    return handleSandboxClaim(request, env, path);
  }
  if (path === '/plugin/wp/sandbox/release') {
    return handleSandboxRelease(request, env, path);
  }
  if (path === '/plugin/wp/sandbox/conflict/report') {
    return handleSandboxConflictReport(request, env, path);
  }
  if (request.method === 'GET' && path === '/plugin/wp/sandbox/conflict/list') {
    return handleSandboxConflictList(request, env);
  }
  if (path === '/plugin/wp/sandbox/conflict/resolve') {
    return handleSandboxConflictResolve(request, env, path);
  }
  if (path === '/plugin/wp/sandbox/loadtest/report') {
    return handleSandboxLoadtestReport(request, env, path);
  }
  if (request.method === 'GET' && path === '/plugin/wp/sandbox/loadtest/shared') {
    return handleSandboxLoadtestShared(request, env);
  }
  return json({ ok: false, error: 'sandbox_route_not_found' }, 404);
}

// ---------------------------------------------------------------------------
// Sandbox: request
// ---------------------------------------------------------------------------

async function handleSandboxRequest(
  request: Request,
  env: Env,
  path: string,
): Promise<Response> {
  const rawBody = await request.arrayBuffer();
  const auth = await verifySignedRequest(request, rawBody, path, env);
  if (!auth.ok) return json({ ok: false, error: auth.error }, auth.status);

  const parsed = parseJsonObject(rawBody);
  if (!parsed.ok) return json({ ok: false, error: parsed.error }, 400);
  const body = parsed.payload;

  const siteId = asString(body['site_id']);
  const taskType = asString(body['task_type']);
  const priorityBase = clampInt(body['priority_base'], 0, 100, 50);
  const estimatedMinutes = clampInt(body['estimated_minutes'], 1, 1440, 30);
  const earliestStartAt = asString(body['earliest_start_at'] ?? '');
  const contextJson = body['context'] != null ? JSON.stringify(body['context']) : null;

  const requestId = crypto.randomUUID();
  const now = new Date().toISOString();

  await env.DB.prepare(
    `INSERT INTO sandbox_requests
     (id, plugin_id, site_id, requested_by_agent, task_type, priority_base,
      estimated_minutes, earliest_start_at, status, context_json, created_at, updated_at)
     VALUES (?1, ?2, ?3, ?4, ?5, ?6, ?7, ?8, 'queued', ?9, ?10, ?11)`,
  )
    .bind(
      requestId,
      auth.pluginId,
      siteId,
      auth.pluginId,
      taskType,
      priorityBase,
      estimatedMinutes,
      earliestStartAt || null,
      contextJson,
      now,
      now,
    )
    .run();

  return json({ ok: true, request_id: requestId });
}

// ---------------------------------------------------------------------------
// Sandbox: vote
// ---------------------------------------------------------------------------

async function handleSandboxVote(request: Request, env: Env, path: string): Promise<Response> {
  const rawBody = await request.arrayBuffer();
  const auth = await verifySignedRequest(request, rawBody, path, env);
  if (!auth.ok) return json({ ok: false, error: auth.error }, auth.status);

  const parsed = parseJsonObject(rawBody);
  if (!parsed.ok) return json({ ok: false, error: parsed.error }, 400);
  const body = parsed.payload;

  const requestId = asString(body['request_id']);
  const vote = clampInt(body['vote'], -1, 1, 0);
  const reason = asString(body['reason'] ?? '');
  const now = new Date().toISOString();

  await env.DB.prepare(
    `INSERT INTO sandbox_votes (id, request_id, agent_id, vote, reason, created_at, updated_at)
     VALUES (?1, ?2, ?3, ?4, ?5, ?6, ?7)
     ON CONFLICT(request_id, agent_id) DO UPDATE SET
       vote=excluded.vote, reason=excluded.reason, updated_at=excluded.updated_at`,
  )
    .bind(crypto.randomUUID(), requestId, auth.pluginId, vote, reason || null, now, now)
    .run();

  return json({ ok: true });
}

// ---------------------------------------------------------------------------
// Sandbox: claim
// ---------------------------------------------------------------------------

async function handleSandboxClaim(request: Request, env: Env, path: string): Promise<Response> {
  const rawBody = await request.arrayBuffer();
  const auth = await authorizeMutation(request, rawBody, path, env);
  if (!auth.ok) return json({ ok: false, error: auth.error }, auth.status);

  const parsed = parseJsonObject(rawBody);
  if (!parsed.ok) return json({ ok: false, error: parsed.error }, 400);
  const body = parsed.payload;

  const requestId = asString(body['request_id']);
  const sandboxId = asString(body['sandbox_id']);
  const startAt = asString(body['start_at']);
  const endAt = asString(body['end_at']);
  const now = new Date().toISOString();

  const existing = await env.DB.prepare(
    `SELECT id FROM sandbox_allocations
     WHERE sandbox_id = ?1 AND status = 'active'
       AND start_at < ?2 AND end_at > ?3`,
  )
    .bind(sandboxId, endAt, startAt)
    .first();

  if (existing) {
    return json({ ok: false, error: 'sandbox_time_conflict' }, 409);
  }

  const allocationId = crypto.randomUUID();

  await env.DB.prepare(
    `INSERT INTO sandbox_allocations
     (id, request_id, sandbox_id, claimed_by_agent, start_at, end_at, status, created_at, updated_at)
     VALUES (?1, ?2, ?3, ?4, ?5, ?6, 'active', ?7, ?8)`,
  )
    .bind(allocationId, requestId, sandboxId, auth.pluginId, startAt, endAt, now, now)
    .run();

  await env.DB.prepare(
    `UPDATE sandbox_requests
     SET status='claimed', claimed_by_agent=?1, claimed_at=?2, updated_at=?3
     WHERE id=?4`,
  )
    .bind(auth.pluginId, now, now, requestId)
    .run();

  return json({ ok: true, allocation_id: allocationId });
}

// ---------------------------------------------------------------------------
// Sandbox: release
// ---------------------------------------------------------------------------

async function handleSandboxRelease(
  request: Request,
  env: Env,
  path: string,
): Promise<Response> {
  const rawBody = await request.arrayBuffer();
  const auth = await authorizeMutation(request, rawBody, path, env);
  if (!auth.ok) return json({ ok: false, error: auth.error }, auth.status);

  const parsed = parseJsonObject(rawBody);
  if (!parsed.ok) return json({ ok: false, error: parsed.error }, 400);
  const body = parsed.payload;

  const allocationId = asString(body['allocation_id']);
  const note = asString(body['note'] ?? '');
  const now = new Date().toISOString();

  const alloc = await env.DB.prepare(
    `SELECT request_id FROM sandbox_allocations WHERE id = ?1`,
  )
    .bind(allocationId)
    .first<{ request_id: string }>();

  if (!alloc) {
    return json({ ok: false, error: 'allocation_not_found' }, 404);
  }

  await env.DB.prepare(
    `UPDATE sandbox_allocations SET status='released', note=?1, updated_at=?2 WHERE id=?3`,
  )
    .bind(note || null, now, allocationId)
    .run();

  await env.DB.prepare(
    `UPDATE sandbox_requests SET status='released', updated_at=?1 WHERE id=?2`,
  )
    .bind(now, alloc.request_id)
    .run();

  return json({ ok: true });
}

// ---------------------------------------------------------------------------
// Sandbox: conflict report
// ---------------------------------------------------------------------------

async function handleSandboxConflictReport(
  request: Request,
  env: Env,
  path: string,
): Promise<Response> {
  const rawBody = await request.arrayBuffer();
  const auth = await verifySignedRequest(request, rawBody, path, env);
  if (!auth.ok) return json({ ok: false, error: auth.error }, auth.status);

  const parsed = parseJsonObject(rawBody);
  if (!parsed.ok) return json({ ok: false, error: parsed.error }, 400);
  const body = parsed.payload;

  const siteId = asString(body['site_id']);
  const requestId = asString(body['request_id'] ?? '');
  const conflictType = asString(body['conflict_type']);
  const severity = clampInt(body['severity'], 1, 5, 3);
  const summary = asString(body['summary']);
  const detailsJson =
    body['details'] != null ? JSON.stringify(body['details']) : null;
  const blockedByRequestId = asString(body['blocked_by_request_id'] ?? '');
  const sandboxId = asString(body['sandbox_id'] ?? '');
  const now = new Date().toISOString();

  const conflictId = crypto.randomUUID();

  await env.DB.prepare(
    `INSERT INTO sandbox_conflicts
     (id, plugin_id, site_id, request_id, agent_id, conflict_type, severity, summary,
      details_json, blocked_by_request_id, sandbox_id, status, created_at, updated_at)
     VALUES (?1, ?2, ?3, ?4, ?5, ?6, ?7, ?8, ?9, ?10, ?11, 'open', ?12, ?13)`,
  )
    .bind(
      conflictId,
      auth.pluginId,
      siteId,
      requestId || null,
      auth.pluginId,
      conflictType,
      severity,
      summary,
      detailsJson,
      blockedByRequestId || null,
      sandboxId || null,
      now,
      now,
    )
    .run();

  return json({ ok: true, conflict_id: conflictId });
}

// ---------------------------------------------------------------------------
// Sandbox: conflict list
// ---------------------------------------------------------------------------

async function handleSandboxConflictList(request: Request, env: Env): Promise<Response> {
  const url = new URL(request.url);
  const pluginId = url.searchParams.get('plugin_id') ?? '';
  const status = url.searchParams.get('status') ?? 'open';

  const rows = await env.DB.prepare(
    `SELECT * FROM sandbox_conflicts
     WHERE plugin_id = ?1 AND status = ?2
     ORDER BY created_at DESC LIMIT 50`,
  )
    .bind(pluginId, status)
    .all();

  return json({ ok: true, conflicts: rows.results });
}

// ---------------------------------------------------------------------------
// Sandbox: conflict resolve
// ---------------------------------------------------------------------------

async function handleSandboxConflictResolve(
  request: Request,
  env: Env,
  path: string,
): Promise<Response> {
  const rawBody = await request.arrayBuffer();
  const auth = await authorizeMutation(request, rawBody, path, env);
  if (!auth.ok) return json({ ok: false, error: auth.error }, auth.status);

  const parsed = parseJsonObject(rawBody);
  if (!parsed.ok) return json({ ok: false, error: parsed.error }, 400);
  const body = parsed.payload;

  const conflictId = asString(body['conflict_id']);
  const resolutionNote = asString(body['resolution_note'] ?? '');
  const now = new Date().toISOString();

  await env.DB.prepare(
    `UPDATE sandbox_conflicts
     SET status='resolved', resolution_note=?1, resolved_by_agent=?2, resolved_at=?3, updated_at=?4
     WHERE id=?5`,
  )
    .bind(resolutionNote || null, auth.pluginId, now, now, conflictId)
    .run();

  return json({ ok: true });
}

// ---------------------------------------------------------------------------
// Sandbox: loadtest report
// ---------------------------------------------------------------------------

async function handleSandboxLoadtestReport(
  request: Request,
  env: Env,
  path: string,
): Promise<Response> {
  const rawBody = await request.arrayBuffer();
  const auth = await verifySignedRequest(request, rawBody, path, env);
  if (!auth.ok) return json({ ok: false, error: auth.error }, auth.status);

  const parsed = parseJsonObject(rawBody);
  if (!parsed.ok) return json({ ok: false, error: parsed.error }, 400);
  const body = parsed.payload;

  const samples: unknown[] = Array.isArray(body['samples']) ? body['samples'] : [];
  if (samples.length === 0) {
    return json({ ok: false, error: 'no_samples' }, 400);
  }
  if (samples.length > 500) {
    return json({ ok: false, error: 'too_many_samples' }, 400);
  }

  const siteId = asString(body['site_id']);
  const workerId = auth.pluginId;
  const now = new Date().toISOString();

  const stmts = samples.map((rawSample) => {
    const s = rawSample as Record<string, unknown>;
    const candidate = normalizeCandidate(s['candidate']);
    if (!candidate) return null;

    const pageUrl = asString(s['page_url'] ?? '');
    const pagePath = derivePagePath(pageUrl, asString(s['page_path'] ?? '/'));

    const hardGatePassed = asBool(s['hard_gate_passed'] ?? true);
    const hardGateFailures: string[] = Array.isArray(s['hard_gate_failures'])
      ? (s['hard_gate_failures'] as string[])
      : [];

    return env.DB.prepare(
      `INSERT OR IGNORE INTO loadtest_samples
       (id, plugin_id, site_id, worker_id, page_url, page_path, strategy,
        p50_latency_ms, p95_latency_ms, p99_latency_ms, origin_cpu_pct, origin_query_count,
        edge_hit_ratio, r2_hit_ratio, purge_mttr_ms, hard_gate_passed,
        hard_gate_failures_json, score, created_at)
       VALUES (?1, ?2, ?3, ?4, ?5, ?6, ?7, ?8, ?9, ?10, ?11, ?12, ?13, ?14, ?15, ?16, ?17, ?18, ?19)`,
    ).bind(
      crypto.randomUUID(),
      auth.pluginId,
      siteId,
      workerId,
      pageUrl,
      pagePath,
      candidate.strategy,
      numberOr(candidate.metrics.p50_latency_ms, 0),
      numberOr(candidate.metrics.p95_latency_ms, 0),
      numberOr(candidate.metrics.p99_latency_ms, 0),
      numberOr(candidate.metrics.origin_cpu_pct, 0),
      numberOr(candidate.metrics.origin_query_count, 0),
      numberOr(candidate.metrics.edge_hit_ratio, 0),
      candidate.metrics.r2_hit_ratio != null
        ? numberOr(candidate.metrics.r2_hit_ratio, 0)
        : null,
      numberOr(candidate.metrics.purge_mttr_ms, 0),
      hardGatePassed ? 1 : 0,
      hardGateFailures.length > 0 ? JSON.stringify(hardGateFailures) : null,
      numberOr(s['score'], 0),
      now,
    );
  });

  const validStmts = stmts.filter((s): s is NonNullable<typeof s> => s !== null);
  if (validStmts.length > 0) {
    await env.DB.batch(validStmts);
  }

  return json({ ok: true, inserted: validStmts.length });
}

// ---------------------------------------------------------------------------
// Sandbox: shared loadtest results
// ---------------------------------------------------------------------------

async function handleSandboxLoadtestShared(request: Request, env: Env): Promise<Response> {
  const url = new URL(request.url);
  const siteId = url.searchParams.get('site_id') ?? '';
  const pagePath = url.searchParams.get('page_path') ?? '';
  const strategy = url.searchParams.get('strategy') ?? '';
  const limit = Math.min(parseInt(url.searchParams.get('limit') ?? '100', 10), 500);

  if (!siteId) {
    return json({ ok: false, error: 'site_id_required' }, 400);
  }

  let query =
    'SELECT * FROM loadtest_samples WHERE site_id = ?1';
  const bindings: unknown[] = [siteId];
  let paramIdx = 2;

  if (pagePath) {
    const normalizedPath = pagePath.endsWith('/') ? pagePath : `${pagePath}/`;
    query += ` AND (page_path = ?${paramIdx} OR page_path = ?${paramIdx + 1})`;
    bindings.push(pagePath, normalizedPath);
    paramIdx += 2;
  }

  if (strategy) {
    query += ` AND strategy = ?${paramIdx}`;
    bindings.push(strategy);
    paramIdx += 1;
  }

  query += ` ORDER BY created_at DESC LIMIT ?${paramIdx}`;
  bindings.push(limit);

  const stmt = env.DB.prepare(query);
  const bound = stmt.bind(...bindings);
  const rows = await bound.all();

  return json({ ok: true, samples: rows.results, count: rows.results.length });
}

// ---------------------------------------------------------------------------
// Profile get
// ---------------------------------------------------------------------------

async function handleProfileGet(request: Request, env: Env): Promise<Response> {
  const url = new URL(request.url);
  const siteId = url.searchParams.get('site_id') ?? '';
  const vpsFingerprint = url.searchParams.get('vps_fingerprint') ?? '';

  if (!siteId) {
    return json({ ok: false, error: 'site_id_required' }, 400);
  }

  let row: Record<string, unknown> | null;
  if (vpsFingerprint) {
    row = await env.DB.prepare(
      `SELECT * FROM strategy_profiles WHERE site_id = ?1 AND vps_fingerprint = ?2`,
    )
      .bind(siteId, vpsFingerprint)
      .first();
  } else {
    row = await env.DB.prepare(
      `SELECT * FROM strategy_profiles WHERE site_id = ?1 ORDER BY updated_at DESC LIMIT 1`,
    )
      .bind(siteId)
      .first();
  }

  if (!row) {
    return json({ ok: false, error: 'profile_not_found' }, 404);
  }

  return json({ ok: true, profile: row });
}

// ---------------------------------------------------------------------------
// Edge cache proxy
// ---------------------------------------------------------------------------

export async function handleEdgeCacheProxy(
  request: Request,
  env: Env,
  url: URL,
): Promise<Response> {
  const cache = await caches.open('ai-wp-dynamic-cache');
  const normalizedKey = normalizeCacheKey(url);
  const cacheRequest = new Request(normalizedKey, request);

  const edgeHit = await cache.match(cacheRequest);
  if (edgeHit) {
    return withDebugHeaders(edgeHit, {
      'X-AI-Cache-Layer': 'edge-cache-api',
      'X-AI-Cache-Key': normalizedKey,
    });
  }

  if (env.CACHE_R2) {
    const r2Object = await env.CACHE_R2.get(normalizedKey);
    if (r2Object) {
      const headers = new Headers();
      r2Object.writeHttpMetadata(headers);
      headers.set('ETag', r2Object.httpEtag);
      headers.set('X-AI-Cache-Layer', 'r2');
      headers.set('X-AI-Cache-Key', normalizedKey);
      const fromR2 = new Response(r2Object.body, { status: 200, headers });
      void cache.put(cacheRequest, fromR2.clone());
      return fromR2;
    }
  }

  const originBase = env.ORIGIN_BASE_URL?.trim() ?? '';
  if (!originBase) {
    return json({ ok: false, error: 'origin_not_configured' }, 500);
  }

  const originUrl = new URL(url.pathname + url.search, originBase).toString();
  const originRes = await fetch(originUrl, {
    method: 'GET',
    headers: { 'X-AI-Edge-Proxy': '1' },
  });

  const response = new Response(originRes.body, originRes);
  response.headers.set('X-AI-Cache-Layer', 'origin');
  response.headers.set('X-AI-Cache-Key', normalizedKey);

  if (originRes.ok && isCacheableResponse(originRes)) {
    void cache.put(cacheRequest, response.clone());
    if (env.CACHE_R2) {
      void env.CACHE_R2.put(normalizedKey, response.clone().body ?? new Blob([]), {
        httpMetadata: {
          contentType: originRes.headers.get('content-type') ?? 'text/html; charset=utf-8',
          cacheControl: originRes.headers.get('cache-control') ?? 'public, max-age=300',
        },
      });
    }
  }

  return response;
}

// ---------------------------------------------------------------------------
// Shared bonus loader
// ---------------------------------------------------------------------------

async function loadSharedStrategyBonuses(
  env: Env,
  siteId: string,
): Promise<Record<string, number>> {
  const rows = await env.DB.prepare(
    `SELECT strategy, AVG(score) as avg_score, COUNT(*) as sample_count
     FROM loadtest_samples
     WHERE site_id = ?1 AND hard_gate_passed = 1
     GROUP BY strategy`,
  )
    .bind(siteId)
    .all<{ strategy: string; avg_score: number; sample_count: number }>();

  const bonuses: Record<string, number> = {};
  for (const row of rows.results) {
    if (row.sample_count >= 3) {
      bonuses[row.strategy] = Math.min(row.avg_score * 0.1, 5);
    }
  }
  return bonuses;
}

// ---------------------------------------------------------------------------
// Auth helper: requires CAP_TOKEN_SANDBOX_WRITE capability
// ---------------------------------------------------------------------------

async function authorizeMutation(
  request: Request,
  rawBody: ArrayBuffer,
  path: string,
  env: Env,
): Promise<{ ok: true; pluginId: string } | { ok: false; status: number; error: string }> {
  const capToken = request.headers.get('X-Cap-Token')?.trim() ?? '';
  if (!env.CAP_TOKEN_SANDBOX_WRITE || capToken !== env.CAP_TOKEN_SANDBOX_WRITE) {
    return { ok: false, status: 403, error: 'missing_or_invalid_cap_token' };
  }
  return verifySignedRequest(request, rawBody, path, env);
}

// ---------------------------------------------------------------------------
// Utility helpers
// ---------------------------------------------------------------------------

function normalizeCacheKey(url: URL): string {
  const clean = new URL(url.href);
  clean.searchParams.sort();
  return clean.toString();
}

function isCacheableResponse(response: Response): boolean {
  const cc = response.headers.get('cache-control') ?? '';
  return !cc.includes('no-store') && !cc.includes('private');
}

function withDebugHeaders(
  response: Response,
  debugHeaders: Record<string, string>,
): Response {
  const r = new Response(response.body, response);
  for (const [k, v] of Object.entries(debugHeaders)) {
    r.headers.set(k, v);
  }
  return r;
}

function parseBenchmarkPayload(rawBody: ArrayBuffer): {
  ok: true;
  payload: Required<Pick<BenchmarkRequestPayload, 'site_id' | 'vps_fingerprint' | 'candidates'>> &
    BenchmarkRequestPayload;
} | { ok: false; error: string } {
  let body: unknown;
  try {
    body = JSON.parse(new TextDecoder().decode(rawBody));
  } catch {
    return { ok: false, error: 'invalid_json' };
  }

  if (typeof body !== 'object' || body === null) {
    return { ok: false, error: 'body_not_object' };
  }

  const obj = body as Record<string, unknown>;
  const siteId = asString(obj['site_id']);
  if (!siteId) return { ok: false, error: 'missing_site_id' };

  const vpsFingerprint = asString(obj['vps_fingerprint'] ?? '');

  const rawCandidates: unknown[] = Array.isArray(obj['candidates']) ? obj['candidates'] : [];
  const candidates: StrategyCandidateInput[] = rawCandidates
    .map(normalizeCandidate)
    .filter((c): c is StrategyCandidateInput => c !== null);

  if (candidates.length === 0) {
    return { ok: false, error: 'no_valid_candidates' };
  }

  return {
    ok: true,
    payload: {
      ...(obj as unknown as BenchmarkRequestPayload),
      site_id: siteId,
      vps_fingerprint: vpsFingerprint,
      candidates,
    },
  };
}

function normalizeCandidate(input: unknown): StrategyCandidateInput | null {
  if (typeof input !== 'object' || input === null) return null;
  const c = input as Record<string, unknown>;

  const strategy = normalizeStrategy(asString(c['strategy']));
  if (!strategy) return null;

  const metrics = c['metrics'];
  if (typeof metrics !== 'object' || metrics === null) return null;
  const m = metrics as Record<string, unknown>;

  const gates = c['gates'];
  const g = typeof gates === 'object' && gates !== null ? (gates as Record<string, unknown>) : {};

  return {
    strategy,
    ttl_seconds: clampInt(c['ttl_seconds'], 60, 86400, 300),
    metrics: {
      p50_latency_ms: numberOr(m['p50_latency_ms'], 0),
      p95_latency_ms: numberOr(m['p95_latency_ms'], 0),
      p99_latency_ms: numberOr(m['p99_latency_ms'], 0),
      origin_cpu_pct: numberOr(m['origin_cpu_pct'], 0),
      origin_query_count: numberOr(m['origin_query_count'], 0),
      edge_hit_ratio: numberOr(m['edge_hit_ratio'], 0),
      ...(m['r2_hit_ratio'] !== undefined
        ? { r2_hit_ratio: numberOr(m['r2_hit_ratio'], 0) }
        : {}),
      purge_mttr_ms: numberOr(m['purge_mttr_ms'], 0),
    },
    gates: {
      digest_mismatch: asBool(g['digest_mismatch'] ?? false),
      personalized_cache_leak: asBool(g['personalized_cache_leak'] ?? false),
      purge_within_window: asBool(g['purge_within_window'] ?? false),
      cache_key_collision: asBool(g['cache_key_collision'] ?? false),
    },
  };
}

function parseJsonObject(
  rawBody: ArrayBuffer,
): { ok: true; payload: Record<string, unknown> } | { ok: false; error: string } {
  let body: unknown;
  try {
    body = JSON.parse(new TextDecoder().decode(rawBody));
  } catch {
    return { ok: false, error: 'invalid_json' };
  }
  if (typeof body !== 'object' || body === null || Array.isArray(body)) {
    return { ok: false, error: 'body_not_object' };
  }
  return { ok: true, payload: body as Record<string, unknown> };
}

function asString(value: unknown): string {
  return typeof value === 'string' ? value.trim() : '';
}

function asBool(value: unknown): boolean {
  if (typeof value === 'boolean') return value;
  if (typeof value === 'number') return value !== 0;
  if (typeof value === 'string') return value === 'true' || value === '1';
  return false;
}

function derivePagePath(urlValue: string, fallbackPath: string): string {
  try {
    return new URL(urlValue).pathname;
  } catch {
    return fallbackPath || '/';
  }
}

function normalizeStrategy(value: string): StrategyCandidateInput['strategy'] | null {
  const valid = ['edge-balanced', 'edge-r2', 'origin-disk', 'object-cache'] as const;
  return valid.find((s) => s === value) ?? null;
}

function clampInt(value: unknown, min: number, max: number, fallback: number): number {
  const n = typeof value === 'number' ? value : parseInt(String(value ?? ''), 10);
  if (isNaN(n)) return fallback;
  return Math.max(min, Math.min(max, Math.round(n)));
}

function numberOr(value: unknown, fallback: number): number {
  return typeof value === 'number' && isFinite(value) ? value : fallback;
}

function json(payload: unknown, status = 200): Response {
  return new Response(JSON.stringify(payload, null, 2), {
    status,
    headers: {
      'content-type': 'application/json; charset=utf-8',
      'cache-control': 'no-store',
    },
  });
}
