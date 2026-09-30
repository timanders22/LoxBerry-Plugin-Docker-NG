#!/bin/bash
# Docker NG - preinstall
# command <TEMPFOLDER> <NAME> <FOLDER> <VERSION> <BASEFOLDER>
#
# Neu in 1.3.9 (I1, Entscheidung 1 vom 29.09.2026), Bauform AudiConnect
# 0.9.22 (preinstall.sh). Der Installer ruft dieses Skript bei JEDEM Einbau
# auf, nach dem Aufraeumen der alten Fassung und VOR dem Kopieren von
# Konfiguration, Cron-Datei und Oberflaeche (sbin/plugininstall.pl:
# preupgrade :846, purge :874, preinstall :877, Cron :990, HTML :1066 -
# Geraet/2026-09-05/08_plugininstall.pl).
#
# Eine Aktualisierung erkennt es allein an der Marke
# data/plugins/<ordner>.upgrade_laeuft, die preupgrade.sh als Erstes anlegt
# (kein Altersvergleich). Dann tut es nichts: die Zweitschriften braucht
# postinstall.sh.
#
# Ohne Marke ist es eine NEUINSTALLATION. Liegengebliebene Zweitschriften
# einer frueheren Installation - config/plugins/<ordner>.backup.json (mit
# dem Merkwort fuer den Endpunkt) und .backup.setup_token (der
# Einrichtungstoken von Portainer) - gehen nach <name>.alt, gemeldet mit
# genau einer <WARNING>. Bis 1.3.9 spielte postinstall.sh sie ungefragt ein;
# lief der Minutentakt vorher, tat es die Selbstheilung der Bibliothek. Eine
# frische Installation uebernahm so Merkwort und Schalter einer frueheren -
# gemessen: abgeschaltetes MQTT war ungefragt wieder an (in WSL,
# Installer-Pruefer Faelle B und B2). Die Selbstheilung liest .alt nie; die
# Deinstallation raeumt es ab.
ARGV3=$3
ARGV5=$5
PFOLDER="${ARGV3:-dockerng}"
BASE="${ARGV5:-$LBHOMEDIR}"
# Wurzelsuche wie in den uebrigen Hakenskripten: ohne config/plugins,
# data/plugins UND config/system/general.json wird nichts angefasst.
if [ -z "$BASE" ] || [ ! -d "$BASE/config/plugins" ] || [ ! -d "$BASE/data/plugins" ] \
   || [ ! -f "$BASE/config/system/general.json" ]; then
    echo "<WARNING> Kein LoxBerry-Wurzelverzeichnis erkannt ('$BASE') - nichts beiseitegelegt."
    exit 0
fi
case "$PFOLDER" in
    ''|*/*|*..*) echo "<WARNING> Unzulaessiger Ordnername '$PFOLDER' - nichts beiseitegelegt."; exit 0 ;;
esac
[ -f "$BASE/data/plugins/$PFOLDER.upgrade_laeuft" ] && exit 0

BEISEITE=""
FEST=""
for ZIEL in "$BASE/config/plugins/$PFOLDER.backup.json" \
            "$BASE/config/plugins/$PFOLDER.backup.json.neu" \
            "$BASE/config/plugins/$PFOLDER.backup.setup_token"; do
    if [ -e "$ZIEL" ] || [ -L "$ZIEL" ]; then
        rm -rf "${ZIEL:?}.alt" 2>/dev/null
        if mv -f "$ZIEL" "$ZIEL.alt" 2>/dev/null; then
            BEISEITE="$BEISEITE $ZIEL.alt"
        else
            FEST="$FEST $ZIEL"
        fi
    fi
done
# Nebendateien eines abgebrochenen Schreibvorgangs (Muster .tmp.<pid>)
# tragen dasselbe Geheimnis; sie werden nicht aufgehoben, sondern entfernt.
for ZIEL in "$BASE/config/plugins/$PFOLDER.backup.json.tmp."*; do
    [ -e "$ZIEL" ] || continue
    rm -f "$ZIEL" 2>/dev/null && BEISEITE="$BEISEITE (Rest $ZIEL entfernt)"
done
for A in "$BASE/config/plugins/$PFOLDER.backup.json.alt" \
         "$BASE/config/plugins/$PFOLDER.backup.json.neu.alt" \
         "$BASE/config/plugins/$PFOLDER.backup.setup_token.alt"; do
    [ -f "$A" ] && [ ! -L "$A" ] && chmod 600 "$A" 2>/dev/null
done
if [ -n "$BEISEITE" ] || [ -n "$FEST" ]; then
    T="<WARNING> Neuinstallation: Merkwort und Einstellungen einer frueheren Installation werden NICHT eingespielt."
    [ -n "$BEISEITE" ] && T="$T Beiseitegelegt:$BEISEITE (die Deinstallation raeumt sie ab)."
    [ -n "$FEST" ] && T="$T Nicht zu verschieben, bitte von Hand entfernen:$FEST"
    echo "$T"
fi
exit 0
