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
        'datadir'   => $home . '/data/plugins/' . $ordner,
        'zustand'   => $home . '/data/plugins/' . $ordner . '/zustand.json',
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
        'portainer_name'   => 'portainer',
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
    $tmp = $pfad . '.tmp.' . getmypid();
    $fh = @fopen($tmp, 'c');
    if ($fh === false) { return false; }
    @chmod($tmp, $rechte);                       // schuetzen, BEVOR Inhalt hineinkommt
    // Gegen strlen() vergleichen, nicht gegen === false: eine kurze Schreibung
    // ist genauso kaputt wie gar keine.
    $ok = ftruncate($fh, 0) && (fwrite($fh, $js) === strlen($js));
    fflush($fh);
    fclose($fh);
    if (!$ok) { @unlink($tmp); return false; }
    if (!@rename($tmp, $pfad)) { @unlink($tmp); return false; }
    return true;
}

/** Taugt dieser Dateiinhalt als Konfiguration? Entscheidend ist das Merkwort. */
function dk_konfig_taugt($roh)
{
    $roh = trim((string) $roh);
    if ($roh === '' || $roh === '{}') { return false; }
    $d = json_decode($roh, true);
    if (!is_array($d)) { return false; }
    return isset($d['aktionstoken']) && trim((string) $d['aktionstoken']) !== '';
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
    $cfg['portainer_port'] = max(1, min(65535, (int) $cfg['portainer_port']));
    $name = trim((string) $cfg['portainer_name']);
    $cfg['portainer_name'] = preg_match('/^[A-Za-z0-9_.\-]{1,64}$/', $name) ? $name : 'portainer';
    $cfg['aktionstoken']   = (string) $cfg['aktionstoken'];

    /* Die Wachliste kommt aus einer Datei und ist damit fremdbestimmt: was
     * nicht ins Muster passt, faellt heraus - nicht zurechtgebogen, denn ein
     * gekuerzter Name faende den Container nicht und meldete "fehlt". */
    $wache = array();
    foreach ((array) (isset($cfg['wachliste']) ? $cfg['wachliste'] : array()) as $w) {
        if (is_string($w) && preg_match('/^[A-Za-z0-9_.\-]{1,64}$/', $w) && !in_array($w, $wache, true)) {
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
    $prae = trim((string) $cfg['mqtt_praefix']);
    $cfg['mqtt_praefix'] = preg_match('/^[A-Za-z0-9_\-]{1,32}$/', $prae) ? $prae : 'dockerng';

    $cfg['schleife_grenze'] = max(1, min(100, (int) $cfg['schleife_grenze']));
    $cfg['platz_grenze_mb'] = max(0, min(1048576, (int) $cfg['platz_grenze_mb']));
    return $cfg;
}

function dk_config()
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
     * Gemeldet von einem Mitleser, zutreffend, in 1.2.0 behoben.
     *
     * BERICHTIGT in 1.2.4 - die Vorkehrung versagte in genau dem Fall, fuer
     * den sie gebaut war. Geprueft wurde bis 1.2.3 auf "leer oder {}". Eine
     * halb geschriebene oder beschaedigte Datei - auf einer Speicherkarte nach
     * einem Stromausfall kein Ausnahmefall - ist weder das eine noch das
     * andere. Also: keine Wiederherstellung, dk_json_lesen() gab bei
     * ungueltigem JSON stumm ein leeres Feld zurueck, das Merkwort war '',
     * dk_token() wuerfelte ein neues - und dk_config_schreiben() kopierte es
     * ueber die Sicherung. Damit war das alte Merkwort in BEIDEN Kopien fort,
     * ausgeloest durch das blosse Oeffnen der Oberflaeche, und saemtliche
     * Adressen im Miniserver waren tot.
     *
     * Jetzt entscheidet nicht die Form des Textes, sondern ob ein Merkwort
     * darin steht (dk_konfig_taugt). Und eine beschaedigte Datei wird vor dem
     * Ueberschreiben zur Seite gelegt, statt verworfen zu werden.
     */
    $roh   = @is_file($p['config']) ? (string) @file_get_contents($p['config']) : '';
    $taugt = dk_konfig_taugt($roh);

    if (!$taugt && @is_file($p['sicherung']) && dk_konfig_taugt(@file_get_contents($p['sicherung']))) {
        @mkdir($p['configdir'], 0755, true);
        // Beschaedigtes NICHT wegwerfen: darin koennen Einstellungen stehen,
        // die die Sicherung noch nicht kennt.
        if (trim($roh) !== '' && trim($roh) !== '{}') {
            $beiseite = $p['config'] . '.kaputt';
            if (@copy($p['config'], $beiseite)) { @chmod($beiseite, 0600); }
            dk_log('Die Konfiguration war unbrauchbar (kein Merkwort lesbar). Sie liegt '
                . 'zur Ansicht unter ' . $beiseite . '.');
        }
        if (@copy($p['sicherung'], $p['config'])) {
            @chmod($p['config'], 0600);
            dk_log('Konfiguration aus der Sicherung ' . $p['sicherung']
                . ' wiederhergestellt. Das Merkwort fuer den Endpunkt bleibt damit gueltig.');
        }
    } elseif (!$taugt && trim($roh) !== '' && trim($roh) !== '{}') {
        // Kaputt UND keine brauchbare Sicherung. Dann wird gleich ein neues
        // Merkwort entstehen - das gehoert benannt, nicht verschwiegen.
        dk_log('Die Konfiguration ist unbrauchbar und es gibt keine verwertbare '
            . 'Sicherung. Es wird ein NEUES Merkwort erzeugt; alle Adressen im '
            . 'Miniserver muessen danach nachgezogen werden.');
    }

    $gemerkt = dk_config_normieren(dk_json_lesen($p['config']));
    dk_config_speicher($gemerkt);
    return $gemerkt;
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
     * werden als das, was sie sichern soll.
     */
    if (trim((string) $cfg['aktionstoken']) !== '') {
        if (!dk_json_schreiben($p['sicherung'], $cfg, 0600)) {
            dk_log('Die Zweitschrift liess sich nicht schreiben: ' . $p['sicherung']);
        }
    }
    // Den Zwischenspeicher nachziehen, sonst arbeitet der Rest dieses Aufrufs
    // mit dem alten Stand weiter.
    dk_config_speicher(dk_config_normieren($cfg));
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
function dk_zustand($frisch = false)
{
    static $z = null;
    if ($frisch) { $z = null; }
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
    list($ok) = dk_zustand($frisch);
    if (!$ok) {
        // Nicht so tun, als gaebe es keine Container. Wer hier eine leere
        // Liste bekommt, soll sie an dk_zustand() halten.
        return array();
    }
    list($roh, $fehler, $code) = dk_ausfuehren(
        "docker ps -a --format '{{.Names}}\t{{.Image}}\t{{.Status}}\t{{.State}}\t{{.HealthStatus}}'");
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
    list($roh, $fehler, $code) = dk_ausfuehren(
        'docker ps -aq | xargs -r docker inspect --format ' . escapeshellarg($vorlage));
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
    if (!isset($z['zeit'])) { return -1; }
    return max(0, time() - (int) $z['zeit']);
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

    list($roh, $fehler, $code) = dk_ausfuehren('docker system df --format ' . escapeshellarg('{{json .}}'));
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
function dk_mqtt($werte)
{
    $cfg = dk_config();
    if (empty($cfg['mqtt_aktiv'])) { return 0; }
    $lage = dk_mqtt_lage();
    if (!$lage['udpport']) {
        dk_log_gebremst('mqtt_kein_port',
            'MQTT ist eingeschaltet, aber der LoxBerry nennt keinen UDP-Eingang '
            . 'fuer das MQTT-Gateway. Unter System, MQTT Gateway einrichten.');
        return 0;
    }
    $s = @stream_socket_client('udp://127.0.0.1:' . $lage['udpport'], $eno, $estr, 2);
    if (!$s) {
        dk_log_gebremst('mqtt_kein_socket',
            'MQTT: UDP-Eingang ' . $lage['udpport'] . ' nicht erreichbar (' . $estr . ')');
        return 0;
    }
    $prae = $cfg['mqtt_praefix'];
    $n = 0;
    foreach ((array) $werte as $k => $v) {
        if (@fwrite($s, 'publish ' . $prae . '/' . $k . ' ' . dk_mqtt_wert($v)) !== false) { $n++; }
    }
    fclose($s);
    return $n;
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
    $z = dk_zaehlung();
    $werte = array(
        'status/ok'        => dk_zustand()[0] ? 1 : 0,
        'status/gesamt'    => $z['gesamt'],
        'status/laeuft'    => $z['laeuft'],
        'status/gestoppt'  => $z['gestoppt'],
        'status/ausfall'   => $z['ausfall'],
        'status/pausiert'  => $z['pausiert'],
        'status/ungesund'  => $z['ungesund'],
        'status/fehlt'     => $z['fehlt'],
        'status/schleife'  => $z['schleife'],
        'status/portainer' => dk_portainer_laeuft() ? 1 : 0,
    );
    $zd = dk_zustandsdatei();
    $werte['status/zaehler'] = isset($zd['zaehler']) ? (int) $zd['zaehler'] : 0;
    foreach (array('images_mb', 'freigebbar_mb', 'frei_mb') as $k) {
        if (isset($zd['platz'][$k])) { $werte['platte/' . $k] = (int) $zd['platz'][$k]; }
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
        . ' ' . escapeshellarg($bild));
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
    // Eigener Fehlerbehandler statt @: eine nicht erreichbare Registry ist ein
    // erwarteter Ausgang (-1 = nicht messbar), kein Befund. Siehe die
    // ausfuehrliche Begruendung bei dk_endpunkt_probe().
    set_error_handler(function () { return true; });
    $fp = fopen($url, 'rb', false, $ctx);
    $kopf = isset($http_response_header) ? $http_response_header : array();
    if ($fp) { fclose($fp); }
    restore_error_handler();
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
                set_error_handler(function () { return true; });
                $antwort = file_get_contents($tu, false, $ctx);
                restore_error_handler();
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
function dk_takt()
{
    $cfg = dk_config();
    $alt = dk_zustandsdatei();
    $jetzt = time();
    $neu = array(
        'zeit'    => $jetzt,
        // Umlaufend bei 1000: Loxone bekommt einen Analogwert, der sich bei
        // JEDEM Takt aendert. Genau das fehlte bis 1.2.4 - die eigene
        // Anleitung empfahl in Schritt 5, auf einen Wertwechsel zu achten,
        // und lieferte keinen Wert, der sich zuverlaessig aendert.
        'zaehler' => (isset($alt['zaehler']) ? ((int) $alt['zaehler'] + 1) : 0) % 1000,
    );

    list($ok) = dk_zustand(true);
    dk_container(true);
    dk_details(true);
    $z = dk_zaehlung();
    $neu['ok'] = $ok ? 1 : 0;

    /* ---- Neustartschleifen ----
     * Der RestartCount von Docker ist ein Lebenszeitzaehler: 47 Neustarts in
     * zwei Jahren sind gesund, +5 in zehn Minuten nicht. Brauchbar ist nur
     * das Delta ueber die Zeit. Gezaehlt wird ueber ein gleitendes Fenster
     * von einer Stunde.
     *
     * NICHT NACHGEMESSEN: ob ein 'docker restart' von Hand den Zaehler
     * erhoeht. Die Stelle im moby-Quelltext spricht dagegen (der Zaehler
     * steht im Zweig hinter ShouldRestart()), im Netz steht das Gegenteil.
     * Falls doch, loest der Knopf "Portainer neu starten" bei einer Grenze
     * von 3 erst beim dritten Mal innerhalb einer Stunde etwas aus - deshalb
     * ist die Grenze einstellbar und die Vorgabe nicht 1.
     */
    $fenster = isset($alt['neustarts']) && is_array($alt['neustarts']) ? $alt['neustarts'] : array();
    $schleife = 0;
    $neuestand = array();
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
        // einmal neu gestartet. Ein Backoff-verzoegerter Dauerlaeufer faellt
        // durch das reine Delta sonst irgendwann heraus.
        $frisch = ($c['seit'] > 0 && ($jetzt - $c['seit']) < 60 && $c['neustarts'] > 0);
        if ($eintrag['delta'] >= (int) $cfg['schleife_grenze'] || $frisch) { $schleife++; }
    }
    $neu['neustarts'] = $neuestand;
    $neu['schleife']  = $schleife;

    /* ---- Plattenbelegung ----
     * Hoechstens alle 15 Minuten: 'docker system df' laeuft Verzeichnisse ab.
     */
    $platz = isset($alt['platz']) && is_array($alt['platz']) ? $alt['platz'] : array();
    $letzte = isset($alt['platz_zeit']) ? (int) $alt['platz_zeit'] : 0;
    if ($jetzt - $letzte >= 900) {
        $platz = dk_platz_messen();
        $neu['platz_zeit'] = $jetzt;
    } else {
        $neu['platz_zeit'] = $letzte;
    }
    $neu['platz'] = $platz;

    /* ---- Abbild-Aktualisierungen ----
     * Hoechstens einmal am Tag, und nur wenn eingeschaltet. Das geht ins
     * Netz - dafuer braucht es eine ausdrueckliche Entscheidung.
     */
    $updates = isset($alt['updates']) && is_array($alt['updates']) ? $alt['updates'] : array();
    $uletzte = isset($alt['updates_zeit']) ? (int) $alt['updates_zeit'] : 0;
    if (!empty($cfg['updates_aktiv']) && $jetzt - $uletzte >= 86400) {
        $updates = dk_updates_pruefen();
        $neu['updates_zeit'] = $jetzt;
    } else {
        $neu['updates_zeit'] = $uletzte;
    }
    $neu['updates'] = empty($cfg['updates_aktiv']) ? array() : $updates;

    dk_zustandsdatei_schreiben($neu);
    dk_zustandsdatei(true);

    /* ---- Melden ----
     * Nur bei WECHSEL des Befundes, nicht bei jedem Takt - eine Meldung je
     * Minute waere keine Meldung, sondern Rauschen.
     */
    $befund = dk_befund();
    $vorher = isset($alt['befund']) ? (string) $alt['befund'] : '';
    if ($befund['kennung'] !== $vorher) {
        if ($befund['schwere'] <= 4) {
            dk_melden($befund['schwere'], $befund['text']);
            dk_log('Befund gewechselt: ' . $befund['text']);
        } elseif ($vorher !== '') {
            dk_melden(6, dk_t('MELDUNG.WIEDER_GUT'));
            dk_log('Befund gewechselt: wieder in Ordnung.');
        }
        $neu['befund'] = $befund['kennung'];
        dk_zustandsdatei_schreiben($neu);
        dk_zustandsdatei(true);
    } else {
        $neu['befund'] = $vorher;
        dk_zustandsdatei_schreiben($neu);
        dk_zustandsdatei(true);
    }

    /* ---- MQTT ----
     * Der Herzschlag geht in JEDEM Takt hinaus, auch wenn sich sonst nichts
     * geaendert hat. Wer nur bei Aenderungen sendet, hoert bei einer Stoerung
     * einfach auf - die zuletzt gesendeten Werte bleiben im Broker stehen,
     * und in Loxone sieht ein toter Dienst genauso aus wie ein ruhiges Haus.
     */
    $gesendet = dk_mqtt(dk_mqtt_themen());

    return array('zaehler' => $neu['zaehler'], 'schleife' => $schleife,
                 'mqtt' => $gesendet, 'befund' => $befund);
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
    /* Der Fehlerbehandler wird ausgetauscht, statt sich auf @ zu verlassen.
     *
     * Ein nicht erreichbarer Webserver ist hier ein ERWARTETER Ausgang, kein
     * Fehler - genau dafuer gibt es den Stand -1. Das @ unterdrueckt die
     * Meldung aber nur fuer die Standardbehandlung: ist ein eigener
     * Fehlerbehandler gesetzt, sieht der sie trotzdem. Im Pruefstand
     * rendern.py stand sie deshalb als Befund da, obwohl nichts kaputt war.
     */
    set_error_handler(function () { return true; });
    $antwort = file_get_contents($url, false, $ctx);
    restore_error_handler();
    $kopf = isset($http_response_header) ? $http_response_header : array();
    $code = 0;
    if ($kopf && preg_match('#\s(\d{3})\s#', $kopf[0], $m)) { $code = (int) $m[1]; }

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

/**
 * Der Gesamtbefund in EINEM Satz - fuer das Benachrichtigungszentrum, den
 * LoxBerry-Healthcheck und den Reiter Test.
 *
 * Schwere nach der LoxBerry-Skala: 3 = Fehler, 4 = Warnung, 5 = in Ordnung.
 */
function dk_befund()
{
    $cfg = dk_config();
    list($ok, $grund, $grundtext) = dk_zustand();
    if (dk_bin() === '') {
        return array('kennung' => 'KEIN_DOCKER', 'schwere' => 3,
                     'text' => dk_t('BEFUND.KEIN_DOCKER'));
    }
    if (!$ok) {
        return array('kennung' => 'ZUGRIFF_' . $grund, 'schwere' => 3,
                     'text' => $grundtext);
    }
    $z = dk_zaehlung();
    if ($z['fehlt'] > 0) {
        $namen = array();
        foreach ($z['wache'] as $n => $w) { if ($w === -1) { $namen[] = $n; } }
        return array('kennung' => 'FEHLT:' . implode(',', $namen), 'schwere' => 3,
                     'text' => sprintf(dk_t('BEFUND.FEHLT'), implode(', ', $namen)));
    }
    if ($z['schleife'] > 0) {
        return array('kennung' => 'SCHLEIFE:' . $z['schleife'], 'schwere' => 3,
                     'text' => sprintf(dk_t('BEFUND.SCHLEIFE'), $z['schleife']));
    }
    if ($z['ungesund'] > 0) {
        $namen = array();
        foreach ($z['liste'] as $c) { if ($c['gesund'] === 3) { $namen[] = $c['name']; } }
        return array('kennung' => 'UNGESUND:' . implode(',', $namen), 'schwere' => 3,
                     'text' => sprintf(dk_t('BEFUND.UNGESUND'), implode(', ', $namen)));
    }
    if ($z['wache_ausfall'] > 0) {
        return array('kennung' => 'AUSFALL:' . $z['wache_ausfall'], 'schwere' => 4,
                     'text' => sprintf(dk_t('BEFUND.AUSFALL'), $z['wache_ausfall']));
    }
    $zd = dk_zustandsdatei();
    $frei = isset($zd['platz']['frei_mb']) ? (int) $zd['platz']['frei_mb'] : -1;
    if ((int) $cfg['platz_grenze_mb'] > 0 && $frei >= 0 && $frei < (int) $cfg['platz_grenze_mb']) {
        return array('kennung' => 'PLATZ:' . $frei, 'schwere' => 4,
                     'text' => sprintf(dk_t('BEFUND.PLATZ'), $frei));
    }
    return array('kennung' => 'OK', 'schwere' => 5,
                 'text' => sprintf(dk_t('BEFUND.OK'), $z['laeuft'], $z['gesamt']));
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
    if (!preg_match('/^[A-Za-z0-9_.\-]{1,64}$/', (string) $name)) { return ''; }
    $zeilen = max(1, min(2000, (int) $zeilen));
    list($aus, $fehler, $code) = dk_ausfuehren('docker logs --tail ' . $zeilen . ' '
                                . escapeshellarg($name));
    $roh = $aus . "\n" . $fehler;
    return preg_replace('/\x1B\[[0-9;]*[A-Za-z]/', '', $roh);
}

function dk_portainer_log($zeilen = 400)
{
    $cfg = dk_config();
    return dk_container_log($cfg['portainer_name'], $zeilen);
}

/**
 * Der bei der Installation VORGEGEBENE Einrichtungstoken, oder Leerstring.
 *
 * postroot.sh legt ihn mit --setup-token fest und schreibt ihn nach
 * config/plugins/<ordner>/setup_token (0600). Damit entfaellt das Fischen im
 * Containerprotokoll - und der Wert ueberlebt einen Neustart des Containers,
 * weil er in dessen Befehlszeile steht.
 *
 * Eigene Datei, nicht dockerng.json: ein Wert, ein Zweck. Und diese Datei
 * darf mit dem Container verschwinden, das Merkwort fuer den Endpunkt nicht.
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
    $cfg = dk_config();

    /* Ist der Token vorgegeben (ab 1.3.0 der Regelfall), aendert er sich beim
     * Neustart NICHT - er steht in der Befehlszeile des Containers. Auf einen
     * anderen zu warten hiesse hier, zwanzig Sekunden lang auf etwas zu warten,
     * das nicht kommen kann. Der Neustart oeffnet trotzdem das
     * Fuenf-Minuten-Fenster, und genau darum geht es beim Druck auf den Knopf.
     */
    $fest = dk_setup_token_vorgegeben();
    if ($fest !== '') {
        list($aus, $fehler, $code) = dk_ausfuehren(
            'docker restart ' . escapeshellarg($cfg['portainer_name']));
        if ($code !== 0) {
            dk_log('Container ' . $cfg['portainer_name'] . ' liess sich nicht neu starten '
                . '(Rueckgabewert ' . $code . '): ' . ($fehler !== '' ? $fehler : 'ohne Meldung'));
            return array(false, '');
        }
        dk_log('Container ' . $cfg['portainer_name'] . ' neu gestartet.');
        dk_zustand(true);
        dk_container(true);
        sleep(3);
        return array(true, $fest);
    }

    $vorher = dk_setup_token();

    list($aus, $fehler, $code) = dk_ausfuehren(
        'docker restart ' . escapeshellarg($cfg['portainer_name']));
    if ($code !== 0) {
        dk_log('Container ' . $cfg['portainer_name'] . ' liess sich nicht neu starten '
            . '(Rueckgabewert ' . $code . '): ' . ($fehler !== '' ? $fehler : 'ohne Meldung'));
        return array(false, '');
    }
    dk_log('Container ' . $cfg['portainer_name'] . ' neu gestartet.');

    // Die Momentaufnahmen sind jetzt veraltet.
    dk_zustand(true);
    dk_container(true);

    $ende = time() + max(3, (int) $wartesekunden);
    do {
        sleep(2);
        $jetzt = dk_setup_token();
        if ($jetzt !== '' && $jetzt !== $vorher) {
            return array(true, $jetzt);
        }
    } while (time() < $ende);

    // Nichts Neues aufgetaucht. Den alten NICHT ausgeben - er ist abgelaufen.
    return array(true, '');
}

function dk_portainer_laeuft()
{
    $cfg = dk_config();
    foreach (dk_container() as $c) {
        if ($c['name'] === $cfg['portainer_name']) { return $c['laeuft'] === 1; }
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
    static $p = null;
    if ($p !== null) { return $p; }
    $cfg = dk_config();
    $vorgabe = (int) $cfg['portainer_port'];
    if (dk_bin() === '') { $p = array($vorgabe, 0, 'http'); return $p; }
    list($ok) = dk_zustand();
    if (!$ok) { $p = array($vorgabe, 0, 'http'); return $p; }

    // 'docker port <name>' listet je Zeile  9000/tcp -> 0.0.0.0:9000
    list($aus, $fehler, $code) = dk_ausfuehren(
        'docker port ' . escapeshellarg($cfg['portainer_name']));
    if ($code === 0 && trim($aus) !== '') {
        // Zuerst die Zuordnung fuer 9000/tcp, sonst die erste brauchbare.
        $innen = 9000;
        if (preg_match('#^9000/tcp\s*->\s*\S*?:(\d{1,5})#mi', $aus, $t)) {
            $innen = 9000;
        } elseif (preg_match('#^(\d{1,5})/tcp\s*->\s*\S*?:(\d{1,5})#m', $aus, $t2)) {
            $innen = (int) $t2[1];
            $t = array($t2[0], $t2[2]);
        } else {
            $t = null;
        }
        if ($t !== null) {
            $gefunden = (int) $t[1];
            if ($gefunden >= 1 && $gefunden <= 65535) {
                $p = array($gefunden, 1, $innen === 9443 ? 'https' : 'http');
                return $p;
            }
        }
    }
    $p = array($vorgabe, 0, 'http');
    return $p;
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
    clearstatcache(true, $p['log']);
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
    return array(
        array('OK',        dk_t('LOX.F_OK'),        0, 1,   1),
        array('GESAMT',    dk_t('LOX.F_GESAMT'),    0, 999, 3),
        array('LAEUFT',    dk_t('LOX.F_LAEUFT'),    0, 999, 3),
        array('GESTOPPT',  dk_t('LOX.F_GESTOPPT'),  0, 999, 0),
        array('AUSFALL',   dk_t('LOX.F_AUSFALL'),   0, 999, 0),
        array('PAUSIERT',  dk_t('LOX.F_PAUSIERT'),  0, 999, 0),
        array('UNGESUND',  dk_t('LOX.F_UNGESUND'),  0, 999, 0),
        array('FEHLT',     dk_t('LOX.F_FEHLT'),     0, 999, 0),
        array('SCHLEIFE',  dk_t('LOX.F_SCHLEIFE'),  0, 999, 0),
        array('PORTAINER', dk_t('LOX.F_PORTAINER'), 0, 1,   1),
        // Der Herzschlag. Er MUSS sich bei jedem Takt aendern - daran und nur
        // daran erkennt Loxone, dass der LoxBerry noch antwortet. Bleibt der
        // Wert stehen, ist die Meldung faellig, auch wenn alles andere gut
        // aussieht: bei einem Ausfall behaelt der virtuelle Eingang seinen
        // letzten Wert, und in der App sieht dann alles normal aus.
        array('ZAEHLER',   dk_t('LOX.F_ZAEHLER'),   0, 999, 42),
        array('PLATZFREI', dk_t('LOX.F_PLATZFREI'), -1, 999999, 4096),
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
        $teile[] = $f[0] . '=' . $f[4];
    }
    $teile[] = 'GRUND=-';
    $teile[] = 'C_portainer=1';
    $teile[] = 'H_portainer=2';
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
            . ' Unit="' . dk_x('<v.1>') . '"'
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
            . ' Comment="' . dk_x(sprintf(dk_t('LOX.F_CONTAINER'), $name)) . '"'
            . ' Check="' . dk_x('C_' . $sicher . '=\v') . '"'
            . ' Signed="true" Analog="true"'
            . ' SourceValLow="0" DestValLow="0"'
            . ' SourceValHigh="100" DestValHigh="100"'
            . ' DefVal="0" MinVal="-1" MaxVal="1"'
            . ' Unit="' . dk_x('<v.1>') . '"'
            . ' HintText=""'
            . '/>' . $crlf;
        $o .= "\t" . '<VirtualInHttpCmd Title="' . dk_x('DOCKERNG_H_' . $sicher) . '"'
            . ' Comment="' . dk_x(sprintf(dk_t('LOX.F_GESUND'), $name)) . '"'
            . ' Check="' . dk_x('H_' . $sicher . '=\v') . '"'
            . ' Signed="true" Analog="true"'
            . ' SourceValLow="0" DestValLow="0"'
            . ' SourceValHigh="100" DestValHigh="100"'
            . ' DefVal="0" MinVal="-1" MaxVal="3"'
            . ' Unit="' . dk_x('<v.1>') . '"'
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
    $mangel = array();
    $daten = json_decode((string) $roh, true);
    if (!is_array($daten)) {
        return array(null, array(dk_t('EINST.SICH_KEIN_JSON')), 0);
    }
    $neu = dk_vorgaben();
    $bekannt = array_keys($neu);
    $anzahl = 0;
    foreach ($daten as $k => $w) {
        if (!in_array($k, $bekannt, true)) {
            $mangel[] = sprintf(dk_t('EINST.SICH_FREMD'),
                                 htmlspecialchars((string) $k, ENT_QUOTES, 'UTF-8'));
            continue;
        }
        $neu[$k] = $w;
        $anzahl++;
    }
    if ($anzahl === 0) {
        $mangel[] = dk_t('EINST.SICH_LEER');
    }
    return array($mangel ? null : $neu, $mangel, $anzahl);
}
