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
/* Grenzen des Ruhebilds. Sie stehen hier, weil drei Stellen sie brauchen:
 * der Speicher-Handler der Oberflaeche, die Anzeigeseite und die Hilfe. Eine
 * Grenze steht genau einmal - sonst laesst das Formular zu, was der Handler
 * kappt, und niemand sieht es. */
if (!defined('DB_RUHE_NACH_MAX')) { define('DB_RUHE_NACH_MAX', 3600); }
/* Unter zehn Sekunden ist das Ruhebild keine Ruhe, sondern eine Sperre:
 * die Klicksperre nach dem Wegnehmen laeuft 700 ms, und was danach an
 * Bedienzeit bliebe, waere kuerzer als ein Handgriff. 0 bleibt erlaubt -
 * das heisst 'aus'. */
if (!defined('DB_RUHE_NACH_MIN')) { define('DB_RUHE_NACH_MIN', 10); }
/* Der Eco-Modus. Dieselbe Untergrenze wie beim Ruhebild und aus demselben
 * Grund: kuerzer waere keine Ruhe, sondern eine Sperre. */
if (!defined('DB_ECO_NACH_MIN')) { define('DB_ECO_NACH_MIN', 10); }
if (!defined('DB_ECO_NACH_MAX')) { define('DB_ECO_NACH_MAX', 3600); }
if (!defined('DB_ECO_HELL_MIN')) { define('DB_ECO_HELL_MIN', 0); }
if (!defined('DB_ECO_HELL_MAX')) { define('DB_ECO_HELL_MAX', 100); }
if (!defined('DB_RUHE_KACHELN_MAX')) { define('DB_RUHE_KACHELN_MAX', 12); }
if (!defined('DB_RUHE_BILD_MAX')) { define('DB_RUHE_BILD_MAX', 4194304); }  // 4 MB
if (!defined('DB_RUHE_BILD_KANTE')) { define('DB_RUHE_BILD_KANTE', 4096); }  // Punkte je Kante
/* Die Helligkeit des Ruhebilds - dieselbe Grenze an zwei Stellen war der
 * Befund, gegen den der Kommentar darueber geschrieben ist. */
if (!defined('DB_RUHE_HELL_MIN')) { define('DB_RUHE_HELL_MIN', 5); }
if (!defined('DB_RUHE_HELL_MAX')) { define('DB_RUHE_HELL_MAX', 100); }
/* C6 (Durchgang 29.09.2026): die PIN einer Seite. Nach DB_PIN_VERSUCHE
 * Fehlversuchen ist sie DB_PIN_SPERRE Sekunden gesperrt, jede weitere Sperre
 * dauert doppelt so lang, hoechstens DB_PIN_SPERRE_MAX. Bis 0.9.25 gab es
 * weder Sperre noch Bremse: alle 10.000 vierstelligen PINs in 122,6 s
 * (gemessen, Befund 6 des Code-Pruefers). */
if (!defined('DB_PIN_VERSUCHE')) { define('DB_PIN_VERSUCHE', 5); }
if (!defined('DB_PIN_SPERRE')) { define('DB_PIN_SPERRE', 300); }
if (!defined('DB_PIN_SPERRE_MAX')) { define('DB_PIN_SPERRE_MAX', 86400); }
/* C13: dieselbe Abweisung vom selben Absender steht hoechstens einmal je
 * DB_ABWEISUNG_BREMSE Sekunden im Protokoll. */
if (!defined('DB_ABWEISUNG_BREMSE')) { define('DB_ABWEISUNG_BREMSE', 60); }


/* Den LoxBerry-Wurzelordner ohne festen Systempfad bestimmen.
 *
 * Vom eigenen Ablageort aufwaerts, bis ein Verzeichnis gefunden ist, das
 * config/plugins, webfrontend UND config/system/general.json enthaelt. Das
 * trifft die uebliche Installation genauso wie eine an einem anderen Ort -
 * und es trifft auch den Fall, dass das Plugin noch als entpacktes Archiv
 * daliegt (dann findet es nichts und gibt einen Leerstring zurueck, was der
 * Aufrufer ohnehin abfangen muss).
 *
 * general.json unterscheidet einen LoxBerry von einem Rest aus Pruefstaenden:
 * ein LoxBerry hat sie immer, ein solcher Rest nie (Regeln/06). Ohne sie galt
 * ein fremder Baum mit config/plugins und webfrontend als Wurzel, und
 * db_paths() las dessen Konfiguration (gemessen am 18.09.2026 in WSL,
 * Pruefung-Dashboard-0.9.24, Fall F8).
 *
 * Der Name traegt kein Plugin-Kuerzel und ist deshalb abgesichert: zwei
 * Bibliotheken landen nie im selben Prozess, aber die Pruefung kostet nichts.
 */
if (!function_exists('lb_wurzel_ermitteln')) {
    function lb_wurzel_ermitteln()
    {
        $d = __DIR__;
        for ($i = 0; $i < 8; $i++) {
            if (is_dir($d . '/config/plugins') && is_dir($d . '/webfrontend')
                && is_file($d . '/config/system/general.json')) {
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
    // Das Hintergrundbild des Ruhebilds. Es liegt im DATENordner, nicht im
    // Konfigurationsordner: es ist kein Einstellwert, sondern Beiwerk, und es
    // darf ein Update ruhig verlieren. Der Name traegt keine Endung - die
    // steht in der Konfiguration, damit der Inhaltstyp nicht aus dem
    // Dateinamen geraten werden muss.
    $p['ruhebild'] = $p['datadir'] . '/ruhebild';
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
        /* Das Ruhebild - neu in 0.9.13, und ebenfalls ab Werk AUS. Es ist dem
         * Ambient Mode der Loxone-App nachempfunden (dort ab App und Config
         * 14.x, nur Querformat). Nachgebaut ist das VERHALTEN; eine
         * Schnittstelle dafuer gibt es bei Loxone nicht, und das Plugin
         * spricht auch keine an. */
        'ruhe_nach'      => 0,    // Sekunden ohne Beruehrung, 0 = aus
        'ruhe_uhr'       => 1,    // Uhrzeit und Datum gross
        'ruhe_wetter'    => 1,    // Wetterzeile, wenn ein Wetterdienst da ist
        'ruhe_kacheln'   => 6,    // Verknuepfungen, 0 bis 12
        'ruhe_seite'     => '',   // leer = die Seite, die gerade offen ist
        'ruhe_hell'      => 60,   // Prozent - "unaufdringlich" heisst dunkler
        'ruhe_bild'      => '',   // Endung des Hintergrundbilds, leer = keines
        /* Der Ambient-Modus - neu in 0.9.17, ebenfalls ab Werk AUS.
         *
         * Er ist etwas ANDERES als das Ruhebild, und die Loxone-App trennt
         * beides genauso: der Ambient-Modus ist die Tafel SELBST, gestaltet -
         * Uhrzeit, Datum und Wetter stehen dauerhaft ueber den Kacheln, das
         * Hintergrundbild liegt dahinter, und alles bleibt bedienbar. Das
         * Ruhebild dagegen tritt an die STELLE der Tafel, wenn niemand sie
         * beruehrt (bei Loxone heisst das Bildschirmschoner).
         *
         * Beide benutzen dasselbe Hintergrundbild ('ruhe_bild') und dieselbe
         * Wetterquelle - ein zweites Bild waere zwei Wahrheiten. */
        'ambient'        => 0,    // Uhr, Datum und Wetter dauerhaft ueber den Kacheln
        /* Der Eco-Modus - neu in 0.9.18, ab Werk AUS.
         *
         * Er senkt die Anzeige ab, wenn niemand das Tablet beruehrt - und
         * laesst dabei die Kacheln STEHEN. Das unterscheidet ihn vom
         * Ruhebild, das an ihre Stelle tritt, und von der Nachtabsenkung,
         * die nach der UHR geht statt nach der Beruehrung. Die Loxone-App
         * trennt die drei genauso.
         *
         * Nacht und Eco koennen zusammentreffen; dann gilt der DUNKLERE
         * von beiden - beide sagen 'jetzt soll es dunkel sein', und die
         * schaerfere Aussage gewinnt. */
        'eco_nach'       => 0,    // Sekunden ohne Beruehrung, 0 = nie
        'eco_hell'       => 30,   // Prozent, 0 = Bildschirm schwarz
        /* Die Wetterzeile aus eigenen Bausteinen - neu in 0.9.19, ab Werk leer.
         *
         * Loxones Wetterdienst ist nicht die einzige Wetterquelle einer Anlage.
         * Wer eine eigene Station hat (Ecowitt, Weather4Loxone, ein Fuehler am
         * Haus), hat die Werte laengst als gewoehnliche Bausteine in Loxone -
         * und die liest dieses Plugin ohnehin schon. Es braucht dafuer KEIN
         * MQTT und keine zweite Schnittstelle.
         *
         * Leer heisst: Loxones Wetterdienst, wie bisher. Sobald HIER etwas
         * steht, gilt ausschliesslich das - nie beides gemischt, das waeren
         * zwei Wahrheiten in einer Zeile. */
        'wetter_lage'    => '',   // Baustein-UUID, Text: "wolkenlos"
        'wetter_temp'    => '',   // Baustein-UUID, Zahl: Temperatur in °C
        'wetter_zusatz'  => '',   // Baustein-UUID, frei: Wind, Regen, Feuchte
    );
}

function db_json_lesen($pfad)
{
    if (!is_file($pfad)) { return array(); }
    $d = json_decode((string) @file_get_contents($pfad), true);
    return is_array($d) ? $d : array();
}

/* ---------------- Dateien lesen und schreiben ----------------
 *
 * C3, C4 und C12 (Durchgang 29.09.2026) sind eine Bauart:
 *   - Die Nebendatei hiess '<ziel>.tmp', ohne Prozessnummer. Oberflaeche und
 *     Dienst schrieben zugang.json gleichzeitig in DIESELBE Nebendatei:
 *     gemessen 1478 bis 2397 ungueltige Lesungen in drei Runden, danach
 *     fehlten Miniserver-Kennwort und Visualisierungs-Passwort.
 *   - Die Rechte kamen NACH dem Inhalt; zugang.json.tmp stand waehrend des
 *     Schreibens mit 0644 und lesbarem Kennwort da.
 *   - Ungueltiges JSON wurde als leere Datei gelesen. Der naechste Schreiber
 *     schrieb nur seine eigenen Schluessel zurueck, und db_token() wuerfelte
 *     bei einer beschaedigten dashboard.json ein neues Aktionstoken.
 * Jetzt: Nebendatei mit Prozessnummer, Rechte vor dem Inhalt, Laenge
 * pruefen, rename (Regeln/03, "Atomares Schreiben"). Die drei
 * Einrichtungsdateien werden STRENG gelesen: unlesbar ist ein Befund
 * ('kaputt'), keine leere Datei. */

/** Erst leer anlegen und schuetzen, dann fuellen, dann umbenennen.
 * true nur, wenn die Datei vollstaendig dasteht. */
function db_datei_schreiben($pfad, $inhalt, $rechte = null)
{
    $inhalt = (string) $inhalt;
    $ordner = dirname($pfad);
    if (!is_dir($ordner) && !@mkdir($ordner, 0775, true) && !is_dir($ordner)) { return false; }
    $tmp = $pfad . '.tmp.' . getmypid();
    $fh = @fopen($tmp, 'c');
    if ($fh === false) { return false; }
    if ($rechte !== null) { @chmod($tmp, $rechte); }
    $n = @ftruncate($fh, 0) ? @fwrite($fh, $inhalt) : false;
    @fflush($fh);
    @fclose($fh);
    if ($n !== strlen($inhalt)) { @unlink($tmp); return false; }
    if (!@rename($tmp, $pfad)) { @unlink($tmp); return false; }
    return true;
}

/** Erst kodieren, Rueckgabe pruefen, dann schreiben (Regeln/03). */
function db_json_schreiben($pfad, $daten, $rechte = null)
{
    $json = json_encode($daten, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) { return false; }
    return db_datei_schreiben($pfad, $json, $rechte);
}

/** Streng lesen. $lage: 'ok', 'fehlt', 'kaputt' (kein JSON-Objekt) oder
 * 'unlesbar'. Rueckgabe das Feld, array() bei 'fehlt', sonst null. */
function db_json_lesen_streng($pfad, &$lage = null)
{
    clearstatcache(true, $pfad);
    if (!is_file($pfad)) {
        $lage = 'fehlt';
        return array();
    }
    $roh = @file_get_contents($pfad);
    if (!is_string($roh)) {
        $lage = 'unlesbar';
        return null;
    }
    $d = json_decode($roh, true);
    if (!is_array($d)) {
        $lage = 'kaputt';
        db_kaputt_melden($pfad, $roh);
        return null;
    }
    $lage = 'ok';
    return $d;
}

/** Eine beschaedigte Datei: Abschrift '<datei>.kaputt' und EINE Protokollzeile
 * je Stunde. Die Datei selbst bleibt liegen - wer sie ersetzt, entscheidet. */
function db_kaputt_melden($pfad, $roh)
{
    static $gemeldet = array();
    if (isset($gemeldet[$pfad])) { return; }
    $gemeldet[$pfad] = 1;
    $ziel = $pfad . '.kaputt';
    if (!is_file($ziel) || (string) @file_get_contents($ziel) !== (string) $roh) {
        db_datei_schreiben($ziel, (string) $roh, 0600);
    }
    db_log_gebremst('kaputt_' . basename($pfad), basename($pfad) . ' ist beschaedigt (kein gueltiges JSON). '
        . 'Abschrift: ' . $ziel . '. Darueber wird nichts geschrieben, bis sie ersetzt ist.');
}

/** Eine Arbeit unter einer Sperrdatei (flock). Dieselbe Sperrdatei nimmt der
 * Dienst fuer zugang.json (bin/dashboard_dienst.py, zugang_sperre()). Laesst
 * sich die Sperrdatei nicht oeffnen, wird ohne Sperre gearbeitet - wie der
 * Dienst, wenn fcntl fehlt. */
function db_mit_sperre($sperrdatei, $arbeit)
{
    $ordner = dirname($sperrdatei);
    if (!is_dir($ordner)) { @mkdir($ordner, 0775, true); }
    $fh = @fopen($sperrdatei, 'c');
    if ($fh === false) {
        return call_user_func($arbeit);
    }
    @chmod($sperrdatei, 0600);
    @flock($fh, LOCK_EX);
    try {
        return call_user_func($arbeit);
    } finally {
        @flock($fh, LOCK_UN);
        @fclose($fh);
    }
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
 * (wiederherstellen und die Sicherung wegraeumen) und uninstall/uninstall.
 * Bis 0.9.24 stand hier "uninstall.sh": die Datei im Wurzelordner ruft der
 * Installer nie auf (plugininstall.pl liest nur uninstall/uninstall, am
 * Geraet am 18.09.2026 gemessen). Sie ist seit 0.9.25 entfernt (I7).
 */

function db_config()
{
    $roh = db_json_lesen_streng(db_paths()['config'], $lage);
    return array_merge(db_vorgaben(), is_array($roh) ? $roh : array());
}

/** 'ok', 'fehlt', 'kaputt' oder 'unlesbar' (C12). */
function db_config_lage()
{
    db_json_lesen_streng(db_paths()['config'], $lage);
    return $lage;
}

/** C11 (Durchgang 29.09.2026): 0600 statt 0644 - die Datei traegt das
 * Aktionstoken (Regeln/05). C12: eine beschaedigte Datei wird nicht still
 * ueberschrieben; nur wer sie ausdruecklich ersetzt (Zurueckspielen, "Neues
 * Token erzeugen"), darf das - die Abschrift .kaputt bleibt daneben. */
function db_config_speichern($cfg, $trotz_kaputt = false)
{
    if (!$trotz_kaputt) {
        $lage = db_config_lage();
        if ($lage === 'kaputt' || $lage === 'unlesbar') { return false; }
    }
    return db_json_schreiben(db_paths()['config'], $cfg, 0600);
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
    return db_zugang_aendern(function ($alt) use ($adresse, $port, $benutzer, $passwort) {
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
        return $alt;
    });
}

/** Lesen, aendern, schreiben - unter der gemeinsamen Sperre mit dem Dienst
 * (C3, Durchgang 29.09.2026; Gegenstueck zugang_sperre() in
 * bin/dashboard_dienst.py). Bis 0.9.25 las jeder Schreiber ohne Sperre und
 * schrieb nur seine eigenen Schluessel zurueck - was der andere dazwischen
 * geschrieben hatte, war weg. Eine beschaedigte Datei wird nicht still ueber-
 * schrieben ($trotz_kaputt nur beim ausdruecklichen Zurueckspielen).
 * $aenderung bekommt den bisherigen Inhalt und gibt den neuen zurueck; gibt
 * sie keine Liste zurueck, wird nichts geschrieben. */
function db_zugang_aendern($aenderung, $trotz_kaputt = false)
{
    $p = db_paths();
    return db_mit_sperre($p['configdir'] . '/zugang.sperre',
        function () use ($p, $aenderung, $trotz_kaputt) {
            $alt = db_json_lesen_streng($p['geheim'], $lage);
            if (($lage === 'kaputt' || $lage === 'unlesbar') && !$trotz_kaputt) {
                return false;
            }
            $neu = call_user_func($aenderung, is_array($alt) ? $alt : array());
            if (!is_array($neu)) { return true; }
            return db_json_schreiben($p['geheim'], $neu, 0600);
        });
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
    if ($pw === '') { return true; }
    return db_zugang_aendern(function ($alt) use ($pw) {
        if ($pw === '--') {
            unset($alt['visu_pw']);
        } else {
            $alt['visu_pw'] = $pw;
        }
        return $alt;
    });
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

/** EIN Tafelbefehl - genau die Felder, die dieser Aufruf nennt.
 *
 * Bis 0.9.12 hiess die Funktion db_tafel_setzen() und schrieb ein einzelnes
 * Feld in die vorhandene Datei. Damit war tafel.json kein Befehl, sondern ein
 * Sack mit den letzten Werten - und weil die Anzeigeseite auf jede NEUE
 * laufende Nummer alle Felder erneut anwendet, wirkte jeder frueher gesetzte
 * Wert bei jedem spaeteren Befehl wieder mit.
 *
 * Der Ablauf, der schiefging: abends '&hell=20', am naechsten Tag '&seite=flur'.
 * Der zweite Aufruf erhoeht nur die Nummer; 'hell' steht weiter auf 20, die
 * Tafel liest 't.hell >= 0' und legt den Nachtschleier wieder auf. Ein
 * Befehl, der laut Kopfzeile nur die Seite umschaltet, dunkelte den
 * Bildschirm ab - und weil die Seite dieselbe blieb, loeste ihn auch kein
 * Neuaufbau wieder auf. Dasselbe mit '&wach=1', das danach jeden Befehl
 * begleitete.
 *
 * Jetzt gilt: was dieser Aufruf nicht nennt, steht ausdruecklich auf 'nichts
 * gesagt' (seite '', wach 0, hell -1). Und ein Aufruf, der drei Felder
 * setzt, erhoeht die Nummer EINMAL, nicht dreimal.
 */
function db_tafel_befehl($felder)
{
    /* C8 (Durchgang 29.09.2026): Befehle, die kurz nacheinander kommen,
     * werden ZUSAMMENGEFUEHRT. Bis 0.9.25 ersetzte jeder Befehl die ganze
     * Datei, und die Anzeigeseite sieht nur die neueste Nummer: kamen
     * '&seite=b' und '&wach=1' innerhalb eines Takts, ging die Seite verloren
     * (gemessen, E7 des Code-Pruefers). Innerhalb von zwei Anzeigetakten
     * (mindestens 2 s) bleiben die Felder des vorigen Befehls stehen, soweit
     * der neue sie nicht selbst nennt. Danach gilt wieder "nichts gesagt" -
     * der Fehler aus 0.9.12 (ein alter Wert wirkt bei jedem spaeteren Befehl
     * mit) kehrt damit nicht zurueck.
     * Rueckgabe true nur, wenn tafel.json vollstaendig geschrieben ist - der
     * Endpunkt antwortet sonst mit 500. */
    $p = db_paths();
    $cfg = db_config();
    $fenster = max(2, 2 * max(1, min(30, (int) $cfg['takt'])));
    return db_mit_sperre($p['datadir'] . '/tafel.sperre', function () use ($p, $felder, $fenster) {
        $alt = db_json_lesen($p['tafel']);
        $d = array('seite' => '', 'wach' => 0, 'hell' => -1, 'ruhe' => -1);
        $ts = isset($alt['ts']) && is_numeric($alt['ts']) ? (int) $alt['ts'] : 0;
        $abstand = time() - $ts;
        if ($ts > 0 && $abstand >= 0 && $abstand < $fenster) {
            foreach (array_keys($d) as $f) {
                if (array_key_exists($f, $alt)) { $d[$f] = $alt[$f]; }
            }
        }
        foreach (array('seite', 'wach', 'hell', 'ruhe') as $f) {
            if (array_key_exists($f, (array) $felder)) { $d[$f] = $felder[$f]; }
        }
        $d['ts'] = time();
        // Jede Aenderung bekommt eine laufende Nummer. Die Anzeigeseite fuehrt
        // sie mit und reagiert nur auf eine NEUE.
        $d['nr'] = (int) (isset($alt['nr']) ? $alt['nr'] : 0) + 1;
        return db_json_schreiben($p['tafel'], $d);
    });
}

function db_tafel_lesen()
{
    $d = db_json_lesen(db_paths()['tafel']);
    return array(
        'nr'      => (int) (isset($d['nr']) ? $d['nr'] : 0),
        'seite'   => (string) (isset($d['seite']) ? $d['seite'] : ''),
        'wach'    => (int) (isset($d['wach']) ? $d['wach'] : 0),
        'hell'    => (int) (isset($d['hell']) ? $d['hell'] : -1),
        // -1 heisst "nichts gesagt". Ein Tafelbefehl, der 'ruhe' nicht nennt,
        // schaltet das Ruhebild weder ein noch aus.
        'ruhe'    => (int) (isset($d['ruhe']) ? $d['ruhe'] : -1),
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
    $s = isset($d['seiten']) && is_array($d['seiten']) ? $d['seiten'] : array();
    if (db_pins_im_klartext($s)) {
        $s = db_pins_umstellen($s);
    }
    return $s;
}

/** C11 (Durchgang 29.09.2026): seiten.json mit 0600 - sie traegt die
 * PIN-Pruefwerte; bis 0.9.25 stand die PIN im Klartext in einer 0644-Datei.
 * Eine beschaedigte Datei wird nicht still ueberschrieben (Bauart C12);
 * das Zurueckspielen ersetzt sie ausdruecklich ($trotz_kaputt). */
function db_seiten_speichern($seiten, $trotz_kaputt = false)
{
    $p = db_paths();
    return db_mit_sperre($p['configdir'] . '/seiten.sperre',
        function () use ($p, $seiten, $trotz_kaputt) {
            $d = db_json_lesen_streng($p['seiten'], $lage);
            if ($lage === 'kaputt' || $lage === 'unlesbar') {
                if (!$trotz_kaputt) { return false; }
                $d = array();
            }
            $d['seiten'] = array_values($seiten);
            $d['geaendert'] = time();
            return db_json_schreiben($p['seiten'], $d, 0600);
        });
}

/** Ist $s ein PIN-Pruefwert aus password_hash()? */
function db_pin_ist_hash($s)
{
    if (!is_string($s) || strlen($s) < 20) { return false; }
    $i = password_get_info($s);
    return !empty($i['algo']);
}

/** Der Pruefwert einer PIN - oder false. */
function db_pin_hash($pin)
{
    $h = password_hash((string) $pin, PASSWORD_DEFAULT);
    return is_string($h) && db_pin_ist_hash($h) ? $h : false;
}

function db_pins_im_klartext($seiten)
{
    foreach ($seiten as $s) {
        if (!is_array($s) || !isset($s['pin'])) { continue; }
        $pin = is_int($s['pin']) ? (string) $s['pin'] : $s['pin'];
        if (is_string($pin) && $pin !== '' && !db_pin_ist_hash($pin)) { return true; }
    }
    return false;
}

/** Eine Klartext-PIN wird beim ersten Lesen in einen Pruefwert umgewandelt
 * (Bauliste, "PIN als Hash"). Die PIN gilt danach unveraendert - verglichen
 * wird mit password_verify(). Einmal je Anfrage; misslingt das Schreiben,
 * bleibt der Klartext stehen, und db_pin_pruefen() vergleicht weiter damit. */
function db_pins_umstellen($seiten)
{
    static $versucht = false;
    if ($versucht) { return $seiten; }
    $versucht = true;
    $p = db_paths();
    $erg = db_mit_sperre($p['configdir'] . '/seiten.sperre', function () use ($p) {
        $d = db_json_lesen_streng($p['seiten'], $lage);
        if ($lage !== 'ok' || !isset($d['seiten']) || !is_array($d['seiten'])) { return null; }
        $n = 0;
        foreach ($d['seiten'] as $i => $s) {
            if (!is_array($s) || !isset($s['pin'])) { continue; }
            $pin = is_int($s['pin']) ? (string) $s['pin'] : $s['pin'];
            if (!is_string($pin) || $pin === '' || db_pin_ist_hash($pin)) { continue; }
            $h = db_pin_hash($pin);
            if ($h === false) { continue; }
            $d['seiten'][$i]['pin'] = $h;
            $n++;
        }
        if ($n === 0) { return $d['seiten']; }
        if (!db_json_schreiben($p['seiten'], $d, 0600)) { return null; }
        db_log(sprintf('Die PIN von %d Seite(n) steht jetzt als Pruefwert in seiten.json, nicht mehr im Klartext.', $n));
        return $d['seiten'];
    });
    return is_array($erg) ? $erg : $seiten;
}

function db_seite($schluessel)
{
    foreach (db_seiten() as $s) {
        if (isset($s['schluessel']) && $s['schluessel'] === $schluessel) { return $s; }
    }
    return null;
}

/** Alter des Abbilds in Sekunden, oder -1.
 *
 * C7 (Durchgang 29.09.2026, Entscheidung 4): -1 heisst "kein Abbild" ODER
 * "Zeitstempel unbrauchbar" - 0, fehlend oder in der Zukunft. Bis 0.9.25
 * machte max(0, ...) aus einem Zeitstempel in der Zukunft (Uhrsprung beim
 * Booten) ein frisches Abbild mit ALTER=0, und ts=0 ergab ein Alter von
 * 1.790.741.789 s (gemessen, E4/E5 des Code-Pruefers). */
function db_alter($a = null)
{
    if ($a === null) { $a = db_abbild(); }
    if (!isset($a['ts']) || !is_numeric($a['ts']) || (int) $a['ts'] <= 0) { return -1; }
    $d = time() - (int) $a['ts'];
    return $d < 0 ? -1 : $d;
}

/** Die Frist, bis zu der das Abbild als frisch gilt (Entscheidung 4).
 *
 * Der Dienst schreibt das Abbild bei stehender Verbindung mindestens alle
 * 30 s (dashboard_dienst.py, laufen()), im HTTP-Notnagel einmal je Runde im
 * Takt http_takt. Die Frist ist das Dreifache: 3 x max(takt, 30 s), im
 * Notnagel 3 x http_takt. */
function db_frist($a = null, $cfg = null)
{
    if ($a === null) { $a = db_abbild(); }
    if ($cfg === null) { $cfg = db_config(); }
    if (isset($a['weg']) && $a['weg'] === 'http') {
        return 3 * max(5, min(120, (int) $cfg['http_takt']));
    }
    return 3 * max(max(1, min(30, (int) $cfg['takt'])), 30);
}

/** Die Werte der Zustandszeile - EINE Stelle fuer Endpunkt und Reiter Test
 * (H8). OK ist 1 nur bei ok=1 im Abbild UND einem Alter innerhalb der Frist;
 * ALTER bleibt unveraendert daneben (Entscheidung 4). */
function db_status_werte()
{
    $a = db_abbild();
    $cfg = db_config();
    $seiten = db_seiten();
    $kacheln = 0;
    foreach ($seiten as $s) {
        $kacheln += count(is_array($s) && isset($s['kacheln']) && is_array($s['kacheln']) ? $s['kacheln'] : array());
    }
    $alter = db_alter($a);
    $ok = (!empty($a['ok']) && $alter >= 0 && $alter <= db_frist($a, $cfg)) ? 1 : 0;
    return array('OK' => $ok, 'BAUSTEINE' => count(db_bausteine()), 'SEITEN' => count($seiten),
                 'KACHELN' => $kacheln, 'ALTER' => $alter);
}

/** Die Zustandszeile, gebaut AUS db_status_felder() (H8, Regeln/07). Bis
 * 0.9.25 stand sie dreimal im Code: im Endpunkt, im Reiter Test und als
 * Feldliste der Vorlage. */
function db_status_zeile($werte = null)
{
    if ($werte === null) { $werte = db_status_werte(); }
    $teile = array('DASHBOARD');
    foreach (array_keys(db_status_felder()) as $f) {
        $teile[] = $f . '=' . (int) (isset($werte[$f]) ? $werte[$f] : 0);
    }
    return implode(';', $teile);
}

/* ---------------- Token ---------------- */

function db_token_erzeugen($laenge = 24)
{
    $zeichen = 'abcdefghijkmnpqrstuvwxyz23456789';
    $t = '';
    for ($i = 0; $i < $laenge; $i++) { $t .= $zeichen[random_int(0, strlen($zeichen) - 1)]; }
    return $t;
}

/** Taugt $t als Aktionstoken? (Regeln/05: [A-Za-z0-9_.-], 1 bis 64 Zeichen.)
 *
 * C5 (Durchgang 29.09.2026): nur eine Zeichenkette. Bis 0.9.25 machte
 * (string) aus einem Token, das als Liste in einer Sicherung stand, das Wort
 * "Array" - und ?token=Array wie ?token[]=x kamen durch (gemessen). Das Wort
 * "Array" selbst wird deshalb auch als Zeichenkette nicht angenommen: es ist
 * genau die Spur dieser Umwandlung und kein gewolltes Token. */
function db_token_gueltig($t)
{
    return is_string($t) && $t !== 'Array'
        && preg_match('/^[A-Za-z0-9_.\-]{1,64}\z/', $t) === 1;
}

/** Das geltende Token fuer Endpunkt und Anzeigeseite - '' wenn keins taugt. */
function db_token_soll($cfg)
{
    $t = isset($cfg['aktionstoken']) ? $cfg['aktionstoken'] : '';
    return db_token_gueltig($t) ? $t : '';
}

/** Eine Angabe aus der Adresse, VOR jeder Umwandlung auf ihre Art geprueft
 * (C5). null = nicht angegeben, false = keine Zeichenkette (Liste). */
function db_get($name)
{
    if (!isset($_GET[$name])) { return null; }
    return is_string($_GET[$name]) ? $_GET[$name] : false;
}

function db_token()
{
    /* C12 (Durchgang 29.09.2026): ein neues Token entsteht NUR, wenn der
     * Schluessel fehlt - array_key_exists, nicht empty (CLAUDE.md, 9). Bis
     * 0.9.25 genuegte ein leerer Wert, und eine beschaedigte dashboard.json
     * las sich als leer: ein einziger Seitenaufruf machte daraus
     * Werkseinstellungen samt neuem Token (gemessen, kaputt.php).
     * Jetzt: Datei beschaedigt -> kein Token, es wird nichts geschrieben;
     * Schluessel da, Wert untauglich -> kein Token, die Oberflaeche sagt es;
     * nur ein FEHLENDER Schluessel wird einmal angelegt. */
    $p = db_paths();
    $roh = db_json_lesen_streng($p['config'], $lage);
    if ($lage === 'kaputt' || $lage === 'unlesbar') { return ''; }
    if ($lage === 'ok' && array_key_exists('aktionstoken', $roh)) {
        return db_token_gueltig($roh['aktionstoken']) ? $roh['aktionstoken'] : '';
    }
    $cfg = array_merge(db_vorgaben(), $lage === 'ok' ? $roh : array());
    $cfg['aktionstoken'] = db_token_erzeugen();
    if (!db_config_speichern($cfg)) { return ''; }
    return $cfg['aktionstoken'];
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
        return array(false, db_t('BEFEHL.UNBEKANNT'));
    }
    if (!empty($b['nurlesen'])) {
        return array(false, db_t('BEFEHL.NUR_LESEN'));
    }
    $erlaubt = isset($b['befehle']) && is_array($b['befehle']) ? $b['befehle'] : array();
    if (!$erlaubt) {
        return array(false, sprintf(db_t('BEFEHL.KEIN_BEFEHL'), db_e((string) $b['loxtyp'])));
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
    return array(false, sprintf(db_t('BEFEHL.NICHT_VORGESEHEN'), db_e($befehl),
                                db_e((string) $b['loxtyp'])));
}


function db_log($text)
{
    $p = db_paths();
    if (!is_dir($p['logdir'])) {
        @mkdir($p['logdir'], 0775, true);
    }
    /* NUR ANHAENGEN. Bis 0.9.12 stand hier eine zweite Rotation: bei ueber
     * 512.000 Byte wurde die Datei mit ihren letzten 400 Zeilen
     * UEBERSCHRIEBEN. Genau das darf die Oberflaeche nicht - der Dienst
     * haelt auf dieselbe Datei einen offenen Deskriptor (RotatingFileHandler,
     * ebenfalls 512.000 Byte). Nach dem Ueberschreiben schreibt er an seinem
     * ALTEN Byte-Versatz weiter: es entsteht eine Datei mit einem Loch aus
     * Null-Bytes, und alles, was die Oberflaeche gerade behalten wollte, ist
     * beim naechsten Schreibvorgang des Dienstes wieder ueberdeckt.
     *
     * Es rotiert deshalb genau EINER, und das ist der Dienst. Die Oberflaeche
     * haengt nur an (O_APPEND, zeilenweise, und ueber db_log_gebremst()
     * hoechstens einmal je Stunde und Schluessel) - das vertraegt sich mit
     * einem zweiten Schreiber, ein Ueberschreiben nicht. */
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

/** Die Adresse des Anrufers, auf zulaessige Zeichen beschraenkt. */
function db_absender()
{
    $a = isset($_SERVER['REMOTE_ADDR']) && is_string($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '';
    $a = substr((string) preg_replace('/[^0-9A-Fa-f.:]/', '', $a), 0, 45);
    return $a !== '' ? $a : 'unbekannt';
}

/** C13 (Durchgang 29.09.2026): jeder abgewiesene Weg von Endpunkt und
 * Anzeigeseite schreibt eine Zeile mit Absender und Grund, gebremst je Ort,
 * Grund und Absender (Regeln/03). Nie mit Token oder PIN. Bis 0.9.25 gab es
 * eine einzige Zeile, ohne Absender und hoechstens einmal je Stunde; 9999
 * falsche PINs hinterliessen keine Protokolldatei (gemessen).
 * Nur in einer eingerichteten Anlage: der unangemeldete Weg legt keine
 * Ordner an (fehlt der Protokoll- oder Datenordner, entfaellt die Zeile). */
function db_abweisung($ort, $grund)
{
    $p = db_paths();
    if (!is_dir($p['logdir']) || !is_dir($p['datadir'])) { return; }
    $grund = (string) preg_replace('/[^A-Z0-9_]/', '', strtoupper((string) $grund));
    $wer = db_absender();
    db_log_gebremst('ab_' . $ort . '_' . $grund . '_' . str_replace(array('.', ':'), '_', $wer),
        sprintf('%s: Anfrage abgewiesen, Grund %s, Absender %s.', $ort, $grund !== '' ? $grund : '?', $wer),
        DB_ABWEISUNG_BREMSE);
}

/** Nimmt der Miniserver TCP-Verbindungen an? Hoechstens einmal je Minute.
 *
 * Rueckgabe: array(true|false, Alter des Messwerts in Sekunden).
 *
 * Warum gepuffert: der Reiter Test wird bei JEDEM Seitenaufruf serverseitig
 * mitgebaut, auch wenn der Bediener in den Einstellungen steht - die Reiter
 * schaltet erst der Browser um. Ein nicht erreichbarer Miniserver kostete
 * damit gemessene 2,007 s je Klick und je Speichervorgang.
 *
 * Der Puffer liegt im Datenordner und uebersteht ein Update ausdruecklich
 * NICHT - er soll es auch nicht: nach einem Update ist jede alte Messung
 * wertlos.
 */
function db_erreichbarkeit($adresse, $port, $frist = 60)
{
    $f = db_paths()['datadir'] . '/.erreichbar';
    clearstatcache(true, $f);
    if (is_file($f)) {
        $d = @json_decode((string) @file_get_contents($f), true);
        if (is_array($d) && isset($d['ts'], $d['ok'], $d['ziel'])
                && $d['ziel'] === $adresse . ':' . $port
                && time() - (int) $d['ts'] < $frist) {
            return array((bool) $d['ok'], time() - (int) $d['ts']);
        }
    }
    // Eine Sekunde reicht im eigenen Netz. Zwei waren nur die Vorgabe von
    // frueher und verdoppelten die Wartezeit im Fehlerfall.
    $auf = @fsockopen($adresse, $port, $en, $es, 1);
    $ok = false;
    if ($auf) { fclose($auf); $ok = true; }
    if (!is_dir(dirname($f))) { @mkdir(dirname($f), 0775, true); }
    @file_put_contents($f, json_encode(array(
        'ts' => time(), 'ok' => $ok ? 1 : 0, 'ziel' => $adresse . ':' . $port)));
    return array($ok, 0);
}

/* ---------------- Dienst ---------------- */


/**
 * Ist die Prozessnummer $pid der Dienst DIESES Plugins?
 *
 * Argumentweise (Regeln/03, "Prozesse argumentweise erkennen"), nicht ueber
 * eine Teilzeichenkette. Bis 0.9.21 stand hier
 *     strpos($cmd, 'dashboard_dienst.py') !== false
 * und das hielt jeden Prozess fuer den Dienst, in dessen Befehlszeile der Name
 * irgendwo vorkommt: einen Editor mit der Datei offen, ein Sicherungsskript,
 * das den Ordner durchsucht, den Einmallauf der eigenen Oberflaeche. In WSL
 * gemessen (Pruefung-Dashboard-0.9.21, Fall 9): fuer einen fremden Prozess
 * "tail -f <dienstpfad>", dessen Nummer in der PID-Datei stand, gab
 * db_dienst_pid() dessen Nummer zurueck - die Kachel meldete "Dienst laeuft",
 * und der Knopf "Logdatei leeren" verweigerte sich mit LOG.LEEREN_LAEUFT.
 *
 * Ein Treffer hat GENAU zwei Argumente: argv[0] ist ein Python, argv[1] ist
 * genau der eigene Dienstpfad. bin/dienst.sh startet den Dauerlaeufer an genau
 * einer Stelle als  <bindir>/venv/bin/python3 <bindir>/dashboard_dienst.py;
 * die Einmallaeufe (--selbsttest, --einmal, --entwurf, --anmeldeprobe,
 * --httpprobe, --visuprobe) haben ein drittes Argument und sind kein
 * laufender Dienst.
 *
 * Der zweite Vergleich ueber realpath() deckt den Fall ab, dass der Dienst
 * ueber einen anderen Pfad auf dieselbe Datei gestartet wurde: bin/dienst.sh
 * loest seinen Ablageort mit readlink -f auf, db_paths() baut ihn aus
 * LBHOMEDIR. Ohne ihn meldete die Oberflaeche "gestoppt", waehrend der Dienst
 * laeuft.
 */
function db_ist_dienst($pid)
{
    $pid = (int) $pid;
    if ($pid <= 0) {
        return false;
    }
    $roh = @file_get_contents('/proc/' . $pid . '/cmdline');
    if (!is_string($roh) || $roh === '') {
        return false;
    }
    // Die Befehlszeile ist eine Folge von Argumenten, jedes mit einem Nullbyte
    // abgeschlossen; das letzte Stueck nach dem Trennen ist deshalb leer.
    $teile = explode("\0", $roh);
    if ($teile[count($teile) - 1] === '') {
        array_pop($teile);
    }
    if (count($teile) !== 2) {
        return false;
    }
    $a0 = basename($teile[0]);
    if ($a0 !== 'python' && strpos($a0, 'python3') !== 0) {
        return false;
    }
    $soll = db_paths()['bindir'] . '/dashboard_dienst.py';
    if ($teile[1] === $soll) {
        return true;
    }
    $r1 = @realpath($teile[1]);
    $r2 = @realpath($soll);
    return $r1 !== false && $r2 !== false && $r1 === $r2;
}

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
    // Nummernrecycling und fremde Prozesse ausschliessen.
    return db_ist_dienst($pid) ? $pid : 0;
}

/** ALLE laufenden Dienste dieses Plugins, argumentweise ueber /proc - wie
 * dienste() in bin/dienst.sh (O8, Durchgang 29.09.2026). Die PID-Datei nennt
 * nur einen; bei einem Doppelstart zeigte der Reiter Test "Ja, PID 15202",
 * waehrend zwei liefen (gemessen, Befund 8 des Oberflaechen-Pruefers). */
function db_dienst_pids()
{
    $aus = array();
    $liste = @glob('/proc/[0-9]*', GLOB_ONLYDIR);
    foreach (is_array($liste) ? $liste : array() as $d) {
        $n = (int) basename($d);
        if ($n > 0 && db_ist_dienst($n)) { $aus[$n] = $n; }
    }
    $pf = db_dienst_pid();
    if ($pf > 0) { $aus[$pf] = $pf; }
    ksort($aus);
    return array_values($aus);
}

function db_dienst_soll()
{
    return is_file(db_paths()['datadir'] . '/soll_laufen') ? 1 : 0;
}

/**
 * Liegt die Marke "Aktualisierung laeuft", und gilt sie?
 *
 * Rueckgabe array(liegt, gilt, seit, pfad). "liegt" und "gilt" getrennt, weil
 * eine liegengebliebene Marke ein anderer Befund ist als eine laufende
 * Aktualisierung - die eine sperrt die Seite, die andere nennt der Reiter Test.
 *
 * Die Datei liegt NEBEN dem Datenordner (data/plugins/<ordner>.upgrade_laeuft),
 * weil purge_installation den Ordner beim Upgrade abraeumt. preupgrade.sh legt
 * sie an, postinstall.sh entfernt sie. Die Regel ist dieselbe wie in
 * bin/dienst.sh, marke_sperrt(): gilt, solange sie hoechstens 3600 s alt ist;
 * aelter, aus der Zukunft oder unlesbar gilt sie nicht. Wer eine der beiden
 * Stellen aendert, aendert beide.
 *
 * ctype_digit waere hier falsch: ctype ist eine Erweiterung, die nicht
 * garantiert geladen ist. Deshalb preg_match.
 */
function db_upgrade_marke()
{
    $d = db_paths()['datadir'];
    $f = dirname($d) . '/' . basename($d) . '.upgrade_laeuft';
    if (!@is_file($f)) {
        return array(0, 0, -1, $f);
    }
    $roh = trim((string) @file_get_contents($f));
    if (!preg_match('/^[0-9]{1,12}$/', $roh)) {
        return array(1, 0, -1, $f);
    }
    $alter = time() - (int) $roh;
    return array(1, ($alter >= 0 && $alter <= 3600) ? 1 : 0, (int) $roh, $f);
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
    $wartezeit = max(DB_WARTEZEIT_MIN, min(DB_WARTEZEIT_MAX, (int) $wartezeit));

    $ordner = $p['datadir'] . '/befehle';
    if (!is_dir($ordner) && !@mkdir($ordner, 0775, true) && !is_dir($ordner)) {
        db_log('Warteschlange nicht anlegbar: ' . $ordner);
        return array(0, sprintf(db_t('EP.WARTESCHLANGE_ORDNER'), $ordner));
    }
    $kennung = bin2hex(random_bytes(8));
    $datei = $ordner . '/' . $kennung . '.json';
    $tmp = $datei . '.tmp';
    if (@file_put_contents($tmp, json_encode($befehl)) === false || !@rename($tmp, $datei)) {
        @unlink($tmp);
        return array(0, sprintf(db_t('EP.BEFEHL_NICHT_ABGELEGT'), $datei));
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
    return array(2, sprintf(db_t('EP.KEINE_ANTWORT'), $wartezeit));
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
    // Reihenfolge und Felder GEMESSEN an der massgeblichen Ausfuhr aus Loxone
    // Config vom 12.08.2026 (VI_Marstek Speicher (LoxBerry-Plugin)_Test.xml).
    // HintText steht VORN, und als erstes Kindelement folgt <Info>. Beides
    // fehlte bis 0.9.12: der Nachbau war aus APC-UPS uebernommen, und zwar
    // der Stand VOR dem Nachtrag vom 20.08.2026.
    $o .= '<VirtualInHttp ';
    $o .= 'HintText="" ';
    $o .= 'Title="' . db_x($kopf['title']) . '" ';
    $o .= 'Comment="' . db_x(isset($kopf['comment']) ? $kopf['comment'] : '') . '" ';
    $o .= 'Address="' . db_x(isset($kopf['address']) ? $kopf['address'] : '') . '" ';
    $o .= 'PollingTime="' . db_x(isset($kopf['polling']) ? $kopf['polling'] : '60') . '"';
    $o .= '>' . $crlf;
    // templateType: 1 = UDP-Eingang, 2 = HTTP-Eingang, 3 = Ausgang.
    $o .= "\t" . '<Info templateType="2" minVersion="17010727"/>' . $crlf;
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
        $o .= 'MaxVal="' . $max . '" ';
        // Unit ist mehr als Kosmetik: ohne das Attribut steht am virtuellen
        // Eingang eine nackte Zahl, und die Einheit findet nur, wer den
        // Kommentar aufklappt. Config legt sie beim Import als Kindelement
        // <Display Type="2" Unit="..." StateOnly="true"/> ab.
        // H9 (Durchgang 29.09.2026): ganzzahlige Zaehler tragen '<v>' - mit
        // '<v.1>' zeigte Loxone "638,0" Bausteine.
        $o .= 'Unit="' . db_x(isset($c['unit']) && $c['unit'] !== '' ? $c['unit']
                              : ('<v.1>' . (isset($c['einheit']) && $c['einheit'] !== ''
                                            ? ' ' . $c['einheit'] : ''))) . '" ';
        $o .= 'HintText=""';
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
    // Reihenfolge und Felder GEMESSEN an der massgeblichen Ausfuhr aus Loxone
    // Config vom 12.08.2026 (VO_Rasenmaeher steuern (LoxBerry-Plugin)_Test.xml).
    $o .= '<VirtualOut ';
    $o .= 'HintText="" ';
    $o .= 'Title="' . db_x($kopf['title']) . '" ';
    $o .= 'Comment="' . db_x(isset($kopf['comment']) ? $kopf['comment'] : '') . '" ';
    $o .= 'Address="' . db_x(isset($kopf['address']) ? $kopf['address'] : '') . '" ';
    $o .= 'CmdInit="" ';
    $o .= 'CloseAfterSend="false" ';
    $o .= 'CmdSep="' . db_x(isset($kopf['cmdsep']) ? $kopf['cmdsep'] : ';') . '"';
    $o .= '>' . $crlf;
    $o .= "\t" . '<Info templateType="3" minVersion="17010727"/>' . $crlf;
    foreach ($cmds as $c) {
        // Volle Reihenfolge: Title, Comment, CmdOnMethod, CmdOffMethod, CmdOn,
        // CmdOnHTTP, CmdOnPost, CmdOff, CmdOffHTTP, CmdOffPost, CmdAnswer,
        // Analog, Repeat, RepeatRate [, die vier Analogfelder], HintText.
        // Bis 0.9.12 fehlten sechs davon, und die beiden Method-Attribute
        // standen an der falschen Stelle.
        $analog = !empty($c['analog']);
        $o .= "\t" . '<VirtualOutCmd ';
        $o .= 'Title="' . db_x($c['title']) . '" ';
        $o .= 'Comment="' . db_x(isset($c['comment']) ? $c['comment'] : '') . '" ';
        $o .= 'CmdOnMethod="' . db_x(isset($c['method']) ? $c['method'] : 'GET') . '" ';
        $o .= 'CmdOffMethod="' . db_x(isset($c['method']) ? $c['method'] : 'GET') . '" ';
        $o .= 'CmdOn="' . db_x(isset($c['on']) ? $c['on'] : '') . '" ';
        $o .= 'CmdOnHTTP="" ';
        $o .= 'CmdOnPost="" ';
        $o .= 'CmdOff="' . db_x(isset($c['off']) ? $c['off'] : '') . '" ';
        $o .= 'CmdOffHTTP="" ';
        $o .= 'CmdOffPost="" ';
        $o .= 'CmdAnswer="" ';
        $o .= 'Analog="' . ($analog ? 'true' : 'false') . '" ';
        $o .= 'Repeat="0" ';
        $o .= 'RepeatRate="0" ';
        if ($analog) {
            // Ein ANALOGER VirtualOutCmd traegt vier Attribute mehr; der
            // digitale traegt sie nicht. Gemessen am 20.08.2026 an einer
            // eigenen Ausfuhr - ein analoger Befehl wird getrennt vom
            // digitalen gemessen, sie sind nicht dasselbe Element mit einem
            // anderen Haken.
            $o .= 'SourceValLow="' . (int) (isset($c['min']) ? $c['min'] : 0) . '" ';
            $o .= 'DestValLow="' . (int) (isset($c['min']) ? $c['min'] : 0) . '" ';
            $o .= 'SourceValHigh="' . (int) (isset($c['max']) ? $c['max'] : 100) . '" ';
            $o .= 'DestValHigh="' . (int) (isset($c['max']) ? $c['max'] : 100) . '" ';
        }
        $o .= 'HintText=""';
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
    } else {
        /* O13 (Durchgang 29.09.2026): Endpunkt und Anzeigeseite laden
         * LBSystem nicht, und Apache reicht LBLANG nicht durch (Regeln/03).
         * Bis 0.9.25 war die Anzeigeseite deshalb auf jeder Anlage deutsch.
         * Die dritte Quelle ist Base.Lang in config/system/general.json. */
        $home = getenv('LBHOMEDIR');
        if (!$home || !is_dir($home)) { $home = lb_wurzel_ermitteln(); }
        if ($home) {
            $g = @json_decode((string) @file_get_contents($home . '/config/system/general.json'), true);
            if (is_array($g) && isset($g['Base']['Lang']) && is_string($g['Base']['Lang'])) {
                $sprache = $g['Base']['Lang'];
            }
        }
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
        $aus[$k] = db_kacheltext($k, isset($texte[$k]) ? $texte[$k] : (isset($z['text']) ? $z['text'] : $k));
    }
    foreach (array('generisch', 'szene') as $k) {
        if (!isset($aus[$k])) {
            $aus[$k] = db_kacheltext($k, isset($texte[$k]) ? $texte[$k] : $k);
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

/** Die Kacheln einer Seite in genau der Reihenfolge, in der die Anzeige sie
 * nummeriert - der EINE Ort, an dem entschieden wird, was die Tafel sieht.
 *
 * Bis 0.9.12 stand dieser Filter nur in db_seite_daten(). Die Anzeigeseite
 * numeriert ihre Kacheln ueber die GEFILTERTE Liste (tafel.php: forEach(k, i),
 * d.dataset.i = i) und schickt diese Nummer als '&kachel='; der Endpunkt griff
 * damit aber in die UNGEFILTERTE Rohliste aus seiten.json. Jede unsichtbare
 * oder fehlerhafte Kachel VOR einer Szene verschob die Nummer um eins - und
 * weil an der verschobenen Stelle meist wieder eine Szene stand, griff auch
 * die Abweisung 'KEINE_SZENE' nicht: es lief stillschweigend die FALSCHE
 * Szene. Gemessen an drei Kacheln (unsichtbarer Schalter, 'Alles aus',
 * 'Kino'): der Druck auf 'Kino' fuehrte 'Alles aus' aus.
 *
 * Deshalb steht die Regel jetzt genau einmal, und beide Seiten lesen sie
 * hier. Wer sie aendert, aendert sie fuer Anzeige und Endpunkt zugleich.
 */
function db_kacheln_sichtbar($seite)
{
    $aus = array();
    if (!is_array($seite)) { return $aus; }
    foreach ((isset($seite['kacheln']) ? $seite['kacheln'] : array()) as $k) {
        if (!is_array($k)) { continue; }
        if (isset($k['sichtbar']) && !$k['sichtbar']) { continue; }
        $aus[] = $k;
    }
    return $aus;
}

/** Das Wetter fuer die Nutzlast - oder null, wenn es niemand braucht.
 *
 * Die Bedingung steht hier genau einmal, damit die beiden Nutzlasten
 * (aktion=seite und aktion=werte) nicht auseinanderlaufen koennen. Ohne
 * eingeschaltetes Ruhebild kostet der Aufruf nichts: er sieht nur in die
 * Konfiguration und kehrt um.
 */
function db_wetter_fuer_nutzlast($cfg, $mit_texten = true)
{
    /* Zwei Abnehmer, eine Bedingung: das Ruhebild (wenn es ueberhaupt kommen
     * kann UND die Wetterzeile gewuenscht ist) und der Ambient-Kopf. Steht
     * die Bedingung an zwei Stellen, laeuft sie auseinander - deshalb hier. */
    $fuer_ruhe    = !empty($cfg['ruhe_nach']) && !empty($cfg['ruhe_wetter']);
    $fuer_ambient = !empty($cfg['ambient']);
    if (!$fuer_ruhe && !$fuer_ambient) { return null; }
    /* Zwei Quellen, NIE gemischt: entweder die selbst gewaehlten Bausteine
     * oder Loxones Wetterdienst. Gemischt stuenden zwei Messungen
     * nebeneinander in einer Zeile, ohne dass jemand sieht, welche woher
     * kommt - eine Temperatur vom Dach neben einer aus der Wolke. */
    if (db_wetter_eigene_gewaehlt($cfg)) { return db_wetter_aus_bausteinen($cfg); }
    $w = db_wetter_jetzt();
    // Die Klartexte zu den Wetterlagen aendern sich nicht. Sie gehoeren in
    // die Struktur-Nutzlast (aktion=seite), nicht in jeden Takt - das ist
    // derselbe Grundsatz, nach dem abbild_bauen() nur Werte schickt und die
    // Struktur einmal. Die Anzeigeseite behaelt den ersten Stand.
    if ($w !== null && !$mit_texten) { unset($w['texte']); }
    return $w;
}

/** Wert und Text EINES Bausteins aus dem Abbild - egal, auf welcher Seite er
 * liegt oder ob er ueberhaupt auf einer liegt.
 *
 * Das Abbild traegt alle Bausteine der Anlage, nicht nur die der offenen
 * Seite (an der Anlage nachgemessen: 664 Bausteine, 3611 Zustaende). Der
 * Griff geht ueber die BAUSTEIN-UUID und darunter ueber den Rollennamen -
 * so baut es abbild_bauen() im Dienst. Der Griff ueber die Zustands-UUID
 * geht ins Leere, und zwar lautlos; genau daran ist die Wetterkachel in
 * 0.9.16 schon einmal gescheitert.
 *
 * Rueckgabe null, wenn es den Baustein nicht gibt oder noch kein Wert da
 * ist. Eine leere Zeile ist besser als eine erfundene Angabe.
 */
function db_baustein_wert($uuid)
{
    $uuid = (string) $uuid;
    if ($uuid === '') { return null; }
    $b = db_baustein($uuid);
    if ($b === null) { return null; }
    $abbild = db_abbild();
    $werte = isset($abbild['werte']) && is_array($abbild['werte']) ? $abbild['werte'] : array();
    if (!isset($werte[$uuid]) || !is_array($werte[$uuid])) { return null; }
    $w = $werte[$uuid];
    /* Der Hauptzustand, mit Rueckfall auf den ersten - derselbe Griff wie
     * _haupt_zustand() im Dienst, und aus demselben Grund an einer Stelle. */
    $rolle = (string) (isset($b['haupt']) ? $b['haupt'] : '');
    if ($rolle === '' || !array_key_exists($rolle, $w)) {
        $vorhanden = array_keys($w);
        if (!count($vorhanden)) { return null; }
        $rolle = (string) $vorhanden[0];
    }
    $roh = $w[$rolle];
    // Tabellen (Wetterdienst, Zeitschaltuhr) sind hier nichts zum Anzeigen.
    if (is_array($roh) || $roh === null) { return null; }
    return array(
        'name'    => (string) (isset($b['name']) ? $b['name'] : ''),
        'wert'    => is_numeric($roh) ? (float) $roh : null,
        'text'    => is_string($roh) ? $roh : (string) $roh,
        // Die Formatangabe der ANLAGE. Ausgewertet wird sie auf der
        // Anzeigeseite mit einheit_kurz() - dort steht die Regel schon, samt
        // der %%-Falle aus 0.9.16. Zweimal waere einmal zu viel.
        'einheit' => (string) (isset($b['format']) ? $b['format'] : ''),
    );
}

/** Sind eigene Wetter-Bausteine gewaehlt? Steht an EINER Stelle, weil die
 * Frage an drei Stellen gestellt wird und sonst auseinanderliefe. */
function db_wetter_eigene_gewaehlt($cfg)
{
    foreach (array('wetter_lage', 'wetter_temp', 'wetter_zusatz') as $f) {
        if ((string) (isset($cfg[$f]) ? $cfg[$f] : '') !== '') { return true; }
    }
    return false;
}

/** Die Wetterzeile aus den selbst gewaehlten Bausteinen.
 *
 * Rueckgabe null, wenn KEINER der gewaehlten Bausteine einen Wert liefert -
 * dann bleibt die Zeile leer, statt eine halbe Wahrheit zu zeigen.
 */
function db_wetter_aus_bausteinen($cfg)
{
    $lage   = db_baustein_wert(isset($cfg['wetter_lage'])   ? $cfg['wetter_lage']   : '');
    $temp   = db_baustein_wert(isset($cfg['wetter_temp'])   ? $cfg['wetter_temp']   : '');
    $zusatz = db_baustein_wert(isset($cfg['wetter_zusatz']) ? $cfg['wetter_zusatz'] : '');
    if ($lage === null && $temp === null && $zusatz === null) { return null; }
    return array(
        'quelle'     => 'bausteine',
        'lage'       => $lage !== null ? $lage['text'] : null,
        /* Ist der Temperatur-Baustein ein Textbaustein ("19.9 °C - Frisch"),
         * steht sein Text da statt einer Zahl. Das kommt an echten Anlagen
         * vor - nachgemessen an Weather4Loxone, dessen Tagesbausteine Text
         * liefern und dessen Baustein 'Wetter aktuell' leer ist. */
        'temperatur' => ($temp !== null) ? $temp['wert'] : null,
        'temptext'   => ($temp !== null && $temp['wert'] === null) ? $temp['text'] : null,
        'zusatz'     => ($zusatz !== null) ? array(
            'wert'    => $zusatz['wert'],
            'text'    => $zusatz['text'],
            'einheit' => $zusatz['einheit'],
        ) : null,
    );
}

/** Die aktuelle Wetterlage - unabhaengig davon, welche Seite gerade offen ist.
 *
 * Das Ruhebild zeigt Wetter, Uhrzeit und Datum. Die Wetterkachel liegt aber
 * vielleicht auf einer ganz anderen Seite, und der Wetterdienst steht in der
 * Strukturdatei ohnehin NICHT unter 'controls', sondern als eigener Abschnitt
 * [S, weatherServer] - das Plugin baut daraus einen eigenen Eintrag.
 * Deshalb wird er hier ueber die Kachelart gesucht, nicht ueber die Seite.
 *
 * Rueckgabe: null, wenn es keinen Wetterdienst gibt oder noch keine Werte da
 * sind. Dann zeigt das Ruhebild schlicht keine Wetterzeile - eine erfundene
 * Angabe waere schlimmer als keine.
 */
function db_wetter_jetzt()
{
    $struktur = db_struktur();
    $abbild = db_abbild();
    $werte = isset($abbild['werte']) && is_array($abbild['werte']) ? $abbild['werte'] : array();
    foreach ((isset($struktur['bausteine']) && is_array($struktur['bausteine'])
              ? $struktur['bausteine'] : array()) as $b) {
        if (!is_array($b) || (string) (isset($b['kachel']) ? $b['kachel'] : '') !== 'wetter') {
            continue;
        }
        /* Das Abbild ist nach BAUSTEIN-UUID geschluesselt und traegt darunter
         * die Rollennamen ('actual', 'forecast') - so baut es abbild_bauen()
         * ueber zustands_index(). NICHT nach Zustands-UUID: der Griff ueber
         * $b['zustaende'] geht ins Leere, und zwar lautlos, weil eine leere
         * Wetterzeile aussieht wie "noch keine Daten". Genau so stand es hier
         * im ersten Anlauf, und gefunden hat es erst eine Messung gegen eine
         * nachgebaute Nutzlast. */
        $bu = (string) (isset($b['uuid']) ? $b['uuid'] : '');
        $w = ($bu !== '' && isset($werte[$bu]) && is_array($werte[$bu]))
             ? $werte[$bu] : array();
        $jetzt = null;
        if (isset($w['actual']['eintraege'][0])) {
            $jetzt = $w['actual']['eintraege'][0];
        } elseif (isset($w['forecast']['eintraege'][0])) {
            $jetzt = $w['forecast']['eintraege'][0];
        }
        // 'continue', nicht 'return': gibt es mehrere Wetterdienste, darf der
        // erste ohne Werte die uebrigen nicht verdecken.
        if (!is_array($jetzt)) { continue; }
        return array(
            'temperatur'   => isset($jetzt['temperatur']) ? $jetzt['temperatur'] : null,
            'gefuehlt'     => isset($jetzt['gefuehlt']) ? $jetzt['gefuehlt'] : null,
            'feuchte'      => isset($jetzt['feuchte']) ? $jetzt['feuchte'] : null,
            'wind'         => isset($jetzt['wind']) ? $jetzt['wind'] : null,
            'art'          => isset($jetzt['art']) ? $jetzt['art'] : null,
            // Die Klartexte kommen aus der Anlage. Fehlt dort einer, steht die
            // Zahl da und keine erfundene Beschreibung.
            'texte'        => isset($struktur['wettertexte']) && is_array($struktur['wettertexte'])
                              ? $struktur['wettertexte'] : array(),
        );
    }
    return null;
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
    foreach (db_kacheln_sichtbar($seite) as $k) {
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
                'uuid' => '', 'titel' => $titel !== '' ? $titel : db_t('TAFEL.SZENE_TITEL'),
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
            /* Der Name des Hauptzustands. Die Anzeige braucht ihn fuer das
             * Ruhebild: dort steht je Kachel EIN Wert, und welcher der
             * gemeinte ist, weiss allein die Kacheltabelle. Ohne ihn muesste
             * die Anzeige raten - und ein geratener Wert auf einem Tablet an
             * der Wand ist schlimmer als gar keiner. */
            'haupt'    => (string) (isset($b['haupt']) ? $b['haupt'] : ''),
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
        'alter'      => db_alter($abbild),
        // O11: die Frist aus Entscheidung 4 - darueber dunkelt die Tafel ab.
        'frist'      => db_frist($abbild, $cfg),
        'tafel'      => db_tafel_lesen(),
        'wetter'     => db_wetter_fuer_nutzlast($cfg),
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
    // Ueber db_kacheln_sichtbar(), damit die Regel wirklich an EINER Stelle
    // steht. Folgenlos fuer die Nummerierung - diese Nutzlast ist nach UUID
    // geschluesselt -, aber die Werte unsichtbarer Kacheln wanderten sonst
    // bei jedem Takt mit.
    foreach (db_kacheln_sichtbar($seite) as $k) {
        $u = (string) (isset($k['uuid']) ? $k['uuid'] : '');
        if ($u === '') { continue; }
        if (isset($alle[$u])) { $aus[$u] = $alle[$u]; }
        if (isset($verlauf[$u])) { $kurven[$u] = array_values($verlauf[$u]); }
    }
    return array('ok' => (int) (isset($abbild['ok']) ? $abbild['ok'] : 0),
                 'weg' => (string) (isset($abbild['weg']) ? $abbild['weg'] : ''),
                 'alter' => db_alter($abbild), 'frist' => db_frist($abbild, $cfg), 'werte' => $aus,
                 'verlauf' => $kurven, 'tafel' => db_tafel_lesen(),
                 'wetter' => db_wetter_fuer_nutzlast($cfg, false));
}

/** PIN einer Seite pruefen.
 *
 * Diese Funktion ist die EINZIGE Stelle, an der eine PIN verglichen wird.
 *
 * C6 (Durchgang 29.09.2026): nach DB_PIN_VERSUCHE Fehlversuchen ist die PIN
 * der Seite gesperrt - fuer DB_PIN_SPERRE Sekunden, jede weitere Sperre
 * doppelt so lang (hoechstens DB_PIN_SPERRE_MAX). Waehrend der Sperre wird
 * gar nicht verglichen, auch nicht die richtige PIN (fail closed). Eine
 * richtige PIN setzt Zaehler und Stufe zurueck. Jeder Fehlversuch geht ueber
 * den Endpunkt gebremst ins Protokoll (db_abweisung), die Sperre selbst
 * ungebremst - beides mit Absender, nie mit der PIN.
 * C11: verglichen wird mit password_verify(); eine Klartext-PIN, die sich
 * noch nicht umwandeln liess, weiter mit hash_equals().
 * Rueckgabe array(ok, Grund 'PIN'|'PIN_GESPERRT'|'SEITE_UNBEKANNT', Sekunden). */
function db_pin_pruefen($schluessel, $eingabe)
{
    $s = db_seite($schluessel);
    if ($s === null) { return array(false, 'SEITE_UNBEKANNT', 0); }
    $soll = isset($s['pin']) ? $s['pin'] : '';
    if (is_int($soll)) { $soll = (string) $soll; }
    if (!is_string($soll)) { return array(false, 'PIN', 0); }
    if ($soll === '') { return array(true, '', 0); }
    $eingabe = is_string($eingabe) ? $eingabe : '';
    $p = db_paths();
    $datei = $p['datadir'] . '/pin_sperre.json';
    return db_mit_sperre($p['datadir'] . '/pin_sperre.sperre',
        function () use ($schluessel, $eingabe, $soll, $datei) {
            $st = db_json_lesen($datei);
            $e = isset($st[$schluessel]) && is_array($st[$schluessel]) ? $st[$schluessel] : array();
            $fehl  = isset($e['fehl'])  ? (int) $e['fehl']  : 0;
            $stufe = isset($e['stufe']) ? (int) $e['stufe'] : 0;
            $bis   = isset($e['bis'])   ? (int) $e['bis']   : 0;
            $jetzt = time();
            if ($bis > $jetzt) {
                return array(false, 'PIN_GESPERRT', $bis - $jetzt);
            }
            $ok = db_pin_ist_hash($soll) ? password_verify($eingabe, $soll)
                                          : ($eingabe !== '' && hash_equals($soll, $eingabe));
            if ($ok) {
                if ($fehl || $stufe || $bis) {
                    unset($st[$schluessel]);
                    db_json_schreiben($datei, $st, 0600);
                }
                return array(true, '', 0);
            }
            $fehl++;
            if ($fehl >= DB_PIN_VERSUCHE) {
                $dauer = (int) min(DB_PIN_SPERRE_MAX, DB_PIN_SPERRE * pow(2, min($stufe, 20)));
                $st[$schluessel] = array('fehl' => 0, 'stufe' => $stufe + 1, 'bis' => $jetzt + $dauer);
                db_json_schreiben($datei, $st, 0600);
                db_log(sprintf('PIN der Seite %s nach %d Fehlversuchen fuer %d s gesperrt (Sperre Nr. %d, zuletzt von %s).',
                               $schluessel, DB_PIN_VERSUCHE, $dauer, $stufe + 1, db_absender()));
                return array(false, 'PIN_GESPERRT', $dauer);
            }
            $st[$schluessel] = array('fehl' => $fehl, 'stufe' => $stufe, 'bis' => 0);
            db_json_schreiben($datei, $st, 0600);
            return array(false, 'PIN', 0);
        });
}

/** Felder, die der Endpunkt als Zustandszeile liefert.
 *
 * Je Feld: Einheit, Sprachschluessel, Untergrenze, Obergrenze, analog.
 * Die Grenzen wandern in die Loxone-Vorlage - OK kann nur 0 oder 1 werden,
 * und das soll Loxone auch wissen.
 */
function db_status_felder()
{
    /* Je Feld: Einheit, Sprachschluessel (Bedeutung im Reiter), Untergrenze,
     * Obergrenze, analog, Unit der Vorlage ('' = <v.1> mit Einheit),
     * Kommentar der Vorlage (hoechstens 40 Zeichen).
     * H9 (Durchgang 29.09.2026): alle fuenf analog - Analog="false" gehoert
     * nicht an VirtualInHttpCmd (Regeln/07:358); die Zaehler kamen als 0/1
     * an. Ganzzahlige Zaehler mit Unit '<v>'.
     * H2: ALTER mit Untergrenze -1 (Signed) und weiter Obergrenze - mit
     * MinVal=0 und MaxVal=86400 kamen "kein Abbild" und "ueber 24 h alt" in
     * Loxone als 0 an, also als "frisch" (Regeln/07). */
    return array(
        'OK'         => array('',  'DB_FELD.OK',        0,  1,          1, '<v>', 'VORLAGE.K_OK'),
        'BAUSTEINE'  => array('',  'DB_FELD.BAUSTEINE', 0,  65535,      1, '<v>', 'VORLAGE.K_BAUSTEINE'),
        'SEITEN'     => array('',  'DB_FELD.SEITEN',    0,  255,        1, '<v>', 'VORLAGE.K_SEITEN'),
        'KACHELN'    => array('',  'DB_FELD.KACHELN',   0,  65535,      1, '<v>', 'VORLAGE.K_KACHELN'),
        'ALTER'      => array('s', 'DB_FELD.ALTER',     -1, 2147483647, 1, '',    'VORLAGE.K_ALTER'),
    );
}

/** Suchtext eines Feldes mit Trennzeichen (H7, Regeln/07): '\i;NAME=\i\v' -
 * ohne das Semikolon traefe '\iALTER=' spaeter auch 'SEITENALTER='. */
function db_check($feld)
{
    return '\i;' . $feld . '=\i\v';
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
            // Kommentare hoechstens 40 Zeichen (Hinweis der Bauliste,
            // Regeln/07): der Kommentar wird zum Kachelnamen.
            'comment' => db_kuerzen(db_t($info[6]), 40),
            'check'   => db_check($feld),
            'min'     => $info[2],
            'max'     => $info[3],
            'analog'  => $info[4],
            'einheit' => $info[0],
            'unit'    => $info[5],
        );
    }
    return array('VI_DASHBOARD_STATUS.xml', db_xml_virtual_in_http(array(
        'title'   => 'Dashboard-Designer',
        'address' => 'http://' . db_host() . '/plugins/' . $p['plugin']
                   . '/index.php?token=' . $token . '&aktion=status',
        'polling' => '60',
        'comment' => db_kuerzen(sprintf(db_t('VORLAGE.KOPF'), date('d.m.Y')), 40),
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
    $cfg = db_config();
    $kopf = array(
        'title'   => 'Dashboard-Designer Steuerung',
        'address' => 'http://' . db_host(),
        'comment' => db_kuerzen(sprintf(db_t('VORLAGE.KOPF'), date('d.m.Y')), 40),
    );
    /* H5 (Durchgang 29.09.2026): Ist die Tafelsteuerung aus, stehen KEINE
     * Befehle in der Vorlage, und der Kopf sagt es - wie beim Ruhebild
     * weiter unten. Bis 0.9.25 standen alle Befehle darin, und jeder
     * Ausgang bekam 403 GESPERRT, ohne dass Loxone es zeigt (gemessen, B6
     * des MQTT-Pruefers). */
    if (empty($cfg['tafelsteuerung'])) {
        $kopf['comment'] = db_kuerzen(db_t('VORLAGE.OUT_AUS'), 40);
        return array('VQ_DASHBOARD_STEUERUNG.xml', db_xml_virtual_out($kopf, array()));
    }
    /* CmdOn traegt nur den Pfad, die Adresse steht am Ausgang (Hinweis der
     * Bauliste; so fuehrt es die massgebliche Ausfuhr VQ_weissware_*). */
    $basis = '/plugins/' . $p['plugin'] . '/index.php?token=' . $token . '&aktion=tafel';
    $cmds = array();
    foreach (db_tafel_befehle() as $b) {
        $cmds[] = array(
            'title'   => 'DASHBOARD_SEITE_' . strtoupper(preg_replace('/[^A-Za-z0-9]/', '_', $b['wert'])),
            'comment' => db_kuerzen(sprintf(db_t('VORLAGE.K_SEITE'), $b['name']), 40),
            'on'      => $basis . '&seite=' . rawurlencode($b['wert']),
            'off'     => '',
        );
    }
    $cmds[] = array(
        'title'   => 'DASHBOARD_WECKEN',
        'comment' => db_kuerzen(db_t('VORLAGE.K_WECKEN'), 40),
        'on'      => $basis . '&wach=1',
        'off'     => $basis . '&wach=0',
    );
    $cmds[] = array(
        'title'   => 'DASHBOARD_HELLIGKEIT',
        'comment' => db_kuerzen(db_t('VORLAGE.K_HELL'), 40),
        'on'      => $basis . '&hell=<v.0>',
        'off'     => '',
        'analog'  => 1,
        'min'     => 0,
        'max'     => 100,
    );
    /* Das Ruhebild nur anbieten, wenn es auch eingerichtet ist. Ein Befehl in
     * der Vorlage, den der Endpunkt mit 409 abweist, waere ein Versprechen,
     * das die Anlage nicht halten kann - und der Anwender sucht den Fehler
     * dann in Loxone Config. */
    if (!empty($cfg['ruhe_nach'])) {
        $cmds[] = array(
            'title'   => 'DASHBOARD_RUHEBILD',
            'comment' => db_kuerzen(db_t('VORLAGE.K_RUHE'), 40),
            'on'      => $basis . '&ruhe=1',
            'off'     => $basis . '&ruhe=0',
        );
    }
    return array('VQ_DASHBOARD_STEUERUNG.xml', db_xml_virtual_out($kopf, $cmds));
}


/**
 * Die Sicherungsdatei bauen (O3, Durchgang 29.09.2026).
 *
 * Hausstandard (CLAUDE.md, 9): die Datei traegt die GANZE Einrichtung - bis
 * 0.9.25 nur dashboard.json (34 Schluessel); es fehlten alle Seiten samt
 * Kacheln und PINs und der eigene Zugang, obwohl der Text am Knopf "alle
 * Einstellungen" und "Ihre Zugangsdaten" zusagte (gemessen, Befund 3 des
 * Oberflaechen-Pruefers). Jetzt:
 *   _         lesbarer Kopf (Plugin, Inhalt, Zeitpunkt, Hinweis)
 *   <34 Schluessel der Konfiguration, samt Aktionstoken>
 *   _seiten   seiten.json (Seiten, Kacheln, PIN-Pruefwerte)
 *   _zugang   eigener Zugang: adresse, port, benutzer, passwort, visu_pw
 * Das Formularmerkwort gehoert nicht hinein; Miniserver-Token und Kennung
 * auch nicht - der Dienst holt sie nach dem Zurueckspielen neu.
 * Nur Schluessel aus den Vorgaben: ein fremder Schluessel in dashboard.json
 * liesse sonst die eigene Sicherung scheitern.
 */
function db_sicherung_bauen()
{
    $cfg = db_config();
    $aus = array('_' => array(
        'plugin'   => 'dashboard',
        'inhalt'   => 'Einstellungen, Seiten samt Kacheln und PIN-Pruefwerten, eigener Zugang',
        'erstellt' => date('Y-m-d H:i:s'),
        'hinweis'  => 'Enthaelt Zugangsdaten und das Aktionstoken - wie ein Passwort behandeln.',
    ));
    foreach (array_keys(db_vorgaben()) as $k) {
        $aus[$k] = $cfg[$k];
    }
    $aus['_seiten'] = array('seiten' => db_seiten());
    $z = db_zugang();
    $zt = array();
    foreach (array('adresse', 'port', 'benutzer', 'passwort', 'visu_pw') as $k) {
        if (array_key_exists($k, $z)) { $zt[$k] = $z[$k]; }
    }
    $aus['_zugang'] = (object) $zt;
    return $aus;
}

/**
 * Einen Wert der Sicherung pruefen - dieselbe Regel wie im Formular (C5,
 * Bauart E, Durchgang 29.09.2026). Rueckgabe '' oder der Grund.
 *
 * Bis 0.9.25 stand hier $neu[$k] = $w ohne jede Pruefung: ein Token als
 * Liste oeffnete den Endpunkt fuer token=Array, ein leeres Token wurde noch
 * in derselben Anfrage neu gewuerfelt, und takt="abc", farbe=["x"],
 * miniserver="../x" standen danach in dashboard.json (gemessen unter 7.4 und
 * 8.5). Ein Schluessel ohne Regel wird abgewiesen (fail closed).
 */
function db_sicherung_wert_pruefen($k, $w)
{
    $ganz = array(
        'takt' => array(1, 30), 'http_takt' => array(5, 120),
        'wartezeit' => array(DB_WARTEZEIT_MIN, DB_WARTEZEIT_MAX),
        'rotation' => array(0, 3600), 'nacht_helligkeit' => array(0, 100),
        'verlauf_punkte' => array(10, 240), 'ruhe_kacheln' => array(0, DB_RUHE_KACHELN_MAX),
        'ruhe_hell' => array(DB_RUHE_HELL_MIN, DB_RUHE_HELL_MAX),
        'eco_hell' => array(DB_ECO_HELL_MIN, DB_ECO_HELL_MAX),
    );
    $haken = array('tls', 'http_rueckfall', 'steuerung_ein', 'vollbild', 'wach', 'haptik',
                   'verlauf', 'sse', 'tafelsteuerung', 'gesichert_schalten', 'ruhe_uhr',
                   'ruhe_wetter', 'ambient');
    if (isset($ganz[$k])) {
        return (is_int($w) && $w >= $ganz[$k][0] && $w <= $ganz[$k][1]) ? ''
            : sprintf(db_t('EINST.SICH_GRUND_BEREICH'), $ganz[$k][0], $ganz[$k][1]);
    }
    if (in_array($k, $haken, true)) {
        return ($w === 0 || $w === 1) ? '' : db_t('EINST.SICH_GRUND_HAKEN');
    }
    switch ($k) {
        case 'aktionstoken':
            if (!is_string($w)) { return db_t('EINST.SICH_GRUND_TOKEN_LISTE'); }
            return ($w === '' || db_token_gueltig($w)) ? '' : db_t('EINST.SICH_GRUND_TOKEN_MUSTER');
        case 'miniserver':
            return (is_string($w) && preg_match('/^[0-9]{1,3}\z/', $w)) ? '' : db_t('EINST.SICH_GRUND_MS');
        case 'farbe':
            return (is_string($w) && in_array($w, array('dunkel', 'hell'), true)) ? '' : db_t('EINST.FEHLER_FARBE');
        case 'nacht_von':
        case 'nacht_bis':
            return (is_string($w) && ($w === '' || preg_match('/^([01]?[0-9]|2[0-3]):[0-5][0-9]\z/', $w)))
                ? '' : db_t('EINST.SICH_GRUND_ZEIT');
        case 'ruhe_nach':
            return (is_int($w) && ($w === 0 || ($w >= DB_RUHE_NACH_MIN && $w <= DB_RUHE_NACH_MAX)))
                ? '' : sprintf(db_t('EINST.FEHLER_RUHE_NACH'), DB_RUHE_NACH_MIN, DB_RUHE_NACH_MAX);
        case 'eco_nach':
            return (is_int($w) && ($w === 0 || ($w >= DB_ECO_NACH_MIN && $w <= DB_ECO_NACH_MAX)))
                ? '' : sprintf(db_t('EINST.FEHLER_ECO_NACH'), DB_ECO_NACH_MIN, DB_ECO_NACH_MAX);
        case 'ruhe_seite':
            return (is_string($w) && ($w === '' || preg_match('/^[a-z0-9-]{1,60}\z/', $w)))
                ? '' : db_t('EINST.SICH_GRUND_SEITE');
        case 'ruhe_bild':
            return (is_string($w) && in_array($w, array('', 'jpg', 'png', 'webp'), true))
                ? '' : db_t('EINST.SICH_GRUND_BILD');
        case 'wetter_lage':
        case 'wetter_temp':
        case 'wetter_zusatz':
            if (!is_string($w)) { return db_t('EINST.SICH_GRUND_WETTER'); }
            if ($w === '') { return ''; }
            if (db_bausteine()) {
                // Dieselbe Regel wie beim Speichern: den Baustein gibt es, und
                // er traegt eine Zahl oder einen Text.
                $b = db_baustein($w);
                $art = ($b !== null && isset($b['kachel'])) ? (string) $b['kachel'] : '';
                return ($art === 'wert' || $art === 'text') ? '' : db_t('EINST.SICH_GRUND_WETTER');
            }
            // Noch keine Struktur (Umzug auf einen zweiten LoxBerry): nur die Form.
            return (strlen($w) <= 100 && !db_steuerzeichen($w)) ? '' : db_t('EINST.SICH_GRUND_WETTER');
    }
    return db_t('EINST.SICH_GRUND_UNBEKANNT');
}

/** Den Zugangsteil der Sicherung pruefen - dieselben Regeln wie im Formular. */
function db_sicherung_zugang_pruefen($z)
{
    if (!is_array($z) || ($z && array_keys($z) === range(0, count($z) - 1))) {
        return array(null, array(db_t('EINST.SICH_ZUGANG_FORM')));
    }
    $f = array();
    $aus = array();
    foreach ($z as $k => $w) {
        switch ((string) $k) {
            case 'adresse':
                if (!is_string($w) || ($w !== '' && !preg_match('/^[A-Za-z0-9][A-Za-z0-9\.\-]{0,80}\z/', $w))) {
                    $f[] = db_t('EINST.FEHLER_ZADRESSE');
                    continue 2;
                }
                break;
            case 'port':
                if (!is_int($w) || $w < 1 || $w > 65535) {
                    $f[] = db_t('EINST.FEHLER_ZPORT');
                    continue 2;
                }
                break;
            case 'benutzer':
                if (!is_string($w) || db_steuerzeichen($w) || strpbrk($w, "\"'") !== false || strlen($w) > 100) {
                    $f[] = db_t('EINST.FEHLER_ZBENUTZER');
                    continue 2;
                }
                break;
            case 'passwort':
            case 'visu_pw':
                if (!is_string($w) || strlen($w) > 256 || strpos($w, "\0") !== false) {
                    $f[] = sprintf(db_t('EINST.SICH_ZUGANG_WERT'), db_e((string) $k));
                    continue 2;
                }
                break;
            default:
                $f[] = sprintf(db_t('EINST.SICH_FREMD'), db_e((string) $k));
                continue 2;
        }
        $aus[$k] = $w;
    }
    return array($f ? null : $aus, $f);
}

/**
 * Eine Sicherungsdatei einlesen - und dabei NICHTS durchgehen lassen.
 *
 * Eine halb gueltige Datei ueberschreibt GAR NICHTS; alle Beanstandungen
 * werden gesammelt. Unbekannte und fehlende Schluessel sind Beanstandungen.
 * Jeder Wert laeuft durch db_sicherung_wert_pruefen() (C5), Seiten und
 * Kacheln durch db_seiten_pruefen() - dieselbe Pruefung wie im Designer.
 * Ein leeres Token heisst "keins gesichert": dann gilt das geltende.
 * Eine Datei ohne Kopf '_' stammt aus 0.9.24 oder frueher und traegt nur die
 * Einstellungen; sie wird angenommen, und die Seite sagt, dass Seiten und
 * Zugang unberuehrt bleiben.
 *
 * Rueckgabe: array(Konfiguration|null, Beanstandungen[], uebernommene Werte,
 *                  Teile array('seiten' => ..., 'zugang' => ...)|null, Hinweise[]).
 */
function db_sicherung_lesen($roh)
{
    $mangel = array();
    $hinweise = array();
    $daten = json_decode((string) $roh, true);
    if (!is_array($daten) || ($daten && array_keys($daten) === range(0, count($daten) - 1))) {
        return array(null, array(db_t('EINST.SICH_KEIN_JSON')), 0, null, array());
    }
    $neues_format = array_key_exists('_', $daten);
    if ($neues_format) {
        $kopf = $daten['_'];
        if (!is_array($kopf) || !isset($kopf['plugin']) || $kopf['plugin'] !== 'dashboard') {
            return array(null, array(db_t('EINST.SICH_FREMDES_PLUGIN')), 0, null, array());
        }
    }
    $vorgaben = db_vorgaben();
    $neu = $vorgaben;
    $anzahl = 0;
    foreach ($daten as $k => $w) {
        if ($neues_format && in_array($k, array('_', '_seiten', '_zugang'), true)) { continue; }
        if (!is_string($k) || !array_key_exists($k, $vorgaben)) {
            $mangel[] = sprintf(db_t('EINST.SICH_FREMD'),
                                htmlspecialchars((string) $k, ENT_QUOTES, 'UTF-8'));
            continue;
        }
        $grund = db_sicherung_wert_pruefen($k, $w);
        if ($grund !== '') {
            $mangel[] = sprintf(db_t('EINST.SICH_WERT'), db_e($k), $grund);
            continue;
        }
        $neu[$k] = $w;
        $anzahl++;
    }
    if ($anzahl === 0) {
        $mangel[] = db_t('EINST.SICH_LEER');
    }
    /* FEHLENDE Schluessel sind eine Beanstandung, kein stiller Rueckfall
     * (gemessen an VolkswagenID 0.9.11 am 03.09.2026; am 07.09.2026 ueber den
     * Bestand ausgerollt). */
    $fehlend = array();
    foreach (array_keys($vorgaben) as $fk) {
        if (!array_key_exists($fk, $daten)) {
            $fehlend[] = $fk;
        }
    }
    if ($fehlend) {
        $mangel[] = sprintf(db_t('EINST.SICH_FEHLEND'), count($fehlend),
            htmlspecialchars(implode(', ', $fehlend), ENT_QUOTES, 'UTF-8'));
    }
    // Nachtabsenkung: beide Zeiten oder keine, und nicht gleich - wie im Formular.
    if (is_string($neu['nacht_von']) && is_string($neu['nacht_bis'])
            && ((($neu['nacht_von'] === '') !== ($neu['nacht_bis'] === ''))
                || ($neu['nacht_von'] !== '' && $neu['nacht_von'] === $neu['nacht_bis']))) {
        $mangel[] = db_t('EINST.FEHLER_NACHTZEIT');
    }
    $teile = array();
    $seiten_danach = db_seiten();
    if ($neues_format) {
        if (!array_key_exists('_seiten', $daten) || !array_key_exists('_zugang', $daten)) {
            $mangel[] = db_t('EINST.SICH_TEIL_FEHLT');
        } else {
            $s = $daten['_seiten'];
            if (!is_array($s) || !array_key_exists('seiten', $s) || !is_array($s['seiten'])) {
                $mangel[] = db_t('EINST.SICH_SEITEN_FORM');
            } else {
                list($seiten, $f, $h) = db_seiten_pruefen($s['seiten'], 'sicherung');
                $mangel = array_merge($mangel, $f);
                $hinweise = array_merge($hinweise, $h);
                if ($seiten !== null) {
                    $teile['seiten'] = $seiten;
                    $seiten_danach = $seiten;
                }
            }
            list($z, $zf) = db_sicherung_zugang_pruefen($daten['_zugang']);
            $mangel = array_merge($mangel, $zf);
            if ($z !== null) { $teile['zugang'] = $z; }
        }
    } else {
        $hinweise[] = db_t('EINST.SICH_ALTES_FORMAT');
    }
    // Die Seite des Ruhebilds muss es nach dem Zurueckspielen geben.
    if (is_string($neu['ruhe_seite']) && $neu['ruhe_seite'] !== '') {
        $da = false;
        foreach ($seiten_danach as $s2) {
            if (is_array($s2) && isset($s2['schluessel']) && $s2['schluessel'] === $neu['ruhe_seite']) { $da = true; }
        }
        if (!$da) { $mangel[] = db_t('EINST.FEHLER_RUHE_SEITE'); }
    }
    // Ein leeres Token heisst "keins gesichert" - das geltende bleibt.
    if (!$mangel && $neu['aktionstoken'] === '') {
        $neu['aktionstoken'] = db_token_soll(db_config());
        $hinweise[] = db_t('EINST.SICH_TOKEN_BEHALTEN');
    }
    return array($mangel ? null : $neu, $mangel, $anzahl, $mangel ? null : $teile, $hinweise);
}

/** Die gepruefte Sicherung einspielen: Konfiguration, Seiten, Zugang. Geht
 * ein Schreibschritt schief, werden die schon geschriebenen Dateien auf den
 * Stand davor zurueckgesetzt - eine halb eingespielte Sicherung gibt es
 * nicht. Rueckgabe true/false. */
function db_sicherung_einspielen($neu, $teile)
{
    $p = db_paths();
    $vorher = array();
    foreach (array('config', 'seiten', 'geheim') as $z) {
        clearstatcache(true, $p[$z]);
        $vorher[$z] = is_file($p[$z]) ? @file_get_contents($p[$z]) : null;
    }
    $getan = array();
    $ok = db_config_speichern($neu, true);
    if ($ok) { $getan[] = 'config'; }
    if ($ok && is_array($teile) && isset($teile['seiten'])) {
        $ok = db_seiten_speichern($teile['seiten'], true);
        if ($ok) { $getan[] = 'seiten'; }
    }
    if ($ok && is_array($teile) && isset($teile['zugang'])) {
        $zneu = $teile['zugang'];
        $ok = db_zugang_aendern(function ($alt) use ($zneu) {
            foreach (array('adresse', 'port', 'benutzer', 'passwort', 'visu_pw') as $k) {
                unset($alt[$k]);
            }
            foreach ($zneu as $k => $w) { $alt[$k] = $w; }
            return $alt;
        }, true);
        if ($ok) { $getan[] = 'geheim'; }
    }
    if ($ok) { return true; }
    foreach ($getan as $z) {
        if ($vorher[$z] === null) {
            @unlink($p[$z]);
        } elseif (is_string($vorher[$z])) {
            db_datei_schreiben($p[$z], $vorher[$z], 0600);
        }
    }
    return false;
}


/* ==================================================================
 * WACHPOSTEN GEGEN FREMDE FORMULARE
 * ==================================================================
 *
 * htmlauth/ schuetzt gegen den UNANGEMELDETEN Aufruf. Es schuetzt nicht
 * dagegen, dass der Browser eines angemeldeten Bedieners ein Formular
 * abschickt, das auf einer fremden Seite steht - die Anmeldung schickt er
 * automatisch mit.
 *
 * Gemessen an Schwesterlinien (Skoda Connect 0.9.12, Midea 4.2.12, beide
 * am 27.08.2026): ein einziger fremder POST genuegte, um das Aktionstoken
 * neu zu wuerfeln. Danach beantwortet der Endpunkt jeden Virtuellen Eingang
 * mit 403 - und ein Virtueller Eingang wertet die Antwort NICHT aus. Der
 * Ausfall bleibt still.
 *
 * Der leere Fall wird eigens abgefangen: hash_equals('', '') ist in PHP
 * TRUE. Wer das Feld nicht vor dem Vergleich auf leer prueft, hat einen
 * Posten gebaut, den jeder passiert, der das Feld leer laesst.
 *
 * Das Merkmal wird aus $_POST und $_GET gelesen, nie aus $_REQUEST:
 * $_REQUEST enthaelt je nach variables_order auch Cookies.
 * ================================================================== */

function db_merkwort()
{
    static $wort = null;
    if ($wort !== null) {
        return $wort;
    }
    $pfade = db_paths();
    $verz  = isset($pfade['datadir']) ? $pfade['datadir'] : '';
    if ($verz === '') {
        return '';
    }
    $datei = $verz . '/formmerkwort';
    if (is_readable($datei)) {
        $roh = trim((string) @file_get_contents($datei));
        if (preg_match('/^[0-9a-f]{32,64}$/', $roh)) {
            $wort = $roh;
            return $wort;
        }
    }
    if (function_exists('random_bytes')) {
        $neu = bin2hex(random_bytes(24));
    } else {
        $neu = substr(hash('sha256', uniqid((string) mt_rand(), true) . microtime(true)), 0, 48);
    }
    if (!is_dir($verz)) {
        @mkdir($verz, 0775, true);
    }
    /* Rechte VOR dem Inhalt. Bis 0.9.25 sagte dieser Kommentar das schon -
     * und darunter stand chmod NACH file_put_contents. Jetzt ueber
     * db_datei_schreiben() (C4, Durchgang 29.09.2026). */
    db_datei_schreiben($datei, $neu, 0600);
    $wort = $neu;
    return $wort;
}

function db_formtoken()
{
    $grund = db_merkwort();
    return $grund === '' ? '' : hash_hmac('sha256', 'formular-v1', $grund);
}

/* Das versteckte Feld. Bewusst OHNE den Escape-Helfer des Plugins: der
 * steht bei einigen Linien in index.php und waere von hier aus nicht da.
 * Der Wert ist hexadezimal. */
function db_fmt()
{
    return '<input data-role="none" type="hidden" name="fmt" value="'
         . htmlspecialchars(db_formtoken(), ENT_QUOTES, 'UTF-8') . '">';
}

/** Rueckgabe: '' wenn die Anfrage durchgelassen wird, sonst der Grund. */
function db_wachposten()
{
    if (!isset($_SERVER['REQUEST_METHOD']) || $_SERVER['REQUEST_METHOD'] !== 'POST') {
        return '';
    }
    $soll = db_formtoken();
    $ist = isset($_POST['fmt']) ? $_POST['fmt']
         : (isset($_GET['fmt']) ? $_GET['fmt'] : null);
    if (!is_string($ist) || $ist === '' || $soll === '') {
        return db_t('WACHE.FEHLT');
    }
    if (!hash_equals($soll, $ist)) {
        return db_t('WACHE.FALSCH');
    }
    return '';
}


/* ==================================================================
 * Ergaenzungen 0.9.25 (Durchgang 29.09.2026)
 * ================================================================== */

/** Traegt $s ein Steuerzeichen? */
function db_steuerzeichen($s)
{
    return preg_match('/[\x00-\x1F\x7F]/', (string) $s) === 1;
}

/** Auf hoechstens $n Zeichen kuerzen - ohne mbstring (nicht garantiert
 * geladen, Regeln/01). Kommentare der Vorlagen: hoechstens 40 Zeichen. */
function db_kuerzen($s, $n)
{
    $s = (string) $s;
    if (preg_match('/^.{0,' . (int) $n . '}\z/us', $s)) { return $s; }
    if (preg_match('/^.{0,' . ((int) $n - 1) . '}/us', $s, $m)) { return $m[0] . "\xE2\x80\xA6"; }
    return substr($s, 0, (int) $n);
}

/** Die Beschriftung einer Kachelart in der Sprache der Oberflaeche (O13).
 * Fehlt der Schluessel, gilt der Text aus kacheln.json. */
function db_kacheltext($art, $rueckfall)
{
    $t = db_t('KACHEL.' . strtoupper((string) $art));
    return $t !== 'KACHEL.' . strtoupper((string) $art) ? $t : (string) $rueckfall;
}

/**
 * Eine Seitenliste pruefen - EINE Regel fuer den Designer, den Reiter
 * Dashboards und das Zurueckspielen (O7 und C5, Durchgang 29.09.2026).
 *
 * Bis 0.9.25 bog der Designer still zurecht: '9x9' wurde 1x1, 'Schalter1'
 * wurde 'chalter' (eine Kachelart, die es nicht gibt), 99 Spalten wurden 12,
 * und ein Anfuehrungszeichen verschwand aus dem Namen - gemeldet wurde
 * "gespeichert" (gemessen, Befund 7 des Oberflaechen-Pruefers). Jetzt wird
 * abgewiesen und gemeldet, mit denselben Saetzen wie im Reiter Dashboards.
 * Anfuehrungszeichen sind erlaubt: jede Ausgabe maskiert sie, und der
 * Erstentwurf uebernimmt Raum- und Bausteinnamen aus Loxone Config
 * unveraendert - eine Regel, die sie abwiese, wiese die eigene Sicherung ab.
 * Steuerzeichen werden abgewiesen.
 *
 * $quelle: 'designer' - die PIN kommt aus der gespeicherten Seite, nie aus
 * dem Formular; 'sicherung' - die PIN kommt aus der Datei und muss leer, ein
 * Pruefwert oder eine Klartext-PIN aus 4 bis 10 Ziffern sein (sie wird dann
 * umgewandelt). Ohne Struktur (Umzug auf einen zweiten LoxBerry) werden die
 * Schritte einer Szene nur nach ihrer Form geprueft; der Endpunkt und der
 * Dienst pruefen jeden Schritt beim Ausloesen ohnehin noch zweimal.
 * Rueckgabe array(Seiten|null, Beanstandungen[], Hinweise[]).
 */
function db_seiten_pruefen($roh, $quelle)
{
    $fehler = array();
    $hinweise = array();
    if (!is_array($roh)) {
        return array(null, array(db_t('DESIGN.FEHLER_JSON')), array());
    }
    $arten = array_keys(db_kacheltypen());
    $bausteine = db_bausteine();
    $bekannt = array();
    foreach ($bausteine as $b) {
        if (is_array($b) && isset($b['uuid']) && is_string($b['uuid'])) { $bekannt[$b['uuid']] = 1; }
    }
    $uuid_muster = '/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{16}\z/';
    $aus = array();
    $schluessel = array();
    $nr = 0;
    $ungeprueft = false;
    foreach ($roh as $s) {
        $nr++;
        if (!is_array($s)) {
            $fehler[] = sprintf(db_t('DESIGN.FEHLER_FORM'), $nr);
            continue;
        }
        $k = isset($s['schluessel']) ? $s['schluessel'] : '';
        if (!is_string($k) || !preg_match('/^[a-z0-9-]{1,60}\z/', $k)) {
            $fehler[] = sprintf(db_t('DESIGN.FEHLER_SCHLUESSEL'), $nr);
            continue;
        }
        if (isset($schluessel[$k])) {
            $fehler[] = sprintf(db_t('DESIGN.FEHLER_DOPPELT'), db_e($k));
            continue;
        }
        $schluessel[$k] = 1;
        $name = array_key_exists('name', $s) ? $s['name'] : $k;
        $name = is_string($name) ? trim($name) : '';
        if ($name === '') {
            $fehler[] = sprintf(db_t('BOARD.FEHLER_NAME'), $nr);
            continue;
        }
        if (db_steuerzeichen($name)) {
            $fehler[] = sprintf(db_t('DESIGN.FEHLER_ZEICHEN'), db_e($k));
            continue;
        }
        $sp = array_key_exists('spalten', $s) ? $s['spalten'] : 6;
        if (is_string($sp) && preg_match('/^[0-9]{1,2}\z/', $sp)) { $sp = (int) $sp; }
        if (!is_int($sp) || $sp < 2 || $sp > 12) {
            $fehler[] = sprintf(db_t('BOARD.FEHLER_SPALTEN'), db_e($name));
            continue;
        }
        if ($quelle === 'designer') {
            $alt = db_seite($k);
            $pin = ($alt !== null && isset($alt['pin'])) ? $alt['pin'] : '';
            if (is_int($pin)) { $pin = (string) $pin; }
            if (!is_string($pin)) { $pin = ''; }
        } else {
            $pin = array_key_exists('pin', $s) ? $s['pin'] : '';
            if (is_int($pin)) { $pin = (string) $pin; }
            if (!is_string($pin)
                    || ($pin !== '' && !db_pin_ist_hash($pin) && !preg_match('/^[0-9]{4,10}\z/', $pin))) {
                $fehler[] = sprintf(db_t('BOARD.FEHLER_PIN'), db_e($name));
                continue;
            }
            if ($pin !== '' && !db_pin_ist_hash($pin)) {
                $h = db_pin_hash($pin);
                if ($h === false) {
                    $fehler[] = sprintf(db_t('BOARD.FEHLER_PIN'), db_e($name));
                    continue;
                }
                $pin = $h;
            }
        }
        $kroh = array_key_exists('kacheln', $s) ? $s['kacheln'] : array();
        if (!is_array($kroh)) {
            $fehler[] = sprintf(db_t('DESIGN.FEHLER_FORM'), $nr);
            continue;
        }
        $kacheln = array();
        $kn = 0;
        foreach ($kroh as $kk) {
            $kn++;
            $wo = sprintf(db_t('DESIGN.KACHEL_ORT'), db_e($name), $kn);
            if (!is_array($kk)) {
                $fehler[] = sprintf(db_t('DESIGN.FEHLER_KACHEL_FORM'), $wo);
                continue;
            }
            $u   = array_key_exists('uuid', $kk) ? $kk['uuid'] : '';
            $t   = array_key_exists('titel', $kk) ? $kk['titel'] : '';
            $art = array_key_exists('kachel', $kk) ? $kk['kachel'] : '';
            $g   = array_key_exists('groesse', $kk) ? $kk['groesse'] : '1x1';
            $sb  = array_key_exists('sichtbar', $kk) ? $kk['sichtbar'] : 0;
            if (!is_string($u) || strlen($u) > 100 || db_steuerzeichen($u)
                    || !is_string($t) || !is_string($art) || !is_string($g)) {
                $fehler[] = sprintf(db_t('DESIGN.FEHLER_KACHEL_FORM'), $wo);
                continue;
            }
            $t = trim($t);
            if (db_steuerzeichen($t)) {
                $fehler[] = sprintf(db_t('DESIGN.FEHLER_TITEL'), $wo);
                continue;
            }
            if (!in_array($art, $arten, true)) {
                $fehler[] = sprintf(db_t('DESIGN.FEHLER_ART'), $wo, db_e($art));
                continue;
            }
            if (!preg_match('/^[1-6]x[1-3]\z/', $g)) {
                $fehler[] = sprintf(db_t('DESIGN.FEHLER_GROESSE'), $wo, db_e($g));
                continue;
            }
            if ($sb === true || $sb === 1 || $sb === '1') {
                $sb = 1;
            } elseif ($sb === false || $sb === 0 || $sb === '0' || $sb === null) {
                $sb = 0;
            } else {
                $fehler[] = sprintf(db_t('DESIGN.FEHLER_KACHEL_FORM'), $wo);
                continue;
            }
            if ($art !== 'szene' && $u !== '' && $bausteine && !isset($bekannt[$u])) {
                // Der Baustein steht nicht mehr in der Struktur. Die Kachel
                // bleibt erhalten und wird auf dem Tablet als fehlend gezeigt.
                $hinweise[] = sprintf(db_t('DESIGN.UNBEKANNT'), db_e($u));
            }
            $neu = array('uuid' => $u, 'titel' => $t, 'kachel' => $art, 'groesse' => $g, 'sichtbar' => $sb);
            if ($art === 'szene') {
                $sroh = array_key_exists('schritte', $kk) ? $kk['schritte'] : array();
                if (!is_array($sroh)) {
                    $fehler[] = sprintf(db_t('DESIGN.FEHLER_KACHEL_FORM'), $wo);
                    continue;
                }
                $schritte = array();
                foreach ($sroh as $sch) {
                    $su = is_array($sch) && isset($sch['uuid']) ? $sch['uuid'] : null;
                    $sbf = is_array($sch) && isset($sch['befehl']) ? $sch['befehl'] : null;
                    if (!is_string($su) || !is_string($sbf)) {
                        $fehler[] = sprintf(db_t('DESIGN.FEHLER_SCHRITT'), db_e($t), db_t('DESIGN.SCHRITT_FORM'));
                        continue;
                    }
                    if ($bausteine) {
                        list($sok, $sgrund) = db_befehl_erlaubt($su, $sbf);
                    } else {
                        $sok = preg_match($uuid_muster, $su) === 1
                            && preg_match('#^[A-Za-z0-9_./+:(),-]{1,120}\z#', $sbf) === 1;
                        $sgrund = db_t('DESIGN.SCHRITT_FORM');
                        $ungeprueft = true;
                    }
                    if (!$sok) {
                        $fehler[] = sprintf(db_t('DESIGN.FEHLER_SCHRITT'), db_e($t), $sgrund);
                        continue;
                    }
                    $schritte[] = array('uuid' => $su, 'befehl' => $sbf);
                }
                $neu['schritte'] = $schritte;
                $neu['uuid'] = '';
            }
            $kacheln[] = $neu;
        }
        $aus[] = array('schluessel' => $k, 'name' => $name, 'spalten' => $sp,
                       'pin' => $pin, 'kacheln' => $kacheln);
    }
    if ($ungeprueft) {
        $hinweise[] = db_t('DESIGN.SCHRITTE_UNGEPRUEFT');
    }
    return array($fehler ? null : $aus, $fehler, $hinweise);
}

/* ---------------- O1: Einmalmeldung nach der Umleitung ----------------
 * Jeder POST endet mit 303 (Regeln/04); das Ergebnis reist als Datei
 * einmalmeldung.json im Datenordner (0600) zur naechsten Seite und wird dort
 * gelesen und geloescht. Der Name ist der, den Werkzeuge/wirkungstest.py als
 * fluechtig kennt. Bauart AudiConnect 0.9.22. */
function db_einmal_schreiben($daten)
{
    $p = db_paths();
    if (!is_dir($p['datadir']) && !@mkdir($p['datadir'], 0775, true) && !is_dir($p['datadir'])) {
        return false;
    }
    $daten['zeit'] = time();
    $js = json_encode($daten, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    return $js !== false && db_datei_schreiben($p['datadir'] . '/einmalmeldung.json', $js, 0600);
}

function db_einmal_lesen()
{
    $f = db_paths()['datadir'] . '/einmalmeldung.json';
    if (!is_file($f)) { return null; }
    $d = json_decode((string) @file_get_contents($f), true);
    @unlink($f);
    if (!is_array($d) || !isset($d['zeit']) || abs(time() - (int) $d['zeit']) > 120) { return null; }
    $aus = array('meldungen' => array(), 'fehler' => array(), 'ausgabe' => '');
    foreach (array('meldungen', 'fehler') as $k) {
        if (isset($d[$k]) && is_array($d[$k])) {
            foreach ($d[$k] as $m) {
                if (is_string($m)) { $aus[$k][] = $m; }
            }
        }
    }
    if (isset($d['ausgabe']) && is_string($d['ausgabe'])) { $aus['ausgabe'] = $d['ausgabe']; }
    return $aus;
}

/* ---------------- O9: Pflichtzeilen des Reiters Test ---------------- */

/** Tragen alle POST-Formulare das Merkmal? Gemessen am GERENDERTEN HTML
 * (Klasse 12). Rueckgabe array(Stand, Text); eine leere Menge ist ein Kreuz
 * (Klasse 8). */
function db_formmerkmal_pruefen($html)
{
    $soll = db_formtoken();
    $n = 0;
    $ohne = 0;
    if (preg_match_all('#<form\b([^>]*)>(.*?)</form>#is', (string) $html, $m, PREG_SET_ORDER)) {
        foreach ($m as $f) {
            if (!preg_match('/method\s*=\s*["\']?post/i', $f[1])) { continue; }
            $n++;
            if ($soll === '' || strpos($f[2], 'name="fmt" value="' . $soll . '"') === false) { $ohne++; }
        }
    }
    if ($n === 0) { return array(0, db_t('TEST.A_FORMMERKMAL_LEER')); }
    if ($ohne > 0) { return array(0, sprintf(db_t('TEST.A_FORMMERKMAL_FEHL'), $ohne, $n)); }
    return array(1, sprintf(db_t('TEST.A_FORMMERKMAL'), $n));
}

/** Antwortet der eigene Endpunkt? Ein echter Aufruf ueber 127.0.0.1 mit
 * ?selftest=1 - drei Ausgaenge: gut (1), falsch (0), nicht messbar (-1).
 * Das Ergebnis gilt 60 s (der Reiter wird bei jedem Seitenaufruf gebaut).
 * Ohne curl und ohne $http_response_header (Fehlerklasse 2). */
function db_endpunkt_probe($token)
{
    if (!db_token_gueltig($token)) {
        return array(0, db_t('TEST.A_ENDPUNKT_KEIN_TOKEN'));
    }
    $p = db_paths();
    $puffer = $p['datadir'] . '/.endpunkt';
    $d = @json_decode((string) @file_get_contents($puffer), true);
    if (is_array($d) && isset($d['ts'], $d['stand'], $d['text']) && time() - (int) $d['ts'] >= 0
            && time() - (int) $d['ts'] < 60 && is_string($d['text'])) {
        return array((int) $d['stand'], $d['text']);
    }
    $port = isset($_SERVER['SERVER_PORT']) && preg_match('/^[0-9]{1,5}\z/', (string) $_SERVER['SERVER_PORT'])
          ? (int) $_SERVER['SERVER_PORT'] : 80;
    $tls = !empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off';
    $kontext = stream_context_create(array('ssl' => array('verify_peer' => false, 'verify_peer_name' => false)));
    $en = 0;
    $es = '';
    $s = @stream_socket_client(($tls ? 'ssl' : 'tcp') . '://127.0.0.1:' . $port, $en, $es, 2,
                               STREAM_CLIENT_CONNECT, $kontext);
    $erg = null;
    if (!$s) {
        $erg = array(-1, sprintf(db_t('TEST.A_ENDPUNKT_NICHT_MESSBAR'), '127.0.0.1:' . $port, db_e((string) $es)));
    } else {
        stream_set_timeout($s, 3);
        $pfad = '/plugins/' . rawurlencode($p['plugin']) . '/index.php?selftest=1&token=' . rawurlencode($token);
        @fwrite($s, 'GET ' . $pfad . " HTTP/1.0\r\nHost: 127.0.0.1\r\nConnection: close\r\n\r\n");
        $antwort = '';
        while (!feof($s) && strlen($antwort) < 65536) {
            $teil = @fread($s, 8192);
            if ($teil === false || $teil === '') {
                $i = stream_get_meta_data($s);
                if ($teil === false || !empty($i['timed_out'])) { break; }
                continue;
            }
            $antwort .= $teil;
        }
        @fclose($s);
        if ($antwort === '') {
            $erg = array(-1, sprintf(db_t('TEST.A_ENDPUNKT_NICHT_MESSBAR'), '127.0.0.1:' . $port, 'keine Antwort'));
        } elseif (!preg_match('#^HTTP/\d\.\d (\d{3})#', $antwort, $m)) {
            $erg = array(0, sprintf(db_t('TEST.A_ENDPUNKT_FALSCH'), 0, db_e(substr($antwort, 0, 80))));
        } else {
            $pos = strpos($antwort, "\r\n\r\n");
            $koerper = $pos !== false ? substr($antwort, $pos + 4) : '';
            if ((int) $m[1] === 200 && strpos($koerper, 'SELFTEST;OK=1') === 0) {
                $erg = array(1, sprintf(db_t('TEST.A_ENDPUNKT'), '127.0.0.1:' . $port));
            } else {
                $erg = array(0, sprintf(db_t('TEST.A_ENDPUNKT_FALSCH'), (int) $m[1],
                                        db_e(trim(substr($koerper, 0, 120)))));
            }
        }
    }
    if (is_dir($p['datadir'])) {
        @file_put_contents($puffer, json_encode(array('ts' => time(), 'stand' => $erg[0], 'text' => $erg[1])));
    }
    return $erg;
}

/** Die Cron-Eintraege dieses Plugins: glob ueber ALLE cron.*min (Regeln/04,
 * Raumklima 0.11.8). null = nicht pruefbar (keine LoxBerry-Wurzel). */
function db_cron_eintraege()
{
    $p = db_paths();
    if ($p['home'] === '') { return null; }
    $aus = array();
    $treffer = @glob($p['home'] . '/system/cron/cron.*min/' . $p['plugin']);
    foreach (is_array($treffer) ? $treffer : array() as $f) {
        if (is_dir($f) || (is_file($f) && filesize($f) > 0)) {
            $aus[] = basename(dirname($f)) . '/' . basename($f);
        }
    }
    return $aus;
}
