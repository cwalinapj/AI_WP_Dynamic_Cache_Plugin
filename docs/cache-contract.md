# Cache Contract

This document defines the contract between the **WordPress Plugin** (origin) and the **Cloudflare Edge Worker** regarding HTTP caching headers, bypass conditions, and TTL tiers. Both sides of the system must adhere to this contract for correct cache behaviour.

---

## Table of Contents

1. [Cache-Control Headers](#cache-control-headers)
2. [Surrogate-Control / CDN-Cache-Control Headers](#surrogate-control--cdn-cache-control-headers)
3. [Vary Header Handling](#vary-header-handling)
4. [Bypass Conditions](#bypass-conditions)
5. [TTL Tiers](#ttl-tiers)
6. [Header Stripping](#header-stripping)

---

## Cache-Control Headers

The plugin's `CacheHeaderEmitter` sets `Cache-Control` on every cacheable response. The Worker reads these headers to determine edge and R2 TTLs.

### Cacheable HTML Pages

```http
Cache-Control: public, max-age=300, s-maxage=86400, stale-while-revalidate=60, stale-if-error=3600
```

| Directive | Value | Purpose |
|---|---|---|
| `public` | — | Marks the response as shareable by any cache |
| `max-age` | Policy-dependent (see [TTL Tiers](#ttl-tiers)) | Browser cache TTL |
| `s-maxage` | Policy-dependent | CDN / shared cache TTL (overrides `max-age` for CDN) |
| `stale-while-revalidate` | 60 s | Serve stale while revalidating in background |
| `stale-if-error` | 3600 s | Serve stale if origin errors |

### Bypass Responses

When a bypass condition is detected the plugin emits:

```http
Cache-Control: private, no-store, no-cache
```

This prevents Cloudflare Edge, R2, and browsers from caching the response.

### API Responses (REST / AJAX)

```http
Cache-Control: no-store
```

---

## Surrogate-Control / CDN-Cache-Control Headers

In addition to `Cache-Control`, the plugin emits **`CDN-Cache-Control`** (Cloudflare's preferred header when `Cache Rules` are in use) and **`Surrogate-Control`** (for compatibility with Varnish / Fastly).

```http
CDN-Cache-Control: max-age=86400
Surrogate-Control: max-age=86400
```

`CDN-Cache-Control` takes precedence over `Cache-Control`'s `s-maxage` at the Cloudflare edge. The Worker strips both headers before forwarding the response to the browser so that downstream proxies do not re-cache with CDN TTLs.

### Surrogate-Key / Cache-Tag

The plugin also emits `Surrogate-Key` (and the Cloudflare alias `Cache-Tag`) to associate a response with purgeable tags:

```http
Surrogate-Key: post:123 term:45 author:7 template:single site
Cache-Tag: post:123 term:45 author:7 template:single site
```

See [`docs/purge-and-tags.md`](purge-and-tags.md) for the full tag taxonomy.

---

## Vary Header Handling

### Origin `Vary` Header

The origin **must not** emit `Vary: Cookie` or `Vary: Authorization` on cacheable responses. Doing so would cause Cloudflare Edge to create separate cache entries per cookie string, effectively defeating the cache.

Instead, cookie-based bypasses are handled **before** the response is generated (see [Bypass Conditions](#bypass-conditions)) and the bypass path sets `Cache-Control: private, no-store`.

### Acceptable `Vary` Values

| `Vary` value | Handling |
|---|---|
| `Accept-Encoding` | Allowed; Cloudflare handles gzip/br transparently |
| `Accept-Language` | Allowed only when the site serves multiple languages; must be paired with a cache key variant dimension (see [`docs/cache-keying.md`](cache-keying.md)) |
| `Cookie` | **Forbidden** on cacheable responses |
| `Authorization` | **Forbidden** on cacheable responses |
| `X-WP-Nonce` | **Forbidden** on cacheable responses |

### Worker `Vary` Normalization

The Worker strips `Vary: Accept-Encoding` from stored R2 objects and normalises to a single compressed variant per resource. The decompressed body is stored; the appropriate compressed version is served based on the request's `Accept-Encoding`.

---

## Bypass Conditions

The `BypassDetector` class evaluates bypass conditions in priority order. The **first** matching condition wins.

| Priority | Condition | Bypass Mechanism |
|---|---|---|
| 1 | HTTP method is not `GET` or `HEAD` | `Cache-Control: private, no-store` |
| 2 | Request URL path starts with `/wp-admin/` | `Cache-Control: private, no-store` |
| 3 | Request URL path starts with `/wp-login.php` | `Cache-Control: private, no-store` |
| 4 | Request contains `wordpress_logged_in_*` cookie | `Cache-Control: private, no-store` |
| 5 | Request contains `wordpress_sec_*` cookie | `Cache-Control: private, no-store` |
| 6 | Request contains `woocommerce_cart_hash` cookie (non-empty) | `Cache-Control: private, no-store` |
| 7 | Request contains `woocommerce_items_in_cart` cookie with value > 0 | `Cache-Control: private, no-store` |
| 8 | Request URL path is `/checkout/`, `/cart/`, `/my-account/` (or configured equivalents) | `Cache-Control: private, no-store` |
| 9 | Request URL path is `/wp-json/` (REST API) | `Cache-Control: no-store` |
| 10 | Request URL contains `?nocache` or `?preview=true` | `Cache-Control: private, no-store` |
| 11 | Canonical cache key string exceeds 8192 bytes (e.g., URL with an extreme number of query parameters) | `Cache-Control: private, no-store` |
| 12 | Response `Set-Cookie` contains session-specific cookie | `Cache-Control: private, no-store` |
| 13 | WordPress admin bar is shown (`is_admin_bar_showing()`) | `Cache-Control: private, no-store` |

### WooCommerce Additional Bypasses

When WooCommerce is active, the following are also bypassed:

- Any URL that generates a `wc_session_*` cookie.
- The `/store-api/` path (WooCommerce Blocks API).
- Any page that WooCommerce marks as "should not cache" via `wc_nocache_headers()`.

### Cookie Passthrough List

Cookies that **do not** trigger a bypass (they are stripped from the upstream request used for cache population):

- `wordpress_test_cookie`
- `wp-settings-*`
- `wp-settings-time-*`
- Analytics cookies (`_ga`, `_gid`, `_gat`, `_fbp`, `_fbc`, `ajs_*`, `amplitude_*`)
- Any cookie matching the configurable `ai_cache_strip_cookies` filter

---

## TTL Tiers

TTLs are configured via the plugin settings and stored in Workers KV under `config:{zone_id}`. The defaults below represent production recommendations.

### HTML Pages

| Policy | `max-age` (browser) | `s-maxage` (CDN) | Notes |
|---|---|---|---|
| `aggressive` | 3600 (1 h) | 86400 (24 h) | High-traffic, infrequently updated pages |
| `standard` (default) | 300 (5 min) | 3600 (1 h) | General content sites |
| `conservative` | 60 (1 min) | 300 (5 min) | Frequently updated news/blog |
| `minimal` | 0 | 60 (1 min) | Near-real-time content |

### Static Assets

Assets served from `/wp-content/uploads/`, `/wp-includes/`, and `/wp-content/themes/` are subject to long-lived caching:

```http
Cache-Control: public, max-age=31536000, immutable
```

Versioning is handled via WordPress's asset versioning query param (`?ver=`), which produces a new cache key automatically.

### REST API / AJAX

```http
Cache-Control: no-store
```

### Feeds (RSS/Atom)

```http
Cache-Control: public, max-age=900, s-maxage=3600
```

### Sitemaps

```http
Cache-Control: public, max-age=3600, s-maxage=86400
```

---

## Header Stripping

The Worker strips the following headers from responses before delivering them to browsers, to prevent leaking internal cache metadata:

- `Surrogate-Key`
- `Cache-Tag`
- `Surrogate-Control`
- `CDN-Cache-Control`
- `X-Cache-Status` (replaced with a sanitized version showing only HIT/MISS/BYPASS)
- `X-Powered-By`
- `Server` (replaced with `Cloudflare`)

The following headers are **preserved** in the response to aid client-side debugging when the `ai_cache_debug` query param is present and the requester is a verified administrator:

- `X-Cache-Key-Hash`
- `X-Cache-Layer` (EDGE / R2 / ORIGIN)
- `X-Cache-Age`
