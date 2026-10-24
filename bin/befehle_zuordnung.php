<?php
/**
 * bin/befehle_zuordnung.php - Zuordnungstabelle der Befehlsbruecke, Linie Dashboard.
 *
 * Gruppe 12 / MQTT (0.9.37; Bauvorschrift wz_bild/GRUPPE12_BAUVORSCHRIFT.md, Entwurf Musterprojekt/entwurf_gruppe12_db.txt
 * Abschnitte 3.2-3.4, ENTSCHEIDUNGEN_GRUPPE12_DASHBOARD.md E1-E13; Muster vb_g11_sp_bau/bin/befehle_zuordnung.php). Die
 * Bruecke selbst (bin/befehlsbruecke.php und .sh) ist byte-gleich die Stammfassung Werkzeuge/gemeinsam/ - linieneigen ist
 * nur diese Datei.
 *
 * Themen: <praefix>/befehl/seite | wecken | hell | ruhe (E1) - fuer ALLE Tablets (die Anzeige kennt keine Tafelnamen;
 * flache Namen, spaeter tafel/{tafel}/... ohne Bruch, E2). seite nimmt den Schluessel einer Seite oder ihre Nummer
 * (1 = erste Seite im Reiter Dashboards), wecken weckt die Bildschirme, hell setzt die Helligkeit 0..100, ruhe legt das
 * Ruhebild auf (1) oder nimmt es weg (0).
 *
 * Regeln (Entwurf gemeinsam 2.5): die Datei gibt ein Feld zurueck, setzt keine Konfiguration und schreibt nichts. 'tun'
 * ruft db_bb_tun() in webfrontend/html/db_lib.php: Upgrade-Marke, dann db_tafel_steuern() - DIESELBE Pruefung wie der
 * HTTP-Endpunkt (aktion=tafel: "Loxone darf die Anzeigeseite umschalten", Seite vorhanden, Ruhebild eingerichtet) und
 * dasselbe Schreiben nach tafel.json ueber db_tafel_befehl(). Kein Dienst, kein HTTP auf sich selbst, kein Kindprozess;
 * Quittung "ausgefuehrt" (tafel.json ist geschrieben).
 *
 * Haken: "Befehle ueber MQTT annehmen" (befehle_mqtt_ein, ab Werk aus) schaltet die Bruecke. Eingreifend ist keiner der
 * vier Befehle (E1): sie wirken allein auf die Anzeige, nie auf ein Geraet - deshalb heute ohne PIN, und deshalb kein
 * zweiter Haken. Kachelbefehle (befehl/szene, mit PIN) kommen NICHT auf die Bruecke.
 *
 * Sperrzeiten (E10): 0 s fuer alle vier (Begruendungen unten).
 *
 * Die Bibliothek liegt unter webfrontend/html/ (Endpunkt und Anzeigeseite teilen sie): installiert
 * <wurzel>/webfrontend/html/plugins/<ordner>/db_lib.php, im Archiv bzw. Pruefstand ../webfrontend/html/db_lib.php.
 */
if (!function_exists('db_bb_tun')) {
    $db_bb_kand = array();
    if (basename(dirname(__DIR__)) === 'plugins') {
        $db_bb_kand[] = dirname(dirname(dirname(__DIR__))) . '/webfrontend/html/plugins/' . basename(__DIR__) . '/db_lib.php';
    }
    $db_bb_kand[] = dirname(__DIR__) . '/webfrontend/html/db_lib.php';
    foreach ($db_bb_kand as $db_bb_k) {
        if (is_file($db_bb_k)) { require_once $db_bb_k; break; }
    }
}
/* Ein Eintrag: Art, Titel, Kachel, Sperre mit Begruendung, Quittung. tun() reicht Name und Wert an db_bb_tun(). */
$db_bb_e = function ($name, $art, $titel, $kachel, $grund, $extra = array()) {
    return array_merge(array(
        'art' => $art, 'eingreifend' => false, 'titel' => 'Dashboard ' . $titel . ' (MQTT)',
        'kachel' => $kachel, 'quittung' => 'ausgefuehrt', 'sperre_s' => 0, 'begruendung' => $grund,
        'tun' => function ($w, $t) use ($name) {
            return db_bb_tun($name, (string) $w);
        }), $extra);
};
return array(
    'fassung' => 1,
    'linie'   => 'dashboard',
    'plugin'  => 'Dashboard',
    'geladen' => function_exists('db_bb_tun') && function_exists('db_config')
                 && function_exists('db_bb_einstellungen') && function_exists('db_bb_protokoll'),
    'einstellungen' => function () {
        return db_bb_einstellungen();
    },
    'protokoll' => function ($stufe, $text) {
        db_bb_protokoll($stufe, $text);
    },
    'befehle' => array(
        'seite'  => $db_bb_e('seite', 'text', 'Tafelseite', 'BEFEHL.K_SEITE',
                             'Klingel und Heimkehr schalten mehrmals in der Minute auf dieselbe Seite; ist ein Tablet '
                             . 'inzwischen weitergeblaettert, soll der zweite Befehl wieder wirken.',
                             array('max_byte' => 60)),
        'wecken' => $db_bb_e('wecken', 'puls', 'Tafeln wecken', 'BEFEHL.K_WECKEN',
                             'Jedes Klingeln weckt; ein gleicher Befehl schreibt nur tafel.json neu.'),
        'hell'   => $db_bb_e('hell', 'ganz', 'Tafelhelligkeit', 'BEFEHL.K_HELL',
                             'Schieberegler senden viele Werte; die Bruecke legt sie ohnehin zusammen (neuester gewinnt), '
                             . 'ein Zurueckschieben auf den alten Wert binnen einer Minute muss wirken.',
                             array('min' => 0, 'max' => 100)),
        'ruhe'   => $db_bb_e('ruhe', 'schalter', 'Ruhebild', 'BEFEHL.K_RUHE',
                             'Anwesenheit wechselt ein-aus-ein binnen einer Minute; ein gleicher Wert aendert nichts.'),
    ),
);
