<?php
/**
 * Docker NG - Ports fuer postroot.sh (C12, neu in 1.3.9)
 *
 * Aufruf:  php dk_ports.php lesen
 *            -> "<http> <https>" aus der Konfiguration (ohne Selbstheilung)
 *          php dk_ports.php setzen <http> <https>
 *            -> schreibt beide in die Konfiguration und, wenn ein Merkwort
 *               darin steht, in die Zweitschrift (dk_config_schreiben);
 *               Ausgabe "KONFIG 0|1 ZWEIT 0|1"
 * Rueckgabe: 0 gut, 1 Schreiben gescheitert, 2 falscher Aufruf.
 *
 * Laeuft als root. Es wird nichts protokolliert (dk_log_aus); postroot.sh
 * gibt die geschriebenen Dateien danach an loxberry.
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
    fwrite(STDERR, "dk_lib.php nicht gefunden\n");
    exit(2);
}
require_once $dk_lib;
dk_log_aus(true);

$dk_modus = isset($argv[1]) ? (string) $argv[1] : '';
if ($dk_modus === 'lesen') {
    $c = dk_config(false);
    echo (int) $c['portainer_port'] . ' ' . (int) $c['portainer_https_port'] . "\n";
    exit(0);
}
if ($dk_modus === 'setzen') {
    $h = isset($argv[2]) && preg_match('/^\d{4,5}\z/', (string) $argv[2]) ? (int) $argv[2] : 0;
    $s = isset($argv[3]) && preg_match('/^\d{4,5}\z/', (string) $argv[3]) ? (int) $argv[3] : 0;
    if ($h < 1024 || $h > 65535 || $s < 1024 || $s > 65535 || $h === $s) {
        fwrite(STDERR, "Ports unzulaessig\n");
        exit(2);
    }
    $c = dk_config(false);
    $c['portainer_port'] = $h;
    $c['portainer_https_port'] = $s;
    $ok = dk_config_schreiben($c);
    $zweit = $ok && is_string($c['aktionstoken']) && trim($c['aktionstoken']) !== '';
    echo 'KONFIG ' . ($ok ? 1 : 0) . ' ZWEIT ' . ($zweit ? 1 : 0) . "\n";
    exit($ok ? 0 : 1);
}
fwrite(STDERR, "Aufruf: php dk_ports.php lesen | setzen <http> <https>\n");
exit(2);
