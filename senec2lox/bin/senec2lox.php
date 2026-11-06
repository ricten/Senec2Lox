<?php
/** Senec2Lox Hauptdienst: fragt SENEC-Quellen ab und veroeffentlicht sie per MQTT. */
if (PHP_SAPI !== 'cli') {
    exit(1);
}
if (!getenv('SENEC2LOX_DEV_DIR')) {
    require_once 'loxberry_system.php';
    require_once 'loxberry_io.php';
    require_once 'loxberry_log.php';
}
require_once __DIR__ . '/lib/bootstrap.php';

$paths = Paths::detect();

// Nur eine Instanz
$lock = fopen($paths->lockFile(), 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    exit(0);
}
file_put_contents($paths->pidFile(), (string)getmypid());

$log = new Logger($paths->logfile);
register_shutdown_function(function () use ($log) {
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        $log->error('Dienst abgestuerzt: ' . $e['message'] . ' (' . basename($e['file']) . ':' . $e['line'] . ')');
    }
});
$running = true;
if (function_exists('pcntl_async_signals')) {
    pcntl_async_signals(true);
    $stop = function () use (&$running) {
        $running = false;
    };
    pcntl_signal(SIGTERM, $stop);
    pcntl_signal(SIGINT, $stop);
}

$state = new State($paths->state);
$s2mcfg = null;
$cfgMtime = -1;
$mqtt = null;
$pub = null;
$mqttKey = '';
$mqttBackoff = 2;
$mqttNext = 0;
$mqtt_err = null;
$sources = [];      // name => ['obj'=>..., 'next'=>int]
$snap = [];         // name => ['ts','interval','data']
$status = [];       // name => ['ok','status','error']
$lastError = null;
$errorUntil = 0;
$startTime = time();
$sentCount = 0;
$mqttLosses = 0;
$mqttLastLoss = null;
$duplicates = [];
$dupCheck = 0;
/** Laufende Senec2Lox-Instanzen anderer Plugin-Ordner (z. B. zweite Installation, alter Plugin-Name). */
$findDuplicates = function () use ($paths) {
    $out = [];
    foreach (glob($paths->runBase() . '/*/senec2*.pid') ?: [] as $f) {
        if (realpath(dirname($f)) === realpath($paths->run)) {
            continue;
        }
        // Dienst laeuft, wenn er seine Status-Datei frisch schreibt (jede Sekunde) - robuster als eine PID-Pruefung
        $statusFile = dirname($f) . '/status.json';
        clearstatcache(true, $statusFile);
        $alive = is_file($statusFile) && (time() - (int)filemtime($statusFile)) < 15;
        if ($alive) {
            $out[] = basename(dirname($f));
        }
    }
    return $out;
};

/** Konfiguration (neu) laden und Quellen aufbauen. */
$reload = function () use (&$s2mcfg, &$cfgMtime, $paths, $log, $state, &$sources, &$snap, &$status) {
    clearstatcache(true, $paths->config);
    $m = is_file($paths->config) ? (int)filemtime($paths->config) : 0;
    if ($m === $cfgMtime && $s2mcfg !== null) {
        return false;
    }
    $cfgMtime = $m;
    $s2mcfg = Config::load($paths->config);
    $log->setLevel($s2mcfg['log_level']);
    $log->addSecret($s2mcfg['connect']['subscription_key']);
    $log->addSecret($s2mcfg['mqtt']['pass']);
    $log->info('Konfiguration geladen');
    $sources = [];
    foreach (['lala', 'openapi', 'connect'] as $n) {
        unset($status[$n]);
    }
    $c = $s2mcfg;
    if ($c['lala']['use'] && $c['lala']['host'] !== '') {
        $sources['lala'] = ['obj' => new LalaSource($c['lala'], $state, $log), 'next' => 0, 'interval' => $c['lala']['interval']];
    }
    if ($c['openapi']['use'] && ($c['openapi']['host'] !== '' || $c['lala']['host'] !== '')) {
        $sources['openapi'] = ['obj' => new OpenApiSource($c['openapi'], $c['lala']['host'], $state, $log), 'next' => 0, 'interval' => $c['openapi']['interval']];
    }
    if ($c['connect']['use'] && $c['connect']['subscription_key'] !== '') {
        $sources['connect'] = ['obj' => new ConnectSource($c['connect'], $log), 'next' => 0, 'interval' => $c['connect']['interval']];
    }
    $missing = [
        'lala' => [$c['lala']['use'] && $c['lala']['host'] === '', 'Keine IP / kein Hostname eingetragen'],
        'openapi' => [$c['openapi']['use'] && !isset($sources['openapi']), 'Kein Host eingetragen (und keine lala-IP)'],
        'connect' => [$c['connect']['use'] && !isset($sources['connect']), 'Kein Subscription-Key eingetragen'],
    ];
    foreach ($missing as $n => [$isMissing, $text]) {
        if ($isMissing) {
            $status[$n] = ['ok' => false, 'status' => 'nicht konfiguriert', 'error' => $text, 'time' => time()];
        }
    }
    foreach (array_keys($snap) as $n) {
        if (!isset($sources[$n])) {
            unset($snap[$n], $status[$n]);
        }
    }
    return true;
};

$brokerSource = '';
$brokerDetails = function () use (&$s2mcfg, &$brokerSource, $log) {
    $m = $s2mcfg['mqtt'];
    if ($m['use_loxberry_broker']) {
        if (!function_exists('mqtt_connectiondetails')) {
            $brokerSource = 'LoxBerry-Funktion mqtt_connectiondetails() nicht verfuegbar';
        } else {
            $d = mqtt_connectiondetails();
            if (is_array($d)) {
                $host = (string)($d['brokerhost'] ?? '');
                $port = (int)($d['brokerport'] ?? 0);
                if (($host === '' || $port === 0) && !empty($d['brokeraddress']) && strpos((string)$d['brokeraddress'], ':') !== false) {
                    [$h2, $p2] = explode(':', (string)$d['brokeraddress'], 2);
                    $host = $host !== '' ? $host : $h2;
                    $port = $port ?: (int)$p2;
                }
                if ($host !== '') {
                    $brokerSource = 'LoxBerry-Broker';
                    $log->debug('mqtt_connectiondetails Schluessel: ' . implode(',', array_keys($d)), 'mqtt-keys');
                    return [$host, $port ?: 1883, (string)($d['brokeruser'] ?? ''), (string)($d['brokerpass'] ?? '')];
                }
            }
            $brokerSource = 'mqtt_connectiondetails() lieferte keine Broker-Daten (MQTT im LoxBerry eingerichtet?)';
        }
        $log->warn($brokerSource . ' - verwende localhost ohne Zugangsdaten', 'mqtt-details');
    } else {
        $brokerSource = 'eigener Broker';
    }
    return [$m['host'] !== '' ? $m['host'] : 'localhost', (int)$m['port'], $m['user'], $m['pass']];
};

$log->info('Senec2Lox ' . AppVersion::CURRENT . ' Dienst gestartet (PID ' . getmypid() . ')');

$tick = 0;
while ($running) {
  try {
    $now = time();
    $reload();
    $state->reload();

    if (!$s2mcfg['enabled']) {
        if ($mqtt) {
            $mqtt->close();
            $mqtt = null;
        }
        file_put_contents($paths->statusFile(), json_encode(['service' => 'disabled', 'time' => $now]));
        sleep(2);
        continue;
    }

    if ($now >= $dupCheck) {
        $dupCheck = $now + 15;
        $duplicates = $findDuplicates();
        if ($duplicates) {
            $log->warn('Weitere Instanz erkannt (Plugin-Ordner: ' . implode(', ', $duplicates) . '). Beide fragen den Speicher ab und veroeffentlichen dieselben Topics (doppelte Last und Werte) - bitte ein Plugin deinstallieren.', 'duplicate');
        }
    }

    // --- MQTT ---
    [$h, $p, $u, $pw] = $brokerDetails();
    $key = "$h:$p:$u:" . md5($pw) . ':' . $s2mcfg['mqtt']['topic_prefix'] . ':' . (int)$s2mcfg['mqtt']['retain'] . (int)$s2mcfg['mqtt']['only_changes'] . $s2mcfg['mqtt']['full_resend_minutes'];
    if ($mqtt && $key !== $mqttKey) {
        $mqtt->close();
        $mqtt = null;
    }
    if ($mqtt && !$mqtt->connected()) {   // Verbindung ist waehrend der letzten Runde abgerissen
        $mqttLosses++;
        $mqttLastLoss = date('H:i:s') . ': ' . ($mqtt->lastError() ?: 'unbekannt') . ' (nach ' . $mqtt->uptime() . ' s)';
        $log->warn('MQTT-Verbindung verloren - ' . $mqttLastLoss);
        if ($mqtt->uptime() < 15) {   // Verbindung haelt nicht: nicht im Sekundentakt neu verbinden
            $mqttBackoff = min(60, max(4, $mqttBackoff * 2));
            $mqttNext = $now + $mqttBackoff;
        } else {
            $mqttBackoff = 2;
        }
        $mqtt = null;
        $pub = null;
    }
    if ($mqtt && $mqtt->connected() && $mqtt->uptime() > 30) {
        $mqttBackoff = 2;   // stabil
    }
    if (!$mqtt || !$mqtt->connected()) {
        if ($now >= $mqttNext) {
            $prefix = $s2mcfg['mqtt']['topic_prefix'];
            $mqtt = new MqttClient($h, $p, $u, $pw, 'senec2lox_' . preg_replace('/[^A-Za-z0-9_\-]/', '_', $paths->folder), $prefix . '/online');
            if ($mqtt->connect()) {
                $mqttKey = $key;
                $pub = new Publisher($mqtt, $prefix, (bool)$s2mcfg['mqtt']['retain'], (bool)$s2mcfg['mqtt']['only_changes'], (int)$s2mcfg['mqtt']['full_resend_minutes']);
                $pub->raw('online', '1');
                $log->info("MQTT verbunden mit $h:$p");
            } else {
                $mqtt_err = $mqtt->lastError();
                $mqtt = null;
                $pub = null;
                $mqttNext = $now + $mqttBackoff;
                $mqttBackoff = min(60, $mqttBackoff * 2);
                $mqttError = $mqtt_err ?? 'unbekannt';
                $log->warn("MQTT-Verbindung zu $h:$p fehlgeschlagen: $mqttError ($brokerSource)", 'mqtt-conn');
            }
        }
    }

    // --- Quellen ---
    foreach ($sources as $name => &$s) {
        if ($s['next'] > $now) {
            continue;
        }
        $r = $s['obj']->poll($now);
        if ($mqtt && $mqtt->connected()) {
            $mqtt->loop();   // Keepalive nach (evtl. langer) Abfrage
        }
        $s['next'] = $now + max(1, (int)$r['delay']);
        $status[$name] = ['ok' => $r['ok'], 'status' => $r['status'], 'error' => $r['error'], 'time' => $now];
        if ($r['ok']) {
            $snap[$name] = ['ts' => $now, 'interval' => $s['interval'], 'data' => $r['data']];
        } else {
            if (!isset($snap[$name])) {
                $snap[$name] = ['ts' => 0, 'interval' => $s['interval']];
            }
        }
        if (!empty($r['fp_changed'])) {
            $lastError = "Zertifikat von $name hat sich geaendert";
            $errorUntil = $now + 3600;
        }
    }
    unset($s);
    if ($lastError !== null && $now > $errorUntil) {
        $lastError = null;
    }

    // --- Mapper + Publish ---
    $built = Mapper::build($s2mcfg, $snap, $status, $now, $lastError);
    if ($mqtt && $mqtt->connected() && $pub) {
        $sentCount += $pub->publish($built['topics'], $now);
        $mqtt->loop();   // erkennt Abbrueche, sendet Keepalive; Verlust wird zu Beginn der naechsten Runde protokolliert
    }

    // --- Status in RAM ---
    $live = [];
    foreach ($built['topics'] as $t => $v) {
        if (strncmp($t, 'live/', 5) === 0) {
            $live[substr($t, 5)] = $v;
        }
    }
    $st = [
        'service' => 'running', 'pid' => getmypid(), 'time' => $now, 'started' => $startTime,
        'mqtt' => ($mqtt && $mqtt->connected()), 'mqtt_target' => "$h:$p", 'mqtt_error' => ($mqtt && $mqtt->connected()) ? null : $mqtt_err, 'mqtt_source' => $brokerSource, 'duplicates' => $duplicates, 'mqtt_losses' => $mqttLosses, 'mqtt_last_loss' => $mqttLastLoss,
        'mqtt_uptime' => ($mqtt && $mqtt->connected()) ? $mqtt->uptime() : 0, 'sent' => $sentCount,
        'live_source' => $built['live_source'], 'sources' => $status, 'live' => $live, 'error' => $lastError,
        'openapi_token' => (bool)$state->get('openapi_token'), 'fingerprint_lala' => $state->get('lala_fp'),
    ];
    @file_put_contents($paths->statusFile(), json_encode($st));
    $log->rotateCheck();
    sleep(1);
  } catch (Throwable $e) {
    // Ein Fehler in einer Runde darf den Dienst nicht beenden (sonst Unterbrechung bis zum Watchdog)
    $log->error('Interner Fehler: ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')',
        'tick-' . md5($e->getMessage() . $e->getLine()));
    sleep(2);
  }
}

// Sauber beenden
if ($mqtt && $mqtt->connected() && $pub) {
    $pub->raw('online', '0');
    $mqtt->close();
}
$log->info('Senec2Lox Dienst beendet');
$log->end();
@unlink($paths->pidFile());
flock($lock, LOCK_UN);
