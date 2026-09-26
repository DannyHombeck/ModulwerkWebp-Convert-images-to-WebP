# 2.0.6
- Rechteinhaber in Lizenz und composer.json: Danny Hombeck

# 2.0.5
- Lizenz auf MIT umgestellt (composer.json, LICENSE.md mit deutscher Übersetzung, Angabe im Admin)

# 2.0.4
- Support-Mail auf post@danny-hombeck.de geändert

# 2.0.3
- Hersteller- und Support-Adresse auf danny-hombeck.de umgestellt (Website https://danny-hombeck.de/, Support-Mail support@danny-hombeck.de)

# 2.0.2
- Farbprofile: Bilder in Adobe RGB, Display P3 oder CMYK werden mit Imagick vor der Umwandlung nach sRGB umgerechnet statt farbverschoben ausgeliefert, mit GD übersprungen; Konsole hängt nicht mehr endlos, wenn ein Eintrag im Medienordner nicht angelegt werden kann; geplante Aufgabe nutzt ab Installation den eingestellten Abstand (Standard 24 Stunden statt 5 Minuten) und reagiert auf den Wechsel zwischen Abstand und fester Uhrzeit; Admin-Endpunkte und Menü prüfen Benutzerrechte (Medien ansehen/bearbeiten/löschen, Plugin-Verwaltung); kein imagedestroy() mehr (veraltet ab PHP 8.5); englische README vervollständigt, Hilfetexte bereinigt

# 2.0.1
- README: Verweis auf ein fremdes Grundgerüst entfernt

# 2.0.0
- Shopware 6.8 unterstützt: Service- und Routendefinition als PHP statt XML (Symfony 8), Admin nutzt durchgängig $t statt des entfernten $tc; täglicher Lauf prüft alle fünf Minuten die Uhrzeit statt den Termin selbst zu setzen; läuft weiterhin unter 6.7

# 1.4.2
- Menüeintrag unter Inhalte erscheint auch dann, wenn das Admin-Asset erst nach dem Aufbau des Hauptmenüs geladen wird

# 1.4.1
- Standardabstand der geplanten Aufgabe auf 1440 Minuten (24 Stunden) geändert

# 1.4.0
- Geplante Aufgabe wahlweise alle paar Minuten (bis 1440 = 24 Stunden) oder einmal täglich zu fester Uhrzeit; ab einer Stunde Abstand arbeitet ein Lauf alle offenen Bilder ab (max. 15 Minuten)

# 1.3.0
- Abstand der geplanten Aufgabe in Minuten einstellbar (Standard 5); Ausschalten setzt die Aufgabe unter Einstellungen → System → Aufgaben auf inaktiv, Einschalten plant sie wieder ein

# 1.2.5
- Fehler behoben: „Offene Bilder umwandeln“ brach mit „that.api().call is not a function“ ab

# 1.2.4
- Übernahme von Alt-Text und Titel vom Original ist abschaltbar (Einstellung unter Medienverwaltung); beim Wiedereinschalten werden alle Einträge einmal abgeglichen

# 1.2.3
- Neuer Button „Alle Bilder neu umwandeln“ in der Übersicht: verwirft alle WebP-Dateien und wandelt sämtliche Bilder mit den aktuellen Einstellungen neu um, Cache wird einmal am Ende geleert

# 1.2.2
- Alt-Text und Titel der Originale werden in allen Sprachen in den Medienordner übernommen und bei Änderungen am Original nachgezogen; bestehende Einträge werden beim Update ergänzt

# 1.2.1
- Einstellung „Bildbibliothek“ verständlicher benannt: „PHP-Bildverarbeitung (GD / Imagick)“, Optionen mit Empfehlung, Hilfetext erklärt GD und Imagick

# 1.2.0
- Seiten-Cache (HTTP-Cache/Varnish) wird nach jeder Verarbeitung automatisch geleert, wenn WebP-Dateien entstanden, entfernt oder geändert wurden – beim Admin-Button einmal am Ende des Laufs; neue Einstellung „Cache nach der Verarbeitung automatisch leeren“

# 1.1.4
- Hilfetexte aller Einstellungen ausführlich überarbeitet (Wirkung, Empfehlungen, Voraussetzungen), auf Deutsch und Englisch

# 1.1.3
- Anzeigename „Modulwerk WebP-Konverter“ in Menü, Einstellungen, Seitentiteln und Medienordner; vorhandener Ordner wird beim Update umbenannt

# 1.1.2
- Plugin-Icon statt Standard-Symbol unter Einstellungen → Erweiterungen

# 1.1.1
- Meldungen im Admin zeigen wieder Zahlen und Details (Platzhalter werden über vue-i18n übergeben)

# 1.1.0
- Eigener Ordner „WebP-Konverter“ unter Inhalte → Medien mit der WebP-Fassung jedes Originalbildes (ohne Kopie, ohne Thumbnails); bestehende Umwandlungen werden nachgetragen; Löschen/Umbenennen im Ordner wird erkannt; „Unbenutzte Medien löschen“ lässt die Einträge stehen; neue Einstellung Medienverwaltung; Button „Medienordner öffnen“ in der Übersicht

# 1.0.3
- Neue Option „JPG verlustfrei umwandeln“; ist ein verlustfreies WebP (PNG oder JPG) nicht kleiner als das Original, wird automatisch verlustbehaftet umgewandelt statt übersprungen

# 1.0.2
- Einstellungen vollständig zweisprachig: Englisch als Standard, Deutsch mit lang="de-DE" (vorher erschien im deutschen Admin Englisch); fehlende Hilfetexte ergänzt

# 1.0.1
- Admin-Snippets in eigene Dateien de-DE.json und en-GB.json ausgelagert (serverseitig geladen, ohne Build); Hinweise der Umwandlung werden sprachneutral gespeichert und im Admin auf Deutsch oder Englisch angezeigt; Umlaute in den deutschen Texten korrigiert

# 1.0.0
- Erste Version: Konvertierung von JPG/PNG samt Thumbnails nach WebP, Auslieferung in der Storefront, Übersicht mit Fortschritt im Admin, CLI-Befehle, geplante Aufgabe, optionales Lazy Loading
