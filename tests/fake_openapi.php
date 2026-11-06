<?php
/**
 * Fake-OpenAPI: php -S 127.0.0.1:8082 tests/fake_openapi.php
 * Zustand per Datei-Schalter: ?mode=unregistered|pending|ok|revoked (merkt sich in sys_get_temp_dir()/fake_openapi_mode)
 * Hinweis: echte API laeuft auf HTTPS :4424; dieser Fake spricht HTTP und dient Handtests/Protokollchecks.
 */
$modeFile = sys_get_temp_dir() . '/fake_openapi_mode';
if (isset($_GET['mode'])) {
    file_put_contents($modeFile, $_GET['mode']);
    echo "mode=" . $_GET['mode'];
    return;
}
$mode = is_file($modeFile) ? trim(file_get_contents($modeFile)) : 'ok';
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

if ($path === '/v1/register' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    http_response_code(202);
    echo '11111111-2222-3333-4444-555555555555';
    return;
}
$token = $_SERVER['HTTP_X_AUTH_TOKEN'] ?? '';
if ($mode === 'revoked' || $token === '') {
    http_response_code(401);
    header('Content-Type: text/plain');
    echo 'Incorrect token';
    return;
}
if ($mode === 'pending') {
    http_response_code(511);
    header('Content-Type: text/plain');
    echo 'Token activation pending';
    return;
}
header('Content-Type: application/json');
switch ($path) {
    case '/v1/battery':
        echo json_encode(['state' => 1, 'stateOfCharge' => 47, 'power' => -850.8, 'voltage' => 52.2, 'current' => -16.3]);
        break;
    case '/v1/meter':
        echo json_encode(['gridPower' => 91, 'consumption' => 1181, 'production' => 244, 'powerSurplus' => 0, 'fullRangePowerSurplus' => 0]);
        break;
    case '/v1/bess/nameplate':
        echo json_encode(['manufacturer' => 'SENEC', 'model' => 'V3', 'serialNumber' => 'S123', 'designCapacity' => 5000,
            'ratedPower' => ['activeChargePower' => 0]]);
        break;
    default:
        http_response_code(404);
        echo 'not found';
}
