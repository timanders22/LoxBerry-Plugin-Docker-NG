#!/bin/bash
# Docker NG - postupgrade
# command <TEMPFOLDER> <NAME> <FOLDER> <VERSION> <BASEFOLDER>
#
# Laeuft NUR beim Aktualisieren, nicht bei einer Erstinstallation - und zwar
# NACH postinstall.sh.
#
# WAS HIER AUSDRUECKLICH NICHT PASSIERT: postinstall.sh wird nicht aufgerufen.
# Der Installer fuehrt postinstall bei Erst- UND Neuinstallation ohnehin aus;
# ein Aufruf von hier ergaebe zwei Durchlaeufe. Bis 1.2.4 stand diese
# Begruendung in postinstall.sh und verwies auf eine Datei, die es gar nicht
# gab - jetzt gibt es sie, und der Satz stimmt.
#
# ACHTUNG bei den Argumenten: $1 ist beim Upgrade KEIN Pfad, sondern eine
# zehnstellige Zufallskennung. Der Arbeitsordner steht im SECHSTEN Argument.
# Hier wird ohnehin nur $3 (Ordner) und $5 (Wurzel) gebraucht.

# D2/D15 (1.3.19, Hausform Ultraschall 1.2.18 us_ist_wurzel): Wurzel ist, was config/plugins UND data/plugins UND
# config/system/general.json traegt (Regeln/06) - gefragt werden $5 (kommt vom Installer immer) und LBHOMEDIR. Bis
# 1.3.18 stand hier ein Rueckfall $SELF/../.. - die Skripte laufen aus dem Arbeitsordner des Installers, zwei Ebenen
# darueber liegt keine Wurzel (toter, falsch berechneter Zweig). Ohne Wurzel wird abgebrochen, ohne etwas anzufassen;
# ein unbrauchbarer Ordnername faellt nie still auf 'dockerng' zurueck.
dk_ist_wurzel() {
    [ -n "$1" ] && [ -d "$1/config/plugins" ] && [ -d "$1/data/plugins" ] \
        && [ -f "$1/config/system/general.json" ]
}
PFOLDER="${3:-}"
case "$PFOLDER" in
    ''|*[!A-Za-z0-9_-]*) echo "<FAIL> Ordnername '$PFOLDER' unbrauchbar - es wird nichts angefasst."; exit 1 ;;
esac
BASE=""
for dk_k in "${5:-}" "${LBHOMEDIR:-}"; do
    if dk_ist_wurzel "$dk_k"; then BASE="$dk_k"; break; fi
done
if [ -z "$BASE" ]; then
    echo "<FAIL> Keine LoxBerry-Wurzel (Argument 5: '${5:-}', LBHOMEDIR: '${LBHOMEDIR:-}') - verlangt sind"
    echo "<FAIL> config/plugins, data/plugins und config/system/general.json. Es wird nichts angefasst."
    exit 1
fi

PBIN="$BASE/bin/plugins/$PFOLDER"
PDATA="$BASE/data/plugins/$PFOLDER"

echo "<INFO> Aktualisierung auf Fassung ${4:-?} - Nachpruefung:"

# ---------- endpunkt_ein=1 fuer bestehende Anlagen (1.3.19; Entscheidung 2, Entwurf 3.2; Bauart Ferien 1.2.28) ----------
# Bis 1.3.18 antwortete der HTTP-Endpunkt ohne Schalter. Eine bestehende Anlage behaelt ihn: in dockerng.json und in
# der Zweitschrift wird endpunkt_ein=1 nachgetragen, wenn die Datei ein Merkwort traegt und den Schluessel nicht kennt.
# Ueber eine Nebendatei (.neu, umask 077) mit Gegenprobe (gleich bis auf den Schluessel), dann mv, 0600. Meist hat der
# Minutentakt aus postinstall.sh den Schluessel schon eingetragen (dieselbe Lese-Regel, dk_uebergang()) - dann bleibt
# es still. Scheitert es, gilt die Lese-Regel. Der Rohling "{}" einer Neuinstallation bleibt, wie er ist.
dk_traegt_token() {
    [ -s "$1" ] || return 1
    command -v php >/dev/null 2>&1 || return 2
    php -r '$d = json_decode((string) @file_get_contents($argv[1]), true);
        exit(is_array($d) && isset($d["aktionstoken"]) && is_string($d["aktionstoken"])
             && trim($d["aktionstoken"]) !== "" ? 0 : 1);' -- "$1" 2>/dev/null
    case $? in 0) return 0 ;; 1) return 1 ;; *) return 2 ;; esac
}
dk_endpunkt_nachtragen() {
    [ -f "$1" ] || return 0
    dk_traegt_token "$1" || return 0
    php -r 'umask(077); $f = $argv[1]; $roh = (string) @file_get_contents($f); $d = json_decode($roh, true);
        if (!is_array($d) || array_key_exists("endpunkt_ein", $d)) { exit(3); }
        $d["endpunkt_ein"] = 1;
        $js = json_encode($d, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($js === false) { exit(1); }
        $neu = $f . ".neu";
        if (@file_put_contents($neu, $js) !== strlen($js)) { @unlink($neu); exit(1); }
        $g = json_decode((string) @file_get_contents($neu), true);
        if (!is_array($g) || !isset($g["endpunkt_ein"]) || $g["endpunkt_ein"] !== 1) { @unlink($neu); exit(1); }
        unset($g["endpunkt_ein"]);
        if ($g !== json_decode($roh, true)) { @unlink($neu); exit(1); }
        if (!@rename($neu, $f)) { @unlink($neu); exit(1); }
        exit(0);' -- "$1" 2>/dev/null
    dk_e=$?
    if [ "$dk_e" = 0 ]; then
        chmod 600 "$1" 2>/dev/null
        chown loxberry:loxberry "$1" 2>/dev/null
        echo "<INFO> Konfiguration ergaenzt ($(basename "$1")): endpunkt_ein=1 (bestehende Anlage - der HTTP-Endpunkt bleibt erreichbar; abschalten im Reiter Einbindung)."
    elif [ "$dk_e" != 3 ]; then
        echo "<WARNING> endpunkt_ein liess sich in $(basename "$1") nicht nachtragen - es gilt die Lese-Regel (der HTTP-Endpunkt bleibt erreichbar)."
    fi
    return 0
}
dk_endpunkt_nachtragen "$BASE/config/plugins/$PFOLDER/dockerng.json"
dk_endpunkt_nachtragen "$BASE/config/plugins/$PFOLDER.backup.json"

# ---------- Neue Einstellungen dieser Fassung ----------
# ENTFALLEN in 1.3.9 (I8). Der Block stand hier und kam zu spaet: der
# Minutentakt aus postinstall.sh hatte die Konfiguration schon
# vervollstaendigt, "<OK> kennt alle Einstellungen" erschien immer, und bei
# {} nannte er dagegen alle Schluessel als neu (in WSL gemessen). Bestimmt
# wird der Hinweis jetzt in preupgrade.sh, an der alten Datei.

# ---------- Ist der Minutentakt wirklich angekommen? ----------
# Die Datei cron/cron.01min wird vom Installer nach
# <wurzel>/system/cron/cron.01min/<plugin> verteilt. Bleibt das aus, steht in
# Loxone der Herzschlag still - und das faellt sonst monatelang nicht auf, weil
# nichts eine Fehlermeldung erzeugt.
CRONZIEL="$BASE/system/cron/cron.01min/$PFOLDER"
if [ -f "$CRONZIEL" ]; then
    # Der Suchtext ist ZUSAMMENGESETZT (B1, Messung 30.10.2026): der Installer
    # ersetzt den Platzhalter in jeder Textdatei des Plugins, auch in dieser
    # Pruefzeile. Sie suchte dann den richtigen Pfad, fand ihn im Cron und
    # meldete zweimal FAIL, obwohl der Minutentakt stimmte (1.3.20 am Geraet).
    PLATZHALTER="REPLACE""LBPBINDIR"
    if grep -qF "$PLATZHALTER" "$CRONZIEL" 2>/dev/null; then
        echo "<FAIL> $CRONZIEL enthaelt noch den unersetzten Platzhalter"
        echo "<FAIL> $PLATZHALTER - der Minutentakt liefe ins Leere."
    else
        echo "<OK> Der Minutentakt ist eingerichtet: $CRONZIEL"
    fi
else
    echo "<FAIL> $CRONZIEL fehlt - der Minutentakt laeuft nicht."
    echo "<INFO> Damit stehen Herzschlag, Neustarterkennung und Plattenmessung still."
    echo "<INFO> Abhilfe: das Plugin noch einmal installieren."
fi

# ---------- Ist bin/ ausfuehrbar angekommen? ----------
if [ -f "$PBIN/healthcheck" ]; then
    if [ -x "$PBIN/healthcheck" ]; then
        echo "<OK> Der LoxBerry-Healthcheck ist eingebunden."
    else
        # Der Installer setzt bin/ rekursiv auf 755; wenn nicht, ist das ein
        # Befund und keine Kleinigkeit - healthcheck.pl ruft die Datei direkt auf.
        chmod 755 "$PBIN/healthcheck" 2>/dev/null
        echo "<INFO> bin/healthcheck war nicht ausfuehrbar und wurde nachgesetzt."
    fi
fi

mkdir -p "$PDATA" 2>/dev/null
chown -R loxberry:loxberry "$PDATA" 2>/dev/null

echo "<OK> Nachpruefung abgeschlossen."
exit 0
