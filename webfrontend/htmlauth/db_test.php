<?php
/**
 * Dashboard-Designer - die Pruefungen des Reiters Test.
 *
 * Getrennt von der Oberflaeche, damit index.php nur Darstellung ist. Jede
 * Zeile beantwortet eine Frage und nennt bei einem Kreuz die Abhilfe mit -
 * ein "Fehler" ohne Hinweis, was zu tun ist, hilft niemandem.
 */

function db_pruefzeile($stand, $frage, $befund)
{
    $z = array(1 => array('&#10003;', '#1a7f1a'), 0 => array('&#10007;', '#b00000'),
               -1 => array('&#8226;', '#888'));
    $s = isset($z[$stand]) ? $z[$stand] : $z[-1];
    return '<tr><td style="color:' . $s[1] . ';font-weight:700;width:22px;text-align:center">'
         . $s[0] . '</td><td>' . $frage . '</td><td>' . $befund . '</td></tr>';
}

function db_pruefungen()
{
    $zeilen = array();
    $cfg = db_config();

    $pid = db_dienst_pid();
    $soll = db_dienst_soll();
    if ($pid > 0) {
        $zeilen[] = db_pruefzeile(1, db_t('TEST.F_DIENST'),
            db_e(db_t('TEST.A_DIENST_LAEUFT')) . ' ' . (int) $pid);
    } elseif ($soll) {
        $zeilen[] = db_pruefzeile(0, db_t('TEST.F_DIENST'), db_t('TEST.A_DIENST_SOLL_TOT'));
    } else {
        $zeilen[] = db_pruefzeile(0, db_t('TEST.F_DIENST'), db_t('TEST.A_DIENST_GESTOPPT'));
    }

    $ms = db_miniserver();
    if (!$ms) {
        $zeilen[] = db_pruefzeile(0, db_t('TEST.F_MS'), db_t('TEST.A_KEIN_MS'));
    } else {
        $zeilen[] = db_pruefzeile(1, db_t('TEST.F_MS'),
            db_e($ms['name'] . ' - ' . $ms['adresse'] . ':' . $ms['port']) . ' &mdash; '
            . db_e($ms['quelle'] === 'loxberry' ? db_t('TEST.A_MS_LOXBERRY') : db_t('TEST.A_MS_EIGEN')));
        $zeilen[] = db_pruefzeile($ms['benutzer'] !== '' ? 1 : 0, db_t('TEST.F_BENUTZER'),
            $ms['benutzer'] !== '' ? db_e($ms['benutzer']) : db_t('TEST.A_KEIN_BENUTZER'));
        // Der Wert des Kennworts wird NIE angezeigt - nur, ob eines da ist.
        $zeilen[] = db_pruefzeile(!empty($ms['passwort_da']) ? 1 : -1, db_t('TEST.F_KENNWORT'),
            !empty($ms['passwort_da']) ? db_t('TEST.A_KENNWORT_DA') : db_t('TEST.A_KENNWORT_LEER'));
        // Erreichbarkeit: nur der TCP-Port, kein Anmeldeversuch. Ein
        // fehlgeschlagener Anmeldeversuch je Seitenaufruf wuerde den
        // Miniserver irgendwann sperren.
        $auf = @fsockopen($ms['adresse'], (int) $ms['port'], $en, $es, 2);
        if ($auf) {
            fclose($auf);
            $zeilen[] = db_pruefzeile(1, db_t('TEST.F_ERREICHBAR'),
                sprintf(db_t('TEST.A_ERREICHBAR'), db_e($ms['adresse']), (int) $ms['port']));
        } else {
            $zeilen[] = db_pruefzeile(0, db_t('TEST.F_ERREICHBAR'),
                sprintf(db_t('TEST.A_NICHT_ERREICHBAR'), db_e($ms['adresse']), (int) $ms['port']));
        }
    }

    $b = db_bausteine();
    $s = db_struktur();
    if (!$b) {
        $zeilen[] = db_pruefzeile(0, db_t('TEST.F_STRUKTUR'), db_t('TEST.A_KEINE_STRUKTUR'));
    } else {
        $eigen = 0;
        foreach ($b as $x) { if (!empty($x['bekannt'])) { $eigen++; } }
        $zeilen[] = db_pruefzeile(1, db_t('TEST.F_STRUKTUR'),
            sprintf(db_t('TEST.A_STRUKTUR'), count($b), $eigen, count($b) - $eigen,
                    db_e((string) (isset($s['lastModified']) ? $s['lastModified'] : '?'))));
    }

    $alter = db_alter();
    if ($alter < 0) {
        $zeilen[] = db_pruefzeile(0, db_t('TEST.F_WERTE'), db_t('TEST.A_KEINE_WERTE'));
    } elseif ($alter > 120) {
        $zeilen[] = db_pruefzeile(0, db_t('TEST.F_WERTE'), sprintf(db_t('TEST.A_WERTE_ALT'), $alter));
    } else {
        $a = db_abbild();
        $zeilen[] = db_pruefzeile(1, db_t('TEST.F_WERTE'),
            sprintf(db_t('TEST.A_WERTE'), $alter,
                    db_e((string) (isset($a['weg']) ? $a['weg'] : '?'))));
        if (isset($a['weg']) && $a['weg'] === 'http') {
            $zeilen[] = db_pruefzeile(-1, db_t('TEST.F_WEG'), db_t('TEST.A_WEG_HTTP'));
        }
    }

    $seiten = db_seiten();
    $kacheln = 0;
    foreach ($seiten as $x) { $kacheln += count(isset($x['kacheln']) ? $x['kacheln'] : array()); }
    if (!$seiten) {
        $zeilen[] = db_pruefzeile(0, db_t('TEST.F_SEITEN'), db_t('TEST.A_KEINE_SEITEN'));
    } else {
        $zeilen[] = db_pruefzeile($kacheln > 0 ? 1 : 0, db_t('TEST.F_SEITEN'),
            sprintf(db_t('TEST.A_SEITEN'), count($seiten), $kacheln));
    }

    // Kacheln, deren Baustein nicht mehr existiert
    $bekannt = array();
    foreach ($b as $x) { $bekannt[$x['uuid']] = 1; }
    $verwaist = 0;
    foreach ($seiten as $x) {
        foreach ((isset($x['kacheln']) ? $x['kacheln'] : array()) as $k) {
            if (!isset($bekannt[(string) $k['uuid']])) { $verwaist++; }
        }
    }
    if ($b) {
        $zeilen[] = db_pruefzeile($verwaist === 0 ? 1 : 0, db_t('TEST.F_VERWAIST'),
            $verwaist === 0 ? db_t('TEST.A_KEINE_VERWAIST')
                            : sprintf(db_t('TEST.A_VERWAIST'), $verwaist));
    }

    $zeilen[] = db_pruefzeile(!empty($cfg['steuerung_ein']) ? 1 : -1, db_t('TEST.F_STEUERUNG'),
        !empty($cfg['steuerung_ein']) ? db_t('TEST.A_STEUERUNG_EIN') : db_t('TEST.A_STEUERUNG_AUS'));

    return $zeilen;
}

function db_pruefungen_html()
{
    return '<table class="sm-tabelle"><tr><th>&nbsp;</th><th>' . db_e(db_t('TEST.T_FRAGE'))
         . '</th><th>' . db_e(db_t('TEST.T_BEFUND')) . '</th></tr>'
         . implode('', db_pruefungen()) . '</table>';
}

/** Die Knoepfe des Reiters Test. Rueckgabe: array(stand, Text). */
function db_test_aktion($was)
{
    if ($was === 'status') {
        $a = db_abbild();
        $seiten = db_seiten();
        $kacheln = 0;
        foreach ($seiten as $s) { $kacheln += count(isset($s['kacheln']) ? $s['kacheln'] : array()); }
        return array(1, sprintf('DASHBOARD;OK=%d;BAUSTEINE=%d;SEITEN=%d;KACHELN=%d;ALTER=%d',
            (int) (!empty($a['ok'])), count(db_bausteine()), count($seiten), $kacheln, db_alter()));
    }
    if ($was === 'roh') {
        $a = db_abbild();
        if (!$a) { return array(0, db_t('TEST.M_KEIN_ABBILD')); }
        return array(1, json_encode($a, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
    return array(0, db_t('TEST.M_UNBEKANNT'));
}
