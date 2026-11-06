<?php
/** Minimaler Fake-MQTT-Broker, gibt empfangene PUBLISH-Pakete aus: php tests/fake_mqtt.php [port] */
$port = (int)($argv[1] ?? 18830);
$srv = stream_socket_server("tcp://127.0.0.1:$port", $e, $es);
if (!$srv) {
    fwrite(STDERR, "listen: $es\n");
    exit(1);
}
$clients = [];
echo "listening $port\n";
while (true) {
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
        $type = ord($d[0]) >> 4;
        if ($type === 1) {
            fwrite($s, chr(0x20) . chr(2) . chr(0) . chr(0));
        } elseif ($type === 3) {
            // einfacher Parser (mehrere Pakete pro Read moeglich)
            $o = 0;
            while ($o < strlen($d)) {
                $t = ord($d[$o]) >> 4;
                $o++;
                $len = 0;
                $mul = 1;
                do {
                    $b = ord($d[$o++]);
                    $len += ($b & 127) * $mul;
                    $mul *= 128;
                } while ($b & 128);
                $body = substr($d, $o, $len);
                $o += $len;
                if ($t === 3) {
                    $tl = unpack('n', substr($body, 0, 2))[1];
                    echo substr($body, 2, $tl) . ' = ' . substr($body, 2 + $tl) . "\n";
                }
            }
        } elseif ($type === 12) {
            fwrite($s, chr(0xD0) . chr(0));
        }
    }
}
