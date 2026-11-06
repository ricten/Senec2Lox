#!/bin/bash
# Erzeugt StateTexts.php aus ioBroker.senec lib/state_trans.js (MIT, (c) NoBl).
# Aufruf: tools/gen_statetexts.sh <pfad/state_trans.js> > senec2lox/bin/lib/StateTexts.php
echo "Hinweis: Skript dokumentiert die Erzeugung; Tabelle ENERGY.STAT_STATE.0 (Zeilen 'N: \"TEXT\",') extrahieren." >&2
awk '/"ENERGY.STAT_STATE.0"/{f=1;next} f&&/^\t},/{exit} f' "$1" | sed -E "s/^\s*([0-9]+): \"(.*)\",\$/        \1 => '\2',/"
