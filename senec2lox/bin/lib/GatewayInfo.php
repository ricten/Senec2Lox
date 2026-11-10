<?php
/**
 * Hilfen rund um den LoxBerry-MQTT-Gateway (Quelle: LoxBerry-Entwicklerwiki und LoxBerry-Quellcode):
 *
 * - Gateway-Version (general.json: Mqtt.Gatewayversion, 1 = Perl-Gateway, 2 = Python-Gateway).
 * - UDP-Eingang fuer Befehle aus Loxone (Standard 11884). Der Port ist einstellbar und steht in
 *   mqtt_connectiondetails()['udpinport'] bzw. general.json (Mqtt.Udpinport): nie fest eintragen.
 * - Kommt das Abo eines Plugins beim Gateway an? Gateway V1 liest die Datei config/mqtt_subscriptions.cfg des Plugins
 *   (eine Zeile je Topic, kein INI-Format). Gateway V2 liest diese Datei nach dem LoxBerry-Quellcode nicht;
 *   dort steht das Abo in config/system/subscriptions.json und muss im Gateway unter "Abonnements" eingetragen werden.
 *
 * Alle Pfade lassen sich fuer Tests uebergeben.
 */
final class GatewayInfo
{
    public const int UDP_STANDARD = 11884;

    /** general.json der LoxBerry-Systemkonfiguration; leer, wenn nicht lesbar. */
    public static function general(?string $file = null): array
    {
        if ($file === null) {
            if (!defined('LBSCONFIGDIR')) {
                return [];
            }
            $file = LBSCONFIGDIR . '/general.json';
        }
        $j = is_file($file) ? json_decode((string)@file_get_contents($file), true) : null;
        return is_array($j) ? $j : [];
    }

    /** 1 (Perl-Gateway) oder 2 (Python-Gateway); 0, wenn die Systemkonfiguration nicht lesbar ist. */
    public static function version(array $general): int
    {
        if (!isset($general['Mqtt'])) {
            return 0;
        }
        $v = (int)($general['Mqtt']['Gatewayversion'] ?? 1);
        return $v === 2 ? 2 : 1;
    }

    /** UDP-Port des Gateways: aus mqtt_connectiondetails() (udpinport), sonst aus general.json, sonst 11884. */
    public static function udpPort(?array $details, array $general): int
    {
        foreach ([$details['udpinport'] ?? null, $general['Mqtt']['Udpinport'] ?? null] as $p) {
            if (is_numeric($p) && (int)$p > 0 && (int)$p < 65536) {
                return (int)$p;
            }
        }
        return self::UDP_STANDARD;
    }

    /**
     * Deckt das Gateway-Abo $sub alle Nachrichten des Filters $filter ab? Beispiele: "#" deckt alles, "nut/#" deckt "nut/#"
     * und "nut/usv/x"; "nut/usv/#" deckt "nut/#" nicht.
     */
    public static function covers(string $sub, string $filter): bool
    {
        $s = explode('/', $sub);
        $f = explode('/', $filter);
        foreach ($s as $i => $part) {
            if ($part === '#') {
                return true;
            }
            if (!isset($f[$i]) || $f[$i] === '#') {
                return false;               // Filter ist kuerzer oder weiter als das Abo
            }
            if ($part !== '+' && $part !== $f[$i]) {
                return false;
            }
        }
        return count($s) === count($f);
    }

    /** Beruehrt das Abo den Filter wenigstens teilweise (Abo ist enger als der Filter)? */
    private static function narrower(string $sub, string $filter): bool
    {
        $root = rtrim(preg_replace('~/?[#+].*$~', '', $filter) ?? '', '/');
        return $root !== '' && $sub !== $filter && str_starts_with($sub, $root . '/');
    }

    /**
     * Prueft, ob das Abo $filter (z. B. "nut/#") beim Gateway ankommt.
     *
     * Ampel: "ok" (gruen), sobald mindestens ein passendes Abo eingetragen ist - auch wenn nur ein Teil des Filters
     * abonniert ist (dann steht "teilweise" im Text und "teilweise" => true). "fehler" (rot) nur, wenn gar kein passendes
     * Abo eingetragen ist. "hinweis", wenn sich das nicht feststellen laesst.
     *
     * @return array{state:string,text:string,topics:list<string>,teilweise:bool}
     *   topics: die eingetragenen Abos, die zum Filter passen (zum Auflisten in der Oberflaeche)
     */
    public static function subscription(string $filter, array $general, ?string $subsFile = null, ?string $pluginFile = null): array
    {
        $v = self::version($general);
        if ($v === 0) {
            return self::antwort('hinweis', 'Die Gateway-Einstellungen sind nicht lesbar; bitte im MQTT Gateway prüfen, ob das Abo „' . $filter . '“ eingetragen ist.');
        }
        if ($v === 2) {
            $subsFile ??= defined('LBSCONFIGDIR') ? LBSCONFIGDIR . '/subscriptions.json' : '';
            $j = $subsFile !== '' && is_file($subsFile) ? json_decode((string)@file_get_contents($subsFile), true) : null;
            if (!is_array($j)) {
                return self::antwort('hinweis', 'Gateway V2: Die Abo-Liste ist nicht lesbar. Bitte im MQTT Gateway unter „Abonnements“ prüfen, ob „' . $filter . '“ eingetragen ist.');
            }
            $ids = [];
            foreach ((array)($j['Subscriptions'] ?? []) as $s) {
                if (is_array($s) && isset($s['Id'])) {
                    $ids[] = (string)$s['Id'];
                }
            }
            return self::bewerten('Gateway V2', $filter, $ids,
                'Das Abo „' . $filter . '“ fehlt. Bitte im MQTT Gateway unter „Abonnements“ eintragen: Das Gateway V2 liest die Abo-Datei des Plugins nicht.');
        }
        $pluginFile ??= defined('LBPCONFIGDIR') ? LBPCONFIGDIR . '/mqtt_subscriptions.cfg' : '';
        $lines = $pluginFile !== '' && is_file($pluginFile) ? preg_split('/\r?\n/', (string)@file_get_contents($pluginFile)) : [];
        $lines = array_values(array_filter(array_map('trim', $lines ?: []), fn($l) => $l !== ''));
        return self::bewerten('Gateway V1', $filter, $lines,
            'Das Abo „' . $filter . '“ steht nicht in der Abo-Datei des Plugins (mqtt_subscriptions.cfg). Plugin neu installieren oder das Abo im Gateway eintragen.');
    }

    /**
     * Farbig hinterlegtes Feld fuer die Oberflaeche (Reiter MQTT): gruen, sobald mindestens ein passendes Abo eingetragen ist,
     * rot nur, wenn keines eingetragen ist, grau bei "hinweis". Darunter die eingetragenen Abos als Liste.
     * Nur Inline-Stile, damit es in jeder Oberflaeche gleich aussieht.
     */
    public static function feldHtml(array $gw, string $filter): string
    {
        $farben = ['ok' => ['#e6f4e6', '#2a8a2a'], 'fehler' => ['#fbe9e7', '#c0392b'], 'hinweis' => ['#f2f2f2', '#777777']];
        $state = isset($farben[$gw['state'] ?? '']) ? $gw['state'] : 'hinweis';
        [$grund, $rand] = $farben[$state];
        $h = fn($s): string => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
        $o = '<div class="gw-abo gw-abo-' . $state . '" style="background:' . $grund . ';border-left:4px solid ' . $rand
            . ';padding:6px 10px;border-radius:3px"><code>' . $h($filter) . '</code> <b style="color:' . $rand . '">' . $h($gw['text'] ?? '') . '</b>';
        $topics = array_values((array)($gw['topics'] ?? []));
        if ($topics) {
            $o .= '<ul style="margin:4px 0 0 18px;padding:0">';
            foreach ($topics as $t) {
                $o .= '<li><code>' . $h($t) . '</code></li>';
            }
            $o .= '</ul>';
        }
        return $o . '</div>';
    }

    /** Vergleicht die eingetragenen Abos mit dem Filter des Plugins. */
    private static function bewerten(string $quelle, string $filter, array $eintraege, string $fehltText): array
    {
        $voll = array_values(array_filter($eintraege, fn($e) => self::covers($e, $filter)));
        if ($voll) {
            return self::antwort('ok', $quelle . ': eingetragen (' . implode(', ', $voll) . ')', $voll);
        }
        $teil = array_values(array_filter($eintraege, fn($e) => self::narrower($e, $filter)));
        if ($teil) {
            return self::antwort('ok', $quelle . ': teilweise abonniert (' . implode(', ', $teil) . '). Nicht alle Topics von „' . $filter
                . '“ kommen im Miniserver an; für alle Werte „' . $filter . '“ eintragen.', $teil, true);
        }
        return self::antwort('fehler', $quelle . ': ' . $fehltText);
    }

    private static function antwort(string $state, string $text, array $topics = [], bool $teilweise = false): array
    {
        return ['state' => $state, 'text' => $text, 'topics' => $topics, 'teilweise' => $teilweise];
    }
}
