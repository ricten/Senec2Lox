# Installation

## ZIP bauen

```bash
bash build.sh
```

Ergebnis: `senec2lox-<version>.zip` (enthält nur den Inhalt von `senec2lox/`, keine Tests und keine Doku).

## Auf dem LoxBerry installieren

1. LoxBerry-Oberfläche → **Plugin-Verwaltung** → **Plugin installieren**.
2. ZIP hochladen und installieren. Der Dienst wird nach der Installation automatisch gestartet.
3. Plugin **Senec2Lox** öffnen.
4. Unter **Lokal (lala.cgi)** die IP des Speichers eintragen und **Speichern**.
5. Innerhalb von etwa 30 s erscheinen Werte unter `senec/live/*` (im LoxBerry-MQTT-Finder prüfbar).


## Was die Installation einrichtet

| Teil | Ort (von LoxBerry gesetzt) |
|---|---|
| Dienst und Bibliotheken | `bin/plugins/senec2lox/` |
| Konfiguration | `config/plugins/senec2lox/senec2lox.json` |
| Laufzeitdaten (OpenAPI-Token, Fingerprints) | `data/plugins/senec2lox/state.json` |
| Logdatei | `log/plugins/senec2lox/senec2lox.log` |
| Live-Status (RAM) | `/dev/shm/senec2lox/status.json` |
| Autostart | Daemon beim Boot (root → startet als `loxberry`) |
| Watchdog | Cronjob jede Minute startet den Dienst, falls er nicht läuft |

## Update

Beim Update sichern die Skripte `preupgrade.sh`/`postupgrade.sh` **Konfiguration und Daten** (OpenAPI-Token!) und spielen sie zurück.
Neue Konfigurationsschlüssel ergänzt der Dienst beim Laden automatisch mit Standardwerten.

## Deinstallation

Plugin in der Plugin-Verwaltung entfernen. Der Dienst wird beendet, `/dev/shm/senec2lox` gelöscht.
**Wichtig:** Einen registrierten OpenAPI-Eintrag im SENEC-Portal bitte selbst entfernen (siehe [OpenAPI-Registrierung](OpenAPI-Registrierung.md)).
