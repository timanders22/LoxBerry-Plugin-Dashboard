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

/* Die Obergrenze der Wartezeit steht GENAU EINMAL: hier. Das Formular in der
 * Oberflaeche liest sie von hier, db_befehl_absetzen() kappt danach. Bis
 * 0.9.5 standen zwei verschiedene Zahlen an zwei Stellen. */
if (!defined('DB_WARTEZEIT_MAX')) { define('DB_WARTEZEIT_MAX', 30); }
if (!defined('DB_WARTEZEIT_MIN')) { define('DB_WARTEZEIT_MIN', 1); }


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
            'datadir'   => $basis . '/data',
            'bindir'    => $basis . '/bin',
            'logdir'    => $basis . '/log',
            'log'       => $basis . '/log/dashboard.log',
            'kacheln'   => $basis . '/templates/kacheln.json',
        );
    }
    $p['verlauf'] = $p['datadir'] . '/verlauf.json';
    $p['tafel']   = $p['datadir'] . '/tafel.json';
    return $p;
}

/** Voreinstellungen. Muessen zu VORGABEN in bin/dashboard_dienst.py passen.
 *
 * Die Uebereinstimmung prueft der Reiter Test - ein Kommentar, der sie nur
 * behauptet, ist eine Absichtserklaerung. Bis 0.9.5 fehlte 'haptik' auf der
 * Python-Seite, obwohl genau hier Gleichheit zugesichert war.
 */
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
        /* Neu in 0.9.6. Alle ab Werk AUS: eine bestehende dashboard.json
         * kennt die Schluessel nicht, also greift die Vorgabe auf JEDER
         * Anlage beim ersten Aufruf nach dem Update, ohne dass jemand etwas
         * angeklickt hat. Ein Vorgabewert, der dabei etwas veraendert, ist
         * ein Fehler (Hausregel). */
        'rotation'         => 0,    // Sekunden bis zur naechsten Seite, 0 = aus
        'nacht_von'        => '',   // "22:30", leer = kein Zeitplan
        'nacht_bis'        => '',
        'nacht_helligkeit' => 15,   // Prozent, 0 = Bildschirm schwarz
        'verlauf'          => 0,    // Verlaufskurve je Kachel
        'verlauf_punkte'   => 60,   // ein Punkt je Minute
        'sse'              => 0,    // Werte werden geschoben statt abgefragt
        'tafelsteuerung'   => 0,    // Loxone darf die Anzeigeseite umschalten
        /* Gesicherte Bausteine schalten. Ab Werk AUS, und das ist keine
         * Bequemlichkeit: wer in Loxone Config ein Visualisierungs-Passwort
         * setzt, will bei jedem Schaltvorgang gefragt werden. Steht das
         * Passwort hier hinterlegt, faellt genau diese Rueckfrage weg -
         * dafuer gibt es die PIN je Seite. Der Reiter Einstellungen sagt
         * das ausdruecklich. */
        'gesichert_schalten' => 0,
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

/* Es gibt bewusst nur EIN Sicherungsverfahren.
 *
 * Bis 0.9.5 liefen zwei nebeneinander: preupgrade.sh schrieb
 * <ordner>.backup.dashboard.json / .seiten.json / .zugang.json, und diese
 * Bibliothek zusaetzlich <ordner>.backup.json. Vier aehnlich heissende
 * Dateien flach nebeneinander, von denen die eine wie die Kurzform der
 * anderen aussah - und die PHP-Sicherung deckte ausgerechnet seiten.json
 * nicht ab, also gerade die Handarbeit.
 *
 * Schlimmer war die Wiederherstellung: sie sprang an, sobald dashboard.json
 * leer oder "{}" war. Nach einer Deinstallation und Neuinstallation holte
 * sie damit stillschweigend die alte Konfiguration samt altem Aktionstoken
 * zurueck. Eine saubere Neuinstallation war keine.
 *
 * Zustaendig sind jetzt preupgrade.sh (sichern), postinstall.sh
 * (wiederherstellen und die Sicherung wegraeumen) und uninstall.sh.
 */

function db_config()
{
    return array_merge(db_vorgaben(), db_json_lesen(db_paths()['config']));
}

function db_config_speichern($cfg)
{
    return db_json_schreiben(db_paths()['config'], $cfg, 0644);
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

/** Das Visualisierungs-Passwort - eigene Datei, Rechte 0600.
 *
 * Es steht NIE in dashboard.json: die zeigt die Oberflaeche an. Angezeigt
 * wird auch hier nur, OB eines hinterlegt ist - nie sein Wert, auch nicht
 * verkuerzt. Es verlaesst den LoxBerry nicht: allein der Dienst bildet daraus
 * den Hash und schickt den an den Miniserver.
 *
 * '--' als Eingabe loescht es. Ein leeres Feld behaelt das bisherige - sonst
 * loescht jedes Speichern der Einstellungen es unbemerkt weg.
 */
function db_visu_speichern($pw)
{
    $p = db_paths();
    $alt = db_zugang();
    if ($pw === '--') {
        unset($alt['visu_pw']);
    } elseif ($pw !== '') {
        $alt['visu_pw'] = $pw;
    } else {
        return true;
    }
    return db_json_schreiben($p['geheim'], $alt, 0600);
}

function db_visu_da()
{
    $z = db_zugang();
    return !empty($z['visu_pw']) ? 1 : 0;
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
    // Nur EIN Pfad. Bis 0.9.5 stand daneben ein zweiter, der als Rueckfall
    // fuer das ausgepackte Archiv gedacht war: installiert zeigte er ins
    // Leere (<home>/webfrontend/html/templates/...), und im Archiv war er
    // Zeichen fuer Zeichen derselbe, den db_paths() ohnehin liefert. Er
    // konnte in keiner der beiden Lagen greifen und liess einen zweiten
    // Fundort vermuten, den es nicht gibt.
    $d = db_json_lesen(db_paths()['kacheln']);
    if (!empty($d['typen'])) { $t = $d; return $t; }
    $t = array('typen' => array(), 'generisch' => array('kachel' => 'generisch'),
               'groessen' => array(), 'vorgabegroesse' => array());
    return $t;
}

/** Die Zeile der Kacheltabelle zu einem Loxone-Typ, oder die generische. */
function db_typzeile($loxtyp)
{
    $t = db_kacheltabelle();
    $typen = isset($t['typen']) && is_array($t['typen']) ? $t['typen'] : array();
    if ($loxtyp !== '' && isset($typen[$loxtyp]) && is_array($typen[$loxtyp])) {
        return $typen[$loxtyp];
    }
    return isset($t['generisch']) && is_array($t['generisch']) ? $t['generisch'] : array();
}

function db_struktur()  { return db_json_lesen(db_paths()['datadir'] . '/struktur.json'); }
function db_abbild()    { return db_json_lesen(db_paths()['datadir'] . '/abbild.json'); }
function db_zustand()   { return db_json_lesen(db_paths()['datadir'] . '/zustand.json'); }

/** Die Verlaufsreihen des Dienstes - ein Punkt je Minute, nur Zahlenwerte. */
function db_verlauf()
{
    $d = db_json_lesen(db_paths()['verlauf']);
    return isset($d['reihen']) && is_array($d['reihen']) ? $d['reihen'] : array();
}

/* ---------------- Steuerung der Anzeigeseite durch Loxone ----------------
 *
 * Ein Wandtablet soll bei Alarm auf die Sicherheitsseite springen und nachts
 * dunkel werden koennen - beides weiss nur Loxone. Der Miniserver legt den
 * Wunsch ueber einen virtuellen Ausgang im Endpunkt ab, die Anzeigeseite
 * holt ihn beim naechsten Takt ab.
 *
 * Bewusst eine Datei und kein Push: die Anzeigeseite fragt ohnehin im Takt,
 * und ein zweiter Uebertragungsweg waere eine zweite Fehlerquelle.
 */

function db_tafel_setzen($feld, $wert)
{
    $p = db_paths();
    $d = db_json_lesen($p['tafel']);
    $d[$feld] = $wert;
    $d['ts'] = time();
    // Jede Aenderung bekommt eine laufende Nummer. Die Anzeigeseite fuehrt
    // sie mit und reagiert nur auf eine NEUE - sonst spraenge sie bei jedem
    // Takt erneut auf dieselbe Seite und waere nicht mehr bedienbar.
    $d['nr'] = (int) (isset($d['nr']) ? $d['nr'] : 0) + 1;
    return db_json_schreiben($p['tafel'], $d);
}

function db_tafel_lesen()
{
    $d = db_json_lesen(db_paths()['tafel']);
    return array(
        'nr'      => (int) (isset($d['nr']) ? $d['nr'] : 0),
        'seite'   => (string) (isset($d['seite']) ? $d['seite'] : ''),
        'wach'    => (int) (isset($d['wach']) ? $d['wach'] : 0),
        'hell'    => (int) (isset($d['hell']) ? $d['hell'] : -1),
        'ts'      => (int) (isset($d['ts']) ? $d['ts'] : 0),
    );
}

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
        /* Benannte Formen. Sie stehen HIER und nicht als Ausdruck in der
         * kacheln.json: ein regulaerer Ausdruck aus einer Konfigurationsdatei,
         * der in zwei Sprachen ausgewertet wird, laeuft frueher oder spaeter
         * auseinander. In der Tabelle steht nur der Name.
         *
         * '$hsv' und '$temp' kamen mit 0.9.7 dazu. Bis dahin trug der
         * ColorPickerV2 nur '$wert', und '$wert' laesst ausschliesslich
         * Zahlen durch - hsv(240,100,80) waere also selbst dann abgewiesen
         * worden, wenn die Kachel es angeboten haette. Der Zeichenvorrat im
         * Endpunkt war seit 0.9.1 erweitert, die Positivliste nicht.
         */
        if ($e === '$hsv') {
            if (preg_match('/^hsv\((\d{1,3}),(\d{1,3}),(\d{1,3})\)$/', $befehl, $m)
                    && (int) $m[1] <= 360 && (int) $m[2] <= 100 && (int) $m[3] <= 100) {
                return array(true, '');
            }
            continue;
        }
        // temp(Helligkeit,Kelvin) und lumitech(Helligkeit,Kelvin) - dieselbe
        // Form, zwei Namen: der ColorPickerV2 nimmt temp, der aeltere
        // ColorPicker lumitech. Beides ist belegt in [S], Abschnitte
        // "ColorPickerV2" und "ColorPicker", Commands.
        if ($e === '$temp' || $e === '$lumitech') {
            $wort = ($e === '$temp') ? 'temp' : 'lumitech';
            if (preg_match('/^' . $wort . '\((\d{1,3}),(\d{4,5})\)$/', $befehl, $m)
                    && (int) $m[1] <= 100
                    && (int) $m[2] >= 1000 && (int) $m[2] <= 12000) {
                return array(true, '');
            }
            continue;
        }
        if ($e === '$wert' && is_numeric($befehl)) { return array(true, ''); }
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
    // escapeshellarg auch fuer den Pfad: escapeshellcmd maskiert keine
    // Leerzeichen. Ausnutzbar ist das hier nicht (der Pfad entsteht aus dem
    // eigenen Ablageort), aber der richtige Aufruf kostet nichts.
    @exec(escapeshellarg($skript) . ' ' . escapeshellarg($befehl) . ' 2>&1', $ausgabe, $code);
    db_log('Dienst ' . $befehl . ': Rueckgabewert ' . (int) $code);
    return array($code === 0 ? 1 : 0, implode("\n", $ausgabe));
}

/** Die messenden Knoepfe des Reiters Test. Rueckgabe: array(ok, Ausgabe). */
function db_probe($was)
{
    $erlaubt = array('anmeldeprobe', 'httpprobe', 'visuprobe', 'selbsttest');
    if (!in_array($was, $erlaubt, true)) {
        return array(0, 'Unbekannte Probe.');
    }
    $skript = db_paths()['bindir'] . '/dienst.sh';
    if (!is_file($skript)) {
        return array(0, 'dienst.sh nicht gefunden: ' . $skript);
    }
    $a = array(); $c = 0;
    @exec(escapeshellarg($skript) . ' ' . escapeshellarg($was) . ' 2>&1', $a, $c);
    return array($c === 0 ? 1 : 0, implode("\n", $a));
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
    // Die Grenze muss zu der im Formular passen. Bis 0.9.5 liess das
    // Formular 1 bis 60 zu und hier wurde bei 20 gekappt - jeder Wert
    // darueber war wirkungslos, ohne dass es irgendwo stand.
    $wartezeit = max(1, min(DB_WARTEZEIT_MAX, (int) $wartezeit));

    $ordner = $p['datadir'] . '/befehle';
    if (!is_dir($ordner) && !@mkdir($ordner, 0775, true) && !is_dir($ordner)) {
        db_log('Warteschlange nicht anlegbar: ' . $ordner);
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
        // Grenzen je Feld, nicht pauschal +/-2147483647. Loxone zieht daraus
        // die Reglergrenzen und die Plausibilitaetspruefung; wer alles offen
        // laesst, verschenkt beides (Hausregel). Bis 0.9.5 stand hier fuer
        // JEDES Feld dieselbe Zahl - auch fuer OK, das nur 0 oder 1 wird.
        $min = isset($c['min']) ? (int) $c['min'] : 0;
        $max = isset($c['max']) ? (int) $c['max'] : 2147483647;
        $o .= "\t" . '<VirtualInHttpCmd ';
        $o .= 'Title="' . db_x($c['title']) . '" ';
        $o .= 'Comment="' . db_x(isset($c['comment']) ? $c['comment'] : '') . '" ';
        $o .= 'Check="' . db_x(isset($c['check']) ? $c['check'] : ' ') . '" ';
        $o .= 'Signed="' . ($min < 0 ? 'true' : 'false') . '" ';
        $o .= 'Analog="' . (!empty($c['analog']) ? 'true' : 'false') . '" ';
        $o .= 'SourceValLow="0" ';
        $o .= 'DestValLow="0" ';
        $o .= 'SourceValHigh="1" ';
        $o .= 'DestValHigh="1" ';
        $o .= 'DefVal="0" ';
        $o .= 'MinVal="' . $min . '" ';
        $o .= 'MaxVal="' . $max . '"';
        $o .= '/>' . $crlf;
    }
    $o .= '</VirtualInHttp>' . $crlf;
    return $o;
}

/** Virtueller Ausgang: Loxone schickt Befehle an die Anzeigeseite.
 *
 * Aufbau nach dem Hausstandard: Wurzel VirtualOut mit Title, Comment,
 * Address, CloseAfterSend und CmdSep, darunter VirtualOutCmd. Die Adresse
 * ist hier ein Rechnername (HTTP), kein Geraetepfad - der gilt fuer UDP.
 */
function db_xml_virtual_out($kopf, $cmds)
{
    $crlf = "\r\n";
    $o = '<?xml version="1.0" encoding="utf-8"?>' . $crlf;
    $o .= '<VirtualOut ';
    $o .= 'Title="' . db_x($kopf['title']) . '" ';
    $o .= 'Comment="' . db_x(isset($kopf['comment']) ? $kopf['comment'] : '') . '" ';
    $o .= 'Address="' . db_x(isset($kopf['address']) ? $kopf['address'] : '') . '" ';
    $o .= 'CloseAfterSend="false" ';
    $o .= 'CmdSep="' . db_x(isset($kopf['cmdsep']) ? $kopf['cmdsep'] : ';') . '"';
    $o .= '>' . $crlf;
    foreach ($cmds as $c) {
        $o .= "\t" . '<VirtualOutCmd ';
        $o .= 'Title="' . db_x($c['title']) . '" ';
        $o .= 'Comment="' . db_x(isset($c['comment']) ? $c['comment'] : '') . '" ';
        $o .= 'CmdOnMethod="' . db_x(isset($c['method']) ? $c['method'] : 'GET') . '" ';
        $o .= 'CmdOn="' . db_x(isset($c['on']) ? $c['on'] : '') . '" ';
        $o .= 'CmdOffMethod="' . db_x(isset($c['method']) ? $c['method'] : 'GET') . '" ';
        $o .= 'CmdOff="' . db_x(isset($c['off']) ? $c['off'] : '') . '" ';
        $o .= 'Analog="' . (!empty($c['analog']) ? 'true' : 'false') . '" ';
        $o .= 'Repeat="0" ';
        $o .= 'RepeatRate="0"';
        $o .= '/>' . $crlf;
    }
    $o .= '</VirtualOut>' . $crlf;
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
    return 'http://' . db_host() . '/plugins/' . $p['plugin'] . '/tafel.php'
         . '?token=' . db_token() . ($seite !== '' ? '&seite=' . rawurlencode($seite) : '');
}

/** Kacheltypen, die es gibt - fuer die Auswahlliste im Designer.
 *
 * Die Beschriftung kommt aus 'kacheltexte' und gehoert zur KACHEL, nicht zum
 * Bausteintyp. Bis 0.9.5 wurde sie aus den Typen abgeleitet, und weil
 * mehrere Typen dieselbe Kachel benutzen, gewann der zuletzt gelesene: aus
 * "Alarmanlage" wurde "Brandmelder", aus "Zaehler" wurde "Betriebsstunden".
 * Bei sieben von siebzehn Kacheln stand der falsche Klartext in der Liste.
 */
function db_kacheltypen()
{
    $t = db_kacheltabelle();
    $texte = isset($t['kacheltexte']) && is_array($t['kacheltexte']) ? $t['kacheltexte'] : array();
    $aus = array();
    foreach ((isset($t['typen']) ? $t['typen'] : array()) as $lox => $z) {
        $k = isset($z['kachel']) ? $z['kachel'] : 'generisch';
        $aus[$k] = isset($texte[$k]) ? $texte[$k] : (isset($z['text']) ? $z['text'] : $k);
    }
    foreach (array('generisch', 'szene') as $k) {
        if (!isset($aus[$k])) {
            $aus[$k] = isset($texte[$k]) ? $texte[$k] : $k;
        }
    }
    ksort($aus);
    return $aus;
}

function db_groessen()
{
    $t = db_kacheltabelle();
    return isset($t['groessen']) && is_array($t['groessen']) ? $t['groessen'] : array();
}

/** Die Schritte einer Szenen-Kachel, geprueft und in Form gebracht. */
function db_szene_schritte($k)
{
    $aus = array();
    $roh = isset($k['schritte']) && is_array($k['schritte']) ? $k['schritte'] : array();
    foreach ($roh as $s) {
        if (!is_array($s)) { continue; }
        $u = (string) (isset($s['uuid']) ? $s['uuid'] : '');
        $b = (string) (isset($s['befehl']) ? $s['befehl'] : '');
        if ($u === '' || $b === '') { continue; }
        $aus[] = array('uuid' => $u, 'befehl' => $b);
    }
    return $aus;
}

/** Struktur und Werte fuer EINE Seite - genau das, was die Anzeige braucht. */
function db_seite_daten($schluessel)
{
    $seite = db_seite($schluessel);
    if ($seite === null) { return null; }
    $cfg = db_config();
    $abbild = db_abbild();
    $werte = isset($abbild['werte']) && is_array($abbild['werte']) ? $abbild['werte'] : array();
    $verlauf = !empty($cfg['verlauf']) ? db_verlauf() : array();
    $kacheln = array();
    foreach ((isset($seite['kacheln']) ? $seite['kacheln'] : array()) as $k) {
        if (!is_array($k)) { continue; }
        if (isset($k['sichtbar']) && !$k['sichtbar']) { continue; }
        $uuid = (string) (isset($k['uuid']) ? $k['uuid'] : '');
        // Alle Schluessel mit isset() lesen. Der Zweig 'fehlt' tat das bis
        // 0.9.5 nicht; bei einer handgepflegten seiten.json ohne 'titel' gab
        // PHP 8 eine Warnung aus - und die steht dann VOR dem JSON, worauf
        // das Tablet "keine lesbare Antwort" meldet.
        $titel = (string) (isset($k['titel']) ? $k['titel'] : '');
        $groesse = (string) (isset($k['groesse']) ? $k['groesse'] : '1x1');

        // Szene: mehrere Befehle auf einen Druck. Sie haengt an keinem
        // einzelnen Baustein, deshalb VOR der Bausteinsuche.
        if ((string) (isset($k['kachel']) ? $k['kachel'] : '') === 'szene') {
            $schritte = db_szene_schritte($k);
            $namen = array();
            foreach ($schritte as $s) {
                $b = db_baustein($s['uuid']);
                $namen[] = ($b !== null ? (string) $b['name'] : $s['uuid']) . ' → ' . $s['befehl'];
            }
            $kacheln[] = array(
                'uuid' => '', 'titel' => $titel !== '' ? $titel : 'Szene',
                'kachel' => 'szene', 'groesse' => $groesse,
                'schritte' => count($schritte), 'beschreibung' => $namen,
                'werte' => array(), 'befehle' => array(),
                'nurlesen' => 0, 'gesichert' => 0, 'warnung' => 0,
            );
            continue;
        }

        $b = db_baustein($uuid);
        if ($b === null) {
            // Der Baustein steht nicht mehr in der Struktur. Er wird NICHT
            // stillschweigend ausgelassen - sonst sucht jemand vergeblich.
            $kacheln[] = array('uuid' => $uuid, 'titel' => $titel,
                               'kachel' => 'fehlt', 'groesse' => $groesse,
                               'werte' => array(), 'befehle' => array(),
                               'nurlesen' => 1, 'gesichert' => 0, 'warnung' => 0);
            continue;
        }
        $zeile = db_typzeile((string) $b['loxtyp']);
        $eintrag = array(
            'uuid'     => $uuid,
            'titel'    => ($titel !== '' ? $titel : (string) $b['name']),
            'kachel'   => (string) (isset($k['kachel']) ? $k['kachel'] : $b['kachel']),
            'groesse'  => $groesse,
            'loxtyp'   => (string) $b['loxtyp'],
            'einheit'  => (string) $b['format'],
            'befehle'  => isset($b['befehle']) ? $b['befehle'] : array(),
            'nurlesen' => (int) (isset($b['nurlesen']) ? $b['nurlesen'] : 0),
            'gesichert' => (int) (isset($b['gesichert']) ? $b['gesichert'] : 0),
            /* 'gesperrt' sagt der Anzeigeseite, ob sie die Knoepfe abschalten
             * soll. Das Schloss bleibt bei einem gesicherten Baustein IMMER
             * stehen - man soll sehen, dass er gesichert ist, auch wenn er
             * gerade bedienbar ist. */
            'gesperrt' => (int) (
                (isset($b['nurlesen']) && $b['nurlesen'])
                || (isset($b['gesichert']) && $b['gesichert']
                    && (empty($cfg['gesichert_schalten']) || !db_visu_da()))),
            // 'warnung' steht in der Kacheltabelle an Alarmanlage und
            // Brandmelder und wurde bis 0.9.5 von nichts gelesen. Die Kachel
            // faerbt damit ihre schaltenden Knoepfe.
            'warnung'  => (int) (isset($zeile['warnung']) ? $zeile['warnung'] : 0),
            'werte'    => isset($werte[$uuid]) ? $werte[$uuid] : array(),
        );
        // Grenzen des Bausteins, falls der Miniserver sie mitschickt. Ohne
        // sie klemmte die Anzeige jeden Schieberegler auf 0..100 - bei einem
        // Slider mit anderem Bereich wich sie damit vom echten Wert ab.
        foreach (array('min', 'max', 'step') as $g) {
            if (isset($eintrag['werte'][$g]) && is_numeric($eintrag['werte'][$g])) {
                $eintrag[$g] = 0 + $eintrag['werte'][$g];
            }
        }
        // Grenzen der Farbtemperatur. Sie stehen NICHT in den Zustaenden,
        // sondern in den Details des Bausteins: [S] ColorPickerV2, Details
        // TWMin/TWMax, Vorgabe 2700 und 6500. 'pickerType' sagt, ob der
        // Baustein ueberhaupt Farbe kann (Rgb/Lumitech) oder nur Weisston
        // (TunableWhite) - danach richtet sich, welche Regler die Kachel zeigt.
        $det = isset($b['details']) && is_array($b['details']) ? $b['details'] : array();
        if ($eintrag['kachel'] === 'farbe') {
            $eintrag['twmin'] = (int) (isset($det['TWMin']) && is_numeric($det['TWMin'])
                                       ? $det['TWMin'] : 2700);
            $eintrag['twmax'] = (int) (isset($det['TWMax']) && is_numeric($det['TWMax'])
                                       ? $det['TWMax'] : 6500);
            $eintrag['pickertyp'] = (string) (isset($det['pickerType']) ? $det['pickerType'] : '');
        }
        if (isset($verlauf[$uuid]) && is_array($verlauf[$uuid])) {
            $eintrag['verlauf'] = array_values($verlauf[$uuid]);
        }
        // Namen der Ausgaenge einer Auswahl. Sie stehen in den Details des
        // Bausteins ([S] Radio, Details outputs und allOff). Ohne sie musste
        // die Kachel die Anzahl der Ausgaenge raten - bis 0.9.5 waren es fest
        // drei, obwohl der Baustein bis zu sechzehn haben kann.
        if ($eintrag['kachel'] === 'auswahl') {
            $eintrag['ausgaenge'] = isset($det['outputs']) && is_array($det['outputs'])
                ? $det['outputs'] : array();
            $eintrag['allesaus'] = (string) (isset($det['allOff']) ? $det['allOff'] : '');
        }
        // Klartexte zu den Wetterlagen - nur fuer die Wetterkachel, und nur
        // einmal. Sie stehen in der Strukturdatei ([S] weatherTypeTexts) und
        // ersparen der Kachel eine nackte Zahl.
        if ($eintrag['kachel'] === 'wetter') {
            $st = db_struktur();
            $eintrag['wettertexte'] = isset($st['wettertexte']) && is_array($st['wettertexte'])
                ? $st['wettertexte'] : array();
        }
        $kacheln[] = $eintrag;
    }
    return array(
        'schluessel' => $schluessel,
        'name'       => (string) (isset($seite['name']) ? $seite['name'] : $schluessel),
        // Nur die Tatsache, dass eine PIN gesetzt ist - nie ihr Wert.
        'pin'        => !empty($seite['pin']) ? 1 : 0,
        'spalten'    => (int) (isset($seite['spalten']) ? $seite['spalten'] : 6),
        'kacheln'    => $kacheln,
        'ok'         => (int) (isset($abbild['ok']) ? $abbild['ok'] : 0),
        'weg'        => (string) (isset($abbild['weg']) ? $abbild['weg'] : ''),
        'alter'      => db_alter(),
        'tafel'      => db_tafel_lesen(),
    );
}

/** Nur die Werte - das, was der Takt der Anzeigeseite holt. */
function db_seite_werte($schluessel)
{
    $seite = db_seite($schluessel);
    if ($seite === null) { return null; }
    $cfg = db_config();
    $abbild = db_abbild();
    $alle = isset($abbild['werte']) && is_array($abbild['werte']) ? $abbild['werte'] : array();
    $verlauf = !empty($cfg['verlauf']) ? db_verlauf() : array();
    $aus = array();
    $kurven = array();
    foreach ((isset($seite['kacheln']) ? $seite['kacheln'] : array()) as $k) {
        if (!is_array($k)) { continue; }
        $u = (string) (isset($k['uuid']) ? $k['uuid'] : '');
        if ($u === '') { continue; }
        if (isset($alle[$u])) { $aus[$u] = $alle[$u]; }
        if (isset($verlauf[$u])) { $kurven[$u] = array_values($verlauf[$u]); }
    }
    return array('ok' => (int) (isset($abbild['ok']) ? $abbild['ok'] : 0),
                 'weg' => (string) (isset($abbild['weg']) ? $abbild['weg'] : ''),
                 'alter' => db_alter(), 'werte' => $aus,
                 'verlauf' => $kurven, 'tafel' => db_tafel_lesen());
}

/** PIN einer Seite pruefen - in gleichbleibender Zeit.
 *
 * Diese Funktion ist die EINZIGE Stelle, an der eine PIN verglichen wird.
 * Bis 0.9.5 stand sie hier und wurde von nirgends aufgerufen, waehrend der
 * Endpunkt eine eigene Kopie desselben Vergleichs fuehrte - zwei Kopien
 * derselben Logik laufen zwangslaeufig auseinander.
 */
function db_pin_stimmt($seite, $eingabe)
{
    $s = db_seite($seite);
    if ($s === null) { return false; }
    $soll = (string) (isset($s['pin']) ? $s['pin'] : '');
    if ($soll === '') { return true; }
    return hash_equals($soll, (string) $eingabe);
}

/** Felder, die der Endpunkt als Zustandszeile liefert.
 *
 * Je Feld: Einheit, Sprachschluessel, Untergrenze, Obergrenze, analog.
 * Die Grenzen wandern in die Loxone-Vorlage - OK kann nur 0 oder 1 werden,
 * und das soll Loxone auch wissen.
 */
function db_status_felder()
{
    return array(
        'OK'         => array('',  'DB_FELD.OK',        0, 1,      0),
        'BAUSTEINE'  => array('',  'DB_FELD.BAUSTEINE', 0, 65535,  0),
        'SEITEN'     => array('',  'DB_FELD.SEITEN',    0, 255,    0),
        'KACHELN'    => array('',  'DB_FELD.KACHELN',   0, 65535,  0),
        'ALTER'      => array('s', 'DB_FELD.ALTER',     0, 86400,  1),
    );
}

/** Der Hostname, unter dem der Miniserver den LoxBerry erreicht.
 *
 * Ein Vorschlag, kein Beleg: gethostname() liefert nicht zwingend den Namen,
 * unter dem der Miniserver das Geraet findet. Der Reiter sagt das dazu.
 */
function db_host()
{
    return isset($_SERVER['HTTP_HOST']) && $_SERVER['HTTP_HOST'] !== ''
        ? preg_replace('/[^A-Za-z0-9\.\-:]/', '', (string) $_SERVER['HTTP_HOST'])
        : (gethostname() ?: 'loxberry');
}

function db_selbsttest_ausgabe()
{
    list($ok, $text) = db_probe('selbsttest');
    return $text;
}

function db_vorlage()
{
    $p = db_paths();
    $token = db_token();
    $cmds = array();
    foreach (db_status_felder() as $feld => $info) {
        $cmds[] = array(
            'title'   => 'DASHBOARD_' . $feld,
            'comment' => trim(strip_tags(html_entity_decode(db_t($info[1]), ENT_QUOTES, 'UTF-8')))
                       . ($info[0] !== '' ? ' [' . $info[0] . ']' : ''),
            'check'   => '\i' . $feld . '=\i\v',
            'min'     => $info[2],
            'max'     => $info[3],
            'analog'  => $info[4],
        );
    }
    return array('VI_DASHBOARD_STATUS.xml', db_xml_virtual_in_http(array(
        'title'   => 'Dashboard-Designer',
        'address' => 'http://' . db_host() . '/plugins/' . $p['plugin']
                   . '/index.php?token=' . $token . '&aktion=status',
        'polling' => '60',
        'comment' => 'Erzeugt vom LoxBerry-Plugin Dashboard-Designer (' . date('d.m.Y') . ')',
    ), $cmds));
}

/** Die Befehle, mit denen Loxone die Anzeigeseite steuern kann. */
function db_tafel_befehle()
{
    $aus = array();
    foreach (db_seiten() as $s) {
        $k = (string) (isset($s['schluessel']) ? $s['schluessel'] : '');
        if ($k === '') { continue; }
        $aus[] = array('art' => 'seite', 'wert' => $k,
                       'name' => (string) (isset($s['name']) ? $s['name'] : $k));
    }
    return $aus;
}

/** Vorlage der Steuerbefehle (virtueller Ausgang). */
function db_vorlage_out()
{
    $p = db_paths();
    $token = db_token();
    $basis = 'http://' . db_host() . '/plugins/' . $p['plugin']
           . '/index.php?token=' . $token . '&aktion=tafel';
    $cmds = array();
    foreach (db_tafel_befehle() as $b) {
        $cmds[] = array(
            'title'   => 'DASHBOARD_SEITE_' . strtoupper(preg_replace('/[^A-Za-z0-9]/', '_', $b['wert'])),
            'comment' => 'Schaltet jedes Wandtablet auf die Seite "' . $b['name'] . '".',
            'on'      => $basis . '&seite=' . rawurlencode($b['wert']),
            'off'     => '',
        );
    }
    $cmds[] = array(
        'title'   => 'DASHBOARD_WECKEN',
        'comment' => 'Weckt den Bildschirm und hebt die Nachtabsenkung auf.',
        'on'      => $basis . '&wach=1',
        'off'     => $basis . '&wach=0',
    );
    $cmds[] = array(
        'title'   => 'DASHBOARD_HELLIGKEIT',
        'comment' => 'Helligkeit der Anzeige in Prozent (0 = schwarz).',
        'on'      => $basis . '&hell=<v.0>',
        'off'     => '',
        'analog'  => 1,
    );
    return array('VQ_DASHBOARD_STEUERUNG.xml', db_xml_virtual_out(array(
        'title'   => 'Dashboard-Designer Steuerung',
        'address' => 'http://' . db_host(),
        'comment' => 'Erzeugt vom LoxBerry-Plugin Dashboard-Designer (' . date('d.m.Y') . ')',
    ), $cmds));
}
