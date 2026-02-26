#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
DIST_DIR="${ROOT_DIR}/dist"
STAGE_DIR="${DIST_DIR}/ai-wp-dynamic-cache"
ZIP_PATH="${DIST_DIR}/ai-wp-dynamic-cache-plugin.zip"

rm -rf "${STAGE_DIR}"
mkdir -p "${STAGE_DIR}"

cp "${ROOT_DIR}/ai-wp-dynamic-cache.php" "${STAGE_DIR}/"
cp "${ROOT_DIR}/README.md" "${STAGE_DIR}/"

rm -f "${ZIP_PATH}"
(
  cd "${DIST_DIR}"
  zip -rq "${ZIP_PATH}" "ai-wp-dynamic-cache"
)

echo "Created ${ZIP_PATH}"
