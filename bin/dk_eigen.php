<?php
/**
 * Docker NG - welcher Container gehoert diesem Plugin? (C1, Entscheidung 9)
 *
 * Die GEMEINSAME Pruefung fuer die Hakenskripte: uninstall/uninstall und
 * postroot.sh rufen sie auf, der Knopf "Portainer neu starten" benutzt
 * dieselbe Funktion dk_eigener_container() aus dk_lib.php. Bis 1.3.9 hatten
 * Knopf, Deinstallation und postroot.sh je ihre eigene Antwort - alle drei
 * am Namen, keiner am Label.
 *
 * Aufruf:  php dk_eigen.php <ordner>
 * Ausgabe, je Zeile, durch Tabulator getrennt:
 *   EIGEN   <name>  <LABEL|ALTBESTAND>  <laeuft 0|1>  <abbild>
 *   FREMD   <name>  <grund>  <abbild>        wird NICHT angefasst
 *   FEHLER  <grund>                          Docker nicht zu fragen
 * Rueckgabe: 0 geprueft, 2 nicht pruefbar (dann nichts anfassen).
 *
 * Laeuft als root. Es wird deshalb nichts protokolliert (dk_log_aus): eine
 * Zeile von hier legte sonst die Protokolldatei root-eigen an.
 */
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
ini_set('display_errors', '0');

$dk_lib = '';
foreach (array(
    dirname(dirname(dirname(__DIR__))) . '/webfrontend/html/plugins/' . basename(__DIR__) . '/dk_lib.php',
    dirname(dirname(__DIR__)) . '/webfrontend/html/plugins/' . basename(__DIR__) . '/dk_lib.php',
    dirname(__DIR__) . '/webfrontend/html/dk_lib.php',
) as $k) {
    if (is_file($k)) { $dk_lib = $k; break; }
}
if ($dk_lib === '') {
    echo "FEHLER\tBIBLIOTHEK_FEHLT\n";
    exit(2);
}
require_once $dk_lib;
dk_log_aus(true);
dk_zeitgrenze(30);

$dk_ordner = (isset($argv[1]) && preg_match('/^[A-Za-z0-9_]{1,64}\z/', (string) $argv[1]))
    ? (string) $argv[1] : 'dockerng';

list($dk_ok, $dk_grund) = dk_zustand(true);
if (!$dk_ok) {
    echo "FEHLER\t" . ($dk_grund !== '' ? $dk_grund : 'DOCKER') . "\n";
    exit(2);
}
list($dk_ok, $dk_eigen, $dk_fremde) = dk_eigener_container(true, $dk_ordner);
if (!$dk_ok) {
    echo "FEHLER\tNICHT_PRUEFBAR\n";
    exit(2);
}
if ($dk_eigen !== null) {
    $dk_laeuft = 0;
    foreach (dk_container() as $c) {
        if ($c['name'] === $dk_eigen[0]) { $dk_laeuft = (int) $c['laeuft']; }
    }
    echo "EIGEN\t" . $dk_eigen[0] . "\t" . $dk_eigen[1] . "\t" . $dk_laeuft . "\t" . $dk_eigen[2] . "\n";
}
foreach ($dk_fremde as $f) {
    echo "FREMD\t" . $f[0] . "\t" . $f[1] . "\t" . $f[2] . "\n";
}
exit(0);
