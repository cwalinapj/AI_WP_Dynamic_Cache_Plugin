# Cloudflare Cache Rules Configuration

This document describes the recommended Cloudflare Cache Rules for the AI WP Dynamic Cache Plugin, replacing legacy Page Rules with the modern Cache Rules interface.

---

## 1. Cache Rules Overview

Cloudflare Cache Rules (available under **Caching → Cache Rules** in the dashboard) allow fine-grained control over what the edge caches, bypasses, and revalidates. They supersede the older Page Rules interface.

---

## 2. Static Assets – 1-Year TTL

**Purpose:** Maximise edge cache efficiency for versioned static files.

**Rule expression:**
```
(http.request.uri.path matches "\.(css|js|jpg|jpeg|png|gif|ico|svg|woff|woff2|ttf|eot|webp|avif)$")
```

**Settings:**
- **Cache status:** Eligible for cache
- **Edge TTL:** Override origin – **31,536,000 seconds** (1 year)
- **Browser TTL:** Override origin – **31,536,000 seconds**
- **Cache key:** Default (URI + Host)

> **Note:** Ensure your WordPress theme and plugin assets include a cache-busting query string or content hash in their filenames (e.g., `style.min.css?ver=2.1.4`) so users always receive the latest version.

---

## 3. HTML Pages – Variable TTL Based on Cookie

**Purpose:** Cache HTML for anonymous visitors; bypass for logged-in users and WooCommerce session holders.

**Rule expression (cache eligible):**
```
(http.request.method eq "GET") and
(not http.request.uri.path contains "/wp-admin") and
(not http.request.uri.path contains "/wp-login.php") and
(not any(http.cookies.names[*] contains "wordpress_logged_in")) and
(not any(http.cookies.names[*] contains "woocommerce_items_in_cart")) and
(not any(http.cookies.names[*] contains "woocommerce_cart_hash"))
```

**Settings:**
- **Cache status:** Eligible for cache
- **Edge TTL:** Override origin – **3,600 seconds** (1 hour)
- **Browser TTL:** Bypass cache – **0 seconds** (always revalidate from edge)
- **Serve stale content:** Enabled (while updating)

---

## 4. Bypass Rules – wp-admin, wp-login, Cart, Checkout

**Purpose:** Never cache authenticated or transactional pages.

### 4a. Admin & Login
```
(http.request.uri.path contains "/wp-admin") or
(http.request.uri.path eq "/wp-login.php") or
(http.request.uri.path contains "/wp-cron.php")
```
**Action:** Bypass cache

### 4b. WooCommerce Cart & Checkout
```
(http.request.uri.path contains "/cart") or
(http.request.uri.path contains "/checkout") or
(http.request.uri.path contains "/my-account") or
(http.request.uri.path contains "/?wc-ajax=")
```
**Action:** Bypass cache

### 4c. REST API & XML-RPC
```
(http.request.uri.path contains "/wp-json") or
(http.request.uri.path contains "/xmlrpc.php")
```
**Action:** Bypass cache

---

## 5. Page Rules vs Cache Rules Comparison

| Feature | Page Rules (legacy) | Cache Rules (current) |
|---|---|---|
| Condition language | Glob patterns | Wirefilter expressions |
| Scope | Per URL pattern | Per request attribute (URI, cookie, header, method, …) |
| Actions | Cache Level, Browser TTL, … | Granular cache eligibility, TTL overrides, serve stale |
| Priority | Rule order (1–n) | Rule order + phase priority |
| Limits | 3 free / 20 paid | 10 free / 125 Enterprise |
| Recommended | ❌ Deprecated | ✅ Use this |

**Migration tip:** Cloudflare provides a **Page Rules migration tool** under Caching → Cache Rules that converts existing rules automatically.

---

## 6. Applying Rules via Terraform

See `infra/terraform/cloudflare/main.tf` for the `cloudflare_ruleset` resource that manages these rules programmatically.

```hcl
resource "cloudflare_ruleset" "cache_rules" {
  zone_id = var.cloudflare_zone_id
  name    = "AI WP Dynamic Cache Rules"
  kind    = "zone"
  phase   = "http_request_cache_settings"
  # ... rules defined as blocks
}
```

---

## 7. Verifying Cache Behaviour

Use `curl -I` to inspect response headers:

```bash
# First request – MISS
curl -sI https://example.com/ | grep -i "cf-cache-status"
# cf-cache-status: MISS

# Second request – HIT
curl -sI https://example.com/ | grep -i "cf-cache-status"
# cf-cache-status: HIT

# Authenticated user – BYPASS
curl -sI -H "Cookie: wordpress_logged_in_abc123=1" https://example.com/ | grep -i "cf-cache-status"
# cf-cache-status: BYPASS
```

---

## 8. Cache Purge

The AI WP Dynamic Cache Worker handles tag-based purges. When a post is published:

1. WordPress plugin calls the Worker's `/purge` endpoint with affected cache tags.
2. Worker purges matching KV and R2 entries.
3. Worker calls `cf.cache.delete()` or uses the Cloudflare API to invalidate Cloudflare edge cache for the affected URLs.

For manual purge via Cloudflare dashboard: **Caching → Configuration → Purge Cache**.
