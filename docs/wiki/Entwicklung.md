# Entwicklung

## Aufbau

```
senec2lox/                  Inhalt des Plugin-ZIP
├── plugin.cfg
├── postinstall.sh preupgrade.sh postupgrade.sh
├── uninstall/uninstall  daemon/daemon  cron/cron.01min
├── bin/
│   ├── senec2lox.php       Hauptdienst (Endlosschleife, Tick 1 s)
│   ├── healthcheck         LoxBerry-Healthcheck (Dienst, Broker, Quellen)
│   ├── start.sh stop.sh
│   └── lib/                 Config, State, Logger, Http, MqttClient, SenecDecode, StateTexts,
│                            LalaSource, OpenApiSource, ConnectSource, Mapper, Publisher, Catalog,
│                            GatewayInfo (Kopie aus loxberry-plugins/common/php), bootstrap
├── config/senec2lox.json   Standardkonfiguration
├── config/mqtt_subscriptions.cfg   Abo für das Gateway V1 (eine Zeile je Topic; V2 liest sie nicht)
├── webfrontend/htmlauth/    index.php, ajax.php
└── icons/
tests/                       Unit-Tests und Fake-Server (nicht im ZIP)
tools/                       Hilfsskripte (Logo, StateTexts)
docs/                        Wiki und Logo (nicht im ZIP)
```

## Dienstablauf

Jede Sekunde: Konfiguration bei geänderter mtime neu laden → MQTT verbinden (Backoff 2–60 s) → für jede aktive Quelle, deren Zeit abgelaufen ist, abfragen (eigener Takt und Backoff je Quelle) → Mapper baut Topics → Publisher sendet nur Änderungen (plus periodischer Komplett-Resend) → `status.json` nach `/dev/shm` → MQTT-Keepalive.
Nur eine Instanz (`flock`), PID-Datei, Beenden per SIGTERM/SIGINT mit `online=0`.

## Tests

```bash
php tests/run.php
```

Deckt Dekodierung, Mapper (Vorzeichen, Grid-Logik, Quellenpriorität), Config-Merge, MQTT-Längenkodierung, Request-Aufteilung und TOFU ab. Außerdem prüft der Lauf den **Healthcheck** (`bin/healthcheck`, alle Zustände) und rendert **jeden Reiter der Weboberfläche** mit Attrappen der LoxBerry-Bibliotheken (`tests/stubs/`, `tests/ui_page.php`): Das findet Laufzeitfehler und prüft Menüleiste und Gateway-Abo. Das echte Aussehen in LoxBerry (jQuery Mobile) lässt sich so nicht prüfen.

`senec2lox/bin/lib/GatewayInfo.php` ist eine Kopie aus `loxberry-plugins/common/php/GatewayInfo.php` (dort liegen die eigenen Tests: `php common/tests/run.php`). Änderungen zuerst dort machen und die Datei erneut hierher kopieren.

## Fake-Server und lokaler Lauf

```bash
php -S 127.0.0.1:8081 tests/fake_lala.php      # lala.cgi (HTTP)
php tests/fake_mqtt.php 18830                  # gibt PUBLISH-Pakete aus
```

Konfiguration `senec2lox.json` in einem Ordner anlegen (z. B. `lala.host = "127.0.0.1:8081"`, `mqtt.use_loxberry_broker = false`, `mqtt.host = "127.0.0.1"`, `mqtt.port = 18830`), dann:

```bash
SENEC2LOX_DEV_DIR=<ordner> php senec2lox/bin/senec2lox.php
```

Robustheitstest des MQTT-Clients: `php tests/slow_broker.php 18840 60` (langsamer Broker) und `php tests/mqtt_burst.php 18840 3000 2000` (Sendeschub gegen diesen Broker).

`tests/fake_openapi.php` und `tests/fake_connect.php` sprechen nur HTTP; die echten Schnittstellen verlangen HTTPS (Reverse-Proxy nötig).

## Logo und Icons

```bash
python tools/make_logo.py        # benötigt Pillow
```

Erzeugt `docs/logo/senec2lox.svg|png` (grüner LoxBerry-Stil mit SENEC-Schriftzug, wird als Plugin-Icon verwendet), `docs/logo/senec2lox_klassisch.svg|png` (klassisch, dunkelblau) und `senec2lox/icons/icon_{64,128,256,512}.png`.

## Versionierung und Release

- Versionsschema: Semantic Versioning (`MAJOR.MINOR.PATCH`).
- Die Version steht in `senec2lox/plugin.cfg` (`VERSION`) und `senec2lox/bin/lib/AppVersion.php`; `build.sh` bricht ab, wenn beide nicht übereinstimmen oder der oberste Eintrag in `CHANGELOG.md` fehlt.
- Ablauf: Änderungen im Abschnitt „Unveröffentlicht“ des `CHANGELOG.md` sammeln → Version erhöhen und Abschnitt umbenennen (`## [x.y.z] – Datum`) → `bash build.sh` → Git-Tag `vx.y.z`.

## Regeln

- Keine hartkodierten `/opt/loxberry`-Pfade (PHP: `loxberry_system.php`, Shell: `REPLACELBPBINDIR` usw.).
- LF-Zeilenenden.
- Keine Secrets loggen.
- Keine Steuerbefehle an den Speicher.
- Registrierung der OpenAPI nur auf Knopfdruck.

Ausführliche Spezifikation: `SENEC-LoxBerry-Plugin-Spezifikation.md` im Projektordner.
