import type { BenchmarkRequestPayload, Env, StrategyCandidateInput } from './lib/types';
import { evaluateCandidate, evaluateCandidates } from './lib/scoring';
import { verifySignedRequest } from './lib/signature';

export default {
  async fetch(request: Request, env: Env): Promise<Response> {
    const url = new URL(request.url);

    if (request.method === 'POST' && url.pathname === '/plugin/wp/cache/benchmark') {
      return handleBenchmark(request, env, url.pathname);
    }

    if (request.method === 'POST' && url.pathname.startsWith('/plugin/wp/sandbox/')) {
      return handleSandbox(request, env, url.pathname);
    }

    if (request.method === 'GET' && url.pathname === '/plugin/wp/cache/profile') {
      return handleProfileGet(request, env);
    }

    if (request.method === 'GET' && url.pathname.startsWith('/edge/cache/')) {
      return handleEdgeCacheProxy(request, env, url);
    }

    return json({ ok: false, error: 'not_found' }, 404);
  },
};

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
      JSON.stringify(chosen.hard_gate_failures),
      JSON.stringify(chosen.candidate.metrics),
      parsed.payload.ai_summary,
      nowIso,
      nowIso,
    )
    .run();

  return json(
    {
      ok: true,
      site_id: parsed.payload.site_id,
      vps_fingerprint: vpsFingerprint,
      recommended_strategy: chosen.candidate.strategy,
      ttl_seconds: chosen.candidate.ttl_seconds,
      score: chosenRanked?.final_score ?? chosen.score,
      components: chosen.components,
      shared_bonus: chosenRanked?.shared_bonus ?? 0,
      strategy_shared_bonuses: sharedBonuses,
      notes:
        (chosenRanked?.shared_bonus ?? 0) > 0
          ? `applied_shared_bonus_${(chosenRanked?.shared_bonus ?? 0).toFixed(4)}`
          : 'shared_bonus_not_applied',
      rejected_candidates: result.evaluated
        .filter((entry) => !entry.hard_gate_passed)
        .map((entry) => ({
          strategy: entry.candidate.strategy,
          hard_gate_failures: entry.hard_gate_failures,
        })),
      profile: {
        strategy: chosen.candidate.strategy,
        ttl_seconds: chosen.candidate.ttl_seconds,
        score: chosenRanked?.final_score ?? chosen.score,
        shared_bonus: chosenRanked?.shared_bonus ?? 0,
        persisted_at: nowIso,
      },
    },
    200,
  );
}

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
  if (path === '/plugin/wp/sandbox/conflicts/report') {
    return handleSandboxConflictReport(request, env, path);
  }
  if (path === '/plugin/wp/sandbox/conflicts/list') {
    return handleSandboxConflictList(request, env, path);
  }
  if (path === '/plugin/wp/sandbox/conflicts/resolve') {
    return handleSandboxConflictResolve(request, env, path);
  }
  if (path === '/plugin/wp/sandbox/loadtests/report') {
    return handleSandboxLoadtestReport(request, env, path);
  }
  if (path === '/plugin/wp/sandbox/loadtests/shared') {
    return handleSandboxLoadtestShared(request, env, path);
  }

  return json({ ok: false, error: 'sandbox_route_not_found' }, 404);
}

async function handleSandboxRequest(request: Request, env: Env, path: string): Promise<Response> {
  const auth = await authorizeMutation(request, env, path);
  if (!auth.ok) {
    return auth.response;
  }

  const siteId = asString(auth.body.site_id);
  const requestedByAgent = asString(auth.body.requested_by_agent);
  if (!siteId || !requestedByAgent) {
    return json({ ok: false, error: 'missing_site_or_agent' }, 400);
  }

  const now = new Date().toISOString();
  const record = {
    id: crypto.randomUUID(),
    plugin_id: auth.pluginId,
    site_id: siteId,
    requested_by_agent: requestedByAgent,
    task_type: asString(auth.body.task_type) || 'generic',
    priority_base: clampInt(auth.body.priority_base, 1, 5, 3),
    estimated_minutes: clampInt(auth.body.estimated_minutes, 5, 240, 30),
    earliest_start_at: asString(auth.body.earliest_start_at) || null,
    status: 'queued',
    context_json: auth.body.context && typeof auth.body.context === 'object' ? JSON.stringify(auth.body.context) : null,
    claimed_by_agent: null,
    claimed_at: null,
    created_at: now,
    updated_at: now,
  };

  await env.DB.prepare(
    `INSERT INTO sandbox_requests (
      id, plugin_id, site_id, requested_by_agent, task_type,
      priority_base, estimated_minutes, earliest_start_at, status,
      context_json, claimed_by_agent, claimed_at, created_at, updated_at
    ) VALUES (?1, ?2, ?3, ?4, ?5, ?6, ?7, ?8, ?9, ?10, ?11, ?12, ?13, ?14)`,
  )
    .bind(
      record.id,
      record.plugin_id,
      record.site_id,
      record.requested_by_agent,
      record.task_type,
      record.priority_base,
      record.estimated_minutes,
      record.earliest_start_at,
      record.status,
      record.context_json,
      record.claimed_by_agent,
      record.claimed_at,
      record.created_at,
      record.updated_at,
    )
    .run();

  return json({ ok: true, request: record }, 201);
}

async function handleSandboxVote(request: Request, env: Env, path: string): Promise<Response> {
  const auth = await authorizeMutation(request, env, path);
  if (!auth.ok) {
    return auth.response;
  }

  const requestId = asString(auth.body.request_id);
  const agentId = asString(auth.body.agent_id);
  if (!requestId || !agentId) {
    return json({ ok: false, error: 'missing_request_or_agent' }, 400);
  }

  const requestRow = await env.DB.prepare('SELECT id FROM sandbox_requests WHERE id = ?1 LIMIT 1')
    .bind(requestId)
    .first();
  if (!requestRow) {
    return json({ ok: false, error: 'sandbox_request_not_found' }, 404);
  }

  const now = new Date().toISOString();
  const vote = clampInt(auth.body.vote, -5, 5, 0);
  const reason = asString(auth.body.reason) || null;

  await env.DB.prepare(
    `INSERT INTO sandbox_votes (id, request_id, agent_id, vote, reason, created_at, updated_at)
     VALUES (?1, ?2, ?3, ?4, ?5, ?6, ?7)
     ON CONFLICT(request_id, agent_id) DO UPDATE SET
      vote=excluded.vote,
      reason=excluded.reason,
      updated_at=excluded.updated_at`,
  )
    .bind(crypto.randomUUID(), requestId, agentId, vote, reason, now, now)
    .run();

  return json({ ok: true, request_id: requestId, agent_id: agentId, vote }, 200);
}

async function handleSandboxClaim(request: Request, env: Env, path: string): Promise<Response> {
  const auth = await authorizeMutation(request, env, path);
  if (!auth.ok) {
    return auth.response;
  }

  const agentId = asString(auth.body.agent_id);
  if (!agentId) {
    return json({ ok: false, error: 'missing_agent_id' }, 400);
  }

  const slotMinutes = clampInt(auth.body.slot_minutes, 5, 240, 30);
  const requestedSandboxId = asString(auth.body.sandbox_id) || `sandbox-${crypto.randomUUID().slice(0, 8)}`;

  const selected = await env.DB.prepare(
    `SELECT
      r.*,
      COALESCE(SUM(v.vote), 0) AS vote_total,
      ((r.priority_base * 10) + COALESCE(SUM(v.vote), 0)) AS score
    FROM sandbox_requests r
    LEFT JOIN sandbox_votes v ON v.request_id = r.id
    WHERE r.status = 'queued' AND r.plugin_id = ?1
    GROUP BY r.id
    ORDER BY score DESC, r.created_at ASC
    LIMIT 1`,
  )
    .bind(auth.pluginId)
    .first<Record<string, unknown>>();

  if (!selected) {
    return json({ ok: false, error: 'no_sandbox_request_available' }, 409);
  }

  const requestId = String(selected.id);
  const now = new Date();
  const nowIso = now.toISOString();
  const endIso = new Date(now.getTime() + slotMinutes * 60_000).toISOString();

  const update = await env.DB.prepare(
    `UPDATE sandbox_requests
     SET status = 'claimed', claimed_by_agent = ?2, claimed_at = ?3, updated_at = ?3
     WHERE id = ?1 AND status = 'queued'`,
  )
    .bind(requestId, agentId, nowIso)
    .run();

  if (!update.success || (update.meta?.changes ?? 0) < 1) {
    return json({ ok: false, error: 'sandbox_claim_conflict' }, 409);
  }

  const allocation = {
    id: crypto.randomUUID(),
    request_id: requestId,
    sandbox_id: requestedSandboxId,
    claimed_by_agent: agentId,
    start_at: nowIso,
    end_at: endIso,
    status: 'active',
    note: null,
    created_at: nowIso,
    updated_at: nowIso,
  };

  await env.DB.prepare(
    `INSERT INTO sandbox_allocations
      (id, request_id, sandbox_id, claimed_by_agent, start_at, end_at, status, note, created_at, updated_at)
     VALUES (?1, ?2, ?3, ?4, ?5, ?6, ?7, ?8, ?9, ?10)`,
  )
    .bind(
      allocation.id,
      allocation.request_id,
      allocation.sandbox_id,
      allocation.claimed_by_agent,
      allocation.start_at,
      allocation.end_at,
      allocation.status,
      allocation.note,
      allocation.created_at,
      allocation.updated_at,
    )
    .run();

  return json(
    {
      ok: true,
      selected_request: selected,
      selected_score: Number(selected.score ?? 0),
      allocation,
    },
    200,
  );
}

async function handleSandboxRelease(request: Request, env: Env, path: string): Promise<Response> {
  const auth = await authorizeMutation(request, env, path);
  if (!auth.ok) {
    return auth.response;
  }

  const requestId = asString(auth.body.request_id);
  const agentId = asString(auth.body.agent_id);
  if (!requestId || !agentId) {
    return json({ ok: false, error: 'missing_request_or_agent' }, 400);
  }

  const requestRow = await env.DB.prepare('SELECT * FROM sandbox_requests WHERE id = ?1 LIMIT 1')
    .bind(requestId)
    .first<Record<string, unknown>>();
  if (!requestRow) {
    return json({ ok: false, error: 'sandbox_request_not_found' }, 404);
  }

  const claimedBy = asString(requestRow.claimed_by_agent);
  const requestedBy = asString(requestRow.requested_by_agent);
  if (claimedBy && claimedBy !== agentId && requestedBy !== agentId) {
    return json({ ok: false, error: 'sandbox_release_forbidden' }, 403);
  }

  const outcomeRaw = (asString(auth.body.outcome) || 'completed').toLowerCase();
  const outcome = outcomeRaw === 'failed' || outcomeRaw === 'requeue' ? outcomeRaw : 'completed';
  const now = new Date().toISOString();
  const note = asString(auth.body.note) || null;

  const requestStatus = outcome === 'requeue' ? 'queued' : outcome;
  await env.DB.prepare(
    `UPDATE sandbox_requests
     SET status = ?2,
         claimed_by_agent = CASE WHEN ?2 = 'queued' THEN NULL ELSE claimed_by_agent END,
         claimed_at = CASE WHEN ?2 = 'queued' THEN NULL ELSE claimed_at END,
         updated_at = ?3
     WHERE id = ?1`,
  )
    .bind(requestId, requestStatus, now)
    .run();

  await env.DB.prepare(
    `UPDATE sandbox_allocations
     SET status = 'released', note = ?2, updated_at = ?3
     WHERE request_id = ?1 AND status = 'active'`,
  )
    .bind(requestId, note, now)
    .run();

  return json({ ok: true, request_id: requestId, outcome }, 200);
}

async function handleSandboxConflictReport(
  request: Request,
  env: Env,
  path: string,
): Promise<Response> {
  const auth = await authorizeMutation(request, env, path);
  if (!auth.ok) {
    return auth.response;
  }

  const siteId = asString(auth.body.site_id);
  const agentId = asString(auth.body.agent_id);
  const summary = asString(auth.body.summary);
  if (!siteId || !agentId || !summary) {
    return json({ ok: false, error: 'missing_conflict_fields' }, 400);
  }

  const requestId = asString(auth.body.request_id) || null;
  if (requestId) {
    const req = await env.DB.prepare('SELECT plugin_id, site_id FROM sandbox_requests WHERE id = ?1 LIMIT 1')
      .bind(requestId)
      .first<Record<string, unknown>>();
    if (!req) {
      return json({ ok: false, error: 'sandbox_request_not_found' }, 404);
    }
    if (asString(req.plugin_id) !== auth.pluginId) {
      return json({ ok: false, error: 'sandbox_request_forbidden' }, 403);
    }
    if (asString(req.site_id) !== siteId) {
      return json({ ok: false, error: 'sandbox_conflict_site_mismatch' }, 400);
    }
  }

  const now = new Date().toISOString();
  const conflict = {
    id: crypto.randomUUID(),
    plugin_id: auth.pluginId,
    site_id: siteId,
    request_id: requestId,
    agent_id: agentId,
    conflict_type: asString(auth.body.conflict_type) || 'general',
    severity: clampInt(auth.body.severity, 1, 5, 3),
    summary: summary.slice(0, 280),
    details_json:
      typeof auth.body.details === 'string'
        ? auth.body.details.slice(0, 4000)
        : auth.body.details && typeof auth.body.details === 'object'
          ? JSON.stringify(auth.body.details).slice(0, 4000)
          : null,
    blocked_by_request_id: asString(auth.body.blocked_by_request_id) || null,
    sandbox_id: asString(auth.body.sandbox_id) || null,
    status: 'open',
    resolution_note: null,
    resolved_by_agent: null,
    resolved_at: null,
    created_at: now,
    updated_at: now,
  };

  await env.DB.prepare(
    `INSERT INTO sandbox_conflicts (
      id, plugin_id, site_id, request_id, agent_id, conflict_type, severity, summary,
      details_json, blocked_by_request_id, sandbox_id, status, resolution_note,
      resolved_by_agent, resolved_at, created_at, updated_at
    ) VALUES (?1, ?2, ?3, ?4, ?5, ?6, ?7, ?8, ?9, ?10, ?11, ?12, ?13, ?14, ?15, ?16, ?17)`,
  )
    .bind(
      conflict.id,
      conflict.plugin_id,
      conflict.site_id,
      conflict.request_id,
      conflict.agent_id,
      conflict.conflict_type,
      conflict.severity,
      conflict.summary,
      conflict.details_json,
      conflict.blocked_by_request_id,
      conflict.sandbox_id,
      conflict.status,
      conflict.resolution_note,
      conflict.resolved_by_agent,
      conflict.resolved_at,
      conflict.created_at,
      conflict.updated_at,
    )
    .run();

  return json({ ok: true, conflict }, 201);
}

async function handleSandboxConflictList(
  request: Request,
  env: Env,
  path: string,
): Promise<Response> {
  const auth = await authorizeMutation(request, env, path);
  if (!auth.ok) {
    return auth.response;
  }

  const siteId = asString(auth.body.site_id) || null;
  const requestId = asString(auth.body.request_id) || null;
  const statusRaw = (asString(auth.body.status) || 'open').toLowerCase();
  const status = statusRaw === 'resolved' || statusRaw === 'dismissed' || statusRaw === 'all' ? statusRaw : 'open';
  const limit = clampInt(auth.body.limit, 1, 200, 50);

  const clauses = ['plugin_id = ?1'];
  const values: unknown[] = [auth.pluginId];

  if (siteId) {
    clauses.push(`site_id = ?${values.length + 1}`);
    values.push(siteId);
  }

  if (requestId) {
    clauses.push(`request_id = ?${values.length + 1}`);
    values.push(requestId);
  }

  if (status !== 'all') {
    clauses.push(`status = ?${values.length + 1}`);
    values.push(status);
  }

  const query = `SELECT * FROM sandbox_conflicts WHERE ${clauses.join(' AND ')} ORDER BY created_at DESC LIMIT ${limit}`;
  const result = await env.DB.prepare(query).bind(...values).all<Record<string, unknown>>();
  const conflicts = result.results ?? [];

  return json({ ok: true, count: conflicts.length, conflicts }, 200);
}

async function handleSandboxConflictResolve(
  request: Request,
  env: Env,
  path: string,
): Promise<Response> {
  const auth = await authorizeMutation(request, env, path);
  if (!auth.ok) {
    return auth.response;
  }

  const conflictId = asString(auth.body.conflict_id);
  const agentId = asString(auth.body.agent_id);
  if (!conflictId || !agentId) {
    return json({ ok: false, error: 'missing_conflict_or_agent' }, 400);
  }

  const statusRaw = (asString(auth.body.status) || 'resolved').toLowerCase();
  const status = statusRaw === 'dismissed' ? 'dismissed' : 'resolved';
  const resolutionNote = asString(auth.body.resolution_note) || null;

  const existing = await env.DB.prepare('SELECT * FROM sandbox_conflicts WHERE id = ?1 LIMIT 1')
    .bind(conflictId)
    .first<Record<string, unknown>>();
  if (!existing || asString(existing.plugin_id) !== auth.pluginId) {
    return json({ ok: false, error: 'sandbox_conflict_not_found' }, 404);
  }
  if (asString(existing.status) !== 'open') {
    return json({ ok: false, error: 'sandbox_conflict_already_closed' }, 409);
  }

  const now = new Date().toISOString();
  await env.DB.prepare(
    `UPDATE sandbox_conflicts
     SET status = ?2,
         resolution_note = ?3,
         resolved_by_agent = ?4,
         resolved_at = ?5,
         updated_at = ?5
     WHERE id = ?1`,
  )
    .bind(conflictId, status, resolutionNote, agentId, now)
    .run();

  return json(
    {
      ok: true,
      conflict_id: conflictId,
      status,
      resolved_by_agent: agentId,
    },
    200,
  );
}

async function handleSandboxLoadtestReport(
  request: Request,
  env: Env,
  path: string,
): Promise<Response> {
  const auth = await authorizeMutation(request, env, path);
  if (!auth.ok) {
    return auth.response;
  }

  const siteId = asString(auth.body.site_id);
  if (!siteId) {
    return json({ ok: false, error: 'missing_site_id' }, 400);
  }

  const workerIdRaw =
    asString(auth.body.worker_id) || asString(auth.body.agent_id) || `${auth.pluginId}-worker`;
  const workerId = workerIdRaw.slice(0, 120);
  const defaultStrategy = normalizeStrategy(asString(auth.body.strategy) || 'edge-balanced');
  const rawSamples = Array.isArray(auth.body.page_tests)
    ? auth.body.page_tests
    : Array.isArray(auth.body.samples)
      ? auth.body.samples
      : [];

  if (rawSamples.length < 1) {
    return json({ ok: false, error: 'missing_page_tests' }, 400);
  }

  const nowIso = new Date().toISOString();
  let inserted = 0;
  let hardFailed = 0;

  for (const raw of rawSamples) {
    if (!raw || typeof raw !== 'object') {
      continue;
    }

    const sample = raw as Record<string, unknown>;
    const urlValue = asString(sample.page_url) || asString(sample.url);
    const pagePath = derivePagePath(urlValue, asString(sample.page_path));
    if (!pagePath) {
      continue;
    }

    const metrics = (sample.metrics ?? sample) as Record<string, unknown>;
    const gates = sample.gates && typeof sample.gates === 'object'
      ? (sample.gates as Record<string, unknown>)
      : {};

    const candidate = {
      strategy: normalizeStrategy(asString(sample.strategy) || defaultStrategy),
      ttl_seconds: clampInt(sample.ttl_seconds, 30, 86400, 300),
      metrics: {
        p50_latency_ms: numberOr(metrics.p50_latency_ms, 300),
        p95_latency_ms: numberOr(metrics.p95_latency_ms, 700),
        p99_latency_ms: numberOr(metrics.p99_latency_ms, 1300),
        origin_cpu_pct: numberOr(metrics.origin_cpu_pct, 60),
        origin_query_count: numberOr(metrics.origin_query_count, 90),
        edge_hit_ratio: numberOr(metrics.edge_hit_ratio, 0.5),
        r2_hit_ratio: numberOr(metrics.r2_hit_ratio, 0.2),
        purge_mttr_ms: numberOr(metrics.purge_mttr_ms, 1500),
      },
      gates: {
        digest_mismatch: asBool(gates.digest_mismatch),
        personalized_cache_leak: asBool(gates.personalized_cache_leak),
        purge_within_window:
          gates.purge_within_window === undefined ? true : asBool(gates.purge_within_window),
        cache_key_collision: asBool(gates.cache_key_collision),
      },
    } satisfies StrategyCandidateInput;

    const evaluated = evaluateCandidate(candidate);
    if (!evaluated.hard_gate_passed) {
      hardFailed += 1;
    }

    const row = {
      id: crypto.randomUUID(),
      plugin_id: auth.pluginId,
      site_id: siteId,
      worker_id: workerId,
      page_url: urlValue || pagePath,
      page_path: pagePath,
      strategy: candidate.strategy,
      p50_latency_ms: candidate.metrics.p50_latency_ms,
      p95_latency_ms: candidate.metrics.p95_latency_ms,
      p99_latency_ms: candidate.metrics.p99_latency_ms,
      origin_cpu_pct: candidate.metrics.origin_cpu_pct,
      origin_query_count: candidate.metrics.origin_query_count,
      edge_hit_ratio: candidate.metrics.edge_hit_ratio,
      r2_hit_ratio: candidate.metrics.r2_hit_ratio ?? null,
      purge_mttr_ms: candidate.metrics.purge_mttr_ms,
      hard_gate_passed: evaluated.hard_gate_passed ? 1 : 0,
      hard_gate_failures_json: JSON.stringify(evaluated.hard_gate_failures),
      score: evaluated.score,
      created_at: nowIso,
    };

    await env.DB.prepare(
      `INSERT INTO loadtest_samples (
         id, plugin_id, site_id, worker_id, page_url, page_path, strategy,
         p50_latency_ms, p95_latency_ms, p99_latency_ms,
         origin_cpu_pct, origin_query_count, edge_hit_ratio, r2_hit_ratio,
         purge_mttr_ms, hard_gate_passed, hard_gate_failures_json, score, created_at
       ) VALUES (?1, ?2, ?3, ?4, ?5, ?6, ?7, ?8, ?9, ?10, ?11, ?12, ?13, ?14, ?15, ?16, ?17, ?18, ?19)`,
    )
      .bind(
        row.id,
        row.plugin_id,
        row.site_id,
        row.worker_id,
        row.page_url,
        row.page_path,
        row.strategy,
        row.p50_latency_ms,
        row.p95_latency_ms,
        row.p99_latency_ms,
        row.origin_cpu_pct,
        row.origin_query_count,
        row.edge_hit_ratio,
        row.r2_hit_ratio,
        row.purge_mttr_ms,
        row.hard_gate_passed,
        row.hard_gate_failures_json,
        row.score,
        row.created_at,
      )
      .run();
    inserted += 1;
  }

  if (inserted < 1) {
    return json({ ok: false, error: 'no_valid_page_tests' }, 400);
  }

  return json(
    {
      ok: true,
      site_id: siteId,
      worker_id: workerId,
      inserted,
      hard_gate_failed: hardFailed,
    },
    201,
  );
}

async function handleSandboxLoadtestShared(
  request: Request,
  env: Env,
  path: string,
): Promise<Response> {
  const auth = await authorizeMutation(request, env, path);
  if (!auth.ok) {
    return auth.response;
  }

  const siteId = asString(auth.body.site_id);
  if (!siteId) {
    return json({ ok: false, error: 'missing_site_id' }, 400);
  }

  const limit = clampInt(auth.body.limit, 1, 500, 200);
  const strategy = asString(auth.body.strategy);
  const pagePath = asString(auth.body.page_path);
  const onlyPassing = auth.body.only_passing === undefined ? true : asBool(auth.body.only_passing);

  const whereParts = ['site_id = ?1'];
  const params: unknown[] = [siteId];

  if (strategy) {
    whereParts.push(`strategy = ?${params.length + 1}`);
    params.push(normalizeStrategy(strategy));
  }
  if (pagePath) {
    whereParts.push(`page_path = ?${params.length + 1}`);
    params.push(pagePath);
  }
  if (onlyPassing) {
    whereParts.push('hard_gate_passed = 1');
  }

  const whereClause = whereParts.join(' AND ');
  const byPageQuery = `SELECT
      page_path,
      strategy,
      COUNT(*) AS sample_count,
      AVG(p95_latency_ms) AS avg_p95_latency_ms,
      AVG(edge_hit_ratio) AS avg_edge_hit_ratio,
      AVG(COALESCE(r2_hit_ratio, 0)) AS avg_r2_hit_ratio,
      AVG(score) AS avg_score,
      AVG(hard_gate_passed) AS pass_ratio,
      MAX(created_at) AS last_seen_at
    FROM loadtest_samples
    WHERE ${whereClause}
    GROUP BY page_path, strategy
    ORDER BY avg_score DESC, sample_count DESC
    LIMIT ${limit}`;

  const byStrategyQuery = `SELECT
      strategy,
      COUNT(*) AS sample_count,
      AVG(p95_latency_ms) AS avg_p95_latency_ms,
      AVG(score) AS avg_score,
      AVG(hard_gate_passed) AS pass_ratio
    FROM loadtest_samples
    WHERE ${whereClause}
    GROUP BY strategy
    ORDER BY avg_score DESC, sample_count DESC`;

  const pageRows = await env.DB.prepare(byPageQuery).bind(...params).all<Record<string, unknown>>();
  const strategyRows = await env.DB
    .prepare(byStrategyQuery)
    .bind(...params)
    .all<Record<string, unknown>>();

  return json(
    {
      ok: true,
      site_id: siteId,
      count: (pageRows.results ?? []).length,
      shared_page_profiles: pageRows.results ?? [],
      strategy_leaderboard: strategyRows.results ?? [],
    },
    200,
  );
}

async function loadSharedStrategyBonuses(
  env: Env,
  siteId: string,
): Promise<Record<StrategyCandidateInput['strategy'], number>> {
  const rows = await env.DB.prepare(
    `SELECT strategy, AVG(p95_latency_ms) AS avg_p95, COUNT(*) AS sample_count
     FROM loadtest_samples
     WHERE site_id = ?1 AND hard_gate_passed = 1
     GROUP BY strategy`,
  )
    .bind(siteId)
    .all<Record<string, unknown>>();

  const results = rows.results ?? [];
  const empty: Record<StrategyCandidateInput['strategy'], number> = {
    'edge-balanced': 0,
    'edge-r2': 0,
    'origin-disk': 0,
    'object-cache': 0,
  };

  if (results.length < 2) {
    return empty;
  }

  const parsed = results
    .map((row) => ({
      strategy: normalizeStrategy(asString(row.strategy)),
      avgP95: numberOr(row.avg_p95, Number.NaN),
      sampleCount: numberOr(row.sample_count, 0),
    }))
    .filter((row) => Number.isFinite(row.avgP95));

  if (parsed.length < 2) {
    return empty;
  }

  const p95Values = parsed.map((row) => row.avgP95);
  const minP95 = Math.min(...p95Values);
  const maxP95 = Math.max(...p95Values);
  if (Math.abs(maxP95 - minP95) < 1) {
    return empty;
  }

  const bonuses = { ...empty };
  for (const row of parsed) {
    const normalized = (maxP95 - row.avgP95) / (maxP95 - minP95);
    const confidence = Math.min(1, row.sampleCount / 40);
    bonuses[row.strategy] = normalized * confidence * 0.12;
  }
  return bonuses;
}

async function authorizeMutation(
  request: Request,
  env: Env,
  path: string,
): Promise<{ ok: true; pluginId: string; body: Record<string, unknown> } | { ok: false; response: Response }> {
  const rawBody = await request.arrayBuffer();
  const auth = await verifySignedRequest(request, rawBody, path, env);
  if (!auth.ok) {
    return { ok: false, response: json({ ok: false, error: auth.error }, auth.status) };
  }

  const parsed = parseJsonObject(rawBody);
  if (!parsed.ok) {
    return { ok: false, response: json({ ok: false, error: parsed.error }, 400) };
  }

  return {
    ok: true,
    pluginId: auth.pluginId,
    body: parsed.payload,
  };
}

async function handleProfileGet(request: Request, env: Env): Promise<Response> {
  const url = new URL(request.url);
  const siteId = (url.searchParams.get('site_id') ?? '').trim();
  const vpsFingerprint = (url.searchParams.get('vps_fingerprint') ?? '').trim();

  if (!siteId) {
    return json({ ok: false, error: 'missing_site_id' }, 400);
  }

  const query = vpsFingerprint
    ? `SELECT * FROM strategy_profiles WHERE site_id = ?1 AND vps_fingerprint = ?2 ORDER BY updated_at DESC LIMIT 1`
    : `SELECT * FROM strategy_profiles WHERE site_id = ?1 ORDER BY updated_at DESC LIMIT 1`;

  const stmt = env.DB.prepare(query);
  const row = vpsFingerprint
    ? await stmt.bind(siteId, vpsFingerprint).first<Record<string, unknown>>()
    : await stmt.bind(siteId).first<Record<string, unknown>>();

  if (!row) {
    return json({ ok: false, error: 'profile_not_found' }, 404);
  }

  return json({ ok: true, profile: row }, 200);
}

async function handleEdgeCacheProxy(request: Request, env: Env, url: URL): Promise<Response> {
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
    headers: {
      'X-AI-Edge-Proxy': '1',
    },
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

function normalizeCacheKey(url: URL): string {
  const params = new URLSearchParams(url.search);
  for (const key of ['utm_source', 'utm_medium', 'utm_campaign', 'gclid', 'fbclid']) {
    params.delete(key);
  }

  const sorted = new URLSearchParams();
  const keys = Array.from(new Set(Array.from(params.keys()))).sort();
  for (const key of keys) {
    const values = params.getAll(key).sort();
    for (const value of values) {
      sorted.append(key, value);
    }
  }

  const query = sorted.toString();
  const normalizedPath = url.pathname.endsWith('/') ? url.pathname : `${url.pathname}/`;
  return `${url.origin}${normalizedPath}${query ? `?${query}` : ''}`;
}

function isCacheableResponse(response: Response): boolean {
  const cacheControl = (response.headers.get('cache-control') ?? '').toLowerCase();
  if (cacheControl.includes('no-store') || cacheControl.includes('private')) {
    return false;
  }
  const type = response.headers.get('content-type') ?? '';
  return type.includes('text/html') || type.includes('application/json');
}

function withDebugHeaders(response: Response, debugHeaders: Record<string, string>): Response {
  const next = new Response(response.body, response);
  for (const [name, value] of Object.entries(debugHeaders)) {
    next.headers.set(name, value);
  }
  return next;
}

function parseBenchmarkPayload(
  rawBody: ArrayBuffer,
):
  | {
      ok: true;
      payload: Required<Pick<BenchmarkRequestPayload, 'site_id' | 'vps_fingerprint' | 'candidates'>> &
        BenchmarkRequestPayload;
    }
  | { ok: false; error: string } {
  const parsed = parseJsonObject(rawBody);
  if (!parsed.ok) {
    return parsed;
  }

  const body = parsed.payload;
  const siteId = asString(body.site_id);
  if (!siteId) {
    return { ok: false, error: 'missing_site_id' };
  }

  const inputCandidates = Array.isArray(body.candidates)
    ? body.candidates.map(normalizeCandidate).filter((item): item is StrategyCandidateInput => item !== null)
    : [];

  const currentStrategy = asString(body.current_strategy) || 'edge-balanced';
  const currentTtl = numberOr(body.current_ttl_seconds, 300);

  const fallbackCandidate: StrategyCandidateInput = {
    strategy: normalizeStrategy(currentStrategy),
    ttl_seconds: clampInt(currentTtl, 30, 86400, 300),
    metrics: {
      p50_latency_ms: 320,
      p95_latency_ms: 720,
      p99_latency_ms: 1400,
      origin_cpu_pct: 60,
      origin_query_count: 90,
      edge_hit_ratio: 0.55,
      r2_hit_ratio: 0.2,
      purge_mttr_ms: 1500,
    },
    gates: {
      digest_mismatch: false,
      personalized_cache_leak: false,
      purge_within_window: true,
      cache_key_collision: false,
    },
  };

  const candidates = inputCandidates.length > 0 ? inputCandidates : [fallbackCandidate];

  return {
    ok: true,
    payload: {
      site_id: siteId,
      site_url: asString(body.site_url) || undefined,
      current_strategy: currentStrategy,
      current_ttl_seconds: currentTtl,
      ai_summary: asString(body.ai_summary)?.slice(0, 1000),
      vps_fingerprint:
        asString(body.vps_fingerprint)?.slice(0, 180) ||
        `vps-${hashSiteId(siteId)}`,
      candidates,
    },
  };
}

function normalizeCandidate(input: unknown): StrategyCandidateInput | null {
  if (!input || typeof input !== 'object') {
    return null;
  }
  const c = input as Record<string, unknown>;
  const metrics = (c.metrics ?? {}) as Record<string, unknown>;
  const gates = (c.gates ?? {}) as Record<string, unknown>;

  return {
    strategy: normalizeStrategy(asString(c.strategy) || 'edge-balanced'),
    ttl_seconds: clampInt(c.ttl_seconds, 30, 86400, 300),
    metrics: {
      p50_latency_ms: numberOr(metrics.p50_latency_ms, 320),
      p95_latency_ms: numberOr(metrics.p95_latency_ms, 720),
      p99_latency_ms: numberOr(metrics.p99_latency_ms, 1400),
      origin_cpu_pct: numberOr(metrics.origin_cpu_pct, 60),
      origin_query_count: numberOr(metrics.origin_query_count, 90),
      edge_hit_ratio: numberOr(metrics.edge_hit_ratio, 0.55),
      r2_hit_ratio: numberOr(metrics.r2_hit_ratio, 0.2),
      purge_mttr_ms: numberOr(metrics.purge_mttr_ms, 1500),
    },
    gates: {
      digest_mismatch: Boolean(gates.digest_mismatch),
      personalized_cache_leak: Boolean(gates.personalized_cache_leak),
      purge_within_window: gates.purge_within_window === undefined ? true : Boolean(gates.purge_within_window),
      cache_key_collision: Boolean(gates.cache_key_collision),
    },
  };
}

function parseJsonObject(rawBody: ArrayBuffer): { ok: true; payload: Record<string, unknown> } | { ok: false; error: string } {
  let parsed: unknown;
  try {
    parsed = JSON.parse(new TextDecoder().decode(rawBody));
  } catch {
    return { ok: false, error: 'invalid_json' };
  }

  if (!parsed || typeof parsed !== 'object' || Array.isArray(parsed)) {
    return { ok: false, error: 'invalid_payload' };
  }

  return { ok: true, payload: parsed as Record<string, unknown> };
}

function asString(value: unknown): string {
  return typeof value === 'string' ? value.trim() : '';
}

function asBool(value: unknown): boolean {
  if (typeof value === 'boolean') {
    return value;
  }
  if (typeof value === 'number') {
    return value !== 0;
  }
  if (typeof value === 'string') {
    const v = value.trim().toLowerCase();
    return v === '1' || v === 'true' || v === 'yes' || v === 'on';
  }
  return false;
}

function derivePagePath(urlValue: string, fallbackPath: string): string {
  if (fallbackPath) {
    return fallbackPath.startsWith('/') ? fallbackPath : `/${fallbackPath}`;
  }
  if (!urlValue) {
    return '';
  }
  try {
    const url = new URL(urlValue);
    const path = url.pathname || '/';
    return path.startsWith('/') ? path : `/${path}`;
  } catch {
    return '';
  }
}

function normalizeStrategy(value: string): StrategyCandidateInput['strategy'] {
  const v = value.trim().toLowerCase();
  if (v === 'edge-r2' || v === 'origin-disk' || v === 'object-cache') {
    return v;
  }
  return 'edge-balanced';
}

function clampInt(value: unknown, min: number, max: number, fallback: number): number {
  const n = Number.parseInt(String(value), 10);
  if (!Number.isFinite(n)) {
    return fallback;
  }
  return Math.max(min, Math.min(max, n));
}

function numberOr(value: unknown, fallback: number): number {
  const n = Number(value);
  return Number.isFinite(n) ? n : fallback;
}

function hashSiteId(input: string): string {
  let h = 0;
  for (let i = 0; i < input.length; i += 1) {
    h = (Math.imul(31, h) + input.charCodeAt(i)) | 0;
  }
  return Math.abs(h).toString(16);
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
