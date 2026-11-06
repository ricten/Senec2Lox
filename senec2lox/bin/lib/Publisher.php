<?php
/** Veroeffentlicht Topics mit Aenderungsfilter und periodischem Komplett-Resend. */
final class Publisher
{
    private readonly int $resend;
    private array $last = [];
    private int $lastFull = 0;

    public function __construct(
        private readonly MqttClient $mqtt,
        private readonly string $prefix,
        private readonly bool $retain,
        private readonly bool $onlyChanges,
        int $resendMinutes,
    ) {
        $this->resend = $resendMinutes * 60;
    }

    public function reset(): void
    {
        $this->last = [];
        $this->lastFull = 0;
    }

    /** @return int Anzahl gesendeter Topics */
    public function publish(array $topics, int $now): int
    {
        $full = !$this->onlyChanges || $now - $this->lastFull >= $this->resend;
        $n = 0;
        foreach ($topics as $t => $v) {
            // Rohwert-Topics haben mind. 3 Ebenen (raw/SEK/KEY, openapi/sek/feld, connect/id/sek/feld)
            $isRaw = substr_count($t, '/') >= 2;
            $changed = !array_key_exists($t, $this->last) || $this->last[$t] !== $v;
            // Rohwerte nur bei Aenderung, ausser nur_aenderungen ist aus
            $send = $changed || ($full && (!$isRaw || !$this->onlyChanges));
            if (!$send) {
                continue;
            }
            if (!$this->mqtt->publish($this->prefix . '/' . $t, $v, $this->retain)) {
                return $n; // Verbindung weg; Hauptschleife verbindet neu
            }
            $this->last[$t] = $v;
            $n++;
        }
        if ($full) {
            $this->lastFull = $now;
        }
        return $n;
    }

    public function raw(string $topic, string $payload): bool
    {
        return $this->mqtt->publish($this->prefix . '/' . $topic, $payload, true);
    }
}
