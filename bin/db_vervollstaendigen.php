<?php
/**
 * Konfiguration vervollstaendigen - Kommandozeile (seit 0.9.43, Regeln/05).
 *
 * Aufgerufen vom Dienst beim Start (konfiguration_vervollstaendigen() in
 * bin/dashboard_dienst.py), bevor er die Konfiguration zum ersten Mal liest,
 * und aus postupgrade.sh. Geschrieben wird ausschliesslich ueber
 * db_config_vervollstaendigen() in webfrontend/html/db_lib.php - der Dienst
 * schreibt dashboard.json nicht selbst (ein Schreiber fuer ein Format).
 * Bauart: LoxBerry-Plugin-BLE-Scanner-1.3.40 (bin/bl_vervollstaendigen.php).
 *
 * Aufruf: php db_vervollstaendigen.php <pluginordner>
 * Ausgabe genau eine Zeile:
 *   VERVOLLSTAENDIGEN;STAND=<ruht|ohne_datei|ohne_merkwort|vollstaendig|ergaenzt|fehler>;SCHLUESSEL=a,b
 *   VERVOLLSTAENDIGEN;STAND=0;KENNUNG=<AUFRUF|WURZEL|MODUL_FEHLT>
 * Rueckgabe 0 bei ruht, ohne_datei, ohne_merkwort, vollstaendig, ergaenzt; sonst 1.
 * Die Zeile nennt nur Schluesselnamen, nie einen Wert.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Nur Kommandozeile.\n");
}
$db_o = isset($argv[1]) ? (string) $argv[1] : '';
if (count($argv) !== 2 || preg_match('/^[A-Za-z0-9_-]{1,64}\z/', $db_o) !== 1) {
    echo "VERVOLLSTAENDIGEN;STAND=0;KENNUNG=AUFRUF\n";
    exit(1);
}
$db_wurzel = function ($h) {
    return is_string($h) && $h !== '' && is_dir($h . '/config/plugins') && is_dir($h . '/data/plugins')
        && is_file($h . '/config/system/general.json');
};
$db_h = getenv('LBHOMEDIR');
if (!$db_wurzel($db_h)) {
    $db_h = dirname(dirname(dirname(__DIR__)));
    if (!$db_wurzel($db_h)) {
        echo "VERVOLLSTAENDIGEN;STAND=0;KENNUNG=WURZEL\n";
        exit(1);
    }
}
$db_h = rtrim($db_h, '/');
$db_lib = $db_h . '/webfrontend/html/plugins/' . $db_o . '/db_lib.php';
if (!is_file($db_lib)) {
    echo "VERVOLLSTAENDIGEN;STAND=0;KENNUNG=MODUL_FEHLT\n";
    exit(1);
}
putenv('LBHOMEDIR=' . $db_h);
putenv('LBPPLUGINDIR=' . $db_o);
require_once $db_lib;
$db_e = db_config_vervollstaendigen();
echo 'VERVOLLSTAENDIGEN;STAND=' . $db_e['stand'] . ';SCHLUESSEL=' . implode(',', $db_e['schluessel']) . "\n";
exit(in_array($db_e['stand'], array('ruht', 'ohne_datei', 'ohne_merkwort', 'vollstaendig', 'ergaenzt'), true) ? 0 : 1);
