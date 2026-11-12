<?php
/**
 * Fake-lala.cgi: php -S 127.0.0.1:8081 tests/fake_lala.php
 * (HTTP; Quelle faellt bei fehlendem HTTPS automatisch auf http zurueck - fuer echte HTTPS-Tests Reverse-Proxy nutzen)
 */
function fl(float $f): string
{
    return 'fl_' . strtoupper(bin2hex(pack('G', $f)));
}

$req = json_decode(file_get_contents('php://input'), true) ?: [];
$stateFile = getenv('FAKE_LALA_STATE') ?: sys_get_temp_dir() . '/fake_lala_state_' . ($_SERVER['SERVER_PORT'] ?? '0') . '.json';
$written = is_file($stateFile) ? (json_decode((string)file_get_contents($stateFile), true) ?: []) : [];
$db = [
    'ENERGY' => ['STAT_STATE' => 'u8_0E', 'GUI_BAT_DATA_POWER' => fl(250.5), 'GUI_INVERTER_POWER' => fl(1800),
        'GUI_HOUSE_POW' => fl(900), 'GUI_GRID_POW' => fl(-650), 'GUI_BAT_DATA_FUEL_CHARGE' => fl(57.3),
        'GUI_BAT_DATA_VOLTAGE' => fl(52.1), 'GUI_BAT_DATA_CURRENT' => fl(4.8), 'GUI_BOOSTING_INFO' => 'u8_00',
        'GUI_CHARGING_INFO' => 'u8_01', 'STAT_LIMITED_NET_SKEW' => 'er_01', 'SAFE_CHARGE_FORCE' => 'VARIABLE_NOT_FOUND'],
    'PV1' => ['POWER_RATIO' => fl(50), 'MPP_POWER' => [fl(900), fl(900), fl(0)]],
    'PM1OBJ1' => ['FREQ' => fl(50.01), 'U_AC' => [fl(230), fl(231), fl(229)]],
    'PWR_UNIT' => ['POWER_L1' => fl(300), 'POWER_L2' => fl(300), 'POWER_L3' => fl(300)],
    'BMS' => ['TEMP_MAX' => fl(24.5), 'TEMP_MIN' => fl(22), 'MODULE_COUNT' => 'u8_02', 'SOC' => fl(57)],
    'FACTORY' => ['DEVICE_ID' => 'st_TEST123', 'SYS_TYPE' => 'u8_04'],
    // Steuerung: zwei schaltbare Steckdosen und eine Wallbox (Werte als Rohwerte; Schreibzugriffe landen in der Zustandsdatei)
    'SOCKETS' => ['NUMBER_OF_SOCKETS' => 'u8_02', 'ENABLE' => ['u8_00', 'u8_00'], 'FORCE_ON' => ['u8_00', 'u8_00'], 'USE_TIME' => ['u8_00', 'u8_00'],
        'UPPER_LIMIT' => ['u1_00C8', 'u1_00C8'], 'LOWER_LIMIT' => ['u1_0064', 'u1_0064'], 'POWER_ON_TIME' => ['u1_000A', 'u1_000A'],
        'TIME_LIMIT' => ['u1_003C', 'u1_003C'], 'SWITCH_ON_HOUR' => ['u8_00', 'u8_00'], 'SWITCH_ON_MINUTE' => ['u8_00', 'u8_00']],
    'WIZARD' => ['SETUP_NUMBER_WALLBOXES' => 'u8_04'],
    'WALLBOX' => ['SET_ICMAX' => [fl(16), fl(16), fl(16), fl(16)], 'SET_IDEFAULT' => [fl(10), fl(10), fl(10), fl(10)],
        'MIN_CHARGING_CURRENT' => [fl(6), fl(6), fl(6), fl(6)], 'SMART_CHARGE_ACTIVE' => ['u8_00', 'u8_00', 'u8_00', 'u8_00'],
        'ALLOW_INTERCHARGE' => ['u8_00', 'u8_00', 'u8_00', 'u8_00'],
        'APPARENT_CHARGING_POWER' => [fl(0), fl(3700), fl(0), fl(1500)], 'EV_CONNECTED' => ['u8_00', 'u8_01', 'u8_00', 'u8_01'],
        'STATE' => ['u8_A1', 'u8_B1', 'u8_A2', 'u8_C2'], 'HW_TYPE' => ['u8_14', 'u8_14', 'u8_14', 'u8_15'], 'MAX_CHARGING_CURRENT_RATED' => [fl(16), fl(16), fl(16), fl(16)], 'PROHIBIT_USAGE' => ['u8_00', 'u8_00', 'u8_00', 'u8_00']],
];
// Schreibzugriffe: nichtleere Werte werden gemerkt (ausser fuer Schluessel in "_ignorieren": der Speicher uebernimmt sie dann nicht)
$ignorieren = $written['_ignorieren'] ?? [];
foreach ($req as $sec => $keys) {
    foreach ((array)$keys as $k => $v) {
        if (in_array("$sec.$k", $ignorieren, true)) {
            continue;
        }
        if (is_array($v)) {
            foreach ($v as $i => $e) {
                if ($e !== '' && $e !== null) {
                    $written[$sec][$k][$i] = $e;
                }
            }
        } elseif (is_string($v) && $v !== '' && preg_match('/^(u8|u1|fl)_/', $v)) {
            $written[$sec][$k] = $v;
        }
    }
}
// Zwangsladung: der Zustand des Speichers folgt (8 = manuelle Sicherheitsladung, 14 = normal)
if (($req['ENERGY']['SAFE_CHARGE_FORCE'] ?? '') === 'u8_01') {
    $written['ENERGY']['STAT_STATE'] = 'u8_08';
}
if (($req['ENERGY']['SAFE_CHARGE_PROHIBIT'] ?? '') === 'u8_01') {
    $written['ENERGY']['STAT_STATE'] = 'u8_0E';
}
file_put_contents($stateFile, json_encode($written));
foreach ($written as $sec => $keys) {
    if ($sec === '_ignorieren') {
        continue;
    }
    foreach ($keys as $k => $v) {
        if (is_array($v) && isset($db[$sec][$k]) && is_array($db[$sec][$k])) {
            $db[$sec][$k] = $v + $db[$sec][$k];
            ksort($db[$sec][$k]);
        } else {
            $db[$sec][$k] = $v;
        }
    }
}
$out = [];
foreach ($req as $sec => $keys) {
    if (!isset($db[$sec])) {
        $out[$sec] = [];
        foreach ((array)$keys as $k => $_) {
            $out[$sec][$k] = 'OBJECT_NOT_FOUND';
        }
        continue;
    }
    if (!$keys) {
        $out[$sec] = $db[$sec];
        continue;
    }
    foreach ($keys as $k => $_) {
        $out[$sec][$k] = $db[$sec][$k] ?? 'VARIABLE_NOT_FOUND';
    }
}
header('Content-Type: application/json');
echo json_encode($out);
