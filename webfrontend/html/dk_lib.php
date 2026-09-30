<?php
/**
 * Docker NG - gemeinsame Bibliothek
 *
 * Pfade, Konfiguration, Merkwort, Sprache, Docker- und Portainer-Zugriff
 * sowie die Loxone-Importvorlage.
 *
 * Sie liegt unter html/ und NICHT unter htmlauth/, weil der Loxone-Endpunkt
 * sie ebenfalls braucht. Die Oberflaeche laedt sie von dort - eine zweite
 * Kopie waere die haeufigste Ursache dafuer, dass zwei Dateien gleichen
 * Namens auseinanderlaufen.
 *
 * Alle Bezeichner tragen das Kuerzel dk_, weil LBWeb::lbheader() eigene globale
 * Variablen setzt und es sonst zu Namenskollisionen kommt.
 */

/* ---------------- Pfade ----------------
 *
 * Der Pluginordner wird NICHT fest verdrahtet, sondern aus dem Ablageort
 * abgeleitet. Der MD5-Schluessel in der plugindatabase.json haengt an
 * Autorenname, E-Mail und Plugin-Name - wer ihn fest einbaut, bricht bei jedem
 * Fork. Der Ordnername dagegen steht fest.
 */

/* Den LoxBerry-Wurzelordner ohne festen Systempfad bestimmen.
 *
 * Vom eigenen Ablageort aufwaerts, bis ein Verzeichnis gefunden ist, das
 * config/plugins UND webfrontend enthaelt. Das trifft die uebliche
 * Installation genauso wie eine an einem anderen Ort - und es trifft auch
 * den Fall, dass das Plugin noch als entpacktes Archiv daliegt (dann findet
 * es nichts und gibt einen Leerstring zurueck, was der Aufrufer ohnehin
 * abfangen muss).
 *
 * Der Name traegt kein Plugin-Kuerzel und ist deshalb abgesichert: zwei
 * Bibliotheken landen nie im selben Prozess, aber die Pruefung kostet nichts.
 */
if (!function_exists('lb_wurzel_ermitteln')) {
    function lb_wurzel_ermitteln()
    {
        $d = __DIR__;
        for ($i = 0; $i < 8; $i++) {
            if (is_dir($d . '/config/plugins') && is_dir($d . '/webfrontend')) {
                return $d;
            }
            $eltern = dirname($d);
            if ($eltern === $d) { break; }
            $d = $eltern;
        }
        return '';
    }
}

function dk_paths()
{
    static $p = null;
    if ($p !== null) { return $p; }

    $home = getenv('LBHOMEDIR');
    if (!$home || !is_dir($home)) {
        foreach (array(lb_wurzel_ermitteln(), '/home/loxberry/loxberry') as $k) {
            if (is_dir($k)) { $home = $k; break; }
        }
        if (!$home) { $home = lb_wurzel_ermitteln(); }
    }

    /* Der Ordnername ergibt sich aus dem ABLAGEORT dieser Datei:
     *   <home>/webfrontend/html/plugins/<ordner>/dk_lib.php
     * Bis 1.1.0 stand hier fest 'dockerng' - der Kommentar darueber behauptete
     * schon damals die Ableitung, der Code machte sie nicht. Bei einem Fork
     * mit anderem Ordnernamen zeigten dann alle Pfade ins Leere.
     */
    $ordner = basename(dirname(__FILE__));
    if (!is_dir($home . '/config/plugins/' . $ordner)) {
        foreach (array(getenv('LBPPLUGINDIR'), 'dockerng') as $kand) {
            if ($kand && is_dir($home . '/config/plugins/' . $kand)) { $ordner = $kand; break; }
        }
    }

    /* data/ liegt - anders als log/ - NICHT auf der Ramdisk und uebersteht
     * deshalb einen Neustart. Dorthin gehoert der Zustand, den der Minutentakt
     * fortschreibt: Herzschlag, Neustartzaehler, Plattenbelegung.
     *
     * Bewusst KEINE Zweitschrift daneben: der Installer loescht
     * data/plugins/<ordner>/ vor jedem postinstall.sh, und alles darin ist neu
     * erzeugbar. Fuer das Merkwort waere das falsch - deshalb liegt die
     * Konfiguration weiterhin unter config/ und ihre Sicherung daneben.
     */
    $p = array(
        'home'      => $home,
        'plugin'    => $ordner,
        'config'    => $home . '/config/plugins/' . $ordner . '/dockerng.json',
        'configdir' => $home . '/config/plugins/' . $ordner,
        'sicherung' => $home . '/config/plugins/' . $ordner . '.backup.json',
        'logdir'    => $home . '/log/plugins/' . $ordner,
        'log'       => $home . '/log/plugins/' . $ordner . '/dockerng.log',
        // Fehlerausgabe des Minutentakts, umgelenkt in cron/cron.01min.
        'cronerr'   => $home . '/log/plugins/' . $ordner . '/cron.err',
        'datadir'   => $home . '/data/plugins/' . $ordner,
        'zustand'   => $home . '/data/plugins/' . $ordner . '/zustand.json',
        // Upgrade-Marke (Entscheidung 1, I1): NEBEN dem Datenordner, sonst
        // loescht purge_installation sie mit. preupgrade.sh legt sie als
        // Erstes an, postinstall.sh raeumt sie ab.
        'marke'       => $home . '/data/plugins/' . $ordner . '.upgrade_laeuft',
        // Zweitschrift des Einrichtungstokens von Portainer (I5), 0600, neben
        // dem Konfigurationsordner - der Ordner selbst faellt bei jedem Upgrade.
        'setup_zweit' => $home . '/config/plugins/' . $ordner . '.backup.setup_token',
        // Abodatei fuer das MQTT-Gateway V1 (M7). Das Gateway liest sie selbst.
        'abo'         => $home . '/config/plugins/' . $ordner . '/mqtt_subscriptions.cfg',
        // Vorgemerkte Themen zum Abraeumen (M4), abgearbeitet vom Minutentakt.
        'abraeumen'   => $home . '/data/plugins/' . $ordner . '/mqtt_abraeumen.json',
    );
    return $p;
}

/* ---------------- Konfiguration ---------------- */

/**
 * Vorgaben.
 *
 * ALLE in 1.3.0 hinzugekommenen Funktionen stehen ab Werk AUS. Ein
 * Vorgabewert, der beim ersten Lauf ungefragt schaltet oder ungefragt ins
 * Netz geht, ist ein Fehler - wer aktualisiert, bekommt sonst Verhalten, um
 * das er nicht gebeten hat.
 *
 * 'wachliste' leer heisst: alle gefundenen Container. Das ist genau das
 * Verhalten bis 1.2.4 und damit die vertraegliche Vorgabe.
 */
function dk_vorgaben()
{
    return array(
        'portainer_port'   => 9000,
        // HTTPS-Port des eigenen Portainer (C12, neu in 1.3.9). postroot.sh
        // legt den Container mit beiden Ports an; bis dahin standen 9000
        // und 9443 fest im Skript.
        'portainer_https_port' => 9443,
        /* 'portainer_name' ENTFAELLT seit 1.3.9 (C1, Entscheidung 9). Den
         * eigenen Container erkennt das Plugin am Label, im Altbestand am
         * Namen portainer UND am Abbild portainer/portainer-*. Ein frei
         * einstellbarer Name hatte danach keinen Zweck mehr - er war nur
         * noch der Weg, auf dem ein fremder Container (gemessen: das
         * MG-Gateway) neu gestartet und bei der Deinstallation geloescht
         * wurde. dk_config_vervollstaendigen() nimmt ihn einmal aus der
         * Datei, mit Protokollzeile. */
        'aktionstoken'     => '',
        // A1 - Wachliste. Leer = alle Container.
        'wachliste'        => array(),
        // C1 - MQTT ueber den LoxBerry-Gateway.
        'mqtt_aktiv'       => 0,
        'mqtt_praefix'     => 'dockerng',
        // B3 - Benachrichtigungszentrum von LoxBerry.
        'melden_aktiv'     => 0,
        // C2 - Neustartschleifen. Ab wie vielen Neustarts je Stunde?
        'schleife_grenze'  => 3,
        // D4 - Abbild-Aktualisierungen taeglich pruefen.
        'updates_aktiv'    => 0,
        // B2 - Warnschwelle fuer den freien Platz in MB. 0 = keine Meldung.
        'platz_grenze_mb'  => 512,
    );
}

function dk_json_lesen($pfad)
{
    if (!@is_file($pfad)) { return array(); }
    $roh = @file_get_contents($pfad);
    if ($roh === false || trim($roh) === '') { return array(); }
    $d = json_decode($roh, true);
    return is_array($d) ? $d : array();
}

/**
 * Unteilbar schreiben - und die Rechte gehoeren an das ANLEGEN, nicht hinterher.
 *
 * Bis 1.2.3 stand hier file_put_contents() und danach ein chmod. Damit lag die
 * Datei fuer die Dauer des Schreibens mit den Vorgaben der umask da - also
 * 0644 - und in ihr steht das Merkwort fuer den unangemeldeten Endpunkt.
 *
 * Der zweite, schwerere Grund ist der Abbruch mittendrin: file_put_contents
 * hinterlaesst dann eine halb geschriebene Datei. Genau die hat bis 1.2.3 die
 * Selbstheilung unten ausgehebelt (siehe dort). Ueber eine Nebendatei mit
 * anschliessendem rename() gibt es diesen Zwischenzustand nicht: entweder
 * steht die alte Fassung da oder die neue, nie eine halbe.
 *
 * Die Nebendatei traegt die Prozessnummer im Namen, sonst zerlegen zwei
 * gleichzeitige Schreiber einander die Nebendatei.
 */
function dk_json_schreiben($pfad, $daten, $rechte = 0600)
{
    $js = json_encode($daten, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($js === false) { return false; }
    return dk_datei_schreiben($pfad, $js, $rechte);
}

/**
 * Den eigentlichen Schreibweg teilen sich JSON und rohe Inhalte (C8): die
 * Selbstheilung schrieb bis 1.3.9 mit copy() und setzte die Rechte erst
 * danach - die Konfiguration mit dem Merkwort lag fuer diesen Moment mit den
 * Rechten der umask da. Jetzt gilt auch dort: Nebendatei mit Prozessnummer,
 * Rechte vor dem Inhalt, Laenge nachzaehlen, rename().
 */
function dk_datei_schreiben($pfad, $inhalt, $rechte = 0600)
{
    $inhalt = (string) $inhalt;
    $tmp = $pfad . '.tmp.' . getmypid();
    $fh = @fopen($tmp, 'c');
    if ($fh === false) { return false; }
    @chmod($tmp, $rechte);                       // schuetzen, BEVOR Inhalt hineinkommt
    // Gegen strlen() vergleichen, nicht gegen === false: eine kurze Schreibung
    // ist genauso kaputt wie gar keine.
    $ok = ftruncate($fh, 0) && (fwrite($fh, $inhalt) === strlen($inhalt));
    fflush($fh);
    fclose($fh);
    if (!$ok) { @unlink($tmp); return false; }
    if (!@rename($tmp, $pfad)) { @unlink($tmp); return false; }
    return true;
}

/** Taugt dieser Dateiinhalt als Konfiguration? Entscheidend ist das Merkwort. */
function dk_konfig_taugt($roh)
{
    if (!is_string($roh)) { return false; }
    $roh = trim($roh);
    if ($roh === '' || $roh === '{}') { return false; }
    $d = json_decode($roh, true);
    if (!is_array($d)) { return false; }
    /* is_string() ZUERST (C2, I6). Bis 1.3.9 stand hier
     * trim((string) $d['aktionstoken']): eine Liste wurde zur Zeichenkette
     * "Array", galt als Merkwort, und preupgrade.sh ueberschrieb damit die
     * gute Zweitschrift (in WSL gemessen, Installer-Pruefer Fall E3). */
    return isset($d['aktionstoken']) && is_string($d['aktionstoken'])
        && trim($d['aktionstoken']) !== '';
}

/* ---------------- Wertpruefung ----------------
 *
 * EINE Stelle fuer die Muster, die Formular, Sicherung, Endpunkt,
 * Oberflaeche und die Hakenskripte gemeinsam benutzen (C2, C6).
 * Jedes Muster endet mit \z, nicht mit $ - '$' liesse einen Zeilenumbruch
 * am Ende durch (Regeln/05).
 */

/** Containername nach dem Muster, das Docker selbst vergibt und annimmt. */
function dk_name_gueltig($n)
{
    return is_string($n) && preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]{0,63}\z/', $n) === 1;
}

/** Ganze Zahl aus Formular oder Datei in [min, max], sonst null. Nie gerundet. */
function dk_ganzzahl($w, $min, $max)
{
    if (is_int($w)) {
        $n = $w;
    } elseif (is_string($w) && preg_match('/^[0-9]{1,9}\z/', $w)) {
        $n = (int) $w;
    } else {
        return null;
    }
    return ($n >= $min && $n <= $max) ? $n : null;
}

/** Schluessel, die eine fruehere Fassung schrieb und die keinen Zweck mehr haben. */
function dk_veraltete_schluessel()
{
    return array('portainer_name');
}

/**
 * Einen Wert der Sicherungsdatei pruefen - mit denselben Grenzen wie das
 * Formular (C2, Bauart E).
 * Rueckgabe array(ok, Normalform, Beschreibung des Mangels).
 */
function dk_wert_pruefen($k, $w)
{
    switch ($k) {
        case 'portainer_port':
            // Seit 1.3.9 legt postroot.sh den Container mit diesem Port an
            // (C12) - Ports unter 1024 sind dem System vorbehalten.
            $n = dk_ganzzahl($w, 1024, 65535);
            return array($n !== null, $n, dk_t('FEHLER.PORT'));
        case 'portainer_https_port':
            $n = dk_ganzzahl($w, 1024, 65535);
            return array($n !== null, $n, dk_t('FEHLER.PORT_HTTPS'));
        case 'aktionstoken':
            /* is_string ZUERST: eine Liste ist kein leeres Token und kein
             * "Array", sondern ein unzulaessiger Wert. Leer heisst "kein
             * Token gesichert" (Regeln/05, VolkswagenID 0.9.12) - das
             * entscheidet dk_sicherung_lesen(). Sonst mindestens zwoelf
             * Zeichen aus dem, was ohne Kodierung in eine Adresse passt:
             * dk_token_neu() erzeugt 24, und ein Wort wie "Array" darf nie
             * als Merkwort gelten. */
            if (!is_string($w)) {
                return array(false, null, dk_t('EINST.SICH_TOKEN_TYP'));
            }
            if ($w === '') { return array(true, '', ''); }
            $ok = preg_match('/^[A-Za-z0-9_.\-]{12,64}\z/', $w) === 1;
            return array($ok, $w, dk_t('EINST.SICH_TOKEN_FORM'));
        case 'wachliste':
            if (!is_array($w)) { return array(false, null, dk_t('FEHLER.NAME')); }
            $aus = array();
            foreach ($w as $n) {
                if (!dk_name_gueltig($n)) { return array(false, null, dk_t('FEHLER.NAME')); }
                if (!in_array($n, $aus, true)) { $aus[] = $n; }
            }
            return array(true, $aus, '');
        case 'mqtt_aktiv':
        case 'melden_aktiv':
        case 'updates_aktiv':
            $ok = in_array($w, array(0, 1, '0', '1'), true);
            return array($ok, $ok ? (int) $w : null, dk_t('EINST.SICH_SCHALTER'));
        case 'mqtt_praefix':
            $ok = is_string($w) && preg_match('/^[A-Za-z0-9_\-]{1,32}\z/', $w) === 1;
            return array($ok, $w, dk_t('FEHLER.MQTT_PRAEFIX'));
        case 'schleife_grenze':
            $n = dk_ganzzahl($w, 1, 100);
            return array($n !== null, $n, dk_t('FEHLER.SCHLEIFE_GRENZE'));
        case 'platz_grenze_mb':
            $n = dk_ganzzahl($w, 0, 1048576);
            return array($n !== null, $n, dk_t('FEHLER.PLATZ_GRENZE'));
    }
    return array(false, null, '');
}

/* Der Zwischenspeicher liegt in einer eigenen Funktion, damit
 * dk_config_schreiben() ihn nachziehen kann.
 *
 * Bis 1.2.3 war es ein 'static' in dk_config() selbst. Nach dem Speichern
 * arbeitete deshalb jede Funktion, die dk_config() erneut aufrief, im selben
 * Aufruf mit dem ALTEN Stand weiter - dk_portainer_laeuft() fragte nach dem
 * alten Containernamen, waehrend das Eingabefeld darueber schon den neuen
 * zeigte. Die Seite widersprach sich genau in dem Moment, in dem der Anwender
 * pruefte, ob seine Aenderung gewirkt hat.
 */
function dk_config_speicher($neu = null)
{
    static $cfg = null;
    if ($neu !== null) { $cfg = $neu; }
    return $cfg;
}

function dk_config_normieren($cfg)
{
    // Grenzen durchsetzen, statt Werte ungeprueft weiterzureichen.
    $cfg = array_merge(dk_vorgaben(), is_array($cfg) ? $cfg : array());
    $port = is_scalar($cfg['portainer_port']) ? (int) $cfg['portainer_port'] : 9000;
    $cfg['portainer_port'] = max(1, min(65535, $port));
    // C12: ein unbrauchbarer HTTPS-Port in der Datei gilt als nicht gesetzt.
    $sport = is_scalar($cfg['portainer_https_port']) ? (int) $cfg['portainer_https_port'] : 0;
    $cfg['portainer_https_port'] = ($sport >= 1 && $sport <= 65535) ? $sport : 9443;

    /* is_string statt (string) (C2). Bis 1.3.9 wurde eine Liste hier zu
     * "Array" - der Endpunkt nahm danach ?token=Array an, und das daraus
     * abgeleitete Formularmerkmal war fuer jeden ausrechenbar (gemessen unter
     * PHP 7.4, 8.4 und 8.5). Ein Nicht-Text gilt jetzt als "kein Token". */
    if (!is_string($cfg['aktionstoken'])) {
        dk_log_gebremst('token_kein_text', 'Das Aktionstoken in der Konfiguration ist keine '
            . 'Zeichenkette (' . gettype($cfg['aktionstoken']) . ') - es gilt als nicht gesetzt.');
        $cfg['aktionstoken'] = '';
    }

    /* Die Wachliste kommt aus einer Datei und ist damit fremdbestimmt: was
     * nicht ins Muster passt, faellt heraus - nicht zurechtgebogen, denn ein
     * gekuerzter Name faende den Container nicht und meldete "fehlt". */
    $wache = array();
    foreach ((is_array($cfg['wachliste']) ? $cfg['wachliste'] : array()) as $w) {
        if (dk_name_gueltig($w) && !in_array($w, $wache, true)) {
            $wache[] = $w;
        }
    }
    $cfg['wachliste'] = $wache;

    $cfg['mqtt_aktiv']    = !empty($cfg['mqtt_aktiv']) ? 1 : 0;
    $cfg['melden_aktiv']  = !empty($cfg['melden_aktiv']) ? 1 : 0;
    $cfg['updates_aktiv'] = !empty($cfg['updates_aktiv']) ? 1 : 0;

    /* Das MQTT-Praefix landet in Themen. Der Gateway ersetzt darin nur / und %
     * durch Unterstrich - Punkte bleiben stehen. Deshalb hier ein enges
     * Muster statt einer Ersetzung. */
    $prae = is_string($cfg['mqtt_praefix']) ? trim($cfg['mqtt_praefix']) : '';
    $cfg['mqtt_praefix'] = preg_match('/^[A-Za-z0-9_\-]{1,32}\z/', $prae) ? $prae : 'dockerng';

    $cfg['schleife_grenze'] = max(1, min(100, is_scalar($cfg['schleife_grenze']) ? (int) $cfg['schleife_grenze'] : 3));
    $cfg['platz_grenze_mb'] = max(0, min(1048576, is_scalar($cfg['platz_grenze_mb']) ? (int) $cfg['platz_grenze_mb'] : 512));

    // Veraltete Schluessel gehoeren nicht in den Arbeitsstand (C1).
    foreach (dk_veraltete_schluessel() as $k) { unset($cfg[$k]); }
    return $cfg;
}

function dk_config($heilen = true)
{
    $gemerkt = dk_config_speicher();
    if ($gemerkt !== null) { return $gemerkt; }
    $p = dk_paths();

    /* Selbstheilung. Der Konfigurationsordner eines Plugins ist beim
     * Neuinstallieren weg, bevor irgendein Skript des Plugins laeuft - und mit
     * ihm das Merkwort, das in den Adressen im Miniserver steckt. Der
     * virtuelle Eingang bekommt danach nur noch 403, ohne erkennbaren Anlass.
     *
     * Die Sicherung liegt deshalb NEBEN dem Ordner, nicht darin:
     *     config/plugins/<ordner>.backup.json   statt
     *     config/plugins/<ordner>/…
     * Ein Geschwister des Ordners uebersteht dessen Loeschung.
     *
     * BERICHTIGT in 1.2.4: entschieden wird nicht an der Form des Textes,
     * sondern daran, ob ein Merkwort darin steht (dk_konfig_taugt). Eine
     * beschaedigte Datei wird vor dem Ueberschreiben zur Seite gelegt.
     *
     * NEU in 1.3.9 (C8): $heilen = false fuer den UNANGEMELDETEN Endpunkt.
     * Er rief bis 1.3.9 dk_config() vor der Tokenpruefung - ein Aufruf mit
     * falschem Token legte die Konfiguration aus der Zweitschrift neu an
     * (gemessen: 403, danach dockerng.json 0600 und eine Protokollzeile).
     * Jetzt liest der Endpunkt die Zweitschrift nur, schreibt nichts und
     * protokolliert nichts; geheilt wird ueber die Oberflaeche und den
     * Minutentakt. Geschrieben wird ueber dk_datei_schreiben(), nicht mehr
     * ueber copy() - Rechte vor dem Inhalt.
     *
     * Die LAGE wird gemerkt, BEVOR geheilt wird (O4, Regeln/05): die
     * Pruefzeile im Reiter Test sah bis 1.3.9 nur die schon geheilte Datei.
     */
    $roh   = @is_file($p['config']) ? @file_get_contents($p['config']) : false;
    $roh   = is_string($roh) ? $roh : '';
    $taugt = dk_konfig_taugt($roh);
    $bkroh = @is_file($p['sicherung']) ? @file_get_contents($p['sicherung']) : false;
    $bkroh = is_string($bkroh) ? $bkroh : '';
    $bk_taugt = dk_konfig_taugt($bkroh);
    $leer  = (trim($roh) === '' || trim($roh) === '{}');

    if (!@is_file($p['config'])) { $lage = 'fehlt'; }
    elseif ($taugt)              { $lage = 'ok'; }
    elseif ($leer)               { $lage = 'leer'; }
    else                         { $lage = 'kaputt'; }

    $quelle = $roh;
    if (!$taugt && $bk_taugt) {
        $quelle = $bkroh;
        if (!$heilen) {
            $lage .= '_zweitschrift_gelesen';
        } else {
            if (!@is_dir($p['configdir'])) { @mkdir($p['configdir'], 0755, true); }
            // Beschaedigtes NICHT wegwerfen: darin koennen Einstellungen
            // stehen, die die Sicherung noch nicht kennt.
            if (!$leer) {
                $beiseite = $p['config'] . '.kaputt';
                if (dk_datei_schreiben($beiseite, $roh, 0600)) {
                    dk_log('Die Konfiguration war unbrauchbar (kein Merkwort lesbar). Sie liegt '
                        . 'zur Ansicht unter ' . $beiseite . '.');
                }
            }
            if (dk_datei_schreiben($p['config'], $bkroh, 0600)) {
                $lage .= '_geheilt';
                dk_log('Konfiguration aus der Sicherung ' . $p['sicherung']
                    . ' wiederhergestellt. Das Merkwort fuer den Endpunkt bleibt damit gueltig.');
            } else {
                $lage .= '_nicht_geheilt';
                dk_log_gebremst('heilen_schreiben', 'Die Konfiguration liess sich aus der Sicherung '
                    . 'nicht zurueckschreiben: ' . $p['config']);
            }
        }
    } elseif (!$taugt && !$leer) {
        $lage = 'kaputt_ohne_zweitschrift';
        if ($heilen) {
            // Kaputt UND keine brauchbare Sicherung. Dann wird gleich ein neues
            // Merkwort entstehen - das gehoert benannt, nicht verschwiegen.
            dk_log('Die Konfiguration ist unbrauchbar und es gibt keine verwertbare '
                . 'Sicherung. Es wird ein NEUES Merkwort erzeugt; alle Adressen im '
                . 'Miniserver muessen danach nachgezogen werden.');
        }
    }
    dk_konfig_lage($lage);

    $d = json_decode($quelle, true);
    $gemerkt = dk_config_normieren(is_array($d) ? $d : array());
    dk_config_speicher($gemerkt);
    return $gemerkt;
}

/**
 * Die Lage der Konfiguration beim ERSTEN Lesen dieses Aufrufs (O4).
 * ok | fehlt | leer | kaputt | kaputt_ohne_zweitschrift, bei den ersten drei
 * ggf. mit _geheilt, _nicht_geheilt oder _zweitschrift_gelesen. Ein spaeteres
 * 'ok' ueberschreibt den ersten Stand nicht - ein geheilter Schaden ist kein
 * Nicht-Schaden (Regeln/05).
 */
function dk_konfig_lage($neu = null)
{
    static $lage = null;
    if ($neu !== null && $lage === null) { $lage = (string) $neu; }
    return $lage === null ? '' : $lage;
}

/**
 * Fehlende Einstellungen EINMAL mit ihrer Vorgabe in die Datei schreiben.
 *
 * Am Geraet gemessen (17.09.2026, 1.3.6): dockerng.json trug drei von zehn
 * Schluesseln - den Stand von vor 1.3.0. dk_config_normieren() ergaenzte die
 * uebrigen bei jedem Aufruf im Speicher, geschrieben wurden sie nie. Das
 * wirkt gleich und ist doch eine Annahme statt einer Auskunft: die Datei
 * sagt nicht, womit das Plugin arbeitet, und eine spaetere Aenderung einer
 * Vorgabe haette jede solche Anlage still mitgeaendert. Hausstandard
 * (Regeln/05): vervollstaendigen, nicht nur ergaenzen - einmal, mit
 * Protokollzeile, beim Dienststart. Der Dienst dieses Plugins ist der
 * Minutentakt; weil nur bei FEHLENDEN Schluesseln geschrieben wird, bleibt
 * es bei einem Schreibvorgang.
 *
 * Nur wenn die Datei ein Merkwort traegt. Eine fehlende oder beschaedigte
 * Konfiguration ist Sache der Selbstheilung in dk_config() - hier wird
 * nichts angelegt und nichts ueberdeckt.
 *
 * Geschrieben werden die Werte AUS DER DATEI, nicht die normierten: ein
 * Wert, den dk_config_normieren() beim Lesen begrenzt, bleibt in der Datei,
 * wie er ist - sonst wuerde er hier still ueberschrieben. Fremde Schluessel
 * bleiben ebenfalls stehen.
 *
 * Rueckgabe: Liste der eingetragenen Schluessel (leer = nichts zu tun).
 */
function dk_config_vervollstaendigen()
{
    $p = dk_paths();
    clearstatcache(true, $p['config']);
    $roh = @is_file($p['config']) ? @file_get_contents($p['config']) : false;
    if (!dk_konfig_taugt($roh)) { return array(); }
    $datei = json_decode($roh, true);
    $fehlend = array();
    foreach (dk_vorgaben() as $k => $w) {
        if (!array_key_exists($k, $datei)) {
            $datei[$k] = $w;
            $fehlend[] = $k;
        }
    }
    /* Veraltete Schluessel (C1: portainer_name) werden EINMAL aus der Datei
     * genommen - mit einer Zeile, die den alten Wert nennt. Stand dort ein
     * anderer Name als portainer, ist das die Stelle, an der der Anwender
     * erfaehrt, dass dieser Container nicht mehr angefasst wird. */
    $veraltet = array();
    foreach (dk_veraltete_schluessel() as $k) {
        if (array_key_exists($k, $datei)) {
            $veraltet[$k] = $datei[$k];
            unset($datei[$k]);
        }
    }
    if (!$fehlend && !$veraltet) { return array(); }
    if (!dk_config_schreiben($datei)) { return array(); }
    if ($fehlend) {
        dk_log(sprintf(dk_t('LOG.VERVOLLSTAENDIGT'), count($fehlend), implode(', ', $fehlend)));
    }
    foreach ($veraltet as $k => $w) {
        dk_log(sprintf(dk_t('LOG.VERALTET_ENTFERNT'), $k,
            is_string($w) ? $w : (string) json_encode($w)));
    }
    return array_merge($fehlend, array_keys($veraltet));
}

function dk_config_schreiben($cfg)
{
    $p = dk_paths();
    if (!@is_dir($p['configdir'])) { @mkdir($p['configdir'], 0755, true); }
    if (!dk_json_schreiben($p['config'], $cfg, 0600)) {
        dk_log('Die Konfiguration liess sich NICHT schreiben: ' . $p['config']);
        return false;
    }
    /* Sicherung mitziehen - aber NUR mit einem Merkwort darin.
     *
     * Sonst ueberschreibt ein Speichervorgang ohne Merkwort die letzte gute
     * Kopie. Die Sicherung ist die Rueckfallebene; sie darf nie schlechter
     * werden als das, was sie sichern soll. is_string statt (string): eine
     * Liste waere sonst "Array" geworden und mitgezogen worden (C2).
     */
    if (isset($cfg['aktionstoken']) && is_string($cfg['aktionstoken'])
        && trim($cfg['aktionstoken']) !== '') {
        if (!dk_json_schreiben($p['sicherung'], $cfg, 0600)) {
            dk_log('Die Zweitschrift liess sich nicht schreiben: ' . $p['sicherung']);
        }
    }
    // Den Zwischenspeicher nachziehen, sonst arbeitet der Rest dieses Aufrufs
    // mit dem alten Stand weiter.
    dk_config_speicher(dk_config_normieren($cfg));
    dk_log('Konfiguration gespeichert (Port '
        . (isset($cfg['portainer_port']) && is_scalar($cfg['portainer_port']) ? (int) $cfg['portainer_port'] : 0) . ').');
    // Das Abo fuer Gateway V1 folgt dem Praefix (M7).
    dk_abo_datei_nachfuehren();
    return true;
}

/* ---------------- Merkwort fuer den Endpunkt ----------------
 *
 * Wird beim ersten Oeffnen der Oberflaeche erzeugt und steckt danach in den
 * Adressen im Miniserver - deshalb nur auf ausdruecklichen Wunsch neu wuerfeln.
 */
function dk_token_neu($laenge = 24)
{
    $zeichen = 'abcdefghijkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $aus = '';
    for ($i = 0; $i < $laenge; $i++) {
        $aus .= $zeichen[random_int(0, strlen($zeichen) - 1)];
    }
    return $aus;
}

/**
 * Das Merkwort, oder Leerstring.
 *
 * Bis 1.2.3 wurde der Rueckgabewert von dk_config_schreiben() verworfen und
 * das frisch gewuerfelte Merkwort trotzdem zurueckgegeben. Gehoerte der
 * Konfigurationsordner nach einer verunglueckten Installation root, dann zeigte
 * der Reiter Test dauerhaft "Merkwort gesetzt, 24 Zeichen" - waehrend auf
 * Platte keines stand, der Endpunkt folgerichtig 403 lieferte und bei JEDEM
 * Seitenaufruf ein anderes Merkwort angezeigt wurde. Der Anwender jagte einem
 * Wert nach, den es nicht gab.
 *
 * Jetzt heisst Leerstring: es gibt keines, und die Oberflaeche sagt das.
 */
/* ---------------- Merkmal gegen fremde Formulare (CSRF) ----------------
 *
 * htmlauth/ schuetzt gegen den unangemeldeten Aufruf - NICHT dagegen, dass der
 * Browser eines angemeldeten Bedieners ein Formular abschickt, das auf einer
 * fremden Seite steht. Die HTTP-Basic-Anmeldung von LoxBerry schickt der
 * Browser bei einem seitenfremden POST automatisch mit; SameSite greift dabei
 * nicht.
 *
 * Was damit bis 1.3.0 moeglich war: eine beliebige fremde Seite konnte
 * 'speichern=1&token_neu=1' absetzen. Danach bekamen saemtliche virtuellen
 * Eingaenge im Miniserver HTTP 403, die Ueberwachung war tot - ohne jede
 * Rueckmeldung. Ueber 'log_leeren=1' liess sich gleich die Spur wegraeumen.
 * Der Angreifer sieht die Antwort nicht, er braucht sie aber auch nicht.
 *
 * Das Merkmal wird aus dem Aktionstoken ABGELEITET, nicht gespeichert: es gibt
 * damit keinen zweiten Wert, der verlorengehen oder auseinanderlaufen kann,
 * und es wechselt automatisch mit, wenn das Merkwort neu gewuerfelt wird.
 */
function dk_formtoken()
{
    $cfg = dk_config();
    $t = trim((string) $cfg['aktionstoken']);
    // Fail closed: ohne Aktionstoken gibt es kein Merkmal. Ein aus dem
    // Leerstring abgeleiteter Wert waere fuer jeden ausrechenbar und damit
    // kein Schutz, sondern nur die Behauptung eines Schutzes.
    if ($t === '') { return ''; }
    return hash_hmac('sha256', 'formular-v1', $t);
}

function dk_token()
{
    $cfg = dk_config();
    if (trim($cfg['aktionstoken']) === '') {
        $cfg['aktionstoken'] = dk_token_neu();
        if (!dk_config_schreiben($cfg)) {
            return '';
        }
    }
    return (string) dk_config()['aktionstoken'];
}

/* ---------------- Sprache ----------------
 *
 * Englisch ist die Rueckfallebene, nicht Deutsch: wer eine dritte Sprache
 * eingestellt hat, versteht eher Englisch. Die Datei wird zweistufig gesucht,
 * damit derselbe Block im installierten Plugin UND im entpackten Archiv traegt.
 */
function dk_sprache()
{
    $sprache = 'de';
    if (class_exists('LBSystem', false) && method_exists('LBSystem', 'lblanguage')) {
        $sprache = LBSystem::lblanguage();
    } elseif (getenv('LBLANG')) {
        $sprache = getenv('LBLANG');
    }
    $sprache = strtolower(substr((string) $sprache, 0, 2));
    return in_array($sprache, array('de', 'en'), true) ? $sprache : 'en';
}

function dk_t($schluessel)
{
    static $texte = null;
    if ($texte === null) {
        $p = dk_paths();
        $pfad = $p['home'] . '/templates/plugins/' . $p['plugin'] . '/lang';
        if (!@is_dir($pfad)) {
            // Archivfall: drei Ebenen ueber dieser Bibliothek liegt die Wurzel.
            $pfad = dirname(dirname(dirname(__FILE__))) . '/templates/lang';
        }
        $texte = @parse_ini_file($pfad . '/language_' . dk_sprache() . '.ini', true, INI_SCANNER_RAW);
        if (!is_array($texte)) { $texte = array(); }
        $rueck = @parse_ini_file($pfad . '/language_en.ini', true, INI_SCANNER_RAW);
        if (is_array($rueck)) { $texte = array_replace_recursive($rueck, $texte); }
    }
    list($a, $s) = array_pad(explode('.', $schluessel, 2), 2, '');
    return isset($texte[$a][$s]) ? $texte[$a][$s] : $schluessel;
}

function dk_e($wert)
{
    return htmlspecialchars((string) $wert, ENT_QUOTES, 'UTF-8');
}

/* ==================================================================
 * Docker
 *
 * Ein Befehl wird hier NIE mit shell_exec und 2>/dev/null abgesetzt.
 *
 * Der Grund ist der wichtigste Fehler, den dieses Plugin haben kann: nach
 * der Installation steht loxberry zwar in der Gruppe docker, aber der
 * bereits laufende Webserver hat diese Gruppe noch nicht - Linux zieht
 * Gruppen fuer laufende Prozesse nicht nach. 'docker ps' scheitert dann mit
 *
 *     permission denied while trying to connect to the Docker daemon socket
 *
 * und Rueckgabewert 1. Mit 2>/dev/null kam davon nichts an: shell_exec gab
 * einen leeren String zurueck, die Liste war leer, und das Plugin meldete an
 * Loxone in aller Ruhe '0 Container' - waehrend Portainer daneben lief.
 *
 * Eine falsche Null ist schlimmer als eine Fehlermeldung. Deshalb laeuft
 * alles ueber dk_ausfuehren(): Rueckgabewert und Fehlerausgabe werden
 * mitgenommen und ausgewertet.
 * ================================================================== */

/**
 * Einen Befehl ausfuehren und ALLES mitnehmen.
 * Rueckgabe: array(ausgabe, fehlertext, rueckgabewert)
 */
function dk_ausfuehren($befehl, $sekunden = null, $mit_frist = true)
{
    $aus = array();
    $code = 0;
    /* ZEITGRENZE (C3). Bis 1.3.9 lief jeder docker-Aufruf ohne Frist. Hing
     * der Docker-Dienst, hing der Minutentakt mit, hielt die Cron-Sperre, und
     * jeder weitere Takt ging still (gemessen: nach 8 s lebte der Takt noch,
     * der Zaehler stand); jeder Abruf des Miniservers band einen
     * Apache-Arbeiter ohne Ende. Jetzt steht 'timeout -k 2 <n>' vor jedem
     * docker-Aufruf, n setzt der Einstieg (dk_zeitgrenze). Gibt es kein
     * timeout (etwa ein Pruefrechner), laeuft der Befehl wie bisher. */
    if ($mit_frist && strncmp($befehl, 'docker ', 7) === 0) {
        $befehl = dk_zeitvorsatz($sekunden) . $befehl;
    }
    // Die Fehlerausgabe kommt in eine eigene Datei, damit sie sich von der
    // Nutzausgabe trennen laesst - '2>&1' vermischte beides, und dann steht
    // eine Fehlermeldung mitten in der Containerliste.
    $fehlerdatei = tempnam(sys_get_temp_dir(), 'dkng');
    if ($fehlerdatei === false) {
        @exec($befehl . ' 2>/dev/null', $aus, $code);
        return array(implode("\n", $aus), '', (int) $code);
    }
    @exec($befehl . ' 2>' . escapeshellarg($fehlerdatei), $aus, $code);
    $fehler = trim((string) @file_get_contents($fehlerdatei));
    @unlink($fehlerdatei);
    return array(implode("\n", $aus), $fehler, (int) $code);
}

/**
 * Frist fuer docker-Aufrufe in Sekunden (C3). Der Einstieg setzt sie:
 * Endpunkt 8 s (der Miniserver fragt alle 60 s), Minutentakt 30 s,
 * Oberflaeche 15 s, Hakenskripte ueber bin/dk_eigen.php 30 s.
 */
function dk_zeitgrenze($neu = null)
{
    static $s = 20;
    if ($neu !== null) { $s = max(1, min(900, (int) $neu)); }
    return $s;
}

/** 'timeout -k 2 <n> ' vor einem Befehl, oder Leerstring ohne timeout. */
function dk_zeitvorsatz($sekunden = null)
{
    static $bin = null;
    if ($bin === null) {
        $bin = '';
        $a = array();
        $rc = 1;
        @exec('command -v timeout 2>/dev/null', $a, $rc);
        $k = trim(implode('', $a));
        if ($rc === 0 && substr($k, -8) === '/timeout') { $bin = $k; }
    }
    if ($bin === '') { return ''; }
    $n = ($sekunden === null) ? dk_zeitgrenze() : max(1, (int) $sekunden);
    return escapeshellarg($bin) . ' -k 2 ' . $n . ' ';
}

/** Rueckgabewert von timeout: 124 abgelaufen, 137 nach -k getoetet. */
function dk_zeitueberschreitung($code)
{
    return $code === 124 || $code === 137;
}

/**
 * Warum klappt der Zugriff auf Docker nicht?
 *
 * Rueckgabe: array(ok, grund, meldung). 'grund' ist ein kurzes Merkwort fuer
 * den Miniserver, 'meldung' der Klartext fuer die Oberflaeche.
 */
function dk_zustand($frisch = false)
{
    static $z = null;
    if ($frisch) { $z = null; }
    if ($z !== null) {
        return $z;
    }
    /* Die Klartexte stehen seit 1.3.9 in den Sprachdateien (O7, [GRUND]);
     * die Kennung in $z[1] bleibt, sie steht in der Antwortzeile an Loxone. */
    if (dk_bin() === '') {
        $z = array(0, 'KEIN_DOCKER', dk_t('GRUND.KEIN_DOCKER'));
        return $z;
    }
    list($aus, $fehler, $code) = dk_ausfuehren('docker ps --format "{{.Names}}"');
    if ($code === 0) {
        $z = array(1, '', '');
        return $z;
    }
    if (dk_zeitueberschreitung($code)) {
        $z = array(0, 'ZEITUEBERSCHREITUNG', sprintf(dk_t('GRUND.ZEITUEBERSCHREITUNG'), dk_zeitgrenze()));
        dk_log_gebremst('zustand_zeitueberschreitung', 'Docker antwortet nicht in der Frist ('
            . dk_zeitgrenze() . ' s, Rueckgabewert ' . $code . ').');
        return $z;
    }
    $t = strtolower($fehler);
    if (strpos($t, 'permission denied') !== false || strpos($t, 'connect: permission') !== false) {
        $z = array(0, 'KEINE_RECHTE', dk_t('GRUND.KEINE_RECHTE'));
        return $z;
    }
    if (strpos($t, 'cannot connect to the docker daemon') !== false
        || strpos($t, 'is the docker daemon running') !== false) {
        $z = array(0, 'DIENST_AUS', dk_t('GRUND.DIENST_AUS'));
        return $z;
    }
    $z = array(0, 'FEHLER', $fehler !== '' ? $fehler
               : sprintf(dk_t('GRUND.FEHLER_OHNE_TEXT'), $code));
    // Gebremst, weil diese Stelle bei jedem Seitenaufruf und jedem
    // Endpunktabruf durchlaufen wird - ungebremst waere die Logdatei nach
    // einer Stunde Dauerstoerung unlesbar.
    dk_log_gebremst('zustand_' . strtolower($z[1]),
        'Docker antwortet nicht (' . $z[1] . '): ' . $z[2]);
    return $z;
}

/** Pfad zum docker-Programm, oder Leerstring. */
function dk_bin()
{
    static $pfad = null;
    if ($pfad === null) {
        list($aus, $fehler, $code) = dk_ausfuehren('command -v docker');
        $pfad = ($code === 0) ? trim($aus) : '';
    }
    return $pfad;
}

function dk_version()
{
    static $v = null;
    if ($v !== null) { return $v; }
    if (dk_bin() === '') { $v = ''; return $v; }
    list($aus, $fehler, $code) = dk_ausfuehren('docker --version');
    $v = trim($code === 0 ? $aus : $fehler);
    return $v;
}

/**
 * Laeuft dieser Container wirklich?
 *
 * Bis 1.2.3 stand hier stripos($status, 'Up') === 0. Docker gibt einen
 * pausierten Container aber als "Up 4 minutes (Paused)" aus - der Test schlug
 * an, und ein per SIGSTOP eingefrorener Container meldete nach Loxone "laeuft".
 * Wer in Portainer das MQTT-Gateway pausiert, bekam von der Ueberwachung, die
 * genau dafuer gebaut wurde, kein Wort.
 *
 * Massgeblich ist deshalb {{.State}}: created, running, paused, restarting,
 * exited, removing, dead. Der Platzhalter fehlt in sehr alten Docker-Fassungen;
 * dann bleibt die Textauswertung als Rueckfallebene - diesmal aber MIT dem
 * Ausschluss von (Paused).
 */
function dk_zustand_ableiten($state, $status)
{
    $s = strtolower(trim((string) $state));
    if ($s !== '') {
        return $s;
    }
    if (stripos($status, 'Up') === 0) {
        return stripos($status, '(Paused)') !== false ? 'paused' : 'running';
    }
    if (stripos($status, 'Restarting') === 0) { return 'restarting'; }
    if (stripos($status, 'Created')    === 0) { return 'created'; }
    if (stripos($status, 'Dead')       === 0) { return 'dead'; }
    if (stripos($status, 'Removal')    === 0) { return 'removing'; }
    return 'exited';
}

/**
 * Planmaessig beendet oder Stoerung?
 *
 * Ein Sicherungscontainer, der nachts laeuft und mit Code 0 endet, steht
 * danach dauerhaft in der Zaehlung 'gestoppt'. Der im Reiter "Einbindung in
 * Loxone" empfohlene Schwellwertschalter "Ein ab 1" schlaegt dadurch vom
 * ersten Tag an dauerhaft an - und weil der Benachrichtigungs-Baustein nur
 * beim Wechsel von Aus auf Ein sendet, verschluckt diese Dauerstoerung
 * anschliessend ALLE anderen Meldungen an demselben ODER. Die Anleitung warnt
 * vor genau diesem Mechanismus und lief mit ihrem eigenen ersten Baustein
 * hinein.
 *
 * 'Exited (0)' und 'Created' sind deshalb keine Stoerung.
 */
function dk_ist_ausfall($zustand, $status)
{
    if ($zustand === 'running' || $zustand === 'created') { return 0; }
    if ($zustand === 'exited' && preg_match('/Exited \(0\)/i', (string) $status)) { return 0; }
    return 1;
}

/**
 * Liste aller Container.
 *
 * Getrennt wird an Tabulatoren, nicht an Leerzeichen: Abbildnamen und
 * Zustandstexte enthalten selbst welche ("Up 3 hours (healthy)").
 *
 * Gemerkt, weil die Liste je Seitenaufbau bis 1.2.3 zweimal geholt wurde
 * (dk_zaehlung und dk_portainer_laeuft). Das kostete nicht nur einen zweiten
 * Prozessstart, sondern lieferte zwei verschiedene Momentaufnahmen: wurde
 * Portainer dazwischen angehalten, zeigte die Tabelle "Up" und die Kachel
 * daneben "gestoppt".
 */
function dk_container($frisch = false)
{
    static $liste = null;
    if ($frisch) { $liste = null; }
    if ($liste !== null) { return $liste; }
    if (dk_bin() === '') { return array(); }
    // Die gemerkte Antwort (C3): wer frisch fragen will, hat vorher
    // dk_zustand(true) gerufen - ein zweites Nachfragen kostete bei
    // haengendem Docker eine zweite volle Frist.
    list($ok) = dk_zustand();
    if (!$ok) {
        // Nicht so tun, als gaebe es keine Container. Wer hier eine leere
        // Liste bekommt, soll sie an dk_zustand() halten.
        return array();
    }
    list($roh, $fehler, $code) = dk_ausfuehren(
        "docker ps -a --format '{{.Names}}\t{{.Image}}\t{{.Status}}\t{{.State}}\t{{.HealthStatus}}\t{{.Ports}}'");
    if ($code !== 0) {
        return array();
    }
    $liste = array();
    foreach (explode("\n", trim($roh)) as $zeile) {
        if (trim($zeile) === '') { continue; }
        $t = explode("\t", $zeile);
        if (count($t) < 3) { continue; }
        $zustand = dk_zustand_ableiten(isset($t[3]) ? $t[3] : '', $t[2]);
        $liste[] = array(
            'name'      => $t[0],
            'image'     => $t[1],
            'status'    => $t[2],
            'zustand'   => $zustand,
            'laeuft'    => $zustand === 'running' ? 1 : 0,
            'ausfall'   => dk_ist_ausfall($zustand, $t[2]),
            'gesund'    => dk_gesundheit_ableiten(isset($t[4]) ? $t[4] : '', $t[2]),
            // C11: die Portspalte, wie docker ps sie schreibt; zerlegt wird
            // erst bei der Anzeige (dk_ports_zerlegen).
            'ports'     => isset($t[5]) ? trim($t[5]) : '',
        );
    }
    // Autostart und Neustartzaehler stehen nur in 'docker inspect' - ein
    // einziger weiterer Aufruf fuer ALLE Container, nicht einer je Container.
    $d = dk_details();
    foreach ($liste as $i => $c) {
        $x = isset($d[$c['name']]) ? $d[$c['name']] : array();
        $liste[$i]['autostart']  = isset($x['autostart']) ? $x['autostart'] : -1;
        $liste[$i]['neustarts']  = isset($x['neustarts']) ? $x['neustarts'] : -1;
        $liste[$i]['seit']       = isset($x['seit']) ? $x['seit'] : 0;
        $liste[$i]['endecode']   = isset($x['endecode']) ? $x['endecode'] : -1;
        $liste[$i]['oom']        = isset($x['oom']) ? $x['oom'] : 0;
    }
    return $liste;
}

/**
 * Gesundheit eines Containers.
 *
 * 0 = kein Healthcheck eingerichtet, 1 = startet gerade, 2 = gesund,
 * 3 = ungesund.
 *
 * Ein Container, der laeuft, aber seinen eigenen Healthcheck nicht besteht,
 * war bis 1.2.4 nicht von einem gesunden zu unterscheiden - LAEUFT=1, fertig.
 * Genau das ist aber der Fall, den die Anleitung als Zweck des Plugins nennt:
 * "das Gateway, das die Auto- oder Wetterdaten liefert. Das faellt sonst erst
 * auf, wenn die Werte tagelang alt sind."
 *
 * {{.HealthStatus}} liefert das ohne zweiten Aufruf. Fehlt der Platzhalter in
 * einer sehr alten Docker-Fassung, bleibt der Zustandstext als Rueckfallebene:
 * er traegt die Angabe in Klammern mit ("Up 3 hours (unhealthy)").
 *
 * NICHT benutzt wird 'docker inspect {{.State.Health.Status}}' ohne
 * {{if .State.Health}}-Waechter: das bricht mit einem Vorlagenfehler ab, wenn
 * gar kein Healthcheck definiert ist, und liefert keinen Leerstring.
 */
function dk_gesundheit_ableiten($feld, $status)
{
    $f = strtolower(trim((string) $feld));
    if ($f === '') {
        if (stripos($status, '(unhealthy)') !== false)       { return 3; }
        if (stripos($status, '(healthy)') !== false)         { return 2; }
        if (stripos($status, '(health: starting)') !== false) { return 1; }
        return 0;
    }
    if ($f === 'unhealthy') { return 3; }
    if ($f === 'healthy')   { return 2; }
    if ($f === 'starting')  { return 1; }
    return 0;                       // 'none' und alles Unbekannte
}

/**
 * Einmal 'docker inspect' fuer ALLE Container.
 *
 * Rueckgabe: array(name => array(autostart, neustarts, seit, endecode, oom)).
 *
 * autostart: 1 = kommt nach einem Neustart des LoxBerry von selbst wieder
 * (RestartPolicy always/unless-stopped/on-failure), 0 = nicht, -1 = unbekannt.
 * Das ist der klassische Stolperstein: ein Container mit 'no' ist nach einem
 * Stromausfall fort, und niemand sieht es der Oberflaeche an.
 *
 * seit: Unixzeit des letzten Starts. Zusammen mit neustarts erkennt der
 * Minutentakt eine Neustartschleife.
 *
 * Die Feldtrennung geschieht mit '|', weil docker inspect --format keine
 * Tabulatoren durchreicht. Namen und Zahlen enthalten kein '|'.
 */
function dk_details($frisch = false)
{
    static $d = null;
    if ($frisch) { $d = null; }
    if ($d !== null) { return $d; }
    $d = array();
    if (dk_bin() === '') { return $d; }
    list($ok) = dk_zustand();
    if (!$ok) { return $d; }

    $vorlage = '{{.Name}}|{{.HostConfig.RestartPolicy.Name}}|{{.RestartCount}}'
             . '|{{.State.StartedAt}}|{{.State.ExitCode}}|{{.State.OOMKilled}}';
    // Beide Glieder der Pfeife bekommen die Frist (C3).
    $v = dk_zeitvorsatz();
    list($roh, $fehler, $code) = dk_ausfuehren(
        $v . 'docker ps -aq | ' . $v . 'xargs -r docker inspect --format ' . escapeshellarg($vorlage),
        null, false);
    if ($code !== 0 || trim($roh) === '') { return $d; }

    foreach (explode("\n", trim($roh)) as $zeile) {
        $t = explode('|', trim($zeile));
        if (count($t) < 6) { continue; }
        $name = ltrim($t[0], '/');          // docker inspect stellt einen / voran
        $pol  = strtolower(trim($t[1]));
        $seit = strtotime($t[3]);
        $d[$name] = array(
            'autostart' => $pol === '' ? -1
                           : (in_array($pol, array('always', 'unless-stopped', 'on-failure'), true) ? 1 : 0),
            'neustarts' => (int) $t[2],
            'seit'      => $seit === false ? 0 : $seit,
            'endecode'  => (int) $t[4],
            'oom'       => strtolower(trim($t[5])) === 'true' ? 1 : 0,
        );
    }
    return $d;
}

/**
 * Die Wachliste: welche Container sollen ueberwacht werden?
 *
 * Bis 1.2.4 wurde je GEFUNDENEM Container eine Stelle C_<name> erzeugt. Wurde
 * ein Container geloescht oder umbenannt, verschwand seine Stelle ersatzlos
 * aus der Antwort - die Befehlserkennung des virtuellen Eingangs fand ihr
 * Muster nicht mehr, und der Eingang behielt seinen LETZTEN Wert, also 1. Der
 * Miniserver meldete damit auf Dauer "laeuft" fuer einen Container, den es
 * nicht mehr gibt. Das ist dieselbe stille Falschaussage, gegen die dieses
 * Plugin in 1.1.0 angetreten ist, nur an anderer Stelle.
 *
 * Mit einer Wachliste wird ueber den SOLL-Bestand gezaehlt, nicht ueber den
 * Ist-Bestand. Ein fehlender Container bekommt -1 statt gar nichts.
 *
 * Leere Wachliste = alle gefundenen Container. Das ist das Verhalten bis
 * 1.2.4, und wer nichts einstellt, bekommt es unveraendert.
 */
function dk_wachliste()
{
    $cfg = dk_config();
    $w = (array) $cfg['wachliste'];
    if (!$w) {
        $w = array();
        foreach (dk_container() as $c) { $w[] = $c['name']; }
    }
    return $w;
}

function dk_zaehlung($frisch = false)
{
    $alle = dk_container($frisch);
    $nach = array();
    foreach ($alle as $c) { $nach[$c['name']] = $c; }

    $lauf = 0; $paus = 0; $stoer = 0; $ungesund = 0;
    foreach ($alle as $c) {
        $lauf  += $c['laeuft'];
        $stoer += $c['ausfall'];
        if ($c['zustand'] === 'paused') { $paus++; }
        if ($c['gesund'] === 3) { $ungesund++; }
    }

    /* Wachliste getrennt auswerten: ueberwacht wird der SOLL-Bestand.
     * 'wache' traegt je Eintrag -1 (nicht vorhanden), 0 (da, laeuft nicht)
     * oder 1 (laeuft). */
    $wache = array(); $fehlt = 0; $wache_ausfall = 0;
    foreach (dk_wachliste() as $name) {
        if (!isset($nach[$name])) {
            $wache[$name] = -1;
            $fehlt++;
            $wache_ausfall++;
            continue;
        }
        $wache[$name] = $nach[$name]['laeuft'];
        $wache_ausfall += $nach[$name]['ausfall'];
    }

    // Neustartschleifen kommen aus dem Minutentakt (data/zustand.json) - eine
    // Momentaufnahme kann sie gar nicht sehen.
    $z = dk_zustandsdatei();
    $schleife = isset($z['schleife']) ? (int) $z['schleife'] : 0;

    return array('gesamt' => count($alle), 'laeuft' => $lauf,
                 'gestoppt' => count($alle) - $lauf, 'pausiert' => $paus,
                 'ausfall' => $stoer, 'ungesund' => $ungesund,
                 'fehlt' => $fehlt, 'schleife' => $schleife,
                 'wache' => $wache, 'wache_ausfall' => $wache_ausfall,
                 'liste' => $alle);
}

/* ==================================================================
 * Zustand ueber die Zeit
 *
 * Alles hier braucht ZWEI Momentaufnahmen und kann deshalb nicht aus einem
 * Seitenaufruf entstehen: der Herzschlag, die Erkennung von Neustartschleifen
 * und die (teure) Plattenbelegung. Fortgeschrieben wird das im Minutentakt
 * (cron/cron.01min -> bin/dockerng_takt.php); Oberflaeche und Endpunkt LESEN
 * die Datei nur.
 *
 * Der Endpunkt schreibt bewusst NICHT selbst: er wird vom Miniserver im
 * Sechzigsekundentakt abgerufen, und jede Schreibung ist ein Schreibvorgang
 * auf der Speicherkarte.
 * ================================================================== */

function dk_zustandsdatei($frisch = false)
{
    static $z = null;
    if ($frisch) { $z = null; }
    if ($z !== null) { return $z; }
    $z = dk_json_lesen(dk_paths()['zustand']);
    return $z;
}

function dk_zustandsdatei_schreiben($daten)
{
    $p = dk_paths();
    if (!@is_dir($p['datadir'])) { @mkdir($p['datadir'], 0755, true); }
    return dk_json_schreiben($p['zustand'], $daten, 0644);
}

/**
 * Wie alt ist der Zustand? Sekunden, oder -1 wenn es ihn nicht gibt.
 *
 * Das beantwortet die Frage, die eine Momentaufnahme nicht beantworten kann:
 * laeuft der Minutentakt ueberhaupt noch? Eine Prozessnummer beantwortet das
 * nicht - ein Prozess kann dastehen und nichts mehr tun.
 */
function dk_zustand_alter()
{
    $z = dk_zustandsdatei();
    $ts = isset($z['zeit']) ? (int) $z['zeit'] : 0;
    return $ts > 0 ? max(0, time() - $ts) : -1;
}

/**
 * Die Altersgrenze des Minutentakts (C4, Entscheidung 4): dreimal der Takt
 * von 60 s. EINE Stelle fuer den Endpunkt, bin/healthcheck, die
 * MQTT-Themenliste, den Befund und den Reiter Test. Bis 1.3.9 standen dort
 * 300 s (Endpunkt), 300 s (Healthcheck) und 180 s (Reiter Test) nebeneinander.
 */
function dk_takt_grenze()
{
    return 180;
}

/** Ist der Stand aus dem Minutentakt jung genug, um ihn weiterzugeben? */
function dk_takt_frisch()
{
    $a = dk_zustand_alter();
    return $a >= 0 && $a <= dk_takt_grenze();
}

/* ---------------- Plattenbelegung ----------------
 *
 * Der einzige Punkt in diesem Plugin, der einen LoxBerry unbootbar machen
 * kann. 'docker system df' laeuft Verzeichnisse ab und ist auf einer
 * Speicherkarte spuerbar langsam - deshalb NUR aus dem Minutentakt, und auch
 * dort nur alle 15 Minuten.
 */
function dk_platz_messen()
{
    $aus = array('images_mb' => -1, 'freigebbar_mb' => -1, 'frei_mb' => -1);

    /* Der freie Platz auf der Partition wird ZUERST gemessen und unabhaengig
     * von Docker: er braucht kein docker, und gerade wenn Docker nicht
     * ansprechbar ist, will man wissen, ob die Karte voll ist. Bis zum
     * Probelauf am 22.08.2026 stand diese Messung hinter zwei vorzeitigen
     * Ruecksprungen und lieferte in genau dem Fall -1, in dem sie am meisten
     * gebraucht wird. */
    $ziel = @is_dir('/var/lib/docker') ? '/var/lib/docker' : dk_paths()['home'];
    $frei = @disk_free_space($ziel);
    if ($frei !== false) { $aus['frei_mb'] = (int) round($frei / 1048576); }

    if (dk_bin() === '') { return $aus; }
    list($ok) = dk_zustand();
    if (!$ok) { return $aus; }

    // Eigene Frist: 'docker system df' laeuft Verzeichnisse ab (C3).
    list($roh, $fehler, $code) = dk_ausfuehren('docker system df --format ' . escapeshellarg('{{json .}}'), 60);
    if ($code === 0 && trim($roh) !== '') {
        $bytes = 0; $freigebbar = 0;
        foreach (explode("\n", trim($roh)) as $zeile) {
            $d = json_decode(trim($zeile), true);
            if (!is_array($d)) { continue; }
            $bytes      += dk_groesse_in_bytes(isset($d['Size']) ? $d['Size'] : '0');
            $freigebbar += dk_groesse_in_bytes(isset($d['Reclaimable']) ? $d['Reclaimable'] : '0');
        }
        $aus['images_mb']     = (int) round($bytes / 1048576);
        $aus['freigebbar_mb'] = (int) round($freigebbar / 1048576);
    }
    return $aus;
}

/** "1.234GB", "512.3MB", "0B" -> Bytes. Docker schreibt Groessen als Text. */
function dk_groesse_in_bytes($text)
{
    $t = trim((string) $text);
    if ($t === '' || $t === '0' || $t === '0B') { return 0; }
    if (!preg_match('/^([0-9]+(?:\.[0-9]+)?)\s*([KMGTP]?)i?B?$/i', $t, $tr)) { return 0; }
    $faktor = array('' => 1, 'K' => 1024, 'M' => 1048576,
                    'G' => 1073741824, 'T' => 1099511627776, 'P' => 1125899906842624);
    $e = strtoupper($tr[2]);
    return (float) $tr[1] * (isset($faktor[$e]) ? $faktor[$e] : 1);
}

/* ---------------- MQTT ----------------
 *
 * Der Hausweg, uebernommen aus ACTiKamera: eine UDP-Zeile an den UDP-Eingang
 * des MQTT-Gateways, das seit LoxBerry 3 Systembestandteil ist. Kein
 * phpMQTT, kein socket_create() - Letzteres steckt in einer Erweiterung, die
 * nicht garantiert geladen ist, und ihr Fehlen ist KEIN abfangbarer Fehler,
 * sondern ein fataler. Im Cron sieht den niemand.
 *
 * Es wird ausschliesslich VEROEFFENTLICHT. Auf ein Kommandothema wird bewusst
 * nicht gehoert: das waere der Schaltweg, den dieses Plugin nicht anbietet,
 * und ueber einen Broker waere er schlechter geschuetzt als der Endpunkt.
 */
function dk_mqtt_lage()
{
    /* 'fassung' seit 1.3.4. Sie entscheidet, was der Anwender ueberhaupt
     * tun muss: unter V1 jedes Thema von Hand eintragen, ab V2 erscheint
     * die Themengruppe von selbst in den Subscriptions. 0 heisst NICHT
     * feststellbar - und das ist etwas anderes als Fassung 1. */
    $aus = array('gefunden' => false, 'udpport' => 0, 'autostart' => false,
                 'fassung' => 0);
    $f = dk_paths()['home'] . '/config/system/general.json';
    if (!@is_file($f)) { return $aus; }
    $d = json_decode((string) @file_get_contents($f), true);
    if (!is_array($d) || !isset($d['Mqtt'])) { return $aus; }
    $aus['gefunden'] = true;
    $aus['udpport'] = isset($d['Mqtt']['Udpinport']) ? (int) $d['Mqtt']['Udpinport'] : 0;
    // 'Gatewayautostart' ist der richtige Schluessel. 'Brokerhost' ist immer
    // gesetzt und beantwortet die Frage nicht.
    $aus['autostart'] = !empty($d['Mqtt']['Gatewayautostart']);
    $aus['fassung'] = isset($d['Mqtt']['Gatewayversion'])
        ? (int) $d['Mqtt']['Gatewayversion'] : 0;
    return $aus;
}

function dk_mqtt_wert($v)
{
    return trim(preg_replace('/ {2,}/', ' ',
        str_replace(array("\r\n", "\r", "\n", "\t"), ' ', (string) $v)));
}

/** Rueckgabe: Zahl der wirklich abgesetzten Meldungen. 0 = nichts ging hinaus. */
function dk_mqtt($werte, $alt = array(), $erzwingen = false)
{
    $aus = array('gesendet' => 0, 'gescheitert' => 0, 'uebersprungen' => 0, 'letzte' => array());
    $cfg = dk_config();
    if (empty($cfg['mqtt_aktiv'])) { return $aus; }
    $lage = dk_mqtt_lage();
    if (!$lage['udpport']) {
        dk_log_gebremst('mqtt_kein_port',
            'MQTT ist eingeschaltet, aber der LoxBerry nennt keinen UDP-Eingang '
            . 'fuer das MQTT-Gateway. Unter System, MQTT Gateway einrichten.');
        $aus['gescheitert'] = count($werte);
        return $aus;
    }
    $s = @stream_socket_client('udp://127.0.0.1:' . $lage['udpport'], $eno, $estr, 2);
    if (!$s) {
        dk_log_gebremst('mqtt_kein_socket',
            'MQTT: UDP-Eingang ' . $lage['udpport'] . ' nicht erreichbar (' . $estr . ')');
        $aus['gescheitert'] = count($werte);
        return $aus;
    }
    $prae = $cfg['mqtt_praefix'];
    $letzte = (isset($alt['letzte']) && is_array($alt['letzte'])) ? $alt['letzte'] : array();
    $leben = dk_mqtt_lebenszeichen();
    $jetzt = time();
    $erster = true;
    foreach ((array) $werte as $k => $v) {
        $wert = dk_mqtt_wert($v);
        $retain = dk_mqtt_retain_eintrag($k) === 1;
        if ($wert === '') {
            // Eine leere Nutzlast gibt es nur zum Abraeumen (M4). Ein leerer
            // Zustand geht als '-' hinaus (Entscheidung 5), ein leerer
            // Messwert gar nicht.
            if (!$retain) { $aus['uebersprungen']++; continue; }
            $wert = '-';
        }
        /* Sendelast (M6): ein unveraenderter Wert geht hoechstens alle zehn
         * Minuten erneut hinaus - so heilt ein verlorenes Datagramm spaetestens
         * dann. Das Lebenszeichen geht in JEDEM Takt hinaus. */
        $vorher = (isset($letzte[$k]) && is_array($letzte[$k]) && count($letzte[$k]) === 2) ? $letzte[$k] : null;
        if (!$erzwingen && !in_array($k, $leben, true) && $vorher !== null
            && (string) $vorher[0] === $wert && ($jetzt - (int) $vorher[1]) < 600) {
            $aus['letzte'][$k] = $vorher;
            $aus['uebersprungen']++;
            continue;
        }
        // Eine kurze Pause zwischen den Datagrammen (M6, Bauart Bewaesserung
        // 0.9.35): der UDP-Eingang des Gateways verwirft Stoesse.
        if (!$erster) { usleep(5000); }
        $erster = false;
        $zeile = ($retain ? 'retain ' : 'publish ') . $prae . '/' . $k . ' ' . $wert;
        // "Gesendet" heisst: an den UDP-Eingang abgesetzt - nicht "angekommen"
        // (Regeln/07). Nur bei vollstaendiger Schreibung merkt sich das
        // Gedaechtnis den Wert; sonst geht er im naechsten Takt wieder hinaus.
        if (@fwrite($s, $zeile) === strlen($zeile)) {
            $aus['gesendet']++;
            $aus['letzte'][$k] = array($wert, $jetzt);
        } else {
            $aus['gescheitert']++;
        }
    }
    fclose($s);
    return $aus;
}

/**
 * Die Retain-Tabelle (M1, Entscheidung 3) - EINE Quelle fuer den Sender und
 * die Spalte "zurueckbehalten" der Themenliste im Reiter MQTT.
 *
 * Zurueckbehalten: die Zustaende der Container und die Zaehlungen daraus.
 * Fluechtig: das Lebenszeichen (ok, zaehler, ts) - eine Aussage des Dienstes
 * ueber sich selbst, nie retained (Regeln/07, 17. und 19.09.2026) - und die
 * Messwerte mit Zeitbezug (Neustarts der letzten Stunde, Plattenplatz).
 * Ein Thema ohne Eintrag geht fluechtig hinaus. '*' steht fuer genau einen
 * Themenabschnitt (den Containernamen).
 */
function dk_mqtt_retain_tabelle()
{
    return array(
        'status/ok'             => 0,
        'status/zaehler'        => 0,
        'status/ts'             => 0,
        'status/gesamt'         => 1,
        'status/laeuft'         => 1,
        'status/gestoppt'       => 1,
        'status/ausfall'        => 1,
        'status/pausiert'       => 1,
        'status/ungesund'       => 1,
        'status/fehlt'          => 1,
        'status/portainer'      => 1,
        'status/schleife'       => 0,
        'platte/images_mb'      => 0,
        'platte/freigebbar_mb'  => 0,
        'platte/frei_mb'        => 0,
        'container/*/laeuft'    => 1,
        'container/*/gesund'    => 1,
        'container/*/stand'     => 1,
    );
}

/** Eintrag der Tabelle fuer ein Thema: 1 retained, 0 fluechtig, -1 kein Eintrag. */
function dk_mqtt_retain_eintrag($thema)
{
    $t = dk_mqtt_retain_tabelle();
    if (isset($t[$thema])) { return (int) $t[$thema]; }
    foreach ($t as $muster => $r) {
        if (strpos($muster, '*') === false) { continue; }
        $re = '#^' . str_replace('\*', '[^/]+', preg_quote($muster, '#')) . '\z#';
        if (preg_match($re, (string) $thema)) { return (int) $r; }
    }
    return -1;
}

/** Das Lebenszeichen: geht in jedem Takt hinaus, am Aenderungsfilter vorbei. */
function dk_mqtt_lebenszeichen()
{
    return array('status/ok', 'status/zaehler', 'status/ts');
}

/**
 * Der MQTT-Teil des Minutentakts (M1-M6).
 *
 * Merkt sich in zustand.json unter 'mqtt': das Praefix, was zuletzt
 * hinausging (Aenderungsfilter) und welche Container zuletzt gemeldet wurden.
 * Verschwindet ein Container, der NICHT auf der Wachliste steht, geht fuer
 * seine drei Themen EINMAL '-' retained hinaus (M3, Entscheidung 9); danach
 * wird er nicht mehr gesendet. Ein Container der Wachliste meldet weiter
 * -1/fehlt. Antwortet Docker nicht, bleibt das Gedaechtnis der Container
 * unangetastet - nur das Signal geht hinaus (M2).
 */
function dk_mqtt_takt($alt, $ok, $z)
{
    $cfg = dk_config();
    dk_mqtt_abraeumen_offen();
    if (empty($cfg['mqtt_aktiv'])) { return array(); }
    $prae = $cfg['mqtt_praefix'];
    $gleich = is_array($alt) && isset($alt['praefix']) && $alt['praefix'] === $prae;
    $vorher = ($gleich && isset($alt['container']) && is_array($alt['container'])) ? $alt['container'] : array();
    $strich = ($gleich && isset($alt['strich']) && is_array($alt['strich'])) ? $alt['strich'] : array();
    $werte = dk_mqtt_themen();
    $gemeldet = $vorher;
    $weg = array();
    if ($ok) {
        $gemeldet = array_keys($z['wache']);
        foreach ($vorher as $n) {
            if (!is_string($n) || in_array($n, $gemeldet, true)) { continue; }
            $t = str_replace(array('/', '%'), '_', $n);
            foreach (array('laeuft', 'gesund', 'stand') as $f) {
                $werte['container/' . $t . '/' . $f] = '-';
            }
            $weg[$n] = $t;
        }
    }
    $erg = dk_mqtt($werte, $gleich ? $alt : array());
    foreach ($weg as $n => $t) {
        // Im Merker bleibt er, bis alle drei '-' abgesetzt sind; danach steht
        // er unter 'strich', damit Praefixwechsel und Deinstallation seine
        // retained Themen noch kennen.
        $alle = true;
        foreach (array('laeuft', 'gesund', 'stand') as $f) {
            if (!isset($erg['letzte']['container/' . $t . '/' . $f])) { $alle = false; break; }
        }
        if ($alle) { $strich[] = $n; } else { $gemeldet[] = $n; }
    }
    $strich = array_slice(array_values(array_diff(array_unique(
        array_filter($strich, 'is_string')), $gemeldet)), -500);
    return array(
        'praefix'   => $prae,
        'letzte'    => $erg['letzte'],
        'container' => array_values(array_unique($gemeldet)),
        'strich'    => $strich,
        'versand'   => array('zeit' => time(), 'gesendet' => $erg['gesendet'],
                             'gescheitert' => $erg['gescheitert'], 'uebersprungen' => $erg['uebersprungen']),
    );
}

/** Die retained Themen, die dieses Plugin unter dem Praefix zuletzt fuehrte. */
function dk_mqtt_eigene_retained($praefix)
{
    $themen = array();
    $zd = dk_zustandsdatei();
    $m = (isset($zd['mqtt']) && is_array($zd['mqtt'])) ? $zd['mqtt'] : array();
    if (isset($m['praefix']) && $m['praefix'] === $praefix) {
        foreach ((isset($m['letzte']) && is_array($m['letzte'])) ? array_keys($m['letzte']) : array() as $t) {
            if (dk_mqtt_retain_eintrag($t) === 1) { $themen[] = (string) $t; }
        }
        $namen = array_merge((isset($m['container']) && is_array($m['container'])) ? $m['container'] : array(),
                             (isset($m['strich']) && is_array($m['strich'])) ? $m['strich'] : array());
        foreach ($namen as $n) {
            if (!is_string($n)) { continue; }
            $t = str_replace(array('/', '%'), '_', $n);
            foreach (array('laeuft', 'gesund', 'stand') as $f) { $themen[] = 'container/' . $t . '/' . $f; }
        }
    }
    foreach (dk_mqtt_retain_tabelle() as $t => $r) {
        if ($r === 1 && strpos($t, '*') === false) { $themen[] = $t; }
    }
    return array_values(array_unique($themen));
}

/**
 * Abraeumen vormerken (M4): beim Praefixwechsel und beim Abschalten von
 * MQTT. Die Oberflaeche schreibt nur diese Vormerkung; abgesetzt wird im
 * Minutentakt, der ohnehin der einzige Sender ist. Rueckgabe: Zahl der Themen.
 */
function dk_mqtt_abraeumen_vormerken($praefix)
{
    $p = dk_paths();
    $offen = dk_json_lesen($p['abraeumen']);
    $themen = dk_mqtt_eigene_retained($praefix);
    $bisher = (isset($offen[$praefix]) && is_array($offen[$praefix])) ? $offen[$praefix] : array();
    $offen[$praefix] = array_values(array_unique(array_merge($bisher, $themen)));
    if (!@is_dir($p['datadir'])) { @mkdir($p['datadir'], 0755, true); }
    if (!dk_json_schreiben($p['abraeumen'], $offen, 0644)) {
        dk_log('MQTT: das Abraeumen unter ' . $praefix . '/ liess sich nicht vormerken: ' . $p['abraeumen']);
        return -1;
    }
    dk_log('MQTT: ' . count($offen[$praefix]) . ' zurueckbehaltene Themen unter ' . $praefix
        . '/ zum Abraeumen vorgemerkt.');
    return count($offen[$praefix]);
}

/** Leere retain-Nutzlasten absetzen. Rueckgabe: Liste der gescheiterten Themen. */
function dk_mqtt_leer_senden($volle_themen, $wiederholen = 1)
{
    $lage = dk_mqtt_lage();
    if (!$lage['udpport'] || !$volle_themen) { return $volle_themen; }
    $s = @stream_socket_client('udp://127.0.0.1:' . $lage['udpport'], $eno, $estr, 2);
    if (!$s) { return $volle_themen; }
    $fehl = array();
    $erster = true;
    foreach ($volle_themen as $t) {
        $ok = true;
        for ($i = 0; $i < max(1, (int) $wiederholen); $i++) {
            if (!$erster) { usleep(5000); }
            $erster = false;
            // Das Leerzeichen am Ende kuerzt das Gateway selbst; eine leere
            // retain-Nutzlast loescht das Thema im Broker (Regeln/07).
            $zeile = 'retain ' . $t . ' ';
            if (@fwrite($s, $zeile) !== strlen($zeile)) { $ok = false; }
        }
        if (!$ok) { $fehl[] = $t; }
    }
    fclose($s);
    return $fehl;
}

/**
 * Vorgemerktes Abraeumen abarbeiten - auch bei ausgeschaltetem MQTT (M4).
 * Ein Thema faellt aus der Vormerkung, sobald sein Datagramm vollstaendig
 * abgesetzt ist (Merker auf den Erfolg des sendto). Ein Praefix, das gerade
 * wieder in Gebrauch ist, wird nicht geleert.
 */
function dk_mqtt_abraeumen_offen()
{
    $p = dk_paths();
    if (!@is_file($p['abraeumen'])) { return 0; }
    $offen = dk_json_lesen($p['abraeumen']);
    $cfg = dk_config();
    $rest = array();
    $n = 0;
    foreach ($offen as $prae => $themen) {
        if (!is_string($prae) || !preg_match('/^[A-Za-z0-9_\-]{1,32}\z/', $prae) || !is_array($themen)) { continue; }
        if (!empty($cfg['mqtt_aktiv']) && $prae === $cfg['mqtt_praefix']) { continue; }
        $voll = array();
        foreach ($themen as $t) {
            if (is_string($t) && preg_match('#^[A-Za-z0-9_./\-]{1,200}\z#', $t)) { $voll[] = $prae . '/' . $t; }
        }
        $fehl = dk_mqtt_leer_senden($voll);
        $n += count($voll) - count($fehl);
        if ($fehl) {
            $rest[$prae] = array();
            foreach ($fehl as $f) { $rest[$prae][] = substr($f, strlen($prae) + 1); }
        }
    }
    if ($rest) {
        dk_json_schreiben($p['abraeumen'], $rest, 0644);
    } else {
        @unlink($p['abraeumen']);
    }
    if ($n > 0) { dk_log('MQTT: ' . $n . ' zurueckbehaltene Themen abgeraeumt.'); }
    return $n;
}

/**
 * Fuer die Deinstallation (M4, Entscheidung 3): alle eigenen retained Themen
 * unter dem eingestellten Praefix und alles Vorgemerkte leeren. Jedes leere
 * Datagramm geht zweimal hinaus - der Eingang verwirft Stoesse, und eine
 * zweite Loeschung schadet nicht. Rueckgabe array(themen, gescheitert).
 */
function dk_mqtt_leeren_alles()
{
    $cfg = dk_config(false);
    $p = dk_paths();
    $voll = array();
    $zd = dk_zustandsdatei();
    $war = !empty($cfg['mqtt_aktiv']) || (isset($zd['mqtt']['praefix']));
    if ($war) {
        foreach (dk_mqtt_eigene_retained($cfg['mqtt_praefix']) as $t) {
            $voll[] = $cfg['mqtt_praefix'] . '/' . $t;
        }
    }
    foreach (dk_json_lesen($p['abraeumen']) as $prae => $themen) {
        if (!is_string($prae) || !preg_match('/^[A-Za-z0-9_\-]{1,32}\z/', $prae) || !is_array($themen)) { continue; }
        foreach ($themen as $t) {
            if (is_string($t) && preg_match('#^[A-Za-z0-9_./\-]{1,200}\z#', $t)) { $voll[] = $prae . '/' . $t; }
        }
    }
    $voll = array_values(array_unique($voll));
    $fehl = dk_mqtt_leer_senden($voll, 2);
    return array(count($voll), count($fehl));
}

/**
 * Die Abodatei fuer das MQTT-Gateway V1 dem Praefix nachfuehren (M7).
 * Das Gateway liest config/plugins/<ordner>/mqtt_subscriptions.cfg selbst
 * (Regeln/07, am Geraet belegt 13.09.2026). Mitgeliefert wird sie mit
 * 'dockerng/#'; nach einem Upgrade steht dort wieder die Vorgabe - deshalb
 * fuehrt auch der Minutentakt nach. Geschrieben wird nur bei Abweichung.
 */
function dk_abo_datei_nachfuehren()
{
    $p = dk_paths();
    if (!@is_dir($p['configdir'])) { return false; }
    $cfg = dk_config();
    $soll = $cfg['mqtt_praefix'] . "/#\n";
    $ist = @is_file($p['abo']) ? (string) @file_get_contents($p['abo']) : '';
    if ($ist === $soll) { return false; }
    if (!dk_datei_schreiben($p['abo'], $soll, 0644)) {
        dk_log_gebremst('abo_schreiben', 'Die Abodatei fuer das MQTT-Gateway liess sich nicht schreiben: ' . $p['abo']);
        return false;
    }
    dk_log(sprintf(dk_t('LOG.ABO_GESETZT'), trim($soll)));
    return true;
}

/**
 * Alle Themen, die dieses Plugin veroeffentlicht.
 *
 * EINE Quelle - dieselbe Liste versorgt den Sendecode, die Themenliste im
 * Reiter MQTT und die Kongruenzprobe im Reiter Test. Eine Anleitung, die
 * Themen nennt, die der Sendecode nie veroeffentlicht, ist schlimmer als gar
 * keine: sie schickt den Anwender in Loxone auf die Suche nach einem Wert,
 * den es nicht gibt.
 */
function dk_mqtt_themen()
{
    list($ok) = dk_zustand();
    $zd = dk_zustandsdatei();
    $frisch = dk_takt_frisch();
    $werte = array(
        'status/ok'      => $ok ? 1 : 0,
        // Zeitstempel des letzten Takts, Unix-Sekunden, fluechtig (M5).
        'status/ts'      => isset($zd['zeit']) ? (int) $zd['zeit'] : 0,
    );
    if (!$ok) {
        /* Docker schweigt (M2, Entscheidung 8 und 9): die Zustaende bleiben im
         * Broker stehen, nur das Signal geht hinaus. Bis 1.3.9 gingen hier
         * 'fehlt' fuer jeden Container der Wachliste und 'gesamt 0' hinaus -
         * dieselben Werte wie fuer einen geloeschten Container. Der freie
         * Platz misst ohne Docker und bleibt deshalb. */
        $werte['status/zaehler'] = isset($zd['zaehler']) ? (int) $zd['zaehler'] : 0;
        if ($frisch && isset($zd['platz']['frei_mb'])) { $werte['platte/frei_mb'] = (int) $zd['platz']['frei_mb']; }
        return $werte;
    }
    $z = dk_zaehlung();
    $werte += array(
        'status/gesamt'    => $z['gesamt'],
        'status/laeuft'    => $z['laeuft'],
        'status/gestoppt'  => $z['gestoppt'],
        'status/ausfall'   => $z['ausfall'],
        'status/pausiert'  => $z['pausiert'],
        'status/ungesund'  => $z['ungesund'],
        'status/fehlt'     => $z['fehlt'],
        // Aus dem Minutentakt: nur so lange, wie er frisch ist (C4).
        'status/schleife'  => $frisch ? $z['schleife'] : -1,
        'status/portainer' => dk_portainer_laeuft() ? 1 : 0,
    );
    $werte['status/zaehler'] = isset($zd['zaehler']) ? (int) $zd['zaehler'] : 0;
    foreach (array('images_mb', 'freigebbar_mb', 'frei_mb') as $k) {
        if ($frisch && isset($zd['platz'][$k])) { $werte['platte/' . $k] = (int) $zd['platz'][$k]; }
    }
    $nach = array();
    foreach ($z['liste'] as $c) { $nach[$c['name']] = $c; }
    foreach ($z['wache'] as $name => $lauf) {
        // Nur / und % ersetzt der Gateway selbst - Punkte bleiben stehen und
        // sind in einem Thema erlaubt. Deshalb hier nur das Noetigste.
        $t = str_replace(array('/', '%'), '_', $name);
        $werte['container/' . $t . '/laeuft'] = $lauf;
        $werte['container/' . $t . '/gesund'] = isset($nach[$name]) ? $nach[$name]['gesund'] : -1;
        $werte['container/' . $t . '/stand']  = isset($nach[$name]) ? $nach[$name]['zustand'] : 'fehlt';
    }
    return $werte;
}

/* ---------------- Benachrichtigungszentrum ----------------
 *
 * Der Hausweg ist notify_ext() aus libs/phplib/loxberry_log.php; im Bestand
 * benutzen ihn 41 Fundstellen. Die Wache auf function_exists() gehoert dazu:
 * die Funktion steckt in einer Bibliothek, die nicht jede LoxBerry-Fassung
 * gleich bestueckt, und ein @ hilft gegen "undefined function" nicht.
 *
 * Schwere nach der Skala von LoxBerry: 3 = Fehler, 4 = Warnung, 6 = Hinweis.
 */
function dk_melden($schwere, $text)
{
    $cfg = dk_config();
    if (empty($cfg['melden_aktiv'])) { return false; }
    $p = dk_paths();
    if ($p['home'] === '') { return false; }
    $sdk = $p['home'] . '/libs/phplib/loxberry_log.php';
    if (!@is_file($sdk)) { return false; }
    require_once $p['home'] . '/libs/phplib/loxberry_system.php';
    require_once $sdk;
    if (!function_exists('notify_ext')) { return false; }
    $s = (int) $schwere;
    if ($s < 1 || $s > 7) { $s = 4; }
    notify_ext(array(
        'PACKAGE'  => $p['plugin'],
        'NAME'     => 'Docker NG',
        'MESSAGE'  => (string) $text,
        'SEVERITY' => $s,
    ));
    return true;
}

/* ---------------- Abbild-Aktualisierungen ----------------
 *
 * Verglichen werden Pruefsummen, nicht Zeitstempel: der lokale RepoDigest
 * gegen den Kopf Docker-Content-Digest eines HEAD auf das Manifest.
 *
 * HEAD ist hier kein Geschmack: Docker Hub zaehlt GET-Abrufe gegen die Grenze
 * von 100 je sechs Stunden, HEAD nicht.
 *
 * Drei Fallen, die alle drei eine DAUERHAFTE Falschmeldung erzeugt haetten -
 * und eine Pruefung, die staendig "Aktualisierung verfuegbar" sagt, wird
 * ignoriert und ist damit schlechter als keine:
 *
 *   1. Ohne die Accept-Koepfe fuer Manifestlisten antwortet die Registry mit
 *      einem plattformabhaengigen Manifest - also mit einer ANDEREN
 *      Pruefsumme als der lokale Listen-Digest. Auf arm64 waere jedes Abbild
 *      dauerhaft "veraltet".
 *   2. Lokal gebaute Abbilder haben gar keinen RepoDigest.
 *   3. Fremde Registries verlangen ein eigenes Merkwort; wo wir keines
 *      bekommen, gibt es keine Antwort - und keine Antwort heisst UNBEKANNT,
 *      nicht "veraltet".
 *
 * Deshalb kennt diese Funktion drei Ausgaenge: 1 = neuer Stand verfuegbar,
 * 0 = aktuell, -1 = nicht messbar. -1 loest nie eine Meldung aus.
 */
function dk_bild_digest_lokal($bild)
{
    list($roh, $fehler, $code) = dk_ausfuehren(
        'docker image inspect --format ' . escapeshellarg('{{range .RepoDigests}}{{.}}{{"\n"}}{{end}}')
        . ' -- ' . escapeshellarg($bild));
    if ($code !== 0) { return ''; }
    foreach (explode("\n", trim($roh)) as $z) {
        if (strpos($z, '@sha256:') !== false) { return substr($z, strpos($z, '@') + 1); }
    }
    return '';
}

/** "portainer/portainer-ce:latest" -> array(registry, pfad, marke) oder null. */
function dk_bild_zerlegen($bild)
{
    $b = trim((string) $bild);
    if ($b === '' || strpos($b, '@') !== false) { return null; }   // Digest-Pin: nichts zu pruefen
    $marke = 'latest';
    $pos = strrpos($b, ':');
    if ($pos !== false && strpos(substr($b, $pos), '/') === false) {
        $marke = substr($b, $pos + 1);
        $b = substr($b, 0, $pos);
    }
    $registry = 'registry-1.docker.io';
    $teile = explode('/', $b);
    if (count($teile) > 1 && (strpos($teile[0], '.') !== false || strpos($teile[0], ':') !== false)) {
        $registry = array_shift($teile);
        $b = implode('/', $teile);
    } elseif (count($teile) === 1) {
        $b = 'library/' . $b;                 // offizielle Abbilder
    }
    return array($registry, $b, $marke);
}

function dk_http_kopf($url, $koepfe, $sekunden = 8)
{
    $ctx = stream_context_create(array('http' => array(
        'method'          => 'HEAD',
        'header'          => implode("\r\n", $koepfe),
        'timeout'         => $sekunden,
        'ignore_errors'   => true,
        // Umleitungen NICHT blind folgen: sonst geht ein Authorization-Kopf
        // an ein fremdbestimmtes Ziel.
        'follow_location' => 0,
        'user_agent'      => 'LoxBerry-Docker-NG',
    )));
    /* Frist fuer den VERBINDUNGSAUFBAU (C3): 'timeout' oben gilt nur fuers
     * Lesen, der Aufbau haengt an default_socket_timeout - ab Werk 60 s. */
    $frist_alt = ini_get('default_socket_timeout');
    @ini_set('default_socket_timeout', (string) (int) $sekunden);
    // Eigener Fehlerbehandler statt @: eine nicht erreichbare Registry ist ein
    // erwarteter Ausgang (-1 = nicht messbar), kein Befund. Siehe die
    // ausfuehrliche Begruendung bei dk_endpunkt_probe().
    set_error_handler(function () { return true; });
    $fp = fopen($url, 'rb', false, $ctx);
    /* Die Kopfzeilen kommen aus stream_get_meta_data(), nicht aus der
     * Zauber-Variable des HTTP-Wrappers (C10): PHP 8.5 meldet schon deren
     * blosse Erwaehnung als verfallen, auch hinter einer Wache (gemessen mit
     * php -l unter 8.5.11). Der Weg traegt von 7.4 bis 8.5. */
    $kopf = array();
    if ($fp) {
        $meta = stream_get_meta_data($fp);
        if (isset($meta['wrapper_data']) && is_array($meta['wrapper_data'])) { $kopf = $meta['wrapper_data']; }
        fclose($fp);
    }
    restore_error_handler();
    @ini_set('default_socket_timeout', (string) $frist_alt);
    return $kopf;
}

function dk_bild_digest_fern($bild)
{
    $t = dk_bild_zerlegen($bild);
    if ($t === null) { return ''; }
    list($registry, $pfad, $marke) = $t;
    $accept = 'Accept: application/vnd.oci.image.index.v1+json, '
            . 'application/vnd.docker.distribution.manifest.list.v2+json, '
            . 'application/vnd.oci.image.manifest.v1+json, '
            . 'application/vnd.docker.distribution.manifest.v2+json';
    $url = 'https://' . $registry . '/v2/' . $pfad . '/manifests/' . rawurlencode($marke);

    $kopf = dk_http_kopf($url, array($accept));
    $merkwort = '';
    foreach ($kopf as $z) {
        if (stripos($z, 'www-authenticate:') === 0 && preg_match('/Bearer\s+(.*)$/i', $z, $m)) {
            $p = array();
            foreach (explode(',', $m[1]) as $paar) {
                if (preg_match('/\s*([a-z_]+)="([^"]*)"/i', $paar, $mm)) { $p[strtolower($mm[1])] = $mm[2]; }
            }
            if (!empty($p['realm'])) {
                $tu = $p['realm'] . '?service=' . rawurlencode(isset($p['service']) ? $p['service'] : '')
                    . '&scope=' . rawurlencode('repository:' . $pfad . ':pull');
                $ctx = stream_context_create(array('http' => array(
                    'timeout' => 8, 'ignore_errors' => true, 'follow_location' => 0,
                    'user_agent' => 'LoxBerry-Docker-NG')));
                // 'timeout' im Kontext gilt nur fuers Lesen; fuer den
                // Verbindungsaufbau gilt default_socket_timeout (C3).
                $dk_frist_alt = ini_get('default_socket_timeout');
                @ini_set('default_socket_timeout', '8');
                set_error_handler(function () { return true; });
                $antwort = file_get_contents($tu, false, $ctx);
                restore_error_handler();
                @ini_set('default_socket_timeout', (string) $dk_frist_alt);
                $d = @json_decode((string) $antwort, true);
                if (isset($d['token']))        { $merkwort = $d['token']; }
                elseif (isset($d['access_token'])) { $merkwort = $d['access_token']; }
            }
            break;
        }
    }
    if ($merkwort !== '') {
        $kopf = dk_http_kopf($url, array($accept, 'Authorization: Bearer ' . $merkwort));
    }
    foreach ($kopf as $z) {
        if (stripos($z, 'docker-content-digest:') === 0) {
            return trim(substr($z, strpos($z, ':') + 1));
        }
    }
    return '';
}

/** Rueckgabe: array(name => 1 neuer Stand / 0 aktuell / -1 nicht messbar). */
function dk_updates_pruefen()
{
    $aus = array();
    foreach (dk_container() as $c) {
        $lokal = dk_bild_digest_lokal($c['image']);
        if ($lokal === '') { $aus[$c['name']] = -1; continue; }   // lokal gebaut
        $fern = dk_bild_digest_fern($c['image']);
        if ($fern === '') { $aus[$c['name']] = -1; continue; }    // keine Antwort
        $aus[$c['name']] = ($lokal === $fern) ? 0 : 1;
    }
    return $aus;
}

/* ==================================================================
 * Der Minutentakt
 *
 * Laeuft aus cron/cron.01min ueber bin/dockerng_takt.php. Er ist die einzige
 * Stelle, die schreibt - Oberflaeche und Endpunkt lesen.
 * ================================================================== */
/**
 * Wann wurde der Rechner gestartet? Unixzeit, oder 0.
 *
 * Aus /proc/uptime, weil das ohne Zusatzrecht lesbar ist. Fehlt die Datei -
 * kein Linux, oder ein sehr enger Container -, gibt es 0, und der Aufrufer
 * behandelt das wie "unbekannt".
 */
function dk_startzeit()
{
    if (!@is_readable('/proc/uptime')) { return 0; }
    $roh = trim((string) @file_get_contents('/proc/uptime'));
    if ($roh === '') { return 0; }
    $teile = explode(' ', $roh);
    $sek = (float) $teile[0];
    return $sek > 0 ? (int) (time() - $sek) : 0;
}

function dk_takt()
{
    $p = dk_paths();
    $ergebnis = array('gelaufen' => false, 'geschrieben' => false, 'grund' => '',
                      'zaehler' => -1, 'schleife' => 0, 'mqtt' => 0, 'befund' => null);

    /* ---- Die Sperre steht im Takt selbst (C9) ----
     *
     * Bis 1.3.9 sperrte nur cron/cron.01min. Der Knopf "Minutentakt jetzt
     * ausfuehren" und postinstall.sh riefen dk_takt() an der Sperre vorbei;
     * gemessen: Cron und Knopf gleichzeitig ergaben zwei Laeufe, der Zaehler
     * stieg um eins, ein Herzschlag ging verloren. Jetzt nimmt dk_takt()
     * selbst eine Sperre, nicht blockierend; wer nicht drankommt, bekommt
     * 'BESETZT' zurueck und sagt das.
     *
     * Das 'e' im Modus setzt close-on-exec: die Kindprozesse (docker) erben
     * die Sperre nicht (Fehlerklasse 3). Mit der Frist aus C3 kann ein
     * haengendes Kind sie ohnehin nicht dauerhaft halten.
     */
    if (!@is_dir($p['datadir'])) { @mkdir($p['datadir'], 0755, true); }
    $sperrdatei = @is_dir($p['datadir']) ? $p['datadir'] . '/takt.lock' : $p['datadir'] . '.takt.lock';
    $sperre = @fopen($sperrdatei, 'ce');
    if ($sperre === false) { $sperre = @fopen($sperrdatei, 'c'); }
    if ($sperre === false) {
        dk_log_gebremst('takt_sperre', 'Minutentakt: die Sperrdatei ' . $sperrdatei
            . ' laesst sich nicht oeffnen - der Takt laeuft nicht.');
        $ergebnis['grund'] = 'SPERRE_FEHLT';
        return $ergebnis;
    }
    if (!flock($sperre, LOCK_EX | LOCK_NB)) {
        fclose($sperre);
        $ergebnis['grund'] = 'BESETZT';
        return $ergebnis;
    }
    $ergebnis['gelaufen'] = true;
    dk_zeitgrenze(30);

    dk_config_vervollstaendigen();
    $cfg = dk_config();
    $alt = dk_zustandsdatei(true);
    $jetzt = time();

    /* ---- Eine Zeile je Systemstart ----
     *
     * Am Geraet gemessen (06.09.2026): sieben Stunden nach einem Neustart war
     * log/plugins/dockerng/ leer - bei rund 440 Taktlaeufen. Erkannt wird der
     * erste Lauf daran, dass der Stand aus der Zustandsdatei AELTER ist als
     * der Systemstart.
     */
    $start = dk_startzeit();
    if ($start > 0 && (!isset($alt['zeit']) || (int) $alt['zeit'] < $start)) {
        dk_log(sprintf(dk_t('LOG.TAKT_AUFGENOMMEN'),
            date('Y-m-d H:i:s', $start),
            isset($alt['zaehler']) ? (int) $alt['zaehler'] : 0));
    }

    $neu = array(
        'zeit'    => $jetzt,
        // Umlaufend bei 1000: Loxone bekommt einen Analogwert, der sich bei
        // JEDEM Takt aendert.
        'zaehler' => (isset($alt['zaehler']) ? ((int) $alt['zaehler'] + 1) : 0) % 1000,
    );

    list($ok) = dk_zustand(true);
    dk_container(true);
    dk_details(true);
    $z = dk_zaehlung();
    $neu['ok'] = $ok ? 1 : 0;

    /* ---- Neustartschleifen ----
     * Der RestartCount von Docker ist ein Lebenszeitzaehler. Brauchbar ist nur
     * das Delta ueber die Zeit, gezaehlt ueber ein gleitendes Fenster von
     * einer Stunde. Ein Neustart von Hand erhoeht den Zaehler nicht (am
     * Geraet nachgemessen 06.09.2026). Antwortet Docker nicht, bleibt das
     * Fenster stehen - sonst finge es danach von vorn an.
     */
    $fenster = isset($alt['neustarts']) && is_array($alt['neustarts']) ? $alt['neustarts'] : array();
    $schleife = 0;
    $neuestand = $ok ? array() : $fenster;
    foreach ($z['liste'] as $c) {
        if ($c['neustarts'] < 0) { continue; }
        $name = $c['name'];
        $vorher = isset($fenster[$name]) ? $fenster[$name] : null;
        $eintrag = array('wert' => (int) $c['neustarts'], 'seit' => $jetzt, 'delta' => 0);
        if (is_array($vorher) && isset($vorher['wert'])) {
            $zuwachs = max(0, (int) $c['neustarts'] - (int) $vorher['wert']);
            $alter = $jetzt - (int) (isset($vorher['seit']) ? $vorher['seit'] : $jetzt);
            $eintrag['delta'] = $alter > 3600 ? $zuwachs
                                : (int) (isset($vorher['delta']) ? $vorher['delta'] : 0) + $zuwachs;
            $eintrag['seit'] = $alter > 3600 ? $jetzt : (int) $vorher['seit'];
        }
        $neuestand[$name] = $eintrag;
        // Zweites Merkmal: laeuft seit weniger als einer Minute UND ist schon
        // einmal neu gestartet.
        $frisch = ($c['seit'] > 0 && ($jetzt - $c['seit']) < 60 && $c['neustarts'] > 0);
        if ($eintrag['delta'] >= (int) $cfg['schleife_grenze'] || $frisch) { $schleife++; }
    }
    $neu['neustarts'] = $neuestand;
    $neu['schleife']  = $schleife;

    /* ---- Plattenbelegung ---- hoechstens alle 15 Minuten. */
    $platz = isset($alt['platz']) && is_array($alt['platz']) ? $alt['platz'] : array();
    $letzte = isset($alt['platz_zeit']) ? (int) $alt['platz_zeit'] : 0;
    if ($jetzt - $letzte >= 900) {
        $platz = dk_platz_messen();
        $neu['platz_zeit'] = $jetzt;
    } else {
        $neu['platz_zeit'] = $letzte;
    }
    $neu['platz'] = $platz;

    /* ---- Abbild-Aktualisierungen ---- hoechstens einmal am Tag, nur wenn an. */
    $updates = isset($alt['updates']) && is_array($alt['updates']) ? $alt['updates'] : array();
    $uletzte = isset($alt['updates_zeit']) ? (int) $alt['updates_zeit'] : 0;
    if (!empty($cfg['updates_aktiv']) && $jetzt - $uletzte >= 86400) {
        $updates = dk_updates_pruefen();
        $neu['updates_zeit'] = $jetzt;
    } else {
        $neu['updates_zeit'] = $uletzte;
    }
    $neu['updates'] = empty($cfg['updates_aktiv']) ? array() : $updates;
    $neu['befund'] = isset($alt['befund']) ? (string) $alt['befund'] : '';
    $neu['mqtt'] = isset($alt['mqtt']) && is_array($alt['mqtt']) ? $alt['mqtt'] : array();

    /* ---- Schreiben, und das Ergebnis ansehen (C7) ----
     * Bis 1.3.9 wurde der Rueckgabewert verworfen: bei einem schreibgeschuetzten
     * Datenordner meldete '--einmal' "Herzschlag: 1", der Takt endete mit 0,
     * postinstall.sh schrieb "<OK> Zustandsdatei angelegt" - und zustand.json
     * stand still, ohne jede Protokollzeile (gemessen, Code-Pruefer T9).
     */
    if (!dk_zustandsdatei_schreiben($neu)) {
        dk_log_gebremst('zustand_schreiben', sprintf(dk_t('LOG.ZUSTAND_NICHT_GESCHRIEBEN'), $p['zustand']));
        $ergebnis['grund'] = 'NICHT_GESCHRIEBEN';
        flock($sperre, LOCK_UN);
        fclose($sperre);
        return $ergebnis;
    }
    dk_zustandsdatei(true);

    /* ---- Melden ---- nur bei WECHSEL des Befundes. */
    $befund = dk_befund();
    $vorher = $neu['befund'];
    if ($befund['kennung'] !== $vorher) {
        if ($befund['schwere'] <= 4) {
            dk_melden($befund['schwere'], $befund['text']);
            dk_log('Befund gewechselt: ' . $befund['text']);
        } elseif ($vorher !== '') {
            dk_melden(6, dk_t('MELDUNG.WIEDER_GUT'));
            dk_log('Befund gewechselt: wieder in Ordnung.');
        }
        $neu['befund'] = $befund['kennung'];
    }

    /* ---- MQTT ----
     * Das Lebenszeichen geht in JEDEM Takt hinaus; Zustaende nur bei
     * Aenderung und sonst hoechstens alle zehn Minuten (M6).
     */
    dk_abo_datei_nachfuehren();
    $neu['mqtt'] = dk_mqtt_takt($neu['mqtt'], $ok, $z);

    if (!dk_zustandsdatei_schreiben($neu)) {
        dk_log_gebremst('zustand_schreiben', sprintf(dk_t('LOG.ZUSTAND_NICHT_GESCHRIEBEN'), $p['zustand']));
        $ergebnis['grund'] = 'NICHT_GESCHRIEBEN';
    } else {
        $ergebnis['geschrieben'] = true;
    }
    dk_zustandsdatei(true);
    flock($sperre, LOCK_UN);
    fclose($sperre);
    $ergebnis['zaehler'] = $neu['zaehler'];
    $ergebnis['schleife'] = $schleife;
    $ergebnis['mqtt'] = isset($neu['mqtt']['versand']['gesendet']) ? (int) $neu['mqtt']['versand']['gesendet'] : 0;
    $ergebnis['befund'] = $befund;
    return $ergebnis;
}

/**
 * Den EIGENEN Endpunkt wirklich abrufen.
 *
 * Bis 1.3.0 bot der Reiter Test dafuer nur einen Link an, den der Anwender
 * selbst anklicken musste - das ist keine Pruefung, sondern eine Einladung zu
 * einer. Der Hausstandard verlangt einen echten Aufruf mit DREI Ausgaengen.
 *
 * Zwei Vorkehrungen, beide notwendig:
 *
 *   1. GEBREMST. Ein Aufruf bei jedem Seitenaufbau waere ein zweiter
 *      PHP-Prozess je Seitenaufruf - und auf einem Webserver mit wenigen
 *      Arbeitern kann sich das gegenseitig blockieren. Das Ergebnis wird
 *      deshalb 300 Sekunden gemerkt; nur der Knopf im Reiter Test misst neu.
 *   2. KURZE ZEITSCHRANKE. Antwortet der eigene Webserver nicht, darf die
 *      Oberflaeche nicht mit haengen. Vier Sekunden, dann 'nicht messbar'.
 *
 * Rueckgabe: array(stand, text, zeit) mit stand 1 = in Ordnung,
 * 0 = geantwortet, aber falsch, -1 = nicht messbar.
 */
function dk_endpunkt_probe($frisch = false)
{
    $p = dk_paths();
    $cache = $p['datadir'] . '/endpunktprobe.json';
    if (!$frisch) {
        $d = dk_json_lesen($cache);
        if (isset($d['zeit']) && (time() - (int) $d['zeit']) < 300) { return $d; }
    }

    $token = trim((string) dk_config()['aktionstoken']);
    if ($token === '') {
        $e = array('stand' => -1, 'text' => dk_t('TEST.A_EP_KEIN_TOKEN'), 'zeit' => time());
        dk_zustandsdatei_schreiben_hilf($cache, $e);
        return $e;
    }

    $port = (int) ($_SERVER['SERVER_PORT'] ?? 80);
    $schema = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
              || $port === 443 ? 'https' : 'http';
    $url = $schema . '://127.0.0.1:' . $port . '/plugins/' . $p['plugin']
         . '/index.php?token=' . rawurlencode($token) . '&selftest=1';

    $ctx = stream_context_create(array(
        'http' => array('timeout' => 4, 'ignore_errors' => true,
                        'follow_location' => 0, 'user_agent' => 'LoxBerry-Docker-NG'),
        // Der Aufruf geht an 127.0.0.1; ein selbst ausgestelltes Zertifikat ist
        // dort der Regelfall und kein Befund.
        'ssl'  => array('verify_peer' => false, 'verify_peer_name' => false),
    ));
    /* Der Fehlerbehandler wird ausgetauscht, statt sich auf @ zu verlassen:
     * ein nicht erreichbarer Webserver ist hier ein ERWARTETER Ausgang (-1).
     *
     * SEIT 1.3.9 ueber fopen() und stream_get_meta_data() (C10): PHP 8.5
     * meldet die Zauber-Variable fuer die Kopfzeilen als verfallen. Die Frist
     * gilt auch fuer den Verbindungsaufbau (default_socket_timeout, C3).
     */
    $frist_alt = ini_get('default_socket_timeout');
    @ini_set('default_socket_timeout', '4');
    set_error_handler(function () { return true; });
    $fp = fopen($url, 'rb', false, $ctx);
    $antwort = false;
    $kopf = array();
    if ($fp) {
        $meta = stream_get_meta_data($fp);
        if (isset($meta['wrapper_data']) && is_array($meta['wrapper_data'])) { $kopf = $meta['wrapper_data']; }
        $antwort = stream_get_contents($fp, 4096);
        fclose($fp);
    }
    restore_error_handler();
    @ini_set('default_socket_timeout', (string) $frist_alt);
    // Bei einer Umleitung gibt es mehrere Statuszeilen; es gilt die letzte.
    $code = 0;
    foreach ($kopf as $z) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', (string) $z, $m)) { $code = (int) $m[1]; }
    }

    if ($antwort === false && $code === 0) {
        $e = array('stand' => -1, 'text' => dk_t('TEST.A_EP_KEINE_ANTWORT'), 'zeit' => time());
    } elseif ($code === 200 && strpos((string) $antwort, 'SELFTEST;OK=1') === 0) {
        $e = array('stand' => 1, 'text' => dk_t('TEST.A_EP_OK'), 'zeit' => time());
    } else {
        $e = array('stand' => 0,
                   'text' => sprintf(dk_t('TEST.A_EP_FALSCH'), $code,
                                     substr(trim((string) $antwort), 0, 60)),
                   'zeit' => time());
    }
    dk_zustandsdatei_schreiben_hilf($cache, $e);
    return $e;
}

/** Kleine Nebendatei unteilbar schreiben. Kein Geheimnis darin - deshalb 0644. */
function dk_zustandsdatei_schreiben_hilf($pfad, $daten)
{
    $p = dk_paths();
    if (!@is_dir($p['datadir'])) { @mkdir($p['datadir'], 0755, true); }
    return dk_json_schreiben($pfad, $daten, 0644);
}

/* ==================================================================
 * Einmalmeldung - der Traeger fuer POST-Redirect-GET
 *
 * WARUM ES DAS BRAUCHT
 *
 * Bis 1.3.4 endete jeder Handler damit, dass die Seite unmittelbar nach dem
 * POST gerendert wurde. Ein Neuladen oder ein zweiter Klick schickte dieselbe
 * Aktion noch einmal - und bei "Portainer neu starten" ist das kein
 * Schoenheitsfehler: der Container geht ein zweites Mal weg.
 *
 * Am Geraet belegt (06.09.2026): EIN beabsichtigter Druck ergab ZWEI
 * Neustarts, zehn Sekunden auseinander. Der Handler wartet nach dem Neustart
 * bis zu zwanzig Sekunden auf einen Setup-Token; in dieser Zeit wirkt die
 * Seite haengend, und ein zweiter Klick liegt nahe.
 *
 * WARUM EINE DATEI UND KEINE SITZUNG
 *
 * Nach einer Umleitung ist das Ergebnis des Handlers fort - Meldung,
 * Setup-Token, Ergebnis des Taktlaufs. Eine PHP-Sitzung waere der uebliche
 * Weg, bringt aber Sitzungsdateien, Sperren und eine Abhaengigkeit mit, die
 * kein anderes Plugin dieses Hauses hat. Die Zustandsablage unter data/ gibt
 * es dagegen schon.
 *
 * Die Datei wird beim Lesen GELOESCHT. Sie traegt gelegentlich den
 * Einrichtungstoken von Portainer, deshalb 0600 - anders als die uebrigen
 * Nebendateien unter data/.
 * ================================================================== */
function dk_flash_datei()
{
    return dk_paths()['datadir'] . '/meldung.json';
}

function dk_flash_schreiben($daten)
{
    $p = dk_paths();
    if (!@is_dir($p['datadir'])) { @mkdir($p['datadir'], 0755, true); }
    return dk_json_schreiben(dk_flash_datei(), $daten, 0600);
}

/** Liest die Einmalmeldung und entfernt sie. Zweimal lesen ergibt nichts. */
function dk_flash_lesen()
{
    $f = dk_flash_datei();
    if (!@is_file($f)) { return array(); }
    $d = dk_json_lesen($f);
    @unlink($f);
    /* Eine liegengebliebene Meldung waere schlimmer als keine: sie erschiene
     * beim naechsten Oeffnen der Seite als Antwort auf eine Handlung, die
     * niemand ausgeloest hat. Alles aelter als zwei Minuten wird verworfen. */
    if (!isset($d['zeit']) || (time() - (int) $d['zeit']) > 120) { return array(); }
    return $d;
}

/**
 * Handler abschliessen: Ergebnis hinterlegen und auf die Seite umleiten.
 *
 * Danach steht im Browser ein GET. Neuladen wiederholt nichts mehr, und die
 * Rueckfrage "Formular erneut senden?" entfaellt.
 *
 * 303 ist die richtige Nummer: sie sagt ausdruecklich "hole das Ergebnis mit
 * GET ab". 302 ueberlassen manche Browser sich selbst und wiederholen den
 * POST.
 *
 * Die Umleitung ist RELATIV auf index.php und traegt nur den Reiternamen aus
 * der eigenen Positivliste - nichts, was ein Aufrufer beeinflussen koennte.
 */
function dk_weiter($reiter, $meldung = array(), $anhang = '')
{
    $erlaubt = array('settings', 'mqtt', 'loxone', 'test', 'log');
    if (!in_array($reiter, $erlaubt, true)) { $reiter = 'settings'; }
    if ($meldung) {
        $meldung['zeit'] = time();
        dk_flash_schreiben($meldung);
    }
    header('Location: index.php?form=' . $reiter . $anhang, true, 303);
    exit;
}

/**
 * Der Gesamtbefund in EINEM Satz - fuer das Benachrichtigungszentrum, den
 * LoxBerry-Healthcheck und den Reiter Test.
 *
 * Schwere nach der LoxBerry-Skala: 3 = Fehler, 4 = Warnung, 5 = in Ordnung.
 */
function dk_befund()
{
    $cfg = dk_config();
    if (dk_bin() === '') {
        return array('kennung' => 'KEIN_DOCKER', 'schwere' => 3,
                     'text' => dk_t('BEFUND.KEIN_DOCKER'));
    }
    list($ok, $grund, $grundtext) = dk_zustand();
    if (!$ok) {
        return array('kennung' => 'ZUGRIFF_' . $grund, 'schwere' => 3,
                     'text' => $grundtext);
    }
    /* SEIT 1.3.9 (O5): der Befund folgt dem SCHLECHTESTEN Punkt, und der
     * Minutentakt gehoert dazu. Bis 1.3.9 stand im Reiter Test "Laeuft der
     * Minutentakt? noch nie gelaufen" und direkt darunter "Gesamtbefund: In
     * Ordnung"; BEFUND.TAKT_NIE und TAKT_ALT kannte nur bin/healthcheck.
     * Gesammelt wird in der Reihenfolge, gewaehlt wird die hoechste Schwere
     * (kleinste Zahl); bei gleicher Schwere der erste Punkt. */
    $kand = array();
    $alter = dk_zustand_alter();
    $frisch = dk_takt_frisch();
    if ($alter < 0) {
        $kand[] = array('kennung' => 'TAKT_NIE', 'schwere' => 4, 'text' => dk_t('BEFUND.TAKT_NIE'));
    } elseif (!$frisch) {
        $kand[] = array('kennung' => 'TAKT_ALT', 'schwere' => 3,
                        'text' => sprintf(dk_t('BEFUND.TAKT_ALT'), (int) round($alter / 60)));
    }
    $z = dk_zaehlung();
    if ($z['fehlt'] > 0) {
        $namen = array();
        foreach ($z['wache'] as $n => $w) { if ($w === -1) { $namen[] = $n; } }
        $kand[] = array('kennung' => 'FEHLT:' . implode(',', $namen), 'schwere' => 3,
                        'text' => sprintf(dk_t('BEFUND.FEHLT'), implode(', ', $namen)));
    }
    // Die Neustartschleife stammt aus dem Minutentakt - nur, solange er frisch ist (C4).
    if ($frisch && $z['schleife'] > 0) {
        $kand[] = array('kennung' => 'SCHLEIFE:' . $z['schleife'], 'schwere' => 3,
                        'text' => sprintf(dk_t('BEFUND.SCHLEIFE'), $z['schleife']));
    }
    if ($z['ungesund'] > 0) {
        $namen = array();
        foreach ($z['liste'] as $c) { if ($c['gesund'] === 3) { $namen[] = $c['name']; } }
        $kand[] = array('kennung' => 'UNGESUND:' . implode(',', $namen), 'schwere' => 3,
                        'text' => sprintf(dk_t('BEFUND.UNGESUND'), implode(', ', $namen)));
    }
    if ($z['wache_ausfall'] > 0) {
        $kand[] = array('kennung' => 'AUSFALL:' . $z['wache_ausfall'], 'schwere' => 4,
                        'text' => sprintf(dk_t('BEFUND.AUSFALL'), $z['wache_ausfall']));
    }
    $zd = dk_zustandsdatei();
    $frei = ($frisch && isset($zd['platz']['frei_mb'])) ? (int) $zd['platz']['frei_mb'] : -1;
    if ((int) $cfg['platz_grenze_mb'] > 0 && $frei >= 0 && $frei < (int) $cfg['platz_grenze_mb']) {
        $kand[] = array('kennung' => 'PLATZ:' . $frei, 'schwere' => 4,
                        'text' => sprintf(dk_t('BEFUND.PLATZ'), $frei));
    }
    if (!$kand) {
        return array('kennung' => 'OK', 'schwere' => 5,
                     'text' => sprintf(dk_t('BEFUND.OK'), $z['laeuft'], $z['gesamt']));
    }
    $best = $kand[0];
    foreach ($kand as $k) { if ($k['schwere'] < $best['schwere']) { $best = $k; } }
    return $best;
}

/* ---------------- Portainer ----------------
 *
 * Portainer schreibt FARBIG. Zwischen "setup_token=" und dem Wert steht deshalb
 * eine ANSI-Escape-Sequenz - ohne deren Entfernen findet kein Suchmuster den
 * Token. Das Muster wurde gegen die tatsaechliche Ausgabe geprueft, nicht gegen
 * eine ausgedachte Beispielzeile: die waere farblos gewesen.
 */
/**
 * Das Protokoll EINES Containers.
 *
 * Bis 1.2.4 gab es das nur fuer Portainer, obwohl der Aufruf fuer jeden
 * Container derselbe ist. Der Name wird gegen das uebliche enge Muster
 * geprueft und abgewiesen, nicht zurechtgebogen.
 *
 * Hier ist 2>&1 richtig: viele Container - Portainer eingeschlossen -
 * schreiben ihre Startmeldungen auf die Fehlerausgabe, und genau die wird
 * gebraucht. ANSI-Farbcodes fliegen heraus, sonst findet kein Suchmuster
 * etwas darin.
 */
function dk_container_log($name, $zeilen = 400)
{
    if (!dk_name_gueltig($name)) { return ''; }
    $zeilen = max(1, min(2000, (int) $zeilen));
    list($aus, $fehler, $code) = dk_ausfuehren('docker logs --tail ' . $zeilen . ' -- '
                                . escapeshellarg($name));
    $roh = $aus . "\n" . $fehler;
    return preg_replace('/\x1B\[[0-9;]*[A-Za-z]/', '', $roh);
}

function dk_portainer_log($zeilen = 400)
{
    // Nur das Protokoll des EIGENEN Containers (C1) - nicht das eines
    // fremden, der zufaellig so heisst, wie ein Feld es einmal sagte.
    list($ok, $eigen) = dk_eigener_container();
    if (!$ok || $eigen === null) { return ''; }
    return dk_container_log($eigen[0], $zeilen);
}

/* ---------------- Container-Ports als Links (C11) ----------------
 *
 * Zusatzpunkt vom 30.09.2026. In der Containeruebersicht steht jeder
 * veroeffentlichte Port eines laufenden Containers als Link
 * http(s)://<Adresse des Seitenaufrufs>:<Port>/.
 *
 * Quelle ist die Spalte {{.Ports}} von 'docker ps' - derselbe Aufruf, der
 * die Liste ohnehin holt. Beim Seitenaufbau wird NICHTS im Netz geprobt:
 * ob hinter dem Port wirklich eine Weboberflaeche lauscht, weiss das
 * Plugin nicht, und es behauptet es auch nicht.
 */

/**
 * '0.0.0.0:9000->9000/tcp, :::9000->9000/tcp, 127.0.0.1:10300->10300/tcp,
 *  9443/tcp' -> Liste array(ip, hport, hbis, cport, cbis, proto).
 * Nicht veroeffentlichte Ports (ohne '->') fallen weg.
 */
function dk_ports_zerlegen($roh)
{
    $aus = array();
    foreach (explode(',', (string) $roh) as $teil) {
        $teil = trim($teil);
        if (!preg_match('/^(.*):(\d{1,5})(?:-(\d{1,5}))?->(\d{1,5})(?:-(\d{1,5}))?\/(tcp|udp|sctp)\z/', $teil, $m)) {
            continue;
        }
        $ip = $m[1];
        if (strlen($ip) > 1 && $ip[0] === '[' && substr($ip, -1) === ']') { $ip = substr($ip, 1, -1); }
        $aus[] = array('ip' => $ip, 'hport' => (int) $m[2], 'hbis' => ($m[3] !== '' ? (int) $m[3] : 0),
                       'cport' => (int) $m[4], 'cbis' => (isset($m[5]) && $m[5] !== '' ? (int) $m[5] : 0),
                       'proto' => $m[6]);
    }
    return $aus;
}

/**
 * Der Host des Seitenaufrufs ohne Port - geprueft, nie ungeprueft
 * uebernommen. Rueckgabe array(host fuer die Adresse, blanke Adresse fuer
 * den Vergleich) oder array('', '') wenn er nicht taugt.
 */
function dk_seitenhost($roh = null)
{
    $h = ($roh === null) ? (isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : '') : $roh;
    if (!is_string($h) || $h === '' || strlen($h) > 260) { return array('', ''); }
    if ($h[0] === '[') {
        // [IPv6] oder [IPv6]:Port
        if (!preg_match('/^\[([0-9A-Fa-f:.]{2,45})\](?::\d{1,5})?\z/', $h, $m)) { return array('', ''); }
        if (filter_var($m[1], FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) { return array('', ''); }
        return array('[' . $m[1] . ']', strtolower($m[1]));
    }
    if (!preg_match('/^([^:]+)(?::\d{1,5})?\z/', $h, $m)) { return array('', ''); }
    $n = $m[1];
    if (filter_var($n, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) { return array($n, $n); }
    if (strlen($n) <= 253
        && preg_match('/^[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?(?:\.[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?)*\.?\z/', $n)) {
        return array(rtrim($n, '.'), strtolower(rtrim($n, '.')));
    }
    return array('', '');
}

/** Ist die Bindung eine Ruecklaufadresse (127.0.0.0/8, ::1)? */
function dk_port_ruecklauf($ip)
{
    return strncmp($ip, '127.', 4) === 0 || $ip === '::1' || strtolower($ip) === 'localhost'
        || strtolower($ip) === '::ffff:127.0.0.1';
}

/**
 * Die Ports eines Containers als fertiges, maskiertes HTML fuer die
 * Uebersicht. $seitenhost = dk_seitenhost().
 */
function dk_ports_html($c, $seitenhost = null)
{
    if (empty($c['laeuft']) || !isset($c['ports']) || $c['ports'] === '') { return ''; }
    list($host, $hostblank) = ($seitenhost === null) ? dk_seitenhost() : $seitenhost;
    $eigene = array();
    if ($hostblank !== '') { $eigene[] = $hostblank; }
    $sa = isset($_SERVER['SERVER_ADDR']) && is_string($_SERVER['SERVER_ADDR']) ? $_SERVER['SERVER_ADDR'] : '';
    if ($sa !== '' && filter_var($sa, FILTER_VALIDATE_IP) !== false) { $eigene[] = strtolower($sa); }

    $links = array();
    $texte = array();
    foreach (dk_ports_zerlegen($c['ports']) as $p) {
        $ip = strtolower($p['ip']);
        $anzeige = ($p['ip'] !== '' ? (strpos($p['ip'], ':') !== false ? '[' . $p['ip'] . ']' : $p['ip']) . ':' : '')
                 . $p['hport'] . ($p['hbis'] ? '-' . $p['hbis'] : '')
                 . '->' . $p['cport'] . ($p['cbis'] ? '-' . $p['cbis'] : '') . '/' . $p['proto'];
        if (dk_port_ruecklauf($ip)) {
            $texte[$anzeige] = sprintf(dk_t('EINST.PORT_LOKAL'), $anzeige);
            continue;
        }
        $offen = ($ip === '' || $ip === '0.0.0.0' || $ip === '::' || in_array($ip, $eigene, true));
        if (!$offen || $p['proto'] !== 'tcp' || $p['hbis'] || $p['cbis'] || $host === ''
            || $p['hport'] < 1 || $p['hport'] > 65535) {
            $texte[$anzeige] = $anzeige;
            continue;
        }
        $schema = in_array($p['cport'], array(443, 8443, 9443), true) ? 'https' : 'http';
        $url = $schema . '://' . $host . ':' . $p['hport'] . '/';
        // 0.0.0.0 und :: nennen denselben Port zweimal - ein Link genuegt.
        $links[$url] = '<a href="' . dk_e($url) . '" target="_blank" rel="noopener">' . dk_e($url) . '</a>';
    }
    $teile = array_values($links);
    foreach ($texte as $t) {
        $teile[] = '<span class="sm-hilfe">' . dk_e($t) . '</span>';
    }
    return implode('<br>', $teile);
}

/* ---------------- Der eigene Container (C1, Entscheidung 9) ----------------
 *
 * Bis 1.3.9 erkannte das Plugin "seinen" Portainer allein am Feld
 * portainer_name. Gemessen im Pruefstand: mit dem Namen des MG-Gateways im
 * Feld startete der Knopf "Portainer neu starten" das Gateway neu, und die
 * Deinstallation hielt es an und loeschte es. Ein Eintrag im Feld oder eine
 * fremde Sicherung genuegte.
 *
 * Seit 1.3.9 gilt (Entscheidung des Hausherrn, 30.09.2026):
 *   - Mit Label de.loxberry.plugin.folder=<ordner> UND
 *     de.loxberry.plugin.name=dockerng: das ist unserer. postroot.sh legt
 *     den Container mit beiden Labels an.
 *   - Ohne Label (Altbestand vor 1.3.9): nur ein Container, der portainer
 *     HEISST und aus einem Abbild portainer/portainer-* STAMMT.
 *   - Alles andere wird nicht angefasst; stattdessen gibt es einen Hinweis
 *     mit Namen und Grund.
 * Dieselbe Funktion benutzen der Knopf, bin/dk_eigen.php (fuer
 * uninstall/uninstall und postroot.sh), der Endpunkt und der Reiter Test.
 */

/** Urteil ueber EINEN Container aus 'docker inspect': array(eigen, grund, name, abbild). */
function dk_eigen_urteil($info, $ordner)
{
    $name = isset($info['Name']) ? ltrim((string) $info['Name'], '/') : '';
    $bild = isset($info['Config']['Image']) ? (string) $info['Config']['Image'] : '';
    $l = (isset($info['Config']['Labels']) && is_array($info['Config']['Labels']))
        ? $info['Config']['Labels'] : array();
    $lf = isset($l['de.loxberry.plugin.folder']) ? (string) $l['de.loxberry.plugin.folder'] : '';
    $ln = isset($l['de.loxberry.plugin.name']) ? (string) $l['de.loxberry.plugin.name'] : '';
    if ($lf === (string) $ordner && $ln === 'dockerng') {
        return array(true, 'LABEL', $name, $bild);
    }
    foreach ($l as $k => $v) {
        if (strpos((string) $k, 'de.loxberry.plugin.') === 0) {
            return array(false, 'FREMDES_LABEL', $name, $bild);
        }
    }
    $portainerbild = preg_match('#^(docker\.io/)?portainer/portainer-[a-z0-9._-]+([:@]\S*)?\z#i', $bild) === 1;
    if ($name === 'portainer' && $portainerbild) {
        return array(true, 'ALTBESTAND', $name, $bild);
    }
    return array(false, $portainerbild ? 'FREMDER_NAME' : 'FREMDES_ABBILD', $name, $bild);
}

/**
 * Den eigenen Container finden.
 * Rueckgabe array(ok, eigen, fremde):
 *   ok     false = Docker war nicht zu fragen - dann wird NICHTS angefasst;
 *   eigen  array(name, grund LABEL|ALTBESTAND, abbild) oder null;
 *   fremde Liste array(name, grund, abbild) - andere Portainer-Abbilder, ein
 *          fremder Container namens portainer, ein zweiter eigener.
 */
function dk_eigener_container($frisch = false, $ordner = null)
{
    static $merk = array();
    $ordner = ($ordner === null) ? dk_paths()['plugin'] : (string) $ordner;
    if (!$frisch && isset($merk[$ordner])) { return $merk[$ordner]; }
    $nicht = array(false, null, array());
    if (dk_bin() === '') { return $merk[$ordner] = $nicht; }
    list($ok) = dk_zustand($frisch);
    if (!$ok) { return $merk[$ordner] = $nicht; }

    // 1. Wer traegt das eigene Label? (Ein Filter ohne Leerzeichen - er
    //    kommt unter jeder Schale heil an.)
    list($roh, $fehler, $code) = dk_ausfuehren('docker ps -a -q --no-trunc --filter '
        . escapeshellarg('label=de.loxberry.plugin.folder=' . $ordner));
    if ($code !== 0) { return $merk[$ordner] = $nicht; }
    $kand = array();
    foreach (preg_split('/\s+/', trim($roh)) as $id) {
        if (preg_match('/^[0-9a-f]{12,64}\z/', $id)) { $kand[] = $id; }
    }
    // 2. Kandidaten fuer den Altbestand und fuer den Hinweis: der Name
    //    portainer und jedes Portainer-Abbild.
    foreach (dk_container($frisch) as $c) {
        if ($c['name'] === 'portainer' || stripos($c['image'], 'portainer/portainer-') !== false) {
            $kand[] = $c['name'];
        }
    }
    $kand = array_values(array_unique($kand));
    if (!$kand) { return $merk[$ordner] = array(true, null, array()); }
    list($js, $fehler, $code) = dk_ausfuehren('docker inspect --type container -- '
        . implode(' ', array_map('escapeshellarg', $kand)));
    $d = json_decode($js, true);
    if (!is_array($d)) { return $merk[$ordner] = $nicht; }
    $eigen = null;
    $fremde = array();
    $gesehen = array();
    foreach ($d as $info) {
        if (!is_array($info)) { continue; }
        list($ist, $grund, $name, $bild) = dk_eigen_urteil($info, $ordner);
        if ($name === '' || isset($gesehen[$name])) { continue; }
        $gesehen[$name] = 1;
        if (!$ist) { $fremde[] = array($name, $grund, $bild); continue; }
        if ($eigen === null) {
            $eigen = array($name, $grund, $bild);
        } elseif ($grund === 'LABEL' && $eigen[1] !== 'LABEL') {
            // Ein Label schlaegt den Altbestand.
            $fremde[] = array($eigen[0], 'ZWEITER', $eigen[2]);
            $eigen = array($name, $grund, $bild);
        } else {
            $fremde[] = array($name, 'ZWEITER', $bild);
        }
    }
    return $merk[$ordner] = array(true, $eigen, $fremde);
}

/** Klartext zu den Containern, die nicht angefasst werden - fuer Meldungen (maskiert). */
function dk_eigen_hinweis($fremde)
{
    $teile = array();
    foreach ((array) $fremde as $f) {
        $teile[] = sprintf(dk_t('EIGEN.FREMD_EINTRAG'), dk_e($f[0]), dk_e($f[2]),
                           dk_e(dk_t('EIGEN.GRUND_' . $f[1])));
    }
    return implode(' ', $teile);
}

/**
 * Der bei der Installation VORGEGEBENE Einrichtungstoken, oder Leerstring.
 *
 * postroot.sh legt ihn mit --setup-token fest und schreibt ihn nach
 * config/plugins/<ordner>/setup_token (0600). Damit entfaellt das Fischen im
 * Containerprotokoll - und der Wert ueberlebt einen Neustart des Containers,
 * weil er in dessen Befehlszeile steht.
 *
 * Eigene Datei, nicht dockerng.json: ein Wert, ein Zweck.
 *
 * BERICHTIGT in 1.3.9 (I5): hier stand, die Datei "darf mit dem Container
 * verschwinden". Verschwunden ist sie aber mit jedem UPGRADE - der Installer
 * raeumt config/plugins/<ordner>/ ab, der Container behaelt den Token in
 * seiner Befehlszeile (in WSL gemessen: vor dem Upgrade vorhanden, danach
 * fehlt). Seit 1.3.9 liegt eine Zweitschrift (0600) neben dem Ordner;
 * preupgrade.sh sichert, postinstall.sh spielt bei einer Aktualisierung
 * zurueck, die Deinstallation raeumt sie ab.
 */
function dk_setup_token_vorgegeben()
{
    $f = dk_paths()['configdir'] . '/setup_token';
    if (!@is_file($f)) { return ''; }
    $t = trim((string) @file_get_contents($f));
    return preg_match('/^[A-Za-z0-9._\-]{6,128}$/', $t) ? $t : '';
}

function dk_setup_token()
{
    $v = dk_setup_token_vorgegeben();
    if ($v !== '') { return $v; }
    $roh = dk_portainer_log(400);
    foreach (array('/setup_token=([A-Za-z0-9._\-]{6,})/i',
                   '/setup[ _-]?token["\']?\s*[:=]\s*["\']?([A-Za-z0-9._\-]{6,})/i') as $muster) {
        if (preg_match_all($muster, $roh, $tr)) { return end($tr[1]); }
    }
    return '';
}

/**
 * Neu starten und einen FRISCHEN Setup-Token holen.
 *
 * Bis 1.2.3 lief das so: docker restart, drei Sekunden warten, docker logs
 * --tail 400 durchsuchen, letzten Treffer nehmen. Ein Neustart loescht das
 * Containerprotokoll aber NICHT - in denselben 400 Zeilen stehen die Ausgaben
 * von vor und nach dem Neustart. Braucht Portainer laenger als drei Sekunden
 * bis zur Ausgabe des neuen Tokens - auf einem Raspberry Pi der Regelfall -,
 * dann war der letzte Treffer der ALTE, laengst abgelaufene Token. Die
 * Oberflaeche zeigte ihn gross an und schrieb darunter, das Einrichten sei
 * jetzt fuenf Minuten lang moeglich. Portainer lehnte ihn ab.
 *
 * Jetzt wird der Token VOR dem Neustart gemerkt und danach so lange gewartet,
 * bis ein ANDERER auftaucht. Ausserdem wird der Rueckgabewert des Neustarts
 * ausgewertet, statt ihn zu verwerfen: "Neustart fehlgeschlagen" und "kein
 * Token gefunden" sind zwei verschiedene Auskuenfte.
 *
 * Rueckgabe: array(neustart_ok, token). Token '' heisst: keiner gefunden -
 * bei einem bereits eingerichteten Portainer der Normalfall, kein Fehler.
 */
function dk_portainer_neustart($wartesekunden = 20)
{
    /* Rueckgabe seit 1.3.9: array(neustart_ok, token, grund, name).
     * grund: OK | NICHT_PRUEFBAR | KEIN_EIGENER | FEHLGESCHLAGEN.
     * Neu gestartet wird NUR der eigene Container (C1) - mit '--' vor dem
     * Namen (C6). Bis 1.3.9 nahm der Knopf den Namen aus dem Feld
     * portainer_name und startete gemessen auch das MG-Gateway neu. */
    list($ok, $eigen) = dk_eigener_container(true);
    if (!$ok) { return array(false, '', 'NICHT_PRUEFBAR', ''); }
    if ($eigen === null) { return array(false, '', 'KEIN_EIGENER', ''); }
    $name = $eigen[0];

    /* Ist der Token vorgegeben (ab 1.3.0 der Regelfall), aendert er sich beim
     * Neustart NICHT - er steht in der Befehlszeile des Containers. */
    $fest = dk_setup_token_vorgegeben();
    $vorher = ($fest !== '') ? $fest : dk_setup_token();

    list($aus, $fehler, $code) = dk_ausfuehren('docker restart -- ' . escapeshellarg($name));
    if ($code !== 0) {
        dk_log('Container ' . $name . ' liess sich nicht neu starten '
            . '(Rueckgabewert ' . $code . '): ' . ($fehler !== '' ? $fehler : 'ohne Meldung'));
        return array(false, '', 'FEHLGESCHLAGEN', $name);
    }
    dk_log('Container ' . $name . ' neu gestartet (' . ($eigen[1] === 'LABEL'
        ? 'am Label erkannt' : 'Altbestand: Name und Abbild') . ').');
    dk_zustand(true);
    dk_container(true);
    if ($fest !== '') {
        sleep(3);
        return array(true, $fest, 'OK', $name);
    }

    $ende = time() + max(3, (int) $wartesekunden);
    do {
        sleep(2);
        $jetzt = dk_setup_token();
        if ($jetzt !== '' && $jetzt !== $vorher) {
            return array(true, $jetzt, 'OK', $name);
        }
    } while (time() < $ende);

    // Nichts Neues aufgetaucht. Den alten NICHT ausgeben - er ist abgelaufen.
    return array(true, '', 'OK', $name);
}

function dk_portainer_laeuft()
{
    // Der EIGENE Container (C1). Ob er laeuft, sagt die Containerliste:
    // ein pausierter Container ist dort nicht 'running' (docker inspect
    // meldete ihn als Running=true).
    list($ok, $eigen) = dk_eigener_container();
    if (!$ok || $eigen === null) { return false; }
    foreach (dk_container() as $c) {
        if ($c['name'] === $eigen[0]) { return $c['laeuft'] === 1; }
    }
    return false;
}

/**
 * Auf welchem Port ist Portainer wirklich erreichbar?
 *
 * Bis 1.2.3 war das Feld "Port der Portainer-Oberflaeche" ein Bedienelement
 * ohne Wirkung: postroot.sh verdrahtet -p=9000:9000 fest, das Feld aenderte
 * ausschliesslich das Ziel des Oeffnen-Knopfes. Wer wegen einer Portbelegung
 * 9001 eintrug und speicherte, bekam beim Klick "Verbindung abgelehnt". Genau
 * das, was die plugin.cfg an anderer Stelle (CUSTOM_LOGLEVELS) als schlimmer
 * als gar kein Bedienelement bezeichnet.
 *
 * Gefragt wird jetzt der Container selbst. Der eingestellte Wert bleibt die
 * Rueckfallebene fuer den Fall, dass Docker nicht ansprechbar ist oder der
 * Container gar nicht laeuft - und die Oberflaeche sagt, welcher der beiden
 * Werte gerade gilt.
 *
 * Rueckgabe: array(port, gemessen, schema) - gemessen=1 heisst: vom Container.
 * Das Schema ergibt sich aus dem CONTAINER-Port, nicht aus dem Hostport:
 * postroot.sh gibt 9000 (HTTP, mit --http-enabled) und 9443 (HTTPS) frei.
 */
function dk_portainer_port()
{
    /* SEIT 1.3.9 (C12) aus dk_portainer_ports_ist(): die Ports, die der
     * eigene Container WIRKLICH gebunden hat (docker inspect, auch bei einem
     * angehaltenen Container). Rueckgabe wie bisher array(port, gemessen,
     * schema): zuerst HTTP, sonst HTTPS, sonst die Einstellung. */
    static $p = null;
    if ($p !== null) { return $p; }
    $cfg = dk_config();
    $vorgabe = (int) $cfg['portainer_port'];
    list($ok, $h, $s) = dk_portainer_ports_ist();
    if ($ok && $h > 0) { return $p = array($h, 1, 'http'); }
    if ($ok && $s > 0) { return $p = array($s, 1, 'https'); }
    return $p = array($vorgabe, 0, 'http');
}

/**
 * Die Ports des EIGENEN Containers aus 'docker inspect' (C12).
 * Rueckgabe array(ok, http, https); -1 = nicht gebunden. ok false: kein
 * eigener Container oder Docker nicht zu fragen.
 */
function dk_portainer_ports_ist()
{
    static $e = null;
    if ($e !== null) { return $e; }
    list($ok, $eigen) = dk_eigener_container();
    if (!$ok || $eigen === null) { return $e = array(false, -1, -1); }
    list($aus, $fehler, $code) = dk_ausfuehren('docker inspect --type container --format '
        . escapeshellarg('{{json .HostConfig.PortBindings}}') . ' -- ' . escapeshellarg($eigen[0]));
    $d = json_decode(trim($aus), true);
    if ($code !== 0 || !is_array($d)) { return $e = array($code === 0 && trim($aus) === 'null', -1, -1); }
    $h = -1;
    $s = -1;
    foreach (array('9000/tcp' => 'h', '9443/tcp' => 's') as $innen => $wohin) {
        if (!isset($d[$innen]) || !is_array($d[$innen])) { continue; }
        foreach ($d[$innen] as $b) {
            $hp = (is_array($b) && isset($b['HostPort']) && is_string($b['HostPort'])) ? $b['HostPort'] : '';
            if (preg_match('/^\d{1,5}\z/', $hp) && (int) $hp >= 1 && (int) $hp <= 65535) {
                if ($wohin === 'h') { $h = (int) $hp; } else { $s = (int) $hp; }
                break;
            }
        }
    }
    return $e = array(true, $h, $s);
}

/**
 * Welche TCP-Ports lauschen auf diesem Rechner? (C12)
 * Zuerst 'ss -ltnH', sonst /proc/net/tcp und tcp6 (Zustand 0A = LISTEN).
 * Keine Probe ins Netz. Rueckgabe array(ok, Liste der Ports).
 */
function dk_ports_belegt()
{
    $a = array();
    $rc = 1;
    @exec(dk_zeitvorsatz(5) . 'ss -ltnH 2>' . (DIRECTORY_SEPARATOR === '\\' ? 'NUL' : '/dev/null'), $a, $rc);
    if ($rc === 0) {
        $ports = array();
        foreach ($a as $z) {
            $f = preg_split('/\s+/', trim($z));
            if (count($f) >= 4 && preg_match('/:(\d{1,5})\z/', $f[3], $m)) { $ports[] = (int) $m[1]; }
        }
        return array(true, array_values(array_unique($ports)));
    }
    $gelesen = false;
    $ports = array();
    foreach (array('/proc/net/tcp', '/proc/net/tcp6') as $f) {
        $roh = @file_get_contents($f);
        if ($roh === false) { continue; }
        $gelesen = true;
        foreach (explode("\n", $roh) as $z) {
            $t = preg_split('/\s+/', trim($z));
            if (count($t) < 4 || $t[3] !== '0A') { continue; }
            $teil = explode(':', $t[1]);
            if (count($teil) === 2 && preg_match('/^[0-9A-Fa-f]{4}\z/', $teil[1])) { $ports[] = hexdec($teil[1]); }
        }
    }
    return array($gelesen, array_values(array_unique($ports)));
}

/**
 * Den EIGENEN Portainer mit den eingestellten Ports neu anlegen (C12).
 * Nur der eigene Container (Entscheidung 9); /opt/portainer bleibt (die
 * Daten liegen dort, nicht im Container). Kein Ausweichen: ist ein
 * gewuenschter Port belegt, geschieht nichts.
 * Rueckgabe array(ok, grund, name, detail); grund OK | NICHT_PRUEFBAR |
 * KEIN_EIGENER | PORTS | PORTS_UNKLAR | PORT_BELEGT | RM | RUN.
 */
function dk_portainer_neu_anlegen()
{
    list($ok, $eigen) = dk_eigener_container(true);
    if (!$ok) { return array(false, 'NICHT_PRUEFBAR', '', ''); }
    if ($eigen === null) { return array(false, 'KEIN_EIGENER', '', ''); }
    $name = $eigen[0];
    $bild = $eigen[2];
    if (!dk_name_gueltig($name) || !preg_match('#^[A-Za-z0-9][A-Za-z0-9._/:@-]{0,254}\z#', $bild)) {
        return array(false, 'RM', $name, 'Name oder Abbild unbrauchbar');
    }
    $cfg = dk_config();
    $h = (int) $cfg['portainer_port'];
    $s = (int) $cfg['portainer_https_port'];
    if ($h < 1024 || $h > 65535 || $s < 1024 || $s > 65535 || $h === $s) {
        return array(false, 'PORTS', $name, '');
    }
    list(, $ist_h, $ist_s) = dk_portainer_ports_ist();
    list($bok, $belegt) = dk_ports_belegt();
    if (!$bok) { return array(false, 'PORTS_UNKLAR', $name, ''); }
    foreach (array($h, $s) as $pt) {
        // Was der eigene Container selbst haelt, wird mit ihm frei.
        if (in_array($pt, $belegt, true) && $pt !== $ist_h && $pt !== $ist_s) {
            return array(false, 'PORT_BELEGT', $name, (string) $pt);
        }
    }
    $token = dk_setup_token_vorgegeben();
    list($aus, $fehler, $code) = dk_ausfuehren('docker rm -f -- ' . escapeshellarg($name), 60);
    if ($code !== 0) {
        dk_log('Neu anlegen: der Container ' . $name . ' liess sich nicht entfernen (Rueckgabewert '
            . $code . '): ' . ($fehler !== '' ? $fehler : 'ohne Meldung'));
        return array(false, 'RM', $name, $fehler !== '' ? $fehler : 'rc ' . $code);
    }
    $befehl = 'docker run --volume=/var/run/docker.sock:/var/run/docker.sock --volume=/opt/portainer:/data'
        . ' -p=' . $h . ':9000 -p=' . $s . ':9443 --name=' . escapeshellarg($name)
        . ' --restart=unless-stopped --detach=true'
        . ' --label=' . escapeshellarg('de.loxberry.plugin.folder=' . dk_paths()['plugin'])
        . ' --label=de.loxberry.plugin.name=dockerng '
        . escapeshellarg($bild) . ' --http-enabled'
        . ($token !== '' ? ' --setup-token ' . escapeshellarg($token) : '');
    list($aus, $fehler, $code) = dk_ausfuehren($befehl, 120);
    if ($code !== 0) {
        dk_log('Neu anlegen: der Container ' . $name . ' wurde entfernt, docker run scheiterte '
            . '(Rueckgabewert ' . $code . '): ' . ($fehler !== '' ? $fehler : 'ohne Meldung')
            . ' - die Daten unter /opt/portainer sind unberuehrt.');
        return array(false, 'RUN', $name, $fehler !== '' ? $fehler : 'rc ' . $code);
    }
    dk_log('Container ' . $name . ' neu angelegt (HTTP ' . $h . ', HTTPS ' . $s . ', Abbild ' . $bild
        . ', Labels gesetzt, /opt/portainer beibehalten).');
    dk_zustand(true);
    dk_container(true);
    return array(true, 'OK', $name, $h . '/' . $s);
}

/* ---------------- Protokoll ----------------
 *
 * Bis 1.1.0 gab es nur dk_log_lesen(). Geschrieben hat die Datei NIEMAND - der
 * Reiter Logdateien blieb deshalb dauerhaft leer, und zwar ohne dass irgendwo
 * ein Fehler sichtbar wurde. Gemeldet von einem Mitleser, am Quelltext
 * nachgeprueft und zutreffend.
 *
 * ACHTUNG, und das gehoert auch in den Reiter: <home>/log/ liegt auf dem
 * LoxBerry auf einer RAMDISK. Diese Datei ueberlebt keinen Neustart. Sie ist
 * eine Spur fuer die Fehlersuche im laufenden Betrieb, kein Archiv.
 */
function dk_log($text)
{
    if (dk_log_aus()) { return false; }
    $p = dk_paths();
    if (!@is_dir($p['logdir'])) { @mkdir($p['logdir'], 0775, true); }
    // Rotation, damit eine Dauerstoerung die Ramdisk nicht vollschreibt.
    clearstatcache(true, $p['log']);
    if (@is_file($p['log']) && @filesize($p['log']) > 262144) {
        $rest = array_slice(@file($p['log'], FILE_IGNORE_NEW_LINES) ?: array(), -300);
        @file_put_contents($p['log'], implode("\n", $rest) . "\n");
    }
    return @file_put_contents($p['log'],
        '[' . date('Y-m-d H:i:s') . '] ' . $text . "\n", FILE_APPEND) !== false;
}

/**
 * Protokoll abschalten - fuer die Aufrufe als root aus den Hakenskripten
 * (bin/dk_eigen.php, --mqtt-leeren): eine Zeile von dort legte die
 * Protokolldatei root-eigen an, und danach schrieb der Minutentakt als
 * loxberry nichts mehr hinein (Installer-Pruefer J4).
 */
function dk_log_aus($setzen = null)
{
    static $aus = false;
    if ($setzen !== null) { $aus = (bool) $setzen; }
    return $aus;
}

/** Dieselbe Meldung hoechstens einmal je Zeitfenster. */
function dk_log_gebremst($schluessel, $text, $sekunden = 3600)
{
    if (dk_log_aus()) { return; }
    $p = dk_paths();
    $f = $p['logdir'] . '/.meld_' . preg_replace('/[^a-z0-9_]/i', '', $schluessel);
    $letzte = @is_file($f) ? (int) @file_get_contents($f) : 0;
    if (time() - $letzte >= $sekunden) {
        if (!@is_dir($p['logdir'])) { @mkdir($p['logdir'], 0775, true); }
        @file_put_contents($f, (string) time());
        dk_log($text);
    }
}

/**
 * Die letzten Zeilen einer Datei - rueckwaerts gelesen.
 *
 * Der Hausstandard verbietet fuer die Protokollanzeige ausdruecklich beides:
 * die ganze Datei einlesen UND exec("tail"). Gemessen an 12.000 Zeilen
 * (610 kB), je 20 Durchlaeufe, Spitzenspeicher in einem eigenen Prozess:
 *
 *     file() + array_reverse    0,37 ms   zusaetzlich 2048 kB
 *     exec("tail -n 400")       2,17 ms   zusaetzlich    0 kB
 *     rueckwaerts mit fseek     0,05 ms   zusaetzlich    0 kB
 *
 * Ein Prozessstart kostet mehr, als das Einlesen je gespart hat.
 *
 * Bis 1.3.0 stand hier file() - die Rotation begrenzt die Datei zwar auf
 * 256 kB, aber die lagen bei jedem Seitenaufruf im Arbeitsspeicher, und
 * log/ liegt auf dem LoxBerry auf einer Ramdisk.
 *
 * Erst fragen, dann oeffnen: ein @fopen() auf eine fehlende Datei ist stumm,
 * aber nicht folgenlos - ein gesetzter Fehlerbehandler sieht die Warnung
 * trotzdem. Die Protokolldatei fehlt regelmaessig, vor dem ersten Start gibt
 * es sie noch gar nicht.
 */
function dk_log_ende($datei, $anzahl = 200, $block = 8192)
{
    if (!@is_file($datei)) { return array(); }
    $fp = @fopen($datei, 'rb');
    if ($fp === false) { return array(); }
    fseek($fp, 0, SEEK_END);
    $pos = ftell($fp);
    $puffer = '';
    $zeilen = array();
    while ($pos > 0 && count($zeilen) <= $anzahl) {
        $lese = (int) min($block, $pos);
        $pos -= $lese;
        fseek($fp, $pos, SEEK_SET);
        $puffer = fread($fp, $lese) . $puffer;
        $zeilen = explode("\n", $puffer);
    }
    fclose($fp);
    $zeilen = array_values(array_filter(array_map('rtrim', $zeilen), 'strlen'));
    return array_slice($zeilen, -$anzahl);
}

function dk_log_lesen($zeilen = 200)
{
    $z = dk_log_ende(dk_paths()['log'], $zeilen);
    return $z ? implode("\n", $z) . "\n" : '';
}

function dk_log_leeren()
{
    $p = dk_paths();
    if (!@is_dir($p['logdir'])) { @mkdir($p['logdir'], 0775, true); }
    return @file_put_contents($p['log'],
        '[' . date('Y-m-d H:i:s') . '] ' . dk_t('LOG.GELEERT') . "\n") !== false;
}

/* ---------------- Loxone-Vorlage ----------------
 *
 * Nachbau der Bausteine aus LoxBerry::LoxoneTemplateBuilder; das Modul gibt es
 * nur in Perl. Attributreihenfolge, CRLF als Zeilenende und der Tabulator vor
 * den Kindelementen entsprechen dem Original - Vorlage ist
 * ap_xml_virtual_in_http() aus dem APC-UPS-Plugin.
 *
 * Maskiert wird mit ENT_XML1: ein Anfuehrungszeichen im Containernamen zerlegt
 * die Datei sonst, und Loxone Config meldet dazu nichts Brauchbares.
 */
function dk_x($s)
{
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_XML1, 'UTF-8');
}

/**
 * Die Sammelfelder der Statuszeile - EINE Quelle.
 *
 * Bis 1.2.3 stand die Liste dreimal da: im XML-Erzeuger, in der Tabelle der
 * Befehlserkennungen und als von Hand getippte Beispielzeile. Die drei sind
 * auseinandergelaufen: PORTAINER wurde gesendet, stand aber weder in der
 * Tabelle noch in der Vorlage, und GRUND fehlte in der Beispielzeile. Wer
 * eine Liste dreimal fuehrt, fuehrt sie zweimal falsch.
 *
 * Grenzen realistisch: Loxone zieht daraus Reglerbereiche und die
 * Plausibilitaetspruefung. Alles offen zu lassen verschenkt beides.
 *
 * Feld: array(Schluessel, Bedeutung, MinVal, MaxVal, Beispielwert)
 */
function dk_lox_felder()
{
    /* Feld: array(Schluessel, Kachelname, MinVal, MaxVal, Beispielwert, Einheit)
     *
     * SEIT 1.3.9 (O8): Kachelnamen hoechstens 40 Zeichen - der Comment wird
     * in Loxone Config zum Anzeigenamen (Regeln/07). Unit mit Einheit oder
     * '<v>'. ZAEHLER, SCHLEIFE und PLATZFREI koennen -1 senden (Takt steht,
     * C4) - MinVal traegt das, sonst wuerde -1 zur 0 (Regeln/07). TAKTALTER
     * ist neu und steht hinten.
     */
    return array(
        array('OK',        dk_t('LOX.F_OK'),        0, 1,   1, '<v>'),
        array('GESAMT',    dk_t('LOX.F_GESAMT'),    0, 999, 3, '<v>'),
        array('LAEUFT',    dk_t('LOX.F_LAEUFT'),    0, 999, 3, '<v>'),
        array('GESTOPPT',  dk_t('LOX.F_GESTOPPT'),  0, 999, 0, '<v>'),
        array('AUSFALL',   dk_t('LOX.F_AUSFALL'),   0, 999, 0, '<v>'),
        array('PAUSIERT',  dk_t('LOX.F_PAUSIERT'),  0, 999, 0, '<v>'),
        array('UNGESUND',  dk_t('LOX.F_UNGESUND'),  0, 999, 0, '<v>'),
        array('FEHLT',     dk_t('LOX.F_FEHLT'),     0, 999, 0, '<v>'),
        array('SCHLEIFE',  dk_t('LOX.F_SCHLEIFE'),  -1, 999, 0, '<v>'),
        array('PORTAINER', dk_t('LOX.F_PORTAINER'), 0, 1,   1, '<v>'),
        // Der Herzschlag. Er MUSS sich bei jedem Takt aendern - daran und nur
        // daran erkennt Loxone, dass der LoxBerry noch antwortet.
        array('ZAEHLER',   dk_t('LOX.F_ZAEHLER'),   -1, 999, 42, '<v>'),
        array('PLATZFREI', dk_t('LOX.F_PLATZFREI'), -1, 999999, 4096, '<v> MB'),
        array('TAKTALTER', dk_t('LOX.F_TAKTALTER'), -1, 99999999, 12, '<v> s'),
    );
}

/**
 * Die Beispielzeile fuer die Anleitung - aus demselben Bauplan wie die echte
 * Antwort in webfrontend/html/index.php. Wer hier ein Feld ergaenzt, ergaenzt
 * es dort mit; die Kongruenzprobe im Reiter Test zaehlt beides nach.
 */
function dk_beispielzeile()
{
    $teile = array('DOCKERNG');
    foreach (dk_lox_felder() as $f) {
        if ($f[0] === 'TAKTALTER') { continue; }   // steht hinten, wie im Endpunkt
        $teile[] = $f[0] . '=' . $f[4];
    }
    $teile[] = 'GRUND=-';
    $teile[] = 'C_portainer=1';
    $teile[] = 'H_portainer=2';
    $teile[] = 'TAKTALTER=12';
    return implode(';', $teile);
}

function dk_xml_virtual_in_http($host, $token)
{
    $crlf = "\r\n";
    $z = dk_zaehlung();

    // Der Rechnername stammt aus HTTP_HOST und ist damit vom Aufrufer
    // beeinflussbar - er MUSS maskiert werden, sonst zerlegt ein
    // Anfuehrungszeichen die Datei, und Loxone Config meldet dazu nichts
    // Brauchbares. Das kaufmaennische Und bleibt danach als &amp; stehen,
    // weil es in einem XML-Attribut so gehoert.
    // http oder https - danach, wie DIESE Seite gerade aufgerufen wurde.
    //
    // Der Miniserver spricht den LoxBerry im eigenen Netz an und nimmt
    // dafuer fast immer http. Wer seinen LoxBerry aber ausschliesslich ueber
    // https erreichbar gemacht hat, bekam bis 1.0.0 eine Vorlage mit einer
    // Adresse, die es nicht gibt - und der virtuelle Eingang blieb stumm,
    // ohne dass man der Vorlage etwas ansieht.
    $schema = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
              || (int) ($_SERVER['SERVER_PORT'] ?? 0) === 443
        ? 'https' : 'http';
    $adresse = dk_x($schema . '://' . $host . '/plugins/' . dk_paths()['plugin']
                    . '/index.php?token=' . $token . '&aktion=status');

    $felder = dk_lox_felder();

    /* Kopf und Eintraege sind gegen ap_xml_virtual_in_http() aus dem
     * APC-UPS-Plugin gebaut - dem Nachbau, der gegen die massgeblichen
     * Ausfuhren aus Loxone Config geprueft wurde.
     *
     * ERGAENZT in 1.2.4: bis 1.2.3 folgte diese Vorlage dem Stand der Referenz
     * VOR deren 1.2.0. Es fehlten HintText am Wurzelelement, das Kindelement
     * <Info templateType="2" minVersion="17010727"/> an erster Stelle sowie
     * Unit und HintText je Eintrag. Nachgezaehlt im Arbeitsordner: 36
     * Plugin-Ordner setzen das Info-Element, Docker NG war nicht darunter.
     */
    $o  = '<?xml version="1.0" encoding="utf-8"?>' . $crlf;
    $o .= '<VirtualInHttp HintText=""'
        . ' Title="' . dk_x('Docker NG') . '"'
        . ' Comment="' . dk_x(dk_t('LOX.XML_KOMMENTAR')) . '"'
        . ' Address="' . $adresse . '"'
        . ' PollingTime="60">' . $crlf;
    $o .= "\t" . '<Info templateType="2" minVersion="17010727"/>' . $crlf;
    foreach ($felder as $f) {
        list($schluessel, $titel, $min, $max, , $einheit) = $f;
        $o .= "\t" . '<VirtualInHttpCmd Title="' . dk_x('DOCKERNG_' . $schluessel) . '"'
            . ' Comment="' . dk_x($titel) . '"'
            . ' Check="' . dk_x($schluessel . '=\v') . '"'
            . ' Signed="true" Analog="true"'
            . ' SourceValLow="0" DestValLow="0"'
            . ' SourceValHigh="100" DestValHigh="100"'
            . ' DefVal="0"'
            . ' MinVal="' . (int) $min . '"'
            . ' MaxVal="' . (int) $max . '"'
            . ' Unit="' . dk_x($einheit) . '"'
            . ' HintText=""'
            . '/>' . $crlf;
    }
    /* Je erkanntem Container eine eigene Zeile - Titel je Geraet, keine
     * Platzhalter. Ohne Container bleibt es bei den Sammelwerten.
     *
     * BERICHTIGT in 1.2.4: hier stand Analog="false" zusammen mit dem
     * Analog-Platzhalter \v und der vollstaendigen Analog-Skalierung. Das ist
     * in sich widerspruechlich - \v liest den Zahlenwert eines ANALOGEN
     * Befehls aus. Im gesamten Arbeitsordner war das die einzige Fundstelle
     * von Analog="false" an einem VirtualInHttpCmd; alle 15 uebrigen
     * Fundstellen sitzen an VirtualOutCmd, wo sie hingehoeren. Der Wert ist
     * 0 oder 1, gelesen wird er wie jeder andere Zahlenwert.
     */
    /* Erzeugt wird ueber die WACHLISTE, nicht ueber den Fundbestand.
     *
     * Das ist nicht nur folgerichtig, sondern der eigentliche Gewinn: bis
     * 1.2.4 richtete sich die Importdatei nach dem MOMENTANEN Bestand, aenderte
     * sich also bei jedem neuen Container - und Loxone Config legt beim Import
     * neu an und ueberschreibt nichts. Zweimal importiert hiess doppelte
     * Objekte. Ueber eine Wachliste bleibt die Datei stabil.
     *
     * MinVal steht auf -1, weil ein Container der Wachliste, den es nicht
     * mehr gibt, genau diesen Wert bekommt.
     */
    foreach (dk_wachliste() as $name) {
        $sicher = preg_replace('/[^A-Za-z0-9_]/', '_', $name);
        $o .= "\t" . '<VirtualInHttpCmd Title="' . dk_x('DOCKERNG_C_' . $sicher) . '"'
            // Der Containername steht im Titel; der Kommentar wird in Loxone
            // Config zum Kachelnamen und bleibt deshalb kurz (O8).
            . ' Comment="' . dk_x(dk_t('LOX.F_CONTAINER_KURZ')) . '"'
            . ' Check="' . dk_x('C_' . $sicher . '=\v') . '"'
            . ' Signed="true" Analog="true"'
            . ' SourceValLow="0" DestValLow="0"'
            . ' SourceValHigh="100" DestValHigh="100"'
            . ' DefVal="0" MinVal="-1" MaxVal="1"'
            . ' Unit="' . dk_x('<v>') . '"'
            . ' HintText=""'
            . '/>' . $crlf;
        $o .= "\t" . '<VirtualInHttpCmd Title="' . dk_x('DOCKERNG_H_' . $sicher) . '"'
            . ' Comment="' . dk_x(dk_t('LOX.F_GESUND_KURZ')) . '"'
            . ' Check="' . dk_x('H_' . $sicher . '=\v') . '"'
            . ' Signed="true" Analog="true"'
            . ' SourceValLow="0" DestValLow="0"'
            . ' SourceValHigh="100" DestValHigh="100"'
            . ' DefVal="0" MinVal="-1" MaxVal="3"'
            . ' Unit="' . dk_x('<v>') . '"'
            . ' HintText=""'
            . '/>' . $crlf;
    }
    $o .= '</VirtualInHttp>' . $crlf;
    return $o;
}

/**
 * Container, deren Name nach der Saeuberung auf denselben Loxone-Schluessel
 * faellt.
 *
 * preg_replace('/[^A-Za-z0-9_]/','_') bildet 'mein-dienst' und 'mein.dienst'
 * auf 'mein_dienst' ab. Die Statuszeile enthaelt dann zweimal denselben
 * Schluessel, Loxone nimmt das erste Vorkommen, und der zweite Container ist
 * unbeobachtet - unsichtbar, denn in der Tabelle stehen zwei Zeilen, die wie
 * zwei getrennte Eingaenge aussehen. Gemeldet wird das jetzt, statt es
 * geschehen zu lassen.
 *
 * Rueckgabe: array(schluessel => array(name, name, …)) nur fuer Kollisionen.
 */
function dk_schluesselkollisionen($liste = null)
{
    if ($liste === null) { $liste = dk_container(); }
    $nach = array();
    foreach ($liste as $c) {
        $s = preg_replace('/[^A-Za-z0-9_]/', '_', $c['name']);
        $nach[$s][] = $c['name'];
    }
    $aus = array();
    foreach ($nach as $s => $namen) {
        if (count($namen) > 1) { $aus[$s] = $namen; }
    }
    return $aus;
}


/**
 * Eine Sicherungsdatei einlesen - und dabei NICHTS durchgehen lassen.
 *
 * Die sieben Punkte aus REGELN_2, und der wichtigste ist der dritte: eine
 * halb gueltige Datei ueberschreibt GAR NICHTS. Wer eine Sicherung
 * zurueckspielt, will entweder den ganzen Stand oder gar keinen - eine zur
 * Haelfte uebernommene Konfiguration ist schlimmer als die alte, und man
 * sieht es ihr nicht an.
 *
 * Unbekannte Schluessel sind eine Beanstandung, kein stiller Verlust: sie
 * stammen aus einer anderen Fassung oder einem anderen Plugin.
 *
 * Rueckgabe: array(Konfiguration|null, Beanstandungen[], uebernommene Werte).
 */
function dk_sicherung_lesen($roh)
{
    /* Rueckgabe seit 1.3.9: array(Konfiguration|null, Beanstandungen[],
     * uebernommene Werte, Hinweise[]).
     *
     * SEIT 1.3.9 wird JEDER Wert geprueft, mit denselben Grenzen wie im
     * Formular (C2, Bauart E). Bis dahin stand hier nur $neu[$k] = $w -
     * gemessen unter PHP 7.4, 8.4 und 8.5: ein Token als Liste wurde
     * uebernommen, Konfiguration UND Zweitschrift trugen die Liste, der
     * Endpunkt nahm danach ?token=Array an, und das Formularmerkmal war
     * hash_hmac('Array') - fuer jeden ausrechenbar. Port 99999, der Name
     * 'x;rm -rf' und das Praefix 'a/b#' gingen roh in die Datei.
     *
     * Der lesbare Kopf (_hinweis, _stand) wird uebergangen, nicht
     * beanstandet (O10, Regeln/05). Veraltete Schluessel (portainer_name)
     * werden uebergangen und genannt, damit eine Sicherung aus 1.3.8
     * zurueckspielbar bleibt - ein unzulaessiger Wert darin wird trotzdem
     * beanstandet. Ein leeres Token heisst "kein Token gesichert": das
     * geltende Merkwort bleibt, und die Meldung sagt das.
     */
    $mangel = array();
    $hinweis = array();
    $daten = json_decode((string) $roh, true);
    if (!is_array($daten)) {
        return array(null, array(dk_t('EINST.SICH_KEIN_JSON')), 0, array());
    }
    $neu = dk_vorgaben();
    $bekannt = array_keys($neu);
    $anzahl = 0;
    foreach ($daten as $k => $w) {
        $k = (string) $k;
        if ($k !== '' && $k[0] === '_') { continue; }
        if (in_array($k, dk_veraltete_schluessel(), true)) {
            if (!dk_name_gueltig($w)) {
                $mangel[] = sprintf(dk_t('EINST.SICH_WERT'), dk_e($k), dk_e(dk_wert_zeigen($w)),
                                    dk_t('FEHLER.NAME'));
            } else {
                $hinweis[] = sprintf(dk_t('EINST.SICH_VERALTET'), dk_e($k));
            }
            continue;
        }
        if (!in_array($k, $bekannt, true)) {
            $mangel[] = sprintf(dk_t('EINST.SICH_FREMD'), dk_e($k));
            continue;
        }
        list($ok, $norm, $warum) = dk_wert_pruefen($k, $w);
        if (!$ok) {
            $mangel[] = sprintf(dk_t('EINST.SICH_WERT'), dk_e($k), dk_e(dk_wert_zeigen($w)), $warum);
            continue;
        }
        $neu[$k] = $norm;
        $anzahl++;
    }
    if ($anzahl === 0) {
        $mangel[] = dk_t('EINST.SICH_LEER');
    }
    /* FEHLENDE Schluessel sind eine Beanstandung, kein stiller Rueckfall
     * (VolkswagenID 0.9.11, am 07.09.2026 ueber den Bestand ausgerollt).
     * Verglichen wird gegen die VORGABEN. */
    $fehlend = array();
    foreach (array_keys(dk_vorgaben()) as $fk) {
        if (!array_key_exists($fk, $daten)) {
            /* Ein Schluessel, den es erst seit 1.3.9 gibt, fehlt in jeder
             * aelteren Sicherung. Er ist kein Mangel: der geltende Wert
             * bleibt, und die Meldung sagt das (C12). */
            if ($fk === 'portainer_https_port') {
                $neu[$fk] = (int) dk_config()[$fk];
                $hinweis[] = sprintf(dk_t('EINST.SICH_NEU_VORGABE'), dk_e($fk));
                continue;
            }
            $fehlend[] = $fk;
        }
    }
    if (!$mangel && (int) $neu['portainer_port'] === (int) $neu['portainer_https_port']) {
        $mangel[] = dk_t('FEHLER.PORT_GLEICH');
    }
    if ($fehlend) {
        $mangel[] = sprintf(dk_t('EINST.SICH_FEHLEND'), count($fehlend),
            dk_e(implode(', ', $fehlend)));
    }
    if (!$mangel && $neu['aktionstoken'] === '') {
        $neu['aktionstoken'] = (string) dk_config()['aktionstoken'];
        $hinweis[] = dk_t('EINST.SICH_TOKEN_BLEIBT');
    }
    return array($mangel ? null : $neu, $mangel, $anzahl, $hinweis);
}

/** Ein Wert aus der Sicherung, kurz und lesbar, fuer eine Beanstandung. */
function dk_wert_zeigen($w)
{
    $t = is_string($w) ? $w : (string) json_encode($w, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    return strlen($t) > 40 ? substr($t, 0, 40) . '…' : $t;
}

/**
 * Die Sicherungsdatei bauen (O10): ein lesbarer Kopf mit '_' und alle
 * Schluessel der Vorgaben - samt Aktionstoken, ohne den die Datei nach dem
 * Zurueckspielen wertlos waere. Das Formularmerkmal gehoert nicht hinein.
 */
function dk_sicherung_bauen()
{
    $cfg = dk_config();
    $aus = array(
        '_hinweis' => dk_t('EINST.SICH_KOPF'),
        '_stand'   => date('Y-m-d H:i:s'),
        '_plugin'  => 'Docker NG',
    );
    foreach (array_keys(dk_vorgaben()) as $k) {
        $aus[$k] = $cfg[$k];
    }
    return $aus;
}
