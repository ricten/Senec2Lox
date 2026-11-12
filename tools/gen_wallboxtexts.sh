#!/bin/bash
# Erzeugt die Tabelle fuer WallboxTexts.php aus ioBroker.senec lib/state_trans.js (MIT, (c) NoBl).
# Aufruf: tools/gen_wallboxtexts.sh <pfad/state_trans.js>   (Ausgabe: die Zeilen "N => 'TEXT'," der Tabelle WALLBOX.STATE.0)
awk '/"WALLBOX.STATE.0"/{f=1;next} f&&/^\t},/{exit} f' "$1" | sed -E "s/^\s*([0-9]+): \"(.*)\",\$/        \1 => '\2',/"
