# OpenAPI-Registrierung

Die lokale SENEC OpenAPI (Port 4424) braucht einen Token, der **einmalig am Speicher registriert** und danach **im SENEC-Portal freigegeben** werden muss.

## Ablauf

1. Im Reiter **Einstellungen** den Bereich **Lokale OpenAPI** öffnen, Quelle aktivieren, IP eintragen (oder leer lassen = IP von lala.cgi), speichern.
2. Status zeigt **unregistered** → Button **„Am Speicher registrieren“** (Bestätigungsdialog).
   Das Plugin sendet `POST /v1/register` und speichert den zurückgegebenen Token in `state.json`.
3. Status wechselt auf **pending** („Freigabe ausstehend“). Im Portal freigeben:
   <https://mein-senec.de/endkunde/#/0/senec-open-api>
   - Die `0` ist der Anlagen-Index; bei mehreren Anlagen oder nach MCU-Tausch `1`, `2` …
   - Die Seite ist im Portal-Menü nicht verlinkt, nur direkt per URL erreichbar.
4. Die Freigabe kommt über die Cloud ans Gerät. Das Plugin fragt alle 60 s nach; nach der Freigabe wechselt der Status auf **ok**.

## Harte Regeln

Jede Registrierung erzeugt einen **neuen Eintrag im Portal**, die sich später nicht unterscheiden lassen. Deshalb:

- Das Plugin registriert **nur auf Knopfdruck** – nie automatisch in der Abfrageschleife.
- Es registriert **nicht**, solange ein Token gespeichert ist.
- Es läuft nur **eine** Registrierung gleichzeitig (Lock).
- Bei einem **Timeout** während der Registrierung hat das Gerät den Eintrag evtl. trotzdem angelegt → im Portal nachsehen, bevor erneut registriert wird.

## Token ungültig / widerrufen (401)

Der Status wird **rejected**, die Abfrage pausiert. Ein 401 lässt sich nicht von „falscher Token“ unterscheiden.

1. Im Portal prüfen, ob der Eintrag noch freigegeben ist.
2. Nur wenn nötig: **„Token vergessen“** (mit Bestätigung). **Vorher den alten Eintrag im Portal entfernen**, sonst bleiben Karteileichen.
3. Danach neu registrieren.

## Token sichern

Geht `state.json` verloren, ist eine Neuregistrierung nötig (neuer Portal-Eintrag). Beim Plugin-Update wird die Datei automatisch gesichert und wiederhergestellt. Bei manuellen Eingriffen `data/plugins/senec2lox/state.json` mitsichern.
