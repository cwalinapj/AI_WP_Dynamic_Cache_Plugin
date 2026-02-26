# Operational Runbooks

This document contains step-by-step runbooks for diagnosing and resolving operational issues with the AI WP Dynamic Cache Plugin. Use these procedures as a first response before escalating.

---

## Table of Contents

1. [Cache Not Being Served](#runbook-1-cache-not-being-served)
2. [Purge Not Working](#runbook-2-purge-not-working)
3. [Worker Deployment Procedure](#runbook-3-worker-deployment-procedure)
4. [Rolling Back a Worker Deployment](#runbook-4-rolling-back-a-worker-deployment)
5. [Disaster Recovery for R2 Cache Loss](#runbook-5-disaster-recovery-for-r2-cache-loss)
6. [Monitoring Alerts and Thresholds](#runbook-6-monitoring-alerts-and-thresholds)
7. [Log Levels and Log Locations](#runbook-7-log-levels-and-log-locations)

---

## Runbook 1: Cache Not Being Served

**Symptoms:** All responses show `X-Cache-Status: MISS` or `X-Cache-Layer: ORIGIN`. Cache hit rate in Admin UI is < 5%.

### Step 1 – Verify bypass conditions are not firing unexpectedly

```bash
# Check what Cache-Control header origin is emitting
curl -sI https://example.com/blog/sample-post/ | grep -i cache-control
```

Expected output for a cacheable page:
```
Cache-Control: public, max-age=300, s-maxage=3600, stale-while-revalidate=60
```

If you see `Cache-Control: private, no-store`, a bypass condition is triggering. Common causes:

- **Logged-in user cookie present in the test request.** Use an incognito window or `curl` without cookies.
- **WooCommerce cart cookie set.** Clear `woocommerce_items_in_cart` cookie.
- **`is_admin_bar_showing()` returning true.** This fires for all logged-in users. Check the test user's role.
- **A plugin is calling `nocache_headers()` unconditionally.** Use `WP_DEBUG_LOG` and search for `nocache_headers` in `debug.log`.

### Step 2 – Verify the Cloudflare Worker is active

```bash
# Check the Worker is responding
curl -sI https://example.com/ | grep -i cf-ray
```

If `CF-RAY` header is absent, traffic is not going through Cloudflare. Check:
- DNS is pointing to Cloudflare (orange cloud in Cloudflare dashboard).
- SSL mode is not "Off".

### Step 3 – Check CDN-Cache-Control / s-maxage

Cloudflare respects `CDN-Cache-Control` over `Cache-Control: s-maxage`. Verify the plugin is emitting both:

```bash
curl -sI https://example.com/ | grep -iE '(cdn-cache-control|surrogate-control)'
```

### Step 4 – Check for Page Rules or Cache Rules overriding headers

In the Cloudflare Dashboard: **Caching → Cache Rules**. Verify no rule is setting `Cache: Bypass` for your URLs.

### Step 5 – Verify Worker KV strategy is not `disk-only` (no edge)

```bash
wrangler kv key get --binding=KV_NAMESPACE "strategy:{zone_id}"
```

If the strategy is `disk-only` or `edge-only` is missing from the response, run a benchmark to re-select the strategy or manually override:

```bash
wp ai-cache benchmark select full-stack
```

### Step 6 – Check Worker logs

```bash
wrangler tail --format=pretty | grep -i "cache"
```

Look for errors in the Worker logs when a request is processed.

---

## Runbook 2: Purge Not Working

**Symptoms:** Updated content is still being served from cache after saving a post in WordPress. Old content visible even after 10+ minutes.

### Step 1 – Verify the purge request was dispatched

Check the WordPress debug log for purge dispatch events:

```bash
tail -f /path/to/wp-content/debug.log | grep "ai_cache.*purge"
```

You should see lines like:
```
[ai_cache][purge] dispatching tags: post:123, term:45, author:7
[ai_cache][purge] response 202 purge-{uuid}
```

If no purge lines appear, the `PurgeDispatcher` hook is not firing. Check:
- The plugin is active (`wp plugin list | grep ai-wp-dynamic-cache`).
- No other plugin has removed the `save_post` hook.
- `WP_CACHE` is `true` in `wp-config.php`.

### Step 2 – Verify the HMAC signature is valid

Check the Worker logs for signature validation failures:

```bash
wrangler tail --format=json | jq 'select(.message | contains("signature"))'
```

If you see `401 Invalid signature`, the signing key may be out of sync. Re-sync:

```bash
# Get current key from WordPress
wp option get ai_cache_signing_key

# Update Worker secret
wrangler secret put SIGNING_KEY
# Paste the key value when prompted
```

### Step 3 – Verify the nonce is not being rejected (replay protection)

A purge may fail with `409 Conflict` if the same nonce was used recently (within 5 minutes). This should not happen under normal operation but can occur if the WordPress clock is drifted.

Check server clock:
```bash
date -u && curl -sI https://cloudflare.com | grep -i date
```

If the skew is > 60 seconds, sync the server clock:
```bash
sudo timedatectl set-ntp true
sudo systemctl restart systemd-timesyncd
```

### Step 4 – Verify Cloudflare Cache-Tag Purge API permissions

The plugin uses Cloudflare's Cache-Tag Purge API, which requires a token with `Cache Purge` permission. Verify:

```bash
curl -X POST "https://api.cloudflare.com/client/v4/zones/{zone_id}/purge_cache" \
  -H "Authorization: Bearer {CF_TOKEN}" \
  -H "Content-Type: application/json" \
  -d '{"tags":["site"]}'
```

Expected response: `{"success": true, ...}`

If the response shows a permission error, regenerate the Cloudflare API token with `Cache Purge` rights and update in Admin UI.

### Step 5 – Check R2 purge

Cloudflare Edge cache may be purged but R2 objects may persist. Query R2 directly:

```bash
wrangler r2 object get ai-cache-bucket "cache/{zone_id}/{hash[0:2]}/{hash[2:4]}/{hash}"
```

If the object exists, R2 purge did not complete. Check Worker logs for R2 delete errors.

### Step 6 – Manual emergency purge

If automated purge is broken and content must be cleared immediately:

```bash
# Via WP-CLI (triggers full purge)
wp ai-cache purge --global

# Via Cloudflare dashboard (nuclear option)
# Caching → Configuration → Purge Everything
```

---

## Runbook 3: Worker Deployment Procedure

### Pre-Deployment Checklist

- [ ] Tests pass: `npm test`
- [ ] TypeScript compiles: `npm run typecheck`
- [ ] Lint passes: `npm run lint`
- [ ] `wrangler.toml` reviewed for config changes
- [ ] Secrets verified in Cloudflare dashboard
- [ ] Deployment window communicated to stakeholders (if production)

### Deployment Steps

```bash
# 1. Authenticate (if needed)
wrangler login

# 2. Deploy to staging first
wrangler deploy --env staging

# 3. Smoke test staging
curl -sI https://staging.example.com/ | grep -iE '(cf-ray|x-cache)'

# 4. Run integration tests against staging
npm run test:integration -- --env=staging

# 5. Deploy to production
wrangler deploy --env production

# 6. Verify production deployment
wrangler deployments list

# 7. Smoke test production
curl -sI https://example.com/ | grep -iE '(cf-ray|x-cache-status)'

# 8. Monitor error rate for 10 minutes
wrangler tail --format=pretty | grep -i error
```

### Post-Deployment

- Monitor the Admin UI cache hit rate graph for 30 minutes.
- Check D1 `purge_log` and `preload_log` for unexpected errors.
- If error rate spikes, execute [Runbook 4: Rolling Back](#runbook-4-rolling-back-a-worker-deployment).

---

## Runbook 4: Rolling Back a Worker Deployment

**Trigger:** Error rate > 2%, or cache hit rate drops > 20 percentage points after deployment.

### Immediate Rollback (< 5 minutes)

Cloudflare retains the last 10 Worker deployments. Roll back to the previous version:

```bash
# List recent deployments
wrangler deployments list

# Roll back to previous deployment by ID
wrangler rollback <previous-deployment-id>
```

Or via the Cloudflare Dashboard: **Workers & Pages → {worker-name} → Deployments → Roll back**.

### Verify Rollback

```bash
# Check active deployment version
wrangler deployments list | head -5

# Confirm Worker is serving correctly
curl -sI https://example.com/ | grep cf-ray
```

### If Rollback Does Not Resolve the Issue

1. Check if the issue is in KV config (not Worker code):

```bash
wrangler kv key get --binding=KV_NAMESPACE "config:{zone_id}"
```

Compare with the last known-good config. If the config is corrupted, restore from the backup in D1:

```sql
-- Get last good config from D1
SELECT config_json, created_at FROM config_snapshots
WHERE zone_id = '{zone_id}'
ORDER BY created_at DESC
LIMIT 5;
```

2. If the issue is with R2 data, see [Runbook 5](#runbook-5-disaster-recovery-for-r2-cache-loss).

3. As a last resort, disable the Worker entirely and serve traffic from origin:
   - In Cloudflare Dashboard: **Workers Routes** → remove the route for the Worker.
   - Origin disk cache will continue to serve cached pages.

---

## Runbook 5: Disaster Recovery for R2 Cache Loss

**Scenario:** R2 bucket is accidentally deleted, objects are corrupted, or R2 is experiencing a regional outage.

### Impact Assessment

R2 cache loss means:
- All requests that would have been served from R2 fall through to origin.
- Depending on traffic volume, this may spike origin CPU/DB load.
- Cloudflare Edge cache is **unaffected** — it operates independently of R2.

### Immediate Response

```bash
# 1. Check if edge cache is still serving most traffic
# If edge hit rate is > 70%, R2 loss is manageable (edge cache is serving)
curl -sI https://example.com/ | grep x-cache-status

# 2. Check R2 bucket status
wrangler r2 bucket list

# 3. If bucket is missing, recreate it
wrangler r2 bucket create ai-cache-bucket

# 4. Update wrangler.toml if the bucket name changed, then redeploy
wrangler deploy --env production
```

### R2 Cache Rebuild

R2 is a cache, not a source of truth. The authoritative content is always the origin. To rebuild R2:

```bash
# Trigger a full-site preload (will repopulate R2 from origin)
wp ai-cache preload --sitemap --priority=100

# Or via Admin UI: Cache → Preload → "Preload Entire Site"
```

The preload system will crawl the sitemap and populate R2 over the next 30–60 minutes depending on site size and rate limits.

### Preventing R2 Cache Storms

If origin is already under load due to R2 miss traffic, throttle the preload:

```bash
# Reduce preload concurrency to 2 to protect origin
wrangler kv key put --binding=KV_NAMESPACE "config:{zone_id}" \
  "$(wrangler kv key get --binding=KV_NAMESPACE "config:{zone_id}" \
    | jq '.preload_max_concurrency = 2')"
```

### KV Tag Index Rebuild

After R2 loss and rebuild, the KV tag index may be stale (pointing to deleted objects). Rebuild:

```bash
wp ai-cache rebuild-tag-index
```

This command re-scans all R2 objects (after preload completes) and rebuilds the KV tag-index entries.

---

## Runbook 6: Monitoring Alerts and Thresholds

### Alert Table

| Alert | Threshold | Severity | Action |
|---|---|---|---|
| Cache hit rate (edge) | < 60% for 5 min | WARNING | Check bypass conditions (Runbook 1, Step 1) |
| Cache hit rate (edge) | < 30% for 10 min | CRITICAL | Full diagnosis (Runbook 1) |
| Purge failure rate | > 5% for 2 min | WARNING | Check signing key and CF token (Runbook 2) |
| Purge failure rate | > 20% for 5 min | CRITICAL | Escalate; manual purge if needed |
| Preload failure rate | > 10% for 5 min | WARNING | Check origin health; check circuit breaker |
| Preload queue depth | > 5,000 | WARNING | Investigate queue drain rate |
| Worker error rate (5xx) | > 2% for 2 min | CRITICAL | Check Worker logs; consider rollback (Runbook 4) |
| D1 write latency | P95 > 500 ms | WARNING | Check Cloudflare status; not actionable |
| R2 write latency | P95 > 2,000 ms | WARNING | Monitor; check Cloudflare status |
| Origin TTFB (via preload) | P95 > 3,000 ms | WARNING | Check origin server load; DB performance |
| Circuit breaker open | Any zone | CRITICAL | Check origin health immediately |

### Alert Delivery

Alerts are written to:
- D1 `alerts` table (always)
- WordPress admin notice (for WARNING and above)
- Email to `admin_email` WordPress option (for CRITICAL, configurable)
- Webhook (Slack/PagerDuty, if `ai_cache_alert_webhook_url` option is set)

### Checking Alert Status

```bash
# Via WP-CLI
wp ai-cache alerts list

# Via D1 directly
# Note: substitute {zone_id} with the actual zone ID string before running;
# wrangler d1 execute passes the full command string to SQLite so ensure
# the value contains only alphanumeric characters to avoid injection.
wrangler d1 execute ai-cache-db --command \
  "SELECT * FROM alerts WHERE zone_id = '{zone_id}' ORDER BY created_at DESC LIMIT 20;"
```

---

## Runbook 7: Log Levels and Log Locations

### WordPress Plugin Logs

Plugin events are written to the standard WordPress debug log when `WP_DEBUG_LOG` is enabled in `wp-config.php`:

```php
define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);
define('WP_DEBUG_DISPLAY', false);
```

**Log location:** `/wp-content/debug.log` (or as configured by `WP_DEBUG_LOG` path).

**Log format:**

```
[2024-01-15 12:34:56 UTC] [ai_cache][{level}][{component}] {message} {context_json}
```

**Log levels and components:**

| Level | Meaning | Examples |
|---|---|---|
| `DEBUG` | Verbose trace (development only) | Cache key constructed, header emitted |
| `INFO` | Normal operation events | Purge dispatched, strategy changed, benchmark started |
| `WARNING` | Recoverable issue | Purge retry, slow origin response, DLQ item |
| `ERROR` | Failed operation | HMAC failure, R2 write error, Agent HTTP error |
| `CRITICAL` | System-level failure | Circuit breaker open, signing key missing |

**Setting the log level:**

```bash
wp option set ai_cache_log_level INFO
```

### Cloudflare Worker Logs

Worker logs are available via the Cloudflare Dashboard (**Workers & Pages → {worker} → Logs**) or via `wrangler tail`:

```bash
# Stream live logs
wrangler tail --format=pretty

# Filter to errors only
wrangler tail --format=json | jq 'select(.level == "error")'

# Filter to a specific request
wrangler tail --format=json | jq 'select(.event.request.url | contains("/blog/my-post/"))'
```

**Worker log format:**

```json
{
  "level": "info",
  "component": "purge",
  "message": "Purge accepted",
  "zone_id": "abc123",
  "tags": ["post:123", "term:45"],
  "purge_id": "purge-uuid",
  "duration_ms": 42
}
```

### D1 Audit Log Queries

The D1 database is the authoritative record of all system actions. Common diagnostic queries:

```bash
# Last 20 purge events for a zone
wrangler d1 execute ai-cache-db --command \
  "SELECT * FROM purge_log WHERE zone_id = 'abc123' ORDER BY created_at DESC LIMIT 20;"

# Purge events for a specific post
wrangler d1 execute ai-cache-db --command \
  "SELECT * FROM purge_log WHERE tags LIKE '%post:123%' ORDER BY created_at DESC LIMIT 10;"

# Preload failures in last hour
wrangler d1 execute ai-cache-db --command \
  "SELECT * FROM preload_log WHERE status = 'failed'
   AND created_at > (UNIXEPOCH() - 3600) ORDER BY created_at DESC;"

# Benchmark history
wrangler d1 execute ai-cache-db --command \
  "SELECT strategy, ttfb_p95_ms, created_at FROM benchmark_results
   WHERE zone_id = 'abc123' ORDER BY created_at DESC LIMIT 10;"
```

### Log Retention

| Log store | Retention | Notes |
|---|---|---|
| WordPress `debug.log` | Until manual rotation | Use `logrotate` in production |
| Cloudflare Worker logs (tail) | Real-time only; no persistence | Use `wrangler tail` during incidents |
| D1 `purge_log` | 90 days (auto-purge via Cron Trigger) | Configurable via `audit_log_retention_days` |
| D1 `preload_log` | 30 days | Configurable |
| D1 `benchmark_results` | Indefinite | Small table; no auto-purge |
| D1 `alerts` | 90 days | Configurable |
