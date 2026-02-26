#!/usr/bin/env bash
# run_benchmarks.sh – Run k6 + Lighthouse benchmarks for every caching strategy.
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "${SCRIPT_DIR}/../.." && pwd)"
RESULTS_DIR="${REPO_ROOT}/sandbox/results"
STRATEGY_MATRIX="${REPO_ROOT}/sandbox/strategies/strategy_matrix.yaml"

BASE_URL="${BASE_URL:-http://nginx}"
WP_CLI_CONTAINER="${WP_CLI_CONTAINER:-wordpress}"
SINGLE_STRATEGY=""

# ---------------------------------------------------------------------------
# Argument parsing
# ---------------------------------------------------------------------------
while [[ $# -gt 0 ]]; do
  case "$1" in
    --strategy)
      SINGLE_STRATEGY="$2"
      shift 2
      ;;
    *)
      echo "Unknown argument: $1" >&2
      exit 1
      ;;
  esac
done

mkdir -p "${RESULTS_DIR}"

# ---------------------------------------------------------------------------
# Parse strategy names from YAML (no external yq dependency required)
# ---------------------------------------------------------------------------
mapfile -t STRATEGIES < <(grep -E '^\s*- name:' "${STRATEGY_MATRIX}" | sed 's/.*- name:\s*//')

if [[ -n "${SINGLE_STRATEGY}" ]]; then
  STRATEGIES=("${SINGLE_STRATEGY}")
fi

echo "======================================================"
echo " AI WP Dynamic Cache – Benchmark Suite"
echo " Strategies : ${STRATEGIES[*]}"
echo " Results    : ${RESULTS_DIR}"
echo "======================================================"

# ---------------------------------------------------------------------------
# Helper: apply a strategy via the plugin's REST API
# ---------------------------------------------------------------------------
apply_strategy() {
  local strategy="$1"
  echo "[*] Applying strategy: ${strategy}"

  # Build the strategy JSON config by reading from the matrix
  local edge disk r2 object
  edge=$(awk "/name: ${strategy}/{found=1} found && /edge_cache:/{print \$2; exit}" "${STRATEGY_MATRIX}")
  disk=$(awk "/name: ${strategy}/{found=1} found && /disk_cache:/{print \$2; exit}" "${STRATEGY_MATRIX}")
  r2=$(awk   "/name: ${strategy}/{found=1} found && /r2_cache:/{print \$2; exit}"   "${STRATEGY_MATRIX}")
  object=$(awk "/name: ${strategy}/{found=1} found && /object_cache:/{print \$2; exit}" "${STRATEGY_MATRIX}")

  local payload
  payload=$(printf '{"edge_cache":%s,"r2_cache":%s,"disk_cache":%s,"object_cache":%s}' \
    "${edge:-false}" "${r2:-false}" "${disk:-false}" "${object:-false}")

  # Attempt REST API first; fall back to WP-CLI inside container
  if curl -sf -X POST \
      -H "Content-Type: application/json" \
      -d "{\"config\":${payload}}" \
      "${BASE_URL}/wp-json/ai-wp-cache/v1/strategy" > /dev/null 2>&1; then
    echo "    Strategy applied via REST API."
  else
    echo "    REST API not available; attempting WP-CLI…"
    docker compose -f "${REPO_ROOT}/sandbox/docker/docker-compose.yml" \
      exec -T "${WP_CLI_CONTAINER}" \
      wp option update ai_wp_dynamic_cache_config "${payload}" --format=json 2>/dev/null || true
  fi
}

# ---------------------------------------------------------------------------
# Helper: warm the cache
# ---------------------------------------------------------------------------
warm_cache() {
  local strategy="$1"
  echo "[*] Warming cache for strategy: ${strategy} (30s)"
  sleep 30
  # Crawl key pages to populate the cache
  local urls=("/" "/?p=1" "/?cat=1" "/sitemap.xml")
  for u in "${urls[@]}"; do
    curl -sf "${BASE_URL}${u}" -o /dev/null || true
  done
}

# ---------------------------------------------------------------------------
# Helper: run k6
# ---------------------------------------------------------------------------
run_k6() {
  local strategy="$1"
  local out="${RESULTS_DIR}/${strategy}-k6.json"
  echo "[*] Running k6 for strategy: ${strategy}"

  docker compose -f "${REPO_ROOT}/sandbox/docker/docker-compose.yml" \
    run --rm -e "BASE_URL=${BASE_URL}" k6 \
    run --out "json=/results/${strategy}-k6.json" \
    /scripts/loadtest.js || {
      echo "    k6 run failed (non-zero exit); results may be partial."
    }

  echo "    k6 results saved to ${out}"
}

# ---------------------------------------------------------------------------
# Helper: run Lighthouse
# ---------------------------------------------------------------------------
run_lighthouse() {
  local strategy="$1"
  local out="${RESULTS_DIR}/${strategy}-lighthouse.json"
  echo "[*] Running Lighthouse for strategy: ${strategy}"

  docker compose -f "${REPO_ROOT}/sandbox/docker/docker-compose.yml" \
    run --rm \
    -e "BASE_URL=${BASE_URL}" \
    -e "RESULTS_DIR=/results" \
    lighthouse node /lighthouse/run.js || {
      echo "    Lighthouse run failed; results may be partial."
    }

  # Rename the timestamped file to strategy-specific name
  local latest
  latest=$(ls -t "${RESULTS_DIR}"/lighthouse-*.json 2>/dev/null | head -1 || true)
  if [[ -n "${latest}" ]]; then
    mv "${latest}" "${out}"
    echo "    Lighthouse results saved to ${out}"
  fi
}

# ---------------------------------------------------------------------------
# Summary table
# ---------------------------------------------------------------------------
print_summary() {
  echo ""
  echo "======================================================"
  echo " Summary"
  printf "%-20s %-12s %-12s %-10s\n" "Strategy" "k6 Results" "LH Results" "Status"
  echo "------------------------------------------------------"
  for strategy in "${STRATEGIES[@]}"; do
    local k6_file="${RESULTS_DIR}/${strategy}-k6.json"
    local lh_file="${RESULTS_DIR}/${strategy}-lighthouse.json"
    local k6_status="missing"
    local lh_status="missing"
    [[ -f "${k6_file}" ]] && k6_status="ok"
    [[ -f "${lh_file}" ]] && lh_status="ok"
    printf "%-20s %-12s %-12s %-10s\n" "${strategy}" "${k6_status}" "${lh_status}" \
      "$([[ ${k6_status} == ok && ${lh_status} == ok ]] && echo 'PASS' || echo 'PARTIAL')"
  done
  echo "======================================================"
}

# ---------------------------------------------------------------------------
# Main loop
# ---------------------------------------------------------------------------
for strategy in "${STRATEGIES[@]}"; do
  echo ""
  echo ">>> Strategy: ${strategy}"
  apply_strategy "${strategy}"
  warm_cache     "${strategy}"
  run_k6         "${strategy}"
  run_lighthouse "${strategy}"
done

print_summary
echo ""
echo "All benchmarks complete. Results in: ${RESULTS_DIR}"
