<?php
/**
 * Docker - Bedienoberflaeche
 *
 * Fuenf Reiter: Portainer | Container | Einbindung in Loxone | Test | Protokoll
 *
 * Alle Variablen tragen das Praefix dk_, weil LBWeb::lbheader() eigene globale
 * Variablen setzt und es sonst zu Namenskollisionen kommt.
 *
 * (c) Docker Plugin Authors - MIT-Lizenz
 */

require_once "loxberry_web.php";
require_once "loxberry_system.php";

$dk_plugin = getenv('LBPPLUGINDIR') ?: 'docker';
$dk_host   = $_SERVER['HTTP_HOST'];
$dk_port   = 9000;   // Portainer

/**
 * Setup-Token aus den Portainer-Protokollen holen.
 * Portainer verlangt ab 2.43 / 2.39.4 beim ersten Einrichten einen Token,
 * der nur im Containerprotokoll steht (Zeile "setup_token=").
 * Ausserdem laeuft das Einrichten nach 5 Minuten ab - danach hilft nur
 * ein Neustart des Containers, der zugleich einen neuen Token erzeugt.
 */
/**
 * Portainer-Protokoll holen und die ANSI-Farbcodes entfernen.
 * Portainer schreibt farbig, dadurch steht zwischen "setup_token=" und dem
 * eigentlichen Wert eine Escape-Sequenz (ESC[0m) - ohne dieses Saeubern
 * findet kein Suchmuster den Token.
 */
function dk_portainer_log($zeilen = 400)
{
    $roh = (string) @shell_exec('docker logs --tail ' . (int) $zeilen . ' portainer 2>&1');
    return preg_replace('/\x1B\[[0-9;]*[A-Za-z]/', '', $roh);
}

function dk_setup_token()
{
    $roh = dk_portainer_log(400);
    // Portainer schreibt den Token als "setup_token=..."; je nach Fassung auch
    // als "setup token: ..." oder in einem JSON-Feld "setupToken".
    foreach (array('/setup_token=([A-Za-z0-9._\-]{6,})/i',
                   '/setup[ _-]?token["\']?\s*[:=]\s*["\']?([A-Za-z0-9._\-]{6,})/i') as $muster) {
        if (preg_match_all($muster, $roh, $tr)) { return end($tr[1]); }
    }
    return '';
}

function dk_portainer_neustart()
{
    @shell_exec('docker restart portainer 2>&1');
    sleep(3);
    return dk_setup_token();
}

$dk_token = ''; $dk_hinweis = ''; $dk_rohlog = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['tokenzeigen'])) {
    $dk_token = dk_setup_token();
    if ($dk_token === '') {
        $dk_rohlog = dk_portainer_log(40);
        $dk_hinweis = 'Im Protokoll wurde kein Token gefunden. Das kann zweierlei hei&szlig;en: '
                    . 'Portainer ist bereits eingerichtet (dann brauchst du keinen, sondern meldest dich an), '
                    . 'oder der Token steht in einer Form da, die hier nicht erkannt wird. '
                    . 'Das Protokoll steht unten &mdash; suche nach <span class="dk-mono">token</span>.';
    }
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['portainerneu'])) {
    $dk_token = dk_portainer_neustart();
    if ($dk_token !== '') {
        $dk_hinweis = 'Portainer wurde neu gestartet. Das Einrichten ist jetzt <b>f&uuml;nf Minuten</b> lang m&ouml;glich.';
    } else {
        $dk_rohlog = dk_portainer_log(40);
        $dk_hinweis = 'Portainer wurde neu gestartet, ein Token wurde aber nicht erkannt. '
                    . 'Das Protokoll steht unten &mdash; suche dort nach <span class="dk-mono">token</span>. '
                    . 'Steht dort keiner, ist Portainer bereits eingerichtet und du meldest dich einfach an.';
    }
}

LBWeb::lbheader('Docker', 'https://www.portainer.io/', 'help.html');

function dk_e($wert) { return htmlspecialchars((string) $wert, ENT_QUOTES, 'UTF-8'); }

/** Ist Docker installiert und laeuft der Dienst? */
function dk_vorhanden()
{
    $pfad = trim((string) @shell_exec('command -v docker 2>/dev/null'));
    return $pfad !== '' ? $pfad : '';
}

/** Liste der Container: array(name, image, status, laeuft) */
function dk_container()
{
    if (dk_vorhanden() === '') { return array(); }
    $roh = (string) @shell_exec("docker ps -a --format '{{.Names}}\t{{.Image}}\t{{.Status}}' 2>/dev/null");
    $liste = array();
    foreach (explode("\n", trim($roh)) as $zeile) {
        if (trim($zeile) === '') { continue; }
        $t = explode("\t", $zeile);
        if (count($t) < 3) { continue; }
        $liste[] = array(
            'name'   => $t[0],
            'image'  => $t[1],
            'status' => $t[2],
            'laeuft' => stripos($t[2], 'Up') === 0,
        );
    }
    return $liste;
}

$dk_docker = dk_vorhanden();
$dk_liste  = dk_container();
$dk_laufen = 0;
foreach ($dk_liste as $dk_c) { if ($dk_c['laeuft']) { $dk_laufen++; } }

// Protokoll
$dk_log = '';
$dk_logdatei = '';
foreach (array('/opt/loxberry/log/plugins/' . $dk_plugin . '/docker.log') as $dk_p) {
    if (@is_file($dk_p)) { $dk_logdatei = $dk_p; break; }
}
if ($dk_logdatei !== '') {
    $dk_z = @file($dk_logdatei);
    if (is_array($dk_z)) { $dk_log = implode('', array_slice($dk_z, -200)); }
} elseif ($dk_docker !== '') {
    $dk_log = (string) @shell_exec('docker ps -a 2>&1 | head -40');
}
?>
<style>
.dkw, .dkw * { text-shadow: none !important; }
.dk-hinweis-ok { background: #e8f5e9; border: 1px solid #a5d6a7; border-radius: 8px; padding: 10px 14px; margin: 12px 0; }
.dk-hinweis-info { background: #e3f2fd; border: 1px solid #90caf9; border-radius: 8px; padding: 10px 14px; margin: 12px 0; font-size: 0.9em; }
.dk-rohlog { background: #1e1e1e; color: #d4d4d4; font-family: ui-monospace, monospace; font-size: 0.78em; padding: 12px; border-radius: 8px; max-height: 320px; overflow: auto; white-space: pre-wrap; }
.dk-token { font-size: 1.05em; font-weight: 700; letter-spacing: 0.03em; user-select: all; }
.dk-btn.dk-b-aktion { background: #e0620d; }
.dk-punkt.dk-b-aktion { background: #e0620d; }
.dkw { max-width: 1100px; }
.dkw h1 { color: #6dac20; font-size: 1.5em; margin: 0 0 4px; }
.dkw h2 { color: #6dac20; margin: 18px 0 6px; font-size: 1.15em; }
.dkw p, .dkw li { line-height: 1.5; }
.dkw .dk-reiter { display: flex; flex-wrap: wrap; gap: 4px; border-bottom: 3px solid #6dac20; margin: 14px 0 0; }
.dkw .dk-reiter div { padding: 9px 16px; background: #eee; border-radius: 8px 8px 0 0; cursor: pointer; font-weight: 600; color: #444; }
.dkw .dk-reiter div.aktiv { background: #6dac20; color: #fff; }
.dkw .dk-seite { display: none; padding: 14px 2px; }
.dkw .dk-seite.aktiv { display: block; }
.dkw .dk-klein { font-size: 0.88em; color: #666; margin: 3px 0 0; max-width: 760px; }
.dkw .dk-mono { font-family: monospace; background: #f4f4f4; padding: 1px 5px; border-radius: 4px; }
.dkw .dk-btn { background: #6dac20; color: #fff !important; border: 0; border-radius: 8px; padding: 9px 18px;
    cursor: pointer; text-decoration: none; font-size: 0.95em; }
.dkw .dk-hinweis { border-left: 5px solid #6dac20; background: #f4faee; padding: 10px 14px; margin: 12px 0; border-radius: 0 8px 8px 0; }
.dkw .dk-warn { border-left-color: #e0620d; background: #fff5ee; }
.dkw .dk-schritt { border: 1px solid #ddd; border-radius: 10px; padding: 12px 14px; margin: 10px 0; }
.dkw table { border-collapse: collapse; width: 100%; max-width: 900px; margin: 8px 0; }
.dkw th, .dkw td { border: 1px solid #ddd; padding: 6px 9px; text-align: left; font-size: 0.93em; }
.dkw th { background: #f2f2f2; }
.dkw pre { background: #f6f6f6; border: 1px solid #ddd; border-radius: 8px; padding: 10px;
    max-height: 460px; overflow: auto; font-size: 0.85em; }
.dkw iframe { width: 100%; height: 78vh; border: 1px solid #ddd; border-radius: 10px; }

/* Hausstandard: Kachel-Raster im Reiter Test */
.dkw .dk-h3 { color: #4f7d17; font-size: 1.0em; font-weight: 700; margin: 16px 0 2px; }
.dkw .dk-knopfreihe { display: flex; flex-wrap: wrap; gap: 10px; margin: 10px 0 4px; align-items: stretch; }
.dkw .dk-knopfreihe form { margin: 0; display: flex; }
.dkw .dk-knopfreihe .dk-btn { flex: 0 0 auto; min-width: 250px; text-align: center;
    display: inline-flex; align-items: center; justify-content: center; line-height: 1.25; }
.dkw .dk-legende { display: flex; flex-wrap: wrap; gap: 14px; margin: 10px 0 2px; font-size: 0.86em; color: #555; }
.dkw .dk-legende span { display: inline-flex; align-items: center; gap: 6px; }
.dkw .dk-punkt { width: 13px; height: 13px; border-radius: 3px; display: inline-block; }
.dkw .dk-btn.dk-b-lesen   { background: #6dac20; }
.dkw .dk-btn.dk-b-technik { background: #546e7a; }
.dkw .dk-btn.dk-b-aktion  { background: #e0620d; }
.dkw .dk-punkt.dk-b-lesen   { background: #6dac20; }
.dkw .dk-punkt.dk-b-technik { background: #546e7a; }
.dkw .dk-punkt.dk-b-aktion  { background: #e0620d; }
.dkw .dk-an { color: #2e7d32; font-weight: 700; }
.dkw .dk-aus { color: #c62828; font-weight: 700; }
</style>

<div class="dkw">
<h1>Docker</h1>
<p>Docker l&auml;sst zus&auml;tzliche Programme in abgeschlossenen &bdquo;Containern&ldquo; auf dem
LoxBerry laufen &mdash; jedes mit allem, was es braucht, ohne dem Rest des Systems in die Quere zu
kommen. <b>Portainer</b> ist die Oberfl&auml;che dazu: Damit lassen sich Container anklicken statt
auf der Kommandozeile eintippen.</p>

<?php if ($dk_docker === '') { ?>
<div class="dk-hinweis dk-warn"><b>Docker wurde nicht gefunden.</b> Normalerweise installiert dieses
Plugin Docker bei der Einrichtung automatisch. Wenn das fehlgeschlagen ist, hilft eine
Neuinstallation des Plugins oder auf der Kommandozeile:
<span class="dk-mono">curl -fsSL https://get.docker.com | sh</span></div>
<?php } ?>

<div class="dk-reiter">
    <div class="aktiv" data-seite="tab-portainer">Portainer</div>
    <div data-seite="tab-container">Container</div>
    <div data-seite="tab-loxone">Einbindung in Loxone</div>
    <div data-seite="tab-test">Test</div>
    <div data-seite="tab-log">Protokoll</div>
</div>

<!-- ===================== Portainer ===================== -->
<div class="dk-seite aktiv" id="tab-portainer">
<h2>Portainer</h2>
<p class="dk-klein">Beim ersten Aufruf legt Portainer ein eigenes Administrator-Konto an. Dieses
Passwort geh&ouml;rt in Ihren Passwortspeicher &mdash; es ist unabh&auml;ngig vom LoxBerry-Konto.</p>
<div class="dk-knopfreihe">
<a class="dk-btn dk-b-lesen" href="http://<?= dk_e(preg_replace('/:.*$/', '', $dk_host)) ?>:<?= (int) $dk_port ?>" target="_blank">Portainer in eigenem Fenster &ouml;ffnen</a>

<?php if ($dk_token !== '') { ?>
<div class="dk-hinweis-ok">
<b>Setup-Token:</b> <span class="dk-mono dk-token"><?= dk_e($dk_token) ?></span><br>
<span class="dk-klein">Beim ersten Einrichten in Portainer eintragen. Das Fenster schlie&szlig;t sich nach f&uuml;nf Minuten.</span>
</div>
<?php } ?>
<?php if ($dk_hinweis !== '') { ?>
<div class="dk-hinweis-info"><?= $dk_hinweis ?></div>
<?php } ?>
<?php if ($dk_rohlog !== '') { ?>
<pre class="dk-rohlog"><?= dk_e($dk_rohlog) ?></pre>
<?php } ?>

<div class="dk-knopfreihe" style="margin-top:12px;">
<form action="index.php" method="post"><input data-role="none" type="hidden" name="tokenzeigen" value="1">
<button data-role="none" class="dk-btn dk-b-technik" type="submit">Setup-Token anzeigen</button></form>
<form action="index.php" method="post"><input data-role="none" type="hidden" name="portainerneu" value="1">
<button data-role="none" class="dk-btn dk-b-aktion" type="submit">Portainer neu starten und Token holen</button></form>
</div>
<p class="dk-klein">Portainer verlangt beim ersten Einrichten einen Token, der nur im Containerprotokoll steht.
Die beiden Kn&ouml;pfe nehmen einem das Nachsehen auf der Kommandozeile ab. Ist das F&uuml;nf-Minuten-Fenster
abgelaufen, holt der rechte Knopf mit dem Neustart gleich einen frischen Token.</p>
</div>
<div class="dk-hinweis-info" style="margin-top:16px;">
<b>Portainer l&auml;sst sich nicht in diese Seite einbetten.</b> Die Oberfl&auml;che schickt
<span class="dk-mono">X-Frame-Options</span> mit und verbietet damit die Anzeige in einem Rahmen &mdash;
ein Schutz davor, dass eine fremde Seite Klicks auf Portainer unterschiebt. Deshalb &ouml;ffnet der
gr&uuml;ne Knopf oben Portainer in einem eigenen Fenster.
</div>
</div>

<!-- ===================== Container ===================== -->
<div class="dk-seite" id="tab-container">
<h2>Container auf diesem LoxBerry</h2>
<?php if ($dk_liste) { ?>
<p>Es sind <b><?= count($dk_liste) ?></b> Container eingerichtet, davon laufen gerade
<b><?= (int) $dk_laufen ?></b>.</p>
<table>
<tr><th>Name</th><th>Abbild</th><th>Zustand</th></tr>
<?php foreach ($dk_liste as $dk_c) { ?>
<tr>
    <td><span class="dk-mono"><?= dk_e($dk_c['name']) ?></span></td>
    <td><?= dk_e($dk_c['image']) ?></td>
    <td class="<?= $dk_c['laeuft'] ? 'dk-an' : 'dk-aus' ?>"><?= dk_e($dk_c['status']) ?></td>
</tr>
<?php } ?>
</table>
<p class="dk-klein">Starten, stoppen und einrichten l&auml;sst sich alles im Reiter
<b>Portainer</b>. Diese Liste ist nur eine &Uuml;bersicht.</p>
<?php } else { ?>
<div class="dk-hinweis">Es ist noch kein Container eingerichtet. Legen Sie im Reiter
<b>Portainer</b> &uuml;ber <i>Add container</i> den ersten an &mdash; zum Beispiel einen
MQTT-Broker, eine Datenbank oder ein Gateway f&uuml;r ein anderes Ger&auml;t.</div>
<?php } ?>
</div>

<!-- ===================== Einbindung in Loxone ===================== -->
<div class="dk-seite" id="tab-loxone">
<h2>Containerzustand nach Loxone melden</h2>
<p>Damit l&auml;sst sich in Loxone anzeigen oder alarmieren, wenn ein Container nicht mehr
l&auml;uft &mdash; etwa das Gateway, das die Auto- oder Wetterdaten liefert.</p>

<div class="dk-schritt"><b>Schritt 1: Virtueller HTTP-Eingang</b><br>
In Loxone Config einen virtuellen HTTP-Eingang mit dieser Adresse anlegen:<br>
<span class="dk-mono">http://<?= dk_e($dk_host) ?>/plugins/<?= dk_e($dk_plugin) ?>/docker.php</span>
<p class="dk-klein">Abfrageintervall 60 Sekunden gen&uuml;gt. Die Antwort ist eine einzelne Zeile:<br>
<span class="dk-mono">DOCKER;OK=1;GESAMT=3;LAEUFT=3;GESTOPPT=0</span></p>
</div>

<div class="dk-schritt"><b>Schritt 2: Befehlserkennungen</b><br>
<table>
<tr><th>Bezeichnung</th><th>Befehlserkennung</th><th>Bedeutung</th></tr>
<tr><td>Docker erreichbar</td><td><span class="dk-mono">OK=\v</span></td><td>1 = Docker antwortet</td></tr>
<tr><td>Container gesamt</td><td><span class="dk-mono">GESAMT=\v</span></td><td>eingerichtete Container</td></tr>
<tr><td>Container laufen</td><td><span class="dk-mono">LAEUFT=\v</span></td><td>davon aktiv</td></tr>
<tr><td>Container gestoppt</td><td><span class="dk-mono">GESTOPPT=\v</span></td><td>Alarm, wenn &gt; 0</td></tr>
</table>
</div>

<div class="dk-schritt"><b>Schritt 3: Einzelnen Container abfragen</b><br>
<span class="dk-mono">http://<?= dk_e($dk_host) ?>/plugins/<?= dk_e($dk_plugin) ?>/docker.php?name=mein-container</span>
<p class="dk-klein">Liefert <span class="dk-mono">DOCKER;OK=1;NAME=mein-container;LAEUFT=1</span> &mdash;
so l&auml;sst sich gezielt ein einzelner Container &uuml;berwachen.</p>
</div>

<div class="dk-schritt"><b>Schritt 4: Alles als JSON</b><br>
<span class="dk-mono">http://<?= dk_e($dk_host) ?>/plugins/<?= dk_e($dk_plugin) ?>/docker.php?json=1</span>
</div>
</div>

<!-- ===================== Test ===================== -->
<div class="dk-seite" id="tab-test">
<h2>Test</h2>
<div class="dk-legende">
<span><i class="dk-punkt dk-b-lesen"></i> Ansehen &mdash; fragt nur ab, ver&auml;ndert nichts</span>
<span><i class="dk-punkt dk-b-technik"></i> Technische Auskunft &mdash; f&uuml;r die Fehlersuche</span>
<span><i class="dk-punkt dk-b-aktion"></i> L&ouml;st etwas aus &mdash; sendet oder ver&auml;ndert etwas</span>
</div>

<h3 class="dk-h3">Ansehen</h3>
<div class="dk-knopfreihe">
<a class="dk-btn dk-b-lesen" href="/plugins/<?= dk_e($dk_plugin) ?>/docker.php" target="_blank">Loxone-Zeile abrufen</a>
<a class="dk-btn dk-b-lesen" href="/plugins/<?= dk_e($dk_plugin) ?>/docker.php?json=1" target="_blank">JSON-Ansicht</a>
</div>

<h3 class="dk-h3">Technische Auskunft</h3>
<div class="dk-knopfreihe">
<a class="dk-btn dk-b-technik" href="/plugins/<?= dk_e($dk_plugin) ?>/docker.php?debug=1" target="_blank">Debug (Version und Containerliste)</a>
</div>

<h3 class="dk-h3">L&ouml;st etwas aus</h3>
<div class="dk-knopfreihe">
<form action="index.php" method="post"><input data-role="none" type="hidden" name="portainerneu" value="1">
<button data-role="none" class="dk-btn dk-b-aktion" type="submit">Portainer neu starten und Token holen</button></form>
</div>

<h2>Zustand</h2>
<table>
<tr><th>Wert</th><th>Inhalt</th></tr>
<tr><td>Docker installiert</td><td><?= $dk_docker !== '' ? 'ja (' . dk_e($dk_docker) . ')' : '<b>nein</b>' ?></td></tr>
<tr><td>Container eingerichtet</td><td><?= count($dk_liste) ?></td></tr>
<tr><td>Container laufen</td><td><?= (int) $dk_laufen ?></td></tr>
<tr><td>Portainer</td><td><span class="dk-mono">Port <?= (int) $dk_port ?></span></td></tr>
</table>
</div>

<!-- ===================== Protokoll ===================== -->
<div class="dk-seite" id="tab-log">
<h2>Protokoll</h2>
<?php if (trim($dk_log) !== '') { ?>
<pre><?= dk_e($dk_log) ?></pre>
<?php } else { ?>
<div class="dk-hinweis">Es liegt noch kein Protokoll vor.</div>
<?php } ?>
</div>

</div>

<script>
(function () {
    var reiter = document.querySelectorAll('.dkw .dk-reiter div');
    for (var i = 0; i < reiter.length; i++) {
        reiter[i].addEventListener('click', function () {
            var ziel = this.getAttribute('data-seite');
            var alle = document.querySelectorAll('.dkw .dk-reiter div');
            for (var j = 0; j < alle.length; j++) { alle[j].classList.remove('aktiv'); }
            this.classList.add('aktiv');
            var seiten = document.querySelectorAll('.dkw .dk-seite');
            for (var k = 0; k < seiten.length; k++) { seiten[k].classList.remove('aktiv'); }
            var s = document.getElementById(ziel);
            if (s) { s.classList.add('aktiv'); }
        });
    }
})();
</script>

<?php
LBWeb::lbfooter();
?>
