# AI WP Dynamic Cache Plugin

WordPress dynamic cache plugin plus Cloudflare Worker control plane skeleton with sandbox benchmark scoring.

## Included now

- Root WordPress plugin implementation:
  - `ai-wp-dynamic-cache.php`
- Modular plugin skeleton:
  - `wordpress-plugin/ai-wp-dynamic-cache.php`
  - `wordpress-plugin/src/Plugin.php`
- Worker skeleton:
  - `workers/src/index.ts`
  - `workers/src/lib/scoring.ts`
  - `workers/src/lib/signature.ts`
  - `workers/src/db/schema.sql`
- Docs:
  - `docs/architecture.md`
  - `docs/cache-keying.md`
  - `docs/purge-and-tags.md`
  - `docs/shared-loadtests.md`
- Sandbox benchmarking scripts:
  - `scripts/sandbox/k6-cache-benchmark.js`
  - `scripts/sandbox/run-lighthouse.sh`
  - `scripts/sandbox/share-loadtests.js`

## Scoring model implemented

Hard gates (fail fast):

- `digest_mismatch`
- `personalized_cache_leak`
- `purge_within_window` must be true
- `cache_key_collision`

Weighted score:

- 60% latency score (p95-heavy)
- 20% origin load score
- 10% cache hit quality score
- 10% purge MTTR score

The Worker stores the selected per-site/per-VPS strategy profile in D1 (`strategy_profiles`) and returns the recommended strategy/TTL to the plugin.

Shared optimization layer:

- workers publish per-page load-test samples to D1 (`loadtest_samples`)
- benchmark route adds a small strategy bonus using shared historical p95 data
- plugin admin shows a shared page/strategy leaderboard to guide tuning

## AI boundaries

AI should be optional and advisory only:

- classify route/template cache risk
- prioritize preload under budget
- summarize benchmark outcomes
- suggest safe tuning changes

Serving remains deterministic in Worker + plugin logic.

## Worker endpoints in skeleton

- `POST /plugin/wp/cache/benchmark`
- `GET /plugin/wp/cache/profile?site_id=...&vps_fingerprint=...`
- `GET /edge/cache/*` (edge -> R2 -> origin cache chain skeleton)
- `POST /plugin/wp/sandbox/request`
- `POST /plugin/wp/sandbox/vote`
- `POST /plugin/wp/sandbox/claim`
- `POST /plugin/wp/sandbox/release`
- `POST /plugin/wp/sandbox/conflicts/report`
- `POST /plugin/wp/sandbox/conflicts/list`
- `POST /plugin/wp/sandbox/conflicts/resolve`
- `POST /plugin/wp/sandbox/loadtests/report`
- `POST /plugin/wp/sandbox/loadtests/shared`

## Build plugin zip

```bash
bash scripts/build-plugin-zip.sh
```

Output:

- `dist/ai-wp-dynamic-cache-plugin.zip`

## Worker setup

```bash
cd workers
npm install
npm run build
```

Then set real values in `workers/wrangler.toml`:

- `database_id`
- `ORIGIN_BASE_URL`
- secrets:
  - `WP_PLUGIN_SHARED_SECRET`
  - `CAP_TOKEN_SANDBOX_WRITE` (for sandbox routes)

## Sandbox benchmark scripts

Run k6 against a target:

```bash
k6 run scripts/sandbox/k6-cache-benchmark.js -e TARGET_URL=https://your-site.example
```

Run Lighthouse profile:

```bash
bash scripts/sandbox/run-lighthouse.sh https://your-site.example sandbox-results
```

Publish shared per-page tests from each worker:

```bash
cat > sandbox-results/page-tests.json <<'JSON'
[
  {
    "url": "https://your-site.example/",
    "p50_latency_ms": 120,
    "p95_latency_ms": 260,
    "p99_latency_ms": 420,
    "origin_cpu_pct": 42,
    "origin_query_count": 34,
    "edge_hit_ratio": 0.88,
    "r2_hit_ratio": 0.44,
    "purge_mttr_ms": 380,
    "gates": {
      "digest_mismatch": false,
      "personalized_cache_leak": false,
      "purge_within_window": true,
      "cache_key_collision": false
    }
  }
]
JSON

WORKER_BASE_URL=https://worker.example \
WP_PLUGIN_SHARED_SECRET=... \
CAP_TOKEN_SANDBOX_WRITE=... \
SITE_ID=site-1 \
WORKER_ID=edge-worker-a \
PLUGIN_ID=site-1 \
LOADTEST_FILE=sandbox-results/page-tests.json \
node scripts/sandbox/share-loadtests.js
```
