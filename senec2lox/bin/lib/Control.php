<?php
/**
 * Steuerbefehle aus Loxone fuer den SENEC-Speicher (lokal ueber lala.cgi). Ab Werk gesperrt.
 *
 * Gruppen (je einzeln freizugeben, dazu der bestaetigte Warnhinweis):
 *   zwangsladung  Zwangsladung der Batterie an/aus (ENERGY.SAFE_CHARGE_FORCE / SAFE_CHARGE_PROHIBIT)
 *   steckdosen    schaltbare Steckdosen: Modus, Schwellen, Zeiten (SOCKETS.*)
 *   wallbox       Wallbox: Ladestrome, Smart-Laden, Zwischenladen (WALLBOX.*)
 * Die Anfragen entsprechen denen des ioBroker-Adapters (ioBroker.senec, MIT, (c) NoBl). Notstromreserve, Peak-Shaving und SG-Ready gibt es
 * nur ueber mein-senec.de (Konto-Anmeldung) und sind nicht enthalten.
 *
 * Befehls-Topics: <prefix>/befehl/zwangsladung | steckdose<N>/<feld> | wallbox<N>/<feld>, Quittung unter <topic>/quittung.
 */
final class Control
{
    public const GRUPPEN = ['zwangsladung' => 'Zwangsladung', 'steckdosen' => 'Steckdosen', 'wallbox' => 'Wallbox'];
    public const MIN_ABSTAND = 2;          // Sekunden zwischen zwei Steuerbefehlen
    public const WALLBOX_FELDER = 4;       // Laenge der Arrays im WALLBOX-Objekt

    public const BATTERY_ON = '{"ENERGY":{"SAFE_CHARGE_FORCE":"u8_01","SAFE_CHARGE_PROHIBIT":"","SAFE_CHARGE_RUNNING":"","LI_STORAGE_MODE_START":"","LI_STORAGE_MODE_STOP":"","LI_STORAGE_MODE_RUNNING":"","STAT_STATE":""}}';
    public const BATTERY_OFF = '{"ENERGY":{"SAFE_CHARGE_FORCE":"","SAFE_CHARGE_PROHIBIT":"u8_01","SAFE_CHARGE_RUNNING":"","LI_STORAGE_MODE_START":"","LI_STORAGE_MODE_STOP":"","LI_STORAGE_MODE_RUNNING":"","STAT_STATE":""}}';

    /** Felder der Steckdosen: name => [Art, Minimum, Maximum, Einheit/Text] */
    public const STECKDOSE = [
        'modus' => 'Modus: aus, automatik oder dauer_ein',
        'einschaltschwelle_w' => 'Einschaltschwelle in Watt Ueberschuss (0 bis 20000)',
        'abschaltschwelle_w' => 'Abschaltschwelle in Watt (0 bis 20000)',
        'ueberschuss_min' => 'Dauer des Leistungsueberschusses in Minuten (0 bis 1440)',
        'einschaltdauer_min' => 'Dauer, die die Steckdose an bleibt, in Minuten (0 bis 1440)',
        'stunde' => 'Einschaltzeit, Stunde (0 bis 23)',
        'minute' => 'Einschaltzeit, Minute (0 bis 59)',
    ];
    public const WALLBOX = [
        'ladestrom_max_a' => 'hoechster Ladestrom in Ampere (6 bis 32)',
        'ladestrom_standard_a' => 'Standard-Ladestrom in Ampere (6 bis 32)',
        'ladestrom_min_a' => 'kleinster Ladestrom in Ampere (6 bis 32)',
        'smart_laden' => 'Smart-Laden: der Speicher regelt den Ladestrom dynamisch (1 an, 0 aus)',
        'sperre' => 'Wallbox sperren (1) oder freigeben (0): gesperrt lädt kein Fahrzeug',
        'zwischenladen' => 'Zwischenladen aus der Batterie erlauben (1 ja, 0 nein)',
    ];

    // ------------------------------------------------------------------ Hilfen fuer die Werte des Speichers

    public static function hexU8(int $v): string
    {
        return 'u8_' . strtoupper(str_pad(dechex(max(0, min(255, $v))), 2, '0', STR_PAD_LEFT));
    }

    public static function hexU16(int $v): string
    {
        return 'u1_' . strtoupper(str_pad(dechex(max(0, min(65535, $v))), 4, '0', STR_PAD_LEFT));
    }

    public static function hexFloat(float $v): string
    {
        return 'fl_' . strtoupper(bin2hex(pack('G', $v)));
    }

    /** Body fuer SOCKETS: je Schluessel ein Array mit dem Wert an der Stelle des Geraets und leeren Texten sonst. */
    public static function steckdosenBody(int $anzahl, int $idx0, array $felder): string
    {
        $o = [];
        foreach ($felder as $key => $val) {
            $a = array_fill(0, $anzahl, '');
            $a[$idx0] = $val;
            $o[$key] = $a;
        }
        return (string)json_encode(['SOCKETS' => $o]);
    }

    /** Body fuer WALLBOX (Arrays immer mit vier Stellen, wie im ioBroker-Adapter). */
    public static function wallboxBody(int $idx0, array $felder): string
    {
        $o = [];
        foreach ($felder as $key => $val) {
            $a = array_fill(0, self::WALLBOX_FELDER, '');
            $a[$idx0] = $val;
            $o[$key] = $a;
        }
        return (string)json_encode(['WALLBOX' => $o]);
    }

    // ------------------------------------------------------------------ Befehlsnamen

    /**
     * Topic eines Befehls zerlegen.
     * @return array{gruppe:string,idx:int,feld:string,name:string}|null  idx 1-basiert (0 bei Zwangsladung); null = kein Befehlstopic
     */
    public static function parseTopic(string $prefix, string $topic): ?array
    {
        $p = preg_quote($prefix . '/befehl/', '#');
        if (preg_match('#^' . $p . 'zwangsladung$#', $topic)) {
            return ['gruppe' => 'zwangsladung', 'idx' => 0, 'feld' => '', 'name' => 'zwangsladung'];
        }
        if (preg_match('#^' . $p . '(steckdose|wallbox)([1-9][0-9]?)/([a-z0-9_]+)$#', $topic, $m)) {
            return ['gruppe' => $m[1] === 'steckdose' ? 'steckdosen' : 'wallbox', 'idx' => (int)$m[2], 'feld' => $m[3], 'name' => $m[1] . $m[2] . '/' . $m[3]];
        }
        return null;
    }

    private static function ganzzahl(string $w, int $min, int $max): ?int
    {
        return preg_match('/^\d{1,6}$/', $w) && (int)$w >= $min && (int)$w <= $max ? (int)$w : null;
    }

    private static function strom(string $w): ?float
    {
        $w = str_replace(',', '.', trim($w));
        return preg_match('/^\d{1,2}(\.\d{1,2})?$/', $w) && (float)$w >= 6.0 && (float)$w <= 32.0 ? (float)$w : null;
    }

    private static function schalter(string $w): ?int
    {
        return $w === '1' ? 1 : ($w === '0' ? 0 : null);
    }

    /**
     * Wert eines Steckdosen-Befehls pruefen und in die Schluessel des Speichers umsetzen.
     * @return array{felder:array<string,string>,fehler:string}  felder: SOCKETS-Schluessel => kodierter Wert
     */
    public static function steckdoseFelder(string $feld, string $wert): array
    {
        $w = trim($wert);
        $fehl = fn(string $t) => ['felder' => [], 'fehler' => $t];
        switch ($feld) {
            case 'modus':
                $m = strtolower($w);
                if (!in_array($m, ['aus', 'automatik', 'dauer_ein'], true)) {
                    return $fehl('ungültiger Wert (aus, automatik oder dauer_ein)');
                }
                return ['felder' => ['ENABLE' => self::hexU8($m === 'aus' ? 0 : 1), 'FORCE_ON' => self::hexU8($m === 'dauer_ein' ? 1 : 0)], 'fehler' => ''];
            case 'einschaltschwelle_w':
            case 'abschaltschwelle_w':
                $v = self::ganzzahl($w, 0, 20000);
                return $v === null ? $fehl('ungültiger Wert (0 bis 20000 Watt)')
                    : ['felder' => [$feld === 'einschaltschwelle_w' ? 'UPPER_LIMIT' : 'LOWER_LIMIT' => self::hexU16($v)], 'fehler' => ''];
            case 'ueberschuss_min':
            case 'einschaltdauer_min':
                $v = self::ganzzahl($w, 0, 1440);
                return $v === null ? $fehl('ungültiger Wert (0 bis 1440 Minuten)')
                    : ['felder' => [$feld === 'ueberschuss_min' ? 'POWER_ON_TIME' : 'TIME_LIMIT' => self::hexU16($v)], 'fehler' => ''];
            case 'stunde':
                $v = self::ganzzahl($w, 0, 23);
                return $v === null ? $fehl('ungültiger Wert (0 bis 23)') : ['felder' => ['SWITCH_ON_HOUR' => self::hexU8($v)], 'fehler' => ''];
            case 'minute':
                $v = self::ganzzahl($w, 0, 59);
                return $v === null ? $fehl('ungültiger Wert (0 bis 59)') : ['felder' => ['SWITCH_ON_MINUTE' => self::hexU8($v)], 'fehler' => ''];
        }
        return $fehl('unbekannter Befehl');
    }

    /** @return array{felder:array<string,string>,fehler:string} */
    public static function wallboxFelder(string $feld, string $wert): array
    {
        $w = trim($wert);
        $fehl = fn(string $t) => ['felder' => [], 'fehler' => $t];
        switch ($feld) {
            case 'ladestrom_max_a':
            case 'ladestrom_standard_a':
            case 'ladestrom_min_a':
                $v = self::strom($w);
                if ($v === null) {
                    return $fehl('ungültiger Wert (6 bis 32 Ampere)');
                }
                $key = ['ladestrom_max_a' => 'SET_ICMAX', 'ladestrom_standard_a' => 'SET_IDEFAULT', 'ladestrom_min_a' => 'MIN_CHARGING_CURRENT'][$feld];
                return ['felder' => [$key => self::hexFloat($v)], 'fehler' => ''];
            case 'smart_laden':
                $v = self::schalter($w);
                return $v === null ? $fehl('ungültiger Wert (1 oder 0)') : ['felder' => ['SMART_CHARGE_ACTIVE' => $v ? 'u8_03' : 'u8_00'], 'fehler' => ''];
            case 'zwischenladen':
                $v = self::schalter($w);
                return $v === null ? $fehl('ungültiger Wert (1 oder 0)') : ['felder' => ['ALLOW_INTERCHARGE' => self::hexU8($v)], 'fehler' => ''];
            case 'sperre':
                $v = self::schalter($w);
                return $v === null ? $fehl('ungültiger Wert (1 sperren oder 0 freigeben)') : ['felder' => ['PROHIBIT_USAGE' => self::hexU8($v)], 'fehler' => ''];
        }
        return $fehl('unbekannter Befehl');
    }

    // ------------------------------------------------------------------ Ausfuehrung

    private float $letzter = 0.0;

    public function __construct(private readonly array $cfg, private readonly ?LalaSource $lala, private readonly Logger $log)
    {
    }

    /** Ist die Gruppe freigegeben? (Warnhinweis bestaetigt und Gruppe eingeschaltet) */
    public static function wirdFrei(array $cfg, string $gruppe): bool
    {
        return !empty($cfg['control']['warnung_ok']) && !empty($cfg['control'][$gruppe]);
    }

    public function frei(string $gruppe): bool
    {
        return self::wirdFrei($this->cfg, $gruppe);
    }

    /**
     * Sektionen, die zusaetzlich abgefragt werden, damit Loxone die Einstellungen sieht (nur freigegebene Gruppen).
     * @return list<string>
     */
    public static function zusatzSektionen(array $cfg): array
    {
        $c = $cfg['control'] ?? [];
        if (empty($c['warnung_ok'])) {
            return [];
        }
        $out = [];
        if (!empty($c['steckdosen'])) {
            $out[] = 'SOCKETS';
        }
        if (!empty($c['wallbox'])) {
            $out[] = 'WALLBOX';
        }
        return $out;
    }

    /** Anzahl der Geraete (Steckdosen bzw. Wallboxen): aus dem letzten Abruf, sonst gezielt gelesen. */
    private function anzahl(string $sec, string $key): ?int
    {
        $n = $this->lala->wert($sec, $key);
        if (!is_numeric($n)) {
            $r = $this->lala->lesen($sec, [$key]);
            $n = $r[$key] ?? null;
        }
        return is_numeric($n) ? (int)$n : null;
    }

    /**
     * Befehl ausfuehren. Gibt die Quittung zurueck: "ausgefuehrt" oder "abgelehnt: <Grund>". ($sofortAngenommen wird vorher vom Aufrufer gesendet.)
     */
    public function ausfuehren(array $b, string $wert): string
    {
        if (!$this->frei($b['gruppe'])) {
            return empty($this->cfg['control']['warnung_ok'])
                ? 'abgelehnt: Befehle sind gesperrt (Warnhinweis im Plugin noch nicht bestätigt)'
                : 'abgelehnt: Befehle sind gesperrt (Gruppe ' . self::GRUPPEN[$b['gruppe']] . ' im Plugin nicht freigegeben)';
        }
        if ($this->lala === null) {
            return 'abgelehnt: Die lokale Quelle (lala.cgi) ist nicht eingerichtet';
        }
        if (microtime(true) - $this->letzter < self::MIN_ABSTAND) {
            return 'abgelehnt: zu schnell (höchstens ein Befehl alle ' . self::MIN_ABSTAND . ' Sekunden)';
        }
        $this->letzter = microtime(true);
        return match ($b['gruppe']) {
            'zwangsladung' => $this->zwangsladung($wert),
            'steckdosen' => $this->steckdose($b['idx'], $b['feld'], $wert),
            default => $this->wallbox($b['idx'], $b['feld'], $wert),
        };
    }

    private function zwangsladung(string $wert): string
    {
        $w = trim($wert);
        if ($w !== '1' && $w !== '0') {
            return 'abgelehnt: ungültiger Wert (1 oder 0)';
        }
        $r = $this->lala->senden($w === '1' ? self::BATTERY_ON : self::BATTERY_OFF);
        if (!$r['ok']) {
            return 'abgelehnt: ' . $r['error'];
        }
        // Das Gerät kennt die Funktion nicht, wenn es die Schluessel als nicht vorhanden meldet
        $key = $w === '1' ? 'SAFE_CHARGE_FORCE' : 'SAFE_CHARGE_PROHIBIT';
        if (isset($r['raw']['ENERGY'][$key]) && is_string($r['raw']['ENERGY'][$key]) && SenecDecode::isAbsent($r['raw']['ENERGY'][$key])) {
            return 'abgelehnt: Der Speicher kennt die Zwangsladung nicht (' . $key . ' fehlt)';
        }
        $this->log->info('Zwangsladung ' . ($w === '1' ? 'eingeschaltet' : 'ausgeschaltet') . ' (Befehl aus MQTT)');
        return 'ausgefuehrt';
    }

    private function steckdose(int $nr, string $feld, string $wert): string
    {
        $p = self::steckdoseFelder($feld, $wert);
        if ($p['fehler'] !== '') {
            return 'abgelehnt: ' . $p['fehler'];
        }
        $n = $this->anzahl('SOCKETS', 'NUMBER_OF_SOCKETS');
        if ($n === null) {
            return 'abgelehnt: Die Anzahl der Steckdosen ließ sich nicht lesen';
        }
        if ($n < 1) {
            return 'abgelehnt: Der Speicher hat keine schaltbaren Steckdosen';
        }
        if ($nr > $n) {
            return "abgelehnt: Steckdose $nr gibt es nicht (der Speicher hat $n)";
        }
        $i = $nr - 1;
        $felder = $p['felder'];
        if ($feld === 'stunde' || $feld === 'minute') {
            // Die Zeitschaltung ist nur im Automatik-Modus aktiv und nur mit einer Zeit > 0:00: USE_TIME wie im ioBroker-Adapter nachfuehren
            $cur = $this->lala->lesen('SOCKETS', ['SWITCH_ON_HOUR', 'SWITCH_ON_MINUTE', 'ENABLE', 'FORCE_ON']);
            if ($cur === null) {
                return 'abgelehnt: Die aktuellen Einstellungen der Steckdose ließen sich nicht lesen';
            }
            $h = (int)($cur['SWITCH_ON_HOUR'][$i] ?? 0);
            $m = (int)($cur['SWITCH_ON_MINUTE'][$i] ?? 0);
            if ($feld === 'stunde') {
                $h = (int)trim($wert);
            } else {
                $m = (int)trim($wert);
            }
            $automatik = !empty($cur['ENABLE'][$i]) && empty($cur['FORCE_ON'][$i]);
            $felder['USE_TIME'] = self::hexU8($automatik && ($h > 0 || $m > 0) ? 1 : 0);
        }
        return $this->schreibenUndPruefen('SOCKETS', self::steckdosenBody($n, $i, $felder), $i, $felder, "Steckdose $nr");
    }

    private function wallbox(int $nr, string $feld, string $wert): string
    {
        $p = self::wallboxFelder($feld, $wert);
        if ($p['fehler'] !== '') {
            return 'abgelehnt: ' . $p['fehler'];
        }
        $n = $this->anzahl('WIZARD', 'SETUP_NUMBER_WALLBOXES');
        if ($n === null) {
            return 'abgelehnt: Die Anzahl der Wallboxen ließ sich nicht lesen';
        }
        if ($n < 1) {
            return 'abgelehnt: Am Speicher ist keine Wallbox eingerichtet';
        }
        if ($nr > $n || $nr > self::WALLBOX_FELDER) {
            return "abgelehnt: Wallbox $nr gibt es nicht (der Speicher hat $n)";
        }
        // Die Ladestroeme uebernimmt der Speicher erst nach etwa 8 Sekunden (am echten Speicher gemessen): laenger auf die Bestaetigung warten
        $strom = isset($p['felder']['SET_ICMAX']) || isset($p['felder']['SET_IDEFAULT']) || isset($p['felder']['MIN_CHARGING_CURRENT']) || isset($p['felder']['PROHIBIT_USAGE']);
        return $this->schreibenUndPruefen('WALLBOX', self::wallboxBody($nr - 1, $p['felder']), $nr - 1, $p['felder'], "Wallbox $nr", $strom ? 9 : 3, $strom ? 2000000 : 800000);
    }

    /**
     * Schreiben und danach zurueckkontrollieren: Der Speicher uebernimmt manche Werte erst nach einiger Zeit (Steckdosen sofort, Ladestroeme der Wallbox nach etwa 8 s).
     * @param array<string,string> $felder Schluessel => kodierter Wert
     */
    private function schreibenUndPruefen(string $sec, string $body, int $idx, array $felder, string $was, int $versuche = 3, int $pauseUs = 800000): string
    {
        $r = $this->lala->senden($body);
        if (!$r['ok']) {
            return 'abgelehnt: ' . $r['error'];
        }
        foreach ($felder as $key => $_) {
            $raw = $r['raw'][$sec][$key] ?? null;
            if (is_string($raw) && SenecDecode::isAbsent($raw)) {
                return "abgelehnt: Der Speicher kennt $key nicht (diese Funktion fehlt an diesem Gerät)";
            }
        }
        $soll = [];
        foreach ($felder as $key => $kodiert) {
            $soll[$key] = SenecDecode::value($kodiert);
        }
        $ist = [];
        for ($versuch = 0; $versuch < $versuche; $versuch++) {
            if ($versuch > 0) {
                usleep($pauseUs);
            }
            $ist = $this->lala->lesen($sec, array_keys($felder)) ?? [];
            $falsch = [];
            foreach ($soll as $key => $s) {
                $v = $ist[$key] ?? null;
                $v = is_array($v) ? ($v[$idx] ?? null) : $v;
                $gleich = is_numeric($v) && is_numeric($s) && abs((float)$v - (float)$s) < 0.01;
                if (!$gleich) {
                    $falsch[] = $key;
                }
            }
            if (!$falsch) {
                $this->log->info("$was: " . implode(', ', array_keys($felder)) . ' gesetzt (Befehl aus MQTT)');
                return 'ausgefuehrt';
            }
        }
        $this->log->warn("$was: Der Speicher hat " . implode(', ', $falsch) . ' nicht übernommen', 'control-' . $sec);
        return 'abgelehnt: Der Speicher hat den Wert nicht übernommen (' . implode(', ', $falsch) . ')';
    }
}
