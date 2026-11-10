# Installation

## ZIP bauen

```bash
bash build.sh
```

Ergebnis: `senec2lox-<version>.zip` (enthält nur den Inhalt von `senec2lox/`, keine Tests und keine Doku).

**Immer das neueste Release:** Der Link `https://github.com/ricten/Senec2Lox/releases/latest/download/senec2lox.zip` lädt das ZIP des jeweils letzten Releases (ohne Version im Dateinamen). Er lässt sich in der LoxBerry-Plugin-Verwaltung beim Installieren von einer Internetadresse eintragen und muss nach einem neuen Release nicht angepasst werden.

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

### Automatische Updates

Ab Version 1.4.0 nutzt Senec2Lox die Update-Funktion der LoxBerry-Plugin-Verwaltung. In der `plugin.cfg` steht der Verweis auf die Datei `release.cfg` im GitHub-Repository (`ricten/Senec2Lox`, Zweig `main`). LoxBerry prüft sie täglich und vergleicht die dort eingetragene Version mit der installierten.

- **Neue Installationen** stehen in der Plugin-Verwaltung bei „Automatische Updates“ auf **Releases**: eine neuere Version wird selbst heruntergeladen und installiert.
- **Schon installierte Plugins** bekommen den Verweis erst, wenn einmal von Hand (ZIP oder Link) mindestens Version 1.4.0 installiert wurde. Danach lässt sich die Einstellung in der Plugin-Verwaltung ändern: *Aus*, *Benachrichtigen* oder *Releases*.
- Konfiguration und Daten bleiben wie bei jedem Update erhalten (siehe oben).
- Die `release.cfg` zeigt immer auf das ZIP der jeweils letzten Version; sie wird erst nach dem GitHub-Release angepasst, damit nie auf ein fehlendes ZIP verwiesen wird.

## Deinstallation

Plugin in der Plugin-Verwaltung entfernen. Der Dienst wird beendet, `/dev/shm/senec2lox` gelöscht.
**Wichtig:** Einen registrierten OpenAPI-Eintrag im SENEC-Portal bitte selbst entfernen (siehe [OpenAPI-Registrierung](OpenAPI-Registrierung.md)).
