#!/usr/bin/env bash
set -euo pipefail

TARGET_URL="${1:-https://example.com}"
OUTPUT_DIR="${2:-sandbox-results}"

mkdir -p "${OUTPUT_DIR}"

if ! command -v lighthouse >/dev/null 2>&1; then
  echo "lighthouse CLI not found. Install with: npm i -g lighthouse"
  exit 1
fi

lighthouse "${TARGET_URL}" \
  --quiet \
  --chrome-flags="--headless" \
  --only-categories=performance \
  --output=json \
  --output-path="${OUTPUT_DIR}/lighthouse.json"

if command -v jq >/dev/null 2>&1; then
  jq '{
    performance_score: .categories.performance.score,
    first_contentful_paint_ms: .audits["first-contentful-paint"].numericValue,
    largest_contentful_paint_ms: .audits["largest-contentful-paint"].numericValue,
    cumulative_layout_shift: .audits["cumulative-layout-shift"].numericValue,
    speed_index_ms: .audits["speed-index"].numericValue,
    total_blocking_time_ms: .audits["total-blocking-time"].numericValue
  }' "${OUTPUT_DIR}/lighthouse.json" > "${OUTPUT_DIR}/lighthouse-summary.json"
else
  echo "jq not found; raw lighthouse JSON is available at ${OUTPUT_DIR}/lighthouse.json"
fi

echo "Wrote Lighthouse results to ${OUTPUT_DIR}"
