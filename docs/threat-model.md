# Threat Model

This document describes the security architecture of the AI WP Dynamic Cache Plugin, including trust boundaries, authentication mechanisms, and mitigations for known threats.

---

## Table of Contents

1. [Scope](#scope)
2. [Trust Boundaries](#trust-boundaries)
3. [HMAC Request Signing](#hmac-request-signing)
4. [Replay Attack Prevention](#replay-attack-prevention)
5. [Idempotency Key Design](#idempotency-key-design)
6. [Rate Limiting on Purge and Preload Endpoints](#rate-limiting-on-purge-and-preload-endpoints)
7. [Secrets Management](#secrets-management)
8. [XSS and CSRF Protection in the Admin UI](#xss-and-csrf-protection-in-the-admin-ui)
9. [D1 and KV Injection Considerations](#d1-and-kv-injection-considerations)
10. [Cache Poisoning Threats](#cache-poisoning-threats)
11. [Known Risks and Accepted Limitations](#known-risks-and-accepted-limitations)

---

## Scope

This threat model covers the components of the system that are within the project's control:

- **WordPress Plugin** (PHP, running on the origin server)
- **Signed Agent** (PHP, issues signed requests to Cloudflare Worker)
- **Cloudflare Edge Worker** (TypeScript, processes HTTP requests and management API calls)
- **Workers KV** (config and tag-index store)
- **R2 Object Store** (cached HTML)
- **D1 SQLite** (audit log)
- **WordPress Admin UI** (PHP-rendered admin pages)

Out-of-scope: Cloudflare platform security, WordPress core vulnerabilities, server OS hardening, and physical infrastructure.

---

## Trust Boundaries

```
┌──────────────────────────────────────────────────────────────────────┐
│  Zone A: Cloudflare Network (Worker, KV, R2, D1)                     │
│  Trusted by: the operator who owns the Cloudflare account            │
│  Authentication required for inbound management API calls            │
└──────────────────────────────────────────────────────────────────────┘
          ▲ HMAC-signed HTTPS               ▼ Cache-Control headers (passive)
┌─────────────────────────────────────────────────────────────────────┐
│  Zone B: Origin Server (WordPress Plugin + Signed Agent)             │
│  Trusted by: the operator who controls the VPS / hosting             │
│  Plugin output treated as trusted IF: server not compromised         │
└─────────────────────────────────────────────────────────────────────┘
          ▲ WordPress nonce-protected         ▼ HMAC-signed REST calls
┌─────────────────────────────────────────────────────────────────────┐
│  Zone C: WordPress Admin (authenticated WP administrator)            │
│  Trusted by: operator with WP admin credentials                      │
│  Subject to: XSS/CSRF mitigations, capability checks                │
└─────────────────────────────────────────────────────────────────────┘

┌─────────────────────────────────────────────────────────────────────┐
│  Zone D: Public Internet (unauthenticated visitors)                  │
│  Untrusted: all inputs from this zone are sanitized and validated    │
└─────────────────────────────────────────────────────────────────────┘
```

### Cross-Boundary Communication

| From | To | Channel | Authentication |
|---|---|---|---|
| Zone B (Plugin) | Zone A (Worker API) | HTTPS POST | HMAC-SHA256 signed request |
| Zone C (Admin) | Zone B (Plugin REST) | HTTPS POST | WordPress nonce + `manage_options` capability |
| Zone D (Visitor) | Zone A (Cloudflare Edge) | HTTPS GET | None (public content) |
| Zone A (Worker) | Zone B (Origin fetch) | HTTPS GET | None (public content; Worker acts as authenticated proxy for management operations only) |

---

## HMAC Request Signing

All management API calls from the WordPress Signed Agent to the Cloudflare Worker are authenticated using **HMAC-SHA256**.

### Signature Algorithm

The signature covers a canonical string that includes the HTTP method, path, timestamp, nonce, and a hash of the request body. This ensures that:

- A signature cannot be reused for a different endpoint (method + path included).
- A signature cannot be replayed after the timestamp expires (timestamp included).
- A signature cannot be reused with a modified body (body hash included).

**Canonical string format:**

```
{METHOD}\n
{PATH}\n
{TIMESTAMP_UNIX_SECONDS}\n
{NONCE_HEX_16_BYTES}\n
{SHA256_HEX(request_body)}
```

**Signature computation:**

```
signature = HMAC-SHA256(signing_key, canonical_string)
```

**Request headers:**

```http
X-Timestamp: 1700000000
X-Nonce: a3f9e2d1b8c7461f
X-Signature: <lowercase hex HMAC-SHA256>
```

### Signing Key

The signing key is a 256-bit (32-byte) cryptographically random secret:

- **Stored on origin:** `wp_options` table, option name `ai_cache_signing_key`, encrypted at rest using WordPress's secret key salts as a KDF input.
- **Stored in Worker:** Cloudflare Worker Secret (`SIGNING_KEY` environment variable). Never in source code or `wrangler.toml`.

### Key Rotation

Keys should be rotated every 90 days. The rotation procedure:

1. Generate new key: `openssl rand -hex 32`
2. Update Cloudflare secret: `wrangler secret put SIGNING_KEY`
3. Update WordPress option via WP-CLI: `wp ai-cache rotate-key <new_key>`
4. There is a 5-minute grace period during which both old and new keys are accepted (double-keying), to accommodate in-flight requests.

---

## Replay Attack Prevention

Even with a valid HMAC signature, a captured request could be replayed. The system prevents replay attacks with two complementary mechanisms.

### Timestamp Window

The Worker rejects any request whose `X-Timestamp` is more than **300 seconds** (5 minutes) in the past or more than **30 seconds** in the future (to account for minor clock drift).

```typescript
const now = Math.floor(Date.now() / 1000);
const skew = Math.abs(now - requestTimestamp);
if (skew >= 300) {
  return new Response('Request expired', { status: 401 });
}
```

### Nonce Validation

The `X-Nonce` (16-byte random hex) must be unique within the timestamp window. The Worker checks the nonce against the D1 `purge_log` / `preload_log` tables:

```sql
SELECT COUNT(*) FROM purge_log
WHERE nonce = ? AND created_at > (UNIXEPOCH() - 300);
```

If the nonce has been seen within the last 300 seconds, the request is rejected with `409 Conflict`.

After the timestamp window expires, the nonce is no longer needed for replay protection and old entries can be archived/deleted.

---

## Idempotency Key Design

Every purge and preload request includes a client-generated `idempotency_key` (UUID v4 with a type prefix):

```
purge-550e8400-e29b-41d4-a716-446655440000
preload-6ba7b810-9dad-11d1-80b4-00c04fd430c8
```

The Worker stores the idempotency key in D1 on first processing. If the same key is received again (within the idempotency window of **24 hours**), the Worker returns the original response without re-processing the operation.

This protects against:
- Network retries that result in duplicate purges.
- WordPress double-firing of hooks on certain operations.
- Duplicate preload enqueues.

Idempotency keys are distinct from nonces: a nonce prevents replay within the timestamp window (security); an idempotency key prevents duplicate side effects (correctness).

---

## Rate Limiting on Purge and Preload Endpoints

### Per-Zone Rate Limits

Rate limits are enforced by the Worker using a token bucket stored in Workers KV:

| Endpoint | Limit | Window |
|---|---|---|
| `POST /api/v1/purge` | 1,000 requests | 1 minute |
| `POST /api/v1/purge` (global purge) | 5 requests | 1 hour |
| `POST /api/v1/preload` | 500 requests | 1 minute |
| Any endpoint | 10,000 requests | 1 hour |

When a rate limit is exceeded, the Worker returns `429 Too Many Requests` with a `Retry-After` header indicating the number of seconds until the next token is available.

### IP-Based Rate Limits (Admin UI)

The WordPress admin UI imposes additional rate limits on the manual purge and benchmark trigger buttons:

- Manual purge: 60 requests per hour per admin user (enforced via WordPress transients).
- Benchmark trigger: 3 runs per 24 hours per zone.

---

## Secrets Management

### WordPress Origin

| Secret | Storage | Access |
|---|---|---|
| Cloudflare signing key | `wp_options` (`ai_cache_signing_key`) | PHP-only; never exposed in HTML |
| Cloudflare Zone ID | `wp_options` (`ai_cache_zone_id`) | Non-sensitive; used in UI |
| Cloudflare API Token (for Cache-Tag Purge) | `wp_options` (`ai_cache_cf_token`) | PHP-only; never exposed in HTML |

All sensitive options are stored encrypted. The encryption key is derived from `AUTH_KEY`, `SECURE_AUTH_KEY`, and `LOGGED_IN_KEY` from `wp-config.php` using HKDF-SHA256. If the WordPress secret keys change, stored secrets must be re-entered via the Admin UI.

### Cloudflare Worker

| Secret | Storage |
|---|---|
| `SIGNING_KEY` | Cloudflare Worker Secret (encrypted at rest by Cloudflare) |
| `R2_BUCKET` | `wrangler.toml` binding (non-sensitive) |
| `KV_NAMESPACE` | `wrangler.toml` binding (non-sensitive) |
| `DB` | `wrangler.toml` binding (non-sensitive) |

Worker Secrets are **never** committed to source control and are not visible in `wrangler.toml`. They are set via `wrangler secret put` or the Cloudflare dashboard.

### What to Do If a Secret Is Compromised

1. Immediately rotate the secret using the procedure in [Key Rotation](#key-rotation).
2. Review D1 audit logs for unauthorized purge/preload activity.
3. File a security report if the compromise was via a vulnerability in this plugin.

---

## XSS and CSRF Protection in the Admin UI

### CSRF

All admin form submissions and AJAX requests use **WordPress nonces**:

```php
// Form field
wp_nonce_field('ai_cache_action', 'ai_cache_nonce');

// Verification
check_admin_referer('ai_cache_action', 'ai_cache_nonce');

// AJAX
check_ajax_referer('ai_cache_ajax_action', 'nonce');
```

The nonce is tied to the current user session and expires after 24 hours (WordPress default).

### XSS

All output to HTML is escaped with the appropriate WordPress escaping function:

| Context | Function |
|---|---|
| Plain text | `esc_html()` |
| HTML attributes | `esc_attr()` |
| URLs | `esc_url()` |
| HTML content from trusted markup | `wp_kses_post()` |
| JS strings | `wp_json_encode()` + `esc_js()` |

Data from the Cloudflare Worker API (e.g., cache statistics displayed in the UI) is treated as untrusted input and escaped before rendering.

### Content Security Policy

The Admin UI adds a `Content-Security-Policy` header on its admin pages:

```
Content-Security-Policy: default-src 'self'; script-src 'self' 'nonce-{random}'; style-src 'self' 'nonce-{random}'; img-src 'self' data:; connect-src 'self' https://api.cloudflare.com;
```

Inline scripts use per-request nonces generated server-side.

---

## D1 and KV Injection Considerations

### D1 (SQL Injection)

All D1 queries use **prepared statements** (parameterized queries). Raw string interpolation into SQL is strictly prohibited:

```typescript
// Correct
const stmt = env.DB.prepare('SELECT * FROM purge_log WHERE nonce = ?').bind(nonce);

// Forbidden
const result = await env.DB.exec(`SELECT * FROM purge_log WHERE nonce = '${nonce}'`);
```

D1's `exec()` method (which accepts raw SQL strings) is never called with user-controlled input.

### KV (Key Injection)

KV keys derived from user input (e.g., zone IDs, tag names) are validated against an allowlist regex before use:

```typescript
// Zone ID: Cloudflare zone IDs are 32 hex characters
const ZONE_ID_RE = /^[0-9a-f]{32}$/i;
if (!ZONE_ID_RE.test(zoneId)) throw new Error('Invalid zone ID');

// Tag: dimension:integer-id
const TAG_RE = /^[a-z_]+:[0-9]+$/;
if (!TAG_RE.test(tag)) throw new Error('Invalid tag format');
```

This prevents a malicious actor with a valid HMAC key from constructing KV keys that collide with internal system keys.

---

## Cache Poisoning Threats

### Header Injection

The Worker only populates the cache with responses to `GET` and `HEAD` requests. Responses to `POST`, `PUT`, `DELETE`, and other mutating methods are never cached. The Worker validates the request method before any cache operation.

### Request Header Poisoning

Variant dimensions (headers included in the cache key) are limited to an explicit allowlist. The Worker never includes arbitrary request headers in the cache key. This prevents an attacker from poisoning the cache for other users by sending a crafted header that alters the response.

### Response Header Poisoning

The plugin sanitizes all values it writes into `Cache-Control` and `Surrogate-Key` headers. In particular, `Surrogate-Key` values are restricted to the tag regex `[a-z_]+:[0-9]+` — no newlines, semicolons, or other control characters are permitted.

### Web Cache Deception

The Worker treats URLs with path extensions that indicate documents (`.html`, `.htm`, `.php`) and extensionless paths as potentially cacheable. Paths with file extensions that should never be cached (e.g., `.json`, `.xml` for API responses) are explicitly bypassed. The bypass list is configurable via KV config.

---

## Known Risks and Accepted Limitations

| Risk | Likelihood | Impact | Mitigation | Accepted |
|---|---|---|---|---|
| HMAC key exfiltration via server compromise | Low | High | Encrypted storage; key rotation; principle of least privilege | Yes — physical server security is out of scope |
| Cloudflare Worker account compromise | Very Low | Critical | MFA on Cloudflare account; Worker Secret isolation | Yes — Cloudflare platform security is out of scope |
| Cache poisoning via misconfigured origin headers | Medium | High | Output validation; cache-contract enforcement in Worker | Partially — operator must follow cache-contract.md |
| WordPress admin account compromise | Low | High | WordPress hardening recommendations in runbooks | Yes — WP account security is out of scope |
| D1 data loss | Very Low | Low | R2 is independent; strategy can fallback to `disk-only` | Yes — Cloudflare SLA covers D1 durability |
