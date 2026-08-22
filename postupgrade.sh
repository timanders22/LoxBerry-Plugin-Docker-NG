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

ARGV3=$3
ARGV5=$5
PFOLDER="${ARGV3:-dockerng}"
BASE="${ARGV5:-$LBHOMEDIR}"
if [ -z "$BASE" ] || [ ! -d "$BASE" ]; then
    SELF=$(cd "$(dirname "$0")" && pwd)
    BASE=$(cd "$SELF/../.." 2>/dev/null && pwd)
fi

PBIN="$BASE/bin/plugins/$PFOLDER"
PDATA="$BASE/data/plugins/$PFOLDER"

echo "<INFO> Aktualisierung auf Fassung ${4:-?} - Nachpruefung:"

# ---------- Der Aktualisierungsfall ----------
# Das ist der einzige Fall, den eine Neuinstallation nie durchlaeuft: eine
# vorhandene Konfiguration, in der die neuen Schluessel fehlen. Das Plugin
# ergaenzt sie beim Lesen aus den Vorgaben (dk_config_normieren), aber gesagt
# gehoert es trotzdem - der Anwender soll nicht raten, warum seine Datei
# kleiner ist als die Beschreibung.
CF="$BASE/config/plugins/$PFOLDER/dockerng.json"
if [ -s "$CF" ] && command -v php >/dev/null 2>&1; then
    FEHLEND=$(php -r '$d=@json_decode(@file_get_contents($argv[1]),true);
        if(!is_array($d)){echo "";exit;}
        $neu=array("wachliste","mqtt_aktiv","mqtt_praefix","melden_aktiv",
                   "schleife_grenze","updates_aktiv","platz_grenze_mb");
        $f=array(); foreach($neu as $k){ if(!array_key_exists($k,$d)){$f[]=$k;} }
        echo implode(", ",$f);' "$CF" 2>/dev/null)
    if [ -n "$FEHLEND" ]; then
        echo "<INFO> Neue Einstellungen in dieser Fassung: $FEHLEND"
        echo "<INFO> Sie stehen noch nicht in Ihrer Konfiguration und gelten deshalb"
        echo "<INFO> mit ihrem Vorgabewert. ALLE neuen Funktionen sind ab Werk AUS -"
        echo "<INFO> die Aktualisierung aendert an Ihrem Betrieb nichts."
    else
        echo "<OK> Die Konfiguration kennt alle Einstellungen dieser Fassung."
    fi
fi

# ---------- Ist der Minutentakt wirklich angekommen? ----------
# Die Datei cron/cron.01min wird vom Installer nach
# <wurzel>/system/cron/cron.01min/<plugin> verteilt. Bleibt das aus, steht in
# Loxone der Herzschlag still - und das faellt sonst monatelang nicht auf, weil
# nichts eine Fehlermeldung erzeugt.
CRONZIEL="$BASE/system/cron/cron.01min/$PFOLDER"
if [ -f "$CRONZIEL" ]; then
    if grep -q "REPLACELBPBINDIR" "$CRONZIEL" 2>/dev/null; then
        echo "<FAIL> $CRONZIEL enthaelt noch den unersetzten Platzhalter"
        echo "<FAIL> REPLACELBPBINDIR - der Minutentakt liefe ins Leere."
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
