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

/* ---------------- Waehrend einer Aktualisierung: nur ein Hinweis ----------
 *
 * Das steht VOR dem Wachposten, vor jedem Handler und vor db_token(). In der
 * Upgrade-Luecke ist config/plugins/<ordner>/ abgeraeumt; wer hier erst liest
 * und dann sperrt, hat schon geschrieben.
 *
 * Gemessen am 18.09.2026 in WSL (Pruefung-Dashboard-0.9.23):
 *   Fall L2  Ein blosser Seitenaufruf in der Luecke legte ueber db_token()
 *            (weiter unten, "Laden") dashboard.json mit einem NEUEN
 *            Aktionstoken an. postinstall.sh sah darin Inhalt, spielte die
 *            Sicherung nicht zurueck und loeschte sie: Aktionstoken und alle
 *            Einstellungen waren danach fort - jede Adresse, die der
 *            Miniserver mit dem alten Token aufruft, wurde abgewiesen.
 *   Fall L5  "Einstellungen speichern" in der Luecke: derselbe Verlust.
 *   Fall L3  Der Knopf "Dienst starten" startete einen vor dem Update bewusst
 *            angehaltenen Dienst, und er lief danach weiter.
 *
 * Die unangemeldeten Seiten (webfrontend/html/index.php und tafel.php)
 * sperren bewusst NICHT: sie schreiben keine Einstellungen (Fall L6, in der
 * Luecke gemessen), und ohne eingerichtetes Aktionstoken weisen sie Befehle
 * ohnehin ab.
 *
 * Eine Marke, die aelter als eine Stunde oder unlesbar ist, sperrt nicht: eine
 * abgebrochene Installation darf die Seite nicht fuer immer stilllegen. Der
 * Reiter Test nennt sie dann (db_test.php). */
list($db_mk_liegt, $db_mk_gilt) = db_upgrade_marke();
if ($db_mk_gilt) {
    $db_rahmen = class_exists('LBWeb', false) && method_exists('LBWeb', 'lbheader');
    if ($db_rahmen) {
        LBWeb::lbheader(db_t('ALLG.TITEL'), 'https://www.loxone.com/enen/kb/api/', 'help.html');
    }
    echo '<div id="db-upgrade-hinweis" style="max-width:980px;margin:12px auto;'
       . 'border-radius:8px;padding:10px 14px;background:#fdf3e3;border:1px solid #e0620d;'
       . 'font-family:-apple-system,Segoe UI,Roboto,sans-serif;color:#333"><b>'
       . db_e(db_t('HINWEIS.UPGRADE_LAEUFT')) . '</b> '
       . db_e(db_t('HINWEIS.UPGRADE_NICHT_GESPEICHERT'))
       . '</div>' . "\n";
    if ($db_rahmen) {
        LBWeb::lbfooter();
    }
    exit;
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
/* X-2 (Regeln/04): die Eingaben eines abgewiesenen Formulars und die Namen
 * der beanstandeten Felder - sie reisen mit der Einmalmeldung. */
$db_eingaben = array();
$db_bean = array();

/* ---------------------------------------------------------------- *
 * Der Wachposten - EIN Posten, vor allen Handlern.
 * Abgewiesen heisst gemeldet, und es wird NICHTS ausgefuehrt: $_POST
 * wird geleert, nur der aktive Reiter bleibt stehen, damit der Bediener
 * nach der Abweisung dort steht, wo er war.
 * ---------------------------------------------------------------- */
/* Eine Anfrage ueber post_max_size kommt bei PHP MIT leerem $_POST und
 * leerem $_FILES an - der Wachposten faende dann kein Formularmerkmal und
 * meldete "Die Anfrage trug kein Formularmerkmal und wurde abgewiesen",
 * also eine Faelschungs-Meldung fuer ein zu grosses Urlaubsfoto. Seit 0.9.13
 * traegt der Reiter Einstellungen ein Hintergrundbild; bis dahin konnte das
 * Formular post_max_size gar nicht erreichen.
 *
 * Deshalb VOR dem Wachposten: sagt der Server, es sei etwas gekommen, und ist
 * trotzdem nichts angekommen, dann war die Anfrage zu gross. */
$db_zugross = false;
if ((isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : '') === 'POST'
        && !$_POST && !$_FILES
        && (int) (isset($_SERVER['CONTENT_LENGTH']) ? $_SERVER['CONTENT_LENGTH'] : 0) > 0) {
    $db_zugross = true;
}

$db_wache = db_wachposten();
if ($db_zugross) {
    $db_fehler[] = sprintf(db_t('EINST.FEHLER_ANFRAGE_GROSS'),
                           db_e((string) @ini_get('post_max_size')));
} elseif ($db_wache !== '') {
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
/* O1 (Durchgang 29.09.2026): das Ergebnis der vorigen Anfrage - NUR beim GET.
 * Jeder POST endet weiter unten mit 303; seine Meldungen reisen als
 * Einmalmeldung (db_einmal_schreiben) zu dieser Seite. */
if (!$db_post) {
    $db_einmal = db_einmal_lesen();
    if ($db_einmal !== null) {
        $db_meldungen = $db_einmal['meldungen'];
        $db_fehler = array_merge($db_fehler, $db_einmal['fehler']);
        $db_ausgabe = $db_einmal['ausgabe'];
        // X-2: db_eingabe() und db_beanstandet_stil() lesen sie von hier.
        $db_eingaben = $db_einmal['eingaben'];
    }
}
/* O17: aendert sich etwas, das der Dienst beim Start liest, wird er
 * nachgezogen (weiter unten, vor der Umleitung). */
$db_nachziehen = false;

$db_sauber = function ($feld) {
    /* Fehlerklasse 4 (Durchgang 29.09.2026): es wird NICHTS mehr entfernt.
     * Bis 0.9.25 verschwanden Steuer- und Anfuehrungszeichen still
     * (aus 'haus"admin' wurde 'hausadmin', gemeldet als "gespeichert").
     * Jetzt wird nur getrimmt; was nicht zum Muster des Feldes passt, weist
     * die Pruefung ab und nennt es. Eine Liste statt eines Wertes wird zu
     * einem Steuerzeichen und faellt damit durch jede Pruefung (C5). */
    if (!isset($_POST[$feld])) { return ''; }
    return is_string($_POST[$feld]) ? trim($_POST[$feld]) : "\x00";
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
    /* a1 (Verbesserungsbau 30.09.2026): die Kennung dieser Vorlage merken.
     * Scheitert das (beschaedigte dashboard.json), kommt der Download
     * trotzdem - der Reiter zeigt dann "unbekannt" bzw. den alten Stand. */
    $db_va = isset($_POST['vorlage_out']) ? 'vq' : 'vi';
    db_vorlage_merken($db_va, db_vorlage_kennung($db_va));
    header('Content-Type: application/xml; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $db_name . '"');
    echo $db_xml;
    exit;
}

/* ---------------- Einstellungen speichern ---------------- */
if ($db_post && isset($_POST['speichern'])) {
    $db_cfg = db_config();
    $db_cfg_vorher = $db_cfg;
    /* C12/C3 (Durchgang 29.09.2026): eine beschaedigte dashboard.json oder
     * zugang.json wird nicht still ueberschrieben. Die Seite sagt oben, was
     * zu tun ist. */
    foreach (array('config', 'geheim') as $db_d) {
        db_json_lesen_streng($db_p[$db_d], $db_lage);
        if ($db_lage === 'kaputt' || $db_lage === 'unlesbar') {
            $db_fehler[] = sprintf(db_t('EINST.KAPUTT_GESPERRT'), db_e($db_p[$db_d]));
        }
    }

    $db_ms = $db_sauber('miniserver');
    if (!preg_match('/^[0-9]{1,3}$/', $db_ms)) {
        $db_bean[] = 'miniserver';
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
                   'verlauf_punkte' => array(10, 240),
                   'ruhe_kacheln' => array(0, DB_RUHE_KACHELN_MAX),
                   'ruhe_hell' => array(DB_RUHE_HELL_MIN, DB_RUHE_HELL_MAX),
                   'eco_hell' => array(DB_ECO_HELL_MIN, DB_ECO_HELL_MAX)) as $db_f => $db_g) {
        $db_w = $db_sauber($db_f);
        if (!preg_match('/^[0-9]+$/', $db_w)) {
            $db_bean[] = $db_f;
            $db_fehler[] = sprintf(db_t('EINST.FEHLER_ZAHL'), db_t('EINST.L_' . strtoupper($db_f)));
        } elseif ((int) $db_w < $db_g[0] || (int) $db_w > $db_g[1]) {
            $db_bean[] = $db_f;
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
        $db_bean[] = 'nacht_von';
        $db_bean[] = 'nacht_bis';
        $db_fehler[] = db_t('EINST.FEHLER_NACHTZEIT');
    } elseif ($db_nv === $db_nb) {
        $db_bean[] = 'nacht_von';
        $db_bean[] = 'nacht_bis';
        $db_fehler[] = db_t('EINST.FEHLER_NACHTGLEICH');
    } else {
        $db_cfg['nacht_von'] = $db_nv;
        $db_cfg['nacht_bis'] = $db_nb;
    }

    /* Das Ruhebild: 0 heisst 'aus', sonst mindestens DB_RUHE_NACH_MIN.
     * Deshalb NICHT in der Schleife oben - die kennt nur einen Bereich, und
     * '0 oder 10 bis 3600' ist keiner. */
    $db_rn = $db_sauber('ruhe_nach');
    if (!preg_match('/^[0-9]+$/', $db_rn)) {
        $db_bean[] = 'ruhe_nach';
        $db_fehler[] = sprintf(db_t('EINST.FEHLER_ZAHL'), db_t('EINST.L_RUHE_NACH'));
    } elseif ((int) $db_rn !== 0
              && ((int) $db_rn < DB_RUHE_NACH_MIN || (int) $db_rn > DB_RUHE_NACH_MAX)) {
        $db_bean[] = 'ruhe_nach';
        $db_fehler[] = sprintf(db_t('EINST.FEHLER_RUHE_NACH'),
                               DB_RUHE_NACH_MIN, DB_RUHE_NACH_MAX);
    } else {
        $db_cfg['ruhe_nach'] = (int) $db_rn;
    }

    /* Der Eco-Modus hat dieselbe Form: 0 heisst aus, sonst ein Bereich. Die
     * Pruefung steht deshalb hier und nicht in der Schleife oben - aus
     * demselben Grund wie beim Ruhebild. */
    $db_en = $db_sauber('eco_nach');
    if (!preg_match('/^[0-9]+$/', $db_en)) {
        $db_bean[] = 'eco_nach';
        $db_fehler[] = sprintf(db_t('EINST.FEHLER_ZAHL'), db_t('EINST.L_ECO_NACH'));
    } elseif ((int) $db_en !== 0
              && ((int) $db_en < DB_ECO_NACH_MIN || (int) $db_en > DB_ECO_NACH_MAX)) {
        $db_bean[] = 'eco_nach';
        $db_fehler[] = sprintf(db_t('EINST.FEHLER_ECO_NACH'),
                               DB_ECO_NACH_MIN, DB_ECO_NACH_MAX);
    } else {
        $db_cfg['eco_nach'] = (int) $db_en;
    }

    /* Die drei Bausteine der Wetterzeile. Leer ist erlaubt und heisst
     * "Loxones Wetterdienst, wie bisher". Eine UUID, die es nicht gibt, wird
     * ABGEWIESEN und nicht stillschweigend geleert - sonst waere ein
     * geloeschter Baustein ein stiller Rueckfall auf eine ganz ANDERE
     * Wetterquelle, und niemand saehe es der Zeile an. Dieselbe Regel wie bei
     * ruhe_seite. */
    foreach (array('wetter_lage', 'wetter_temp', 'wetter_zusatz') as $db_f) {
        $db_w = $db_sauber($db_f);
        if ($db_w === '') { $db_cfg[$db_f] = ''; continue; }
        $db_wb = db_baustein($db_w);
        $db_wk = ($db_wb !== null && isset($db_wb['kachel'])) ? (string) $db_wb['kachel'] : '';
        if ($db_wb === null || ($db_wk !== 'wert' && $db_wk !== 'text')) {
            $db_bean[] = $db_f;
            $db_fehler[] = sprintf(db_t('EINST.FEHLER_WETTER_BAUSTEIN'),
                                   db_t('EINST.L_' . strtoupper($db_f)));
        } else {
            $db_cfg[$db_f] = $db_w;
        }
    }

    /* Die Seite fuer die Verknuepfungen des Ruhebilds. Leer ist erlaubt und
     * heisst "die Seite, die gerade offen ist". Ein Schluessel, den es nicht
     * gibt, wird ABGEWIESEN und nicht stillschweigend geleert - sonst waere
     * eine geloeschte Seite hier ein stiller Rueckfall. */
    $db_rs = $db_sauber('ruhe_seite');
    if ($db_rs === '') {
        $db_cfg['ruhe_seite'] = '';
    } elseif (db_seite($db_rs) === null) {
        $db_bean[] = 'ruhe_seite';
        $db_fehler[] = db_t('EINST.FEHLER_RUHE_SEITE');
    } else {
        $db_cfg['ruhe_seite'] = $db_rs;
    }

    $db_farbe = $db_sauber('farbe');
    if (!in_array($db_farbe, array('dunkel', 'hell'), true)) {
        $db_bean[] = 'farbe';
        $db_fehler[] = db_t('EINST.FEHLER_FARBE');
    } else {
        $db_cfg['farbe'] = $db_farbe;
    }

    /* b1 (Verbesserungsbau 30.09.2026): PIN-freie Absender. Jeder Eintrag
     * muss eine IP-Adresse sein; gespeichert wird die Normalform. Was nicht
     * passt, wird abgewiesen und genannt - nie still weggelassen. */
    list($db_pf, $db_pf_falsch) = db_pin_frei_lesen(
        isset($_POST['pin_frei']) && is_string($_POST['pin_frei']) ? $_POST['pin_frei'] : "\x00");
    if ($db_pf === null) {
        $db_bean[] = 'pin_frei';
        $db_fehler[] = sprintf(db_t('EINST.FEHLER_PIN_FREI'),
                               db_e(implode(', ', array_slice($db_pf_falsch, 0, 5))), DB_PIN_FREI_MAX);
    } else {
        $db_cfg['pin_frei'] = implode(', ', $db_pf);
    }

    /* Tafel-1 (Verbesserungsbau 30.09.2026): der Praefix der Tafelthemen.
     * Abgewiesen wird er auch bei ausgeschalteter Einstellung - sonst wartete
     * der Fehler still bis zum Einschalten. */
    $db_tmp = $db_sauber('tafel_mqtt_praefix');
    if (!db_tafel_mqtt_praefix_gueltig($db_tmp)) {
        $db_bean[] = 'tafel_mqtt_praefix';
        $db_fehler[] = db_t('EINST.FEHLER_TAFEL_MQTT_PRAEFIX');
    } else {
        $db_cfg['tafel_mqtt_praefix'] = $db_tmp;
    }

    // Haken: isset() stellt sie beim Absenden DIESES Formulars. Alle Haken
    // dieses Reiters stehen deshalb in demselben Formular - sonst setzte das
    // Absenden eines anderen sie stillschweigend auf 0.
    foreach (array('tls', 'http_rueckfall', 'steuerung_ein', 'vollbild', 'wach',
                   'haptik', 'verlauf', 'sse', 'tafelsteuerung',
                   'gesichert_schalten', 'ruhe_uhr', 'ruhe_wetter',
                   'ambient', 'tafel_mqtt') as $db_h) {
        $db_cfg[$db_h] = isset($_POST[$db_h]) ? 1 : 0;
    }

    /* Eigene Zugangsdaten. Sie landen in zugang.json mit Rechten 0600 und
     * NIE in der Konfiguration, die diese Seite anzeigt.
     *
     * O4 (Durchgang 29.09.2026): hier wird nur GEPRUEFT; geschrieben wird erst
     * unten, wenn es keine einzige Beanstandung gibt. Bis 0.9.25 schrieb ein
     * abgewiesenes Formular zugang.json doch - gemessen: nach "Bitte
     * pruefen" (Bild kein Bild) standen neue Adresse, Benutzer, Kennwort und
     * Visualisierungs-Passwort darin (Befund 4 des Oberflaechen-Pruefers). */
    $db_zadr = $db_sauber('z_adresse');
    $db_zport = $db_sauber('z_port');
    $db_zben = $db_sauber('z_benutzer');
    $db_zpw = isset($_POST['z_passwort']) ? $_POST['z_passwort'] : '';
    $db_vpw = isset($_POST['visu_pw']) ? $_POST['visu_pw'] : '';
    if ($db_zadr !== '' && !preg_match('/^[A-Za-z0-9][A-Za-z0-9\.\-]{0,80}$/', $db_zadr)) {
        $db_bean[] = 'z_adresse';
        $db_fehler[] = db_t('EINST.FEHLER_ZADRESSE');
    }
    /* Nr. 19 (Nachzug G2, 02.10.2026): der Port wird IMMER geprueft, sobald
     * er etwas enthaelt - bis 0.9.29 nur bei gesetzter Adresse, und 99999
     * ging bei leerer Adresse still durch. Leer ist nur ohne Adresse
     * erlaubt (dann gilt 80 wie bisher). */
    if (($db_zadr !== '' || $db_zport !== '') && (!preg_match('/^[0-9]+$/', $db_zport)
            || (int) $db_zport < 1 || (int) $db_zport > 65535)) {
        $db_bean[] = 'z_port';
        $db_fehler[] = db_t('EINST.FEHLER_ZPORT');
    } elseif ($db_zadr === '' && $db_zport !== '' && (int) $db_zport !== 80) {
        /* Nr. 19 (zweiter Nachtrag G2): ohne eigene Adresse gilt der Zugang
         * des LoxBerry, ein eigener Port wuerde still verworfen. */
        $db_bean[] = 'z_port';
        $db_fehler[] = db_t('EINST.FEHLER_ZPORT_OHNE_ADRESSE');
    }
    /* Ein Anfuehrungszeichen im Benutzernamen wird abgewiesen und gemeldet,
     * nicht still entfernt (Hinweis der Bauliste, Durchgang 29.09.2026). */
    if (db_steuerzeichen($db_zben) || strpbrk($db_zben, "\"'") !== false) {
        $db_bean[] = 'z_benutzer';
        $db_fehler[] = db_t('EINST.FEHLER_ZBENUTZER');
    }
    // Kennwoerter duerfen Anfuehrungszeichen tragen; nur eine Liste ist falsch.
    if (!is_string($db_zpw) || !is_string($db_vpw)) {
        $db_bean[] = 'z_passwort';
        $db_bean[] = 'visu_pw';
        $db_fehler[] = db_t('EINST.FEHLER_KENNWORT_FORM');
        $db_zpw = '';
        $db_vpw = '';
    }

    /* Das Hintergrundbild des Ruhebilds.
     *
     * Geprueft wird der INHALT mit getimagesize(), nicht die Dateiendung und
     * auch nicht der Typ, den der Browser mitschickt - beides bestimmt der
     * Absender. Der Dateiname des Hochladenden wird nirgends uebernommen: die
     * Datei landet unter einem festen Pfad, und in der Konfiguration steht
     * allein die Endung, aus der der Endpunkt spaeter den Inhaltstyp bildet.
     *
     * '--' entfernt das Bild, ein leeres Feld laesst das vorhandene stehen -
     * dieselbe Regel wie beim Visualisierungs-Passwort, damit nicht jedes
     * Speichern der Einstellungen es unbemerkt wegwirft. */
    /* Der Fehlercode von PHP wird ZUERST gelesen.
     *
     * Bis zu einem Zwischenstand von 0.9.13 stieg dieser Block nur ueber
     * is_uploaded_file() ein. Scheitert der Upload in PHP selbst - etwa weil
     * die Datei ueber upload_max_filesize liegt -, ist 'tmp_name' leer, der
     * Zweig lief gar nicht an, und die Seite meldete "Gespeichert.". Es gab
     * kein Bild, und nichts sagte es dem Bediener. Gemessen mit
     * upload_max_filesize=100 und einem 147-Byte-Bild: weder Meldung noch
     * Beanstandung, und die Konfiguration wurde als Erfolg gespeichert.
     *
     * UPLOAD_ERR_NO_FILE ist der einzige beabsichtigte Fall ("leeres Feld
     * laesst das vorhandene stehen") - und genau den konnte der Code von
     * allen anderen nicht unterscheiden. Das Hausmuster dafuer steht in
     * BatterieBMS 0.9.15 und Midea2Lox 4.4.0. */
    $db_bildfehler = '';
    $db_bildda = false;
    if (isset($_FILES['ruhe_bild']) && is_array($_FILES['ruhe_bild'])) {
        $db_code = isset($_FILES['ruhe_bild']['error'])
                 ? (int) $_FILES['ruhe_bild']['error'] : UPLOAD_ERR_NO_FILE;
        if ($db_code === UPLOAD_ERR_INI_SIZE || $db_code === UPLOAD_ERR_FORM_SIZE) {
            $db_bildfehler = sprintf(db_t('EINST.FEHLER_RUHE_BILD_INI'),
                                     db_e((string) @ini_get('upload_max_filesize')));
        } elseif ($db_code !== UPLOAD_ERR_OK && $db_code !== UPLOAD_ERR_NO_FILE) {
            $db_bildfehler = sprintf(db_t('EINST.FEHLER_RUHE_BILD_CODE'), $db_code);
        } elseif ($db_code === UPLOAD_ERR_OK
                  && isset($_FILES['ruhe_bild']['tmp_name'])
                  && @is_uploaded_file($_FILES['ruhe_bild']['tmp_name'])) {
            $db_bildda = true;
        }
    }
    if ($db_bildfehler !== '') { $db_fehler[] = $db_bildfehler; $db_bean[] = 'ruhe_bild'; }

    /* Ein Mangel an ANDERER Stelle im selben Formular verwirft die gewaehlte
     * Datei - PHP haelt sie nur bis zum Ende der Anfrage, und kein Browser
     * fuellt das Dateifeld wieder. Der Bediener berichtigt den Takt, sendet
     * erneut ab und liest "Gespeichert." - ohne Bild und ohne Hinweis, dass
     * seine Auswahl beim ersten Versuch verlorenging. Deshalb wird das
     * gesagt: ein abgewiesenes Formular schreibt nichts, aber es verschweigt
     * auch nichts. */
    if ($db_fehler && $db_bildda) {
        $db_fehler[] = db_t('EINST.RUHE_BILD_VERWORFEN');
    }

    /* O4: auch das Bild wird ZUERST geprueft (Groesse, Inhalt, Kanten) und
     * erst unten abgelegt. */
    $db_bild_weg = ($db_sauber('ruhe_bild_weg') === '--');
    $db_bild_endung = '';
    $db_masse = null;
    if (!$db_fehler && !$db_bild_weg && $db_bildda) {
        if ((int) $_FILES['ruhe_bild']['size'] > DB_RUHE_BILD_MAX) {
            $db_bean[] = 'ruhe_bild';
            $db_fehler[] = sprintf(db_t('EINST.FEHLER_RUHE_BILD_GROSS'),
                                   (int) (DB_RUHE_BILD_MAX / 1048576));
        } else {
            $db_masse = @getimagesize($_FILES['ruhe_bild']['tmp_name']);
            $db_endungen = array(IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png',
                                 IMAGETYPE_WEBP => 'webp');
            if (!is_array($db_masse) || !isset($db_masse[2])
                    || !isset($db_endungen[$db_masse[2]])) {
                $db_bean[] = 'ruhe_bild';
                $db_fehler[] = db_t('EINST.FEHLER_RUHE_BILD_TYP');
            } elseif ((int) $db_masse[0] > DB_RUHE_BILD_KANTE
                      || (int) $db_masse[1] > DB_RUHE_BILD_KANTE) {
                /* Die Bytegrenze allein reicht nicht: ein gut gepacktes
                 * PNG mit 25000 Punkten Kantenlaenge bleibt weit unter
                 * 4 MB, und dekodieren muss es das Tablet, nicht der
                 * Server. */
                $db_bean[] = 'ruhe_bild';
                $db_fehler[] = sprintf(db_t('EINST.FEHLER_RUHE_BILD_KANTE'),
                                       (int) $db_masse[0], (int) $db_masse[1],
                                       DB_RUHE_BILD_KANTE);
            } else {
                $db_bild_endung = $db_endungen[$db_masse[2]];
            }
        }
    }

    /* X-2 (Regeln/04): abgewiesen - die Eingaben reisen zurueck ins Formular. */
    if ($db_fehler) {
        $db_eingaben = db_eingaben_sammeln('speichern', $db_bean);
    }
    /* O4/O5 (Durchgang 29.09.2026): erst ALLES pruefen, dann schreiben - und
     * jede Erfolgsmeldung haengt am Rueckgabewert. Bis 0.9.25 meldete ein
     * unschreibbares zugang.json "Die Einstellungen wurden gespeichert"
     * (gemessen, Befund 5 des Oberflaechen-Pruefers). */
    if (!$db_fehler) {
        $db_schreibfehler = array();
        // 1. das Hintergrundbild
        $db_bp = db_paths()['ruhebild'];
        if ($db_bild_weg) {
            /* '--' schlaegt eine gleichzeitig gewaehlte Datei - und sagt es. */
            @unlink($db_bp);
            clearstatcache(true, $db_bp);
            if (is_file($db_bp)) {
                $db_schreibfehler[] = sprintf(db_t('EINST.FEHLER_RUHE_BILD_SCHREIBEN'), db_e($db_bp));
            } else {
                $db_cfg['ruhe_bild'] = '';
                $db_meldungen[] = $db_bildda
                    ? db_t('EINST.RUHE_BILD_WEG_STATT')
                    : db_t('EINST.RUHE_BILD_WEG');
            }
        } elseif ($db_bild_endung !== '') {
            if (!@is_dir(dirname($db_bp)) && !@mkdir(dirname($db_bp), 0775, true)) {
                $db_schreibfehler[] = sprintf(db_t('EINST.FEHLER_RUHE_BILD_SCHREIBEN'),
                                              db_e(dirname($db_bp)));
            } elseif (!@move_uploaded_file($_FILES['ruhe_bild']['tmp_name'], $db_bp)) {
                $db_schreibfehler[] = sprintf(db_t('EINST.FEHLER_RUHE_BILD_SCHREIBEN'),
                                              db_e($db_bp));
            } else {
                @chmod($db_bp, 0644);
                $db_cfg['ruhe_bild'] = $db_bild_endung;
                $db_meldungen[] = sprintf(db_t('EINST.RUHE_BILD_UEBERNOMMEN'),
                                          db_e(strtoupper($db_bild_endung)),
                                          (int) $db_masse[0], (int) $db_masse[1]);
            }
        }
        // 2. eigener Zugang und Visualisierungs-Passwort (0600, unter der
        //    gemeinsamen Sperre mit dem Dienst)
        $db_zug_vorher = db_zugang();
        if (!db_zugang_speichern($db_zadr, $db_zport !== '' ? $db_zport : 80, $db_zben, $db_zpw)
                || !db_visu_speichern($db_vpw)) {
            $db_schreibfehler[] = sprintf(db_t('EINST.FEHLER_SPEICHERN'), db_e($db_p['geheim']));
        }
        // 3. die Einstellungen
        if (!db_config_speichern($db_cfg)) {
            $db_schreibfehler[] = sprintf(db_t('EINST.FEHLER_SPEICHERN'), db_e($db_p['config']));
        }
        if ($db_schreibfehler) {
            $db_fehler = array_merge($db_fehler, $db_schreibfehler);
            // X-2: nicht gespeichert - auch dann bleiben die Eingaben stehen.
            $db_eingaben = db_eingaben_sammeln('speichern', array());
        } else {
            $db_meldungen[] = db_t('EINST.GESPEICHERT');
            // O17: was der Dienst beim Start liest
            foreach (array('miniserver', 'tls', 'takt', 'http_rueckfall', 'http_takt',
                           'verlauf', 'verlauf_punkte', 'tafel_mqtt', 'tafel_mqtt_praefix') as $db_k) {
                if (json_encode($db_cfg_vorher[$db_k]) !== json_encode($db_cfg[$db_k])) {
                    $db_nachziehen = true;
                }
            }
            $db_zug_nachher = db_zugang();
            foreach (array('adresse', 'port', 'benutzer', 'passwort') as $db_k) {
                if ((isset($db_zug_vorher[$db_k]) ? $db_zug_vorher[$db_k] : null)
                        !== (isset($db_zug_nachher[$db_k]) ? $db_zug_nachher[$db_k] : null)) {
                    $db_nachziehen = true;
                }
            }
        }
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
    db_json_lesen_streng($db_p['seiten'], $db_lage);
    if ($db_lage === 'kaputt' || $db_lage === 'unlesbar') {
        $db_fehler[] = sprintf(db_t('EINST.KAPUTT_GESPERRT'), db_e($db_p['seiten']));
    }
    $db_seiten = db_seiten();
    $db_namen = isset($_POST['s_name']) && is_array($_POST['s_name']) ? $_POST['s_name'] : array();
    $db_spalten = isset($_POST['s_spalten']) && is_array($_POST['s_spalten']) ? $_POST['s_spalten'] : array();
    $db_pins = isset($_POST['s_pin']) && is_array($_POST['s_pin']) ? $_POST['s_pin'] : array();
    $db_weg = isset($_POST['s_weg']) && is_array($_POST['s_weg']) ? $_POST['s_weg'] : array();
    $db_pinweg = isset($_POST['s_pinweg']) && is_array($_POST['s_pinweg']) ? $_POST['s_pinweg'] : array();
    /* O7 (Durchgang 29.09.2026): dieselbe Regel wie im Designer
     * (db_seiten_pruefen in db_lib.php) - ein Name mit Steuerzeichen und eine
     * Spaltenzahl, die keine ganze Zahl von 2 bis 12 ist, werden abgewiesen.
     * Bis 0.9.25 entfernte dieser Reiter Anfuehrungszeichen still aus dem
     * Namen, und "12abc" Spalten wurden 12. Anfuehrungszeichen sind erlaubt -
     * jede Ausgabe maskiert sie.
     * C11: die PIN wird als Pruefwert gespeichert (password_hash). */
    $db_neu = array();
    // X-2: Nummer im Formular -> Seitenschluessel; die Eingaben reisen je Schluessel.
    $db_zeilen = array();
    foreach ($db_seiten as $db_i => $db_s) {
        $db_zeilen[$db_i] = is_array($db_s) && isset($db_s['schluessel']) ? (string) $db_s['schluessel'] : '';
    }
    foreach ($db_seiten as $db_i => $db_s) {
        if (!empty($db_weg[$db_i])) { continue; }
        $db_bk = $db_zeilen[$db_i];
        $db_n = isset($db_namen[$db_i]) && is_string($db_namen[$db_i]) ? trim($db_namen[$db_i]) : '';
        if ($db_n === '') {
            $db_bean[] = 's_name:' . $db_bk;
            $db_fehler[] = sprintf(db_t('BOARD.FEHLER_NAME'), (int) $db_i + 1);
            continue;
        }
        if (db_steuerzeichen($db_n)) {
            $db_bean[] = 's_name:' . $db_bk;
            $db_fehler[] = sprintf(db_t('DESIGN.FEHLER_ZEICHEN'),
                                   db_e((string) (isset($db_s['schluessel']) ? $db_s['schluessel'] : '')));
            continue;
        }
        $db_sp = isset($db_spalten[$db_i]) && is_string($db_spalten[$db_i]) ? trim($db_spalten[$db_i]) : '6';
        if (!preg_match('/^[0-9]{1,2}$/', $db_sp) || (int) $db_sp < 2 || (int) $db_sp > 12) {
            $db_bean[] = 's_spalten:' . $db_bk;
            $db_fehler[] = sprintf(db_t('BOARD.FEHLER_SPALTEN'), db_e($db_n));
            continue;
        }
        $db_pin = isset($db_pins[$db_i]) && is_string($db_pins[$db_i]) ? trim($db_pins[$db_i]) : '';
        if ($db_pin !== '' && !preg_match('/^[0-9]{4,10}$/', $db_pin)) {
            $db_bean[] = 's_pin:' . $db_bk;
            $db_fehler[] = sprintf(db_t('BOARD.FEHLER_PIN'), db_e($db_n));
            continue;
        }
        $db_s['name'] = $db_n;
        $db_s['spalten'] = (int) $db_sp;
        // Leere PIN heisst: die bisherige beibehalten. Zum Loeschen gibt es
        // das Haekchen daneben.
        if ($db_pin !== '') {
            $db_h = db_pin_hash($db_pin);
            if ($db_h === false) {
                $db_bean[] = 's_pin:' . $db_bk;
                $db_fehler[] = sprintf(db_t('BOARD.FEHLER_PIN'), db_e($db_n));
                continue;
            }
            $db_s['pin'] = $db_h;
        }
        if (!empty($db_pinweg[$db_i])) { $db_s['pin'] = ''; }
        $db_neu[] = $db_s;
    }
    if (!$db_fehler) {
        if (db_seiten_speichern($db_neu)) { $db_meldungen[] = db_t('BOARD.GESPEICHERT'); }
        else {
            $db_fehler[] = sprintf(db_t('EINST.FEHLER_SPEICHERN'), db_e($db_p['seiten']));
            // X-2: nicht gespeichert - die Eingaben bleiben stehen.
            $db_eingaben = db_eingaben_sammeln('seiten_speichern', array(), $db_zeilen);
        }
    } else {
        // X-2 (Regeln/04): abgewiesen - die Eingaben reisen zurueck, nie die PIN.
        $db_eingaben = db_eingaben_sammeln('seiten_speichern', $db_bean, $db_zeilen);
    }
    $db_tab = 'tab-boards';
}

/* ---------------- Designer speichert ---------------- */
if ($db_post && isset($_POST['designer_speichern'])) {
    $db_roh = isset($_POST['aufbau']) && is_string($_POST['aufbau']) ? $_POST['aufbau'] : '';
    $db_d = json_decode($db_roh, true);
    $db_orte = array();
    db_json_lesen_streng($db_p['seiten'], $db_lage);
    if ($db_lage === 'kaputt' || $db_lage === 'unlesbar') {
        $db_fehler[] = sprintf(db_t('EINST.KAPUTT_GESPERRT'), db_e($db_p['seiten']));
    } elseif (!is_array($db_d) || !isset($db_d['seiten']) || !is_array($db_d['seiten'])) {
        // Kaputte Daten werden NICHT gespeichert und NICHT zurechtgebogen.
        $db_fehler[] = db_t('DESIGN.FEHLER_JSON');
    } else {
        /* O7 (Durchgang 29.09.2026): abweisen statt verbiegen, mit derselben
         * Regel wie der Reiter Dashboards und das Zurueckspielen
         * (db_seiten_pruefen). Bis 0.9.25 wurden '9x9' zu 1x1, 'Schalter1' zu
         * 'chalter' und 99 Spalten zu 12, gemeldet als "gespeichert"
         * (gemessen, Befund 7 des Oberflaechen-Pruefers). Die PIN kommt aus
         * der gespeicherten Seite, nie aus dem Formular. */
        list($db_neu, $db_f2, $db_h2) = db_seiten_pruefen($db_d['seiten'], 'designer', $db_orte);
        $db_fehler = array_merge($db_fehler, $db_f2);
        $db_meldungen = array_merge($db_meldungen, $db_h2);
        if (!$db_fehler && is_array($db_neu)) {
            if (db_seiten_speichern($db_neu)) { $db_meldungen[] = db_t('DESIGN.GESPEICHERT'); }
            else { $db_fehler[] = sprintf(db_t('EINST.FEHLER_SPEICHERN'), db_e($db_p['seiten'])); }
        }
    }
    /* X2D (Welle E, 30.09.2026): nicht gespeichert - der Aufbau reist als
     * Entwurf mit der Einmalmeldung zurueck in den Designer, mit den Orten
     * der Beanstandung; nie eine PIN, nie in den Seitenordner. Bis 0.9.28
     * zeigte der Designer danach den gespeicherten Stand, und die Arbeit
     * seit dem letzten Speichern war weg. */
    if ($db_fehler) {
        $db_eingaben = db_entwurf_sammeln($db_roh, $db_d, $db_orte);
        if (!$db_eingaben) { $db_fehler[] = db_t('DESIGN.ENTWURF_NICHT'); }
    }
    $db_tab = 'tab-designer';
}

/* ---------------- Token, Test, Log ---------------- */
if ($db_post && isset($_POST['token_neu'])) {
    $db_cfg = db_config();
    $db_cfg['aktionstoken'] = db_token_erzeugen();
    /* C12: "Neues Token erzeugen" ist eine ausdrueckliche Entscheidung - es
     * darf auch eine beschaedigte dashboard.json ersetzen (die Abschrift
     * .kaputt bleibt daneben), und die Seite sagt, dass die uebrigen
     * Einstellungen dann auf Werk stehen. */
    $db_lage_vorher = db_config_lage();
    if (db_config_speichern($db_cfg, true)) {
        $db_meldungen[] = db_t('LOX.TOKEN_NEU');
        if ($db_lage_vorher === 'kaputt' || $db_lage_vorher === 'unlesbar') {
            $db_meldungen[] = sprintf(db_t('EINST.KAPUTT_ERSETZT'), db_e($db_p['config'] . '.kaputt'));
        }
    } else {
        $db_fehler[] = sprintf(db_t('EINST.FEHLER_SPEICHERN'), db_e($db_p['config']));
    }
    $db_tab = 'tab-loxone';
}
if ($db_post && isset($_POST['log_leeren'])) {
    /* Nur bei angehaltenem Dienst. Der Dienst haelt auf diese Datei einen
     * offenen Deskriptor; wird sie unter ihm weggeschrieben, macht er an
     * seinem alten Byte-Versatz weiter, und es entsteht eine Datei mit einem
     * Loch aus Null-Bytes - der geleerte Anfang ist danach nicht leer,
     * sondern Muell. Bis 0.9.12 leerte der Knopf unbesehen.
     *
     * Kein stilles Zurechtbiegen: der Knopf sagt, was fehlt. */
    /* O6 (Durchgang 29.09.2026): nur mit Bestaetigungshaken (Regeln/04, "Ein
     * Haekchen zur Bestaetigung, sonst passiert nichts"). O5: die Meldung
     * haengt am Rueckgabewert - bis 0.9.25 meldete eine unschreibbare
     * Logdatei "Logdatei geleert" (gemessen). */
    if (!isset($_POST['log_leeren_ok']) || $_POST['log_leeren_ok'] !== '1') {
        $db_fehler[] = db_t('LOG.LEEREN_BESTAETIGEN');
    } elseif (db_dienst_pid() > 0 || db_dienst_pids()) {
        $db_fehler[] = db_t('LOG.LEEREN_LAEUFT');
    } else {
        @mkdir(dirname($db_p['log']), 0775, true);
        // In die Logdatei gehoert Klartext, kein HTML.
        $db_klartext = trim(strip_tags(html_entity_decode(db_t('LOG.GELEERT'), ENT_QUOTES, 'UTF-8')));
        $db_zeile = '[' . date('Y-m-d H:i:s') . '] ' . $db_klartext . "\n";
        if (@file_put_contents($db_p['log'], $db_zeile) === strlen($db_zeile)) {
            $db_meldungen[] = db_t('LOG.GELEERT');
        } else {
            $db_fehler[] = sprintf(db_t('LOG.LEEREN_FEHL'), db_e($db_p['log']));
        }
    }
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

/* ---------------- Einstellungen sichern ----------------
 *
 * Ausgegeben wird die VOLLE Konfiguration - samt Aktionstoken. Ohne ihn
 * stuenden nach dem Zurueckspielen alle Felder richtig, und das Plugin
 * kaeme trotzdem nicht an die Anlage; die Datei waere wertlos. Damit
 * traegt sie ein Geheimnis, und der Hinweis am Knopf sagt das. */
if ($db_post && isset($_POST['db_sichern'])) {
    /* O3 (Durchgang 29.09.2026): die GANZE Einrichtung - Einstellungen samt
     * Aktionstoken, Seiten samt Kacheln und PIN-Pruefwerten, eigener Zugang
     * (db_sicherung_bauen). Aus einer beschaedigten Datei wird nichts
     * gesichert - die Sicherung waere sonst eine Sicherung der Vorgaben. */
    $db_js = false;
    $db_heil = true;
    foreach (array('config', 'seiten', 'geheim') as $db_d) {
        db_json_lesen_streng($db_p[$db_d], $db_lage);
        if ($db_lage === 'kaputt' || $db_lage === 'unlesbar') {
            $db_heil = false;
            $db_fehler[] = sprintf(db_t('EINST.KAPUTT_GESPERRT'), db_e($db_p[$db_d]));
        }
    }
    if ($db_heil) {
        /* X-3 (Verbesserungsbau 30.09.2026): bestuende die Datei das eigene
         * Zurueckspielen nicht, steht die Warnung in ihrem Kopf - geliefert
         * wird sie trotzdem. */
        $db_js = db_sicherung_mit_warnung();
    }
    if ($db_js !== false) {
        /* S3 (0.9.26): zusaetzlich zum Download ein Eintrag im
         * Sicherungsverlauf, mit DEMSELBEN Inhalt. Die Antwort auf diesen POST
         * ist die Datei selbst; was mit dem Verlauf geschah, reist als
         * Einmalmeldung zur naechsten Seite. Scheitert der Eintrag, kommt der
         * Download trotzdem - er ist der Zweck des Knopfes -, und die Seite
         * sagt es beim naechsten Aufruf, das Protokoll ebenso. */
        list($db_vn, $db_vg, $db_vweg) = db_sicherungen_ablegen('hand', $db_js);
        if ($db_vn !== null) {
            $db_meldungen[] = sprintf(db_t('EINST.SVL_ANGELEGT'), db_e($db_vn));
            if ($db_vweg) {
                $db_meldungen[] = sprintf(db_t('EINST.SVL_GEKUERZT'), db_e(implode(', ', $db_vweg)),
                                          DB_SICHERUNGEN_MAX);
            }
        } else {
            $db_fehler[] = sprintf(db_t('EINST.SVL_ANLEGEN_FEHL'), $db_vg);
            db_log('Sicherungsverlauf: der Eintrag zu "Einstellungen sichern" liess sich nicht anlegen.');
        }
        db_einmal_schreiben(array('meldungen' => $db_meldungen, 'fehler' => $db_fehler, 'ausgabe' => ''));
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="dashboard_einstellungen_'
               . date('Ymd_His') . '.json"');
        echo $db_js;
        exit;
    }
    if ($db_heil) { $db_fehler[] = db_t('EINST.SICH_SCHREIBFEHLER'); }
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
        /* C5 (Durchgang 29.09.2026, Bauart E) und S2/S4 (0.9.26): jeder Wert
         * geprueft, Seiten und Kacheln durch dieselbe Pruefung wie im
         * Designer, der Zugang wie im Formular. Vor dem Einspielen wird der
         * Ist-Stand in den Sicherungsverlauf gelegt; scheitert das, wird
         * nicht zurueckgespielt. Dieselbe Strecke wie beim Zurueckspielen aus
         * dem Verlauf (db_sicherung_zurueckspielen) - eine zweite gibt es
         * nicht. Eine halb gueltige Datei aendert nichts. */
        list($db_ok, $db_m2, $db_f2) = db_sicherung_zurueckspielen(
            (string) @file_get_contents($_FILES['db_sicherung']['tmp_name']));
        $db_meldungen = array_merge($db_meldungen, $db_m2);
        $db_fehler = array_merge($db_fehler, $db_f2);
        // O17: nach dem Zurueckspielen wird der Dienst nachgezogen.
        if ($db_ok) { $db_nachziehen = true; }
    }
    $db_tab = 'tab-settings';
}

/* ---------------- Sicherungsverlauf (0.9.26, S3) ----------------
 * Drei Knoepfe je Eintrag. Der Name kommt aus einem versteckten Feld und
 * gilt nur, wenn er dem Muster folgt UND im Ordner steht
 * (db_sicherungen_pfad) - '../', absolute Pfade und fremde Dateien fallen
 * damit heraus. Zurueckspielen und Loeschen verlangen den
 * Bestaetigungshaken (Regeln/04). Herunterladen liefert die Datei selbst und
 * endet mit exit wie "Einstellungen sichern"; alles andere endet unten mit
 * 303, F5 wiederholt also nichts. */
$db_vname = isset($_POST['db_verlauf_name']) ? $_POST['db_verlauf_name'] : null;
if ($db_post && isset($_POST['db_verlauf_laden'])) {
    list($db_vroh, $db_vgrund) = db_sicherungen_lesen($db_vname);
    if ($db_vroh !== null) {
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="dashboard_' . $db_vname . '"');
        echo $db_vroh;
        exit;
    }
    $db_fehler[] = $db_vgrund;
    $db_tab = 'tab-settings';
}
if ($db_post && isset($_POST['db_verlauf_zurueck'])) {
    if (!isset($_POST['db_verlauf_zurueck_ok']) || $_POST['db_verlauf_zurueck_ok'] !== '1') {
        $db_fehler[] = db_t('EINST.SVL_BESTAETIGEN_ZURUECK');
    } else {
        list($db_vroh, $db_vgrund) = db_sicherungen_lesen($db_vname);
        if ($db_vroh === null) {
            $db_fehler[] = $db_vgrund;
        } else {
            list($db_ok, $db_m2, $db_f2) = db_sicherung_zurueckspielen($db_vroh);
            if ($db_ok) {
                array_unshift($db_m2, sprintf(db_t('EINST.SVL_ZURUECK_AUS'), db_e($db_vname)));
                $db_nachziehen = true;
            }
            $db_meldungen = array_merge($db_meldungen, $db_m2);
            $db_fehler = array_merge($db_fehler, $db_f2);
        }
    }
    $db_tab = 'tab-settings';
}
if ($db_post && isset($_POST['db_verlauf_weg'])) {
    if (!isset($_POST['db_verlauf_weg_ok']) || $_POST['db_verlauf_weg_ok'] !== '1') {
        $db_fehler[] = db_t('EINST.SVL_BESTAETIGEN_WEG');
    } else {
        $db_vw = db_sicherungen_loeschen($db_vname);
        if ($db_vw === null) {
            $db_fehler[] = sprintf(db_t('EINST.SVL_NAME_UNGUELTIG'), db_sicherungen_name_zeigen($db_vname));
        } elseif ($db_vw) {
            $db_meldungen[] = sprintf(db_t('EINST.SVL_GELOESCHT'), db_e($db_vname));
        } else {
            $db_fehler[] = sprintf(db_t('EINST.SVL_LOESCHEN_FEHL'), db_e($db_vname));
        }
    }
    $db_tab = 'tab-settings';
}

/* ---------------- O17: Dienst nachziehen ----------------
 * Der Dienst liest seine Einstellungen und den Zugang beim Start. Bis 0.9.25
 * wirkten Takt, Miniserver, TLS und HTTP-Rueckfall nach "gespeichert" und
 * "zurueckgespielt" erst nach einem Neustart von Hand, und keine Meldung
 * sagte das (Befund 17 des Oberflaechen-Pruefers). Laeuft er, wird er jetzt
 * neu gestartet und das Ergebnis gemeldet; laeuft er nicht, sagt die Seite
 * es. */
if ($db_nachziehen) {
    if (db_dienst_pid() > 0 || db_dienst_pids()) {
        list($db_ok, $db_aus) = db_dienst('restart');
        if ($db_ok) { $db_meldungen[] = sprintf(db_t('EINST.DIENST_NACHGEZOGEN'), db_e($db_aus)); }
        else { $db_fehler[] = sprintf(db_t('EINST.DIENST_NACHZIEHEN_FEHL'), db_e($db_aus)); }
    } else {
        $db_meldungen[] = db_t('EINST.DIENST_NICHT_NACHGEZOGEN');
    }
}

/* ---------------- O1: nach JEDEM POST umleiten ----------------
 * Regeln/04: jeder POST-Handler endet mit 303; das Ergebnis reist als
 * Einmalmeldung. Bis 0.9.25 antworteten alle Handler mit 200 ohne Location:
 * F5 speicherte erneut, und "Neues Token erzeugen" wuerfelte bei F5 noch
 * einmal (gemessen: zwei Absendungen, drei verschiedene Token). Die
 * Downloads (Vorlagen, Sicherung) liefern vorher selbst und enden mit exit.
 * Laesst sich die Einmalmeldung nicht schreiben, wird wie bisher direkt
 * gerendert - lieber ohne Umleitung als ohne Meldung. */
if ($db_post) {
    if (db_einmal_schreiben(array('meldungen' => $db_meldungen, 'fehler' => $db_fehler,
                                  'ausgabe' => $db_ausgabe, 'eingaben' => $db_eingaben))) {
        header('Location: index.php?form=' . rawurlencode(substr($db_tab, 4)), true, 303);
        exit;
    }
}

/* ---------------- Laden ---------------- */
$db_cfg = db_config();
$db_token = db_token();
/* C12/C3 (Durchgang 29.09.2026): beschaedigte Einrichtungsdateien stehen
 * oben, mit Abschrift und Abhilfe - sie werden nicht still ueberschrieben. */
$db_kaputt = false;
foreach (array('config' => 'EINST.KAPUTT_CONFIG', 'seiten' => 'EINST.KAPUTT_SEITEN',
               'geheim' => 'EINST.KAPUTT_ZUGANG') as $db_d => $db_sk) {
    db_json_lesen_streng($db_p[$db_d], $db_lage);
    if ($db_lage === 'kaputt' || $db_lage === 'unlesbar') {
        $db_kaputt = $db_kaputt || $db_d === 'config';
        $db_fehler[] = sprintf(db_t($db_sk), db_e($db_p[$db_d]), db_e($db_p[$db_d] . '.kaputt'));
    }
}
// Ein Token, das nicht taugt, wird nicht still ersetzt - die Seite sagt es.
if ($db_token === '' && !$db_kaputt) {
    $db_fehler[] = db_t('LOX.TOKEN_UNTAUGLICH');
}
/* C10: JSON in den Skriptbloecken des Designers mit HEX_TAG, HEX_AMP,
 * HEX_APOS und HEX_QUOT - dieselbe Bauart wie in tafel.php. */
$db_jf = JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
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

if ($db_rahmen) {
    LBWeb::lbheader(db_t('ALLG.TITEL'), 'https://www.loxone.com/enen/kb/api/', 'help.html');
}
/* O9 (Durchgang 29.09.2026): die Seite wird gepuffert, damit der Reiter Test
 * das Formularmerkmal am GERENDERTEN HTML zaehlt (Klasse 12) - die Zeile
 * steht als Platzhalter in der Tabelle und wird ganz am Ende ersetzt. */
ob_start();
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
/* Rollbehaelter nach VORLAGE_hausstandard.css.html (dort fuer die Klasse
   sm-tbl; die Tabellenklasse dieses Plugins heisst sm-tabelle). Neu in
   0.9.26 fuer die Tabelle des Sicherungsverlaufs, die Eingabefelder traegt
   (Regeln/04: jede Tabelle mit Eingabefeldern kommt in sm-breit). */
.sm-breit { overflow-x: auto; -webkit-overflow-scrolling: touch; margin: 10px 0; }
.sm-breit .sm-tabelle { margin: 0; min-width: 760px; }
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
/* Ein Auswahlfeld muss man als Auswahlfeld erkennen. Nachgezogen am
   05.09.2026 nach Regeln/04; Wortlaut aus VORLAGE_hausstandard.css.html.

   Am Geraet gemessen (LoxBerry 4.0.0.15, components.css): die Rahmen-CSS
   zeichnet seit der neuen Oberflaeche selbst einen Pfeil - Regel
   ".lb-content select". Darauf kann sich eine Plugin-Oberflaeche nicht
   verlassen: die Regel gibt es erst seit dieser Fassung, und die eigene
   Feldregel loescht sie, sobald sie die Kurzform "background:" benutzt.
   Dann steht ein Auswahlfeld da, das aussieht wie ein Textfeld.

   Die Raute im SVG wird als %23 geschrieben: eine rohe Raute beendet in
   einer CSS-Adresse den Wert. */
.sm-wrap select {
    appearance: none; -webkit-appearance: none; -moz-appearance: none;
    background-image: url("data:image/svg+xml;charset=UTF-8,%3Csvg xmlns='http://www.w3.org/2000/svg' width='14' height='9' viewBox='0 0 14 9'%3E%3Cpath d='M1 1l6 6 6-6' fill='none' stroke='%234f7d17' stroke-width='2'/%3E%3C/svg%3E");
    background-repeat: no-repeat; background-position: right 10px center;
    padding-right: 32px; cursor: pointer; }
/* Ergaenzung, nicht aus der Vorlage (X-2, Nachzug G2, 02.10.2026): das
   beanstandete Feld traegt sm-beanstandet und aria-invalid. Dieselben Farben
   wie db_beanstandet_stil(); background-color statt der Kurzform, damit der
   Pfeil der Auswahlfelder (Regel oben) stehen bleibt. */
.sm-wrap .sm-beanstandet { border: 2px solid #c62828 !important; background-color: #fff5f5 !important; }

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

<?php /* enctype seit 0.9.13: der Reiter traegt jetzt ein Hintergrundbild
        fuer das Ruhebild. Ohne multipart/form-data kaeme $_FILES leer an -
        und zwar OHNE Fehlermeldung, was der unangenehmste Fall waere. */ ?>
<form action="index.php" method="post" enctype="multipart/form-data">
  <?php echo db_fmt(); ?>
<input data-role="none" type="hidden" name="activetab" value="tab-settings">
<?php
/* X-2 (Regeln/04): nach einer Beanstandung zeigt DIESES Formular die
 * eingetippten Werte statt der gespeicherten. Dafuer werden $db_cfg und
 * $db_zugang nur fuer die Dauer des Formulars ueberlagert und danach
 * zurueckgesetzt (hinter </form>). Kennwoerter reisen nie mit. */
$db_cfg_gespeichert = $db_cfg;
$db_zugang_gespeichert = $db_zugang;
if (db_eingaben_aktiv('speichern')) {
    foreach ($db_eingaben['werte'] as $db_ek => $db_ev) {
        if (array_key_exists($db_ek, $db_cfg)) { $db_cfg[$db_ek] = $db_ev; }
    }
    foreach (array('z_adresse' => 'adresse', 'z_port' => 'port', 'z_benutzer' => 'benutzer') as $db_ek => $db_zk) {
        if (array_key_exists($db_ek, $db_eingaben['werte'])) { $db_zugang[$db_zk] = $db_eingaben['werte'][$db_ek]; }
    }
    echo '<div class="sm-warnung" id="db-eingaben-zurueck">' . db_e(db_t('EINST.EINGABEN_ZURUECK')) . '</div>'
       . db_beanstandet_stil('speichern');
}
?>

<h2><?= db_e(db_t('EINST.H_MS')) ?></h2>
<p class="sm-hilfe"><?= db_t('EINST.MS_ERKLAERUNG') ?></p>
<div class="sm-feld">
  <label for="miniserver"><?= db_e(db_t('EINST.L_MINISERVER')) ?></label>
  <select data-role="none" name="miniserver" id="miniserver"<?= db_beanstandet_attr('speichern', 'miniserver') ?>>
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
  <input data-role="none" type="text" name="z_adresse" id="z_adresse"<?= db_beanstandet_attr('speichern', 'z_adresse') ?>
         value="<?= db_e(isset($db_zugang['adresse']) ? $db_zugang['adresse'] : '') ?>"
         placeholder="<?= db_e(db_t('EINST.P_ZADRESSE')) ?>">
</div>
<div class="sm-feld">
  <label for="z_port"><?= db_e(db_t('EINST.L_ZPORT')) ?></label>
  <input data-role="none" type="text" name="z_port" id="z_port"<?= db_beanstandet_attr('speichern', 'z_port') ?>
         value="<?= db_e(isset($db_zugang['port']) ? $db_zugang['port'] : '80') ?>">
</div>
<div class="sm-feld">
  <label for="z_benutzer"><?= db_e(db_t('EINST.L_ZBENUTZER')) ?></label>
  <input data-role="none" type="text" name="z_benutzer" id="z_benutzer"<?= db_beanstandet_attr('speichern', 'z_benutzer') ?>
         value="<?= db_e(isset($db_zugang['benutzer']) ? $db_zugang['benutzer'] : '') ?>">
</div>
<div class="sm-feld">
  <label for="z_passwort"><?= db_e(db_t('EINST.L_ZPASSWORT')) ?></label>
  <input data-role="none" type="password" name="z_passwort" id="z_passwort"<?= db_beanstandet_attr('speichern', 'z_passwort') ?> value=""
         placeholder="<?= db_e(!empty($db_zugang['passwort']) ? db_t('EINST.PW_DA') : db_t('EINST.PW_LEER')) ?>">
  <p class="sm-hilfe"><?= db_t('EINST.H_ZPASSWORT') ?></p>
</div>

<h3><?= db_e(db_t('EINST.H_GESICHERT')) ?></h3>
<p class="sm-hilfe"><?= db_t('EINST.GESICHERT_ERKLAERUNG') ?></p>
<div class="sm-warnung"><?= db_t('EINST.GESICHERT_WARNUNG') ?></div>
<div class="sm-feld">
  <label for="visu_pw"><?= db_e(db_t('EINST.L_VISU_PW')) ?></label>
  <input data-role="none" type="password" name="visu_pw" id="visu_pw"<?= db_beanstandet_attr('speichern', 'visu_pw') ?> value=""
         placeholder="<?= db_e(db_visu_da() ? db_t('EINST.PW_DA') : db_t('EINST.PW_LEER')) ?>">
  <p class="sm-hilfe"><?= db_t('EINST.H_VISU_PW') ?></p>
</div>
<label><input data-role="none" type="checkbox" name="gesichert_schalten" value="1"<?= !empty($db_cfg['gesichert_schalten']) ? ' checked' : '' ?>>
  <?= db_e(db_t('EINST.L_GESICHERT')) ?></label>
<p class="sm-hilfe"><?= db_t('EINST.H_GESICHERT_SCHALTEN') ?></p>

<h2><?= db_e(db_t('EINST.H_TAKT')) ?></h2>
<div class="sm-feld">
  <label for="takt"><?= db_e(db_t('EINST.L_TAKT')) ?></label>
  <input data-role="none" type="text" name="takt" id="takt"<?= db_beanstandet_attr('speichern', 'takt') ?> value="<?= db_e($db_cfg['takt']) ?>">
  <p class="sm-hilfe"><?= db_t('EINST.H_TAKT') ?></p>
</div>
<label><input data-role="none" type="checkbox" name="http_rueckfall" value="1"<?= !empty($db_cfg['http_rueckfall']) ? ' checked' : '' ?>>
  <?= db_e(db_t('EINST.L_RUECKFALL')) ?></label>
<p class="sm-hilfe"><?= db_t('EINST.H_RUECKFALL') ?></p>
<div class="sm-feld">
  <label for="http_takt"><?= db_e(db_t('EINST.L_HTTP_TAKT')) ?></label>
  <input data-role="none" type="text" name="http_takt" id="http_takt"<?= db_beanstandet_attr('speichern', 'http_takt') ?> value="<?= db_e($db_cfg['http_takt']) ?>">
</div>
<div class="sm-feld">
  <label for="wartezeit"><?= db_e(db_t('EINST.L_WARTEZEIT')) ?></label>
  <input data-role="none" type="text" name="wartezeit" id="wartezeit"<?= db_beanstandet_attr('speichern', 'wartezeit') ?> value="<?= db_e($db_cfg['wartezeit']) ?>">
  <p class="sm-hilfe"><?= sprintf(db_e(db_t('EINST.H_WARTEZEIT')), DB_WARTEZEIT_MIN, DB_WARTEZEIT_MAX) ?></p>
</div>
<label><input data-role="none" type="checkbox" name="sse" value="1"<?= !empty($db_cfg['sse']) ? ' checked' : '' ?>>
  <?= db_e(db_t('EINST.L_SSE')) ?></label>
<p class="sm-hilfe"><?= db_t('EINST.H_SSE') ?></p>

<h2><?= db_e(db_t('EINST.H_ANZEIGE')) ?></h2>
<div class="sm-feld">
  <label for="farbe"><?= db_e(db_t('EINST.L_FARBE')) ?></label>
  <select data-role="none" name="farbe" id="farbe"<?= db_beanstandet_attr('speichern', 'farbe') ?>>
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
  <input data-role="none" type="text" name="rotation" id="rotation"<?= db_beanstandet_attr('speichern', 'rotation') ?> value="<?= db_e($db_cfg['rotation']) ?>">
  <p class="sm-hilfe"><?= db_t('EINST.H_ROTATION') ?></p>
</div>
<div class="sm-feld">
  <label for="nacht_von"><?= db_e(db_t('EINST.L_NACHT')) ?></label>
  <input data-role="none" type="text" name="nacht_von" id="nacht_von"<?= db_beanstandet_attr('speichern', 'nacht_von') ?> size="6"
         value="<?= db_e($db_cfg['nacht_von']) ?>" placeholder="22:30">
  <input data-role="none" type="text" name="nacht_bis" id="nacht_bis"<?= db_beanstandet_attr('speichern', 'nacht_bis') ?> size="6"
         value="<?= db_e($db_cfg['nacht_bis']) ?>" placeholder="06:00">
  <p class="sm-hilfe"><?= db_t('EINST.H_NACHT') ?></p>
</div>
<div class="sm-feld">
  <label for="nacht_helligkeit"><?= db_e(db_t('EINST.L_NACHT_HELLIGKEIT')) ?></label>
  <input data-role="none" type="text" name="nacht_helligkeit" id="nacht_helligkeit"<?= db_beanstandet_attr('speichern', 'nacht_helligkeit') ?>
         value="<?= db_e($db_cfg['nacht_helligkeit']) ?>">
</div>
<label><input data-role="none" type="checkbox" name="verlauf" value="1"<?= !empty($db_cfg['verlauf']) ? ' checked' : '' ?>>
  <?= db_e(db_t('EINST.L_VERLAUF')) ?></label>
<p class="sm-hilfe"><?= db_t('EINST.H_VERLAUF') ?></p>
<div class="sm-feld">
  <label for="verlauf_punkte"><?= db_e(db_t('EINST.L_VERLAUF_PUNKTE')) ?></label>
  <input data-role="none" type="text" name="verlauf_punkte" id="verlauf_punkte"<?= db_beanstandet_attr('speichern', 'verlauf_punkte') ?>
         value="<?= db_e($db_cfg['verlauf_punkte']) ?>">
</div>
<label><input data-role="none" type="checkbox" name="tafelsteuerung" value="1"<?= !empty($db_cfg['tafelsteuerung']) ? ' checked' : '' ?>>
  <?= db_e(db_t('EINST.L_TAFELSTEUERUNG')) ?></label>
<p class="sm-hilfe"><?= db_t('EINST.H_TAFELSTEUERUNG') ?></p>
<label><input data-role="none" type="checkbox" name="tafel_mqtt" value="1"<?= !empty($db_cfg['tafel_mqtt']) ? ' checked' : '' ?>>
  <?= db_e(db_t('EINST.L_TAFEL_MQTT')) ?></label>
<div class="sm-feld">
  <label for="tafel_mqtt_praefix"><?= db_e(db_t('EINST.L_TAFEL_MQTT_PRAEFIX')) ?></label>
  <input data-role="none" type="text" name="tafel_mqtt_praefix" id="tafel_mqtt_praefix"<?= db_beanstandet_attr('speichern', 'tafel_mqtt_praefix') ?>
         value="<?= db_e($db_cfg['tafel_mqtt_praefix']) ?>" placeholder="dashboard">
  <p class="sm-hilfe"><?= sprintf(db_t('EINST.H_TAFEL_MQTT'),
      '<span class="sm-mono">' . db_e(implode(', ', db_tafel_mqtt_themen((string) $db_cfg['tafel_mqtt_praefix']))) . '</span>') ?></p>
</div>

<h3><?= db_e(db_t('EINST.T_PIN_FREI')) ?></h3>
<div class="sm-feld">
  <label for="pin_frei"><?= db_e(db_t('EINST.L_PIN_FREI')) ?></label>
  <input data-role="none" type="text" name="pin_frei" id="pin_frei"<?= db_beanstandet_attr('speichern', 'pin_frei') ?>
         value="<?= db_e($db_cfg['pin_frei']) ?>" placeholder="192.168.1.50, 192.168.1.51">
  <p class="sm-hilfe"><?= sprintf(db_t('EINST.H_PIN_FREI'), DB_PIN_FREI_MAX,
      '<span class="sm-mono">' . db_e(db_adresse_normal(isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '') !== ''
          ? db_adresse_normal((string) $_SERVER['REMOTE_ADDR']) : '?') . '</span>') ?></p>
</div>

<h2><?= db_e(db_t('EINST.H_AMBIENT')) ?></h2>
<p class="sm-hilfe"><?= db_t('EINST.AMBIENT_ERKLAERUNG') ?></p>
<label><input data-role="none" type="checkbox" name="ambient" value="1"<?= !empty($db_cfg['ambient']) ? ' checked' : '' ?>>
  <?= db_e(db_t('EINST.L_AMBIENT')) ?></label>
<p class="sm-hilfe"><?= db_t('EINST.H_AMBIENT_HINWEIS') ?></p>

<h2><?= db_e(db_t('EINST.H_RUHE')) ?></h2>
<p class="sm-hilfe"><?= db_t('EINST.RUHE_ERKLAERUNG') ?></p>
<div class="sm-feld">
  <label for="ruhe_nach"><?= db_e(db_t('EINST.L_RUHE_NACH')) ?></label>
  <input data-role="none" type="text" name="ruhe_nach" id="ruhe_nach"<?= db_beanstandet_attr('speichern', 'ruhe_nach') ?>
         value="<?= db_e($db_cfg['ruhe_nach']) ?>">
</div>
<p class="sm-hilfe"><?= sprintf(db_t('EINST.H_RUHE_NACH'), DB_RUHE_NACH_MIN, DB_RUHE_NACH_MAX) ?></p>
<label><input data-role="none" type="checkbox" name="ruhe_uhr" value="1"<?= !empty($db_cfg['ruhe_uhr']) ? ' checked' : '' ?>>
  <?= db_e(db_t('EINST.L_RUHE_UHR')) ?></label>
<label><input data-role="none" type="checkbox" name="ruhe_wetter" value="1"<?= !empty($db_cfg['ruhe_wetter']) ? ' checked' : '' ?>>
  <?= db_e(db_t('EINST.L_RUHE_WETTER')) ?></label>
<p class="sm-hilfe"><?= db_t('EINST.H_RUHE_WETTER') ?></p>
<div class="sm-feld">
  <label for="ruhe_kacheln"><?= db_e(db_t('EINST.L_RUHE_KACHELN')) ?></label>
  <input data-role="none" type="text" name="ruhe_kacheln" id="ruhe_kacheln"<?= db_beanstandet_attr('speichern', 'ruhe_kacheln') ?>
         value="<?= db_e($db_cfg['ruhe_kacheln']) ?>">
</div>
<p class="sm-hilfe"><?= sprintf(db_t('EINST.H_RUHE_KACHELN'), DB_RUHE_KACHELN_MAX) ?></p>
<div class="sm-feld">
  <label for="ruhe_seite"><?= db_e(db_t('EINST.L_RUHE_SEITE')) ?></label>
  <select data-role="none" name="ruhe_seite" id="ruhe_seite"<?= db_beanstandet_attr('speichern', 'ruhe_seite') ?>>
    <option value=""><?= db_e(db_t('EINST.L_RUHE_SEITE_KEINE')) ?></option>
  <?php foreach ($db_seiten as $db_rs2) {
        $db_rk = (string) (isset($db_rs2['schluessel']) ? $db_rs2['schluessel'] : ''); ?>
    <option value="<?= db_e($db_rk) ?>"<?= ((string) $db_cfg['ruhe_seite'] === $db_rk) ? ' selected' : '' ?>><?= db_e(isset($db_rs2['name']) ? $db_rs2['name'] : $db_rk) ?></option>
  <?php } ?>
  </select>
</div>
<p class="sm-hilfe"><?= db_t('EINST.H_RUHE_SEITE') ?></p>
<div class="sm-feld">
  <label for="ruhe_hell"><?= db_e(db_t('EINST.L_RUHE_HELL')) ?></label>
  <input data-role="none" type="text" name="ruhe_hell" id="ruhe_hell"<?= db_beanstandet_attr('speichern', 'ruhe_hell') ?>
         value="<?= db_e($db_cfg['ruhe_hell']) ?>">
</div>
<p class="sm-hilfe"><?= sprintf(db_t('EINST.H_RUHE_HELL'), DB_RUHE_HELL_MIN, DB_RUHE_HELL_MAX) ?></p>
<div class="sm-feld">
  <label for="ruhe_bild"><?= db_e(db_t('EINST.L_RUHE_BILD')) ?></label>
  <input data-role="none" type="file" name="ruhe_bild" id="ruhe_bild"<?= db_beanstandet_attr('speichern', 'ruhe_bild') ?> accept="image/jpeg,image/png,image/webp">
</div>
<p class="sm-hilfe"><?= sprintf(db_t('EINST.H_RUHE_BILD'), (int) (DB_RUHE_BILD_MAX / 1048576), DB_RUHE_BILD_KANTE) ?></p>
<?php
$db_bpfad = db_paths()['ruhebild'];
if ((string) $db_cfg['ruhe_bild'] !== '' && is_file($db_bpfad)) {
    $db_bm = @getimagesize($db_bpfad);
    echo '<p class="sm-hilfe">' . db_e(sprintf(db_t('EINST.RUHE_BILD_DA'),
        strtoupper((string) $db_cfg['ruhe_bild']),
        (int) (is_array($db_bm) ? $db_bm[0] : 0),
        (int) (is_array($db_bm) ? $db_bm[1] : 0))) . '</p>';
    echo '<div class="sm-feld"><label for="ruhe_bild_weg">'
       . db_e(db_t('EINST.L_RUHE_BILD_WEG')) . '</label>'
       . '<input data-role="none" type="text" name="ruhe_bild_weg" id="ruhe_bild_weg" value=""></div>';
} else {
    echo '<p class="sm-hilfe">' . db_e(db_t('EINST.RUHE_BILD_KEINS')) . '</p>';
}
?>

<h2><?= db_e(db_t('EINST.H_ECO')) ?></h2>
<p class="sm-hilfe"><?= db_t('EINST.ECO_ERKLAERUNG') ?></p>
<div class="sm-feld">
  <label for="eco_nach"><?= db_e(db_t('EINST.L_ECO_NACH')) ?></label>
  <input data-role="none" type="text" name="eco_nach" id="eco_nach"<?= db_beanstandet_attr('speichern', 'eco_nach') ?>
         value="<?= db_e($db_cfg['eco_nach']) ?>">
</div>
<p class="sm-hilfe"><?= sprintf(db_t('EINST.H_ECO_NACH'), DB_ECO_NACH_MIN, DB_ECO_NACH_MAX) ?></p>
<div class="sm-feld">
  <label for="eco_hell"><?= db_e(db_t('EINST.L_ECO_HELL')) ?></label>
  <input data-role="none" type="text" name="eco_hell" id="eco_hell"<?= db_beanstandet_attr('speichern', 'eco_hell') ?>
         value="<?= db_e($db_cfg['eco_hell']) ?>">
</div>
<p class="sm-hilfe"><?= sprintf(db_t('EINST.H_ECO_HELL'), DB_ECO_HELL_MIN, DB_ECO_HELL_MAX) ?></p>
<p class="sm-hilfe"><?= db_t('EINST.H_ECO_ZUSAMMEN') ?></p>

<h2><?= db_e(db_t('EINST.H_WETTERZEILE')) ?></h2>
<p class="sm-hilfe"><?= db_t('EINST.WETTERZEILE_ERKLAERUNG') ?></p>
<?php
/* Die Auswahlliste: nur Wert- und Textkacheln - eine Wetterzeile zeigt eine
   Zahl oder einen Text, keine Jalousie. Nach Kategorie gruppiert, weil eine
   flache Liste mit einigen hundert Eintraegen niemand liest.

   EINMAL gebaut und dreimal benutzt. Drei Kopien derselben Liste laufen
   auseinander, sobald sich die Regel aendert, welche Bausteine taugen. */
$db_wliste = array();
foreach (db_bausteine() as $db_wb) {
    if (!is_array($db_wb)) { continue; }
    $db_wk = (string) (isset($db_wb['kachel']) ? $db_wb['kachel'] : '');
    if ($db_wk !== 'wert' && $db_wk !== 'text') { continue; }
    $db_wkat = (string) (isset($db_wb['katname']) ? $db_wb['katname'] : '');
    if ($db_wkat === '') { $db_wkat = db_t('EINST.WETTER_OHNE_KATEGORIE'); }
    $db_wraum = (string) (isset($db_wb['raumname']) ? $db_wb['raumname'] : '');
    $db_wliste[$db_wkat][] = array(
        (string) (isset($db_wb['uuid']) ? $db_wb['uuid'] : ''),
        (string) (isset($db_wb['name']) ? $db_wb['name'] : '')
        . ($db_wraum !== '' ? ' (' . $db_wraum . ')' : ''));
}
ksort($db_wliste);
$db_wetterfeld = function ($feld, $beschriftung) use ($db_wliste, $db_cfg) {
    $ist = (string) (isset($db_cfg[$feld]) ? $db_cfg[$feld] : '');
    echo '<div class="sm-feld"><label for="' . db_e($feld) . '">'
       . db_e($beschriftung) . '</label>'
       . '<select data-role="none" name="' . db_e($feld) . '" id="' . db_e($feld) . '"' . db_beanstandet_attr('speichern', $feld) . '>'
       . '<option value=""' . ($ist === '' ? ' selected' : '') . '>'
       . db_e(db_t('EINST.WETTER_KEINER')) . '</option>';
    foreach ($db_wliste as $kat => $eintraege) {
        echo '<optgroup label="' . db_e($kat) . '">';
        foreach ($eintraege as $e) {
            echo '<option value="' . db_e($e[0]) . '"'
               . ($ist === $e[0] ? ' selected' : '') . '>' . db_e($e[1]) . '</option>';
        }
        echo '</optgroup>';
    }
    echo '</select></div>';
};
$db_wetterfeld('wetter_lage',   db_t('EINST.L_WETTER_LAGE'));
$db_wetterfeld('wetter_temp',   db_t('EINST.L_WETTER_TEMP'));
$db_wetterfeld('wetter_zusatz', db_t('EINST.L_WETTER_ZUSATZ'));
?>
<p class="sm-hilfe"><?= db_t('EINST.H_WETTERZEILE_QUELLE') ?></p>
<?php
/* Was die gewaehlten Bausteine gerade TRAGEN - nicht, wie die Zeile aussieht.
 *
 * Der Unterschied ist wichtig. Ein erster Entwurf zeigte hier die fertige
 * Zeile - und zeigte sie FALSCH: "20 - kein Regen - 70", waehrend auf der
 * Tafel "20,0 °C | kein Regen | 70,0 %" steht. Die Formatierung liegt in
 * zahl() und einheit_kurz() auf der Anzeigeseite; sie hier ein zweites Mal
 * zu bauen hiesse, dieselbe Regel zweimal zu fuehren, und die zweite laeuft
 * weg. Eine Vorschau, die etwas anderes verspricht als das Ergebnis, ist
 * schlimmer als keine.
 *
 * Die Frage, die diese Probe wirklich beantwortet, ist die wichtigere:
 * traegt der gewaehlte Baustein ueberhaupt etwas? An einer echten Anlage
 * nachgemessen liefert Weather4Loxone seinen Baustein 'Wetter aktuell'
 * leer, waehrend der Tagesbaustein den Text traegt - wer das erst am Tablet
 * merkt, sucht lange. */
if (db_wetter_eigene_gewaehlt($db_cfg)) {
    $db_wzeilen = array();
    foreach (array('wetter_lage'   => db_t('EINST.L_WETTER_LAGE'),
                   'wetter_temp'   => db_t('EINST.L_WETTER_TEMP'),
                   'wetter_zusatz' => db_t('EINST.L_WETTER_ZUSATZ')) as $db_f => $db_wbez) {
        $db_wu = (string) (isset($db_cfg[$db_f]) ? $db_cfg[$db_f] : '');
        if ($db_wu === '') { continue; }
        $db_ww = db_baustein_wert($db_wu);
        if ($db_ww === null) {
            $db_wtext = db_t('EINST.WETTER_PROBE_LEER');
            $db_wb    = db_baustein($db_wu);
            $db_wname = ($db_wb !== null && isset($db_wb['name'])) ? (string) $db_wb['name'] : $db_wu;
        } else {
            $db_wname = $db_ww['name'];
            $db_wtext = ($db_ww['text'] !== '') ? $db_ww['text'] : db_t('EINST.WETTER_PROBE_LEER');
        }
        $db_wzeilen[] = db_e($db_wbez . ' — ' . $db_wname . ': ' . $db_wtext);
    }
    if (count($db_wzeilen)) {
        echo '<p class="sm-hilfe">' . db_e(db_t('EINST.WETTER_PROBE')) . '<br>'
           . implode('<br>', $db_wzeilen) . '</p>';
    }
}
?>

<div class="sm-knopfreihe">
<button data-role="none" class="sm-btn sm-b-aktion" name="speichern" value="1"><?= db_e(db_t('ALLG.SPEICHERN')) ?></button>
</div>
</form>
<?php
// X-2: ab hier wieder die gespeicherten Werte.
$db_cfg = $db_cfg_gespeichert;
$db_zugang = $db_zugang_gespeichert;
?>

<h2><?= db_t('EINST.H_SICHERUNG') ?></h2>
<div class="sm-hinweis"><?= db_t('EINST.SICH_ERKLAERUNG') ?></div>
<div class="sm-warnung"><?= db_t('EINST.SICH_WARNUNG') ?></div>
<!-- ZWEI GETRENNTE Formulare. Das Sichern schickt einen Download und ruft
     exit auf; das Zurueckspielen braucht enctype="multipart/form-data".
     Wer beides in ein Formular legt, bekommt entweder keinen Upload oder
     einen Download, der das Speichern verschluckt.

     Und ZWEI GETRENNTE Knopfreihen: der Hausstandard mischt lesende und
     schaltende Knoepfe nie in dieselbe Reihe. Bis 0.9.12 standen sie hier
     nebeneinander - der harmlose Download und das Ueberschreiben der ganzen
     Konfiguration, gleich gross und gleich weit vom Mauszeiger entfernt. -->
<div class="sm-knopfreihe">
  <form action="index.php" method="post">
    <?php echo db_fmt(); ?>
    <input data-role="none" type="hidden" name="activetab" value="tab-settings">
    <button data-role="none" class="sm-btn sm-b-lesen" type="submit" name="db_sichern" value="1"><?= db_t('EINST.K_SICHERN') ?></button>
  </form>
</div>
<?php
/* X-3 (Verbesserungsbau 30.09.2026): gelb am Knopf, wenn die Sicherung des
 * jetzigen Stands das eigene Zurueckspielen nicht bestuende - dieselbe
 * Pruefung wie beim Zurueckspielen. Die Sicherung kommt trotzdem. */
$db_altwerte = db_sicherung_altwerte();
if ($db_altwerte) { ?>
<div class="sm-warnung" id="db-sicherung-warnung"><?= db_t('EINST.SICH_ALTWERT') ?><ul>
<?php foreach ($db_altwerte as $db_aw) { ?><li><?= db_e($db_aw) ?></li><?php } ?>
</ul></div>
<?php } ?>
<div class="sm-knopfreihe">
  <form action="index.php" method="post" enctype="multipart/form-data">
    <?php echo db_fmt(); ?>
    <input data-role="none" type="hidden" name="activetab" value="tab-settings">
    <input data-role="none" type="file" name="db_sicherung" accept=".json">
    <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="db_zurueck" value="1"><?= db_t('EINST.K_ZURUECK') ?></button>
  </form>
</div>

<h3><?= db_e(db_t('EINST.H_SVL')) ?></h3>
<p class="sm-hilfe"><?= sprintf(db_t('EINST.SVL_ERKLAERUNG'), (int) DB_SICHERUNGEN_MAX,
    '<span class="sm-mono">' . db_e($db_p['sicherungen']) . '</span>') ?></p>
<?php
/* S3 (0.9.26): die Liste des Verlaufs, die juengste zuerst. Je Eintrag drei
 * eigene Formulare - kein Formular im Formular, jedes mit Merkmal. Die
 * Tabelle traegt Eingabefelder und steht deshalb in sm-breit. */
$db_vliste = db_sicherungen_liste();
if (!$db_vliste) { ?>
<p class="sm-hilfe"><?= db_e(db_t('EINST.SVL_LEER')) ?></p>
<?php } else { ?>
<div class="sm-breit">
<table class="sm-tabelle">
<tr><th><?= db_e(db_t('EINST.SVL_T_DATUM')) ?></th><th><?= db_e(db_t('EINST.SVL_T_ANLASS')) ?></th><th><?= db_e(db_t('EINST.SVL_T_GROESSE')) ?></th><th><?= db_e(db_t('EINST.SVL_T_LADEN')) ?></th><th><?= db_e(db_t('EINST.SVL_T_ZURUECK')) ?></th><th><?= db_e(db_t('EINST.SVL_T_WEG')) ?></th></tr>
<?php foreach ($db_vliste as $db_v) { ?>
<tr>
  <td><?= db_e(db_sicherungen_zeit($db_v)) ?></td>
  <td><?= db_e(db_sicherungen_anlass($db_v)) ?></td>
  <td><?= db_e(sprintf(db_t('EINST.SVL_GROESSE'), (int) $db_v['groesse'])) ?></td>
  <td><form action="index.php" method="post">
    <?php echo db_fmt(); ?>
    <input data-role="none" type="hidden" name="activetab" value="tab-settings">
    <input data-role="none" type="hidden" name="db_verlauf_name" value="<?= db_e($db_v['name']) ?>">
    <button data-role="none" class="sm-btn sm-b-lesen" type="submit" name="db_verlauf_laden" value="1"><?= db_e(db_t('EINST.K_SVL_LADEN')) ?></button>
  </form></td>
  <td><form action="index.php" method="post">
    <?php echo db_fmt(); ?>
    <input data-role="none" type="hidden" name="activetab" value="tab-settings">
    <input data-role="none" type="hidden" name="db_verlauf_name" value="<?= db_e($db_v['name']) ?>">
    <label><input data-role="none" type="checkbox" name="db_verlauf_zurueck_ok" value="1"> <?= db_e(db_t('EINST.L_SVL_ZURUECK_OK')) ?></label><br>
    <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="db_verlauf_zurueck" value="1"><?= db_e(db_t('EINST.K_SVL_ZURUECK')) ?></button>
  </form></td>
  <td><form action="index.php" method="post">
    <?php echo db_fmt(); ?>
    <input data-role="none" type="hidden" name="activetab" value="tab-settings">
    <input data-role="none" type="hidden" name="db_verlauf_name" value="<?= db_e($db_v['name']) ?>">
    <label><input data-role="none" type="checkbox" name="db_verlauf_weg_ok" value="1"> <?= db_e(db_t('EINST.L_SVL_WEG_OK')) ?></label><br>
    <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="db_verlauf_weg" value="1"><?= db_e(db_t('EINST.K_SVL_WEG')) ?></button>
  </form></td>
</tr>
<?php } ?>
</table>
</div>
<?php } ?>
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
  <!-- Zwei Reihen: erst lesen, dann schalten. Der Hausstandard mischt beides
       nie in dieselbe Reihe - "Struktur holen" fragt nur ab, "Von vorn
       anfangen" verwirft jede Handarbeit. Bis 0.9.12 standen alle drei
       nebeneinander. -->
  <div class="sm-knopfreihe">
  <button data-role="none" class="sm-btn sm-b-lesen" name="struktur_holen" value="1"><?= db_e(db_t('BOARD.K_STRUKTUR')) ?></button>
  </div>
  <div class="sm-knopfreihe">
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
<?php if (db_eingaben_aktiv('seiten_speichern')) {
    // X-2: die eingetippten Werte stehen wieder da, nie die PIN.
    echo '<div class="sm-warnung" id="db-eingaben-zurueck-seiten">' . db_e(db_t('EINST.EINGABEN_ZURUECK')) . '</div>'
       . db_beanstandet_stil('seiten_speichern', $db_seiten);
} ?>
<table class="sm-tabelle">
<tr><th><?= db_e(db_t('BOARD.T_NAME')) ?></th><th><?= db_e(db_t('BOARD.T_KACHELN')) ?></th>
    <th><?= db_e(db_t('BOARD.T_SPALTEN')) ?></th><th><?= db_e(db_t('BOARD.T_PIN')) ?></th>
    <th><?= db_e(db_t('BOARD.T_ADRESSE')) ?></th><th><?= db_e(db_t('BOARD.T_WEG')) ?></th></tr>
<?php foreach ($db_seiten as $db_i => $db_s) {
    $db_k = (string) $db_s['schluessel']; ?>
<tr>
  <td><input data-role="none" type="text" name="s_name[<?= (int) $db_i ?>]"<?= db_beanstandet_attr('seiten_speichern', 's_name', $db_k) ?>
             value="<?= db_e(db_eingabe('seiten_speichern', 's_name', $db_s['name'], $db_k)) ?>" size="18">
      <div class="sm-hilfe sm-mono"><?= db_e($db_k) ?></div></td>
  <td><?= count(isset($db_s['kacheln']) ? $db_s['kacheln'] : array()) ?></td>
  <td><input data-role="none" type="text" name="s_spalten[<?= (int) $db_i ?>]"<?= db_beanstandet_attr('seiten_speichern', 's_spalten', $db_k) ?>
             value="<?= db_e(db_eingabe('seiten_speichern', 's_spalten', (int) (isset($db_s['spalten']) ? $db_s['spalten'] : 6), $db_k)) ?>" size="3"></td>
  <td><input data-role="none" type="password" name="s_pin[<?= (int) $db_i ?>]"<?= db_beanstandet_attr('seiten_speichern', 's_pin', $db_k) ?> value="" size="8"
             placeholder="<?= db_e(!empty($db_s['pin']) ? db_t('BOARD.PIN_DA') : db_t('BOARD.PIN_LEER')) ?>">
      <?php if (!empty($db_s['pin'])) { ?>
      <div class="sm-hilfe"><label><input data-role="none" type="checkbox"
        name="s_pinweg[<?= (int) $db_i ?>]" value="1"<?= db_eingabe('seiten_speichern', 's_pinweg', 0, $db_k) ? ' checked' : '' ?>> <?= db_e(db_t('BOARD.PIN_WEG')) ?></label></div>
      <?php } ?></td>
  <td><a href="<?= db_e(db_tafel_adresse($db_k)) ?>" target="_blank"
         class="sm-mono" style="font-size:0.8em"><?= db_e(db_t('BOARD.OEFFNEN')) ?></a>
      <div class="sm-hilfe sm-mono" style="font-size:0.75em"><?= db_e(db_tafel_adresse($db_k)) ?></div></td>
  <td><label><input data-role="none" type="checkbox" name="s_weg[<?= (int) $db_i ?>]" value="1"<?= db_eingabe('seiten_speichern', 's_weg', 0, $db_k) ? ' checked' : '' ?>></label></td>
</tr>
<?php } ?>
</table>
<div class="sm-knopfreihe">
<button data-role="none" class="sm-btn sm-b-aktion" name="seiten_speichern" value="1"><?= db_e(db_t('ALLG.SPEICHERN')) ?></button>
</div>
</form>
<div class="sm-hinweis"><?= db_t('BOARD.ADRESSE_HINWEIS') ?></div>
<?php } ?>

<?php /* Der Reiter mit: $db_ausgabe traegt auch die Ausgabe von Selbsttest,
        Anmelde- und HTTP-Probe aus dem Reiter Test. Ohne diese Bedingung stand
        das Ergebnis einer Anmeldeprobe zusaetzlich hier unter "Ausgabe" -
        die Zeile im Reiter Test prueft den Reiter seit jeher mit, diese
        nicht. */ ?>
<?php if ($db_ausgabe !== '' && $db_tab === 'tab-boards') { ?>
<h3><?= db_e(db_t('BOARD.H_AUSGABE')) ?></h3>
<div class="sm-log"><?= db_e($db_ausgabe) ?></div>
<?php } ?>
</div>

<!-- ================= Designer ================= -->
<div class="sm-seite<?= $db_tab === 'tab-designer' ? ' sm-active' : '' ?>" id="tab-designer">
<h2><?= db_e(db_t('DESIGN.H_TITEL')) ?></h2>
<?php
/* X2D: der abgewiesene Aufbau aus der Einmalmeldung (nur beim GET direkt
 * nach der Abweisung; danach ist sie verbraucht). */
$db_entwurf = (db_eingaben_aktiv('designer') && isset($db_eingaben['entwurf'])) ? $db_eingaben : null;
?>
<div class="sm-legende">
  <span><i class="sm-punkt sm-b-technik"></i> <?= db_t('LEGENDE.TECHNIK') ?></span>
  <span><i class="sm-punkt sm-b-aktion"></i> <?= db_t('LEGENDE.AKTION') ?></span>
</div>
<?php if (!$db_bausteine) { ?>
<div class="sm-warnung"><?= db_t('DESIGN.KEINE_STRUKTUR') ?></div>
<?php } else { ?>
<p class="sm-hilfe"><?= db_t('DESIGN.ERKLAERUNG') ?></p>
<div class="sm-warnung"><?= db_t('DESIGN.WARNUNG') ?></div>
<?php if ($db_entwurf !== null) { ?>
<div class="sm-warnung" id="dz-entwurf" data-orte="<?= count($db_entwurf['orte']) ?>" style="border:2px solid #c62828"><?= sprintf(db_t('DESIGN.ENTWURF_HINWEIS'), count($db_entwurf['orte'])) ?> <a href="index.php?form=designer" id="dz-entwurf-weg"><?= db_e(db_t('DESIGN.ENTWURF_VERWERFEN')) ?></a></div>
<?php } ?>
<?php
/* S8 (0.9.26): Symbole aus LoxoneIcons. Fehlt das Plugin oder hat es noch
 * keine Symbole geladen, steht hier ein Hinweis, und das Feld "Symbol"
 * entfaellt - alles andere bleibt wie bisher. */
list($db_sy_ordner, $db_sy_lage) = db_symbol_ordner();
if ($db_sy_lage !== 'ok' || !db_symbol_liste()) { ?>
<div class="sm-hinweis"><?= db_t($db_sy_lage === 'kein_plugin' ? 'DESIGN.SYMBOL_KEIN_PLUGIN' : 'DESIGN.SYMBOL_KEINE') ?></div>
<?php } else { ?>
<p class="sm-hilfe"><?= sprintf(db_t('DESIGN.SYMBOL_ERKLAERUNG'), count(db_symbol_liste())) ?></p>
<?php } ?>

<div class="sm-knopfreihe">
  <!-- O16 (Durchgang 29.09.2026): Hinzufuegen ist grau (es aendert nur den
       Entwurf, gespeichert wird mit dem orangen Knopf), Loeschen orange
       (Regeln/04). Bis 0.9.25 war "+ Neue Seite" orange, "+ Szene" grau und
       das Loeschen einer Seite grau. -->
  <button data-role="none" type="button" class="sm-btn sm-b-technik" id="dz-neu">+ <?= db_e(db_t('DESIGN.NEUE_SEITE')) ?></button>
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
  <td class="sm-mono"><?= db_e(db_check($db_feld)) ?></td>
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
<?php /* a1 (Verbesserungsbau 30.09.2026): je Vorlage, ob die zuletzt
        heruntergeladene noch der entspricht, die das Plugin jetzt erzeugt. */
foreach (array('vi' => 'VI_DASHBOARD_STATUS.xml', 'vq' => 'VQ_DASHBOARD_STEUERUNG.xml') as $db_va => $db_vn) {
    list($db_vl, $db_vz) = db_vorlage_lage($db_va, $db_cfg);
    if ($db_vl === 'veraltet') { ?>
<div class="sm-warnung" id="db-vorlage-<?= db_e($db_va) ?>" data-lage="veraltet"><?= sprintf(db_t('LOX.VORLAGE_VERALTET'), '<span class="sm-mono">' . db_e($db_vn) . '</span>', db_e(date('d.m.Y H:i', $db_vz))) ?></div>
<?php } elseif ($db_vl === 'aktuell') { ?>
<p class="sm-hilfe" id="db-vorlage-<?= db_e($db_va) ?>" data-lage="aktuell"><?= sprintf(db_t('LOX.VORLAGE_AKTUELL'), '<span class="sm-mono">' . db_e($db_vn) . '</span>', db_e(date('d.m.Y H:i', $db_vz))) ?></p>
<?php } else { ?>
<p class="sm-hilfe" id="db-vorlage-<?= db_e($db_va) ?>" data-lage="unbekannt"><?= sprintf(db_t('LOX.VORLAGE_UNBEKANNT'), '<span class="sm-mono">' . db_e($db_vn) . '</span>') ?></p>
<?php }
} ?>
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
<?php /* Nur zeigen, wenn das Ruhebild eingerichtet ist - der Endpunkt weist
        den Befehl sonst mit GRUND=RUHE_AUS ab, und eine Zeile, die etwas
        verspricht, was 409 antwortet, schickt den Anwender nach Loxone
        Config statt in die Einstellungen. */ ?>
<?php if (!empty($db_cfg['ruhe_nach'])) { ?>
<tr><td class="sm-mono">…&amp;aktion=tafel&amp;ruhe=1</td><td><?= db_t('LOX.SOUT_RUHE') ?></td></tr>
<?php } ?>
</table>
<?php /* Tafel-1: der Weg ueber MQTT, zusaetzlich zum virtuellen Ausgang. */ ?>
<h4 style="margin:14px 0 2px"><?= db_e(db_t('LOX.SMQTT_TITEL')) ?></h4>
<?php if (empty($db_cfg['tafel_mqtt'])) { ?>
<p class="sm-hilfe"><?= db_t('LOX.SMQTT_AUS') ?></p>
<?php } else {
    $db_tmt = db_tafel_mqtt_themen((string) $db_cfg['tafel_mqtt_praefix']); ?>
<table class="sm-tabelle">
<tr><th><?= db_e(db_t('LOX.T_THEMA')) ?></th><th><?= db_e(db_t('LOX.T_BEDEUTUNG')) ?></th></tr>
<tr><td class="sm-mono"><?= db_e($db_tmt[0]) ?></td><td><?= db_t('LOX.SMQTT_SEITE') ?></td></tr>
<tr><td class="sm-mono"><?= db_e($db_tmt[1]) ?></td><td><?= db_t('LOX.SMQTT_WECKEN') ?></td></tr>
</table>
<p class="sm-hilfe"><?= sprintf(db_t('LOX.SMQTT_TEXT'), '<span class="sm-mono">publish ' . db_e($db_tmt[0]) . ' &lt;v&gt;</span>') ?></p>
<?php } ?>
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
    /* Regel A4: ein ODER belegt hoechstens zwei Eingaenge; drei Wege
       brauchen die Kaskade #7 -> #8 (Nachzug G2, 02.10.2026). */
    array(7,  'BAUSTEIN.T_ODER',    'BAUSTEIN.N07A', '',            'I1 &larr; #4, I2 &larr; #5'),
    array(8,  'BAUSTEIN.T_ODER',    'BAUSTEIN.N07', '',             'I1 &larr; #7, I2 &larr; #6'),
    array(9,  'BAUSTEIN.T_EVZ',     'BAUSTEIN.N08', 'BAUSTEIN.P08', 'I &larr; #8'),
    array(10, 'BAUSTEIN.T_BENACHR', 'BAUSTEIN.N09', 'BAUSTEIN.P09', 'I &larr; #9'),
    array(11, 'BAUSTEIN.T_STATUS',  'BAUSTEIN.N10', 'BAUSTEIN.P10', 'I &larr; #2'),
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
  <label><input data-role="none" type="checkbox" name="log_leeren_ok" value="1"> <?= db_e(db_t('LOG.L_BESTAETIGEN')) ?></label>
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
		/* ALLE Schluessel mit isset lesen. Bis 0.9.12 standen 'raumname',
		 * 'katname' und 'bekannt' hier nackt da - bei einer struktur.json,
		 * die sie nicht traegt (von Hand gepflegt, oder von einer aelteren
		 * Fassung geschrieben), gibt PHP 8 drei Warnungen aus, und die
		 * stehen dann MITTEN im <script>-Block vor dem JSON. Der Designer
		 * bekommt daraufhin gar keine Bausteinliste mehr. Dieselbe Falle wie
		 * im Zweig 'fehlt' von db_seite_daten(), die dort seit 0.9.6
		 * zugemacht ist - hier war sie offen geblieben. Gefunden von
		 * Werkzeuge/rendern.py gegen eine handgeschriebene Struktur. */
		return array('uuid' => isset($b['uuid']) ? $b['uuid'] : '',
		             'name' => isset($b['name']) ? $b['name'] : '',
		             'kachel' => isset($b['kachel']) ? $b['kachel'] : 'generisch',
		             'loxtyp' => isset($b['loxtyp']) ? $b['loxtyp'] : '',
		             'raum' => isset($b['raumname']) ? $b['raumname'] : '',
		             'kat' => isset($b['katname']) ? $b['katname'] : '',
		             'bekannt' => (int) (isset($b['bekannt']) ? $b['bekannt'] : 0),
		             // Die erlaubten Befehle wandern mit, damit der
		             // Schritt-Editor der Szene nur anbietet, was die
		             // Kacheltabelle fuer genau diesen Typ nennt.
		             'befehle' => isset($b['befehle']) ? $b['befehle'] : array(),
		             'nurlesen' => (int) (isset($b['nurlesen']) ? $b['nurlesen'] : 0),
		             'gesichert' => (int) (isset($b['gesichert']) ? $b['gesichert'] : 0));
	}, $db_bausteine), $db_jf) ?>;
	/* C11 (Durchgang 29.09.2026): der PIN-Pruefwert geht nicht in den Browser -
	   der Designer speichert die PIN ohnehin aus der gespeicherten Seite. */
	var AUFBAU = <?= json_encode(array('seiten' => array_map(function ($s) {
		if (is_array($s)) { $s['pin'] = ''; }
		return $s;
	}, $db_seiten)), $db_jf) ?>;
	/* X2D: nach einer Abweisung der abgeschickte Aufbau als Entwurf (PIN
	   immer leer) und die beanstandeten Stellen als [Seite, Kachel (-1 = die
	   Seite selbst), Feld]. Sonst null. */
	var ENTWURF = <?= json_encode($db_entwurf !== null
		? array('seiten' => $db_entwurf['entwurf']['seiten'], 'orte' => $db_entwurf['orte'])
		: null, $db_jf) ?>;
	if (ENTWURF) { AUFBAU = { seiten: ENTWURF.seiten }; }
	var TYPEN = <?= json_encode(db_kacheltypen(), $db_jf) ?>;
	var GROESSEN = <?= json_encode(array_keys(db_groessen()), $db_jf) ?>;
	/* S8 (0.9.26): die Symbole von LoxoneIcons (nur Dateinamen) und die
	   Adresse fuer die Vorschau - der Endpunkt hinter dem Token. Ohne
	   gueltiges Token gibt es keine Vorschau, die Auswahl bleibt. */
	var SYMBOLE = <?= json_encode(db_symbol_liste(), $db_jf) ?>;
	var SYMBOLADRESSE = <?= json_encode(db_token_gueltig($db_token)
		? '/plugins/' . $db_p['plugin'] . '/index.php?token=' . rawurlencode($db_token) . '&aktion=symbol&name='
		: '', $db_jf) ?>;
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
		'symbol'       => strip_tags(db_t('DESIGN.SYMBOL')),
		'kein_symbol'  => strip_tags(db_t('DESIGN.KEIN_SYMBOL')),
		'symbol_suche' => strip_tags(db_t('DESIGN.SYMBOL_SUCHE')),
		'entwurf'      => strip_tags(db_t('DESIGN.ENTWURF_STAND')),
	), $db_jf) ?>;

	var bau = document.getElementById('dz-bau');
	var feldAufbau = document.getElementById('dz-aufbau');
	var stand = document.getElementById('dz-stand');
	var geaendert = false;
	var gezogen = null;

	/* X2D: die Marken haengen an den Objekten des Entwurfs, nicht an ihrer
	   Nummer - sie wandern beim Verschieben mit und verschwinden, sobald das
	   beanstandete Feld geaendert wird. */
	var MARKE = (typeof Map === 'function') ? new Map() : null;
	if (ENTWURF && MARKE) {
		(ENTWURF.orte || []).forEach(function (o) {
			var s = AUFBAU.seiten[o[0]];
			if (!s) { return; }
			var ziel = o[1] < 0 ? s : (s.kacheln || [])[o[1]];
			if (!ziel) { return; }
			if (!MARKE.has(ziel)) { MARKE.set(ziel, {}); }
			MARKE.get(ziel)[o[2]] = 1;
		});
	}
	function marken(o) {
		var m = MARKE ? MARKE.get(o) : null;
		return m ? Object.keys(m) : [];
	}

	/* Maskiert fuer Inhalt UND Attribut. Bis 0.9.12 stand hier nur
	   textContent -> innerHTML; das ist die Serialisierung eines TEXTKNOTENS
	   und ersetzt & < > - aber NICHT das Anfuehrungszeichen. In einem
	   value="..." reisst ein Bausteinname wie  Lampe " Kueche  den
	   Attributwert auf, und was dahinter steht, wird zu weiteren Attributen
	   (x" onfocus="..."). Genau dieser Weg ist im Designer offen: der Name
	   kommt ungefiltert aus struktur.json, und das Anfuehrungszeichen wird
	   erst BEIM SPEICHERN entfernt - betroffen ist also die ganze Zeit, in der
	   der Designer benutzt wird.
	   Im Elementinhalt schadet die schaerfere Form nichts: &quot; stellt der
	   Browser wieder als " dar. */
	function e(t) {
		var d = document.createElement('div');
		d.textContent = t == null ? '' : String(t);
		return d.innerHTML.replace(/"/g, '&quot;').replace(/'/g, '&#39;');
	}
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

	/* S8 (0.9.26): das Feld "Symbol" je Kachel. Die Auswahl zeigt "Kein
	   Symbol", das gewaehlte und bis zu 60 Treffer der Suche nach dem
	   Dateinamen - bei ueber 500 Symbolen und vielen Kacheln waere eine volle
	   Liste je Kachel zu schwer. Die Vorschau ist ein <img>, nie inline. */
	function symbol_optionen(aktuell, such) {
		var s = String(such || '').toLowerCase();
		var aus = '<option value="">' + e(TEXT.kein_symbol) + '</option>';
		if (aktuell) { aus += '<option value="' + e(aktuell) + '" selected>' + e(aktuell) + '</option>'; }
		var n = 0;
		for (var i = 0; i < SYMBOLE.length && n < 60; i++) {
			var x = SYMBOLE[i];
			if (x === aktuell) { continue; }
			if (s && x.toLowerCase().indexOf(s) < 0) { continue; }
			aus += '<option value="' + e(x) + '">' + e(x) + '</option>';
			n++;
		}
		return aus;
	}
	function symbol_zeile(k) {
		if (!SYMBOLE.length) { return ''; }
		var sy = k.symbol || '';
		var quelle = (sy && SYMBOLADRESSE) ? ' src="' + e(SYMBOLADRESSE + encodeURIComponent(sy)) + '"' : '';
		return '<div style="display:flex;gap:5px;margin-top:4px;align-items:center">' +
			'<img data-symbolbild="1" alt=""' + quelle + ' style="width:22px;height:22px;flex:0 0 22px' + (quelle ? '' : ';visibility:hidden') + '">' +
			'<input data-role="none" type="text" data-symbolsuche="1" placeholder="' + e(TEXT.symbol_suche) + '" style="width:84px;padding:2px 4px;border:1px solid #ddd;border-radius:4px;font-size:.95em">' +
			'<select data-role="none" data-feld="symbol" title="' + e(TEXT.symbol) + '" style="flex:1;min-width:90px;font-size:.95em">' + symbol_optionen(sy, '') + '</select>' +
			'</div>';
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
			if (marken(s).length) {
				kasten.style.border = '2px solid #c62828';
				kasten.setAttribute('data-beanstandet', marken(s).join(' '));
			}
			var titel = document.createElement('div');
			titel.style.cssText = 'display:flex;gap:8px;align-items:center;padding:8px 10px;background:#f2f7ea;border-bottom:1px solid #ddd;border-radius:8px 8px 0 0';
			titel.innerHTML = '<b style="flex:1">' + e(s.name) + '</b>' +
				'<span class="sm-hilfe">' + (s.kacheln || []).length + '</span>' +
				'<button data-role="none" type="button" class="sm-btn sm-b-aktion" data-seiteweg="' + si + '" style="padding:4px 10px;margin:0">&times;</button>';
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
					(szene ? szene_editor(k) : '') + symbol_zeile(k);
				var km = marken(k);
				if (km.length) {
					kk.style.border = '2px solid #c62828';
					kk.style.background = '#fff5f5';
					kk.setAttribute('data-beanstandet', km.join(' '));
					km.forEach(function (f) {
						var feld = kk.querySelector('[data-feld="' + f + '"]');
						if (feld) { feld.style.outline = '2px solid #c62828'; }
					});
				}
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
					var m = MARKE ? MARKE.get(ziel) : null;
					if (m && m[f.dataset.feld]) {
						delete m[f.dataset.feld];
						f.style.outline = '';
						if (!Object.keys(m).length) { MARKE.delete(ziel); }
					}
					markieren();
				};
			});
			/* S8: Suche und Vorschau des Symbols. Den Wert selbst setzt der
			   allgemeine Handler oben (data-feld="symbol"). */
			var symSuche = k.querySelector('[data-symbolsuche]');
			var symWahl = k.querySelector('select[data-feld="symbol"]');
			var symBild = k.querySelector('[data-symbolbild]');
			if (symSuche && symWahl) {
				symSuche.oninput = function () {
					var ziel = AUFBAU.seiten[si].kacheln[ki];
					symWahl.innerHTML = symbol_optionen(ziel.symbol || '', symSuche.value);
				};
				symWahl.addEventListener('change', function () {
					if (!symBild) { return; }
					if (symWahl.value && SYMBOLADRESSE) {
						symBild.src = SYMBOLADRESSE + encodeURIComponent(symWahl.value);
						symBild.style.visibility = '';
					} else {
						symBild.removeAttribute('src');
						symBild.style.visibility = 'hidden';
					}
				});
			}
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

	if (ENTWURF) {
		/* X2D: der Entwurf ist nicht gespeichert - Stand und Rueckfrage beim
		   Verlassen wie nach jeder Aenderung. Der Verweis im Hinweis laedt
		   ausdruecklich den gespeicherten Stand und fragt deshalb nicht. */
		geaendert = true;
		stand.textContent = TEXT.entwurf;
		var entwurfWeg = document.getElementById('dz-entwurf-weg');
		if (entwurfWeg) { entwurfWeg.addEventListener('click', function () { geaendert = false; }); }
	}
	zeichnen();
})();
</script>
<?php } ?>

<?php
$db_html = (string) ob_get_clean();
list($db_fm_stand, $db_fm_text) = db_formmerkmal_pruefen($db_html);
echo str_replace('<!--DB_FORMMERKMAL-->',
                 db_pruefzeile($db_fm_stand, db_t('TEST.F_FORMMERKMAL'), $db_fm_text), $db_html);
if ($db_rahmen) {
    LBWeb::lbfooter();
}
