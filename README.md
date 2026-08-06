# LoxBerry-Plugin: Docker NG

Richtet **Docker** und **Portainer** auf dem LoxBerry ein und meldet den
Containerzustand an Loxone.

> **Fassung 1.0.0 — auf einem LoxBerry mit Debian trixie und PHP 8 gebaut.**
> Nicht geprüft ist das Verhalten auf älteren LoxBerry-Ständen; deshalb
> `LB_MINIMUM=3.0.0`.

## Verhältnis zum Plugin „Docker"

Docker NG ist ein **eigenständiges Plugin**, kein Update des älteren
[Docker-Plugins von M. Miklis](https://github.com/michaelmiklis/loxberry-plugin-docker).
Es benutzt einen eigenen Ordner (`dockerng`) und eine eigene Kennung — beide
lassen sich **nebeneinander installieren**, und wer beim Original bleiben will,
behält es unverändert.

Der Weg dorthin ist nachlesbar: Die Änderungen wurden dem Originalautor zuerst
als Pull Request angeboten
([#8](https://github.com/michaelmiklis/loxberry-plugin-docker/pull/8)). Dort
teilte @blacksun80 mit, dass Michael Miklis weder Docker noch einen LoxBerry
weiter betreibt und kein eigenes Interesse mehr am Plugin hat — verbunden mit
der ausdrücklichen Einladung, eine „Next Gen"-Fassung für die
LoxBerry-Gemeinde bereitzustellen. Das ist dieses Repository.

**Der ursprüngliche Beitrag bleibt offen stehen**, damit nachvollziehbar
bleibt, woher die Änderungen stammen.

## Die drei Fallstricke bei Portainer

Alle drei kosten ohne Vorwissen einen Abend.

1. **Port 9000 blieb stumm.** Ab Portainer CE 2.19 lauscht ohne
   `--http-enabled` nichts mehr auf Port 9000. Der Container läuft, der Browser
   meldet nur „Verbindung abgelehnt" — das sieht aus wie eine fehlgeschlagene
   Installation. `postroot.sh` setzt das Kennzeichen und gibt zusätzlich Port
   9443 frei.

2. **Der Setup-Token steht nur im Containerprotokoll.** Ab 2.43 beziehungsweise
   2.39.4 verlangt die Ersteinrichtung einen Token, der nirgends angezeigt wird
   und **nach fünf Minuten verfällt**. Zwei Knöpfe holen ihn beziehungsweise
   starten den Container neu und holen einen frischen.

   Nebenbei: Portainer schreibt **farbig**. Zwischen `setup_token=` und dem Wert
   steht eine ANSI-Escape-Sequenz — ohne deren Entfernen findet kein Suchmuster
   den Token. Das Muster wurde gegen die tatsächliche Ausgabe geprüft, nicht
   gegen eine ausgedachte Beispielzeile: die wäre farblos gewesen.

3. **Portainer lässt sich nicht einbetten.** Die Oberfläche schickt
   `X-Frame-Options` und verweigert die Anzeige in einem Rahmen. Der Knopf
   öffnet deshalb ein eigenes Fenster.

## Installation ohne Fehler 100

Zwei Pakete sind aus der Paketliste entfernt:

* `software-properties-common` stammt aus der Ubuntu-Welt (liefert
  `add-apt-repository`) und ist unter Debian trixie in den aktivierten Quellen
  nicht vorhanden. `apt` bricht darüber mit Fehler 100 ab — obwohl das Paket
  für Docker gar nicht gebraucht wird.
* `apt-transport-https` ist seit apt 1.5 ein leeres Übergangspaket; HTTPS kann
  apt von Haus aus.

`get.docker.com` verlangt beides ohnehin nicht.

## Der Endpunkt für Loxone

    /plugins/dockerng/index.php?token=<TOKEN>&aktion=status

| Aufruf | Antwort |
|---|---|
| `aktion=status` | `DOCKERNG;OK=1;GESAMT=3;LAEUFT=3;GESTOPPT=0;PORTAINER=1;C_portainer=1` |
| `aktion=container&name=…` | Zustand eines einzelnen Containers |
| `aktion=liste` | eine Zeile je Container |
| `aktion=roh` | vollständiger Zustand als JSON |

Je erkanntem Container steht zusätzlich eine eigene Stelle `C_<name>` in
derselben Zeile — damit lässt sich ein einzelner Container überwachen, ohne ein
zweites Mal abzufragen.

**Der Endpunkt ist rein lesend.** Er startet und stoppt nichts. Ein Endpunkt im
unangemeldeten Bereich, der Container anhalten kann, wäre eine Angriffsfläche
ohne Gegenwert — geschaltet wird in Portainer.

Das Merkwort wird beim ersten Öffnen der Oberfläche selbst erzeugt und mit
`hash_equals` verglichen, also in gleichbleibender Zeit. **Ohne gesetztes
Merkwort antwortet der Endpunkt mit 403** — auch lesend. Unbekannte Aktionen
und Containernamen, die nicht ins Muster passen, werden **abgewiesen und
benannt**, nicht stillschweigend zurechtgebogen: ein still gekürzter Name fände
den Container nicht und meldete „läuft nicht" — eine stille Falschaussage.

## Warum es keinen MQTT-Reiter gibt

Der Hausstandard sieht MQTT als Regelweg vor. Docker NG führt aber **keinen
Dienst**, der zyklisch veröffentlichen könnte; Loxone holt den Zustand über den
HTTP-Endpunkt. Ein MQTT-Weg wäre nachrüstbar, ist aber ungebaut — und wird
deshalb auch nicht behauptet.

## Aufbau

    webfrontend/htmlauth/index.php   Bedienoberflaeche, vier Reiter
    webfrontend/html/dk_lib.php      Pfade, Konfiguration, Sprache, Docker, Loxone-Vorlage
    webfrontend/html/index.php       Endpunkt fuer den Miniserver
    templates/lang/language_de.ini   Sprachdatei Deutsch
    templates/lang/language_en.ini   Sprachdatei Englisch
    templates/help/help.html         Hilfetext hinter dem Fragezeichen
    postroot.sh                      Docker und Portainer einrichten
    dpkg/apt                         Paketliste

Die Bibliothek liegt unter `html/` und nicht unter `htmlauth/`, weil der
Loxone-Endpunkt sie ebenfalls braucht. Eine zweite Kopie wäre die häufigste
Ursache dafür, dass zwei Dateien gleichen Namens auseinanderlaufen.

## Lizenz

**Apache License 2.0** — siehe [LICENSE.md](LICENSE.md).

Dieses Plugin ist ein abgeleitetes Werk des Docker-Plugins von Michael Miklis
und steht deshalb weiterhin unter der Apache-Lizenz 2.0. Die Urheberangabe des
Originals bleibt unverändert; die vorgenommenen Änderungen sind in
[NOTICE](NOTICE) aufgeführt, wie es Abschnitt 4 der Lizenz verlangt.

Weder mit Docker Inc. noch mit Portainer.io verbunden.
