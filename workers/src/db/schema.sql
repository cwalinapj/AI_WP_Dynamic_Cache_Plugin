CREATE TABLE IF NOT EXISTS strategy_profiles (
  id TEXT PRIMARY KEY,
  plugin_id TEXT NOT NULL,
  site_id TEXT NOT NULL,
  vps_fingerprint TEXT NOT NULL,
  strategy TEXT NOT NULL,
  ttl_seconds INTEGER NOT NULL,
  score REAL NOT NULL,
  component_latency REAL NOT NULL,
  component_origin_load REAL NOT NULL,
  component_cache_hit_quality REAL NOT NULL,
  component_purge_mttr REAL NOT NULL,
  hard_gate_failures_json TEXT,
  metrics_json TEXT NOT NULL,
  ai_summary TEXT,
  created_at TEXT NOT NULL,
  updated_at TEXT NOT NULL
);

CREATE UNIQUE INDEX IF NOT EXISTS idx_strategy_profiles_site_vps
  ON strategy_profiles (site_id, vps_fingerprint);

CREATE INDEX IF NOT EXISTS idx_strategy_profiles_site_updated
  ON strategy_profiles (site_id, updated_at DESC);

CREATE TABLE IF NOT EXISTS sandbox_requests (
  id TEXT PRIMARY KEY,
  plugin_id TEXT NOT NULL,
  site_id TEXT NOT NULL,
  requested_by_agent TEXT NOT NULL,
  task_type TEXT NOT NULL,
  priority_base INTEGER NOT NULL,
  estimated_minutes INTEGER NOT NULL,
  earliest_start_at TEXT,
  status TEXT NOT NULL DEFAULT 'queued',
  context_json TEXT,
  claimed_by_agent TEXT,
  claimed_at TEXT,
  created_at TEXT NOT NULL,
  updated_at TEXT NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_sandbox_requests_queue
  ON sandbox_requests (status, created_at);

CREATE TABLE IF NOT EXISTS sandbox_votes (
  id TEXT PRIMARY KEY,
  request_id TEXT NOT NULL,
  agent_id TEXT NOT NULL,
  vote INTEGER NOT NULL,
  reason TEXT,
  created_at TEXT NOT NULL,
  updated_at TEXT NOT NULL
);

CREATE UNIQUE INDEX IF NOT EXISTS idx_sandbox_votes_request_agent
  ON sandbox_votes (request_id, agent_id);

CREATE TABLE IF NOT EXISTS sandbox_allocations (
  id TEXT PRIMARY KEY,
  request_id TEXT NOT NULL,
  sandbox_id TEXT NOT NULL,
  claimed_by_agent TEXT NOT NULL,
  start_at TEXT NOT NULL,
  end_at TEXT NOT NULL,
  status TEXT NOT NULL DEFAULT 'active',
  note TEXT,
  created_at TEXT NOT NULL,
  updated_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS sandbox_conflicts (
  id TEXT PRIMARY KEY,
  plugin_id TEXT NOT NULL,
  site_id TEXT NOT NULL,
  request_id TEXT,
  agent_id TEXT NOT NULL,
  conflict_type TEXT NOT NULL,
  severity INTEGER NOT NULL,
  summary TEXT NOT NULL,
  details_json TEXT,
  blocked_by_request_id TEXT,
  sandbox_id TEXT,
  status TEXT NOT NULL DEFAULT 'open',
  resolution_note TEXT,
  resolved_by_agent TEXT,
  resolved_at TEXT,
  created_at TEXT NOT NULL,
  updated_at TEXT NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_sandbox_conflicts_plugin_status_created
  ON sandbox_conflicts (plugin_id, status, created_at);

CREATE TABLE IF NOT EXISTS loadtest_samples (
  id TEXT PRIMARY KEY,
  plugin_id TEXT NOT NULL,
  site_id TEXT NOT NULL,
  worker_id TEXT NOT NULL,
  page_url TEXT NOT NULL,
  page_path TEXT NOT NULL,
  strategy TEXT NOT NULL,
  p50_latency_ms REAL NOT NULL,
  p95_latency_ms REAL NOT NULL,
  p99_latency_ms REAL NOT NULL,
  origin_cpu_pct REAL NOT NULL,
  origin_query_count REAL NOT NULL,
  edge_hit_ratio REAL NOT NULL,
  r2_hit_ratio REAL,
  purge_mttr_ms REAL NOT NULL,
  hard_gate_passed INTEGER NOT NULL,
  hard_gate_failures_json TEXT,
  score REAL NOT NULL,
  created_at TEXT NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_loadtest_samples_site_path_created
  ON loadtest_samples (site_id, page_path, created_at DESC);

CREATE INDEX IF NOT EXISTS idx_loadtest_samples_site_strategy_created
  ON loadtest_samples (site_id, strategy, created_at DESC);
