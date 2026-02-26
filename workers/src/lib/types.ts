export interface Env {
  DB: D1Database;
  CACHE_R2?: R2Bucket;
  WP_PLUGIN_SHARED_SECRET?: string;
  CAP_TOKEN_SANDBOX_WRITE?: string;
  REPLAY_WINDOW_SECONDS?: string;
  ORIGIN_BASE_URL?: string;
}

export interface StrategyCandidateInput {
  strategy: 'edge-balanced' | 'edge-r2' | 'origin-disk' | 'object-cache';
  ttl_seconds: number;
  metrics: {
    p50_latency_ms: number;
    p95_latency_ms: number;
    p99_latency_ms: number;
    origin_cpu_pct: number;
    origin_query_count: number;
    edge_hit_ratio: number;
    r2_hit_ratio?: number;
    purge_mttr_ms: number;
  };
  gates: {
    digest_mismatch: boolean;
    personalized_cache_leak: boolean;
    purge_within_window: boolean;
    cache_key_collision: boolean;
  };
}

export interface BenchmarkRequestPayload {
  site_id: string;
  site_url?: string;
  vps_fingerprint?: string;
  current_strategy?: string;
  current_ttl_seconds?: number;
  candidates?: StrategyCandidateInput[];
  ai_summary?: string;
}
