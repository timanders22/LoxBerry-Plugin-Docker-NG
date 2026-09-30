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

# ---------- Upgrade-Marke, ALS ERSTES (I1, Entscheidung 1) ----------
# preinstall.sh erkennt an ihr die Aktualisierung (ohne Marke gilt der Einbau
# als Neuinstallation, und liegengebliebene Zweitschriften gehen nach .alt).
# Der Minutentakt ruht, solange sie liegt; postinstall.sh raeumt sie ab. Sie
# liegt NEBEN dem Datenordner, sonst loescht purge_installation sie mit.
case "$PFOLDER" in ''|*/*|*..*) PFOLDER=dockerng ;; esac
if [ -n "$BASE" ] && [ -d "$BASE/data/plugins" ]; then
    if date +%s > "$BASE/data/plugins/$PFOLDER.upgrade_laeuft" 2>/dev/null; then
        echo "<INFO> Upgrade-Marke gesetzt: $BASE/data/plugins/$PFOLDER.upgrade_laeuft"
    else
        echo "<WARNING> Die Upgrade-Marke liess sich nicht anlegen. postinstall.sh haelt die"
        echo "<WARNING> Aktualisierung dann fuer eine Neuinstallation und spielt nichts zurueck."
    fi
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
# nichtleerem aktionstoken.
#
# BERICHTIGT in 1.3.9 (I6): is_string() vor trim(). Mit (string) wurde ein
# Token als Liste zu "Array", galt als Merkwort und ueberschrieb die GUTE
# Zweitschrift (in WSL gemessen, Installer-Pruefer Fall E3).
TAUGT=0
if [ -s "$CF" ] && command -v php >/dev/null 2>&1; then
    TAUGT=$(php -r '$d=@json_decode(@file_get_contents($argv[1]),true);
        echo (is_array($d)&&isset($d["aktionstoken"])&&is_string($d["aktionstoken"])&&trim($d["aktionstoken"])!=="")?"1":"0";' "$CF" 2>/dev/null)
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

# ---------- Einrichtungstoken von Portainer (I5) ----------
# config/plugins/<ordner>/setup_token faellt mit dem Ordner bei jedem
# Upgrade; der Container behaelt den Token in seiner Befehlszeile. Bis 1.3.9
# war die Datei danach fort (gemessen: vor dem Upgrade vorhanden, danach
# fehlt). Die Zweitschrift liegt neben dem Ordner, 0600; postinstall.sh
# spielt sie bei einer Aktualisierung zurueck.
ST="$BASE/config/plugins/$PFOLDER/setup_token"
STB="$BASE/config/plugins/$PFOLDER.backup.setup_token"
if [ -f "$ST" ] && grep -Eq '^[A-Za-z0-9._-]{6,128}$' "$ST" 2>/dev/null; then
    if ( umask 077 && cat "$ST" > "$STB.neu" ) && chmod 600 "$STB.neu" && mv -f "$STB.neu" "$STB"; then
        echo "<OK> Einrichtungstoken von Portainer gesichert nach $STB (Rechte 0600)."
    else
        rm -f "$STB.neu"
        echo "<INFO> Der Einrichtungstoken von Portainer liess sich nicht sichern. Die Plugin-Seite"
        echo "<INFO> liest ihn dann wie bisher aus dem Protokoll des Containers."
    fi
fi

# ---------- Neue Einstellungen dieser Fassung (I8) ----------
# Bestimmt HIER, an der ALTEN Konfiguration und mit der Vorgabenliste der
# NEUEN Fassung (dk_lib.php aus dem Archiv, das gerade eingespielt wird).
# Bis 1.3.9 stand der Block in postupgrade.sh: dort hatte der Minutentakt
# aus postinstall.sh die Datei schon vervollstaendigt, und der Hinweis
# erschien nie (gemessen); bei {} nannte er dagegen alle Schluessel als neu.
# Eine Konfiguration ohne Merkwort ({}, kaputt) bekommt keinen Hinweis.
if [ "$TAUGT" = "1" ]; then
    NEULIB="$(cd "$(dirname "$0")" 2>/dev/null && pwd)/webfrontend/html/dk_lib.php"
    if [ -f "$NEULIB" ]; then
        LAGE=$(php -r 'require $argv[1]; $d=json_decode(file_get_contents($argv[2]),true);
            $f=array(); foreach(array_keys(dk_vorgaben()) as $k){ if(!array_key_exists($k,$d)){$f[]=$k;} }
            $v=array(); foreach(dk_veraltete_schluessel() as $k){ if(array_key_exists($k,$d)){$v[]=$k."=".(is_string($d[$k])?$d[$k]:json_encode($d[$k]));} }
            echo implode(", ",$f)."|".implode(", ",$v);' "$NEULIB" "$CF" 2>/dev/null)
        NEU="${LAGE%%|*}"
        ALT=""
        case "$LAGE" in *"|"*) ALT="${LAGE#*|}" ;; esac
        if [ -n "$NEU" ]; then
            echo "<INFO> Neue Einstellungen dieser Fassung, die Ihre Konfiguration noch nicht kennt: $NEU"
            echo "<INFO> Der Minutentakt traegt sie mit ihrer Vorgabe ein (eine Zeile im Protokoll)."
            echo "<INFO> Alle neuen Funktionen sind ab Werk AUS - die Aktualisierung aendert daran nichts."
        fi
        if [ -n "$ALT" ]; then
            echo "<INFO> Entfaellt in dieser Fassung: $ALT. Den eigenen Portainer erkennt das Plugin"
            echo "<INFO> jetzt am Label, nicht mehr an einem eingetragenen Namen. Ein anderer Container"
            echo "<INFO> als der eigene wird nicht mehr neu gestartet und nicht mehr entfernt."
        fi
    fi
fi
exit 0
