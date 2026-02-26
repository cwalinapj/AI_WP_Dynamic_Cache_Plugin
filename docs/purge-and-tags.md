# Purge and Tags

## Tag model

Recommended tag namespaces:

- `page:{url}`
- `post:{post_id}`
- `term:{term_id}`
- `template:{template_key}`
- `site:{site_id}`

Attach tags to cache objects so updates can invalidate precise slices.

## Purge paths

- single object purge by exact cache key
- tag purge for related objects
- site-level emergency purge

## Purge SLO

Benchmark includes `purge_mttr_ms` (time to recover after invalidation).

Hard gate:

- if purge does not complete inside max window, candidate is rejected.

Score impact:

- `purge_mttr_ms` contributes 10% of weighted score.

## Safety sequence

1. Purge key/tag
2. Trigger targeted preload (budget-aware)
3. Validate fresh digest against origin
4. Mark profile healthy if validation passes

## Operational guardrails

- never purge by wildcard without an operator action
- record purge operations with timestamp and actor
- block promotion of strategy profiles that fail purge SLO
