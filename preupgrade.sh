#!/bin/bash
# Docker NG - preupgrade
# command <TEMPFOLDER> <NAME> <FOLDER> <VERSION> <BASEFOLDER>
#
# Zweck: das Merkwort fuer den Endpunkt ueber die Neuinstallation retten.
#
# Bis 1.1.0 gab es dieses Skript nicht. Der Konfigurationsordner eines Plugins
# ist beim Installieren weg, bevor irgendein Skript des Plugins laeuft - und
# damit auch dockerng.json samt Merkwort. Das Merkwort steckt in den Adressen
# im Miniserver: der virtuelle Eingang bekommt danach nur noch HTTP 403, ohne
# erkennbaren Anlass. Gemeldet von einem Mitleser, zutreffend.
#
# Die Sicherung liegt NEBEN dem Konfigurationsordner, nicht darin:
#
#     config/plugins/<ordner>.backup.json      Geschwister  -> ueberlebt
#     config/plugins/<ordner>/sicherung.json   Kind         -> faellt mit
#
# Bewusst NICHT /tmp: das ist auf dem LoxBerry eine Ramdisk und ausserdem fuer
# jeden lesbar. In der Datei steht ein Geheimnis.

ARGV3=$3
ARGV5=$5
PFOLDER="${ARGV3:-dockerng}"
BASE="${ARGV5:-$LBHOMEDIR}"
if [ -z "$BASE" ] || [ ! -d "$BASE" ]; then
    # Ableitung aus dem eigenen Ablageort. LoxBerry::System taugt hier nicht:
    # es leitet den Pluginordner aus dem Aufrufort ab und liefert von hier aus
    # ueberall Leerstring.
    SELF=$(cd "$(dirname "$0")" && pwd)
    BASE=$(cd "$SELF/../.." 2>/dev/null && pwd)
fi

CF="$BASE/config/plugins/$PFOLDER/dockerng.json"
BK="$BASE/config/plugins/$PFOLDER.backup.json"

if [ -s "$CF" ]; then
    if cp -p "$CF" "$BK" && chmod 600 "$BK"; then
        echo "<OK> Konfiguration gesichert nach $BK (Rechte 0600)."
    else
        echo "<FAIL> Die Konfiguration liess sich nicht sichern. Nach dem Update"
        echo "<INFO> muss das Merkwort im Reiter Einbindung in Loxone abgelesen und"
        echo "<INFO> in den Adressen im Miniserver nachgezogen werden."
    fi
else
    echo "<INFO> Keine Konfiguration vorhanden - offenbar eine Erstinstallation."
fi
exit 0
