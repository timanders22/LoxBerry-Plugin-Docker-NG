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
