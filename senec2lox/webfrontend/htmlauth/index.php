<?php
// Fatale PHP-Fehler sichtbar in der Seite ausgeben (statt stillem Abbruch der Ausgabe)
register_shutdown_function(function () {
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
        echo '</details></form><pre style="color:#cf222e;white-space:pre-wrap;border:2px solid #cf222e;padding:8px">PHP-Fehler: '
            . htmlspecialchars($e['message'] . ' (' . basename($e['file']) . ':' . $e['line'] . ', PHP ' . PHP_VERSION . ')') . '</pre>';
    }
});
require_once "loxberry_system.php";
require_once "loxberry_web.php";
require_once LBPBINDIR . "/lib/bootstrap.php";

session_start();
if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(16));
}
$csrf = $_SESSION['csrf'];
$paths = Paths::detect();

function h($s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

/** Formularzeile im Design System: Beschriftung links, Feld rechts, optional Hilfetext. */
function frow(string $label, string $field, string $help = '', string $for = ''): string
{
    return '<div class="lb-form-row"><label class="lb-form-label"' . ($for !== '' ? ' for="' . h($for) . '"' : '') . '>' . h($label) . '</label>'
        . '<div class="lb-form-field">' . $field . '</div>' . ($help !== '' ? '<div class="lb-form-help">' . h($help) . '</div>' : '') . '</div>';
}

/** Eingabefeld (Feldname = id). */
function fin(string $name, $value, string $type = 'text', string $attr = ''): string
{
    return '<input class="lb-input" type="' . h($type) . '" name="' . h($name) . '" id="' . h($name) . '" value="' . h($value) . '"' . ($attr !== '' ? ' ' . $attr : '') . '>';
}

/** Auswahlliste. @param array<string,string> $optionen */
function fsel(string $name, array $optionen, string $aktuell): string
{
    $o = '<select class="lb-select" name="' . h($name) . '" id="' . h($name) . '">';
    foreach ($optionen as $v => $t) {
        $o .= '<option value="' . h($v) . '"' . ((string)$v === $aktuell ? ' selected' : '') . '>' . h($t) . '</option>';
    }
    return $o . '</select>';
}

/** Ein/Aus-Schalter (Checkbox mit Wert 1) als Formularzeile. */
function ftog(string $name, bool $an, string $text): string
{
    return '<div class="lb-form-row"><label class="lb-form-label" for="' . h($name) . '">' . h($text) . '</label><div class="lb-form-field">'
        . '<label class="lb-toggle"><input type="checkbox" name="' . h($name) . '" id="' . h($name) . '" value="1"' . ($an ? ' checked' : '') . '><span class="lb-toggle-slider"></span></label></div></div>';
}

$s2mcfg = Config::load($paths->config);
$msg = '';

// ---- Speichern ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['do'] ?? '') === 'save') {
    if (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) {
        $msg = 'Ungueltiges CSRF-Token.';
    } else {
        $p = function ($k, $d = '') {
            return isset($_POST[$k]) ? trim((string)$_POST[$k]) : $d;
        };
        $b = function ($k) {
            return !empty($_POST[$k]);
        };
        // Schnellabfrage: Checkboxen "SEKTION.KEY" + erweiterte Zeilen "SEKTION=KEY1,KEY2"
        $bySec = [];
        foreach ((array)($_POST['lala_fast'] ?? []) as $sk) {
            if (is_string($sk) && preg_match('/^([A-Z0-9_]+)\.([A-Z0-9_]+)$/', $sk, $mm)) {
                $bySec[$mm[1]][] = $mm[2];
            }
        }
        $fastText = Config::formatFast($bySec) . "\n" . (string)($_POST['lala_fast_extra'] ?? '');
        $n = $s2mcfg;
        $n['enabled'] = $b('enabled');
        $n['lala'] = ['use' => $b('lala_use'), 'host' => $p('lala_host'), 'interval' => (int)$p('lala_interval', '10'),
            'interval_full' => (int)$p('lala_interval_full', '60'),
            'full_sections' => implode(',', array_merge(array_map('strval', (array)($_POST['lala_full'] ?? [])),
                array_filter(array_map('trim', explode(',', (string)($_POST['lala_full_extra'] ?? '')))))),
            'timeout' => (int)$p('lala_timeout', '5'),
            'scheme' => $p('lala_scheme', 'auto'), 'fast_sections' => $fastText];
        $n['openapi'] = ['use' => $b('openapi_use'), 'host' => $p('openapi_host'), 'interval' => (int)$p('openapi_interval', '60'),
            'timeout' => (int)$p('openapi_timeout', '10'), 'include' => implode(',', (array)($_POST['openapi_include'] ?? [])),
            'device_name' => $p('openapi_device_name', 'LoxBerry')];
        $key = $p('connect_key');
        $n['connect'] = ['use' => $b('connect_use'), 'subscription_key' => $key !== '' ? $key : $s2mcfg['connect']['subscription_key'],
            'system_filter' => $p('connect_filter'), 'interval' => (int)$p('connect_interval', '300'),
            'timeout' => (int)$p('connect_timeout', '30'), 'include' => implode(',', (array)($_POST['connect_include'] ?? []))];
        $n['live_source_priority'] = $p('live_source_priority', 'lala,openapi,connect');
        $n['control'] = ['warnung_ok' => $b('ctl_warnung'), 'zwangsladung' => $b('ctl_zwangsladung'), 'steckdosen' => $b('ctl_steckdosen'), 'wallbox' => $b('ctl_wallbox')];
        $pass = $p('mqtt_pass');
        $n['mqtt'] = ['use_loxberry_broker' => $b('mqtt_lb'), 'host' => $p('mqtt_host'), 'port' => (int)$p('mqtt_port', '1883'),
            'user' => $p('mqtt_user'), 'pass' => $pass !== '' ? $pass : $s2mcfg['mqtt']['pass'],
            'topic_prefix' => $p('mqtt_prefix', 'senec'), 'retain' => $b('mqtt_retain'), 'only_changes' => $b('mqtt_only_changes'),
            'full_resend_minutes' => (int)$p('mqtt_resend', '5'), 'publish_raw' => $b('mqtt_raw')];
        $n['log_level'] = $s2mcfg['log_level'];   // gilt nur noch als Rueckfall ohne LoxBerry
        $n = Config::validate($n);
        $msg = Config::save($paths->config, $n) ? 'Gespeichert. Der Dienst uebernimmt die Aenderungen automatisch.' : 'Speichern fehlgeschlagen.';
        $s2mcfg = $n;
    }
}

$sel = function (bool $c): string {
    return $c ? ' checked' : '';
};
$inc = function (string $csv): array {
    return array_filter(explode(',', $csv));
};

// Reiter wählt der Server (?tab=...): Alle Bereiche bleiben im Formular, nur die nicht gewählten sind ausgeblendet,
// damit ein Speichern nie Felder der anderen Reiter verliert.
$tabs = ['einstellungen' => 'Einstellungen', 'mqtt' => 'MQTT', 'loxone' => 'Einbindung in Loxone', 'test' => 'Test', 'logs' => 'Logdateien'];
$tab = (string)($_GET['tab'] ?? 'einstellungen');
if (!isset($tabs[$tab])) {
    $tab = 'einstellungen';
}
$hide = fn(string $t): string => $t === $tab ? '' : ' style="display:none"';
// Menüleiste der LoxBerry-Oberfläche: $navbar muss vor lbheader() definiert sein
$navbar = [];
$nr = 10;
foreach ($tabs as $k => $t) {
    $navbar[$nr] = ['Name' => $t, 'URL' => '?tab=' . $k] + ($tab === $k ? ['active' => true] : []);
    $nr += 10;
}
// Kommt das Abo beim Gateway an? (Gateway V2 liest die Abo-Datei des Plugins nicht)
$aboFilter = $s2mcfg['mqtt']['topic_prefix'] . '/#';
$gw = GatewayInfo::subscription($aboFilter, GatewayInfo::general());
$udp = GatewayInfo::udpPort(function_exists('mqtt_connectiondetails') ? mqtt_connectiondetails() : null, GatewayInfo::general());   // UDP-Port des Gateways fuer Befehle aus Loxone

// 4. Parameter true: kein jQuery Mobile, LoxBerry Design System (lb-* Klassen)
LBWeb::lbheader("Senec2Lox", "", "", true);
?>
<style>
  /* Farben und Abstaende kommen aus dem LoxBerry Design System (folgen dem gewaehlten Theme, auch dunkel) */
  .s2m-ok { color: var(--lb-success, #1a7f37); font-weight: bold; }
  .s2m-bad { color: var(--lb-danger, #cf222e); font-weight: bold; }
  .s2m-warn { color: var(--lb-warning, #9a6700); font-weight: bold; }
  .s2m .hint { color: var(--lb-text-muted, #666); font-size: 90%; }
  .s2m .s2m-ver { margin: 0 0 4px; text-align: right; }
  .s2m pre { background: var(--lb-gray-50, #f4f4f4); color: var(--lb-text, #222); border: 1px solid var(--lb-border-color, #ccc); border-radius: var(--lb-radius, 6px); padding: 8px; overflow: auto; font-size: 12px; max-height: 300px; }
  .s2m .lb-collapsible { margin: 10px 0; }
  .s2m .lb-card { margin: 10px 0; }
  .s2m .lb-card h3 { margin-top: 0; }
  .s2m .s2m-dd summary .cnt { margin-left: auto; padding-right: 12px; }
  .s2m .dd-tools { margin: 4px 0; }
  .s2m .s2m-list > label { display: block; margin-top: 4px; }
  .s2m .s2m-actions { margin: 8px 0; display: flex; flex-wrap: wrap; gap: 8px; }
  .s2m fieldset { border: 1px solid var(--lb-border-color, #ccc); border-radius: var(--lb-radius, 6px); margin: 8px 0; }
</style>
<div class="lb-content s2m">
<p class="hint s2m-ver">Senec2Lox Version <?= h(AppVersion::CURRENT) ?></p>
<?php if ($msg !== '') { ?><div class="lb-callout"><b><?= h($msg) ?></b></div><?php } ?>

<div class="s2m-set">

<form method="post">
<input type="hidden" name="do" value="save"><input type="hidden" name="csrf" value="<?= h($csrf) ?>">

<div class="s2m-pane" data-tab="einstellungen"<?= $hide('einstellungen') ?>>
<details class="lb-collapsible s2m-sec"><summary>Allgemein</summary><div class="lb-collapsible-content">
  <?= ftog('enabled', $s2mcfg['enabled'], 'Dienst aktiviert') ?>
  <p class="hint">Der Loglevel wird in der LoxBerry-Plugin-Verwaltung eingestellt (Plugin-Eintrag, Auswahl „Loglevel“). Für die Fehlersuche dort „Debug“ wählen.</p>
</div></details>

<details class="lb-collapsible s2m-sec"><summary>Lokal (lala.cgi)</summary><div class="lb-collapsible-content">
  <?= ftog('lala_use', $s2mcfg['lala']['use'], 'Quelle aktiv') ?>
  <?= frow('IP / Hostname des SENEC-Speichers', fin('lala_host', $s2mcfg['lala']['host']), '', 'lala_host') ?>
  <?= frow('Intervall Livewerte (s, min. 3)', fin('lala_interval', $s2mcfg['lala']['interval'], 'number', 'min="3"'), '', 'lala_interval') ?>
  <?= frow('Intervall Vollabfrage (s)', fin('lala_interval_full', $s2mcfg['lala']['interval_full'], 'number', 'min="10"'), '', 'lala_interval_full') ?>
  <?= frow('Timeout (s)', fin('lala_timeout', $s2mcfg['lala']['timeout'], 'number', 'min="1" max="30"'), '', 'lala_timeout') ?>
  <?= frow('Protokoll', fsel('lala_scheme', ['auto' => 'automatisch (HTTPS, sonst HTTP)', 'https' => 'nur HTTPS', 'http' => 'nur HTTP'], $s2mcfg['lala']['scheme']), '', 'lala_scheme') ?>
  <?php
  // Aktuelle Auswahl: gespeicherte Liste, sonst Standardliste
  $fastActive = Config::parseFast($s2mcfg['lala']['fast_sections']) ?: LalaSource::FAST;
  $fastFlat = Catalog::fastFlat();
  $fastExtra = [];
  foreach ($fastActive as $sec => $keys) {
      $unknown = array_values(array_filter($keys, function ($k) use ($sec, $fastFlat) {
          return !in_array("$sec.$k", $fastFlat, true);
      }));
      if ($unknown) {
          $fastExtra[$sec] = $unknown;
      }
  }
  $fullActive = array_filter(explode(',', $s2mcfg['lala']['full_sections']));
  $fullExtra = array_values(array_diff($fullActive, Catalog::fullFlat()));
  ?>
  <h4>Schnellabfrage (alle <?= h($s2mcfg['lala']['interval']) ?> s) &ndash; Livewerte</h4>
  <p class="hint">Diese Datenpunkte werden im kurzen Takt abgefragt. Fuer die Topics <code>live/*</code> werden die Punkte aus <i>Energie &amp; Live-Leistung</i> benoetigt. Ohne jede Auswahl gilt die Standardliste.</p>
  <?php foreach (Catalog::fastKeys() as $sec => [$title, $keys]) {
      $cnt = count(array_filter(array_keys($keys), function ($k) use ($sec, $fastActive) {
          return in_array($k, $fastActive[$sec] ?? [], true);
      })); ?>
    <details class="lb-collapsible s2m-dd"><summary><span><?= h($title) ?> <span class="hint">(<?= h($sec) ?>)</span></span> <b class="cnt"><?= $cnt ?>/<?= count($keys) ?></b></summary><div class="lb-collapsible-content">
      <p class="dd-tools"><button type="button" class="lb-btn lb-btn-sm" onclick="ddAll(this,true)">alle</button> <button type="button" class="lb-btn lb-btn-sm" onclick="ddAll(this,false)">keine</button></p>
      <div class="s2m-list">
      <?php foreach ($keys as $k => $label) { ?>
        <label><input type="checkbox" name="lala_fast[]" value="<?= h("$sec.$k") ?>"<?= $sel(in_array($k, $fastActive[$sec] ?? [], true)) ?> onchange="ddCount(this)"> <?= h($label) ?> <span class="hint"><?= h($k) ?></span></label>
      <?php } ?>
      </div>
    </div></details>
  <?php } ?>
  <details class="lb-collapsible s2m-dd"><summary><span>Erweitert: weitere Datenpunkte <span class="hint">(je Zeile SEKTION=KEY1,KEY2)</span></span></summary><div class="lb-collapsible-content">
    <textarea class="lb-textarea" name="lala_fast_extra" rows="3"><?= h(Config::formatFast($fastExtra)) ?></textarea>
  </div></details>

  <h4>Vollabfrage (alle <?= h($s2mcfg['lala']['interval_full']) ?> s) &ndash; komplette Sektionen</h4>
  <p class="hint">Hier werden ganze Sektionen mit allen Werten abgefragt (viele Rohwert-Topics, nur Aenderungen werden gesendet). Nur auswaehlen, was gebraucht wird. STATISTIC ist von SENEC abgeschaltet.</p>
  <?php foreach (Catalog::fullSections() as $cat => [$title, $secs]) {
      $cnt = count(array_filter(array_keys($secs), function ($s) use ($fullActive) {
          return in_array($s, $fullActive, true);
      })); ?>
    <details class="lb-collapsible s2m-dd"><summary><span><?= h($title) ?></span> <b class="cnt"><?= $cnt ?>/<?= count($secs) ?></b></summary><div class="lb-collapsible-content">
      <p class="dd-tools"><button type="button" class="lb-btn lb-btn-sm" onclick="ddAll(this,true)">alle</button> <button type="button" class="lb-btn lb-btn-sm" onclick="ddAll(this,false)">keine</button></p>
      <div class="s2m-list">
      <?php foreach ($secs as $s => $label) { ?>
        <label><input type="checkbox" name="lala_full[]" value="<?= h($s) ?>"<?= $sel(in_array($s, $fullActive, true)) ?> onchange="ddCount(this)"> <?= h($label) ?> <span class="hint"><?= h($s) ?></span></label>
      <?php } ?>
      </div>
    </div></details>
  <?php } ?>
  <details class="lb-collapsible s2m-dd"><summary><span>Erweitert: weitere Sektionen <span class="hint">(Komma-getrennt)</span></span></summary><div class="lb-collapsible-content">
    <input class="lb-input" type="text" name="lala_full_extra" value="<?= h(implode(',', $fullExtra)) ?>">
  </div></details>
  <p class="hint">Der Request-Body der lala.cgi darf maximal ca. 11 500 Byte gross sein; das Plugin teilt grosse Abfragen automatisch auf. STATISTIC wird nie abgefragt.</p>
  <p class="hint">Zu haeufiges Abfragen kann den Datentransfer des Speichers zu den SENEC-Servern stoeren. Default 10 s.</p>
  <div class="s2m-actions">
    <button type="button" class="lb-btn" onclick="act('test_lala', {}, 'lala-result')">Verbindung testen</button>
    <button type="button" class="lb-btn" onclick="if(confirm('Zertifikats-Fingerprints zuruecksetzen?'))act('reset_fingerprint')">Fingerprint zuruecksetzen</button>
  </div>
  <p>Fingerprint: <code id="fp-lala">-</code></p>
  <pre id="lala-result" style="display:none"></pre>
</div></details>

<details class="lb-collapsible s2m-sec"><summary>Lokale OpenAPI</summary><div class="lb-collapsible-content">
  <?= ftog('openapi_use', $s2mcfg['openapi']['use'], 'Quelle aktiv') ?>
  <?= frow('Host (leer = wie lala.cgi)', fin('openapi_host', $s2mcfg['openapi']['host']), '', 'openapi_host') ?>
  <?= frow('Intervall (s, min. 10)', fin('openapi_interval', $s2mcfg['openapi']['interval'], 'number', 'min="10"'), '', 'openapi_interval') ?>
  <?= frow('Timeout (s)', fin('openapi_timeout', $s2mcfg['openapi']['timeout'], 'number', 'min="1" max="60"'), '', 'openapi_timeout') ?>
  <?= frow('Gerätename im Portal', fin('openapi_device_name', $s2mcfg['openapi']['device_name']), '', 'openapi_device_name') ?>
  <fieldset><legend>Sektionen</legend>
    <div class="s2m-list">
    <?php $oi = $inc($s2mcfg['openapi']['include']);
    foreach (['battery', 'meter', 'bess', 'bessNameplate', 'evse'] as $s) { ?>
      <label><input type="checkbox" name="openapi_include[]" value="<?= $s ?>"<?= $sel(in_array($s, $oi, true)) ?>> <?= $s ?></label>
    <?php } ?>
    </div>
  </fieldset>
  <div id="openapi-state"><p>Status: <span id="oa-status">-</span></p></div>
  <div id="oa-unregistered" style="display:none">
    <p>Die Registrierung erzeugt einen neuen Eintrag im SENEC-Portal. Nur einmal ausfuehren.</p>
    <button type="button" class="lb-btn" onclick="if(confirm('Jetzt am Speicher registrieren? Dies erzeugt einen Eintrag im SENEC-Portal.'))act('openapi_register', {}, 'oa-result')">Am Speicher registrieren</button>
  </div>
  <div id="oa-pending" style="display:none" class="lb-callout lb-callout-warning">
    <p>Freigabe ausstehend. Bitte im Portal freigeben:
      <a href="https://mein-senec.de/endkunde/#/0/senec-open-api" target="_blank" rel="noopener">https://mein-senec.de/endkunde/#/0/senec-open-api</a><br>
      <span class="hint">Die <code>0</code> ist der Anlagen-Index; bei mehreren Anlagen bzw. nach MCU-Tausch <code>1</code>, <code>2</code> ... Die Seite ist im Portal-Menue nicht verlinkt.</span></p>
  </div>
  <div id="oa-rejected" style="display:none">
    <p class="s2m-bad">Token ungueltig oder widerrufen. Im Portal pruefen.</p>
  </div>
  <div id="oa-unreachable" style="display:none">
    <p class="s2m-bad">Port 4424 nicht erreichbar: Das Geraet bietet keine OpenAPI (V4/P4/E4 oder Firmware &lt; 4.4.0).</p>
  </div>
  <div id="oa-forget" style="display:none">
    <button type="button" class="lb-btn lb-btn-danger" onclick="if(confirm('Token vergessen?\n\nEntferne zuerst den alten Eintrag im SENEC-Portal, bevor du neu registrierst, sonst bleiben Karteileichen im Portal.')) act('openapi_forget', {confirm:'1'}, 'oa-result')">Token vergessen</button>
  </div>
  <details class="lb-collapsible s2m-dd"><summary><span>Vorhandenen Token eintragen (statt neu zu registrieren)</span></summary><div class="lb-collapsible-content">
    <?= frow('Token (UUID)', '<input class="lb-input" type="password" id="oa-token" autocomplete="off" placeholder="nur moeglich, wenn noch kein Token gespeichert ist">', '', 'oa-token') ?>
    <button type="button" class="lb-btn" onclick="act('openapi_set_token', {token: document.getElementById('oa-token').value}, 'oa-result'); document.getElementById('oa-token').value=''">Token speichern</button>
  </div></details>
  <div class="s2m-actions"><button type="button" class="lb-btn" onclick="act('openapi_check', {}, 'oa-result')">Status pruefen</button></div>
  <p id="oa-result"></p>
</div></details>

<details class="lb-collapsible s2m-sec"><summary>SENEC.Connect</summary><div class="lb-collapsible-content">
  <?= ftog('connect_use', $s2mcfg['connect']['use'], 'Quelle aktiv') ?>
  <?= frow('Subscription-Key', '<input class="lb-input" type="password" name="connect_key" id="connect_key" autocomplete="off" value="" placeholder="' . h($s2mcfg['connect']['subscription_key'] !== '' ? '******** (gespeichert, leer lassen = behalten)' : '') . '">', '', 'connect_key') ?>
  <?= frow('Anlagen-Filter (Seriennummer oder System-ID, leer = alle)', fin('connect_filter', $s2mcfg['connect']['system_filter']), '', 'connect_filter') ?>
  <?= frow('Intervall (s)', fin('connect_interval', $s2mcfg['connect']['interval'], 'number', 'min="30"'), '', 'connect_interval') ?>
  <?= frow('Timeout (s)', fin('connect_timeout', $s2mcfg['connect']['timeout'], 'number', 'min="1" max="120"'), '', 'connect_timeout') ?>
  <fieldset><legend>Sektionen (bessNameplate wird immer abgefragt)</legend>
    <div class="s2m-list">
    <?php $ci = $inc($s2mcfg['connect']['include']);
    foreach (['battery', 'meter', 'evse', 'bessNameplate', 'bess'] as $s) { ?>
      <label><input type="checkbox" name="connect_include[]" value="<?= $s ?>"<?= $sel(in_array($s, $ci, true)) ?>> <?= $s ?></label>
    <?php } ?>
    </div>
  </fieldset>
  <div class="s2m-actions"><button type="button" class="lb-btn" onclick="act('test_connect', {key: document.getElementById('connect_key').value}, 'connect-result')">Verbindung testen</button></div>
  <pre id="connect-result" style="display:none"></pre>
  <p class="hint">Key: <a href="https://developer.senec.com/instructions" target="_blank" rel="noopener">developer.senec.com</a> &rarr; Produkte abonnieren &rarr; Profil &rarr; Meine Abonnements.</p>
</div></details>

<details class="lb-collapsible s2m-sec"><summary>Steuerung (Befehle aus Loxone)</summary><div class="lb-collapsible-content">
  <div class="lb-callout lb-callout-warning"><b>Nur auf eigene Gefahr.</b> Diese Befehle ändern Einstellungen an Ihrem SENEC-Speicher über die lokale, nicht dokumentierte Schnittstelle <code>lala.cgi</code>
    (dieselbe, die der ioBroker-Adapter nutzt). SENEC unterstützt das nicht; falsche Werte können den Betrieb stören. Es gibt keinen Schutz vor widersprüchlichen Befehlen aus mehreren Quellen
    (Loxone, App, mein-senec.de). Die Befehle sind ab Werk gesperrt. Geprüft sind an einem echten Speicher die Zwangsladung und die Steckdosen (auch dauer_ein, kurz); auch die Wallbox wurde am echten Speicher geprüft (der Speicher übernimmt Ladeströme erst nach einigen Sekunden; der Standard-Ladestrom ließ sich nicht ändern). Notstromreserve, Peak-Shaving und SG-Ready gibt es nur über mein-senec.de und sind nicht enthalten.</div>
  <?= ftog('ctl_warnung', !empty($s2mcfg['control']['warnung_ok']), 'Ich habe den Warnhinweis gelesen und möchte Steuerbefehle zulassen') ?>
  <?= ftog('ctl_zwangsladung', !empty($s2mcfg['control']['zwangsladung']), 'Zwangsladung der Batterie an/aus') ?>
  <?= ftog('ctl_steckdosen', !empty($s2mcfg['control']['steckdosen']), 'Schaltbare Steckdosen (Modus, Schwellen, Zeiten)') ?>
  <?= ftog('ctl_wallbox', !empty($s2mcfg['control']['wallbox']), 'Wallbox (Ladeströme, Smart-Laden, Zwischenladen)') ?>
  <p class="hint">Ohne bestätigten Warnhinweis bleibt alles gesperrt, auch wenn eine Gruppe angehakt ist. Befehle gehen nur über die lokale Quelle (lala.cgi). Für Steckdosen und Wallbox liest das Plugin zusätzlich die Abschnitte SOCKETS und WALLBOX,
    damit ihre Einstellungen als Rohwerte in Loxone ankommen. Die Befehls-Topics stehen im Reiter „Einbindung in Loxone“.</p>
</div></details>

<button type="submit" class="lb-btn lb-btn-primary">Speichern</button>
</div>

<div class="s2m-pane" data-tab="mqtt"<?= $hide('mqtt') ?>>
<div class="lb-card"><h3>MQTT</h3>
  <p>Gateway-Abo (damit die Werte im Miniserver ankommen):</p>
  <?= GatewayInfo::feldHtml($gw, $aboFilter) ?>
  <?= ftog('mqtt_lb', $s2mcfg['mqtt']['use_loxberry_broker'], 'LoxBerry-Broker verwenden') ?>
  <?= frow('Eigener Broker: Host', fin('mqtt_host', $s2mcfg['mqtt']['host']), '', 'mqtt_host') ?>
  <?= frow('Port', fin('mqtt_port', $s2mcfg['mqtt']['port'], 'number'), '', 'mqtt_port') ?>
  <?= frow('Benutzer', fin('mqtt_user', $s2mcfg['mqtt']['user']), '', 'mqtt_user') ?>
  <?= frow('Passwort', '<input class="lb-input" type="password" name="mqtt_pass" id="mqtt_pass" autocomplete="off" value="" placeholder="' . h($s2mcfg['mqtt']['pass'] !== '' ? '******** (leer lassen = behalten)' : '') . '">', '', 'mqtt_pass') ?>
  <?= frow('Topic-Praefix', fin('mqtt_prefix', $s2mcfg['mqtt']['topic_prefix']), '', 'mqtt_prefix') ?>
  <?= ftog('mqtt_retain', $s2mcfg['mqtt']['retain'], 'retain') ?>
  <?= ftog('mqtt_only_changes', $s2mcfg['mqtt']['only_changes'], 'nur Aenderungen senden') ?>
  <?= frow('Komplett-Resend alle (min)', fin('mqtt_resend', $s2mcfg['mqtt']['full_resend_minutes'], 'number', 'min="1"'), '', 'mqtt_resend') ?>
  <?= ftog('mqtt_raw', $s2mcfg['mqtt']['publish_raw'], 'Rohwerte veroeffentlichen') ?>
  <?= frow('Reihenfolge der Live-Quellen', fin('live_source_priority', $s2mcfg['live_source_priority']), 'Gueltige Werte: lala, openapi, connect (Komma-getrennt).', 'live_source_priority') ?>
</div>
<button type="submit" class="lb-btn lb-btn-primary">Speichern</button>
</div>
</form>

<div class="s2m-pane" data-tab="loxone"<?= $hide('loxone') ?>>
<div class="lb-card"><h3>Einbindung in Loxone</h3>
  <p>Im LoxBerry-MQTT-Gateway das Topic <code><?= h($aboFilter) ?></code> abonnieren (bzw.
     <code><?= h($s2mcfg['mqtt']['topic_prefix']) ?>/live/#</code> und <code><?= h($s2mcfg['mqtt']['topic_prefix']) ?>/online</code>).
     Das Gateway ersetzt <code>/</code> durch <code>_</code>. Anschliessend im Loxone Config virtuelle Eingaenge (z. B. <code><?= h($s2mcfg['mqtt']['topic_prefix']) ?>_live_pv_w</code>) anlegen.</p>
  <p>Gateway-Abo:</p>
  <?= GatewayInfo::feldHtml($gw, $aboFilter) ?>
  <p class="hint">Beim Gateway V2 muss das Abo von Hand im MQTT Gateway unter „Abonnements“ eingetragen werden; die Abo-Datei des Plugins liest nur das Gateway V1. Ändert man den Topic-Präfix, muss das Abo angepasst werden.</p>
</div>
<div class="lb-card"><h3>Befehle aus Loxone (Steuerung)</h3>
  <p>Ab Werk gesperrt (Reiter „Einstellungen“ → Steuerung): erst Warnhinweis bestätigen, dann die Gruppe freigeben.
     Virtueller Ausgang: Adresse <code>/dev/udp/&lt;LoxBerry-IP&gt;/<?= (int)$udp ?></code>, Befehl bei EIN
     <code>publish <?= h($s2mcfg['mqtt']['topic_prefix']) ?>/befehl/&lt;befehl&gt; &lt;wert&gt;</code> (ohne HTTP-Header/Body). Die Quittung steht unter <code>&lt;topic&gt;/quittung</code>:
     <code>angenommen</code>, dann <code>ausgefuehrt</code> oder <code>abgelehnt: &lt;grund&gt;</code> (nie retained). Höchstens ein Befehl alle <?= (int)Control::MIN_ABSTAND ?> Sekunden.</p>
  <p>Freigegeben:
  <?php foreach (Control::GRUPPEN as $gk => $gt) { echo h($gt) . ': ' . (Control::wirdFrei($s2mcfg, $gk) ? '<span class="s2m-ok">ja</span>' : '<span class="s2m-bad">nein</span>') . ' &nbsp; '; } ?></p>
  <div class="lb-table-scroll"><table class="lb-table"><tr><th>Topic</th><th>Wert</th><th>Wirkung</th></tr>
  <?php
  $pf = $s2mcfg['mqtt']['topic_prefix'] . '/befehl/';
  echo '<tr><td><code>' . h($pf) . 'zwangsladung</code></td><td>1 / 0</td><td>Zwangsladung der Batterie an / aus (der Zustand steht in <code>' . h($s2mcfg['mqtt']['topic_prefix']) . '/live/zwangsladung</code>)</td></tr>';
  foreach (Control::STECKDOSE as $f => $t) {
      echo '<tr><td><code>' . h($pf) . 'steckdose&lt;N&gt;/' . h($f) . '</code></td><td>' . ($f === 'modus' ? 'aus / automatik / dauer_ein' : 'Zahl') . '</td><td>' . h($t) . '</td></tr>';
  }
  foreach (Control::WALLBOX as $f => $t) {
      echo '<tr><td><code>' . h($pf) . 'wallbox&lt;N&gt;/' . h($f) . '</code></td><td>' . (in_array($f, ['smart_laden', 'zwischenladen'], true) ? '1 / 0' : 'Ampere') . '</td><td>' . h($t) . '</td></tr>';
  }
  ?></table></div>
  <p class="hint"><code>&lt;N&gt;</code> ist die Nummer der Steckdose bzw. Wallbox, ab 1 (der Speicher unterstützt bis zu vier Wallboxen). Die aktuellen Einstellungen der Steckdosen und Wallboxen stehen (bei freigegebener Gruppe) als Rohwerte unter <code><?= h($s2mcfg['mqtt']['topic_prefix']) ?>/raw/SOCKETS/…</code> und <code>…/raw/WALLBOX/…</code>.
     Nicht dabei: Reboot des Speichers, Notstromreserve, Peak-Shaving, SG-Ready (nur über mein-senec.de).</p>
</div>
<div class="lb-card"><h3>Status und Lebenszeichen</h3>
  <div class="lb-table-scroll"><table class="lb-table"><tr><th>Topic</th><th>Eingangsname</th><th>Bedeutung</th></tr>
  <?php foreach ([['online', '1 = Dienst läuft, 0 = beendet (retained)'], ['status/ok', '1 = aktuelle Live-Werte vorhanden, sonst 0'],
      ['status/zaehler', 'Lebenszeichen: zählt 0…999 weiter (im Gateway „No Cache“); steht er still, ist der Dienst ausgefallen'],
      ['status/ts', 'Unix-Zeit des letzten Durchgangs']] as [$stTopic, $stText]) { $stFull = $s2mcfg['mqtt']['topic_prefix'] . '/' . $stTopic; ?>
  <tr><td><code><?= h($stFull) ?></code></td><td><?= h(str_replace('/', '_', $stFull)) ?></td><td class="lb-cell-wrap"><?= h($stText) ?></td></tr>
  <?php } ?></table></div>
</div>
<div class="lb-card"><h3>Livewerte</h3>
  <div class="lb-table-scroll"><table class="lb-table" id="live-table"><tr><th>Topic</th><th>Eingangsname</th><th>Wert</th></tr></table></div>
</div>
</div>

<div class="s2m-pane" data-tab="test"<?= $hide('test') ?>>
<div class="lb-card"><h3>Status</h3>
  <p>Dienst: <span id="st-service">...</span> &middot; MQTT: <span id="st-mqtt">...</span>
     &middot; Live-Quelle: <span id="st-live">-</span></p>
  <div class="lb-table-scroll"><table class="lb-table" id="st-sources"><tr><th>Quelle</th><th>Status</th><th>Letzte Abfrage</th><th>Meldung</th></tr></table></div>
  <p>Gateway-Abo <code><?= h($aboFilter) ?></code>: <span class="<?= $gw['state'] === 'ok' ? 's2m-ok' : ($gw['state'] === 'fehler' ? 's2m-bad' : 's2m-warn') ?>"><?= h($gw['text']) ?></span></p>
  <p id="st-error" class="s2m-bad"></p>
  <p id="st-dup" class="s2m-bad"></p>
  <div class="s2m-actions">
    <button type="button" class="lb-btn" onclick="act('restart')">Neu starten</button>
    <button type="button" class="lb-btn lb-btn-danger" onclick="act('stop')">Stoppen</button>
  </div>
  <p id="act-result"></p>
</div>
<div class="lb-card"><h3>Wartung</h3>
  <p class="hint">Der OpenAPI-Token wird von keiner dieser Aktionen geloescht (dafuer gibt es "Token vergessen" im Reiter OpenAPI).</p>
  <div class="s2m-actions">
    <button type="button" class="lb-btn" onclick="act('restart', {}, 'wartung-result')">Dienst neu starten</button>
    <button type="button" class="lb-btn" onclick="act('clear_status', {}, 'wartung-result')">Status-Cache leeren</button>
    <button type="button" class="lb-btn" onclick="if(confirm('Logdatei leeren?'))act('clear_log', {}, 'wartung-result');setTimeout(loadLog,500)">Log leeren</button>
    <button type="button" class="lb-btn" onclick="if(confirm('Zertifikats-Fingerprints zuruecksetzen?'))act('reset_fingerprint', {}, 'wartung-result')">Fingerprints zuruecksetzen</button>
    <button type="button" class="lb-btn lb-btn-danger" onclick="if(confirm('Alle Einstellungen auf Standardwerte zuruecksetzen? Der OpenAPI-Token bleibt erhalten. Die Seite wird neu geladen.'))act('reset_config', {}, 'wartung-result');setTimeout(function(){location.reload()},1500)">Einstellungen auf Standard</button>
  </div>
  <p id="wartung-result"></p>
</div>
</div>

<div class="s2m-pane" data-tab="logs"<?= $hide('logs') ?>>
<div class="lb-card" id="sec-log"><h3>Logdateien</h3>
  <button type="button" class="lb-btn" onclick="loadLog()">Aktualisieren</button> <span class="hint">aktualisiert sich alle 5 s, solange der Bereich offen ist (letzte 100 Zeilen)</span>
  <pre id="log">-</pre>
</div>
</div>

</div>
</div>

<script>
var CSRF = <?= json_encode($csrf) ?>;
var AJAX = <?= json_encode(rtrim(str_replace(chr(92), '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? ''))), '/') . '/ajax.php') ?>;
function ddCount(el){
  var d = el.closest('details'), boxes = d.querySelectorAll('input[type=checkbox]'), n = 0;
  boxes.forEach(function(b){ if (b.checked) n++; });
  d.querySelector('.cnt').textContent = n + '/' + boxes.length;
}
function ddAll(a, on){
  var d = a.closest('details');
  d.querySelectorAll('input[type=checkbox]').forEach(function(b){ b.checked = on; });
  ddCount(d.querySelector('input[type=checkbox]'));
}
function fmtDur(s){ s = Math.max(0, parseInt(s || 0, 10)); if (s < 90) { return s + ' s'; } var m = Math.floor(s / 60); if (m < 120) { return m + ' min'; } return Math.floor(m / 60) + ' h ' + (m % 60) + ' min'; }
function esc(s){ var d=document.createElement('div'); d.textContent=(s===null||s===undefined)?'':s; return d.innerHTML; }
function post(action, data){
  var body = new URLSearchParams(Object.assign({action: action, csrf: CSRF}, data || {}));
  return fetch(AJAX, {method: 'POST', body: body, credentials: 'same-origin'}).then(function(r){ return r.json(); });
}
function act(action, data, target){
  post(action, data).then(function(j){
    var el = document.getElementById(target || 'act-result');
    var txt = j.message || '';
    if (j.factory) { txt += '\n' + JSON.stringify(j.factory, null, 2); }
    if (j.systems) { txt += '\n' + j.systems.map(function(s){ return s.id + ' (System-ID: ' + s.system_id + ', Seriennr.: ' + s.serial_number + ')'; }).join('\n'); }
    if (j.status && !j.message) { txt = j.status; }
    el.style.display = ''; el.textContent = txt;
    refresh();
  }).catch(function(e){ alert('Fehler: ' + e); });
}
function refresh(){
  fetch(AJAX + '?action=status', {credentials: 'same-origin'}).then(function(r){ return r.json(); }).then(function(j){
    var st = j.status || {};
    document.getElementById('st-service').innerHTML = j.alive ? '<span class="s2m-ok">laeuft</span>' : '<span class="s2m-bad">gestoppt</span>';
    var mq = st.mqtt ? '<span class="s2m-ok">verbunden (' + esc(st.mqtt_target) + ', seit ' + fmtDur(st.mqtt_uptime) + ')</span>' : ('<span class="s2m-bad">getrennt</span>' + (st.mqtt_error ? ' &ndash; ' + esc(st.mqtt_error) : '') + (st.mqtt_source ? ' <span class="hint">[' + esc(st.mqtt_source) + ', ' + esc(st.mqtt_target) + ']</span>' : ''));
    if (st.mqtt_losses) { mq += ' <span class="hint">[' + st.mqtt_losses + ' Abbr&uuml;che seit Dienststart, zuletzt ' + esc(st.mqtt_last_loss) + ']</span>'; }
    document.getElementById('st-mqtt').innerHTML = mq;
    document.getElementById('st-live').textContent = st.live_source || '-';
    document.getElementById('st-error').textContent = st.error || '';
    document.getElementById('st-dup').innerHTML = (st.duplicates && st.duplicates.length)
      ? 'Achtung: Es l&auml;uft noch eine weitere Instanz (Plugin-Ordner: ' + esc(st.duplicates.join(', ')) + '). Beide fragen den Speicher ab und senden dieselben Topics &ndash; bitte ein Plugin in der Plugin-Verwaltung deinstallieren.' : '';
    document.getElementById('fp-lala').textContent = j.fingerprint_lala || '-';
    var rows = '<tr><th>Quelle</th><th>Status</th><th>Letzte Abfrage</th><th>Meldung</th></tr>';
    var src = st.sources || {};
    Object.keys(src).forEach(function(n){
      var s = src[n];
      rows += '<tr><td>' + esc(n) + '</td><td>' + (s.ok ? '<span class="s2m-ok">ok</span>' : '<span class="s2m-bad">' + esc(s.status) + '</span>') + '</td><td>' +
        (s.time ? new Date(s.time*1000).toLocaleTimeString() : '-') + '</td><td>' + esc(s.error) + '</td></tr>';
    });
    document.getElementById('st-sources').innerHTML = rows;
    var lt = '<tr><th>Topic</th><th>Eingangsname</th><th>Wert</th></tr>', pre = <?= json_encode($s2mcfg['mqtt']['topic_prefix']) ?>;
    Object.keys(st.live || {}).forEach(function(k){ lt += '<tr><td><code>' + esc(pre + '/live/' + k) + '</code></td><td>' + esc((pre + '/live/' + k).replace(/\//g, '_')) + '</td><td>' + esc(st.live[k]) + '</td></tr>'; });
    document.getElementById('live-table').innerHTML = lt;
    var oa = (src.openapi && src.openapi.status) || (j.openapi_token ? 'unbekannt' : 'unregistered');
    document.getElementById('oa-status').textContent = oa;
    ['unregistered','pending','rejected','unreachable'].forEach(function(k){ document.getElementById('oa-' + k).style.display = (oa === k) ? '' : 'none'; });
    document.getElementById('oa-forget').style.display = (j.openapi_token && oa !== 'unregistered') ? '' : 'none';
  }).catch(function(e){ document.getElementById('st-service').innerHTML = '<span class="s2m-bad">Statusabfrage fehlgeschlagen (' + esc(String(e)) + ') - URL: ' + esc(AJAX) + '</span>'; });
}
function loadLog(){
  fetch(AJAX + '?action=log', {credentials: 'same-origin'}).then(function(r){ return r.json(); }).then(function(j){
    var pre = document.getElementById('log');
    var atEnd = pre.scrollTop + pre.clientHeight >= pre.scrollHeight - 20;
    pre.textContent = (j.lines || []).join('\n') || '(Log ist leer)';
    if (atEnd) { pre.scrollTop = pre.scrollHeight; }
  }).catch(function(e){ document.getElementById('log').textContent = 'Log konnte nicht geladen werden: ' + e; });
}
var curTab = <?= json_encode($tab) ?>;   // der Reiter wird vom Server gewählt (Menüleiste, ?tab=...)
setInterval(function(){ if (!document.hidden && curTab === 'logs') { loadLog(); } }, 5000);
// Zustand der Hauptbereiche merken (nur Browser-lokal)
(function(){
  var secs = document.querySelectorAll('details.s2m-sec');
  secs.forEach(function(d){
    var k = 's2m-open-' + d.querySelector('summary').textContent.trim().replace(/\s+/g, ' ');
    try { var v = localStorage.getItem(k); if (v !== null) { d.open = (v === '1'); } } catch (e) {}
    d.addEventListener('toggle', function(){ try { localStorage.setItem(k, d.open ? '1' : '0'); } catch (e) {} });
  });
})();
refresh(); if (curTab === 'logs') { loadLog(); }
// Nur aktualisieren, solange der Tab sichtbar ist (spart Rechenzeit auf kleinen LoxBerrys)
setInterval(function(){ if (!document.hidden) { refresh(); } }, 5000);
document.addEventListener('visibilitychange', function(){ if (!document.hidden) { refresh(); } });
</script>
<?php LBWeb::lbfooter(); ?>
