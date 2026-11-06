<?php
/**
 * Messung: Rechenzeit einer Dienstrunde (Mapper::build + Publisher) bei realistisch grosser Vollabfrage.
 * php tests/bench_tick.php <port-eines-fake-mqtt-brokers> [runden=500]
 * (Broker: php tests/fake_mqtt.php <port>)
 */
require_once __DIR__ . '/../senec2lox/bin/lib/bootstrap.php';

$port = (int)($argv[1] ?? 18860);
$n = (int)($argv[2] ?? 500);

// realistische Datenmenge: ENERGY, BMS (mit Zell-Arrays), FACTORY, WIZARD, ... ca. 500 Topics
$data = ['ENERGY' => [], 'BMS' => [], 'FACTORY' => [], 'WIZARD' => [], 'SYS_UPDATE' => [], 'TEMPMEASURE' => [], 'PV1' => [], 'PM1OBJ1' => [], 'WALLBOX' => []];
for ($i = 0; $i < 90; $i++) {
    $data['ENERGY']["KEY_$i"] = $i * 1.5;
}
$data['ENERGY'] += ['GUI_INVERTER_POWER' => 1800.0, 'GUI_HOUSE_POW' => 900.0, 'GUI_GRID_POW' => -650.0, 'GUI_BAT_DATA_POWER' => 250.5,
    'GUI_BAT_DATA_FUEL_CHARGE' => 57.3, 'STAT_STATE' => 14];
foreach (['A', 'B', 'C', 'D'] as $m) {
    $data['BMS']["CELL_VOLTAGES_MODULE_$m"] = array_fill(0, 14, 3.31);
    $data['BMS']["CELL_TEMPERATURES_MODULE_$m"] = array_fill(0, 4, 22.5);
}
for ($i = 0; $i < 60; $i++) {
    $data['FACTORY']["F_$i"] = "wert_$i";
    $data['WIZARD']["W_$i"] = $i;
}
for ($i = 0; $i < 40; $i++) {
    $data['SYS_UPDATE']["S_$i"] = $i;
    $data['TEMPMEASURE']["T_$i"] = 20.5 + $i / 10;
}
$data['PM1OBJ1'] = ['FREQ' => 50.01, 'U_AC' => [230.1, 231.0, 229.9]];
$data['WALLBOX'] = ['APPARENT_CHARGING_POWER' => [0.0, 0.0, 0.0, 0.0], 'EV_CONNECTED' => [0, 0, 0, 0], 'STATE' => [0, 0, 0, 0]];

$cfg = Config::validate(Config::defaults());
$snap = ['lala' => ['ts' => time(), 'interval' => 10, 'data' => $data]];
$status = ['lala' => ['ok' => true, 'status' => 'ok']];

$c = new MqttClient('127.0.0.1', $port, '', '', 'bench', 'senec/online');
if (!$c->connect()) {
    exit("connect fehlgeschlagen: " . $c->lastError() . "\n");
}
$pub = new Publisher($c, 'senec', true, true, 5);

$now = time();
$b = Mapper::build($cfg, $snap, $status, $now, null);
$pub->publish($b['topics'], $now);       // erster Komplett-Versand
$topics = count($b['topics']);

$t = hrtime(true);
for ($i = 0; $i < $n; $i++) {
    $b = Mapper::build($cfg, $snap, $status, $now, null);
    $pub->publish($b['topics'], $now);   // nichts geaendert -> nur Vergleich
}
$build = (hrtime(true) - $t) / 1e6 / $n;

$t = hrtime(true);
for ($i = 0; $i < $n; $i++) {
    $st = ['service' => 'running', 'pid' => 1, 'time' => $now, 'live' => $b['topics'], 'sources' => $status];
    $js = json_encode($st);
}
$stat = (hrtime(true) - $t) / 1e6 / $n;

printf("Topics: %d | build+publish je Runde: %.2f ms | status.json je Runde: %.2f ms | Summe je Sekunde: %.2f ms\n",
    $topics, $build, $stat, $build + $stat);
