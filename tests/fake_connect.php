<?php
/** Fake-SENEC.Connect mit 2 Anlagen: php -S 127.0.0.1:8083 tests/fake_connect.php */
header('Content-Type: application/json');
if (($_SERVER['HTTP_OCP_APIM_SUBSCRIPTION_KEY'] ?? '') === '') {
    http_response_code(401);
    echo '{"message":"Access denied"}';
    return;
}
echo json_encode([
    ['battery' => ['state' => 1, 'state_of_charge' => 80, 'power' => 500, 'voltage' => 400, 'current' => 1.2],
        'meter' => ['grid_power' => -200, 'consumption' => 700, 'production' => 1400],
        'bessNameplate' => ['serial_number' => 'v4-0001', 'system_id' => 'P4H1-AAA', 'design_capacity' => 10000]],
    ['battery' => ['state' => 1, 'state_of_charge' => 20, 'power' => -100, 'voltage' => 400, 'current' => -0.3],
        'meter' => ['grid_power' => 300, 'consumption' => 400, 'production' => 0],
        'bessNameplate' => ['serial_number' => 'v4-0002', 'system_id' => 'P4H1-BBB', 'design_capacity' => 5000]],
]);
