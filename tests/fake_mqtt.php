<?php
/** Minimaler Fake-MQTT-Broker, gibt empfangene PUBLISH-Pakete aus: php tests/fake_mqtt.php [port] [verzeichnis]
 *  Mit Verzeichnis: Zeilen "topic<TAB>payload" in <verzeichnis>/inject.txt werden als PUBLISH an alle Abonnenten gesendet (Befehle aus Loxone). */
$port = (int)($argv[1] ?? 18830);
$injf = isset($argv[2]) ? $argv[2] . '/inject.txt' : null;
$subs = [];
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
    if (!stream_select($r, $w, $x, 0, 200000)) {
        if ($injf !== null && is_file($injf)) {
            $zeilen = file($injf, FILE_IGNORE_NEW_LINES);
            @unlink($injf);
            foreach ($zeilen ?: [] as $z) {
                [$t, $pl] = explode("	", $z, 2) + [1 => ''];
                $body = pack('n', strlen($t)) . $t . $pl;
                $len = strlen($body);
                $lenBytes = '';
                do {
                    $enc = $len % 128;
                    $len = intdiv($len, 128);
                    $lenBytes .= chr($len > 0 ? $enc | 128 : $enc);
                } while ($len > 0);
                foreach ($subs as $id => $_) {
                    if (isset($clients[$id])) {
                        @fwrite($clients[$id], chr(0x30) . $lenBytes . $body);
                    }
                }
            }
        }
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
            unset($clients[(int)$s], $subs[(int)$s]);
            continue;
        }
        $type = ord($d[0]) >> 4;
        if ($type === 1) {
            fwrite($s, chr(0x20) . chr(2) . chr(0) . chr(0));
        } elseif ($type === 8) {
            $subs[(int)$s] = true;
            fwrite($s, chr(0x90) . chr(3) . substr($d, 2, 2) . chr(0));       // SUBACK mit der Paket-ID des Clients
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
