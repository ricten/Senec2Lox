<?php
/**
 * WALLBOX.STATE -> Klartext (deutsch).
 * Quelle: ioBroker.senec lib/state_trans.js, Tabelle "WALLBOX.STATE.0".
 * Copyright (c) NoBl, MIT-Lizenz (https://github.com/nobl/ioBroker.senec).
 * Erzeugt mit tools/gen_wallboxtexts.sh - nicht von Hand aendern.
 */
final class WallboxTexts
{
    /**
     * Wallbox-Typen (WALLBOX.HW_TYPE), fuer die die Tabelle gilt. Der ioBroker-Adapter unterscheidet nicht nach Modell; hier nur der Typ, an dem die Codes
     * geprueft wurden. Bei anderen Typen veroeffentlicht das Plugin nur die Zahl (wallbox<N>_status_code), keinen Text.
     */
    public const BEKANNTE_TYPEN = [20 => 'SENEC Wallbox pro S'];

    /** Auswahl je Wallbox in den Einstellungen. */
    public const TYPEN = ['auto' => 'automatisch erkennen', 'pro_s' => 'SENEC Wallbox pro S (mit Status-Texten)', 'andere' => 'andere Wallbox (nur Status-Zahl)'];

    /** Soll fuer diese Wallbox ein Status-Text veroeffentlicht werden? auto = nur bei bekanntem HW_TYPE. */
    public static function textFuer(string $einstellung, mixed $hwType): bool
    {
        return match ($einstellung) {
            'pro_s' => true,
            'andere' => false,
            default => self::gilt($hwType),
        };
    }

    public static function gilt(mixed $hwType): bool
    {
        return is_numeric($hwType) && isset(self::BEKANNTE_TYPEN[(int)$hwType]);
    }

    private const TEXTS = [
        0 => 'Ungültiger Zustand (0)',
        161 => 'Nicht verbunden (161)',
        162 => 'Nicht verbunden (162)',
        177 => 'Verbunden (177)',
        178 => 'Bereit (178)',
        194 => 'Lädt (194)',
        195 => 'Lädt mit reduzierter Leistung (hohe Temperatur) (195)',
        196 => 'Lädt mit reduzierter Leistung (Schieflastbegrenzung) (196)',
        224 => 'Deaktiviert (Freigabekontakt) (224)',
        225 => 'Test Production (225)',
        226 => 'Steuereinheit kann programmiert werden (226)',
        227 => 'Warte auf Kommunikation mit SENEC-Speicher (227)',
        241 => 'Kontaktfehler (241)',
        242 => 'Interner Fehler (242)',
        243 => 'Fehlerstrom erkannt (243)',
        244 => 'Kommunikationsfehler Wallbox (244)',
        245 => 'Verriegelungsfehler (245)',
        246 => 'Kabelfehler (246)',
        247 => 'Übertemperatur Fahrzeug (247)',
        248 => 'Kommunikationsfehler Fahrzeug (248)',
        249 => 'Fehler in der Stromversorgung (249)',
        250 => 'Temperatur zu hoch (250)',
        251 => 'Kontaktfehler (251)',
    ];

    public static function text(int $code): string
    {
        return self::TEXTS[$code] ?? "UNBEKANNT ($code)";
    }
}
