=== KI-Kennzeichnung für Medien ===
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.2.0
License: GPLv2 or later

Markiert Bilder und Medien in der WordPress-Mediathek als KI-generiert und blendet
im Frontend automatisch einen Hinweis ein.

== Installation ==

1. Plugins → Installieren → Plugin hochladen → ZIP auswählen → Installieren → Aktivieren.
2. Einstellungen → KI-Kennzeichnung: Text, Darstellung, Position und Optik festlegen.
   Standard ist "Dauerhaft im Bild sichtbar" — das ist die Variante, die eine
   Kennzeichnungspflicht erfüllen kann.
3. Mediathek öffnen, ein Medium anklicken und "Mit KI erstellt oder verändert" ankreuzen.
   Alternativ in der Listenansicht die Spalte "KI" anklicken oder mehrere Medien
   per Sammelaktion markieren.

== Was das Plugin tut ==

* Checkbox + optionaler eigener Hinweistext pro Medium (Mediathek-Modal und Bearbeiten-Seite)
* Spalte "KI" mit Ein-Klick-Umschalter in der Listenansicht
* Sammelaktionen: markieren / Kennzeichnung entfernen
* Filter "Nur KI-generierte" in der Mediathek
* Frontend-Ausgabe für Inhaltsbilder (Blöcke, Galerien) und Beitragsbilder
* Vier Darstellungen: dauerhaft im Bild, dauerhaft + Textzeile, nur Textzeile,
  nur Hover (Letzteres ausdrücklich nicht für die Kennzeichnungspflicht geeignet)
* data-ai-generated="true" an Bild und Wrapper — maschinell auswertbar
* Hinweis erscheint auch im Ausdruck und im RSS-Feed
* Optional: Hinweis wird an das alt-Attribut angehängt
* Meta-Felder sind über die REST-API lesbar (_aikz_is_ai, _aikz_text)

== Hooks für Entwickler ==

* apply_filters( 'aikz_is_ai', bool $is_ai, int $attachment_id )
* apply_filters( 'aikz_label', string $text, int $attachment_id )
* apply_filters( 'aikz_badge_html', string $html, int $attachment_id, string $text )
* aikz_badge( int $attachment_id ) — Template-Funktion, gibt das Badge-HTML zurück

== Wenn sich die Bildgröße ändert ==

Einstellungen → KI-Kennzeichnung → "Einbindung ins Layout" durchprobieren:
"Automatisch" (Standard) → "Container füllen" (Karten/Slider mit fester Bildhöhe)
→ "Ohne Container" (Badge wird per JS gesetzt, das Bild bleibt unverändert im HTML).

== Bekannte Grenzen ==

* Themes, die Bilder über get_the_post_thumbnail_url() o. ä. selbst zusammenbauen,
  werden nicht automatisch erfasst — dort aikz_badge() im Template einsetzen.
* Videos und Audios werden in der Mediathek gekennzeichnet, im Frontend aber nicht
  automatisch überlagert.
* Das Plugin erzeugt eine sichtbare Kennzeichnung im HTML. Eine maschinenlesbare
  Markierung in der Bilddatei selbst (C2PA/Content Credentials, IPTC digitalSourceType)
  wird nicht geschrieben — beim Herunterladen des Bildes geht die Kennzeichnung verloren.

== Changelog ==

= 1.2.0 =
* Fix: Der Hinweis-Container konnte die Bildgröße verändern (z. B. in Karten,
  Rastern und Slidern mit fester Bildhöhe). Es werden keine Größenangaben mehr
  auf das Bild gesetzt.
* Neue Option "Einbindung ins Layout": Automatisch / Container füllen /
  Ohne Container (JavaScript).

= 1.1.0 =
* Standard ist jetzt "Dauerhaft im Bild sichtbar"; bestehende Hover-Einstellungen
  werden einmalig umgestellt.
* Neuer Modus "Dauerhaft im Bild + Textzeile darunter".
* Hover-Modus in den Einstellungen als nicht konform gekennzeichnet, inkl. Warnhinweis.
* Kennzeichnung gegen Theme-CSS abgesichert, Druck- und Feed-Ausgabe ergänzt.
* Maschinenlesbares Attribut data-ai-generated.

= 1.0.0 =
* Erste Version.
