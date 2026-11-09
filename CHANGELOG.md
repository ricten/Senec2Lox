# Changelog

Alle nennenswerten Änderungen an Senec2Lox. Format angelehnt an [Keep a Changelog](https://keepachangelog.com/de/1.1.0/),
Versionierung nach [Semantic Versioning](https://semver.org/lang/de/) (`MAJOR.MINOR.PATCH`).

Release-Ablauf: Version in `senec2lox/plugin.cfg` und `senec2lox/bin/lib/AppVersion.php` erhöhen, hier einen Eintrag ergänzen,
`bash build.sh` ausführen (prüft die Übereinstimmung), danach Git-Tag `vX.Y.Z` setzen.

## [Unveröffentlicht]

## [1.1.0] – 2026-11-09

### Geändert
- **Menü der Weboberfläche mit Reitern** (wie bei Nut2Lox): Einstellungen · MQTT · Einbindung in Loxone · Test · Logdateien. Bisher standen alle Bereiche als ausklappbare Abschnitte untereinander. Einstellungen (Allgemein, Lokal, OpenAPI, SENEC.Connect) und MQTT liegen im selben Formular, ein Speichern gilt für beide. Der Reiter „Test“ enthält Status und Wartung, „Einbindung in Loxone“ die Livewerte mit Eingangsnamen (`/` → `_`), „Logdateien“ das Log (wird nur geladen, solange der Reiter offen ist). Der zuletzt geöffnete Reiter wird im Browser gemerkt. Funktion und Konfiguration sind unverändert.

## [1.0.4] – 2026-11-06

### Geändert
- **Mindestversion LoxBerry 4.0** (`LB_MINIMUM=4.0.0`): Das Plugin benötigt PHP 8.4 (CLI und Web). Die bisherige Angabe 3.0.0 passte dazu nicht; der Installer lehnt LoxBerry 3.x jetzt ab, statt das Plugin zu installieren und mit einem PHP-Fehler scheitern zu lassen.
- **Weniger Rechenzeit der Weboberfläche:** Die zyklischen Abfragen `status` und `log` laufen über einen Schnellpfad, der nur Dateien liest (ohne LoxBerry-Bibliothek, ohne Klassen, ohne PHP-Session). Das spart auf kleinen LoxBerrys (Pi) viel Rechenzeit; die Session-Sperre stellte parallele Aufrufe mehrerer Browser-Tabs bisher hintereinander. Die Oberfläche aktualisiert sich nur noch, solange der Browser-Tab sichtbar ist.
- Der Dienst schreibt das Vorhandensein des OpenAPI-Tokens und den lala-Fingerprint in `status.json` (Datenbasis des Schnellpfads).
- Neues Mess-Werkzeug `tests/bench_tick.php` (Rechenzeit einer Dienstrunde: ca. 0,3 ms bei 400 Topics).

## [1.0.3] – 2026-11-06

### Behoben
- **Deinstallation beendete den falschen Dienst:** `uninstall` hatte den Plugin-Ordner fest als `senec2lox` eingetragen. Wurde ein zweites Plugin (Ordner z. B. `senec2lox_000`) deinstalliert, beendete das Skript den Dienst des Plugins `senec2lox` und ließ den Dienst des deinstallierten Plugins als Prozess ohne Dateien weiterlaufen. Der Ordner kommt jetzt aus dem von LoxBerry übergebenen Argument (`$3`); das Skript wartet auf das Ende des Dienstes und beendet Prozesse aus genau diesem Plugin-Ordner als Sicherheitsnetz.

## [1.0.2] – 2026-11-06

### Behoben
- **MQTT wird im Sekundentakt getrennt („Broker hat die Verbindung geschlossen“):** Die MQTT-Client-ID war für alle Instanzen auf demselben LoxBerry gleich (`senec2lox_<Hostname>`). Läuft eine zweite Instanz, z. B. ein zweites Plugin nach geändertem Autornamen oder ein altes Plugin, wirft der Broker jeweils die ältere Verbindung hinaus und beide verbinden sich sofort neu. Die Client-ID enthält jetzt den Plugin-Ordner (`senec2lox_<Ordner>`).
- Hält eine Verbindung nur kurz, wird der Wiederverbindungsabstand schrittweise verlängert (bis 60 s) statt im Sekundentakt zu verbinden.
- **Update/Neustart:** `stop.sh` wartet jetzt, bis der Dienst wirklich beendet ist (danach hartes Beenden). Vorher startete ein direkt folgendes `start.sh` (z. B. in `postupgrade.sh` oder „Neu starten“) gegen die noch gehaltene Sperre, und der Dienst lief erst nach dem nächsten Watchdog-Lauf (bis zu 1 Minute) wieder.
- `start.sh` startet nichts, wenn der Dienst schon läuft, löst sich vom aufrufenden Prozess (`setsid`, kein Stdin) und schreibt Startfehler von PHP nach `log/…/start-error.log` statt sie zu verwerfen.
- Das erkannte lala-Protokoll (HTTPS/HTTP) wird in `state.json` gemerkt; der Dienst probiert beim Start zuerst das bekannte Protokoll (schnellerer Start), das andere dient als Rückfall.

### Hinzugefügt
- **Erkennung weiterer Instanzen:** läuft ein zweites Senec2Lox (anderer Plugin-Ordner, auch altes `senec2mqtt`), erscheint eine Warnung im Log und ein roter Hinweis im Status der Oberfläche.
- Statusanzeige „nicht konfiguriert“, wenn eine Quelle aktiv ist, aber IP/Host bzw. Subscription-Key fehlt.
- Test-Werkzeug `tests/takeover_broker.php` (Fake-Broker mit Client-ID-Verdrängung wie Mosquitto).

### Geändert
- **Keine Standard-IP mehr:** Die ausgelieferte Konfiguration enthält keine IP (`lala.host` ist leer); die IP muss in der Oberfläche eingetragen werden.
- Ein Eintrag in der Entwicklungsumgebung: `SENEC2LOX_DEV_FOLDER` und `SENEC2LOX_DEV_RUN` ermöglichen Tests mit mehreren Instanzen.

## [1.0.1] – 2026-11-06

### Behoben
- **MQTT-Abbrüche:** Der Client behandelte einen vollen Sendepuffer (`fwrite()` liefert unter PHP ≥ 7.4 dann `false`/`0`) fälschlich als Verbindungsabbruch, besonders bei großen Sendeschüben (Rohwerte, Komplett-Resend). Er wartet jetzt auf einen schreibbaren Socket und meldet einen Abbruch nur, wenn der Broker die Verbindung wirklich schließt oder nichts mehr annimmt.
- Keepalive wird häufiger (alle 20 s statt 30 s) und auch direkt nach langen Abfragen gesendet.
- Ein Fehler in einer Dienstrunde beendet den Dienst nicht mehr (wird protokolliert, der Dienst läuft weiter); fatale Fehler landen im Log. Bisher konnte ein Absturz bis zum nächsten Watchdog-Lauf eine MQTT-Unterbrechung verursachen.
- **Log:** Zeitstempel je Zeile (`addtime`), DEBUG-Zeilen mit Markierung, Loglevel nur noch aus der Plugin-Einstellung (kein zusätzliches Verschlucken durch den Loglevel der LoxBerry-Pluginverwaltung). Die Logdatei wird bei > 1 MB auf die letzten Zeilen gekürzt statt neue Log-Sitzungen anzulegen.

### Geändert
- Status zeigt, seit wann MQTT verbunden ist, sowie Anzahl und Grund der Abbrüche seit Dienststart; jeder Abbruch steht mit Ursache im Log.
- Log-Bereich der Oberfläche: aktualisiert sich alle 5 s (wenn offen), zeigt die letzten 100 Zeilen ohne LoxBerry-Kopfzeilen und scrollt mit.
- Neue Test-Werkzeuge: `tests/slow_broker.php` und `tests/mqtt_burst.php` (Robustheitstest des MQTT-Clients).

## [1.0.0] – 2026-11-06 – First Release

Erstes Release von Senec2Lox (ein LoxBerry-Plugin, das SENEC-Speicherdaten per MQTT für den Loxone Miniserver bereitstellt).

### Funktionen
- LoxBerry-Plugin (PHP ≥ 8.4) mit Dauerdienst, Autostart per Daemon und Watchdog (Cron, jede Minute).
- Drei Datenquellen: lokale `lala.cgi` (V2/V2.1/V3), lokale SENEC OpenAPI (Port 4424) und SENEC.Connect (Cloud, auch V4/P4/E4).
- Eigener MQTT-3.1.1-Client mit Last Will, Reconnect-Backoff und Zugangsdaten vom LoxBerry-Broker.
- „Sprechende“ Topics `senec/live/*` mit automatischer Quellenumschaltung, `senec/info/*`, Status-Topics und optionalen Rohwerten.
- Wallbox-Werte (`wallbox_w`, `wallbox_fahrzeug_verbunden`, `wallbox_status_code`) und frei wählbare Wallbox-Datenpunkte.
- Weboberfläche: fester Status-Kasten, Livewerte, Einstellungen für alle Quellen und MQTT, Wartung, Log.
- Auswahl der Schnell- und Vollabfrage über aufklappbare Kategorien (inkl. „alle/keine“, Zähler und Erweitert-Feld).
- OpenAPI-Registrierung nur auf Knopfdruck, Eintragen eines vorhandenen Tokens, „Token vergessen“.
- Einstellbar: Timeouts, Protokoll (auto/HTTPS/HTTP), Schnellabfrage-Schlüssel und Vollabfrage-Sektionen, MQTT-Optionen, Loglevel.
- Wartungsaktionen: Dienst neu starten, Status-Cache und Log leeren, Fingerprints zurücksetzen, Einstellungen auf Standard.
- Zertifikats-Fingerprints per TOFU mit Warnung bei Änderung; MQTT-Fehlergrund im Status und Log.
- Installations-, Upgrade- (Konfiguration und OpenAPI-Token bleiben erhalten) und Uninstall-Skripte, `build.sh` mit Versionsprüfung.
- Unit-Tests (`php tests/run.php`), Fake-Server für lala, OpenAPI, Connect und MQTT.
- Wiki-Dokumentation (`docs/wiki/`), Logos (LoxBerry-Stil mit Speicherschrank und Miniserver, klassisch) und Plugin-Icons.

### Hinweise
- Das Plugin liest nur; es sendet keine Steuerbefehle an den Speicher.
- StateTexts, Abfragelisten und Dekodierung basieren auf [ioBroker.senec](https://github.com/nobl/ioBroker.senec) (MIT, © NoBl).
- Interne Vorabversionen liefen unter dem Namen `senec2mqtt` bzw. mit höheren Nummern. Sie sollten vor der Installation deinstalliert werden;
  eine `state.json` (OpenAPI-Token) kann nach `data/plugins/senec2lox/` kopiert werden, um eine Neuregistrierung zu vermeiden.
