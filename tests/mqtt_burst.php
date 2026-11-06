<?php
/** Robustheitstest MqttClient: php tests/mqtt_burst.php <port> [anzahl=6000]  (gegen tests/slow_broker.php) */
require_once __DIR__ . '/../senec2lox/bin/lib/bootstrap.php';

$port = (int)($argv[1] ?? 18840);
$n = (int)($argv[2] ?? 6000);
$c = new MqttClient('127.0.0.1', $port, '', '', 'burst_test', 'senec/online');
if (!$c->connect()) {
    echo "connect fehlgeschlagen: " . $c->lastError() . "\n";
    exit(1);
}
$t = microtime(true);
$payload = str_repeat("x", (int)($argv[3] ?? 100));
for ($i = 0; $i < $n; $i++) {
    if (!$c->publish("senec/raw/TEST/KEY_$i", $payload, true)) {
        echo "FEHLER bei Nachricht $i: " . $c->lastError() . " (Verbindung $i Nachrichten alt, " . $c->uptime() . " s)\n";
        exit(2);
    }
    if ($i % 50 === 0 && !$c->loop()) {
        echo "FEHLER in loop() bei $i: " . $c->lastError() . "\n";
        exit(3);
    }
}
printf("OK: %d Nachrichten in %.1f s gesendet, Verbindung steht: %s\n", $n, microtime(true) - $t, $c->connected() ? 'ja' : 'nein');
