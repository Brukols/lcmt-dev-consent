#!/usr/bin/env bash
#
# Build a clean, production-ready plugin zip for upload to a WordPress site.
#
#   - Compiles the front-end assets (production / hashed) so assets/dist is fresh.
#   - Copies ONLY the runtime files into a staging folder (allowlist, so new
#     dev files can never leak into a release).
#   - Zips it with a top-level `lcmt-dev-consent/` folder — the exact shape the
#     WordPress "Plugins → Add New → Upload Plugin" screen expects.
#
# Usage:  npm run package        (or)        bash bin/build.sh
# Output: build/lcmt-dev-consent-<version>.zip
#
set -euo pipefail

SLUG="lcmt-dev-consent"

# Resolve the plugin root from this script's location so it works from anywhere.
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
cd "$ROOT"

# Runtime files/dirs that MUST ship. Everything not listed here is excluded.
INCLUDE=(
  "lcmt-dev-consent.php"
  "uninstall.php"
  "readme.txt"
  "src"
  "languages"
  "assets/dist"
  "lib"
)

# --- Tooling checks -----------------------------------------------------------
command -v zip >/dev/null 2>&1 || { echo "✗ 'zip' is required but not installed." >&2; exit 1; }
command -v npm >/dev/null 2>&1 || { echo "✗ 'npm' is required but not installed." >&2; exit 1; }

# --- Version (read from the plugin header) ------------------------------------
VERSION="$(grep -m1 -E '^\s*\*\s*Version:' "$ROOT/lcmt-dev-consent.php" | sed -E 's/.*Version:[[:space:]]*//' | tr -d '[:space:]')"
VERSION="${VERSION:-0.0.0}"

echo "▶ Building $SLUG v$VERSION"

# --- 1. Build assets (production) ---------------------------------------------
if [ ! -d "$ROOT/node_modules" ]; then
  echo "▶ Installing node dependencies (node_modules missing)…"
  npm ci 2>/dev/null || npm install
fi
echo "▶ Compiling assets (npm run build)…"
npm run build

if [ ! -f "$ROOT/assets/dist/manifest.json" ]; then
  echo "✗ assets/dist/manifest.json missing after build — aborting." >&2
  exit 1
fi

# --- 2. Stage the runtime files (allowlist) -----------------------------------
BUILD_DIR="$ROOT/build"
STAGE="$BUILD_DIR/$SLUG"
ZIP_PATH="$BUILD_DIR/${SLUG}-${VERSION}.zip"

rm -rf "$BUILD_DIR"
mkdir -p "$STAGE"

for item in "${INCLUDE[@]}"; do
  if [ ! -e "$ROOT/$item" ]; then
    echo "✗ Required path missing: $item — aborting." >&2
    exit 1
  fi
  # Recreate the relative directory structure inside the stage (handles nested
  # paths like assets/dist).
  dest="$STAGE/$item"
  mkdir -p "$(dirname "$dest")"
  cp -R "$ROOT/$item" "$dest"
done

# Belt-and-suspenders: strip junk that could ride along inside copied dirs.
find "$STAGE" -name '.DS_Store' -delete
find "$STAGE" -name '*.map' -delete

# --- 3. Zip it ----------------------------------------------------------------
( cd "$BUILD_DIR" && zip -rq "$ZIP_PATH" "$SLUG" )

# --- 4. Report ----------------------------------------------------------------
SIZE="$(du -h "$ZIP_PATH" | cut -f1 | tr -d '[:space:]')"
echo ""
echo "✓ Built $ZIP_PATH ($SIZE)"
echo "  Contents:"
unzip -l "$ZIP_PATH" | awk 'NR>3 && $4 != "" {print "    " $4}' | grep -v '/$' | sed "s#^    $SLUG/#    #" | sort
echo ""
echo "  Upload this zip via WordPress → Plugins → Add New → Upload Plugin."
