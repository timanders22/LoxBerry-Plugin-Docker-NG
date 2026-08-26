# LoxBerry-Plugin: Docker NG

Richtet **Docker** und **Portainer** auf dem LoxBerry ein und meldet den
Containerzustand an Loxone.

> **Fassung 1.3.0 — auf einem LoxBerry mit Debian trixie gebaut, läuft ab PHP 7.4.**
> Nicht geprüft ist das Verhalten auf älteren LoxBerry-Ständen; deshalb
> `LB_MINIMUM=3.0.0`.

## Stand: vorbereitet, nicht veröffentlicht

`plugin.cfg` steht auf **1.3.0**, `release.cfg` und `prerelease.cfg` stehen
weiter auf **1.2.3**. Das ist der vorbereitete Zustand und kein Versäumnis:
die beiden Adressdateien werden erst **nach** dem Anlegen des Tags
hochgesetzt, sonst sieht jede fremde Anlage eine Fassung, die es als Tag nicht
gibt. Der Ablauf steht in `REGELN_4`; das Werkzeug dafür ist
`Werkzeuge/fassung_setzen.py … --auch-release`.

Der Tag heißt **`v1.3.0`**. Der Vorsatz `ng-` galt bis 1.2.2 und ist beendet.

### Was am Gerät noch zu messen ist

Gebaut und geprüft wurde ohne laufenden Docker. Fünf Aussagen sind deshalb
belegt, aber nicht am Gerät nachgemessen — für jede ist eine Rückfallebene
eingebaut, keine bricht bei Abweichung etwas:

1. **Erhöht ein `docker restart` von Hand den `RestartCount`?** Die Stelle im
   moby-Quelltext spricht dagegen, im Netz steht das Gegenteil. Falls doch,
   löst der Knopf *Portainer neu starten* bei drei Betätigungen innerhalb einer
   Stunde die eigene Schleifenmeldung aus. Deshalb ist die Grenze einstellbar
   und ihre Vorgabe 3, nicht 1.
2. **Kennt die installierte Docker-Fassung `{{.HealthStatus}}`?** Wenn nicht,
   greift die Textauswertung des Zustands.
3. **Kennt die installierte Portainer-Fassung `--setup-token`?** Wenn nicht,
   legt `postroot.sh` den Container ohne den Schalter erneut an und benutzt den
   alten Weg über das Containerprotokoll — und sagt das im
   Installationsprotokoll.
4. **Nimmt Loxone Config die erzeugte Importdatei an?** Sie ist wohlgeformt und
   folgt dem geprüften Nachbau aus APC-UPS, ist aber nicht importiert worden.
5. **Wie lange braucht `docker system df` auf diesem Pi?** Deshalb läuft es nur
   aus dem Minutentakt und dort höchstens alle 15 Minuten.

Der erste Handgriff nach der Installation bleibt: **einmal neu starten** (sonst
hat der Webserver die Gruppe `docker` nicht), dann im Reiter *Test* den Knopf
*Minutentakt jetzt einmal ausführen* drücken und die Zeilen darüber ansehen.

### Bewusst nicht umgesetzt

Das Protokoll bleibt eine schlichte Textdatei statt `LBLog::newLog()` mit
`LBWeb::loglist_html()`. Der Umbau brächte den Log-Manager und einen wirksamen
Loglevel-Wähler; er ist nicht gemacht, weil sich sein Nutzen hier nicht messen
ließ. `CUSTOM_LOGLEVELS` bleibt konsequenterweise ungesetzt — ein Wähler ohne
Wirkung wäre schlechter als keiner.

## Neu in 1.3.0 — vom Momentbild zur Überwachung

Bis 1.2.4 lief jede Zeile Code dieses Plugins nur dann, wenn ein Mensch die
Seite öffnete oder der Miniserver den Endpunkt abrief. Damit war alles, was
**zwei Momentaufnahmen** braucht, grundsätzlich unerreichbar: ein Herzschlag,
eine Neustartschleife, eine Veränderung über die Zeit. 1.3.0 ergänzt den
fehlenden Teil.

> **Alle neuen Funktionen sind ab Werk aus.** Wer aktualisiert, bekommt kein
> Verhalten, um das er nicht gebeten hat. Einzige Ausnahme sind die neuen
> Felder in der Statuszeile — sie kommen dazu, die bisherigen bleiben
> unverändert, und bestehende Loxone-Programme tragen weiter.

### Der Minutentakt

`cron/cron.01min` ruft `bin/dockerng_takt.php`. Das ist die einzige Stelle des
Plugins, die schreibt; Oberfläche und Endpunkt lesen nur. Der Endpunkt wird vom
Miniserver im Sechzigsekundentakt abgerufen — würde er selbst schreiben, wäre
das ein Schreibvorgang je Minute auf der Speicherkarte.

**Die Begründung, warum es das bis dahin nicht gab, war falsch.** Im Quelltext
stand: *„Docker NG führt keinen Dienst, der zyklisch veröffentlichen könnte."*
Die Aussage stimmte — der Weg war ungebaut —, die Begründung nicht:
`plugininstall.pl` verteilt Cron-Skripte aus dem Ordner `cron/` unter anderem
nach `cron.01min`. Ein eigener Dienst ist dafür nicht nötig. Der Satz ist
ersetzt.

### Der Herzschlag — die wichtigste Ergänzung

Schritt 5 der Loxone-Anleitung empfahl seit jeher, *„zusätzlich zu melden, wenn
der Wert eine Weile nicht mehr wechselt"*. Nur gab es keinen Wert, der sich
zuverlässig ändert: bei stabilem Betrieb war die Statuszeile Abruf für Abruf
identisch. **Die eigene Empfehlung war mit den vorhandenen Feldern nicht
umsetzbar.**

`DOCKERNG_ZAEHLER` zählt bei jedem Durchlauf des Minutentakts eine Stelle
weiter (0…999, umlaufend). Eine Änderungsüberwachung darauf meldet den Ausfall
auch dann, wenn alle übrigen Werte gut aussehen — denn bei einem Ausfall behält
der virtuelle Eingang seinen letzten Wert, und in der App sieht dann alles
normal aus. Läuft der Takt nicht, steht `ZAEHLER` auf `-1`; das ist eine
Aussage, kein Platzhalter. Die Baustein-Liste in Schritt 4 hat dafür zwei neue
Zeilen (#8 und #9).

### Wachliste statt Fundliste

Bis 1.2.4 entstand die Stelle `C_<name>` je **gefundenem** Container. Wurde
einer gelöscht oder umbenannt, verschwand seine Stelle ersatzlos — die
Befehlserkennung fand ihr Muster nicht mehr und der Eingang behielt seinen
letzten Wert, also `1`. Loxone meldete auf Dauer „läuft" für einen Container,
den es nicht mehr gibt.

Jetzt wird über den **Soll**-Bestand gezählt: `-1` = nicht vorhanden, `0` = da
und läuft nicht, `1` = läuft, dazu `FEHLT` als Sammelwert. Die Auswahl steht in
der Containertabelle; ohne Auswahl gilt weiterhin „alle", also das Verhalten
bis 1.2.4. Nebengewinn: die Importdatei richtet sich nicht mehr nach dem
Augenblicksbestand und bleibt damit stabil — Loxone Config legt beim Import neu
an und überschreibt nichts, zweimal importiert hieß bisher doppelte Objekte.

### Gesundheit, Autostart, Neustartschleifen

* **`UNGESUND` und `H_<name>`.** Ein Container, der läuft, aber seinen eigenen
  Healthcheck nicht besteht, war bisher von einem gesunden nicht zu
  unterscheiden. Genau das ist der Fall, den die Anleitung als Zweck des
  Plugins nennt: *„das Gateway, das die Auto- oder Wetterdaten liefert."*
  `{{.HealthStatus}}` liefert es ohne zweiten Aufruf.
* **`SCHLEIFE`.** Ein Container, der im Sekundentakt abstürzt und neu startet,
  flackerte bisher zwischen 0 und 1 — und die in der Anleitung empfohlene
  Einschaltverzögerung von 300 s verschluckte genau diesen Fall. Gezählt wird
  jetzt der Zuwachs von `RestartCount` in einem gleitenden Fenster von einer
  Stunde, ergänzt um „läuft seit unter einer Minute bei RestartCount > 0".
  *Nicht nachgemessen:* ob ein Neustart von Hand diesen Zähler mit erhöht.
  Deshalb ist die Grenze einstellbar und die Vorgabe 3, nicht 1.
* **Autostart.** Ein Container mit `RestartPolicy: no` kommt nach einem
  Stromausfall nicht von selbst wieder — der klassische Stolperstein. Die
  Containertabelle sagt es jetzt.

Alle drei kommen aus **einem** zusätzlichen `docker inspect` für alle Container,
nicht aus einem je Container.

### Plattenplatz und Log-Rotation

`PLATZFREI` meldet den freien Platz in MB. Der json-file-Treiber von Docker hat
als Vorgabe `max-size: -1`, also **unbegrenzt** — ein einziger gesprächiger
Container in einer Reconnect-Schleife schreibt die Speicherkarte voll, und ein
volles Dateisystem macht den LoxBerry unbootbar. Das ist der einzige Punkt an
diesem Plugin, der das kann.

`postroot.sh` richtet deshalb bei der Installation `max-size: 10m` und
`max-file: 3` ein — **zurückhaltend**: nur wenn noch gar keine `log-opts`
gesetzt sind, durch Zusammenführen statt Ersetzen einer vorhandenen
`daemon.json`, ohne Docker neu zu starten, und mit dem ausdrücklichen Hinweis,
dass die Einstellung nur für künftig erzeugte Container gilt. Die Deinstallation
nimmt sie bewusst **nicht** zurück: sie schützt das Dateisystem und gehört
inzwischen zum System.

### MQTT

Ein eigener Reiter mit eigenem Formular und eigenem Speicher-Handler — ein
Sammelhandler würde beim Absenden des einen Formulars die Haken des anderen
stillschweigend nullen. Der Weg ist der im Haus übliche: eine UDP-Zeile an den
UDP-Eingang des MQTT-Gateways, das seit LoxBerry 3 Systembestandteil ist. Kein
`phpMQTT`, kein `socket_create()` — Letzteres steckt in einer Erweiterung, die
nicht garantiert geladen ist, und ihr Fehlen ist kein abfangbarer Fehler,
sondern ein fataler; im Cron sieht den niemand.

Die Themenliste im Reiter ist keine Beschreibung, sondern der tatsächliche
Sendestand: sie wird aus demselben Aufruf erzeugt, den der Minutentakt zum
Senden benutzt. **Gehört wird auf nichts.** Ein Kommandothema wäre der
Schaltweg, den dieses Plugin nicht anbietet, und über einen Broker wäre er
schlechter geschützt als der Endpunkt.

### Benachrichtigungen und Healthcheck

Wenn der Webserver den Docker-Socket nicht erreicht — laut README der Regelfall
nach jeder frischen Installation —, stand der Klartext dazu bisher
ausschließlich auf der Plugin-Seite. Wer sie nicht öffnet, sah nichts. Jetzt:

* `notify_ext()` erzeugt den roten Punkt am Plugin-Symbol. Gemeldet wird nur bei
  **Wechsel** des Befundes — eine Meldung je Minute wäre Rauschen.
* `bin/healthcheck` klinkt sich in den LoxBerry-Healthcheck ein. Nebeneffekt
  ohne Zusatzarbeit: `healthcheck.pl` veröffentlicht das Ergebnis zusätzlich
  retained nach MQTT.

Beide benutzen denselben `dk_befund()` wie die Oberfläche. Drei Stellen, die
dasselbe anders sagen, wären zwei zu viel.

### Schutz gegen fremde Formulare

**Der schwerste Befund dieser Runde, und er war seit 1.0.0 offen.** `htmlauth/`
schützt gegen den unangemeldeten Aufruf — **nicht** dagegen, dass der Browser
eines angemeldeten Bedieners ein Formular abschickt, das auf einer fremden
Seite steht. Die HTTP-Basic-Anmeldung schickt er dabei automatisch mit;
SameSite greift nicht.

Damit konnte bis 1.2.4 eine beliebige fremde Seite `speichern=1&token_neu=1`
absetzen. Danach bekamen sämtliche virtuellen Eingänge im Miniserver HTTP 403,
die Überwachung war tot — ohne jede Rückmeldung. Über `log_leeren=1` ließ sich
gleich die Spur wegräumen. Der Angreifer sieht die Antwort nicht; er braucht
sie auch nicht.

Jetzt: ein Merkmal, aus dem Aktionstoken **abgeleitet** statt gespeichert
(`hash_hmac`), in **jedem** der elf Formulare — und **eine** zentrale Prüfung
vor allen Handlern. Einen einzelnen Handler kann man beim Erweitern vergessen,
einen Wachposten am Eingang nicht. Fällt sie durch, wird `$_POST` bis auf den
aktiven Reiter geleert **und gemeldet**: ein Formular, das wortlos nichts tut,
schickt den Anwender auf die Suche nach einem Fehler, den es nicht gibt.

Gemessen (`Pruefung-DockerNG-1.3.0/csrf_probe.py`), an der Wirkung — steht
hinterher ein anderes Merkwort in der Konfiguration?

| Fall | 1.2.3 | 1.3.0 |
|---|---|---|
| POST ohne Merkmal | **wirkt** | abgewiesen |
| POST mit falschem Merkmal | **wirkt** | abgewiesen |
| POST mit richtigem Merkmal | wirkt | wirkt |

Die dritte Zeile ist die Gegenrichtung der Eichung: ohne sie bestünde auch ein
Plugin, das überhaupt nichts mehr tut.

### Selbstprüfung am Endpunkt

`?selftest=1&token=…` — vom Hausstandard an jedem tokengeschützten Endpunkt
verlangt und bis 1.2.4 nicht vorhanden. Sie beantwortet „ist die Adresse samt
Merkwort richtig?", ohne irgendetwas anzufassen: **kein Gerätekontakt, kein
Schreibzugriff**, deshalb steht sie vor jedem `docker`-Aufruf.

    SELFTEST;OK=1;TOKEN=OK
    403  SELFTEST;OK=0;ERR=TOKEN
    403  SELFTEST;OK=0;ERR=KEIN_TOKEN_EINGERICHTET

Damit kann der Reiter *Test* den eigenen Endpunkt **wirklich abrufen**, statt
nur einen Link anzubieten, den der Anwender selbst anklicken muss — das war
keine Prüfung, sondern eine Einladung zu einer. Drei Ausgänge, auf 300 Sekunden
gebremst, mit Knopf zum sofortigen Nachmessen.

Nebenbei: der Parameter wird jetzt zuerst auf seinen **Typ** geprüft.
`?token[]=x` macht ein Feld daraus; unter PHP 8 stünde sonst eine Warnung
mitten in der Antwort an den Miniserver.

### Kleineres

* **Setup-Token wird vorgegeben** statt aus dem Containerprotokoll gefischt.
  `postroot.sh` legt ihn mit `--setup-token` fest; damit entfällt die Bindung an
  ein Ausgabeformat, das Portainer schon zweimal geändert hat, und der Wert
  überlebt jeden Neustart des Containers. Kennt die installierte Fassung den
  Schalter nicht, wird ohne ihn erneut versucht und der alte Weg benutzt — und
  das steht so im Installationsprotokoll.
* **Protokoll jedes Containers** in der Oberfläche, nicht nur das von Portainer.
* **Abbild-Aktualisierungen** über einen Prüfsummenvergleich (HEAD, zählt nicht
  gegen das Abrufkontingent von Docker Hub). Drei Ausgänge: verfügbar, aktuell,
  **nicht messbar** — Letzteres löst nie eine Meldung aus. Ab Werk aus.
* **Vier neue Zeilen im Reiter Test**: läuft der Minutentakt (Alter der
  Zustandsdatei, nicht Prozessnummer), Stand des Herzschlags, ist der MQTT-Weg
  vollständig, sind die Container-Protokolle begrenzt. Dazu ein Knopf, der den
  Minutentakt einmal von Hand auslöst — die Hausregel verlangt, jeden
  Cron-Dienst nach der Installation einmal von Hand zu starten.
* **Die Kongruenzprobe war beim Bau dieser Fassung selbst blind geworden**: ihr
  Suchmuster nahm nur das erste Teilstück der verketteten Formatzeichenkette und
  blieb grün, während sechs neue Felder außerhalb ihres Blickfelds lagen.
  Repariert und in beide Richtungen geeicht.
* **`postinstall.sh`** startet den Minutentakt nach der Installation einmal von
  sich aus und sagt, was dabei herauskam.
* **`postupgrade.sh` ergänzt** — es fehlte als einziges der üblichen Skripte.
  Es prüft den Fall, den eine Neuinstallation nie durchläuft: eine vorhandene
  Konfiguration ohne die neuen Schlüssel. Dazu zählt es nach, ob
  `cron/cron.01min` wirklich im System gelandet ist und ob der Platzhalter
  `REPLACELBPBINDIR` ersetzt wurde — bleibt das aus, steht der Herzschlag still,
  und das fällt sonst monatelang nicht auf.
* **Protokollanzeige rückwärts mit `fseek`** statt `file()`. Der Hausstandard
  verbietet für diese Stelle ausdrücklich beides: die ganze Datei einlesen und
  `exec("tail")`. Gemessen an 12.000 Zeilen: 0,05 ms und 0 kB zusätzlich
  gegenüber 0,37 ms und 2 MB.
* **Selbstwiderspruch in `release.cfg`/`prerelease.cfg` aufgelöst.** Der Kopf
  sagte „ab 1.2.3 gilt das kleine v", der Fuß derselben Datei im Präsens „die
  Reihe benutzt deshalb den Präfix `ng-`" — in einer Datei, deren erklärter
  Zweck es ist, das Ausliefern des falschen Archivs zu verhindern. Der Fußteil
  steht jetzt in der Vergangenheit; die alten `ng-`-Tags bleiben erklärt.
* **Der Abschnitt zur Hilfe war in beide Richtungen falsch**: er nannte eine
  Datei `templates/help/help_en.html`, die es nicht gibt, und verneinte den
  `help_de.ini`/`help_en.ini`-Weg, den der Code seit 1.2.x geht. Richtiggestellt
  mit dem Nachweis aus `libs/phplib/loxberry_web.php`.
* **Vier weitere Prüfstände** unter `Pruefung-DockerNG-1.3.0/`, alle geeicht:
  Merkwortverlust, CSRF-Wachposten, Selbstprüfung am Endpunkt, Minutentakt.

## Neu in 1.2.4 — sechs Befunde aus einer zeilenweisen Durchsicht

Alle sechs am Quelltext nachgeprüft. Die Reihenfolge ist die nach Gewicht,
nicht die nach Aufwand.

### Die Selbstheilung zerstörte im Fehlerfall ihre eigene Rettungskopie

Der schwerste Fund, und ausgerechnet an der Vorkehrung aus 1.2.0. Geprüft
wurde in `dk_config()` auf **„leer oder `{}`"**. Eine halb geschriebene oder
beschädigte `dockerng.json` — auf einer Speicherkarte nach einem Stromausfall
kein Ausnahmefall — ist weder das eine noch das andere. Also: keine
Wiederherstellung, `dk_json_lesen()` gab bei ungültigem JSON stumm ein leeres
Feld zurück, das Merkwort war `''`, `dk_token()` würfelte ein neues — und
`dk_config_schreiben()` kopierte es **über die Sicherung**. Damit war das alte
Merkwort in **beiden** Kopien fort, ausgelöst durch das bloße Öffnen der
Oberfläche, protokolliert nur als „Konfiguration gespeichert". Sämtliche
Adressen im Miniserver waren tot.

Behoben an vier Stellen:

- Es entscheidet nicht mehr die **Form** des Textes, sondern ob ein Merkwort
  darin steht (`dk_konfig_taugt()`).
- Eine beschädigte Datei wird als `dockerng.json.kaputt` **beiseitegelegt**,
  nicht verworfen — und die Tatsache steht im Protokoll.
- Die Sicherung wird nur noch **mit** einem Merkwort darin mitgezogen. Sie ist
  die Rückfallebene; sie darf nie schlechter werden als das, was sie sichert.
- `preupgrade.sh` und `postinstall.sh` prüfen dasselbe. Bis 1.2.3 genügte
  `[ -s "$CF" ]`, also „nicht leer" — eine kaputte Datei überschrieb damit die
  zuvor gute Sicherung.

Dazu schreibt `dk_json_schreiben()` jetzt **unteilbar**: Nebendatei mit der
Prozessnummer im Namen, Rechte **vor** dem Inhalt, dann `rename()`. Bis 1.2.3
stand die Datei mit dem Merkwort für die Dauer des Schreibens mit den Vorgaben
der umask da — und ein Abbruch mittendrin erzeugte genau die Trümmerdatei, die
oben die Selbstheilung aushebelte.

### `postroot.sh` prüfte die Socket-Rechte mit dem falschen Werkzeug

```bash
su loxberry -s /bin/sh -c "docker ps >/dev/null 2>&1"
```

`su` legt eine **neue** Sitzung an und liest die Gruppen frisch aus
`/etc/group` — die soeben per `usermod` gesetzte Gruppe `docker` ist dort
sofort wirksam. Der Test lief also nach **jeder** Neuinstallation durch, das
Skript meldete „erreicht den Docker-Socket bereits" und übersprang den
`else`-Zweig mit der einzigen Anweisung, auf die es ankommt: einmal neu
starten. Der Anwender startete nicht neu und las in der Oberfläche das
Gegenteil.

Gefragt wird jetzt der **laufende Webserver** selbst — `Groups:` aus
`/proc/<pid>/status` gegen die GID von `docker`. Das ist genau die Frage, um
die es geht. Findet sich kein Prozess, sagt das Skript das und gibt den Hinweis
trotzdem: ein Strich statt eines Befunds wäre das Schlechteste.

### Port und Containername waren Bedienelemente ohne Wirkung

`postroot.sh` verdrahtet `-p=9000:9000` fest. Das Feld *Port der
Portainer-Oberfläche* änderte ausschließlich das Ziel des Öffnen-Knopfs. Wer
wegen einer Portbelegung 9001 eintrug, speicherte und klickte, bekam
„Verbindung abgelehnt" — der Container lauschte weiter auf 9000. Das ist
dasselbe, was `plugin.cfg` an anderer Stelle (`CUSTOM_LOGLEVELS`) ausdrücklich
als schlimmer als gar kein Bedienelement bezeichnet.

Jetzt wird der **wirkliche** Port am Container abgelesen (`docker port`), das
Schema aus dem Container-Port abgeleitet (9000 → HTTP, 9443 → HTTPS), und die
Oberfläche sagt unter dem Knopf, welcher der beiden Werte gerade gilt. Der
eingestellte Wert bleibt die Rückfallebene, und der Hilfetext sagt jetzt
ausdrücklich, dass dieses Feld den Container **nicht** neu einrichtet.

### `GESTOPPT` löste die eigene Bauanleitung dauerhaft aus

`docker ps -a` listet jeden je erzeugten und nicht entfernten Container — auch
den Sicherungscontainer, der nachts läuft und sauber mit Code 0 endet. Auf
einem gewachsenen LoxBerry ist `GESTOPPT` praktisch nie 0. Wer Schritt 4
wörtlich nachbaute (`DOCKERNG_GESTOPPT` → Schwellwertschalter „Ein ab 1" →
ODER → Benachrichtigung), bekam eine Dauerstörung — und weil der
Benachrichtigungs-Baustein nur beim Wechsel von Aus auf Ein sendet,
**verschluckte sie anschließend alle anderen Meldungen an demselben ODER**.
Die Anleitung warnt vor genau diesem Mechanismus und lief mit ihrem eigenen
ersten Baustein hinein.

Neu ist `AUSFALL`: gezählt wird, was weder läuft noch planmäßig beendet ist —
`Exited (0)` und `Created` bleiben außen vor. `GESTOPPT` bleibt unverändert
erhalten, damit bestehende Loxone-Programme weiter tragen; die Anleitung nennt
ab jetzt `AUSFALL`.

### Pausierte Container galten als „läuft"

`stripos($status, 'Up') === 0`. Docker gibt einen pausierten Container als
`Up 4 minutes (Paused)` aus — der Test schlug an. Ein per SIGSTOP eingefrorener
Container meldete nach Loxone „läuft". Wer in Portainer das MQTT-Gateway
pausiert, bekam von der Überwachung, die genau dafür gebaut wurde, kein Wort.

Maßgeblich ist jetzt `{{.State}}` — `created, running, paused, restarting,
exited, removing, dead`. Für sehr alte Docker-Fassungen bleibt die
Textauswertung als Rückfallebene, diesmal aber **mit** dem Ausschluss von
`(Paused)`. Neu ist zusätzlich `PAUSIERT`, und die Containertabelle zeigt den
Stand jetzt in Klartext statt nur den englischen Rohtext.

### Die XML-Vorlage war gegen einen veralteten Stand der Referenz gebaut

Der Kommentar nennt `ap_xml_virtual_in_http()` aus dem APC-UPS-Plugin als
Vorlage. Die Referenz hat seit ihrer 1.2.0 `HintText` am Wurzelelement,
`<Info templateType="2" minVersion="17010727"/>` als erstes Kindelement sowie
`Unit` und `HintText` je Eintrag. Docker NG hatte nichts davon — nachgezählt im
Arbeitsordner: **36 Plugin-Ordner setzen das Info-Element, Docker NG war nicht
darunter.**

Dazu ein zweiter Punkt: die Container-Einträge trugen `Analog="false"`
zusammen mit dem **Analog**-Platzhalter `\v` und der vollständigen
Analog-Skalierung. Im gesamten Arbeitsordner war das die **einzige** Fundstelle
von `Analog="false"` an einem `VirtualInHttpCmd`; alle 15 übrigen sitzen an
`VirtualOutCmd`, wo sie hingehören.

### Kleineres, in derselben Durchsicht

- `dk_token()` verwarf den Rückgabewert von `dk_config_schreiben()`. Ließ sich
  die Konfiguration nicht schreiben, zeigte der Reiter *Test* dauerhaft
  „Merkwort gesetzt, 24 Zeichen" — fest auf grün verdrahtet —, während auf
  Platte keines stand und bei jedem Seitenaufruf ein anderes angezeigt wurde.
- Der Zwischenspeicher der Konfiguration wird nach dem Speichern nachgezogen.
  Bis 1.2.3 fragte die Portainer-Kachel nach einer Namensänderung noch den
  alten Namen ab und stand rot, während das Feld darüber schon den neuen zeigte.
- `dk_portainer_neustart()` merkt sich den Setup-Token **vor** dem Neustart und
  wartet, bis ein **anderer** auftaucht. Bis 1.2.3 wurde drei Sekunden
  gewartet und dann der letzte Treffer aus `--tail 400` genommen — auf einem
  Raspberry Pi regelmäßig der alte, längst abgelaufene. Außerdem sind
  „Neustart fehlgeschlagen" und „kein Token gefunden" jetzt zwei verschiedene
  Auskünfte; Letzteres ist bei eingerichtetem Portainer der Regelfall und
  keine Fehlermeldung mehr.
- `dk_container()` und `dk_version()` haben einen Zwischenspeicher. Bis 1.2.3
  lief `docker ps -a` je Seitenaufbau zweimal — das kostete nicht nur einen
  Prozessstart, sondern lieferte zwei verschiedene Momentaufnahmen: wurde
  Portainer dazwischen angehalten, zeigte die Tabelle „Up" und die Kachel
  daneben „gestoppt".
- Der Portainer-Block in `postroot.sh` lief auch **ohne** Docker durch (vier
  rohe „command not found"-Zeilen, kein `<FAIL>`, `exit 0`), löschte einen
  bewusst angehaltenen Container per `docker rm --force` und legte ihn mit den
  Vorgaben des Plugins neu an, und `--filter name=portainer` traf als
  Teilstring auch `my-portainer`. Jetzt: Vorbedingungen geprüft, ein
  vorhandener Container wird **gestartet, nicht ersetzt**, und der Filter ist
  mit `^portainer$` genau.
- `curl` und `sh get-docker.sh` werden auf Erfolg geprüft. Ohne
  Internetverbindung meldete die Installation bis 1.2.3 Erfolg und das Plugin
  danach „Docker wurde nicht gefunden".
- `uninstall/uninstall` empfahl `docker volume rm portainer_data`. `postroot.sh`
  hängt das Datenverzeichnis aber als **Hostpfad** ein (`/opt/portainer`) —
  der Befehl endete mit „no such volume", der Anwender glaubte aufgeräumt zu
  haben, und die Portainer-Konten samt Kennwort-Hashes lagen weiter auf der
  Karte. Der richtige Ort stand nirgends.
- Zwei Container, deren Namen nach der Säuberung auf denselben Loxone-Schlüssel
  fallen (`mein-dienst` und `mein.dienst` → `C_mein_dienst`), werden jetzt
  **gemeldet**. Bis 1.2.3 geschah das lautlos: Loxone nahm das erste Vorkommen,
  der zweite Container blieb unbeobachtet — und in der Tabelle standen zwei
  Zeilen, die wie zwei getrennte Eingänge aussahen.
- Der Reiter *Test* hat vier neue Zeilen: ist die Konfiguration heil (drei
  Ausgänge, nicht zwei), gibt es eine brauchbare Zweitschrift, **nennt die
  Anleitung alle Felder, die der Endpunkt sendet** (am Quelltext des Endpunkts
  gemessen), und ist die erzeugbare Importdatei wohlgeformt.
- Die Sammelfelder stehen jetzt an **einer** Stelle (`dk_lox_felder()`).
  Vorher standen sie dreimal — im XML-Erzeuger, in der Tabelle der
  Befehlserkennungen und als von Hand getippte Beispielzeile —, und die drei
  waren auseinandergelaufen: `PORTAINER` wurde gesendet und stand nirgends,
  `GRUND` fehlte in der Beispielzeile.
- `.sm-breit` aus dem Hausstandard ergänzt; es war die einzige fehlende Klasse.
- Angezeigte Adresse und erzeugte Importdatei benutzen jetzt dasselbe Schema
  und denselben Port. Bis 1.2.3 stand in der Anzeige fest `http://` ohne Port,
  während die Vorlage daneben das Schema aus `$_SERVER` ableitete und den Port
  mitnahm — auf einem LoxBerry mit HTTPS konnten die beiden nicht gleichzeitig
  stimmen.

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

Beides zutreffend.

> **Nachtrag.** Dieser Absatz beschrieb bis 1.2.4 einen Zwischenstand als
> Endstand und war damit in beide Richtungen falsch: Er nannte eine Datei
> `templates/help/help_en.html`, die es im Plugin **nicht gibt**, und er
> verneinte den Weg über `help_de.ini`/`help_en.ini`, den der Code seit 1.2.x
> tatsächlich geht. Nachgeholt wurde die offene Prüfung am 10.08.2026 an
> `libs/phplib/loxberry_web.php`: `LBWeb::gethelp()` nimmt die genannte Datei
> aus `templates/help/`, leitet daraus `<name>.ini` ab und lässt
> `readlanguage()` die Sprachdateien in `templates/lang/` suchen — also
> `help_de.ini` und `help_en.ini`. Die Sprache wählt damit LoxBerry selbst;
> eine zweite Hilfedatei ist überflüssig. So steht es auch im Quelltext der
> Oberfläche.

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
| `aktion=status` | `DOCKERNG;OK=1;GESAMT=3;LAEUFT=3;GESTOPPT=0;AUSFALL=0;PAUSIERT=0;UNGESUND=0;FEHLT=0;SCHLEIFE=0;PORTAINER=1;ZAEHLER=42;PLATZFREI=4096;GRUND=-;C_portainer=1;H_portainer=2` |
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

## MQTT

Seit 1.3.0 vorhanden, mit eigenem Reiter — und **ab Werk aus**. Veröffentlicht
wird im Minutentakt über den UDP-Eingang des MQTT-Gateways, das seit LoxBerry 3
Systembestandteil ist. MQTT tritt **nicht** an die Stelle des HTTP-Endpunkts,
es steht daneben.

Bis 1.2.4 stand an dieser Stelle die Begründung, Docker NG führe „keinen
Dienst, der zyklisch veröffentlichen könnte". Die Aussage stimmte, die
Begründung nicht — siehe *Neu in 1.3.0*.

**Ohne das Abo geschieht nichts:** im MQTT-Gateway muss der Vorsatz mit `/#`
abonniert sein. Der fehlende Eintrag ist der häufigste Grund, warum am
Miniserver nichts ankommt, und es gibt keine Fehlermeldung, die darauf hinweist.

## Aufbau

    webfrontend/htmlauth/index.php   Bedienoberflaeche, fuenf Reiter
    webfrontend/html/dk_lib.php      Pfade, Konfiguration, Sprache, Docker,
                                     Zustand, MQTT, Loxone-Vorlage
    webfrontend/html/index.php       Endpunkt fuer den Miniserver
    bin/dockerng_takt.php            Minutentakt: Herzschlag, Schleifen, Platz, MQTT
    bin/healthcheck                  Anschluss an den LoxBerry-Healthcheck
    cron/cron.01min                  ruft den Minutentakt auf
    templates/lang/language_de.ini   Sprachdatei Deutsch
    templates/lang/language_en.ini   Sprachdatei Englisch
    templates/lang/help_de.ini       Hilfetexte Deutsch
    templates/lang/help_en.ini       Hilfetexte Englisch
    templates/help/help.html         Geruest fuer die Hilfe hinter dem Fragezeichen
    preupgrade.sh                    Konfiguration vor der Neuinstallation sichern
    postinstall.sh                   Ordner anlegen, Sicherung zurueckspielen
    postupgrade.sh                   nur beim Update: Cron und neue Schluessel pruefen
    postroot.sh                      Docker, Log-Rotation und Portainer einrichten
    uninstall/uninstall              Portainer entfernen, Geheimnisse wegraeumen
    dpkg/apt                         Paketliste
    plugin.cfg                       Fassung, Untergrenzen, Auto-Update
    release.cfg / prerelease.cfg     Adressen fuer das Auto-Update

Die Bibliothek liegt unter `html/` und nicht unter `htmlauth/`, weil der
Loxone-Endpunkt sie ebenfalls braucht. Eine zweite Kopie wäre die häufigste
Ursache dafür, dass zwei Dateien gleichen Namens auseinanderlaufen. Aus
demselben Grund laden auch `bin/dockerng_takt.php` und `bin/healthcheck` genau
diese eine Datei — über eine Kandidatenliste, weil der Weg von `bin/` dorthin
im entpackten Archiv ein anderer ist als im installierten Zustand.

Was in `data/plugins/<ordner>/zustand.json` steht, ist **neu erzeugbar**:
Herzschlag, Neustartzähler, Plattenbelegung. Deshalb liegt dort auch keine
Zweitschrift daneben — anders als bei der Konfiguration, in der das Merkwort
für den Endpunkt steht.

## Lizenz

**Apache License 2.0** — siehe [LICENSE.md](LICENSE.md).

Dieses Plugin ist ein abgeleitetes Werk des Docker-Plugins von Michael Miklis
und steht deshalb weiterhin unter der Apache-Lizenz 2.0. Die Urheberangabe des
Originals bleibt unverändert; die vorgenommenen Änderungen sind in
[NOTICE](NOTICE) aufgeführt, wie es Abschnitt 4 der Lizenz verlangt.

Weder mit Docker Inc. noch mit Portainer.io verbunden.
