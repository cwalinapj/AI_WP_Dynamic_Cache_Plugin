# Shared Page Load Tests

## Purpose

Share per-page speed tests from all workers into one D1 pool so benchmark strategy selection can learn from fleet-wide performance patterns.

## Write endpoint

- `POST /plugin/wp/sandbox/loadtests/report`

Required payload fields:

- `site_id`
- `worker_id` (or `agent_id`)
- `strategy`
- `page_tests` (array)

Example `page_tests` item:

```json
{
  "url": "https://example.com/pricing/",
  "p50_latency_ms": 140,
  "p95_latency_ms": 320,
  "p99_latency_ms": 510,
  "origin_cpu_pct": 48,
  "origin_query_count": 38,
  "edge_hit_ratio": 0.83,
  "r2_hit_ratio": 0.31,
  "purge_mttr_ms": 420,
  "gates": {
    "digest_mismatch": false,
    "personalized_cache_leak": false,
    "purge_within_window": true,
    "cache_key_collision": false
  }
}
```

Each sample is scored with the same hard-gate + weighted model as benchmark candidates and stored in `loadtest_samples`.

## Read endpoint

- `POST /plugin/wp/sandbox/loadtests/shared`

Filters:

- `site_id` (required)
- `strategy` (optional)
- `page_path` (optional)
- `only_passing` (optional, default true)
- `limit` (optional)

Returns:

- `shared_page_profiles` aggregated by page + strategy
- `strategy_leaderboard` aggregated by strategy

## How optimization uses shared data

During `/plugin/wp/cache/benchmark`, Worker computes a strategy bonus from historical shared p95 values:

- lower shared p95 gets higher bonus
- bonus is confidence-weighted by sample count
- bonus is additive only for hard-gate passing candidates

This keeps serving deterministic while still leveraging fleet learning.
