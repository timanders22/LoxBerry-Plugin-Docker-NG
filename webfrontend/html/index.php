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
$dk_ist  = isset($_GET['token']) ? (string) $_GET['token'] : '';

// Faellt geschlossen aus: ohne gesetztes Merkwort wird abgewiesen, nicht
// durchgelassen. Sonst waere der Endpunkt bis zum ersten Oeffnen der
// Oberflaeche fuer jeden offen.
if ($dk_soll === '') {
    http_response_code(403);
    echo "FEHLER;OK=0;GRUND=KEIN_TOKEN_GESETZT\n";
    echo "Die Plugin-Oberflaeche wurde noch nie geoeffnet - es gibt noch kein Merkwort.\n";
    exit;
}
if (!hash_equals($dk_soll, $dk_ist)) {
    http_response_code(403);
    echo "FEHLER;OK=0;GRUND=TOKEN\n";
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

$dk_da = dk_bin() !== '' ? 1 : 0;
$dk_z  = dk_zaehlung();

/* ---------------- roh ---------------- */
if ($dk_aktion === 'roh') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array(
        'ok'          => $dk_da,
        'version'     => dk_version(),
        'gesamt'      => $dk_z['gesamt'],
        'laeuft'      => $dk_z['laeuft'],
        'gestoppt'    => $dk_z['gestoppt'],
        'portainer'   => dk_portainer_laeuft() ? 1 : 0,
        'container'   => $dk_z['liste'],
    ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

/* ---------------- liste ---------------- */
if ($dk_aktion === 'liste') {
    foreach ($dk_z['liste'] as $c) {
        printf("%s;LAEUFT=%d;ABBILD=%s;ZUSTAND=%s\n",
               $c['name'], $c['laeuft'], $c['image'], $c['status']);
    }
    if (!$dk_z['liste']) { echo "LEER;OK=" . $dk_da . "\n"; }
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
    $gefunden = 0; $laeuft = 0; $zustand = '';
    foreach ($dk_z['liste'] as $c) {
        if ($c['name'] === $name) {
            $gefunden = 1; $laeuft = $c['laeuft']; $zustand = $c['status']; break;
        }
    }
    printf("DOCKERNG;OK=%d;NAME=%s;GEFUNDEN=%d;LAEUFT=%d;ZUSTAND=%s\n",
           $dk_da, $name, $gefunden, $laeuft, $zustand !== '' ? $zustand : '-');
    exit;
}

/* ---------------- status ----------------
 *
 * Je Container zusaetzlich eine Stelle C_<name>=0/1, damit sich ein einzelner
 * Container ohne zweiten Abruf ueberwachen laesst. Der Name wird dabei auf
 * A-Z, 0-9 und Unterstrich gebracht, weil die Befehlserkennung in Loxone
 * sonst nicht darauf passt.
 */
$dk_zeile = sprintf('DOCKERNG;OK=%d;GESAMT=%d;LAEUFT=%d;GESTOPPT=%d;PORTAINER=%d',
                    $dk_da, $dk_z['gesamt'], $dk_z['laeuft'], $dk_z['gestoppt'],
                    dk_portainer_laeuft() ? 1 : 0);
foreach ($dk_z['liste'] as $c) {
    $dk_zeile .= ';C_' . preg_replace('/[^A-Za-z0-9_]/', '_', $c['name']) . '=' . $c['laeuft'];
}
echo $dk_zeile . "\n";
