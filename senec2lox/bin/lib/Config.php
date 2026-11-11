<?php
/** Konfiguration laden (Defaults + gespeicherte Werte), validieren, atomar speichern. */
final class Config
{
    public static function defaults(): array
    {
        return [
            'enabled' => true,
            'lala' => ['use' => true, 'host' => '', 'interval' => 10, 'interval_full' => 60,
                'full_sections' => 'ENERGY,PV1,PM1OBJ1,TEMPMEASURE,BMS,FACTORY,SYS_UPDATE,WIZARD', 'timeout' => 5,
                'scheme' => 'auto', 'fast_sections' => ''],
            'openapi' => ['use' => false, 'host' => '', 'interval' => 60, 'timeout' => 10,
                'include' => 'battery,meter', 'device_name' => 'LoxBerry'],
            'connect' => ['use' => false, 'subscription_key' => '', 'system_filter' => '', 'interval' => 300,
                'timeout' => 30, 'include' => 'battery,meter,evse,bessNameplate'],
            'live_source_priority' => 'lala,openapi,connect',
            'mqtt' => ['use_loxberry_broker' => true, 'host' => '', 'port' => 1883, 'user' => '', 'pass' => '',
                'topic_prefix' => 'senec', 'retain' => true, 'only_changes' => true,
                'full_resend_minutes' => 5, 'publish_raw' => true],
            // Steuerbefehle aus Loxone (ab Werk gesperrt): erst nach Bestaetigung des Warnhinweises und je Gruppe freigeben
            'control' => ['warnung_ok' => false, 'zwangsladung' => false, 'steckdosen' => false, 'wallbox' => false],
            'log_level' => 'info',
        ];
    }

    /** Rekursiv: Defaults ueberschrieben durch gespeicherte Werte (nur bekannte Schluessel). */
    public static function merge(array $defaults, array $saved): array
    {
        $out = $defaults;
        foreach ($defaults as $k => $dv) {
            if (!array_key_exists($k, $saved)) {
                continue;
            }
            if (is_array($dv)) {
                $out[$k] = is_array($saved[$k]) ? self::merge($dv, $saved[$k]) : $dv;
            } elseif (is_bool($dv)) {
                $out[$k] = filter_var($saved[$k], FILTER_VALIDATE_BOOLEAN);
            } elseif (is_int($dv)) {
                $out[$k] = is_numeric($saved[$k]) ? (int)$saved[$k] : $dv;
            } else {
                $out[$k] = is_scalar($saved[$k]) ? (string)$saved[$k] : $dv;
            }
        }
        return $out;
    }

    public static function load(string $file): array
    {
        $saved = [];
        if (is_file($file)) {
            $j = json_decode((string)file_get_contents($file), true);
            if (is_array($j)) {
                $saved = $j;
            }
        }
        return self::validate(self::merge(self::defaults(), $saved));
    }

    private static function clamp(int $v, int $min, int $max): int
    {
        return max($min, min($max, $v));
    }

    private static function validHost(string $h): string
    {
        $h = trim($h);
        return preg_match('/^[A-Za-z0-9.\-]{1,253}(:[0-9]{1,5})?$/', $h) ? $h : '';
    }

    private static function csv(string $s, string $pattern, array $allowed = []): string
    {
        $out = [];
        foreach (explode(',', $s) as $p) {
            $p = trim($p);
            if ($p === '' || !preg_match($pattern, $p)) {
                continue;
            }
            if ($allowed && !in_array($p, $allowed, true)) {
                continue;
            }
            $out[$p] = $p;
        }
        return implode(',', $out);
    }

    /**
     * Schnellabfrage-Schluessel: je Zeile "SEKTION=KEY1,KEY2". Leer = eingebaute Standardliste.
     * @return array<string,string[]>
     */
    public static function parseFast(string $text): array
    {
        $out = [];
        foreach (preg_split('/[\r\n;]+/', $text) as $line) {
            $line = trim($line);
            if (!preg_match('/^([A-Z0-9_]+)\s*=\s*([A-Z0-9_]+(?:\s*,\s*[A-Z0-9_]+)*)$/', $line, $m) || $m[1] === 'STATISTIC') {
                continue;
            }
            $keys = array_values(array_unique(array_map('trim', explode(',', $m[2]))));
            $out[$m[1]] = array_values(array_unique(array_merge($out[$m[1]] ?? [], $keys)));
        }
        return $out;
    }

    public static function formatFast(array $fast): string
    {
        $lines = [];
        foreach ($fast as $sec => $keys) {
            $lines[] = $sec . '=' . implode(',', $keys);
        }
        return implode("\n", $lines);
    }

    public static function validate(array $c): array
    {
        $c['lala']['host'] = self::validHost($c['lala']['host']);
        $c['lala']['interval'] = self::clamp($c['lala']['interval'], 3, 3600);
        $c['lala']['interval_full'] = self::clamp($c['lala']['interval_full'], 10, 86400);
        $c['lala']['timeout'] = self::clamp($c['lala']['timeout'], 1, 30);
        $sections = self::csv($c['lala']['full_sections'], '/^[A-Z0-9_]+$/');
        $c['lala']['full_sections'] = implode(',', array_diff(explode(',', $sections), ['STATISTIC', '']));

        if (!in_array($c['lala']['scheme'], ['auto', 'https', 'http'], true)) {
            $c['lala']['scheme'] = 'auto';
        }
        $c['lala']['fast_sections'] = self::formatFast(self::parseFast($c['lala']['fast_sections']));

        $c['openapi']['host'] = self::validHost($c['openapi']['host']);
        $c['openapi']['interval'] = self::clamp($c['openapi']['interval'], 10, 3600);
        $c['openapi']['timeout'] = self::clamp($c['openapi']['timeout'], 1, 60);
        $c['openapi']['include'] = self::csv($c['openapi']['include'], '/^[A-Za-z]+$/',
            ['battery', 'meter', 'bess', 'bessNameplate', 'evse']);
        $dn = trim($c['openapi']['device_name']);
        $c['openapi']['device_name'] = preg_match('/^[A-Za-z0-9 _.\-]{1,40}$/', $dn) ? $dn : 'LoxBerry';

        $c['connect']['subscription_key'] = trim($c['connect']['subscription_key']);
        $c['connect']['system_filter'] = trim($c['connect']['system_filter']);
        $c['connect']['interval'] = self::clamp($c['connect']['interval'], 30, 86400);
        $c['connect']['timeout'] = self::clamp($c['connect']['timeout'], 1, 120);
        $inc = explode(',', self::csv($c['connect']['include'], '/^[A-Za-z]+$/',
            ['battery', 'meter', 'evse', 'bessNameplate', 'bess']));
        if (!in_array('bessNameplate', $inc, true)) {
            $inc[] = 'bessNameplate'; // immer noetig, um Anlagen zu unterscheiden
        }
        $c['connect']['include'] = implode(',', array_filter($inc));

        $prio = array_values(array_intersect(explode(',', str_replace(' ', '', $c['live_source_priority'])),
            ['lala', 'openapi', 'connect']));
        foreach (['lala', 'openapi', 'connect'] as $s) {
            if (!in_array($s, $prio, true)) {
                $prio[] = $s;
            }
        }
        $c['live_source_priority'] = implode(',', array_unique($prio));

        $m = &$c['mqtt'];
        $m['host'] = self::validHost($m['host']);
        $m['port'] = self::clamp($m['port'], 1, 65535);
        $p = trim($m['topic_prefix'], "/ \t");
        $m['topic_prefix'] = ($p !== '' && !preg_match('/[#+\s]/', $p)) ? $p : 'senec';
        $m['full_resend_minutes'] = self::clamp($m['full_resend_minutes'], 1, 1440);
        unset($m);

        // Ohne bestaetigten Warnhinweis ist keine Steuerung freigegeben, egal was in den Gruppen steht
        if (empty($c['control']['warnung_ok'])) {
            $c['control'] = ['warnung_ok' => false, 'zwangsladung' => false, 'steckdosen' => false, 'wallbox' => false];
        }
        if (!in_array($c['log_level'], ['error', 'warn', 'info', 'debug'], true)) {
            $c['log_level'] = 'info';
        }
        return $c;
    }

    /** Atomar schreiben (Temp-Datei + rename). */
    public static function save(string $file, array $c): bool
    {
        return State::atomicWrite($file, json_encode($c, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}
