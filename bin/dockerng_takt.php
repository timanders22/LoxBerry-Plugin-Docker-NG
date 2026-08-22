<?php
/**
 * Docker NG - der Minutentakt
 *
 * Aufgerufen aus cron/cron.01min. Er ist die einzige Stelle des Plugins, die
 * den Zustand ueber die Zeit fortschreibt:
 *
 *   - den Herzschlag (ZAEHLER), an dem Loxone erkennt, dass der LoxBerry
 *     ueberhaupt noch antwortet,
 *   - die Erkennung von Neustartschleifen (zwei Momentaufnahmen noetig),
 *   - die Plattenbelegung (teuer, deshalb hoechstens alle 15 Minuten),
 *   - die Abbild-Aktualisierungen (nur wenn eingeschaltet, hoechstens taeglich),
 *   - die Veroeffentlichung nach MQTT (nur wenn eingeschaltet),
 *   - die Meldung ins Benachrichtigungszentrum (nur bei WECHSEL des Befundes).
 *
 * Oberflaeche und Endpunkt LESEN diese Datei nur. Der Endpunkt wird vom
 * Miniserver im Sechzigsekundentakt abgerufen; wuerde er selbst schreiben,
 * waere das ein Schreibvorgang je Minute auf der Speicherkarte.
 *
 * DIESES SKRIPT SCHREIBT NICHT NACH STDOUT.
 * Der Cron leitet stdout nicht um, und eine Ausgabe je Minute fuellt die
 * Systemprotokolle. Was zu sagen ist, geht in das Protokoll des Plugins, das
 * die Oberflaeche im Reiter Logdateien anzeigt. Nur '--einmal' auf der
 * Kommandozeile gibt eine Zusammenfassung aus - fuer die Gegenprobe von Hand
 * nach der Installation.
 */

error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
ini_set('display_errors', '0');

/* Die Bibliothek liegt unter webfrontend/html/. Der Weg von bin/ dorthin ist
 * NICHT in beiden Zustaenden derselbe:
 *
 *   im entpackten Archiv   <wurzel>/bin/  und  <wurzel>/webfrontend/html/
 *   installiert            <home>/bin/plugins/<ordner>/  und
 *                          <home>/webfrontend/html/plugins/<ordner>/
 *
 * Eine feste Zahl von '..' trifft deshalb immer nur einen der beiden Faelle.
 * Kandidatenliste statt Rechnung - dieselbe Loesung wie in der Oberflaeche,
 * aus demselben Grund.
 */
$dk_gefunden = '';
foreach (array(
    dirname(dirname(dirname(__DIR__))) . '/webfrontend/html/plugins/' . basename(__DIR__) . '/dk_lib.php',
    dirname(dirname(__DIR__)) . '/webfrontend/html/plugins/' . basename(__DIR__) . '/dk_lib.php',
    dirname(__DIR__) . '/webfrontend/html/dk_lib.php',
) as $dk_kandidat) {
    if (is_file($dk_kandidat)) { $dk_gefunden = $dk_kandidat; break; }
}
if ($dk_gefunden === '') {
    // Auf stderr, nicht auf stdout: der Cron faengt stderr auf, und ein
    // stiller Fehlschlag hier bedeutet, dass der Herzschlag monatelang
    // stillsteht, ohne dass jemand erfaehrt warum.
    fwrite(STDERR, "Docker NG: dk_lib.php nicht gefunden. Gesucht wurde unter:\n"
        . "  " . dirname(dirname(dirname(__DIR__))) . "/webfrontend/html/plugins/" . basename(__DIR__) . "/\n"
        . "  " . dirname(dirname(__DIR__)) . "/webfrontend/html/plugins/" . basename(__DIR__) . "/\n"
        . "  " . dirname(__DIR__) . "/webfrontend/html/\n"
        . "Bitte das Plugin neu installieren.\n");
    exit(1);
}
require_once $dk_gefunden;

$dk_laut = in_array('--einmal', $argv, true);

$dk_e = dk_takt();

if ($dk_laut) {
    $b = $dk_e['befund'];
    echo "Docker NG - Minutentakt einmal von Hand ausgefuehrt\n";
    echo "  Herzschlag      : " . $dk_e['zaehler'] . "\n";
    echo "  Neustartschleife: " . $dk_e['schleife'] . "\n";
    echo "  MQTT gesendet   : " . $dk_e['mqtt'] . " Meldungen\n";
    echo "  Befund          : " . $b['kennung'] . " (Schwere " . $b['schwere'] . ")\n";
    echo "                    " . $b['text'] . "\n";
    echo "  Zustandsdatei   : " . dk_paths()['zustand'] . "\n";
}
exit(0);
