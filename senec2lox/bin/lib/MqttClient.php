<?php
/**
 * Minimaler MQTT-3.1.1-Client (QoS 0, dauerhafte Verbindung).
 *
 * Der Socket ist nicht blockierend. Schreibzugriffe warten bei vollem Sendepuffer per stream_select auf
 * Schreibbarkeit (PHP >= 7.4 liefert dann fwrite() = false bzw. 0, das ist kein Verbindungsabbruch).
 * Ein Abbruch wird nur gemeldet, wenn der Broker die Verbindung schliesst oder nicht mehr liest.
 */
final class MqttClient
{
    private const WRITE_TIMEOUT = 15;       // Sekunden, solange der Broker nichts annimmt

    /** @var resource|null */
    private $sock = null;
    private int $lastSend = 0;
    private int $connectedAt = 0;
    private int $closedAt = 0;
    private string $lastError = '';

    public function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly string $user,
        private readonly string $pass,
        private readonly string $clientId,
        private readonly string $willTopic,
        private readonly int $keepalive = 60,
    ) {
    }

    public static function str(string $s): string
    {
        return pack('n', strlen($s)) . $s;
    }

    public static function len(int $l): string
    {
        $o = '';
        do {
            $d = $l % 128;
            $l = intdiv($l, 128);
            if ($l > 0) {
                $d |= 0x80;
            }
            $o .= chr($d);
        } while ($l > 0);
        return $o;
    }

    public function connected(): bool
    {
        return $this->sock !== null;
    }

    public function lastError(): string
    {
        return $this->lastError;
    }

    /** Dauer der (letzten) Verbindung in Sekunden. */
    public function uptime(): int
    {
        if ($this->connectedAt === 0) {
            return 0;
        }
        return ($this->closedAt ?: time()) - $this->connectedAt;
    }

    /** Verbindung beenden und den Grund merken. */
    private function fail(string $reason): bool
    {
        $this->lastError = $reason;
        $this->close();
        return false;
    }

    public function connect(): bool
    {
        $this->lastError = '';
        $this->closedAt = 0;
        $this->sock = @stream_socket_client("tcp://{$this->host}:{$this->port}", $e, $es, 5) ?: null;
        if (!$this->sock) {
            $this->lastError = "TCP-Verbindung zu {$this->host}:{$this->port} fehlgeschlagen ($e $es)";
            return false;
        }
        stream_set_timeout($this->sock, 5);
        $flags = 0x02 | 0x04 | 0x20; // clean session, will, will retain
        if ($this->user !== '') {
            $flags |= 0x80;
        }
        if ($this->pass !== '') {
            $flags |= 0x40;
        }
        $body = self::str('MQTT') . chr(4) . chr($flags) . pack('n', $this->keepalive)
            . self::str($this->clientId) . self::str($this->willTopic) . self::str('0');
        if ($this->user !== '') {
            $body .= self::str($this->user);
        }
        if ($this->pass !== '') {
            $body .= self::str($this->pass);
        }
        if (!$this->write(chr(0x10) . self::len(strlen($body)) . $body)) {
            if ($this->lastError === '') {
                $this->lastError = 'CONNECT konnte nicht gesendet werden';
            }
            return false;
        }
        $ack = '';
        $deadline = microtime(true) + 5;
        while (strlen($ack) < 4 && microtime(true) < $deadline) {
            $d = fread($this->sock, 4 - strlen($ack));
            if ($d === false || ($d === '' && feof($this->sock))) {
                break;
            }
            $ack .= $d;
        }
        if (strlen($ack) < 4 || ord($ack[0]) !== 0x20) {
            return $this->fail('Keine gueltige CONNACK-Antwort vom Broker (evtl. TLS-Port oder kein MQTT)');
        }
        if (ord($ack[3]) !== 0) {
            $reasons = [1 => 'Protokollversion abgelehnt', 2 => 'Client-ID abgelehnt', 3 => 'Broker nicht verfuegbar',
                4 => 'Benutzer/Passwort falsch', 5 => 'nicht autorisiert'];
            return $this->fail('Broker lehnt Verbindung ab: ' . ($reasons[ord($ack[3])] ?? 'Code ' . ord($ack[3]))
                . ($this->user === '' ? ' (ohne Benutzername verbunden)' : " (Benutzer '{$this->user}')"));
        }
        stream_set_blocking($this->sock, false);
        $this->connectedAt = time();
        $this->lastSend = time();
        return true;
    }

    public function publish(string $topic, string $payload, bool $retain): bool
    {
        $body = self::str($topic) . $payload;
        return $this->write(chr(0x30 | ($retain ? 1 : 0)) . self::len(strlen($body)) . $body);
    }

    /**
     * Regelmaessig aufrufen (auch waehrend langer Abfragen): eingehende Pakete verwerfen,
     * Verbindungsabbruch erkennen, Keepalive (PINGREQ) senden.
     */
    public function loop(): bool
    {
        if (!$this->sock) {
            return false;
        }
        for ($i = 0; $i < 50; $i++) {
            $r = [$this->sock];
            $w = $x = null;
            if (@stream_select($r, $w, $x, 0) < 1) {
                break;
            }
            $d = @fread($this->sock, 4096);
            if ($d === '' || $d === false) { // lesbar, aber leer = Broker hat geschlossen
                return $this->fail('Broker hat die Verbindung geschlossen');
            }
        }
        if (time() - $this->lastSend >= max(5, intdiv($this->keepalive, 3))) {
            return $this->write(chr(0xC0) . chr(0));
        }
        return true;
    }

    private function write(string $data): bool
    {
        if (!$this->sock) {
            return false;
        }
        $done = 0;
        $len = strlen($data);
        $deadline = microtime(true) + self::WRITE_TIMEOUT;
        $errors = 0;
        while ($done < $len) {
            $n = @fwrite($this->sock, substr($data, $done));
            if ($n !== false && $n > 0) {
                $done += $n;
                $errors = 0;
                continue;
            }
            // false oder 0: Sendepuffer voll (EAGAIN) - oder echter Fehler
            if (microtime(true) > $deadline) {
                return $this->fail('Senden: Broker nimmt keine Daten mehr an (Zeitueberschreitung)');
            }
            $r = null;
            $w = [$this->sock];
            $x = null;
            $sel = @stream_select($r, $w, $x, 1);
            if ($sel === false) {
                return $this->fail('Senden: Socket-Fehler');
            }
            if (feof($this->sock)) {
                return $this->fail('Broker hat die Verbindung geschlossen');
            }
            if ($sel > 0 && ++$errors > 40) {   // schreibbar, aber fwrite scheitert weiter = Verbindung kaputt
                return $this->fail('Senden fehlgeschlagen (Verbindung zurueckgesetzt)');
            }
            usleep(20000);
        }
        $this->lastSend = time();
        return true;
    }

    public function close(): void
    {
        if ($this->sock) {
            @fwrite($this->sock, chr(0xE0) . chr(0));
            @fclose($this->sock);
            $this->closedAt = time();
        }
        $this->sock = null;
    }
}
