# Architecture

## Goals

- Keep request serving deterministic and independent from AI availability.
- Use sandbox benchmarks to pick the best cache strategy per VPS.
- Persist strategy profiles in D1 so Workers enforce a known-good profile.

## High-level components

- WordPress plugin (`ai-wp-dynamic-cache.php` + `wordpress-plugin/src/Plugin.php`)
  - Sends signed benchmark payloads and sandbox operations.
  - Applies safe cache headers on frontend responses.
- Cloudflare Worker (`workers/src/index.ts`)
  - Verifies signed plugin requests.
  - Evaluates benchmark candidates with hard gates + weighted scoring.
  - Persists profile in D1 (`strategy_profiles`).
  - Exposes sandbox queue + conflict endpoints for multi-agent coordination.
  - Executes edge cache chain: Edge Cache API -> R2 -> Origin.
- D1
  - Stores per-site, per-VPS strategy profile with component scores.
  - Stores shared per-page load-test samples from all workers.
- R2
  - Secondary cache layer for larger or reusable objects.

## Request flow

```mermaid
flowchart TD
  A["WP Plugin"] -->|"POST /plugin/wp/cache/benchmark (signed)"| B["Worker Benchmark Route"]
  B --> C["Hard Gates + Weighted Scoring"]
  C --> D["D1 strategy_profiles upsert"]
  D --> E["Recommended strategy response"]
  E --> A

  U["End user request"] --> F["Worker /edge/cache/*"]
  F --> G{"Edge Cache API hit?"}
  G -->|"yes"| H["Return edge response"]
  G -->|"no"| I{"R2 hit?"}
  I -->|"yes"| J["Return R2 + refill Edge"]
  I -->|"no"| K["Fetch Origin"]
  K --> L["Store in Edge/R2 when cacheable"]
  L --> M["Return response"]
```

## Benchmark scoring model

Hard gates (any failure rejects the candidate):

- digest mismatch (wrong content)
- personalized content leak for authenticated users
- purge outside max invalidation window
- cache key collision

Weighted score:

- 60% latency score (p95 emphasized)
- 20% origin load score
- 10% cache hit quality
- 10% purge MTTR score

Result:

- Highest score among gate-passing candidates is selected.
- Selected profile is persisted by `site_id + vps_fingerprint`.
- Shared strategy bonus (from historical per-page p95 across workers) is added to passing candidates to improve convergence on real-world winners.

## AI role boundaries

AI can assist with:

- route/template risk classification
- preload prioritization under budget
- benchmark result summarization
- safe tuning suggestions

AI is not in runtime serving path. Request serving and cache decisions use deterministic rules and persisted profiles.
