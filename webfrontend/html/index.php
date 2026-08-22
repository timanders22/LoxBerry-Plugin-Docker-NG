<?php
/**
 * Docker NG - Endpunkt fuer den Miniserver
 *
 * Liegt im unangemeldeten Bereich, damit Loxone ihn ohne Zugangsdaten erreicht,
 * und ist durch ein Merkwort geschuetzt:
 *
 *   /plugins/<Ordner>/index.php?token=<TOKEN>&aktion=<Befehl>
 *
 * Verglichen wird mit hash_equals, also in gleichbleibender Zeit - ein
 * einfaches == liesse sich ueber die Antwortzeit Zeichen fuer Zeichen erraten.
 *
 * Der Endpunkt ist REIN LESEND. Er startet und stoppt nichts. Ein Endpunkt im
 * unangemeldeten Bereich, der Container anhalten kann, waere eine
 * Angriffsflaeche ohne Gegenwert - geschaltet wird in Portainer.
 */

error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
require_once __DIR__ . '/dk_lib.php';

header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store');

$dk_cfg  = dk_config();
$dk_soll = (string) $dk_cfg['aktionstoken'];

/* Der Parameter wird ZUERST auf seinen Typ geprueft.
 *
 * '?token[]=x' macht aus $_GET['token'] ein Feld. Eine Umwandlung nach string
 * erzeugt daraus unter PHP 8 eine Warnung mitten in der Antwort an den
 * Miniserver, und wer statt (string) ein trim() schreibt, bekommt einen
 * TypeError - also HTTP 500 mit leerem Rumpf.
 */
$dk_ist = (isset($_GET['token']) && is_string($_GET['token'])) ? $_GET['token'] : '';

/* ---------------- Selbstpruefung ----------------
 *
 * Vom Hausstandard an jedem tokengeschuetzten Endpunkt verlangt, und aus einem
 * guten Grund: sie beantwortet die Frage "ist die Adresse samt Merkwort
 * richtig?", OHNE dabei irgendetwas anzufassen. Kein Geraetekontakt, kein
 * Schreibzugriff, kein wirksamer Protokolleintrag - deshalb steht sie VOR
 * jedem docker-Aufruf.
 *
 * Genau das braucht die Endpunktprobe im Reiter Test: sie darf messen, ob der
 * Weg traegt, ohne alle 300 Sekunden zusaetzlich drei docker-Prozesse zu
 * starten.
 */
$dk_selftest = isset($_GET['selftest']) && (string) $_GET['selftest'] === '1';

if ($dk_soll === '') {
    // Faellt geschlossen aus: ohne gesetztes Merkwort wird abgewiesen, nicht
    // durchgelassen. Sonst waere der Endpunkt bis zum ersten Oeffnen der
    // Oberflaeche fuer jeden offen.
    http_response_code(403);
    if ($dk_selftest) {
        echo "SELFTEST;OK=0;ERR=KEIN_TOKEN_EINGERICHTET\n";
    } else {
        echo "FEHLER;OK=0;GRUND=KEIN_TOKEN_GESETZT\n";
        echo "Die Plugin-Oberflaeche wurde noch nie geoeffnet - es gibt noch kein Merkwort.\n";
    }
    exit;
}
if (!hash_equals($dk_soll, $dk_ist)) {
    http_response_code(403);
    echo $dk_selftest ? "SELFTEST;OK=0;ERR=TOKEN\n" : "FEHLER;OK=0;GRUND=TOKEN\n";
    exit;
}
if ($dk_selftest) {
    echo "SELFTEST;OK=1;TOKEN=OK\n";
    exit;
}

/* Positivliste: alles andere wird abgewiesen, nicht zurechtgebogen. */
$dk_erlaubt = array('status', 'container', 'liste', 'roh');
$dk_aktion  = isset($_GET['aktion']) ? (string) $_GET['aktion'] : 'status';
if (!in_array($dk_aktion, $dk_erlaubt, true)) {
    http_response_code(400);
    echo "FEHLER;OK=0;GRUND=UNBEKANNTE_AKTION\n";
    echo 'Erlaubt: ' . implode(', ', $dk_erlaubt) . "\n";
    exit;
}

/* OK=1 heisst: Docker ist da UND ansprechbar.
 *
 * Bis 1.0.0 stand hier nur 'gibt es das Programm docker'. Fehlten dem
 * Webserver die Gruppenrechte auf den Docker-Socket - nach einer frischen
 * Installation der Regelfall -, meldete das Plugin trotzdem OK=1 und dazu
 * GESAMT=0. Loxone bekam damit die Aussage 'alles in Ordnung, es laeuft
 * nichts', und die ist falsch: es laeuft womoeglich alles, nur darf niemand
 * nachsehen. Ein Baustein, der auf GESAMT=0 eine Meldung ausloest, haette
 * geschwiegen.
 */
list($dk_ok, $dk_grund, $dk_grundtext) = dk_zustand();
$dk_da = $dk_ok ? 1 : 0;
$dk_z  = dk_zaehlung();

/* Der Zustand ueber die Zeit kommt aus dem Minutentakt - Herzschlag,
 * Neustartschleifen, Plattenbelegung. Der Endpunkt LIEST ihn nur: er wird vom
 * Miniserver im Sechzigsekundentakt abgerufen, und jede Schreibung waere ein
 * Schreibvorgang auf der Speicherkarte.
 *
 * Laeuft der Takt nicht (Cron nicht eingerichtet, Plugin gerade erst
 * installiert), steht ZAEHLER auf -1. Das ist eine Aussage, kein Platzhalter:
 * ein Zaehler, der stillsteht, sieht in Loxone genauso aus wie ein
 * ausgefallener LoxBerry - und genau dafuer ist er da.
 */
$dk_zd     = dk_zustandsdatei();
$dk_alter  = dk_zustand_alter();
$dk_zaehler = ($dk_alter >= 0 && $dk_alter < 300 && isset($dk_zd['zaehler']))
              ? (int) $dk_zd['zaehler'] : -1;
$dk_platzfrei = isset($dk_zd['platz']['frei_mb']) ? (int) $dk_zd['platz']['frei_mb'] : -1;

/* ---------------- roh ---------------- */
if ($dk_aktion === 'roh') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array(
        'ok'          => $dk_da,
        'grund'       => $dk_grund,
        'meldung'     => $dk_grundtext,
        'version'     => dk_version(),
        'gesamt'      => $dk_z['gesamt'],
        'laeuft'      => $dk_z['laeuft'],
        'gestoppt'    => $dk_z['gestoppt'],
        'ausfall'     => $dk_z['ausfall'],
        'pausiert'    => $dk_z['pausiert'],
        'ungesund'    => $dk_z['ungesund'],
        'fehlt'       => $dk_z['fehlt'],
        'schleife'    => $dk_z['schleife'],
        'portainer'   => dk_portainer_laeuft() ? 1 : 0,
        'zaehler'     => $dk_zaehler,
        'takt_alter'  => dk_zustand_alter(),
        'platz'       => isset($dk_zd['platz']) ? $dk_zd['platz'] : array(),
        'updates'     => isset($dk_zd['updates']) ? $dk_zd['updates'] : array(),
        'wache'       => $dk_z['wache'],
        'kollisionen' => dk_schluesselkollisionen($dk_z['liste']),
        'container'   => $dk_z['liste'],
    ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

/* ---------------- liste ---------------- */
if ($dk_aktion === 'liste') {
    foreach ($dk_z['liste'] as $c) {
        printf("%s;LAEUFT=%d;AUSFALL=%d;STAND=%s;GESUND=%d;AUTOSTART=%d;NEUSTARTS=%d;ABBILD=%s;ZUSTAND=%s\n",
               $c['name'], $c['laeuft'], $c['ausfall'], $c['zustand'],
               $c['gesund'], $c['autostart'], $c['neustarts'],
               $c['image'], $c['status']);
    }
    // Ueberwachte Container, die es nicht mehr gibt, gehoeren in dieselbe
    // Liste - sonst faellt genau das nicht auf, worum es geht.
    foreach ($dk_z['wache'] as $dk_n => $dk_w) {
        if ($dk_w === -1) {
            printf("%s;LAEUFT=-1;AUSFALL=1;STAND=fehlt;GESUND=-1;AUTOSTART=-1;NEUSTARTS=-1;ABBILD=-;ZUSTAND=-\n", $dk_n);
        }
    }
    if (!$dk_z['liste'] && !$dk_z['wache']) { echo "LEER;OK=" . $dk_da . "\n"; }
    exit;
}

/* ---------------- container: ein einzelner ----------------
 *
 * Enges Muster fuer den Parameter. Was nicht passt, wird abgewiesen und
 * benannt - nicht stillschweigend um die verbotenen Zeichen erleichtert.
 * Ein still zurechtgebogener Name faende den Container nicht und meldete
 * "laeuft nicht": eine stille Falschaussage.
 */
if ($dk_aktion === 'container') {
    $name = isset($_GET['name']) ? (string) $_GET['name'] : '';
    if (!preg_match('/^[A-Za-z0-9_.\-]{1,64}$/', $name)) {
        http_response_code(400);
        echo "FEHLER;OK=0;GRUND=NAME_UNGUELTIG\n";
        echo "Erlaubt sind Buchstaben, Ziffern, Punkt, Bindestrich und Unterstrich.\n";
        exit;
    }
    $gefunden = 0; $laeuft = 0; $ausfall = 0; $stand = '-'; $zustand = '';
    $gesund = -1; $autostart = -1; $neustarts = -1;
    foreach ($dk_z['liste'] as $c) {
        if ($c['name'] === $name) {
            $gefunden = 1; $laeuft = $c['laeuft']; $ausfall = $c['ausfall'];
            $stand = $c['zustand']; $zustand = $c['status'];
            $gesund = $c['gesund']; $autostart = $c['autostart'];
            $neustarts = $c['neustarts'];
            break;
        }
    }
    // Steht der Name auf der Wachliste und ist trotzdem nicht da, ist das eine
    // eigene Aussage - nicht dasselbe wie "nach einem unbekannten Namen
    // gefragt". LAEUFT=-1 unterscheidet die beiden Faelle.
    if (!$gefunden && isset($dk_z['wache'][$name])) { $laeuft = -1; $ausfall = 1; $stand = 'fehlt'; }
    printf("DOCKERNG;OK=%d;NAME=%s;GEFUNDEN=%d;LAEUFT=%d;AUSFALL=%d;STAND=%s;GESUND=%d;AUTOSTART=%d;NEUSTARTS=%d;ZUSTAND=%s\n",
           $dk_da, $name, $gefunden, $laeuft, $ausfall, $stand,
           $gesund, $autostart, $neustarts,
           $zustand !== '' ? $zustand : '-');
    exit;
}

/* ---------------- status ----------------
 *
 * Je Container zusaetzlich eine Stelle C_<name>=0/1, damit sich ein einzelner
 * Container ohne zweiten Abruf ueberwachen laesst. Der Name wird dabei auf
 * A-Z, 0-9 und Unterstrich gebracht, weil die Befehlserkennung in Loxone
 * sonst nicht darauf passt.
 *
 * ERGAENZT in 1.2.4: AUSFALL und PAUSIERT.
 *
 * GESTOPPT zaehlt ueber 'docker ps -a' jeden je erzeugten und nicht
 * entfernten Container mit - auch den Sicherungscontainer, der nachts laeuft
 * und sauber mit Code 0 endet. Auf einem gewachsenen LoxBerry ist der Wert
 * praktisch nie 0. Wer die Bauanleitung aus Schritt 4 woertlich nachbaute
 * (GESTOPPT -> Schwellwertschalter "Ein ab 1" -> ODER -> Benachrichtigung),
 * bekam deshalb eine Dauerstoerung - und weil der Benachrichtigungs-Baustein
 * nur beim Wechsel von Aus auf Ein sendet, verschluckte sie anschliessend
 * ALLE anderen Meldungen an demselben ODER. Die Anleitung warnt vor genau
 * diesem Mechanismus und lief mit ihrem eigenen ersten Baustein hinein.
 *
 * GESTOPPT bleibt unveraendert, damit bestehende Loxone-Programme weiter
 * tragen. Die Anleitung nennt ab 1.2.4 aber AUSFALL.
 */
$dk_zeile = sprintf('DOCKERNG;OK=%d;GESAMT=%d;LAEUFT=%d;GESTOPPT=%d;AUSFALL=%d;PAUSIERT=%d'
                  . ';UNGESUND=%d;FEHLT=%d;SCHLEIFE=%d;PORTAINER=%d;ZAEHLER=%d;PLATZFREI=%d;GRUND=%s',
                    $dk_da, $dk_z['gesamt'], $dk_z['laeuft'], $dk_z['gestoppt'],
                    $dk_z['ausfall'], $dk_z['pausiert'],
                    $dk_z['ungesund'], $dk_z['fehlt'], $dk_z['schleife'],
                    dk_portainer_laeuft() ? 1 : 0,
                    $dk_zaehler, $dk_platzfrei,
                    $dk_grund !== '' ? $dk_grund : '-');
/* Iteriert wird ueber die WACHLISTE, nicht ueber den Fundbestand.
 *
 * Bis 1.2.4 entstand die Stelle C_<name> je gefundenem Container. Wurde einer
 * geloescht, verschwand seine Stelle ersatzlos - der virtuelle Eingang fand
 * sein Muster nicht mehr und behielt seinen LETZTEN Wert, also 1. Loxone
 * meldete auf Dauer "laeuft" fuer einen Container, den es nicht mehr gibt.
 *
 * Jetzt: -1 = nicht mehr vorhanden, 0 = da und laeuft nicht, 1 = laeuft.
 * Deshalb steht MinVal der Container-Eintraege in der Importdatei auf -1.
 */
foreach ($dk_z['wache'] as $dk_name => $dk_wert) {
    $dk_zeile .= ';C_' . preg_replace('/[^A-Za-z0-9_]/', '_', $dk_name) . '=' . (int) $dk_wert;
}
/* Zusaetzlich je ueberwachtem Container die Gesundheit: 0 = kein Healthcheck,
 * 1 = startet, 2 = gesund, 3 = ungesund, -1 = Container fehlt. Ein Container,
 * der laeuft, aber seinen Healthcheck nicht besteht, war bis 1.2.4 von einem
 * gesunden nicht zu unterscheiden. */
$dk_nach = array();
foreach ($dk_z['liste'] as $c) { $dk_nach[$c['name']] = $c; }
foreach ($dk_z['wache'] as $dk_name => $dk_wert) {
    $dk_g = isset($dk_nach[$dk_name]) ? $dk_nach[$dk_name]['gesund'] : -1;
    $dk_zeile .= ';H_' . preg_replace('/[^A-Za-z0-9_]/', '_', $dk_name) . '=' . (int) $dk_g;
}
echo $dk_zeile . "\n";
