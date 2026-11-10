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
- Installationsskripte (auch `uninstall`) nie mit festem Ordnernamen: der Plugin-Ordner kommt aus `$3` (kann `senec2lox_000` heißen); sonst trifft man den Dienst eines anderen Plugins.
- Kein MQTT-Paket-Dependency: eigener Client (`MqttClient.php`).
- Status und Lebenszeichen wie in allen Plugins (`../loxberry-plugins/README.md`): `<Präfix>/status/ok`, `status/zaehler` (0…999 je Durchgang, mindestens alle 10 s), `status/ts`, nie retained. Bestehende Topics (`online`, `lala/ok` …) nie umbenennen (Loxone-Eingänge hängen daran), sonst MAJOR-Version.
- Weboberfläche: `LBWeb::lbheader()` überschreibt die globale Variable `$cfg` (stdClass). Eigene Konfiguration deshalb nie `$cfg` nennen (in index.php, ajax.php und senec2lox.php: `$s2mcfg`). Auch `mqtt_connectiondetails()` liest und überschreibt das globale `$cfg`.

## Entwicklung
- PHP ist lokal evtl. nicht installiert; dann Tests nicht ausführbar – das offen sagen, nichts als „getestet“ melden.
- Tests: `php tests/run.php`. Fake-Server: `php -S 127.0.0.1:8080 tests/fake_lala.php` usw.
- Build: `bash build.sh`.

## Vorgaben aus dem LoxBerry-Entwicklerwiki (Stand 2026-11-10)
- `plugin.cfg`: `CUSTOM_LOGLEVELS=true` schaltet die Loglevel-Auswahl der Plugin-Verwaltung frei. Senec2Lox hat einen eigenen Loglevel (Einstellung `log_level`), deshalb `false`. Version dreiteilig ohne Buchstaben, `TITLE` höchstens 25 Zeichen.
- **Gateway-Abo:** `config/mqtt_subscriptions.cfg` ist **zeilenweise** (ein Topic je Zeile), kein INI. Nur das Gateway V1 liest sie; das **Gateway V2 liest sie nicht**, das Abo muss dort von Hand unter „Abonnements“ stehen. Die Oberfläche prüft das (`GatewayInfo::subscription`, Kopie aus `../loxberry-plugins/common/php/GatewayInfo.php`; Änderungen zuerst dort). Nie behaupten, das Plugin trage das Abo selbst ein.
- Ungenutzte Installationsskripte (`preinstall.sh` usw.) nicht mitliefern.
- Healthcheck: `bin/healthcheck` (Argumente `title` und `check`, Ausgabe nur als JSON mit `desc`, `status`, `result`, optional `logfile`; 3 Fehler = Benachrichtigung und E-Mail, 4 Warnung, 5 OK, 6 Info; schnell, nur lesend). Ein erwarteter Zustand (z. B. Dienst bewusst abgeschaltet) ist nie Fehler oder Warnung.
- Weboberfläche: Menüleiste über `$navbar` (vor `LBWeb::lbheader()` definieren), nicht über eigene Reiterknöpfe. Tests rendern jeden Reiter mit Attrappen der LoxBerry-Bibliotheken (`tests/stubs`, `tests/ui_page.php`).
- Keine festen `/opt/loxberry`-Pfade (auch nicht in Kommentaren).

## Agents
Siehe [AGENTS.md](AGENTS.md) und `.claude/agents/`.
