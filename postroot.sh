#!/bin/bash

# Shell script which is executed by bash *AFTER* complete installation is done
# (*AFTER* postinstall and *AFTER* postupdate). Use with caution and remember,
# that all systems may be different!
#
# Exit code must be 0 if executed successfull. 
# Exit code 1 gives a warning but continues installation.
# Exit code 2 cancels installation.
#
# !!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!
# Will be executed as user "root".
# !!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!
#
# You can use all vars from /etc/environment in this script.
#
# We add 5 additional arguments when executing this script:
# command <TEMPFOLDER> <NAME> <FOLDER> <VERSION> <BASEFOLDER>
#
# For logging, print to STDOUT. You can use the following tags for showing
# different colorized information during plugin installation:
#
# <OK> This was ok!"
# <INFO> This is just for your information."
# <WARNING> This is a warning!"
# <ERROR> This is an error!"
# <FAIL> This is a fail!"

# To use important variables from command line use the following code:
COMMAND=$0    # Zero argument is shell command
PTEMPDIR=$1   # First argument is temp folder during install
PSHNAME=$2    # Second argument is Plugin-Name for scipts etc.
PDIR=$3       # Third argument is Plugin installation folder
PVERSION=$4   # Forth argument is Plugin version
#LBHOMEDIR=$5 # Comes from /etc/environment now. Fifth argument is
              # Base folder of LoxBerry
PTEMPPATH=$6  # Sixth argument is full temp path during install (see also $1)

# Combine them with /etc/environment
PCGI=$LBPCGI/$PDIR
PHTML=$LBPHTML/$PDIR
PTEMPL=$LBPTEMPL/$PDIR
PDATA=$LBPDATA/$PDIR
PLOG=$LBPLOG/$PDIR # Note! This is stored on a Ramdisk now!
PCONFIG=$LBPCONFIG/$PDIR
PSBIN=$LBPSBIN/$PDIR
PBIN=$LBPBIN/$PDIR

echo -n "<INFO> Current working folder is: "
pwd
echo "<INFO> Command is: $COMMAND"
echo "<INFO> Temporary folder is: $PTEMPDIR"
echo "<INFO> (Short) Name is: $PSHNAME"
echo "<INFO> Installation folder is: $PDIR"
echo "<INFO> Plugin version is: $PVERSION"
echo "<INFO> Plugin CGI folder is: $PCGI"
echo "<INFO> Plugin HTML folder is: $PHTML"
echo "<INFO> Plugin Template folder is: $PTEMPL"
echo "<INFO> Plugin Data folder is: $PDATA"
echo "<INFO> Plugin Log folder (on RAMDISK!) is: $PLOG"
echo "<INFO> Plugin CONFIG folder is: $PCONFIG"

# ---------------------------------------------------------------------------
# Docker einrichten
#
# Zwei Dinge waren hier bis 1.0.0 falsch, und das zweite ist das schwerere.
#
# 1. Geprueft wurde auf die DATEI /usr/bin/docker. Das Installationsskript von
#    get.docker.com legt docker je nach System auch unter /usr/local/bin ab,
#    und wer es ueber ein anderes Paket hat, hat es womoeglich woanders. Dann
#    waere Docker ein zweites Mal installiert worden. 'command -v' fragt den
#    Suchpfad und ist die richtige Frage.
#
# 2. usermod stand INNERHALB des Installationszweiges. War Docker schon da -
#    weil es jemand vorher von Hand installiert hat oder eine fruehere Fassung
#    des Plugins -, wurde loxberry der Gruppe docker NIE hinzugefuegt. Nicht
#    'erst nach einem Neustart', sondern nie. Das Plugin meldete dann dauerhaft
#    0 Container, und kein Neustart der Welt haette daran etwas geaendert.
#    Die Gruppenzuordnung gehoert deshalb heraus aus dem Zweig.
# ---------------------------------------------------------------------------

# PORTS (C12, neu in 1.3.9). Ist ein Port belegt? Gefragt wird 'ss -ltnH',
# sonst /proc/net/tcp und tcp6 (Zustand 0A = LISTEN) - keine Probe ins Netz.
# Rueckgabe 0 belegt, 1 frei, 2 nicht pruefbar.
dk_port_belegt()
{
	local p="$1" hex liste
	if command -v ss >/dev/null 2>&1 && liste=$(ss -ltnH 2>/dev/null)
	then
		printf '%s\n' "$liste" | awk '{print $4}' | sed 's/.*://' | grep -qx "$p" && return 0
		return 1
	fi
	if [ -r /proc/net/tcp ] || [ -r /proc/net/tcp6 ]
	then
		hex=$(printf '%04X' "$p")
		cat /proc/net/tcp /proc/net/tcp6 2>/dev/null \
			| awk -v h="$hex" '$4 == "0A" { n = split($2, a, ":"); if (toupper(a[n]) == h) f = 1 } END { exit f ? 0 : 1 }' \
			&& return 0
		return 1
	fi
	return 2
}

# Den ersten freien Port ab $1 waehlen, hoechstens 20 Versuche aufwaerts;
# $2 ist ein schon vergebener Port, der nicht noch einmal genommen wird.
# Ausgabe "<port> frei|ausgewichen|unpruefbar", Rueckgabe 1: keiner frei.
dk_port_waehlen()
{
	local wunsch="$1" schon="$2" i p rc
	for i in $(seq 0 19)
	do
		p=$((wunsch + i))
		[ "$p" -le 65535 ] || break
		[ "$p" = "$schon" ] && continue
		dk_port_belegt "$p"
		rc=$?
		if [ "$rc" = "2" ]
		then
			echo "$p unpruefbar"
			return 0
		fi
		if [ "$rc" = "1" ]
		then
			if [ "$i" = "0" ]; then echo "$p frei"; else echo "$p ausgewichen"; fi
			return 0
		fi
	done
	return 1
}

# FRIST fuer docker-Aufrufe (C3, neu in 1.3.9). Hing der Docker-Dienst, hing
# bis dahin die ganze Installation mit - dieses Skript laeuft als root unter
# der Sperre von plugininstall.pl. Ohne timeout laeuft der Befehl wie bisher.
dk_frist()
{
	local n="$1"
	shift
	if command -v timeout >/dev/null 2>&1
	then
		timeout -k 2 "$n" "$@"
	else
		"$@"
	fi
}

# ERGAENZT in 1.2.4: Rueckgabewerte auswerten.
#
# Bis 1.2.3 wurde weder der von curl noch der von 'sh get-docker.sh' angesehen,
# und das Skript endete in jedem Fall mit exit 0. Hatte der LoxBerry beim
# Installieren keine Internetverbindung, brach curl -f ab, die Datei gab es
# nicht, sh meldete "can't open" - und LoxBerry bekam Erfolg gemeldet. Der
# Anwender sah eine gruene, angeblich fertige Installation und danach ein
# Plugin, das nur noch "Docker wurde nicht gefunden" sagt.
#
# Nach der LoxBerry-Konvention: 1 = Warnung, Installation laeuft weiter;
# 2 = Abbruch. Hier ist 1 richtig - die Oberflaeche traegt auch ohne Docker
# und sagt im Klartext, was fehlt.
if ! command -v docker >/dev/null 2>&1
then
	echo "<INFO> Docker ist nicht vorhanden - es wird eingerichtet."
	# In ein eigenes Verzeichnis, nicht ins unbestimmte Arbeitsverzeichnis:
	# hier laeuft root, und ein relativer Pfad in einem fuer andere
	# schreibbaren Ordner ist ein Weg, ein untergeschobenes Skript
	# auszufuehren.
	#
	# BERICHTIGT in 1.3.9 (I4): hier stand 'mktemp -d || DKTMP=/tmp'.
	# Scheiterte mktemp, loeschte das Skript danach als root ganz /tmp -
	# samt dem Upload-Ordner des Installers (in WSL gemessen, die Attrappe
	# verweigerte). Jetzt: ohne eigenes Verzeichnis kein Weiter, und
	# geloescht wird nur ein Pfad mit dem Muster von mktemp.
	DKTMP=$(mktemp -d 2>/dev/null) || DKTMP=""
	case "$DKTMP" in
		"${TMPDIR:-/tmp}"/tmp.?*) ;;
		*)
			echo "<FAIL> Ein eigenes Arbeitsverzeichnis liess sich nicht anlegen (mktemp) -"
			echo "<FAIL> Docker wird nicht eingerichtet. Ist /tmp voll?"
			exit 1
			;;
	esac
	if ! curl -fsSL https://get.docker.com -o "$DKTMP/get-docker.sh"
	then
		echo "<FAIL> Das Installationsskript von get.docker.com liess sich nicht laden."
		echo "<FAIL> Hat der LoxBerry gerade eine Internetverbindung?"
		echo "<INFO> Von Hand nachholen: curl -fsSL https://get.docker.com | sh"
		rm -rf "${DKTMP:?}"
		exit 1
	fi
	if ! sh "$DKTMP/get-docker.sh"
	then
		echo "<FAIL> Die Einrichtung von Docker ist fehlgeschlagen."
		echo "<INFO> Die Meldungen darueber nennen den Grund."
		rm -rf "${DKTMP:?}"
		exit 1
	fi
	rm -rf "${DKTMP:?}"
	if ! command -v docker >/dev/null 2>&1
	then
		echo "<FAIL> Nach der Einrichtung ist docker weiterhin nicht auffindbar."
		exit 1
	fi
	echo "<OK> Docker eingerichtet: $(docker --version 2>&1 | head -1)"
else
	echo "<OK> Docker ist bereits vorhanden: $(docker --version 2>&1 | head -1)"
fi

# Gruppenzuordnung IMMER pruefen, nicht nur bei einer Neuinstallation.
if getent group docker >/dev/null 2>&1
then
	if id -nG loxberry 2>/dev/null | tr ' ' '\n' | grep -qx docker
	then
		echo "<OK> Benutzer loxberry ist in der Gruppe docker."
	elif usermod -aG docker loxberry
	then
		echo "<OK> Benutzer loxberry der Gruppe docker hinzugefuegt."
	else
		echo "<FAIL> Benutzer loxberry liess sich der Gruppe docker nicht hinzufuegen."
		echo "<FAIL> Von Hand nachholen: sudo usermod -aG docker loxberry"
	fi
else
	echo "<FAIL> Die Gruppe docker gibt es nicht - ist Docker wirklich eingerichtet?"
fi

# ---------------------------------------------------------------------------
# Und der Punkt, an dem die meisten haengen bleiben
#
# Eine neue Gruppe wirkt erst in einer NEUEN Sitzung. Der Webserver laeuft
# bereits, und Linux zieht Gruppen fuer laufende Prozesse nicht nach - PHP
# darf also weiterhin nicht an /var/run/docker.sock, obwohl loxberry jetzt in
# der Gruppe steht.
#
# Der Webserver wird hier BEWUSST NICHT neu gestartet: dieses Skript laeuft
# waehrend der Installation, und die Installationsausgabe wird gerade ueber
# genau diesen Webserver angezeigt. Ein Neustart mittendrin risse die Seite
# ab, und der Anwender saehe einen Abbruch statt einer fertigen Installation.
#
# Stattdessen wird es benannt - und die Oberflaeche erkennt den Zustand
# selbst und sagt dasselbe noch einmal an der Stelle, an der er auffaellt.
#
# BERICHTIGT in 1.2.4 - bis 1.2.3 stand hier
#
#     su loxberry -s /bin/sh -c "docker ps >/dev/null 2>&1"
#
# und das misst das Falsche. 'su' legt fuer den Zielbenutzer eine NEUE Sitzung
# an und liest die Gruppen frisch aus /etc/group - die eben hinzugefuegte
# Gruppe docker ist dort sofort wirksam. Der Test lief also nach JEDER
# Neuinstallation durch, das Skript meldete "erreicht den Docker-Socket
# bereits" und uebersprang den else-Zweig mit der einzigen Anweisung, auf die
# es ankommt. Der Anwender startete nicht neu, oeffnete die Oberflaeche und
# las dort das Gegenteil.
#
# Gefragt wird jetzt der laufende Webserver selbst: welche Gruppen stehen in
# /proc/<pid>/status? Das ist genau die Frage, um die es geht - nicht "koennte
# eine neue Sitzung", sondern "kann der Prozess, der die Seite ausliefert".
dk_gid=$(getent group docker | cut -d: -f3)
dk_wpid=""
for dk_n in apache2 httpd nginx php-fpm
do
	dk_wpid=$(pgrep -u loxberry -x "$dk_n" 2>/dev/null | head -1)
	[ -n "$dk_wpid" ] && break
done

if [ -z "$dk_gid" ]
then
	echo "<INFO> Die Gruppe docker gibt es nicht - der Socket-Test entfaellt."
elif [ -n "$dk_wpid" ] && [ -r "/proc/$dk_wpid/status" ] \
     && grep '^Groups:' "/proc/$dk_wpid/status" | tr ' ' '\n' | grep -qx "$dk_gid"
then
	echo "<OK> Der laufende Webserver (PID $dk_wpid) hat die Gruppe docker bereits."
	echo "<OK> Ein Neustart ist nicht noetig."
else
	# Deckt beides ab: Webserver laeuft ohne die Gruppe - UND: kein Prozess
	# gefunden, also nicht messbar. In beiden Faellen ist der Hinweis richtig
	# und schadet nicht. Ein Strich statt eines Befunds waere das Schlechteste.
	if [ -z "$dk_wpid" ]
	then
		echo "<INFO> Kein Webserver-Prozess des Benutzers loxberry gefunden -"
		echo "<INFO> ob er die Gruppe docker hat, ist von hier aus nicht messbar."
	fi
	echo "<INFO> ACHTUNG: der Webserver kann den Docker-Socket voraussichtlich NOCH NICHT lesen."
	echo "<INFO> Das ist nach einer frischen Installation normal - eine neue Gruppe"
	echo "<INFO> wirkt erst in einer neuen Sitzung, und der Webserver laeuft schon."
	echo "<INFO> Bis dahin meldet das Plugin 0 Container."
	echo "<INFO> Abhilfe: den LoxBerry einmal neu starten."
	echo "<INFO> Wer nicht neu starten will, genuegt auch:"
	echo "<INFO>   sudo systemctl restart apache2"
fi


# ---------------------------------------------------------------------------
# Portainer
#
# BERICHTIGT in 1.2.4, drei Punkte:
#
# 1. Der Block stand ausserhalb jeder Pruefung auf docker. Fehlte Docker,
#    lieferte 'docker ps' ein "command not found" auf stderr und container=""
#    - womit die Bedingung WAHR wurde und das Skript den Zweig betrat. Es
#    folgten vier weitere rohe "command not found"-Zeilen im
#    Installationsprotokoll, ohne <FAIL>, ohne Abbruch.
#
# 2. Gefragt wurde 'docker ps' OHNE -a, also nur nach LAUFENDEN Containern.
#    Ein vorhandener, aber bewusst angehaltener Portainer wurde deshalb nicht
#    erkannt - und zwei Zeilen weiter mit 'docker rm --force' geloescht und
#    mit den Vorgaben des Plugins neu angelegt. Eigene docker-run-Zusaetze
#    waren damit fort. Jetzt wird ein vorhandener Container nur noch
#    GESTARTET, nicht ersetzt.
#
# 3. '--filter name=portainer' ist bei Docker ein Teilstring-Vergleich: er
#    trifft auch 'my-portainer' und 'portainer2'. Mit ^...$ wird daraus ein
#    genauer Vergleich.
# ---------------------------------------------------------------------------

if ! command -v docker >/dev/null 2>&1
then
	echo "<FAIL> Docker ist nicht verfuegbar - Portainer wird nicht eingerichtet."
	exit 1
fi

if ! dk_frist 30 docker info >/dev/null 2>&1
then
	echo "<FAIL> Der Docker-Dienst antwortet nicht - Portainer wird nicht eingerichtet."
	echo "<INFO> Pruefen mit: systemctl status docker"
	exit 1
fi

# ---------------------------------------------------------------------------
# Log-Rotation von Docker  (neu in 1.3.0)
#
# Der json-file-Treiber hat als Vorgabe max-size = -1, also UNBEGRENZT, und
# max-file = 1 wirkt nur zusammen mit max-size. Ein einziger gespraechiger
# Container in einer Reconnect-Schleife schreibt damit die Speicherkarte voll -
# und ein volles Dateisystem macht den LoxBerry unbootbar. Das ist der einzige
# Punkt an diesem Plugin, der das kann.
#
# ZURUECKHALTUNG, BEWUSST:
#   - Geschrieben wird NUR, wenn es noch GAR KEINEN Abschnitt log-opts gibt
#     UND der Treiber fehlt oder json-file bzw. local ist. Eine vorhandene
#     Einstellung wird nie ueberschrieben - der Anwender hat sie aus einem
#     Grund gesetzt.
#     BERICHTIGT in 1.3.9 (I3): bis dahin stand hier dasselbe, geprueft
#     wurde aber nur log-opts.max-size. Gemessen (WSL): {"log-opts":
#     {"max-file":"5"}} wurde zu max-file 3; {"log-driver":"journald"}
#     bekam max-size und max-file dazu, die journald nicht kennt - dockerd
#     prueft die Optionen gegen den Treiber, der naechste Start von Docker
#     waere daran vermutlich gescheitert (nicht gemessen, kein Docker in
#     WSL); die Rechte 640 wurden zu 644.
#   - Vorher entsteht eine Kopie daemon.json.vor-dockerng (Modus erhalten),
#     und die neue Datei behaelt den Modus der alten.
#   - Eine vorhandene daemon.json wird ZUSAMMENGEFUEHRT, nicht ersetzt. Auf
#     einem LoxBerry kann dort schon etwas stehen.
#   - Docker wird NICHT neu gestartet. Das riss alle Container mit, mitten in
#     der Installation. Was zu tun ist, wird benannt - genau wie beim
#     Webserver weiter oben.
#   - Und es wird gesagt, was die Einstellung NICHT tut: sie wirkt nur auf
#     kuenftig erzeugte Container. Bestehende behalten ihre alten Vorgaben.
# ---------------------------------------------------------------------------
DJ=/etc/docker/daemon.json
if command -v php >/dev/null 2>&1
then
	DJLAGE=$(php -r '$f=$argv[1];
		if(!is_file($f)){echo "NOETIG";exit;}
		$d=json_decode((string)@file_get_contents($f),true);
		if(!is_array($d)){echo "UNLESBAR";exit;}
		if(array_key_exists("log-opts",$d)){echo "SCHON";exit;}
		$t=isset($d["log-driver"])?(string)$d["log-driver"]:"";
		echo ($t===""||$t==="json-file"||$t==="local")?"NOETIG":"TREIBER:".$t;' "$DJ" 2>/dev/null)

	if [ "$DJLAGE" = "SCHON" ]
	then
		echo "<OK> In $DJ stehen bereits log-opts - unangetastet gelassen."
		echo "<INFO> Ob die Protokolle damit begrenzt sind, zeigt der Reiter Test."
	elif [ "$DJLAGE" = "UNLESBAR" ]
	then
		echo "<INFO> $DJ ist vorhanden, laesst sich aber nicht als JSON lesen."
		echo "<INFO> Sie wird NICHT angefasst. Die Container-Protokolle bleiben damit"
		echo "<INFO> unbegrenzt; der Reiter Test sagt das."
	elif [ "${DJLAGE%%:*}" = "TREIBER" ]
	then
		echo "<INFO> In $DJ ist der Protokolltreiber ${DJLAGE#TREIBER:} eingestellt - er kennt"
		echo "<INFO> max-size und max-file nicht. Die Datei wird NICHT angefasst."
	elif [ "$DJLAGE" != "NOETIG" ]
	then
		echo "<INFO> $DJ liess sich nicht pruefen - sie wird NICHT angefasst."
	else
		mkdir -p "$(dirname "$DJ")"
		DJMODUS="644"
		if [ -f "$DJ" ]
		then
			DJMODUS=$(stat -c %a "$DJ" 2>/dev/null || echo 644)
			if [ ! -e "$DJ.vor-dockerng" ]
			then
				cp -p "$DJ" "$DJ.vor-dockerng" && echo "<INFO> Die bisherige Datei liegt als $DJ.vor-dockerng daneben."
			fi
		fi
		case "$DJMODUS" in [0-7][0-7][0-7]|[0-7][0-7][0-7][0-7]) ;; *) DJMODUS="644" ;; esac
		if php -r '$f=$argv[1];
			$d=is_file($f)?json_decode((string)@file_get_contents($f),true):array();
			if(!is_array($d)){exit(1);}
			$d["log-opts"]=array("max-size"=>"10m","max-file"=>"3");
			$j=json_encode($d,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES);
			if($j===false){exit(1);}
			$j.="\n";
			$t=$f.".tmp.".getmypid();
			$h=@fopen($t,"c"); if($h===false){exit(1);}
			@chmod($t,octdec($argv[2]));
			$ok=ftruncate($h,0)&&fwrite($h,$j)===strlen($j); fclose($h);
			if(!$ok){@unlink($t);exit(1);}
			exit(@rename($t,$f)?0:1);' "$DJ" "$DJMODUS"
		then
			echo "<OK> Log-Rotation eingerichtet: max-size 10m, max-file 3 (in $DJ)."
			echo "<INFO> Sie wirkt erst nach einem Neustart des Docker-Dienstes:"
			echo "<INFO>   sudo systemctl restart docker"
			echo "<INFO> Der wird hier BEWUSST nicht ausgeloest - er riesse alle Container mit."
			echo "<INFO> ACHTUNG: die Einstellung gilt nur fuer KUENFTIG erzeugte Container."
			echo "<INFO> Bestehende behalten ihre alten Vorgaben und muessen dafuer neu"
			echo "<INFO> erzeugt werden."
			echo "<INFO> Rueckgaengig: den Abschnitt log-opts aus $DJ entfernen."
		else
			echo "<FAIL> $DJ liess sich nicht schreiben - die Container-Protokolle bleiben unbegrenzt."
		fi
	fi
else
	echo "<INFO> Kein PHP gefunden - die Log-Rotation wurde nicht geprueft."
fi

# ---------------------------------------------------------------------------
# Welcher Container gehoert diesem Plugin? (C1 und I2, Entscheidung 9)
#
# Das entscheidet bin/dk_eigen.php - DIESELBE Pruefung wie beim Knopf
# "Portainer neu starten" und bei der Deinstallation:
#   - mit den Labels de.loxberry.plugin.folder=<ordner> und
#     de.loxberry.plugin.name=dockerng: unserer;
#   - ohne Label (Altbestand vor 1.3.9): nur ein Container, der portainer
#     heisst UND aus einem Abbild portainer/portainer-* stammt;
#   - alles andere wird nicht angefasst.
#
# BERICHTIGT in 1.3.9 (I2), alles in WSL gemessen:
#   1. Gefragt wurde fest nach dem Namen portainer. Neben einem portainer-ce
#      des Anwenders entstand ein ZWEITER Portainer. Jetzt: gibt es einen
#      fremden Portainer, wird keiner angelegt, und es gibt einen Hinweis.
#   2. Ein angehaltener Portainer wurde bei JEDEM Upgrade wieder gestartet -
#      mit AUTOMATIC_UPDATES auch ungefragt. Jetzt bleibt ein vorhandener
#      Container, wie er ist; gestartet wird nur, was hier neu entsteht.
#   3. Ein neu angelegter Container bekommt beide Labels.
# ---------------------------------------------------------------------------
case "$PDIR" in ''|*[!A-Za-z0-9_]*) PDIR_LABEL="dockerng" ;; *) PDIR_LABEL="$PDIR" ;; esac
EIGENPHP="$PBIN/dk_eigen.php"
LAGE=""
LAGE_RC=2
if [ -f "$EIGENPHP" ] && command -v php >/dev/null 2>&1
then
	LAGE=$(dk_frist 120 php "$EIGENPHP" "$PDIR_LABEL" 2>/dev/null)
	LAGE_RC=$?
fi
EIGEN_NAME=""
EIGEN_GRUND=""
EIGEN_LAEUFT=""
FREMDE=""
PRUEF_FEHLER=""
TAB=$(printf '\t')
while IFS="$TAB" read -r dk_art dk_n dk_g dk_l dk_b
do
	case "$dk_art" in
		EIGEN)  EIGEN_NAME="$dk_n"; EIGEN_GRUND="$dk_g"; EIGEN_LAEUFT="$dk_l" ;;
		FREMD)  FREMDE="$FREMDE $dk_n ($dk_l, $dk_g)" ;;
		FEHLER) PRUEF_FEHLER="$dk_n" ;;
	esac
done <<< "$LAGE"

if [ "$LAGE_RC" != "0" ] || [ -n "$PRUEF_FEHLER" ]
then
	echo "<FAIL> Welcher Container zu diesem Plugin gehoert, liess sich nicht pruefen (${PRUEF_FEHLER:-Rueckgabewert $LAGE_RC})."
	echo "<INFO> Es wird kein Portainer angelegt und keiner angefasst."
	exit 1
elif [ -n "$EIGEN_NAME" ]
then
	if [ "$EIGEN_LAEUFT" = "1" ]
	then
		echo "<OK> Der eigene Container $EIGEN_NAME ist vorhanden und laeuft."
	else
		echo "<INFO> Der eigene Container $EIGEN_NAME ist vorhanden, aber angehalten. Er wird"
		echo "<INFO> NICHT gestartet - wer ihn angehalten hat, wollte das. Starten: in Portainer"
		echo "<INFO> oder mit  docker start $EIGEN_NAME"
	fi
	if [ "$EIGEN_GRUND" = "ALTBESTAND" ]
	then
		echo "<INFO> Er traegt noch keine Labels (angelegt vor 1.3.9) und wird am Namen portainer"
		echo "<INFO> und am Abbild portainer/portainer-* erkannt. Er wird NICHT ersetzt."
	fi
	if [ -n "$FREMDE" ]
	then
		echo "<INFO> Weitere Portainer-Container, die dieses Plugin nicht anfasst:$FREMDE"
	fi
elif [ -n "$FREMDE" ]
then
	echo "<INFO> Es gibt bereits Portainer-Container, die nicht zu diesem Plugin gehoeren:$FREMDE"
	echo "<INFO> Es wird KEIN zweiter angelegt - zwei Portainer streiten um die Ports 9000"
	echo "<INFO> und 9443. Der Reiter Einstellungen nennt je Container den Grund."
else
	# Ports (C12): die Einstellung, bei Belegung der naechste freie. Nur hier,
	# beim NEUANLEGEN - ein bestehender eigener Container behaelt seine Ports.
	W_HTTP=9000
	W_HTTPS=9443
	if [ -f "$PBIN/dk_ports.php" ] && command -v php >/dev/null 2>&1
	then
		WUNSCH=$(dk_frist 60 php "$PBIN/dk_ports.php" lesen 2>/dev/null)
		case "$WUNSCH" in
			*[!0-9\ ]*|'') ;;
			*\ *) W_HTTP="${WUNSCH%% *}"; W_HTTPS="${WUNSCH##* }" ;;
		esac
	fi
	if [ "$W_HTTP" -lt 1024 ] || [ "$W_HTTP" -gt 65535 ] || [ "$W_HTTPS" -lt 1024 ] || [ "$W_HTTPS" -gt 65535 ] || [ "$W_HTTP" = "$W_HTTPS" ]
	then
		echo "<WARNING> Die eingestellten Ports ($W_HTTP, $W_HTTPS) sind unzulaessig - es gelten 9000 und 9443."
		W_HTTP=9000
		W_HTTPS=9443
	fi
	if ! WAHL_HTTP=$(dk_port_waehlen "$W_HTTP" "")
	then
		echo "<FAIL> Fuer HTTP ist keiner der Ports $W_HTTP bis $((W_HTTP + 19)) frei. Portainer wird NICHT angelegt."
		echo "<INFO> In den Einstellungen des Plugins einen anderen Port eintragen und das Plugin noch einmal installieren."
		exit 1
	fi
	HTTP_PORT="${WAHL_HTTP%% *}"
	if ! WAHL_HTTPS=$(dk_port_waehlen "$W_HTTPS" "$HTTP_PORT")
	then
		echo "<FAIL> Fuer HTTPS ist keiner der Ports $W_HTTPS bis $((W_HTTPS + 19)) frei. Portainer wird NICHT angelegt."
		echo "<INFO> In den Einstellungen des Plugins einen anderen Port eintragen und das Plugin noch einmal installieren."
		exit 1
	fi
	HTTPS_PORT="${WAHL_HTTPS%% *}"
	case "$WAHL_HTTP $WAHL_HTTPS" in
		*unpruefbar*) echo "<INFO> Ob die Ports frei sind, liess sich nicht pruefen (weder ss noch /proc/net/tcp) - es gelten die eingestellten." ;;
	esac

	if ! dk_frist 900 docker pull portainer/portainer-ce:latest
	then
		echo "<FAIL> Das Abbild portainer/portainer-ce:latest liess sich nicht laden."
		echo "<INFO> Hat der LoxBerry gerade eine Internetverbindung?"
		exit 1
	fi

	# Portainer CE ab 2.19 startet ohne --http-enabled NUR mit HTTPS auf 9443.
	# Ohne dieses Flag laeuft der Container zwar, aber auf Port 9000 lauscht
	# nichts - der Browser meldet dann "Verbindung abgelehnt".
	# Deshalb: HTTP ausdruecklich einschalten und zusaetzlich 9443 mappen,
	# damit auch der HTTPS-Zugang erreichbar ist.
	#
	# NEU in 1.3.0: der Einrichtungstoken wird VORGEGEBEN.
	#
	# Ab Portainer 2.43 bzw. 2.39.4 verlangt die Ersteinrichtung einen Token,
	# der sonst nur im Containerprotokoll steht. Dieses Plugin hat ihn bis 1.2.4
	# von dort gefischt - was funktionierte, aber an ein Ausgabeformat gebunden
	# war, das Portainer jederzeit aendern kann. Zweimal ist genau das schon
	# passiert (2.19 und 2.43).
	#
	# Mit --setup-token steht der Wert fest, das Fischen entfaellt, und er
	# ueberlebt jeden Neustart des Containers: er steht in der Befehlszeile,
	# und 'docker restart' benutzt dieselbe wieder. Das Fuenf-Minuten-Fenster
	# bleibt - aber der Knopf "Portainer neu starten" oeffnet es jetzt
	# zuverlaessig mit einem BEKANNTEN Token.
	#
	# Ob die Fassung von Portainer diesen Schalter kennt, ist von hier aus nicht
	# nachgemessen. Deshalb wird bei Fehlschlag OHNE ihn erneut versucht - und
	# gesagt, dass es der alte Weg geworden ist.
	SETUPTOKEN=$(tr -dc 'a-zA-Z0-9' </dev/urandom 2>/dev/null | head -c 24)
	[ ${#SETUPTOKEN} -ge 16 ] || SETUPTOKEN=""

	GRUND="--volume=/var/run/docker.sock:/var/run/docker.sock --volume=/opt/portainer:/data -p=$HTTP_PORT:9000 -p=$HTTPS_PORT:9443 --name=portainer --restart=unless-stopped --detach=true"
	# Die Labels, an denen das Plugin seinen Container erkennt (C1).
	LABELS="--label=de.loxberry.plugin.folder=$PDIR_LABEL --label=de.loxberry.plugin.name=dockerng"
	ANGELEGT=0
	if [ -n "$SETUPTOKEN" ]
	then
		if dk_frist 300 docker run $GRUND $LABELS portainer/portainer-ce:latest --http-enabled --setup-token "$SETUPTOKEN" >/dev/null 2>&1
		then
			ANGELEGT=1
			echo "<OK> Container portainer angelegt (Port $HTTP_PORT, HTTPS $HTTPS_PORT, Token vorgegeben, Labels gesetzt)."
			# Der Token ist ein Geheimnis: 0600 schon beim Anlegen (umask), und er
			# gehoert loxberry, damit die Oberflaeche ihn lesen kann. Er liegt
			# NICHT in dockerng.json - ein Wert, ein Zweck.
			# BERICHTIGT in 1.3.9 (I5): hier stand, diese Datei "ueberlebt bewusst
			# anders". Sie ueberlebte kein Upgrade - der Installer raeumt den
			# Konfigurationsordner ab. Deshalb liegt daneben eine Zweitschrift
			# (0600), die preupgrade.sh erneuert, postinstall.sh bei einer
			# Aktualisierung zurueckspielt und die Deinstallation abraeumt.
			if [ -n "$PCONFIG" ] && [ -d "$PCONFIG" ]
			then
				( umask 077 && printf '%s' "$SETUPTOKEN" > "$PCONFIG/setup_token" )
				chmod 600 "$PCONFIG/setup_token"
				chown loxberry:loxberry "$PCONFIG/setup_token" 2>/dev/null
				STB="$(dirname "$PCONFIG")/$PDIR.backup.setup_token"
				( umask 077 && printf '%s' "$SETUPTOKEN" > "$STB" )
				chmod 600 "$STB"
				chown loxberry:loxberry "$STB" 2>/dev/null
				echo "<INFO> Der Einrichtungstoken steht im Reiter Einstellungen der Plugin-Seite."
			fi
		else
			# Aufraeumen: ein halb angelegter Container mit dem Namen portainer
			# wuerde den zweiten Versuch scheitern lassen. Entfernt wird er nur,
			# wenn er das eigene Label traegt - also eben hier entstand.
			if [ "$(dk_frist 30 docker inspect --format '{{index .Config.Labels "de.loxberry.plugin.folder"}}' portainer 2>/dev/null)" = "$PDIR_LABEL" ]
			then
				dk_frist 30 docker rm -f portainer >/dev/null 2>&1
			fi
			echo "<INFO> Diese Fassung von Portainer kennt --setup-token offenbar nicht."
			echo "<INFO> Es wird ohne ihn erneut versucht; der Token steht dann wie bisher"
			echo "<INFO> im Containerprotokoll und wird von der Plugin-Seite dort abgelesen."
		fi
	fi

	if [ "$ANGELEGT" = "0" ]
	then
		if dk_frist 300 docker run $GRUND $LABELS portainer/portainer-ce:latest --http-enabled
		then
			echo "<OK> Container portainer angelegt und gestartet (Port $HTTP_PORT, HTTPS $HTTPS_PORT, Labels gesetzt)."
		else
			echo "<FAIL> Der Container portainer liess sich nicht anlegen."
			echo "<INFO> Haeufigste Ursache: Port $HTTP_PORT oder $HTTPS_PORT ist bereits belegt."
			exit 1
		fi
	fi

	# Ausgewichen? Dann den benutzten Port in Konfiguration und Zweitschrift
	# eintragen und es laut sagen (C12).
	if [ "$HTTP_PORT" != "$W_HTTP" ] || [ "$HTTPS_PORT" != "$W_HTTPS" ]
	then
		[ "$HTTP_PORT" != "$W_HTTP" ] && echo "<WARNING> Port $W_HTTP ist belegt - Portainer lauscht fuer HTTP auf Port $HTTP_PORT."
		[ "$HTTPS_PORT" != "$W_HTTPS" ] && echo "<WARNING> Port $W_HTTPS ist belegt - Portainer lauscht fuer HTTPS auf Port $HTTPS_PORT."
		EINTRAG=""
		if [ -f "$PBIN/dk_ports.php" ] && command -v php >/dev/null 2>&1
		then
			EINTRAG=$(dk_frist 60 php "$PBIN/dk_ports.php" setzen "$HTTP_PORT" "$HTTPS_PORT" 2>/dev/null)
		fi
		chown loxberry:loxberry "$PCONFIG/dockerng.json" "$(dirname "$PCONFIG")/$PDIR.backup.json" "$PCONFIG/mqtt_subscriptions.cfg" 2>/dev/null
		case "$EINTRAG" in
			"KONFIG 1 ZWEIT 1") echo "<WARNING> Eingetragen in Konfiguration und Zweitschrift: HTTP $HTTP_PORT, HTTPS $HTTPS_PORT." ;;
			"KONFIG 1 ZWEIT 0") echo "<WARNING> Eingetragen in die Konfiguration: HTTP $HTTP_PORT, HTTPS $HTTPS_PORT (eine Zweitschrift entsteht mit dem ersten Merkwort)." ;;
			*) echo "<FAIL> Die benutzten Ports liessen sich NICHT in die Konfiguration eintragen - die Plugin-Seite liest sie am Container ab." ;;
		esac
	fi
fi

# Exit with Status 0
exit 0
