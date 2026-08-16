# LoxBerry-Plugin: Dashboard-Designer

Liest die Struktur des **Loxone Miniservers** aus und baut daraus per
Drag-and-Drop moderne Kachel-Dashboards, die sich auf jedem Tablet ohne
Loxone-App aufrufen lassen.

> **Fassung 0.9.6 — ungeprüft am echten Miniserver.** Gebaut ohne Hardware;
> gemessen gegen eine Attrappe, die streng nach den Loxone-Dokumenten gebaut
> ist. Deshalb 0.9.6 und nicht 1.0.0. Was ungeprüft bleibt, steht unten unter
> *Was ungeprüft bleibt* — vollständig und ohne Beschönigung.

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

K = *Communicating with the Miniserver*, Fassung 16.0 vom 03.06.2025.

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
(eigene Kachel) und `ColorPickerV2` (Farbwahl über HSV).

Anzeigekacheln für `InfoOnlyAnalog`, `InfoOnlyDigital`, `InfoOnlyText`,
`TextState`, `Meter`, `Hourcounter`, `PresenceDetector`, `Webpage`.

Dazu die **Szene**: mehrere Befehle auf einen Druck, im Designer aus
Baustein und Befehl zusammengestellt.

Jeder andere Typ bekommt eine schlichte Kachel mit seinen Zuständen — **aber
keinen Schaltknopf**. Welcher Befehl für einen unbekannten Typ richtig wäre,
weiß hier niemand, und ein geratener Befehl an eine Alarmanlage ist schlimmer
als ein fehlender Knopf.

**Gesicherte Bausteine** (`isSecured` in Loxone Config) tragen ein Schloss und
lassen sich hier **nicht** schalten. Loxone verlangt dafür das
Visualisierungs-Passwort; wie dessen Hash gebildet wird, ließ sich hier gegen
kein Dokument und keine Anlage messen. Ein geratener Sicherheitsweg ist
schlimmer als gar keiner — deshalb weist der Endpunkt solche Befehle ab und
sagt, warum. Dasselbe gilt für Bausteine, die in Loxone auf „nur lesen"
stehen; sie tragen ein Auge und haben keine Knöpfe.

## Die Anzeigeseite

`tafel.php` ist **eine Datei ohne fremde Bibliotheken**: kein Framework, keine
Schrift aus dem Netz. Ein Wandtablet soll auch dann funktionieren, wenn das
Haus kein Internet hat — und genau darum geht es bei diesem Plugin.

Sie holt einmal ihre Struktur und danach nur noch Werte — im Takt oder,
wahlweise, geschoben (Server-Sent Events). Kommt der Schub nicht durch, fällt
sie auf die Abfrage zurück **und zeigt das an**; sonst würde aus dem Ersatz
unbemerkt der Normalfall.

Dazu kommen, alle **ab Werk abgeschaltet**: Seitenrotation, Nachtabsenkung mit
Zeitplan, Verlaufskurve auf den Kacheln, und die Steuerung der Anzeige durch
Loxone (Seitenwechsel, Wecken, Helligkeit) über einen virtuellen Ausgang.

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

Ob die Token-Anmeldung auf Ihrer Firmware durchgeht, ob die Kachel-Befehle am
Gerät die erwartete Wirkung haben und wie flüssig sich das Dashboard bei Ihrer
Anzahl Bausteine anfühlt. Für die ersten beiden Fragen gibt es im Reiter
*Test* je einen Knopf, der sie an Ihrer Anlage **misst** statt sie zu
vermuten.

Namentlich ungeprüft und deshalb hier genannt:

- **`jdev/sps/io/{uuid}/state`** als HTTP-Notnagel steht in keinem der beiden
  Loxone-Dokumente. Der Knopf *HTTP-Notnagel messen* probiert es an einem
  Baustein Ihrer Anlage aus.
- **Die Reihenfolge in `temp(Helligkeit,Kelvin)`** der Farbkachel ist die
  dokumentierte, aber an keiner Anlage nachgemessen. Der HSV-Weg
  (`hsv(Farbton,Sättigung,Helligkeit)`) ist der belegte.
- **Wetter- und Tageszeittabellen** (Kennung 4 und 7) werden empfangen und
  bewusst **nicht** ausgewertet: das Format ließ sich hier gegen keine Anlage
  messen, und ein halb verstandener Datensatz ist schlechter als keiner. Der
  Reiter *Test* sagt, wie viele ankamen — schweigen wäre ein blinder Fleck.
- **Das Visualisierungs-Passwort** für gesicherte Bausteine, siehe oben.

## Grundlage

*Communicating with the Miniserver* 16.0 und *Structure File* 16.0
(beide 03.06.2025, loxone.com). Die Abschnitte zu `Switch`, `Pushbutton`,
`Radio`, `Slider`, `ValueSelector` und `TimedSwitch` fehlten im Textauszug der
Fassung 16.0 und stammen aus den Fassungen 12.2 und 8.3.

Die Änderungen je Fassung stehen in der Commit-Nachricht und auf der
GitHub-Release-Seite zum jeweiligen Tag — nicht hier. Eine dritte Kopie
derselben Aussage läuft zwangsläufig aus dem Takt; genau das war der README
bis 0.9.5 passiert, die noch 0.9.1 beschrieb.
