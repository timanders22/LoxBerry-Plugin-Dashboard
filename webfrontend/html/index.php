<?php
/**
 * Dashboard-Designer - Endpunkt
 *
 * Zwei Sorten Aufrufer:
 *   - die Anzeigeseite auf dem Tablet (holt Werte, setzt Befehle ab)
 *   - der Miniserver (holt eine Zustandszeile fuer virtuelle Eingaenge)
 *
 * Er liegt im unangemeldeten Bereich, damit beide ihn ohne Zugangsdaten
 * erreichen, und ist deshalb durch ein Token geschuetzt. Verglichen wird mit
 * hash_equals, also in gleichbleibender Zeit.
 *
 *   /plugins/<ordner>/index.php?token=<TOKEN>&aktion=<Befehl>
 *
 * Lesend:
 *   status                 Zustandszeile fuer Loxone
 *   seiten                 Liste der Seiten
 *   seite    &seite=...    eine Seite samt Kacheln und Werten (JSON)
 *   werte    &seite=...    nur die Werte dieser Seite (JSON) - der Takt
 *   roh                    das vollstaendige Abbild als JSON
 *
 * Schaltend:
 *   befehl   &seite=...&uuid=...&befehl=...[&pin=...]
 *            seite ist PFLICHT: die PIN haengt an der Seite, von der aus
 *            geschaltet wird - nicht am Baustein.
 *
 * Der Endpunkt spricht NIE selbst mit dem Miniserver. Er liest den
 * Zwischenspeicher und legt Befehle in einer Warteschlange ab, die der
 * Dienst abarbeitet.
 */

error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
require_once __DIR__ . '/db_lib.php';

$db_cfg = db_config();

/* ---------------- Token ---------------- */
$db_soll = (string) $db_cfg['aktionstoken'];
$db_ist = isset($_GET['token']) ? (string) $_GET['token'] : '';
if ($db_soll === '') {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo "FEHLER;OK=0;GRUND=KEIN_TOKEN_GESETZT\n";
    echo "Die Plugin-Oberflaeche wurde noch nie geoeffnet - es gibt noch kein Token.\n";
    exit;
}
if (!hash_equals($db_soll, $db_ist)) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo "FEHLER;OK=0;GRUND=TOKEN\n";
    exit;
}

/* ---------------- Aktion (Weissliste) ---------------- */
$db_lesend = array('status', 'seiten', 'seite', 'werte', 'roh');
$db_schaltend = array('befehl');
$db_aktion = isset($_GET['aktion']) ? (string) $_GET['aktion'] : 'status';
if (!in_array($db_aktion, array_merge($db_lesend, $db_schaltend), true)) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
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
        $aus[] = array('schluessel' => (string) $s['schluessel'],
                       'name' => (string) $s['name'],
                       'kacheln' => count(isset($s['kacheln']) ? $s['kacheln'] : array()),
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

if ($db_aktion === 'status') {
    header('Content-Type: text/plain; charset=utf-8');
    $a = db_abbild();
    $seiten = db_seiten();
    $kacheln = 0;
    foreach ($seiten as $s) { $kacheln += count(isset($s['kacheln']) ? $s['kacheln'] : array()); }
    printf("DASHBOARD;OK=%d;BAUSTEINE=%d;SEITEN=%d;KACHELN=%d;ALTER=%d\n",
        (int) (!empty($a['ok'])), count(db_bausteine()), count($seiten), $kacheln, db_alter());
    exit;
}

/* ================= Schaltende Aktion ================= */

header('Content-Type: application/json; charset=utf-8');

if (empty($db_cfg['steuerung_ein'])) {
    db_json_raus(array('ok' => 0, 'grund' => 'GESPERRT',
                       'meldung' => 'Das Schalten ist im Reiter Einstellungen gesperrt.'), 403);
}

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

/* Von WELCHER Seite kommt der Befehl?
 *
 * Bis 0.9.0 wurde die erstbeste Seite genommen, auf der der Baustein steht
 * ('break 2'). Das war eine Luecke: liegt ein Tuerschloss auf Seite "flur"
 * ohne PIN und zusaetzlich auf Seite "sicherheit" mit PIN, entschied die
 * Reihenfolge in der Konfiguration darueber, ob eine PIN verlangt wird.
 * Stand die ungeschuetzte Seite vorn, liess sich die PIN der geschuetzten
 * Seite umgehen - ohne jeden Trick, einfach durch Aufruf.
 *
 * Deshalb ist &seite= jetzt PFLICHT fuer schaltende Aufrufe. Geprueft wird
 * genau die PIN der Seite, von der der Befehl kommt, und ob die Kachel dort
 * ueberhaupt liegt. Die Anzeigeseite schickt den Parameter mit; wer die
 * Adresse von Hand baut, bekommt eine Meldung, die sagt was fehlt.
 */
if ($db_seite === '') {
    db_json_raus(array('ok' => 0, 'grund' => 'SEITE_FEHLT',
                       'meldung' => 'Fuer einen Befehl ist &seite= Pflicht - die PIN '
                                  . 'haengt an der Seite, von der aus geschaltet wird.'), 400);
}
$db_gefunden = null;
foreach (db_seiten() as $s) {
    if ((string) $s['schluessel'] !== $db_seite) {
        continue;
    }
    foreach ((isset($s['kacheln']) ? $s['kacheln'] : array()) as $k) {
        if ((string) $k['uuid'] === $db_uuid) { $db_gefunden = $s; break 2; }
    }
}
if ($db_gefunden === null) {
    db_json_raus(array('ok' => 0, 'grund' => 'NICHT_AUF_DIESER_SEITE',
                       'meldung' => 'Dieser Baustein steht nicht auf der Seite "'
                                  . $db_seite . '".'), 403);
}

/* PIN GENAU DIESER Seite. */
if (!empty($db_gefunden['pin'])) {
    $db_pin = isset($_GET['pin']) ? (string) $_GET['pin'] : '';
    if (!hash_equals((string) $db_gefunden['pin'], $db_pin)) {
        db_json_raus(array('ok' => 0, 'grund' => 'PIN',
                           'meldung' => 'Die PIN stimmt nicht.'), 403);
    }
}

list($db_ok, $db_grund) = db_befehl_erlaubt($db_uuid, $db_befehl);
if (!$db_ok) {
    db_json_raus(array('ok' => 0, 'grund' => 'BEFEHL_NICHT_VORGESEHEN',
                       'meldung' => $db_grund), 400);
}

if (db_dienst_pid() === 0) {
    db_json_raus(array('ok' => 0, 'grund' => 'DIENST_LAEUFT_NICHT',
                       'meldung' => 'Der Dienst laeuft nicht. Reiter Einstellungen, '
                                  . 'Knopf "Dienst starten".'), 503);
}

list($db_erg, $db_meldung) = db_befehl_absetzen(
    array('uuid' => $db_uuid, 'befehl' => $db_befehl, 'seite' => $db_seite));
db_json_raus(array('ok' => $db_erg, 'meldung' => $db_meldung),
             $db_erg === 0 ? 500 : 200);
