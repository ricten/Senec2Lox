<?php
/** Quelle C: SENEC.Connect (offizielle Cloud-API, Header Ocp-Apim-Subscription-Key). */
final class ConnectSource
{
    public const URL = 'https://apim-eds-gwc-prod.azure-api.net/senec-connect/v1/systems/device-data/general';

    private int $failures = 0;

    private array $cfg;
    private Logger $log;

    public function __construct(array $cfg, Logger $log)
    {
        $this->cfg = $cfg;
        $this->log = $log;
        $log->addSecret($cfg['subscription_key']);
    }

    public static function topicId(string $id): string
    {
        $s = preg_replace('/[^A-Za-z0-9_\-]/', '_', $id);
        return $s !== '' ? $s : 'anlage';
    }

    /** Eindeutige ID einer Anlage (Seriennummer, sonst System-ID, sonst Index). */
    public static function systemId(array $sys, int $index): string
    {
        $np = $sys['bessNameplate'] ?? ($sys['bess_nameplate'] ?? []);
        foreach (['serial_number', 'system_id'] as $k) {
            if (!empty($np[$k]) && is_string($np[$k])) {
                return $np[$k];
            }
        }
        return 'anlage' . $index;
    }

    /** @return array[] systems: [id => daten] */
    public static function parse(string $body, string $filter = ''): ?array
    {
        $j = json_decode($body, true);
        if (!is_array($j)) {
            return null;
        }
        if ($j && array_keys($j) !== range(0, count($j) - 1)) {
            $j = [$j]; // einzelnes Objekt
        }
        $out = [];
        foreach ($j as $i => $sys) {
            if (!is_array($sys)) {
                continue;
            }
            $id = self::systemId($sys, $i);
            if ($filter !== '') {
                $np = $sys['bessNameplate'] ?? ($sys['bess_nameplate'] ?? []);
                if ($filter !== $id && $filter !== ($np['system_id'] ?? '') && $filter !== ($np['serial_number'] ?? '')) {
                    continue;
                }
            }
            $out[$id] = $sys;
        }
        return $out;
    }

    private function fetch(): array
    {
        return Http::request('GET', self::URL . '?include=' . rawurlencode($this->cfg['include']),
            ['Accept: application/json', 'Ocp-Apim-Subscription-Key: ' . $this->cfg['subscription_key']],
            null, (int)$this->cfg['timeout'], true, 4194304);
    }

    /** @return array ok, error, status, delay, data */
    public function poll(int $now): array
    {
        $res = ['ok' => false, 'error' => null, 'status' => 'error', 'delay' => (int)$this->cfg['interval'], 'data' => null];
        if ($this->cfg['subscription_key'] === '') {
            $res['error'] = 'Kein Subscription-Key';
            $res['delay'] = 60;
            return $res;
        }
        $r = $this->fetch();
        if ($r['errno'] !== 0) {
            $this->failures++;
            $res['error'] = 'Verbindung: ' . $r['error'];
            $res['delay'] = min(1800, 60 * (2 ** min($this->failures, 5)));
            $this->log->warn('SENEC.Connect: ' . $res['error'], 'connect-err');
            return $res;
        }
        if ($r['status'] === 401 || $r['status'] === 403) {
            $res['status'] = 'rejected';
            $res['error'] = 'Subscription-Key ungueltig';
            $res['delay'] = 1800;
            $this->log->error('SENEC.Connect: ' . $res['error'], 'connect-401');
            return $res;
        }
        if ($r['status'] === 429) {
            $this->failures++;
            $ra = isset($r['headers']['retry-after']) ? (int)$r['headers']['retry-after'] : 0;
            $res['error'] = 'Rate-Limit (429)';
            $res['delay'] = $ra > 0 ? min(1800, $ra) : min(1800, 120 * (2 ** min($this->failures, 4)));
            $this->log->warn('SENEC.Connect: ' . $res['error'], 'connect-429');
            return $res;
        }
        $systems = $r['status'] === 200 ? self::parse($r['body'], $this->cfg['system_filter']) : null;
        if ($systems === null) {
            $this->failures++;
            $res['error'] = 'HTTP ' . $r['status'];
            $res['delay'] = min(1800, 60 * (2 ** min($this->failures, 5)));
            $this->log->warn('SENEC.Connect: ' . $res['error'], 'connect-http');
            return $res;
        }
        $this->failures = 0;
        $res['ok'] = true;
        $res['status'] = 'ok';
        $res['data'] = $systems;
        return $res;
    }

    /** Verbindungstest: Liste der gefundenen Anlagen. */
    public function test(): array
    {
        if ($this->cfg['subscription_key'] === '') {
            return ['ok' => false, 'message' => 'Kein Subscription-Key eingetragen'];
        }
        $r = $this->fetch();
        if ($r['errno'] !== 0) {
            return ['ok' => false, 'message' => 'Verbindung: ' . $r['error']];
        }
        if ($r['status'] === 401 || $r['status'] === 403) {
            return ['ok' => false, 'message' => 'Subscription-Key ungueltig'];
        }
        $systems = $r['status'] === 200 ? self::parse($r['body']) : null;
        if ($systems === null) {
            return ['ok' => false, 'message' => 'HTTP ' . $r['status']];
        }
        $list = [];
        foreach ($systems as $id => $sys) {
            $np = $sys['bessNameplate'] ?? ($sys['bess_nameplate'] ?? []);
            $list[] = ['id' => $id, 'system_id' => $np['system_id'] ?? '', 'serial_number' => $np['serial_number'] ?? ''];
        }
        return ['ok' => true, 'message' => count($list) . ' Anlage(n) gefunden', 'systems' => $list];
    }
}
