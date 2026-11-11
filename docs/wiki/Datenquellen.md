# Datenquellen

| # | Quelle | Adresse | Auth | Systeme | Standardtakt |
|---|---|---|---|---|---|
| A | **lala.cgi** (lokal) | `https://<IP>/lala.cgi` | keine | V2, V2.1, V3 | 10 s Live, 60 s Vollabfrage |
| B | **Lokale OpenAPI** | `https://<IP>:4424/v1/…` | Header `X-Auth-Token` | V3, V2/V2.1 ab Firmware 4.4.0 | 60 s |
| C | **SENEC.Connect** (Cloud) | `https://apim-eds-gwc-prod.azure-api.net/senec-connect/v1/…` | Header `Ocp-Apim-Subscription-Key` | alle im SENEC-Konto, **einziger Weg für V4/P4/E4** | 300 s |

Alle drei Quellen sind für Endkunden **nur lesend**; nur die lokale `lala.cgi` nimmt auch Schreibzugriffe an. Das Plugin sendet **nur dann** Steuerbefehle dorthin, wenn sie ausdrücklich freigegeben sind (siehe [Loxone-Anbindung](Loxone-Anbindung.md), Abschnitt Befehle).

## A – lala.cgi

- Zwei Abfragearten: **schnell** (einzelne Schlüssel aus `ENERGY`, `PV1`, `PM1OBJ1`, `PWR_UNIT`, `BMS`) und **langsam** (ganze Sektionen).
- Werte kommen hex-kodiert (`fl_`, `u8_`, `i1_` …) und werden dekodiert. Nicht vorhandene Datenpunkte (`VARIABLE_NOT_FOUND` u. a.) werden dauerhaft übersprungen, `er_…`-Werte vorübergehend.
- Der Request-Body bleibt unter 11 500 Byte (größere Anfragen kommen vom Speicher abgeschnitten zurück); das Plugin teilt bei Bedarf auf.
- HTTPS wird bevorzugt, HTTP nur als Fallback für alte Geräte.
- Vorzeichen: `netz_w` **+ Bezug / − Einspeisung**, `batterie_w` **+ Laden / − Entladen**.

## B – Lokale OpenAPI

- Benötigt Registrierung und Freigabe im Portal: [OpenAPI-Registrierung](OpenAPI-Registrierung.md).
- Liefert inhaltlich nichts, was lala.cgi nicht auch liefert, bringt aber `pv_ueberschuss_w` (`powerSurplus`).
- `meter.gridPower` hat ein ungeklärtes Vorzeichen; das Plugin leitet die Richtung aus der Energiebilanz ab (**⚠ am eigenen Gerät bei Einspeisung prüfen**).
- `battery.state` wird bewusst **nicht** übersetzt (Bedeutung unbekannt).
- `designCapacity` ist die Kapazität **eines Moduls**, nicht des Gesamtspeichers → Topic `nennkapazitaet_modul_wh`.

| Antwort | Status im Plugin |
|---|---|
| 200 | `ok` |
| 511 | `pending` – Freigabe im Portal ausstehend |
| 401 | `rejected` – Token ungültig/widerrufen (keine automatische Neuregistrierung) |
| Verbindung abgelehnt | `unreachable` – Gerät hat keine OpenAPI (V4/P4/E4 oder alte Firmware) |
| kein Token | `unregistered` |

## C – SENEC.Connect

- Antwort ist ein Array aller Anlagen im Konto; `bessNameplate` wird immer mitgesendet, um Anlagen zu unterscheiden.
- Bei mehreren Anlagen: Anlagen-Filter setzen. Ohne Filter werden alle Anlagen unter `senec/connect/<id>/…` veröffentlicht, die erste speist `senec/live/*`.
- Fehler: 401/403 → „Subscription-Key ungültig“ (Abfrage nur noch alle 30 min), 429 → `Retry-After` wird beachtet.

## Quellenauswahl für `senec/live/*`

`senec/live/*` wird aus der **ersten Quelle** laut „Reihenfolge Live-Quellen“ gespeist, deren letzter erfolgreicher Abruf **jünger als 3 × ihr Intervall** ist.
Fällt sie aus, übernimmt die nächste; sobald sie zurück ist, wechselt das Plugin wieder. Die aktive Quelle steht in `senec/live/quelle`.

Welche Werte welche Quelle liefert, zeigt die Tabelle unter [MQTT-Topics](MQTT-Topics.md).
