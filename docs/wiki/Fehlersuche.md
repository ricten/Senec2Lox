# Fehlersuche

Zuerst: Plugin öffnen → **Status** (Dienst, MQTT, Quellen) und **Log** (letzte 50 Zeilen). Das vollständige Log liegt im LoxBerry-Log-Manager (`senec2lox.log`). Für mehr Details Loglevel auf `debug` stellen.

| Symptom | Ursache / Lösung |
|---|---|
| Dienst „gestoppt“ | Der Watchdog startet ihn jede Minute. Bleibt er aus: `php -v` (≥ 8.4, mit `curl`, `openssl`) prüfen, Log ansehen |
| MQTT „getrennt“ | MQTT-Broker auf dem LoxBerry aktiv? Bei eigenem Broker Host/Port/Zugang prüfen |
| lala: `Verbindung: …` | IP falsch, Speicher nicht erreichbar, V4/P4/E4 (kein lala.cgi → nur SENEC.Connect) |
| lala: `HTTP …` oder „kein gültiges JSON“ | Antwort abgeschnitten; Intervall nicht zu klein wählen, Sektionen der Vollabfrage reduzieren |
| Warnung „Fingerprint geändert“ | Zertifikat des Speichers wurde erneuert (z. B. Update). Wird übernommen. Bei Zweifel Netz prüfen; **Fingerprint zurücksetzen** |
| OpenAPI `unregistered` | Noch nicht registriert → [OpenAPI-Registrierung](OpenAPI-Registrierung) |
| OpenAPI `pending` | Freigabe im Portal fehlt |
| OpenAPI `rejected` | Token ungültig/widerrufen → im Portal prüfen, ggf. „Token vergessen“ |
| OpenAPI `unreachable` | Port 4424 geschlossen: V4/P4/E4 oder Firmware < 4.4.0 |
| Connect: „Subscription-Key ungültig“ | Key im Developer-Portal prüfen; Abfrage ist für 30 min gedrosselt |
| Connect: Rate-Limit (429) | Intervall erhöhen |
| `netz_w` bei OpenAPI wirkt falsch | Vorzeichen von `meter.gridPower` ist ungeklärt → lala als Hauptquelle verwenden |
| Keine Werte in Loxone | MQTT-Gateway-Abonnement `senec/live/#` prüfen, Namen der virtuellen Eingänge (`senec_live_…`) |

## MQTT direkt prüfen

```bash
mosquitto_sub -h localhost -v -t 'senec/#'
```

Auf dem LoxBerry ggf. mit `-u`/`-P` und den Zugangsdaten aus den MQTT-Einstellungen.

## Dienst von Hand starten/stoppen

```bash
/opt/loxberry/bin/plugins/senec2lox/stop.sh
/opt/loxberry/bin/plugins/senec2lox/start.sh
```

## Fehler melden

Bitte die Version (steht oben rechts im Plugin), SENEC-Systemtyp, aktive Quellen und die relevanten Logzeilen angeben. **Vorher Keys, Tokens und Passwörter prüfen** – das Plugin maskiert sie im Log, trotzdem vor dem Teilen kontrollieren.

## MQTT „getrennt“

Im Status steht hinter „getrennt“ der Grund und die Quelle der Zugangsdaten, z. B.:

| Meldung | Bedeutung / Lösung |
|---|---|
| `Broker lehnt Verbindung ab: nicht autorisiert` / `Benutzer/Passwort falsch` | Zugangsdaten stimmen nicht. Beim LoxBerry-Broker im LoxBerry unter *MQTT Gateway* bzw. *Einstellungen → MQTT* prüfen; bei eigenem Broker Benutzer/Passwort im Reiter MQTT eintragen |
| `mqtt_connectiondetails() lieferte keine Broker-Daten` | MQTT ist im LoxBerry nicht eingerichtet; dort den Broker aktivieren oder „LoxBerry-Broker verwenden“ abwählen und einen eigenen Broker eintragen |
| `TCP-Verbindung … fehlgeschlagen` | Broker läuft nicht oder falscher Host/Port |
| `Keine gültige CONNACK-Antwort` | Port gehört nicht zu MQTT (z. B. TLS-Port) |

## MQTT wird unterbrochen

Im Status steht, seit wann MQTT verbunden ist, wie viele Abbrüche es seit dem Dienststart gab und der Grund des letzten. Jeder Abbruch steht auch im Log
(`MQTT-Verbindung verloren - <Uhrzeit>: <Grund> (nach <n> s)`); der Dienst verbindet sich sofort neu und sendet alle Werte erneut.

| Grund im Log | Bedeutung |
|---|---|
| `Broker hat die Verbindung geschlossen` | Der Broker (Mosquitto) hat die Verbindung beendet, z. B. beim Neustart des Brokers oder bei Neuladen der MQTT-Gateway-Konfiguration |
| `Senden: Broker nimmt keine Daten mehr an` | Der Broker liest nicht mehr (überlastet oder hängt) |
| `Senden fehlgeschlagen (Verbindung zurückgesetzt)` | Netzwerk-/Broker-Reset |
| `Interner Fehler: …` (Level error) | Ein Fehler in einer Dienstrunde; der Dienst läuft weiter. Bitte die Zeile melden |

## MQTT wird alle paar Sekunden getrennt

Zeigt das Log immer wieder `MQTT-Verbindung verloren … Broker hat die Verbindung geschlossen (nach 1–3 s)`, läuft sehr wahrscheinlich **eine zweite Instanz** (z. B. ein zweites Plugin `senec2lox_xxx` oder das alte `senec2mqtt`). Unter der Status-Anzeige erscheint dann ein roter Hinweis „weitere Instanz“.
Lösung: In der LoxBerry-Plugin-Verwaltung nur **ein** Senec2Lox-Plugin behalten und die anderen deinstallieren.

Der Autor, der Plugin-Name und der Ordner bestimmen die Identität des Plugins. Ändern sich diese zwischen zwei Versionen, behandelt LoxBerry das Paket als **neues** Plugin (zweite Installation mit Standardeinstellungen) statt als Update.

## Keine Werte, obwohl der Dienst läuft

Bei „lala“ steht im Status `nicht konfiguriert: Keine IP / kein Hostname eingetragen`: Die ausgelieferte Konfiguration enthält keine Standard-IP; bitte die IP des Speichers unter *Lokal (lala.cgi)* eintragen und speichern.
