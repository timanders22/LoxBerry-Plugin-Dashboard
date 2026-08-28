<?php
/**
 * Dashboard-Designer - Bedienoberflaeche
 *
 * Reiter: Einstellungen | Dashboards | Designer |
 *         Einbindung in Loxone | Test | Logdateien
 *
 * Der Reiter MQTT des Hausstandards entfaellt ersatzlos: dieses Plugin
 * veroeffentlicht nichts ueber MQTT. Es liest aus dem Miniserver und schreibt
 * in ihn zurueck - ein Umweg ueber den Broker waere nur eine Zwischenstation
 * mehr. Die uebrigen Reiter behalten Reihenfolge und Benennung.
 *
 * Diese Datei ist NUR Oberflaeche. Der Dienst haelt die Verbindung, das
 * Tablet spricht mit webfrontend/html/tafel.php und index.php.
 */

error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);

$db_gefunden = false;
foreach (array(
    dirname(dirname(__DIR__)) . '/html/plugins/' . basename(__DIR__) . '/db_lib.php',
    dirname(dirname(dirname(__DIR__))) . '/html/plugins/' . basename(__DIR__) . '/db_lib.php',
    dirname(__DIR__) . '/html/db_lib.php',
) as $db_kandidat) {
    if (is_file($db_kandidat)) { require_once $db_kandidat; $db_gefunden = true; break; }
}
if (!$db_gefunden) {
    echo '<p><b>Fehler:</b> db_lib.php wurde nicht gefunden. Bitte das Plugin neu installieren.</p>';
    exit;
}
require_once __DIR__ . '/db_test.php';

$db_p = db_paths();
if ($db_p['home'] !== '' && is_file($db_p['home'] . '/libs/phplib/loxberry_system.php')) {
    require_once $db_p['home'] . '/libs/phplib/loxberry_system.php';
    require_once $db_p['home'] . '/libs/phplib/loxberry_web.php';
}

/* Die Reiterliste steht GENAU EINMAL.
 *
 * Aus diesem Feld entstehen der Pruefausdruck, die Leiste und das
 * serverseitige sm-active. Bis 0.9.5 stand die Positivliste als Ausdruck von
 * Hand daneben - sie war zwar vollstaendig, aber der naechste Reiter waere
 * vergessen worden, und dann ist er anklickbar und die Seite springt nach
 * jedem Absenden zurueck auf Einstellungen.
 *
 * Die id der Bereiche laesst sich nicht mit erzeugen; sie steht im Rumpf.
 * Dafuer bleibt die Kongruenzprobe in der Pflichtpruefung. */
$db_reiter = array(
    'settings' => 'REITER.EINSTELLUNGEN',
    'boards'   => 'REITER.DASHBOARDS',
    'designer' => 'REITER.DESIGNER',
    'loxone'   => 'REITER.LOXONE',
    'test'     => 'REITER.TEST',
    'log'      => 'REITER.LOG',
);
/* Die Positivliste steht AUSGESCHRIEBEN da, aus demselben Grund wie die
 * Leiste weiter unten: hausstandard_pruefen.py sucht genau die Form des
 * Ausdrucks in der naechsten Zeile. Wird er mit implode() zusammengesetzt,
 * findet das Werkzeug "Liste 0" und die Pruefung ist blind.
 *
 * In diesem Kommentar steht der Ausdruck deshalb NICHT noch einmal als
 * Beispiel: das Werkzeug nimmt die erste Fundstelle, und ein Beispiel mit
 * drei erfundenen Namen haette es auf die falsche Faehrte geschickt
 * (dieselbe Klasse wie ein Kommentar, der eine ini-Sektion erwaehnt).
 *
 * Damit die drei Aufzaehlungen - Feld, Ausdruck, Leiste - trotzdem nicht
 * auseinanderlaufen koennen, vergleicht der Reiter Test sie miteinander und
 * meldet jede Abweichung. Eine Regel, die ein Werkzeug prueft, ist
 * hinterlegt; eine Regel in Prosa ist eine Hoffnung. */
$db_muster = '/^tab-(settings|boards|designer|loxone|test|log)$/';
$db_tab = 'tab-settings';
if (isset($_POST['activetab']) && preg_match($db_muster, (string) $_POST['activetab'])) {
    $db_tab = (string) $_POST['activetab'];
} elseif (isset($_GET['form']) && preg_match($db_muster, 'tab-' . (string) $_GET['form'])) {
    $db_tab = 'tab-' . (string) $_GET['form'];
}

$db_meldungen = array();
$db_fehler = array();

/* ---------------------------------------------------------------- *
 * Der Wachposten - EIN Posten, vor allen Handlern.
 * Abgewiesen heisst gemeldet, und es wird NICHTS ausgefuehrt: $_POST
 * wird geleert, nur der aktive Reiter bleibt stehen, damit der Bediener
 * nach der Abweisung dort steht, wo er war.
 * ---------------------------------------------------------------- */
$db_wache = db_wachposten();
if ($db_wache !== '') {
    $db_reiter_merk = isset($_POST['activetab']) && is_string($_POST['activetab'])
        ? (string) $_POST['activetab'] : null;
    $_POST = array();
    if ($db_reiter_merk !== null) {
        $_POST['activetab'] = $db_reiter_merk;
    }
    $db_fehler[] = $db_wache;
}

$db_ausgabe = '';
$db_post = (isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : '') === 'POST';

$db_sauber = function ($feld) {
    // Nur Steuerzeichen und Anfuehrungszeichen entfernen - ein hartes Filtern
    // auf eine Positivliste zerstoert gueltige Eingaben.
    return trim(preg_replace('/[\x00-\x1F\x7F"\']/', '',
        (string) (isset($_POST[$feld]) ? $_POST[$feld] : '')));
};

/* ==================================================================
 * DIE HANDLER STEHEN VOR lbheader() - DAS IST BAUVORSCHRIFT
 * ==================================================================
 *
 * Stand der Kopf davor, war er beim Aufruf von header() schon
 * geschrieben - "Cannot modify header information", und der Knopf
 * "Einstellungen sichern" lieferte eine Seite mit angehaengtem JSON
 * statt einer Datei.
 *
 * Am PHP-CLI ist das unsichtbar: header() ist dort wirkungslos und
 * headers_sent() immer falsch. Und wer OHNE gueltiges Formularmerkmal
 * misst, wird vom Wachposten abgewiesen, bevor der Handler anlaeuft.
 * Beides hat den Fehler lange verdeckt.
 *
 * Reihenfolge: Bibliothek, Konfiguration, Wachposten, Reiterwahl,
 * ALLE Handler samt Downloads, dann erst lbheader(), dann HTML.
 * ================================================================== */
/* ---------------- Loxone-Vorlagen herunterladen ----------------
 *
 * Die Anfuehrungszeichen um den Dateinamen sind Pflicht - ohne sie bricht
 * jeder Name, der ein Leerzeichen enthaelt. */
if ($db_post && (isset($_POST['vorlage']) || isset($_POST['vorlage_out']))) {
    list($db_name, $db_xml) = isset($_POST['vorlage_out']) ? db_vorlage_out() : db_vorlage();
    header('Content-Type: application/xml; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $db_name . '"');
    echo $db_xml;
    exit;
}

/* ---------------- Einstellungen speichern ---------------- */
if ($db_post && isset($_POST['speichern'])) {
    $db_cfg = db_config();

    $db_ms = $db_sauber('miniserver');
    if (!preg_match('/^[0-9]{1,3}$/', $db_ms)) {
        $db_fehler[] = db_t('EINST.FEHLER_MS');
    } else {
        $db_cfg['miniserver'] = $db_ms;
    }

    // Die Grenzen der Wartezeit kommen aus der Bibliothek. Bis 0.9.5 stand
    // hier 1..60 und db_befehl_absetzen() kappte bei 20 - jeder Wert
    // darueber war wirkungslos, ohne dass es irgendwo stand.
    foreach (array('takt' => array(1, 30), 'http_takt' => array(5, 120),
                   'wartezeit' => array(DB_WARTEZEIT_MIN, DB_WARTEZEIT_MAX),
                   'rotation' => array(0, 3600),
                   'nacht_helligkeit' => array(0, 100),
                   'verlauf_punkte' => array(10, 240)) as $db_f => $db_g) {
        $db_w = $db_sauber($db_f);
        if (!preg_match('/^[0-9]+$/', $db_w)) {
            $db_fehler[] = sprintf(db_t('EINST.FEHLER_ZAHL'), db_t('EINST.L_' . strtoupper($db_f)));
        } elseif ((int) $db_w < $db_g[0] || (int) $db_w > $db_g[1]) {
            $db_fehler[] = sprintf(db_t('EINST.FEHLER_BEREICH'),
                                   db_t('EINST.L_' . strtoupper($db_f)), $db_g[0], $db_g[1]);
        } else {
            $db_cfg[$db_f] = (int) $db_w;
        }
    }

    /* Nachtabsenkung: entweder BEIDE Zeiten oder keine. Eine halb
     * ausgefuellte Angabe wird gemeldet und die Zeile uebergangen - alles
     * Uebrige wird trotzdem gespeichert. Blockieren darf nur, was das
     * Speichern technisch unmoeglich macht. */
    $db_nv = $db_sauber('nacht_von');
    $db_nb = $db_sauber('nacht_bis');
    $db_zeitmuster = '/^([01]?[0-9]|2[0-3]):[0-5][0-9]$/';
    if ($db_nv === '' && $db_nb === '') {
        $db_cfg['nacht_von'] = '';
        $db_cfg['nacht_bis'] = '';
    } elseif (!preg_match($db_zeitmuster, $db_nv) || !preg_match($db_zeitmuster, $db_nb)) {
        $db_fehler[] = db_t('EINST.FEHLER_NACHTZEIT');
    } elseif ($db_nv === $db_nb) {
        $db_fehler[] = db_t('EINST.FEHLER_NACHTGLEICH');
    } else {
        $db_cfg['nacht_von'] = $db_nv;
        $db_cfg['nacht_bis'] = $db_nb;
    }

    $db_farbe = $db_sauber('farbe');
    if (!in_array($db_farbe, array('dunkel', 'hell'), true)) {
        $db_fehler[] = db_t('EINST.FEHLER_FARBE');
    } else {
        $db_cfg['farbe'] = $db_farbe;
    }

    // Haken: isset() stellt sie beim Absenden DIESES Formulars. Alle Haken
    // dieses Reiters stehen deshalb in demselben Formular - sonst setzte das
    // Absenden eines anderen sie stillschweigend auf 0.
    foreach (array('tls', 'http_rueckfall', 'steuerung_ein', 'vollbild', 'wach',
                   'haptik', 'verlauf', 'sse', 'tafelsteuerung',
                   'gesichert_schalten') as $db_h) {
        $db_cfg[$db_h] = isset($_POST[$db_h]) ? 1 : 0;
    }

    /* Eigene Zugangsdaten. Sie landen in zugang.json mit Rechten 0600 und
     * NIE in der Konfiguration, die diese Seite anzeigt. */
    $db_zadr = $db_sauber('z_adresse');
    if ($db_zadr !== '' && !preg_match('/^[A-Za-z0-9][A-Za-z0-9\.\-]{0,80}$/', $db_zadr)) {
        $db_fehler[] = db_t('EINST.FEHLER_ZADRESSE');
    } else {
        $db_zport = $db_sauber('z_port');
        if ($db_zadr !== '' && (!preg_match('/^[0-9]+$/', $db_zport)
                || (int) $db_zport < 1 || (int) $db_zport > 65535)) {
            $db_fehler[] = db_t('EINST.FEHLER_ZPORT');
        } else {
            // Das Kennwort wird NICHT durch $db_sauber gejagt: darin duerfen
            // Anfuehrungszeichen stehen.
            $db_zpw = (string) (isset($_POST['z_passwort']) ? $_POST['z_passwort'] : '');
            if (!$db_fehler) {
                db_zugang_speichern($db_zadr, $db_zport !== '' ? $db_zport : 80,
                                    $db_sauber('z_benutzer'), $db_zpw);
            }
        }
    }

    // Das Visualisierungs-Passwort steht fuer sich: es gilt fuer den Benutzer,
    // mit dem sich der Dienst anmeldet, unabhaengig davon, ob das der
    // LoxBerry-Zugang oder ein abweichender ist. Auch hier gilt: ein leeres
    // Feld behaelt das bisherige, sonst loescht jedes Speichern es weg.
    db_visu_speichern((string) (isset($_POST['visu_pw']) ? $_POST['visu_pw'] : ''));

    if (!$db_fehler) {
        if (db_config_speichern($db_cfg)) { $db_meldungen[] = db_t('EINST.GESPEICHERT'); }
        else { $db_fehler[] = sprintf(db_t('EINST.FEHLER_SPEICHERN'), $db_p['config']); }
    }
    $db_tab = 'tab-settings';
}

/* ---------------- Dienst ---------------- */
if ($db_post && isset($_POST['dienst'])) {
    $db_was = (string) $_POST['dienst'];
    if (!in_array($db_was, array('start', 'stop', 'restart'), true)) {
        $db_fehler[] = db_t('EINST.FEHLER_DIENST');
    } else {
        list($db_ok, $db_aus) = db_dienst($db_was);
        if ($db_ok) { $db_meldungen[] = sprintf(db_t('EINST.DIENST_OK'), db_e($db_aus)); }
        else { $db_fehler[] = sprintf(db_t('EINST.DIENST_FEHL'), db_e($db_aus)); }
    }
    $db_tab = 'tab-settings';
}

/* ---------------- Struktur holen / Entwurf ---------------- */
if ($db_post && isset($_POST['struktur_holen'])) {
    $db_skript = $db_p['bindir'] . '/dienst.sh';
    $db_a = array(); $db_c = 0;
    @exec(escapeshellcmd($db_skript) . ' einmal 2>&1', $db_a, $db_c);
    $db_ausgabe = implode("\n", $db_a);
    if ($db_c === 0) { $db_meldungen[] = db_t('BOARD.STRUKTUR_OK'); }
    else { $db_fehler[] = db_t('BOARD.STRUKTUR_FEHL'); }
    $db_tab = 'tab-boards';
}

if ($db_post && isset($_POST['entwurf'])) {
    $db_vorn = ((string) $_POST['entwurf']) === 'vonvorn';
    $db_skript = $db_p['bindir'] . '/dienst.sh';
    $db_a = array(); $db_c = 0;
    @exec(escapeshellcmd($db_skript) . ' entwurf ' . ($db_vorn ? '--von-vorn' : '') . ' 2>&1',
          $db_a, $db_c);
    $db_ausgabe = implode("\n", $db_a);
    if ($db_c === 0) { $db_meldungen[] = db_t('BOARD.ENTWURF_OK'); }
    else { $db_fehler[] = db_t('BOARD.ENTWURF_FEHL'); }
    $db_tab = 'tab-boards';
}

/* ---------------- Seiten verwalten ---------------- */
if ($db_post && isset($_POST['seiten_speichern'])) {
    $db_seiten = db_seiten();
    $db_namen = isset($_POST['s_name']) ? (array) $_POST['s_name'] : array();
    $db_spalten = isset($_POST['s_spalten']) ? (array) $_POST['s_spalten'] : array();
    $db_pins = isset($_POST['s_pin']) ? (array) $_POST['s_pin'] : array();
    $db_weg = isset($_POST['s_weg']) ? (array) $_POST['s_weg'] : array();
    $db_neu = array();
    foreach ($db_seiten as $db_i => $db_s) {
        if (!empty($db_weg[$db_i])) { continue; }
        $db_n = isset($db_namen[$db_i])
            ? trim(preg_replace('/[\x00-\x1F\x7F"]/', '', (string) $db_namen[$db_i])) : '';
        if ($db_n === '') {
            $db_fehler[] = sprintf(db_t('BOARD.FEHLER_NAME'), (int) $db_i + 1);
            continue;
        }
        $db_sp = isset($db_spalten[$db_i]) ? (int) $db_spalten[$db_i] : 6;
        if ($db_sp < 2 || $db_sp > 12) {
            $db_fehler[] = sprintf(db_t('BOARD.FEHLER_SPALTEN'), db_e($db_n));
            continue;
        }
        $db_pin = isset($db_pins[$db_i]) ? trim((string) $db_pins[$db_i]) : '';
        if ($db_pin !== '' && !preg_match('/^[0-9]{4,10}$/', $db_pin)) {
            $db_fehler[] = sprintf(db_t('BOARD.FEHLER_PIN'), db_e($db_n));
            continue;
        }
        $db_s['name'] = $db_n;
        $db_s['spalten'] = $db_sp;
        // Leere PIN heisst: die bisherige beibehalten. Zum Loeschen gibt es
        // das Haekchen daneben.
        if ($db_pin !== '') { $db_s['pin'] = $db_pin; }
        if (!empty($_POST['s_pinweg'][$db_i])) { $db_s['pin'] = ''; }
        $db_neu[] = $db_s;
    }
    if (!$db_fehler) {
        if (db_seiten_speichern($db_neu)) { $db_meldungen[] = db_t('BOARD.GESPEICHERT'); }
        else { $db_fehler[] = sprintf(db_t('EINST.FEHLER_SPEICHERN'), $db_p['seiten']); }
    }
    $db_tab = 'tab-boards';
}

/* ---------------- Designer speichert ---------------- */
if ($db_post && isset($_POST['designer_speichern'])) {
    $db_roh = (string) (isset($_POST['aufbau']) ? $_POST['aufbau'] : '');
    $db_d = json_decode($db_roh, true);
    if (!is_array($db_d) || !isset($db_d['seiten']) || !is_array($db_d['seiten'])) {
        // Kaputte Daten werden NICHT gespeichert und NICHT zurechtgebogen.
        $db_fehler[] = db_t('DESIGN.FEHLER_JSON');
    } else {
        $db_bekannt = array();
        foreach (db_bausteine() as $db_b) { $db_bekannt[$db_b['uuid']] = 1; }
        $db_neu = array();
        $db_schluessel = array();
        foreach ($db_d['seiten'] as $db_i => $db_s) {
            $db_k = (string) (isset($db_s['schluessel']) ? $db_s['schluessel'] : '');
            if (!preg_match('/^[a-z0-9-]{1,60}$/', $db_k)) {
                $db_fehler[] = sprintf(db_t('DESIGN.FEHLER_SCHLUESSEL'), (int) $db_i + 1);
                continue;
            }
            if (isset($db_schluessel[$db_k])) {
                $db_fehler[] = sprintf(db_t('DESIGN.FEHLER_DOPPELT'), db_e($db_k));
                continue;
            }
            $db_schluessel[$db_k] = 1;
            $db_kacheln = array();
            foreach ((isset($db_s['kacheln']) && is_array($db_s['kacheln'])
                      ? $db_s['kacheln'] : array()) as $db_k2) {
                $db_u = (string) (isset($db_k2['uuid']) ? $db_k2['uuid'] : '');
                if ($db_u !== '' && !isset($db_bekannt[$db_u])) {
                    // Der Baustein steht nicht mehr in der Struktur. Die
                    // Kachel bleibt trotzdem erhalten - sie wird auf dem
                    // Dashboard als 'fehlt' angezeigt, statt spurlos zu
                    // verschwinden.
                    $db_meldungen[] = sprintf(db_t('DESIGN.UNBEKANNT'), db_e($db_u));
                }
                $db_g = (string) (isset($db_k2['groesse']) ? $db_k2['groesse'] : '1x1');
                if (!preg_match('/^[1-6]x[1-3]$/', $db_g)) { $db_g = '1x1'; }
                $db_art = preg_replace('/[^a-z]/', '',
                              (string) (isset($db_k2['kachel']) ? $db_k2['kachel'] : ''));
                $db_neuek = array(
                    'uuid'     => $db_u,
                    'titel'    => trim(preg_replace('/[\x00-\x1F\x7F"]/', '',
                                       (string) (isset($db_k2['titel']) ? $db_k2['titel'] : ''))),
                    'kachel'   => $db_art,
                    'groesse'  => $db_g,
                    'sichtbar' => !empty($db_k2['sichtbar']) ? 1 : 0,
                );
                /* Szene: die Schritte werden EINZELN gegen dieselbe
                 * Positivliste geprueft wie ein einzelner Befehl. Ein
                 * unbrauchbarer Schritt wird uebergangen und gemeldet - die
                 * uebrigen Kacheln werden trotzdem gespeichert. */
                if ($db_art === 'szene') {
                    $db_schritte = array();
                    foreach ((isset($db_k2['schritte']) && is_array($db_k2['schritte'])
                              ? $db_k2['schritte'] : array()) as $db_sch) {
                        if (!is_array($db_sch)) { continue; }
                        $db_su = (string) (isset($db_sch['uuid']) ? $db_sch['uuid'] : '');
                        $db_sb = (string) (isset($db_sch['befehl']) ? $db_sch['befehl'] : '');
                        list($db_sok, $db_sgrund) = db_befehl_erlaubt($db_su, $db_sb);
                        if (!$db_sok) {
                            $db_fehler[] = sprintf(db_t('DESIGN.FEHLER_SCHRITT'),
                                                   db_e($db_neuek['titel']), $db_sgrund);
                            continue;
                        }
                        $db_schritte[] = array('uuid' => $db_su, 'befehl' => $db_sb);
                    }
                    $db_neuek['schritte'] = $db_schritte;
                    $db_neuek['uuid'] = '';
                }
                $db_kacheln[] = $db_neuek;
            }
            $db_alt = db_seite($db_k);
            $db_neu[] = array(
                'schluessel' => $db_k,
                'name'       => trim(preg_replace('/[\x00-\x1F\x7F"]/', '',
                                     (string) (isset($db_s['name']) ? $db_s['name'] : $db_k))),
                'spalten'    => max(2, min(12, (int) (isset($db_s['spalten']) ? $db_s['spalten'] : 6))),
                'pin'        => (string) ($db_alt !== null && isset($db_alt['pin']) ? $db_alt['pin'] : ''),
                'kacheln'    => $db_kacheln,
            );
        }
        if (!$db_fehler) {
            if (db_seiten_speichern($db_neu)) { $db_meldungen[] = db_t('DESIGN.GESPEICHERT'); }
            else { $db_fehler[] = sprintf(db_t('EINST.FEHLER_SPEICHERN'), $db_p['seiten']); }
        }
    }
    $db_tab = 'tab-designer';
}

/* ---------------- Token, Test, Log ---------------- */
if ($db_post && isset($_POST['token_neu'])) {
    $db_cfg = db_config();
    $db_cfg['aktionstoken'] = db_token_erzeugen();
    if (db_config_speichern($db_cfg)) { $db_meldungen[] = db_t('LOX.TOKEN_NEU'); }
    else { $db_fehler[] = sprintf(db_t('EINST.FEHLER_SPEICHERN'), $db_p['config']); }
    $db_tab = 'tab-loxone';
}
if ($db_post && isset($_POST['log_leeren'])) {
    @mkdir(dirname($db_p['log']), 0775, true);
    // In die Logdatei gehoert Klartext, kein HTML.
    $db_klartext = trim(strip_tags(html_entity_decode(db_t('LOG.GELEERT'), ENT_QUOTES, 'UTF-8')));
    @file_put_contents($db_p['log'], '[' . date('Y-m-d H:i:s') . '] ' . $db_klartext . "\n");
    $db_meldungen[] = db_t('LOG.GELEERT');
    $db_tab = 'tab-log';
}
if ($db_post && isset($_POST['test'])) {
    list($db_stand, $db_text) = db_test_aktion((string) $_POST['test']);
    if ($db_stand === 1) { $db_meldungen[] = db_e($db_text); } else { $db_fehler[] = db_e($db_text); }
    $db_tab = 'tab-test';
}
if ($db_post && isset($_POST['selbsttest'])) {
    $db_ausgabe = db_selbsttest_ausgabe();
    $db_tab = 'tab-test';
}
if ($db_post && isset($_POST['probe'])) {
    list($db_ok, $db_ausgabe) = db_probe((string) $_POST['probe']);
    if ($db_ok) { $db_meldungen[] = db_t('TEST.PROBE_OK'); }
    else { $db_fehler[] = db_t('TEST.PROBE_FEHL'); }
    $db_tab = 'tab-test';
}

/* ---------------- Laden ---------------- */
$db_cfg = db_config();
$db_token = db_token();
$db_seiten = db_seiten();
$db_bausteine = db_bausteine();
$db_struktur = db_struktur();
$db_zustand = db_zustand();
$db_ms = db_miniserver();
$db_zugang = db_zugang();
$db_pid = db_dienst_pid();
$db_alter = db_alter();
$db_kachelzahl = 0;
foreach ($db_seiten as $db_s) { $db_kachelzahl += count(isset($db_s['kacheln']) ? $db_s['kacheln'] : array()); }

$db_rahmen = class_exists('LBWeb', false) && method_exists('LBWeb', 'lbheader');

/* ---------------- Einstellungen sichern ----------------
 *
 * Ausgegeben wird die VOLLE Konfiguration - samt Aktionstoken. Ohne ihn
 * stuenden nach dem Zurueckspielen alle Felder richtig, und das Plugin
 * kaeme trotzdem nicht an die Anlage; die Datei waere wertlos. Damit
 * traegt sie ein Geheimnis, und der Hinweis am Knopf sagt das. */
if ($db_post && isset($_POST['db_sichern'])) {
    $db_js = json_encode(db_config(),
        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($db_js !== false) {
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="dashboard_einstellungen_'
               . date('Ymd_His') . '.json"');
        echo $db_js;
        exit;
    }
    $db_fehler[] = db_t('EINST.SICH_SCHREIBFEHLER');
}

/* ---------------- Einstellungen zurueckspielen ----------------
 *
 * is_uploaded_file() ZUERST: ohne diese Pruefung liesse sich jede Datei des
 * Servers unterschieben. Dann die Groessengrenze - eine Sicherung dieses
 * Plugins ist wenige Kilobyte gross; alles darueber wird gar nicht gelesen. */
if ($db_post && isset($_POST['db_zurueck'])) {
    if (!isset($_FILES['db_sicherung']) || !is_array($_FILES['db_sicherung'])
        || !isset($_FILES['db_sicherung']['tmp_name'])
        || !@is_uploaded_file($_FILES['db_sicherung']['tmp_name'])) {
        $db_fehler[] = db_t('EINST.SICH_KEINE_DATEI');
    } elseif ((int) $_FILES['db_sicherung']['size'] > 262144) {
        $db_fehler[] = db_t('EINST.SICH_ZU_GROSS');
    } else {
        list($db_neu, $db_mangel, $db_n) = db_sicherung_lesen(
            (string) @file_get_contents($_FILES['db_sicherung']['tmp_name']));
        if ($db_neu === null) {
            /* ALLE Beanstandungen, nicht nur die erste - und geaendert wird
             * nichts. */
            $db_fehler[] = db_t('EINST.SICH_ABGELEHNT') . ' '
                            . implode(' ', $db_mangel);
        } elseif (db_config_speichern($db_neu)) {
            $db_meldungen[] = sprintf(db_t('EINST.SICH_UEBERNOMMEN'), $db_n);
        } else {
            $db_fehler[] = db_t('EINST.SICH_SCHREIBFEHLER');
        }
    }
}


if ($db_rahmen) {
    LBWeb::lbheader(db_t('ALLG.TITEL'), 'https://www.loxone.com/enen/kb/api/', 'help.html');
}

?>
<style>
.sm-wrap { max-width: 980px; margin: 0 auto; font-family: -apple-system, 'Segoe UI', Roboto, sans-serif; color: #333; }
.sm-wrap, .sm-wrap *, .sm-tabs, .sm-tabs * { text-shadow: none !important; }
.sm-wrap h2 { color: #6dac20; margin: 24px 0 10px; font-size: 1.15em; border-bottom: 2px solid #e0e0e0; padding-bottom: 6px; }
.sm-wrap h3 { color: #4f7d17; font-size: 1.0em; font-weight: 700; margin: 16px 0 2px; }
.sm-tabs { display: flex; gap: 4px; margin: 14px 0 0; border-bottom: 2px solid #6dac20; flex-wrap: wrap; }
.sm-tab { background: #eee; border: 1px solid #ccc; border-bottom: 0; border-radius: 8px 8px 0 0;
          padding: 9px 18px; font-size: 0.95em; color: #444 !important; text-decoration: none; display: inline-block; }
.sm-tab.sm-active { background: #6dac20; color: #fff !important; border-color: #6dac20; font-weight: 600; }
.sm-seite { display: none; }
.sm-seite.sm-active { display: block; }
.sm-feld { margin: 14px 0; }
.sm-feld > label { display: block; font-weight: 600; font-size: 0.9em; color: #555; margin: 0 0 4px; }
.sm-feld input[type=text], .sm-feld input[type=password], .sm-feld input[type=number], .sm-feld select,
.sm-feld textarea {
    width: 100%; padding: 8px 10px; border: 1px solid #ccc; border-radius: 6px;
    box-sizing: border-box; font-size: 0.95em; background: #fff; color: #333; }
.sm-hilfe { font-size: 0.84em; color: #777; margin: 3px 0 0; line-height: 1.45; }
.sm-hinweis { border: 1px solid #a5d6a7; background: #e8f5e9; border-radius: 6px;
    padding: 10px 12px; margin: 12px 0; font-size: 0.9em; }
.sm-warnung { border: 1px solid #f0c9a0; background: #fdf4ec; border-radius: 6px;
    padding: 10px 12px; margin: 12px 0; font-size: 0.9em; }
.sm-fehler { border: 1px solid #ef9a9a; background: #ffebee; border-radius: 6px;
    padding: 10px 12px; margin: 12px 0; font-size: 0.9em; }
.sm-an  { color: #1a7f1a; font-weight: 700; }
.sm-aus { color: #b00000; font-weight: 700; }
.sm-mono { font-family: Consolas, 'Courier New', monospace; background: #f2f2f2;
    padding: 1px 5px; border-radius: 4px; font-size: 0.92em; word-break: break-all; }
.sm-log { background: #1e1e1e; color: #d4d4d4; font-family: Consolas, 'Courier New', monospace;
    font-size: 0.82em; padding: 12px; border-radius: 8px; max-height: 480px; overflow: auto;
    white-space: pre-wrap; }
.sm-tabelle { border-collapse: collapse; width: 100%; font-size: 0.88em; margin: 10px 0; }
.sm-tabelle th, .sm-tabelle td { border: 1px solid #ddd; padding: 6px 8px; text-align: left; vertical-align: top; }
.sm-tabelle th { background: #f5f5f5; font-weight: 600; }
/* Knoepfe, Knopfreihe und Legende woertlich nach VORLAGE_hausstandard.css.html.
   Bis 0.9.5 hiessen die Klassen hier sm-b statt sm-btn, es gab keine
   sm-knopfreihe, und die Legende malte ihre Punkte mit style="background:..."
   statt mit sm-punkt. Folge: hausstandard_pruefen.py fand keine Knopfreihe
   und meldete die Legendenspalte als "nicht pruefbar" - eine Pruefstelle, die
   nichts bedeutet, ist schlimmer als keine. Ausserdem fehlten !important und
   jede :hover-Regel; jQuery Mobile haette die Knoepfe sonst uebermalt. */
.sm-knopfreihe { display: flex; flex-wrap: wrap; gap: 10px; margin: 10px 0 4px; align-items: stretch; }
.sm-wrap .sm-knopfreihe .sm-btn, .sm-wrap a.sm-btn, .sm-wrap button.sm-btn {
    border: 0; border-radius: 6px; padding: 9px 18px; font-size: 0.93em; cursor: pointer;
    color: #fff !important; margin: 0; display: inline-block; text-decoration: none;
    text-shadow: none !important; box-shadow: none !important; }
.sm-wrap .sm-btn.sm-b-lesen   { background: #6dac20 !important; }
.sm-wrap .sm-btn.sm-b-technik { background: #546e7a !important; }
.sm-wrap .sm-btn.sm-b-aktion  { background: #e0620d !important; }
.sm-wrap .sm-btn.sm-b-lesen:hover,   .sm-wrap .sm-btn.sm-b-lesen:focus   { background: #5c9219 !important; color: #fff !important; }
.sm-wrap .sm-btn.sm-b-technik:hover, .sm-wrap .sm-btn.sm-b-technik:focus { background: #435962 !important; color: #fff !important; }
.sm-wrap .sm-btn.sm-b-aktion:hover,  .sm-wrap .sm-btn.sm-b-aktion:focus  { background: #b84f0a !important; color: #fff !important; }
.sm-legende { display: flex; flex-wrap: wrap; gap: 14px; margin: 10px 0 2px; font-size: 0.86em; color: #555; }
.sm-legende span { display: inline-flex; align-items: center; gap: 6px; }
.sm-punkt { width: 13px; height: 13px; border-radius: 3px; display: inline-block; }
.sm-punkt.sm-b-lesen   { background: #6dac20; }
.sm-punkt.sm-b-technik { background: #546e7a; }
.sm-punkt.sm-b-aktion  { background: #e0620d; }
.sm-step { border-left: 3px solid #6dac20; padding: 2px 0 2px 14px; margin: 18px 0; }
.sm-step h3 { margin-top: 0; }
</style>

<div class="sm-wrap">

<?php foreach ($db_meldungen as $db_m) { ?>
<div class="sm-hinweis"><?= $db_m ?></div>
<?php } ?>
<?php if ($db_fehler) { ?>
<div class="sm-fehler"><b><?= db_e(db_t('ALLG.BEANSTANDUNG')) ?></b>
<ul style="margin:6px 0 0;padding-left:20px">
<?php foreach ($db_fehler as $db_f) { ?><li><?= $db_f ?></li><?php } ?>
</ul></div>
<?php } ?>

<table class="sm-tabelle" style="max-width:620px">
<tr><th><?= db_e(db_t('ALLG.EIGENSCHAFT')) ?></th><th><?= db_e(db_t('ALLG.WERT')) ?></th></tr>
<tr><td><?= db_e(db_t('ALLG.DIENST')) ?></td>
    <td class="<?= $db_pid ? 'sm-an' : 'sm-aus' ?>"><?= $db_pid
        ? db_e(db_t('ALLG.LAEUFT')) . ' (PID ' . (int) $db_pid . ')'
        : db_e(db_t('ALLG.GESTOPPT')) ?></td></tr>
<tr><td><?= db_e(db_t('ALLG.MINISERVER')) ?></td>
    <td><?= $db_ms ? db_e($db_ms['name'] . ' - ' . $db_ms['adresse'] . ':' . $db_ms['port'])
                   : '<span class="sm-aus">' . db_e(db_t('ALLG.KEIN_MS')) . '</span>' ?></td></tr>
<tr><td><?= db_e(db_t('ALLG.BAUSTEINE')) ?></td>
    <td><?= count($db_bausteine) ?><?= isset($db_struktur['lastModified'])
        ? ' <span class="sm-hilfe">(' . db_e($db_struktur['lastModified']) . ')</span>' : '' ?></td></tr>
<tr><td><?= db_e(db_t('ALLG.DASHBOARDS')) ?></td>
    <td><?= count($db_seiten) ?> <?= db_e(db_t('ALLG.SEITEN')) ?>,
        <?= (int) $db_kachelzahl ?> <?= db_e(db_t('ALLG.KACHELN')) ?></td></tr>
<tr><td><?= db_e(db_t('ALLG.WERTE')) ?></td>
    <td class="<?= ($db_alter >= 0 && $db_alter < 60) ? 'sm-an' : 'sm-aus' ?>"><?= $db_alter < 0
        ? db_e(db_t('ALLG.KEINE_WERTE'))
        : sprintf(db_e(db_t('ALLG.ALTER')), (int) $db_alter)
          . (isset($db_zustand['weg']) && $db_zustand['weg'] === 'http'
             ? ' &mdash; ' . db_e(db_t('ALLG.UEBER_HTTP')) : '') ?></td></tr>
</table>

<!-- Die Leiste steht AUSGESCHRIEBEN da, obwohl $db_reiter sie erzeugen
     koennte. Grund: hausstandard_pruefen.py sucht 'data-ziel="tab-…"' im
     Quelltext. Eine Schleife macht das Werkzeug blind - es meldete die Reiter
     dann als "0 gefunden". Eine Korrektur, die eine Pruefung blind macht, ist
     keine (dieselbe Falle wie die printf-Reiterleiste vom 16.08.2026).

     Auseinanderlaufen kann sie trotzdem nicht: der Reiter Test vergleicht
     diese Leiste, die Bereiche und $db_reiter miteinander und meldet jede
     Abweichung. -->
<div class="sm-tabs">
  <a href="index.php?form=settings" class="sm-tab<?= $db_tab === 'tab-settings' ? ' sm-active' : '' ?>" data-ziel="tab-settings"><?= db_e(db_t('REITER.EINSTELLUNGEN')) ?></a>
  <a href="index.php?form=boards" class="sm-tab<?= $db_tab === 'tab-boards' ? ' sm-active' : '' ?>" data-ziel="tab-boards"><?= db_e(db_t('REITER.DASHBOARDS')) ?></a>
  <a href="index.php?form=designer" class="sm-tab<?= $db_tab === 'tab-designer' ? ' sm-active' : '' ?>" data-ziel="tab-designer"><?= db_e(db_t('REITER.DESIGNER')) ?></a>
  <a href="index.php?form=loxone" class="sm-tab<?= $db_tab === 'tab-loxone' ? ' sm-active' : '' ?>" data-ziel="tab-loxone"><?= db_e(db_t('REITER.LOXONE')) ?></a>
  <a href="index.php?form=test" class="sm-tab<?= $db_tab === 'tab-test' ? ' sm-active' : '' ?>" data-ziel="tab-test"><?= db_e(db_t('REITER.TEST')) ?></a>
  <a href="index.php?form=log" class="sm-tab<?= $db_tab === 'tab-log' ? ' sm-active' : '' ?>" data-ziel="tab-log"><?= db_e(db_t('REITER.LOG')) ?></a>
</div>

<!-- ================= Einstellungen ================= -->
<div class="sm-seite<?= $db_tab === 'tab-settings' ? ' sm-active' : '' ?>" id="tab-settings">
<div class="sm-hinweis"><?= db_t('EINST.WAS_IST_DAS') ?></div>

<h2><?= db_e(db_t('EINST.H_DIENST')) ?></h2>
<p class="sm-hilfe"><?= db_t('EINST.DIENST_ERKLAERUNG') ?></p>
<div class="sm-legende">
  <span><i class="sm-punkt sm-b-lesen"></i> <?= db_t('LEGENDE.LESEN') ?></span>
  <span><i class="sm-punkt sm-b-aktion"></i> <?= db_t('LEGENDE.AKTION') ?></span>
</div>
<form action="index.php" method="post">
  <?php echo db_fmt(); ?>
  <input data-role="none" type="hidden" name="activetab" value="tab-settings">
  <div class="sm-knopfreihe">
  <!-- Die Trennlinie zwischen Gruen und Orange ist nicht "hat eine Wirkung",
       sondern "kann den Betrieb stoeren". Ein Dienststart ist umkehrbar und
       harmlos, also gruen; Anhalten und Neustarten greifen in den laufenden
       Betrieb ein, also orange. Bis 0.9.5 war der Start orange. -->
  <button data-role="none" class="sm-btn sm-b-lesen" name="dienst" value="start"><?= db_e(db_t('EINST.K_START')) ?></button>
  <button data-role="none" class="sm-btn sm-b-aktion" name="dienst" value="restart"><?= db_e(db_t('EINST.K_NEUSTART')) ?></button>
  <button data-role="none" class="sm-btn sm-b-aktion" name="dienst" value="stop"><?= db_e(db_t('EINST.K_STOP')) ?></button>
  </div>
</form>

<form action="index.php" method="post">
  <?php echo db_fmt(); ?>
<input data-role="none" type="hidden" name="activetab" value="tab-settings">

<h2><?= db_e(db_t('EINST.H_MS')) ?></h2>
<p class="sm-hilfe"><?= db_t('EINST.MS_ERKLAERUNG') ?></p>
<div class="sm-feld">
  <label for="miniserver"><?= db_e(db_t('EINST.L_MINISERVER')) ?></label>
  <select data-role="none" name="miniserver" id="miniserver">
  <?php $db_liste = db_miniserver_liste();
        if (!$db_liste) { $db_liste = array('1' => db_t('EINST.KEINE_LISTE')); }
        foreach ($db_liste as $db_nr => $db_bez) { ?>
    <option value="<?= db_e($db_nr) ?>"<?= ((string) $db_cfg['miniserver'] === (string) $db_nr) ? ' selected' : '' ?>><?= db_e($db_bez) ?></option>
  <?php } ?>
  </select>
</div>
<label><input data-role="none" type="checkbox" name="tls" value="1"<?= !empty($db_cfg['tls']) ? ' checked' : '' ?>>
  <?= db_e(db_t('EINST.L_TLS')) ?></label>
<p class="sm-hilfe"><?= db_t('EINST.H_TLS') ?></p>

<h3><?= db_e(db_t('EINST.H_ZUGANG')) ?></h3>
<p class="sm-hilfe"><?= db_t('EINST.ZUGANG_ERKLAERUNG') ?></p>
<div class="sm-feld">
  <label for="z_adresse"><?= db_e(db_t('EINST.L_ZADRESSE')) ?></label>
  <input data-role="none" type="text" name="z_adresse" id="z_adresse"
         value="<?= db_e(isset($db_zugang['adresse']) ? $db_zugang['adresse'] : '') ?>"
         placeholder="<?= db_e(db_t('EINST.P_ZADRESSE')) ?>">
</div>
<div class="sm-feld">
  <label for="z_port"><?= db_e(db_t('EINST.L_ZPORT')) ?></label>
  <input data-role="none" type="text" name="z_port" id="z_port"
         value="<?= db_e(isset($db_zugang['port']) ? $db_zugang['port'] : '80') ?>">
</div>
<div class="sm-feld">
  <label for="z_benutzer"><?= db_e(db_t('EINST.L_ZBENUTZER')) ?></label>
  <input data-role="none" type="text" name="z_benutzer" id="z_benutzer"
         value="<?= db_e(isset($db_zugang['benutzer']) ? $db_zugang['benutzer'] : '') ?>">
</div>
<div class="sm-feld">
  <label for="z_passwort"><?= db_e(db_t('EINST.L_ZPASSWORT')) ?></label>
  <input data-role="none" type="password" name="z_passwort" id="z_passwort" value=""
         placeholder="<?= db_e(!empty($db_zugang['passwort']) ? db_t('EINST.PW_DA') : db_t('EINST.PW_LEER')) ?>">
  <p class="sm-hilfe"><?= db_t('EINST.H_ZPASSWORT') ?></p>
</div>

<h3><?= db_e(db_t('EINST.H_GESICHERT')) ?></h3>
<p class="sm-hilfe"><?= db_t('EINST.GESICHERT_ERKLAERUNG') ?></p>
<div class="sm-warnung"><?= db_t('EINST.GESICHERT_WARNUNG') ?></div>
<div class="sm-feld">
  <label for="visu_pw"><?= db_e(db_t('EINST.L_VISU_PW')) ?></label>
  <input data-role="none" type="password" name="visu_pw" id="visu_pw" value=""
         placeholder="<?= db_e(db_visu_da() ? db_t('EINST.PW_DA') : db_t('EINST.PW_LEER')) ?>">
  <p class="sm-hilfe"><?= db_t('EINST.H_VISU_PW') ?></p>
</div>
<label><input data-role="none" type="checkbox" name="gesichert_schalten" value="1"<?= !empty($db_cfg['gesichert_schalten']) ? ' checked' : '' ?>>
  <?= db_e(db_t('EINST.L_GESICHERT')) ?></label>
<p class="sm-hilfe"><?= db_t('EINST.H_GESICHERT_SCHALTEN') ?></p>

<h2><?= db_e(db_t('EINST.H_TAKT')) ?></h2>
<div class="sm-feld">
  <label for="takt"><?= db_e(db_t('EINST.L_TAKT')) ?></label>
  <input data-role="none" type="text" name="takt" id="takt" value="<?= db_e($db_cfg['takt']) ?>">
  <p class="sm-hilfe"><?= db_t('EINST.H_TAKT') ?></p>
</div>
<label><input data-role="none" type="checkbox" name="http_rueckfall" value="1"<?= !empty($db_cfg['http_rueckfall']) ? ' checked' : '' ?>>
  <?= db_e(db_t('EINST.L_RUECKFALL')) ?></label>
<p class="sm-hilfe"><?= db_t('EINST.H_RUECKFALL') ?></p>
<div class="sm-feld">
  <label for="http_takt"><?= db_e(db_t('EINST.L_HTTP_TAKT')) ?></label>
  <input data-role="none" type="text" name="http_takt" id="http_takt" value="<?= db_e($db_cfg['http_takt']) ?>">
</div>
<div class="sm-feld">
  <label for="wartezeit"><?= db_e(db_t('EINST.L_WARTEZEIT')) ?></label>
  <input data-role="none" type="text" name="wartezeit" id="wartezeit" value="<?= db_e($db_cfg['wartezeit']) ?>">
  <p class="sm-hilfe"><?= sprintf(db_e(db_t('EINST.H_WARTEZEIT')), DB_WARTEZEIT_MIN, DB_WARTEZEIT_MAX) ?></p>
</div>
<label><input data-role="none" type="checkbox" name="sse" value="1"<?= !empty($db_cfg['sse']) ? ' checked' : '' ?>>
  <?= db_e(db_t('EINST.L_SSE')) ?></label>
<p class="sm-hilfe"><?= db_t('EINST.H_SSE') ?></p>

<h2><?= db_e(db_t('EINST.H_ANZEIGE')) ?></h2>
<div class="sm-feld">
  <label for="farbe"><?= db_e(db_t('EINST.L_FARBE')) ?></label>
  <select data-role="none" name="farbe" id="farbe">
    <option value="dunkel"<?= $db_cfg['farbe'] === 'dunkel' ? ' selected' : '' ?>><?= db_e(db_t('EINST.FARBE_DUNKEL')) ?></option>
    <option value="hell"<?= $db_cfg['farbe'] === 'hell' ? ' selected' : '' ?>><?= db_e(db_t('EINST.FARBE_HELL')) ?></option>
  </select>
</div>
<label><input data-role="none" type="checkbox" name="vollbild" value="1"<?= !empty($db_cfg['vollbild']) ? ' checked' : '' ?>>
  <?= db_e(db_t('EINST.L_VOLLBILD')) ?></label><br>
<label><input data-role="none" type="checkbox" name="wach" value="1"<?= !empty($db_cfg['wach']) ? ' checked' : '' ?>>
  <?= db_e(db_t('EINST.L_WACH')) ?></label>
<p class="sm-hilfe"><?= db_t('EINST.H_WACH') ?></p>
<label><input data-role="none" type="checkbox" name="haptik" value="1"<?= !empty($db_cfg['haptik']) ? ' checked' : '' ?>>
  <?= db_e(db_t('EINST.L_HAPTIK')) ?></label>
<p class="sm-hilfe"><?= db_t('EINST.H_HAPTIK') ?></p>
<label><input data-role="none" type="checkbox" name="steuerung_ein" value="1"<?= !empty($db_cfg['steuerung_ein']) ? ' checked' : '' ?>>
  <?= db_e(db_t('EINST.L_STEUERUNG')) ?></label>
<p class="sm-hilfe"><?= db_t('EINST.H_STEUERUNG') ?></p>

<h2><?= db_e(db_t('EINST.H_WANDTABLET')) ?></h2>
<p class="sm-hilfe"><?= db_t('EINST.WANDTABLET_ERKLAERUNG') ?></p>
<div class="sm-feld">
  <label for="rotation"><?= db_e(db_t('EINST.L_ROTATION')) ?></label>
  <input data-role="none" type="text" name="rotation" id="rotation" value="<?= db_e($db_cfg['rotation']) ?>">
  <p class="sm-hilfe"><?= db_t('EINST.H_ROTATION') ?></p>
</div>
<div class="sm-feld">
  <label for="nacht_von"><?= db_e(db_t('EINST.L_NACHT')) ?></label>
  <input data-role="none" type="text" name="nacht_von" id="nacht_von" size="6"
         value="<?= db_e($db_cfg['nacht_von']) ?>" placeholder="22:30">
  <input data-role="none" type="text" name="nacht_bis" id="nacht_bis" size="6"
         value="<?= db_e($db_cfg['nacht_bis']) ?>" placeholder="06:00">
  <p class="sm-hilfe"><?= db_t('EINST.H_NACHT') ?></p>
</div>
<div class="sm-feld">
  <label for="nacht_helligkeit"><?= db_e(db_t('EINST.L_NACHT_HELLIGKEIT')) ?></label>
  <input data-role="none" type="text" name="nacht_helligkeit" id="nacht_helligkeit"
         value="<?= db_e($db_cfg['nacht_helligkeit']) ?>">
</div>
<label><input data-role="none" type="checkbox" name="verlauf" value="1"<?= !empty($db_cfg['verlauf']) ? ' checked' : '' ?>>
  <?= db_e(db_t('EINST.L_VERLAUF')) ?></label>
<p class="sm-hilfe"><?= db_t('EINST.H_VERLAUF') ?></p>
<div class="sm-feld">
  <label for="verlauf_punkte"><?= db_e(db_t('EINST.L_VERLAUF_PUNKTE')) ?></label>
  <input data-role="none" type="text" name="verlauf_punkte" id="verlauf_punkte"
         value="<?= db_e($db_cfg['verlauf_punkte']) ?>">
</div>
<label><input data-role="none" type="checkbox" name="tafelsteuerung" value="1"<?= !empty($db_cfg['tafelsteuerung']) ? ' checked' : '' ?>>
  <?= db_e(db_t('EINST.L_TAFELSTEUERUNG')) ?></label>
<p class="sm-hilfe"><?= db_t('EINST.H_TAFELSTEUERUNG') ?></p>

<div class="sm-knopfreihe">
<button data-role="none" class="sm-btn sm-b-aktion" name="speichern" value="1"><?= db_e(db_t('ALLG.SPEICHERN')) ?></button>
</div>
</form>

<h2><?= db_t('EINST.H_SICHERUNG') ?></h2>
<div class="sm-hinweis"><?= db_t('EINST.SICH_ERKLAERUNG') ?></div>
<div class="sm-warnung"><?= db_t('EINST.SICH_WARNUNG') ?></div>
<div class="sm-knopfreihe">
  <!-- ZWEI GETRENNTE Formulare. Das Sichern schickt einen Download und ruft
       exit auf; das Zurueckspielen braucht enctype="multipart/form-data".
       Wer beides in ein Formular legt, bekommt entweder keinen Upload oder
       einen Download, der das Speichern verschluckt. -->
  <form action="index.php" method="post">
    <?php echo db_fmt(); ?>
    <input data-role="none" type="hidden" name="activetab" value="tab-settings">
    <button data-role="none" class="sm-btn sm-b-lesen" type="submit" name="db_sichern" value="1"><?= db_t('EINST.K_SICHERN') ?></button>
  </form>
  <form action="index.php" method="post" enctype="multipart/form-data">
    <?php echo db_fmt(); ?>
    <input data-role="none" type="hidden" name="activetab" value="tab-settings">
    <input data-role="none" type="file" name="db_sicherung" accept=".json">
    <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="db_zurueck" value="1"><?= db_t('EINST.K_ZURUECK') ?></button>
  </form>
</div>
</div>

<!-- ================= Dashboards ================= -->
<div class="sm-seite<?= $db_tab === 'tab-boards' ? ' sm-active' : '' ?>" id="tab-boards">
<h2><?= db_e(db_t('BOARD.H_ENTWURF')) ?></h2>
<p class="sm-hilfe"><?= db_t('BOARD.ENTWURF_ERKLAERUNG') ?></p>
<div class="sm-legende">
  <span><i class="sm-punkt sm-b-lesen"></i> <?= db_t('LEGENDE.LESEN') ?></span>
  <span><i class="sm-punkt sm-b-aktion"></i> <?= db_t('LEGENDE.AKTION') ?></span>
</div>
<form action="index.php" method="post">
  <?php echo db_fmt(); ?>
  <input data-role="none" type="hidden" name="activetab" value="tab-boards">
  <div class="sm-knopfreihe">
  <button data-role="none" class="sm-btn sm-b-lesen" name="struktur_holen" value="1"><?= db_e(db_t('BOARD.K_STRUKTUR')) ?></button>
  <button data-role="none" class="sm-btn sm-b-aktion" name="entwurf" value="ergaenzen"><?= db_e(db_t('BOARD.K_ENTWURF')) ?></button>
  <button data-role="none" class="sm-btn sm-b-aktion" name="entwurf" value="vonvorn"
          onclick="return confirm(<?= db_e(json_encode(strip_tags(html_entity_decode(db_t('BOARD.VONVORN_FRAGE'), ENT_QUOTES, 'UTF-8')))) ?>)"><?= db_e(db_t('BOARD.K_VONVORN')) ?></button>
  </div>
</form>
<div class="sm-warnung"><?= db_t('BOARD.VONVORN_WARNUNG') ?></div>

<h2><?= db_e(db_t('BOARD.H_SEITEN')) ?></h2>
<?php if (!$db_seiten) { ?>
<div class="sm-warnung"><?= db_t('BOARD.KEINE_SEITEN') ?></div>
<?php } else { ?>
<form action="index.php" method="post">
  <?php echo db_fmt(); ?>
<input data-role="none" type="hidden" name="activetab" value="tab-boards">
<table class="sm-tabelle">
<tr><th><?= db_e(db_t('BOARD.T_NAME')) ?></th><th><?= db_e(db_t('BOARD.T_KACHELN')) ?></th>
    <th><?= db_e(db_t('BOARD.T_SPALTEN')) ?></th><th><?= db_e(db_t('BOARD.T_PIN')) ?></th>
    <th><?= db_e(db_t('BOARD.T_ADRESSE')) ?></th><th><?= db_e(db_t('BOARD.T_WEG')) ?></th></tr>
<?php foreach ($db_seiten as $db_i => $db_s) {
    $db_k = (string) $db_s['schluessel']; ?>
<tr>
  <td><input data-role="none" type="text" name="s_name[<?= (int) $db_i ?>]"
             value="<?= db_e($db_s['name']) ?>" size="18">
      <div class="sm-hilfe sm-mono"><?= db_e($db_k) ?></div></td>
  <td><?= count(isset($db_s['kacheln']) ? $db_s['kacheln'] : array()) ?></td>
  <td><input data-role="none" type="text" name="s_spalten[<?= (int) $db_i ?>]"
             value="<?= (int) (isset($db_s['spalten']) ? $db_s['spalten'] : 6) ?>" size="3"></td>
  <td><input data-role="none" type="password" name="s_pin[<?= (int) $db_i ?>]" value="" size="8"
             placeholder="<?= db_e(!empty($db_s['pin']) ? db_t('BOARD.PIN_DA') : db_t('BOARD.PIN_LEER')) ?>">
      <?php if (!empty($db_s['pin'])) { ?>
      <div class="sm-hilfe"><label><input data-role="none" type="checkbox"
        name="s_pinweg[<?= (int) $db_i ?>]" value="1"> <?= db_e(db_t('BOARD.PIN_WEG')) ?></label></div>
      <?php } ?></td>
  <td><a href="<?= db_e(db_tafel_adresse($db_k)) ?>" target="_blank"
         class="sm-mono" style="font-size:0.8em"><?= db_e(db_t('BOARD.OEFFNEN')) ?></a>
      <div class="sm-hilfe sm-mono" style="font-size:0.75em"><?= db_e(db_tafel_adresse($db_k)) ?></div></td>
  <td><label><input data-role="none" type="checkbox" name="s_weg[<?= (int) $db_i ?>]" value="1"></label></td>
</tr>
<?php } ?>
</table>
<div class="sm-knopfreihe">
<button data-role="none" class="sm-btn sm-b-aktion" name="seiten_speichern" value="1"><?= db_e(db_t('ALLG.SPEICHERN')) ?></button>
</div>
</form>
<div class="sm-hinweis"><?= db_t('BOARD.ADRESSE_HINWEIS') ?></div>
<?php } ?>

<?php if ($db_ausgabe !== '') { ?>
<h3><?= db_e(db_t('BOARD.H_AUSGABE')) ?></h3>
<div class="sm-log"><?= db_e($db_ausgabe) ?></div>
<?php } ?>
</div>

<!-- ================= Designer ================= -->
<div class="sm-seite<?= $db_tab === 'tab-designer' ? ' sm-active' : '' ?>" id="tab-designer">
<h2><?= db_e(db_t('DESIGN.H_TITEL')) ?></h2>
<div class="sm-legende">
  <span><i class="sm-punkt sm-b-technik"></i> <?= db_t('LEGENDE.TECHNIK') ?></span>
  <span><i class="sm-punkt sm-b-aktion"></i> <?= db_t('LEGENDE.AKTION') ?></span>
</div>
<?php if (!$db_bausteine) { ?>
<div class="sm-warnung"><?= db_t('DESIGN.KEINE_STRUKTUR') ?></div>
<?php } else { ?>
<p class="sm-hilfe"><?= db_t('DESIGN.ERKLAERUNG') ?></p>
<div class="sm-warnung"><?= db_t('DESIGN.WARNUNG') ?></div>

<div class="sm-knopfreihe">
  <button data-role="none" type="button" class="sm-btn sm-b-aktion" id="dz-neu">+ <?= db_e(db_t('DESIGN.NEUE_SEITE')) ?></button>
  <button data-role="none" type="button" class="sm-btn sm-b-technik" id="dz-szene">+ <?= db_e(db_t('DESIGN.SZENE_NEU')) ?></button>
</div>

<div id="dz-bau"></div>

<form action="index.php" method="post" id="dz-form">
  <?php echo db_fmt(); ?>
  <input data-role="none" type="hidden" name="activetab" value="tab-designer">
  <input data-role="none" type="hidden" name="aufbau" id="dz-aufbau" value="">
  <div class="sm-knopfreihe">
  <button data-role="none" class="sm-btn sm-b-aktion" name="designer_speichern" value="1"><?= db_e(db_t('DESIGN.K_SPEICHERN')) ?></button>
  </div>
  <span class="sm-hilfe" id="dz-stand"></span>
</form>
<?php } ?>
</div>

<!-- ================= Einbindung in Loxone ================= -->
<div class="sm-seite<?= $db_tab === 'tab-loxone' ? ' sm-active' : '' ?>" id="tab-loxone">
<h2><?= db_e(db_t('LOX.H_TITEL')) ?></h2>
<p class="sm-hilfe"><?= db_t('LOX.EINLEITUNG') ?></p>
<div class="sm-legende">
  <span><i class="sm-punkt sm-b-technik"></i> <?= db_t('LEGENDE.TECHNIK') ?></span>
  <span><i class="sm-punkt sm-b-aktion"></i> <?= db_t('LEGENDE.AKTION') ?></span>
</div>

<div class="sm-step">
<h3><?= db_e(db_t('LOX.S1_TITEL')) ?></h3>
<p class="sm-hilfe"><?= db_t('LOX.S1_TEXT') ?></p>
<table class="sm-tabelle">
<tr><th><?= db_e(db_t('LOX.T_ADRESSE')) ?></th><th><?= db_e(db_t('LOX.T_TITEL')) ?></th>
    <th><?= db_e(db_t('LOX.T_BEFEHL')) ?></th><th><?= db_e(db_t('LOX.T_BEDEUTUNG')) ?></th></tr>
<?php $db_erste = true; foreach (db_status_felder() as $db_feld => $db_info) { ?>
<tr><?php if ($db_erste) { $db_erste = false; ?>
  <td rowspan="<?= count(db_status_felder()) ?>"><span class="sm-mono">http://<?= db_e(isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'loxberry') ?>/plugins/<?= db_e($db_p['plugin']) ?>/index.php?token=<?= db_e($db_token) ?>&amp;aktion=status</span></td>
  <?php } ?>
  <td class="sm-mono">DASHBOARD_<?= db_e($db_feld) ?></td>
  <td class="sm-mono">\i<?= db_e($db_feld) ?>=\i\v</td>
  <td><?= db_t($db_info[1]) ?><?= $db_info[0] !== '' ? ' [' . db_e($db_info[0]) . ']' : '' ?></td></tr>
<?php } ?>
</table>
<h4 style="margin:14px 0 2px"><?= db_e(db_t('LOX.ALLES_TITEL')) ?></h4>
<p class="sm-hilfe"><?= db_t('LOX.ALLES_TEXT') ?></p>
<form action="index.php" method="post">
  <?php echo db_fmt(); ?>
  <input data-role="none" type="hidden" name="activetab" value="tab-loxone">
  <div class="sm-knopfreihe">
  <button data-role="none" class="sm-btn sm-b-technik" name="vorlage" value="1"><?= db_e(db_t('LOX.K_VORLAGE')) ?></button>
  <button data-role="none" class="sm-btn sm-b-technik" name="vorlage_out" value="1"><?= db_e(db_t('LOX.K_VORLAGE_OUT')) ?></button>
  </div>
</form>
<div class="sm-warnung"><?= db_t('LOX.IMPORT_WARNUNG') ?></div>
</div>

<div class="sm-step">
<h3><?= db_e(db_t('LOX.S2_TITEL')) ?></h3>
<p class="sm-hilfe"><?= db_t('LOX.S2_TEXT') ?></p>
<div class="sm-hinweis"><?= db_t('LOX.S2_HINWEIS') ?></div>
</div>

<!-- Steuerbefehle: der Miniserver schaltet die Anzeigeseite um. -->
<div class="sm-step">
<h3><?= db_e(db_t('LOX.SOUT_TITEL')) ?></h3>
<p class="sm-hilfe"><?= db_t('LOX.SOUT_TEXT') ?></p>
<?php if (empty($db_cfg['tafelsteuerung'])) { ?>
<div class="sm-warnung"><?= db_t('LOX.SOUT_AUS') ?></div>
<?php } ?>
<table class="sm-tabelle">
<tr><th><?= db_e(db_t('LOX.T_BEFEHL')) ?></th><th><?= db_e(db_t('LOX.T_BEDEUTUNG')) ?></th></tr>
<?php foreach (db_tafel_befehle() as $db_tb) { ?>
<tr><td class="sm-mono">…&amp;aktion=tafel&amp;seite=<?= db_e($db_tb['wert']) ?></td>
    <td><?= sprintf(db_e(db_t('LOX.SOUT_SEITE')), db_e($db_tb['name'])) ?></td></tr>
<?php } ?>
<tr><td class="sm-mono">…&amp;aktion=tafel&amp;wach=1</td><td><?= db_t('LOX.SOUT_WACH') ?></td></tr>
<tr><td class="sm-mono">…&amp;aktion=tafel&amp;hell=&lt;v.0&gt;</td><td><?= db_t('LOX.SOUT_HELL') ?></td></tr>
</table>
</div>

<!-- Ausfallerkennung: der Schritt, den der Hausstandard ausdruecklich
     verlangt. Ein virtueller Eingang behaelt seinen letzten Wert - in der
     App sieht dann alles normal aus, waehrend nichts mehr ankommt. -->
<div class="sm-step">
<h3><?= db_e(db_t('LOX.SAUS_TITEL')) ?></h3>
<p class="sm-hilfe"><?= db_t('LOX.SAUS_TEXT') ?></p>
<div class="sm-hinweis"><?= db_t('LOX.SAUS_HINWEIS') ?></div>
</div>

<div class="sm-step">
<h3><?= db_e(db_t('LOX.S3_TITEL')) ?></h3>
<p class="sm-hilfe"><?= db_t('LOX.S3_TEXT') ?></p>
<table class="sm-tabelle">
<tr><th>#</th><th><?= db_e(db_t('LOX.T_BAUSTEIN')) ?></th><th><?= db_e(db_t('LOX.T_NAMENSVORSCHLAG')) ?></th>
    <th><?= db_e(db_t('LOX.T_PARAMETER')) ?></th><th><?= db_e(db_t('LOX.T_EINGAENGE')) ?></th></tr>
<?php
$db_bausteinliste = array(
    array(1,  'BAUSTEIN.T_VE',      'BAUSTEIN.N01', 'BAUSTEIN.P01', '&mdash;'),
    array(2,  'BAUSTEIN.T_VE',      'BAUSTEIN.N02', 'BAUSTEIN.P02', '&mdash;'),
    array(3,  'BAUSTEIN.T_VE',      'BAUSTEIN.N03', 'BAUSTEIN.P03', '&mdash;'),
    array(4,  'BAUSTEIN.T_NICHT',   'BAUSTEIN.N04', '',             'I &larr; #1'),
    array(5,  'BAUSTEIN.T_SWS',     'BAUSTEIN.N05', 'BAUSTEIN.P05', 'I &larr; #2'),
    array(6,  'BAUSTEIN.T_VERGL',   'BAUSTEIN.N06', 'BAUSTEIN.P06', 'I &larr; #3'),
    array(7,  'BAUSTEIN.T_ODER',    'BAUSTEIN.N07', '',             'I1 &larr; #4, I2 &larr; #5, I3 &larr; #6'),
    array(8,  'BAUSTEIN.T_EVZ',     'BAUSTEIN.N08', 'BAUSTEIN.P08', 'I &larr; #7'),
    array(9,  'BAUSTEIN.T_BENACHR', 'BAUSTEIN.N09', 'BAUSTEIN.P09', 'I &larr; #8'),
    array(10, 'BAUSTEIN.T_STATUS',  'BAUSTEIN.N10', 'BAUSTEIN.P10', 'I &larr; #2'),
);
foreach ($db_bausteinliste as $db_z) { ?>
<?php /* Die Werte kommen aus der Sprachdatei, nicht vom Anwender: sie
   duerfen ihre Entitaeten behalten. Werden sie zusaetzlich maskiert, steht
   der Entitaetsname woertlich auf dem Bildschirm. */ ?>
<tr><td><?= (int) $db_z[0] ?></td><td><?= db_t($db_z[1]) ?></td>
    <td class="sm-mono"><?= db_t($db_z[2]) ?></td>
    <td><?= $db_z[3] !== '' ? db_t($db_z[3]) : '&mdash;' ?></td>
    <td class="sm-mono"><?= $db_z[4] ?></td></tr>
<?php } ?>
</table>
<div class="sm-hinweis"><?= db_t('LOX.S3_ERLAEUTERUNG') ?></div>
</div>

<div class="sm-step">
<h3><?= db_e(db_t('LOX.S4_TITEL')) ?></h3>
<p class="sm-hilfe"><?= db_t('LOX.S4_TEXT') ?></p>
<table class="sm-tabelle">
<tr><th><?= db_e(db_t('LOX.T_TOKEN')) ?></th><td class="sm-mono"><?= db_e($db_token) ?></td></tr>
</table>
<form action="index.php" method="post">
  <?php echo db_fmt(); ?>
  <input data-role="none" type="hidden" name="activetab" value="tab-loxone">
  <div class="sm-knopfreihe">
  <button data-role="none" class="sm-btn sm-b-aktion" name="token_neu" value="1"
    onclick="return confirm(<?= db_e(json_encode(strip_tags(html_entity_decode(db_t('LOX.TOKEN_FRAGE'), ENT_QUOTES, 'UTF-8')))) ?>)"><?= db_e(db_t('LOX.K_TOKEN_NEU')) ?></button>
  </div>
</form>
</div>

<div class="sm-step">
<h3><?= db_e(db_t('LOX.S5_TITEL')) ?></h3>
<p class="sm-hilfe"><?= db_t('LOX.S5_TEXT') ?></p>
<table class="sm-tabelle">
<tr><th><?= db_e(db_t('LOX.T_PRUEFUNG')) ?></th><th><?= db_e(db_t('LOX.T_ERWARTUNG')) ?></th></tr>
<tr><td class="sm-mono">index.php?token=<?= db_e($db_token) ?>&amp;aktion=status</td><td><?= db_t('LOX.E1') ?></td></tr>
<tr><td class="sm-mono">index.php?token=falsch&amp;aktion=status</td><td><?= db_t('LOX.E2') ?></td></tr>
<tr><td class="sm-mono">index.php?token=<?= db_e($db_token) ?>&amp;aktion=neustart</td><td><?= db_t('LOX.E3') ?></td></tr>
<tr><td class="sm-mono">index.php?selftest=1&amp;token=<?= db_e($db_token) ?></td><td><?= db_t('LOX.E4') ?></td></tr>
<tr><td class="sm-mono">index.php?selftest=1&amp;token=falsch</td><td><?= db_t('LOX.E5') ?></td></tr>
</table>
<div class="sm-hinweis"><?= db_t('LOX.E_HINWEIS') ?></div>
</div>
</div>

<!-- ================= Test ================= -->
<div class="sm-seite<?= $db_tab === 'tab-test' ? ' sm-active' : '' ?>" id="tab-test">
<h2><?= db_e(db_t('TEST.H_SELBSTPRUEFUNG')) ?></h2>
<p class="sm-hilfe"><?= db_t('TEST.EINLEITUNG') ?></p>
<?= db_pruefungen_html() ?>

<h2><?= db_e(db_t('TEST.H_LESEN')) ?></h2>
<div class="sm-legende">
  <span><i class="sm-punkt sm-b-lesen"></i> <?= db_t('LEGENDE.LESEN') ?></span>
  <span><i class="sm-punkt sm-b-technik"></i> <?= db_t('LEGENDE.TECHNIK') ?></span>
</div>
<form action="index.php" method="post">
  <?php echo db_fmt(); ?>
  <input data-role="none" type="hidden" name="activetab" value="tab-test">
  <div class="sm-knopfreihe">
  <button data-role="none" class="sm-btn sm-b-lesen" name="test" value="status"><?= db_e(db_t('TEST.K_STATUS')) ?></button>
  <button data-role="none" class="sm-btn sm-b-technik" name="test" value="roh"><?= db_e(db_t('TEST.K_ROH')) ?></button>
  <button data-role="none" class="sm-btn sm-b-technik" name="selbsttest" value="1"><?= db_e(db_t('TEST.K_SELBSTTEST')) ?></button>
  </div>
</form>

<h2><?= db_e(db_t('TEST.H_MESSEN')) ?></h2>
<p class="sm-hilfe"><?= db_t('TEST.MESSEN_ERKLAERUNG') ?></p>
<form action="index.php" method="post">
  <?php echo db_fmt(); ?>
  <input data-role="none" type="hidden" name="activetab" value="tab-test">
  <div class="sm-knopfreihe">
  <button data-role="none" class="sm-btn sm-b-lesen" name="probe" value="anmeldeprobe"><?= db_e(db_t('TEST.K_ANMELDUNG')) ?></button>
  <button data-role="none" class="sm-btn sm-b-lesen" name="probe" value="httpprobe"><?= db_e(db_t('TEST.K_HTTPPROBE')) ?></button>
  <button data-role="none" class="sm-btn sm-b-lesen" name="probe" value="visuprobe"><?= db_e(db_t('TEST.K_VISUPROBE')) ?></button>
  </div>
</form>
<div class="sm-warnung"><?= db_t('TEST.MESSEN_WARNUNG') ?></div>

<?php if ($db_ausgabe !== '' && $db_tab === 'tab-test') { ?>
<div class="sm-log"><?= db_e($db_ausgabe) ?></div>
<?php } ?>

<h2><?= db_e(db_t('TEST.H_UNGEPRUEFT')) ?></h2>
<div class="sm-warnung"><?= db_t('TEST.UNGEPRUEFT') ?></div>
</div>

<!-- ================= Logdateien ================= -->
<div class="sm-seite<?= $db_tab === 'tab-log' ? ' sm-active' : '' ?>" id="tab-log">
<h2><?= db_e(db_t('LOG.H_TITEL')) ?></h2>
<p class="sm-hilfe"><?= db_t('LOG.ERKLAERUNG') ?></p>
<p class="sm-hilfe sm-mono"><?= db_e($db_p['log']) ?></p>
<?php
$db_zeilen = array();
if (is_file($db_p['log'])) {
    $db_alleszeilen = @file($db_p['log'], FILE_IGNORE_NEW_LINES);
    if (is_array($db_alleszeilen)) { $db_zeilen = array_slice($db_alleszeilen, -400); }
}
if (!$db_zeilen) { ?>
<div class="sm-hinweis"><?= db_t('LOG.LEER') ?></div>
<?php } else { ?>
<div class="sm-log"><?= db_e(implode("\n", $db_zeilen)) ?></div>
<?php } ?>
<div class="sm-legende">
  <span><i class="sm-punkt sm-b-aktion"></i> <?= db_t('LEGENDE.AKTION_LOG') ?></span>
</div>
<form action="index.php" method="post">
  <?php echo db_fmt(); ?>
  <input data-role="none" type="hidden" name="activetab" value="tab-log">
  <div class="sm-knopfreihe">
  <button data-role="none" class="sm-btn sm-b-aktion" name="log_leeren" value="1"><?= db_e(db_t('LOG.K_LEEREN')) ?></button>
  </div>
</form>
</div>

</div><!-- /sm-wrap -->

<script>
(function () {
	var reiter = document.querySelectorAll('.sm-tab');
	function zeige(id) {
		reiter.forEach(function (r) { r.classList.toggle('sm-active', r.dataset.ziel === id); });
		document.querySelectorAll('.sm-seite').forEach(function (s) { s.classList.toggle('sm-active', s.id === id); });
		document.querySelectorAll('input[name="activetab"]').forEach(function (f) { f.value = id; });
		if (history.replaceState) { history.replaceState(null, '', 'index.php?form=' + id.replace('tab-', '')); }
	}
	reiter.forEach(function (r) {
		r.addEventListener('click', function (e) { e.preventDefault(); zeige(r.dataset.ziel); });
	});
	zeige(<?= json_encode($db_tab) ?>);
})();
</script>

<?php if ($db_bausteine) { ?>
<script>
/* ================= Designer =================
 *
 * Ziehen und Ablegen ohne fremde Bibliothek. Bewusst mit den HTML5-Ereignissen
 * dragstart/dragover/drop: sie funktionieren in jedem Browser der letzten zehn
 * Jahre und brauchen kein Nachladen. Auf einem Tablet greifen sie nicht - dafuer
 * gibt es die Pfeilknoepfe an jeder Kachel. Der Designer ist ohnehin fuer den
 * Rechner gedacht, das Ergebnis fuer das Tablet.
 */
(function () {
	var BAUSTEINE = <?= json_encode(array_map(function ($b) {
		return array('uuid' => $b['uuid'], 'name' => $b['name'], 'kachel' => $b['kachel'],
		             'loxtyp' => $b['loxtyp'], 'raum' => $b['raumname'], 'kat' => $b['katname'],
		             'bekannt' => $b['bekannt'],
		             // Die erlaubten Befehle wandern mit, damit der
		             // Schritt-Editor der Szene nur anbietet, was die
		             // Kacheltabelle fuer genau diesen Typ nennt.
		             'befehle' => isset($b['befehle']) ? $b['befehle'] : array(),
		             'nurlesen' => (int) (isset($b['nurlesen']) ? $b['nurlesen'] : 0),
		             'gesichert' => (int) (isset($b['gesichert']) ? $b['gesichert'] : 0));
	}, $db_bausteine), JSON_UNESCAPED_UNICODE) ?>;
	var AUFBAU = <?= json_encode(array('seiten' => $db_seiten), JSON_UNESCAPED_UNICODE) ?>;
	var TYPEN = <?= json_encode(db_kacheltypen(), JSON_UNESCAPED_UNICODE) ?>;
	var GROESSEN = <?= json_encode(array_keys(db_groessen())) ?>;
	var TEXT = <?= json_encode(array(
		'neue'    => strip_tags(db_t('DESIGN.NEUE_SEITE')),
		'frage'   => strip_tags(html_entity_decode(db_t('DESIGN.SEITE_WEG_FRAGE'), ENT_QUOTES, 'UTF-8')),
		'suchen'  => strip_tags(db_t('DESIGN.SUCHEN')),
		'unbenutzt' => strip_tags(db_t('DESIGN.UNBENUTZT')),
		'alle'    => strip_tags(db_t('DESIGN.ALLE')),
		'leer'    => strip_tags(db_t('DESIGN.SEITE_LEER')),
		'geaendert' => strip_tags(db_t('DESIGN.GEAENDERT')),
		'nicht_gespeichert' => strip_tags(html_entity_decode(db_t('DESIGN.NICHT_GESPEICHERT'), ENT_QUOTES, 'UTF-8')),
		'szene_neu'    => strip_tags(db_t('DESIGN.SZENE_NEU')),
		'szene_name'   => strip_tags(db_t('DESIGN.SZENE_NAME')),
		'szene_schritt' => strip_tags(db_t('DESIGN.SZENE_SCHRITT')),
		'szene_leer'   => strip_tags(db_t('DESIGN.SZENE_LEER')),
		'szene_dazu'   => strip_tags(db_t('DESIGN.SZENE_DAZU')),
		'szene_ohne_befehl' => strip_tags(db_t('DESIGN.SZENE_OHNE_BEFEHL')),
	), JSON_UNESCAPED_UNICODE) ?>;

	var bau = document.getElementById('dz-bau');
	var feldAufbau = document.getElementById('dz-aufbau');
	var stand = document.getElementById('dz-stand');
	var geaendert = false;
	var gezogen = null;

	function e(t) { var d = document.createElement('div'); d.textContent = t == null ? '' : String(t); return d.innerHTML; }
	function schluessel(t) {
		return String(t || '').toLowerCase()
			.replace(/ä/g, 'ae').replace(/ö/g, 'oe').replace(/ü/g, 'ue').replace(/ß/g, 'ss')
			.replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '') || 'seite';
	}
	function baustein(u) {
		for (var i = 0; i < BAUSTEINE.length; i++) { if (BAUSTEINE[i].uuid === u) { return BAUSTEINE[i]; } }
		return null;
	}
	function benutzt() {
		var m = {};
		AUFBAU.seiten.forEach(function (s) { (s.kacheln || []).forEach(function (k) { m[k.uuid] = 1; }); });
		return m;
	}
	function markieren() {
		geaendert = true;
		stand.textContent = TEXT.geaendert;
		feldAufbau.value = JSON.stringify(AUFBAU);
	}

	window.addEventListener('beforeunload', function (ev) {
		if (!geaendert) { return; }
		ev.preventDefault();
		ev.returnValue = TEXT.nicht_gespeichert;
		return TEXT.nicht_gespeichert;
	});

	/* Schritt-Editor einer Szene.
	   Angeboten wird NUR, was die Kacheltabelle fuer den gewaehlten
	   Bausteintyp nennt - dieselbe Positivliste, gegen die der Endpunkt und
	   der Dienst spaeter noch einmal pruefen. Bausteine, die auf nur lesen
	   stehen oder gesichert sind, kommen gar nicht erst in die Auswahl. */
	function szene_editor(k) {
		var zeilen = (k.schritte || []).map(function (sch, x) {
			var bb = baustein(sch.uuid);
			return '<div style="display:flex;gap:4px;align-items:center;margin-top:3px">' +
				'<span style="flex:1">' + e((bb ? bb.name : sch.uuid) + ' → ' + sch.befehl) + '</span>' +
				'<button data-role="none" type="button" data-schrittweg="' + x + '" style="padding:0 6px">&times;</button></div>';
		}).join('');
		if (!zeilen) { zeilen = '<div class="sm-hilfe" style="margin-top:3px">' + e(TEXT.szene_leer) + '</div>'; }
		var wahl = BAUSTEINE.filter(function (x) {
			return (x.befehle || []).length && !x.nurlesen && !x.gesichert;
		}).slice(0, 400).map(function (x) {
			return '<option value="' + e(x.uuid) + '">' + e(x.name + (x.raum ? ' · ' + x.raum : '')) + '</option>';
		}).join('');
		return '<div style="border-top:1px dashed #ccc;margin-top:5px;padding-top:4px">' + zeilen +
			'<div style="display:flex;gap:4px;margin-top:5px">' +
			'<select data-role="none" data-szenebaustein="1" style="flex:1;font-size:.95em">' + wahl + '</select>' +
			'<select data-role="none" data-szenebefehl="1" style="font-size:.95em"></select>' +
			'<button data-role="none" type="button" data-schrittdazu="1" style="padding:1px 8px" title="' + e(TEXT.szene_dazu) + '">+</button>' +
			'</div></div>';
	}

	function zeichnen() {
		bau.innerHTML = '';
		var kopf = document.createElement('div');
		kopf.style.cssText = 'display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin:10px 0';
		kopf.innerHTML =
			'<input data-role="none" type="text" id="dz-suche" placeholder="' + e(TEXT.suchen) + '" style="flex:1;min-width:180px;padding:7px 10px;border:1px solid #ccc;border-radius:6px">' +
			'<label style="font-size:.86em"><input data-role="none" type="checkbox" id="dz-nur"> ' + e(TEXT.unbenutzt) + '</label>';
		bau.appendChild(kopf);

		var flaeche = document.createElement('div');
		flaeche.style.cssText = 'display:flex;gap:14px;align-items:flex-start;flex-wrap:wrap';
		bau.appendChild(flaeche);

		/* linke Spalte: Vorrat */
		var vorrat = document.createElement('div');
		vorrat.style.cssText = 'flex:0 0 250px;max-height:560px;overflow:auto;border:1px solid #ddd;border-radius:8px;padding:8px;background:#fafafa';
		vorrat.id = 'dz-vorrat';
		flaeche.appendChild(vorrat);

		/* rechte Spalte: Seiten */
		var rechts = document.createElement('div');
		rechts.style.cssText = 'flex:1;min-width:300px';
		flaeche.appendChild(rechts);

		AUFBAU.seiten.forEach(function (s, si) {
			var kasten = document.createElement('div');
			kasten.style.cssText = 'border:1px solid #ddd;border-radius:8px;margin:0 0 14px;background:#fff';
			var titel = document.createElement('div');
			titel.style.cssText = 'display:flex;gap:8px;align-items:center;padding:8px 10px;background:#f2f7ea;border-bottom:1px solid #ddd;border-radius:8px 8px 0 0';
			titel.innerHTML = '<b style="flex:1">' + e(s.name) + '</b>' +
				'<span class="sm-hilfe">' + (s.kacheln || []).length + '</span>' +
				'<button data-role="none" type="button" class="sm-btn sm-b-technik" data-seiteweg="' + si + '" style="padding:4px 10px;margin:0">&times;</button>';
			kasten.appendChild(titel);

			var liste = document.createElement('div');
			liste.style.cssText = 'padding:8px;min-height:52px;display:flex;flex-wrap:wrap;gap:6px';
			liste.dataset.seite = si;
			if (!(s.kacheln || []).length) {
				liste.innerHTML = '<span class="sm-hilfe">' + e(TEXT.leer) + '</span>';
			}
			(s.kacheln || []).forEach(function (k, ki) {
				var szene = k.kachel === 'szene';
				var b = szene ? null : baustein(k.uuid);
				/* Eine Szene haengt an keinem Baustein - sie darf deshalb
				   nicht als "unbekannt" rot markiert werden. */
				var gut = szene || !!b;
				var kk = document.createElement('div');
				kk.draggable = true;
				kk.dataset.seite = si; kk.dataset.kachel = ki;
				kk.style.cssText = 'border:1px solid ' + (gut ? '#cfd8c0' : '#ef9a9a') + ';border-radius:7px;padding:6px 8px;background:' + (gut ? '#fbfdf7' : '#fff5f5') + ';font-size:.83em;cursor:move;max-width:270px';
				var opt = GROESSEN.map(function (g) {
					return '<option value="' + e(g) + '"' + (k.groesse === g ? ' selected' : '') + '>' + e(g) + '</option>';
				}).join('');
				var topt = Object.keys(TYPEN).map(function (t) {
					return '<option value="' + e(t) + '"' + (k.kachel === t ? ' selected' : '') + '>' + e(TYPEN[t]) + '</option>';
				}).join('');
				kk.innerHTML =
					'<div style="display:flex;gap:5px;align-items:center">' +
					'<input data-role="none" type="text" data-feld="titel" value="' + e(k.titel) + '" style="flex:1;min-width:80px;padding:3px 5px;border:1px solid #ddd;border-radius:4px;font-size:1em">' +
					'<button data-role="none" type="button" data-hoch="1" style="padding:1px 6px">&uarr;</button>' +
					'<button data-role="none" type="button" data-runter="1" style="padding:1px 6px">&darr;</button>' +
					'<button data-role="none" type="button" data-weg="1" style="padding:1px 6px">&times;</button></div>' +
					'<div style="display:flex;gap:5px;margin-top:4px">' +
					'<select data-role="none" data-feld="kachel" style="flex:1;font-size:.95em">' + topt + '</select>' +
					'<select data-role="none" data-feld="groesse" style="font-size:.95em">' + opt + '</select>' +
					'<label style="white-space:nowrap"><input data-role="none" type="checkbox" data-feld="sichtbar"' + (k.sichtbar ? ' checked' : '') + '></label>' +
					'</div>' +
					'<div class="sm-hilfe" style="margin-top:2px">' +
					  (szene ? e(TEXT.szene_schritt) : e(b ? (b.loxtyp + (b.raum ? ' · ' + b.raum : '')) : '?')) + '</div>' +
					(szene ? szene_editor(k) : '');
				liste.appendChild(kk);
			});
			kasten.appendChild(liste);
			rechts.appendChild(kasten);
		});

		vorrat_fuellen();
		binden();
		feldAufbau.value = JSON.stringify(AUFBAU);
	}

	function vorrat_fuellen() {
		var v = document.getElementById('dz-vorrat');
		var such = (document.getElementById('dz-suche') || {}).value || '';
		var nur = (document.getElementById('dz-nur') || {}).checked;
		var b = benutzt();
		var s = such.toLowerCase();
		v.innerHTML = '';
		var n = 0;
		BAUSTEINE.forEach(function (x) {
			if (nur && b[x.uuid]) { return; }
			if (s && (x.name + ' ' + x.raum + ' ' + x.kat + ' ' + x.loxtyp).toLowerCase().indexOf(s) < 0) { return; }
			if (n++ > 300) { return; }
			var d = document.createElement('div');
			d.draggable = true;
			d.dataset.neu = x.uuid;
			d.style.cssText = 'border:1px solid #e0e0e0;border-radius:6px;padding:5px 7px;margin:0 0 5px;background:#fff;cursor:move;font-size:.83em' + (b[x.uuid] ? ';opacity:.5' : '');
			d.innerHTML = '<b>' + e(x.name) + '</b><div class="sm-hilfe">' + e(x.loxtyp + (x.raum ? ' · ' + x.raum : '')) + '</div>';
			v.appendChild(d);
		});
		if (!n) { v.innerHTML = '<div class="sm-hilfe">' + e(TEXT.alle) + '</div>'; }
	}

	function binden() {
		document.getElementById('dz-neu').onclick = function () {
			var name = prompt(TEXT.neue, '');
			if (!name) { return; }
			var k = schluessel(name), i = 2, k2 = k;
			while (AUFBAU.seiten.some(function (s) { return s.schluessel === k2; })) { k2 = k + '-' + (i++); }
			AUFBAU.seiten.push({ schluessel: k2, name: name, spalten: 6, pin: '', kacheln: [] });
			markieren(); zeichnen();
		};
		document.getElementById('dz-szene').onclick = function () {
			if (!AUFBAU.seiten.length) { alert(TEXT.leer); return; }
			var name = prompt(TEXT.szene_name, '');
			if (!name) { return; }
			/* Die Szene kommt auf die ERSTE Seite. Von dort laesst sie sich
			   ziehen wie jede andere Kachel - ein eigener Auswahldialog waere
			   ein zweiter Weg fuer dieselbe Sache. */
			AUFBAU.seiten[0].kacheln.push({ uuid: '', titel: name, kachel: 'szene',
				groesse: '2x1', sichtbar: 1, schritte: [] });
			markieren(); zeichnen();
		};
		var such = document.getElementById('dz-suche');
		var nur = document.getElementById('dz-nur');
		such.oninput = vorrat_fuellen;
		nur.onchange = vorrat_fuellen;

		bau.querySelectorAll('[data-seiteweg]').forEach(function (b) {
			b.onclick = function () {
				if (!confirm(TEXT.frage)) { return; }
				AUFBAU.seiten.splice(parseInt(b.dataset.seiteweg, 10), 1);
				markieren(); zeichnen();
			};
		});

		bau.querySelectorAll('[data-kachel]').forEach(function (k) {
			var si = parseInt(k.dataset.seite, 10), ki = parseInt(k.dataset.kachel, 10);
			k.querySelector('[data-weg]').onclick = function () {
				AUFBAU.seiten[si].kacheln.splice(ki, 1); markieren(); zeichnen();
			};
			k.querySelector('[data-hoch]').onclick = function () {
				if (ki === 0) { return; }
				var a = AUFBAU.seiten[si].kacheln;
				a.splice(ki - 1, 0, a.splice(ki, 1)[0]); markieren(); zeichnen();
			};
			k.querySelector('[data-runter]').onclick = function () {
				var a = AUFBAU.seiten[si].kacheln;
				if (ki >= a.length - 1) { return; }
				a.splice(ki + 1, 0, a.splice(ki, 1)[0]); markieren(); zeichnen();
			};
			/* Szenen-Schritte. Die Befehlsliste haengt am gewaehlten
			   Baustein und wird bei jedem Wechsel neu gefuellt - angeboten
			   wird nur, was die Kacheltabelle fuer dessen Typ nennt. */
			var wahlB = k.querySelector('[data-szenebaustein]');
			var wahlC = k.querySelector('[data-szenebefehl]');
			if (wahlB && wahlC) {
				var fuellen = function () {
					var bb = baustein(wahlB.value);
					var liste = (bb && bb.befehle) ? bb.befehle : [];
					wahlC.innerHTML = liste.map(function (c) {
						return '<option value="' + e(c) + '">' + e(c) + '</option>';
					}).join('');
					if (!liste.length) {
						wahlC.innerHTML = '<option value="">' + e(TEXT.szene_ohne_befehl) + '</option>';
					}
				};
				wahlB.onchange = fuellen;
				fuellen();
				var dazu = k.querySelector('[data-schrittdazu]');
				if (dazu) {
					dazu.onclick = function () {
						var u = wahlB.value, c = wahlC.value;
						if (!u || !c) { return; }
						/* Ein Platzhalter wie "$wert" oder "changeTo/$wert"
						   laesst sich nicht als fertiger Befehl ablegen - der
						   Wert fehlt. Er wird erfragt statt geraten. */
						if (c.indexOf('$') >= 0) {
							var w = prompt(c, '');
							if (w === null || w === '') { return; }
							if (!/^-?\d+(\.\d+)?$/.test(w)) { alert(TEXT.szene_ohne_befehl); return; }
							c = (c === '$wert') ? w : c.replace('$wert', w);
						}
						var ziel = AUFBAU.seiten[si].kacheln[ki];
						if (!ziel.schritte) { ziel.schritte = []; }
						ziel.schritte.push({ uuid: u, befehl: c });
						markieren(); zeichnen();
					};
				}
			}
			k.querySelectorAll('[data-schrittweg]').forEach(function (w) {
				w.onclick = function () {
					var ziel = AUFBAU.seiten[si].kacheln[ki];
					(ziel.schritte || []).splice(parseInt(w.dataset.schrittweg, 10), 1);
					markieren(); zeichnen();
				};
			});
			k.querySelectorAll('[data-feld]').forEach(function (f) {
				f.onchange = f.oninput = function () {
					var ziel = AUFBAU.seiten[si].kacheln[ki];
					ziel[f.dataset.feld] = (f.type === 'checkbox') ? (f.checked ? 1 : 0) : f.value;
					markieren();
				};
			});
			k.addEventListener('dragstart', function (ev) {
				gezogen = { art: 'kachel', si: si, ki: ki };
				ev.dataTransfer.effectAllowed = 'move';
				ev.dataTransfer.setData('text/plain', 'x');
			});
		});

		bau.querySelectorAll('[data-neu]').forEach(function (d) {
			d.addEventListener('dragstart', function (ev) {
				gezogen = { art: 'neu', uuid: d.dataset.neu };
				ev.dataTransfer.effectAllowed = 'copy';
				ev.dataTransfer.setData('text/plain', 'x');
			});
		});

		bau.querySelectorAll('[data-seite]').forEach(function (l) {
			if (l.dataset.kachel !== undefined) { return; }
			l.addEventListener('dragover', function (ev) { ev.preventDefault(); l.style.background = '#f2f7ea'; });
			l.addEventListener('dragleave', function () { l.style.background = ''; });
			l.addEventListener('drop', function (ev) {
				ev.preventDefault(); l.style.background = '';
				var ziel = parseInt(l.dataset.seite, 10);
				if (!gezogen) { return; }
				if (gezogen.art === 'neu') {
					var b = baustein(gezogen.uuid);
					AUFBAU.seiten[ziel].kacheln.push({
						uuid: gezogen.uuid, titel: b ? b.name : gezogen.uuid,
						kachel: b ? b.kachel : 'generisch', groesse: '1x1', sichtbar: 1 });
				} else {
					var k = AUFBAU.seiten[gezogen.si].kacheln.splice(gezogen.ki, 1)[0];
					AUFBAU.seiten[ziel].kacheln.push(k);
				}
				gezogen = null;
				markieren(); zeichnen();
			});
		});
	}

	document.getElementById('dz-form').addEventListener('submit', function () {
		feldAufbau.value = JSON.stringify(AUFBAU);
		geaendert = false;
	});

	zeichnen();
})();
</script>
<?php } ?>

<?php
if ($db_rahmen) {
    LBWeb::lbfooter();
}
