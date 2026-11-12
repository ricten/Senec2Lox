# Loxone-Anbindung

Der Weg: **Senec2Lox → LoxBerry-MQTT-Broker → LoxBerry-MQTT-Gateway → Miniserver (virtuelle Eingänge)**.

## 1. MQTT-Gateway einrichten

Im Plugin **MQTT Gateway** das Abonnement ergänzen:

- `senec/live/#` (empfohlen) und `senec/online`
- oder `senec/#` für alles inklusive Rohwerte (sehr viele Topics)

**Gateway V2:** Das Abonnement muss von Hand im MQTT Gateway unter „Abonnements“ eingetragen werden. Die Abo-Datei des Plugins (`config/mqtt_subscriptions.cfg`, `senec/#`) liest nur das Gateway V1. Das Plugin prüft es selbst: In den Reitern „Einbindung in Loxone“ und „Test“ steht, ob das Abo eingetragen ist. Ändert man den Topic-Präfix, muss das Abo angepasst werden.

## 2. Virtuelle Eingänge in Loxone Config

Das Gateway ersetzt `/` durch `_`. Aus `senec/live/pv_w` wird der Eingang `senec_live_pv_w`.
Typische Eingänge:

| Eingang | Bedeutung |
|---|---|
| `senec_live_pv_w` | PV-Erzeugung (W) |
| `senec_live_haus_w` | Hausverbrauch (W) |
| `senec_live_netz_bezug_w` / `senec_live_netz_einspeisung_w` | Netzbezug / Einspeisung (W, immer ≥ 0) |
| `senec_live_batterie_soc` | Ladestand (%) |
| `senec_live_batterie_laden_w` / `senec_live_batterie_entladen_w` | Laden / Entladen (W, immer ≥ 0) |
| `senec_online` | Plugin-Dienst läuft (1/0) |
| `senec_status_ok` | 1 = aktuelle Live-Werte vorhanden, 0 = keine Quelle liefert |
| `senec_status_zaehler` | Lebenszeichen: zählt 0…999 weiter (im Gateway *No Cache*; steht er still, ist der Dienst ausgefallen) |
| `senec_status_ts` | Unix-Zeit des letzten Durchgangs |

Hinweise:

- Für die Anzeige „Bezug/Einspeisung“ und „Laden/Entladen“ eignen sich die getrennten, immer positiven Topics besser als `netz_w`/`batterie_w` mit Vorzeichen.
- Mit `senec_online` kann ein Ausfall des Dienstes im Miniserver überwacht werden. `senec_status_zaehler` ist das genauere Lebenszeichen: Bleibt der Zähler stehen, läuft der Dienst nicht mehr (auch wenn der Broker noch `online = 1` meldet).
- `senec_live_quelle` zeigt, aus welcher Quelle gerade gespeist wird (Text).
- Mit „retain“ liefert der Broker nach einem Neustart sofort den letzten Wert.


## 3. Befehle aus Loxone (Steuerung)

**Ab Werk gesperrt.** Im Reiter *Einstellungen* → *Steuerung* erst den Warnhinweis bestätigen, dann die gewünschten Gruppen freigeben (Zwangsladung, Steckdosen, Wallbox). Ohne bestätigten Warnhinweis bleibt alles gesperrt. Die Befehle ändern Einstellungen am Speicher über die lokale, nicht dokumentierte Schnittstelle `lala.cgi` (dieselbe, die der ioBroker-Adapter nutzt); SENEC unterstützt das nicht, und es gibt keinen Schutz vor widersprüchlichen Befehlen aus mehreren Quellen (Loxone, App, mein-senec.de). **Nutzung auf eigene Gefahr.**

Das Plugin abonniert `senec/befehl/#` (der Präfix folgt dem Topic-Präfix). Virtueller Ausgang in Loxone: Adresse `/dev/udp/<LoxBerry-IP>/<UDP-Port>` (Standard 11884, steht im Plugin im Reiter „Einbindung in Loxone“), Befehl bei EIN `publish senec/befehl/<befehl> <wert>` (ohne HTTP-Header/Body). Die Quittung steht unter `<topic>/quittung`: zuerst `angenommen`, dann `ausgefuehrt` oder `abgelehnt: <Grund>` (nie retained). Höchstens ein Befehl alle 2 Sekunden.

| Befehl | Wert | Wirkung |
|---|---|---|
| `senec/befehl/zwangsladung` | 1 / 0 | Zwangsladung der Batterie an / aus (`SAFE_CHARGE_FORCE` bzw. `SAFE_CHARGE_PROHIBIT`). Der Zustand steht in `senec/live/zwangsladung` (1 bei Status 8 und 9) und `senec/live/status_text` |
| `senec/befehl/entladung_freigeben` | 1 | Entladung freigeben wie „Entladen freigeben“ auf der Wartungsseite des Speichers: beendet die Vollladung und den Lager-/Modultausch-Modus, wenn sie laufen; Zustand in `senec/live/entladung_gesperrt` und `senec/live/lagermodus` |
| `senec/befehl/steckdose<N>/modus` | aus / automatik / dauer_ein | Betriebsart der Steckdose N |
| `senec/befehl/steckdose<N>/einschaltschwelle_w`, `…/abschaltschwelle_w` | 0–20000 | Schwellen in Watt |
| `senec/befehl/steckdose<N>/ueberschuss_min`, `…/einschaltdauer_min` | 0–1440 | Dauer des Leistungsüberschusses bzw. Einschaltdauer in Minuten |
| `senec/befehl/steckdose<N>/stunde`, `…/minute` | 0–23, 0–59 | Einschaltzeit (die Zeitschaltung gilt nur im Modus automatik) |
| `senec/befehl/wallbox<N>/ladestrom_max_a`, `…/ladestrom_standard_a`, `…/ladestrom_min_a` | 6 bis Nennstrom | Ladeströme in Ampere, höchstens bis zum Nennstrom der Wallbox (bei der SENEC Wallbox pro S 16 A) |
| `senec/befehl/wallbox<N>/smart_laden`, `…/zwischenladen` | 1 / 0 | Smart-Laden an/aus (der Speicher regelt den Ladestrom dynamisch); Zwischenladen aus der Batterie erlauben |
| `senec/befehl/wallbox<N>/sperre` | 1 / 0 | Wallbox sperren (kein Fahrzeug lädt) / freigeben; der Zustand steht in `senec/live/wallbox<N>_gesperrt` |

`<N>` ist die Nummer der Steckdose bzw. Wallbox ab 1 (der Speicher unterstützt bis zu vier Wallboxen). Nach dem Schreiben liest das Plugin die Werte zurück und quittiert `ausgefuehrt` nur, wenn der Speicher sie übernommen hat; sonst `abgelehnt: Der Speicher hat den Wert nicht übernommen`. Weitere Gründe: gesperrt (Warnhinweis oder Gruppe), ungültiger Wert, zu schnell, die Steckdose/Wallbox gibt es nicht, lokale Quelle nicht eingerichtet, Speicher nicht erreichbar, der Speicher kennt die Funktion nicht.

Für freigegebene Steckdosen und Wallbox liest das Plugin zusätzlich die Abschnitte `SOCKETS` und `WALLBOX`; ihre Einstellungen stehen (bei aktiver Option „Rohwerte veröffentlichen“) unter `senec/raw/SOCKETS/…` und `senec/raw/WALLBOX/…`.

Geprüft sind an einem echten Speicher die **Zwangsladung** (an und wieder aus, der Zustand folgte mit Status 8 und zurück) und die **Steckdosen** (Schwellen, Zeiten und die Modi aus, automatik und dauer_ein, jeweils mit Zurücklesen; dauer_ein für je etwa 10 Sekunden, danach wieder aus und im Ausgangszustand). Beim Einschalten kann der Speicher kurz langsam antworten; eine Abfrage in dieser Zeit lief einmal in die Zeitüberschreitung. Die **Wallbox** wurde am echten Speicher mit Smart-Laden, Zwischenladen, kleinstem und höchstem Ladestrom geprüft (mit Zurücklesen, danach wieder im Ausgangszustand). Dabei zeigte sich: Den höchsten Ladestrom übernimmt der Speicher erst nach etwa 8 Sekunden, das Plugin wartet darauf bis zu 16 Sekunden, bevor es quittiert. Der **Standard-Ladestrom** (`ladestrom_standard_a`) wurde mit 15 A statt 16 A auch nach 60 Sekunden nicht übernommen; das Plugin quittiert das ehrlich mit „abgelehnt: Der Speicher hat den Wert nicht übernommen“.

Die **Sperre** (`wallbox<N>/sperre`) wurde am echten Speicher geprüft: Sie wird in etwa einer Sekunde übernommen. Beim Test setzte der Speicher nach dem Freigeben den höchsten Ladestrom (`SET_ICMAX`) von 16 A auf 0 (ohne Fahrzeug); er blieb auf 0, bis er neu gesetzt wurde (`wallbox<N>/ladestrom_max_a`, rund 8 Sekunden bis zur Übernahme). Ob das mit angeschlossenem Fahrzeug anders ist, ist nicht geprüft.

**Nicht enthalten:** Reboot des Speichers sowie Notstromreserve, Peak-Shaving und SG-Ready. Diese gibt es nur über mein-senec.de mit Anmeldung am SENEC-Konto.
