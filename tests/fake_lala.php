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
];
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
