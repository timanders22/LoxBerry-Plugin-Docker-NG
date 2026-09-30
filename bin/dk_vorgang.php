<?php
/**
 * Docker NG - aktualisiert das Portainer-Abbild im Hintergrund
 * (Verbesserungsbau 30.09.2026, a1).
 *
 * Gestartet von der Oberflaeche (Reiter Einstellungen, Knopf
 * "Portainer-Abbild aktualisieren") ueber dk_vorgang_starten(). Im
 * Hintergrund, weil docker pull auf einem Raspberry Pi Minuten dauert und
 * die Seite nicht so lange warten soll. Der Vorgang schreibt seinen Stand
 * nach data/plugins/<ordner>/portainer_vorgang.json (0600); die Seite zeigt
 * "wird aktualisiert ... seit N s" und laedt sich neu, solange er laeuft.
 * Bauform: MGiSmart bin/gateway_vorgang.php, Matter2Lox bin/container_vorgang.php.
 *
 * Kein Takt und kein Hakenskript ruft diese Datei.
 *
 * Aufruf: php dk_vorgang.php aktualisieren|label
 * (label, Nachtrag 01.10.2026: den Altbestand ohne Label mit denselben
 * Ports, Volumes und Token neu anlegen und die Labels vergeben.)
 * Rueckgabewert: 0 erledigt, 1 nicht gelungen, 2 falscher Aufruf,
 * 3 es laeuft schon ein Vorgang.
 */
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
ini_set('display_errors', '0');

$dk_lib = '';
foreach (array(
    dirname(dirname(dirname(__DIR__))) . '/webfrontend/html/plugins/' . basename(__DIR__) . '/dk_lib.php',
    dirname(dirname(__DIR__)) . '/webfrontend/html/plugins/' . basename(__DIR__) . '/dk_lib.php',
    dirname(__DIR__) . '/webfrontend/html/dk_lib.php',
) as $dk_k) {
    if (is_file($dk_k)) { $dk_lib = $dk_k; break; }
}
if ($dk_lib === '') {
    fwrite(STDERR, "dk_lib.php nicht gefunden - Plugin neu installieren.\n");
    exit(1);
}
require_once $dk_lib;

$dk_p = dk_paths();
if ($dk_p['home'] === '' || !@is_dir($dk_p['home'] . '/config/plugins')) {
    fwrite(STDERR, "dk_vorgang.php: keine LoxBerry-Installation gefunden - nichts angefasst.\n");
    exit(1);
}
/* Die Sprache der Meldungen wie in der Oberflaeche (LBSystem::lblanguage). */
if (is_file($dk_p['home'] . '/libs/phplib/loxberry_system.php')) {
    require_once $dk_p['home'] . '/libs/phplib/loxberry_system.php';
}
/* Laufzeitfehler in eine Datei, nicht auf die Fehlerausgabe: die geht beim
 * Start nach /dev/null (Regeln/03, "dritte Protokollart"). */
if (!@is_dir($dk_p['logdir'])) { @mkdir($dk_p['logdir'], 0775, true); }
ini_set('log_errors', '1');
ini_set('error_log', $dk_p['logdir'] . '/vorgang.err');

$dk_auftrag = isset($argv[1]) ? (string) $argv[1] : '';
if (!in_array($dk_auftrag, array('aktualisieren', 'label'), true) || count($argv) !== 2) {
    fwrite(STDERR, "Unbekannter Auftrag - erlaubt sind nur: aktualisieren, label\n");
    exit(2);
}

/* Zweimal laufen geht nicht: lebt schon ein anderer Vorgang, endet dieser. */
$dk_v = dk_vorgang();
if ($dk_v['zustand'] === 'laeuft' && (int) $dk_v['pid'] !== getmypid()) {
    fwrite(STDERR, 'Es laeuft bereits ein Vorgang (PID ' . (int) $dk_v['pid'] . ").\n");
    exit(3);
}
$dk_start = (isset($dk_v['start']) && (int) $dk_v['start'] > 0) ? (int) $dk_v['start'] : time();
dk_vorgang_schreiben(array('vorgang' => $dk_auftrag, 'zustand' => 'laeuft', 'pid' => getmypid(),
    'start' => $dk_start, 'meldung' => ''));
dk_zeitgrenze(60);
list($dk_ok, $dk_text, $dk_art) = ($dk_auftrag === 'label') ? dk_portainer_label_anlegen() : dk_portainer_aktualisieren();
dk_vorgang_schreiben(array('vorgang' => $dk_auftrag, 'zustand' => $dk_ok ? 'fertig' : 'fehler',
    'art' => $dk_art, 'pid' => getmypid(), 'start' => $dk_start, 'ende' => time(), 'meldung' => $dk_text));
dk_log('Portainer ' . ($dk_auftrag === 'label' ? 'mit Label neu anlegen' : 'aktualisieren') . ': '
    . ($dk_ok ? 'fertig' : 'gescheitert') . ' - ' . $dk_text);
exit($dk_ok ? 0 : 1);
