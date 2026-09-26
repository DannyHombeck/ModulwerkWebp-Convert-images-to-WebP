# Modulwerk WebP-Konverter (ModulwerkWebp)

Wandelt JPG- und PNG-Bilder samt Thumbnails in WebP um und liefert sie in der
Storefront aus. Die Originale bleiben unverändert; die WebP-Dateien liegen in
einem eigenen Verzeichnis und lassen sich jederzeit komplett entfernen.

## Funktionsweise

- Jede Bilddatei (Original und jedes Thumbnail) wird einzeln umgewandelt und
  unter `public/modulwerk-webp/<Originalpfad>.webp` abgelegt.
- In der Storefront werden die Bild-URLs in der fertigen HTML-Ausgabe
  umgeschrieben – egal aus welchem Template sie kommen (Produktbilder,
  Erlebniswelten, Slider, Logo, Theme-Erweiterungen). Umgeschrieben wird nur,
  was laut Datenbank wirklich als WebP vorliegt.
- Mails, Produkt-Feeds und die API bleiben unberührt, dort stehen weiter die
  Original-URLs.
- Ist die WebP-Datei nicht kleiner als das Original, wird sie übersprungen.
- Wird ein Medium gelöscht oder seine Datei ersetzt oder werden Thumbnails neu
  erzeugt, verschwinden die zugehörigen WebP-Dateien und das Bild wird beim
  nächsten Lauf neu umgewandelt.

## Voraussetzungen

- Shopware 6.7 oder 6.8
- PHP mit GD (WebP-Unterstützung) oder Imagick mit WebP-Delegate
- Browser: alle aktuellen Browser, Safari ab Version 14 (macOS Big Sur / iOS 14)

Welche Bibliothek auf dem Server zur Verfügung steht, zeigt die Übersicht.

## Bedienung

Menü **Inhalte → Modulwerk WebP-Konverter**:

- **Offene Bilder umwandeln** arbeitet alle offenen Medien in Portionen ab und
  zeigt den Fortschritt. Jede Anfrage dauert höchstens rund 20 Sekunden, so
  gibt es auch bei knappem `max_execution_time` keinen Abbruch.
- **Alle Bilder neu umwandeln** verwirft alle WebP-Dateien und wandelt
  sämtliche Bilder mit den aktuellen Einstellungen neu um, etwa nach einer
  geänderten Qualität. Der Cache wird einmal am Ende geleert.
- **Cache leeren** – danach zeigt die Storefront die WebP-Bilder.
- **Fehler erneut versuchen**, **Verwaiste Dateien entfernen**,
  **Alle WebP-Dateien löschen**
- Tabelle der zuletzt verarbeiteten Dateien mit Größe, Ersparnis und
  **Neu erzeugen** je Medium

Neue Bilder wandelt die geplante Aufgabe `modulwerk_webp.convert` um. Einstellbar
ist, ob sie in einem Abstand von Minuten läuft (Standard 1440 = 24 Stunden) oder
einmal täglich zu einer festen Uhrzeit. Ab einem Abstand von einer Stunde
arbeitet ein Lauf alle offenen Bilder ab, höchstens 15 Minuten am Stück.
Ausschalten setzt die Aufgabe unter Einstellungen → System → Aufgaben auf
inaktiv. Voraussetzung ist ein laufender Scheduled-Task-Runner oder der
Admin-Worker.

**Cache:** Die Storefront speichert fertige Seiten im HTTP-Cache. Das Plugin
leert diesen Seiten-Cache (bzw. Varnish) automatisch, sobald eine Verarbeitung
WebP-Dateien erzeugt, entfernt oder geändert hat – beim Button in der
Übersicht einmal am Ende des Laufs, bei geplanter Aufgabe, Konsolenbefehlen,
gelöschten Medien und neu erzeugten Thumbnails. Abschaltbar unter
Einstellungen → Automatik. Nach Änderungen an den Einstellungen und nach dem
Deaktivieren des Plugins den Cache selbst leeren.

## Medienordner „Modulwerk WebP-Konverter“

Unter **Inhalte → Medien** erscheint der Ordner „Modulwerk WebP-Konverter“ mit der
WebP-Fassung jedes Originalbildes. Die Einträge zeigen direkt auf die Dateien
in `modulwerk-webp/`, es wird nichts kopiert und es entstehen keine Thumbnails.
Thumbnails selbst werden dort nicht aufgeführt.

- Alt-Text und Titel kommen in allen Sprachen vom Original und werden bei
  jeder Änderung dort nachgezogen. Am Original pflegen – Änderungen direkt am
  Eintrag im Ordner werden überschrieben.
  Abschaltbar über „Alt-Text und Titel vom Original übernehmen“; beim
  Wiedereinschalten wird einmal alles abgeglichen.
- Beim Löschen eines Originals verschwindet auch sein Eintrag im Ordner.
- Wird ein Eintrag im Ordner gelöscht oder umbenannt, liefert die Storefront
  wieder das Original. „Neu erzeugen“ in der Übersicht holt ihn zurück.
- „Unbenutzte Medien löschen“ lässt die Einträge stehen.
- Die Einträge nicht Produkten oder Erlebniswelten zuweisen: Beim Neu-Erzeugen
  werden sie ersetzt, eine Zuweisung ginge dabei verloren. Die Storefront
  liefert die WebP-Fassung ohnehin automatisch aus.

Abschaltbar unter Einstellungen → Medienverwaltung.

## Einstellungen

| Einstellung | Bedeutung |
|---|---|
| WebP-Bilder ausliefern | URLs in der Storefront umschreiben (je Verkaufskanal) |
| Ausnahmen | Pfad-Teile, die nie umgeschrieben werden, z. B. der Logo-Dateiname |
| Originalbilder / Thumbnails umwandeln | was umgewandelt wird |
| Qualität | getrennt für Originale und Thumbnails (Standard 82 / 78) |
| PNG / JPG verlustfrei | pixelgenau; ist das Ergebnis nicht kleiner, wird verlustbehaftet umgewandelt |
| Überspringen, wenn nicht kleiner | vermeidet größere Dateien |
| PHP-Bildverarbeitung (GD / Imagick) | welche PHP-Erweiterung umwandelt; „Automatisch“ empfohlen |
| Cache automatisch leeren | Seiten-Cache nach jeder Verarbeitung leeren |
| Geplante Aufgabe: Abstand in Minuten oder feste Uhrzeit, Medien je Durchlauf | Automatik für neue Bilder |
| Lazy Loading | `loading="lazy"` ab dem n-ten Bild; Klasse `no-lazyload` schließt aus |

Lazy Loading nicht zusätzlich zu einem anderen Lazy-Loading-Plugin aktivieren.

## Befehle

```bash
bin/console modulwerk:webp:status                  # Stand anzeigen
bin/console modulwerk:webp:convert                 # alle offenen Bilder
bin/console modulwerk:webp:convert --limit=200     # höchstens 200 Medien
bin/console modulwerk:webp:convert --retry-errors  # Fehler erneut versuchen
bin/console modulwerk:webp:convert --force         # alles neu erzeugen
bin/console modulwerk:webp:convert -m <mediaId>    # ein Medium neu erzeugen
bin/console modulwerk:webp:clear --orphaned        # verwaiste Dateien entfernen
bin/console modulwerk:webp:clear                   # alle WebP-Dateien löschen
bin/console cache:clear
```

Für große Bestände ist die Konsole der schnellste Weg, da dort kein
Zeitlimit greift.

## Hinweise

- GD entpackt Bilder vollständig in den Speicher. Bilder, für die
  `memory_limit` nicht reicht, werden als Fehler markiert statt das Skript
  abzubrechen.
- Handy- und Kamerafotos werden anhand der EXIF-Ausrichtung gedreht.
- Farbprofile: Browser zeigen WebP ohne Profil als sRGB an. Bilder in einem
  anderen Farbraum (Adobe RGB, Display P3 von Smartphones, CMYK) rechnet
  Imagick deshalb vor der Umwandlung nach sRGB um, damit die Farben stimmen.
  GD kann das nicht – solche Bilder werden mit GD übersprungen, die
  Storefront zeigt dann das Original.
- Private Medien (z. B. Dokumente) werden nie umgewandelt.
- Bei externem Speicher (S3, CDN) landen die WebP-Dateien im selben
  öffentlichen Dateisystem.

## Rechte

Die Übersicht erscheint für Benutzer mit dem Recht, Medien anzusehen. Zum
Umwandeln braucht es das Recht, Medien zu bearbeiten, zum Löschen der
WebP-Dateien das Recht, Medien zu löschen. Die Einstellungen setzen das Recht
zur Plugin-Verwaltung voraus. Administratoren dürfen alles.

## Deinstallation

Ohne „Daten behalten“ werden entfernt: das Verzeichnis `modulwerk-webp` im
öffentlichen Dateisystem, die Tabelle `modulwerk_webp_file`, alle
Einstellungen `ModulwerkWebp.config.*` und die geplante Aufgabe. Danach den
Cache leeren.

## Entwicklung

Aufbau und Werkzeuge folgen einem eigenen Grundgerüst:

```bash
./bump.sh patch "Was geändert wurde"
./sync-admin.sh
./pack.sh
```

Das Admin-Modul (`src/Resources/app/administration/src/main.js`) wird nicht
gebaut; `sync-admin.sh` kopiert es mit Versionsnummer nach
`src/Resources/public/administration/assets/`.

## Texte (Snippets)

Alle Admin-Texte stehen in

- `src/Resources/app/administration/src/snippet/de-DE.json`
- `src/Resources/app/administration/src/snippet/en-GB.json`

Shopware lädt diese Dateien serverseitig, ein Build ist nicht nötig.
`sync-admin.sh` schreibt sie zusätzlich in das Admin-Asset, damit die Texte
auch vor dem nächsten `cache:clear` vorhanden sind. Gepflegt wird nur in den
JSON-Dateien; neue Schlüssel immer in beiden Sprachen anlegen.

Hinweise zur Umwandlung (Tabelle „Zuletzt verarbeitet“) speichert der Server
sprachneutral als `code|detail`, übersetzt werden sie über
`modulwerk-webp.messages.<code>`. Die Einstellungen (`config.xml`) sind ebenfalls
zweisprachig. Die Ausgaben der Konsolenbefehle sind Deutsch.
