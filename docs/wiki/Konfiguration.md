# Konfiguration

Alle Einstellungen werden in der Weboberfläche vorgenommen und in `senec2lox.json` gespeichert (atomar geschrieben).
Der Dienst erkennt Änderungen selbst (mtime-Prüfung) – ein Neustart ist nicht nötig. Änderungen am MQTT-Broker führen zu einem automatischen Reconnect.

## Allgemein

| Einstellung | Standard | Beschreibung |
|---|---|---|
| Dienst aktiviert | an | Aus = Dienst läuft weiter, fragt aber nichts ab und verbindet nicht |
| Loglevel | `info` | `error`, `warn`, `info`, `debug`. Wird nur hier eingestellt; die Einstellung der LoxBerry-Pluginverwaltung wird nicht verwendet |

## Lokal (lala.cgi)

| Einstellung | Standard | Beschreibung |
|---|---|---|
| Quelle aktiv | an | |
| IP / Hostname | – | Adresse des Speichers (optional `:port`) |
| Intervall Livewerte | 10 s (min. 3) | Zu häufige Abfragen können den Datentransfer des Speichers zu SENEC stören |
| Intervall Vollabfrage | 60 s | Ganze Sektionen werden abgefragt |
| Schnellabfrage | Standardliste | Aufklappbare Auswahl nach Kategorien (Energie & Live-Leistung, PV-Generator, Netzmessung, Phasenleistung, Batteriemanagement, Wallbox); „Erweitert“ nimmt weitere Datenpunkte als `SEKTION=KEY1,KEY2` |
| Vollabfrage | `ENERGY,PV1,PM1OBJ1,TEMPMEASURE,BMS,FACTORY,SYS_UPDATE,WIZARD` | Aufklappbare Auswahl ganzer Sektionen nach Kategorien (Energie & Leistung, Batterie, Temperatur & Lüfter, Gerät & System, Ein-/Ausgänge, Diagnose); `STATISTIC` wird nie abgefragt |
| Timeout | 5 s | |
| Protokoll | automatisch | `auto` (HTTPS, sonst HTTP), `https` oder `http` |

Buttons: **Verbindung testen** (zeigt `FACTORY`-Daten) und **Fingerprint zurücksetzen**.

## Lokale OpenAPI

| Einstellung | Standard | Beschreibung |
|---|---|---|
| Quelle aktiv | aus | |
| Host | leer | Leer = gleiche IP wie lala.cgi (Port 4424 wird automatisch verwendet) |
| Intervall | 60 s (min. 10) | |
| Sektionen | `battery,meter` | Auswahl aus `battery`, `meter`, `bess`, `bessNameplate`, `evse` |
| Gerätename | `LoxBerry` | Name des Eintrags im SENEC-Portal |

Siehe [OpenAPI-Registrierung](OpenAPI-Registrierung). Ein bereits vorhandener Token kann unter „Vorhandenen Token eintragen“ übernommen werden (nur wenn noch keiner gespeichert ist).

## SENEC.Connect

| Einstellung | Standard | Beschreibung |
|---|---|---|
| Quelle aktiv | aus | |
| Subscription-Key | – | Wird maskiert angezeigt; leeres Feld beim Speichern = alten Key behalten |
| Anlagen-Filter | leer | Seriennummer oder System-ID; leer = alle Anlagen |
| Intervall | 300 s | Rate-Limits sind nicht dokumentiert |
| Sektionen | `battery,meter,evse,bessNameplate` | `bessNameplate` wird immer mitgesendet |

Den Key erhalten Sie auf <https://developer.senec.com> (anmelden → Produkt abonnieren → Profil → „Meine Abonnements“).
**Verbindung testen** listet die gefundenen Anlagen mit Seriennummer und System-ID.

## MQTT

| Einstellung | Standard | Beschreibung |
|---|---|---|
| LoxBerry-Broker verwenden | an | Zugangsdaten werden bei jedem Reconnect vom LoxBerry geholt |
| Host / Port / Benutzer / Passwort | – / 1883 | Nur bei eigenem Broker |
| Topic-Präfix | `senec` | Ohne `#`, `+`, Leerzeichen |
| retain | an | |
| Nur Änderungen senden | an | |
| Komplett-Resend | 5 min | Alle Werte werden regelmäßig erneut gesendet |
| Rohwerte veröffentlichen | an | `raw/…`, `openapi/…`, `connect/…` |
| Reihenfolge Live-Quellen | `lala,openapi,connect` | Erste aktuelle Quelle speist `senec/live/*` |

## Beispiel `senec2lox.json`

```json
{
  "enabled": true,
  "lala": { "use": true, "host": "", "interval": 10, "interval_full": 60,
            "full_sections": "ENERGY,PV1,PM1OBJ1,TEMPMEASURE,BMS,FACTORY,SYS_UPDATE,WIZARD", "timeout": 5 },
  "openapi": { "use": false, "host": "", "interval": 60, "timeout": 10, "include": "battery,meter", "device_name": "LoxBerry" },
  "connect": { "use": false, "subscription_key": "", "system_filter": "", "interval": 300, "timeout": 30,
               "include": "battery,meter,evse,bessNameplate" },
  "live_source_priority": "lala,openapi,connect",
  "mqtt": { "use_loxberry_broker": true, "host": "", "port": 1883, "user": "", "pass": "",
            "topic_prefix": "senec", "retain": true, "only_changes": true, "full_resend_minutes": 5, "publish_raw": true },
  "log_level": "info"
}
```

## Sicherheit

- Subscription-Key, Token und MQTT-Passwort werden nie geloggt und in der Oberfläche nicht im Klartext angezeigt.
- Zertifikate der lokalen Schnittstellen sind selbstsigniert. Das Plugin merkt sich den SHA-256-Fingerprint beim ersten Kontakt (TOFU), warnt bei Änderung, übernimmt ihn aber und arbeitet weiter.

## Wartung (Reiter „Wartung“)

| Aktion | Wirkung |
|---|---|
| Dienst neu starten | Stoppt und startet den Dienst |
| Status-Cache leeren | Löscht `/dev/shm/senec2lox/status.json` (wird neu aufgebaut) |
| Log leeren | Leert die Logdatei |
| Fingerprints zurücksetzen | Vergisst die gemerkten Zertifikats-Fingerprints (lala.cgi und OpenAPI) |
| Einstellungen auf Standard | Setzt die gesamte Konfiguration zurück (inkl. IP und Subscription-Key); der OpenAPI-Token bleibt erhalten |

Den OpenAPI-Token löscht nur „Token vergessen“ im Reiter *Lokale OpenAPI*.

## Wallbox-Werte abfragen

Im Reiter *Lokal (lala.cgi)* unter **Schnellabfrage → Wallbox** die gewünschten Datenpunkte anhaken (z. B. *Wallbox-Status*, *Fahrzeug verbunden*, *Ladeleistung*). Dann erscheinen `senec/live/wallbox_w`, `wallbox_fahrzeug_verbunden` und `wallbox_status_code` sowie alle gewählten Werte als Rohwerte unter `senec/raw/WALLBOX/<KEY>/<0-3>` (ein Eintrag je Wallbox). Welche Datenpunkte Ihr Gerät wirklich liefert, zeigt die **Vollabfrage → Sektion WALLBOX** (Rohwerte).
