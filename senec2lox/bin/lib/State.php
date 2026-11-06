<?php
/** Persistente Laufzeitdaten (OpenAPI-Token, Zertifikats-Fingerprints) in state.json. */
final class State
{
    private array $data = [];
    private int $mtime = 0;

    public function __construct(private readonly string $file)
    {
        $this->reload(true);
    }

    public static function atomicWrite(string $file, string $content): bool
    {
        $dir = dirname($file);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $tmp = $file . '.tmp' . getmypid();
        if (file_put_contents($tmp, $content) === false) {
            return false;
        }
        @chmod($tmp, 0664);
        if (!@rename($tmp, $file)) {
            @unlink($tmp);
            return false;
        }
        return true;
    }

    /** Liest neu, wenn sich mtime geaendert hat. */
    public function reload(bool $force = false): void
    {
        clearstatcache(true, $this->file);
        $m = is_file($this->file) ? (int)filemtime($this->file) : 0;
        if (!$force && $m === $this->mtime) {
            return;
        }
        $this->mtime = $m;
        $j = $m ? json_decode((string)file_get_contents($this->file), true) : null;
        $this->data = is_array($j) ? $j : [];
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        if ($value === null) {
            unset($this->data[$key]);
        } else {
            $this->data[$key] = $value;
        }
        $this->save();
    }

    private function save(): void
    {
        if (self::atomicWrite($this->file, json_encode($this->data, JSON_PRETTY_PRINT))) {
            clearstatcache(true, $this->file);
            $this->mtime = (int)filemtime($this->file);
        }
    }

    /**
     * TOFU: erster Fingerprint wird gemerkt, Aenderung wird uebernommen.
     * @return bool true, wenn sich der Fingerprint geaendert hat
     */
    public function checkFingerprint(string $key, ?string $fp): bool
    {
        if ($fp === null || $fp === '') {
            return false;
        }
        $old = $this->get($key);
        if ($old === $fp) {
            return false;
        }
        $this->set($key, $fp);
        return $old !== null;
    }
}
