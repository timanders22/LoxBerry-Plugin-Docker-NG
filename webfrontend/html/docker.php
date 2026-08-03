<?php
/**
 * Endpunkt fuer Loxone: Zustand der Docker-Container als einzelne Zeile.
 *
 *   docker.php                  -> DOCKER;OK=1;GESAMT=3;LAEUFT=3;GESTOPPT=0
 *   docker.php?name=xyz         -> DOCKER;OK=1;NAME=xyz;LAEUFT=1
 *   docker.php?json=1           -> vollstaendiger Zustand als JSON
 *   docker.php?debug=1          -> Klartext-Uebersicht zur Fehlersuche
 *
 * (c) Docker Plugin Authors - MIT-Lizenz
 */

header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store');

function dk_bin()
{
    $p = trim((string) @shell_exec('command -v docker 2>/dev/null'));
    return $p !== '' ? $p : '';
}

function dk_liste()
{
    if (dk_bin() === '') { return array(); }
    $roh = (string) @shell_exec("docker ps -a --format '{{.Names}}\t{{.Image}}\t{{.Status}}' 2>/dev/null");
    $aus = array();
    foreach (explode("\n", trim($roh)) as $z) {
        if (trim($z) === '') { continue; }
        $t = explode("\t", $z);
        if (count($t) < 3) { continue; }
        $aus[] = array('name' => $t[0], 'image' => $t[1], 'status' => $t[2],
                       'laeuft' => stripos($t[2], 'Up') === 0 ? 1 : 0);
    }
    return $aus;
}

$dk_ok    = dk_bin() !== '' ? 1 : 0;
$dk_alle  = dk_liste();
$dk_lauf  = 0;
foreach ($dk_alle as $c) { $dk_lauf += $c['laeuft']; }
$dk_stopp = count($dk_alle) - $dk_lauf;

// Einzelner Container
if (isset($_GET['name'])) {
    $gesucht = preg_replace('/[^A-Za-z0-9_.\-]/', '', (string) $_GET['name']);
    $laeuft = 0;
    $gefunden = 0;
    foreach ($dk_alle as $c) {
        if ($c['name'] === $gesucht) { $gefunden = 1; $laeuft = $c['laeuft']; break; }
    }
    echo 'DOCKER;OK=' . $dk_ok . ';NAME=' . $gesucht . ';GEFUNDEN=' . $gefunden . ';LAEUFT=' . $laeuft . "\n";
    exit;
}

if (isset($_GET['json'])) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array(
        'ok' => $dk_ok, 'gesamt' => count($dk_alle), 'laeuft' => $dk_lauf,
        'gestoppt' => $dk_stopp, 'container' => $dk_alle,
    ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

if (isset($_GET['debug'])) {
    echo "DOCKER - Uebersicht\n" . str_repeat('-', 60) . "\n";
    echo 'docker: ' . (dk_bin() ?: 'nicht gefunden') . "\n";
    echo trim((string) @shell_exec('docker --version 2>&1')) . "\n";
    echo str_repeat('-', 60) . "\n";
    foreach ($dk_alle as $c) {
        printf("%-24s %-30s %s\n", $c['name'], $c['image'], $c['status']);
    }
    if (!$dk_alle) { echo "Kein Container eingerichtet.\n"; }
    exit;
}

echo 'DOCKER;OK=' . $dk_ok . ';GESAMT=' . count($dk_alle)
   . ';LAEUFT=' . $dk_lauf . ';GESTOPPT=' . $dk_stopp . "\n";
