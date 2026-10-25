<?php
/**
 * bin/befehle_zuordnung.php - Zuordnungstabelle der Befehlsbruecke, Linie Docker NG (linieneigen).
 *
 * Gruppe 21 (1.3.19; Entscheidung 3 V3, Entwurf entwurf_gruppe21_dk.txt 3.1, E-E1/E-E5/E-E16; Bauart Ferien 1.2.28
 * bin/befehle_zuordnung.php, zweiter Haken wie Pumpenwacht 1.0.15 / Robonect 1.1.24). Die Bruecke selbst
 * (bin/befehlsbruecke.php, .sh) ist die gemeinsame Stammfassung 1.0.1 und wird hier nicht veraendert.
 *
 * EIN Befehl: "neustart" (Art text, bis 64 Byte, Wert = Containername genau so; E-E1). Ein Thema je Container ginge
 * nicht: Befehlsebenen erlauben nur [a-z0-9_] (Punkt, Bindestrich, Grossbuchstaben fielen heraus), und 'auswahl'
 * legte zwei Container im selben Lesepuffer zusammen. 'text' sperrt je Name UND Wert, also je Container.
 * Eingreifend -> zweiter Haken befehle_mqtt_steuern ("Container-Neustart aus Loxone erlauben", ab Werk aus).
 *
 * 'tun' ruft DIESELBE Funktion wie der Knopf "Neu starten" der Containertabelle: dk_befehl_neustart($name, 'mqtt').
 * Sie prueft noch einmal selbst (Haken, Neustartliste, ausdrueckliche Wachliste, eigener Portainer, Broker-Schutz,
 * Sperre 600 s je Container) und startet synchron neu (docker restart --time 10, Frist 40 s): die Quittung sagt, was
 * passiert ist. einstellungen() liest die Konfiguration bei jedem Aufruf frisch (dk_config_neu(), N1).
 *
 * Die Datei setzt keine Konfiguration und schreibt nichts.
 */
if (!function_exists('dk_config')) {
    /* Ladeblock wie Ferien 1.2.28: ausdruecklich LBHOMEDIR + LBPPLUGINDIR (Pruefstaende) zuerst, dann installiert
     * (bin/plugins/<ordner> -> webfrontend/html/plugins/<ordner>) ODER Archiv (bin/ neben webfrontend/). */
    $dk_kand = array();
    $dk_lb = (string) getenv('LBHOMEDIR');
    $dk_ordner = basename(rtrim((string) getenv('LBPPLUGINDIR'), '/'));
    if ($dk_lb !== '' && $dk_ordner !== ''
        && !in_array($dk_ordner, array('.', '..', 'html', 'htmlauth', 'bin', 'plugins', 'webfrontend'), true)) {
        $dk_kand[] = $dk_lb . '/webfrontend/html/plugins/' . $dk_ordner . '/dk_lib.php';
    }
    if (basename(dirname(__DIR__)) === 'plugins') {
        $dk_kand[] = dirname(dirname(dirname(__DIR__))) . '/webfrontend/html/plugins/' . basename(__DIR__) . '/dk_lib.php';
    } else {
        $dk_kand[] = dirname(__DIR__) . '/webfrontend/html/dk_lib.php';
    }
    foreach ($dk_kand as $dk_k) {
        if (is_file($dk_k)) { require_once $dk_k; break; }
    }
}
// array('ok' => bool, 'grund' => string) der Linie -> array(ok, grund) der Bruecke
$dk_um = function ($r) {
    return array(!empty($r['ok']), isset($r['grund']) ? (string) $r['grund'] : '');
};
return array(
    'fassung' => 1,
    'linie'   => 'dockerng',
    'plugin'  => 'Docker NG',
    'geladen' => function_exists('dk_config') && function_exists('dk_config_neu') && function_exists('dk_befehl_neustart'),
    'zweiter_haken' => 'befehle_mqtt_steuern',
    'einstellungen' => function () {
        $c = dk_config_neu();
        return array('praefix'     => $c['mqtt_praefix'],
                     'annehmen'    => !empty($c['befehle_mqtt_ein']) && !empty($c['mqtt_aktiv']),
                     'eingreifend' => !empty($c['befehle_mqtt_steuern']),
                     'mqtt_ein'    => !empty($c['mqtt_aktiv']));
    },
    'protokoll' => function ($stufe, $text) { dk_log(($stufe === 'INFO' ? '' : $stufe . ': ') . $text); },
    'befehle' => array(
        'neustart' => array('art' => 'text', 'max_byte' => 64, 'eingreifend' => true,
                            'titel' => 'Docker NG Container neu starten (MQTT)',
                            'kachel' => 'BEFEHL.K_NEUSTART', 'quittung' => 'ausgefuehrt',
                            'tun' => function ($w, $t) use ($dk_um) { return $dk_um(dk_befehl_neustart($w, 'mqtt')); }),
    ),
);
