<?php
/**
 * Gemeinsamer Start fuer Dienst und Weboberflaeche: LoxBerry-Bibliotheken, Klassen, Pfade.
 * Ausserhalb von LoxBerry (Entwicklung/Tests) greift SENEC2LOX_DEV_DIR als Basisverzeichnis.
 */
foreach (['Compat', 'SenecDecode', 'StateTexts', 'WallboxTexts', 'Config', 'State', 'Logger', 'Http', 'MqttClient', 'LalaSource',
    'OpenApiSource', 'ConnectSource', 'Control', 'Mapper', 'Publisher', 'Catalog', 'GatewayInfo', 'AppVersion'] as $cls) {
    require_once __DIR__ . '/' . $cls . '.php';
}

final class Paths
{
    public string $folder;
    public string $config;
    public string $state;
    public string $logfile;
    public string $run;   // /dev/shm/<folder>
    public string $bin;

    public static function detect(): Paths
    {
        $p = new Paths();
        $dev = getenv('SENEC2LOX_DEV_DIR');
        if ($dev) {
            $p->folder = getenv('SENEC2LOX_DEV_FOLDER') ?: 'senec2lox';
            $p->config = $dev . '/senec2lox.json';
            $p->state = $dev . '/state.json';
            $p->logfile = $dev . '/senec2lox.log';
            $p->run = getenv('SENEC2LOX_DEV_RUN') ?: $dev . '/run';
            $p->bin = dirname(__DIR__);
        } else {
            require_once 'loxberry_system.php';
            $p->folder = LBPPLUGINDIR;
            $p->config = LBPCONFIGDIR . '/senec2lox.json';
            $p->state = LBPDATADIR . '/state.json';
            $p->logfile = LBPLOGDIR . '/senec2lox.log';
            $p->run = '/dev/shm/' . LBPPLUGINDIR;
            $p->bin = LBPBINDIR;
        }
        if (!is_dir($p->run)) {
            @mkdir($p->run, 0775, true);
        }
        return $p;
    }

    /** Gemeinsames Elternverzeichnis aller Plugin-Laufzeitordner (/dev/shm). */
    public function runBase(): string
    {
        return dirname($this->run);
    }

    public function statusFile(): string
    {
        return $this->run . '/status.json';
    }

    public function pidFile(): string
    {
        return $this->run . '/senec2lox.pid';
    }

    public function lockFile(): string
    {
        return $this->run . '/run.lock';
    }
}
