#!/usr/bin/env bash
# purge_test.sh – Verify the full cache warm → purge → re-warm cycle.
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

BASE_URL="${BASE_URL:-http://nginx}"
WORKER_URL="${WORKER_URL:-${BASE_URL}}"
HMAC_SECRET="${HMAC_SECRET:-}"

PASS=0
FAIL=0

# ---------------------------------------------------------------------------
# Helpers
# ---------------------------------------------------------------------------
log_pass() { echo "  [PASS] $*"; ((PASS++)) || true; }
log_fail() { echo "  [FAIL] $*"; ((FAIL++)) || true; }

require_cmd() {
  command -v "$1" >/dev/null 2>&1 || { echo "Required command not found: $1" >&2; exit 1; }
}

require_cmd curl
require_cmd jq

# URLs under test
TEST_URLS=(
  "${BASE_URL}/"
  "${BASE_URL}/?p=1"
  "${BASE_URL}/?cat=1"
)

# ---------------------------------------------------------------------------
# HMAC-SHA256 signature
# ---------------------------------------------------------------------------
sign_payload() {
  local payload="$1"
  echo -n "${payload}" | openssl dgst -sha256 -hmac "${HMAC_SECRET}" -binary | xxd -p -c 256
}

# ---------------------------------------------------------------------------
# Step 1: Warm the cache
# ---------------------------------------------------------------------------
echo "=== Step 1: Warming cache ==="
for url in "${TEST_URLS[@]}"; do
  status=$(curl -sf -o /dev/null -w "%{http_code}" "${url}" || echo "000")
  if [[ "${status}" == "200" ]]; then
    log_pass "Warmed ${url} (HTTP ${status})"
  else
    log_fail "Could not warm ${url} (HTTP ${status})"
  fi
  # Second hit should populate the cache
  curl -sf -o /dev/null "${url}" || true
done

# ---------------------------------------------------------------------------
# Step 2: Verify URLs are cached (HIT)
# ---------------------------------------------------------------------------
echo ""
echo "=== Step 2: Verifying cache HITs ==="
sleep 2
for url in "${TEST_URLS[@]}"; do
  cache_status=$(curl -sf -o /dev/null -D - "${url}" 2>&1 | grep -i "x-cache-status" | awk '{print $2}' | tr -d '\r' || echo "")
  if [[ "${cache_status}" == "HIT" ]]; then
    log_pass "Cache HIT for ${url}"
  else
    log_fail "Expected HIT, got '${cache_status}' for ${url}"
  fi
done

# ---------------------------------------------------------------------------
# Step 3: Trigger purge via REST API with HMAC signature
# ---------------------------------------------------------------------------
echo ""
echo "=== Step 3: Triggering cache purge ==="
PURGE_PAYLOAD='{"purge_all":true}'
TIMESTAMP=$(date +%s)
SIGNATURE=""

if [[ -n "${HMAC_SECRET}" ]]; then
  SIGNATURE=$(sign_payload "${TIMESTAMP}:${PURGE_PAYLOAD}")
fi

HTTP_CODE=$(curl -sf -o /dev/null -w "%{http_code}" -X POST \
  -H "Content-Type: application/json" \
  -H "X-Cache-Timestamp: ${TIMESTAMP}" \
  -H "X-Cache-Signature: ${SIGNATURE}" \
  -d "${PURGE_PAYLOAD}" \
  "${WORKER_URL}/wp-json/ai-wp-cache/v1/purge" 2>/dev/null || echo "000")

if [[ "${HTTP_CODE}" =~ ^2 ]]; then
  log_pass "Purge request accepted (HTTP ${HTTP_CODE})"
else
  log_fail "Purge request failed (HTTP ${HTTP_CODE})"
fi

# Give the purge a moment to propagate
sleep 3

# ---------------------------------------------------------------------------
# Step 4: Verify URLs return cache MISS
# ---------------------------------------------------------------------------
echo ""
echo "=== Step 4: Verifying cache MISSes after purge ==="
for url in "${TEST_URLS[@]}"; do
  cache_status=$(curl -sf -o /dev/null -D - "${url}" 2>&1 | grep -i "x-cache-status" | awk '{print $2}' | tr -d '\r' || echo "")
  if [[ "${cache_status}" == "MISS" || "${cache_status}" == "BYPASS" || "${cache_status}" == "EXPIRED" ]]; then
    log_pass "Cache MISS/BYPASS after purge for ${url} (${cache_status})"
  else
    log_fail "Expected MISS after purge, got '${cache_status}' for ${url}"
  fi
done

# ---------------------------------------------------------------------------
# Step 5: Wait for preload and verify HITs again
# ---------------------------------------------------------------------------
echo ""
echo "=== Step 5: Waiting for preload (15s) then verifying HITs ==="
sleep 15

# Trigger a preload if the plugin supports it
curl -sf -X POST \
  -H "Content-Type: application/json" \
  -d '{"urls":["/"]}' \
  "${WORKER_URL}/wp-json/ai-wp-cache/v1/preload" > /dev/null 2>&1 || true

# Warm manually
for url in "${TEST_URLS[@]}"; do
  curl -sf -o /dev/null "${url}" || true
done
sleep 2

for url in "${TEST_URLS[@]}"; do
  cache_status=$(curl -sf -o /dev/null -D - "${url}" 2>&1 | grep -i "x-cache-status" | awk '{print $2}' | tr -d '\r' || echo "")
  if [[ "${cache_status}" == "HIT" ]]; then
    log_pass "Cache HIT after re-warm for ${url}"
  else
    log_fail "Expected HIT after re-warm, got '${cache_status}' for ${url}"
  fi
done

# ---------------------------------------------------------------------------
# Summary
# ---------------------------------------------------------------------------
echo ""
echo "=============================="
echo " Purge Test Summary"
echo "  PASS : ${PASS}"
echo "  FAIL : ${FAIL}"
echo "=============================="

[[ "${FAIL}" -eq 0 ]] && exit 0 || exit 1
