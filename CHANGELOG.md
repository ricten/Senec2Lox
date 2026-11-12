# Changelog

Alle nennenswerten Änderungen an Senec2Lox. Format angelehnt an [Keep a Changelog](https://keepachangelog.com/de/1.1.0/),
Versionierung nach [Semantic Versioning](https://semver.org/lang/de/) (`MAJOR.MINOR.PATCH`).

Release-Ablauf: Version in `senec2lox/plugin.cfg` und `senec2lox/bin/lib/AppVersion.php` erhöhen, hier einen Eintrag ergänzen,
`bash build.sh` ausführen (prüft die Übereinstimmung), danach Git-Tag `vX.Y.Z` setzen.

## [1.6.1] - 2026-11-12

### Neu
- **Status-Texte der Wallbox:** `senec/live/wallbox<N>_status_text` (je Wallbox) und `senec/live/wallbox_status_text` (erste Wallbox), z. B. „Nicht verbunden (161)“, „Lädt (194)“. Die Texte stammen wie die des Speichers aus dem ioBroker-Adapter (MIT, © NoBl), der nicht nach Wallbox-Modell unterscheidet. Sie werden deshalb **nur für die SENEC Wallbox pro S** (`WALLBOX.HW_TYPE` 20) veröffentlicht; bei anderen Typen und ohne Typ-Angabe gibt es nur die Zahl (`…_status_code`, unverändert). Wer eine andere SENEC-Wallbox hat, kann Typ und Codes in einem Issue melden.

### Geändert
- Ladeströme der Wallbox (`ladestrom_max_a`, `ladestrom_standard_a`, `ladestrom_min_a`) werden höchstens bis zum Nennstrom der Wallbox angenommen (`MAX_CHARGING_CURRENT_RATED`, bei der SENEC Wallbox pro S 16 A); darüber lautet die Quittung `abgelehnt: höher als der Nennstrom der Wallbox (16 A)`. Vorher waren 6 bis 32 A erlaubt.

## [1.6.0] - 2026-11-12

### Neu
- **Wallbox sperren und freigeben:** `senec/befehl/wallbox<N>/sperre` (1 sperrt, 0 gibt frei; `WALLBOX.PROHIBIT_USAGE`). Gesperrt lädt kein Fahrzeug; freigegeben lädt es wieder nach den Einstellungen. Der Zustand steht in `senec/live/wallbox<N>_gesperrt` (0/1). Wie die Ladeströme mit Zurücklesen und längerem Warten auf die Bestätigung. Kein „Laden starten“: Der Lademodus (Solar, Schnell, Komfort) lässt sich nur über die SENEC-Cloud schalten und ist nicht enthalten.
- Am echten Speicher geprüft: Die Sperre wird in etwa einer Sekunde übernommen. Nach dem Freigeben stand der höchste Ladestrom (`SET_ICMAX`) bei der Wallbox ohne Fahrzeug auf 0 statt vorher 16 A und musste neu gesetzt werden (`ladestrom_max_a`).
- Smart-Laden ist als „der Speicher regelt den Ladestrom dynamisch“ beschrieben.

## [1.5.0] - 2026-11-12

### Neu
- **Steuerung per MQTT (Befehle aus Loxone), ab Werk gesperrt.** Erst den Warnhinweis bestätigen (Reiter Einstellungen → Steuerung), dann die Gruppen einzeln freigeben. Befehle gehen nur über die lokale Quelle (`lala.cgi`), dieselben Anfragen wie im ioBroker-Adapter. Jeder Befehl wird quittiert (`angenommen`, dann `ausgefuehrt` oder `abgelehnt: <Grund>`), höchstens ein Befehl alle 2 Sekunden.
  - `senec/befehl/zwangsladung` (1/0): Zwangsladung der Batterie an/aus; der Zustand steht in `senec/live/zwangsladung` (Status 8 und 9).
  - `senec/befehl/steckdose<N>/<feld>`: schaltbare Steckdosen: `modus` (aus, automatik, dauer_ein), `einschaltschwelle_w`, `abschaltschwelle_w`, `ueberschuss_min`, `einschaltdauer_min`, `stunde`, `minute`. Nach dem Schreiben liest das Plugin zurück und quittiert nur, was der Speicher übernommen hat.
  - `senec/befehl/wallbox<N>/<feld>`: Wallbox: `ladestrom_max_a`, `ladestrom_standard_a`, `ladestrom_min_a` (6 bis 32 A), `smart_laden`, `zwischenladen`.
  - Für freigegebene Steckdosen und Wallbox liest das Plugin zusätzlich die Abschnitte `SOCKETS` und `WALLBOX`, damit ihre Einstellungen als Rohwerte in Loxone ankommen.
- Geprüft: Die Zwangsladung wurde an einem echten Speicher ein- und wieder ausgeschaltet (Status 8 und zurück, Zwangsladung und Ladesperre standen danach wieder auf 0). Die Steckdosen wurden am echten Speicher mit Schwellen, Zeiten und den Modi aus, automatik und dauer_ein (je etwa 10 Sekunden) geprüft, mit Zurücklesen, danach wieder im Ausgangszustand; beim Einschalten kann der Speicher kurz langsam antworten. Die Wallbox wurde am echten Speicher mit Smart-Laden, Zwischenladen, kleinstem und höchstem Ladestrom geprüft (mit Zurücklesen, danach wieder im Ausgangszustand): Den höchsten Ladestrom übernimmt der Speicher erst nach etwa 8 Sekunden, das Plugin wartet bis zu 16 Sekunden auf die Bestätigung; der Standard-Ladestrom ließ sich mit 15 A statt 16 A nicht ändern (der Speicher behielt 16 A, Quittung: abgelehnt).
- Mehrere Wallboxen: Der Speicher unterstützt bis zu vier. Zusätzlich zu `wallbox_w`, `wallbox_fahrzeug_verbunden` und `wallbox_status_code` (erste Wallbox) gibt es je eingerichteter Wallbox `wallbox1_w` … `wallbox4_w`, `wallbox<N>_fahrzeug_verbunden` und `wallbox<N>_status_code`; die Befehle `wallbox<N>/…` gelten für 1 bis 4.
- Nicht dabei: Reboot des Speichers sowie Notstromreserve, Peak-Shaving und SG-Ready (gibt es nur über mein-senec.de mit Konto-Anmeldung).
- MQTT-Client kann Nachrichten empfangen (Abo `<Präfix>/befehl/#`); Test-Attrappe des Speichers kann schreiben, Test-Broker kann Nachrichten einspielen.

## [1.4.2] - 2026-11-11

### Geändert
- Installation und Update prüfen jetzt, ob PHP 8.2 oder neuer verfügbar ist (Kommandozeile und Apache). Ist es älter, bricht `preinstall.sh` bzw. `preupgrade.sh` mit einer klaren Meldung im Installationslog ab (Hinweis auf LoxBerry 4.0 mit Debian 13 und die Upgrade-Anleitung des LoxBerry-Wikis), statt dass die Weboberfläche nach der Installation mit HTTP 500 antwortet. Bei einem Update bleibt die alte Version dabei unverändert erhalten. Test: `bash tests/test_phpcheck.sh`.
- Doku: PHP 8.2 oder neuer hat ein LoxBerry 4.0 auf Debian 13 (Trixie); auf Debian 11 und 12 ist meist PHP 7.4 aktiv.

## [1.4.1] - 2026-11-11

### Behoben
- Läuft jetzt auch mit PHP 8.2 und 8.3. Bisher war PHP 8.4 nötig: typisierte Klassenkonstanten gibt es erst ab PHP 8.3, ältere PHP-Versionen brachen mit einem Syntaxfehler ab (HTTP 500 in der Weboberfläche, Dienst startete nicht). Keine Funktionsänderung.

## [Unveröffentlicht]

## [1.4.0] – 2026-11-10

### Neu
- **ZIP ohne Version im Namen** (`<name>.zip`) an jedem Release: `…/releases/latest/download/<name>.zip` zeigt immer auf das neueste Release (`bash build.sh` erzeugt beide ZIPs).
- **Automatische Updates:** `plugin.cfg` hat jetzt `AUTOMATIC_UPDATES=true` und `RELEASECFG` (raw-URL der `release.cfg` im GitHub-Repo). LoxBerry prüft täglich diese Datei und installiert eine neuere Version. Neue Installationen stehen damit auf „automatische Release-Updates“ (in der Plugin-Verwaltung änderbar). Schon installierte Versionen bekommen `RELEASECFG` erst durch die Neuinstallation von 1.4.0. `bash build.sh release-cfg` schreibt die `release.cfg` (liegt im Repo, nicht im ZIP), der Release-Ablauf steht in `CLAUDE.md`.

### Behoben
- `plugin.cfg` hat jetzt `WEBSITE` (Repository auf GitHub): In der LoxBerry-Plugin-Verwaltung ist der Knopf „Dokumentation“ nicht mehr ausgegraut.

### Geändert
- **Weboberfläche im LoxBerry Design System** (`lb-*`-Klassen, `lbheader(…, true)`) statt jQuery Mobile: Schalter, Eingabefelder, Tabellen, Karten und aufklappbare Bereiche. Feldnamen, Speichern und Inhalte sind unverändert; Farben folgen dem gewählten LoxBerry-Theme (auch dunkel). Das Gateway-Abo-Feld nutzt die Theme-Farben.
- Tests für die neue Oberfläche (Kopf ohne jQuery Mobile, Feldnamen, kein `data-role`).
- Doku: Seitenlinks in `docs/wiki/` funktionieren im Repository (mit `.md`), Abschnitt „Automatische Updates“ in `Installation.md` und `README.md`.

## [1.3.1] – 2026-11-10

### Behoben
- **Loglevel-Auswahl in der LoxBerry-Plugin-Verwaltung fehlte** (seit 1.2.0 stand `CUSTOM_LOGLEVELS` auf `false`). Sie ist wieder da und wirkt jetzt auch: Der Dienst nutzt den Loglevel der Plugin-Verwaltung wie die anderen Plugins (Stufe 3 = nur Fehler, 4 = Warnungen, 6 = Info, 7 = Debug) und liest ihn alle 30 s neu, ein Neustart ist nicht nötig. Vorher hatte die Auswahl in 1.1.0 keine Wirkung.

### Geändert
- Die eigene Loglevel-Auswahl im Reiter „Einstellungen“ entfällt (zwei Einstellungen wären widersprüchlich). `log_level` in `senec2lox.json` gilt nur noch als Rückfall ohne LoxBerry. **Nach dem Update steht die Auswahl in der Plugin-Verwaltung evtl. auf Stufe 3 (nur Fehler): für die bisherigen Info-Meldungen auf 6 stellen.**

## [1.3.0] – 2026-11-10

### Neu
- **Status und Lebenszeichen wie in den anderen Plugins:** `senec/status/ok` (1, wenn aktuelle Live-Werte aus mindestens einer Quelle vorliegen), `senec/status/zaehler` (zählt bei jedem Abfrage-Durchgang, mindestens alle 10 s, 0…999 weiter) und `senec/status/ts` (Unix-Zeit des Durchgangs). Alle drei werden nicht retained gesendet. Bestehende Topics (`senec/online`, `senec/lala/ok` …) bleiben unverändert.
- Test des Dienstes gegen Fake-Speicher und Fake-Broker (prüft die Status-Topics; wird ohne PHP-Erweiterung curl übersprungen und gemeldet).

## [1.2.0] – 2026-11-10

Anpassung an die Vorgaben aus dem LoxBerry-Entwicklerwiki (und dem LoxBerry-Quellcode). Topics und Konfiguration sind unverändert.

### Hinzugefügt
- **Healthcheck für die LoxBerry-Healthcheck-Seite** (`bin/healthcheck`, Aufruf mit `title` und `check`): Fehler (Benachrichtigung und E-Mail), wenn der Dienst keine Statusmeldung mehr schreibt; Warnung bei MQTT-Broker weg, Quelle ohne Antwort oder zweiter Instanz; Info bei abgeschaltetem Dienst oder ohne Quelle; sonst OK mit Zahl der Quellen und Live-Quelle.
- **Prüfung des Gateway-Abos** in den Reitern „Einbindung in Loxone“ und „Test“ (`GatewayInfo`, Kopie aus `common/php`): Gateway V2 liest die Abo-Datei des Plugins nicht, das Abo (`senec/#` bzw. mit eigenem Präfix) muss dort von Hand unter „Abonnements“ eingetragen sein. Die Oberfläche sagt, ob es eingetragen ist und was fehlt.
- **Abo-Datei `config/mqtt_subscriptions.cfg`** (`senec/#`, eine Zeile je Topic) für das Gateway V1.
- Tests für Healthcheck und Weboberfläche: Jeder Reiter wird mit Attrappen der LoxBerry-Bibliotheken gerendert (`tests/stubs/`, `tests/ui_page.php`).

### Geändert
- **Gateway-Abo im Reiter MQTT als farbiges Feld:** grün, sobald mindestens ein passendes Abo im Gateway eingetragen ist (auch wenn nur ein Teil der Topics abonniert ist; dann mit Hinweis), rot nur, wenn keines eingetragen ist. Die eingetragenen Abos werden darunter aufgelistet.
- **Menüleiste:** Die Reiter nutzen die offizielle Menüleiste von LoxBerry (`$navbar`) statt eigener Reiterknöpfe. Der Server wählt den Reiter (`?tab=…`); alle Bereiche bleiben im Formular, damit Speichern nie Felder anderer Reiter verliert. Ein Reiterwechsel lädt die Seite neu, nicht gespeicherte Eingaben gehen dabei verloren.
- `CUSTOM_LOGLEVELS=false`: Der Loglevel gilt nur aus den Plugin-Einstellungen (wie in der Oberfläche beschrieben); die Loglevel-Auswahl der LoxBerry-Pluginverwaltung hatte bisher keine Wirkung und entfällt.
- Beim Gateway-Hinweis im Reiter „Einbindung in Loxone“ steht der Hinweis, dass bei Gateway V2 von Hand abonniert werden muss.

### Entfernt
- Leeres `preinstall.sh` (verzögerte nur die Installation).

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
