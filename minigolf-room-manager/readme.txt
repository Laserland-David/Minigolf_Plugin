=== MiniGolf Room Manager ===
Contributors: codex-agent
Tags: minigolf, scorecard, rooms
Requires at least: 6.0
Tested up to: 6.5
Stable tag: 0.1.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

== Description ==
Dieses Plugin ermöglicht das Anlegen virtueller Minigolf-Räume. Besucher können Spieler erfassen, Scores eintragen und die Ergebnisse über einen Raumcode aufrufen.

== Installation ==
1. Plugin-Ordner in `wp-content/plugins` hochladen.
2. Plugin im Admin unter "Plugins" aktivieren.

**So erstellst du die ZIP-Datei:**
1. Im Terminal im Plugin-Verzeichnis `zip -r minigolf-room-manager.zip minigolf-room-manager` ausführen.
2. Anschließend die ZIP im WordPress-Dashboard unter „Plugins → Installieren → Plugin hochladen“ auswählen.
3. Nach erfolgreicher Installation das Plugin aktivieren.

== Verwendung ==
Nach Erstellung eines Raums kann das Ergebnis mit dem Shortcode `[minigolf_room code="RAUMCODE"]` eingebunden werden.

== Changelog ==
= 0.1.1 =
* Basisdesign und responsive Styles hinzugefügt

= 0.1.0 =
* Erste Version
