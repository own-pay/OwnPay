#!/bin/bash
# ============================================================
# OwnPay Release Build Script
# Run from the OwnPay project root to create a release package
# Usage: bash docs/update_system/build_release.sh 0.2.0
# ============================================================

set -euo pipefail

VERSION="${1:?Usage: build_release.sh <version>}"
BUILD_DIR="build/release-${VERSION}"
ZIP_NAME="ownpay-${VERSION}.zip"
OUTPUT_DIR="build/output"

echo "=== Building OwnPay ${VERSION} ==="

# Clean
rm -rf "${BUILD_DIR}" "${OUTPUT_DIR}"
mkdir -p "${BUILD_DIR}" "${OUTPUT_DIR}"

# ─── Copy release files ────────────────────────────────────
echo "[1/6] Copying source files..."

# Directories to include
for dir in src config templates public database modules; do
    if [ -d "$dir" ]; then
        cp -r "$dir" "${BUILD_DIR}/"
    fi
done

# Root files to include
for file in composer.json composer.lock .env.example LICENSE README.md; do
    if [ -f "$file" ]; then
        cp "$file" "${BUILD_DIR}/"
    fi
done

# ─── Exclude sensitive/unnecessary files ────────────────────
echo "[2/6] Cleaning excluded files..."

# Remove test files
rm -rf "${BUILD_DIR}/tests"
rm -rf "${BUILD_DIR}/storage"
rm -rf "${BUILD_DIR}/.git"
rm -rf "${BUILD_DIR}/.github"
rm -rf "${BUILD_DIR}/vendor"
rm -rf "${BUILD_DIR}/docs"
rm -rf "${BUILD_DIR}/node_modules"

# Remove dev configs
rm -f "${BUILD_DIR}/.env"
rm -f "${BUILD_DIR}/.gitignore"
rm -f "${BUILD_DIR}/phpunit.xml"
rm -f "${BUILD_DIR}/phpstan.neon"

# ─── Bump version in config/app.php ─────────────────────────
echo "[3/6] Bumping version to ${VERSION}..."

sed -i "s/'version' => '[^']*'/'version' => '${VERSION}'/" "${BUILD_DIR}/config/app.php"

# Verify
grep "version" "${BUILD_DIR}/config/app.php" | head -1

# ─── Create ZIP ──────────────────────────────────────────────
echo "[4/6] Creating ZIP package..."

cd "${BUILD_DIR}"
zip -r "../../${OUTPUT_DIR}/${ZIP_NAME}" . -x '*.DS_Store' '*__MACOSX*'
cd ../..

# ─── Generate checksum ──────────────────────────────────────
echo "[5/6] Generating SHA-256 checksum..."

sha256sum "${OUTPUT_DIR}/${ZIP_NAME}" > "${OUTPUT_DIR}/checksum.sha256"
cat "${OUTPUT_DIR}/checksum.sha256"

# ─── Summary ─────────────────────────────────────────────────
echo "[6/6] Build complete!"
echo ""
echo "Files in ${OUTPUT_DIR}/:"
ls -la "${OUTPUT_DIR}/"
echo ""
echo "Next steps:"
echo "  1. Copy ${OUTPUT_DIR}/${ZIP_NAME} to update.ownpay.org/releases/${VERSION}/"
echo "  2. Copy ${OUTPUT_DIR}/checksum.sha256 to update.ownpay.org/releases/${VERSION}/"
echo "  3. Write changelog at update.ownpay.org/releases/${VERSION}/changelog.md"
echo "  4. Update manifest.json with new version info"
echo ""
echo "ZIP contents excluded: .env, storage/, vendor/, tests/, .git/"
