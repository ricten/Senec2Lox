<?php
/**
 * Langsamer Fake-MQTT-Broker fuer Robustheitstests: php tests/slow_broker.php <port> [sekunden=20] [kappe-nach-bytes]
 * Liest nur kleine Haeppchen, sodass der Sendepuffer des Clients volllaeuft (provoziert EAGAIN).
 * Optional beendet er die Verbindung nach <kappe-nach-bytes> empfangenen Bytes (simuliert Abbruch).
 */
$port = (int)($argv[1] ?? 18840);
$secs = (int)($argv[2] ?? 20);
$cut = (int)($argv[3] ?? 0);
$srv = stream_socket_server("tcp://127.0.0.1:$port", $e, $es);
if (!$srv) {
    fwrite(STDERR, "listen: $es\n");
    exit(1);
}
$end = time() + $secs;
$total = 0;
$client = null;
while (time() < $end) {
    if (!$client) {
        $r = [$srv];
        $w = $x = null;
        if (stream_select($r, $w, $x, 1)) {
            $client = stream_socket_accept($srv);
            $first = fread($client, 4096);
            $total += strlen($first);
            fwrite($client, chr(0x20) . chr(2) . chr(0) . chr(0));
            echo "client verbunden\n";
        }
        continue;
    }
    $d = fread($client, 8192);
    if ($d === '' || $d === false) {
        echo "client getrennt nach $total Bytes\n";
        $client = null;
        continue;
    }
    $total += strlen($d);
    if ($cut && $total > $cut) {
        echo "kappe Verbindung nach $total Bytes\n";
        fclose($client);
        $client = null;
        $cut = 0;
        continue;
    }
    usleep(8000);    // langsam lesen (ca. 1 MB/s)
}
echo "empfangen: $total Bytes\n";
