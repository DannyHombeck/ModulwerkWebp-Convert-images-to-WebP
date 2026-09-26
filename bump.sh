#!/usr/bin/env bash
#
# Zaehlt die Version in der composer.json hoch und legt einen
# Changelog-Eintrag in beiden Sprachen an.
#
#   ./bump.sh                      1.0.8 -> 1.0.9
#   ./bump.sh minor                1.0.8 -> 1.1.0
#   ./bump.sh major                1.0.8 -> 2.0.0
#   ./bump.sh patch "Text"         Eintrag direkt mitgeben
#   ./bump.sh patch "DE" "EN"      Eintrag je Sprache
#
set -euo pipefail

PLUGIN_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
LEVEL="${1:-patch}"
NOTE="${2:-}"
NOTE_EN="${3:-$NOTE}"

OLD="$(sed -n 's/.*"version"[[:space:]]*:[[:space:]]*"\([^"]*\)".*/\1/p' "$PLUGIN_DIR/composer.json" | head -n 1)"

if [ -z "$OLD" ]; then
    echo "Fehler: Keine Version in composer.json gefunden." >&2
    exit 1
fi

MAJOR="${OLD%%.*}"
REST="${OLD#*.}"
MINOR="${REST%%.*}"
PATCH="${REST#*.}"
PATCH="${PATCH%%.*}"

case "$LEVEL" in
    patch) PATCH=$((PATCH + 1)) ;;
    minor) MINOR=$((MINOR + 1)); PATCH=0 ;;
    major) MAJOR=$((MAJOR + 1)); MINOR=0; PATCH=0 ;;
    *) echo "Verwendung: ./bump.sh [patch|minor|major] [\"Changelog-Text\"]" >&2; exit 1 ;;
esac

NEW="${MAJOR}.${MINOR}.${PATCH}"

tmp="composer.json.tmp.$$"
sed "s/\"version\"[[:space:]]*:[[:space:]]*\"${OLD}\"/\"version\": \"${NEW}\"/" \
    "$PLUGIN_DIR/composer.json" > "$PLUGIN_DIR/$tmp"
mv "$PLUGIN_DIR/$tmp" "$PLUGIN_DIR/composer.json"

add_entry() {
    local file="$1"
    local text="$2"
    local tmp="${file}.tmp.$$"

    {
        echo "# ${NEW}"
        echo "- ${text}"
        echo
        cat "$file"
    } > "$tmp"

    mv "$tmp" "$file"
}

DE="${NOTE:-Änderungen ergänzen}"
EN="${NOTE_EN:-Describe changes}"

add_entry "$PLUGIN_DIR/CHANGELOG_de-DE.md" "$DE"
add_entry "$PLUGIN_DIR/CHANGELOG_en-GB.md" "$EN"

# Die Version steht auch im Admin-JS (Support-Karte) und muss mitwandern.
ADMIN_JS="$PLUGIN_DIR/src/Resources/app/administration/src/main.js"

if [ -f "$ADMIN_JS" ] && grep -q "var PLUGIN_VERSION" "$ADMIN_JS"; then
    tmp="${ADMIN_JS}.tmp.$$"
    sed "s/var PLUGIN_VERSION = '[^']*';/var PLUGIN_VERSION = '${NEW}';/" "$ADMIN_JS" > "$tmp"
    mv "$tmp" "$ADMIN_JS"

    if [ -x "$PLUGIN_DIR/sync-admin.sh" ] || [ -f "$PLUGIN_DIR/sync-admin.sh" ]; then
        bash "$PLUGIN_DIR/sync-admin.sh" > /dev/null
        echo "Admin-Asset aktualisiert."
    fi
fi

# Dieselbe Nummer kann als Cache-Stempel in Storefront-Templates stehen.
# Gesucht wird die Zeile: {% set pluginVersion = '1.2.3' %}
VIEWS_DIR="$PLUGIN_DIR/src/Resources/views"

if [ -d "$VIEWS_DIR" ]; then
    while IFS= read -r twig; do
        tmp="${twig}.tmp.$$"
        sed "s/set pluginVersion = '[^']*'/set pluginVersion = '${NEW}'/" "$twig" > "$tmp"
        mv "$tmp" "$twig"
        echo "Aktualisiert: ${twig#"$PLUGIN_DIR"/}"
    done < <(grep -rl "set pluginVersion" "$VIEWS_DIR" 2>/dev/null || true)
fi

echo "Version: ${OLD} -> ${NEW}"

if [ -z "$NOTE" ]; then
    echo "Changelog-Eintraege noch ausfuellen:"
    echo "  CHANGELOG_de-DE.md"
    echo "  CHANGELOG_en-GB.md"
fi
