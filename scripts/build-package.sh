#!/usr/bin/env bash
set -euo pipefail
ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PLUGIN_DIR="cf-03-payments-billing-donations-financial-operations"
VERSION="1.1.0-rc.1"
BUILD_DIR="${ROOT_DIR}/build"
STAGE_DIR="${BUILD_DIR}/${PLUGIN_DIR}"
ZIP_PATH="${BUILD_DIR}/${PLUGIN_DIR}-${VERSION}.zip"
rm -rf "${BUILD_DIR}" && mkdir -p "${STAGE_DIR}"
while IFS= read -r -d '' path; do
  relative="${path#${ROOT_DIR}/}"
  mkdir -p "${STAGE_DIR}/$(dirname "${relative}")"
  cp "${path}" "${STAGE_DIR}/${relative}"
done < <(find "${ROOT_DIR}" -type f ! -path "${ROOT_DIR}/.git/*" ! -path "${ROOT_DIR}/.github/*" ! -path "${ROOT_DIR}/tests/*" ! -path "${ROOT_DIR}/build/*" ! -path "${ROOT_DIR}/scripts/*" ! -name '.gitignore' ! -name '.editorconfig' -print0 | sort -z)
find "${STAGE_DIR}" -type f -exec touch -t 202608041702 {} +
(cd "${BUILD_DIR}" && find "${PLUGIN_DIR}" -type f -print | LC_ALL=C sort | zip -X -q "${ZIP_PATH}" -@)
sha256sum "${ZIP_PATH}" > "${ZIP_PATH}.sha256"
printf 'Built %s\n' "${ZIP_PATH}"
