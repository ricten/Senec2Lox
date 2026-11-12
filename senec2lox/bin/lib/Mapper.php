<?php
/** Rohwerte -> sprechende Topics, Quellenauswahl. Liefert Topics relativ zum Praefix. */
final class Mapper
{
    /** Zahl/Bool/String -> MQTT-Payload (null = nicht senden). */
    public static function fmt($v): ?string
    {
        if ($v === null || is_array($v)) {
            return null;
        }
        if (is_bool($v)) {
            return $v ? '1' : '0';
        }
        if (is_float($v)) {
            $s = rtrim(rtrim(sprintf('%.4f', $v), '0'), '.');
            return ($s === '-0' || $s === '') ? '0' : $s;
        }
        return (string)$v;
    }

    /** Verschachtelte Struktur zu Topics abflachen: prefix/key/sub ... */
    public static function flatten(string $prefix, $value, array &$out): void
    {
        if (is_array($value)) {
            foreach ($value as $k => $v) {
                self::flatten($prefix . '/' . $k, $v, $out);
            }
            return;
        }
        $s = self::fmt($value);
        if ($s !== null) {
            $out[$prefix] = $s;
        }
    }

    private static function num($v): ?float
    {
        return is_numeric($v) ? (float)$v : null;
    }

    /**
     * Netzleistung der OpenAPI (Spec 7.4): + Bezug, - Einspeisung.
     * @return float|null
     */
    public static function openApiGrid(?float $gridPower, ?float $consumption, ?float $production, ?float $batteryPower, ?float $surplus): ?float
    {
        if ($gridPower === null) {
            return null;
        }
        if ($gridPower < 0) {
            return $gridPower;
        }
        if ($consumption === null || $production === null) {
            return $gridPower;
        }
        $x = $consumption + ($batteryPower ?? 0.0) - $production;
        if (abs($x) < 50) {
            return 0.0;
        }
        if ($x > 0) {
            return $gridPower;
        }
        $mag = $gridPower > 0 ? $gridPower : ($surplus ?? 0.0);
        return $mag > 0 ? -$mag : 0.0;
    }

    /** @return array<string,mixed> live-Werte aus lala-Werten [SEK][KEY] */
    /** @param array<string,string> $wbTypen Auswahl je Wallbox: typ1 ... typ4 = auto | pro_s | andere */
    public static function liveFromLala(array $v, array $wbTypen = []): array
    {
        $e = $v['ENERGY'] ?? [];
        $l = [
            'pv_w' => $e['GUI_INVERTER_POWER'] ?? null,
            'haus_w' => $e['GUI_HOUSE_POW'] ?? null,
            'netz_w' => $e['GUI_GRID_POW'] ?? null,
            'batterie_w' => $e['GUI_BAT_DATA_POWER'] ?? null,
            'batterie_soc' => $e['GUI_BAT_DATA_FUEL_CHARGE'] ?? null,
            'batterie_spannung_v' => $e['GUI_BAT_DATA_VOLTAGE'] ?? null,
            'batterie_strom_a' => $e['GUI_BAT_DATA_CURRENT'] ?? null,
            'netz_frequenz_hz' => $v['PM1OBJ1']['FREQ'] ?? null,
            'phase_l1_w' => $v['PWR_UNIT']['POWER_L1'] ?? null,
            'phase_l2_w' => $v['PWR_UNIT']['POWER_L2'] ?? null,
            'phase_l3_w' => $v['PWR_UNIT']['POWER_L3'] ?? null,
            'temp_batterie_max_c' => $v['BMS']['TEMP_MAX'] ?? null,
            'temp_batterie_min_c' => $v['BMS']['TEMP_MIN'] ?? null,
        ];
        // Wallbox (nur wenn abgefragt): Werte je Wallbox liegen als Array vor
        $w = $v['WALLBOX'] ?? [];
        if (isset($w['APPARENT_CHARGING_POWER'])) {
            $sum = 0.0;
            foreach ((array)$w['APPARENT_CHARGING_POWER'] as $x) {
                $sum += is_numeric($x) ? (float)$x : 0.0;
            }
            $l['wallbox_w'] = $sum;
        }
        if (isset($w['EV_CONNECTED'])) {
            $any = false;
            foreach ((array)$w['EV_CONNECTED'] as $x) {
                $any = $any || (is_numeric($x) && (float)$x != 0.0) || $x === true;
            }
            $l['wallbox_fahrzeug_verbunden'] = $any ? 1 : 0;
        }
        if (isset($w['STATE'])) {
            $first = (array)$w['STATE'];
            $first = reset($first);
            if (is_numeric($first)) {
                $l['wallbox_status_code'] = (int)$first;
                if (WallboxTexts::textFuer((string)($wbTypen['typ1'] ?? 'auto'), ((array)($w['HW_TYPE'] ?? []))[0] ?? null)) {
                    $l['wallbox_status_text'] = WallboxTexts::text((int)$first);
                }
            }
        }
        // Je Wallbox (der Speicher unterstuetzt bis zu vier): wallbox1_w ... wallbox4_w, wallbox<N>_fahrzeug_verbunden, wallbox<N>_status_code
        $anz = isset($v['WIZARD']['SETUP_NUMBER_WALLBOXES']) && is_numeric($v['WIZARD']['SETUP_NUMBER_WALLBOXES']) ? min(4, (int)$v['WIZARD']['SETUP_NUMBER_WALLBOXES']) : 4;
        foreach (['APPARENT_CHARGING_POWER' => 'w', 'EV_CONNECTED' => 'fahrzeug_verbunden', 'STATE' => 'status_code', 'PROHIBIT_USAGE' => 'gesperrt'] as $key => $suffix) {
            if (!isset($w[$key])) {
                continue;
            }
            foreach (array_values((array)$w[$key]) as $i => $x) {
                if ($i >= $anz) {
                    break;
                }
                $name = 'wallbox' . ($i + 1) . '_' . $suffix;
                if ($key === 'EV_CONNECTED' || $key === 'PROHIBIT_USAGE') {
                    $l[$name] = ((is_numeric($x) && (float)$x != 0.0) || $x === true) ? 1 : 0;
                } elseif (is_numeric($x)) {
                    $l[$name] = $key === 'STATE' ? (int)$x : (float)$x;
                    if ($key === 'STATE' && WallboxTexts::textFuer((string)($wbTypen['typ' . ($i + 1)] ?? 'auto'), ((array)($w['HW_TYPE'] ?? []))[$i] ?? null)) {
                        $l['wallbox' . ($i + 1) . '_status_text'] = WallboxTexts::text((int)$x);
                    }
                }
            }
        }
        // Entladung gesperrt: Vollladung (SAFE_CHARGE_RUNNING) oder Lager-/Modultausch-Modus (LI_STORAGE_MODE_RUNNING) laeuft; "Entladen freigeben" beendet beides
        if (isset($e['LI_STORAGE_MODE_RUNNING']) && is_numeric($e['LI_STORAGE_MODE_RUNNING'])) {
            $l['lagermodus'] = (int)$e['LI_STORAGE_MODE_RUNNING'] === 1 ? 1 : 0;
        }
        if (isset($e['SAFE_CHARGE_RUNNING']) && is_numeric($e['SAFE_CHARGE_RUNNING'])) {
            $l['entladung_gesperrt'] = ((int)$e['SAFE_CHARGE_RUNNING'] === 1 || (int)($e['LI_STORAGE_MODE_RUNNING'] ?? 0) === 1) ? 1 : 0;
        }
        if (isset($e['STAT_STATE']) && is_numeric($e['STAT_STATE'])) {
            $l['status_code'] = (int)$e['STAT_STATE'];
            $l['status_text'] = StateTexts::text((int)$e['STAT_STATE']);
            $l['zwangsladung'] = in_array((int)$e['STAT_STATE'], [8, 9], true) ? 1 : 0;   // Zwangsladung laeuft (8) bzw. ist fertig (9), wie im ioBroker-Adapter
        }
        return $l;
    }

    public static function liveFromOpenApi(array $d): array
    {
        $b = $d['battery'] ?? [];
        $m = $d['meter'] ?? [];
        $batP = self::num($b['power'] ?? null);
        return [
            'pv_w' => $m['production'] ?? null,
            'haus_w' => $m['consumption'] ?? null,
            'netz_w' => self::openApiGrid(self::num($m['gridPower'] ?? null), self::num($m['consumption'] ?? null),
                self::num($m['production'] ?? null), $batP, self::num($m['powerSurplus'] ?? null)),
            'batterie_w' => $b['power'] ?? null,
            'batterie_soc' => $b['stateOfCharge'] ?? null,
            'batterie_spannung_v' => $b['voltage'] ?? null,
            'batterie_strom_a' => $b['current'] ?? null,
            'pv_ueberschuss_w' => $m['powerSurplus'] ?? null,
        ];
    }

    public static function liveFromConnect(array $sys): array
    {
        $b = $sys['battery'] ?? [];
        $m = $sys['meter'] ?? [];
        return [
            'pv_w' => $m['production'] ?? null,
            'haus_w' => $m['consumption'] ?? null,
            'netz_w' => $m['grid_power'] ?? null,
            'batterie_w' => $b['power'] ?? null,
            'batterie_soc' => $b['state_of_charge'] ?? null,
            'batterie_spannung_v' => $b['voltage'] ?? null,
            'batterie_strom_a' => $b['current'] ?? null,
        ];
    }

    /** Abgeleitete Werte (Bezug/Einspeisung, Laden/Entladen). */
    public static function derive(array $live): array
    {
        if (isset($live['netz_w']) && is_numeric($live['netz_w'])) {
            $live['netz_bezug_w'] = max(0.0, (float)$live['netz_w']);
            $live['netz_einspeisung_w'] = max(0.0, -(float)$live['netz_w']);
        }
        if (isset($live['batterie_w']) && is_numeric($live['batterie_w'])) {
            $live['batterie_laden_w'] = max(0.0, (float)$live['batterie_w']);
            $live['batterie_entladen_w'] = max(0.0, -(float)$live['batterie_w']);
        }
        return $live;
    }

    /**
     * Erste aktuelle Quelle laut Prioritaet (letzter Erfolg juenger als 3 x Intervall).
     * @param array $snap name => ['ts'=>int,'interval'=>int,'live'=>array]
     * @return string|null
     */
    public static function pickSource(array $priority, array $snap, int $now): ?string
    {
        foreach ($priority as $name) {
            if (isset($snap[$name]) && $snap[$name]['ts'] > 0 && $now - $snap[$name]['ts'] <= 3 * $snap[$name]['interval']) {
                return $name;
            }
        }
        return null;
    }

    /**
     * Baut alle Topics (ohne Praefix).
     * @param array $snap name => ['ts','interval','data'] (data = Rohdaten der Quelle)
     * @param array $status name => ['ok'=>bool,'status'=>string]
     * @return array{topics:array<string,string>,live_source:?string}
     */
    public static function build(array $cfg, array $snap, array $status, int $now, ?string $error): array
    {
        $topics = [];
        $raw = !empty($cfg['mqtt']['publish_raw']);
        $livePer = [];
        foreach ($snap as $name => $s) {
            if (!isset($s['data'])) {
                continue;
            }
            if ($name === 'lala') {
                $livePer[$name] = self::liveFromLala($s['data'], (array)($cfg['wallboxen'] ?? []));
                if ($raw) {
                    foreach ($s['data'] as $sec => $keys) {
                        foreach ($keys as $key => $val) {
                            self::flatten("raw/$sec/$key", $val, $topics);
                        }
                    }
                }
                self::info($s['data'], $topics);
            } elseif ($name === 'openapi') {
                $livePer[$name] = self::liveFromOpenApi($s['data']);
                if ($raw) {
                    foreach ($s['data'] as $sec => $val) {
                        self::flatten("openapi/$sec", $val, $topics);
                    }
                }
                $np = $s['data']['bessNameplate'] ?? [];
                self::infoDefault($topics, 'modell', $np['model'] ?? null);
                self::infoDefault($topics, 'seriennummer', $np['serialNumber'] ?? null);
                self::infoDefault($topics, 'nennkapazitaet_modul_wh', $np['designCapacity'] ?? null);
            } elseif ($name === 'connect') {
                $first = true;
                foreach ($s['data'] as $id => $sys) {
                    if ($first) {
                        $livePer[$name] = self::liveFromConnect($sys);
                        $np = $sys['bessNameplate'] ?? ($sys['bess_nameplate'] ?? []);
                        self::infoDefault($topics, 'modell', $np['system_id'] ?? null);
                        self::infoDefault($topics, 'seriennummer', $np['serial_number'] ?? null);
                        $first = false;
                    }
                    if ($raw) {
                        foreach ($sys as $sec => $val) {
                            self::flatten('connect/' . ConnectSource::topicId((string)$id) . "/$sec", $val, $topics);
                        }
                    }
                }
            }
        }

        $priority = explode(',', $cfg['live_source_priority']);
        $pickSnap = [];
        foreach ($livePer as $n => $_) {
            $pickSnap[$n] = ['ts' => $snap[$n]['ts'], 'interval' => $snap[$n]['interval']];
        }
        $src = self::pickSource($priority, $pickSnap, $now);
        if ($src !== null) {
            $live = self::derive($livePer[$src]);
            foreach ($live as $k => $v) {
                $s = self::fmt($v);
                if ($s !== null) {
                    $topics["live/$k"] = $s;
                }
            }
            $topics['live/quelle'] = $src;
        }

        foreach (['lala', 'openapi', 'connect'] as $n) {
            if (isset($status[$n])) {
                $topics["$n/ok"] = $status[$n]['ok'] ? '1' : '0';
                if ($n === 'openapi') {
                    $topics['openapi/status'] = $status[$n]['status'];
                }
            }
        }
        $last = 0;
        foreach ($snap as $s) {
            $last = max($last, (int)$s['ts']);
        }
        if ($last > 0) {
            $topics['lastupdate'] = (string)$last;
        }
        if ($error !== null) {
            $topics['error'] = $error;
        }
        return ['topics' => $topics, 'live_source' => $src];
    }

    private static function infoDefault(array &$topics, string $name, $value): void
    {
        $s = self::fmt($value);
        if ($s !== null && $s !== '' && !isset($topics["info/$name"])) {
            $topics["info/$name"] = $s;
        }
    }

    /** Info-Topics aus lala. Firmware-/Modellfelder: ⚠ am Geraet pruefen. */
    private static function info(array $v, array &$topics): void
    {
        self::infoDefault($topics, 'modell', $v['FACTORY']['SYS_TYPE'] ?? null);
        self::infoDefault($topics, 'seriennummer', $v['FACTORY']['DEVICE_ID'] ?? null);
        self::infoDefault($topics, 'module_anzahl', $v['BMS']['MODULE_COUNT'] ?? null);
        self::infoDefault($topics, 'firmware', $v['SYS_UPDATE']['NPU_VER'] ?? ($v['SYS_UPDATE']['NPU_IMAGE_VERSION'] ?? null));
    }
}
