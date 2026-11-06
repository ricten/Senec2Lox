<?php
/**
 * Fake-MQTT-Broker mit Client-ID-Verdraengung wie Mosquitto: php tests/takeover_broker.php <port> [sekunden=30]
 * Verbindet sich ein Client mit einer bereits verbundenen Client-ID, wird die aeltere Verbindung geschlossen.
 * Gibt je Verdraengung eine Zeile aus.
 */
$port = (int)($argv[1] ?? 18850);
$secs = (int)($argv[2] ?? 30);
$srv = stream_socket_server("tcp://127.0.0.1:$port", $e, $es);
if (!$srv) {
    fwrite(STDERR, "listen: $es\n");
    exit(1);
}
$clients = [];      // id => socket
$byId = [];         // client id => resource id
$takeovers = 0;
$end = time() + $secs;
while (time() < $end) {
    $r = array_merge([$srv], $clients);
    $w = $x = null;
    if (!stream_select($r, $w, $x, 1)) {
        continue;
    }
    foreach ($r as $s) {
        if ($s === $srv) {
            $c = stream_socket_accept($srv);
            $clients[(int)$c] = $c;
            continue;
        }
        $d = fread($s, 65536);
        if ($d === '' || $d === false) {
            fclose($s);
            unset($clients[(int)$s]);
            continue;
        }
        if ((ord($d[0]) >> 4) === 1) {                 // CONNECT
            $o = 1;
            do {
                $b = ord($d[$o++]);
            } while ($b & 128);
            $o += 2 + 4 + 1 + 1 + 2;                   // Protokollname, Level, Flags, Keepalive
            $len = unpack('n', substr($d, $o, 2))[1];
            $cid = substr($d, $o + 2, $len);
            if (isset($byId[$cid]) && isset($clients[$byId[$cid]])) {
                $takeovers++;
                echo "VERDRAENGT: $cid (Nr. $takeovers)\n";
                fclose($clients[$byId[$cid]]);
                unset($clients[$byId[$cid]]);
            }
            $byId[$cid] = (int)$s;
            fwrite($s, chr(0x20) . chr(2) . chr(0) . chr(0));
        } elseif ((ord($d[0]) >> 4) === 12) {          // PINGREQ
            fwrite($s, chr(0xD0) . chr(0));
        }
    }
}
echo "Verdraengungen gesamt: $takeovers\n";
