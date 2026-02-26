# Cache Keying

## Deterministic key model

Edge key must be deterministic and stable across equivalent URLs.

Normalization in `workers/src/index.ts`:

- keeps host + normalized path
- removes tracking params: `utm_*`, `gclid`, `fbclid`
- sorts remaining query parameters and values
- enforces trailing slash normalization

Example:

- Input: `/products?utm_source=x&color=blue&size=m`
- Key: `/products/?color=blue&size=m`

## Vary rules

Default vary dimensions should stay minimal:

- method (`GET` only cacheable)
- language (if multilingual path/header routing is used)
- device class only if template truly differs

Avoid high-cardinality vary dimensions:

- raw `User-Agent`
- session or user ids
- full cookie strings

## Logged-in and personalized traffic

- bypass shared caching for authenticated users
- mark bypass responses with debug header (plugin uses `X-AI-Dynamic-Cache-Bypass`)

## Collision prevention checks

Sandbox benchmarks should include collision checks:

- same key, different normalized variants must produce same canonical payload
- different payload variants must not map to same cache key

If collision is detected, candidate fails hard gate.

---


---

## Table of Contents

1. [Overview](#overview)
2. [URL Normalization](#url-normalization)
3. [Cookie Stripping and Bypass Detection](#cookie-stripping-and-bypass-detection)
4. [Variant Dimensions](#variant-dimensions)
5. [Cache Key Format](#cache-key-format)
6. [Key Hashing Algorithm](#key-hashing-algorithm)
7. [Canonical Key Length Limit](#canonical-key-length-limit)
8. [Examples](#examples)
9. [Security Considerations](#security-considerations)

---

## Overview

A **cache key** is the identifier used to store and retrieve a cached response. It must be:

- **Deterministic**: the same logical request always produces the same key.
- **Discriminating**: different logical requests (e.g., different languages, different compression) produce different keys.
- **Minimal**: irrelevant differences (e.g., tracking query params, cookie order) must not produce different keys.

Key construction happens in two places:

| Location | Class / Module | Purpose |
|---|---|---|
| WordPress Plugin (PHP) | `CacheKeyBuilder` | Constructs the canonical key string for disk cache lookups and `Surrogate-Key` emission |
| Cloudflare Worker (TypeScript) | `buildCacheKey()` in `cache-key.ts` | Constructs the key for edge cache and R2 lookups |

Both implementations follow the same algorithm to guarantee consistency.

---

## URL Normalization

URL normalization is the first step. The raw request URL is transformed into a canonical form before any further processing.

### Steps (applied in order)

1. **Scheme normalization**: Downcase and strip `http://` → use `https://` always. Edge enforces HTTPS-only.

2. **Host normalization**: Downcase the hostname. Strip `www.` prefix if the site is configured as apex-only (configurable via `ai_cache_strip_www` filter / KV config).

3. **Path normalization**:
   - Remove duplicate slashes (`//` → `/`).
   - Resolve `.` and `..` segments (RFC 3986 §5.2.4).
   - Remove trailing slash **only** for non-root paths when the site uses `trailingslashit` canonicalization (configurable).

4. **Query string normalization**:
   - Parse the query string into individual parameters.
   - **Remove** any parameter whose key matches the tracking/analytics blocklist (see below).
   - **Sort** the remaining parameters lexicographically by key, then by value.
   - Re-encode the query string in `application/x-www-form-urlencoded` format.
   - If no parameters remain after stripping, omit the `?` separator entirely.

5. **Fragment stripping**: URL fragments (`#anchor`) are never sent to the server; they are always stripped.

### Query Parameter Blocklist

The following query parameters are stripped before key construction because they carry no semantic content for the server:

```
utm_source   utm_medium   utm_campaign   utm_term   utm_content
fbclid       gclid        gclsrc         dclid      msclkid
_ga          _gl          ref            source
```

Additional parameters can be added via the `ai_cache_strip_query_params` WordPress filter or via the KV config `strip_params` array.

### Query Parameter Allowlist Mode

For sites with complex query-driven content (e.g., faceted search), the plugin can be switched to **allowlist mode**: only explicitly permitted query parameters are included in the cache key. All others are stripped. Configure via `ai_cache_allow_query_params` filter.

---

## Cookie Stripping and Bypass Detection

Cookies are examined **before** the cache key is computed.

### Phase 1 – Bypass Detection

If any bypass cookie is present (see [`docs/cache-contract.md § Bypass Conditions`](cache-contract.md#bypass-conditions)), the request is flagged as **non-cacheable**. No cache key is constructed; the request is passed directly to origin with `Cache-Control: private, no-store`.

### Phase 2 – Cookie Stripping

For cacheable requests, all cookies are stripped from the upstream request that the Worker uses for cache population. This ensures origin does not set `Cache-Control: private` in response to seeing unrecognised cookies.

Cookies are **not** part of the cache key by default. Personalisation that requires cookie-based differentiation must use the variant key system (see below) with an explicit allowlist.

---

## Variant Dimensions

Variant dimensions allow the same URL to have multiple cache entries based on request characteristics. Each active dimension adds a component to the cache key.

| Dimension | Request Header | Key Component | Default |
|---|---|---|---|
| Content encoding | `Accept-Encoding` | `enc:{br\|gzip\|identity}` | ✅ Enabled |
| Language | `Accept-Language` | `lang:{primary-tag}` | ❌ Disabled (enable per site) |
| Device type | `User-Agent` (bucketed) | `device:{desktop\|mobile\|tablet}` | ❌ Disabled |
| Currency (WooCommerce) | `X-WC-Currency` or cookie | `cur:{ISO-4217}` | ❌ Disabled |
| Custom cookie value | Configurable cookie name | `ck:{sha256(value)[0:8]}` | ❌ Disabled |

> **Note:** Enabling too many variant dimensions multiplies cache storage requirements and reduces hit rates. Only enable dimensions that actually change the response content.

### Accept-Language Normalization

When the language dimension is enabled, the `Accept-Language` header is parsed and reduced to the **primary language tag** only (e.g., `en-GB` → `en`, `zh-Hant-TW` → `zh`). Granular sub-tags are ignored to avoid excessive key proliferation.

---

## Cache Key Format

The cache key is a structured string composed of normalized URL components and active variant dimensions, separated by `|`:

```
{scheme}://{host}{path}?{sorted-query}|{variant-dimension-1}|{variant-dimension-2}
```

When no query string remains after normalization, the `?` is omitted:

```
https://example.com/blog/my-post/|enc:br
```

When multiple variant dimensions are active, they are appended in the fixed order defined in the table above:

```
https://example.com/shop/|enc:gzip|lang:fr|device:mobile
```

---

## Key Hashing Algorithm

The canonical key string is hashed to produce a fixed-length, filesystem-safe, URL-safe identifier used for:

- R2 object keys
- Disk cache file names
- `X-Cache-Key-Hash` debug header

**Algorithm:** SHA-256, output encoded as lowercase hexadecimal (64 characters).

```
cache_key_hash = SHA-256(canonical_key_string)
```

### PHP Implementation

```php
$hash = hash('sha256', $canonicalKey);
```

### TypeScript Implementation

```typescript
const encoder = new TextEncoder();
const data = encoder.encode(canonicalKey);
const hashBuffer = await crypto.subtle.digest('SHA-256', data);
const hashArray = Array.from(new Uint8Array(hashBuffer));
const hash = hashArray.map(b => b.toString(16).padStart(2, '0')).join('');
```

### R2 Object Key Structure

R2 objects use a directory-structured key to enable prefix-based listing and partial purges:

```
cache/{zone_id}/{hash[0:2]}/{hash[2:4]}/{hash}
```

Example:

```
cache/abc123xyz/a3/f9/a3f9e2d1...64chars.../
```

---

## Examples

### Example 1 – Simple Page

**Raw request URL:**
```
https://www.example.com/blog/hello-world/?utm_source=twitter&utm_medium=social
```

**After normalization** (www stripped, tracking params removed):
```
https://example.com/blog/hello-world/
```

**With encoding variant (br):**
```
https://example.com/blog/hello-world/|enc:br
```

**SHA-256 hash:**
```
3d8f2e... (64 hex chars)
```

---

### Example 2 – Query-Driven Page

**Raw request URL:**
```
https://example.com/products/?color=red&page=2&fbclid=abc123&size=M
```

**After normalization** (fbclid stripped, params sorted):
```
https://example.com/products/?color=red&page=2&size=M
```

**With encoding variant (gzip):**
```
https://example.com/products/?color=red&page=2&size=M|enc:gzip
```

---

### Example 3 – Multilingual Site

**Raw request URL:**
```
https://example.com/about/
```

**Accept-Language header:**
```
Accept-Language: fr-CH, fr;q=0.9, en;q=0.8
```

**Primary language tag extracted:** `fr`

**Canonical key:**
```
https://example.com/about/|enc:br|lang:fr
```

---

## Security Considerations

- **Cache Poisoning via Headers**: The Worker only incorporates headers into the cache key via the explicit variant dimension allowlist. Arbitrary request headers are never included in the key. This prevents a poisoning attack where an attacker injects a malformed header to cause a poisoned response to be stored under a legitimate key.

- **Cache Poisoning via Query Params**: Unrecognized query parameters are stripped (blocklist mode) or excluded (allowlist mode) before key construction. A parameter that changes the response but is not in the key would allow poisoning only if an attacker could influence what the origin returns — which is a separate concern addressed by input sanitization.

- **Hash Collisions**: SHA-256 collision resistance is sufficient; no mitigations beyond the standard algorithm are required.

- **Key Length**: The canonical key string (before hashing) can be arbitrarily long for URLs with many query parameters. The Worker enforces a maximum canonical key length of **8192 bytes**; requests exceeding this are treated as cache bypasses.
