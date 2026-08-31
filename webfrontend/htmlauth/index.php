<?php
/**
 * Docker NG - Bedienoberflaeche
 *
 * Fuenf Reiter: Einstellungen | MQTT | Einbindung in Loxone | Test | Logdateien
 *
 * Bis 1.2.4 stand hier: "Kein MQTT-Reiter: Docker NG fuehrt keinen Dienst, der
 * zyklisch veroeffentlichen koennte." Die AUSSAGE stimmte - der Weg war
 * ungebaut -, die BEGRUENDUNG nicht: fuer genau diesen Fall verteilt
 * plugininstall.pl Cron-Skripte aus dem Ordner cron/ nach cron.01min. Ein
 * eigener Dienst ist dafuer nicht noetig. Seit 1.3.0 gibt es cron/cron.01min
 * und bin/dockerng_takt.php; MQTT steht DANEBEN, nicht anstelle des
 * HTTP-Endpunkts, und ist ab Werk aus.
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
$dk_fmt     = dk_formtoken();      // Merkmal gegen fremde Formulare

/* ==================================================================
 * DER WACHPOSTEN GEGEN FREMDE FORMULARE
 *
 * Er steht hier - VOR jedem Handler und VOR der Reiterwahl. Das ist kein
 * Geschmack, sondern die Bauform:
 *
 *   - EINE Pruefung. Einen einzelnen Handler kann man beim Erweitern
 *     vergessen; einen Wachposten am Eingang nicht. Ein Schutz, den man beim
 *     Erweitern vergessen kann, ist keiner.
 *   - Faellt sie durch, wird $_POST bis auf den aktiven Reiter GELEERT. Damit
 *     laeuft danach kein Handler mehr an, ohne dass jeder einzelne davon
 *     wissen muesste.
 *   - Und es wird GEMELDET. Ein Formular, das wortlos nichts tut, schickt den
 *     Anwender auf die Suche nach einem Fehler, den es nicht gibt.
 *   - Die Reiterwahl steht DANACH, damit der Anwender die Meldung dort sieht,
 *     wo er gerade war.
 *
 * Warum es das braucht: htmlauth/ schuetzt gegen den unangemeldeten Aufruf,
 * nicht dagegen, dass der Browser eines angemeldeten Bedieners ein Formular
 * abschickt, das auf einer fremden Seite steht - die Basic-Anmeldung schickt
 * er dabei automatisch mit. Bis 1.3.0 genuegte das, um von jeder beliebigen
 * Seite aus 'token_neu=1' auszuloesen und damit alle virtuellen Eingaenge im
 * Miniserver auf HTTP 403 zu setzen.
 * ================================================================== */
$dk_csrf_ok = true;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $dk_mit = (isset($_POST['fmt']) && is_string($_POST['fmt'])) ? $_POST['fmt'] : '';
    if ($dk_fmt === '') {
        // Kein Aktionstoken, also kein ableitbares Merkmal. Fail closed: es
        // wird abgewiesen, nicht durchgelassen.
        $dk_csrf_ok = false;
        $dk_fehler[] = dk_t('FEHLER.CSRF_KEIN_TOKEN');
    } elseif (!hash_equals($dk_fmt, $dk_mit)) {
        $dk_csrf_ok = false;
        $dk_fehler[] = dk_t('FEHLER.CSRF');
        dk_log('Ein Formular ohne gueltiges Merkmal wurde abgewiesen. Das ist der '
            . 'erwartete Schutz gegen Formulare auf fremden Seiten - oder die Seite '
            . 'lag lange offen, waehrend das Merkwort neu gewuerfelt wurde.');
    }
    if (!$dk_csrf_ok) {
        $dk_behalten = isset($_POST['activetab']) ? $_POST['activetab'] : null;
        $_POST = array();
        if ($dk_behalten !== null) { $_POST['activetab'] = $dk_behalten; }
    }
}

/* Die Reiterliste steht GENAU EINMAL - ausgeschrieben, nicht gerechnet.
 * Leiste, Bereiche und diese Positivliste muessen dieselben Namen tragen;
 * die Kongruenzprobe im Reiter Test zaehlt das nach. */
$dk_reiter = array('tab-settings', 'tab-mqtt', 'tab-loxone', 'tab-test', 'tab-log');
$dk_tab = 'tab-settings';
if (isset($_POST['activetab']) && in_array((string) $_POST['activetab'], $dk_reiter, true)) {
    $dk_tab = (string) $_POST['activetab'];
} elseif (isset($_GET['form'])
          && in_array('tab-' . (string) $_GET['form'], $dk_reiter, true)) {
    $dk_tab = 'tab-' . (string) $_GET['form'];
}

/* ==================================================================
 * DIE HANDLER STEHEN VOR lbheader() - DAS IST BAUVORSCHRIFT
 * ==================================================================
 *
 * Stand der Kopf davor, war er beim Aufruf von header() schon
 * geschrieben - "Cannot modify header information", und der Knopf
 * "Einstellungen sichern" lieferte eine Seite mit angehaengtem JSON
 * statt einer Datei.
 *
 * Am PHP-CLI ist das unsichtbar: header() ist dort wirkungslos und
 * headers_sent() immer falsch. Und wer OHNE gueltiges Formularmerkmal
 * misst, wird vom Wachposten abgewiesen, bevor der Handler anlaeuft.
 * Beides hat den Fehler lange verdeckt.
 *
 * Reihenfolge: Bibliothek, Konfiguration, Wachposten, Reiterwahl,
 * ALLE Handler samt Downloads, dann erst lbheader(), dann HTML.
 * ================================================================== */
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

    /* ---- Wachliste ----
     * Kaestchen werden per isset() gelesen. Genau deshalb hat MQTT unten ein
     * EIGENES Formular mit eigenem Handler: ein Sammelhandler wuerde beim
     * Absenden des einen Formulars die Haken des anderen stillschweigend
     * nullen.
     *
     * 'alle' bedeutet leere Liste - das ist das Verhalten bis 1.2.4 und
     * bleibt die Vorgabe. Sonst waere ein Anwender, der nach dem Update
     * einmal speichert, ohne Haken zu setzen, ploetzlich ohne jede
     * Ueberwachung - und wuesste nicht, warum.
     */
    if (isset($_POST['wache_gesetzt'])) {
        if (!empty($_POST['wache_alle'])) {
            $dk_neu['wachliste'] = array();
        } else {
            $dk_w = array();
            foreach ((array) ($_POST['wache'] ?? array()) as $dk_wn) {
                if (is_string($dk_wn) && preg_match('/^[A-Za-z0-9_.\-]{1,64}$/', $dk_wn)) {
                    $dk_w[] = $dk_wn;
                }
            }
            if (!$dk_w) {
                $dk_fehler[] = dk_t('FEHLER.WACHE_LEER');
            } else {
                $dk_neu['wachliste'] = $dk_w;
            }
        }
    }

    $dk_gr = trim((string) ($_POST['schleife_grenze'] ?? ''));
    if ($dk_gr !== '') {
        if (!preg_match('/^[0-9]{1,3}$/', $dk_gr) || (int) $dk_gr < 1 || (int) $dk_gr > 100) {
            $dk_fehler[] = dk_t('FEHLER.SCHLEIFE_GRENZE');
        } else {
            $dk_neu['schleife_grenze'] = (int) $dk_gr;
        }
    }

    $dk_pg = trim((string) ($_POST['platz_grenze_mb'] ?? ''));
    if ($dk_pg !== '') {
        if (!preg_match('/^[0-9]{1,7}$/', $dk_pg)) {
            $dk_fehler[] = dk_t('FEHLER.PLATZ_GRENZE');
        } else {
            $dk_neu['platz_grenze_mb'] = (int) $dk_pg;
        }
    }

    $dk_neu['melden_aktiv']  = isset($_POST['melden_aktiv']) ? 1 : 0;
    $dk_neu['updates_aktiv'] = isset($_POST['updates_aktiv']) ? 1 : 0;

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

/* ---------------- Speichern: MQTT ----------------
 * Eigenes Formular, eigener Handler - siehe die Begruendung bei der
 * Wachliste oben.
 */
if (($_POST['speichern_mqtt'] ?? '') === '1') {
    $dk_neu = $dk_cfg;
    $dk_neu['mqtt_aktiv'] = isset($_POST['mqtt_aktiv']) ? 1 : 0;

    $dk_prae = trim((string) ($_POST['mqtt_praefix'] ?? ''));
    if (!preg_match('/^[A-Za-z0-9_\-]{1,32}$/', $dk_prae)) {
        $dk_fehler[] = dk_t('FEHLER.MQTT_PRAEFIX');
    } else {
        $dk_neu['mqtt_praefix'] = $dk_prae;
    }

    if (!$dk_fehler) {
        if (dk_config_schreiben($dk_neu)) {
            $dk_cfg = $dk_neu;
            $dk_meldung = dk_t('MELDUNG.GESPEICHERT');
        } else {
            $dk_fehler[] = dk_t('FEHLER.SCHREIBEN');
        }
    }
    $dk_tab = 'tab-mqtt';
}

/* ---------------- Minutentakt von Hand ausloesen ----------------
 * Der Hausregel folgend: jeden Cron-Dienst nach der Installation einmal von
 * Hand starten und das Ergebnis ansehen. Genau dafuer ist dieser Knopf da -
 * er ersetzt den Gang auf die Kommandozeile.
 */
$dk_takt_ergebnis = null;
if (isset($_POST['takt_jetzt'])) {
    $dk_takt_ergebnis = dk_takt();
    dk_zustandsdatei(true);
    $dk_meldung = dk_t('MELDUNG.TAKT_GELAUFEN');
    $dk_tab = 'tab-test';
}

/* ---------------- Protokoll eines Containers ---------------- */
$dk_clog = '';
$dk_cname = '';
if (isset($_POST['containerlog'])) {
    $dk_cname = trim((string) ($_POST['clog_name'] ?? ''));
    if (!preg_match('/^[A-Za-z0-9_.\-]{1,64}$/', $dk_cname)) {
        $dk_fehler[] = dk_t('FEHLER.NAME');
        $dk_cname = '';
    } else {
        $dk_clog = dk_container_log($dk_cname, 200);
        if (trim($dk_clog) === '') { $dk_clog = dk_t('TEST.CLOG_LEER'); }
    }
    $dk_tab = 'tab-test';
}

/* ---------------- Logdatei leeren ---------------- */
if (isset($_POST['log_leeren'])) {
    // Rueckgabewert auswerten. Bis 1.2.3 stand "Das Protokoll wurde geleert."
    // auch dann da, wenn das Schreiben scheiterte.
    if (dk_log_leeren()) {
        $dk_meldung = dk_t('LOG.GELEERT');
    } else {
        $dk_fehler[] = dk_t('FEHLER.LOG_LEEREN');
    }
    $dk_tab = 'tab-log';
}

/* ---------------- Portainer: Setup-Token ----------------
 *
 * Neustart und Tokensuche sind ab 1.2.4 zwei getrennte Auskuenfte. Bis 1.2.3
 * gab dk_portainer_neustart() nur einen String zurueck; ein fehlgeschlagener
 * Neustart und ein bereits eingerichtetes Portainer sahen danach gleich aus,
 * und beide erzeugten eine orange Fehlerbox ohne jede Bestaetigung, dass der
 * Neustart geklappt hat.
 */
if (isset($_POST['tokenzeigen']) || isset($_POST['portainerneu'])) {
    if (isset($_POST['portainerneu'])) {
        list($dk_neustart_ok, $dk_setup) = dk_portainer_neustart();
        if (!$dk_neustart_ok) {
            $dk_fehler[] = dk_t('FEHLER.NEUSTART');
        } elseif ($dk_setup !== '') {
            $dk_meldung = dk_t('MELDUNG.NEUSTART_OK');
        } else {
            // Neustart hat geklappt, nur kein neuer Token - der Regelfall bei
            // einem bereits eingerichteten Portainer. Das ist kein Fehler.
            $dk_meldung = dk_t('MELDUNG.NEUSTART_OHNE_TOKEN');
        }
    } else {
        $dk_setup = dk_setup_token();
        if ($dk_setup === '') {
            $dk_rohlog = dk_portainer_log(40);
            $dk_fehler[] = dk_t('FEHLER.KEIN_SETUPTOKEN');
        }
    }
}

$dk_da    = dk_bin();
list($dk_ok, $dk_grund, $dk_grundtext) = dk_zustand();
$dk_z     = dk_zaehlung();
$dk_pl    = dk_portainer_laeuft();
$dk_koll  = dk_schluesselkollisionen($dk_z['liste']);
list($dk_port, $dk_port_gemessen, $dk_port_schema) = dk_portainer_port();
$dk_host  = preg_replace('/:.*$/', '', (string) ($_SERVER['HTTP_HOST'] ?? 'loxberry'));
/* Schema und Port so, wie DIESE Seite gerade aufgerufen wurde.
 *
 * Bis 1.2.3 stand in der Anzeige fest 'http://' und ein um den Port
 * gekuerzter Rechnername, waehrend die Importdatei daneben das Schema aus
 * $_SERVER ableitete und den Port MITNAHM. Auf einem LoxBerry, der nur ueber
 * HTTPS oder auf einem abweichenden Port erreichbar ist, konnten die beiden
 * nicht gleichzeitig stimmen - und der Aufwand, der fuer die Schemaerkennung
 * in der Vorlage getrieben wurde, verpuffte an der Anzeige.
 */
$dk_schema = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
             || (int) ($_SERVER['SERVER_PORT'] ?? 0) === 443 ? 'https' : 'http';
$dk_hostport = (string) ($_SERVER['HTTP_HOST'] ?? 'loxberry');
$dk_basis = '/plugins/' . $dk_p['plugin'] . '/index.php?token=' . rawurlencode($dk_token);

$dk_zd      = dk_zustandsdatei();
$dk_alter   = dk_zustand_alter();
$dk_mqttlage = dk_mqtt_lage();
$dk_befund  = dk_befund();
$dk_wache   = dk_wachliste();
$dk_alle_ueberwacht = !$dk_cfg['wachliste'];
$dk_platz   = isset($dk_zd['platz']) ? $dk_zd['platz'] : array();
$dk_updates = isset($dk_zd['updates']) ? (array) $dk_zd['updates'] : array();

/* Log-Rotation von Docker. Ohne max-size schreibt der json-file-Treiber
 * unbegrenzt weiter - der einzige Punkt in diesem Plugin, der einen LoxBerry
 * unbootbar machen kann. Gelesen wird die Datei nur; geschrieben hat sie
 * postroot.sh bei der Installation, denn dafuer braucht es root. */
$dk_rot = array('gesetzt' => -1, 'max' => '', 'anzahl' => '');
if (@is_readable('/etc/docker/daemon.json')) {
    $dk_dj = json_decode((string) @file_get_contents('/etc/docker/daemon.json'), true);
    if (is_array($dk_dj)) {
        $dk_rot['gesetzt'] = isset($dk_dj['log-opts']['max-size']) ? 1 : 0;
        $dk_rot['max']     = isset($dk_dj['log-opts']['max-size']) ? (string) $dk_dj['log-opts']['max-size'] : '';
        $dk_rot['anzahl']  = isset($dk_dj['log-opts']['max-file']) ? (string) $dk_dj['log-opts']['max-file'] : '';
    }
} elseif (!@file_exists('/etc/docker/daemon.json')) {
    $dk_rot['gesetzt'] = 0;
}


/* ---------------- Einstellungen sichern ----------------
 *
 * Ausgegeben wird die VOLLE Konfiguration - samt Aktionstoken. Ohne ihn
 * stuenden nach dem Zurueckspielen alle Felder richtig, und das Plugin
 * kaeme trotzdem nicht an die Anlage; die Datei waere wertlos. Damit
 * traegt sie ein Geheimnis, und der Hinweis am Knopf sagt das. */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['dk_sichern'])) {
    $dk_js = json_encode(dk_config(),
        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($dk_js !== false) {
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="docker_einstellungen_'
               . date('Ymd_His') . '.json"');
        echo $dk_js;
        exit;
    }
    $dk_fehler[] = dk_t('EINST.SICH_SCHREIBFEHLER');
}

/* ---------------- Einstellungen zurueckspielen ----------------
 *
 * is_uploaded_file() ZUERST: ohne diese Pruefung liesse sich jede Datei des
 * Servers unterschieben. Dann die Groessengrenze - eine Sicherung dieses
 * Plugins ist wenige Kilobyte gross; alles darueber wird gar nicht gelesen. */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['dk_zurueck'])) {
    if (!isset($_FILES['dk_sicherung']) || !is_array($_FILES['dk_sicherung'])
        || !isset($_FILES['dk_sicherung']['tmp_name'])
        || !@is_uploaded_file($_FILES['dk_sicherung']['tmp_name'])) {
        $dk_fehler[] = dk_t('EINST.SICH_KEINE_DATEI');
    } elseif ((int) $_FILES['dk_sicherung']['size'] > 262144) {
        $dk_fehler[] = dk_t('EINST.SICH_ZU_GROSS');
    } else {
        list($dk_neu, $dk_mangel, $dk_n) = dk_sicherung_lesen(
            (string) @file_get_contents($_FILES['dk_sicherung']['tmp_name']));
        if ($dk_neu === null) {
            /* ALLE Beanstandungen, nicht nur die erste - und geaendert wird
             * nichts. */
            $dk_fehler[] = dk_t('EINST.SICH_ABGELEHNT') . ' '
                            . implode(' ', $dk_mangel);
        } elseif (dk_config_schreiben($dk_neu)) {
            $dk_meldungen[] = sprintf(dk_t('EINST.SICH_UEBERNOMMEN'), $dk_n);
        } else {
            $dk_fehler[] = dk_t('EINST.SICH_SCHREIBFEHLER');
        }
    }
}


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
/* Rollbehaelter fuer Tabellen, die breiter sind als das Fenster. Aus der
   VORLAGE_hausstandard.css.html - bis 1.2.3 fehlte er als einzige Klasse des
   Hausstandards in diesem Plugin. */
.sm-breit { overflow-x: auto; -webkit-overflow-scrolling: touch; margin: 10px 0; }
.sm-breit .sm-tbl { margin: 0; min-width: 760px; }
/* Ein Auswahlfeld muss man als Auswahlfeld erkennen. jQuery Mobile ist mit
   data-role="none" abgeschaltet, und ohne eigenen Pfeil sieht das Feld dann
   aus wie ein Textfeld. Der Rautenzeichen im SVG MUSS als %23 stehen - in
   einer Data-URI beginnt mit # der Fragmentbezeichner, und die Farbe faellt
   still aus. */
.sm-wrap select {
	-webkit-appearance: none; -moz-appearance: none; appearance: none;
	border: 1px solid #ccc; border-radius: 6px; background-color: #fff;
	padding: 8px 32px 8px 10px; font-size: 0.95em; max-width: 520px;
	background-image: url("data:image/svg+xml;charset=UTF-8,%3Csvg xmlns='http://www.w3.org/2000/svg' width='14' height='9' viewBox='0 0 14 9'%3E%3Cpath fill='%23546e7a' d='M1 1l6 6 6-6'/%3E%3C/svg%3E");
	background-repeat: no-repeat; background-position: right 10px center; }
.sm-tbl select { padding-right: 28px; background-position: right 7px center; }
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
	<a class="sm-tab<?= $dk_tab === 'tab-mqtt' ? ' sm-active' : '' ?>" data-ziel="tab-mqtt"     href="index.php?form=mqtt"><?= dk_e(dk_t('REITER.MQTT')) ?></a>
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
	<div class="sm-kachel"><?= dk_e(dk_t('EINST.K_AUSFALL')) ?>
		<b class="<?= $dk_z['ausfall'] ? 'sm-aus' : 'sm-an' ?>"><?= (int) $dk_z['ausfall'] ?></b></div>
<?php if ($dk_z['pausiert']) { ?>
	<div class="sm-kachel"><?= dk_e(dk_t('EINST.K_PAUSIERT')) ?><b class="sm-aus"><?= (int) $dk_z['pausiert'] ?></b></div>
<?php } ?>
	<div class="sm-kachel"><?= dk_e(dk_t('EINST.K_UNGESUND')) ?>
		<b class="<?= $dk_z['ungesund'] ? 'sm-aus' : 'sm-an' ?>"><?= (int) $dk_z['ungesund'] ?></b></div>
<?php if ($dk_z['fehlt']) { ?>
	<div class="sm-kachel"><?= dk_e(dk_t('EINST.K_FEHLT')) ?><b class="sm-aus"><?= (int) $dk_z['fehlt'] ?></b></div>
<?php } ?>
<?php if ($dk_z['schleife']) { ?>
	<div class="sm-kachel"><?= dk_e(dk_t('EINST.K_SCHLEIFE')) ?><b class="sm-aus"><?= (int) $dk_z['schleife'] ?></b></div>
<?php } ?>
	<div class="sm-kachel"><?= dk_e(dk_t('EINST.K_PORTAINER')) ?>
		<b class="<?= $dk_pl ? 'sm-an' : 'sm-aus' ?>"><?= $dk_pl ? dk_e(dk_t('ALLGEMEIN.LAEUFT')) : dk_e(dk_t('ALLGEMEIN.GESTOPPT')) ?></b></div>
<?php if (isset($dk_platz['frei_mb']) && (int) $dk_platz['frei_mb'] >= 0) { ?>
	<div class="sm-kachel"><?= dk_e(dk_t('EINST.K_PLATZ')) ?>
		<b class="<?= ((int) $dk_cfg['platz_grenze_mb'] > 0 && (int) $dk_platz['frei_mb'] < (int) $dk_cfg['platz_grenze_mb']) ? 'sm-aus' : 'sm-an' ?>"><?= (int) $dk_platz['frei_mb'] ?> MB</b></div>
<?php } ?>
</div>
<p class="sm-hilfe"><?= dk_t('EINST.K_HINWEIS') ?></p>
<?php /* Der Gesamtbefund in einem Satz - dieselbe Quelle, die auch das
       * Benachrichtigungszentrum und der LoxBerry-Healthcheck benutzen. Drei
       * Stellen, die dasselbe anders sagen, waeren zwei zu viel. */ ?>
<div class="<?= $dk_befund['schwere'] <= 4 ? 'sm-warnung' : 'sm-hinweis' ?>">
	<b><?= dk_e(dk_t('EINST.BEFUND')) ?>:</b> <?= dk_e($dk_befund['text']) ?></div>
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
	   href="<?= dk_e($dk_port_schema) ?>://<?= dk_e($dk_host) ?>:<?= (int) $dk_port ?>"><?= dk_e(dk_t('EINST.B_OEFFNEN')) ?></a>
</div>
<p class="sm-hilfe"><?= $dk_port_gemessen
	? sprintf(dk_t('EINST.PORT_GEMESSEN'), (int) $dk_port)
	: sprintf(dk_t('EINST.PORT_VERMUTET'), (int) $dk_port) ?></p>
<div class="sm-hinweis"><?= dk_t('EINST.KEIN_IFRAME') ?></div>

<h3><?= dk_e(dk_t('EINST.SETUPTOKEN')) ?></h3>
<p class="sm-hilfe"><?= dk_t('EINST.SETUPTOKEN_TEXT') ?></p>
<div class="sm-knopfreihe">
	<form action="index.php" method="post">
	<input data-role="none" type="hidden" name="fmt" value="<?= dk_e($dk_fmt) ?>">
		<input data-role="none" type="hidden" name="activetab" value="tab-settings">
		<input data-role="none" type="hidden" name="tokenzeigen" value="1">
		<button data-role="none" class="sm-btn sm-b-technik" type="submit"><?= dk_e(dk_t('EINST.B_SETUPZEIGEN')) ?></button>
	</form>
	<form action="index.php" method="post">
	<input data-role="none" type="hidden" name="fmt" value="<?= dk_e($dk_fmt) ?>">
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
<?php if ($dk_koll) { ?>
<?php /* Zwei Container, deren Namen nach der Saeuberung auf denselben
	   * Loxone-Schluessel fallen. Bis 1.2.3 geschah das lautlos: die
	   * Statuszeile trug den Schluessel zweimal, Loxone nahm das erste
	   * Vorkommen, der zweite Container war unbeobachtet. */ ?>
<div class="sm-warnung"><b><?= dk_e(dk_t('EINST.T_KOLLISION')) ?></b><br>
<?php foreach ($dk_koll as $dk_s => $dk_n) { ?>
<span class="sm-mono">C_<?= dk_e($dk_s) ?></span> &larr; <?= dk_e(implode(', ', $dk_n)) ?><br>
<?php } ?>
<span class="sm-hilfe"><?= dk_t('EINST.H_KOLLISION') ?></span></div>
<?php } ?>
<?php
/* Container der Wachliste, die es gar nicht (mehr) gibt. Das ist der Fall,
 * um dessentwillen es die Wachliste ueberhaupt gibt - er gehoert nach oben,
 * nicht ans Ende einer Tabelle. */
$dk_fehlende = array();
foreach ($dk_z['wache'] as $dk_wn => $dk_ww) { if ($dk_ww === -1) { $dk_fehlende[] = $dk_wn; } }
if ($dk_fehlende) { ?>
<div class="sm-warnung"><b><?= dk_e(dk_t('EINST.T_FEHLT')) ?></b>
<span class="sm-mono"><?= dk_e(implode(', ', $dk_fehlende)) ?></span><br>
<span class="sm-hilfe"><?= dk_t('EINST.H_FEHLT') ?></span></div>
<?php } ?>
<?php if ($dk_z['liste']) { ?>
<form action="index.php" method="post">
<input data-role="none" type="hidden" name="fmt" value="<?= dk_e($dk_fmt) ?>">
<input data-role="none" type="hidden" name="activetab" value="tab-settings">
<input data-role="none" type="hidden" name="wache_gesetzt" value="1">
<div class="sm-breit">
<table class="sm-tbl">
<tr><th><?= dk_e(dk_t('EINST.T_WACHE')) ?></th><th><?= dk_e(dk_t('EINST.T_NAME')) ?></th><th><?= dk_e(dk_t('EINST.T_ABBILD')) ?></th><th><?= dk_e(dk_t('EINST.T_STAND')) ?></th><th><?= dk_e(dk_t('EINST.T_GESUND')) ?></th><th><?= dk_e(dk_t('EINST.T_AUTOSTART')) ?></th><th><?= dk_e(dk_t('EINST.T_ZUSTAND')) ?></th></tr>
<?php foreach ($dk_z['liste'] as $dk_c) { ?>
<tr><td><input data-role="none" type="checkbox" name="wache[]" value="<?= dk_e($dk_c['name']) ?>"
	<?= ($dk_alle_ueberwacht || in_array($dk_c['name'], $dk_wache, true)) ? 'checked' : '' ?>></td>
	<td><span class="sm-mono"><?= dk_e($dk_c['name']) ?></span></td>
	<td><?= dk_e($dk_c['image']) ?><?php if (isset($dk_updates[$dk_c['name']]) && (int) $dk_updates[$dk_c['name']] === 1) { ?>
		<br><span class="sm-hilfe"><?= dk_e(dk_t('EINST.T_UPDATE')) ?></span><?php } ?></td>
	<td class="<?= $dk_c['laeuft'] ? 'sm-an' : ($dk_c['ausfall'] ? 'sm-aus' : '') ?>"><?= dk_e(dk_t('STAND.' . strtoupper($dk_c['zustand']))) ?></td>
	<td class="<?= $dk_c['gesund'] === 3 ? 'sm-aus' : ($dk_c['gesund'] === 2 ? 'sm-an' : '') ?>"><?= dk_e(dk_t('GESUND.G' . (int) $dk_c['gesund'])) ?></td>
	<td class="<?= $dk_c['autostart'] === 0 ? 'sm-aus' : '' ?>"><?= dk_e(dk_t('AUTOSTART.A' . (int) $dk_c['autostart'])) ?></td>
	<td><?= dk_e($dk_c['status']) ?></td></tr>
<?php } ?>
</table>
</div>
<div class="sm-feld">
	<label><input data-role="none" type="checkbox" name="wache_alle" value="1" <?= $dk_alle_ueberwacht ? 'checked' : '' ?>>
	<?= dk_e(dk_t('EINST.L_WACHE_ALLE')) ?></label>
	<p class="sm-hilfe"><?= dk_t('EINST.H_WACHE') ?></p>
</div>
<div class="sm-legende">
<span><i class="sm-punkt sm-b-aktion"></i><?= dk_e(dk_t('LEGENDE.AKTION_WACHE')) ?></span>
</div>
<div class="sm-knopfreihe">
	<button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="speichern" value="1"><?= dk_e(dk_t('EINST.B_WACHE')) ?></button>
</div>
</form>
<p class="sm-hilfe"><?= dk_t('EINST.CONTAINER_HINWEIS') ?></p>
<?php } else { ?>
<div class="sm-hinweis"><?= dk_t('EINST.KEINE_CONTAINER') ?></div>
<?php } ?>

<h2><?= dk_e(dk_t('EINST.KONFIG')) ?></h2>
<form action="index.php" method="post">
<input data-role="none" type="hidden" name="fmt" value="<?= dk_e($dk_fmt) ?>">
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
	<label for="schleife_grenze"><?= dk_e(dk_t('EINST.L_SCHLEIFE')) ?></label>
	<input data-role="none" type="text" id="schleife_grenze" name="schleife_grenze"
	       value="<?= dk_e($dk_cfg['schleife_grenze']) ?>" size="6">
	<p class="sm-hilfe"><?= dk_t('EINST.H_SCHLEIFE') ?></p>
</div>
<div class="sm-feld">
	<label for="platz_grenze_mb"><?= dk_e(dk_t('EINST.L_PLATZ')) ?></label>
	<input data-role="none" type="text" id="platz_grenze_mb" name="platz_grenze_mb"
	       value="<?= dk_e($dk_cfg['platz_grenze_mb']) ?>" size="8">
	<p class="sm-hilfe"><?= dk_t('EINST.H_PLATZ') ?></p>
</div>
<div class="sm-feld">
	<label><input data-role="none" type="checkbox" name="melden_aktiv" value="1" <?= $dk_cfg['melden_aktiv'] ? 'checked' : '' ?>>
	<?= dk_e(dk_t('EINST.L_MELDEN')) ?></label>
	<p class="sm-hilfe"><?= dk_t('EINST.H_MELDEN') ?></p>
</div>
<div class="sm-feld">
	<label><input data-role="none" type="checkbox" name="updates_aktiv" value="1" <?= $dk_cfg['updates_aktiv'] ? 'checked' : '' ?>>
	<?= dk_e(dk_t('EINST.L_UPDATES')) ?></label>
	<p class="sm-hilfe"><?= dk_t('EINST.H_UPDATES') ?></p>
</div>
<div class="sm-feld">
	<label><input data-role="none" type="checkbox" name="token_neu" value="1"> <?= dk_e(dk_t('EINST.L_TOKENNEU')) ?></label>
	<p class="sm-hilfe"><?= dk_t('EINST.H_TOKENNEU') ?></p>
</div>
<div class="sm-legende">
<span><i class="sm-punkt sm-b-aktion"></i><?= dk_e(dk_t('LEGENDE.AKTION_SPEICHERN')) ?></span>
</div>
<div class="sm-knopfreihe">
	<button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="speichern" value="1"><?= dk_e(dk_t('ALLGEMEIN.SPEICHERN')) ?></button>
</div>
</form>

<h2><?= dk_e(dk_t('EINST.ROTATION')) ?></h2>
<?php /* Der einzige Punkt in diesem Plugin, der einen LoxBerry unbootbar
       * machen kann: der json-file-Treiber von Docker hat als Vorgabe
       * max-size = -1, also unbegrenzt. Ein einziger gespraechiger Container
       * in einer Reconnect-Schleife schreibt die Speicherkarte voll. */ ?>
<?php if ($dk_rot['gesetzt'] === 1) { ?>
<div class="sm-hinweis"><?= sprintf(dk_t('EINST.ROTATION_JA'), dk_e($dk_rot['max']),
	dk_e($dk_rot['anzahl'] !== '' ? $dk_rot['anzahl'] : '1')) ?></div>
<?php } elseif ($dk_rot['gesetzt'] === 0) { ?>
<div class="sm-warnung"><?= dk_t('EINST.ROTATION_NEIN') ?></div>
<?php } else { ?>
<div class="sm-hinweis"><?= dk_t('EINST.ROTATION_UNKLAR') ?></div>
<?php } ?>

<h2><?= dk_t('EINST.H_SICHERUNG') ?></h2>
<div class="sm-hinweis"><?= dk_t('EINST.SICH_ERKLAERUNG') ?></div>
<div class="sm-warnung"><?= dk_t('EINST.SICH_WARNUNG') ?></div>
<div class="sm-knopfreihe">
  <!-- ZWEI GETRENNTE Formulare. Das Sichern schickt einen Download und ruft
       exit auf; das Zurueckspielen braucht enctype="multipart/form-data".
       Wer beides in ein Formular legt, bekommt entweder keinen Upload oder
       einen Download, der das Speichern verschluckt. -->
  <form action="index.php" method="post">
    <input data-role="none" type="hidden" name="activetab" value="tab-settings">
    <input data-role="none" type="hidden" name="fmt" value="<?= dk_e($dk_fmt) ?>">
    <button data-role="none" class="sm-btn sm-b-lesen" type="submit" name="dk_sichern" value="1"><?= dk_t('EINST.K_SICHERN') ?></button>
  </form>
  <form action="index.php" method="post" enctype="multipart/form-data">
    <input data-role="none" type="hidden" name="activetab" value="tab-settings">
    <input data-role="none" type="hidden" name="fmt" value="<?= dk_e($dk_fmt) ?>">
    <input data-role="none" type="file" name="dk_sicherung" accept=".json">
    <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="dk_zurueck" value="1"><?= dk_t('EINST.K_ZURUECK') ?></button>
  </form>
</div>
</div>

<!-- ======================= MQTT ======================= -->
<div class="sm-seite<?= $dk_tab === 'tab-mqtt' ? ' sm-active' : '' ?>" id="tab-mqtt">
<h2><?= dk_e(dk_t('MQTT.TITEL')) ?></h2>
<p class="sm-hilfe"><?= dk_t('MQTT.EINLEITUNG') ?></p>

<?php if (!$dk_mqttlage['gefunden']) { ?>
<div class="sm-warnung"><?= dk_t('MQTT.KEIN_GATEWAY') ?></div>
<?php } elseif (!$dk_mqttlage['autostart']) { ?>
<?php /* Gefragt wird 'Gatewayautostart'. 'Brokerhost' ist immer gesetzt und
	   * beantwortet die Frage nicht - eine Pruefung darauf waere immer gruen. */ ?>
<div class="sm-warnung"><?= dk_t('MQTT.KEIN_AUTOSTART') ?></div>
<?php } elseif (!$dk_mqttlage['udpport']) { ?>
<div class="sm-warnung"><?= dk_t('MQTT.KEIN_UDPPORT') ?></div>
<?php } else { ?>
<div class="sm-hinweis"><?= sprintf(dk_t('MQTT.GATEWAY_OK'), (int) $dk_mqttlage['udpport']) ?></div>
<?php } ?>

<!-- DREI AUSGAENGE, nicht einer. Bis 1.3.3 ging der Pflichtsatz fuer
     Gateway V1 unbedingt hinaus - auch an jede Anlage mit Fassung 2, wo
     nichts einzutragen ist und der LoxBerry-Kern das Eingabefeld abschaltet.
     Ist die Fassung nicht lesbar, stehen BEIDE Faelle da: einen von beiden zu
     behaupten waere fuer die Haelfte der Anlagen falsch. -->
<div class="sm-step"><b><?= dk_e(dk_t('MQTT.S1')) ?></b><br>
<?= sprintf(dk_t('MQTT.S1_EINLEITUNG'), dk_e($dk_cfg['mqtt_praefix'])) ?>
<?php $dk_gwf = (int) $dk_mqttlage['fassung']; ?>
<?php if ($dk_gwf >= 2) { ?>
<div class="sm-hinweis"><?= dk_t('MQTT.S1_V2') ?></div>
<?php } elseif ($dk_gwf === 1) { ?>
<div class="sm-warnung"><?= dk_t('MQTT.S1_V1') ?></div>
<?php } else { ?>
<div class="sm-warnung"><?= dk_t('MQTT.S1_V1') ?></div>
<div class="sm-hinweis"><?= dk_t('MQTT.S1_V2') ?></div>
<div class="sm-hilfe"><?= dk_t('MQTT.S1_UNBEKANNT') ?></div>
<?php } ?>
</div>

<form action="index.php" method="post">
<input data-role="none" type="hidden" name="fmt" value="<?= dk_e($dk_fmt) ?>">
<input data-role="none" type="hidden" name="activetab" value="tab-mqtt">
<div class="sm-feld">
	<label><input data-role="none" type="checkbox" name="mqtt_aktiv" value="1" <?= $dk_cfg['mqtt_aktiv'] ? 'checked' : '' ?>>
	<?= dk_e(dk_t('MQTT.L_AKTIV')) ?></label>
	<p class="sm-hilfe"><?= dk_t('MQTT.H_AKTIV') ?></p>
</div>
<div class="sm-feld">
	<label for="mqtt_praefix"><?= dk_e(dk_t('MQTT.L_PRAEFIX')) ?></label>
	<input data-role="none" type="text" id="mqtt_praefix" name="mqtt_praefix"
	       value="<?= dk_e($dk_cfg['mqtt_praefix']) ?>" size="24">
	<p class="sm-hilfe"><?= dk_t('MQTT.H_PRAEFIX') ?></p>
</div>
<div class="sm-legende">
<span><i class="sm-punkt sm-b-aktion"></i><?= dk_e(dk_t('LEGENDE.AKTION_SPEICHERN')) ?></span>
</div>
<div class="sm-knopfreihe">
	<button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="speichern_mqtt" value="1"><?= dk_e(dk_t('ALLGEMEIN.SPEICHERN')) ?></button>
</div>
</form>

<h2><?= dk_e(dk_t('MQTT.THEMEN')) ?></h2>
<p class="sm-hilfe"><?= dk_t('MQTT.THEMEN_TEXT') ?></p>
<?php /* Die Liste wird aus DEMSELBEN Aufruf erzeugt, den der Minutentakt zum
	   * Senden benutzt (dk_mqtt_themen). Eine Anleitung, die Themen nennt, die
	   * der Sendecode nie veroeffentlicht, schickt den Anwender in Loxone auf
	   * die Suche nach einem Wert, den es nicht gibt - deshalb keine zweite,
	   * von Hand gefuehrte Liste. */ ?>
<div class="sm-breit">
<table class="sm-tbl">
<tr><th><?= dk_e(dk_t('MQTT.T_THEMA')) ?></th><th><?= dk_e(dk_t('MQTT.T_WERT')) ?></th></tr>
<?php foreach (dk_mqtt_themen() as $dk_th => $dk_wt) { ?>
<tr><td><span class="sm-mono"><?= dk_e($dk_cfg['mqtt_praefix'] . '/' . $dk_th) ?></span></td>
	<td><?= dk_e($dk_wt) ?></td></tr>
<?php } ?>
</table>
</div>
<div class="sm-warnung"><?= dk_t('MQTT.ABO') ?></div>
</div>

<!-- ======================= Einbindung in Loxone ======================= -->
<div class="sm-seite<?= $dk_tab === 'tab-loxone' ? ' sm-active' : '' ?>" id="tab-loxone">
<h2><?= dk_e(dk_t('LOX.TITEL')) ?></h2>
<p class="sm-hilfe"><?= dk_t('LOX.EINLEITUNG') ?></p>

<div class="sm-step"><b><?= dk_e(dk_t('LOX.S1')) ?></b><br>
<?= dk_t('LOX.S1_TEXT') ?><br>
<span class="sm-mono"><?= dk_e($dk_schema) ?>://<?= dk_e($dk_hostport) ?><?= dk_e($dk_basis) ?>&amp;aktion=status</span>
<p class="sm-hilfe"><?= dk_t('LOX.S1_HINWEIS') ?><br>
<?php /* Diese Beispielzeile wird aus DEMSELBEN Bauplan erzeugt wie die echte
	   * Antwort (dk_beispielzeile in dk_lib.php). Bis 1.2.3 stand sie hier von
	   * Hand und war falsch: das Feld GRUND= fehlte, obwohl der Endpunkt es
	   * immer sendet - und Schritt 6 fordert den Anwender auf, die abgerufene
	   * Zeile mit "derselben Zeile wie oben" zu vergleichen. */ ?>
<span class="sm-mono"><?= dk_e(dk_beispielzeile()) ?></span></p>
</div>

<div class="sm-step"><b><?= dk_e(dk_t('LOX.S2')) ?></b><br>
<div class="sm-breit">
<table class="sm-tbl">
<tr><th><?= dk_e(dk_t('LOX.T_TITEL')) ?></th><th><?= dk_e(dk_t('LOX.T_ERKENNUNG')) ?></th><th><?= dk_e(dk_t('LOX.T_BEDEUTUNG')) ?></th></tr>
<?php /* Die Zeilen kommen aus derselben Liste, aus der auch die Importdatei
	   * gebaut wird - so koennen Anleitung und Vorlage nicht auseinanderlaufen. */ ?>
<?php foreach (dk_lox_felder() as $dk_f) { ?>
<tr><td>DOCKERNG_<?= dk_e($dk_f[0]) ?></td>
	<td><span class="sm-mono"><?= dk_e($dk_f[0]) ?>=\v</span></td>
	<td><?= dk_e($dk_f[1]) ?></td></tr>
<?php } ?>
<?php foreach ($dk_z['liste'] as $dk_c) { $dk_s = preg_replace('/[^A-Za-z0-9_]/', '_', $dk_c['name']); ?>
<tr><td>DOCKERNG_C_<?= dk_e($dk_s) ?></td>
	<td><span class="sm-mono">C_<?= dk_e($dk_s) ?>=\v</span></td>
	<td><?= dk_e(sprintf(dk_t('LOX.F_CONTAINER'), $dk_c['name'])) ?></td></tr>
<?php } ?>
</table>
</div>
<p class="sm-hilfe"><?= dk_t('LOX.S2_HINWEIS') ?></p>
<p class="sm-hilfe"><?= dk_t('LOX.S2_AUSFALL') ?></p>
</div>

<div class="sm-step"><b><?= dk_e(dk_t('LOX.S3')) ?></b><br>
<?= dk_t('LOX.S3_TEXT') ?>
<div class="sm-legende">
<span><i class="sm-punkt sm-b-technik"></i><?= dk_e(dk_t('LEGENDE.TECHNIK')) ?></span>
</div>
<div class="sm-knopfreihe" style="margin-top:10px;">
	<form action="index.php" method="post">
	<input data-role="none" type="hidden" name="fmt" value="<?= dk_e($dk_fmt) ?>">
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
<?php /* BERICHTIGT in 1.2.4: hier stand DOCKERNG_GESTOPPT. Dieser Wert zaehlt
	   * jeden je erzeugten und nicht entfernten Container mit, auch den
	   * planmaessig beendeten Sicherungscontainer - die Sammelstörung stand
	   * damit vom ersten Tag an dauerhaft an und verschluckte ueber #4 alle
	   * uebrigen Meldungen. AUSFALL laesst 'Exited (0)' und 'Created' aus. */ ?>
<tr><td>1</td><td><?= dk_e(dk_t('LOX.BS_VI')) ?></td><td>DOCKERNG_AUSFALL</td><td><?= dk_e(dk_t('LOX.BS_VI_P')) ?></td><td>&mdash;</td></tr>
<tr><td>2</td><td><?= dk_e(dk_t('LOX.BS_SWS')) ?></td><td>Container gestoert</td><td><?= dk_e(dk_t('LOX.BS_SWS_P')) ?></td><td>#1</td></tr>
<tr><td>3</td><td><?= dk_e(dk_t('LOX.BS_EIN')) ?></td><td>Meldung verzoegern</td><td><?= dk_e(dk_t('LOX.BS_EIN_P')) ?></td><td>#2</td></tr>
<tr><td>4</td><td><?= dk_e(dk_t('LOX.BS_ODER')) ?></td><td>Sammelstörung</td><td>&mdash;</td><td>#3<?= $dk_z['liste'] ? ', ' . dk_e(dk_t('LOX.BS_ODER_MEHR')) : '' ?></td></tr>
<tr><td>5</td><td><?= dk_e(dk_t('LOX.BS_BENACH')) ?></td><td>Docker-Störung</td><td><?= dk_e(dk_t('LOX.BS_BENACH_P')) ?></td><td>#4</td></tr>
<tr><td>6</td><td><?= dk_e(dk_t('LOX.BS_VI')) ?></td><td>DOCKERNG_OK</td><td><?= dk_e(dk_t('LOX.BS_VI_P')) ?></td><td>&mdash;</td></tr>
<tr><td>7</td><td><?= dk_e(dk_t('LOX.BS_NICHT')) ?></td><td>Docker antwortet nicht</td><td>&mdash;</td><td>#6</td></tr>
<?php /* ERGAENZT in 1.3.0: Bausteine 8 und 9. Bis 1.2.4 empfahl Schritt 5,
	   * auf einen Wertwechsel zu achten - und es gab keinen Wert, der sich
	   * zuverlaessig aendert. Die Empfehlung war mit den damaligen Feldern
	   * gar nicht umsetzbar. DOCKERNG_ZAEHLER aendert sich in JEDEM Takt. */ ?>
<tr><td>8</td><td><?= dk_e(dk_t('LOX.BS_VI')) ?></td><td>DOCKERNG_ZAEHLER</td><td><?= dk_e(dk_t('LOX.BS_VI_P')) ?></td><td>&mdash;</td></tr>
<tr><td>9</td><td><?= dk_e(dk_t('LOX.BS_AENDER')) ?></td><td>LoxBerry antwortet nicht</td><td><?= dk_e(dk_t('LOX.BS_AENDER_P')) ?></td><td>#8</td></tr>
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
<?php
/* Laeuft der Minutentakt noch?
 *
 * Das ist die wichtigste neue Zeile. Eine Prozessnummer beantwortet die Frage
 * nicht - ein Prozess kann dastehen und nichts mehr tun. Das ALTER der
 * Zustandsdatei beantwortet sie. Drei Ausgaenge, nicht zwei: nie gelaufen ist
 * etwas anderes als seit einer Stunde nicht mehr.
 */
?>
<tr><td><?= dk_e(dk_t('TEST.F_TAKT')) ?></td>
<?php if ($dk_alter < 0) { ?>
	<td class="sm-aus">&#10007; <?= dk_e(dk_t('TEST.A_TAKT_NIE')) ?></td>
<?php } elseif ($dk_alter <= 180) { ?>
	<td class="sm-an">&#10003; <?= dk_e(sprintf(dk_t('TEST.A_TAKT_OK'), $dk_alter)) ?></td>
<?php } else { ?>
	<td class="sm-aus">&#10007; <?= dk_e(sprintf(dk_t('TEST.A_TAKT_ALT'), (int) round($dk_alter / 60))) ?></td>
<?php } ?>
</tr>
<tr><td><?= dk_e(dk_t('TEST.F_HERZ')) ?></td>
	<td><?= isset($dk_zd['zaehler']) ? (int) $dk_zd['zaehler'] : '&mdash;' ?></td></tr>
<?php
/* Ist der MQTT-Weg vollstaendig? Der fehlende Eintrag <Thema>/# im Abo ist
 * der haeufigste Grund, warum am Miniserver nichts ankommt - deshalb steht er
 * als Warnung im Reiter MQTT und nicht nur hier. */
?>
<tr><td><?= dk_e(dk_t('TEST.F_MQTT')) ?></td>
<?php if (!$dk_cfg['mqtt_aktiv']) { ?>
	<td><?= dk_e(dk_t('TEST.A_MQTT_AUS')) ?></td>
<?php } elseif ($dk_mqttlage['udpport'] && $dk_mqttlage['autostart']) { ?>
	<td class="sm-an">&#10003; <?= dk_e(sprintf(dk_t('TEST.A_MQTT_OK'), (int) $dk_mqttlage['udpport'])) ?></td>
<?php } else { ?>
	<td class="sm-aus">&#10007; <?= dk_e(dk_t('TEST.A_MQTT_FEHLT')) ?></td>
<?php } ?>
</tr>
<?php
/* Kongruenzprobe fuer MQTT: nennt die Themenliste genau das, was der
 * Sendecode veroeffentlicht? Hier ist die Antwort bauartbedingt ja - beide
 * kommen aus dk_mqtt_themen(). Die Zeile zaehlt trotzdem nach und meldet die
 * ANZAHL: eine Pruefung ohne Fundstellen ist kein Nachweis, sondern ein
 * blinder Fleck. Und die leere Menge wird zuerst geprueft - "alle 0 von 0
 * sind in Ordnung" ist kein Haken.
 */
$dk_themen = dk_mqtt_themen();
?>
<tr><td><?= dk_e(dk_t('TEST.F_THEMEN')) ?></td>
	<td class="<?= count($dk_themen) > 0 ? 'sm-an' : 'sm-aus' ?>"><?= count($dk_themen) > 0
		? '&#10003; ' . dk_e(sprintf(dk_t('TEST.A_THEMEN_OK'), count($dk_themen)))
		: '&#10007; ' . dk_e(dk_t('TEST.A_THEMEN_LEER')) ?></td></tr>
<tr><td><?= dk_e(dk_t('TEST.F_ROTATION')) ?></td>
<?php if ($dk_rot['gesetzt'] === 1) { ?>
	<td class="sm-an">&#10003; <?= dk_e($dk_rot['max']) ?></td>
<?php } elseif ($dk_rot['gesetzt'] === 0) { ?>
	<td class="sm-aus">&#10007; <?= dk_e(dk_t('TEST.A_ROTATION_NEIN')) ?></td>
<?php } else { ?>
	<td><?= dk_e(dk_t('TEST.A_ROTATION_UNKLAR')) ?></td>
<?php } ?>
</tr>
<tr><td><?= dk_e(dk_t('TEST.F_BEFUND')) ?></td>
	<td class="<?= $dk_befund['schwere'] <= 4 ? 'sm-aus' : 'sm-an' ?>"><?= dk_e($dk_befund['text']) ?></td></tr>
<?php /* BERICHTIGT in 1.2.4: diese Zeile war fest auf sm-an verdrahtet und
	   * zeigte "24 Zeichen" auch dann, wenn sich die Konfiguration gar nicht
	   * schreiben liess und auf Platte kein Merkwort stand. Der Endpunkt
	   * antwortete folgerichtig 403, und bei jedem Seitenaufruf stand hier ein
	   * anderer Wert. Der Anwender jagte einem Merkwort nach, das es nie gab. */ ?>
<tr><td><?= dk_e(dk_t('TEST.F_TOKEN')) ?></td>
<?php if ($dk_token !== '') { ?>
	<td class="sm-an">&#10003; <?= (int) strlen($dk_token) ?> <?= dk_e(dk_t('TEST.ZEICHEN')) ?></td>
<?php } else { ?>
	<td class="sm-aus">&#10007; <?= dk_e(dk_t('TEST.A_KEIN_TOKEN')) ?></td>
<?php } ?>
</tr>
<?php
/* Ist die Konfiguration heil? Drei Ausgaenge, nicht zwei - "es gibt eine
 * Datei" ist keine Antwort auf "steht etwas Brauchbares darin". */
$dk_kroh = @is_file($dk_p['config']) ? (string) @file_get_contents($dk_p['config']) : '';
if (!@is_file($dk_p['config'])) {
    $dk_kklasse = 'sm-aus'; $dk_ktext = '&#10007; ' . dk_e(dk_t('TEST.A_KONFIG_FEHLT'));
} elseif (dk_konfig_taugt($dk_kroh)) {
    $dk_kklasse = 'sm-an';  $dk_ktext = '&#10003; ' . dk_e(dk_t('TEST.A_KONFIG_OK'));
} else {
    $dk_kklasse = 'sm-aus'; $dk_ktext = '&#10007; ' . dk_e(dk_t('TEST.A_KONFIG_KAPUTT'));
}
?>
<tr><td><?= dk_e(dk_t('TEST.F_KONFIG')) ?></td>
	<td class="<?= $dk_kklasse ?>"><?= $dk_ktext ?></td></tr>
<tr><td><?= dk_e(dk_t('TEST.F_SICHERUNG')) ?></td>
	<td class="<?= @is_file($dk_p['sicherung']) && dk_konfig_taugt(@file_get_contents($dk_p['sicherung'])) ? 'sm-an' : 'sm-aus' ?>"><?=
		@is_file($dk_p['sicherung']) && dk_konfig_taugt(@file_get_contents($dk_p['sicherung']))
		? '&#10003;' : '&#10007;' ?></td></tr>
<?php
/* Kongruenzprobe: nennt die Anleitung genau die Felder, die der Endpunkt
 * sendet? Bis 1.2.3 liefen die drei Listen auseinander - PORTAINER wurde
 * gesendet und stand nirgends, GRUND fehlte in der Beispielzeile. Geprueft
 * wird am Quelltext des Endpunkts, nicht an einer Vermutung.
 *
 * Geeicht wird die Zeile durch Rueckbau: nimmt man in html/index.php ein Feld
 * aus der sprintf-Zeile heraus, muss sie rot werden.
 */
$dk_ep = '';
foreach (array($dk_p['home'] . '/webfrontend/html/plugins/' . $dk_p['plugin'] . '/index.php',
               dirname(dirname(__DIR__)) . '/html/plugins/' . basename(__DIR__) . '/index.php',
               dirname(__DIR__) . '/html/index.php') as $dk_k) {
    if (@is_file($dk_k)) { $dk_ep = (string) @file_get_contents($dk_k); break; }
}
$dk_kongruent = -1; $dk_fehlend = array(); $dk_gesendet = array();
/* Die Formatzeichenkette des Endpunkts ist ueber mehrere Teilstuecke
 * verkettet. Ein Muster, das nur das ERSTE Anfuehrungszeichenpaar nimmt, misst
 * deshalb nur den halben Bauplan - und wird dadurch gruen, ohne etwas zu
 * pruefen. Genau das ist beim Bau der 1.3.0 passiert: die Zeile blieb gruen,
 * waehrend sechs neue Felder ausserhalb ihres Blickfelds lagen.
 *
 * Deshalb wird der GESAMTE erste Parameter von sprintf() genommen und daraus
 * jedes Teilstueck eingesammelt. Die Formatzeichenkette enthaelt kein Komma;
 * das erste Komma trennt sie sicher vom naechsten Parameter.
 */
if ($dk_ep !== '' && preg_match('/\$dk_zeile\s*=\s*sprintf\(([^,]*),/s', $dk_ep, $dk_tr)) {
    if (preg_match_all("/'([^']*)'/", $dk_tr[1], $dk_teile)) {
        $dk_formatzeile = implode('', $dk_teile[1]);
        if (preg_match_all('/;([A-Z_]+)=/', $dk_formatzeile, $dk_tr2)) { $dk_gesendet = $dk_tr2[1]; }
    }
    if ($dk_gesendet) {
        $dk_genannt = array('GRUND');
        foreach (dk_lox_felder() as $dk_f) { $dk_genannt[] = $dk_f[0]; }
        $dk_fehlend = array_diff($dk_gesendet, $dk_genannt);
        $dk_kongruent = $dk_fehlend ? 0 : 1;
    }
}
?>
<tr><td><?= dk_e(dk_t('TEST.F_KONGRUENZ')) ?></td>
<?php if ($dk_kongruent === 1) { ?>
	<td class="sm-an">&#10003; <?= dk_e(sprintf(dk_t("TEST.A_KONGRUENZ_OK"), count($dk_gesendet))) ?></td>
<?php } elseif ($dk_kongruent === 0) { ?>
	<td class="sm-aus">&#10007; <?= dk_e(sprintf(dk_t('TEST.A_KONGRUENZ_FEHLT'), implode(', ', $dk_fehlend))) ?></td>
<?php } else { ?>
	<td><?= dk_e(dk_t('TEST.A_KONGRUENZ_UNKLAR')) ?></td>
<?php } ?>
</tr>
<?php
/* Ist die erzeugbare Importdatei wohlgeformt? Geprueft wird die Datei, die der
 * Knopf im Reiter Loxone gerade ausliefern wuerde - nicht eine nachgebaute. */
$dk_xmlroh = dk_xml_virtual_in_http($dk_hostport, $dk_token !== '' ? $dk_token : 'x');
$dk_xmlalt = libxml_use_internal_errors(true);
$dk_xmlok  = simplexml_load_string($dk_xmlroh) !== false;
libxml_clear_errors();
libxml_use_internal_errors($dk_xmlalt);
?>
<tr><td><?= dk_e(dk_t('TEST.F_XML')) ?></td>
	<td class="<?= $dk_xmlok ? 'sm-an' : 'sm-aus' ?>"><?= $dk_xmlok
		? '&#10003; ' . dk_e(sprintf(dk_t('TEST.A_XML_OK'), substr_count($dk_xmlroh, '<VirtualInHttpCmd')))
		: '&#10007;' ?></td></tr>
<tr><td><?= dk_e(dk_t('TEST.F_KOLLISION')) ?></td>
	<td class="<?= $dk_koll ? 'sm-aus' : 'sm-an' ?>"><?= $dk_koll
		? '&#10007; ' . dk_e(implode(', ', array_keys($dk_koll)))
		: '&#10003; ' . dk_e(sprintf(dk_t('TEST.A_KOLLISION_OK'), count($dk_z['liste']))) ?></td></tr>
<?php
/* Antwortet der eigene Endpunkt WIRKLICH?
 *
 * Bis 1.3.0 bot dieser Reiter dafuer nur einen Link an, den der Anwender
 * selbst anklicken musste - das ist keine Pruefung, sondern eine Einladung zu
 * einer. Jetzt ein echter Aufruf mit drei Ausgaengen, gebremst auf 300
 * Sekunden. Der Knopf weiter unten misst neu.
 */
$dk_ep_probe = dk_endpunkt_probe(isset($_POST['ep_neu']));
?>
<tr><td><?= dk_e(dk_t('TEST.F_ENDPUNKT')) ?></td>
<?php if ((int) $dk_ep_probe['stand'] === 1) { ?>
	<td class="sm-an">&#10003; <?= dk_e($dk_ep_probe['text']) ?></td>
<?php } elseif ((int) $dk_ep_probe['stand'] === 0) { ?>
	<td class="sm-aus">&#10007; <?= dk_e($dk_ep_probe['text']) ?></td>
<?php } else { ?>
	<td><?= dk_e($dk_ep_probe['text']) ?></td>
<?php } ?>
</tr>
<?php
/* Setzt die Seite sm-active SERVERSEITIG - an der Leiste UND an den Bereichen?
 *
 * Diese Zeile ERSETZT eine Pruefung, die blind ist: hausstandard_pruefen.py
 * meldet bei zusammengesetzten Klassen "nicht pruefbar", und ein "nicht
 * pruefbar" liest sich beim Ueberfliegen wie ein Haken. Wer eine Pruefung
 * blind macht, ersetzt sie.
 *
 * Ohne serverseitiges sm-active waere die Seite ohne JavaScript vollstaendig
 * leer - jeder Bereich traegt display:none.
 */
$dk_quelle  = (string) @file_get_contents(__FILE__);
preg_match_all('/data-ziel="tab-([a-z]+)"/', $dk_quelle, $dk_zy);
$dk_anz     = count($dk_zy[1]);
$dk_leiste  = preg_match_all('/class="sm-tab<\?=[^>]*sm-active/', $dk_quelle);
$dk_bereich = preg_match_all('/class="sm-seite<\?=[^>]*sm-active/', $dk_quelle);
?>
<tr><td><?= dk_e(dk_t('TEST.F_SMACTIVE')) ?></td>
<?php if ($dk_anz > 0 && $dk_leiste >= $dk_anz && $dk_bereich >= $dk_anz) { ?>
	<td class="sm-an">&#10003; <?= dk_e(sprintf(dk_t('TEST.A_SMACTIVE_OK'), $dk_anz)) ?></td>
<?php } else { ?>
	<td class="sm-aus">&#10007; <?= dk_e(sprintf(dk_t('TEST.A_SMACTIVE_FEHLT'),
		$dk_leiste, $dk_bereich, $dk_anz)) ?></td>
<?php } ?>
</tr>
<?php
/* Tragen ALLE Formulare das Merkmal gegen fremde Formulare?
 *
 * Der Wachposten am Eingang nuetzt nichts, wenn ein Formular das Merkmal nicht
 * mitschickt - dann tut es einfach nichts mehr, und der Anwender sucht den
 * Fehler bei sich. Gezaehlt wird an der Quelle, Block fuer Block, und die
 * ZAHL wird genannt: eine Pruefung ohne Fundstellen ist kein Nachweis.
 */
$dk_form_a = 0; $dk_form_ohne = 0;
if (preg_match_all('/<form\s/', $dk_quelle, $dk_fy, PREG_OFFSET_CAPTURE)) {
    foreach ($dk_fy[0] as $dk_ff) {
        $dk_form_a++;
        $dk_ende = strpos($dk_quelle, '</form>', $dk_ff[1]);
        $dk_blk  = substr($dk_quelle, $dk_ff[1], ($dk_ende === false ? 400 : $dk_ende - $dk_ff[1]));
        if (strpos($dk_blk, 'name="fmt"') === false) { $dk_form_ohne++; }
    }
}
?>
<tr><td><?= dk_e(dk_t('TEST.F_CSRF')) ?></td>
<?php if ($dk_form_a > 0 && $dk_form_ohne === 0) { ?>
	<td class="sm-an">&#10003; <?= dk_e(sprintf(dk_t('TEST.A_CSRF_OK'), $dk_form_a)) ?></td>
<?php } elseif ($dk_form_a === 0) { ?>
	<td class="sm-aus">&#10007; <?= dk_e(dk_t('TEST.A_CSRF_LEER')) ?></td>
<?php } else { ?>
	<td class="sm-aus">&#10007; <?= dk_e(sprintf(dk_t('TEST.A_CSRF_FEHLT'), $dk_form_ohne, $dk_form_a)) ?></td>
<?php } ?>
</tr>
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
	<form action="index.php" method="post">
<input data-role="none" type="hidden" name="fmt" value="<?= dk_e($dk_fmt) ?>">
		<input data-role="none" type="hidden" name="activetab" value="tab-test">
		<button data-role="none" class="sm-btn sm-b-technik" type="submit" name="ep_neu" value="1"><?= dk_e(dk_t('TEST.B_EP_NEU')) ?></button>
	</form>
</div>
<p class="sm-hilfe"><?= dk_t('TEST.H_OHNETOKEN') ?></p>
<p class="sm-hilfe"><?= dk_t('TEST.H_EP_NEU') ?></p>

<?php /* Das Protokoll eines beliebigen Containers - rein lesend, im
	   * angemeldeten Bereich. Bis 1.2.4 gab es das nur fuer Portainer,
	   * obwohl der Aufruf fuer jeden Container derselbe ist. */ ?>
<h3><?= dk_e(dk_t('TEST.G_CLOG')) ?></h3>
<p class="sm-hilfe"><?= dk_t('TEST.H_CLOG') ?></p>
<?php if ($dk_z['liste']) { ?>
<form action="index.php" method="post">
<input data-role="none" type="hidden" name="fmt" value="<?= dk_e($dk_fmt) ?>">
<input data-role="none" type="hidden" name="activetab" value="tab-test">
<div class="sm-feld">
	<label for="clog_name"><?= dk_e(dk_t('TEST.L_CLOG')) ?></label>
	<select data-role="none" id="clog_name" name="clog_name">
<?php foreach ($dk_z['liste'] as $dk_c) { ?>
		<option value="<?= dk_e($dk_c['name']) ?>"<?= $dk_cname === $dk_c['name'] ? ' selected' : '' ?>><?= dk_e($dk_c['name']) ?></option>
<?php } ?>
	</select>
</div>
<div class="sm-knopfreihe">
	<button data-role="none" class="sm-btn sm-b-technik" type="submit" name="containerlog" value="1"><?= dk_e(dk_t('TEST.B_CLOG')) ?></button>
</div>
</form>
<?php if ($dk_clog !== '') { ?>
<p class="sm-hilfe"><span class="sm-mono"><?= dk_e($dk_cname) ?></span></p>
<pre class="sm-pre"><?= dk_e($dk_clog) ?></pre>
<?php } ?>
<?php } else { ?>
<div class="sm-hinweis"><?= dk_t('EINST.KEINE_CONTAINER') ?></div>
<?php } ?>

<h3><?= dk_e(dk_t('TEST.G_AKTION')) ?></h3>
<p class="sm-hilfe"><?= dk_t('TEST.H_AKTION') ?></p>
<?php if ($dk_takt_ergebnis !== null) { ?>
<div class="sm-hinweis"><?= dk_e(sprintf(dk_t('TEST.TAKT_ERGEBNIS'),
	(int) $dk_takt_ergebnis['zaehler'], (int) $dk_takt_ergebnis['schleife'],
	(int) $dk_takt_ergebnis['mqtt'])) ?></div>
<?php } ?>
<div class="sm-knopfreihe">
	<?php /* Die Hausregel verlangt, jeden Cron-Dienst nach der Installation
		   * einmal von Hand zu starten und das Ergebnis anzusehen. Dieser Knopf
		   * ersetzt den Gang auf die Kommandozeile. */ ?>
	<form action="index.php" method="post">
	<input data-role="none" type="hidden" name="fmt" value="<?= dk_e($dk_fmt) ?>">
		<input data-role="none" type="hidden" name="activetab" value="tab-test">
		<button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="takt_jetzt" value="1"><?= dk_e(dk_t('TEST.B_TAKT')) ?></button>
	</form>
	<form action="index.php" method="post">
	<input data-role="none" type="hidden" name="fmt" value="<?= dk_e($dk_fmt) ?>">
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
	<input data-role="none" type="hidden" name="fmt" value="<?= dk_e($dk_fmt) ?>">
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
