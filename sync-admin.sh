#!/usr/bin/env bash
#
# Kopiert das Administrations-JS an die Stelle, von der Shopware es laedt.
#
# Die Datei wird nicht gebaut, sondern 1:1 uebernommen. Deshalb muss sie
# browserfertig sein: kein import/export, kein JSX, keine .twig-Imports.
#
# Der Dateiname des Assets traegt die Versionsnummer. Sonst liefern Browser
# und Proxys nach einem Update weiter die alte Fassung aus.
#
set -euo pipefail

PLUGIN_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLUGIN_NAME="$(basename "$PLUGIN_DIR")"

# Bundle-Name in Kleinbuchstaben, so heisst der Ordner unter public/bundles/
FLAT="$(printf '%s' "$PLUGIN_NAME" | tr '[:upper:]' '[:lower:]')"

# Kebab-Schreibweise, zum Beispiel ModulwerkError404 -> modulwerk-error404
KEBAB="$(printf '%s' "$PLUGIN_NAME" \
    | sed 's/\([a-z0-9]\)\([A-Z]\)/\1-\2/g' \
    | tr '[:upper:]' '[:lower:]')"

VERSION="$(sed -n 's/.*"version"[[:space:]]*:[[:space:]]*"\([^"]*\)".*/\1/p' "$PLUGIN_DIR/composer.json" | head -n 1)"

if [ -z "$VERSION" ]; then
    echo "Fehler: Keine Version in composer.json gefunden." >&2
    exit 1
fi

SOURCE="$PLUGIN_DIR/src/Resources/app/administration/src/main.js"
PUBLIC_DIR="$PLUGIN_DIR/src/Resources/public/administration"
TARGET_DIR="$PUBLIC_DIR/assets"
ASSET_FILE="${FLAT}-${VERSION}.js"
TARGET="$TARGET_DIR/$ASSET_FILE"
ASSET_PATH="/bundles/${FLAT}/administration/assets/${ASSET_FILE}"

if [ ! -f "$SOURCE" ]; then
    echo "Fehler: $SOURCE fehlt." >&2
    exit 1
fi

if grep -Eq '^[[:space:]]*(import|export)[[:space:]]' "$SOURCE"; then
    echo "Fehler: $SOURCE enthaelt import/export." >&2
    echo "  Diese Datei wird nicht gebaut und muss ohne Module auskommen." >&2
    exit 1
fi

if ! node --check "$SOURCE" 2>/dev/null; then
    if command -v node > /dev/null 2>&1; then
        echo "Fehler: $SOURCE ist syntaktisch nicht gueltig." >&2
        exit 1
    fi
    echo "Hinweis: node nicht vorhanden, Syntaxpruefung uebersprungen."
fi

mkdir -p "$TARGET_DIR"

# Aeltere Fassungen entfernen, sonst wandern sie mit ins ZIP.
rm -f "$TARGET_DIR/${FLAT}"*.js

# Dokumente base64-kodiert in das Asset schreiben.
# base64 statt Text, damit keine Anfuehrungszeichen oder Zeilenumbrueche
# maskiert werden muessen - das ginge in reinem Bash schnell schief.
DOC_LINE="    var DOC_DATA = {"

for doc in README.md README_en-GB.md CHANGELOG_de-DE.md CHANGELOG_en-GB.md LICENSE.md; do
    if [ -f "$PLUGIN_DIR/$doc" ]; then
        if base64 -w 0 "$PLUGIN_DIR/$doc" > /dev/null 2>&1; then
            encoded="$(base64 -w 0 "$PLUGIN_DIR/$doc")"
        else
            # macOS kennt -w nicht
            encoded="$(base64 < "$PLUGIN_DIR/$doc" | tr -d '\n')"
        fi

        DOC_LINE="${DOC_LINE}'${doc}':'${encoded}',"
    fi
done

DOC_LINE="${DOC_LINE}};"

awk -v repl="$DOC_LINE" '
    index($0, "/* __DOC_DATA__ */") > 0 { print repl; next }
    { print }
' "$SOURCE" > "$TARGET"

# Snippets aus snippet/<locale>.json als Rueckfall in das Asset schreiben.
# JSON enthaelt keine echten Zeilenumbrueche in Strings, tr ist daher sicher.
# ENVIRON statt awk -v, weil -v Backslashes in den Texten auswerten wuerde.
SNIPPET_DIR="$PLUGIN_DIR/src/Resources/app/administration/src/snippet"
SNIPPET_LINE="    var SNIPPETS = {"
first=1

for locale in de-DE en-GB; do
    if [ -f "$SNIPPET_DIR/$locale.json" ]; then
        [ $first -eq 0 ] && SNIPPET_LINE="${SNIPPET_LINE},"
        first=0
        SNIPPET_LINE="${SNIPPET_LINE}\"${locale}\":$(tr -d '\n\r' < "$SNIPPET_DIR/$locale.json")"
    fi
done

SNIPPET_LINE="${SNIPPET_LINE}};"

if grep -q '/\* __SNIPPET_DATA__ \*/' "$TARGET"; then
    tmp="${TARGET}.tmp.$$"
    REPL="$SNIPPET_LINE" awk '
        index($0, "/* __SNIPPET_DATA__ */") > 0 { print ENVIRON["REPL"]; next }
        { print }
    ' "$TARGET" > "$tmp"
    mv "$tmp" "$TARGET"
fi

if ! grep -q "var DOC_DATA = {'" "$TARGET"; then
    echo "Fehler: Die Dokumente wurden nicht in das Asset geschrieben." >&2
    echo "  Steht der Platzhalter /* __DOC_DATA__ */ noch in main.js?" >&2
    exit 1
fi

# Einstiegspunkte neu schreiben, damit sie auf den aktuellen Dateinamen zeigen.
# Derselbe Pfad steht unter mehreren Entry-Namen (flach, Kebab-Case,
# Original), damit der Eintrag unabhaengig davon greift, welche Variante
# Shopware aus dem Bundle-Namen ableitet.
mkdir -p "$PUBLIC_DIR/.vite"

{
    printf '{\n'
    printf '  "base": "/bundles/%s/administration/",\n' "$FLAT"
    printf '  "entryPoints": {\n'

    first=1
    for entry in "$FLAT" "$KEBAB" "$PLUGIN_NAME"; do
        [ $first -eq 0 ] && printf ',\n'
        first=0
        printf '    "%s": {\n' "$entry"
        printf '      "css": [],\n'
        printf '      "dynamic": [],\n'
        printf '      "js": [\n        "%s"\n      ],\n' "$ASSET_PATH"
        printf '      "legacy": false,\n'
        printf '      "preload": []\n'
        printf '    }'
    done

    printf '\n  },\n'
    printf '  "legacy": false,\n'
    printf '  "metadatas": {},\n'
    printf '  "version": [\n    "7.1.0",\n    7,\n    1,\n    0\n  ],\n'
    printf '  "viteServer": null\n'
    printf '}\n'
} > "$PUBLIC_DIR/.vite/entrypoints.json"

cat > "$PUBLIC_DIR/.vite/manifest.json" <<MANIFEST
{
  "main.js": {
    "file": "assets/${ASSET_FILE}",
    "name": "${FLAT}",
    "src": "main.js",
    "isEntry": true,
    "css": [],
    "assets": []
  }
}
MANIFEST

# Dokumentation mit ausliefern, damit das Admin-Modul sie anzeigen kann.
DOC_DIR="$PUBLIC_DIR/doc"
mkdir -p "$DOC_DIR"

for doc in README.md README_en-GB.md CHANGELOG_de-DE.md CHANGELOG_en-GB.md LICENSE.md; do
    if [ -f "$PLUGIN_DIR/$doc" ]; then
        cp "$PLUGIN_DIR/$doc" "$DOC_DIR/$doc"
    fi
done

echo "Kopiert nach: src/Resources/public/administration/assets/${ASSET_FILE}"
echo "Einstiegspunkte aktualisiert: ${ASSET_PATH}"
echo "Dokumentation nach: src/Resources/public/administration/doc/"
echo
echo "Auf dem Server danach:"
echo "  bin/console plugin:update ${PLUGIN_NAME}"
echo "  bin/console assets:install"
echo "  bin/console cache:clear"
