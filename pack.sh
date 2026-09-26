#!/usr/bin/env bash
#
# Baut build/<PluginName>-<Version>.zip. Die Version kommt aus der composer.json.
#
set -euo pipefail

PLUGIN_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLUGIN_NAME="$(basename "$PLUGIN_DIR")"
VERSION="$(sed -n 's/.*"version"[[:space:]]*:[[:space:]]*"\([^"]*\)".*/\1/p' "$PLUGIN_DIR/composer.json" | head -n 1)"

if [ -z "$VERSION" ]; then
    echo "Fehler: Keine Version in composer.json gefunden." >&2
    exit 1
fi

bash "$PLUGIN_DIR/sync-admin.sh" > /dev/null

BUILD_DIR="$PLUGIN_DIR/build"
ZIP="$BUILD_DIR/${PLUGIN_NAME}-${VERSION}.zip"
mkdir -p "$BUILD_DIR"
rm -f "$ZIP"

cd "$PLUGIN_DIR/.."
zip -rq "$ZIP" "$PLUGIN_NAME" \
    -x "$PLUGIN_NAME/build/*" \
    -x "$PLUGIN_NAME/.git/*" \
    -x "*/.DS_Store"

echo "Erstellt: build/${PLUGIN_NAME}-${VERSION}.zip"
