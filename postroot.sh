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
	DKTMP=$(mktemp -d) || DKTMP=/tmp
	if ! curl -fsSL https://get.docker.com -o "$DKTMP/get-docker.sh"
	then
		echo "<FAIL> Das Installationsskript von get.docker.com liess sich nicht laden."
		echo "<FAIL> Hat der LoxBerry gerade eine Internetverbindung?"
		echo "<INFO> Von Hand nachholen: curl -fsSL https://get.docker.com | sh"
		rm -rf "$DKTMP"
		exit 1
	fi
	if ! sh "$DKTMP/get-docker.sh"
	then
		echo "<FAIL> Die Einrichtung von Docker ist fehlgeschlagen."
		echo "<INFO> Die Meldungen darueber nennen den Grund."
		rm -rf "$DKTMP"
		exit 1
	fi
	rm -rf "$DKTMP"
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

if ! docker info >/dev/null 2>&1
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
#   - Geschrieben wird NUR, wenn noch gar keine log-opts gesetzt sind. Eine
#     vorhandene Einstellung wird nie ueberschrieben - der Anwender hat sie
#     dann aus einem Grund gesetzt.
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
	SCHONDA=$(php -r '$f=$argv[1];
		$d=is_file($f)?json_decode((string)@file_get_contents($f),true):array();
		echo (is_array($d)&&isset($d["log-opts"]["max-size"]))?"1":"0";' "$DJ" 2>/dev/null)
	LESBAR=$(php -r '$f=$argv[1];
		if(!is_file($f)){echo "1";exit;}
		echo is_array(json_decode((string)@file_get_contents($f),true))?"1":"0";' "$DJ" 2>/dev/null)

	if [ "$SCHONDA" = "1" ]
	then
		echo "<OK> Die Log-Rotation von Docker ist bereits eingestellt - unangetastet gelassen."
	elif [ "$LESBAR" != "1" ]
	then
		echo "<INFO> $DJ ist vorhanden, laesst sich aber nicht als JSON lesen."
		echo "<INFO> Sie wird NICHT angefasst. Die Container-Protokolle bleiben damit"
		echo "<INFO> unbegrenzt; der Reiter Test sagt das."
	else
		mkdir -p /etc/docker
		if php -r '$f=$argv[1];
			$d=is_file($f)?json_decode((string)@file_get_contents($f),true):array();
			if(!is_array($d)){exit(1);}
			if(!isset($d["log-driver"])){$d["log-driver"]="json-file";}
			$o=isset($d["log-opts"])&&is_array($d["log-opts"])?$d["log-opts"]:array();
			$o["max-size"]="10m"; $o["max-file"]="3";
			$d["log-opts"]=$o;
			$j=json_encode($d,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES);
			if($j===false){exit(1);}
			$t=$f.".tmp.".getmypid();
			if(@file_put_contents($t,$j."\n")===false){exit(1);}
			@chmod($t,0644);
			exit(@rename($t,$f)?0:1);' "$DJ"
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

vorhanden=$(docker ps -a --filter "name=^portainer$" -q)

if [ -n "$vorhanden" ]
then
	laeuft=$(docker ps --filter "name=^portainer$" -q)
	if [ -n "$laeuft" ]
	then
		echo "<OK> Der Container portainer ist vorhanden und laeuft."
	elif docker start portainer >/dev/null 2>&1
	then
		echo "<OK> Der vorhandene Container portainer wurde gestartet."
	else
		echo "<FAIL> Der vorhandene Container portainer liess sich nicht starten."
		echo "<INFO> Nachsehen mit: docker logs portainer"
	fi
	echo "<INFO> Ein vorhandener Container wird NICHT ersetzt - eigene Einstellungen"
	echo "<INFO> bleiben damit erhalten. Wer die Vorgaben dieses Plugins will,"
	echo "<INFO> entfernt ihn vorher von Hand:  docker rm -f portainer"
else
	if ! docker pull portainer/portainer-ce:latest
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

	GRUND="--volume=/var/run/docker.sock:/var/run/docker.sock --volume=/opt/portainer:/data -p=9000:9000 -p=9443:9443 --name=portainer --restart=unless-stopped --detach=true"
	ANGELEGT=0
	if [ -n "$SETUPTOKEN" ]
	then
		if docker run $GRUND portainer/portainer-ce:latest --http-enabled --setup-token "$SETUPTOKEN" >/dev/null 2>&1
		then
			ANGELEGT=1
			echo "<OK> Container portainer angelegt (Port 9000, HTTPS 9443, Token vorgegeben)."
			# Der Token ist ein Geheimnis: 0600, und er gehoert loxberry, damit
			# die Oberflaeche ihn lesen kann. Er liegt NICHT in dockerng.json -
			# ein Wert, ein Zweck, und diese Datei ueberlebt bewusst anders.
			if [ -n "$PCONFIG" ] && [ -d "$PCONFIG" ]
			then
				printf '%s' "$SETUPTOKEN" > "$PCONFIG/setup_token"
				chmod 600 "$PCONFIG/setup_token"
				chown loxberry:loxberry "$PCONFIG/setup_token" 2>/dev/null
				echo "<INFO> Der Einrichtungstoken steht im Reiter Einstellungen der Plugin-Seite."
			fi
		else
			# Aufraeumen: ein halb angelegter Container mit dem Namen portainer
			# wuerde den zweiten Versuch scheitern lassen.
			docker rm -f portainer >/dev/null 2>&1
			echo "<INFO> Diese Fassung von Portainer kennt --setup-token offenbar nicht."
			echo "<INFO> Es wird ohne ihn erneut versucht; der Token steht dann wie bisher"
			echo "<INFO> im Containerprotokoll und wird von der Plugin-Seite dort abgelesen."
		fi
	fi

	if [ "$ANGELEGT" = "0" ]
	then
		if docker run $GRUND portainer/portainer-ce:latest --http-enabled
		then
			echo "<OK> Container portainer angelegt und gestartet (Port 9000, HTTPS 9443)."
		else
			echo "<FAIL> Der Container portainer liess sich nicht anlegen."
			echo "<INFO> Haeufigste Ursache: Port 9000 oder 9443 ist bereits belegt."
			exit 1
		fi
	fi
fi

# Exit with Status 0
exit 0
