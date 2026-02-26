# Sandbox Benchmarking

This document describes the **sandbox benchmarking system** — a self-contained Docker Compose environment that runs on the target VPS to measure the performance of each caching strategy and automatically selects the optimal one.

---

## Table of Contents

1. [Overview](#overview)
2. [Docker Compose Environment](#docker-compose-environment)
3. [Strategy Matrix](#strategy-matrix)
4. [k6 Load Test Scenarios](#k6-load-test-scenarios)
5. [Lighthouse Score Collection](#lighthouse-score-collection)
6. [Results Interpretation and Strategy Selection](#results-interpretation-and-strategy-selection)
7. [Running Benchmarks Manually](#running-benchmarks-manually)
8. [CI Integration](#ci-integration)

---

## Overview

Every VPS has different performance characteristics:

- **Disk I/O speed** determines how fast origin disk cache is served.
- **Memory / Redis latency** determines how fast object cache responses are.
- **Network egress speed** affects how quickly Cloudflare can pull from origin on cache miss.
- **CPU capacity** determines whether PHP render time dominates other costs.

A fixed strategy ("always use Redis", "always use Cloudflare edge") is therefore suboptimal. The benchmark system:

1. Spins up an isolated Docker Compose stack alongside the production WordPress environment.
2. Runs a matrix of strategies against realistic traffic patterns using **k6**.
3. Collects P50, P95, and P99 time-to-first-byte (TTFB) metrics for each strategy.
4. Runs **Lighthouse** for Core Web Vitals scores.
5. Feeds results to `StrategySelector`, which picks the strategy with the best TTFB P95 while maintaining a Lighthouse Performance score ≥ a configurable threshold.
6. Writes the selected strategy to Workers KV.

Benchmarks run automatically on:
- First plugin activation.
- Every 7 days (WP-Cron).
- Manually from the Admin UI.
- After significant infrastructure changes detected by the plugin (e.g., Redis becoming available).

---

## Docker Compose Environment

The benchmark stack is defined in `sandbox/docker-compose.yml`. It is isolated from the production database; it uses a **cloned copy of the production database** (anonymized via wp-cli `anonymize-db`) and a copy of uploaded media.

### Services

```yaml
services:
  benchmark-wp:          # WordPress + PHP-FPM (mirrors production PHP version)
  benchmark-nginx:       # Nginx (mirrors production Nginx config)
  benchmark-db:          # MariaDB (cloned from production)
  benchmark-redis:       # Redis 7 (for object cache benchmarks)
  benchmark-worker:      # Miniflare (local Cloudflare Worker emulation)
  benchmark-k6:          # k6 load tester
  benchmark-lighthouse:  # Lighthouse CLI (headless Chrome)
  benchmark-results:     # Lightweight HTTP server to collect + aggregate results
```

### Isolation

The benchmark stack runs on an internal Docker bridge network (`172.29.0.0/24`) with **no external internet access** from the WordPress container. Cloudflare edge is simulated by Miniflare running locally on port 8787.

> **Benchmark accuracy note:** Blocking external network calls means services such as Gravatar, external CDN-hosted fonts, and third-party analytics are not fetched during benchmarks. This produces conservative TTFB measurements that reflect only origin + caching performance — which is exactly what the strategy selector needs. Lighthouse runs against the local stack so scores may differ from production for assets that rely on external CDNs; this is an accepted trade-off for fully reproducible, network-isolated results.

### Data Preparation

```bash
# Anonymize production DB into benchmark DB
cd sandbox
./scripts/prepare-db.sh

# Sync media (symlinks, not copies, to save disk)
./scripts/sync-media.sh
```

### Teardown

The stack is automatically torn down after benchmarks complete:

```bash
docker compose -f sandbox/docker-compose.yml down -v --remove-orphans
```

---

## Strategy Matrix

Each strategy is a named combination of cache layers. The benchmark runs each strategy independently in a clean environment.

| Strategy ID | Edge Cache | R2 | Origin Disk | Object Cache | Description |
|---|---|---|---|---|---|
| `disk-only` | ❌ | ❌ | ✅ | ❌ | PHP generates then caches to disk; no CDN |
| `disk+objcache` | ❌ | ❌ | ✅ | ✅ | Disk cache + Redis object cache |
| `disk+edge` | ✅ | ❌ | ✅ | ❌ | Disk cache with Cloudflare Edge CDN in front |
| `disk+edge+objcache` | ✅ | ❌ | ✅ | ✅ | Disk + Edge + Redis |
| `disk+r2` | ❌ | ✅ | ✅ | ❌ | Disk cache + R2 long-lived storage |
| `full-stack` | ✅ | ✅ | ✅ | ✅ | All layers active (default recommendation for high-traffic) |
| `edge-only` | ✅ | ❌ | ❌ | ❌ | No disk cache; rely entirely on edge |

### Strategy Configuration Files

Each strategy maps to a JSON config file in `sandbox/strategies/`:

```
sandbox/strategies/
├── disk-only.json
├── disk+objcache.json
├── disk+edge.json
├── disk+edge+objcache.json
├── disk+r2.json
├── full-stack.json
└── edge-only.json
```

The benchmark runner injects these configs into the running WordPress and Miniflare instances before each test run.

---

## k6 Load Test Scenarios

k6 scripts live in `sandbox/k6/`. Each scenario targets a specific traffic pattern.

### Scenario 1 – Warm Cache (Steady State)

Simulates traffic after the cache is fully warmed. Measures sustained throughput and latency at moderate concurrency.

```javascript
// sandbox/k6/scenarios/warm-cache.js
export const options = {
  scenarios: {
    warm_cache: {
      executor: 'constant-vus',
      vus: 20,
      duration: '60s',
    },
  },
  thresholds: {
    http_req_duration: ['p(95)<500'],
    http_req_failed: ['rate<0.01'],
  },
};
```

**URL distribution:** Weighted random sample of top-100 URLs from the sitemap (simulated via `sandbox/k6/data/urls.csv`), with a Zipf distribution (top 10 URLs get 80% of traffic).

### Scenario 2 – Cold Start

Simulates traffic immediately after a full cache purge. Measures how quickly the cache warms and TTFB recovers.

```javascript
// sandbox/k6/scenarios/cold-start.js
export const options = {
  scenarios: {
    cold_start: {
      executor: 'ramping-vus',
      startVUs: 1,
      stages: [
        { duration: '10s', target: 1 },   // trickle start
        { duration: '30s', target: 20 },  // ramp up
        { duration: '20s', target: 20 },  // sustain
      ],
    },
  },
};
```

Before this scenario runs, the benchmark runner calls `POST /api/v1/purge { global: true }` to clear all caches.

### Scenario 3 – High Concurrency

Simulates a traffic spike (e.g., a viral post or being featured on a news aggregator).

```javascript
// sandbox/k6/scenarios/high-concurrency.js
export const options = {
  scenarios: {
    spike: {
      executor: 'ramping-arrival-rate',
      startRate: 10,
      timeUnit: '1s',
      preAllocatedVUs: 100,
      stages: [
        { duration: '10s', target: 10 },
        { duration: '5s',  target: 200 },   // spike
        { duration: '30s', target: 200 },   // sustain spike
        { duration: '10s', target: 10 },    // cool down
      ],
    },
  },
};
```

### Scenario 4 – Mixed Read/Write (WooCommerce)

Only runs when WooCommerce is active. Simulates a mix of product browsing (cacheable) and cart/checkout (bypass).

```javascript
// sandbox/k6/scenarios/woocommerce.js
// 80% browse (cacheable), 15% add-to-cart (bypass), 5% checkout (bypass)
```

### Metrics Collected per Scenario

| Metric | Description |
|---|---|
| `http_req_duration` | Full request duration (P50, P95, P99) |
| `http_req_waiting` | Time-to-first-byte (TTFB) |
| `http_req_failed` | Error rate |
| `cache_hit_rate` | Custom metric from `X-Cache-Status` header |
| `r2_hit_rate` | Custom metric from `X-Cache-Layer: R2` header |
| `origin_hit_rate` | Custom metric from `X-Cache-Layer: ORIGIN` header |
| `iterations` | Total requests completed |

---

## Lighthouse Score Collection

After each strategy's k6 scenarios complete, Lighthouse is run in headless mode against the benchmark WordPress instance to capture Core Web Vitals.

```bash
lighthouse \
  http://localhost:8080/ \
  --only-categories=performance \
  --output=json \
  --output-path=results/lighthouse-{strategy}.json \
  --chrome-flags="--headless --no-sandbox" \
  --preset=desktop
```

### Lighthouse Metrics Used

| Metric | Weight in Selection | Target |
|---|---|---|
| Performance Score | Gate (must be ≥ threshold) | ≥ 85 |
| LCP (Largest Contentful Paint) | Informational | < 2.5 s |
| TBT (Total Blocking Time) | Informational | < 200 ms |
| CLS (Cumulative Layout Shift) | Informational | < 0.1 |
| FCP (First Contentful Paint) | Informational | < 1.8 s |

Lighthouse scores are primarily used as a **gate** — a strategy that degrades Lighthouse Performance below the configured minimum is disqualified, regardless of TTFB.

---

## Results Interpretation and Strategy Selection

### Results Schema

After all runs, the benchmark aggregator (`sandbox/scripts/aggregate-results.py`) produces a `results/summary.json`:

```json
{
  "zone_id": "abc123xyz",
  "timestamp": 1700000000,
  "strategies": [
    {
      "id": "full-stack",
      "ttfb_p50_ms": 45,
      "ttfb_p95_ms": 120,
      "ttfb_p99_ms": 380,
      "error_rate": 0.001,
      "cache_hit_rate": 0.97,
      "lighthouse_performance": 91,
      "disqualified": false,
      "disqualification_reason": null
    },
    ...
  ]
}
```

### Selection Algorithm

```
1. Disqualify any strategy where:
   - error_rate > 0.02  (> 2% errors)
   - lighthouse_performance < min_lighthouse_score (default: 85)
   - ttfb_p99_ms > 5000  (catastrophic tail latency)

2. From remaining strategies, select the one with the lowest ttfb_p95_ms.

3. In case of a tie (within 10 ms), prefer the strategy with fewer active
   layers (lower infrastructure complexity).

4. Write the selected strategy ID to Workers KV: strategy:{zone_id}

5. Write the full results JSON to D1: benchmark_results table.

6. Emit a WordPress admin notice with the selected strategy and summary.
```

### Fallback

If all strategies are disqualified (e.g., Redis is down), the algorithm falls back to `disk-only`, which has no external dependencies.

---

## Running Benchmarks Manually

### Via Admin UI

Navigate to **Settings → AI Cache → Benchmarks** and click **"Run Benchmarks Now"**. Progress is shown in real time via a polling REST endpoint.

### Via WP-CLI

```bash
wp ai-cache benchmark run
wp ai-cache benchmark run --strategy=full-stack  # single strategy only
wp ai-cache benchmark status                     # check last run results
wp ai-cache benchmark select full-stack          # manually override strategy
```

### Via Shell Script

```bash
cd sandbox
./run-benchmarks.sh [--strategy=<id>] [--skip-lighthouse] [--json-output=path/to/file.json]
```

---

## CI Integration

GitHub Actions runs a subset of the benchmark suite (scenarios 1 and 2, without Lighthouse) on every push to `main` to detect performance regressions:

```yaml
# .github/workflows/benchmark.yml
jobs:
  benchmark:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - name: Run sandbox benchmarks
        run: cd sandbox && ./run-benchmarks.sh --skip-lighthouse --ci
      - name: Upload results
        uses: actions/upload-artifact@v4
        with:
          name: benchmark-results
          path: sandbox/results/summary.json
```

The CI script fails if any strategy's `ttfb_p95_ms` regresses by more than 20% compared to the baseline stored in `sandbox/baselines/`.
