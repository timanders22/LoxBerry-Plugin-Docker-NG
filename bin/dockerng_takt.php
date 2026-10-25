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
 * LoxBerry verwirft stdout ohnehin (/etc/cron.d/lbdefaults), und wer das
 * Skript anders einhaengt, fuellte mit einer Ausgabe je Minute die
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
    // Auf stderr, nicht auf stdout: cron/cron.01min leitet stderr nach
    // log/plugins/<ordner>/cron.err, und der Reiter Logdateien zeigt die
    // Datei an. BERICHTIGT in 1.3.7 - bis dahin stand hier "der Cron faengt
    // stderr auf". Das tat er nicht: LoxBerry ruft jede Cron-Datei mit
    // "> /dev/null 2>&1" auf, die Meldung ging verloren. Ein stiller
    // Fehlschlag hier bedeutet, dass der Herzschlag monatelang stillsteht,
    // ohne dass jemand erfaehrt warum.
    fwrite(STDERR, "Docker NG: dk_lib.php nicht gefunden. Gesucht wurde unter:\n"
        . "  " . dirname(dirname(dirname(__DIR__))) . "/webfrontend/html/plugins/" . basename(__DIR__) . "/\n"
        . "  " . dirname(dirname(__DIR__)) . "/webfrontend/html/plugins/" . basename(__DIR__) . "/\n"
        . "  " . dirname(__DIR__) . "/webfrontend/html/\n"
        . "Bitte das Plugin neu installieren.\n");
    exit(1);
}
require_once $dk_gefunden;

/* Ein unbekannter Schalter endet mit einer Antwort, statt still den Takt
 * zu fahren (Regeln/03). */
foreach ($argv as $dk_i => $dk_a) {
    if ($dk_i === 0 || strncmp((string) $dk_a, '--', 2) !== 0) { continue; }
    if (!in_array($dk_a, array('--einmal', '--mqtt-leeren'), true)) {
        fwrite(STDERR, 'Unbekannter Schalter: ' . $dk_a . "\n");
        exit(2);
    }
}

/* --mqtt-leeren (M4, Entscheidung 3): aufgerufen aus uninstall/uninstall,
 * als root. Leert die eigenen zurueckbehaltenen Themen unter dem
 * eingestellten Praefix und alles, was zum Abraeumen vorgemerkt ist.
 * Protokolliert NICHTS - eine Zeile von hier legte die Protokolldatei
 * root-eigen an (Installer-Pruefer J4).
 * SEIT 1.3.19 (E-E8, D11): in einer kurzen TCP-Sitzung mit Ruecklesen (auch Wachliste, Gedaechtnis und die
 * aktuellen Container); die Zeilen tragen ihre Kennung selbst (<OK>, <INFO>, <WARNING>), Rueckgabe immer 0 -
 * die Deinstallation laeuft weiter. */
if (in_array('--mqtt-leeren', $argv, true)) {
    dk_log_aus(true);
    dk_zeitgrenze(10);
    list($dk_lage, $dk_n, $dk_offen) = dk_mqtt_leeren_alles();
    if ($dk_lage === 'geleert') {
        echo '<OK> MQTT: ' . $dk_n . " Themen geleert (Ruecklesen leer).\n";
    } elseif ($dk_lage === 'nichts') {
        echo '<INFO> MQTT: nichts zu leeren (der Broker haelt keines der ' . $dk_n . " Themen zurueck).\n";
    } elseif ($dk_lage === 'rest') {
        echo '<WARNING> MQTT: noch zurueckbehalten - bitte von Hand leeren: ' . implode(', ', $dk_offen)
            . " (mosquitto_pub -r -n -t <thema>)\n";
    } else {
        echo '<WARNING> MQTT: der Broker war nicht erreichbar - ' . $dk_n . ' Themen ueber den UDP-Eingang geleert'
            . ($dk_offen ? ', davon ' . count($dk_offen) . ' nicht abgesetzt' : '')
            . " (ohne Ruecklesen; pruefen mit dem MQTT-Finder).\n";
    }
    exit(0);
}

$dk_laut = in_array('--einmal', $argv, true);

$dk_e = dk_takt();

/* V3 (1.3.19, E-E9): der Waechter der Befehlsbruecke laeuft in diesem Minutentakt (bb_waechter() der Stammfassung
 * 1.0.1; Hausform Ferien cron.php). Er misst das Erzeugnis (zustand.json hoechstens 180 s alt), startet hoechstens
 * alle 600 s, nie waehrend eines Updates (frische Marke), haelt die Bruecke bei Haken aus an und sendet
 * <praefix>/status/befehle (1/0/-1, nie retained). Ausserhalb von dk_takt(), das seine Sperre takt.lock haelt; die
 * Cron-Sperre (fd 9) erbt der Dienst nicht - befehlsbruecke.sh schliesst geerbte Deskriptoren. Nicht im Zweig
 * --mqtt-leeren. Ein Fehler haelt den Takt nicht an. */
if (dk_bruecke_laden()) {
    try {
        bb_waechter();
    } catch (Throwable $dk_bt) {
        dk_log_gebremst('bruecke', 'Waechter der Befehlsbruecke: ' . get_class($dk_bt) . ': ' . $dk_bt->getMessage());
    }
}

/* Rueckgabewert (C7, C9): 0 gelaufen und geschrieben, 3 besetzt (ein
 * anderer Takt laeuft gerade), 1 gescheitert. Bis 1.3.9 endete der Takt
 * immer mit 0, auch wenn zustand.json nicht geschrieben war - und
 * postinstall.sh meldete daraus "<OK> Zustandsdatei angelegt". */
if (!$dk_e['gelaufen']) {
    if ($dk_laut) {
        echo $dk_e['grund'] === 'BESETZT'
            ? "Docker NG - der Minutentakt laeuft gerade (Sperre besetzt); nichts doppelt ausgefuehrt.\n"
            : "Docker NG - der Minutentakt konnte nicht laufen: " . $dk_e['grund'] . "\n";
    }
    exit($dk_e['grund'] === 'BESETZT' ? 3 : 1);
}
if (!$dk_e['geschrieben']) {
    fwrite(STDERR, 'Docker NG: ' . dk_paths()['zustand'] . " liess sich nicht schreiben - "
        . "Herzschlag und Zustand stehen still.\n");
    if ($dk_laut) {
        echo "Docker NG - Minutentakt GESCHEITERT: die Zustandsdatei liess sich nicht schreiben.\n";
        echo "  Zustandsdatei   : " . dk_paths()['zustand'] . "\n";
    }
    exit(1);
}

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
