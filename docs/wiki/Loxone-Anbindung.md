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
