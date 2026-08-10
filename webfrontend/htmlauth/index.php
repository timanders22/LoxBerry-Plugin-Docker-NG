<?php
/**
 * Docker NG - Bedienoberflaeche
 *
 * Vier Reiter: Einstellungen | Einbindung in Loxone | Test | Logdateien
 *
 * Kein MQTT-Reiter: Docker NG fuehrt keinen Dienst, der zyklisch
 * veroeffentlichen koennte - Loxone holt den Zustand ueber den HTTP-Endpunkt.
 * Ein MQTT-Weg waere nachruestbar, ist aber ungebaut und wird deshalb auch
 * nicht behauptet.
 *
 * Alle Bezeichner tragen das Kuerzel dk_, weil LBWeb::lbheader() eigene globale
 * Variablen setzt und es sonst zu Namenskollisionen kommt.
 */

error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);

/* Die Bibliothek liegt unter webfrontend/html/, weil der Loxone-Endpunkt sie
 * ebenfalls braucht. Der Pfad dorthin ist NICHT in beiden Zustaenden derselbe:
 *
 *   im entpackten Archiv   …/webfrontend/htmlauth/   und  …/webfrontend/html/
 *                          liegen nebeneinander      ->  __DIR__/../html/
 *
 *   installiert            <home>/webfrontend/htmlauth/plugins/<ordner>/  und
 *                          <home>/webfrontend/html/plugins/<ordner>/
 *                          jeweils unter EINER EIGENEN plugins-Ebene
 *                          ->  __DIR__/../html/ zeigt auf
 *                              htmlauth/plugins/html/, das es nicht gibt
 *
 * Bis 1.1.0 stand hier nur der Archivfall. Installiert endete die Oberflaeche
 * damit in einem Fatal Error und der Browser bekam HTTP 500 - gemeldet von
 * einem Mitleser, am Quelltext nachgeprueft, zutreffend.
 *
 * Deshalb eine Kandidatenliste statt eines festen Pfades: sie traegt in beiden
 * Zustaenden und ueberlebt auch eine Umbenennung des Pluginordners.
 */
$dk_gefunden = false;
foreach (array(
    dirname(dirname(__DIR__)) . '/html/plugins/' . basename(__DIR__) . '/dk_lib.php',
    dirname(dirname(dirname(__DIR__))) . '/html/plugins/' . basename(__DIR__) . '/dk_lib.php',
    dirname(__DIR__) . '/html/dk_lib.php',
) as $dk_kandidat) {
    if (is_file($dk_kandidat)) {
        require_once $dk_kandidat;
        $dk_gefunden = true;
        break;
    }
}
if (!$dk_gefunden) {
    echo '<p><b>Fehler:</b> dk_lib.php wurde nicht gefunden. '
       . 'Bitte das Plugin neu installieren.</p>';
    exit;
}

$dk_p = dk_paths();
if (file_exists($dk_p['home'] . '/libs/phplib/loxberry_system.php')) {
    require_once $dk_p['home'] . '/libs/phplib/loxberry_system.php';
    require_once $dk_p['home'] . '/libs/phplib/loxberry_web.php';
}

$dk_cfg     = dk_config();
$dk_token   = dk_token();          // erzeugt sich beim ersten Aufruf selbst
$dk_meldung = '';
$dk_fehler  = array();             // gesammelt, nicht ueberschrieben
$dk_setup   = '';
$dk_rohlog  = '';

$dk_tab = preg_match('/^tab-(settings|loxone|test|log)$/', (string) ($_POST['activetab'] ?? ''))
    ? $_POST['activetab']
    : (preg_match('/^(settings|loxone|test|log)$/', (string) ($_GET['form'] ?? ''))
        ? 'tab-' . $_GET['form'] : 'tab-settings');

/* ---------------- Loxone-Vorlage herunterladen ----------------
 * Eigenes Formular, damit der Download nicht am Speichern haengt.
 */
if (($_POST['download'] ?? '') === 'xml_in') {
    $dk_xml = dk_xml_virtual_in_http((string) ($_SERVER['HTTP_HOST'] ?? 'loxberry'), $dk_token);
    header('Content-Type: application/x-download');
    header('Content-Disposition: attachment; filename="VI_DOCKER_NG.xml"');
    echo $dk_xml;
    exit;
}

/* ---------------- Speichern ---------------- */
if (($_POST['speichern'] ?? '') === '1') {
    $dk_neu = $dk_cfg;

    $dk_port = trim((string) ($_POST['portainer_port'] ?? ''));
    if (!preg_match('/^[0-9]{1,5}$/', $dk_port) || (int) $dk_port < 1 || (int) $dk_port > 65535) {
        $dk_fehler[] = dk_t('FEHLER.PORT');
    } else {
        $dk_neu['portainer_port'] = (int) $dk_port;
    }

    $dk_name = trim((string) ($_POST['portainer_name'] ?? ''));
    if (!preg_match('/^[A-Za-z0-9_.\-]{1,64}$/', $dk_name)) {
        $dk_fehler[] = dk_t('FEHLER.NAME');
    } else {
        $dk_neu['portainer_name'] = $dk_name;
    }

    $dk_tokengewuerfelt = false;
    if (isset($_POST['token_neu'])) {
        $dk_neu['aktionstoken'] = dk_token_neu();
        $dk_tokengewuerfelt = true;
    }

    if (!$dk_fehler) {
        if (dk_config_schreiben($dk_neu)) {
            $dk_cfg = $dk_neu;
            $dk_token = (string) $dk_neu['aktionstoken'];
            $dk_meldung = dk_t('MELDUNG.GESPEICHERT');
            if ($dk_tokengewuerfelt) {
                // Der Wert selbst gehoert NICHT ins Protokoll - nur die
                // Tatsache. Ein Merkwort im Log waere ein Merkwort auf Platte.
                dk_log('Neues Merkwort fuer den Endpunkt gewuerfelt. Alle Adressen '
                    . 'im Miniserver muessen jetzt nachgezogen werden.');
            }
        } else {
            $dk_fehler[] = dk_t('FEHLER.SCHREIBEN');
        }
    }
}

/* ---------------- Logdatei leeren ---------------- */
if (isset($_POST['log_leeren'])) {
    dk_log_leeren();
    $dk_meldung = dk_t('LOG.GELEERT');
    $dk_tab = 'tab-log';
}

/* ---------------- Portainer: Setup-Token ---------------- */
if (isset($_POST['tokenzeigen']) || isset($_POST['portainerneu'])) {
    if (isset($_POST['portainerneu'])) {
        $dk_setup = dk_portainer_neustart();
        $dk_meldung = $dk_setup !== '' ? dk_t('MELDUNG.NEUSTART_OK') : '';
    } else {
        $dk_setup = dk_setup_token();
    }
    if ($dk_setup === '') {
        $dk_rohlog = dk_portainer_log(40);
        $dk_fehler[] = dk_t('FEHLER.KEIN_SETUPTOKEN');
    }
}

$dk_da    = dk_bin();
list($dk_ok, $dk_grund, $dk_grundtext) = dk_zustand();
$dk_z     = dk_zaehlung();
$dk_pl    = dk_portainer_laeuft();
$dk_host  = preg_replace('/:.*$/', '', (string) ($_SERVER['HTTP_HOST'] ?? 'loxberry'));
$dk_basis = '/plugins/' . $dk_p['plugin'] . '/index.php?token=' . rawurlencode($dk_token);

if (class_exists('LBWeb', false)) {
    /* Hilfe in der eingestellten Sprache.
     *
     * Bis 1.1.0 stand hier fest 'help.html', und diese Datei war fest deutsch.
     * Gemeldet von einem Mitleser, zutreffend.
     *
     * In 1.2.0 stand hier eine Auswahl zwischen help.html und help_en.html,
     * weil ungeprueft war, welchen Pfad lbheader() absucht. Das ist am
     * 10.08.2026 an libs/phplib/loxberry_web.php nachgeholt worden:
     * LBWeb::gethelp() nimmt die genannte Datei aus templates/help/, leitet
     * daraus <name>.ini ab und laesst readlanguage() die Sprachdateien in
     * templates/lang/ suchen - also help_de.ini und help_en.ini. Die Auswahl
     * hier ist damit ueberfluessig; die Sprache waehlt LoxBerry selbst.
     */
    LBWeb::lbheader('Docker NG', 'https://wiki.loxberry.de/', 'help.html');
}
?>
<style>
.sm-wrap { max-width: 980px; margin: 0 auto; font-family: -apple-system, 'Segoe UI', Roboto, sans-serif; color: #333; }
.sm-wrap, .sm-wrap *, .sm-tabs, .sm-tabs * { text-shadow: none !important; }
.sm-wrap h2 { color: #6dac20; margin: 24px 0 10px; font-size: 1.15em; border-bottom: 2px solid #e0e0e0; padding-bottom: 6px; }
.sm-wrap h3 { color: #4f7d17; font-size: 1.0em; font-weight: 700; margin: 16px 0 2px; }
.sm-tabs { display: flex; gap: 4px; margin: 14px 0 0; border-bottom: 2px solid #6dac20; flex-wrap: wrap; }
.sm-tab { background: #eee; border: 1px solid #ccc; border-bottom: 0; border-radius: 8px 8px 0 0;
          padding: 9px 18px; font-size: 0.95em; color: #444 !important; text-decoration: none; display: inline-block; }
.sm-tab.sm-active { background: #6dac20; color: #fff !important; border-color: #6dac20; font-weight: 600; }
.sm-feld { margin: 14px 0; }
.sm-feld > label { display: block; font-weight: 600; font-size: 0.9em; color: #555; margin: 0 0 4px; }
.sm-feld .ui-input-text, .sm-feld .ui-select, .sm-feld .ui-textinput { max-width: 520px; }
.sm-feld .ui-input-text input, .sm-feld .ui-input-text textarea { font-size: 0.95em; }
.sm-hilfe { font-size: 0.85em; color: #555; margin: 4px 0 0; max-width: 640px; }
.sm-step { border: 1px solid #ddd; border-left: 4px solid #6dac20; background: #fafafa;
    border-radius: 6px; padding: 12px 14px; margin: 12px 0; font-size: 0.92em; line-height: 1.5; }
.sm-tbl { border-collapse: collapse; width: 100%; margin: 8px 0; font-size: 0.9em; }
.sm-tbl th, .sm-tbl td { border: 1px solid #ccc; padding: 5px 7px; text-align: left; vertical-align: top; }
.sm-tbl th { background: #eef3e6; font-weight: 600; }
.sm-mono { font-family: Consolas, "Courier New", monospace; background: #f0f0f0;
    padding: 1px 4px; border-radius: 3px; font-size: 0.94em; word-break: break-all; }
.sm-pre { background: #f4f4f4; border: 1px solid #ccc; padding: 10px; font-size: 0.85em;
    overflow: auto; margin: 8px 0; max-height: 420px; }
.sm-knopfreihe { display: flex; flex-wrap: wrap; gap: 10px; margin: 10px 0 4px; align-items: stretch; }
.sm-knopfreihe form { margin: 0; display: flex; }
.sm-wrap .sm-knopfreihe .sm-btn, .sm-wrap a.sm-btn, .sm-wrap button.sm-btn {
    flex: 0 0 auto; min-width: 250px; text-align: center; display: inline-flex;
    align-items: center; justify-content: center; line-height: 1.25;
    padding: 10px 14px !important; border-radius: 6px !important;
    color: #fff !important; text-decoration: none !important; font-size: 0.92em;
    border: 0 !important; cursor: pointer; font-weight: 600 !important;
    text-shadow: none !important; box-shadow: none !important;
    opacity: 1 !important; margin: 0 !important; width: auto !important; }
.sm-kacheln { display: flex; flex-wrap: wrap; gap: 10px; margin: 10px 0; }
.sm-kachel { border: 1px solid #ddd; border-radius: 10px; padding: 10px 14px; min-width: 130px; }
.sm-kachel b { display: block; font-size: 1.35em; color: #33691e; }
.sm-legende { display: flex; flex-wrap: wrap; gap: 14px; margin: 10px 0 2px; font-size: 0.86em; color: #555; }
.sm-legende span { display: inline-flex; align-items: center; gap: 6px; }
.sm-punkt { width: 13px; height: 13px; border-radius: 3px; display: inline-block; }
.sm-wrap .sm-btn.sm-b-lesen   { background: #6dac20 !important; }
.sm-wrap .sm-btn.sm-b-technik { background: #546e7a !important; }
.sm-wrap .sm-btn.sm-b-aktion  { background: #e0620d !important; }
.sm-wrap .sm-btn.sm-b-lesen:hover,   .sm-wrap .sm-btn.sm-b-lesen:focus   { background: #5c9219 !important; color: #fff !important; }
.sm-wrap .sm-btn.sm-b-technik:hover, .sm-wrap .sm-btn.sm-b-technik:focus { background: #435962 !important; color: #fff !important; }
.sm-wrap .sm-btn.sm-b-aktion:hover,  .sm-wrap .sm-btn.sm-b-aktion:focus  { background: #b84f0a !important; color: #fff !important; }
.sm-punkt.sm-b-lesen   { background: #6dac20; }
.sm-punkt.sm-b-technik { background: #546e7a; }
.sm-punkt.sm-b-aktion  { background: #e0620d; }
.sm-seite { display: none; padding-top: 4px; }
.sm-seite.sm-active { display: block; }
.sm-hinweis { border: 1px solid #cfe3b0; background: #f2f8ea; border-radius: 6px;
    padding: 10px 12px; margin: 12px 0; font-size: 0.9em; }
.sm-warnung { border: 1px solid #f0c9a0; background: #fdf4ec; border-radius: 6px;
    padding: 10px 12px; margin: 12px 0; font-size: 0.9em; }
.sm-an  { color: #1a7f1a; font-weight: 700; }
.sm-aus { color: #b00000; font-weight: 700; }
.sm-setup { font-size: 1.05em; font-weight: 700; letter-spacing: 0.03em; user-select: all; }
</style>

<div class="sm-wrap">
<h2><?= dk_e(dk_t('ALLGEMEIN.TITEL')) ?></h2>
<p class="sm-hilfe"><?= dk_t('ALLGEMEIN.EINLEITUNG') ?></p>

<?php if ($dk_da === '') { ?>
<div class="sm-warnung"><?= dk_t('MELDUNG.KEIN_DOCKER') ?></div>
<?php } elseif (!$dk_ok) { ?>
<?php /* Docker ist da, aber nicht ansprechbar. Genau hier faellt es auf -
       * die Containerliste bliebe sonst leer, und das saehe aus wie
       * 'es laeuft eben nichts'. Der Grund steht im Klartext dabei. */ ?>
<div class="sm-warnung"><b><?= dk_e(dk_t('MELDUNG.T_NICHT_ERREICHBAR')) ?></b><br>
<?= dk_e($dk_grundtext) ?></div>
<?php } ?>
<?php if ($dk_meldung !== '') { ?>
<div class="sm-hinweis"><?= dk_e($dk_meldung) ?></div>
<?php } ?>
<?php foreach ($dk_fehler as $dk_f) { ?>
<div class="sm-warnung"><?= $dk_f ?></div>
<?php } ?>

<div class="sm-tabs">
	<a class="sm-tab<?= $dk_tab === 'tab-settings' ? ' sm-active' : '' ?>" data-ziel="tab-settings" href="index.php?form=settings"><?= dk_e(dk_t('REITER.EINSTELLUNGEN')) ?></a>
	<a class="sm-tab<?= $dk_tab === 'tab-loxone' ? ' sm-active' : '' ?>" data-ziel="tab-loxone"   href="index.php?form=loxone"><?= dk_e(dk_t('REITER.LOXONE')) ?></a>
	<a class="sm-tab<?= $dk_tab === 'tab-test' ? ' sm-active' : '' ?>" data-ziel="tab-test"     href="index.php?form=test"><?= dk_e(dk_t('REITER.TEST')) ?></a>
	<a class="sm-tab<?= $dk_tab === 'tab-log' ? ' sm-active' : '' ?>" data-ziel="tab-log"      href="index.php?form=log"><?= dk_e(dk_t('REITER.LOG')) ?></a>
</div>

<!-- ======================= Einstellungen ======================= -->
<div class="sm-seite<?= $dk_tab === 'tab-settings' ? ' sm-active' : '' ?>" id="tab-settings">

<h2><?= dk_e(dk_t('EINST.ZUSTAND')) ?></h2>
<div class="sm-kacheln">
	<div class="sm-kachel"><?= dk_e(dk_t('EINST.K_DOCKER')) ?>
		<b class="<?= $dk_da !== '' ? 'sm-an' : 'sm-aus' ?>"><?= $dk_da !== '' ? dk_e(dk_t('ALLGEMEIN.JA')) : dk_e(dk_t('ALLGEMEIN.NEIN')) ?></b></div>
	<div class="sm-kachel"><?= dk_e(dk_t('EINST.K_GESAMT')) ?><b><?= (int) $dk_z['gesamt'] ?></b></div>
	<div class="sm-kachel"><?= dk_e(dk_t('EINST.K_LAEUFT')) ?><b><?= (int) $dk_z['laeuft'] ?></b></div>
	<div class="sm-kachel"><?= dk_e(dk_t('EINST.K_GESTOPPT')) ?><b><?= (int) $dk_z['gestoppt'] ?></b></div>
	<div class="sm-kachel"><?= dk_e(dk_t('EINST.K_PORTAINER')) ?>
		<b class="<?= $dk_pl ? 'sm-an' : 'sm-aus' ?>"><?= $dk_pl ? dk_e(dk_t('ALLGEMEIN.LAEUFT')) : dk_e(dk_t('ALLGEMEIN.GESTOPPT')) ?></b></div>
</div>
<?php if ($dk_da !== '') { ?>
<p class="sm-hilfe"><span class="sm-mono"><?= dk_e(dk_version()) ?></span></p>
<?php } ?>

<h2><?= dk_e(dk_t('EINST.PORTAINER')) ?></h2>
<p class="sm-hilfe"><?= dk_t('EINST.PORTAINER_TEXT') ?></p>
<div class="sm-legende">
<span><i class="sm-punkt sm-b-lesen"></i><?= dk_e(dk_t('LEGENDE.LESEN')) ?></span>
<span><i class="sm-punkt sm-b-technik"></i><?= dk_e(dk_t('LEGENDE.TECHNIK')) ?></span>
<span><i class="sm-punkt sm-b-aktion"></i><?= dk_e(dk_t('LEGENDE.AKTION')) ?></span>
</div>
<div class="sm-knopfreihe">
	<a data-role="none" class="sm-btn sm-b-lesen" target="_blank"
	   href="http://<?= dk_e($dk_host) ?>:<?= (int) $dk_cfg['portainer_port'] ?>"><?= dk_e(dk_t('EINST.B_OEFFNEN')) ?></a>
</div>
<div class="sm-hinweis"><?= dk_t('EINST.KEIN_IFRAME') ?></div>

<h3><?= dk_e(dk_t('EINST.SETUPTOKEN')) ?></h3>
<p class="sm-hilfe"><?= dk_t('EINST.SETUPTOKEN_TEXT') ?></p>
<div class="sm-knopfreihe">
	<form action="index.php" method="post">
		<input data-role="none" type="hidden" name="activetab" value="tab-settings">
		<input data-role="none" type="hidden" name="tokenzeigen" value="1">
		<button data-role="none" class="sm-btn sm-b-technik" type="submit"><?= dk_e(dk_t('EINST.B_SETUPZEIGEN')) ?></button>
	</form>
	<form action="index.php" method="post">
		<input data-role="none" type="hidden" name="activetab" value="tab-settings">
		<input data-role="none" type="hidden" name="portainerneu" value="1">
		<button data-role="none" class="sm-btn sm-b-aktion" type="submit"><?= dk_e(dk_t('EINST.B_NEUSTART')) ?></button>
	</form>
</div>
<?php if ($dk_setup !== '') { ?>
<div class="sm-hinweis"><b><?= dk_e(dk_t('EINST.SETUPTOKEN')) ?>:</b>
	<span class="sm-mono sm-setup"><?= dk_e($dk_setup) ?></span><br>
	<span class="sm-hilfe"><?= dk_t('EINST.SETUPTOKEN_GEFUNDEN') ?></span></div>
<?php } ?>
<?php if ($dk_rohlog !== '') { ?>
<pre class="sm-pre"><?= dk_e($dk_rohlog) ?></pre>
<?php } ?>

<h2><?= dk_e(dk_t('EINST.CONTAINER')) ?></h2>
<?php if ($dk_z['liste']) { ?>
<table class="sm-tbl">
<tr><th><?= dk_e(dk_t('EINST.T_NAME')) ?></th><th><?= dk_e(dk_t('EINST.T_ABBILD')) ?></th><th><?= dk_e(dk_t('EINST.T_ZUSTAND')) ?></th></tr>
<?php foreach ($dk_z['liste'] as $dk_c) { ?>
<tr><td><span class="sm-mono"><?= dk_e($dk_c['name']) ?></span></td>
	<td><?= dk_e($dk_c['image']) ?></td>
	<td class="<?= $dk_c['laeuft'] ? 'sm-an' : 'sm-aus' ?>"><?= dk_e($dk_c['status']) ?></td></tr>
<?php } ?>
</table>
<p class="sm-hilfe"><?= dk_t('EINST.CONTAINER_HINWEIS') ?></p>
<?php } else { ?>
<div class="sm-hinweis"><?= dk_t('EINST.KEINE_CONTAINER') ?></div>
<?php } ?>

<h2><?= dk_e(dk_t('EINST.KONFIG')) ?></h2>
<form action="index.php" method="post">
<input data-role="none" type="hidden" name="activetab" value="tab-settings">
<div class="sm-feld">
	<label for="portainer_name"><?= dk_e(dk_t('EINST.L_NAME')) ?></label>
	<input data-role="none" type="text" id="portainer_name" name="portainer_name"
	       value="<?= dk_e($dk_cfg['portainer_name']) ?>" size="24">
	<p class="sm-hilfe"><?= dk_t('EINST.H_NAME') ?></p>
</div>
<div class="sm-feld">
	<label for="portainer_port"><?= dk_e(dk_t('EINST.L_PORT')) ?></label>
	<input data-role="none" type="text" id="portainer_port" name="portainer_port"
	       value="<?= dk_e($dk_cfg['portainer_port']) ?>" size="8">
	<p class="sm-hilfe"><?= dk_t('EINST.H_PORT') ?></p>
</div>
<div class="sm-feld">
	<label><input data-role="none" type="checkbox" name="token_neu" value="1"> <?= dk_e(dk_t('EINST.L_TOKENNEU')) ?></label>
	<p class="sm-hilfe"><?= dk_t('EINST.H_TOKENNEU') ?></p>
</div>
<div class="sm-knopfreihe">
	<button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="speichern" value="1"><?= dk_e(dk_t('ALLGEMEIN.SPEICHERN')) ?></button>
</div>
</form>
</div>

<!-- ======================= Einbindung in Loxone ======================= -->
<div class="sm-seite<?= $dk_tab === 'tab-loxone' ? ' sm-active' : '' ?>" id="tab-loxone">
<h2><?= dk_e(dk_t('LOX.TITEL')) ?></h2>
<p class="sm-hilfe"><?= dk_t('LOX.EINLEITUNG') ?></p>

<div class="sm-step"><b><?= dk_e(dk_t('LOX.S1')) ?></b><br>
<?= dk_t('LOX.S1_TEXT') ?><br>
<span class="sm-mono">http://<?= dk_e($dk_host) ?><?= dk_e($dk_basis) ?>&amp;aktion=status</span>
<p class="sm-hilfe"><?= dk_t('LOX.S1_HINWEIS') ?><br>
<span class="sm-mono">DOCKERNG;OK=1;GESAMT=3;LAEUFT=3;GESTOPPT=0;PORTAINER=1;C_portainer=1</span></p>
</div>

<div class="sm-step"><b><?= dk_e(dk_t('LOX.S2')) ?></b><br>
<table class="sm-tbl">
<tr><th><?= dk_e(dk_t('LOX.T_TITEL')) ?></th><th><?= dk_e(dk_t('LOX.T_ERKENNUNG')) ?></th><th><?= dk_e(dk_t('LOX.T_BEDEUTUNG')) ?></th></tr>
<tr><td>DOCKERNG_OK</td><td><span class="sm-mono">OK=\v</span></td><td><?= dk_e(dk_t('LOX.F_OK')) ?></td></tr>
<tr><td>DOCKERNG_GESAMT</td><td><span class="sm-mono">GESAMT=\v</span></td><td><?= dk_e(dk_t('LOX.F_GESAMT')) ?></td></tr>
<tr><td>DOCKERNG_LAEUFT</td><td><span class="sm-mono">LAEUFT=\v</span></td><td><?= dk_e(dk_t('LOX.F_LAEUFT')) ?></td></tr>
<tr><td>DOCKERNG_GESTOPPT</td><td><span class="sm-mono">GESTOPPT=\v</span></td><td><?= dk_e(dk_t('LOX.F_GESTOPPT')) ?></td></tr>
<?php foreach ($dk_z['liste'] as $dk_c) { $dk_s = preg_replace('/[^A-Za-z0-9_]/', '_', $dk_c['name']); ?>
<tr><td>DOCKERNG_C_<?= dk_e($dk_s) ?></td>
	<td><span class="sm-mono">C_<?= dk_e($dk_s) ?>=\v</span></td>
	<td><?= dk_e(sprintf(dk_t('LOX.F_CONTAINER'), $dk_c['name'])) ?></td></tr>
<?php } ?>
</table>
<p class="sm-hilfe"><?= dk_t('LOX.S2_HINWEIS') ?></p>
</div>

<div class="sm-step"><b><?= dk_e(dk_t('LOX.S3')) ?></b><br>
<?= dk_t('LOX.S3_TEXT') ?>
<div class="sm-legende">
<span><i class="sm-punkt sm-b-technik"></i><?= dk_e(dk_t('LEGENDE.TECHNIK')) ?></span>
</div>
<div class="sm-knopfreihe" style="margin-top:10px;">
	<form action="index.php" method="post">
		<input data-role="none" type="hidden" name="activetab" value="tab-loxone">
		<input data-role="none" type="hidden" name="download" value="xml_in">
		<button data-role="none" class="sm-btn sm-b-technik" type="submit"><?= dk_e(dk_t('LOX.B_XML')) ?></button>
	</form>
</div>
<p class="sm-hilfe"><?= dk_t('LOX.S3_WARNUNG') ?></p>
</div>

<div class="sm-step"><b><?= dk_e(dk_t('LOX.S4')) ?></b><br>
<?= dk_t('LOX.S4_TEXT') ?>
<table class="sm-tbl">
<tr><th>#</th><th><?= dk_e(dk_t('LOX.B_TYP')) ?></th><th><?= dk_e(dk_t('LOX.B_NAME')) ?></th><th><?= dk_e(dk_t('LOX.B_PARAM')) ?></th><th><?= dk_e(dk_t('LOX.B_EIN')) ?></th></tr>
<tr><td>1</td><td><?= dk_e(dk_t('LOX.BS_VI')) ?></td><td>DOCKERNG_GESTOPPT</td><td><?= dk_e(dk_t('LOX.BS_VI_P')) ?></td><td>&mdash;</td></tr>
<tr><td>2</td><td><?= dk_e(dk_t('LOX.BS_SWS')) ?></td><td>Container gestoppt</td><td><?= dk_e(dk_t('LOX.BS_SWS_P')) ?></td><td>#1</td></tr>
<tr><td>3</td><td><?= dk_e(dk_t('LOX.BS_EIN')) ?></td><td>Meldung verzoegern</td><td><?= dk_e(dk_t('LOX.BS_EIN_P')) ?></td><td>#2</td></tr>
<tr><td>4</td><td><?= dk_e(dk_t('LOX.BS_ODER')) ?></td><td>Sammelstoerung</td><td>&mdash;</td><td>#3<?= $dk_z['liste'] ? ', ' . dk_e(dk_t('LOX.BS_ODER_MEHR')) : '' ?></td></tr>
<tr><td>5</td><td><?= dk_e(dk_t('LOX.BS_BENACH')) ?></td><td>Docker-Stoerung</td><td><?= dk_e(dk_t('LOX.BS_BENACH_P')) ?></td><td>#4</td></tr>
<tr><td>6</td><td><?= dk_e(dk_t('LOX.BS_VI')) ?></td><td>DOCKERNG_OK</td><td><?= dk_e(dk_t('LOX.BS_VI_P')) ?></td><td>&mdash;</td></tr>
<tr><td>7</td><td><?= dk_e(dk_t('LOX.BS_NICHT')) ?></td><td>Docker antwortet nicht</td><td>&mdash;</td><td>#6</td></tr>
</table>
<p class="sm-hilfe"><?= dk_t('LOX.S4_ZU3') ?></p>
<p class="sm-hilfe"><?= dk_t('LOX.S4_ZU4') ?></p>
</div>

<div class="sm-step"><b><?= dk_e(dk_t('LOX.S5')) ?></b><br>
<?= dk_t('LOX.S5_TEXT') ?></div>

<div class="sm-step"><b><?= dk_e(dk_t('LOX.S6')) ?></b><br>
<?= dk_t('LOX.S6_TEXT') ?></div>
</div>

<!-- ======================= Test ======================= -->
<div class="sm-seite<?= $dk_tab === 'tab-test' ? ' sm-active' : '' ?>" id="tab-test">
<h2><?= dk_e(dk_t('TEST.TITEL')) ?></h2>
<p class="sm-hilfe"><?= dk_t('TEST.EINLEITUNG') ?></p>

<table class="sm-tbl">
<tr><th><?= dk_e(dk_t('TEST.T_FRAGE')) ?></th><th><?= dk_e(dk_t('TEST.T_ANTWORT')) ?></th></tr>
<tr><td><?= dk_e(dk_t('TEST.F_DOCKER')) ?></td>
	<td class="<?= $dk_da !== '' ? 'sm-an' : 'sm-aus' ?>"><?= $dk_da !== '' ? '&#10003; ' . dk_e($dk_da) : '&#10007;' ?></td></tr>
<tr><td><?= dk_e(dk_t('TEST.F_VERSION')) ?></td>
	<td><?= $dk_da !== '' ? dk_e(dk_version()) : '&mdash;' ?></td></tr>
<tr><td><?= dk_e(dk_t('TEST.F_PORTAINER')) ?></td>
	<td class="<?= $dk_pl ? 'sm-an' : 'sm-aus' ?>"><?= $dk_pl ? '&#10003;' : '&#10007;' ?></td></tr>
<tr><td><?= dk_e(dk_t('TEST.F_CONTAINER')) ?></td><td><?= (int) $dk_z['gesamt'] ?></td></tr>
<tr><td><?= dk_e(dk_t('TEST.F_TOKEN')) ?></td>
	<td class="sm-an">&#10003; <?= (int) strlen($dk_token) ?> <?= dk_e(dk_t('TEST.ZEICHEN')) ?></td></tr>
<tr><td><?= dk_e(dk_t('TEST.F_KONFIG')) ?></td>
	<td class="<?= @is_file($dk_p['config']) ? 'sm-an' : 'sm-aus' ?>"><?= @is_file($dk_p['config']) ? '&#10003;' : '&#10007;' ?></td></tr>
</table>

<div class="sm-legende">
<span><i class="sm-punkt sm-b-lesen"></i> <?= dk_e(dk_t('LEGENDE.LESEN')) ?></span>
<span><i class="sm-punkt sm-b-technik"></i> <?= dk_e(dk_t('LEGENDE.TECHNIK')) ?></span>
<span><i class="sm-punkt sm-b-aktion"></i> <?= dk_e(dk_t('LEGENDE.AKTION')) ?></span>
</div>

<h3><?= dk_e(dk_t('TEST.G_LESEN')) ?></h3>
<div class="sm-knopfreihe">
	<a data-role="none" class="sm-btn sm-b-lesen" target="_blank" href="<?= dk_e($dk_basis) ?>&amp;aktion=status"><?= dk_e(dk_t('TEST.B_STATUS')) ?></a>
	<a data-role="none" class="sm-btn sm-b-lesen" target="_blank" href="<?= dk_e($dk_basis) ?>&amp;aktion=liste"><?= dk_e(dk_t('TEST.B_LISTE')) ?></a>
</div>

<h3><?= dk_e(dk_t('TEST.G_TECHNIK')) ?></h3>
<div class="sm-knopfreihe">
	<a data-role="none" class="sm-btn sm-b-technik" target="_blank" href="<?= dk_e($dk_basis) ?>&amp;aktion=roh"><?= dk_e(dk_t('TEST.B_ROH')) ?></a>
	<a data-role="none" class="sm-btn sm-b-technik" target="_blank" href="/plugins/<?= dk_e($dk_p['plugin']) ?>/index.php?aktion=status"><?= dk_e(dk_t('TEST.B_OHNETOKEN')) ?></a>
</div>
<p class="sm-hilfe"><?= dk_t('TEST.H_OHNETOKEN') ?></p>

<h3><?= dk_e(dk_t('TEST.G_AKTION')) ?></h3>
<p class="sm-hilfe"><?= dk_t('TEST.H_AKTION') ?></p>
<div class="sm-knopfreihe">
	<form action="index.php" method="post">
		<input data-role="none" type="hidden" name="activetab" value="tab-test">
		<input data-role="none" type="hidden" name="portainerneu" value="1">
		<button data-role="none" class="sm-btn sm-b-aktion" type="submit"><?= dk_e(dk_t('EINST.B_NEUSTART')) ?></button>
	</form>
</div>
</div>

<!-- ======================= Logdateien ======================= -->
<div class="sm-seite<?= $dk_tab === 'tab-log' ? ' sm-active' : '' ?>" id="tab-log">
<h2><?= dk_e(dk_t('REITER.LOG')) ?></h2>
<?php
/* Kein LBWeb::loglist_html() hier.
 *
 * Die Funktion baut eine Auswahlliste ueber die Logdateien, die ueber das
 * Log-SDK von LoxBerry angelegt wurden. Dieses Plugin fuehrt sein Protokoll
 * aber als schlichte Textdatei (siehe dk_paths) - der Log-Manager kennt sie
 * gar nicht. Die Liste blieb deshalb leer und stand als leeres Bedienelement
 * ueber dem Protokolltext, den es darunter ohnehin gibt. Ein Bedienelement,
 * das nie etwas anbietet, laesst den Anwender suchen, was er falsch gemacht
 * hat.
 */
$dk_logtext = dk_log_lesen(200);
?>
<div class="sm-warnung"><?= dk_t('LOG.RAMDISK') ?></div>
<p class="sm-hilfe"><?= dk_t('LOG.ORT') ?><br>
<span class="sm-mono"><?= dk_e($dk_p['log']) ?></span></p>
<?php if (trim($dk_logtext) !== '') { ?>
<pre class="sm-pre"><?= dk_e($dk_logtext) ?></pre>
<?php } else { ?>
<div class="sm-hinweis"><?= dk_t('MELDUNG.KEIN_LOG') ?></div>
<?php } ?>
<div class="sm-legende">
<span><i class="sm-punkt sm-b-aktion"></i> <?= dk_t('LEGENDE.AKTION_LOG') ?></span>
</div>
<div class="sm-knopfreihe">
	<form action="index.php" method="post">
		<input data-role="none" type="hidden" name="activetab" value="tab-log">
		<button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="log_leeren" value="1"><?= dk_e(dk_t('LOG.B_LEEREN')) ?></button>
	</form>
</div>
</div>

</div>

<script>
(function () {
	var reiter = document.querySelectorAll('.sm-tab');
	function zeige(id) {
		reiter.forEach(function (r) { r.classList.toggle('sm-active', r.dataset.ziel === id); });
		document.querySelectorAll('.sm-seite').forEach(function (s) { s.classList.toggle('sm-active', s.id === id); });
		document.querySelectorAll('input[name="activetab"]').forEach(function (f) { f.value = id; });
		if (history.replaceState) { history.replaceState(null, '', 'index.php?form=' + id.replace('tab-', '')); }
	}
	reiter.forEach(function (r) {
		r.addEventListener('click', function (e) { e.preventDefault(); zeige(r.dataset.ziel); });
	});
	zeige(<?= json_encode($dk_tab) ?>);
})();
</script>

<?php
if (class_exists('LBWeb', false)) { LBWeb::lbfooter(); }
