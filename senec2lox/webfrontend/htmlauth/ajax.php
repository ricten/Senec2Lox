<?php
/** AJAX-Schnittstelle der Senec2Lox-Oberflaeche (laeuft als loxberry hinter LoxBerry-Auth). */

/**
 * Letzte Zeilen einer Logdatei lesen (nur das Dateiende), LoxBerry-Kopfzeilen ausblenden, Secrets maskieren.
 * @param string[] $secrets
 * @return string[]
 */
function senecTailLog(string $file, array $secrets, int $max = 100): array
{
    $lines = [];
    if (is_file($file)) {
        $size = (int)filesize($file);
        $raw = (string)@file_get_contents($file, false, null, max(0, $size - 65536));
        $all = preg_split('/\r?\n/', $raw);
        if ($size > 65536) {
            array_shift($all);          // angeschnittene erste Zeile
        }
        foreach ($all as $ln) {
            if ($ln === '' || preg_match('/^=+$/', $ln) || strpos($ln, '<LOGSTART>') !== false || strpos($ln, '<LOGEND>') !== false) {
                continue;
            }
            $lines[] = $ln;
        }
        $lines = array_slice($lines, -$max);
    }
    foreach ($lines as &$l) {
        foreach ($secrets as $s) {
            if (is_string($s) && strlen($s) >= 4) {
                $l = str_replace($s, '***', $l);
            }
        }
    }
    return $lines;
}

function senecReadJson(string $file): array
{
    $j = is_file($file) ? json_decode((string)@file_get_contents($file), true) : null;
    return is_array($j) ? $j : [];
}

/*
 * Schnellpfad fuer die zyklischen Abfragen (status, log): ohne LoxBerry-Bibliothek, ohne Klassen, ohne Session.
 * Jede Abfrage nur Dateien lesen. Das spart auf kleinen LoxBerrys (Pi) viel Rechenzeit; die Session-Sperre
 * wuerde ausserdem parallele Aufrufe mehrerer Browser-Tabs hintereinander stellen.
 * Pfade: Webfrontend-Ordner = Plugin-Ordner; LoxBerry-Verzeichnis liegt 4 Ebenen darueber
 * (<home>/webfrontend/htmlauth/plugins/<ordner>).
 */
$fastAction = $_REQUEST['action'] ?? '';
if (($fastAction === 'status' || $fastAction === 'log') && !getenv('SENEC2LOX_DEV_DIR')) {
    $folder = basename(__DIR__);
    $home = dirname(__DIR__, 4);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    if ($fastAction === 'status') {
        $st = senecReadJson((getenv('SENEC2LOX_SHM') ?: '/dev/shm') . '/' . $folder . '/status.json');
        $alive = $st && (time() - (int)($st['time'] ?? 0)) < 10;
        echo json_encode(['ok' => true, 'alive' => $alive, 'status' => $st ?: null,
            'openapi_token' => (bool)($st['openapi_token'] ?? false), 'fingerprint_lala' => $st['fingerprint_lala'] ?? null],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
    $cfgJ = senecReadJson($home . '/config/plugins/' . $folder . '/senec2lox.json');
    $stateJ = senecReadJson($home . '/data/plugins/' . $folder . '/state.json');
    $lines = senecTailLog($home . '/log/plugins/' . $folder . '/senec2lox.log', [
        $cfgJ['connect']['subscription_key'] ?? '', $stateJ['openapi_token'] ?? '', $cfgJ['mqtt']['pass'] ?? '']);
    echo json_encode(['ok' => true, 'lines' => $lines], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

require_once "loxberry_system.php";
require_once LBPBINDIR . "/lib/bootstrap.php";

session_start();
header('Content-Type: application/json; charset=utf-8');

function out(array $a, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($a, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$paths = Paths::detect();
$action = $_REQUEST['action'] ?? '';
$readOnly = ['status', 'log'];

if (!in_array($action, $readOnly, true)) {
    $tok = $_POST['csrf'] ?? '';
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], (string)$tok)) {
        out(['ok' => false, 'message' => 'Ungueltiges CSRF-Token (Seite neu laden)'], 403);
    }
}

$s2mcfg = Config::load($paths->config);
$log = new Logger($paths->logfile, 'Web');
$log->addSecret($s2mcfg['connect']['subscription_key']);
$log->addSecret($s2mcfg['mqtt']['pass']);
$state = new State($paths->state);

function sh(string $script): void
{
    $cmd = escapeshellarg(LBPBINDIR . '/' . $script);
    @shell_exec($cmd . ' >/dev/null 2>&1');
}

switch ($action) {
    case 'status':
        $st = is_file($paths->statusFile()) ? json_decode((string)file_get_contents($paths->statusFile()), true) : null;
        $alive = is_array($st) && (time() - (int)($st['time'] ?? 0)) < 10;
        out(['ok' => true, 'alive' => $alive, 'status' => $st,
            'openapi_token' => (bool)$state->get('openapi_token'),
            'fingerprint_lala' => $state->get('lala_fp')]);

    case 'log':
        out(['ok' => true, 'lines' => senecTailLog($paths->logfile, [
            $s2mcfg['connect']['subscription_key'], (string)$state->get('openapi_token'), $s2mcfg['mqtt']['pass']])]);

    case 'restart':
        sh('stop.sh');   // wartet, bis der Dienst beendet ist
        sh('start.sh');
        out(['ok' => true, 'message' => 'Dienst wird neu gestartet']);

    case 'stop':
        sh('stop.sh');
        out(['ok' => true, 'message' => 'Dienst wird gestoppt (der Watchdog startet ihn nach spaetestens 1 min wieder; zum dauerhaften Abschalten "Dienst aktiviert" abwaehlen und speichern)']);

    case 'clear_status':
        @unlink($paths->statusFile());
        out(['ok' => true, 'message' => 'Status-Cache geleert (wird vom Dienst neu aufgebaut)']);

    case 'clear_log':
        if (is_file($paths->logfile)) {
            @file_put_contents($paths->logfile, '');
        }
        out(['ok' => true, 'message' => 'Log geleert']);

    case 'reset_config':
        // Standardwerte; der Token liegt in state.json und bleibt unberuehrt
        $d = Config::defaults();
        out(Config::save($paths->config, $d)
            ? ['ok' => true, 'message' => 'Einstellungen auf Standard zurueckgesetzt']
            : ['ok' => false, 'message' => 'Speichern fehlgeschlagen']);

    case 'test_lala':
        $src = new LalaSource($s2mcfg['lala'], $state, $log);
        out($src->test());

    case 'reset_fingerprint':
        $state->set('lala_fp', null);
        $state->set('openapi_fp', null);
        out(['ok' => true, 'message' => 'Fingerprints zurueckgesetzt']);

    case 'test_connect':
        // Optional: Key aus dem Formular (leer = gespeicherter Key)
        $c = $s2mcfg['connect'];
        $k = trim((string)($_POST['key'] ?? ''));
        if ($k !== '') {
            $c['subscription_key'] = $k;
        }
        $src = new ConnectSource($c, $log);
        out($src->test());

    case 'openapi_register':
        out(OpenApiSource::register($s2mcfg['openapi'], $s2mcfg['lala']['host'], $state, $log, $paths->run . '/register.lock'));

    case 'openapi_set_token':
        $t = trim((string)($_POST['token'] ?? ''));
        if ($state->get('openapi_token')) {
            out(['ok' => false, 'message' => 'Es ist bereits ein Token gespeichert. Erst "Token vergessen" verwenden.']);
        }
        if (!preg_match('/^[0-9a-fA-F-]{16,64}$/', $t)) {
            out(['ok' => false, 'message' => 'Ungueltiges Token-Format (erwartet: UUID)']);
        }
        $state->set('openapi_token', $t);
        $log->addSecret($t);
        $log->info('OpenAPI-Token manuell eingetragen');
        out(['ok' => true, 'message' => 'Token gespeichert. Status pruefen.']);

    case 'openapi_check':
        $src = new OpenApiSource($s2mcfg['openapi'], $s2mcfg['lala']['host'], $state, $log);
        out(['ok' => true] + $src->check());

    case 'openapi_forget':
        if (($_POST['confirm'] ?? '') !== '1') {
            out(['ok' => false, 'message' => 'Bestaetigung fehlt'], 400);
        }
        $state->set('openapi_token', null);
        $log->info('OpenAPI-Token vergessen (auf Wunsch des Benutzers)');
        out(['ok' => true, 'message' => 'Token vergessen. Alten Eintrag im SENEC-Portal entfernen, bevor neu registriert wird.']);

    default:
        out(['ok' => false, 'message' => 'Unbekannte Aktion'], 400);
}
