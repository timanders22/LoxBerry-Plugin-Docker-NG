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

if ! command -v docker >/dev/null 2>&1
then
	echo "<INFO> Docker ist nicht vorhanden - es wird eingerichtet."
	curl -fsSL https://get.docker.com -o get-docker.sh
	sh get-docker.sh
	rm -f get-docker.sh
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
if [ -S /var/run/docker.sock ]
then
	if su loxberry -s /bin/sh -c "docker ps >/dev/null 2>&1"
	then
		echo "<OK> Der Benutzer loxberry erreicht den Docker-Socket bereits."
	else
		echo "<INFO> ACHTUNG: der Webserver kann den Docker-Socket noch NICHT lesen."
		echo "<INFO> Das ist nach einer frischen Installation normal - eine neue Gruppe"
		echo "<INFO> wirkt erst in einer neuen Sitzung, und der Webserver laeuft schon."
		echo "<INFO> Bis dahin meldet das Plugin 0 Container."
		echo "<INFO> Abhilfe: den LoxBerry einmal neu starten."
		echo "<INFO> Wer nicht neu starten will, genuegt auch:"
		echo "<INFO>   sudo systemctl restart apache2"
	fi
fi


# check if container ist in the corret version
container=$(docker ps --filter ancestor=portainer/portainer-ce:latest --filter name=portainer -q)
if [ "$container" = "" ]
then

	# check if container with name portainer exists
	container=$(docker ps -a --filter name=portainer -q)
	if [ -n "$container" ]
	then
		# remove stopped portainer container
		docker rm --force portainer
	fi

	# pull portainer docker image
	docker pull portainer/portainer-ce:latest

	# start portainer container
	#
	# Portainer CE ab 2.19 startet ohne --http-enabled NUR mit HTTPS auf 9443.
	# Ohne dieses Flag laeuft der Container zwar, aber auf Port 9000 lauscht
	# nichts - der Browser meldet dann "Verbindung abgelehnt".
	# Deshalb: HTTP ausdruecklich einschalten und zusaetzlich 9443 mappen,
	# damit auch der HTTPS-Zugang erreichbar ist.
	docker run --volume=/var/run/docker.sock:/var/run/docker.sock --volume=/opt/portainer:/data -p=9000:9000 -p=9443:9443 --name="portainer" --restart="unless-stopped" --detach=true portainer/portainer-ce:latest --http-enabled
fi

# Exit with Status 0
exit 0
