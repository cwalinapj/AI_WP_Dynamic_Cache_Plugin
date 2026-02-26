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
