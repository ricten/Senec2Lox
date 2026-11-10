<?php
/** Einfacher Test-Runner ohne Abhaengigkeiten: php tests/run.php */
require_once __DIR__ . '/../senec2lox/bin/lib/bootstrap.php';

$fails = 0;
$count = 0;
function check(string $name, $actual, $expected): void
{
    global $fails, $count;
    $count++;
    $ok = is_float($expected) && is_numeric($actual) ? abs($actual - $expected) < 1e-9 : $actual === $expected;
    if (!$ok) {
        $fails++;
        echo "FAIL $name: erwartet " . var_export($expected, true) . ", erhalten " . var_export($actual, true) . "\n";
    }
}

// --- SenecDecode (Spec 6.2) ---
check('fl pos', SenecDecode::value('fl_43C80000'), 400.0);
check('fl neg', SenecDecode::value('fl_C3480000'), -200.0);
check('u8', SenecDecode::value('u8_04'), 4);
check('u6', SenecDecode::value('u6_00FF'), 255);
check('i1 -1', SenecDecode::value('i1_FFFF'), -1);
check('i3 -100', SenecDecode::value('i3_FFFFFF9C'), -100);
check('i8 -1', SenecDecode::value('i8_FF'), -1);
check('i1 pos', SenecDecode::value('i1_0010'), 16);
check('st', SenecDecode::value('st_abc'), 'abc');
check('ch mit _', SenecDecode::value('ch_a_b'), 'a_b');
check('er', SenecDecode::value('er_12'), null);
check('absent null', SenecDecode::value('VARIABLE_NOT_FOUND'), null);
check('absent flag', SenecDecode::isAbsent('VARIABLE_NOT_FOUND'), true);
check('absent flag2', SenecDecode::isAbsent('OBJECT_NOT_FOUND'), true);
check('absent kein', SenecDecode::isAbsent('u8_01'), false);
check('malformed', SenecDecode::value('MALFORMED_VALUE'), null);
check('file', SenecDecode::value('FILE_VARIABLE_NOT_READABLE'), '');
check('under_voltage kein Zahlpraefix', SenecDecode::value('u9_FF'), null);
check('fl nan', SenecDecode::value('fl_7FC00000'), null);
check('fl kaputt', SenecDecode::value('fl_XYZ'), null);
check('array', SenecDecode::value(['fl_43C80000', 'u8_01']), [400.0, 1]);

// --- Mapper: Formatierung / Ableitungen ---
check('fmt float', Mapper::fmt(400.0), '400');
check('fmt neg', Mapper::fmt(-200.5), '-200.5');
check('fmt bool', Mapper::fmt(true), '1');
check('fmt null', Mapper::fmt(null), null);
$o = [];
Mapper::flatten('raw/PM1OBJ1/U_AC', [230.1, 231.0, 229.9], $o);
check('flatten', $o, ['raw/PM1OBJ1/U_AC/0' => '230.1', 'raw/PM1OBJ1/U_AC/1' => '231', 'raw/PM1OBJ1/U_AC/2' => '229.9']);

$d = Mapper::derive(['netz_w' => -300.0, 'batterie_w' => 150.0]);
check('einspeisung', $d['netz_einspeisung_w'], 300.0);
check('bezug 0', $d['netz_bezug_w'], 0.0);
check('laden', $d['batterie_laden_w'], 150.0);
check('entladen 0', $d['batterie_entladen_w'], 0.0);

// --- OpenAPI Grid-Logik (Spec 7.4) ---
check('grid negativ uebernehmen', Mapper::openApiGrid(-200.0, 500.0, 900.0, 0.0, 0.0), -200.0);
check('grid bezug', Mapper::openApiGrid(91.0, 1181.0, 244.0, -850.8, 0.0), 91.0);
check('grid bilanz ~0', Mapper::openApiGrid(20.0, 500.0, 480.0, 0.0, 0.0), 0.0);
check('grid einspeisung aus gridPower betrag', Mapper::openApiGrid(300.0, 500.0, 900.0, 100.0, 0.0), -300.0);
check('grid einspeisung aus surplus', Mapper::openApiGrid(0.0, 500.0, 2000.0, 0.0, 1400.0), -1400.0);
check('grid null', Mapper::openApiGrid(null, 1.0, 1.0, 1.0, 1.0), null);

// --- Quellenpriorität ---
$snap = ['lala' => ['ts' => 1000, 'interval' => 10], 'openapi' => ['ts' => 990, 'interval' => 60]];
check('prio lala aktuell', Mapper::pickSource(['lala', 'openapi', 'connect'], $snap, 1020), 'lala');
check('prio fallback', Mapper::pickSource(['lala', 'openapi', 'connect'], $snap, 1031), 'openapi');
check('prio keine', Mapper::pickSource(['lala', 'openapi', 'connect'], $snap, 5000), null);
check('prio ts 0', Mapper::pickSource(['lala'], ['lala' => ['ts' => 0, 'interval' => 10]], 5), null);

// --- Mapper::build mit lala-Daten ---
$cfg = Config::validate(Config::defaults());
$lala = ['ENERGY' => ['GUI_INVERTER_POWER' => 1200.0, 'GUI_GRID_POW' => -400.0, 'STAT_STATE' => 14, 'GUI_BAT_DATA_POWER' => 100.0]];
$b = Mapper::build($cfg, ['lala' => ['ts' => 100, 'interval' => 10, 'data' => $lala]], ['lala' => ['ok' => true, 'status' => 'ok']], 105, null);
check('live pv', $b['topics']['live/pv_w'], '1200');
check('live einspeisung', $b['topics']['live/netz_einspeisung_w'], '400');
check('live status text', $b['topics']['live/status_text'], 'LADEN (14)');
check('live quelle', $b['topics']['live/quelle'], 'lala');
check('raw topic', $b['topics']['raw/ENERGY/STAT_STATE'], '14');
check('lala ok', $b['topics']['lala/ok'], '1');

// --- Config ---
$c = Config::merge(Config::defaults(), ['lala' => ['interval' => '1'], 'mqtt' => ['topic_prefix' => 'a/#'], 'unbekannt' => 5]);
$c = Config::validate($c);
check('interval min', $c['lala']['interval'], 3);
check('prefix ungueltig', $c['mqtt']['topic_prefix'], 'senec');
check('merge ergaenzt defaults', $c['openapi']['interval'], 60);
$c = Config::validate(Config::merge(Config::defaults(), ['lala' => ['full_sections' => 'ENERGY,STATISTIC,BMS;x']]));
check('STATISTIC entfernt', $c['lala']['full_sections'], 'ENERGY');
$c = Config::validate(Config::merge(Config::defaults(), ['connect' => ['include' => 'battery']]));
check('bessNameplate erzwungen', $c['connect']['include'], 'battery,bessNameplate');

// --- MQTT-Längenkodierung ---
check('len 0', bin2hex(MqttClient::len(0)), '00');
check('len 127', bin2hex(MqttClient::len(127)), '7f');
check('len 128', bin2hex(MqttClient::len(128)), '8001');
check('len 16383', bin2hex(MqttClient::len(16383)), 'ff7f');
check('len 16384', bin2hex(MqttClient::len(16384)), '808001');
check('str', bin2hex(MqttClient::str('ab')), '00026162');

// --- lala Request-Bodies ---
$bodies = LalaSource::buildBodies(LalaSource::FAST);
check('fast ein Body', count($bodies), 1);
check('fast klein', strlen($bodies[0]) < LalaSource::MAX_BODY, true);
$j = json_decode(LalaSource::buildBodies(['FACTORY' => []])[0], true);
check('leere Sektion als Objekt', json_encode($j), '[]' === json_encode($j) ? '[]' : '{"FACTORY":[]}');
check('leere Sektion json', LalaSource::buildBodies(['FACTORY' => []])[0], '{"FACTORY":{}}');
$many = [];
for ($i = 0; $i < 3; $i++) {
    $many["S$i"] = array_map(function ($n) { return "KEY_$n"; }, range(1, 300));
}
$parts = LalaSource::buildBodies($many, 8000);
check('Aufteilung', count($parts) > 1, true);

// --- Connect ---
$sys = [['bessNameplate' => ['serial_number' => 'v4-1', 'system_id' => 'P4H1-A']], ['bessNameplate' => ['serial_number' => 'v4-2']]];
$p = ConnectSource::parse(json_encode($sys));
check('connect 2 Anlagen', array_keys($p), ['v4-1', 'v4-2']);
$p = ConnectSource::parse(json_encode($sys), 'P4H1-A');
check('connect Filter system_id', array_keys($p), ['v4-1']);
check('topicId', ConnectSource::topicId('a b/c#'), 'a_b_c_');
check('connect kaputt', ConnectSource::parse('nope'), null);

// --- StateTexts ---
check('state 14', StateTexts::text(14), 'LADEN (14)');
check('state 13', StateTexts::text(13), 'AKKU VOLL (13)');
check('state unbekannt', StateTexts::text(9999), 'UNBEKANNT (9999)');

// --- State / TOFU ---
$tmp = sys_get_temp_dir() . '/senec_state_' . getmypid() . '.json';
@unlink($tmp);
$st = new State($tmp);
check('fp erst', $st->checkFingerprint('fp', 'aa'), false);
check('fp gleich', $st->checkFingerprint('fp', 'aa'), false);
check('fp geaendert', $st->checkFingerprint('fp', 'bb'), true);
$st2 = new State($tmp);
check('state persistent', $st2->get('fp'), 'bb');
@unlink($tmp);

// --- Schnellabfrage-Konfiguration / Protokoll ---
check('parseFast', Config::parseFast("ENERGY=A_B, C\nbad line\nSTATISTIC=X\nPV1=MPP_POWER"), ['ENERGY' => ['A_B', 'C'], 'PV1' => ['MPP_POWER']]);
check('parseFast leer', Config::parseFast(''), []);
check('formatFast', Config::formatFast(['ENERGY' => ['A', 'B']]), 'ENERGY=A,B');
$c = Config::validate(Config::merge(Config::defaults(), ['lala' => ['scheme' => 'ftp', 'fast_sections' => "ENERGY=X\nmuell"]]));
check('scheme ungueltig', $c['lala']['scheme'], 'auto');
check('fast validiert', $c['lala']['fast_sections'], 'ENERGY=X');
check('default scheme', Config::validate(Config::defaults())['lala']['scheme'], 'auto');

// --- Katalog (Auswahl in der Weboberflaeche) ---
$flat = Catalog::fastFlat();
$missing = [];
foreach (LalaSource::FAST as $sec => $keys) {
    foreach ($keys as $k) {
        if (!in_array("$sec.$k", $flat, true)) {
            $missing[] = "$sec.$k";
        }
    }
}
check('Standard-Schnellabfrage komplett im Katalog', $missing, []);
check('keine doppelten Schnell-Eintraege', count($flat), count(array_unique($flat)));
check('STATISTIC nicht im Katalog', in_array('STATISTIC', Catalog::fullFlat(), true), false);
$defFull = explode(',', Config::defaults()['lala']['full_sections']);
check('Standard-Vollabfrage im Katalog', array_values(array_diff($defFull, Catalog::fullFlat())), []);
check('keine doppelten Sektionen', count(Catalog::fullFlat()), count(array_unique(Catalog::fullFlat())));

// --- Wallbox-Livewerte ---
$wl = Mapper::liveFromLala(['WALLBOX' => ['APPARENT_CHARGING_POWER' => [3700.5, 0.0, 0.0, 0.0], 'EV_CONNECTED' => [1, 0, 0, 0], 'STATE' => [6, 0, 0, 0]]]);
check('wallbox_w Summe', $wl['wallbox_w'], 3700.5);
check('wallbox verbunden', $wl['wallbox_fahrzeug_verbunden'], 1);
check('wallbox status', $wl['wallbox_status_code'], 6);
$wl = Mapper::liveFromLala(['WALLBOX' => ['EV_CONNECTED' => [0, 0, 0, 0]]]);
check('wallbox nicht verbunden', $wl['wallbox_fahrzeug_verbunden'], 0);
check('ohne wallbox keine Topics', array_key_exists('wallbox_w', Mapper::liveFromLala(['ENERGY' => ['GUI_GRID_POW' => 1.0]])), false);
check('wallbox im Katalog', in_array('WALLBOX.STATE', Catalog::fastFlat(), true), true);

// --- Gateway-Hilfen (Kopie von common/php/GatewayInfo.php; eigene Tests dort: common/tests/run.php) ---
check('GatewayInfo: senec/# deckt senec/live/pv_w', GatewayInfo::covers('senec/#', 'senec/#'), true);
check('GatewayInfo: senec/live/# deckt senec/# nicht', GatewayInfo::covers('senec/live/#', 'senec/#'), false);
check('GatewayInfo: Standard-UDP-Port', GatewayInfo::udpPort(null, []), 11884);

// --- Healthcheck (LoxBerry-Healthcheck-Seite) ---
$php = PHP_BINARY;
$root = dirname(__DIR__);
$work = sys_get_temp_dir() . '/senec_test_' . getmypid();
$hcDir = $work . '/hc';
@mkdir($hcDir . '/run', 0777, true);
$hc = function (string $arg, ?array $status) use ($php, $root, $hcDir): array {
    $f = $hcDir . '/run/status.json';
    @unlink($f);
    if ($status !== null) {
        file_put_contents($f, json_encode($status));
    }
    $p = proc_open([$php, $root . '/senec2lox/bin/healthcheck', $arg], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null,
        array_merge(getenv(), ['SENEC2LOX_DEV_DIR' => $hcDir]));
    $out = (string)stream_get_contents($pipes[1]);
    $err = (string)stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $rc = proc_close($p);
    return [$out, $err, $rc];
};
$ok = ['ok' => true, 'status' => 'ok', 'error' => null, 'time' => time()];
$basis = ['service' => 'running', 'time' => time(), 'mqtt' => true, 'mqtt_error' => null, 'duplicates' => [],
    'live_source' => 'lala', 'sources' => ['lala' => $ok, 'openapi' => $ok]];
[$o, $e, $rc] = $hc('title', null);
check('Healthcheck title', trim($o), 'Senec2Lox: Dienst, MQTT-Broker und SENEC-Abfrage');
check('Healthcheck title: Exitcode 0, keine Fehlerausgabe', [$rc, $e], [0, '']);
check('Healthcheck ohne Argument: keine Ausgabe', $hc('', null)[0], '');
$res = function (?array $st) use ($hc): array {
    [$o, $e, $rc] = $hc('check', $st);
    $j = json_decode($o, true);
    return [is_array($j) ? $j : ['status' => -1, 'result' => $o], $rc, $e];
};
[$j, $rc, $e] = $res(null);
check('Healthcheck: ohne Status = Fehler 3', $j['status'], 3);
check('Healthcheck: Dienst laeuft nicht im Text', str_contains($j['result'], 'Der Dienst läuft nicht'), true);
check('Healthcheck: Exitcode 0, keine Fehlerausgabe', [$rc, $e], [0, '']);
check('Healthcheck: veralteter Status (3 min) = Fehler 3', $res(['time' => time() - 180] + $basis)[0]['status'], 3);
check('Healthcheck: 60 s alter Status (langsame Quelle) noch ok', $res(['time' => time() - 60] + $basis)[0]['status'], 5);
$j = $res(['service' => 'disabled', 'time' => time()])[0];
check('Healthcheck: abgeschaltet = Info 6', $j['status'], 6);
check('Healthcheck: abgeschaltet im Text', str_contains($j['result'], 'abgeschaltet'), true);
$j = $res(['mqtt' => false, 'mqtt_error' => 'Verbindung abgelehnt'] + $basis)[0];
check('Healthcheck: Broker weg = Warnung 4', $j['status'], 4);
check('Healthcheck: Broker-Grund im Text', str_contains($j['result'], 'Verbindung abgelehnt'), true);
$j = $res(['duplicates' => ['senec2lox_000']] + $basis)[0];
check('Healthcheck: zweite Instanz = Warnung 4', $j['status'], 4);
check('Healthcheck: zweite Instanz im Text', str_contains($j['result'], 'senec2lox_000'), true);
check('Healthcheck: keine Quelle = Info 6', $res(['sources' => []] + $basis)[0]['status'], 6);
$j = $res(['sources' => ['lala' => $ok, 'openapi' => ['ok' => false, 'status' => 'unreachable', 'error' => 'Zeitueberschreitung', 'time' => time()]]] + $basis)[0];
check('Healthcheck: Quelle ohne Antwort = Warnung 4', $j['status'], 4);
check('Healthcheck: Quelle und Grund im Text', str_contains($j['result'], 'openapi (unreachable: Zeitueberschreitung)'), true);
$j = $res($basis)[0];
check('Healthcheck: alles gut = OK 5', $j['status'], 5);
check('Healthcheck: Text zaehlt Quellen und nennt Live-Quelle', $j['result'], '2 Quelle(n) in Ordnung, Live-Werte aus lala');
check('Healthcheck: ohne Logdatei kein logfile', isset($j['logfile']), false);
file_put_contents($hcDir . '/senec2lox.log', "x\n");
check('Healthcheck: mit Logdatei der Pfad', $res($basis)[0]['logfile'] ?? null, $hcDir . '/senec2lox.log');
check('Healthcheck: Ausgabe ist genau eine JSON-Zeile', substr_count(rtrim($hc('check', $basis)[0], "\n"), "\n"), 0);

// --- Weboberflaeche mit Attrappen der LoxBerry-Bibliotheken ---
// Rendert jeden Reiter (ohne echtes LoxBerry): findet undefinierte Variablen, Syntax- und Laufzeitfehler und prueft Menueleiste
// und Gateway-Abo. Nicht pruefbar: das echte Aussehen in LoxBerry (jQuery Mobile).
$uiDir = $work . '/ui';
foreach (['config', 'log', 'data', 'sys', 'dev', 'sess'] as $sub) {
    @mkdir("$uiDir/$sub", 0777, true);
}
$uiPage = function (string $tab, array $env = []) use ($php, $root, $uiDir): array {
    $e = array_merge(getenv(), [
        'STUB_INDEX' => $root . '/senec2lox/webfrontend/htmlauth/index.php', 'STUB_BIN' => $root . '/senec2lox/bin', 'STUB_CONFIG' => "$uiDir/config",
        'STUB_LOG' => "$uiDir/log", 'STUB_DATA' => "$uiDir/data", 'STUB_SYSCONFIG' => "$uiDir/sys", 'SENEC2LOX_DEV_DIR' => "$uiDir/dev",
    ], $env);
    $cmd = [$php, '-d', 'display_errors=1', '-d', 'error_reporting=-1', '-d', 'include_path="' . $root . '/tests/stubs"', '-d', 'session.save_path="' . "$uiDir/sess" . '"',
        $root . '/tests/ui_page.php', $tab];
    $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $e);
    $out = (string)stream_get_contents($pipes[1]);
    $err = (string)stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($p);
    return [$out, $err];
};
$bad = fn(string $o, string $e) => (bool)preg_match('/(Warning|Notice|Fatal error|Deprecated|Parse error|Uncaught)/', $o . $e);
file_put_contents("$uiDir/sys/general.json", json_encode(['Mqtt' => ['Gatewayversion' => 2]]));
file_put_contents("$uiDir/sys/subscriptions.json", json_encode(['Subscriptions' => [['Id' => 'shellies/#']]]));
foreach (['einstellungen', 'mqtt', 'loxone', 'test', 'logs'] as $tab) {
    [$o, $e] = $uiPage($tab);
    check("UI $tab: ohne PHP-Warnungen und -Fehler", [$bad($o, $e), $e], [false, '']);
    check("UI $tab: Kopf und Fuss", str_contains($o, '<!--HEADER:') && str_contains($o, '<!--FOOTER-->'), true);
    preg_match('/<!--NAVBAR:(.*?)-->/', $o, $m);
    $nav = json_decode($m[1] ?? '[]', true) ?: [];
    check("UI $tab: Menueleiste mit 5 Eintraegen", count($nav), 5);
    $aktiv = array_values(array_filter($nav, fn($n) => !empty($n['active'])));
    check("UI $tab: genau ein aktiver Eintrag, und zwar dieser", [count($aktiv), $aktiv[0]['URL'] ?? null], [1, '?tab=' . $tab]);
    check("UI $tab: kein eigener Tab-Block mehr", str_contains($o, 's2m-tabs'), false);
    // Alle Bereiche bleiben im Formular (sonst gingen beim Speichern Felder verloren); nur der gewaehlte ist sichtbar
    check("UI $tab: alle fuenf Bereiche vorhanden", preg_match_all('/class="s2m-pane" data-tab="/', $o), 5);
    check("UI $tab: genau vier Bereiche ausgeblendet", preg_match_all('/class="s2m-pane" data-tab="[a-z]+" style="display:none"/', $o), 4);
    check("UI $tab: dieser Bereich sichtbar", preg_match('/class="s2m-pane" data-tab="' . $tab . '">/', $o), 1);
}
[$o] = $uiPage('einstellungen');
check('UI einstellungen: Formularfelder aus MQTT-Bereich sind trotzdem im Formular', str_contains($o, 'name="mqtt_prefix"'), true);
// Design System statt jQuery Mobile
foreach (['einstellungen', 'mqtt', 'loxone', 'test', 'logs'] as $tab) {
    [$o] = $uiPage($tab);
    check("UI $tab: Kopf ohne jQuery Mobile (nojqm)", str_contains($o, '<!--NOJQM:1-->'), true);
    check("UI $tab: Inhalt im Design System (lb-content), kein data-role/data-ajax", [str_contains($o, 'class="lb-content s2m"'), str_contains($o, 'data-role='), str_contains($o, 'data-ajax=')], [true, false, false]);
}
[$o] = $uiPage('einstellungen');
check('UI einstellungen: Feldnamen unveraendert (Formular)', [str_contains($o, 'name="enabled"'), str_contains($o, 'name="lala_host"'), str_contains($o, 'name="lala_scheme"'), str_contains($o, 'name="openapi_include[]"'), str_contains($o, 'name="connect_key"'), str_contains($o, 'name="mqtt_pass"'), str_contains($o, 'name="live_source_priority"'), str_contains($o, 'name="csrf"')], array_fill(0, 8, true));
check('UI einstellungen: Schalter und Eingabefelder im Design System', [str_contains($o, 'class="lb-toggle"'), str_contains($o, 'class="lb-input"'), str_contains($o, 'class="lb-select"'), str_contains($o, 'lb-btn lb-btn-primary')], [true, true, true, true]);
check('UI einstellungen: Auswahl Protokoll mit gewaehltem Wert', str_contains($o, '<option value="auto" selected>'), true);
[$o] = $uiPage('unbekannt');
check('UI: unbekannter Reiter faellt auf Einstellungen zurueck', preg_match('/data-tab="einstellungen">/', $o), 1);
[$o] = $uiPage('loxone');
check('UI loxone: Gateway V2, Abo fehlt, mit Anleitung', str_contains($o, 'Gateway V2: Das Abo „senec/#“ fehlt'), true);
[$o] = $uiPage('mqtt');
check('UI mqtt: nichts abonniert = rotes Feld ohne Topic-Liste', str_contains($o, 'gw-abo-fehler') && !str_contains($o, 'gw-abo-ok') && !str_contains($o, '<ul style="margin:4px 0 0 18px'), true);
file_put_contents("$uiDir/sys/subscriptions.json", json_encode(['Subscriptions' => [['Id' => 'senec/#']]]));
[$o] = $uiPage('loxone');
check('UI loxone: Gateway V2, Abo eingetragen', str_contains($o, 'Gateway V2: eingetragen (senec/#)'), true);
[$o] = $uiPage('mqtt');
check('UI mqtt: vollstaendig abonniert = gruenes Feld mit Topic-Liste', str_contains($o, 'gw-abo-ok') && str_contains($o, '<li><code>senec/#</code></li>') && !str_contains($o, 'gw-abo-fehler'), true);
file_put_contents("$uiDir/sys/subscriptions.json", json_encode(['Subscriptions' => [['Id' => 'senec/live/#'], ['Id' => 'senec/online']]]));
[$o] = $uiPage('mqtt');
check('UI mqtt: nur ein Teil abonniert = gruen (nicht rot)', str_contains($o, 'gw-abo-ok') && !str_contains($o, 'gw-abo-fehler'), true);
check('UI mqtt: Teil-Abo: Hinweis und beide Topics aufgelistet', str_contains($o, 'teilweise abonniert (senec/live/#, senec/online)') && str_contains($o, '<li><code>senec/live/#</code></li>') && str_contains($o, '<li><code>senec/online</code></li>'), true);
file_put_contents("$uiDir/sys/subscriptions.json", json_encode(['Subscriptions' => [['Id' => 'senec/#']]]));
[$o] = $uiPage('test');
check('UI test: Gateway-Abo auch im Reiter Test', str_contains($o, 'Gateway V2: eingetragen (senec/#)'), true);
file_put_contents("$uiDir/sys/general.json", json_encode(['Mqtt' => ['Gatewayversion' => 1]]));
file_put_contents("$uiDir/config/mqtt_subscriptions.cfg", "senec/#\n");
[$o] = $uiPage('loxone');
check('UI loxone: Gateway V1, Abo in der Datei = gruen', str_contains($o, 'Gateway V1: eingetragen (senec/#)') && str_contains($o, 'gw-abo-ok'), true);
check('UI loxone: Tabelle Status und Lebenszeichen mit Eingangsnamen', str_contains($o, '<td>senec_status_zaehler</td>') && str_contains($o, '<td>senec_status_ok</td>') && str_contains($o, '<td>senec_status_ts</td>') && str_contains($o, '<td>senec_online</td>'), true);
$dflt = trim((string)file_get_contents($root . '/senec2lox/config/mqtt_subscriptions.cfg'));
check('Mitgelieferte Abo-Datei: senec/# (eine Zeile, kein INI)', $dflt, 'senec/#');

// --- Installationspaket ---
$pcfg = (string)file_get_contents($root . '/senec2lox/plugin.cfg');
check('plugin.cfg: automatische Updates mit RELEASECFG (raw-URL der release.cfg im Repo)', [str_contains($pcfg, "\nAUTOMATIC_UPDATES=true"), str_contains($pcfg, "\nRELEASECFG=https://raw.githubusercontent.com/ricten/Senec2Lox/main/release.cfg"), str_contains($pcfg, "\nPRERELEASECFG=\n")], [true, true, true]);
check('release.cfg gehoert nicht ins Plugin-Verzeichnis (liegt im Repo-Wurzelordner)', is_file($root . '/senec2lox/release.cfg'), false);
check('plugin.cfg: WEBSITE gesetzt (sonst ist der Dokumentation-Knopf der Plugin-Verwaltung ausgegraut)', str_contains($pcfg, "
WEBSITE=https://github.com/ricten/Senec2Lox
"), true);
check('plugin.cfg: Loglevel-Auswahl der Plugin-Verwaltung frei', str_contains((string)file_get_contents($root . '/senec2lox/plugin.cfg'), "\nCUSTOM_LOGLEVELS=true"), true);
foreach ([[7, 'debug'], [6, 'info'], [5, 'info'], [4, 'warn'], [3, 'error'], [1, 'error']] as [$lb, $name]) {
    $lg = new Logger(null);
    $lg->setLoxberryLevel($lb);
    $ref = new ReflectionProperty($lg, 'level');
    check("Logger: LoxBerry-Stufe $lb = $name", $ref->getValue($lg), ['debug' => 4, 'info' => 3, 'warn' => 2, 'error' => 1][$name]);
}
[$o] = $uiPage('einstellungen');
check('UI: keine eigene Loglevel-Auswahl mehr, Hinweis auf die Plugin-Verwaltung', !str_contains($o, 'name="log_level"') && str_contains($o, 'LoxBerry-Plugin-Verwaltung eingestellt'), true);
check('kein leeres preinstall.sh', is_file($root . '/senec2lox/preinstall.sh'), false);

// --- Dienst gegen Fake-Speicher (lala.cgi) und Fake-Broker: Status und Lebenszeichen ---
// Der Dienst braucht die PHP-Erweiterung curl (auf dem LoxBerry vorhanden). Lokal ohne curl wird der Test uebersprungen und gemeldet.
$curlArgs = [];
if (!function_exists('curl_init')) {
    $extDir = dirname(PHP_BINARY) . '/ext';
    if (is_file($extDir . '/php_curl.dll')) {
        $curlArgs = ['-d', 'extension_dir=' . $extDir, '-d', 'extension=curl'];
    } else {
        $curlArgs = null;
    }
}
if ($curlArgs === null) {
    echo "HINWEIS: PHP ohne curl - Dienst-Test (status/ok, status/zaehler, status/ts) uebersprungen\n";
} else {
    $e2e = $work . '/e2e';
    @mkdir($e2e, 0777, true);
    file_put_contents($e2e . '/senec2lox.json', json_encode(['enabled' => true,
        'lala' => ['use' => true, 'host' => '127.0.0.1:18981', 'interval' => 2, 'scheme' => 'http'],
        'mqtt' => ['use_loxberry_broker' => false, 'host' => '127.0.0.1', 'port' => 18930, 'topic_prefix' => 'senec', 'retain' => true]]));
    $start = function (array $args, string $out) use ($php, $e2e) {
        return proc_open(array_merge([$php], $args), [1 => ['file', $out, 'w'], 2 => ['file', $out . '.err', 'w']], $pp,
            null, array_merge(getenv(), ['SENEC2LOX_DEV_DIR' => $e2e]));
    };
    $e2e_stop = function ($p): void {
        if (is_resource($p)) {
            proc_terminate($p);
            proc_close($p);
        }
    };
    $mq = $start([$root . '/tests/fake_mqtt.php', '18930'], $e2e . '/mqtt.out');
    $la = $start(['-S', '127.0.0.1:18981', $root . '/tests/fake_lala.php'], $e2e . '/lala.out');
    usleep(800000);
    $dm = $start(array_merge($curlArgs, [$root . '/senec2lox/bin/senec2lox.php']), $e2e . '/daemon.out');
    $mqttLog = fn(): string => (string)@file_get_contents($e2e . '/mqtt.out');
    $deadline = time() + 25;
    while (time() < $deadline && !preg_match('~senec/status/zaehler = 2~', $mqttLog())) {
        usleep(300000);
    }
    $m = $mqttLog();
    $e2e_stop($dm);
    $e2e_stop($la);
    $e2e_stop($mq);
    check('Dienst: Live-Wert kommt an (senec/live/pv_w)', (bool)preg_match('~senec/live/pv_w = 1800~', $m), true);
    check('Dienst: status/ok = 1 bei aktuellen Live-Werten', (bool)preg_match('~senec/status/ok = 1~', $m), true);
    check('Dienst: status/zaehler zaehlt weiter (1, dann 2)', (bool)preg_match('~senec/status/zaehler = 1\b.*senec/status/zaehler = 2~s', $m), true);
    check('Dienst: status/ts ist ein Unix-Zeitstempel', (bool)preg_match('~senec/status/ts = (\d{10})~', $m, $tm) && abs((int)$tm[1] - time()) < 60, true);
}

// Aufraeumen
function rrmdir(string $d): void
{
    if (!is_dir($d)) {
        return;
    }
    foreach (scandir($d) as $f) {
        if ($f === "." || $f === "..") {
            continue;
        }
        is_dir("$d/$f") ? rrmdir("$d/$f") : @unlink("$d/$f");
    }
    @rmdir($d);
}
rrmdir($work);

echo "$count Tests, $fails Fehler\n";
exit($fails ? 1 : 0);
