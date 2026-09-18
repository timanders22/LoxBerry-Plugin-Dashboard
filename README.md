# LoxBerry-Plugin: Dashboard-Designer

Liest die Struktur des **Loxone Miniservers** aus und baut daraus per
Drag-and-Drop moderne Kachel-Dashboards, die sich auf jedem Tablet ohne
Loxone-App aufrufen lassen.

> **Fassung 0.9.24 — Anmeldung und Befehle sind am Gerät gemessen.** Am
> 07.09.2026 an einem Miniserver mit Firmware 17.2.8.28 nachgemessen:
> Anmeldung (Hashverfahren des Benutzers SHA1), Wiederanmeldung mit
> gespeichertem Token, die Strukturdatei (666 Bausteine, 3610 Zustände), der
> HTTP-Rückfall `jdev/sps/io/<uuid>/state` und die **Wirkung der
> Kachel-Befehle** (an einer Steckdose über den Endpunkt geschaltet, nach 1 s
> im Abbild, Ausgangszustand wiederhergestellt; vier Gegenproben abgewiesen). Alles Übrige ist gegen
> eine Attrappe gemessen, die streng nach den Loxone-Dokumenten gebaut ist.
> Deshalb 0.9.13 und nicht 1.0.0. Was ungeprüft bleibt, steht unten unter
> *Was ungeprüft bleibt* — vollständig und ohne Beschönigung.
>
> **Wer 0.9.12 oder älter installiert hat, sollte aktualisieren.** Die
> Farbkachel konnte bis dahin **überhaupt nicht schalten**: der letzte von
> vier Zeichenvorräten kannte weder Klammer noch Komma, und `hsv(240,100,80)`,
> `temp(80,4000)` und `lumitech(80,4000)` starben unmittelbar vor dem
> Absenden mit „Das ist kein gueltiger Befehl." — einer Meldung, die nach
> einem Fehler des Anwenders klingt. Dazu löste eine Szene hinter einer
> unsichtbaren Kachel die **falsche** Szene aus. Beides steht auf der
> Release-Seite zu `v0.9.13`.

## Neu in 0.9.24

**Die Suche nach der LoxBerry-Wurzel verlangt jetzt `config/system/general.json`.**
Wird ein Skript des Plugins ohne `LBHOMEDIR` und ohne die Argumente des
Installers aufgerufen, sucht es die Wurzel vom eigenen Ablageort aufwärts.
Bis 0.9.23 galt dabei jedes Verzeichnis mit `config/plugins` und
`data/plugins` (Dienst und Oberfläche: `config/plugins` und `webfrontend`) als
Wurzel. Solche Ordner liegen auf einem Prüfrechner auch außerhalb eines
LoxBerry, etwa als Reste früherer Prüfläufe. In WSL Ubuntu nachgestellt
(18.09.2026, `Pruefung-Dashboard-0.9.24`, Fälle F1 bis F8) — in einem solchen
Baum ohne `general.json`:

* `bin/dienst.sh start` legte `data/plugins/dashboard` und `log/plugins/dashboard` an, `stop` löschte `soll_laufen`;
* `preupgrade.sh` legte die Marke und die Zweitschriften an und meldete Erfolg;
* `postinstall.sh` legte die Plugin-Ordner an;
* `uninstall/uninstall` und `uninstall.sh` löschten Zweitschrift und `.upgrade_sicherung`;
* der Dienst und die Oberfläche hielten den Baum für die Wurzel und hätten dort gelesen und geschrieben.

Alle sieben Suchen prüfen jetzt zusätzlich `config/system/general.json` — ein
LoxBerry hat sie immer. In der installierten Lage (mit `general.json`) finden
alle sieben die Wurzel weiterhin, auch ohne `LBHOMEDIR` und aus `/` aufgerufen
(Fälle G1 bis G7). Ein gesetztes `LBHOMEDIR` und die Argumente des Installers
gelten unverändert (Fälle U1 bis U4). Am Gerät ändert sich damit nichts.

## Neu in 0.9.23

**Ein Seitenaufruf während der Aktualisierung kostete Aktionstoken und
Einstellungen.** Zwischen `preupgrade.sh` und `postinstall.sh` räumt der
Installer `config/plugins/dashboard/` ab; die neuen Dateien liegen dann schon
bereit (am Gerät knapp eine Minute gemessen). Wer in dieser Zeit die
Einstellungsseite öffnete, bekam ein neues Aktionstoken angelegt und in eine
frische `dashboard.json` gespeichert. `postinstall.sh` sah darin Inhalt, spielte
die Sicherung nicht zurück und räumte sie weg: das alte Aktionstoken und alle
Einstellungen waren fort, und jede Adresse, die der Miniserver mit dem alten
Token aufruft, wurde abgewiesen. Dasselbe beim Speichern der Einstellungen in
dieser Zeit. In WSL Ubuntu nachgestellt (18.09.2026, `Pruefung-Dashboard-0.9.23`,
Fälle L2 und L5).

**Der Knopf „Dienst starten" startete während der Aktualisierung einen Dienst,
der vorher bewusst angehalten war** — und der Minutentakt hielt ihn danach am
Leben (Fall L3).

Seit dieser Fassung legt `preupgrade.sh` als Erstes die Marke
`data/plugins/dashboard.upgrade_laeuft` an (neben dem Datenordner, darin
löschte der Installer sie mit). Solange sie höchstens eine Stunde alt ist,
startet `bin/dienst.sh` den Dienst nicht — auch ohne lesbare Uhr nicht —, und
die Einstellungsseite zeigt nur einen Hinweis, liest und schreibt nichts.
`postinstall.sh` startet einen vorher laufenden Dienst wieder und entfernt die
Marke erst **danach**: fällt sie vorher, startete der Minutentakt in 27 von 30
Durchläufen einen zweiten Dienst neben dem eigenen (gemessen mit einem auf
0,3 s verbreiterten Startfenster; bei normaler Geschwindigkeit 0 von 60 in
beiden Reihenfolgen). Eine Marke, die älter als eine Stunde, aus der Zukunft
oder unlesbar ist, gilt nicht — eine abgebrochene Installation legt nichts auf
Dauer still; der Reiter Test nennt sie dann mit Pfad. Beide Deinstallationsskripte
räumen sie weg. Die Anzeigeseiten für das Tablet sperren nicht: sie schreiben
keine Einstellungen (gemessen, Fall L6).

**Kein zweiter Dienst nach dem Update** (in derselben Messung nachgesehen): ein
Dienst ohne PID-Datei wird schon von `preupgrade.sh` beendet, auch ohne altes
`dienst.sh`, und einen Dienst, der das Update trotzdem überlebt, findet der
Wiederanlauf in `postinstall.sh` über `/proc` und startet keinen zweiten
daneben (Fälle G2a bis G2c).

## Neu in 0.9.22

**Eine abgeschnittene Konfiguration wurde nicht zurückgespielt — und die
Sicherung danach gelöscht.** `postinstall.sh` entschied bis 0.9.21 nach der
Größe (`[ ! -s ]`, dazu die Vergleiche mit `{}` und `{"seiten":[]}`), ob eine
Konfigurationsdatei aus der Sicherung zurückgeholt wird, und räumte die
Sicherung anschließend ohne jede Bedingung weg. Eine abgeschnittene Datei ist
weder leer noch `{}`: sie galt als vorhanden, wurde nicht ersetzt, und die
einzige heile Abschrift war danach fort. In WSL Ubuntu gemessen (18.09.2026,
`Bestand-2026-09-18/klasse-C`, Fall 15, und `Pruefung-Dashboard-0.9.22`,
Fälle 1 bis 4) — für `dashboard.json`, `seiten.json` (die geordneten Kacheln)
und `zugang.json` (Benutzer und Kennwort des Miniservers). Jetzt wird nach
**Inhalt** entschieden: die Datei muss sich lesen lassen, ein Objekt sein und
etwas enthalten (`seiten.json` mindestens eine Seite). Zurückgespielt wird nur,
wenn die vorhandene Datei nachweislich nichts trägt und die Sicherung
nachweislich etwas; weggeräumt wird die Sicherung nur, wenn die Konfiguration
danach Inhalt trägt. Sonst bleibt sie liegen, und das Installationsprotokoll
nennt sie mit Pfad.

**Die Sicherung konnte beim zweiten Update-Anlauf überschrieben werden.**
`preupgrade.sh` kopierte die Konfiguration ohne Prüfung über eine vorhandene
Sicherung. Bricht ein Update zwischen `preupgrade` und `postinstall` ab, liegt
die heile Sicherung des ersten Anlaufs noch da; der zweite Anlauf ersetzte sie
durch die inzwischen beschädigte Datei (gemessen, `Pruefung-Dashboard-0.9.22`,
Fall 8). Jetzt bleibt eine vorhandene Sicherung unverändert, wenn die
Konfiguration keinen Inhalt trägt. Gibt es noch gar keine, wird weiterhin
gesichert, was da ist.

**`dienst.sh` rechnete Wurzel und Ordnernamen aus dem eigenen Ablageort und
legte das Ergebnis an — bei jedem Aufruf.** Bis 0.9.21 kamen Wurzel und
Ordnername allein aus dem Verzeichnis, in dem `dienst.sh` liegt; ein gesetztes
`LBHOMEDIR` wurde überschrieben, und ein `mkdir -p` auf oberster Ebene legte
den errechneten Daten- und Protokollordner an, auch bei `status`. Gemessen
(`Pruefung-Dashboard-0.9.22`, Fälle 12 bis 14): ein `status` aus einem
Prüfordner unter `<LoxBerry-Wurzel>/pruefung/dashboard/bin` legte in der
**laufenden** Installation `data/plugins/bin` und `log/plugins/bin` an; aus
einem ausgepackten Archiv mit gesetztem `LBHOMEDIR` galt der laufende Dienst
der Installation als „gestoppt"; und nach dem Abräumen des Datenordners durch
den Installer legte schon ein `status` ihn wieder an. Jetzt wird die Wurzel
zuerst aus `LBHOMEDIR` gelesen und erst danach aufwärts gesucht, der
Ordnername zuerst aus `LBPPLUGINDIR`. Passen Wurzel und Ordnername nicht
zusammen, endet der Aufruf mit einer Fehlermeldung, bevor etwas angelegt wird.
Angelegt wird nur noch beim Starten und wenn der Wächter neu startet.

**`postinstall.sh` von Hand aufgerufen legte Ordner an beliebiger Stelle an.**
Ohne Argumente fiel das Skript auf „zwei Ebenen über mir" zurück, ohne zu
prüfen, ob dort eine LoxBerry-Wurzel liegt (gemessen, Fall 16). Es sucht jetzt
wie `preupgrade.sh` und die beiden Deinstallationsskripte aufwärts nach einem
Verzeichnis mit `config/plugins` und `data/plugins` und bricht sonst ab, ohne
etwas anzulegen. Beim Aufruf durch den Installer ändert sich nichts: der
übergibt die Wurzel.

## Neu in 0.9.21

**Ein fremder Prozess konnte als Dienst gelten — und wurde beendet.** Wer der
Dienst ist, wurde bis 0.9.20 mit
`grep -qa "dashboard_dienst.py" /proc/<nummer>/cmdline` entschieden, also über
eine Teilzeichenkette der ganzen Befehlszeile. Das trifft jeden Prozess, in
dessen Befehlszeile der Dateiname irgendwo vorkommt: einen Editor mit der
Datei offen, ein Sicherungsskript, das den Ordner durchsucht, und den
Einmallauf der eigenen Oberfläche.

In WSL Ubuntu gemessen (18.09.2026,
`Bestand-2026-09-18/klasse-F-nachmessung/Befundliste-Nachmessung.md`, Zeile 8):
für den fremden Prozess `tail -f <dienstpfad>`, dessen Nummer in der PID-Datei
stand, meldete `dienst.sh status` wörtlich `laeuft 9000`, und nach
`dienst.sh stop` war er tot.

Geändert an drei Stellen:

- **`bin/dienst.sh`** erkennt den eigenen Dienst jetzt argumentweise
  (Regeln/03): argv[0] ist ein Python, argv[1] ist genau der eigene
  Dienstpfad (mit `readlink -f`-Gegenprobe), ein drittes Argument gibt es
  nicht — damit gelten die Einmalläufe `--selbsttest`, `--einmal`,
  `--entwurf`, `--anmeldeprobe`, `--httpprobe` und `--visuprobe`
  ausdrücklich **nicht** als Dienst. Gesucht wird über `/proc` mit
  Benutzerfilter, damit auch ein Dienst **ohne** PID-Datei mitgeht: die liegt
  im Datenordner, und `purge_installation` räumt den bei jedem Upgrade ab.
  `stop` beendet **alle** eigenen Treffer, sucht vor **jedem** Signal neu —
  auch vor dem `kill -9` — und meldet „angehalten" erst nach einer
  Nachkontrolle; sonst nennt es die übrig gebliebenen Nummern und gibt 1
  zurück. `status` meldet die gefundenen Nummern statt des Inhalts der
  PID-Datei.
- **`preupgrade.sh`** prüft im Rückfallweg ohne `dienst.sh` schon vor dem
  **ersten** Signal, wem die Nummer gehört (bisher erst vor dem harten), sagt
  es, wenn die Nummer einem fremden Vorgang gehört, und beendet zusätzlich
  einen eigenen Dienst ohne PID-Datei.
- **`webfrontend/html/db_lib.php`** entscheidet in der neuen Funktion
  `db_ist_dienst()` nach denselben Regeln. Vorher hielt `db_dienst_pid()`
  jeden Prozess mit passender Teilzeichenkette für den Dienst — die Kachel
  meldete „Dienst läuft", und der Knopf *Logdatei leeren* verweigerte sich.

Prüfstand: `Pruefung-Dashboard-0.9.21/` — 39 Fälle in zehn Lagen, vorher **20
Fehlschläge**, nachher **0**. Eichung: sieben Rückbauten, jeder einzeln, jeder
macht genau die vorhergesagten Zeilen rot. **Am Dienst selbst ändert sich
nichts.**

## Neu in 0.9.20

**Das Installationsprotokoll behauptete, einen Dienst angehalten zu haben, der
gar nicht lief.** Die Zeile stand unbedingt hinter dem Aufruf von `dienst.sh
stop`, obwohl `LIEF_VORHER` zwei Zeilen darüber längst ermittelt wird und die
Frage beantwortet. Jetzt hängt die Meldung daran.

Dasselbe im **Rückfallweg** ohne `dienst.sh`: dort stand sie hinter
`rm -f "$PID"` und damit außerhalb der Prüfung, ob der Prozess lebte — eine
liegengebliebene PID-Datei genügte.

Geprüft mit `Werkzeuge/preupgrade_meldung_pruefen.py`: gegen 0.9.20 grün, gegen
0.9.19 rot. **Am Verhalten ändert sich nichts.**

## Neu in 0.9.14

- **Das Auswahlfeld hatte gar keinen Pfeil.** Die eigene Feldregel dieser
  Seite setzte `background: #fff` — die Kurzform löscht das
  Hintergrundbild, mit dem die LoxBerry-Oberfläche den Pfeil zeichnet.
  Übrig blieb ein Feld, das aussieht wie ein Textfeld; wer nicht
  hineinklickt, erfährt nicht, dass eine Auswahl dahintersteht. Am
  05.09.2026 im Browser gegen die Rahmen-CSS des Geräts gemessen (LoxBerry 4.0.0.15)
  und behoben: die Seite zeichnet den Pfeil jetzt selbst
  (`Regeln/04`). Sonst ist an dieser Fassung nichts geändert.

### Der Dienst konnte sein Protokoll verlieren, ohne dass es auffiel

`log/plugins` liegt auf einer Ramdisk (`/dev/zram0`). Wird sie geleert — beim
Neustart, durch LoxBerrys `log_maint`, oder von Hand —, ist die Datei fort. Ein
`RotatingFileHandler`, der sie beim Start **einmal** geöffnet hat, schreibt
danach bis zum nächsten Neustart in einen gelöschten Inode: keine
Fehlermeldung, keine Datei, kein Hinweis. Auch die Rotation greift dann nicht
mehr.

Diese Fassung benutzt deshalb `WachsameRotation` in `bin/dashboard_dienst.py` — einen
umlaufenden Handler, der vor jeder Zeile Gerätenummer und Inode vergleicht und
nötigenfalls neu öffnet. Die Standardbibliothek hat für den einen Fall den
`WatchedFileHandler` und für den anderen den `RotatingFileHandler`, aber
nichts, was beides kann; deshalb die eigene Klasse.

Auf dem LoxBerry geeicht, vier Prüfungen und in beide Richtungen: schreiben,
nach dem Löschen weiterschreiben, Umlauf bei Überlänge, nach dem Umlauf erneut
löschen. Mit dem alten Handler ist die Zeile nach dem Löschen verloren und
bleibt es, mit dem neuen steht sie in der wieder angelegten Datei. Auf einem
Windows-Arbeitsplatz lässt sich das nicht messen — dort kann eine offene Datei
gar nicht gelöscht werden.

Aufgefallen ist die Bauart am Heimkino-Plugin, dessen Dienst sieben Stunden
ohne Protokolldatei lief, und am laufenden Gerät belegt: der
Midea2Lox-Dienst hielt `midea2lox.log (deleted)` offen, während unter
demselben Namen längst eine neue Datei fortgeschrieben wurde — von außen sah
das Plugin gesund aus. Elf Linien tragen dieselbe Bauart; alle elf sind am
06.09.2026 nachgezogen worden.


## Warum ein Dienst dazwischen hängt

    Miniserver ──WebSocket (ws/rfc6455)──> Dienst auf dem LoxBerry
                                              │
                                       Zwischenspeicher
                                              │
                          Tablet 1 ── Tablet 2 ── Tablet 3 ── …

Der Miniserver lässt **31 Clients gleichzeitig** Zustandsänderungen empfangen
(„Communicating with the Miniserver", Seite 9). Jedes Tablet mit eigener
Verbindung würde einen dieser Plätze verbrauchen — und dazu Benutzername und
Kennwort im Browser brauchen. Deshalb hält **ein** Dienst **eine** Verbindung
und bedient damit beliebig viele Tablets.

## Aufbau

    bin/lox_client.py        Miniserver-Client: Token, WebSocket,
                             Ereignistabellen, HTTP-Rückfall
    bin/dashboard_dienst.py  Dienst: Verbindung halten, Abbild schreiben,
                             Befehlswarteschlange, Selbsttest, Proben
    bin/entwurf.py           Erstentwurf aus der Struktur
    bin/dienst.sh            Start, Stopp, Wächter, Proben
    cron/cron.01min          minütlicher Wächter
    dpkg/apt                 die beiden Debian-Pakete, von LoxBerry als
                             root installiert
    templates/kacheln.json   Zuordnung Bausteintyp → Kachel — EINE Datei
                             für Dienst, Designer und Anzeigeseite
    webfrontend/htmlauth/    Oberfläche (sechs Reiter) + Designer
    webfrontend/html/        Endpunkt, Anzeigeseite (tafel.php), Bibliothek
    uninstall.sh             räumt die Sicherungen mit den Zugangsdaten weg
    uninstall/uninstall      dasselbe — welches der beiden LoxBerry ausführt,
                             ist hier nicht nachgemessen, deshalb tut seit
                             0.9.13 jedes die ganze Arbeit

## Was am Miniserver benutzt wird

Alles aus der offiziellen Dokumentation, nichts geraten:

| Schritt | Aufruf | Beleg |
|---|---|---|
| Erreichbarkeit | `jdev/cfg/api` | K, S. 8 |
| Öffentlicher Schlüssel | `jdev/sys/getPublicKey` | K, S. 26 |
| Sitzungsschlüssel | `jdev/sys/keyexchange/{RSA(key:iv)}` | K, S. 9 |
| Salt-Wechsel | `nextSalt/{prev}/{next}/{cmd}` | K, S. 8 |
| Anmeldedaten | `jdev/sys/getkey2/{user}` → key, salt, hashAlg | K, S. 29 |
| Token holen | `jdev/sys/getjwt/…` (**muss** verschlüsselt sein) | K, S. 30 |
| Wiederanmeldung | `authwithtoken/{hash}/{user}` mit dem hashAlg des Benutzers | K, S. 15, 31 |
| Struktur | `data/LoxAPP3.json` | K, S. 24 |
| Zustände einschalten | `jdev/sps/enablebinstatusupdate` | K, S. 18 |
| Schalten | `jdev/sps/io/{uuid}/{befehl}` | K, S. 13 |

K = *Communicating with the Miniserver*, Fassung 17.0 vom 31.03.2026.
S = *Structure File*, Fassung 17.0 vom 31.03.2026.
Beide frei abrufbar unter loxone.com/enen/kb/api/.

### Der Schlüsseltausch

Der Sitzungsschlüssel geht **roh** über den WebSocket, nicht URI-kodiert. Das
Dokument unterscheidet drei Stellen und kodiert nur zwei davon:

| Stelle | Dokument |
|---|---|
| Sitzungsschlüssel im HTTP-Aufruf (`?sk=`) | „URI-Component-Encode the {session-key}" |
| Verschlüsselter Befehl über WebSocket (`jdev/sys/enc/…`) | „URI-Component-Encode the {cipher}" |
| Sitzungsschlüssel über WebSocket (`jdev/sys/keyexchange/…`) | **keine Kodierung genannt** |

Bis 0.9.6 wurde auch die dritte Stelle kodiert. Gemessen an Firmware
17.1.7.27: URI-kodiert antwortet der Miniserver mit **401**, roh mit **200**.
Die 401 ist dabei keine Anmeldefrage — an dieser Stelle ist noch kein Kennwort
im Spiel; der Miniserver bekommt schlicht kein entschlüsselbares Paket, und
darauf antwortet er laut Dokument mit 401.

**Zustände kommen als binäre Ereignistabellen**, nicht als Text `uuid:wert` —
das steht in vielen Gemeinschaftsbeschreibungen falsch. Vor jeder Nachricht
steht ein 8-Byte-Kopf, der sagt, was folgt; Wert-Einträge sind je 24 Byte
(16 Byte UUID + 8 Byte Double), Text-Einträge variabel mit Füllbytes auf ein
Vielfaches von vier (K, S. 19–22).

## Der Erstentwurf

Nach der Installation baut das Plugin aus Räumen und Bewertungen sofort
brauchbare Seiten — je Raum eine, Favoriten und höher bewertete Bausteine
zuerst. Ausgelassen werden Bausteine mit leerem Typ (die Strukturdatei sagt
dazu: *„an empty string as type indicates a control that should not be
visualized"*) und solche, deren `restrictions`-Bit 0 oder 4 gesetzt ist.

Ein erneuter Entwurf **ergänzt nur**: bestehende Seiten und jede Handarbeit
bleiben unangetastet.

## Welche Bausteine bedient werden

Bausteine mit eigenem Bedienelement: `Switch`, `Pushbutton`, `Dimmer`,
`LightControllerV2`, `LightController` (V1, eigene Szenenkachel), `Jalousie`
(mit Schieberegler), `Gate`, `IRoomControllerV2`, `IRoomController`,
`TimedSwitch`, `Radio`, `Slider`, `ValueSelector`, `Alarm`, `SmokeAlarm`
(eigene Kachel), `ColorPickerV2` und `ColorPicker` (V1).

Die Farbkachel bedient Farbton, Sättigung, Helligkeit und den Weißton. Welchen
Weißbefehl sie sendet, entscheidet die Befehlsliste des Bausteins:
`temp(Helligkeit,Kelvin)` beim ColorPickerV2, `lumitech(Helligkeit,Kelvin)`
beim älteren ColorPicker — beides belegt in *Structure File*, Abschnitte
*ColorPickerV2* und *ColorPicker*. Die Grenzen des Weißtons kommen aus den
Details `TWMin`/`TWMax` des Bausteins, nicht aus einer festen Annahme; steht
dort `pickerType: TunableWhite`, entfallen Farbton und Sättigung. Der
ColorPickerV2 kennt laut Dokument **kein** `off` — die Kachel schaltet ihn
über die Helligkeit 0 aus.

Anzeigekacheln für `InfoOnlyAnalog`, `InfoOnlyDigital`, `InfoOnlyText`,
`TextState`, `Meter`, `Hourcounter`, `PresenceDetector`, `Webpage`, dazu
`WeatherServer` (aktuelle Lage und 24 Stunden Vorhersage) und `Daytimer`
(Tagesbalken mit den geschalteten Zeiträumen).

Der Wetterdienst steht in der Strukturdatei **nicht unter `controls`**,
sondern als eigener Abschnitt `weatherServer`; das Plugin baut daraus einen
Eintrag, sonst wäre er unauffindbar.

Dazu die **Szene**: mehrere Befehle auf einen Druck, im Designer aus
Baustein und Befehl zusammengestellt.

Jeder andere Typ bekommt eine schlichte Kachel mit seinen Zuständen — **aber
keinen Schaltknopf**. Welcher Befehl für einen unbekannten Typ richtig wäre,
weiß hier niemand, und ein geratener Befehl an eine Alarmanlage ist schlimmer
als ein fehlender Knopf.

**Gesicherte Bausteine** (`isSecured` in Loxone Config) tragen ein Schloss.
Sie lassen sich schalten, wenn **beides** eingerichtet ist: der Haken
*Gesicherte Bausteine schalten dürfen* und ein hinterlegtes
Visualisierungs-Passwort. Beides fehlt ab Werk; fehlt eines, bleibt die Kachel
gesperrt und sagt, was fehlt.

Der Weg dahin steht in *Communicating with the Miniserver*, Abschnitt
*Secured Commands*: `jdev/sys/getvisusalt/{user}` liefert `key`, `salt` und
`hashAlg`, daraus wird `hashAlg("{visuPw}:{salt}")` in Großbuchstaben gebildet,
darüber `HMAC(…, key)`, und gesendet wird
`jdev/sps/ios/{hash}/{uuid}/{command}`.

Das Passwort liegt in `zugang.json` mit Rechten 0600 und **verlässt den
LoxBerry nicht** — der Dienst bildet den Hash, gesendet wird nur der. Der
Reiter *Test* prüft es über `jdev/sps/checkuservisupwd`, also **ohne etwas
auszulösen**; diesen Dienst gibt es laut Loxone erst ab Firmware 16.0, und
auf älterer sagt die Meldung das, statt „Passwort falsch" zu behaupten.

**Abwägung, die dazugehört:** wer das Passwort hinterlegt, nimmt genau die
Rückfrage weg, für die er es in Loxone Config gesetzt hat. Wer den Schutz am
Tablet behalten will, setzt eine **PIN auf die Seite**, auf der die Kachel
liegt.

Für Bausteine, die in Loxone auf „nur lesen" stehen, gilt das nicht: sie
tragen ein Auge und haben keine Knöpfe.

## Die Anzeigeseite

`tafel.php` ist **eine Datei ohne fremde Bibliotheken**: kein Framework, keine
Schrift aus dem Netz. Ein Wandtablet soll auch dann funktionieren, wenn das
Haus kein Internet hat — und genau darum geht es bei diesem Plugin.

Sie holt einmal ihre Struktur und danach nur noch Werte — im Takt oder,
wahlweise, geschoben (Server-Sent Events). Kommt der Schub nicht durch, fällt
sie auf die Abfrage zurück **und zeigt das an**; sonst würde aus dem Ersatz
unbemerkt der Normalfall.

Dazu kommen, alle **ab Werk abgeschaltet**: Seitenrotation, Nachtabsenkung mit
Zeitplan, Verlaufskurve auf den Kacheln, das Ruhebild (siehe unten), und die
Steuerung der Anzeige durch Loxone (Seitenwechsel, Wecken, Helligkeit,
Ruhebild) über einen virtuellen Ausgang.

### Der Ambient-Modus

Uhrzeit, Datum und Wetter stehen **dauerhaft** über den Kacheln, das
Hintergrundbild liegt dahinter — und die Kacheln bleiben stehen und bleiben
bedienbar. Über einem Bild bekommen sie so viel Deckung, dass sie auch auf
einem hellen Foto lesbar sind.

Dem **Ambient-Modus** der Loxone-App nachempfunden. Nachgebaut ist das
*Verhalten*; eine Schnittstelle dafür gibt es bei Loxone nicht, und dieses
Plugin spricht keine an.

**Nicht zu verwechseln mit dem Ruhebild darunter.** Der Ambient-Modus
*gestaltet* die Tafel, das Ruhebild tritt an ihre *Stelle* — bei Loxone heißt
das Bildschirmschoner. Beide sind einzeln schaltbar, beide ab Werk aus, und
beide benutzen dasselbe Hintergrundbild und dieselbe Wetterquelle.

### Das Ruhebild

Nach einer einstellbaren Zeit ohne Berührung tritt die Bedienung zurück. Es
bleiben Uhrzeit, Datum, die aktuelle Wetterlage und bis zu zwölf Werte —
lesbar aus einigen Metern, dunkler als die Tafel, wahlweise über einem eigenen
Hintergrundbild. Jede Berührung holt die Tafel zurück, und **diese eine
Berührung schaltet nichts**: ein Griff im Vorbeigehen soll kein Fehlgriff
werden.

Es ist dem **Bildschirmschoner** der Loxone-App nachempfunden — nicht dem
Ambient-Modus darüber, der die Tafel *gestaltet*, statt an ihre Stelle zu
treten. Nachgebaut ist das *Verhalten*; beides sind Betriebsarten der App (ab
App und Config 14.x, nur Querformat, mindestens 1024×700), keine
Schnittstellen. Dieses Plugin spricht keine an und benutzt ausschließlich die
eigenen Werte.

Die Wetterzeile kommt aus einer von **zwei** Quellen (siehe unten); fehlt der
Klartext zu einer Wetterlage in der Anlage, steht die Zahl da und keine
erfundene Beschreibung. Je Kachel steht **ein** Wert — der
Hauptzustand aus der Kacheltabelle, nicht irgendeiner. Solange das Ruhebild
aufliegt, blättert die Seitenrotation nicht weiter, und eine Nachtabsenkung
wirkt zusätzlich.

### Der Eco-Modus

Die dritte Absenkung — und die einzige, die nach der **Berührung** geht statt
nach der Uhr. Nach einer einstellbaren Zeit ohne Berührung wird die Anzeige
dunkler, und die **Kacheln bleiben dabei stehen** und bleiben ablesbar. Jede
Berührung und jeder Tastendruck hebt ihn sofort wieder auf.

Damit hat das Plugin dieselben drei Dinge wie die Loxone-App, und getrennt wie
dort:

| | Loxone | dieses Plugin |
|---|---|---|
| die Tafel **gestalten** | Ambient-Modus | `ambient` |
| an ihre **Stelle** treten | Bildschirmschoner | Ruhebild (`ruhe_nach`) |
| sie **absenken**, Kacheln bleiben | Eco-Modus | `eco_nach` |

Alle drei sind einzeln schaltbar und ab Werk aus, und alle drei können
nebeneinander laufen. Trifft der Eco-Modus mit der **Nachtabsenkung**
zusammen, gilt der **dunklere** von beiden — beide sagen „jetzt soll es dunkel
sein", und die schärfere Aussage gewinnt. Ein **Helligkeitsbefehl aus Loxone**
ist etwas anderes, nämlich eine Ansage, und steht über beiden.

Steht die Helligkeit auf 0, wird der Bildschirm schwarz; die erste Berührung
weckt ihn dann, **ohne etwas zu schalten** — dasselbe Versprechen wie beim
Ruhebild.

### Die Wetterzeile: zwei Quellen, nie gemischt

Die Zeile unter der Uhr — im Ambient-Modus wie im Ruhebild — kann aus zwei
Quellen kommen:

1. **Loxones eigener Wetterdienst** (Ereignistabelle Kennung 7). Das ist die
   Vorgabe. Hat die Anlage keinen, bleibt die Zeile leer.
2. **Drei selbst gewählte Bausteine** — Lage, Temperatur und eine dritte
   Angabe. Wer eine eigene Station betreibt (Ecowitt, Weather4Loxone, ein
   Fühler am Haus), hat deren Werte längst als gewöhnliche Bausteine in
   Loxone. Das Plugin liest sie ohnehin schon: **kein MQTT, keine zweite
   Schnittstelle.**

**Sobald einer der drei gesetzt ist, gilt ausschließlich diese Auswahl.**
Gemischt stünden zwei Messungen nebeneinander in einer Zeile, ohne dass
jemand sieht, welche woher kommt — eine Temperatur vom Dach neben einer aus
der Wolke.

Zur Auswahl stehen alle Bausteine, die eine **Zahl oder einen Text** tragen;
eine Jalousie ist keine Wetterangabe. Ein Baustein, den es nicht mehr gibt,
wird beim Speichern **abgewiesen** statt stillschweigend geleert — sonst wäre
eine gelöschte Wahl ein stiller Rückfall auf die andere Quelle.

Unter der Auswahl steht, **wie die Zeile im Augenblick aussähe**. Das ist
nicht Zierde: an einer echten Anlage gemessen liefert Weather4Loxone seinen
Baustein `Wetter aktuell` leer, während `Wetter Heute samt Wettertyp` den
Text trägt. Wer das erst am Tablet merkt, sucht lange.

## Sicherheit

- Endpunkt und Anzeigeseite liegen im unangemeldeten Bereich (damit ein
  Wandtablet ohne Anmeldung offen bleiben kann) und sind durch ein langes
  Zufallstoken geschützt; verglichen wird mit `hash_equals`.
- **`?selftest=1`** beantwortet, ob das Token noch stimmt, **ohne dass etwas
  passiert** — kein Gerätekontakt, kein Schreibzugriff. Ein falsches Token
  bekommt dieselbe Abweisung wie sonst auch.
- Je Seite ist eine PIN möglich. Geprüft wird die PIN **der Seite, von der der
  Druck kam** — deshalb ist `&seite=` bei jedem schaltenden Aufruf Pflicht.
- Geschaltet werden kann **nur, was auf einer Seite steht**, und nur mit den
  Befehlen, die die Kacheltabelle für genau diesen Bausteintyp nennt. Bei einer
  Szene wird **jeder Schritt einzeln** geprüft — sie ist keine Abkürzung an der
  Prüfung vorbei. Beides wird zweimal geprüft: am Endpunkt und im Dienst.
- Zugangsdaten liegen in `zugang.json` mit Rechten 0600 — nie in der
  angezeigten Konfiguration, nie auf der Kommandozeile, nie in der Adresse.
  Der Wert eines Kennworts wird nirgends angezeigt, auch nicht verkürzt.
- Eingaben, die nicht zum Muster passen, werden **abgelehnt und benannt**, nie
  stillschweigend zurechtgebogen.

## Was ungeprüft bleibt

Ob die Token-Anmeldung auf **Ihrer** Firmware durchgeht und wie flüssig sich
das Dashboard bei Ihrer Anzahl Bausteine anfühlt. Anmeldung und Kachel-Befehle
sind an *einer* Anlage gemessen (07.09.2026, 17.2.8.28) — das ist keine
Zusage für jede. Für beide Fragen gibt es im Reiter *Test* je einen Knopf, der
sie an Ihrer Anlage **misst** statt sie zu vermuten.

Namentlich ungeprüft und deshalb hier genannt:

- **`jdev/sps/io/{uuid}/state`** als HTTP-Notnagel steht in keinem der beiden
  Loxone-Dokumente. Der Knopf *HTTP-Notnagel messen* probiert es an einem
  Baustein Ihrer Anlage aus.
- **Die Wetter- und Zeitschaltuhr-Kachel** ist aus den Binärstrukturen des
  Dokuments gebaut (Kennung 7 und 4) und gegen eine Attrappe gemessen, die
  ihre Pakete ebenfalls aus dem Dokument packt — aber nie gegen einen echten
  Wetterdienst. Insbesondere die Zuordnung der Zahl in `weatherType` zum
  Klartext kommt aus `weatherTypeTexts` Ihrer Anlage; fehlt dort ein Eintrag,
  steht die Zahl da statt einer erfundenen Beschreibung.
- **Der Weg für gesicherte Bausteine** ist aus dem Dokument gebaut und gegen
  eine Attrappe gemessen, die den erwarteten Hash ebenfalls aus dem Dokument
  rechnet — nicht aus diesem Quelltext. Am Gerät geprüft ist er nicht.
- **Die Marke „Aktualisierung läuft"** (neu in 0.9.23) ist in WSL mit einem
  nachgestellten Installer-Ablauf gemessen, nicht an einem LoxBerry. Der
  Benutzerwechsel auf `loxberry` und Apache sind dabei nicht nachgebildet.

## Grundlage

*Communicating with the Miniserver* **17.0** und *Structure File* **17.0**
(beide 31.03.2026, `1700_Communicating-with-the-Miniserver.pdf` und
`1700_Structure-File.pdf`, frei abrufbar unter loxone.com/enen/kb/api/).

Bis 0.9.5 war gegen Fassung 16.0 gebaut. Der Abgleich auf 17.0 hat drei
Stellen geändert:

- **Alarm** — `nextLevelDelay` und `sensors` sind seit Config 13.0
  abgekündigt; an ihre Stelle treten `nextLevelAt` und `armedAt`, beides
  Unix-Zeitstempel. Die Kachel zeigt daraus die laufende Verzögerung.
- **Radio** — die Namen der Ausgänge stehen in den Details unter `outputs`,
  die Beschriftung für „nichts gewählt" unter `allOff`. Vorher standen dort
  fest die Knöpfe 1, 2 und 3: bei einem Baustein mit acht Ausgängen waren
  fünf nicht erreichbar. `next` und `prev` gibt es seit 13.3.1.10.
- **Meter** — `totalDay` und `totalWeek` gibt es seit 13.01.

Ausdrücklich **unverändert richtig**: der Raumregler V2 (alle sieben
Zustände und beide Befehle stehen so im Dokument), die Anmeldung, die
Ereignistabellen und die Kopfstruktur. Die Neuerungen der Fassung 17.0
selbst (Remote Connect, neue Adresse zur Auflösung der externen Adresse,
Fancoils am Raumregler, Präsenz-Befehle) berühren dieses Plugin nicht: es
spricht den Miniserver im eigenen Netz an und schaltet keine Fancoils.

Die Änderungen je Fassung stehen in der Commit-Nachricht und auf der
GitHub-Release-Seite zum jeweiligen Tag — nicht hier. Eine dritte Kopie
derselben Aussage läuft zwangsläufig aus dem Takt; genau das war der README
bis 0.9.5 passiert, die noch 0.9.1 beschrieb.

**Und es ist wieder passiert.** Unter diesem Absatz stand bis 0.9.12 ein
Abschnitt „Fassung 0.9.12 — der Stat-Zwischenspeicher", also genau die
vierte Kopie, gegen die der Absatz darüber sich ausspricht; die Kopfzeile
dieser Datei nannte gleichzeitig noch die Fassung 0.9.7 und behauptete, das
Plugin sei am Miniserver ungeprüft — fünf Fassungen und eine Messung am
Gerät später. Der Abschnitt ist entfernt, der Inhalt steht auf der
Release-Seite zu `v0.9.12`. Eine Regel, die man im selben Text bricht, ist
keine.
