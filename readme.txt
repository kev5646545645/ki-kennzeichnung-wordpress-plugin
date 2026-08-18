=== KI-Kennzeichnung für Medien ===
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later

Markiert Bilder und Medien in der WordPress-Mediathek als KI-generiert und blendet
im Frontend automatisch einen Hinweis ein.

== Installation ==

1. Plugins → Installieren → Plugin hochladen → ZIP auswählen → Installieren → Aktivieren.
2. Einstellungen → KI-Kennzeichnung: Text, Darstellung (dauerhaft / Hover / Bildunterschrift),
   Position und Optik festlegen.
3. Mediathek öffnen, ein Medium anklicken und "Mit KI erstellt oder verändert" ankreuzen.
   Alternativ in der Listenansicht die Spalte "KI" anklicken oder mehrere Medien
   per Sammelaktion markieren.

== Was das Plugin tut ==

* Checkbox + optionaler eigener Hinweistext pro Medium (Mediathek-Modal und Bearbeiten-Seite)
* Spalte "KI" mit Ein-Klick-Umschalter in der Listenansicht
* Sammelaktionen: markieren / Kennzeichnung entfernen
* Filter "Nur KI-generierte" in der Mediathek
* Frontend-Ausgabe für Inhaltsbilder (Blöcke, Galerien) und Beitragsbilder
* Optional: Hinweis wird an das alt-Attribut angehängt
* Meta-Felder sind über die REST-API lesbar (_aikz_is_ai, _aikz_text)

== Hooks für Entwickler ==

* apply_filters( 'aikz_is_ai', bool $is_ai, int $attachment_id )
* apply_filters( 'aikz_label', string $text, int $attachment_id )
* apply_filters( 'aikz_badge_html', string $html, int $attachment_id, string $text )
* aikz_badge( int $attachment_id ) — Template-Funktion, gibt das Badge-HTML zurück

== Bekannte Grenzen ==

* Themes, die Bilder über get_the_post_thumbnail_url() o. ä. selbst zusammenbauen,
  werden nicht automatisch erfasst — dort aikz_badge() im Template einsetzen.
* Videos und Audios werden in der Mediathek gekennzeichnet, im Frontend aber nicht
  automatisch überlagert.
* Das Plugin erzeugt eine sichtbare Kennzeichnung. Eine maschinenlesbare Markierung
  (z. B. C2PA/Content Credentials) wird nicht geschrieben.
