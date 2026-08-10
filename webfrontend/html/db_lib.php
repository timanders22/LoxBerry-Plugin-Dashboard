<?php
/**
 * Dashboard-Designer - gemeinsame Bibliothek
 *
 * Liegt unter webfrontend/html/, weil der Endpunkt und die Anzeigeseite sie
 * ebenso brauchen wie die Oberflaeche. So gibt es EINE Datei statt dreier
 * Kopien, die auseinanderlaufen.
 *
 * Diese Bibliothek spricht NIE selbst mit dem Miniserver. Das tut allein
 * bin/dashboard_dienst.py. Hier wird der Zwischenspeicher gelesen und werden
 * Befehle in einer Warteschlange abgelegt.
 *
 * Praefix 'db_', weil LBWeb::lbheader() SDK-Globale setzt.
 * Kompatibel mit PHP 7.4 und PHP 8.x (LoxBerry 3.x/4.x).
 */

if (!function_exists('db_e')) {
    function db_e($s)
    {
        return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    }
}


/* Den LoxBerry-Wurzelordner ohne festen Systempfad bestimmen.
 *
 * Vom eigenen Ablageort aufwaerts, bis ein Verzeichnis gefunden ist, das
 * config/plugins UND webfrontend enthaelt. Das trifft die uebliche
 * Installation genauso wie eine an einem anderen Ort - und es trifft auch
 * den Fall, dass das Plugin noch als entpacktes Archiv daliegt (dann findet
 * es nichts und gibt einen Leerstring zurueck, was der Aufrufer ohnehin
 * abfangen muss).
 *
 * Der Name traegt kein Plugin-Kuerzel und ist deshalb abgesichert: zwei
 * Bibliotheken landen nie im selben Prozess, aber die Pruefung kostet nichts.
 */
if (!function_exists('lb_wurzel_ermitteln')) {
    function lb_wurzel_ermitteln()
    {
        $d = __DIR__;
        for ($i = 0; $i < 8; $i++) {
            if (is_dir($d . '/config/plugins') && is_dir($d . '/webfrontend')) {
                return $d;
            }
            $eltern = dirname($d);
            if ($eltern === $d) { break; }
            $d = $eltern;
        }
        return '';
    }
}

function db_paths()
{
    static $p = null;
    if ($p !== null) { return $p; }
    $home = getenv('LBHOMEDIR');
    if (!$home || !is_dir($home)) {
        foreach (array(lb_wurzel_ermitteln(), '/home/loxberry/loxberry') as $k) {
            if (is_dir($k)) { $home = $k; break; }
        }
    }
    // Der Pluginordner ergibt sich aus dem Ablageort DIESER Datei. Der
    // MD5-Schluessel aus der plugindatabase.json wird bewusst NICHT benutzt -
    // er wird aus Autorenname, E-Mail und Plugin-Name gebildet und aendert
    // sich bei jedem Fork.
    $dir = basename(dirname(__FILE__));
    if ($home && !is_dir($home . '/config/plugins/' . $dir)) {
        foreach (array(getenv('LBPPLUGINDIR'), 'dashboard') as $kand) {
            if ($kand && is_dir($home . '/config/plugins/' . $kand)) { $dir = $kand; break; }
        }
    }
    if ($home) {
        $p = array(
            'home'      => $home,
            'plugin'    => $dir,
            'configdir' => $home . '/config/plugins/' . $dir,
            'config'    => $home . '/config/plugins/' . $dir . '/dashboard.json',
            'geheim'    => $home . '/config/plugins/' . $dir . '/zugang.json',
            'seiten'    => $home . '/config/plugins/' . $dir . '/seiten.json',
            'sicherung' => $home . '/config/plugins/' . $dir . '.backup.json',
            'datadir'   => $home . '/data/plugins/' . $dir,
            'bindir'    => $home . '/bin/plugins/' . $dir,
            'logdir'    => $home . '/log/plugins/' . $dir,
            'log'       => $home . '/log/plugins/' . $dir . '/dashboard.log',
            'kacheln'   => $home . '/templates/plugins/' . $dir . '/kacheln.json',
        );
    } else {
        $basis = dirname(dirname(__DIR__));
        $p = array(
            'home' => '', 'plugin' => $dir,
            'configdir' => $basis . '/config',
            'config'    => $basis . '/config/dashboard.json',
            'geheim'    => $basis . '/config/zugang.json',
            'seiten'    => $basis . '/config/seiten.json',
            'sicherung' => $basis . '/config/dashboard.backup.json',
            'datadir'   => $basis . '/data',
            'bindir'    => $basis . '/bin',
            'logdir'    => $basis . '/log',
            'log'       => $basis . '/log/dashboard.log',
            'kacheln'   => $basis . '/templates/kacheln.json',
        );
    }
    return $p;
}

/** Voreinstellungen. Muessen zu VORGABEN in bin/dashboard_dienst.py passen. */
function db_vorgaben()
{
    return array(
        'miniserver'     => '1',
        'tls'            => 0,
        'takt'           => 2,
        'http_rueckfall' => 1,
        'http_takt'      => 10,
        'steuerung_ein'  => 1,
        'aktionstoken'   => '',
        'wartezeit'      => 8,
        'vollbild'       => 1,
        'wach'           => 1,
        // Kurzes Ruetteln beim Antippen. Nur Android kann das; wo nicht,
        // passiert nichts.
        'haptik'         => 1,
        'farbe'          => 'dunkel',
    );
}

function db_json_lesen($pfad)
{
    if (!is_file($pfad)) { return array(); }
    $d = json_decode((string) @file_get_contents($pfad), true);
    return is_array($d) ? $d : array();
}

/** Erst in eine Nebendatei, dann umbenennen. */
function db_json_schreiben($pfad, $daten, $rechte = null)
{
    $ordner = dirname($pfad);
    if (!is_dir($ordner) && !@mkdir($ordner, 0775, true) && !is_dir($ordner)) { return false; }
    $tmp = $pfad . '.tmp';
    $json = json_encode($daten, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false || @file_put_contents($tmp, $json) === false) { @unlink($tmp); return false; }
    if ($rechte !== null) { @chmod($tmp, $rechte); }
    return @rename($tmp, $pfad);
}

function db_config()
{
    $p = db_paths();
    $roh = is_file($p['config']) ? trim((string) @file_get_contents($p['config'])) : '';
    if (($roh === '' || $roh === '{}') && is_file($p['sicherung'])) {
        @mkdir($p['configdir'], 0775, true);
        @copy($p['sicherung'], $p['config']);
    }
    return array_merge(db_vorgaben(), db_json_lesen($p['config']));
}

function db_config_speichern($cfg)
{
    $p = db_paths();
    if (!db_json_schreiben($p['config'], $cfg, 0644)) { return false; }
    @copy($p['config'], $p['sicherung']);
    return true;
}

/* ---------------- Zugangsdaten ----------------
 *
 * Zugangsdaten stehen NIE in der Konfiguration, die die Oberflaeche anzeigt,
 * sondern in einer eigenen Datei mit Rechten 0600. Angezeigt wird nie ihr
 * Wert - nur, ob einer da ist.
 */

function db_zugang()
{
    return db_json_lesen(db_paths()['geheim']);
}

function db_zugang_speichern($adresse, $port, $benutzer, $passwort)
{
    $p = db_paths();
    $alt = db_zugang();
    if ($adresse === '') {
        // Leere Adresse heisst: wieder die LoxBerry-Zugangsdaten benutzen.
        unset($alt['adresse'], $alt['port'], $alt['benutzer'], $alt['passwort']);
    } else {
        $alt['adresse']  = $adresse;
        $alt['port']     = (int) $port;
        $alt['benutzer'] = $benutzer;
        // Leeres Kennwort heisst: das bisherige behalten.
        if ($passwort !== '') { $alt['passwort'] = $passwort; }
    }
    return db_json_schreiben($p['geheim'], $alt, 0600);
}

/** Die Miniserver-Daten so, wie der Dienst sie sieht - ohne das Kennwort. */
function db_miniserver()
{
    $z = db_zugang();
    if (!empty($z['adresse']) && !empty($z['benutzer'])) {
        return array('quelle' => 'eigen', 'name' => 'eigener Zugang',
                     'adresse' => (string) $z['adresse'], 'port' => (int) $z['port'],
                     'benutzer' => (string) $z['benutzer'],
                     'passwort_da' => !empty($z['passwort']) ? 1 : 0);
    }
    $cfg = db_config();
    $g = db_json_lesen(db_paths()['home'] . '/config/system/general.json');
    $alle = isset($g['Miniserver']) && is_array($g['Miniserver']) ? $g['Miniserver'] : array();
    $nr = (string) $cfg['miniserver'];
    $ms = isset($alle[$nr]) ? $alle[$nr] : (count($alle) ? reset($alle) : null);
    if (!is_array($ms)) { return array(); }
    $ip = isset($ms['Ipaddress']) ? $ms['Ipaddress'] : (isset($ms['IPAddress']) ? $ms['IPAddress'] : '');
    if ($ip === '') { return array(); }
    return array('quelle' => 'loxberry',
                 'name' => isset($ms['Name']) ? $ms['Name'] : ('Miniserver ' . $nr),
                 'adresse' => (string) $ip,
                 'port' => (int) (isset($ms['Port']) ? $ms['Port'] : 80),
                 'benutzer' => db_vielleicht_base64((string) (isset($ms['Admin']) ? $ms['Admin'] : '')),
                 'passwort_da' => !empty($ms['Pass']) ? 1 : 0);
}

/** Siehe die gleichnamige Funktion im Dienst - dieselbe Pruefung. */
function db_vielleicht_base64($s)
{
    if ($s === '' || strlen($s) % 4 !== 0) { return $s; }
    $roh = base64_decode($s, true);
    if ($roh === false || $roh === '') { return $s; }
    if (preg_match('/[\x00-\x1F]/', $roh)) { return $s; }
    if (base64_encode($roh) !== $s) { return $s; }
    return $roh;
}

/** Liste aller Miniserver aus der LoxBerry-Konfiguration. */
function db_miniserver_liste()
{
    $g = db_json_lesen(db_paths()['home'] . '/config/system/general.json');
    $alle = isset($g['Miniserver']) && is_array($g['Miniserver']) ? $g['Miniserver'] : array();
    $out = array();
    foreach ($alle as $nr => $ms) {
        if (!is_array($ms)) { continue; }
        $ip = isset($ms['Ipaddress']) ? $ms['Ipaddress'] : (isset($ms['IPAddress']) ? $ms['IPAddress'] : '');
        $out[(string) $nr] = (isset($ms['Name']) ? $ms['Name'] : ('Miniserver ' . $nr))
                           . ' (' . $ip . ')';
    }
    return $out;
}

/* ---------------- Kacheltabelle, Struktur, Dashboard ---------------- */

function db_kacheltabelle()
{
    static $t = null;
    if ($t !== null) { return $t; }
    $p = db_paths();
    foreach (array($p['kacheln'], dirname(dirname(__DIR__)) . '/templates/kacheln.json') as $k) {
        $d = db_json_lesen($k);
        if (!empty($d['typen'])) { $t = $d; return $t; }
    }
    $t = array('typen' => array(), 'generisch' => array('kachel' => 'generisch'),
               'groessen' => array(), 'vorgabegroesse' => array());
    return $t;
}

function db_struktur()  { return db_json_lesen(db_paths()['datadir'] . '/struktur.json'); }
function db_abbild()    { return db_json_lesen(db_paths()['datadir'] . '/abbild.json'); }
function db_zustand()   { return db_json_lesen(db_paths()['datadir'] . '/zustand.json'); }

function db_bausteine()
{
    $s = db_struktur();
    return isset($s['bausteine']) && is_array($s['bausteine']) ? $s['bausteine'] : array();
}

/** Einen Baustein anhand seiner UUID finden. */
function db_baustein($uuid)
{
    foreach (db_bausteine() as $b) {
        if (isset($b['uuid']) && $b['uuid'] === $uuid) { return $b; }
    }
    return null;
}

function db_seiten()
{
    $d = db_json_lesen(db_paths()['seiten']);
    return isset($d['seiten']) && is_array($d['seiten']) ? $d['seiten'] : array();
}

function db_seiten_speichern($seiten)
{
    $d = db_json_lesen(db_paths()['seiten']);
    $d['seiten'] = array_values($seiten);
    $d['geaendert'] = time();
    return db_json_schreiben(db_paths()['seiten'], $d);
}

function db_seite($schluessel)
{
    foreach (db_seiten() as $s) {
        if (isset($s['schluessel']) && $s['schluessel'] === $schluessel) { return $s; }
    }
    return null;
}

/** Alter des Abbilds in Sekunden, oder -1 wenn es keines gibt. */
function db_alter()
{
    $a = db_abbild();
    return isset($a['ts']) ? max(0, time() - (int) $a['ts']) : -1;
}

/* ---------------- Token ---------------- */

function db_token_erzeugen($laenge = 24)
{
    $zeichen = 'abcdefghijkmnpqrstuvwxyz23456789';
    $t = '';
    for ($i = 0; $i < $laenge; $i++) { $t .= $zeichen[random_int(0, strlen($zeichen) - 1)]; }
    return $t;
}

function db_token()
{
    $cfg = db_config();
    if (trim((string) $cfg['aktionstoken']) === '') {
        $cfg['aktionstoken'] = db_token_erzeugen();
        db_config_speichern($cfg);
    }
    return (string) $cfg['aktionstoken'];
}

/* ---------------- Pruefung eines Befehls ----------------
 *
 * Dieselbe Regel wie im Dienst, nur frueher: erlaubt ist ausschliesslich, was
 * die Kacheltabelle fuer genau diesen Bausteintyp nennt. Der Dienst prueft es
 * ein zweites Mal - eine zweite Pruefung an der Stelle, wo es wirklich
 * hinausgeht, kostet nichts.
 */

function db_befehl_erlaubt($uuid, $befehl)
{
    $b = db_baustein($uuid);
    if ($b === null) {
        return array(false, 'Diesen Baustein gibt es in der Struktur nicht.');
    }
    if (!empty($b['nurlesen'])) {
        return array(false, 'Dieser Baustein ist in Loxone auf "nur lesen" gesetzt.');
    }
    $erlaubt = isset($b['befehle']) && is_array($b['befehle']) ? $b['befehle'] : array();
    if (!$erlaubt) {
        return array(false, 'Fuer den Typ ' . (string) $b['loxtyp']
                          . ' kennt das Plugin keinen Befehl. Geraten wird hier nicht.');
    }
    foreach ($erlaubt as $e) {
        if ($e === $befehl) { return array(true, ''); }
        if (substr($e, 0, 1) === '$' && is_numeric($befehl)) { return array(true, ''); }
        if (substr($e, -6) === '/$wert') {
            $kopf = substr($e, 0, -5);
            if (strpos($befehl, $kopf) === 0 && is_numeric(substr($befehl, strlen($kopf)))) {
                return array(true, '');
            }
        }
    }
    return array(false, 'Der Befehl "' . db_e($befehl) . '" ist fuer den Typ '
                      . db_e((string) $b['loxtyp']) . ' nicht vorgesehen.');
}


function db_log($text)
{
    $p = db_paths();
    if (!is_dir($p['logdir'])) {
        @mkdir($p['logdir'], 0775, true);
    }
    if (is_file($p['log']) && filesize($p['log']) > 512000) {
        // Rotation: die letzten 400 Zeilen behalten
        $rest = array_slice(file($p['log'], FILE_IGNORE_NEW_LINES) ?: array(), -400);
        @file_put_contents($p['log'], implode("\n", $rest) . "\n");
    }
    @file_put_contents($p['log'], '[' . date('Y-m-d H:i:s') . '] ' . $text . "\n", FILE_APPEND);
}

/** Dieselbe Meldung hoechstens einmal je Zeitfenster - sonst wird die
 *  Logdatei durch eine Dauerstoerung unlesbar. */
function db_log_gebremst($schluessel, $text, $sekunden = 3600)
{
    $f = db_paths()['datadir'] . '/.meld_' . preg_replace('/[^a-z0-9_]/i', '', $schluessel);
    $letzte = is_file($f) ? (int) @file_get_contents($f) : 0;
    if (time() - $letzte >= $sekunden) {
        @file_put_contents($f, (string) time());
        db_log($text);
    }
}

/* ---------------- Dienst ---------------- */


function db_dienst_pid()
{
    $f = db_paths()['datadir'] . '/dienst.pid';
    if (!is_file($f)) {
        return 0;
    }
    $pid = (int) trim((string) @file_get_contents($f));
    if ($pid <= 0 || !is_dir('/proc/' . $pid)) {
        return 0;
    }
    $cmd = (string) @file_get_contents('/proc/' . $pid . '/cmdline');
    return strpos($cmd, 'dashboard_dienst.py') !== false ? $pid : 0;
}

function db_dienst_soll()
{
    return is_file(db_paths()['datadir'] . '/soll_laufen') ? 1 : 0;
}

/** $befehl ist 'start', 'stop' oder 'restart'. Rueckgabe: array(ok, Ausgabe) */
function db_dienst($befehl)
{
    if (!in_array($befehl, array('start', 'stop', 'restart'), true)) {
        return array(0, 'Unbekannter Befehl.');
    }
    $skript = db_paths()['bindir'] . '/dienst.sh';
    if (!is_file($skript)) {
        return array(0, 'dienst.sh nicht gefunden: ' . $skript);
    }
    $ausgabe = array();
    $code = 0;
    @exec(escapeshellcmd($skript) . ' ' . escapeshellarg($befehl) . ' 2>&1', $ausgabe, $code);
    return array($code === 0 ? 1 : 0, implode("\n", $ausgabe));
}

/* ---------------- Befehlswarteschlange ----------------
 *
 * Sowohl der Miniserver-Endpunkt als auch der Reiter Test setzen Befehle ueber
 * diese eine Funktion ab. Zwei Kopien derselben Logik laufen zwangslaeufig
 * auseinander.
 *
 * Rueckgabe: array(ok, Meldung). ok = 1 erledigt, 0 abgelehnt,
 * 2 eingereiht, aber ohne Antwort in der Wartezeit - Ergebnis unbekannt.
 * Es wird nie ein Erfolg gemeldet, den niemand geprueft hat.
 */

function db_befehl_absetzen($befehl, $wartezeit = null)
{
    $p = db_paths();
    $cfg = db_config();
    if ($wartezeit === null) {
        $wartezeit = (int) $cfg['wartezeit'];
    }
    $wartezeit = max(0, min(20, (int) $wartezeit));

    $ordner = $p['datadir'] . '/befehle';
    if (!is_dir($ordner) && !@mkdir($ordner, 0775, true) && !is_dir($ordner)) {
        return array(0, 'Der Ordner fuer die Warteschlange liess sich nicht anlegen: ' . $ordner);
    }
    $kennung = bin2hex(random_bytes(8));
    $datei = $ordner . '/' . $kennung . '.json';
    $tmp = $datei . '.tmp';
    if (@file_put_contents($tmp, json_encode($befehl)) === false || !@rename($tmp, $datei)) {
        @unlink($tmp);
        return array(0, 'Der Befehl liess sich nicht ablegen: ' . $datei);
    }
    $antwort = $p['datadir'] . '/antworten/' . $kennung . '.json';
    for ($i = 0; $i < $wartezeit * 10; $i++) {
        if (is_file($antwort)) {
            $a = db_json_lesen($antwort);
            // Gleich wegraeumen. Der Dienst kehrt sie zwar nach 120 s selbst
            // aus, aber solange sie liegt, belegt sie einen Namen, und bei
            // einem Wandtablet mit Dauerbetrieb sind das viele Namen.
            @unlink($antwort);
            return array((int) (isset($a['ok']) ? $a['ok'] : 0),
                         (string) (isset($a['meldung']) ? $a['meldung'] : ''));
        }
        usleep(100000);
    }
    return array(2, 'Eingereiht, aber der Dienst hat innerhalb von ' . $wartezeit . ' s nicht geantwortet.');
}

/* Es gibt bewusst KEINE MQTT-Funktionen mehr.
 *
 * Bis 0.9.0 standen hier db_mqtt_zustand() und db_mqtt_senden() - aufgerufen
 * wurden sie von nirgends. Das passt auch nicht zum Entwurf: dieses Plugin
 * haelt die Werte im Zwischenspeicher des Dienstes und liefert sie der
 * Anzeigeseite als JSON. Ein Umweg ueber einen Broker braeuchte es nicht,
 * und in der Oberflaeche steht ausdruecklich, dass es ihn nicht gibt.
 *
 * Toter Code, der einen ganzen Uebertragungsweg andeutet, ist schlimmer als
 * gar keiner: der naechste Leser haelt ihn fuer benutzt.
 */


/* ==================================================================
 * Loxone-Vorlagen
 *
 * Nachbau der Bausteine aus LoxBerry::LoxoneTemplateBuilder; das Modul gibt es
 * nur in Perl. Attributreihenfolge, CRLF als Zeilenende und der Tabulator vor
 * den Kindelementen entsprechen dem Original. Wortgleich uebernommen aus
 * LoxBerry-Plugin-APC-UPS-1.0.0 (ap_xml_virtual_in_http) - nicht neu
 * geschrieben, weil die Fassung dort geprueft ist.
 * ================================================================== */


function db_x($s)
{
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_XML1, 'UTF-8');
}

function db_xml_virtual_in_http($kopf, $cmds)
{
    $crlf = "\r\n";
    $o = '<?xml version="1.0" encoding="utf-8"?>' . $crlf;
    $o .= '<VirtualInHttp ';
    $o .= 'Title="' . db_x($kopf['title']) . '" ';
    $o .= 'Comment="' . db_x(isset($kopf['comment']) ? $kopf['comment'] : '') . '" ';
    $o .= 'Address="' . db_x(isset($kopf['address']) ? $kopf['address'] : '') . '" ';
    $o .= 'PollingTime="' . db_x(isset($kopf['polling']) ? $kopf['polling'] : '60') . '"';
    $o .= '>' . $crlf;
    foreach ($cmds as $c) {
        $o .= "\t" . '<VirtualInHttpCmd ';
        $o .= 'Title="' . db_x($c['title']) . '" ';
        $o .= 'Comment="' . db_x(isset($c['comment']) ? $c['comment'] : '') . '" ';
        $o .= 'Check="' . db_x(isset($c['check']) ? $c['check'] : ' ') . '" ';
        $o .= 'Signed="true" ';
        $o .= 'Analog="true" ';
        $o .= 'SourceValLow="0" ';
        $o .= 'DestValLow="0" ';
        $o .= 'SourceValHigh="100" ';
        $o .= 'DestValHigh="100" ';
        $o .= 'DefVal="0" ';
        $o .= 'MinVal="-2147483647" ';
        $o .= 'MaxVal="2147483647"';
        $o .= '/>' . $crlf;
    }
    $o .= '</VirtualInHttp>' . $crlf;
    return $o;
}

/* ==================================================================
 * Sprache (Pflicht: Deutsch und Englisch)
 *
 * Englisch ist die Rueckfallebene, nicht Deutsch: wer eine dritte Sprache
 * eingestellt hat, versteht eher Englisch. Deshalb muss language_en.ini immer
 * vollstaendig sein.
 *
 * Die Funktion setzt kein db_paths() voraus, damit derselbe Block in jedes
 * Plugin passt. Der Pfad wird zweistufig gesucht:
 *   installiert: <home>/templates/plugins/<ordner>/lang
 *   Archiv:      <pluginwurzel>/templates/lang
 * ================================================================== */


function db_sprache()
{
    $sprache = 'de';
    if (class_exists('LBSystem', false) && method_exists('LBSystem', 'lblanguage')) {
        $sprache = LBSystem::lblanguage();
    } elseif (getenv('LBLANG')) {
        $sprache = getenv('LBLANG');
    }
    $sprache = strtolower(substr((string) $sprache, 0, 2));
    return in_array($sprache, array('de', 'en'), true) ? $sprache : 'en';
}

function db_t($schluessel)
{
    static $texte = null;
    if ($texte === null) {
        $home = getenv('LBHOMEDIR');
        if (!$home || !is_dir($home)) {
            foreach (array(lb_wurzel_ermitteln(), '/home/loxberry/loxberry') as $k) {
                if (is_dir($k)) {
                    $home = $k;
                    break;
                }
            }
        }
        $ordner = basename(dirname(__FILE__));
        $pfad = $home . '/templates/plugins/' . $ordner . '/lang';
        if (!is_dir($pfad)) {
            $pfad = dirname(dirname(dirname(__FILE__))) . '/templates/lang';
        }
        $texte = @parse_ini_file($pfad . '/language_' . db_sprache() . '.ini', true, INI_SCANNER_RAW);
        if (!is_array($texte)) {
            $texte = array();
        }
        $rueck = @parse_ini_file($pfad . '/language_en.ini', true, INI_SCANNER_RAW);
        if (is_array($rueck)) {
            $texte = array_replace_recursive($rueck, $texte);
        }
        // INI_SCANNER_RAW liefert die Werte samt der Anfuehrungszeichen
        // zurueck, in die sie in der Datei stehen muessen. Die gehoeren nicht
        // in die Ausgabe.
        foreach ($texte as $ab => $paare) {
            if (!is_array($paare)) {
                continue;
            }
            foreach ($paare as $s => $w) {
                $texte[$ab][$s] = trim((string) $w, '"');
            }
        }
    }
    $teile = array_pad(explode('.', $schluessel, 2), 2, '');
    return isset($texte[$teile[0]][$teile[1]]) ? $texte[$teile[0]][$teile[1]] : $schluessel;
}

/* ==================================================================
 * Dashboard-eigene Teile
 * ================================================================== */

/** Die Adresse, die auf das Wandtablet gehoert. */
function db_tafel_adresse($seite = '')
{
    $p = db_paths();
    $host = isset($_SERVER['HTTP_HOST']) && $_SERVER['HTTP_HOST'] !== ''
        ? preg_replace('/[^A-Za-z0-9\.\-:]/', '', (string) $_SERVER['HTTP_HOST'])
        : (gethostname() ?: 'loxberry');
    return 'http://' . $host . '/plugins/' . $p['plugin'] . '/tafel.php'
         . '?token=' . db_token() . ($seite !== '' ? '&seite=' . rawurlencode($seite) : '');
}

/** Kacheltypen, die es gibt - fuer die Auswahlliste im Designer. */
function db_kacheltypen()
{
    $t = db_kacheltabelle();
    $aus = array();
    foreach ((isset($t['typen']) ? $t['typen'] : array()) as $lox => $z) {
        $k = isset($z['kachel']) ? $z['kachel'] : 'generisch';
        $aus[$k] = isset($z['text']) ? $z['text'] : $k;
    }
    $aus['generisch'] = isset($t['generisch']['text']) ? $t['generisch']['text'] : 'generisch';
    ksort($aus);
    return $aus;
}

function db_groessen()
{
    $t = db_kacheltabelle();
    return isset($t['groessen']) && is_array($t['groessen']) ? $t['groessen'] : array();
}

/** Struktur und Werte fuer EINE Seite - genau das, was die Anzeige braucht. */
function db_seite_daten($schluessel)
{
    $seite = db_seite($schluessel);
    if ($seite === null) { return null; }
    $abbild = db_abbild();
    $werte = isset($abbild['werte']) && is_array($abbild['werte']) ? $abbild['werte'] : array();
    $kacheln = array();
    foreach ((isset($seite['kacheln']) ? $seite['kacheln'] : array()) as $k) {
        if (isset($k['sichtbar']) && !$k['sichtbar']) { continue; }
        $uuid = (string) (isset($k['uuid']) ? $k['uuid'] : '');
        $b = db_baustein($uuid);
        if ($b === null) {
            // Der Baustein steht nicht mehr in der Struktur. Er wird NICHT
            // stillschweigend ausgelassen - sonst sucht jemand vergeblich.
            $kacheln[] = array('uuid' => $uuid, 'titel' => (string) $k['titel'],
                               'kachel' => 'fehlt', 'groesse' => (string) $k['groesse'],
                               'werte' => array(), 'befehle' => array());
            continue;
        }
        $kacheln[] = array(
            'uuid'     => $uuid,
            'titel'    => (string) (isset($k['titel']) && $k['titel'] !== '' ? $k['titel'] : $b['name']),
            'kachel'   => (string) (isset($k['kachel']) ? $k['kachel'] : $b['kachel']),
            'groesse'  => (string) (isset($k['groesse']) ? $k['groesse'] : '1x1'),
            'loxtyp'   => (string) $b['loxtyp'],
            'einheit'  => (string) $b['format'],
            'befehle'  => isset($b['befehle']) ? $b['befehle'] : array(),
            'nurlesen' => (int) (isset($b['nurlesen']) ? $b['nurlesen'] : 0),
            'gesichert' => (int) (isset($b['gesichert']) ? $b['gesichert'] : 0),
            'werte'    => isset($werte[$uuid]) ? $werte[$uuid] : array(),
        );
    }
    return array(
        'schluessel' => $schluessel,
        'name'       => (string) $seite['name'],
        // Nur die Tatsache, dass eine PIN gesetzt ist - nie ihr Wert.
        'pin'        => !empty($seite['pin']) ? 1 : 0,
        'spalten'    => (int) (isset($seite['spalten']) ? $seite['spalten'] : 6),
        'kacheln'    => $kacheln,
        'ok'         => (int) (isset($abbild['ok']) ? $abbild['ok'] : 0),
        'weg'        => (string) (isset($abbild['weg']) ? $abbild['weg'] : ''),
        'alter'      => db_alter(),
    );
}

/** Nur die Werte - das, was der Takt der Anzeigeseite holt. */
function db_seite_werte($schluessel)
{
    $seite = db_seite($schluessel);
    if ($seite === null) { return null; }
    $abbild = db_abbild();
    $alle = isset($abbild['werte']) && is_array($abbild['werte']) ? $abbild['werte'] : array();
    $aus = array();
    foreach ((isset($seite['kacheln']) ? $seite['kacheln'] : array()) as $k) {
        $u = (string) (isset($k['uuid']) ? $k['uuid'] : '');
        if (isset($alle[$u])) { $aus[$u] = $alle[$u]; }
    }
    return array('ok' => (int) (isset($abbild['ok']) ? $abbild['ok'] : 0),
                 'weg' => (string) (isset($abbild['weg']) ? $abbild['weg'] : ''),
                 'alter' => db_alter(), 'werte' => $aus);
}

/** PIN einer Seite pruefen - in gleichbleibender Zeit. */
function db_pin_stimmt($seite, $eingabe)
{
    $s = db_seite($seite);
    if ($s === null) { return false; }
    $soll = (string) (isset($s['pin']) ? $s['pin'] : '');
    if ($soll === '') { return true; }
    return hash_equals($soll, (string) $eingabe);
}

/** Felder, die der Endpunkt als Zustandszeile liefert. */
function db_status_felder()
{
    return array(
        'OK'         => array('',  'DB_FELD.OK'),
        'BAUSTEINE'  => array('',  'DB_FELD.BAUSTEINE'),
        'SEITEN'     => array('',  'DB_FELD.SEITEN'),
        'KACHELN'    => array('',  'DB_FELD.KACHELN'),
        'ALTER'      => array('s', 'DB_FELD.ALTER'),
    );
}

function db_selbsttest_ausgabe()
{
    $p = db_paths();
    $skript = $p['bindir'] . '/dienst.sh';
    if (!is_file($skript)) { return 'dienst.sh nicht gefunden: ' . $skript; }
    $a = array(); $c = 0;
    @exec(escapeshellcmd($skript) . ' selbsttest 2>&1', $a, $c);
    return implode("\n", $a);
}

function db_vorlage()
{
    $p = db_paths();
    $host = isset($_SERVER['HTTP_HOST']) && $_SERVER['HTTP_HOST'] !== ''
        ? preg_replace('/[^A-Za-z0-9\.\-:]/', '', (string) $_SERVER['HTTP_HOST'])
        : (gethostname() ?: 'loxberry');
    $token = db_token();
    $cmds = array();
    foreach (db_status_felder() as $feld => $info) {
        $cmds[] = array(
            'title'   => 'DASHBOARD_' . $feld,
            'comment' => trim(strip_tags(html_entity_decode(db_t($info[1]), ENT_QUOTES, 'UTF-8')))
                       . ($info[0] !== '' ? ' [' . $info[0] . ']' : ''),
            'check'   => '\i' . $feld . '=\i\v',
        );
    }
    return array('dashboard_status.xml', db_xml_virtual_in_http(array(
        'title'   => 'Dashboard-Designer',
        'address' => 'http://' . $host . '/plugins/' . $p['plugin']
                   . '/index.php?token=' . $token . '&aktion=status',
        'polling' => '60',
        'comment' => 'Erzeugt vom LoxBerry-Plugin Dashboard-Designer (' . date('d.m.Y') . ')',
    ), $cmds));
}
