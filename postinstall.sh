#!/bin/bash
# Docker NG - postinstall
# command <TEMPFOLDER> <NAME> <FOLDER> <VERSION> <BASEFOLDER>
#
# Laeuft ohne Bedingung - der Installer fuehrt postinstall bei Erst- UND
# Neuinstallation aus. Es wird deshalb NICHT aus postupgrade.sh heraus noch
# einmal aufgerufen; das ergaebe zwei Durchlaeufe.
#
# Hier passiert nur, was ohne root-Rechte geht. Docker und Portainer richtet
# postroot.sh ein.

ARGV3=$3
ARGV5=$5
PFOLDER="${ARGV3:-dockerng}"
BASE="${ARGV5:-$LBHOMEDIR}"
if [ -z "$BASE" ] || [ ! -d "$BASE" ]; then
    SELF=$(cd "$(dirname "$0")" && pwd)
    BASE=$(cd "$SELF/../.." 2>/dev/null && pwd)
fi

PCONFIG="$BASE/config/plugins/$PFOLDER"
PLOG="$BASE/log/plugins/$PFOLDER"
# data/ ab 1.3.0: dort schreibt der Minutentakt seinen Zustand fort. Anders als
# log/ liegt es NICHT auf der Ramdisk und uebersteht einen Neustart.
PDATA="$BASE/data/plugins/$PFOLDER"
CF="$PCONFIG/dockerng.json"
BK="$BASE/config/plugins/$PFOLDER.backup.json"

# ---------- Aktualisierung oder Neuinstallation? (I1, Entscheidung 1) ----------
# Allein an der Marke, die preupgrade.sh als Erstes anlegt - kein
# Altersvergleich. Sie wird per trap abgeraeumt, auch wenn dieses Skript
# vorzeitig endet; sonst ruhte der Minutentakt, ohne dass irgendwo stuende
# warum. Zweitschriften werden NUR bei einer Aktualisierung eingespielt;
# eine Neuinstallation findet keine mehr vor (preinstall.sh legt sie nach
# .alt). Hier wird nichts zusaetzlich beiseitegelegt - das traefe eine
# frische Zweitschrift (Warnung des Ecowitt-Installer-Pruefers).
MARKE="$BASE/data/plugins/$PFOLDER.upgrade_laeuft"
UPGRADE=0
if [ -e "$MARKE" ]; then
    UPGRADE=1
    trap 'rm -f "$MARKE"' EXIT
fi

mkdir -p "$PCONFIG" "$PLOG" "$PDATA" || {
    echo "<FAIL> Ordner konnten nicht angelegt werden."
    exit 1
}

# ---------- Konfiguration zurueckspielen ----------
# Nur, wenn die vorhandene nichts taugt. Eine brauchbare Konfiguration wird
# NICHT ueberschrieben - sonst verliert ein Anwender seine Einstellungen, weil
# eine alte Sicherung herumlag.
#
# ERWEITERT in 1.2.4: geprueft wurde bis 1.2.3 nur auf "leer oder {}". Eine
# halb geschriebene Datei ist weder das eine noch das andere - sie blieb also
# liegen, und das Merkwort darin war unlesbar. Dieselbe Pruefung wie in
# dk_config() und preupgrade.sh: gueltiges JSON mit nichtleerem aktionstoken.
dk_taugt() {
    [ -s "$1" ] || return 1
    command -v php >/dev/null 2>&1 || return 1
    [ "$(php -r '$d=@json_decode(@file_get_contents($argv[1]),true);
        echo (is_array($d)&&isset($d["aktionstoken"])&&is_string($d["aktionstoken"])&&trim($d["aktionstoken"])!=="")?"1":"0";' "$1" 2>/dev/null)" = "1" ]
}

if [ "$UPGRADE" = "1" ] && [ -f "$BK" ]; then
    if dk_taugt "$CF"; then
        echo "<INFO> Es liegt bereits eine brauchbare Konfiguration vor - Sicherung nicht angefasst."
    else
        # Beschaedigtes beiseitelegen statt wegwerfen: darin koennen
        # Einstellungen stehen, die die Sicherung noch nicht kennt.
        if [ -s "$CF" ]; then
            cp -p "$CF" "$CF.kaputt" 2>/dev/null && chmod 600 "$CF.kaputt" 2>/dev/null
            echo "<INFO> Die vorgefundene Konfiguration war unbrauchbar und liegt jetzt"
            echo "<INFO> zur Ansicht unter $CF.kaputt"
        fi
        if cp -p "$BK" "$CF" && chmod 600 "$CF"; then
            echo "<OK> Konfiguration aus der Sicherung wiederhergestellt."
            echo "<INFO> Das Merkwort fuer den Endpunkt bleibt damit gueltig - die"
            echo "<INFO> Adressen im Miniserver muessen nicht angefasst werden."
        else
            echo "<FAIL> Die Sicherung liess sich nicht zurueckspielen: $BK"
        fi
    fi
elif [ "$UPGRADE" != "1" ] && [ -f "$BK" ]; then
    # preinstall.sh konnte die Zweitschrift nicht beiseitelegen. Eingespielt
    # wird sie trotzdem nicht: eine Neuinstallation beginnt frisch.
    echo "<INFO> Eine Zweitschrift aus einer frueheren Installation liegt noch unter $BK -"
    echo "<INFO> sie wird bei einer Neuinstallation NICHT eingespielt."
elif [ -s "$CF" ] && ! dk_taugt "$CF"; then
    echo "<FAIL> Die Konfiguration ist unbrauchbar und es gibt keine Sicherung."
    echo "<INFO> Beim naechsten Oeffnen der Oberflaeche entsteht ein NEUES Merkwort;"
    echo "<INFO> alle Adressen im Miniserver muessen danach nachgezogen werden."
fi
[ -f "$CF" ] || { echo '{}' > "$CF"; chmod 600 "$CF"; }

# ---------- Einrichtungstoken von Portainer zurueckspielen (I5) ----------
STB="$BASE/config/plugins/$PFOLDER.backup.setup_token"
if [ "$UPGRADE" = "1" ] && [ -f "$STB" ] && [ ! -f "$PCONFIG/setup_token" ]; then
    if ( umask 077 && cat "$STB" > "$PCONFIG/setup_token" ) && chmod 600 "$PCONFIG/setup_token"; then
        echo "<OK> Einrichtungstoken von Portainer zurueckgespielt."
    else
        echo "<INFO> Der Einrichtungstoken von Portainer liess sich nicht zurueckspielen; die"
        echo "<INFO> Plugin-Seite liest ihn dann aus dem Protokoll des Containers."
    fi
fi

# ---------- PHP pruefen ----------
if ! command -v php >/dev/null 2>&1; then
    echo "<FAIL> Es wurde kein PHP gefunden. Ohne PHP laeuft die Oberflaeche nicht."
    exit 1
fi
echo "<INFO> PHP: $(php -v 2>/dev/null | head -1)"

# ---------- Erste Protokollzeile ----------
# Damit der Reiter Logdateien nicht leer bleibt, bevor irgendetwas passiert
# ist. Bis 1.1.0 schrieb ueberhaupt niemand in diese Datei.
echo "[$(date '+%Y-%m-%d %H:%M:%S')] Plugin installiert oder aktualisiert." \
    >> "$PLOG/dockerng.log" 2>/dev/null

chown -R loxberry:loxberry "$PCONFIG" "$PLOG" "$PDATA" 2>/dev/null
chmod 600 "$CF" 2>/dev/null

# ---------- Den Minutentakt einmal von Hand starten ----------
# Hausregel: jeden Cron-Dienst nach der Installation einmal von Hand starten
# und den Rueckgabewert ansehen. Ein Cron, der ins Leere laeuft, faellt sonst
# monatelang nicht auf - in Loxone steht dann der Herzschlag still, und
# niemand findet den Grund.
#
# Der Aufruf geht ueber das INSTALLIERTE Programmverzeichnis, nicht ueber den
# Arbeitsordner des Installers: nur so wird geprueft, was spaeter wirklich
# laeuft.
PBIN2="$BASE/bin/plugins/$PFOLDER"
# Die Marke weg, BEVOR der Takt laeuft - solange sie liegt, ruht er (I7).
rm -f "$MARKE"
if [ -f "$PBIN2/dockerng_takt.php" ]; then
    # Der Rueckgabewert traegt seit 1.3.9 (C7): 0 geschrieben, 3 besetzt,
    # 1 gescheitert. Bis 1.3.9 stand hier "<OK> ... Zustandsdatei angelegt"
    # auch dann, wenn zustand.json gar nicht geschrieben war (gemessen).
    (cd "$PBIN2" && php dockerng_takt.php >/dev/null 2>&1)
    TRC=$?
    if [ "$TRC" = "0" ]; then
        echo "<OK> Der Minutentakt ist einmal durchgelaufen (Zustandsdatei geschrieben)."
    elif [ "$TRC" = "3" ]; then
        echo "<INFO> Der Minutentakt lief gerade aus dem Cron - er wurde nicht doppelt ausgefuehrt."
    elif [ ! -f "$PDATA/zustand.json" ]; then
        echo "<INFO> Der Minutentakt hat keine Zustandsdatei geschrieben (Rueckgabewert $TRC)."
        echo "<INFO> Die Fehlerausgabe steht im Reiter Logdateien (cron.err). Im Reiter Test"
        echo "<INFO> steht ein Knopf, um ihn von Hand auszuloesen."
    else
        echo "<INFO> Der Minutentakt endete mit Rueckgabewert $TRC; die Zustandsdatei wurde nicht"
        echo "<INFO> erneuert. Einzelheiten im Reiter Logdateien."
    fi
    chown -R loxberry:loxberry "$PDATA" 2>/dev/null
else
    echo "<FAIL> $PBIN2/dockerng_takt.php fehlt - der Minutentakt kann nicht laufen."
fi

if [ "$UPGRADE" = "1" ]; then
    # Nach einer Aktualisierung darf der Schlusstext nicht zur Erstinstallation
    # raten (Regeln/06).
    echo "<OK> Aktualisierung abgeschlossen - es ist nichts weiter zu tun."
else
    echo "<OK> Installation abgeschlossen."
    echo "<INFO> Nach einer Erstinstallation den LoxBerry EINMAL neu starten:"
    echo "<INFO> der Webserver laeuft sonst noch ohne die Gruppe docker und kann"
    echo "<INFO> den Docker-Socket nicht erreichen."
fi
exit 0
