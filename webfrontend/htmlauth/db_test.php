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
        //
        // HOECHSTENS EINMAL JE MINUTE. Bis 0.9.12 lief dieser Aufruf bei JEDEM
        // Seitenaufruf: die Reiter werden alle serverseitig gebaut und erst im
        // Browser umgeschaltet, der Reiter Test also auch dann, wenn der
        // Bediener in den Einstellungen steht. Bei nicht erreichbarem
        // Miniserver kostete das gemessene 2,007 s - je Klick und je
        // Speichervorgang, und ausgerechnet dann, wenn jemand gerade die
        // falsche Adresse berichtigen will.
        //
        // Gemessen wird weiter, nur nicht sechzigmal in der Minute dasselbe.
        // Wie alt der Messwert ist, steht in der Antwort - eine Anzeige, die
        // ihr Alter verschweigt, ist eine Behauptung.
        // Die beiden Schluessel stehen AUSGESCHRIEBEN in je einem eigenen
        // sprintf. Als Ternaer im ersten Argument waren sie fuer
        // sprachplatzhalter_pruefen.py unsichtbar, und das Werkzeug meldete
        // zu Recht "traegt Platzhalter, wird aber nirgends durch sprintf
        // gereicht". Sauberer Code, der eine Pruefung blind macht, ist keiner.
        list($db_erreichbar, $db_alter_s) = db_erreichbarkeit($ms['adresse'], (int) $ms['port']);
        if ($db_erreichbar) {
            $zeilen[] = db_pruefzeile(1, db_t('TEST.F_ERREICHBAR'),
                sprintf(db_t('TEST.A_ERREICHBAR'),
                        db_e($ms['adresse']), (int) $ms['port'], (int) $db_alter_s));
        } else {
            $zeilen[] = db_pruefzeile(0, db_t('TEST.F_ERREICHBAR'),
                sprintf(db_t('TEST.A_NICHT_ERREICHBAR'),
                        db_e($ms['adresse']), (int) $ms['port'], (int) $db_alter_s));
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
            if (!is_array($k)) { continue; }
            // Eine Szene haengt an keinem einzelnen Baustein und traegt
            // deshalb bewusst KEINE UUID (htmlauth/index.php legt sie mit
            // uuid: '' an und erzwingt das beim Speichern noch einmal). Bis
            // 0.9.12 fehlte diese Zeile: jede Szene zaehlte als verwaist,
            // und der Reiter Test meldete "n Kacheln zeigen ins Leere.
            // Meist wurde der Baustein in Loxone Config umbenannt oder
            // geloescht" - bei vier Szenen viermal, ohne dass eine einzige
            // davon defekt war. Der Designer wusste es besser als die
            // Pruefung, die ihn pruefen soll.
            if ((string) (isset($k['kachel']) ? $k['kachel'] : '') === 'szene') { continue; }
            $uuid = (string) (isset($k['uuid']) ? $k['uuid'] : '');
            if ($uuid === '' || !isset($bekannt[$uuid])) { $verwaist++; }
        }
    }
    if ($b) {
        $zeilen[] = db_pruefzeile($verwaist === 0 ? 1 : 0, db_t('TEST.F_VERWAIST'),
            $verwaist === 0 ? db_t('TEST.A_KEINE_VERWAIST')
                            : sprintf(db_t('TEST.A_VERWAIST'), $verwaist));
    }

    $zeilen[] = db_pruefzeile(!empty($cfg['steuerung_ein']) ? 1 : -1, db_t('TEST.F_STEUERUNG'),
        !empty($cfg['steuerung_ein']) ? db_t('TEST.A_STEUERUNG_EIN') : db_t('TEST.A_STEUERUNG_AUS'));

    // Der Miniserver selbst - Seriennummer und Projektname stehen in der
    // Struktur und wurden bis 0.9.5 nirgends gezeigt.
    $z = db_zustand();
    $msinfo = isset($z['msinfo']) && is_array($z['msinfo']) ? $z['msinfo']
            : (isset($s['msinfo']) && is_array($s['msinfo']) ? $s['msinfo'] : array());
    if ($msinfo) {
        $zeilen[] = db_pruefzeile(1, db_t('TEST.F_MSINFO'),
            db_e(trim((isset($msinfo['msName']) ? $msinfo['msName'] : '?')
                 . ' · ' . (isset($msinfo['projectName']) ? $msinfo['projectName'] : '?')
                 . ' · ' . (isset($msinfo['serialNr']) ? $msinfo['serialNr'] : '?'))));
    }

    // Welche Ereignistabellen ankommen. Sie zu verschweigen waere ein blinder
    // Fleck - deshalb steht die Zeile da, sobald der Dienst einmal lief,
    // auch mit lauter Nullen. Vor dem ersten Lauf (oder bei einer
    // zustand.json aus 0.9.12) fehlt das Feld und die Zeile entfaellt -
    // nichts zu sagen ist besser, als eine Null zu behaupten.
    //
    // Bis 0.9.12 hing sie an 'wetter oder tageszeit' aus einem Feld namens
    // 'uebergangen', dessen beide Zaehler seit 0.9.6 nie mehr erhoeht wurden
    // (die Tabellen werden seither ausgewertet). Die Zeile war damit
    // unerreichbar, und ihr Text behauptete, Wetter und Tageszeit wuerden
    // "bewusst nicht ausgewertet" - waehrend zwei Kacheln genau daraus leben.
    $tab = isset($z['tabellen']) && is_array($z['tabellen']) ? $z['tabellen'] : array();
    if ($tab) {
        $zeilen[] = db_pruefzeile(-1, db_t('TEST.F_TABELLEN'),
            sprintf(db_t('TEST.A_TABELLEN'),
                    (int) (isset($tab['wetter']) ? $tab['wetter'] : 0),
                    (int) (isset($tab['tageszeit']) ? $tab['tageszeit'] : 0),
                    (int) (isset($tab['bindatei']) ? $tab['bindatei'] : 0)));
    }

    // Die Vorgabewerte der Oberflaeche und die des Dienstes muessen
    // uebereinstimmen. Ein Kommentar, der das nur behauptet, ist eine
    // Absichtserklaerung - bis 0.9.5 fehlte 'haptik' auf der Python-Seite.
    $py = db_paths()['bindir'] . '/dashboard_dienst.py';
    if (is_file($py)) {
        $roh = (string) @file_get_contents($py);
        if (preg_match('/VORGABEN\s*=\s*\{(.*?)\n\}/s', $roh, $m)) {
            preg_match_all('/"([a-z_]+)"\s*:/', $m[1], $t);
            $dort = array_values($t[1]);
            $hier = array_keys(db_vorgaben());
            $fehlt = array_merge(array_diff($hier, $dort), array_diff($dort, $hier));
            $zeilen[] = db_pruefzeile($fehlt ? 0 : 1, db_t('TEST.F_VORGABEN'),
                $fehlt ? sprintf(db_t('TEST.A_VORGABEN_FEHL'), db_e(implode(', ', $fehlt)))
                       : sprintf(db_t('TEST.A_VORGABEN'), count($hier)));
        }
    }

    // Jede Kachel, die eine Seite benutzt, muss es in tafel.php auch geben -
    // sonst faellt sie stumm auf die generische Liste zurueck.
    $tafel = dirname(__DIR__) . '/html/tafel.php';
    if (!is_file($tafel)) { $tafel = db_paths()['home'] . '/webfrontend/html/plugins/'
                                   . db_paths()['plugin'] . '/tafel.php'; }
    if (is_file($tafel)) {
        $roh = (string) @file_get_contents($tafel);
        preg_match_all('/BAUER\.([a-z]+)\s*=/', $roh, $m);
        $gebaut = array_flip($m[1]);
        $gebraucht = array();
        foreach (db_kacheltabelle()['typen'] as $z2) {
            $gebraucht[isset($z2['kachel']) ? $z2['kachel'] : 'generisch'] = 1;
        }
        $ohne = array_keys(array_diff_key($gebraucht, $gebaut));
        $zeilen[] = db_pruefzeile($ohne ? 0 : 1, db_t('TEST.F_KACHELN'),
            $ohne ? sprintf(db_t('TEST.A_KACHELN_FEHL'), db_e(implode(', ', $ohne)))
                  : sprintf(db_t('TEST.A_KACHELN'), count($gebraucht)));

        /* Dasselbe fuer das Ruhebild: jede Kachelart braucht dort entweder
         * einen Kurzwert (RUHE_KURZ) oder einen ausdruecklichen Grund, warum
         * sie keinen hat (RUHE_OHNE). Ohne diese Zeile faellt beim naechsten
         * neuen Bausteintyp wieder etwas stumm heraus - und im Ruhebild
         * heisst "stumm" nicht "leer", sondern eine falsche Zahl.
         *
         * Die erste Fassung von 0.9.13 hatte fuenf Sonderfaelle und liess
         * alles Uebrige durch zahl(): ein ausgeloester Brandmelder stand als
         * "1,0" da, ein Wetterdienst als "[object Object]". */
        $ruhe_kurz = array();
        if (preg_match('/var RUHE_KURZ = \{(.*?)
\};/s', $roh, $mk)) {
            preg_match_all('/^\s{2}([a-z]+):\s*function/m', $mk[1], $m2);
            $ruhe_kurz = array_flip($m2[1]);
        }
        $ruhe_ohne = array();
        if (preg_match('/var RUHE_OHNE = \{(.*?)
\};/s', $roh, $mo)) {
            preg_match_all('/^\s{2}([a-z]+):/m', $mo[1], $m3);
            $ruhe_ohne = array_flip($m3[1]);
        }
        $ruhe_fehlt = array_keys(array_diff_key($gebraucht,
                                                $ruhe_kurz + $ruhe_ohne));
        $zeilen[] = db_pruefzeile($ruhe_fehlt ? 0 : 1, db_t('TEST.F_RUHE_KACHELN'),
            $ruhe_fehlt
                ? sprintf(db_t('TEST.A_RUHE_KACHELN_FEHL'), db_e(implode(', ', $ruhe_fehlt)))
                : sprintf(db_t('TEST.A_RUHE_KACHELN'),
                          count($ruhe_kurz), count($ruhe_ohne)));
    }

    // Reiterleiste, Bereiche und Positivliste muessen dieselben Namen
    // fuehren. Die Leiste steht ausgeschrieben im Rumpf, damit
    // hausstandard_pruefen.py sie findet - dafuer prueft diese Zeile, dass
    // sie nicht auseinanderlaeuft. Fehlt ein Name in der Positivliste, ist
    // der Reiter sichtbar und anklickbar, springt aber nach jedem Absenden
    // zurueck auf Einstellungen.
    $ui = __DIR__ . '/index.php';
    if (is_file($ui)) {
        $roh = (string) @file_get_contents($ui);
        preg_match_all('/data-ziel="(tab-[a-z0-9]+)"/', $roh, $m1);
        preg_match_all('/id="(tab-[a-z0-9]+)"/', $roh, $m2);
        preg_match_all("/'([a-z0-9]+)'\s*=>\s*'REITER\./", $roh, $m3);
        preg_match('/\^tab-\(([a-z0-9|]+)\)/', $roh, $m4);
        $leiste = array_unique($m1[1]);
        $bereiche = array_unique($m2[1]);
        $liste = array_map(function ($k) { return 'tab-' . $k; }, $m3[1]);
        $muster = isset($m4[1]) ? array_map(function ($k) { return 'tab-' . $k; },
                                            explode('|', $m4[1])) : array();
        sort($leiste); sort($bereiche); sort($liste); sort($muster);
        $gleich = ($leiste === $bereiche && $leiste === $liste && $leiste === $muster
                   && count($leiste) > 0);
        $zeilen[] = db_pruefzeile($gleich ? 1 : 0, db_t('TEST.F_REITER'),
            $gleich ? sprintf(db_t('TEST.A_REITER'), count($leiste))
                    : sprintf(db_t('TEST.A_REITER_FEHL'),
                              db_e(implode(', ', $leiste)),
                              db_e(implode(', ', $bereiche)),
                              db_e(implode(', ', $liste))));
    }

    return $zeilen;
}

function db_pruefungen_html()
{
    $zeilen = db_pruefungen();
    // Eine Pruefung ohne Fundstellen ist kein Nachweis, sondern ein blinder
    // Fleck. Deshalb sagt die Tabelle, WIE VIELE Stellen sie angesehen hat.
    return '<table class="sm-tabelle"><tr><th>&nbsp;</th><th>' . db_e(db_t('TEST.T_FRAGE'))
         . '</th><th>' . db_e(db_t('TEST.T_BEFUND')) . '</th></tr>'
         . implode('', $zeilen) . '</table>'
         . '<p class="sm-hilfe">' . sprintf(db_e(db_t('TEST.ANZAHL')), count($zeilen)) . '</p>';
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
