# LoxBerry-Plugin „SENEC2MQTT" – Umsetzungsspezifikation

> Arbeitsgrundlage für die Umsetzung in VS Code (auch direkt als Prompt/Kontext für Copilot oder Claude Code nutzbar).
> Stand: 06.11.2026. Abgeglichen mit dem Quellcode von **ioBroker.senec v2.16.5** (github.com/nobl/ioBroker.senec, Commit 6355b2a vom 30.10.2026, MIT-Lizenz).
> Stellen mit **⚠ prüfen** sind nicht abschließend verifiziert und müssen am echten Gerät bestätigt werden.

---

## 1. Ziel

Ein installierbares LoxBerry-Plugin (ZIP), das Daten eines SENEC-Stromspeichers abfragt und an den **internen MQTT-Broker des LoxBerry** (Mosquitto, ab LoxBerry 3.x im Core) veröffentlicht. Von dort gehen die Werte über das LoxBerry-MQTT-Gateway an den Loxone Miniserver.

Drei Datenquellen, genau wie im ioBroker-Adapter – einzeln oder kombiniert nutzbar:

| # | Quelle | Adresse | Auth | Systeme | Takt (ioBroker-Default) |
|---|---|---|---|---|---|
| A | **Lokal `lala.cgi`** | `https://<IP>/lala.cgi` (Port 443) | keine | V2, V2.1, V3 (alle mit lokaler Weboberfläche) | 10 s Live-Werte, 60 s Vollabfrage |
| B | **Lokale SENEC OpenAPI** | `https://<IP>:4424/v1/...` | Header `X-Auth-Token` (Registrierung + Freigabe im Portal) | V3 (gemessen), V2/V2.1 ab Firmware 4.4.0 (erwartet) | 60 s (min. 10 s) |
| C | **SENEC.Connect (offizielle Cloud-API)** | `https://apim-eds-gwc-prod.azure-api.net/senec-connect/v1/...` | Header `Ocp-Apim-Subscription-Key` | alle im SENEC-Konto, **einziger Weg für V4 / P4 / E4** | 300 s |

**Wichtig aus dem ioBroker-Repo (docs/LOCAL_OPENAPI_4424.md):**
- V4/P4/E4 haben **weder** `lala.cgi` **noch** Port 4424 → dort geht nur Quelle C.
- Quelle B liefert inhaltlich nichts, was A nicht auch liefert – sie ist aber offiziell vorgesehen (Schnittstelle für Heizstäbe etc.) und bringt zusätzlich `powerSurplus` (PV-Überschuss).
- **Alle drei Quellen sind für Endkunden nur lesend.** Schreib-/Steuer-Routen der OpenAPI (`/v1/bess/setpoint`, `/v1/battery/limits`) antworten auch mit freigegebenem Token mit 403. Steuern (z. B. Zwangsladung) geht nur über `lala.cgi` → bewusst **nicht** im Plugin (siehe 14 „Später“).

Empfohlene Konfiguration je System:

| System | Empfehlung |
|---|---|
| V3 / V2 | A (Hauptquelle), optional B als zweite Meinung/Fallback, optional C |
| V4 / P4 / E4 | nur C |

---

## 2. Technische Grundsatzentscheidungen

- **Sprache: PHP 8 (CLI)** – auf jedem LoxBerry vorhanden, `php-curl` ist dabei. Weboberfläche ebenfalls PHP (LoxBerry-Standard). Keine Paketinstallation nötig.
- **Ein dauerhaft laufender Dienst** (`bin/senec2mqtt.php`) mit Endlosschleife – Cron kann auf LoxBerry nur minütlich.
- **Jede Quelle hat ihren eigenen Takt und eigenen Fehlerzähler/Backoff** (Scheduler in der Hauptschleife).
- **MQTT:** eigener schlanker MQTT-3.1.1-Client mit dauerhafter Verbindung (siehe 9.2).
- **Konfigänderungen ohne Neustart:** `mtime` der Konfigdatei in jeder Runde prüfen.
- **Nur eine Instanz:** `flock()` auf Lockdatei.
- **Watchdog:** Cronjob jede Minute startet den Dienst, falls er nicht läuft.
- **SD-Karte schonen:** Status/Livewerte nach `/dev/shm/<plugin>/status.json`. Persistente Laufzeitdaten (OpenAPI-Token, Zertifikats-Fingerprints) einmalig nach `/opt/loxberry/data/plugins/<folder>/state.json`.

---

## 3. Verzeichnisstruktur des Plugin-ZIP

```
senec2mqtt/
├── plugin.cfg
├── preinstall.sh  postinstall.sh  preupgrade.sh  postupgrade.sh
├── uninstall/uninstall
├── daemon/daemon
├── cron/cron.01min
├── bin/
│   ├── senec2mqtt.php          # Hauptdienst
│   ├── lib/
│   │   ├── Config.php           # Laden, Defaults mergen, validieren
│   │   ├── State.php            # Token, Fingerprints (data-Verzeichnis)
│   │   ├── Logger.php
│   │   ├── MqttClient.php
│   │   ├── SenecDecode.php      # lala.cgi-Wertdekodierung
│   │   ├── LalaSource.php       # Quelle A
│   │   ├── OpenApiSource.php    # Quelle B (inkl. Registrierung)
│   │   ├── ConnectSource.php    # Quelle C
│   │   ├── Mapper.php           # Rohwerte -> „sprechende“ Topics, Quellenauswahl
│   │   └── StateTexts.php       # STAT_STATE -> Klartext (aus ioBroker state_trans.js)
│   ├── start.sh  stop.sh
├── config/senec2mqtt.json      # Default-Konfiguration
├── webfrontend/htmlauth/
│   ├── index.php               # Konfiguration + Status
│   └── ajax.php                # Status/Log/Start/Stop/Tests/OpenAPI-Registrierung
├── icons/icon_64.png icon_128.png icon_256.png icon_512.png
└── tests/ (nicht im ZIP)
```

LoxBerry kopiert bei der Installation:
- `bin/` → `/opt/loxberry/bin/plugins/<folder>/`
- `config/` → `/opt/loxberry/config/plugins/<folder>/`
- `webfrontend/htmlauth/` → `/opt/loxberry/webfrontend/htmlauth/plugins/<folder>/`
- `daemon/daemon` → `/opt/loxberry/system/daemons/plugins/<name>` (Boot, **als root**)
- `cron/cron.01min` → `/opt/loxberry/system/cron/cron.01min/<name>` (als `loxberry`)
- Logs nach `/opt/loxberry/log/plugins/<folder>/`, Daten nach `/opt/loxberry/data/plugins/<folder>/`

**Pfade nicht hart kodieren.** Der Installer durchsucht alle Textdateien nach `/opt/loxberry` und gibt sonst die Warnung „HARDCODED PATHS“ aus.
- **PHP (Web und CLI):** `require_once "loxberry_system.php";` – der Include-Pfad auf `libs/phplib` ist über LoxBerrys `loxberry-cli.ini`/`loxberry-apache.ini` gesetzt. Die Bibliothek ermittelt aus dem Skriptpfad den Plugin-Ordner und definiert `LBHOMEDIR`, `LBPPLUGINDIR`, `LBPCONFIGDIR`, `LBPDATADIR`, `LBPLOGDIR`, `LBPBINDIR`, `LBPHTMLAUTHDIR` (und die Variablen `$lbpconfigdir` usw.). Das funktioniert auch für den Dienst unter `bin/plugins/<folder>/`.
- **Shell-Skripte, daemon, cron:** Der Installer ersetzt in allen Textdateien die Platzhalter `REPLACELBHOMEDIR`, `REPLACELBPPLUGINDIR`, `REPLACELBPBINDIR`, `REPLACELBPCONFIGDIR`, `REPLACELBPDATADIR`, `REPLACELBPLOGDIR`, `REPLACELBPHTMLAUTHDIR`, `REPLACELBPHTMLDIR`, `REPLACELBPTEMPLATEDIR` (per `sed`, vor dem Kopieren). Der Installer läuft außerdem `dos2unix` über die Textdateien.
- Plugin-`NAME`/`FOLDER` dürfen nur `A-Za-z0-9_-` enthalten (Installer filtert), `TITLE` max. 25 Zeichen.

Rechte, die der Installer setzt: `bin/` → 755, Besitzer loxberry; Cron-Dateien 755 loxberry; `daemon` 755 root; `uninstall` 755 root; in `htmlauth` nur `*.cgi|*.pl|*.sh` ausführbar.

---

## 4. `plugin.cfg`

```ini
[AUTHOR]
NAME=Rico
EMAIL=rico@cod-lan.de

[PLUGIN]
VERSION=0.1.0
NAME=senec2mqtt
FOLDER=senec2mqtt
TITLE=SENEC2MQTT

[AUTOUPDATE]
AUTOMATIC_UPDATES=false
RELEASECFG=
PRERELEASECFG=

[SYSTEM]
REBOOT=false
LB_MINIMUM=3.0.0
LB_MAXIMUM=false
ARCHITECTURE=false
CUSTOM_LOGLEVELS=true
INTERFACE=2.0
```

Abgeglichen mit `sbin/plugininstall.pl` des LoxBerry-Kerns (github.com/mschlenstedt/Loxberry):
- Pflicht: `AUTHOR.NAME`, `AUTHOR.EMAIL`, `PLUGIN.VERSION`, `PLUGIN.NAME`, `PLUGIN.TITLE`, `PLUGIN.FOLDER`, `SYSTEM.INTERFACE` – sonst Abbruch.
- `INTERFACE` muss `2.0` sein (`1.0` wird abgelehnt).
- Abschnitt heißt `[AUTOUPDATE]`; ohne `RELEASECFG` sind Auto-Updates aus.
- `CUSTOM_LOGLEVELS=true` → Loglevel ist in LoxBerrys Plugin-Verwaltung einstellbar (siehe 10, `LBSystem::pluginloglevel()`).
- `ARCHITECTURE=false` = keine Einschränkung (sonst Liste wie `aarch64,x86_64`).

Für Auto-Updates später: `RELEASECFG=https://raw.githubusercontent.com/<user>/<repo>/main/release.cfg` mit Version + ZIP-URL.

---

## 5. Konfiguration `config/senec2mqtt.json`

```json
{
  "enabled": true,

  "lala": {
    "use": true,
    "host": "192.168.1.50",
    "interval": 10,
    "interval_full": 60,
    "full_sections": "ENERGY,PV1,PM1OBJ1,TEMPMEASURE,BMS,FACTORY,SYS_UPDATE,WIZARD",
    "timeout": 5
  },

  "openapi": {
    "use": false,
    "host": "",
    "interval": 60,
    "timeout": 10,
    "include": "battery,meter",
    "device_name": "LoxBerry"
  },

  "connect": {
    "use": false,
    "subscription_key": "",
    "system_filter": "",
    "interval": 300,
    "timeout": 30,
    "include": "battery,meter,evse,bessNameplate"
  },

  "live_source_priority": "lala,openapi,connect",

  "mqtt": {
    "use_loxberry_broker": true,
    "host": "", "port": 1883, "user": "", "pass": "",
    "topic_prefix": "senec",
    "retain": true,
    "only_changes": true,
    "full_resend_minutes": 5,
    "publish_raw": true
  },

  "log_level": "info"
}
```

Erläuterungen:
- `lala.interval` min. 3 s. ioBroker-Hinweis: zu häufiges Abfragen kann den Datentransfer des Speichers zu den SENEC-Servern stören → Default 10 s.
- `lala.full_sections`: Sektionen, die im langsamen Takt **komplett** abgefragt werden (siehe 6.1). ioBroker fragt alle bekannten Sektionen ab; für Loxone reicht eine Auswahl.
- `openapi.host` leer = gleiche IP wie `lala.host`.
- `openapi.include`: aus `battery, meter, bess, bessNameplate, evse`. ioBroker lässt `bess` (teils `null`-Werte) und `evse` (immer 4 leere Wallbox-Slots) standardmäßig weg.
- `connect.interval` Default **300 s** wie ioBroker (Rate-Limits nicht dokumentiert).
- `live_source_priority`: Reihenfolge, aus welcher Quelle die `live/*`-Topics gespeist werden, solange die Quelle aktuell ist (siehe 9.3).
- Konfig atomar schreiben (Temp-Datei + `rename`). Subscription-Key / MQTT-Passwort in der Oberfläche nur maskiert; leeres Feld beim Speichern = alten Wert behalten.
- **Nicht** in die Konfig: OpenAPI-Token, Zertifikats-Fingerprints → `data/.../state.json` (Laufzeitdaten, nicht vom Benutzer getippt). **Achtung:** Der LoxBerry-Installer löscht bei einem Upgrade `config/` **und** `data/` des Plugins → beides in `preupgrade.sh` sichern (siehe 12).

---

## 6. Quelle A: lokale Schnittstelle `lala.cgi`

### 6.1 Abfragestrategie (wie ioBroker `local-client.js`)

Zwei Abfragearten:

**Schnell (`interval`, 10 s)** – nur einzelne Keys:
```json
{
  "ENERGY": {
    "STAT_STATE": "", "GUI_BAT_DATA_POWER": "", "GUI_INVERTER_POWER": "",
    "GUI_HOUSE_POW": "", "GUI_GRID_POW": "", "GUI_BAT_DATA_FUEL_CHARGE": "",
    "GUI_CHARGING_INFO": "", "GUI_BOOSTING_INFO": "", "GUI_BAT_DATA_VOLTAGE": "",
    "GUI_BAT_DATA_CURRENT": "", "STAT_LIMITED_NET_SKEW": "",
    "SAFE_CHARGE_FORCE": "", "SAFE_CHARGE_PROHIBIT": "", "SAFE_CHARGE_RUNNING": ""
  },
  "PV1":     { "POWER_RATIO": "", "MPP_POWER": "" },
  "PM1OBJ1": { "FREQ": "", "U_AC": "", "I_AC": "", "P_AC": "", "P_TOTAL": "" },
  "PWR_UNIT":{ "POWER_L1": "", "POWER_L2": "", "POWER_L3": "" },
  "BMS": {
    "CURRENT": "", "SOC": "", "SYSTEM_SOC": "", "TEMP_MAX": "", "TEMP_MIN": "", "VOLTAGE": "",
    "CELL_TEMPERATURES_MODULE_A": "", "CELL_TEMPERATURES_MODULE_B": "",
    "CELL_TEMPERATURES_MODULE_C": "", "CELL_TEMPERATURES_MODULE_D": "",
    "CELL_VOLTAGES_MODULE_A": "", "CELL_VOLTAGES_MODULE_B": "",
    "CELL_VOLTAGES_MODULE_C": "", "CELL_VOLTAGES_MODULE_D": ""
  }
}
```
(Liste 1:1 aus ioBroker `local-client.js`. `GUI_BAT_DATA_OA_CHARGING` hat ioBroker 2026-08 entfernt – V3 kennt es nicht mehr.)

**Langsam (`interval_full`, 60 s)** – ganze Sektionen mit leerem Objekt, das Gerät liefert dann **alle** Keys der Sektion:
```json
{ "ENERGY": {}, "TEMPMEASURE": {}, "FACTORY": {}, "SYS_UPDATE": {}, "WIZARD": {} }
```

Bekannte Sektionen (ioBroker `constants.js` → `allKnownObjects`):
`AMPACE, BAT1, BAT1OBJ1, BMS, BMS_PARA, BMZ_CURRENT_LIMITS, CASC, CELL_DEVIATION_ROC, CURRENT_IMBALANCE_CONTROL, DEBUG, DISPLAY, ENERGY, FACTORY, FAN_SPEED, FAN_TEST, FEATURES, FILE, GRIDCONFIG, IPU, LOG, PM1, PM1OBJ1, PM1OBJ2, PV1, PWR_UNIT, RTC, SENEC_IO_INPUT, SENEC_IO_OUTPUT, SELFTEST_RESULTS, SOCKETS, STECA, SYS_UPDATE, TEMPMEASURE, TEST, UPDATE, WALLBOX, WIZARD`

- **`STATISTIC` ist von SENEC abgeschaltet** (ioBroker: `deprecatedSections`) → nicht abfragen.
- **Größenlimit der Anfrage:** Bei einer V3 kamen 11 428 Byte Request vollständig zurück, 11 998 Byte nur noch abgeschnitten (HTTP 200, kaputtes JSON). → Request-Body < **11 500 Byte** halten; ab 50 % im Log warnen. Ggf. in mehrere Requests aufteilen.

### 6.2 Dekodierung (aus ioBroker `constants.js` → `reviverNumParse`)

| Präfix | Bedeutung | Dekodierung |
|---|---|---|
| `fl_` | IEEE-754 float32, hex | Big-Endian-Float, auf 2 Nachkommastellen |
| `u1_` `u3_` `u6_` `u8_` | unsigned int, hex | `hexdec()` – **nur genau diese vier** Präfixe (ioBroker-Bugfix: „under_voltage“ wurde früher fälschlich als Zahl gelesen) |
| `i1_` | signed 16 bit | Zweierkomplement (`& 0x8000`) |
| `i3_` | signed 32 bit | Zweierkomplement (`& 0x80000000`) |
| `i8_` | signed 8 bit | Zweierkomplement (`& 0x80`) |
| `st_` | String | Rest nach Präfix |
| `ch_` | String/Char | Rest nach Präfix (ganzer Rest, nicht am `_` abschneiden) |
| `er_…` | Gerät meldet Fehler | Wert **nicht** veröffentlichen (vorübergehend) |
| `VARIABLE_NOT_FOUND`, `OBJECT_NOT_FOUND`, `FORBIDDEN` | Datenpunkt gibt es auf diesem Gerät nicht | dauerhaft überspringen (Key aus der Schnellabfrage entfernen) |
| `MALFORMED_VALUE` | diesmal nicht lesbar | überspringen |
| `FILE_VARIABLE_NOT_READABLE` | – | leerer String |
| alles andere | unbekannt | überspringen, einmalig im Log melden |

Antworten können verschachtelte Arrays sein (z. B. `U_AC` = 3 Phasen, `MPP_POWER` = je Tracker, `CELL_VOLTAGES_MODULE_A` = je Zelle).

```php
final class SenecDecode {
    public const ABSENT = ['VARIABLE_NOT_FOUND', 'OBJECT_NOT_FOUND', 'FORBIDDEN'];

    /** @return mixed  decodierter Wert oder null = nicht veröffentlichen */
    public static function value($v) {
        if (is_array($v)) return array_map([self::class, 'value'], $v);
        if (!is_string($v)) return $v;
        if (str_starts_with($v, 'FILE_VARIABLE_NOT_READABLE')) return '';
        if (strlen($v) < 3 || $v[2] !== '_') return null;            // Sentinels, Unbekanntes
        $type = substr($v, 0, 2); $hex = substr($v, 3);
        switch ($type) {
            case 'fl':
                if (strlen($hex) !== 8 || !ctype_xdigit($hex)) return null;
                $f = unpack('G', hex2bin($hex))[1];
                return (is_nan($f) || is_infinite($f)) ? null : round($f, 2);
            case 'u1': case 'u3': case 'u6': case 'u8':
                return ($hex !== '' && ctype_xdigit($hex)) ? hexdec($hex) : null;
            case 'i8': case 'i1': case 'i3':
                if ($hex === '' || !ctype_xdigit($hex)) return null;
                $bits = ['i8' => 8, 'i1' => 16, 'i3' => 32][$type];
                $n = hexdec($hex);
                return $n >= (1 << ($bits - 1)) ? $n - (1 << $bits) : $n;
            case 'st': case 'ch':
                return $hex;
            default:                                                    // er_ und Unbekanntes
                return null;
        }
    }

    public static function isAbsent($raw): bool {
        return is_string($raw) && in_array($raw, self::ABSENT, true);
    }
}
```

Testfälle: `fl_43C80000`→400.0, `fl_C3480000`→-200.0, `u8_04`→4, `i1_FFFF`→-1, `i3_FFFFFF9C`→-100, `st_abc`→"abc", `ch_a_b`→"a_b", `er_12`→null, `VARIABLE_NOT_FOUND`→null + isAbsent.

### 6.3 HTTP / TLS

- `POST https://<IP>/lala.cgi`, Body JSON, `Content-Type: application/json`. ioBroker nutzt inzwischen **standardmäßig HTTPS**; HTTP nur als Fallback für alte Geräte (`auto`: HTTPS zuerst, Ergebnis merken).
- Zertifikat: selbstsigniert, `CN=senecGuiSelf`, Aussteller `SenecGui-Root` (nicht öffentlich). ioBroker macht „TOFU“ (Trust on first use): Prüfung aus, SHA-256-Fingerprint beim ersten Kontakt merken, bei Änderung **warnen, übernehmen und weitermachen** (bewusste Entscheidung: Verfügbarkeit vor strenger Prüfung im eigenen LAN). Im Plugin genauso:
  - `CURLOPT_SSL_VERIFYPEER=false`, `CURLOPT_SSL_VERIFYHOST=0`, `CURLOPT_CERTINFO=true`
  - Fingerprint aus `curl_getinfo($ch, CURLINFO_CERTINFO)[0]['Cert']` → `openssl_x509_fingerprint(..., 'sha256')`
  - Fingerprint in `state.json` → bei Abweichung `warn` ins Log und Topic `senec/error`.
- Timeout 5 s. Fehler → Backoff (Intervall verdoppeln bis 60 s), `senec/lala/ok` = 0.
- Optional (später): eigenes CA-PEM hochladen und echte Prüfung (ioBroker-Modus `user`).

### 6.4 Vorzeichen & Klartexte

- `ENERGY.GUI_GRID_POW`: **+ Bezug**, **− Einspeisung**
- `ENERGY.GUI_BAT_DATA_POWER`: **+ Laden**, **− Entladen**
- `ENERGY.STAT_STATE` → Klartext aus ioBroker `lib/state_trans.js` (Tabelle `"ENERGY.STAT_STATE.0"`, Codes 0–100+, deutsch). Beispiele: 13 = AKKU VOLL, 14 = LADEN, 16 = ENTLADEN, 17 = PV + ENTLADEN, 21 = EIGENVERBRAUCH. Tabelle vollständig nach `StateTexts.php` übernehmen (MIT-Lizenz → Copyright-Hinweis „© NoBl“ im Dateikopf und in README).
- Weitere Einheiten/Namen je Datenpunkt: ioBroker `lib/state_attr.js` (z. B. `"ENERGY.GUI_GRID_POW": { name: "Net Power Current", unit: "W" }`) – gut als Vorlage für die Livewerte-Tabelle in der Weboberfläche.

---

## 7. Quelle B: lokale SENEC OpenAPI (Port 4424)

Quelle: ioBroker `lib/openapi-client.js` und `docs/LOCAL_OPENAPI_4424.md` (gemessen an einer V3 hybrid LFP, 25.10.2026).

### 7.1 Eckdaten

- `https://<IP>:4424`, HTTP/2 (Go-Server), JSON-Antworten, Fehler als `text/plain`.
- Auth-Header: **`X-Auth-Token: <uuid>`**
- Keine Schema-/Discovery-Route vorhanden.
- Zertifikat eigenständig: Subject `CN=S<Geräte-ID>`, Issuer `CN=openapi`, gültig bis 2035. → Ebenfalls TOFU, eigener Fingerprint (nicht der von Port 443). Der CN entspricht `FACTORY.DEVICE_ID` aus `lala.cgi` mit `S` statt `st_` → optional gegenprüfen.

### 7.2 Registrierung (einmalig)

1. `POST https://<IP>:4424/v1/register`
   `Content-Type: application/json; charset=utf-8`
   Body: `{"deviceName":"LoxBerry","manufacturerName":"LoxBerry.SENEC2MQTT"}`
   Antwort: **`202 Accepted`**, Body = nackte UUID = **Token**. Token sofort in `state.json` speichern.
2. Benutzer gibt den Eintrag im Portal frei:
   `https://mein-senec.de/endkunde/#/0/senec-open-api`
   (`0` = Anlagen-Index; bei mehreren Anlagen/MCU-Tausch `1`, `2` …). Die Seite ist **nicht im Menü verlinkt**, nur per URL erreichbar → in der Weboberfläche als Link anzeigen.
3. Freigabe kommt über die Cloud ans Gerät (Rücknahme dauerte ca. 30 s; Freigabe-Dauer nicht gemessen) → bis zu ~70 s nachfragen (ioBroker: 14 × 5 s).

**Harte Regeln (sonst entsteht Chaos im Portal):**
- Jeder `POST /v1/register` erzeugt einen **neuen** Eintrag im Portal, die man später nicht mehr unterscheiden kann.
- Nur registrieren, wenn **kein Token gespeichert** ist – und **nur auf Knopfdruck** in der Weboberfläche („Registrieren“), nie automatisch in der Poll-Schleife.
- Nur eine Registrierung gleichzeitig (Lock).
- „Token vergessen“ ist ein eigener, bewusster Knopf mit Hinweis: „Alten Eintrag im Portal entfernen, bevor neu registriert wird.“
- Bei Timeout während der Registrierung (Gerät hat sie evtl. doch angelegt): Hinweis, im Portal nachzusehen.

(ioBroker kann die Freigabe zusätzlich automatisch über das mein-senec-Portal erledigen – das braucht den SSO-Login mit Benutzer/Passwort. **Nicht ins Plugin übernehmen**, Freigabe macht der Benutzer von Hand.)

### 7.3 Statuscodes

| Antwort | Bedeutung | Reaktion |
|---|---|---|
| `200` + JSON | ok | Werte verarbeiten |
| `511 Token activation pending` | registriert, noch nicht freigegeben | Status „Freigabe ausstehend“ + Portal-Link, weiter alle 60 s probieren |
| `401 Incorrect token` | kein/falscher/**widerrufener** Token – nicht unterscheidbar | Abfrage stoppen, Status „Token ungültig oder widerrufen – im Portal prüfen, ggf. Token vergessen und neu registrieren“. **Nicht** automatisch neu registrieren. Vor dieser Meldung nach 3 s einmal wiederholen (Verzögerung durch Cloud). |
| `403` | nur auf Steuer-Routen | Steuer-Routen nie aufrufen |
| Verbindung abgelehnt | Gerät ohne OpenAPI (z. B. V4/P4/E4, alte Firmware) | Status „Port 4424 nicht erreichbar“, Backoff bis 15 min |

### 7.4 Routen und Daten

Je Sektion eine eigene GET-Route, camelCase:

| Route | Felder | Beispiel (V3) |
|---|---|---|
| `/v1/battery` | `state`, `stateOfCharge`, `power`, `voltage`, `current` | `{"state":1,"stateOfCharge":47,"power":-850.8,"voltage":52.2,"current":-16.3}` |
| `/v1/meter` | `gridPower`, `consumption`, `production`, `powerSurplus`, `fullRangePowerSurplus` | `{"gridPower":91,"consumption":1181,"production":244,"powerSurplus":0,"fullRangePowerSurplus":0}` |
| `/v1/bess` | `apparentPower`, `activePower`, `reactivePower`, `voltage`, `current`, `acRelayConnected` | `activePower`/`reactivePower` teils `null`, teils 0 |
| `/v1/bess/nameplate` | `manufacturer`, `model`, `serialNumber`, `designCapacity`, `ratedPower{activeChargePower, activeDischargePower, reactiveChargePower, reactiveDischargePower}` | `designCapacity:5000` |
| `/v1/evse` | `devices[]` mit `id`, `evConnected`, `evCharging`, `limitationStatus`, `chargingPower` | immer 4 Slots, auch ohne Wallbox |

**Fallstricke (aus den ioBroker-Messungen):**
- `battery.power`: + Laden, − Entladen (wie lala). Im Beispiel: Entladen mit −850 W.
- **`battery.state` nicht übersetzen** – Bedeutung unbekannt (gesunde Geräte melden `1`, obwohl SENEC.Connect `1 = Fehler` dokumentiert).
- **`meter.gridPower` Vorzeichen ungeklärt** – alle Messungen lagen bei Netzbezug. Entweder vorzeichenbehaftet oder nur Betrag (Einspeisung dann in `powerSurplus`). ioBroker-Logik übernehmen:
  1. `gridPower < 0` → vorzeichenbehaftet, so übernehmen.
  2. sonst Richtung aus Bilanz: `x = consumption + battery.power − production`; |x| < 50 W → 0.
  3. Bilanz sagt Einspeisung, aber `gridPower == 0` → Betrag aus `powerSurplus`, negativ.
  → **⚠ prüfen** an deiner Anlage: bei Einspeisung gleichzeitig `ENERGY.GUI_GRID_POW` (lala) und `meter.gridPower`/`powerSurplus` mitloggen. Debug-Option dafür vorsehen.
- Energiebilanz stimmt: `consumption ≈ production + |battery.power| + gridPower` (Entladefall).
- `bess.activePower/reactivePower`: `null` und `0` beide als „kein Wert“ behandeln.
- **`designCapacity` = ein Modul, nicht der ganze Speicher** (V3: 5000 Wh bei 2 Modulen = 10 kWh). Gesamt nur mit `BMS.MODULE_COUNT` aus lala berechenbar. Topic heißt deshalb `nennkapazitaet_modul_wh`.
- `ratedPower` kann 0 sein → als „nicht gemeldet“ behandeln.
- `evse.devices`: Wallbox nur als vorhanden werten, wenn `evConnected`/`chargingPower` jemals ≠ false/0.

---

## 8. Quelle C: SENEC.Connect (offizielle Cloud-API)

Quelle: ioBroker `lib/connect-client.js`, evcc-Template `senec-connect`, Developer-Portal.

### 8.1 Zugang

1. Auf https://developer.senec.com mit dem SENEC-Konto anmelden.
2. Unter „Produkte“ abonnieren → **Subscription-Key** (Profil → „Meine Abonnements“).
3. Nutzungsbedingungen SENEC.Connect beachten.
4. Im Portal unter „APIs“ die OpenAPI-Spezifikation herunterladen und als `docs/senec-connect-openapi.json` ins Repo legen (Referenz für Einheiten).

### 8.2 Abfrage

```
GET https://apim-eds-gwc-prod.azure-api.net/senec-connect/v1/systems/device-data/general?include=battery,meter,evse,bessNameplate
Accept: application/json
Ocp-Apim-Subscription-Key: <key>
```
- `bessNameplate` **immer** mitschicken (ioBroker erzwingt das) – nur darüber lassen sich mehrere Anlagen unterscheiden.
- Antwort: **Array** von Anlagen, snake_case, gleiche Datenstruktur wie die lokale OpenAPI:

| JSON-Pfad | Bedeutung |
|---|---|
| `battery.state`, `battery.state_of_charge` (%), `battery.power` (W, + Laden), `battery.voltage`, `battery.current` | Batterie |
| `meter.grid_power` (W, + Bezug, − Einspeisung laut evcc), `meter.consumption` (Haus **ohne** Wallbox), `meter.production` | Zähler |
| `bessNameplate.serial_number` (`v4-…`), `bessNameplate.system_id` (`P4H1-…`/`S4H1-…`), `design_capacity` (Wh, **inkl.** weiterer Einheiten), `active_charge_power`, `active_discharge_power` | Typenschild |
| `evse[]` mit `id` (String, z. B. `"WB-01"`), `charging_power` (in **VA**), … | Wallbox |

- Anlagen-Auswahl: `connect.system_filter` = Seriennummer oder System-ID; leer → alle Anlagen veröffentlichen unter `senec/connect/<id>/…`, die erste speist `live/*`. Ungültige Zeichen in IDs für Topics ersetzen.
- Keine Schreib-Operation in der API vorhanden.

### 8.3 Fehler

- 401/403 → „Subscription-Key ungültig“, Abfrage auf 30 min drosseln.
- 429 → `Retry-After` beachten, sonst Backoff bis 30 min.
- TLS normal prüfen (öffentliches Zertifikat). Antwort > 4 MB verwerfen.

### 8.4 Nicht verwenden: alte App-API / mein-senec-Portal

ioBroker hat zusätzlich `api-client.js` (App-API, `senec-app-*-proxy.prod.senec.dev`) und `web-client.js` (mein-senec.de), beides mit SSO-Login (`sso.senec.com`, Keycloak, Client `endcustomer-app-frontend`, PKCE). Das liefert Statistik/Historie, ist aber inoffiziell, wurde 2025/2026 mehrfach umgebaut und braucht das Kontopasswort. **Nicht in Version 1.** Ggf. später als optionale Statistik-Quelle.

---

## 9. MQTT

### 9.1 Zugangsdaten vom LoxBerry

1. `require_once 'loxberry_io.php';` → `mqtt_connectiondetails()` (bestätigt im LoxBerry-Kern, `libs/phplib/loxberry_io.php`). Liefert u. a. `brokerhost`, `brokerport`, `brokeruser`, `brokerpass`, `brokeraddress`; bei aktiviertem TLS zusätzlich `tls_brokerport` usw. Quelle ist `config/system/general.json` → `Mqtt.Brokerhost/Brokerport/Brokeruser/Brokerpass`.
2. Der eigene Client nutzt den unverschlüsselten `brokerport` (immer gesetzt). TLS zum lokalen Broker ist für Version 1 nicht nötig.
3. `use_loxberry_broker=false` → Werte aus der Plugin-Konfig.
4. Die Zugangsdaten **bei jedem Reconnect neu holen** – der Benutzer kann sie in LoxBerry ändern.

Warum nicht die fertigen LoxBerry-Funktionen `mqtt_publish()`/`mqtt_retain()`/`mqtt_set()`? Die gibt es (phpMQTT, `libs/phplib/phpMQTT`), sie bauen aber pro Aufruf eine neue Verbindung auf – bei 30+ Werten alle 10 s ungeeignet. Alternative zum eigenen Client: die mitgelieferte Klasse `Bluerhinos\phpMQTT` einmal verbinden und offen halten (`connect()`, `publish()`, `proc()` für Keepalive). **Entscheidung beim Umsetzen** – eigener Client (9.2) hat keine Abhängigkeit vom Kern.

### 9.2 Minimaler Client (MQTT 3.1.1, QoS 0)

```php
final class MqttClient {
    private $sock = null; private int $lastSend = 0;
    public function __construct(private string $host, private int $port,
        private string $user, private string $pass, private string $clientId,
        private string $willTopic, private int $keepalive = 60) {}

    private static function str(string $s): string { return pack('n', strlen($s)) . $s; }
    private static function len(int $l): string {
        $o = ''; do { $d = $l % 128; $l = intdiv($l, 128); if ($l > 0) $d |= 0x80; $o .= chr($d); } while ($l > 0); return $o;
    }
    public function connected(): bool { return $this->sock !== null; }

    public function connect(): bool {
        $this->sock = @stream_socket_client("tcp://{$this->host}:{$this->port}", $e, $es, 5) ?: null;
        if (!$this->sock) return false;
        stream_set_timeout($this->sock, 5);
        $flags = 0x02 | 0x04 | 0x20;                 // clean session, will, will retain
        if ($this->user !== '') $flags |= 0x80;
        if ($this->pass !== '') $flags |= 0x40;
        $body = self::str('MQTT') . chr(4) . chr($flags) . pack('n', $this->keepalive)
              . self::str($this->clientId) . self::str($this->willTopic) . self::str('0');
        if ($this->user !== '') $body .= self::str($this->user);
        if ($this->pass !== '') $body .= self::str($this->pass);
        if (!$this->write(chr(0x10) . self::len(strlen($body)) . $body)) return false;
        $ack = fread($this->sock, 4);
        if ($ack === false || strlen($ack) < 4 || ord($ack[0]) !== 0x20 || ord($ack[3]) !== 0) { $this->close(); return false; }
        stream_set_blocking($this->sock, false);
        return true;
    }

    public function publish(string $topic, string $payload, bool $retain): bool {
        $body = self::str($topic) . $payload;
        return $this->write(chr(0x30 | ($retain ? 1 : 0)) . self::len(strlen($body)) . $body);
    }

    /** Jede Runde aufrufen: eingehende Pakete verwerfen, Keepalive senden. */
    public function loop(): bool {
        if (!$this->sock) return false;
        while (($d = @fread($this->sock, 1024)) !== false && $d !== '') {}
        if (feof($this->sock)) { $this->close(); return false; }
        if (time() - $this->lastSend > $this->keepalive / 2) return $this->write(chr(0xC0) . chr(0));
        return true;
    }

    private function write(string $data): bool {
        if (!$this->sock) return false;
        $done = 0; $len = strlen($data); $start = microtime(true);
        while ($done < $len) {
            $n = @fwrite($this->sock, substr($data, $done));
            if ($n === false) { $this->close(); return false; }
            if ($n === 0) { if (microtime(true) - $start > 5) { $this->close(); return false; } usleep(10000); continue; }
            $done += $n;
        }
        $this->lastSend = time(); return true;
    }

    public function close(): void {
        if ($this->sock) { @fwrite($this->sock, chr(0xE0) . chr(0)); @fclose($this->sock); }
        $this->sock = null;
    }
}
```
- Client-ID `senec2mqtt_<hostname>`, LWT `<prefix>/online` = `0` retained; nach Connect `1` retained.
- Reconnect mit Backoff 2 → 60 s.

### 9.3 Topics

Präfix Default `senec`. Werte als reine Zahl/Text (kein JSON) → das LoxBerry-MQTT-Gateway macht daraus direkt virtuelle Eingänge (`senec_live_pv_w` …).

**Sprechende Topics `senec/live/*`** – gespeist aus der ersten Quelle laut `live_source_priority`, deren letzte erfolgreiche Abfrage jünger als 3 × ihr Intervall ist:

| Topic | A: lala.cgi | B: OpenAPI 4424 | C: Connect |
|---|---|---|---|
| `pv_w` | `ENERGY.GUI_INVERTER_POWER` | `meter.production` | `meter.production` |
| `haus_w` | `ENERGY.GUI_HOUSE_POW` | `meter.consumption` | `meter.consumption` |
| `netz_w` (+ Bezug / − Einspeisung) | `ENERGY.GUI_GRID_POW` | berechnet (7.4) | `meter.grid_power` |
| `netz_bezug_w` / `netz_einspeisung_w` | aus `netz_w` | aus `netz_w` | aus `netz_w` |
| `batterie_w` (+ Laden / − Entladen) | `ENERGY.GUI_BAT_DATA_POWER` | `battery.power` | `battery.power` |
| `batterie_laden_w` / `batterie_entladen_w` | aus `batterie_w` | aus `batterie_w` | aus `batterie_w` |
| `batterie_soc` | `ENERGY.GUI_BAT_DATA_FUEL_CHARGE` | `battery.stateOfCharge` | `battery.state_of_charge` |
| `batterie_spannung_v` | `ENERGY.GUI_BAT_DATA_VOLTAGE` | `battery.voltage` | `battery.voltage` |
| `batterie_strom_a` | `ENERGY.GUI_BAT_DATA_CURRENT` | `battery.current` | `battery.current` |
| `pv_ueberschuss_w` | – | `meter.powerSurplus` | – |
| `status_code` / `status_text` | `ENERGY.STAT_STATE` + Tabelle | – | – |
| `netz_frequenz_hz` | `PM1OBJ1.FREQ` | – | – |
| `phase_l1_w` … `l3_w` | `PWR_UNIT.POWER_L1..3` | – | – |
| `temp_batterie_max_c` / `_min_c` | `BMS.TEMP_MAX` / `TEMP_MIN` | – | – |
| `quelle` | `lala` | `openapi` | `connect` |

**Info (selten):** `senec/info/modell`, `seriennummer`, `nennkapazitaet_modul_wh`, `module_anzahl` (lala `BMS.MODULE_COUNT`), `firmware` (lala `SYS_UPDATE.NPU_VER`/`NPU_IMAGE_VERSION` **⚠ prüfen**).

**Status:** `senec/online`, `senec/lala/ok`, `senec/openapi/ok`, `senec/openapi/status` (`ok`/`pending`/`rejected`/`unreachable`/`unregistered`), `senec/connect/ok`, `senec/lastupdate` (Unix-Zeit), `senec/error`.

**Rohwerte (`publish_raw`):**
- A: `senec/raw/<SEKTION>/<KEY>`, Arrays als `…/<KEY>/0`, `/1` …
- B: `senec/openapi/<sektion>/<feld>` (verschachtelt mit `/`)
- C: `senec/connect/<anlage>/<sektion>/<feld>`

Rohwerte der Langsam-Abfrage (ganze Sektionen) nur bei Änderung senden – das sind schnell mehrere hundert Topics.

---

## 10. Hauptdienst – Ablauf

```
start
 ├─ Pfade aus __DIR__ ableiten; flock(/dev/shm/<folder>/run.lock) sonst Ende; PID schreiben
 ├─ SIGTERM/SIGINT (pcntl, falls vorhanden) → online=0, MQTT DISCONNECT, Ende
 └─ Schleife (Tick 1 s):
     ├─ Config neu laden bei mtime-Änderung (MQTT-Änderung → reconnect)
     ├─ enabled=false → schlafen
     ├─ MQTT verbunden? sonst connect (Backoff)
     ├─ für jede aktive Quelle mit nextRun <= now:
     │     poll → Ergebnis/Fehler; nextRun = now + interval (bzw. Backoff)
     ├─ Mapper: live/* aus bester aktueller Quelle, raw/*, info/*, Status
     ├─ Änderungsfilter (only_changes, full_resend_minutes) → publish
     ├─ status.json in /dev/shm schreiben
     └─ mqtt->loop()
```
Requests sind blockierend (curl) – Timeouts klein halten, damit eine hängende Quelle die anderen nicht lange aufhält (optional später `curl_multi`).

Logging über LoxBerrys Log-Bibliothek (`libs/phplib/loxberry_log.php`), damit die Logs im LoxBerry-Log-Manager erscheinen und der Loglevel aus der Plugin-Verwaltung kommt:
```php
require_once "loxberry_log.php";
$log = LBLog::newLog(["name" => "Dienst", "filename" => LBPLOGDIR . "/senec2mqtt.log", "append" => 1]);
LOGSTART("SENEC2MQTT gestartet");
LOGINF("…"); LOGWARN("…"); LOGERR("…"); LOGDEB("…");
```
Für einen Dauerdienst die Datei selbst begrenzen (bei > 1 MB neue Log-Sitzung mit `LOGEND` / neuem `newLog` beginnen). Den Loglevel aus der Plugin-Verwaltung bei Konfig-Reload neu lesen (`LBSystem::pluginloglevel()`). Gleiche Fehlermeldung höchstens alle 10 min (ioBroker „loggedConditions“). Subscription-Key und Token **nie** loggen (maskieren).

---

## 11. Start/Stop, Daemon, Watchdog

`bin/start.sh`
```bash
#!/bin/bash
DIR="$(cd "$(dirname "$0")" && pwd)"
nohup php "$DIR/senec2mqtt.php" >/dev/null 2>&1 &
```
`bin/stop.sh`
```bash
#!/bin/bash
FOLDER="$(basename "$(cd "$(dirname "$0")" && pwd)")"
PIDFILE="/dev/shm/$FOLDER/senec2mqtt.pid"
[ -f "$PIDFILE" ] && kill "$(cat "$PIDFILE")" 2>/dev/null
```
`daemon/daemon` (Boot, root; wird nach `system/daemons/plugins/<name>` kopiert)
```bash
#!/bin/bash
su loxberry -s /bin/bash -c "REPLACELBPBINDIR/start.sh"
exit 0
```
`cron/cron.01min` (Datei heißt exakt wie der Takt; wird als loxberry nach `system/cron/cron.01min/<name>` kopiert)
```bash
#!/bin/bash
REPLACELBPBINDIR/start.sh
```
Zulässige Cron-Dateinamen laut Installer: `cron.reboot, cron.01min, cron.03min, cron.05min, cron.10min, cron.15min, cron.30min, cron.hourly, cron.daily, cron.weekly, cron.monthly, cron.yearly` oder eine eigene `cron/crontab`.
Die Platzhalter ersetzt der Installer; Rechte setzt er selbst (siehe 3). `start.sh`/`stop.sh` liegen in `bin/` und werden dort 755.

---

## 12. Installations-Skripte

Argumente (laut `plugininstall.pl`, für alle Skripte gleich): `$1` Temp-Dateiname, `$2` Plugin-Name, `$3` Plugin-Ordner, `$4` Version, `$5` LBHOMEDIR, `$6` Temp-Ordner des entpackten Plugins.

Ablauf und Benutzer laut Installer:

| Schritt | Skript | läuft als | wann |
|---|---|---|---|
| 1 | `preroot.sh` | root | Install + Upgrade (nicht nötig) |
| 2 | `preupgrade.sh` | loxberry | nur Upgrade, **vor** dem Löschen der alten Dateien |
| 3 | – | – | **Upgrade: Installer löscht `config/`, `bin/`, `data/`, `templates/`, `htmlauth/` des Plugins, Cronjobs und Daemon** |
| 4 | `preinstall.sh` | loxberry | Install + Upgrade |
| 5 | – | – | Dateien kopieren, Platzhalter ersetzen, `dpkg/apt` installieren (hier nicht nötig) |
| 6 | `postinstall.sh` | loxberry | Install + Upgrade |
| 7 | `postupgrade.sh` | loxberry | nur Upgrade |
| 8 | `postroot.sh` | root | Install + Upgrade (nicht nötig) |
| – | `uninstall/uninstall` | root | nur Deinstallation |

Exit-Code 1 = Fehler (Installation läuft weiter), > 1 = Abbruch.

- `preupgrade.sh`: Dienst stoppen, Konfig **und** Daten (OpenAPI-Token!) sichern:
  ```bash
  #!/bin/bash
  "$5/bin/plugins/$3/stop.sh"
  mkdir -p "/tmp/$1_upgrade/config" "/tmp/$1_upgrade/data"
  cp -p -r "$5/config/plugins/$3/." "/tmp/$1_upgrade/config/"
  cp -p -r "$5/data/plugins/$3/."   "/tmp/$1_upgrade/data/" 2>/dev/null
  exit 0
  ```
- `postinstall.sh`: Log-Ordner anlegen; bei Neuinstallation Dienst starten.
- `postupgrade.sh`: beides zurückspielen, Dienst starten, Temp löschen:
  ```bash
  #!/bin/bash
  cp -p -r "/tmp/$1_upgrade/config/." "$5/config/plugins/$3/"
  cp -p -r "/tmp/$1_upgrade/data/."   "$5/data/plugins/$3/" 2>/dev/null
  rm -rf "/tmp/$1_upgrade"
  "$5/bin/plugins/$3/start.sh"
  exit 0
  ```
  Neue Konfig-Schlüssel ergänzt `Config.php` beim Laden (Defaults ∪ gespeichert).
- `uninstall/uninstall` (root): Dienst über PID beenden, `/dev/shm/$3` löschen. README-Hinweis: OpenAPI-Eintrag im Portal selbst entfernen.

**Token-Verlust vermeiden:** Geht `state.json` verloren, braucht die OpenAPI eine Neuregistrierung (neuer Portal-Eintrag). Deshalb zwingend sichern.

---

## 13. Weboberfläche

Gerüst:
```php
<?php
require_once "loxberry_system.php";
require_once "loxberry_web.php";
LBWeb::lbheader("SENEC2MQTT", "", "");
// Inhalt
LBWeb::lbfooter();
```
Globale Variablen/Konstanten aus `loxberry_system.php`: `$lbpconfigdir`, `$lbpdatadir`, `$lbplogdir`, `$lbpbindir` bzw. `LBPCONFIGDIR` usw. `LBWeb::lbheader()` lädt jQuery Mobile (4. Parameter `$nojqm=true` schaltet es ab). Formulare mit `data-ajax="false"`. Alternativ mehrseitig mit `LBWeb::pagestart()` / `LBWeb::pageend()` und Navigationsleiste.

Reiter / Bereiche:
1. **Status** (Auto-Refresh 5 s): Dienst, MQTT, je Quelle letzte Abfrage/Fehler, aktive Live-Quelle. Buttons Neu starten / Stoppen.
2. **Livewerte**: Tabelle `live/*` mit Topic (kopierbar) und Wert.
3. **Lokal (lala.cgi)**: aktiv, IP, Intervalle, Sektionen für Vollabfrage, Timeout, Zertifikats-Fingerprint + „Fingerprint zurücksetzen“, „Verbindung testen“ (zeigt Gerätemodell aus `FACTORY`).
4. **Lokale OpenAPI**: aktiv, Host (leer = wie lala), Intervall, Sektionen (Checkboxen), Gerätename. Statusanzeige mit Ablauf:
   - *nicht registriert* → Button **„Am Speicher registrieren“**
   - *Freigabe ausstehend* → Link `https://mein-senec.de/endkunde/#/0/senec-open-api` + Hinweis Anlagen-Index
   - *aktiv* → Livewerte der OpenAPI
   - *abgelehnt/widerrufen* → Button **„Token vergessen“** (mit Bestätigungsdialog und Portal-Hinweis)
   - *nicht erreichbar* → „Gerät bietet keine OpenAPI (V4/P4/E4 oder Firmware < 4.4.0)“
5. **SENEC.Connect**: aktiv, Subscription-Key (Passwortfeld), Anlagen-Filter, Intervall, Sektionen; „Verbindung testen“ listet gefundene Anlagen (Seriennummer/System-ID zum Übernehmen). Link zum Developer-Portal.
6. **MQTT**: LoxBerry-Broker verwenden / eigener Broker, Präfix, retain, nur Änderungen, Rohwerte, Live-Quellen-Reihenfolge.
7. **Loxone-Hinweis**: Im MQTT-Gateway `senec/#` abonnieren (bzw. `senec/live/#`, `senec/online`), virtuelle Eingänge `senec_live_…` anlegen.
8. **Log**: letzte 50 Zeilen.

Sicherheit: Eingaben validieren (IP/Hostname, Zahlenbereiche, Präfix ohne `#`/`+`), `htmlspecialchars` überall, CSRF-Token in der Session.

`ajax.php`: `status`, `log`, `restart`, `stop`, `test_lala`, `test_connect`, `openapi_register`, `openapi_check`, `openapi_forget`, `reset_fingerprint`.
Die Registrierung läuft in `ajax.php` (als loxberry) und schreibt das Token in `state.json`; der Dienst liest `state.json` bei `mtime`-Änderung neu ein.

---

## 14. Tests

1. Unit-Tests (`tests/run.php` oder PHPUnit): `SenecDecode`, Mapper (Vorzeichen, Grid-Logik der OpenAPI aus 7.4 mit Import-/Export-/Nullfällen, Quellen-Priorität), Config-Merge, MQTT-Längenkodierung.
2. Fake-Server (`php -S`):
   - `tests/fake_lala.php` – kodierte Werte inkl. `VARIABLE_NOT_FOUND`, `er_`, Arrays; Sektionsabfrage `{}` liefert viele Keys.
   - `tests/fake_openapi.php` – `/v1/register` → 202 + UUID; Zustände unregistered/pending(511)/ok/401 per Schalter.
   - `tests/fake_connect.php` – Array mit 2 Anlagen.
3. MQTT lokal: `mosquitto` + `mosquitto_sub -v -t 'senec/#'`.
4. Am LoxBerry: Installation, Topics im MQTT-Finder, Miniserver-Eingänge, Reboot-Test, Kill-Test (Watchdog), Upgrade-Test (Konfig **und** OpenAPI-Token bleiben), Deinstallation.
5. Am Gerät klären: Vorzeichen `meter.gridPower` bei Einspeisung (7.4), Fingerprints, Antwortzeiten.

**Später (nicht Version 1):** Zwangsladung an/aus über lala.cgi (ioBroker `constants.js`: `batteryOn` = `{"ENERGY":{"SAFE_CHARGE_FORCE":"u8_01",…}}`, `batteryOff` = `SAFE_CHARGE_PROHIBIT:"u8_01"`) per MQTT-Befehlstopic – nur mit ausdrücklicher Freischaltung und Warnhinweis. Statistik über mein-senec.

---

## 15. Umsetzungsreihenfolge in VS Code

1. Repo, Struktur aus 3, `.editorconfig` + `.gitattributes` (`* text=auto eol=lf` – wichtig für Shell-Skripte).
2. ioBroker-Repo daneben klonen (`git clone https://github.com/nobl/ioBroker.senec`) als Nachschlagewerk – v. a. `lib/constants.js`, `lib/local-client.js`, `lib/openapi-client.js`, `lib/connect-client.js`, `lib/state_trans.js`, `lib/state_attr.js`, `docs/LOCAL_OPENAPI_4424.md`.
3. `SenecDecode` + Tests.
4. `LalaSource` + Fake-Server.
5. `MqttClient` gegen lokalen Mosquitto.
6. `Mapper`, Hauptschleife, Config-Reload, Lock, Logging, `State`.
7. `OpenApiSource` inkl. Registrierungsablauf + Fake-Server.
8. `ConnectSource` + Fake-Server, dann echter Key.
9. `StateTexts.php` aus `state_trans.js` generieren (kleines Skript, Copyright-Hinweis).
10. Weboberfläche + `ajax.php`.
11. Install-/Upgrade-/Uninstall-Skripte, Daemon, Cron, Icons.
12. `build.sh`: ZIP ohne `tests/`, `.git`, `docs/` → `senec2mqtt-<version>.zip`.
13. Test-LoxBerry, Punkt 14.4/14.5.

Erweiterungen: PHP Intelephense, ShellCheck, EditorConfig, optional Remote-SSH zum LoxBerry.

---

## 16. Abnahmekriterien

- [ ] Installation über die LoxBerry-Pluginverwaltung ohne Fehler (LB ≥ 3.0).
- [ ] lala.cgi: nach Eingabe der IP innerhalb 30 s Werte unter `senec/live/*`.
- [ ] OpenAPI: Registrierung per Knopf, Anzeige „Freigabe ausstehend“ mit Portal-Link, nach Freigabe Werte; 401 führt **nicht** zu automatischer Neuregistrierung.
- [ ] SENEC.Connect: mit Subscription-Key Werte; mehrere Anlagen werden erkannt und auswählbar.
- [ ] Fällt die bevorzugte Quelle aus, speist die nächste `live/*` (`senec/live/quelle` zeigt es), und zurück.
- [ ] Konfigänderungen wirken ohne Neustart.
- [ ] Autostart nach Reboot und nach Absturz (≤ 1 min).
- [ ] Upgrade behält Konfiguration und OpenAPI-Token.
- [ ] Keine Keys/Tokens/Passwörter im Log oder im Klartext in der Oberfläche.
- [ ] Deinstallation stoppt den Dienst und entfernt die Dateien.

---

## 17. Quellen

- ioBroker-Adapter (Vorlage, MIT): https://github.com/nobl/ioBroker.senec – insbesondere `lib/constants.js`, `lib/local-client.js`, `lib/openapi-client.js`, `lib/connect-client.js`, `lib/state_trans.js`, `docs/LOCAL_OPENAPI_4424.md`, `docs/TLS_TOFU_REVIEW.md`
- ASKOMA-Anleitung zur Kopplung über die SENEC OpenAPI (Firmware ≥ 4.4.0): https://faq.askoma.com/index_html_files/SENEC_V2_V3.pdf
- SENEC Developer-Portal: https://developer.senec.com/instructions
- evcc SENEC.Connect: https://docs.evcc.io/de/meters/senec-connect-cloud.md
- LoxBerry MQTT-Gateway: https://github.com/christianTF/LoxBerry-Plugin-MQTT-Gateway
- LoxBerry Wiki, Entwickler: https://wiki.loxberry.de/entwickler/start (konnte automatisch nicht abgerufen werden – bitte gegenlesen, v. a. „Plugin-Struktur“, „plugin.cfg“, „PHP-Bibliotheken“, „MQTT für Plugins“)
- LoxBerry-Kern (verifiziert: Installer, PHP-Bibliotheken): https://github.com/mschlenstedt/Loxberry – `sbin/plugininstall.pl`, `libs/phplib/loxberry_system.php`, `loxberry_io.php`, `loxberry_log.php`, `loxberry_web.php`
