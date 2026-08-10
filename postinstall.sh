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
CF="$PCONFIG/dockerng.json"
BK="$BASE/config/plugins/$PFOLDER.backup.json"

mkdir -p "$PCONFIG" "$PLOG" || {
    echo "<FAIL> Ordner konnten nicht angelegt werden."
    exit 1
}

# ---------- Konfiguration zurueckspielen ----------
# Nur, wenn die vorhandene leer ist oder fehlt. Eine bestehende Konfiguration
# wird NICHT ueberschrieben - sonst verliert ein Anwender seine Einstellungen,
# weil eine alte Sicherung herumlag.
if [ -f "$BK" ]; then
    INHALT=$(cat "$CF" 2>/dev/null)
    if [ ! -s "$CF" ] || [ "$INHALT" = "{}" ]; then
        if cp -p "$BK" "$CF" && chmod 600 "$CF"; then
            echo "<OK> Konfiguration aus der Sicherung wiederhergestellt."
            echo "<INFO> Das Merkwort fuer den Endpunkt bleibt damit gueltig - die"
            echo "<INFO> Adressen im Miniserver muessen nicht angefasst werden."
        else
            echo "<FAIL> Die Sicherung liess sich nicht zurueckspielen: $BK"
        fi
    else
        echo "<INFO> Es liegt bereits eine Konfiguration vor - Sicherung nicht angefasst."
    fi
fi
[ -f "$CF" ] || { echo '{}' > "$CF"; chmod 600 "$CF"; }

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

chown -R loxberry:loxberry "$PCONFIG" "$PLOG" 2>/dev/null
chmod 600 "$CF" 2>/dev/null

echo "<OK> Installation abgeschlossen."
echo "<INFO> Nach einer Erstinstallation den LoxBerry EINMAL neu starten:"
echo "<INFO> der Webserver laeuft sonst noch ohne die Gruppe docker und kann"
echo "<INFO> den Docker-Socket nicht erreichen."
exit 0
