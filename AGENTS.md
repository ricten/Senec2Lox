# Agents – Senec2Lox

Kontext und Regeln: [CLAUDE.md](CLAUDE.md). Spezifikation: [SENEC-LoxBerry-Plugin-Spezifikation.md](SENEC-LoxBerry-Plugin-Spezifikation.md).

| Agent | Zweck | Dateien |
|---|---|---|
| `php-backend` | Dienst, Quellen A/B/C, Mapper, MQTT, Config/State/Logger | `senec2lox/bin/**` |
| `loxberry-packaging` | plugin.cfg, Install-/Upgrade-Skripte, daemon, cron, build.sh | `senec2lox/*.sh`, `daemon/`, `cron/`, `uninstall/`, `build.sh` |
| `web-ui` | Weboberfläche + ajax.php | `senec2lox/webfrontend/**` |
| `test-writer` | Unit-Tests und Fake-Server | `tests/**` |
| `spec-reviewer` | Abgleich Code ↔ Spezifikation / Abnahmekriterien (nur lesend) | – |

Gemeinsame Regeln: Spezifikation zuerst lesen, keine hartkodierten Pfade, keine Secrets im Log, LF-Zeilenenden, offen berichten was nicht getestet werden konnte.
