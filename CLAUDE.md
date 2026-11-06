# Senec2Lox – LoxBerry-Plugin

**Version: siehe `senec2lox/bin/lib/AppVersion.php` (muss mit `plugin.cfg` und [CHANGELOG.md](CHANGELOG.md) übereinstimmen; `build.sh` prüft das). Ziel-PHP: 8.4 (CLI + Web).**

Bei jeder Änderung: Eintrag in CHANGELOG.md (Abschnitt „Unveröffentlicht“), bei Release Version erhöhen (SemVer).

Projektziel: installierbares LoxBerry-Plugin (PHP 8 CLI + PHP-Weboberfläche), das SENEC-Speicherdaten
(lala.cgi, lokale OpenAPI :4424, SENEC.Connect) an den LoxBerry-MQTT-Broker veröffentlicht.

**Maßgebliche Spezifikation: [SENEC-LoxBerry-Plugin-Spezifikation.md](SENEC-LoxBerry-Plugin-Spezifikation.md).**
Bei Widerspruch gilt die Spezifikation. Stellen mit „⚠ prüfen“ nicht als gesichert behandeln.

## Layout
- `senec2lox/` = Inhalt des Plugin-ZIP (plugin.cfg, bin/, config/, webfrontend/htmlauth/, daemon/, cron/, uninstall/, *.sh)
- `tests/` = Unit-Tests (`php tests/run.php`) und Fake-Server – **nicht** im ZIP
- `tools/` = Hilfsskripte, `build.sh` baut `senec2lox-<version>.zip`

## Harte Regeln
- Keine hartkodierten `/opt/loxberry`-Pfade. PHP: `loxberry_system.php` (`LBPCONFIGDIR` …); Shell/daemon/cron: Platzhalter `REPLACELBPBINDIR` usw.
- Dateien mit LF-Zeilenenden (Shell-Skripte!). `.gitattributes`/`.editorconfig` beachten.
- Keine Secrets (Subscription-Key, OpenAPI-Token, MQTT-Passwort) loggen oder im Klartext in der Oberfläche anzeigen.
- OpenAPI `POST /v1/register` **nur** auf Knopfdruck in der Weboberfläche, nie in der Poll-Schleife; nie automatisch neu registrieren.
- Nur lesende Zugriffe auf SENEC; keine Steuerbefehle (Zwangsladung ist „Später“).
- `lala.cgi`-Request-Body < 11 500 Byte. `STATISTIC` nie abfragen.
- Konfig atomar schreiben (Temp + rename). Token/Fingerprints nur in `state.json` (data-Verzeichnis).
- StateTexts basiert auf ioBroker.senec (MIT, © NoBl) – Copyright-Hinweis behalten.
- **Plugin-Identität nie ändern:** LoxBerry bildet sie aus `AUTHOR.NAME` + `AUTHOR.EMAIL` + `PLUGIN.NAME` + `PLUGIN.FOLDER`. Eine Änderung macht das Paket zum *neuen* Plugin (zweite Installation statt Update).
- MQTT-Client-ID enthält den Plugin-Ordner (`senec2lox_<Ordner>`); sonst verdrängen sich mehrere Instanzen am Broker.
- Kein MQTT-Paket-Dependency: eigener Client (`MqttClient.php`).
- Weboberfläche: `LBWeb::lbheader()` überschreibt die globale Variable `$cfg` (stdClass). Eigene Konfiguration deshalb nie `$cfg` nennen (in index.php, ajax.php und senec2lox.php: `$s2mcfg`). Auch `mqtt_connectiondetails()` liest und überschreibt das globale `$cfg`.

## Entwicklung
- PHP ist lokal evtl. nicht installiert; dann Tests nicht ausführbar – das offen sagen, nichts als „getestet“ melden.
- Tests: `php tests/run.php`. Fake-Server: `php -S 127.0.0.1:8080 tests/fake_lala.php` usw.
- Build: `bash build.sh`.

## Agents
Siehe [AGENTS.md](AGENTS.md) und `.claude/agents/`.
