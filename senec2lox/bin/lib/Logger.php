<?php
/**
 * Logging ueber die LoxBerry-Logbibliothek (LBLog, falls vorhanden), sonst direkt in die Logdatei.
 * Maskiert Secrets, entprellt wiederholte Meldungen und haelt die Datei klein.
 *
 * Der Loglevel wird ausschliesslich ueber die Plugin-Einstellung "log_level" bestimmt (error, warn, info, debug).
 * LBLog bekommt deshalb einen festen Level 7 und filtert nicht selbst - sonst wuerden Meldungen
 * zusaetzlich vom Loglevel der LoxBerry-Pluginverwaltung verschluckt.
 */
final class Logger
{
    private const array LEVELS = ['error' => 1, 'warn' => 2, 'info' => 3, 'debug' => 4];
    private const int MAX_BYTES = 1048576;      // darueber wird die Datei auf die letzten KEEP_BYTES gekuerzt
    private const int KEEP_BYTES = 262144;

    private int $level = 3;
    private array $secrets = [];
    private array $seen = [];
    private bool $lb;
    private int $checkTick = 0;

    public function __construct(private readonly ?string $file, private readonly string $logName = 'Dienst')
    {
        $this->lb = class_exists('LBLog') && function_exists('LOGSTART');
        if ($this->lb) {
            // addtime: Zeitstempel je Zeile; loglevel 7: filtern wir selbst (siehe oben)
            LBLog::newLog(['name' => $this->logName, 'filename' => $this->file, 'append' => 1,
                'addtime' => 1, 'loglevel' => 7]);
            LOGSTART('Senec2Lox gestartet');
        }
    }

    public function setLevel(string $level): void
    {
        $this->level = self::LEVELS[$level] ?? 3;
    }

    public function addSecret(string $s): void
    {
        if (strlen($s) >= 4) {
            $this->secrets[$s] = true;
        }
    }

    private function mask(string $m): string
    {
        foreach (array_keys($this->secrets) as $s) {
            $m = str_replace((string)$s, '***', $m);
        }
        return $m;
    }

    private function out(string $lvl, string $msg, ?string $key): void
    {
        if (self::LEVELS[$lvl] > $this->level) {
            return;
        }
        if ($key !== null) { // gleiche Meldung hoechstens alle 10 min
            $now = time();
            if (isset($this->seen[$key]) && $now - $this->seen[$key] < 600) {
                return;
            }
            $this->seen[$key] = $now;
        }
        $msg = $this->mask(preg_replace('/[\r\n]+/', ' ', $msg));
        if ($this->lb) {
            switch ($lvl) {
                case 'error':
                    LOGERR($msg);
                    break;
                case 'warn':
                    LOGWARN($msg);
                    break;
                case 'debug':
                    LOGDEB('<DEBUG> ' . $msg);   // LBLog schreibt DEBUG ohne Markierung
                    break;
                default:
                    LOGINF($msg);
            }
            return;
        }
        $tag = ['error' => 'ERROR', 'warn' => 'WARNING', 'info' => 'INFO', 'debug' => 'DEBUG'][$lvl];
        $line = date('Y-m-d H:i:s') . ' <' . $tag . '> ' . $msg . "\n";
        if ($this->file) {
            @file_put_contents($this->file, $line, FILE_APPEND);
        } else {
            fwrite(STDERR, $line);
        }
    }

    public function error(string $m, ?string $key = null): void
    {
        $this->out('error', $m, $key);
    }

    public function warn(string $m, ?string $key = null): void
    {
        $this->out('warn', $m, $key);
    }

    public function info(string $m, ?string $key = null): void
    {
        $this->out('info', $m, $key);
    }

    public function debug(string $m, ?string $key = null): void
    {
        $this->out('debug', $m, $key);
    }

    /** Regelmaessig aufrufen: ueberlange Logdatei auf die letzten Zeilen kuerzen (keine neue Log-Sitzung). */
    public function rotateCheck(): void
    {
        if (++$this->checkTick % 300 !== 0 || !$this->file) {
            return;
        }
        clearstatcache(true, $this->file);
        if (!is_file($this->file)) {
            return;
        }
        $size = (int)filesize($this->file);
        if ($size <= self::MAX_BYTES) {
            return;
        }
        $tail = @file_get_contents($this->file, false, null, max(0, $size - self::KEEP_BYTES));
        if ($tail === false) {
            return;
        }
        $nl = strpos($tail, "\n");
        if ($nl !== false) {
            $tail = substr($tail, $nl + 1);     // angeschnittene erste Zeile verwerfen
        }
        @file_put_contents($this->file, $tail, LOCK_EX);
    }

    public function end(): void
    {
        if ($this->lb) {
            LOGEND('Senec2Lox beendet');
        }
    }
}
