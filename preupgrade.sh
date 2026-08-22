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

# Gesichert wird nur, was auch etwas wert ist.
#
# BERICHTIGT in 1.2.4: bis 1.2.3 stand hier allein [ -s "$CF" ], also "nicht
# leer". Eine beschaedigte oder merkwortlose Datei erfuellt das - und
# ueberschrieb per cp die zuvor GUTE Sicherung. Zusammen mit der zu engen
# Selbstheilungspruefung in dk_config() war damit die letzte Kopie des
# Merkworts fort. Geprueft wird jetzt dasselbe wie dort: gueltiges JSON mit
# nichtleerem aktionstoken. Das Werkzeug dafuer steht ohnehin schon in
# uninstall/uninstall.
TAUGT=0
if [ -s "$CF" ] && command -v php >/dev/null 2>&1; then
    TAUGT=$(php -r '$d=@json_decode(@file_get_contents($argv[1]),true);
        echo (is_array($d)&&isset($d["aktionstoken"])&&trim((string)$d["aktionstoken"])!=="")?"1":"0";' "$CF" 2>/dev/null)
    [ "$TAUGT" = "1" ] || TAUGT=0
fi

if [ "$TAUGT" = "1" ]; then
    # Erst daneben schreiben, dann umbenennen: ein Abbruch mittendrin darf die
    # vorhandene Sicherung nicht halb ueberschrieben zuruecklassen.
    if cp -p "$CF" "$BK.neu" && chmod 600 "$BK.neu" && mv -f "$BK.neu" "$BK"; then
        echo "<OK> Konfiguration gesichert nach $BK (Rechte 0600)."
    else
        rm -f "$BK.neu"
        echo "<FAIL> Die Konfiguration liess sich nicht sichern. Nach dem Update"
        echo "<INFO> muss das Merkwort im Reiter Einbindung in Loxone abgelesen und"
        echo "<INFO> in den Adressen im Miniserver nachgezogen werden."
    fi
elif [ -s "$CF" ]; then
    echo "<INFO> Die vorhandene Konfiguration enthaelt kein lesbares Merkwort."
    if [ -f "$BK" ]; then
        echo "<OK> Die bisherige Sicherung $BK bleibt unangetastet - sie ist die"
        echo "<INFO> bessere Kopie und wird nach dem Update zurueckgespielt."
    else
        echo "<FAIL> Es gibt auch keine Sicherung. Nach dem Update entsteht ein NEUES"
        echo "<INFO> Merkwort; alle Adressen im Miniserver muessen nachgezogen werden."
    fi
else
    echo "<INFO> Keine Konfiguration vorhanden - offenbar eine Erstinstallation."
fi
exit 0
