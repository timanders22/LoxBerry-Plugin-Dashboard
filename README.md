# LoxBerry-Plugin: Dashboard-Designer

Liest die Struktur des **Loxone Miniservers** aus und baut daraus per
Drag-and-Drop moderne Kachel-Dashboards, die sich auf jedem Tablet ohne
Loxone-App aufrufen lassen.

> **Fassung 0.9.7 — ungeprüft am echten Miniserver.** Gebaut ohne Hardware;
> gemessen gegen eine Attrappe, die streng nach den Loxone-Dokumenten gebaut
> ist. Deshalb 0.9.7 und nicht 1.0.0. Was ungeprüft bleibt, steht unten unter
> *Was ungeprüft bleibt* — vollständig und ohne Beschönigung.
>
> **Wer 0.9.5 oder 0.9.6 installiert hat, muss aktualisieren.** Beide Fassungen
> kommen an einem Miniserver mit aktueller Firmware **gar nicht erst zustande**:
> der Sitzungsschlüssel wurde URI-kodiert übertragen, und der Miniserver weist
> das mit Code 401 ab — noch bevor ein Kennwort im Spiel ist. Am 16.08.2026 an
> Firmware 17.1.7.27 gemessen. Siehe *Der Schlüsseltausch* weiter unten.
>
> Dazu enthält 0.9.6 vier fertige Punkte nicht, die als Zwischenstand
> veröffentlicht wurde: Farbwahl, gesicherte Bausteine, Wetter- und
> Zeitschaltuhr-Kachel, Abgleich auf die Dokumentfassung 17.0.

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
- **Die Wetter- und Zeitschaltuhr-Kachel** ist aus den Binärstrukturen des
  Dokuments gebaut (Kennung 7 und 4) und gegen eine Attrappe gemessen, die
  ihre Pakete ebenfalls aus dem Dokument packt — aber nie gegen einen echten
  Wetterdienst. Insbesondere die Zuordnung der Zahl in `weatherType` zum
  Klartext kommt aus `weatherTypeTexts` Ihrer Anlage; fehlt dort ein Eintrag,
  steht die Zahl da statt einer erfundenen Beschreibung.
- **Der Weg für gesicherte Bausteine** ist aus dem Dokument gebaut und gegen
  eine Attrappe gemessen, die den erwarteten Hash ebenfalls aus dem Dokument
  rechnet — nicht aus diesem Quelltext. Am Gerät geprüft ist er nicht.

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
