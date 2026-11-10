# Vorlage für das LoxBerry-Wiki

`senec2lox_start.txt` ist die Seite für das [LoxBerry-Wiki](https://wiki.loxberry.de/plugins/start) im **DokuWiki-Format**
(Aufbau wie die Seite des Plugins [Sonos4Loxone](https://wiki.loxberry.de/plugins/sonos4loxone/start): Plugin-Daten, Überblick,
Konfiguration, Referenz, Miniserver-Anbindung, FAQ, Changelog).

## Vor dem Einstellen anpassen

- `FORUM-LINK-EINTRAGEN` (zweimal): Link zum Forenbeitrag des Plugins.
- Bild `plugins:senec2lox:senec2lox.png`: Datei `docs/logo/senec2lox.png` im Medien-Manager des Wikis hochladen
  (Namensraum `plugins:senec2lox`).
- Das Feld **Status** („BETA“) und **Min. LB Version** („4.0“) prüfen. Die Version steht in `senec2lox/plugin.cfg`.
- Bei jedem Release die Felder **Version** und **Release Download** sowie die Changelog-Tabelle aktualisieren.
- Screenshots (z. B. Status, Lokal (lala.cgi), OpenAPI) lassen sich als `{{:plugins:senec2lox:name.png?600|Text}}` einfügen.

## Einstellen

Die Seite gehört in den Namensraum `plugins:senec2lox:start`. Das Anlegen und Bearbeiten im LoxBerry-Wiki setzt ein Wiki-Konto
bzw. die Freigabe durch das LoxBerry-Team voraus (Details im LoxBerry-Forum). Den Inhalt der Datei im Wiki-Editor einfügen.
