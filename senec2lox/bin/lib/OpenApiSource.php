<?php
/**
 * Quelle B: lokale SENEC OpenAPI (Port 4424, Header X-Auth-Token).
 * Registrierung nur ueber register() (Knopf in der Weboberflaeche) - nie automatisch.
 */
final class OpenApiSource
{
    public const ROUTES = [
        'battery' => '/v1/battery',
        'meter' => '/v1/meter',
        'bess' => '/v1/bess',
        'bessNameplate' => '/v1/bess/nameplate',
        'evse' => '/v1/evse',
    ];

    private string $status = 'unregistered';
    private int $failures = 0;

    private array $cfg;
    private string $lalaHost;
    private State $state;
    private Logger $log;

    public function __construct(
        array $cfg,
        string $lalaHost,
        State $state,
        Logger $log) {
        $this->cfg = $cfg;
        $this->lalaHost = $lalaHost;
        $this->state = $state;
        $this->log = $log;
        $t = $state->get('openapi_token');
        if (is_string($t)) {
            $log->addSecret($t);
        }
    }

    public function status(): string
    {
        return $this->status;
    }

    private function host(): string
    {
        return self::hostOnly($this->cfg['host'] !== '' ? $this->cfg['host'] : $this->lalaHost);
    }

    /** Port-Anteil entfernen (OpenAPI laeuft immer auf 4424). */
    public static function hostOnly(string $h): string
    {
        return preg_replace('/:[0-9]+$/', '', $h);
    }

    private function url(string $path): string
    {
        return 'https://' . $this->host() . ':4424' . $path;
    }

    private function token(): ?string
    {
        $t = $this->state->get('openapi_token');
        return is_string($t) && $t !== '' ? $t : null;
    }

    private function get(string $path, string $token): array
    {
        $r = Http::request('GET', $this->url($path), ['X-Auth-Token: ' . $token, 'Accept: application/json'],
            null, (int)$this->cfg['timeout'], false);
        if ($this->state->checkFingerprint('openapi_fp', $r['fingerprint'])) {
            $this->log->warn('Zertifikat-Fingerprint der OpenAPI hat sich geaendert (uebernommen)');
        }
        return $r;
    }

    /** @return array ok, error, status, delay, data */
    public function poll(int $now): array
    {
        $res = ['ok' => false, 'error' => null, 'status' => $this->status, 'delay' => (int)$this->cfg['interval'], 'data' => null];
        $token = $this->token();
        if ($token === null) {
            $this->status = 'unregistered';
            $res['status'] = $this->status;
            $res['error'] = 'Nicht registriert';
            $res['delay'] = 60;
            return $res;
        }
        $data = [];
        $routes = array_filter(explode(',', $this->cfg['include']));
        foreach ($routes as $name) {
            if (!isset(self::ROUTES[$name])) {
                continue;
            }
            $r = $this->get(self::ROUTES[$name], $token);
            if ($r['errno'] !== 0) {
                $this->failures++;
                $this->status = 'unreachable';
                $res['status'] = $this->status;
                $res['error'] = 'Port 4424 nicht erreichbar: ' . $r['error'];
                $res['delay'] = min(900, 60 * (2 ** min($this->failures, 4)));
                $this->log->warn($res['error'], 'openapi-unreach');
                return $res;
            }
            if ($r['status'] === 401) { // einmal nach 3 s wiederholen (Cloud-Verzoegerung)
                sleep(3);
                $r = $this->get(self::ROUTES[$name], $token);
            }
            if ($r['status'] === 511) {
                $this->status = 'pending';
                $res['status'] = $this->status;
                $res['error'] = 'Freigabe im SENEC-Portal ausstehend';
                $res['delay'] = 60;
                return $res;
            }
            if ($r['status'] === 401) {
                $this->status = 'rejected';
                $res['status'] = $this->status;
                $res['error'] = 'Token ungueltig oder widerrufen - im Portal pruefen';
                $res['delay'] = 300;
                $this->log->error($res['error'], 'openapi-401');
                return $res;
            }
            $j = json_decode($r['body'], true);
            if ($r['status'] !== 200 || !is_array($j)) {
                $this->failures++;
                $res['error'] = 'HTTP ' . $r['status'] . ' bei ' . self::ROUTES[$name];
                $res['delay'] = min(900, 60 * (2 ** min($this->failures, 4)));
                $this->log->warn($res['error'], 'openapi-http-' . $name);
                return $res;
            }
            $data[$name] = $j;
        }
        $this->failures = 0;
        $this->status = 'ok';
        $res['ok'] = true;
        $res['status'] = 'ok';
        $res['data'] = $data;
        return $res;
    }

    /**
     * Einmalige Registrierung (nur aus ajax.php auf Knopfdruck).
     * Registriert NICHT, wenn schon ein Token gespeichert ist.
     */
    public static function register(array $cfg, string $lalaHost, State $state, Logger $log, string $lockFile): array
    {
        if ($state->get('openapi_token')) {
            return ['ok' => false, 'message' => 'Es ist bereits ein Token gespeichert. Erst "Token vergessen" verwenden.'];
        }
        $lock = fopen($lockFile, 'c');
        if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
            return ['ok' => false, 'message' => 'Eine Registrierung laeuft bereits.'];
        }
        try {
            $host = self::hostOnly($cfg['host'] !== '' ? $cfg['host'] : $lalaHost);
            if ($host === '') {
                return ['ok' => false, 'message' => 'Keine IP/Host konfiguriert.'];
            }
            $body = json_encode(['deviceName' => $cfg['device_name'], 'manufacturerName' => 'LoxBerry.Senec2Lox']);
            $r = Http::request('POST', "https://$host:4424/v1/register",
                ['Content-Type: application/json; charset=utf-8'], $body, (int)$cfg['timeout'], false);
            if ($r['errno'] !== 0) {
                $timeout = $r['errno'] === 28;
                return ['ok' => false, 'message' => 'Registrierung fehlgeschlagen: ' . $r['error']
                    . ($timeout ? ' - Das Geraet hat evtl. trotzdem einen Eintrag angelegt, bitte im SENEC-Portal nachsehen.' : '')];
            }
            $token = trim($r['body']);
            if ($r['status'] !== 202 || !preg_match('/^[0-9a-fA-F-]{16,64}$/', $token)) {
                return ['ok' => false, 'message' => 'Unerwartete Antwort: HTTP ' . $r['status']];
            }
            $state->set('openapi_token', $token);
            $log->addSecret($token);
            $state->checkFingerprint('openapi_fp', $r['fingerprint']);
            $log->info('OpenAPI registriert, Freigabe im Portal erforderlich');
            return ['ok' => true, 'message' => 'Registriert. Bitte jetzt im SENEC-Portal freigeben.'];
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** Einmaliger Statuscheck (ajax). */
    public function check(): array
    {
        $token = $this->token();
        if ($token === null) {
            return ['status' => 'unregistered', 'message' => 'Nicht registriert'];
        }
        $r = $this->get('/v1/battery', $token);
        if ($r['errno'] !== 0) {
            return ['status' => 'unreachable', 'message' => 'Port 4424 nicht erreichbar: ' . $r['error']];
        }
        switch ($r['status']) {
            case 200:
                return ['status' => 'ok', 'message' => 'Aktiv'];
            case 511:
                return ['status' => 'pending', 'message' => 'Freigabe ausstehend'];
            case 401:
                return ['status' => 'rejected', 'message' => 'Token ungueltig oder widerrufen'];
            default:
                return ['status' => 'error', 'message' => 'HTTP ' . $r['status']];
        }
    }
}
