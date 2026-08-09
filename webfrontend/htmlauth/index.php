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

/* Positivliste: jeder Reiter MUSS hier stehen, sonst springt die Seite nach
 * jedem Absenden zurueck auf Einstellungen. */
$db_muster = '/^tab-(settings|boards|designer|loxone|test|log)$/';
$db_tab = 'tab-settings';
if (isset($_POST['activetab']) && preg_match($db_muster, (string) $_POST['activetab'])) {
    $db_tab = (string) $_POST['activetab'];
} elseif (isset($_GET['form']) && preg_match($db_muster, 'tab-' . (string) $_GET['form'])) {
    $db_tab = 'tab-' . (string) $_GET['form'];
}

$db_meldungen = array();
$db_fehler = array();
$db_ausgabe = '';
$db_post = (isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : '') === 'POST';

$db_sauber = function ($feld) {
    // Nur Steuerzeichen und Anfuehrungszeichen entfernen - ein hartes Filtern
    // auf eine Positivliste zerstoert gueltige Eingaben.
    return trim(preg_replace('/[\x00-\x1F\x7F"\']/', '',
        (string) (isset($_POST[$feld]) ? $_POST[$feld] : '')));
};

/* ---------------- Loxone-Vorlage herunterladen ---------------- */
if ($db_post && isset($_POST['vorlage'])) {
    list($db_name, $db_xml) = db_vorlage();
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

    foreach (array('takt' => array(1, 30), 'http_takt' => array(5, 120),
                   'wartezeit' => array(1, 60)) as $db_f => $db_g) {
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

    $db_farbe = $db_sauber('farbe');
    if (!in_array($db_farbe, array('dunkel', 'hell'), true)) {
        $db_fehler[] = db_t('EINST.FEHLER_FARBE');
    } else {
        $db_cfg['farbe'] = $db_farbe;
    }

    $db_cfg['tls'] = isset($_POST['tls']) ? 1 : 0;
    $db_cfg['http_rueckfall'] = isset($_POST['http_rueckfall']) ? 1 : 0;
    $db_cfg['steuerung_ein'] = isset($_POST['steuerung_ein']) ? 1 : 0;
    $db_cfg['vollbild'] = isset($_POST['vollbild']) ? 1 : 0;
    $db_cfg['wach'] = isset($_POST['wach']) ? 1 : 0;
    $db_cfg['haptik'] = isset($_POST['haptik']) ? 1 : 0;

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
                if (!isset($db_bekannt[$db_u])) {
                    // Der Baustein steht nicht mehr in der Struktur. Die
                    // Kachel bleibt trotzdem erhalten - sie wird auf dem
                    // Dashboard als 'fehlt' angezeigt, statt spurlos zu
                    // verschwinden.
                    $db_meldungen[] = sprintf(db_t('DESIGN.UNBEKANNT'), db_e($db_u));
                }
                $db_g = (string) (isset($db_k2['groesse']) ? $db_k2['groesse'] : '1x1');
                if (!preg_match('/^[1-6]x[1-3]$/', $db_g)) { $db_g = '1x1'; }
                $db_kacheln[] = array(
                    'uuid'     => $db_u,
                    'titel'    => trim(preg_replace('/[\x00-\x1F\x7F"]/', '',
                                       (string) (isset($db_k2['titel']) ? $db_k2['titel'] : ''))),
                    'kachel'   => preg_replace('/[^a-z]/', '',
                                       (string) (isset($db_k2['kachel']) ? $db_k2['kachel'] : '')),
                    'groesse'  => $db_g,
                    'sichtbar' => !empty($db_k2['sichtbar']) ? 1 : 0,
                );
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
if ($db_rahmen) {
    LBWeb::lbheader(db_t('ALLG.TITEL'), 'https://www.loxone.com/enen/kb/api/', '');
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
.sm-b { border: 0; border-radius: 6px; padding: 9px 18px; font-size: 0.93em; cursor: pointer;
    color: #fff; margin: 4px 6px 4px 0; }
.sm-b-lesen  { background: #4f7d17; }
.sm-b-technik { background: #6b7280; }
.sm-b-aktion { background: #d97706; }
.sm-legende { font-size: 0.83em; color: #666; margin: 6px 0 14px; line-height: 1.8; }
.sm-legende span { display: inline-block; width: 12px; height: 12px; border-radius: 3px;
    vertical-align: -2px; margin-right: 5px; }
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
  <span style="background:#4f7d17"></span><?= db_t('LEGENDE.LESEN') ?><br>
  <span style="background:#6b7280"></span><?= db_t('LEGENDE.TECHNIK') ?><br>
  <span style="background:#d97706"></span><?= db_t('LEGENDE.AKTION') ?>
</div>
<form action="index.php" method="post">
  <input data-role="none" type="hidden" name="activetab" value="tab-settings">
  <button data-role="none" class="sm-b sm-b-aktion" name="dienst" value="start"><?= db_e(db_t('EINST.K_START')) ?></button>
  <button data-role="none" class="sm-b sm-b-aktion" name="dienst" value="restart"><?= db_e(db_t('EINST.K_NEUSTART')) ?></button>
  <button data-role="none" class="sm-b sm-b-aktion" name="dienst" value="stop"><?= db_e(db_t('EINST.K_STOP')) ?></button>
</form>

<form action="index.php" method="post">
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
</div>

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

<button data-role="none" class="sm-b sm-b-aktion" name="speichern" value="1"><?= db_e(db_t('ALLG.SPEICHERN')) ?></button>
</form>
</div>

<!-- ================= Dashboards ================= -->
<div class="sm-seite<?= $db_tab === 'tab-boards' ? ' sm-active' : '' ?>" id="tab-boards">
<h2><?= db_e(db_t('BOARD.H_ENTWURF')) ?></h2>
<p class="sm-hilfe"><?= db_t('BOARD.ENTWURF_ERKLAERUNG') ?></p>
<form action="index.php" method="post">
  <input data-role="none" type="hidden" name="activetab" value="tab-boards">
  <button data-role="none" class="sm-b sm-b-lesen" name="struktur_holen" value="1"><?= db_e(db_t('BOARD.K_STRUKTUR')) ?></button>
  <button data-role="none" class="sm-b sm-b-aktion" name="entwurf" value="ergaenzen"><?= db_e(db_t('BOARD.K_ENTWURF')) ?></button>
  <button data-role="none" class="sm-b sm-b-aktion" name="entwurf" value="vonvorn"
          onclick="return confirm(<?= json_encode(strip_tags(html_entity_decode(db_t('BOARD.VONVORN_FRAGE'), ENT_QUOTES, 'UTF-8'))) ?>)"><?= db_e(db_t('BOARD.K_VONVORN')) ?></button>
</form>
<div class="sm-warnung"><?= db_t('BOARD.VONVORN_WARNUNG') ?></div>

<h2><?= db_e(db_t('BOARD.H_SEITEN')) ?></h2>
<?php if (!$db_seiten) { ?>
<div class="sm-warnung"><?= db_t('BOARD.KEINE_SEITEN') ?></div>
<?php } else { ?>
<form action="index.php" method="post">
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
<button data-role="none" class="sm-b sm-b-aktion" name="seiten_speichern" value="1"><?= db_e(db_t('ALLG.SPEICHERN')) ?></button>
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
<?php if (!$db_bausteine) { ?>
<div class="sm-warnung"><?= db_t('DESIGN.KEINE_STRUKTUR') ?></div>
<?php } else { ?>
<p class="sm-hilfe"><?= db_t('DESIGN.ERKLAERUNG') ?></p>
<div class="sm-warnung"><?= db_t('DESIGN.WARNUNG') ?></div>

<div id="dz-bau"></div>

<form action="index.php" method="post" id="dz-form">
  <input data-role="none" type="hidden" name="activetab" value="tab-designer">
  <input data-role="none" type="hidden" name="aufbau" id="dz-aufbau" value="">
  <button data-role="none" class="sm-b sm-b-aktion" name="designer_speichern" value="1"><?= db_e(db_t('DESIGN.K_SPEICHERN')) ?></button>
  <span class="sm-hilfe" id="dz-stand"></span>
</form>
<?php } ?>
</div>

<!-- ================= Einbindung in Loxone ================= -->
<div class="sm-seite<?= $db_tab === 'tab-loxone' ? ' sm-active' : '' ?>" id="tab-loxone">
<h2><?= db_e(db_t('LOX.H_TITEL')) ?></h2>
<p class="sm-hilfe"><?= db_t('LOX.EINLEITUNG') ?></p>

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
<form action="index.php" method="post" style="display:inline">
  <input data-role="none" type="hidden" name="activetab" value="tab-loxone">
  <button data-role="none" class="sm-b sm-b-lesen" name="vorlage" value="1"><?= db_e(db_t('LOX.K_VORLAGE')) ?></button>
</form>
</div>

<div class="sm-step">
<h3><?= db_e(db_t('LOX.S2_TITEL')) ?></h3>
<p class="sm-hilfe"><?= db_t('LOX.S2_TEXT') ?></p>
<div class="sm-hinweis"><?= db_t('LOX.S2_HINWEIS') ?></div>
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
<form action="index.php" method="post" style="display:inline">
  <input data-role="none" type="hidden" name="activetab" value="tab-loxone">
  <button data-role="none" class="sm-b sm-b-aktion" name="token_neu" value="1"
    onclick="return confirm(<?= json_encode(strip_tags(html_entity_decode(db_t('LOX.TOKEN_FRAGE'), ENT_QUOTES, 'UTF-8'))) ?>)"><?= db_e(db_t('LOX.K_TOKEN_NEU')) ?></button>
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
</table>
</div>
</div>

<!-- ================= Test ================= -->
<div class="sm-seite<?= $db_tab === 'tab-test' ? ' sm-active' : '' ?>" id="tab-test">
<h2><?= db_e(db_t('TEST.H_SELBSTPRUEFUNG')) ?></h2>
<p class="sm-hilfe"><?= db_t('TEST.EINLEITUNG') ?></p>
<?= db_pruefungen_html() ?>

<h2><?= db_e(db_t('TEST.H_LESEN')) ?></h2>
<div class="sm-legende">
  <span style="background:#4f7d17"></span><?= db_t('LEGENDE.LESEN') ?><br>
  <span style="background:#6b7280"></span><?= db_t('LEGENDE.TECHNIK') ?>
</div>
<form action="index.php" method="post">
  <input data-role="none" type="hidden" name="activetab" value="tab-test">
  <button data-role="none" class="sm-b sm-b-lesen" name="test" value="status"><?= db_e(db_t('TEST.K_STATUS')) ?></button>
  <button data-role="none" class="sm-b sm-b-technik" name="test" value="roh"><?= db_e(db_t('TEST.K_ROH')) ?></button>
  <button data-role="none" class="sm-b sm-b-technik" name="selbsttest" value="1"><?= db_e(db_t('TEST.K_SELBSTTEST')) ?></button>
</form>

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
<div class="sm-legende"><span style="background:#d97706"></span><?= db_t('LEGENDE.AKTION_LOG') ?></div>
<form action="index.php" method="post">
  <input data-role="none" type="hidden" name="activetab" value="tab-log">
  <button data-role="none" class="sm-b sm-b-aktion" name="log_leeren" value="1"><?= db_e(db_t('LOG.K_LEEREN')) ?></button>
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
		             'bekannt' => $b['bekannt']);
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

	function zeichnen() {
		bau.innerHTML = '';
		var kopf = document.createElement('div');
		kopf.style.cssText = 'display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin:10px 0';
		kopf.innerHTML =
			'<input data-role="none" type="text" id="dz-suche" placeholder="' + e(TEXT.suchen) + '" style="flex:1;min-width:180px;padding:7px 10px;border:1px solid #ccc;border-radius:6px">' +
			'<label style="font-size:.86em"><input data-role="none" type="checkbox" id="dz-nur"> ' + e(TEXT.unbenutzt) + '</label>' +
			'<button data-role="none" type="button" class="sm-b sm-b-aktion" id="dz-neu">+ ' + e(TEXT.neue) + '</button>';
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
				'<button data-role="none" type="button" class="sm-b sm-b-technik" data-seiteweg="' + si + '" style="padding:4px 10px;margin:0">&times;</button>';
			kasten.appendChild(titel);

			var liste = document.createElement('div');
			liste.style.cssText = 'padding:8px;min-height:52px;display:flex;flex-wrap:wrap;gap:6px';
			liste.dataset.seite = si;
			if (!(s.kacheln || []).length) {
				liste.innerHTML = '<span class="sm-hilfe">' + e(TEXT.leer) + '</span>';
			}
			(s.kacheln || []).forEach(function (k, ki) {
				var b = baustein(k.uuid);
				var kk = document.createElement('div');
				kk.draggable = true;
				kk.dataset.seite = si; kk.dataset.kachel = ki;
				kk.style.cssText = 'border:1px solid ' + (b ? '#cfd8c0' : '#ef9a9a') + ';border-radius:7px;padding:6px 8px;background:' + (b ? '#fbfdf7' : '#fff5f5') + ';font-size:.83em;cursor:move;max-width:270px';
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
					'<div class="sm-hilfe" style="margin-top:2px">' + e(b ? (b.loxtyp + (b.raum ? ' · ' + b.raum : '')) : '?') + '</div>';
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
