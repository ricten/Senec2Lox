<?php
/**
 * Quelle A: lokale lala.cgi (V2/V2.1/V3).
 * Liste der Schnellabfrage 1:1 aus ioBroker.senec local-client.js (MIT, (c) NoBl).
 */
final class LalaSource
{
    public const MAX_BODY = 11500;

    public const FAST = [
        'ENERGY' => ['STAT_STATE', 'GUI_BAT_DATA_POWER', 'GUI_INVERTER_POWER', 'GUI_HOUSE_POW', 'GUI_GRID_POW',
            'GUI_BAT_DATA_FUEL_CHARGE', 'GUI_CHARGING_INFO', 'GUI_BOOSTING_INFO', 'GUI_BAT_DATA_VOLTAGE',
            'GUI_BAT_DATA_CURRENT', 'STAT_LIMITED_NET_SKEW', 'SAFE_CHARGE_FORCE', 'SAFE_CHARGE_PROHIBIT',
            'SAFE_CHARGE_RUNNING'],
        'PV1' => ['POWER_RATIO', 'MPP_POWER'],
        'PM1OBJ1' => ['FREQ', 'U_AC', 'I_AC', 'P_AC', 'P_TOTAL'],
        'PWR_UNIT' => ['POWER_L1', 'POWER_L2', 'POWER_L3'],
        'BMS' => ['CURRENT', 'SOC', 'SYSTEM_SOC', 'TEMP_MAX', 'TEMP_MIN', 'VOLTAGE',
            'CELL_TEMPERATURES_MODULE_A', 'CELL_TEMPERATURES_MODULE_B', 'CELL_TEMPERATURES_MODULE_C',
            'CELL_TEMPERATURES_MODULE_D', 'CELL_VOLTAGES_MODULE_A', 'CELL_VOLTAGES_MODULE_B',
            'CELL_VOLTAGES_MODULE_C', 'CELL_VOLTAGES_MODULE_D'],
    ];

    private array $values = [];      // [SEKTION][KEY] => decodierter Wert
    private array $absent = [];      // "SEK.KEY" => true (dauerhaft uebersprungen)
    private array $unknownLogged = [];
    private int $nextFull = 0;
    private ?string $scheme = null;  // zuletzt erfolgreiches Protokoll ('https' | 'http'), bleibt in state.json gemerkt
    private int $failures = 0;

    public function __construct(private readonly array $cfg, private readonly State $state, private readonly Logger $log)
    {
    }

    public function interval(): int
    {
        return (int)$this->cfg['interval'];
    }

    /** Wartezeit bis zum naechsten Versuch (Backoff bei Fehlern bis 60 s). */
    public function delay(): int
    {
        if ($this->failures === 0) {
            return $this->interval();
        }
        return min(60, $this->interval() * (2 ** min($this->failures, 6)));
    }

    public function resetFingerprint(): void
    {
        $this->state->set('lala_fp', null);
    }

    /** Baut die Request-Bodies (je < MAX_BODY Byte). */
    public static function buildBodies(array $sections, int $max = self::MAX_BODY): array
    {
        $bodies = [];
        $cur = [];
        foreach ($sections as $name => $keys) {
            $obj = $keys ? array_fill_keys($keys, '') : new stdClass();
            $try = $cur;
            $try[$name] = $obj;
            if ($cur && strlen(json_encode($try)) >= $max) {
                $bodies[] = json_encode($cur);
                $cur = [$name => $obj];
            } else {
                $cur = $try;
            }
        }
        if ($cur) {
            $bodies[] = json_encode($cur);
        }
        return $bodies;
    }

    private function fastSections(): array
    {
        $out = [];
        $custom = Config::parseFast($this->cfg['fast_sections'] ?? '');
        foreach ($custom ?: self::FAST as $sec => $keys) {
            $k = array_values(array_filter($keys, function ($key) use ($sec) {
                return !isset($this->absent["$sec.$key"]);
            }));
            if ($k) {
                $out[$sec] = $k;
            }
        }
        // Der Text zum Wallbox-Status gilt nur fuer bekannte Wallbox-Typen: den Typ dann mitlesen
        if (isset($out['WALLBOX']) && in_array('STATE', $out['WALLBOX'], true) && !in_array('HW_TYPE', $out['WALLBOX'], true) && !isset($this->absent['WALLBOX.HW_TYPE'])) {
            $out['WALLBOX'][] = 'HW_TYPE';
        }
        return $out;
    }

    private function fullSections(): array
    {
        $out = [];
        foreach (explode(',', $this->cfg['full_sections']) as $s) {
            if ($s !== '' && $s !== 'STATISTIC') {
                $out[$s] = [];
            }
        }
        foreach ($this->cfg['_extra_sections'] ?? [] as $s) {      // z. B. SOCKETS und WALLBOX, solange die Steuerung dafuer freigegeben ist
            $out[$s] = [];
        }
        return $out;
    }

    /** Letzter abgefragter Wert [SEKTION][KEY] (oder null). */
    public function wert(string $sec, string $key): mixed
    {
        return $this->values[$sec][$key] ?? null;
    }

    /**
     * Anfrage mit Schreibzugriff (Steuerbefehl) an lala.cgi; die Antwort enthaelt die abgefragten bzw. gesetzten Schluessel.
     * @return array{ok:bool,error:?string,raw:array,data:array}  raw: Rohwerte der Antwort, data: decodierte Werte [SEKTION][KEY]
     */
    public function senden(string $body): array
    {
        if (strlen($body) >= self::MAX_BODY) {
            return ['ok' => false, 'error' => 'Anfrage zu gross', 'raw' => [], 'data' => []];
        }
        $r = $this->post($body);
        if ($r['errno'] !== 0) {
            return ['ok' => false, 'error' => 'Keine Verbindung zum Speicher: ' . $r['error'], 'raw' => [], 'data' => []];
        }
        if ($r['status'] !== 200) {
            return ['ok' => false, 'error' => 'Der Speicher antwortet mit HTTP ' . $r['status'], 'raw' => [], 'data' => []];
        }
        $raw = json_decode($r['body'], true);
        if (!is_array($raw)) {
            return ['ok' => false, 'error' => 'Unverstaendliche Antwort des Speichers', 'raw' => [], 'data' => []];
        }
        $data = [];
        foreach ($raw as $sec => $keys) {
            foreach ((array)$keys as $key => $v) {
                if (!is_string($v) || !SenecDecode::isAbsent($v)) {
                    $d = SenecDecode::value($v);
                    if ($d !== null) {
                        $data[$sec][$key] = $d;
                    }
                }
            }
        }
        return ['ok' => true, 'error' => null, 'raw' => $raw, 'data' => $data];
    }

    /** Einzelne Schluessel lesen (kein Schreibzugriff). @return array|null decodierte Werte [KEY] oder null bei Fehler */
    public function lesen(string $sec, array $keys): ?array
    {
        $r = $this->senden(json_encode([$sec => array_fill_keys($keys, '')]));
        return $r['ok'] ? ($r['data'][$sec] ?? []) : null;
    }

    private function post(string $body): array
    {
        $host = $this->cfg['host'];
        $conf = $this->cfg['scheme'] ?? 'auto';
        $known = $this->scheme ?? $this->state->get('lala_scheme');
        if ($conf !== 'auto') {
            $schemes = [$conf];
        } elseif ($known === 'http' || $known === 'https') {
            $schemes = [$known, $known === 'https' ? 'http' : 'https'];   // bekanntes zuerst, anderes als Rueckfall
        } else {
            $schemes = ['https', 'http'];
        }
        $r = null;
        foreach ($schemes as $sch) {
            $r = Http::request('POST', "$sch://$host/lala.cgi", ['Content-Type: application/json'], $body,
                (int)$this->cfg['timeout'], false);
            if ($r['errno'] === 0) {
                if ($known !== $sch) {   // neu erkannt oder gewechselt
                    $this->state->set('lala_scheme', $sch);
                    $this->log->info("lala.cgi erreichbar ueber $sch");
                }
                $this->scheme = $sch;
                if ($sch === 'https' && $this->state->checkFingerprint('lala_fp', $r['fingerprint'])) {
                    $this->log->warn('Zertifikat-Fingerprint von lala.cgi hat sich geaendert (uebernommen)');
                    $r['fp_changed'] = true;
                }
                return $r;
            }
        }
        return $r;
    }

    /** Verarbeitet eine Antwort; liefert Fehlertext oder null. */
    private function ingest(string $json): ?string
    {
        $data = json_decode($json, true);
        if (!is_array($data)) {
            return 'Antwort ist kein gueltiges JSON (evtl. abgeschnitten)';
        }
        foreach ($data as $sec => $keys) {
            if (!is_array($keys)) {
                continue;
            }
            foreach ($keys as $key => $raw) {
                if (SenecDecode::isAbsent($raw)) {
                    $this->absent["$sec.$key"] = true;
                    continue;
                }
                $v = SenecDecode::value($raw);
                if ($v === null) {
                    if (is_string($raw) && strncmp($raw, 'er_', 3) !== 0 && $raw !== 'MALFORMED_VALUE') {
                        $k = "$sec.$key";
                        if (!isset($this->unknownLogged[$k])) {
                            $this->unknownLogged[$k] = true;
                            $this->log->debug("lala: unbekannter Wert bei $k");
                        }
                    }
                    continue;
                }
                $this->values[$sec][$key] = $v;
            }
        }
        return null;
    }

    /** @return array Ergebnis: ok, error, status, delay, data, fp_changed */
    public function poll(int $now): array
    {
        $res = ['ok' => false, 'error' => null, 'status' => 'error', 'delay' => 0, 'data' => null, 'fp_changed' => false];
        $bodies = self::buildBodies($this->fastSections());
        if ($this->nextFull <= $now) {
            foreach (self::buildBodies($this->fullSections()) as $b) {
                $bodies[] = $b;
            }
        }
        $fullDone = $this->nextFull <= $now;
        foreach ($bodies as $body) {
            if (strlen($body) > self::MAX_BODY * 0.5) {
                $this->log->warn('lala-Request ist ' . strlen($body) . ' Byte gross (Limit ca. ' . self::MAX_BODY . ')', 'lala-size');
            }
            $r = $this->post($body);
            if (!empty($r['fp_changed'])) {
                $res['fp_changed'] = true;
            }
            if ($r['errno'] !== 0 || $r['status'] !== 200) {
                $this->failures++;
                $res['error'] = $r['errno'] !== 0 ? ('Verbindung: ' . $r['error']) : ('HTTP ' . $r['status']);
                $res['delay'] = $this->delay();
                $this->log->warn('lala.cgi: ' . $res['error'], 'lala-err');
                return $res;
            }
            $err = $this->ingest($r['body']);
            if ($err !== null) {
                $this->failures++;
                $res['error'] = $err;
                $res['delay'] = $this->delay();
                $this->log->warn('lala.cgi: ' . $err, 'lala-json');
                return $res;
            }
        }
        if ($fullDone) {
            $this->nextFull = $now + (int)$this->cfg['interval_full'];
        }
        $this->failures = 0;
        $res['ok'] = true;
        $res['status'] = 'ok';
        $res['delay'] = $this->interval();
        $res['data'] = $this->values;
        return $res;
    }

    /** Einmaliger Verbindungstest fuer die Weboberflaeche. */
    public function test(): array
    {
        $b = self::buildBodies(['FACTORY' => [], 'ENERGY' => ['STAT_STATE', 'GUI_INVERTER_POWER']]);
        $r = $this->post($b[0]);
        if ($r['errno'] !== 0) {
            return ['ok' => false, 'message' => 'Keine Verbindung: ' . $r['error']];
        }
        if ($r['status'] !== 200) {
            return ['ok' => false, 'message' => 'HTTP ' . $r['status']];
        }
        $this->ingest($r['body']);
        $f = $this->values['FACTORY'] ?? [];
        return ['ok' => true, 'message' => 'Verbindung ok', 'factory' => $f];
    }
}
