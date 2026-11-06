# MQTT-Topics

Präfix (Standard `senec`). Werte sind reine Zahlen oder Text, kein JSON – das LoxBerry-MQTT-Gateway macht daraus direkt virtuelle Eingänge.

## `senec/live/*` (sprechende Topics)

| Topic | Einheit | A: lala.cgi | B: OpenAPI | C: Connect |
|---|---|---|---|---|
| `pv_w` | W | `ENERGY.GUI_INVERTER_POWER` | `meter.production` | `meter.production` |
| `haus_w` | W | `ENERGY.GUI_HOUSE_POW` | `meter.consumption` | `meter.consumption` |
| `netz_w` (+ Bezug / − Einspeisung) | W | `ENERGY.GUI_GRID_POW` | berechnet | `meter.grid_power` |
| `netz_bezug_w` / `netz_einspeisung_w` | W | aus `netz_w` | aus `netz_w` | aus `netz_w` |
| `batterie_w` (+ Laden / − Entladen) | W | `ENERGY.GUI_BAT_DATA_POWER` | `battery.power` | `battery.power` |
| `batterie_laden_w` / `batterie_entladen_w` | W | aus `batterie_w` | aus `batterie_w` | aus `batterie_w` |
| `batterie_soc` | % | `ENERGY.GUI_BAT_DATA_FUEL_CHARGE` | `battery.stateOfCharge` | `battery.state_of_charge` |
| `batterie_spannung_v` | V | `ENERGY.GUI_BAT_DATA_VOLTAGE` | `battery.voltage` | `battery.voltage` |
| `batterie_strom_a` | A | `ENERGY.GUI_BAT_DATA_CURRENT` | `battery.current` | `battery.current` |
| `pv_ueberschuss_w` | W | – | `meter.powerSurplus` | – |
| `status_code` / `status_text` | – | `ENERGY.STAT_STATE` + Klartext | – | – |
| `netz_frequenz_hz` | Hz | `PM1OBJ1.FREQ` | – | – |
| `phase_l1_w` … `phase_l3_w` | W | `PWR_UNIT.POWER_L1..3` | – | – |
| `temp_batterie_max_c` / `temp_batterie_min_c` | °C | `BMS.TEMP_MAX` / `TEMP_MIN` | – | – |
| `wallbox_w` | W | Summe aus `WALLBOX.APPARENT_CHARGING_POWER` (nur wenn abgefragt) | – | – |
| `wallbox_fahrzeug_verbunden` | 0/1 | `WALLBOX.EV_CONNECTED` (1, wenn an einer Wallbox ein Fahrzeug steckt) | – | – |
| `wallbox_status_code` | – | `WALLBOX.STATE` der ersten Wallbox | – | – |
| `quelle` | – | `lala` | `openapi` | `connect` |

Beispiele für `status_text`: `LADEN (14)`, `ENTLADEN (16)`, `AKKU VOLL (13)`, `EIGENVERBRAUCH (21)`.

## `senec/info/*` (selten aktualisiert)

`modell`, `seriennummer`, `nennkapazitaet_modul_wh`, `module_anzahl`, `firmware`
(die lala-Felder für Modell und Firmware sind **⚠ am Gerät zu bestätigen**).

## Status

| Topic | Bedeutung |
|---|---|
| `senec/online` | `1` Dienst läuft, `0` beendet (Last Will, retained) |
| `senec/lala/ok`, `senec/openapi/ok`, `senec/connect/ok` | `1`/`0` letzte Abfrage erfolgreich |
| `senec/openapi/status` | `ok`, `pending`, `rejected`, `unreachable`, `unregistered` |
| `senec/lastupdate` | Unix-Zeit der letzten erfolgreichen Abfrage |
| `senec/error` | Letzte relevante Warnung (z. B. geänderter Zertifikats-Fingerprint) |

## Rohwerte (Option „Rohwerte veröffentlichen“)

| Quelle | Topic |
|---|---|
| lala.cgi | `senec/raw/<SEKTION>/<KEY>`, Arrays als `…/<KEY>/0`, `/1` … |
| OpenAPI | `senec/openapi/<sektion>/<feld>` |
| Connect | `senec/connect/<anlage>/<sektion>/<feld>` |

Rohwerte werden nur bei Änderung gesendet (die Vollabfrage erzeugt schnell mehrere hundert Topics).
