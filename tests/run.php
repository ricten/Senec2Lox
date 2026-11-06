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

echo "$count Tests, $fails Fehler\n";
exit($fails ? 1 : 0);
