<?php
/**
 * Katalog der lala.cgi-Datenpunkte fuer die Auswahl in der Weboberflaeche.
 * Schluessel- und Sektionsnamen nach ioBroker.senec (MIT, (c) NoBl); Bezeichnungen sinngemaess.
 * Nicht alle Datenpunkte gibt es auf jedem Geraet; fehlende werden automatisch uebersprungen.
 */
final class Catalog
{
    /**
     * Schnellabfrage: Kategorie (= Sektion) => [Titel, [KEY => Bezeichnung]].
     * @return array<string,array{0:string,1:array<string,string>}>
     */
    public static function fastKeys(): array
    {
        return [
            'ENERGY' => ['Energie & Live-Leistung', [
                'STAT_STATE' => 'Systemstatus (Code)',
                'GUI_INVERTER_POWER' => 'PV-Leistung (W)',
                'GUI_HOUSE_POW' => 'Hausverbrauch (W)',
                'GUI_GRID_POW' => 'Netzleistung (W, + Bezug / - Einspeisung)',
                'GUI_BAT_DATA_POWER' => 'Batterieleistung (W, + Laden / - Entladen)',
                'GUI_BAT_DATA_FUEL_CHARGE' => 'Ladestand (%)',
                'GUI_BAT_DATA_VOLTAGE' => 'Batteriespannung (V)',
                'GUI_BAT_DATA_CURRENT' => 'Batteriestrom (A)',
                'GUI_CHARGING_INFO' => 'Lade-Info',
                'GUI_BOOSTING_INFO' => 'Boost-Info',
                'STAT_LIMITED_NET_SKEW' => 'Netz-Schieflastbegrenzung',
                'SAFE_CHARGE_FORCE' => 'Sicherheitsladung erzwungen',
                'SAFE_CHARGE_PROHIBIT' => 'Sicherheitsladung verboten',
                'SAFE_CHARGE_RUNNING' => 'Sicherheitsladung laeuft',
                'LI_STORAGE_MODE_RUNNING' => 'Lager-/Modultausch-Modus laeuft',
            ]],
            'PV1' => ['PV-Generator', [
                'POWER_RATIO' => 'PV-Leistungsverhaeltnis',
                'MPP_POWER' => 'MPP-Leistung je Tracker (W)',
            ]],
            'PM1OBJ1' => ['Netzmessung', [
                'FREQ' => 'Netzfrequenz (Hz)',
                'U_AC' => 'Spannung je Phase (V)',
                'I_AC' => 'Strom je Phase (A)',
                'P_AC' => 'Leistung je Phase (W)',
                'P_TOTAL' => 'Gesamtleistung (W)',
            ]],
            'PWR_UNIT' => ['Leistung je Phase (Wechselrichter)', [
                'POWER_L1' => 'Leistung L1 (W)',
                'POWER_L2' => 'Leistung L2 (W)',
                'POWER_L3' => 'Leistung L3 (W)',
            ]],
            'BMS' => ['Batteriemanagement', [
                'SOC' => 'Ladestand Zellen (%)',
                'SYSTEM_SOC' => 'Ladestand System (%)',
                'VOLTAGE' => 'Spannung (V)',
                'CURRENT' => 'Strom (A)',
                'TEMP_MAX' => 'Temperatur max. (Grad C)',
                'TEMP_MIN' => 'Temperatur min. (Grad C)',
                'MODULE_COUNT' => 'Anzahl Batteriemodule',
                'CELL_TEMPERATURES_MODULE_A' => 'Zelltemperaturen Modul A',
                'CELL_TEMPERATURES_MODULE_B' => 'Zelltemperaturen Modul B',
                'CELL_TEMPERATURES_MODULE_C' => 'Zelltemperaturen Modul C',
                'CELL_TEMPERATURES_MODULE_D' => 'Zelltemperaturen Modul D',
                'CELL_VOLTAGES_MODULE_A' => 'Zellspannungen Modul A',
                'CELL_VOLTAGES_MODULE_B' => 'Zellspannungen Modul B',
                'CELL_VOLTAGES_MODULE_C' => 'Zellspannungen Modul C',
                'CELL_VOLTAGES_MODULE_D' => 'Zellspannungen Modul D',
            ]],
            'WALLBOX' => ['Wallbox (je Wallbox ein Wert, Index 0-3)', [
                'STATE' => 'Wallbox-Status (Code)',
                'EV_CONNECTED' => 'Fahrzeug verbunden',
                'APPARENT_CHARGING_POWER' => 'Ladeleistung (W)',
                'L1_CHARGING_CURRENT' => 'Ladestrom L1 (A)',
                'L2_CHARGING_CURRENT' => 'Ladestrom L2 (A)',
                'L3_CHARGING_CURRENT' => 'Ladestrom L3 (A)',
                'SMART_CHARGE_ACTIVE' => 'Smart-Charge aktiv',
                'PROHIBIT_USAGE' => 'Nutzung gesperrt',
                'ALLOW_INTERCHARGE' => 'Interchargen erlaubt',
                'MIN_CHARGING_CURRENT' => 'Min. Ladestrom (A)',
                'MAX_CHARGING_CURRENT_RATED' => 'Nennladestrom max. (A)',
                'MAX_CHARGING_CURRENT_DEFAULT' => 'Standard-Ladestrom max. (A)',
                'MAX_CHARGING_CURRENT_IC' => 'Max. Ladestrom Intercharge (A)',
                'MAX_CHARGING_CURRENT_ICMAX' => 'Max. Ladestrom ICMAX (A)',
                'SET_ICMAX' => 'Eingestellter ICMAX (A)',
                'SET_IDEFAULT' => 'Eingestellter Standard-Ladestrom (A)',
                'MAX_PHASE_CURRENT_BY_GRID' => 'Max. Phasenstrom durch Netz (A)',
                'MAX_TOTAL_CURRENT_BY_GRID' => 'Max. Gesamtstrom durch Netz (A)',
                'NUMBER_OF_PHASE' => 'Anzahl Phasen',
                'PHASES_USED' => 'Genutzte Phasen',
                'L1_USED' => 'L1 genutzt',
                'L2_USED' => 'L2 genutzt',
                'L3_USED' => 'L3 genutzt',
                'LOAD_IMBALANCE_DETECTED' => 'Schieflast erkannt',
                'LOAD_IMBALANCE_ENABLED' => 'Schieflast-Erkennung aktiv',
                'METER_ENABLED' => 'Zaehler aktiv',
                'SOCKET_ENABLED' => 'Steckdose aktiv',
                'CS_ENABLED' => 'CS aktiv',
                'UTMP' => 'Temperatur (Grad C)',
                'ERROR_DETAILS' => 'Fehlerdetails',
                'ADDITIONAL_ERROR' => 'Zusatzfehler',
            ]],
        ];
    }

    /**
     * Vollabfrage: Kategorie => [Titel, [SEKTION => Bezeichnung]]. STATISTIC fehlt absichtlich (von SENEC abgeschaltet).
     * @return array<string,array{0:string,1:array<string,string>}>
     */
    public static function fullSections(): array
    {
        return [
            'leistung' => ['Energie & Leistung', [
                'ENERGY' => 'Energiefluesse und Status',
                'PV1' => 'PV-Generator',
                'PM1' => 'Netzmessung (allgemein)',
                'PM1OBJ1' => 'Netzmessung 1',
                'PM1OBJ2' => 'Netzmessung 2',
                'PWR_UNIT' => 'Leistung je Phase',
                'GRIDCONFIG' => 'Netzkonfiguration',
            ]],
            'batterie' => ['Batterie', [
                'BMS' => 'Batteriemanagement',
                'BMS_PARA' => 'BMS-Parameter',
                'BMZ_CURRENT_LIMITS' => 'Strombegrenzungen Batteriemodule',
                'BAT1' => 'Batterie 1',
                'BAT1OBJ1' => 'Batterie 1 Objekt',
                'AMPACE' => 'Ampace-Zellen',
                'CASC' => 'Kaskade',
                'CELL_DEVIATION_ROC' => 'Zellabweichung',
                'CURRENT_IMBALANCE_CONTROL' => 'Stromunsymmetrie-Regelung',
            ]],
            'temperatur' => ['Temperatur & Luefter', [
                'TEMPMEASURE' => 'Temperaturmessung',
                'FAN_SPEED' => 'Luefterdrehzahl',
                'FAN_TEST' => 'Luefter-Test',
            ]],
            'geraet' => ['Geraet & System', [
                'FACTORY' => 'Werksdaten (Modell, Seriennummer)',
                'SYS_UPDATE' => 'Systemupdate / Firmware',
                'UPDATE' => 'Update',
                'WIZARD' => 'Einrichtungsassistent',
                'FEATURES' => 'Funktionen',
                'RTC' => 'Uhr',
                'DISPLAY' => 'Display',
                'IPU' => 'Wechselrichter-Einheit',
                'STECA' => 'Steca-Komponenten',
            ]],
            'zubehoer' => ['Ein-/Ausgaenge & Zubehoer', [
                'SENEC_IO_INPUT' => 'SENEC.IO Eingaenge',
                'SENEC_IO_OUTPUT' => 'SENEC.IO Ausgaenge',
                'SOCKETS' => 'Steckdosen',
                'WALLBOX' => 'Wallbox',
            ]],
            'diagnose' => ['Diagnose', [
                'DEBUG' => 'Debug',
                'LOG' => 'Log',
                'TEST' => 'Test',
                'SELFTEST_RESULTS' => 'Selbsttest-Ergebnisse',
                'FILE' => 'Dateien',
            ]],
        ];
    }

    /** Alle Schnellabfrage-Schluessel als "SEK.KEY". */
    public static function fastFlat(): array
    {
        $out = [];
        foreach (self::fastKeys() as $sec => [$title, $keys]) {
            foreach (array_keys($keys) as $k) {
                $out[] = "$sec.$k";
            }
        }
        return $out;
    }

    public static function fullFlat(): array
    {
        $out = [];
        foreach (self::fullSections() as [$title, $secs]) {
            foreach (array_keys($secs) as $s) {
                $out[] = $s;
            }
        }
        return $out;
    }
}
