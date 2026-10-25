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

# Abbruch mit Rueckgabe 2: plugininstall.pl bricht das Update dann ab, die bisherige Fassung bleibt.
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
    ''|*[!A-Za-z0-9_-]*) echo "<FAIL> Ordnername '$PFOLDER' unbrauchbar - es wird nichts angefasst."; exit 2 ;;
esac
BASE=""
for dk_k in "${5:-}" "${LBHOMEDIR:-}"; do
    if dk_ist_wurzel "$dk_k"; then BASE="$dk_k"; break; fi
done
if [ -z "$BASE" ]; then
    echo "<FAIL> Keine LoxBerry-Wurzel (Argument 5: '${5:-}', LBHOMEDIR: '${LBHOMEDIR:-}') - verlangt sind"
    echo "<FAIL> config/plugins, data/plugins und config/system/general.json. Es wird nichts angefasst."
    exit 2
fi

# ---------- Upgrade-Marke, ALS ERSTES (I1, Entscheidung 1) ----------
# preinstall.sh erkennt an ihr die Aktualisierung (ohne Marke gilt der Einbau
# als Neuinstallation, und liegengebliebene Zweitschriften gehen nach .alt).
# Der Minutentakt ruht, solange sie liegt; postinstall.sh raeumt sie ab. Sie
# liegt NEBEN dem Datenordner, sonst loescht purge_installation sie mit.
if [ -d "$BASE/data/plugins" ]; then
    if date +%s > "$BASE/data/plugins/$PFOLDER.upgrade_laeuft" 2>/dev/null; then
        echo "<INFO> Upgrade-Marke gesetzt: $BASE/data/plugins/$PFOLDER.upgrade_laeuft"
    else
        echo "<WARNING> Die Upgrade-Marke liess sich nicht anlegen. postinstall.sh haelt die"
        echo "<WARNING> Aktualisierung dann fuer eine Neuinstallation und spielt nichts zurueck."
    fi
fi

# V3 (1.3.19): die Befehlsbruecke vor dem Austausch der Dateien anhalten. Die Marke liegt schon - der Waechter im
# Minutentakt startet sie bis postinstall.sh nicht neu. Bis 1.3.18 gab es keine Bruecke (dann fehlt die Datei).
BBSH="$BASE/bin/plugins/$PFOLDER/befehlsbruecke.sh"
if [ -f "$BBSH" ]; then
    echo "<INFO> Befehlsbruecke: $(timeout 40 bash "$BBSH" stop 2>&1 | head -n 1)"
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
            echo "<INFO> Fuer diese bestehende Anlage sind alle neuen Funktionen ab Werk AUS bzw. unveraendert (der"
            echo "<INFO> HTTP-Endpunkt bleibt an) - die Aktualisierung aendert daran nichts. Nur Neuinstallationen"
            echo "<INFO> senden ab 1.3.19 ab Werk ueber MQTT."
        fi
        # E-E3 (1.3.19): ist MQTT in dieser Anlage aus (oder der Schluessel fehlt - Stand vor 1.3.0), ein Hinweis.
        if [ "$(php -r '$d=json_decode(file_get_contents($argv[1]),true); echo (is_array($d)&&!empty($d["mqtt_aktiv"]))?"1":"0";' "$CF" 2>/dev/null)" = "0" ]; then
            echo "<INFO> MQTT ist in dieser Anlage aus. Neue Anlagen senden ab 1.3.19 ab Werk ueber MQTT - einschalten im"
            echo "<INFO> Reiter MQTT (empfohlen; der HTTP-Weg bleibt)."
        fi
        if [ -n "$ALT" ]; then
            echo "<INFO> Entfaellt in dieser Fassung: $ALT. Den eigenen Portainer erkennt das Plugin"
            echo "<INFO> jetzt am Label, nicht mehr an einem eingetragenen Namen. Ein anderer Container"
            echo "<INFO> als der eigene wird nicht mehr neu gestartet und nicht mehr entfernt."
        fi
    fi
fi
exit 0
