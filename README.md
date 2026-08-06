# LoxBerry-Plugin: Dashboard-Designer

Liest die Struktur des **Loxone Miniservers** aus und baut daraus per
Drag-and-Drop moderne Kachel-Dashboards, die sich auf jedem Tablet ohne
Loxone-App aufrufen lassen.

> **Fassung 0.9.0 — ungeprüft am echten Miniserver.** Gebaut ohne Hardware;
> geprüft gegen eine Attrappe, die streng nach den Loxone-Dokumenten gebaut
> ist. Deshalb 0.9.0 und nicht 1.0.0.

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

## Was am Miniserver benutzt wird

Alles aus der offiziellen Dokumentation, nichts geraten:

| Schritt | Aufruf | Beleg |
|---|---|---|
| Erreichbarkeit | `jdev/cfg/api` | K, S. 8 |
| Öffentlicher Schlüssel | `jdev/sys/getPublicKey` | K, S. 26 |
| Sitzungsschlüssel | `jdev/sys/keyexchange/{RSA(key:iv)}` | K, S. 9 |
| Anmeldedaten | `jdev/sys/getkey2/{user}` → key, salt, hashAlg | K, S. 29 |
| Token holen | `jdev/sys/getjwt/…` (**muss** verschlüsselt sein) | K, S. 30 |
| Wiederanmeldung | `authwithtoken/{hash}/{user}` | K, S. 31 |
| Struktur | `data/LoxAPP3.json` | K, S. 24 |
| Zustände einschalten | `jdev/sps/enablebinstatusupdate` | K, S. 18 |
| Schalten | `jdev/sps/io/{uuid}/{befehl}` | K, S. 13 |

K = *Communicating with the Miniserver*, Fassung 16.0 vom 03.06.2025.

**Zustände kommen als binäre Ereignistabellen**, nicht als Text `uuid:wert` —
das steht in vielen Gemeinschaftsbeschreibungen falsch. Vor jeder Nachricht
steht ein 8-Byte-Kopf, der sagt, was folgt; Wert-Einträge sind je 24 Byte
(16 Byte UUID + 8 Byte Double), Text-Einträge variabel mit Füllbytes auf ein
Vielfaches von vier (K, S. 19–22).

## Aufbau

    bin/lox_client.py        Miniserver-Client: Token, WebSocket,
                             Ereignistabellen, HTTP-Rückfall
    bin/dashboard_dienst.py  Dienst: Verbindung halten, Abbild schreiben,
                             Befehlswarteschlange, Selbsttest
    bin/entwurf.py           Erstentwurf aus der Struktur
    bin/dienst.sh            Start, Stopp, Wächter
    cron/cron.01min          minütlicher Wächter
    templates/kacheln.json   Zuordnung Bausteintyp → Kachel — EINE Datei
                             für Dienst, Designer und Anzeigeseite
    webfrontend/htmlauth/    Oberfläche (sechs Reiter) + Designer
    webfrontend/html/        Endpunkt, Anzeigeseite (tafel.php), Bibliothek

Im venv liegen zwei Pakete: `websockets` und `cryptography`. Beide sind
Pflicht.

## Der Erstentwurf

Nach der Installation baut das Plugin aus Räumen und Bewertungen sofort
brauchbare Seiten — je Raum eine, Favoriten und höher bewertete Bausteine
zuerst. Ausgelassen werden Bausteine mit leerem Typ (die Strukturdatei sagt
dazu: *„an empty string as type indicates a control that should not be
visualized"*) und solche, deren `restrictions`-Bit 0 oder 4 gesetzt ist.

Ein erneuter Entwurf **ergänzt nur**: bestehende Seiten und jede Handarbeit
bleiben unangetastet.

## Welche Bausteine bedient werden

Zehn Typen mit eigenem Bedienelement: `Switch`, `Pushbutton`, `Dimmer`,
`LightControllerV2`, `Jalousie`, `Gate`, `IRoomControllerV2`, `TimedSwitch`,
`Radio`, `Alarm`. Dazu Anzeigekacheln für `InfoOnlyAnalog`, `InfoOnlyDigital`,
`InfoOnlyText`, `TextState`, `Slider`, `ValueSelector`, `Meter`,
`Hourcounter`, `PresenceDetector`, `SmokeAlarm`, `ColorPickerV2`.

Jeder andere Typ bekommt eine schlichte Kachel mit seinen Zuständen — **aber
keinen Schaltknopf**. Welcher Befehl für einen unbekannten Typ richtig wäre,
weiß hier niemand, und ein geratener Befehl an eine Alarmanlage ist schlimmer
als ein fehlender Knopf.

## Die Anzeigeseite

`tafel.php` ist **eine Datei ohne fremde Bibliotheken**: kein Framework, keine
Schrift aus dem Netz. Ein Wandtablet soll auch dann funktionieren, wenn das
Haus kein Internet hat — und genau darum geht es bei diesem Plugin.

Sie holt einmal ihre Struktur und danach im Takt nur noch die Werte. Vollbild
und Bildschirm-wach werden nach der ersten Berührung versucht; klappt es nicht,
passiert einfach nichts.

## Sicherheit

- Endpunkt und Anzeigeseite liegen im unangemeldeten Bereich (damit ein
  Wandtablet ohne Anmeldung offen bleiben kann) und sind durch ein langes
  Zufallstoken geschützt; verglichen wird mit `hash_equals`.
- Je Seite ist eine PIN möglich.
- Geschaltet werden kann **nur, was auf einer Seite steht**, und nur mit den
  Befehlen, die die Kacheltabelle für genau diesen Bausteintyp nennt. Beides
  wird zweimal geprüft: am Endpunkt und noch einmal im Dienst.
- Zugangsdaten liegen in `zugang.json` mit Rechten 0600 — nie in der
  angezeigten Konfiguration, nie auf der Kommandozeile, nie in der Adresse.
  Der Wert eines Kennworts wird nirgends angezeigt, auch nicht verkürzt.
- Eingaben, die nicht zum Muster passen, werden **abgelehnt und benannt**, nie
  stillschweigend zurechtgebogen.

## Was ungeprüft bleibt

Ob die Token-Anmeldung auf Ihrer Firmware durchgeht, ob die Kachel-Befehle am
Gerät die erwartete Wirkung haben und wie flüssig sich das Dashboard bei Ihrer
Anzahl Bausteine anfühlt. Alles davor — Anmeldung, Ereignistabellen,
Erstentwurf, Designer, Endpunkt, Anzeigeseite — ist gegen eine Attrappe
gemessen, die aus den Loxone-Dokumenten gebaut wurde und nicht aus diesem
Quelltext.

## Grundlage

*Communicating with the Miniserver* 16.0 und *Structure File* 16.0
(beide 03.06.2025, loxone.com). Die Abschnitte zu `Switch`, `Pushbutton`,
`Radio`, `Slider`, `ValueSelector` und `TimedSwitch` fehlten im Textauszug der
Fassung 16.0 und stammen aus den Fassungen 12.2 und 8.3.
