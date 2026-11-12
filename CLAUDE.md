# Senec2Lox – LoxBerry-Plugin

**Version: siehe `senec2lox/bin/lib/AppVersion.php` (muss mit `plugin.cfg` und [CHANGELOG.md](CHANGELOG.md) übereinstimmen; `build.sh` prüft das). Läuft mit PHP 7.4, 8.2, 8.3 und 8.4 (CLI + Web).**

Bei jeder Änderung: Eintrag in CHANGELOG.md (Abschnitt „Unveröffentlicht“), bei Release Version erhöhen (SemVer).

Projektziel: installierbares LoxBerry-Plugin (PHP-CLI + PHP-Weboberfläche, PHP 7.4 bis 8.4), das SENEC-Speicherdaten
(lala.cgi, lokale OpenAPI :4424, SENEC.Connect) an den LoxBerry-MQTT-Broker veröffentlicht.

**Maßgebliche Spezifikation: [SENEC-LoxBerry-Plugin-Spezifikation.md](SENEC-LoxBerry-Plugin-Spezifikation.md).**
Bei Widerspruch gilt die Spezifikation. Stellen mit „⚠ prüfen“ nicht als gesichert behandeln.

## Layout
- `senec2lox/` = Inhalt des Plugin-ZIP (plugin.cfg, bin/, config/, webfrontend/htmlauth/, daemon/, cron/, uninstall/, *.sh)
- `tests/` = Unit-Tests (`php tests/run.php`) und Fake-Server – **nicht** im ZIP
- `tools/` = Hilfsskripte, `build.sh` baut `senec2lox-<version>.zip`

## Harte Regeln
- **Keine PHP-8-Syntax** (läuft ab PHP 7.4): kein `match`, keine Konstruktor-Promotion, kein `readonly`, keine Union-Typen, kein `mixed`, kein `catch` ohne Variable, kein `?->`; Rückfälle für `str_contains` & Co. stehen in `Compat.php`. Vor Releases `php -l` und `php tests/run.php` mit PHP 7.4, 8.2, 8.3 und 8.4.
- Keine hartkodierten `/opt/loxberry`-Pfade. PHP: `loxberry_system.php` (`LBPCONFIGDIR` …); Shell/daemon/cron: Platzhalter `REPLACELBPBINDIR` usw.
- Dateien mit LF-Zeilenenden (Shell-Skripte!). `.gitattributes`/`.editorconfig` beachten.
- Keine Secrets (Subscription-Key, OpenAPI-Token, MQTT-Passwort) loggen oder im Klartext in der Oberfläche anzeigen.
- OpenAPI `POST /v1/register` **nur** auf Knopfdruck in der Weboberfläche, nie in der Poll-Schleife; nie automatisch neu registrieren.
- Schreibzugriffe auf SENEC nur als Steuerbefehle aus MQTT (`Control.php`): ab Werk gesperrt, erst nach bestätigtem Warnhinweis und je Gruppe freigegeben (Zwangsladung, Steckdosen, Wallbox), nur lokal über `lala.cgi`, mit Quittung, höchstens ein Befehl alle 2 Sekunden, Werte streng geprüft. Nie Reboot, nie Konfiguration der Anlage. Notstromreserve, Peak-Shaving und SG-Ready laufen nur über mein-senec.de (Konto-Anmeldung) und sind nicht eingebaut. Echte Hardware nur mit ausdrücklicher Freigabe von Rico je Funktion ausprobieren. Stand 2026-11-12: am echten Speicher geprüft sind Zwangsladung (an, aus, Zustand folgt, Flags danach 0) und Steckdosen (Schwellen, Zeiten, Modus aus, automatik und dauer_ein für 10 s, Zurücklesen, wiederhergestellt); die Wallbox (Smart-Laden, Zwischenladen, Ladestrom min und max; der höchste Ladestrom braucht etwa 8 s bis zur Übernahme, der Standard-Ladestrom ließ sich mit 15 A nicht setzen, Quittung dann abgelehnt).
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
- `plugin.cfg`: `CUSTOM_LOGLEVELS=true` schaltet die Loglevel-Auswahl der Plugin-Verwaltung frei. Senec2Lox nutzt wie alle Plugins den Loglevel der Plugin-Verwaltung (`LBSystem::pluginloglevel()`, im Betrieb alle 30 s neu gelesen), deshalb `true`; die Einstellung `log_level` in der Konfiguration ist nur noch Rückfall ohne LoxBerry. Version dreiteilig ohne Buchstaben, `TITLE` höchstens 25 Zeichen.
- **Gateway-Abo:** `config/mqtt_subscriptions.cfg` ist **zeilenweise** (ein Topic je Zeile), kein INI. Nur das Gateway V1 liest sie; das **Gateway V2 liest sie nicht**, das Abo muss dort von Hand unter „Abonnements“ stehen. Die Oberfläche prüft das (`GatewayInfo::subscription`, Kopie aus `../loxberry-plugins/common/php/GatewayInfo.php`; Änderungen zuerst dort). Nie behaupten, das Plugin trage das Abo selbst ein.
- Ungenutzte Installationsskripte (`preinstall.sh` usw.) nicht mitliefern.
- Healthcheck: `bin/healthcheck` (Argumente `title` und `check`, Ausgabe nur als JSON mit `desc`, `status`, `result`, optional `logfile`; 3 Fehler = Benachrichtigung und E-Mail, 4 Warnung, 5 OK, 6 Info; schnell, nur lesend). Ein erwarteter Zustand (z. B. Dienst bewusst abgeschaltet) ist nie Fehler oder Warnung.
- Weboberfläche: Menüleiste über `$navbar` (vor `LBWeb::lbheader()` definieren), nicht über eigene Reiterknöpfe. Tests rendern jeden Reiter mit Attrappen der LoxBerry-Bibliotheken (`tests/stubs`, `tests/ui_page.php`).
- Keine festen `/opt/loxberry`-Pfade (auch nicht in Kommentaren).

## Agents
Siehe [AGENTS.md](AGENTS.md) und `.claude/agents/`.

## Release-Ablauf und automatische Updates
`plugin.cfg` hat `AUTOMATIC_UPDATES=true` und `RELEASECFG=https://raw.githubusercontent.com/ricten/Senec2Lox/main/release.cfg`. LoxBerry prüft täglich diese Datei (`VERSION`, `ARCHIVEURL`, `INFOURL`; Format wie im LoxBerry-Beispielplugin) und installiert die Version, wenn sie neuer ist als die installierte. Neue Installationen sind damit auf „automatische Release-Updates“ gestellt; schon installierte Versionen bekommen `RELEASECFG` erst durch die Neuinstallation von 1.4.0.
1. Version in `plugin.cfg` und `AppVersion.php` erhöhen, Eintrag in `CHANGELOG.md`, Tests, `bash build.sh`.
2. Committen (**ohne** `release.cfg`), pushen, Tag `vX.Y.Z` setzen, `gh release create vX.Y.Z senec2lox-X.Y.Z.zip senec2lox.zip …` (beide ZIPs anhängen: `bash build.sh` erzeugt neben dem ZIP mit Version ein gleiches **ZIP ohne Version**, damit der Link `https://github.com/ricten/Senec2Lox/releases/latest/download/senec2lox.zip` immer das neueste Release liefert).
3. Danach `bash build.sh release-cfg` (schreibt `release.cfg` für die Version aus `plugin.cfg`), `release.cfg` committen und pushen. Erst jetzt sehen Installationen das Update. `release.cfg` darf nie auf eine Version zeigen, deren ZIP auf GitHub noch nicht existiert (sonst scheitert der Download bei allen). `release.cfg` liegt im Repo-Wurzelordner und nicht im ZIP.
- **`release.cfg` nur nach Rücksprache mit Rico anlegen oder ändern** (auch `bash build.sh release-cfg` nicht ohne Freigabe): Sie löst bei allen Installationen mit aktivierten Auto-Updates sofort ein Update aus.
- Der Plugin-Name in `plugin.cfg` muss gleich bleiben; `ARCHIVEURL` ist der direkte ZIP-Download (Release-Anhang), kein Seitenlink.
- Weboberfläche: LoxBerry Design System (`lbheader(…, true)`, `lb-*`-Klassen, Farben nur über `var(--lb-…)`-Tokens mit Rückfallwert), kein jQuery Mobile.
