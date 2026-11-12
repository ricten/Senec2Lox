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
check('Wallbox-Typen: nur die SENEC Wallbox pro S (HW_TYPE 20) ist bekannt', [WallboxTexts::gilt(20), WallboxTexts::gilt('20'), WallboxTexts::gilt(21), WallboxTexts::gilt(null), WallboxTexts::gilt('x')], [true, true, false, false, false]);
check('Wallbox-Status: Texte aus dem ioBroker-Adapter', [WallboxTexts::text(161), WallboxTexts::text(177), WallboxTexts::text(194), WallboxTexts::text(243), WallboxTexts::text(7)], ['Nicht verbunden (161)', 'Verbunden (177)', 'Lädt (194)', 'Fehlerstrom erkannt (243)', 'UNBEKANNT (7)']);

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

[$o] = $uiPage('einstellungen');
check('UI einstellungen: Wallbox-Typ je Wallbox (vier Auswahlfelder, automatisch gewaehlt)', [str_contains($o, 'Wallboxen (Typ je Wallbox)'), substr_count($o, 'name="wb_typ'), substr_count($o, '<option value="auto" selected>automatisch erkennen</option>') >= 4, str_contains($o, 'SENEC Wallbox pro S (mit Status-Texten)')], [true, 4, true, true]);
check('UI einstellungen: Karte Steuerung mit Warnhinweis, Bestaetigung und drei Gruppen (ab Werk alles aus)', [str_contains($o, 'Steuerung (Befehle aus Loxone)'), str_contains($o, 'Nur auf eigene Gefahr'), str_contains($o, 'name="ctl_warnung" id="ctl_warnung" value="1">'),
    str_contains($o, 'name="ctl_zwangsladung" id="ctl_zwangsladung" value="1">'), str_contains($o, 'name="ctl_steckdosen" id="ctl_steckdosen" value="1">'), str_contains($o, 'name="ctl_wallbox" id="ctl_wallbox" value="1">'), str_contains($o, 'ctl_warnung" id="ctl_warnung" value="1" checked')], [true, true, true, true, true, true, false]);
[$o] = $uiPage('loxone');
check('UI loxone: Befehlstabelle mit Topics, Quittung, gesperrt', [str_contains($o, 'Befehle aus Loxone (Steuerung)'), str_contains($o, 'senec/befehl/zwangsladung'), str_contains($o, 'senec/befehl/entladung_freigeben'), str_contains($o, 'steckdose&lt;N&gt;/einschaltschwelle_w'), str_contains($o, 'wallbox&lt;N&gt;/ladestrom_max_a'),
    str_contains($o, 'quittung'), substr_count($o, '<span class="s2m-bad">nein</span>') >= 3, str_contains($o, 'befehl/reboot')], [true, true, true, true, true, true, true, false]);
file_put_contents("$uiDir/dev/senec2lox.json", json_encode(['wallboxen' => ['typ2' => 'pro_s', 'typ3' => 'andere'], 'control' => ['warnung_ok' => true, 'zwangsladung' => true, 'steckdosen' => false, 'wallbox' => true]]));
[$o] = $uiPage('einstellungen');
check('UI einstellungen: gespeicherter Wallbox-Typ steht gewaehlt', [str_contains($o, '<select class="lb-select" name="wb_typ2" id="wb_typ2"><option value="auto">automatisch erkennen</option><option value="pro_s" selected>'), str_contains($o, 'name="wb_typ3" id="wb_typ3"><option value="auto">automatisch erkennen</option><option value="pro_s">SENEC Wallbox pro S (mit Status-Texten)</option><option value="andere" selected>')], [true, true]);
check('UI einstellungen: gespeicherte Freigaben stehen angehakt', [str_contains($o, 'name="ctl_warnung" id="ctl_warnung" value="1" checked'), str_contains($o, 'name="ctl_zwangsladung" id="ctl_zwangsladung" value="1" checked'), str_contains($o, 'name="ctl_steckdosen" id="ctl_steckdosen" value="1">'), str_contains($o, 'name="ctl_wallbox" id="ctl_wallbox" value="1" checked')], [true, true, true, true]);
[$o] = $uiPage('loxone');
check('UI loxone: Freigabe je Gruppe sichtbar (Zwangsladung und Wallbox ja, Steckdosen nein)', [str_contains($o, 'Zwangsladung und Entladung freigeben: <span class="s2m-ok">ja</span>'), str_contains($o, 'Steckdosen: <span class="s2m-bad">nein</span>'), str_contains($o, 'Wallbox: <span class="s2m-ok">ja</span>')], [true, true, true]);
@unlink("$uiDir/dev/senec2lox.json");

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
    $ref->setAccessible(true);    // vor PHP 8.1 noetig
    check("Logger: LoxBerry-Stufe $lb = $name", $ref->getValue($lg), ['debug' => 4, 'info' => 3, 'warn' => 2, 'error' => 1][$name]);
}
[$o] = $uiPage('einstellungen');
check('UI: keine eigene Loglevel-Auswahl mehr, Hinweis auf die Plugin-Verwaltung', !str_contains($o, 'name="log_level"') && str_contains($o, 'LoxBerry-Plugin-Verwaltung eingestellt'), true);
check('preinstall.sh ist nicht leer, sondern prueft PHP 7.4', is_file($root . '/senec2lox/preinstall.sh') && str_contains((string)file_get_contents($root . '/senec2lox/preinstall.sh'), 'PHP_VERSION_ID'), true);

// --- Mehrere Wallboxen (bis zu vier): Werte je Wallbox ---
$wb = ['WIZARD' => ['SETUP_NUMBER_WALLBOXES' => 2], 'WALLBOX' => ['APPARENT_CHARGING_POWER' => [0.0, 3700.0, 0.0, 0.0], 'EV_CONNECTED' => [0, 1, 0, 0], 'STATE' => [0, 2, 0, 0]]];
$wl = Mapper::liveFromLala($wb);
check('Mapper: nur die eingerichteten Wallboxen (2 von 4) als wallbox<N>_*', [isset($wl['wallbox1_w']), $wl['wallbox2_w'] ?? null, isset($wl['wallbox3_w']), isset($wl['wallbox4_w']), $wl['wallbox2_fahrzeug_verbunden'] ?? null,
    $wl['wallbox1_fahrzeug_verbunden'] ?? null, $wl['wallbox2_status_code'] ?? null], [true, 3700.0, false, false, 1, 0, 2]);
$stl = Mapper::liveFromLala(['WALLBOX' => ['STATE' => [161, 194, 0, 0], 'HW_TYPE' => [20, 20, 20, 20]], 'WIZARD' => ['SETUP_NUMBER_WALLBOXES' => 2]]);
check('Mapper: Status-Text je Wallbox und fuer die erste (bekannter Typ)', [$stl['wallbox2_status_text'] ?? null, $stl['wallbox_status_text'] ?? null], ['Lädt (194)', 'Nicht verbunden (161)']);
$stf = Mapper::liveFromLala(['WALLBOX' => ['STATE' => [161, 194, 0, 0], 'HW_TYPE' => [20, 21, 20, 20]], 'WIZARD' => ['SETUP_NUMBER_WALLBOXES' => 2]]);
check('Mapper: anderer Wallbox-Typ: nur die Zahl, kein Text (Wallbox 2); Wallbox 1 mit bekanntem Typ hat den Text', [$stf['wallbox2_status_code'] ?? null, isset($stf['wallbox2_status_text']), $stf['wallbox1_status_text'] ?? null], [194, false, 'Nicht verbunden (161)']);
$stm = Mapper::liveFromLala(['WALLBOX' => ['STATE' => [161, 194, 0, 0], 'HW_TYPE' => [21, 20, 20, 20]], 'WIZARD' => ['SETUP_NUMBER_WALLBOXES' => 2]], ['typ1' => 'pro_s', 'typ2' => 'andere']);
check('Mapper: manuelle Auswahl "pro_s" gibt den Text auch bei anderem/fehlendem Typ, "andere" unterdrueckt ihn', [$stm['wallbox1_status_text'] ?? null, isset($stm['wallbox2_status_text']), $stm['wallbox_status_text'] ?? null], ['Nicht verbunden (161)', false, 'Nicht verbunden (161)']);
$stn = Mapper::liveFromLala(['WALLBOX' => ['STATE' => [161, 0, 0, 0]], 'WIZARD' => ['SETUP_NUMBER_WALLBOXES' => 1]], ['typ1' => 'pro_s']);
check('Mapper: "pro_s" auch ganz ohne Typ-Angabe vom Speicher', $stn['wallbox1_status_text'] ?? null, 'Nicht verbunden (161)');
check('WallboxTexts::textFuer: auto, pro_s, andere, Unsinn wie auto', [WallboxTexts::textFuer('auto', 20), WallboxTexts::textFuer('auto', 21), WallboxTexts::textFuer('pro_s', null), WallboxTexts::textFuer('andere', 20), WallboxTexts::textFuer('quatsch', 20)], [true, false, true, false, true]);
$stk = Mapper::liveFromLala(['WALLBOX' => ['STATE' => [161, 0, 0, 0]], 'WIZARD' => ['SETUP_NUMBER_WALLBOXES' => 1]]);
check('Mapper: ohne Typ-Angabe kein Text (nur die Zahl)', [$stk['wallbox1_status_code'] ?? null, isset($stk['wallbox1_status_text']), isset($stk['wallbox_status_text'])], [161, false, false]);
check('Mapper: gesperrt je Wallbox', [Mapper::liveFromLala(['WALLBOX' => ['PROHIBIT_USAGE' => [1, 0, 0, 0]]])['wallbox1_gesperrt'] ?? null, Mapper::liveFromLala(['WALLBOX' => ['PROHIBIT_USAGE' => [1, 0, 0, 0]]])['wallbox2_gesperrt'] ?? null], [1, 0]);
check('Mapper: Summenwerte bleiben (Leistung, Fahrzeug an einer Wallbox, Status der ersten)', [$wl['wallbox_w'], $wl['wallbox_fahrzeug_verbunden'], $wl['wallbox_status_code']], [3700.0, 1, 0]);
$wb['WIZARD']['SETUP_NUMBER_WALLBOXES'] = 4;
check('Mapper: vier Wallboxen', [isset(Mapper::liveFromLala($wb)['wallbox4_w']), isset(Mapper::liveFromLala($wb)['wallbox5_w'])], [true, false]);
unset($wb['WIZARD']);
check('Mapper: ohne Angabe der Anzahl alle vier Stellen', isset(Mapper::liveFromLala($wb)['wallbox4_w']), true);

// --- Steuerung (Befehle aus Loxone): Themen, Werte, Pruefungen ---
check('Control: Topic zwangsladung', Control::parseTopic('senec', 'senec/befehl/zwangsladung'), ['gruppe' => 'zwangsladung', 'idx' => 0, 'feld' => '', 'name' => 'zwangsladung']);
check('Control: Topic entladung_freigeben (Gruppe Zwangsladung)', Control::parseTopic('senec', 'senec/befehl/entladung_freigeben'), ['gruppe' => 'zwangsladung', 'idx' => 0, 'feld' => '', 'name' => 'entladung_freigeben']);
check('Control: Topic Steckdose 2', Control::parseTopic('senec', 'senec/befehl/steckdose2/modus'), ['gruppe' => 'steckdosen', 'idx' => 2, 'feld' => 'modus', 'name' => 'steckdose2/modus']);
check('Control: Topic Wallbox 1', Control::parseTopic('senec', 'senec/befehl/wallbox1/ladestrom_max_a')['gruppe'] ?? null, 'wallbox');
check('Control: anderer Praefix', Control::parseTopic('meinhaus/senec', 'meinhaus/senec/befehl/zwangsladung')['gruppe'] ?? null, 'zwangsladung');
check('Control: Quittung, fremde und kaputte Topics sind keine Befehle', [Control::parseTopic('senec', 'senec/befehl/zwangsladung/quittung'), Control::parseTopic('senec', 'senec/live/pv_w'),
    Control::parseTopic('senec', 'senec/befehl/steckdose0/modus'), Control::parseTopic('senec', 'senec/befehl/reboot'), Control::parseTopic('senec', 'andere/befehl/zwangsladung')], [null, null, null, null, null]);
check('Control: Werte kodieren (u8, u1, fl)', [Control::hexU8(1), Control::hexU8(255), Control::hexU8(300), Control::hexU16(200), Control::hexU16(70000), Control::hexFloat(16.0), Control::hexFloat(10.5)], ['u8_01', 'u8_FF', 'u8_FF', 'u1_00C8', 'u1_FFFF', 'fl_41800000', 'fl_41280000']);
check('Control: kodierte Floats lassen sich wieder lesen', SenecDecode::value(Control::hexFloat(13.5)), 13.5);
check('Control: Body Steckdosen mit leeren Texten fuer die anderen', Control::steckdosenBody(2, 1, ['UPPER_LIMIT' => 'u1_0190']), '{"SOCKETS":{"UPPER_LIMIT":["","u1_0190"]}}');
check('Control: Body Wallbox mit vier Stellen', Control::wallboxBody(0, ['SET_ICMAX' => 'fl_41800000']), '{"WALLBOX":{"SET_ICMAX":["fl_41800000","","",""]}}');
check('Control: Steckdose Modus aus/automatik/dauer_ein', [Control::steckdoseFelder('modus', 'aus')['felder'], Control::steckdoseFelder('modus', 'automatik')['felder'], Control::steckdoseFelder('modus', 'DAUER_EIN')['felder']],
    [['ENABLE' => 'u8_00', 'FORCE_ON' => 'u8_00'], ['ENABLE' => 'u8_01', 'FORCE_ON' => 'u8_00'], ['ENABLE' => 'u8_01', 'FORCE_ON' => 'u8_01']]);
check('Control: Steckdose Schwellen und Zeiten', [Control::steckdoseFelder('einschaltschwelle_w', '400')['felder'], Control::steckdoseFelder('abschaltschwelle_w', '0')['felder'], Control::steckdoseFelder('ueberschuss_min', '15')['felder'],
    Control::steckdoseFelder('einschaltdauer_min', '90')['felder'], Control::steckdoseFelder('stunde', '23')['felder'], Control::steckdoseFelder('minute', '59')['felder']],
    [['UPPER_LIMIT' => 'u1_0190'], ['LOWER_LIMIT' => 'u1_0000'], ['POWER_ON_TIME' => 'u1_000F'], ['TIME_LIMIT' => 'u1_005A'], ['SWITCH_ON_HOUR' => 'u8_17'], ['SWITCH_ON_MINUTE' => 'u8_3B']]);
check('Control: Steckdose ungueltige Werte', [Control::steckdoseFelder('modus', 'an')['fehler'] !== '', Control::steckdoseFelder('einschaltschwelle_w', '20001')['fehler'] !== '', Control::steckdoseFelder('einschaltschwelle_w', '-5')['fehler'] !== '',
    Control::steckdoseFelder('stunde', '24')['fehler'] !== '', Control::steckdoseFelder('minute', '60')['fehler'] !== '', Control::steckdoseFelder('minute', 'x')['fehler'] !== '', Control::steckdoseFelder('farbe', '1')['fehler']],
    [true, true, true, true, true, true, 'unbekannter Befehl']);
check('Control: Wallbox Stroeme, Komma erlaubt', [Control::wallboxFelder('ladestrom_max_a', '16')['felder'], Control::wallboxFelder('ladestrom_standard_a', '10,5')['felder'], Control::wallboxFelder('ladestrom_min_a', '6')['felder']],
    [['SET_ICMAX' => 'fl_41800000'], ['SET_IDEFAULT' => 'fl_41280000'], ['MIN_CHARGING_CURRENT' => 'fl_40C00000']]);
check('Control: Wallbox Schalter (Smart-Laden an = u8_03 wie im ioBroker-Adapter)', [Control::wallboxFelder('smart_laden', '1')['felder'], Control::wallboxFelder('smart_laden', '0')['felder'], Control::wallboxFelder('zwischenladen', '1')['felder']],
    [['SMART_CHARGE_ACTIVE' => 'u8_03'], ['SMART_CHARGE_ACTIVE' => 'u8_00'], ['ALLOW_INTERCHARGE' => 'u8_01']]);
check('Control: Wallbox sperren und freigeben (PROHIBIT_USAGE)', [Control::wallboxFelder('sperre', '1')['felder'], Control::wallboxFelder('sperre', '0')['felder'], Control::wallboxFelder('sperre', 'ja')['fehler'] !== ''], [['PROHIBIT_USAGE' => 'u8_01'], ['PROHIBIT_USAGE' => 'u8_00'], true]);
check('Control: Wallbox ungueltige Werte (unter 6 A, ueber 32 A, kein Schalterwert)', [Control::wallboxFelder('ladestrom_max_a', '5')['fehler'] !== '', Control::wallboxFelder('ladestrom_max_a', '33')['fehler'] !== '', Control::wallboxFelder('smart_laden', '2')['fehler'] !== '',
    Control::wallboxFelder('ladestrom_max_a', 'abc')['fehler'] !== ''], [true, true, true, true]);
check('Control: Lagermodus beenden wie die Wartungsseite (LI_STORAGE_MODE_STOP = u8_01, nur dieses Feld gesetzt)', [json_decode(Control::LAGERMODUS_AUS, true)['ENERGY']['LI_STORAGE_MODE_STOP'], json_decode(Control::LAGERMODUS_AUS, true)['ENERGY']['LI_STORAGE_MODE_START'], json_decode(Control::LAGERMODUS_AUS, true)['ENERGY']['SAFE_CHARGE_FORCE']], ['u8_01', '', '']);
check('Control: Zwangsladung-Bodies wie im ioBroker-Adapter', [str_contains(Control::BATTERY_ON, '"SAFE_CHARGE_FORCE":"u8_01","SAFE_CHARGE_PROHIBIT":""'), str_contains(Control::BATTERY_OFF, '"SAFE_CHARGE_FORCE":"","SAFE_CHARGE_PROHIBIT":"u8_01"'),
    json_decode(Control::BATTERY_ON) !== null, json_decode(Control::BATTERY_OFF) !== null], [true, true, true, true]);
$cfgOff = Config::load('/gibt/es/nicht.json');
check('Config: Wallbox-Typ ab Werk automatisch, ungueltige Eintraege werden automatisch', [$cfgOff['wallboxen'], Config::validate(array_replace_recursive($cfgOff, ['wallboxen' => ['typ2' => 'unsinn', 'typ3' => 'pro_s']]))['wallboxen']],
    [['typ1' => 'auto', 'typ2' => 'auto', 'typ3' => 'auto', 'typ4' => 'auto'], ['typ1' => 'auto', 'typ2' => 'auto', 'typ3' => 'pro_s', 'typ4' => 'auto']]);
check('Config: Steuerung ab Werk ganz gesperrt', $cfgOff['control'], ['warnung_ok' => false, 'zwangsladung' => false, 'steckdosen' => false, 'wallbox' => false]);
$cfgGr = $cfgOff;
$cfgGr['control'] = ['warnung_ok' => false, 'zwangsladung' => true, 'steckdosen' => true, 'wallbox' => true];
check('Config: Gruppen ohne bestaetigten Warnhinweis werden gesperrt', Config::validate($cfgGr)['control'], ['warnung_ok' => false, 'zwangsladung' => false, 'steckdosen' => false, 'wallbox' => false]);
$cfgGr['control']['warnung_ok'] = true;
$cfgGr['control']['steckdosen'] = false;
check('Config: mit Warnhinweis bleiben die gewaehlten Gruppen', Config::validate($cfgGr)['control'], ['warnung_ok' => true, 'zwangsladung' => true, 'steckdosen' => false, 'wallbox' => true]);
check('Control: zusaetzliche Abschnitte nur fuer freigegebene Gruppen', [Control::zusatzSektionen($cfgOff), Control::zusatzSektionen(Config::validate($cfgGr)), Control::wirdFrei($cfgGr, 'wallbox'), Control::wirdFrei($cfgGr, 'steckdosen'), Control::wirdFrei($cfgOff, 'zwangsladung')],
    [[], ['WALLBOX'], true, false, false]);
check('Mapper: entladung_gesperrt bei Vollladung oder Lagermodus, sonst 0; lagermodus', [Mapper::liveFromLala(['ENERGY' => ['SAFE_CHARGE_RUNNING' => 1, 'LI_STORAGE_MODE_RUNNING' => 0]])['entladung_gesperrt'], Mapper::liveFromLala(['ENERGY' => ['SAFE_CHARGE_RUNNING' => 0, 'LI_STORAGE_MODE_RUNNING' => 1]])['entladung_gesperrt'],
    Mapper::liveFromLala(['ENERGY' => ['SAFE_CHARGE_RUNNING' => 0, 'LI_STORAGE_MODE_RUNNING' => 0]])['entladung_gesperrt'], Mapper::liveFromLala(['ENERGY' => ['SAFE_CHARGE_RUNNING' => 0, 'LI_STORAGE_MODE_RUNNING' => 1]])['lagermodus']], [1, 1, 0, 1]);
check('Mapper: live/zwangsladung bei Status 8 und 9, sonst 0', [Mapper::liveFromLala(['ENERGY' => ['STAT_STATE' => 8]])['zwangsladung'], Mapper::liveFromLala(['ENERGY' => ['STAT_STATE' => 9]])['zwangsladung'],
    Mapper::liveFromLala(['ENERGY' => ['STAT_STATE' => 14]])['zwangsladung']], [1, 1, 0]);

$ctlCfg = Config::load('/gibt/es/nicht.json');
$ctlCfg['control'] = ['warnung_ok' => true, 'zwangsladung' => true, 'steckdosen' => true, 'wallbox' => true];
$ctlLog = new Logger(null);
$ctlLala = new LalaSource(array_merge($ctlCfg['lala'], ['host' => '127.0.0.1:1']), new State(sys_get_temp_dir() . '/s2m_ctl_state_' . getmypid() . '.json'), $ctlLog);
$ctl = new Control($ctlCfg, $ctlLala, $ctlLog);
$ctlB = Control::parseTopic('senec', 'senec/befehl/zwangsladung');
check('Control: erster Befehl wird geprueft (ungueltiger Wert, ohne Zugriff auf den Speicher)', $ctl->ausfuehren($ctlB, 'ja'), 'abgelehnt: ungültiger Wert (1 oder 0)');
check('Control: zweiter Befehl direkt danach wird gebremst', $ctl->ausfuehren($ctlB, 'ja'), 'abgelehnt: zu schnell (höchstens ein Befehl alle 2 Sekunden)');
check('Control: ohne lala-Quelle abgelehnt', (new Control($ctlCfg, null, $ctlLog))->ausfuehren($ctlB, '1'), 'abgelehnt: Die lokale Quelle (lala.cgi) ist nicht eingerichtet');
check('Control: Gruppe nicht freigegeben', (new Control(array_replace_recursive($ctlCfg, ['control' => ['wallbox' => false]]), $ctlLala, $ctlLog))->ausfuehren(Control::parseTopic('senec', 'senec/befehl/wallbox1/smart_laden'), '1'),
    'abgelehnt: Befehle sind gesperrt (Gruppe Wallbox im Plugin nicht freigegeben)');
@unlink(sys_get_temp_dir() . '/s2m_ctl_state_' . getmypid() . '.json');

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

    // --- Steuerung ueber MQTT gegen den Fake-Speicher (Befehle aus Loxone) ---
    $stateFile = sys_get_temp_dir() . '/fake_lala_state_18981.json';
    @unlink($stateFile);
    $zustand = fn(): array => (array)json_decode((string)@file_get_contents($stateFile), true);
    $cmdCfg = json_decode((string)file_get_contents($e2e . '/senec2lox.json'), true);
    $cmdCfg['control'] = ['warnung_ok' => false, 'zwangsladung' => false, 'steckdosen' => false, 'wallbox' => false];
    $cmdCfg['lala']['interval_full'] = 10;       // SOCKETS und WALLBOX sollen im Test schnell gelesen werden
    file_put_contents($e2e . '/senec2lox.json', json_encode($cmdCfg));
    @unlink($e2e . '/mqtt.out');
    $mq = $start([$root . '/tests/fake_mqtt.php', '18930', $e2e], $e2e . '/mqtt.out');
    $la = $start(['-S', '127.0.0.1:18981', $root . '/tests/fake_lala.php'], $e2e . '/lala.out');
    usleep(800000);
    $dm = $start(array_merge($curlArgs, [$root . '/senec2lox/bin/senec2lox.php']), $e2e . '/daemon.out');
    $senden = function (string $topic, string $wert) use ($e2e): void {
        file_put_contents($e2e . '/inject.txt', $topic . "\t" . $wert . "\n");
    };
    $warte = function (callable $f, int $s = 15) {
        $ende = time() + $s;
        while (time() < $ende) {
            if ($f()) {
                return true;
            }
            usleep(250000);
        }
        return false;
    };
    $quittungen = function (string $topic) use ($mqttLog): array {
        preg_match_all('~^' . preg_quote($topic, '~') . '/quittung = (.*)$~m', $mqttLog(), $mm);
        return $mm[1];
    };
    $warte(fn() => str_contains($mqttLog(), 'senec/online = 1') && str_contains($mqttLog(), 'senec/live/pv_w'), 20);
    $senden('senec/befehl/zwangsladung', '1');
    check('Steuerung: ab Werk gesperrt (Warnhinweis nicht bestaetigt)', $warte(fn() => in_array('abgelehnt: Befehle sind gesperrt (Warnhinweis im Plugin noch nicht bestätigt)', $quittungen('senec/befehl/zwangsladung'), true), 10), true);
    check('Steuerung: bei gesperrten Befehlen wurde nichts an den Speicher geschrieben', (array)($zustand()['ENERGY'] ?? []), []);
    check('Steuerung: erste Quittung ist immer "angenommen"', ($quittungen('senec/befehl/zwangsladung')[0] ?? ''), 'angenommen');

    // Warnhinweis bestaetigt und alle Gruppen frei: Dienst uebernimmt die Konfiguration von selbst
    sleep(1);
    $cmdCfg['control'] = ['warnung_ok' => true, 'zwangsladung' => true, 'steckdosen' => true, 'wallbox' => true];
    file_put_contents($e2e . '/senec2lox.json', json_encode($cmdCfg));
    sleep(3);
    $senden('senec/befehl/zwangsladung', '1');
    check('Zwangsladung an: angenommen, dann ausgefuehrt', $warte(fn() => in_array('ausgefuehrt', $quittungen('senec/befehl/zwangsladung'), true), 15), true);
    check('Zwangsladung an: der Speicher hat SAFE_CHARGE_FORCE = 1 bekommen', ($zustand()['ENERGY']['SAFE_CHARGE_FORCE'] ?? ''), 'u8_01');
    check('Zwangsladung an: Zustand kommt als live/zwangsladung = 1 und Status 8 an', $warte(fn() => (bool)preg_match('~senec/live/zwangsladung = 1~', $mqttLog()) && (bool)preg_match('~senec/live/status_code = 8~', $mqttLog()), 15), true);
    sleep(2);
    $senden('senec/befehl/zwangsladung', '0');
    check('Zwangsladung aus: ausgefuehrt, SAFE_CHARGE_PROHIBIT = 1, live/zwangsladung = 0', $warte(fn() => count(array_filter($quittungen('senec/befehl/zwangsladung'), fn($q) => $q === 'ausgefuehrt')) >= 2
        && ($zustand()['ENERGY']['SAFE_CHARGE_PROHIBIT'] ?? '') === 'u8_01' && (bool)preg_match('~senec/live/zwangsladung = 0\b[\s\S]*$~', $mqttLog()), 15), true);
    sleep(2);
    $senden('senec/befehl/zwangsladung', 'ja');
    check('Zwangsladung mit ungueltigem Wert: abgelehnt', $warte(fn() => in_array('abgelehnt: ungültiger Wert (1 oder 0)', $quittungen('senec/befehl/zwangsladung'), true), 10), true);
    sleep(3);
    $senden('senec/befehl/zwangsladung', '1');
    check('Vollladung an fuer den Test "Entladung freigeben"', $warte(fn() => ($zustand()['ENERGY']['SAFE_CHARGE_RUNNING'] ?? '') === 'u8_01', 20), true);
    sleep(3);
    $senden('senec/befehl/entladung_freigeben', '1');
    check('Entladung freigeben bei laufender Vollladung: ausgefuehrt, Vollladung beendet', $warte(fn() => in_array('ausgefuehrt', $quittungen('senec/befehl/entladung_freigeben'), true), 30)
        && ($zustand()['ENERGY']['SAFE_CHARGE_RUNNING'] ?? '') === 'u8_00', true);
    check('Entladung freigeben: live/entladung_gesperrt = 0', $warte(fn() => (bool)preg_match('~senec/live/entladung_gesperrt = 0\b[\s\S]*$~', $mqttLog()), 15), true);
    $zs = $zustand();
    $zs['ENERGY']['LI_STORAGE_MODE_RUNNING'] = 'u8_01';
    file_put_contents($stateFile, json_encode($zs));
    sleep(3);
    $senden('senec/befehl/entladung_freigeben', '1');
    check('Entladung freigeben bei laufendem Lagermodus: LI_STORAGE_MODE_STOP gesendet, Lagermodus beendet', $warte(fn() => count(array_filter($quittungen('senec/befehl/entladung_freigeben'), fn($q) => $q === 'ausgefuehrt')) >= 2, 30)
        && ($zustand()['ENERGY']['LI_STORAGE_MODE_RUNNING'] ?? '') === 'u8_00', true);
    sleep(3);
    $senden('senec/befehl/entladung_freigeben', '1');
    check('Entladung freigeben, wenn nichts gesperrt ist: ausgefuehrt (nichts zu tun)', $warte(fn() => count(array_filter($quittungen('senec/befehl/entladung_freigeben'), fn($q) => $q === 'ausgefuehrt')) >= 3, 30), true);
    sleep(3);
    $senden('senec/befehl/entladung_freigeben', '0');
    check('Entladung freigeben mit 0: abgelehnt', $warte(fn() => in_array('abgelehnt: ungültiger Wert (1 erwartet)', $quittungen('senec/befehl/entladung_freigeben'), true), 10), true);
    sleep(3);
    $senden('senec/befehl/steckdose2/modus', 'automatik');
    check('Steckdose 2 Modus automatik: ausgefuehrt', $warte(fn() => in_array('ausgefuehrt', $quittungen('senec/befehl/steckdose2/modus'), true), 20), true);
    check('Steckdose 2: ENABLE nur an Stelle 2 gesetzt, Steckdose 1 unveraendert', [$zustand()['SOCKETS']['ENABLE'][1] ?? null, $zustand()['SOCKETS']['ENABLE'][0] ?? null], ['u8_01', null]);
    sleep(3);
    $senden('senec/befehl/steckdose1/einschaltschwelle_w', '450');
    check('Steckdose 1 Einschaltschwelle 450 W: ausgefuehrt, u1_01C2', $warte(fn() => in_array('ausgefuehrt', $quittungen('senec/befehl/steckdose1/einschaltschwelle_w'), true), 20) && ($zustand()['SOCKETS']['UPPER_LIMIT'][0] ?? '') === 'u1_01C2', true);
    sleep(3);
    $senden('senec/befehl/steckdose2/stunde', '7');
    check('Steckdose 2 Stunde 7 im Automatik-Modus: USE_TIME wird mitgefuehrt', $warte(fn() => in_array('ausgefuehrt', $quittungen('senec/befehl/steckdose2/stunde'), true), 20)
        && ($zustand()['SOCKETS']['SWITCH_ON_HOUR'][1] ?? '') === 'u8_07' && ($zustand()['SOCKETS']['USE_TIME'][1] ?? '') === 'u8_01', true);
    sleep(3);
    $senden('senec/befehl/steckdose3/modus', 'aus');
    check('Steckdose 3 gibt es nicht (Speicher hat 2): abgelehnt', $warte(fn() => in_array('abgelehnt: Steckdose 3 gibt es nicht (der Speicher hat 2)', $quittungen('senec/befehl/steckdose3/modus'), true), 10), true);
    sleep(3);
    $senden('senec/befehl/steckdose1/minute', '75');
    check('Steckdose mit ungueltiger Minute: abgelehnt', $warte(fn() => in_array('abgelehnt: ungültiger Wert (0 bis 59)', $quittungen('senec/befehl/steckdose1/minute'), true), 10), true);

    sleep(3);
    $senden('senec/befehl/wallbox1/ladestrom_max_a', '12');
    check('Wallbox 1 Ladestrom max 12 A: ausgefuehrt, Wert im Speicher', $warte(fn() => in_array('ausgefuehrt', $quittungen('senec/befehl/wallbox1/ladestrom_max_a'), true), 20)
        && SenecDecode::value($zustand()['WALLBOX']['SET_ICMAX'][0] ?? '') === 12.0, true);
    sleep(3);
    $senden('senec/befehl/wallbox1/smart_laden', '1');
    check('Wallbox 1 Smart-Laden an: ausgefuehrt, u8_03', $warte(fn() => in_array('ausgefuehrt', $quittungen('senec/befehl/wallbox1/smart_laden'), true), 20) && ($zustand()['WALLBOX']['SMART_CHARGE_ACTIVE'][0] ?? '') === 'u8_03', true);
    sleep(3);
    $senden('senec/befehl/wallbox4/ladestrom_min_a', '7');
    check('Wallbox 4 (die vierte): Ladestrom min 7 A ausgefuehrt, nur an Stelle 4 geschrieben', $warte(fn() => in_array('ausgefuehrt', $quittungen('senec/befehl/wallbox4/ladestrom_min_a'), true), 20)
        && SenecDecode::value($zustand()['WALLBOX']['MIN_CHARGING_CURRENT'][3] ?? '') === 7.0 && !isset($zustand()['WALLBOX']['MIN_CHARGING_CURRENT'][0]), true);
    sleep(3);
    $senden('senec/befehl/wallbox3/sperre', '1');
    check('Wallbox 3 sperren: ausgefuehrt, PROHIBIT_USAGE nur an Stelle 3', $warte(fn() => in_array('ausgefuehrt', $quittungen('senec/befehl/wallbox3/sperre'), true), 30)
        && ($zustand()['WALLBOX']['PROHIBIT_USAGE'][2] ?? '') === 'u8_01' && !isset($zustand()['WALLBOX']['PROHIBIT_USAGE'][0]), true);
    check('Wallbox 3 gesperrt kommt als live/wallbox3_gesperrt = 1 an', $warte(fn() => (bool)preg_match('~senec/live/wallbox3_gesperrt = 1~', $mqttLog()), 40), true);
    sleep(3);
    $senden('senec/befehl/wallbox3/sperre', '0');
    check('Wallbox 3 freigeben: ausgefuehrt', $warte(fn() => count(array_filter($quittungen('senec/befehl/wallbox3/sperre'), fn($q) => $q === 'ausgefuehrt')) >= 2, 30) && ($zustand()['WALLBOX']['PROHIBIT_USAGE'][2] ?? '') === 'u8_00', true);
    sleep(3);
    $senden('senec/befehl/wallbox5/ladestrom_max_a', '12');
    check('Wallbox 5 gibt es nicht (der Speicher unterstuetzt vier): abgelehnt', $warte(fn() => in_array('abgelehnt: Wallbox 5 gibt es nicht (der Speicher hat 4)', $quittungen('senec/befehl/wallbox5/ladestrom_max_a'), true), 10), true);
    sleep(3);
    $senden('senec/befehl/wallbox1/ladestrom_max_a', '20');
    check('Wallbox 20 A (Nennstrom 16 A): abgelehnt, nichts geschrieben', $warte(fn() => in_array('abgelehnt: höher als der Nennstrom der Wallbox (16 A)', $quittungen('senec/befehl/wallbox1/ladestrom_max_a'), true), 15)
        && SenecDecode::value($zustand()['WALLBOX']['SET_ICMAX'][0] ?? '') === 12.0, true);
    sleep(3);
    $senden('senec/befehl/wallbox1/ladestrom_max_a', '40');
    check('Wallbox mit 40 A: abgelehnt, nichts geschrieben', $warte(fn() => in_array('abgelehnt: ungültiger Wert (6 bis 32 Ampere)', $quittungen('senec/befehl/wallbox1/ladestrom_max_a'), true), 10)
        && SenecDecode::value($zustand()['WALLBOX']['SET_ICMAX'][0] ?? '') === 12.0, true);

    // Der Speicher uebernimmt einen Wert nicht: das darf nicht als ausgefuehrt quittiert werden
    sleep(3);
    $zs = $zustand();
    $zs['_ignorieren'] = ['WALLBOX.SET_IDEFAULT'];
    file_put_contents($stateFile, json_encode($zs));
    $senden('senec/befehl/wallbox1/ladestrom_standard_a', '8');
    check('Speicher uebernimmt den Wert nicht: abgelehnt mit Hinweis', $warte(fn() => in_array('abgelehnt: Der Speicher hat den Wert nicht übernommen (SET_IDEFAULT)', $quittungen('senec/befehl/wallbox1/ladestrom_standard_a'), true), 45), true);

    // Werte je Wallbox (vier eingerichtet) und Summenwerte
    check('Vier Wallboxen: Leistung, Fahrzeug und Status je Wallbox, Summe bleibt', $warte(fn() => (bool)preg_match('~senec/live/wallbox1_w = 0\b~', $mqttLog()) && (bool)preg_match('~senec/live/wallbox2_w = 3700~', $mqttLog())
        && (bool)preg_match('~senec/live/wallbox4_w = 1500~', $mqttLog()) && (bool)preg_match('~senec/live/wallbox2_fahrzeug_verbunden = 1~', $mqttLog()) && (bool)preg_match('~senec/live/wallbox3_fahrzeug_verbunden = 0~', $mqttLog())
        && (bool)preg_match('~senec/live/wallbox4_status_code = 194~', $mqttLog()) && !str_contains($mqttLog(), 'wallbox4_status_text')
        && (bool)preg_match('~senec/live/wallbox2_status_text = Verbunden \(177\)~', $mqttLog()) && (bool)preg_match('~senec/live/wallbox_status_text = Nicht verbunden \(161\)~', $mqttLog()) && (bool)preg_match('~senec/live/wallbox_w = 5200~', $mqttLog()), 40), true);
    check('Keine fuenfte Wallbox als Topic; Wallbox 4 (anderer Typ) hat keinen Status-Text', [str_contains($mqttLog(), 'wallbox5_'), str_contains($mqttLog(), 'wallbox4_status_text')], [false, false]);

    // Rohwerte der freigegebenen Gruppen kommen in Loxone an
    check('Freigegebene Gruppen: SOCKETS und WALLBOX werden mitgelesen und als Rohwerte veroeffentlicht', $warte(fn() => (bool)preg_match('~senec/raw/SOCKETS/NUMBER_OF_SOCKETS = 2~', $mqttLog()) && (bool)preg_match('~senec/raw/WALLBOX/SET_ICMAX/0 = 12~', $mqttLog()), 40), true);
    check('Keine Zugangsdaten oder Schluessel in den Quittungen', (bool)preg_match('~quittung = .*(token|key|passwort)~i', $mqttLog()), false);
    $statusJson = json_decode((string)@file_get_contents($e2e . '/run/status.json'), true);
    check('status.json: Freigaben und letzter Befehl', [$statusJson['control']['warnung_ok'] ?? null, $statusJson['control']['frei'] ?? null, isset($statusJson['control']['letzter']['befehl'])], [true, ['zwangsladung', 'steckdosen', 'wallbox'], true]);
    $e2e_stop($dm);
    $e2e_stop($la);
    $e2e_stop($mq);
    @unlink($stateFile);
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
