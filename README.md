# LoxBerry-Plugin: Docker NG

Richtet **Docker** und **Portainer** auf dem LoxBerry ein und meldet den
Containerzustand an Loxone.

> **Fassung 1.0.0 — auf einem LoxBerry mit Debian trixie gebaut, laeuft ab PHP 7.4.**
> Nicht geprüft ist das Verhalten auf älteren LoxBerry-Ständen; deshalb
> `LB_MINIMUM=3.0.0`.

## Neu in 1.2.0 — fünf Befunde eines Mitlesers

Alle fünf am Quelltext nachgeprüft, alle fünf zutreffend. Kein einziger davon
war bei den vorigen Prüfungen aufgefallen, weil sie das Plugin **im entpackten
Archiv** geprüft haben statt im installierten Zustand. Genau dieser Unterschied
war der erste Befund — die Prüfung hat also den Fehler übersehen, den sie am
ehesten hätte finden müssen.

### Die Oberfläche startete installiert nicht (HTTP 500)

`require_once __DIR__ . '/../html/dk_lib.php'` deckt nur den Archivfall ab, in
dem `htmlauth/` und `html/` nebeneinander liegen. Installiert liegt jedes unter
**einer eigenen `plugins/`-Ebene**:

```
<home>/webfrontend/htmlauth/plugins/dockerng/index.php
<home>/webfrontend/html/plugins/dockerng/dk_lib.php
```

`__DIR__/../html/` zeigt dort auf `htmlauth/plugins/html/` — ein Verzeichnis,
das es nicht gibt. Fatal Error, HTTP 500, keine Zeile Ausgabe.

Jetzt eine Kandidatenliste, die beide Zustände abdeckt und auch eine
Umbenennung des Pluginordners überlebt. Der Melder schlug
`LBPHTMLDIR . "/dk_lib.php"` vor — das ginge ebenfalls, setzt aber voraus, dass
`loxberry_system.php` schon geladen ist. Die Kandidatenliste kommt ohne diese
Reihenfolgeabhängigkeit aus und ist in vierzehn weiteren Plugins dieses Autors
seit Wochen im Einsatz.

**Zur Einordnung:** Eine Durchsicht aller 41 Plugins im Arbeitsordner hat
gezeigt, dass Docker NG der **einzige** Fall dieses Fehlers war.

### Die Konfiguration überlebte keine Neuinstallation

Zutreffend, und die Folge ist besonders unangenehm: Mit der Konfiguration ging
das **Merkwort** verloren, das in den Adressen im Miniserver steckt. Der
virtuelle Eingang bekam danach nur noch HTTP 403 — ohne erkennbaren Anlass, weil
sich an der Loxone-Seite ja nichts geändert hatte.

Docker NG hatte bis 1.1.0 **überhaupt kein** `pre*`- oder `postinstall`-Skript,
nur `postroot.sh`. Es gab also nichts, was hätte sichern können.

Jetzt: `preupgrade.sh` legt die Sicherung an, `postinstall.sh` spielt sie
zurück. Der Ort ist dabei entscheidend — die Sicherung ist ein **Geschwister**
des Konfigurationsordners, kein Kind:

```
config/plugins/dockerng.backup.json      überlebt
config/plugins/dockerng/sicherung.json   fällt mit dem Ordner
```

Bewusst **nicht** `/tmp`: das ist auf dem LoxBerry eine Ramdisk und obendrein
für jeden lesbar; in der Datei steht ein Geheimnis. Zusätzlich heilt sich
`dk_config()` selbst, falls die Konfiguration leer vorgefunden wird.

*Was hier offengeblieben ist:* Der genaue Zeitpunkt, zu dem
`plugininstall.pl` den Konfigurationsordner entfernt, ist in dieser Sitzung
**nicht** am LoxBerry-Quelltext nachgeprüft worden. Die gewählte Lösung ist
deshalb so gebaut, dass sie in beiden Fällen trägt — ob der Ordner nun beim
Update gelöscht wird oder nur bei der Neuinstallation.

### Der Reiter „Logdateien" blieb dauerhaft leer

Zutreffend. `dk_log_lesen()` gab es, geschrieben hat die Datei **niemand**.
Jetzt gibt es `dk_log()` mit Rotation, und protokolliert werden: Speichern der
Konfiguration, Würfeln eines neuen Merkworts (die Tatsache, **nicht** der
Wert), Neustart des Containers und jede Störung beim Docker-Zugriff — letztere
gebremst auf höchstens eine Meldung je Stunde, sonst schreibt eine
Dauerstörung die Datei voll.

Dazu ein Hinweis, der vorher fehlte und der wichtiger ist als das Protokoll
selbst: **`log/` liegt auf dem LoxBerry auf einer Ramdisk.** Diese Datei
überlebt keinen Neustart. Sie steht jetzt als Warnung über dem Protokolltext.

`CUSTOM_LOGLEVELS=true` ist raus. Der Schalter blendet einen Loglevel-Wähler
ein, der ohne `LoxBerry::Log` wirkungslos ist — ein Bedienelement ohne Wirkung
lässt den Anwender suchen, was er falsch gemacht hat.

### Doppelte Maskierung an 16 Stellen

Zutreffend, und die Zahl stimmt auf den Punkt: `dk_e(dk_t(...))` bei
INI-Werten, die HTML-Entitäten enthalten. Im Browser stand wörtlich `l&auml;uft`
statt „läuft". Die 16 betroffenen Werte tragen jetzt echte UTF-8-Zeichen.

Eine Durchsicht aller Plugins fand dasselbe Bild an **40 Stellen in 13
Plugins** — Docker NG war mit 16 der schwerste Fall, aber kein Einzelfall. Die
übrigen werden bei der jeweils nächsten Überarbeitung nachgezogen.

### Hilfe nur auf Deutsch, kein Deinstallationsskript

Beides zutreffend. Es gibt jetzt `templates/help/help_en.html`; die Sprache
wird aus der LoxBerry-Einstellung abgeleitet, und `help.html` bleibt
Rückfallebene. Der vom Melder vorgeschlagene Weg über `help_de.ini`/`help_en.ini`
ist **nicht** übernommen worden — welchen Pfad `lbheader()` für den dritten
Parameter absucht, ist hier nicht am Quelltext nachgeprüft, und ein ungeprüfter
Umbau an genau der Stelle, an der schon einmal ein Pfad falsch war, wäre die
falsche Reihenfolge.

`uninstall/uninstall` hält den Portainer-Container an und entfernt ihn,
überschreibt die Sicherung mit dem Merkwort und löscht sie. **Docker selbst
bleibt stehen** — daran hängen auf einem LoxBerry regelmäßig weitere Container.
Der Befehl zum Entfernen steht im Protokoll der Deinstallation.

### Was nicht zutraf

`LB_MINIMUM=3.0.0` und **PHP 7.4**. Der Melder hat die Untergrenze selbst
gegengeprüft und für richtig befunden; das deckt sich mit der Durchsicht hier.
Zur PHP-Fassung: Der Quelltext enthält **kein einziges** Konstrukt, das PHP 8
voraussetzt — kein `str_contains`, kein `match`, kein `?->`. Der Fehler 500 kam
allein vom Bibliothekspfad, nicht von der PHP-Fassung. Die Angabe „setzt PHP 8
voraus" in `plugin.cfg` und Hilfe war schlicht falsch und ist raus.

## Neu in 1.1.0

### Der Kern: „0 Container" war eine Falschaussage

Nach einer frischen Installation steht `loxberry` zwar in der Gruppe `docker`,
aber der bereits laufende Webserver hat diese Gruppe noch nicht — Linux zieht
Gruppen für laufende Prozesse nicht nach. `docker ps` scheitert dann mit
*permission denied* und Rückgabewert 1. Weil der Befehl mit
`shell_exec(… 2>/dev/null)` abgesetzt wurde, kam davon **nichts** an: leerer
String, leere Liste, und an Loxone ging `OK=1;GESAMT=0`. Also: „alles in
Ordnung, es läuft nichts" — während Portainer daneben lief. Ein Baustein, der
bei `GESAMT=0` warnen soll, hätte geschwiegen.

Behoben an drei Stellen:

- **Alle Docker-Aufrufe laufen über `dk_ausfuehren()`** mit Rückgabewert und
  getrennt aufgefangener Fehlerausgabe. Kein `2>/dev/null` mehr.
- **`dk_zustand()`** unterscheidet *keine Rechte*, *Dienst läuft nicht* und
  *sonstiger Fehler* — geprüft gegen die echten Meldungstexte von Docker.
- **Der Endpunkt meldet `OK=0` und `GRUND=KEINE_RECHTE`** statt einer stillen
  Null. Die Oberfläche zeigt an derselben Stelle Klartext, an der es auffällt.

### Ein Fund, der in keiner Liste stand

`usermod -aG docker loxberry` stand **innerhalb** des Installationszweiges
`if [ ! -f /usr/bin/docker ]`. War Docker schon vorhanden — weil es jemand von
Hand installiert hatte oder eine frühere Fassung des Plugins —, wurde
`loxberry` der Gruppe **nie** hinzugefügt. Nicht „erst nach einem Neustart",
sondern nie; kein Neustart der Welt hätte daran etwas geändert. Die
Gruppenzuordnung steht jetzt außerhalb und wird bei jedem Lauf geprüft.
Nebenbei: die Prüfung auf die Datei `/usr/bin/docker` ist durch `command -v`
ersetzt — das Installationsskript legt `docker` je nach System auch unter
`/usr/local/bin` ab, und dann wäre Docker ein zweites Mal installiert worden.

### Warum der Webserver *nicht* neu gestartet wird

Ein `systemctl restart apache2` am Ende von `postroot.sh` wäre naheliegend und
wäre falsch: das Skript läuft **während** der Installation, und die
Installationsausgabe wird gerade über genau diesen Webserver angezeigt. Ein
Neustart mittendrin risse die Seite ab, und der Anwender sähe einen Abbruch
statt einer fertigen Installation. `REBOOT=true` wiederum erzwingt einen
kompletten Neustart für etwas, das auch ein Apache-Neustart erledigt.

Stattdessen: `postroot.sh` **prüft nach**, ob `loxberry` den Socket schon
erreicht, und sagt im Klartext, was zu tun ist. Und die Oberfläche erkennt den
Zustand selbst — dort, wo er auffällt, mit demselben Hinweis.

### Kleinere Korrekturen

- **`http://` war fest verdrahtet** in der Loxone-Vorlage. Wer seinen LoxBerry
  ausschließlich über HTTPS erreichbar gemacht hat, bekam eine Adresse, die es
  nicht gibt — der virtuelle Eingang blieb stumm, ohne dass man der Vorlage
  etwas ansieht. Jetzt richtet sich das Schema danach, wie die Seite gerade
  aufgerufen wurde.
- **`LBWeb::loglist_html()` entfernt.** Die Funktion listet Logdateien des
  LoxBerry-Log-SDK; dieses Plugin führt sein Protokoll als schlichte Textdatei.
  Die Liste blieb leer und stand als leeres Bedienelement über dem Text, den es
  darunter ohnehin gibt.
- `[ "$container" == "" ]` → `=` (portabel), und der doppelt verneinte Zweig ist
  zu `[ -n "$container" ]` geworden.

### Nicht zutreffend

Der gemeldete **Copy-Paste-Block aus dem Dashboard-Plugin** existiert nicht:
`grep` über das gesamte Plugin findet weder `db_lib.php` noch `db_test.php`
noch die zitierte Fehlermeldung. Die Zeilen 20–23 sind der reguläre Einbund des
LoxBerry-SDK. Ebenso ist die **Versionierung** tatsächlich stimmig — auch mein
eigener Anfangsverdacht wegen eines `ng-1.1.0` im Text hat sich nicht bestätigt:
das stand im erklärenden Kommentar zur Tag-Benennung, nicht in einer Adresse.

Die vorgeschlagene **Verschlankung von `dk_paths()`** auf reine
LoxBerry-Umgebungsvariablen ist nicht umgesetzt. Sie bringt keine Funktion und
kostet den Fall, in dem das Plugin aus dem ausgepackten Archiv heraus läuft —
den brauche ich zum Prüfen.

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
