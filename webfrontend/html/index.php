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
 *
 * Schaltend:
 *   befehl   &seite=...&uuid=...&befehl=...[&pin=...]
 *   szene    &seite=...&kachel=<Nr>[&pin=...]
 *            seite ist PFLICHT: die PIN haengt an der Seite, von der aus
 *            geschaltet wird - nicht am Baustein.
 *   tafel    &seite=... | &wach=0|1 | &hell=<0..100>
 *            Nur wenn die Tafelsteuerung eingeschaltet ist. Sie wirkt allein
 *            auf die Anzeige, nie auf ein Geraet - deshalb ohne PIN.
 *
 * Der Endpunkt spricht NIE selbst mit dem Miniserver. Er liest den
 * Zwischenspeicher und legt Befehle in einer Warteschlange ab, die der
 * Dienst abarbeitet.
 */

error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
require_once __DIR__ . '/db_lib.php';

$db_cfg = db_config();
$db_soll = (string) $db_cfg['aktionstoken'];
$db_ist = isset($_GET['token']) ? (string) $_GET['token'] : '';

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
    echo "FEHLER;OK=0;GRUND=KEIN_TOKEN_GESETZT\n";
    echo "Die Plugin-Oberflaeche wurde noch nie geoeffnet - es gibt noch kein Token.\n";
    exit;
}
if (!hash_equals($db_soll, $db_ist)) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    echo "FEHLER;OK=0;GRUND=TOKEN\n";
    // Gebremst protokollieren: eine Dauerstoerung soll die Logdatei nicht
    // unlesbar machen, aber ein falsches Token gehoert gesehen.
    db_log_gebremst('token', 'Aufruf mit falschem Token abgewiesen.');
    exit;
}

/* ---------------- Aktion (Weissliste) ---------------- */
$db_lesend = array('status', 'seiten', 'seite', 'werte', 'strom', 'roh');
$db_schaltend = array('befehl', 'szene', 'tafel');
$db_aktion = isset($_GET['aktion']) ? (string) $_GET['aktion'] : 'status';
if (!in_array($db_aktion, array_merge($db_lesend, $db_schaltend), true)) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    echo "FEHLER;OK=0;GRUND=UNBEKANNTE_AKTION\n";
    echo 'Erlaubt sind: ' . implode(', ', array_merge($db_lesend, $db_schaltend)) . "\n";
    exit;
}

/* Parameter: enge Muster. Was nicht passt, wird abgewiesen und benannt -
 * nicht stillschweigend zurechtgebogen. */
$db_seite = isset($_GET['seite']) ? (string) $_GET['seite'] : '';
if ($db_seite !== '' && !preg_match('/^[a-z0-9-]{1,60}$/', $db_seite)) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    echo "FEHLER;OK=0;GRUND=SEITE_UNGUELTIG\n";
    echo "Ein Seitenschluessel besteht aus Kleinbuchstaben, Ziffern und Bindestrich.\n";
    exit;
}

function db_json_raus($daten, $code = 200)
{
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
                           'meldung' => 'Es wurde keine Seite angegeben.'), 400);
    }
    $d = ($db_aktion === 'seite') ? db_seite_daten($db_seite) : db_seite_werte($db_seite);
    if ($d === null) {
        db_json_raus(array('ok' => 0, 'grund' => 'SEITE_UNBEKANNT',
                           'meldung' => 'Diese Seite gibt es nicht.'), 404);
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
                           'meldung' => 'Es wurde keine Seite angegeben.'), 400);
    }
    if (db_seite($db_seite) === null) {
        db_json_raus(array('ok' => 0, 'grund' => 'SEITE_UNBEKANNT',
                           'meldung' => 'Diese Seite gibt es nicht.'), 404);
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
    $a = db_abbild();
    $seiten = db_seiten();
    $kacheln = 0;
    foreach ($seiten as $s) {
        $kacheln += count(isset($s['kacheln']) && is_array($s['kacheln']) ? $s['kacheln'] : array());
    }
    printf("DASHBOARD;OK=%d;BAUSTEINE=%d;SEITEN=%d;KACHELN=%d;ALTER=%d\n",
        (int) (!empty($a['ok'])), count(db_bausteine()), count($seiten), $kacheln, db_alter());
    exit;
}

/* ================= Steuerung der Anzeigeseite ================= */

header('Content-Type: application/json; charset=utf-8');

if ($db_aktion === 'tafel') {
    if (empty($db_cfg['tafelsteuerung'])) {
        db_json_raus(array('ok' => 0, 'grund' => 'GESPERRT',
                           'meldung' => 'Die Tafelsteuerung ist im Reiter Einstellungen '
                                      . 'abgeschaltet.'), 403);
    }
    $db_getan = array();
    if ($db_seite !== '') {
        if (db_seite($db_seite) === null) {
            db_json_raus(array('ok' => 0, 'grund' => 'SEITE_UNBEKANNT',
                               'meldung' => 'Diese Seite gibt es nicht.'), 404);
        }
        db_tafel_setzen('seite', $db_seite);
        $db_getan[] = 'seite=' . $db_seite;
    }
    if (isset($_GET['wach'])) {
        if (!preg_match('/^[01]$/', (string) $_GET['wach'])) {
            db_json_raus(array('ok' => 0, 'grund' => 'WERT_UNGUELTIG',
                               'meldung' => 'wach ist 0 oder 1.'), 400);
        }
        db_tafel_setzen('wach', (int) $_GET['wach']);
        $db_getan[] = 'wach=' . (int) $_GET['wach'];
    }
    if (isset($_GET['hell'])) {
        $db_h = (string) $_GET['hell'];
        if (!preg_match('/^[0-9]{1,3}$/', $db_h) || (int) $db_h > 100) {
            db_json_raus(array('ok' => 0, 'grund' => 'WERT_UNGUELTIG',
                               'meldung' => 'hell ist eine Zahl von 0 bis 100.'), 400);
        }
        db_tafel_setzen('hell', (int) $db_h);
        $db_getan[] = 'hell=' . (int) $db_h;
    }
    if (!$db_getan) {
        db_json_raus(array('ok' => 0, 'grund' => 'NICHTS_ANGEGEBEN',
                           'meldung' => 'Erwartet wird seite=, wach= oder hell=.'), 400);
    }
    db_json_raus(array('ok' => 1, 'meldung' => implode(', ', $db_getan)));
}

/* ================= Schaltende Aktionen ================= */

if (empty($db_cfg['steuerung_ein'])) {
    db_json_raus(array('ok' => 0, 'grund' => 'GESPERRT',
                       'meldung' => 'Das Schalten ist im Reiter Einstellungen gesperrt.'), 403);
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
                       'meldung' => 'Fuer einen Befehl ist &seite= Pflicht - die PIN '
                                  . 'haengt an der Seite, von der aus geschaltet wird.'), 400);
}
$db_s = db_seite($db_seite);
if ($db_s === null) {
    db_json_raus(array('ok' => 0, 'grund' => 'SEITE_UNBEKANNT',
                       'meldung' => 'Diese Seite gibt es nicht.'), 404);
}
if (!db_pin_stimmt($db_seite, isset($_GET['pin']) ? (string) $_GET['pin'] : '')) {
    db_json_raus(array('ok' => 0, 'grund' => 'PIN',
                       'meldung' => 'Die PIN stimmt nicht.'), 403);
}
$db_kacheln = isset($db_s['kacheln']) && is_array($db_s['kacheln']) ? $db_s['kacheln'] : array();

/* ---------------- Szene: mehrere Befehle auf einen Druck ---------------- */

if ($db_aktion === 'szene') {
    $db_nr = isset($_GET['kachel']) ? (string) $_GET['kachel'] : '';
    if (!preg_match('/^[0-9]{1,4}$/', $db_nr)) {
        db_json_raus(array('ok' => 0, 'grund' => 'KACHEL_UNGUELTIG',
                           'meldung' => 'kachel ist die laufende Nummer der Kachel '
                                      . 'auf dieser Seite.'), 400);
    }
    $db_nr = (int) $db_nr;
    if (!isset($db_kacheln[$db_nr]) || !is_array($db_kacheln[$db_nr])
            || (string) (isset($db_kacheln[$db_nr]['kachel'])
                         ? $db_kacheln[$db_nr]['kachel'] : '') !== 'szene') {
        db_json_raus(array('ok' => 0, 'grund' => 'KEINE_SZENE',
                           'meldung' => 'An dieser Stelle steht keine Szene.'), 404);
    }
    $db_schritte = db_szene_schritte($db_kacheln[$db_nr]);
    if (!$db_schritte) {
        db_json_raus(array('ok' => 0, 'grund' => 'SZENE_LEER',
                           'meldung' => 'Diese Szene hat keine Schritte.'), 400);
    }
    // JEDER Schritt wird gegen dieselbe Positivliste geprueft. Eine Szene ist
    // keine Abkuerzung an der Pruefung vorbei.
    foreach ($db_schritte as $db_sch) {
        if (!preg_match('/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{16}$/',
                        $db_sch['uuid'])) {
            db_json_raus(array('ok' => 0, 'grund' => 'UUID_UNGUELTIG',
                               'meldung' => 'Ein Schritt dieser Szene traegt keine '
                                          . 'Loxone-UUID.'), 400);
        }
        list($db_ok, $db_grund) = db_befehl_erlaubt($db_sch['uuid'], $db_sch['befehl']);
        if (!$db_ok) {
            db_json_raus(array('ok' => 0, 'grund' => 'BEFEHL_NICHT_VORGESEHEN',
                               'meldung' => $db_grund), 400);
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
                           'meldung' => 'Der Dienst laeuft nicht. Reiter Einstellungen, '
                                      . 'Knopf "Dienst starten".'), 503);
    }
    list($db_erg, $db_meldung) = db_befehl_absetzen(
        array('befehle' => $db_schritte, 'seite' => $db_seite));
    db_json_raus(array('ok' => $db_erg, 'meldung' => $db_meldung),
                 $db_erg === 0 ? 500 : 200);
}

/* ---------------- Einzelner Befehl ---------------- */

$db_uuid = isset($_GET['uuid']) ? (string) $_GET['uuid'] : '';
$db_befehl = isset($_GET['befehl']) ? (string) $_GET['befehl'] : '';

if (!preg_match('/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{16}$/', $db_uuid)) {
    db_json_raus(array('ok' => 0, 'grund' => 'UUID_UNGUELTIG',
                       'meldung' => 'Das ist keine Loxone-UUID.'), 400);
}
/* Zeichenvorrat des Befehls.
 *
 * Bis 0.9.0 fehlten Klammern und Komma. Der ColorPickerV2 schickt aber
 * genau solche Befehle: hsv(240,100,80) fuer eine Farbe, temp(4000) fuer
 * ein Weiss. Jeder Farbwechsel lief damit in HTTP 400 - der Baustein stand
 * in der Kacheltabelle, war aber nicht bedienbar.
 *
 * Erlaubt sind jetzt zusaetzlich ( ) , und das Leerzeichen. Weiterhin NICHT
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
if (!preg_match('#^[A-Za-z0-9_./+:() ,-]{1,120}$#', $db_befehl)) {
    db_json_raus(array('ok' => 0, 'grund' => 'BEFEHL_UNGUELTIG',
                       'meldung' => 'Der Befehl enthaelt unerlaubte Zeichen.'), 400);
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
                       'meldung' => 'Dieser Baustein steht nicht auf der Seite "'
                                  . $db_seite . '".'), 403);
}

list($db_ok, $db_grund) = db_befehl_erlaubt($db_uuid, $db_befehl);
if (!$db_ok) {
    db_json_raus(array('ok' => 0, 'grund' => 'BEFEHL_NICHT_VORGESEHEN',
                       'meldung' => $db_grund), 400);
}

/* Gesicherte Bausteine.
 *
 * Loxone verlangt fuer einen Baustein mit gesetztem isSecured die
 * Bestaetigung mit dem Visualisierungs-Passwort. Dieses Plugin baut das
 * NICHT nach: die genaue Bildung des Hashes laesst sich hier gegen kein
 * Dokument und keine Anlage messen, und ein geratener Sicherheitsweg ist
 * schlimmer als gar keiner.
 *
 * Deshalb wird abgewiesen und gesagt, warum - statt zu senden und den
 * Anwender in eine nichtssagende Antwort des Miniservers laufen zu lassen.
 * Die Kachel kennzeichnet solche Bausteine ausserdem als gesichert.
 */
$db_b = db_baustein($db_uuid);
if ($db_b !== null && !empty($db_b['gesichert'])) {
    db_json_raus(array('ok' => 0, 'grund' => 'GESICHERT',
                       'meldung' => 'Dieser Baustein ist in Loxone Config als gesichert '
                                  . 'gekennzeichnet und verlangt das '
                                  . 'Visualisierungs-Passwort. Das kann dieses Plugin '
                                  . 'nicht - bitte in der Loxone-App schalten.'), 403);
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
                       'meldung' => 'Der Dienst laeuft nicht. Reiter Einstellungen, '
                                  . 'Knopf "Dienst starten".'), 503);
}

list($db_erg, $db_meldung) = db_befehl_absetzen(
    array('uuid' => $db_uuid, 'befehl' => $db_befehl, 'seite' => $db_seite));
db_json_raus(array('ok' => $db_erg, 'meldung' => $db_meldung),
             $db_erg === 0 ? 500 : 200);
