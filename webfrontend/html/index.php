<?php
/**
 * Dashboard-Designer - Endpunkt
 *
 * Drei Sorten Aufrufer:
 *   - die Anzeigeseite auf dem Tablet (holt Werte, setzt Befehle ab)
 *   - der Miniserver (holt eine Zustandszeile fuer virtuelle Eingaenge und
 *     schaltet ueber einen virtuellen Ausgang die Anzeigeseite um)
 *   - ein Mensch, der pruefen will, ob das Token noch stimmt
 *
 * Er liegt im unangemeldeten Bereich, damit alle drei ihn ohne Zugangsdaten
 * erreichen, und ist deshalb durch ein Token geschuetzt. Verglichen wird mit
 * hash_equals, also in gleichbleibender Zeit.
 *
 *   /plugins/<ordner>/index.php?token=<TOKEN>&aktion=<Befehl>
 *
 * Pruefend (ohne jede Wirkung):
 *   ?selftest=1            SELFTEST;OK=1;TOKEN=OK
 *
 * Lesend:
 *   status                 Zustandszeile fuer Loxone
 *   seiten                 Liste der Seiten
 *   seite    &seite=...    eine Seite samt Kacheln und Werten (JSON)
 *   werte    &seite=...    nur die Werte dieser Seite (JSON) - der Takt
 *   strom    &seite=...    dasselbe, aber geschoben (Server-Sent Events)
 *   roh                    das vollstaendige Abbild als JSON
 *   ruhebild               das Hintergrundbild des Ruhebilds (Binaerdatei)
 *   symbol   &name=<datei>.svg   ein Symbol des Plugins LoxoneIcons (SVG)
 *
 * Schaltend:
 *   befehl   &seite=...&uuid=...&befehl=...[&pin=...]
 *   szene    &seite=...&kachel=<Nr>[&pin=...]
 *            seite ist PFLICHT: die PIN haengt an der Seite, von der aus
 *            geschaltet wird - nicht am Baustein.
 *   tafel    &seite=... | &wach=0|1 | &hell=<0..100> | &ruhe=0|1
 *            Nur wenn die Tafelsteuerung eingeschaltet ist. Sie wirkt allein
 *            auf die Anzeige, nie auf ein Geraet - deshalb ohne PIN.
 *            &ruhe=1 legt das Ruhebild sofort auf, &ruhe=0 nimmt es weg.
 *
 * Der Endpunkt spricht NIE selbst mit dem Miniserver. Er liest den
 * Zwischenspeicher und legt Befehle in einer Warteschlange ab, die der
 * Dienst abarbeitet.
 */

error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
require_once __DIR__ . '/db_lib.php';

$db_cfg = db_config();
/* C5 (Durchgang 29.09.2026): das Token ist eine Zeichenkette nach Muster,
 * die Angabe aus der Adresse ebenso - VOR jeder Umwandlung geprueft. Bis
 * 0.9.25 machte (string) aus ?token[]=x und aus einem Token, das als Liste
 * in einer Sicherung stand, das Wort "Array"; beide kamen durch (gemessen,
 * Befund 5 des Code-Pruefers, 7.4 und 8.5). */
$db_soll = db_token_soll($db_cfg);
$db_ist = db_get('token');
if (!is_string($db_ist)) { $db_ist = ''; }

/* ---------------- Selbstpruefung ----------------
 *
 * Ein Token muss sich pruefen lassen, OHNE dass etwas passiert. Sonst gibt
 * es nur zwei schlechte Moeglichkeiten: entweder man schaltet wirklich, oder
 * man erfaehrt nie, ob die Adresse im Miniserver noch stimmt.
 *
 * Der Zweig steht so, dass die Token-Pruefung greift, die Wirkung aber
 * nicht: er beantwortet genau eine Frage - stimmt das Token -, macht keinen
 * Geraetekontakt, schreibt nichts und protokolliert nichts. Ein falsches
 * Token bekommt dieselbe Abweisung wie sonst auch.
 */
if (isset($_GET['selftest'])) {
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    if ($db_soll === '') {
        http_response_code(403);
        echo "SELFTEST;OK=0;ERR=KEIN_TOKEN_EINGERICHTET\n";
        exit;
    }
    if (!hash_equals($db_soll, $db_ist)) {
        http_response_code(403);
        echo "SELFTEST;OK=0;ERR=TOKEN\n";
        exit;
    }
    echo "SELFTEST;OK=1;TOKEN=OK\n";
    exit;
}

/* ---------------- Token ---------------- */
if ($db_soll === '') {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    // C12: eine beschaedigte dashboard.json ist kein "noch nie geoeffnet".
    $db_lage = db_config_lage();
    if ($db_lage === 'kaputt' || $db_lage === 'unlesbar') {
        db_abweisung('Endpunkt', 'KONFIG_KAPUTT');
        echo "FEHLER;OK=0;GRUND=KONFIG_KAPUTT\n";
        echo db_t('EP.KONFIG_KAPUTT') . "\n";
        exit;
    }
    db_abweisung('Endpunkt', 'KEIN_TOKEN_GESETZT');
    echo "FEHLER;OK=0;GRUND=KEIN_TOKEN_GESETZT\n";
    echo (array_key_exists('aktionstoken', db_json_lesen(db_paths()['config']))
          ? db_t('EP.TOKEN_UNTAUGLICH') : db_t('EP.KEIN_TOKEN')) . "\n";
    exit;
}
if (!hash_equals($db_soll, $db_ist)) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    echo "FEHLER;OK=0;GRUND=TOKEN\n";
    // C13: gebremst, mit Absender und Grund, nie mit dem Token.
    db_abweisung('Endpunkt', 'TOKEN');
    exit;
}

/* ---------------- Aktion (Weissliste) ---------------- */
$db_lesend = array('status', 'seiten', 'seite', 'werte', 'strom', 'roh', 'ruhebild', 'symbol');
$db_schaltend = array('befehl', 'szene', 'tafel');
$db_aktion = db_get('aktion');
if ($db_aktion === null) { $db_aktion = 'status'; }
if (!is_string($db_aktion) || !in_array($db_aktion, array_merge($db_lesend, $db_schaltend), true)) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    db_abweisung('Endpunkt', 'UNBEKANNTE_AKTION');
    echo "FEHLER;OK=0;GRUND=UNBEKANNTE_AKTION\n";
    echo sprintf(db_t('EP.ERLAUBT'), implode(', ', array_merge($db_lesend, $db_schaltend))) . "\n";
    exit;
}

/* Parameter: enge Muster. Was nicht passt, wird abgewiesen und benannt -
 * nicht stillschweigend zurechtgebogen. */
$db_seite = db_get('seite');
$db_seite_gut = ($db_seite === null || $db_seite === ''
                 || (is_string($db_seite) && preg_match('/^[a-z0-9-]{1,60}$/', $db_seite)));
if ($db_seite === null) { $db_seite = ''; }
if (!$db_seite_gut) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    db_abweisung('Endpunkt', 'SEITE_UNGUELTIG');
    echo "FEHLER;OK=0;GRUND=SEITE_UNGUELTIG\n";
    echo db_t('EP.SEITE_MUSTER') . "\n";
    exit;
}

function db_json_raus($daten, $code = 200)
{
    // C13 (Durchgang 29.09.2026): jeder abgewiesene Weg ins Protokoll -
    // gebremst, mit Absender und Grund (db_abweisung in db_lib.php).
    if ($code >= 400) {
        db_abweisung('Endpunkt', isset($daten['grund']) ? $daten['grund'] : ('HTTP' . (int) $code));
    }
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($daten, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/* ================= Lesende Aktionen ================= */

if ($db_aktion === 'roh') {
    db_json_raus(db_abbild());
}

/* Das Hintergrundbild des Ruhebilds.
 *
 * Es liegt im Datenordner und wird NICHT ueber den Dateinamen ausgeliefert:
 * der Pfad steht fest, und der Inhaltstyp kommt aus der Konfiguration, wo ihn
 * der Hochladevorgang nach einer Pruefung mit getimagesize() eingetragen hat.
 * Damit gibt es keinen Weg, ueber einen Parameter eine andere Datei oder
 * einen anderen Typ zu erreichen.
 *
 * Als einzige Antwort dieses Endpunkts darf sie zwischengespeichert werden -
 * ein Wandtablet soll ein unveraendertes Bild nicht alle paar Sekunden neu
 * laden. 'private' verbietet dabei den Zwischenspeicher unterwegs; das Token
 * steht in der Adresse. */
if ($db_aktion === 'ruhebild') {
    $db_typen = array('jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp');
    $db_endung = (string) (isset($db_cfg['ruhe_bild']) ? $db_cfg['ruhe_bild'] : '');
    $db_datei = db_paths()['ruhebild'];
    if ($db_endung === '' || !isset($db_typen[$db_endung]) || !is_file($db_datei)) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        header('Cache-Control: no-store');
        echo "FEHLER;OK=0;GRUND=KEIN_BILD\n";
        exit;
    }
    header('Content-Type: ' . $db_typen[$db_endung]);
    header('Content-Length: ' . (string) filesize($db_datei));
    header('Cache-Control: private, max-age=300');
    header('X-Content-Type-Options: nosniff');
    readfile($db_datei);
    exit;
}

/* S8 (0.9.26): ein Symbol des Plugins LoxoneIcons - hinter demselben Token
 * wie die Tafel.
 *
 * Erlaubt ist nur ein Dateiname nach db_symbol_name_gueltig() aus GENAU dem
 * Symbolordner (realpath, db_symbol_pfad): kein ../, kein Verweis hinaus.
 * Ausgeliefert mit image/svg+xml, nosniff und einer CSP, die jedes Skript
 * im SVG verbietet - auch dann, wenn jemand die Adresse direkt oeffnet. Die
 * Tafel bindet es als <img> ein; dort laeuft ohnehin kein Skript. */
if ($db_aktion === 'symbol') {
    $db_sname = db_get('name');
    $db_sfehl = function ($code, $grund) {
        http_response_code($code);
        header('Content-Type: text/plain; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        db_abweisung('Endpunkt', $grund);
        echo 'FEHLER;OK=0;GRUND=' . $grund . "\n";
        exit;
    };
    if (!is_string($db_sname) || !db_symbol_name_gueltig($db_sname)) {
        $db_sfehl(400, 'SYMBOL_NAME');
    }
    list($db_so, $db_slage) = db_symbol_ordner();
    if ($db_so === '') {
        $db_sfehl(404, $db_slage === 'kein_plugin' ? 'KEIN_LOXONEICONS' : 'KEINE_SYMBOLE');
    }
    $db_sp = db_symbol_pfad($db_sname);
    if ($db_sp === null) {
        // Liegt unter dem Namen etwas, das nicht in den Ordner gehoert (ein
        // Verweis hinaus, ein Ordner), ist das eine Abweisung, kein Fehlen.
        $db_roh = $db_so . DIRECTORY_SEPARATOR . $db_sname;
        $db_sfehl((is_link($db_roh) || file_exists($db_roh)) ? 403 : 404,
                  (is_link($db_roh) || file_exists($db_roh)) ? 'SYMBOL_AUSSERHALB' : 'SYMBOL_FEHLT');
    }
    clearstatcache(true, $db_sp);
    $db_sgr = (int) @filesize($db_sp);
    if ($db_sgr <= 0 || $db_sgr > DB_SYMBOL_MAX_BYTE) {
        $db_sfehl(403, 'SYMBOL_GROESSE');
    }
    header('Content-Type: image/svg+xml');
    header('Content-Length: ' . (string) $db_sgr);
    header('X-Content-Type-Options: nosniff');
    header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'");
    header('Cache-Control: private, max-age=3600');
    readfile($db_sp);
    exit;
}

if ($db_aktion === 'seiten') {
    $aus = array();
    foreach (db_seiten() as $s) {
        if (!is_array($s)) { continue; }
        $aus[] = array('schluessel' => (string) (isset($s['schluessel']) ? $s['schluessel'] : ''),
                       'name' => (string) (isset($s['name']) ? $s['name'] : ''),
                       'kacheln' => count(isset($s['kacheln']) && is_array($s['kacheln'])
                                          ? $s['kacheln'] : array()),
                       'pin' => !empty($s['pin']) ? 1 : 0);
    }
    db_json_raus(array('ok' => 1, 'seiten' => $aus));
}

if ($db_aktion === 'seite' || $db_aktion === 'werte') {
    if ($db_seite === '') {
        db_json_raus(array('ok' => 0, 'grund' => 'SEITE_FEHLT',
                           'meldung' => db_t('EP.SEITE_FEHLT')), 400);
    }
    $d = ($db_aktion === 'seite') ? db_seite_daten($db_seite) : db_seite_werte($db_seite);
    if ($d === null) {
        db_json_raus(array('ok' => 0, 'grund' => 'SEITE_UNBEKANNT',
                           'meldung' => db_t('EP.SEITE_UNBEKANNT')), 404);
    }
    db_json_raus($d);
}

/* ---------------- Werte schieben statt abfragen ----------------
 *
 * Bei drei Tablets und einem Takt von zwei Sekunden sind das rund 130.000
 * PHP-Aufrufe am Tag, von denen die allermeisten dasselbe zurueckgeben. Mit
 * Server-Sent Events bleibt eine Verbindung offen, und geschickt wird nur,
 * wenn sich wirklich etwas geaendert hat.
 *
 * Zwei Vorsichtsmassnahmen, beide noetig:
 *   - Der Lauf endet nach fuenf Minuten von selbst. Ein PHP-Prozess bindet
 *     einen Apache-Arbeiter; ihn unbegrenzt zu halten, waere der sichere Weg
 *     in "keine freien Arbeiter mehr". EventSource verbindet von selbst neu.
 *   - Gelesen wird nur die Datei, die der Dienst ohnehin schreibt. Der
 *     Endpunkt spricht auch hier nicht mit dem Miniserver.
 */
if ($db_aktion === 'strom') {
    if ($db_seite === '') {
        db_json_raus(array('ok' => 0, 'grund' => 'SEITE_FEHLT',
                           'meldung' => db_t('EP.SEITE_FEHLT')), 400);
    }
    if (db_seite($db_seite) === null) {
        db_json_raus(array('ok' => 0, 'grund' => 'SEITE_UNBEKANNT',
                           'meldung' => db_t('EP.SEITE_UNBEKANNT')), 404);
    }
    header('Content-Type: text/event-stream; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Accel-Buffering: no');
    while (ob_get_level() > 0) { ob_end_flush(); }
    ignore_user_abort(false);
    @set_time_limit(0);
    $db_takt = max(1, min(30, (int) $db_cfg['takt']));
    $db_letzte = '';
    $db_ende = time() + 300;
    echo "retry: 5000\n\n";
    @flush();
    while (time() < $db_ende) {
        if (connection_aborted()) { break; }
        $d = db_seite_werte($db_seite);
        if ($d === null) { break; }
        $j = json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($j !== $db_letzte) {
            $db_letzte = $j;
            echo 'data: ' . $j . "\n\n";
        } else {
            // Ein Doppelpunkt am Zeilenanfang ist ein Kommentar. Er haelt die
            // Verbindung durch Zwischenstationen offen, ohne Daten zu senden.
            echo ": still\n\n";
        }
        @flush();
        sleep($db_takt);
    }
    echo "event: ende\ndata: {}\n\n";
    @flush();
    exit;
}

if ($db_aktion === 'status') {
    header('Content-Type: text/plain; charset=utf-8');
    // Der Miniserver holt die Zeile im Takt. Ohne no-store duerfte eine
    // Zwischenstation sie zwischenspeichern - eine eingefrorene Anzeige saehe
    // dann aus wie "laeuft".
    header('Cache-Control: no-store');
    /* C7/H1 (Durchgang 29.09.2026, Entscheidung 4):
     *   - OK=0, sobald ALTER die Frist uebersteigt (3 x max(takt, 30 s), im
     *     Notnagel 3 x http_takt) oder das Alter unbrauchbar ist (-1);
     *     ALTER bleibt daneben unveraendert. Bis 0.9.25 blieb OK=1 bei jedem
     *     Ausfall - Miniserver weg, Dienst abgestuerzt oder angehalten
     *     (gemessen: ALTER=90000 mit OK=1, B1 des MQTT-Pruefers).
     *   - Ohne Abbild antwortet der Endpunkt mit 503 und nennt den Grund
     *     (Regeln/07, "auch vor dem ersten Abruf"). OK ist hier ein
     *     Gesundheitsmerker: mit Abbild bleibt es bei 200 und OK=0, damit
     *     ein Alarm auf OK=0 ausloesen kann (Ausnahme BatterieBMS, Regeln/07).
     *   - Die Zeile entsteht aus db_status_felder() - eine Stelle (H8). */
    $db_w = db_status_werte();
    if (!db_abbild()) {
        http_response_code(503);
        echo db_status_zeile($db_w) . ";GRUND=KEIN_ABBILD\n";
        exit;
    }
    echo db_status_zeile($db_w) . "\n";
    exit;
}

/* ================= Steuerung der Anzeigeseite ================= */

header('Content-Type: application/json; charset=utf-8');

if ($db_aktion === 'tafel') {
    if (empty($db_cfg['tafelsteuerung'])) {
        db_json_raus(array('ok' => 0, 'grund' => 'GESPERRT',
                           'meldung' => db_t('EP.TAFEL_GESPERRT')), 403);
    }
    /* ERST alles pruefen, DANN einmal schreiben. Bis 0.9.12 schrieb jeder
     * Zweig einzeln: ein Aufruf mit gueltiger Seite und ungueltigem 'hell'
     * hatte die Seite schon umgeschaltet, bevor er mit 400 abgewiesen wurde -
     * eine abgewiesene Anfrage, die trotzdem gewirkt hat. */
    $db_getan = array();
    $db_felder = array();
    if ($db_seite !== '') {
        if (db_seite($db_seite) === null) {
            db_json_raus(array('ok' => 0, 'grund' => 'SEITE_UNBEKANNT',
                               'meldung' => db_t('EP.SEITE_UNBEKANNT')), 404);
        }
        $db_felder['seite'] = $db_seite;
        $db_getan[] = 'seite=' . $db_seite;
    }
    // C5: jede Angabe VOR der Umwandlung auf ihre Art geprueft.
    $db_v = db_get('wach');
    if ($db_v !== null) {
        if (!is_string($db_v) || !preg_match('/^[01]$/', $db_v)) {
            db_json_raus(array('ok' => 0, 'grund' => 'WERT_UNGUELTIG',
                               'meldung' => db_t('EP.WACH')), 400);
        }
        $db_felder['wach'] = (int) $db_v;
        $db_getan[] = 'wach=' . (int) $db_v;
    }
    $db_h = db_get('hell');
    if ($db_h !== null) {
        if (!is_string($db_h) || !preg_match('/^[0-9]{1,3}$/', $db_h) || (int) $db_h > 100) {
            db_json_raus(array('ok' => 0, 'grund' => 'WERT_UNGUELTIG',
                               'meldung' => db_t('EP.HELL')), 400);
        }
        $db_felder['hell'] = (int) $db_h;
        $db_getan[] = 'hell=' . (int) $db_h;
    }
    $db_r = db_get('ruhe');
    if ($db_r !== null) {
        if (!is_string($db_r) || !preg_match('/^[01]$/', $db_r)) {
            db_json_raus(array('ok' => 0, 'grund' => 'WERT_UNGUELTIG',
                               'meldung' => db_t('EP.RUHE')), 400);
        }
        if (empty($db_cfg['ruhe_nach'])) {
            /* Nicht stillschweigend nichts tun: wer das Ruhebild aus Loxone
             * schaltet und es ist gar nicht eingerichtet, sucht sonst am
             * falschen Ende. */
            db_json_raus(array('ok' => 0, 'grund' => 'RUHE_AUS',
                               'meldung' => db_t('EP.RUHE_AUS')), 409);
        }
        $db_felder['ruhe'] = (int) $db_r;
        $db_getan[] = 'ruhe=' . (int) $db_r;
    }
    if (!$db_getan) {
        db_json_raus(array('ok' => 0, 'grund' => 'NICHTS_ANGEGEBEN',
                           'meldung' => db_t('EP.NICHTS')), 400);
    }
    /* C8 (Durchgang 29.09.2026): ein misslungenes Schreiben ist ein Fehler.
     * Bis 0.9.25 wurde der Rueckgabewert verworfen und {"ok":1} gemeldet,
     * obwohl tafel.json unveraendert blieb (gemessen, E8). */
    if (!db_tafel_befehl($db_felder)) {
        db_json_raus(array('ok' => 0, 'grund' => 'SCHREIBFEHLER',
                           'meldung' => db_t('EP.SCHREIBFEHLER')), 500);
    }
    db_json_raus(array('ok' => 1, 'meldung' => implode(', ', $db_getan)));
}

/* ================= Schaltende Aktionen ================= */

if (empty($db_cfg['steuerung_ein'])) {
    db_json_raus(array('ok' => 0, 'grund' => 'GESPERRT',
                       'meldung' => db_t('EP.SCHALTEN_GESPERRT')), 403);
}

/* Von WELCHER Seite kommt der Befehl?
 *
 * Bis 0.9.0 wurde die erstbeste Seite genommen, auf der der Baustein steht.
 * Das war eine Luecke: liegt ein Tuerschloss auf Seite "flur" ohne PIN und
 * zusaetzlich auf Seite "sicherheit" mit PIN, entschied die Reihenfolge in
 * der Konfiguration darueber, ob eine PIN verlangt wird.
 *
 * Deshalb ist &seite= PFLICHT fuer schaltende Aufrufe. Geprueft wird genau
 * die PIN der Seite, von der der Befehl kommt - mit db_pin_stimmt() aus der
 * Bibliothek, nicht mit einer zweiten Kopie desselben Vergleichs.
 */
if ($db_seite === '') {
    db_json_raus(array('ok' => 0, 'grund' => 'SEITE_FEHLT',
                       'meldung' => db_t('EP.SEITE_PFLICHT')), 400);
}
$db_s = db_seite($db_seite);
if ($db_s === null) {
    db_json_raus(array('ok' => 0, 'grund' => 'SEITE_UNBEKANNT',
                       'meldung' => db_t('EP.SEITE_UNBEKANNT')), 404);
}
/* C6 (Durchgang 29.09.2026): nach DB_PIN_VERSUCHE Fehlversuchen ist die PIN
 * der Seite gesperrt; waehrend der Sperre wird gar nicht verglichen. Jeder
 * Fehlversuch geht ueber db_json_raus() gebremst ins Protokoll. */
$db_pin = db_get('pin');
list($db_pin_ok, $db_pin_grund, $db_pin_rest) = db_pin_pruefen($db_seite, is_string($db_pin) ? $db_pin : '');
if (!$db_pin_ok) {
    if ($db_pin_grund === 'PIN_GESPERRT') {
        db_json_raus(array('ok' => 0, 'grund' => 'PIN_GESPERRT', 'rest' => (int) $db_pin_rest,
                           'meldung' => sprintf(db_t('EP.PIN_GESPERRT'), (int) $db_pin_rest)), 403);
    }
    db_json_raus(array('ok' => 0, 'grund' => 'PIN',
                       'meldung' => db_t('EP.PIN')), 403);
}
/* Die ROHLISTE - wie bis 0.9.12. Sie bedient 'aktion=befehl', und der sucht
 * ueber die UUID; ein Index ist dabei nie im Spiel, die Rohliste war dort
 * also nie falsch.
 *
 * Der Szenen-Zweig weiter unten nimmt dagegen die GEFILTERTE Liste, denn er
 * greift ueber die laufende Nummer - und die vergibt die Anzeigeseite ueber
 * die gefilterte. Genau dieser Unterschied hat bis 0.9.12 die falsche Szene
 * ausgeloest.
 *
 * In einem Zwischenstand von 0.9.13 stand hier die gefilterte Liste fuer
 * BEIDE Aktionen. Das behob denselben Fehler, nahm aber nebenbei einen Weg
 * weg, der nie kaputt war: eine auf 'unsichtbar' gestellte Kachel liess sich
 * danach auch ueber 'aktion=befehl' nicht mehr schalten - aus Loxone, aus
 * einem Lesezeichen, aus einem Skript. Eine Berichtigung darf nur den Fehler
 * beheben, den sie behebt. */
$db_kacheln = isset($db_s['kacheln']) && is_array($db_s['kacheln']) ? $db_s['kacheln'] : array();

/* ---------------- Szene: mehrere Befehle auf einen Druck ---------------- */

if ($db_aktion === 'szene') {
    $db_nr = db_get('kachel');
    if (!is_string($db_nr) || !preg_match('/^[0-9]{1,4}$/', $db_nr)) {
        db_json_raus(array('ok' => 0, 'grund' => 'KACHEL_UNGUELTIG',
                           'meldung' => db_t('EP.KACHEL')), 400);
    }
    $db_nr = (int) $db_nr;
    /* Die GEFILTERTE Liste - die Anzeigeseite nummeriert ueber sie. Die
     * Begruendung steht bei db_kacheln_sichtbar() in db_lib.php. */
    $db_kacheln = db_kacheln_sichtbar($db_s);
    if (!isset($db_kacheln[$db_nr]) || !is_array($db_kacheln[$db_nr])
            || (string) (isset($db_kacheln[$db_nr]['kachel'])
                         ? $db_kacheln[$db_nr]['kachel'] : '') !== 'szene') {
        db_json_raus(array('ok' => 0, 'grund' => 'KEINE_SZENE',
                           'meldung' => db_t('EP.KEINE_SZENE')), 404);
    }
    $db_schritte = db_szene_schritte($db_kacheln[$db_nr]);
    if (!$db_schritte) {
        db_json_raus(array('ok' => 0, 'grund' => 'SZENE_LEER',
                           'meldung' => db_t('EP.SZENE_LEER')), 400);
    }
    // JEDER Schritt wird gegen dieselbe Positivliste geprueft. Eine Szene ist
    // keine Abkuerzung an der Pruefung vorbei.
    foreach ($db_schritte as $db_sch) {
        if (!preg_match('/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{16}$/',
                        $db_sch['uuid'])) {
            db_json_raus(array('ok' => 0, 'grund' => 'UUID_UNGUELTIG',
                               'meldung' => db_t('EP.SCHRITT_UUID')), 400);
        }
        list($db_ok, $db_grund) = db_befehl_erlaubt($db_sch['uuid'], $db_sch['befehl']);
        if (!$db_ok) {
            db_json_raus(array('ok' => 0, 'grund' => 'BEFEHL_NICHT_VORGESEHEN',
                               'meldung' => $db_grund), 400);
        }
        // Ein gesicherter Baustein bleibt auch in einer Szene gesichert.
        $db_sb = db_baustein($db_sch['uuid']);
        if ($db_sb !== null && !empty($db_sb['gesichert'])
                && (empty($db_cfg['gesichert_schalten']) || !db_visu_da())) {
            db_json_raus(array('ok' => 0, 'grund' => 'GESICHERT',
                               'meldung' => db_t('EP.SZENE_GESICHERT')), 403);
        }
    }
    /* Erst JETZT die Frage, ob der Dienst laeuft.
     *
     * Die Reihenfolge ist nicht beliebig: eine unbrauchbare Anfrage - eine
     * Kachelnummer, an der keine Szene steht, ein Befehl, den der Typ nicht
     * kennt - ist auch dann unbrauchbar, wenn der Dienst laeuft. Stuende die
     * Dienstpruefung vorn, bekaeme der Anwender "Der Dienst laeuft nicht" auf
     * eine Anfrage, die selbst mit laufendem Dienst abgewiesen wuerde, und
     * suchte an der falschen Stelle.
     */
    if (db_dienst_pid() === 0) {
        db_json_raus(array('ok' => 0, 'grund' => 'DIENST_LAEUFT_NICHT',
                           'meldung' => db_t('EP.DIENST_AUS')), 503);
    }
    list($db_erg, $db_meldung) = db_befehl_absetzen(
        array('befehle' => $db_schritte, 'seite' => $db_seite));
    db_json_raus(array('ok' => $db_erg, 'meldung' => $db_meldung),
                 $db_erg === 0 ? 500 : 200);
}

/* ---------------- Einzelner Befehl ---------------- */

$db_uuid = db_get('uuid');
$db_befehl = db_get('befehl');
if (!is_string($db_uuid)) { $db_uuid = ''; }
if (!is_string($db_befehl)) { $db_befehl = ''; }

if (!preg_match('/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{16}$/', $db_uuid)) {
    db_json_raus(array('ok' => 0, 'grund' => 'UUID_UNGUELTIG',
                       'meldung' => db_t('EP.UUID')), 400);
}
/* Zeichenvorrat des Befehls.
 *
 * Bis 0.9.0 fehlten Klammern und Komma. Der ColorPickerV2 schickt aber
 * genau solche Befehle: hsv(240,100,80) fuer eine Farbe, temp(4000) fuer
 * ein Weiss. Jeder Farbwechsel lief damit in HTTP 400 - der Baustein stand
 * in der Kacheltabelle, war aber nicht bedienbar.
 *
 * Erlaubt sind jetzt zusaetzlich ( ) und das Komma - fuer hsv(), temp()
 * und lumitech(). Weiterhin NICHT
 * erlaubt sind & ? # % " ' < > und der Backslash: der Befehl wandert in die
 * Adresse einer Anfrage an den Miniserver, und ein & oder ? dort haenge
 * einen zweiten Parameter an. Die Laenge steigt auf 120 Zeichen, weil
 * hsv(...) mit drei dreistelligen Zahlen schon 18 braucht und Textbefehle
 * laenger werden.
 *
 * Die eigentliche Sicherung ist ohnehin die zweite Pruefung weiter unten:
 * db_befehl_erlaubt() laesst nur durch, was die Kacheltabelle fuer genau
 * diesen Bausteintyp nennt. Diese Regel hier haelt nur Zeichen fern, die
 * die Adresse zerlegen wuerden.
 */
if (!preg_match('#^[A-Za-z0-9_./+:(),-]{1,120}$#', $db_befehl)) {
    db_json_raus(array('ok' => 0, 'grund' => 'BEFEHL_UNGUELTIG',
                       'meldung' => db_t('EP.BEFEHL_ZEICHEN')), 400);
}

$db_gefunden = false;
foreach ($db_kacheln as $db_k) {
    if (is_array($db_k) && (string) (isset($db_k['uuid']) ? $db_k['uuid'] : '') === $db_uuid) {
        $db_gefunden = true;
        break;
    }
}
if (!$db_gefunden) {
    db_json_raus(array('ok' => 0, 'grund' => 'NICHT_AUF_DIESER_SEITE',
                       'meldung' => sprintf(db_t('EP.NICHT_AUF_SEITE'), $db_seite)), 403);
}

list($db_ok, $db_grund) = db_befehl_erlaubt($db_uuid, $db_befehl);
if (!$db_ok) {
    db_json_raus(array('ok' => 0, 'grund' => 'BEFEHL_NICHT_VORGESEHEN',
                       'meldung' => $db_grund), 400);
}

/* Gesicherte Bausteine.
 *
 * Loxone verlangt fuer einen Baustein mit gesetztem isSecured das
 * Visualisierungs-Passwort ("Secured Commands", [K, Seite 14-15]). Der Dienst
 * kann das seit 0.9.7 - aber nur, wenn beides zutrifft: der Haken im Reiter
 * Einstellungen ist gesetzt UND ein Visualisierungs-Passwort ist hinterlegt.
 * Beides fehlt ab Werk.
 *
 * Fail closed: fehlt eines von beiden, wird abgewiesen und gesagt, was fehlt -
 * statt zu senden und den Anwender in eine nichtssagende Antwort des
 * Miniservers laufen zu lassen. Die Kachel kennzeichnet solche Bausteine
 * ausserdem mit einem Schloss.
 */
$db_b = db_baustein($db_uuid);
if ($db_b !== null && !empty($db_b['gesichert'])) {
    if (empty($db_cfg['gesichert_schalten'])) {
        db_json_raus(array('ok' => 0, 'grund' => 'GESICHERT',
                           'meldung' => db_t('EP.GESICHERT')), 403);
    }
    if (!db_visu_da()) {
        db_json_raus(array('ok' => 0, 'grund' => 'GESICHERT_OHNE_PASSWORT',
                           'meldung' => db_t('EP.GESICHERT_OHNE_PW')), 403);
    }
}

/* Erst JETZT die Frage, ob der Dienst laeuft.
 *
 * Die Reihenfolge ist nicht beliebig: eine unbrauchbare Anfrage - eine
 * Kachelnummer, an der keine Szene steht, ein Befehl, den der Typ nicht
 * kennt - ist auch dann unbrauchbar, wenn der Dienst laeuft. Stuende die
 * Dienstpruefung vorn, bekaeme der Anwender "Der Dienst laeuft nicht" auf
 * eine Anfrage, die selbst mit laufendem Dienst abgewiesen wuerde, und
 * suchte an der falschen Stelle.
 */
if (db_dienst_pid() === 0) {
    db_json_raus(array('ok' => 0, 'grund' => 'DIENST_LAEUFT_NICHT',
                       'meldung' => db_t('EP.DIENST_AUS')), 503);
}

list($db_erg, $db_meldung) = db_befehl_absetzen(
    array('uuid' => $db_uuid, 'befehl' => $db_befehl, 'seite' => $db_seite));
db_json_raus(array('ok' => $db_erg, 'meldung' => $db_meldung),
             $db_erg === 0 ? 500 : 200);
