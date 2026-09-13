#!/usr/bin/env bash
set -euo pipefail

# Builds a clean production zip for WordPress.org submission
REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
BUILD_DIR="${REPO_ROOT}/dist"
PLUGIN_SLUG="clockwork-renegade"
STAGE_DIR="${BUILD_DIR}/${PLUGIN_SLUG}"
ZIP_FILE="${BUILD_DIR}/${PLUGIN_SLUG}.zip"

echo "==> Cleaning build artifacts..."
rm -rf "${BUILD_DIR}"
mkdir -p "${STAGE_DIR}"

echo "==> Copying plugin distribution files..."
cp "${REPO_ROOT}/clockwork-renegade.php" "${STAGE_DIR}/"
cp "${REPO_ROOT}/readme.txt" "${STAGE_DIR}/"
cp "${REPO_ROOT}/uninstall.php" "${STAGE_DIR}/"
cp "${REPO_ROOT}/LICENSE" "${STAGE_DIR}/"
cp -R "${REPO_ROOT}/src" "${STAGE_DIR}/"
cp -R "${REPO_ROOT}/assets" "${STAGE_DIR}/"

# Remove any stray IDE or OS metadata files
find "${STAGE_DIR}" -name ".DS_Store" -delete || true

echo "==> Creating distribution zip: ${ZIP_FILE}..."
(cd "${BUILD_DIR}" && zip -q -r "${PLUGIN_SLUG}.zip" "${PLUGIN_SLUG}")

echo "==> Release zip built successfully!"
ls -lh "${ZIP_FILE}"
shasum -a 256 "${ZIP_FILE}" > "${ZIP_FILE}.sha256"
cat "${ZIP_FILE}.sha256"
