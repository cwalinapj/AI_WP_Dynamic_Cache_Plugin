#!/usr/bin/env bash
# collect_results.sh – Aggregate benchmark results into a markdown summary.
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "${SCRIPT_DIR}/../.." && pwd)"
RESULTS_DIR="${REPO_ROOT}/sandbox/results"
SUMMARY_FILE="${RESULTS_DIR}/summary.md"
RESULTS_WEBHOOK="${RESULTS_WEBHOOK:-}"

require_cmd() {
  command -v "$1" >/dev/null 2>&1 || { echo "Required command not found: $1" >&2; exit 1; }
}

require_cmd jq

# ---------------------------------------------------------------------------
# Helpers
# ---------------------------------------------------------------------------
extract_k6_metrics() {
  local file="$1"
  [[ -f "${file}" ]] || { echo "N/A N/A N/A N/A N/A"; return; }

  # k6 JSON output streams metric objects; filter summary lines
  local p50 p95 p99 rps err_rate hit_rate
  p50=$(jq -r 'select(.type=="Point" and .metric=="http_req_duration") | .data.value' "${file}" 2>/dev/null \
        | sort -n | awk 'BEGIN{c=0;a[0]=0} {a[c]=$1;c++} END{print a[int(c*0.50)]}' || echo "N/A")
  p95=$(jq -r 'select(.type=="Point" and .metric=="http_req_duration") | .data.value' "${file}" 2>/dev/null \
        | sort -n | awk 'BEGIN{c=0} {a[c]=$1;c++} END{print a[int(c*0.95)]}' || echo "N/A")
  p99=$(jq -r 'select(.type=="Point" and .metric=="http_req_duration") | .data.value' "${file}" 2>/dev/null \
        | sort -n | awk 'BEGIN{c=0} {a[c]=$1;c++} END{print a[int(c*0.99)]}' || echo "N/A")
  rps=$(jq -rs '[.[] | select(.type=="Point" and .metric=="http_reqs")] | length' "${file}" 2>/dev/null || echo "N/A")
  err_rate=$(jq -rs '[.[] | select(.type=="Point" and .metric=="http_req_failed")] | (map(.data.value) | add) / length * 100 | . * 100 | round / 100 | tostring + "%"' "${file}" 2>/dev/null || echo "N/A")
  hit_rate=$(jq -rs '[.[] | select(.type=="Point" and .metric=="cache_hit_rate")] | (map(.data.value) | add) / length * 100 | . * 100 | round / 100 | tostring + "%"' "${file}" 2>/dev/null || echo "N/A")

  echo "${p50} ${p95} ${p99} ${rps} ${err_rate} ${hit_rate}"
}

extract_lighthouse_metrics() {
  local file="$1"
  [[ -f "${file}" ]] || { echo "N/A N/A N/A N/A N/A"; return; }

  # Our lighthouse runner writes an array of page result objects
  jq -r '
    if type == "array" then
      .[0]
    else
      .
    end
    | [
        (.metrics.performance | tostring),
        ((.metrics.fcp // 0) | round | tostring) + "ms",
        ((.metrics.lcp // 0) | round | tostring) + "ms",
        ((.metrics.tbt // 0) | round | tostring) + "ms",
        ((.metrics.cls // 0) | tostring)
      ]
    | join(" ")
  ' "${file}" 2>/dev/null || echo "N/A N/A N/A N/A N/A"
}

# ---------------------------------------------------------------------------
# Discover strategies from result file names
# ---------------------------------------------------------------------------
mapfile -t K6_FILES < <(find "${RESULTS_DIR}" -name '*-k6.json' | sort)

if [[ ${#K6_FILES[@]} -eq 0 ]]; then
  echo "No k6 result files found in ${RESULTS_DIR}. Run benchmarks first." >&2
  exit 1
fi

declare -a STRATEGIES=()
for f in "${K6_FILES[@]}"; do
  base=$(basename "${f}" -k6.json)
  STRATEGIES+=("${base}")
done

# ---------------------------------------------------------------------------
# Build markdown summary
# ---------------------------------------------------------------------------
TIMESTAMP=$(date -u '+%Y-%m-%dT%H:%M:%SZ')

{
  echo "# AI WP Dynamic Cache – Benchmark Summary"
  echo ""
  echo "> Generated: ${TIMESTAMP}"
  echo ""
  echo "## Latency & Throughput (k6)"
  echo ""
  echo "| Strategy | p50 (ms) | p95 (ms) | p99 (ms) | Req/s | Error Rate | Cache Hit Rate |"
  echo "|----------|----------|----------|----------|-------|------------|----------------|"

  for strategy in "${STRATEGIES[@]}"; do
    k6_file="${RESULTS_DIR}/${strategy}-k6.json"
    read -r p50 p95 p99 rps err hit <<< "$(extract_k6_metrics "${k6_file}")"
    printf "| %-20s | %-8s | %-8s | %-8s | %-5s | %-10s | %-14s |\n" \
      "${strategy}" "${p50}" "${p95}" "${p99}" "${rps}" "${err}" "${hit}"
  done

  echo ""
  echo "## Lighthouse Performance"
  echo ""
  echo "| Strategy | Perf Score | FCP | LCP | TBT | CLS |"
  echo "|----------|-----------|-----|-----|-----|-----|"

  for strategy in "${STRATEGIES[@]}"; do
    lh_file="${RESULTS_DIR}/${strategy}-lighthouse.json"
    read -r perf fcp lcp tbt cls <<< "$(extract_lighthouse_metrics "${lh_file}")"
    printf "| %-20s | %-9s | %-6s | %-6s | %-6s | %-4s |\n" \
      "${strategy}" "${perf}" "${fcp}" "${lcp}" "${tbt}" "${cls}"
  done

  echo ""
  echo "## Files"
  echo ""
  for strategy in "${STRATEGIES[@]}"; do
    echo "- \`results/${strategy}-k6.json\`"
    echo "- \`results/${strategy}-lighthouse.json\`"
  done
} > "${SUMMARY_FILE}"

echo "Summary written to: ${SUMMARY_FILE}"
cat "${SUMMARY_FILE}"

# ---------------------------------------------------------------------------
# Optional: upload results
# ---------------------------------------------------------------------------
if [[ -n "${RESULTS_WEBHOOK}" ]]; then
  echo ""
  echo "Uploading results to ${RESULTS_WEBHOOK} …"
  payload=$(jq -n \
    --arg ts "${TIMESTAMP}" \
    --rawfile summary "${SUMMARY_FILE}" \
    '{timestamp: $ts, summary: $summary}')
  http_code=$(curl -sf -o /dev/null -w "%{http_code}" \
    -X POST \
    -H "Content-Type: application/json" \
    -d "${payload}" \
    "${RESULTS_WEBHOOK}" || echo "000")
  if [[ "${http_code}" =~ ^2 ]]; then
    echo "Upload succeeded (HTTP ${http_code})."
  else
    echo "Upload failed (HTTP ${http_code})." >&2
  fi
fi
