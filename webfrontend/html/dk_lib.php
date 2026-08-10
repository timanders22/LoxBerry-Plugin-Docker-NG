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

    $p = array(
        'home'      => $home,
        'plugin'    => $ordner,
        'config'    => $home . '/config/plugins/' . $ordner . '/dockerng.json',
        'configdir' => $home . '/config/plugins/' . $ordner,
        'sicherung' => $home . '/config/plugins/' . $ordner . '.backup.json',
        'logdir'    => $home . '/log/plugins/' . $ordner,
        'log'       => $home . '/log/plugins/' . $ordner . '/dockerng.log',
    );
    return $p;
}

/* ---------------- Konfiguration ---------------- */

function dk_vorgaben()
{
    return array(
        'portainer_port' => 9000,
        'portainer_name' => 'portainer',
        'aktionstoken'   => '',
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

function dk_config()
{
    static $cfg = null;
    if ($cfg !== null) { return $cfg; }
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
     * Gemeldet von einem Mitleser, zutreffend, in 1.2.0 behoben.
     */
    $roh = @is_file($p['config']) ? trim((string) @file_get_contents($p['config'])) : '';
    if (($roh === '' || $roh === '{}') && @is_file($p['sicherung'])) {
        @mkdir($p['configdir'], 0755, true);
        if (@copy($p['sicherung'], $p['config'])) {
            @chmod($p['config'], 0600);
            dk_log('Konfiguration war leer oder fehlte - aus der Sicherung '
                . $p['sicherung'] . ' wiederhergestellt. Das Merkwort fuer den '
                . 'Endpunkt bleibt damit gueltig.');
        }
    }

    $cfg = array_merge(dk_vorgaben(), dk_json_lesen($p['config']));

    // Grenzen durchsetzen, statt Werte ungeprueft weiterzureichen.
    $cfg['portainer_port'] = max(1, min(65535, (int) $cfg['portainer_port']));
    $name = trim((string) $cfg['portainer_name']);
    $cfg['portainer_name'] = preg_match('/^[A-Za-z0-9_.\-]{1,64}$/', $name) ? $name : 'portainer';
    $cfg['aktionstoken']   = (string) $cfg['aktionstoken'];
    return $cfg;
}

function dk_config_schreiben($cfg)
{
    $p = dk_paths();
    if (!@is_dir($p['configdir'])) { @mkdir($p['configdir'], 0755, true); }
    $ok = @file_put_contents($p['config'],
        json_encode($cfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    if ($ok === false) {
        dk_log('Die Konfiguration liess sich NICHT schreiben: ' . $p['config']);
        return false;
    }
    @chmod($p['config'], 0600);
    // Sicherung mitziehen. Sie enthaelt das Merkwort, deshalb ebenfalls 0600.
    if (@copy($p['config'], $p['sicherung'])) {
        @chmod($p['sicherung'], 0600);
    }
    dk_log('Konfiguration gespeichert (Port ' . (int) $cfg['portainer_port']
        . ', Container ' . $cfg['portainer_name'] . ').');
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

function dk_token()
{
    $cfg = dk_config();
    if (trim($cfg['aktionstoken']) === '') {
        $cfg['aktionstoken'] = dk_token_neu();
        dk_config_schreiben($cfg);
    }
    return (string) $cfg['aktionstoken'];
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
function dk_ausfuehren($befehl)
{
    $aus = array();
    $code = 0;
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
 * Warum klappt der Zugriff auf Docker nicht?
 *
 * Rueckgabe: array(ok, grund, meldung). 'grund' ist ein kurzes Merkwort fuer
 * den Miniserver, 'meldung' der Klartext fuer die Oberflaeche.
 */
function dk_zustand()
{
    static $z = null;
    if ($z !== null) {
        return $z;
    }
    if (dk_bin() === '') {
        $z = array(0, 'KEIN_DOCKER',
                   'Das Programm docker ist nicht vorhanden.');
        return $z;
    }
    list($aus, $fehler, $code) = dk_ausfuehren('docker ps --format "{{.Names}}"');
    if ($code === 0) {
        $z = array(1, '', '');
        return $z;
    }
    $t = strtolower($fehler);
    if (strpos($t, 'permission denied') !== false || strpos($t, 'connect: permission') !== false) {
        $z = array(0, 'KEINE_RECHTE',
            'Der Webserver darf nicht auf den Docker-Socket zugreifen. Das ist nach '
            . 'einer frischen Installation der Regelfall und KEIN Defekt: der Benutzer '
            . 'loxberry wurde der Gruppe docker hinzugefuegt, aber Linux zieht neue '
            . 'Gruppen fuer bereits laufende Prozesse nicht nach - der Webserver laeuft '
            . 'noch mit den alten. Abhilfe: den LoxBerry einmal neu starten. Wer nicht '
            . 'neu starten will, kann auch nur den Webserver durchstarten: '
            . 'sudo systemctl restart apache2 - dabei bricht diese Seite kurz ab.');
        return $z;
    }
    if (strpos($t, 'cannot connect to the docker daemon') !== false
        || strpos($t, 'is the docker daemon running') !== false) {
        $z = array(0, 'DIENST_AUS',
            'Der Docker-Dienst laeuft nicht. Pruefen mit: systemctl status docker, '
            . 'starten mit: sudo systemctl enable --now docker');
        return $z;
    }
    $z = array(0, 'FEHLER', $fehler !== '' ? $fehler
               : ('docker endete mit Rueckgabewert ' . $code . ' ohne Meldung.'));
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
    if (dk_bin() === '') { return ''; }
    list($aus, $fehler, $code) = dk_ausfuehren('docker --version');
    return trim($code === 0 ? $aus : $fehler);
}

/**
 * Liste aller Container.
 *
 * Getrennt wird an Tabulatoren, nicht an Leerzeichen: Abbildnamen und
 * Zustandstexte enthalten selbst welche ("Up 3 hours (healthy)").
 */
function dk_container()
{
    if (dk_bin() === '') { return array(); }
    list($ok) = dk_zustand();
    if (!$ok) {
        // Nicht so tun, als gaebe es keine Container. Wer hier eine leere
        // Liste bekommt, soll sie an dk_zustand() halten.
        return array();
    }
    list($roh, $fehler, $code) = dk_ausfuehren(
        "docker ps -a --format '{{.Names}}\t{{.Image}}\t{{.Status}}'");
    if ($code !== 0) {
        return array();
    }
    $liste = array();
    foreach (explode("\n", trim($roh)) as $zeile) {
        if (trim($zeile) === '') { continue; }
        $t = explode("\t", $zeile);
        if (count($t) < 3) { continue; }
        $liste[] = array(
            'name'   => $t[0],
            'image'  => $t[1],
            'status' => $t[2],
            'laeuft' => stripos($t[2], 'Up') === 0 ? 1 : 0,
        );
    }
    return $liste;
}

function dk_zaehlung()
{
    $alle = dk_container();
    $lauf = 0;
    foreach ($alle as $c) { $lauf += $c['laeuft']; }
    return array('gesamt' => count($alle), 'laeuft' => $lauf,
                 'gestoppt' => count($alle) - $lauf, 'liste' => $alle);
}

/* ---------------- Portainer ----------------
 *
 * Portainer schreibt FARBIG. Zwischen "setup_token=" und dem Wert steht deshalb
 * eine ANSI-Escape-Sequenz - ohne deren Entfernen findet kein Suchmuster den
 * Token. Das Muster wurde gegen die tatsaechliche Ausgabe geprueft, nicht gegen
 * eine ausgedachte Beispielzeile: die waere farblos gewesen.
 */
function dk_portainer_log($zeilen = 400)
{
    $cfg = dk_config();
    // Hier ist 2>&1 richtig: Portainer schreibt seine Startmeldungen samt
    // Einrichtungsmerkwort auf die Fehlerausgabe, und genau die wird
    // gebraucht.
    list($aus, $fehler, $code) = dk_ausfuehren('docker logs --tail ' . (int) $zeilen . ' '
                                . escapeshellarg($cfg['portainer_name']));
    $roh = $aus . "\n" . $fehler;
    return preg_replace('/\x1B\[[0-9;]*[A-Za-z]/', '', $roh);
}

function dk_setup_token()
{
    $roh = dk_portainer_log(400);
    foreach (array('/setup_token=([A-Za-z0-9._\-]{6,})/i',
                   '/setup[ _-]?token["\']?\s*[:=]\s*["\']?([A-Za-z0-9._\-]{6,})/i') as $muster) {
        if (preg_match_all($muster, $roh, $tr)) { return end($tr[1]); }
    }
    return '';
}

function dk_portainer_neustart()
{
    $cfg = dk_config();
    list($aus, $fehler, $code) = dk_ausfuehren(
        'docker restart ' . escapeshellarg($cfg['portainer_name']));
    if ($code === 0) {
        dk_log('Container ' . $cfg['portainer_name'] . ' neu gestartet.');
    } else {
        dk_log('Container ' . $cfg['portainer_name'] . ' liess sich nicht neu starten '
            . '(Rueckgabewert ' . $code . '): ' . ($fehler !== '' ? $fehler : 'ohne Meldung'));
    }
    sleep(3);
    return dk_setup_token();
}

function dk_portainer_laeuft()
{
    $cfg = dk_config();
    foreach (dk_container() as $c) {
        if ($c['name'] === $cfg['portainer_name']) { return $c['laeuft'] === 1; }
    }
    return false;
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
    $p = dk_paths();
    if (!@is_dir($p['logdir'])) { @mkdir($p['logdir'], 0775, true); }
    // Rotation, damit eine Dauerstoerung die Ramdisk nicht vollschreibt.
    if (@is_file($p['log']) && @filesize($p['log']) > 262144) {
        $rest = array_slice(@file($p['log'], FILE_IGNORE_NEW_LINES) ?: array(), -300);
        @file_put_contents($p['log'], implode("\n", $rest) . "\n");
    }
    return @file_put_contents($p['log'],
        '[' . date('Y-m-d H:i:s') . '] ' . $text . "\n", FILE_APPEND) !== false;
}

/** Dieselbe Meldung hoechstens einmal je Zeitfenster. */
function dk_log_gebremst($schluessel, $text, $sekunden = 3600)
{
    $p = dk_paths();
    $f = $p['logdir'] . '/.meld_' . preg_replace('/[^a-z0-9_]/i', '', $schluessel);
    $letzte = @is_file($f) ? (int) @file_get_contents($f) : 0;
    if (time() - $letzte >= $sekunden) {
        if (!@is_dir($p['logdir'])) { @mkdir($p['logdir'], 0775, true); }
        @file_put_contents($f, (string) time());
        dk_log($text);
    }
}

function dk_log_lesen($zeilen = 200)
{
    $p = dk_paths();
    if (@is_file($p['log'])) {
        $z = @file($p['log']);
        if (is_array($z)) { return implode('', array_slice($z, -$zeilen)); }
    }
    return '';
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

    // Grenzen realistisch: Loxone zieht daraus Reglerbereiche und die
    // Plausibilitaetspruefung. Alles offen zu lassen verschenkt beides.
    $felder = array(
        array('OK',       dk_t('LOX.F_OK'),       0, 1),
        array('GESAMT',   dk_t('LOX.F_GESAMT'),   0, 999),
        array('LAEUFT',   dk_t('LOX.F_LAEUFT'),   0, 999),
        array('GESTOPPT', dk_t('LOX.F_GESTOPPT'), 0, 999),
    );

    $o  = '<?xml version="1.0" encoding="utf-8"?>' . $crlf;
    $o .= '<VirtualInHttp Title="' . dk_x('Docker NG') . '"'
        . ' Comment="' . dk_x(dk_t('LOX.XML_KOMMENTAR')) . '"'
        . ' Address="' . $adresse . '"'
        . ' PollingTime="60">' . $crlf;
    foreach ($felder as $f) {
        list($schluessel, $titel, $min, $max) = $f;
        $o .= "\t" . '<VirtualInHttpCmd Title="' . dk_x('DOCKERNG_' . $schluessel) . '"'
            . ' Comment="' . dk_x($titel) . '"'
            . ' Check="' . dk_x($schluessel . '=\v') . '"'
            . ' Signed="true" Analog="true"'
            . ' SourceValLow="0" DestValLow="0"'
            . ' SourceValHigh="100" DestValHigh="100"'
            . ' DefVal="0"'
            . ' MinVal="' . (int) $min . '"'
            . ' MaxVal="' . (int) $max . '"'
            . '/>' . $crlf;
    }
    // Je erkanntem Container eine eigene Zeile - Titel je Geraet, keine
    // Platzhalter. Ohne Container bleibt es bei den vier Sammelwerten.
    foreach ($z['liste'] as $c) {
        $sicher = preg_replace('/[^A-Za-z0-9_]/', '_', $c['name']);
        $o .= "\t" . '<VirtualInHttpCmd Title="' . dk_x('DOCKERNG_C_' . $sicher) . '"'
            . ' Comment="' . dk_x(sprintf(dk_t('LOX.F_CONTAINER'), $c['name'])) . '"'
            . ' Check="' . dk_x('C_' . $sicher . '=\v') . '"'
            . ' Signed="true" Analog="false"'
            . ' SourceValLow="0" DestValLow="0"'
            . ' SourceValHigh="1" DestValHigh="1"'
            . ' DefVal="0" MinVal="0" MaxVal="1"'
            . '/>' . $crlf;
    }
    $o .= '</VirtualInHttp>' . $crlf;
    return $o;
}
