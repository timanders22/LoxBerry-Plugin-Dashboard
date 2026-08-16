#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Dashboard-Designer - der Dienst.

Er haelt EINE Verbindung zum Miniserver und bedient damit beliebig viele
Tablets. Das ist der ganze Grund, warum es diesen Dienst gibt: der Miniserver
laesst nur 31 Clients gleichzeitig Zustaende empfangen
["Communicating with the Miniserver" 17.0, Seite 8], und jedes Tablet, das
selbst eine Verbindung aufmachte, wuerde einen dieser Plaetze verbrauchen.

Aufgabenteilung im Plugin:

    dieser Dienst      spricht mit dem Miniserver, sonst niemand
    webfrontend/html/index.php   liest den Zwischenspeicher, legt Befehle ab
    webfrontend/html/tafel.php   die Anzeigeseite fuer das Tablet
    webfrontend/htmlauth/        Einrichtung und Designer

Weder Oberflaeche noch Anzeigeseite sprechen je selbst mit dem Miniserver.

Aufrufe:
    dashboard_dienst.py                Dauerbetrieb
    dashboard_dienst.py --einmal       einmal verbinden, Abbild schreiben, Ende
    dashboard_dienst.py --selbsttest   Pruefungen ohne Miniserver
    dashboard_dienst.py --entwurf      Erstentwurf erzeugen und speichern
    dashboard_dienst.py --anmeldeprobe einmal anmelden und wieder abmelden
    dashboard_dienst.py --httpprobe    den HTTP-Notnagel an einem Baustein messen
    dashboard_dienst.py --visuprobe    das Visualisierungs-Passwort pruefen
"""

from __future__ import annotations

import asyncio
import base64
import json
import logging
import logging.handlers
import os
import re
import signal
import sys
import time
from pathlib import Path
from typing import Any

HIER = Path(__file__).resolve().parent
sys.path.insert(0, str(HIER))

from entwurf import entwurf_bauen, bausteine_sammeln          # noqa: E402
from lox_client import Miniserver, LoxFehler, selbstpruefung   # noqa: E402


def lb_wurzel_ermitteln():
    """Den LoxBerry-Wurzelordner ohne festen Systempfad bestimmen.

    Vom eigenen Ablageort aufwaerts, bis ein Verzeichnis gefunden ist, das
    config/plugins UND webfrontend enthaelt. Trifft die uebliche
    Installation genauso wie eine an einem anderen Ort.
    """
    d = os.path.dirname(os.path.abspath(__file__))
    for _ in range(8):
        if os.path.isdir(os.path.join(d, "config", "plugins")) \
                and os.path.isdir(os.path.join(d, "webfrontend")):
            return d
        eltern = os.path.dirname(d)
        if eltern == d:
            break
        d = eltern
    return ""



# ---------------------------------------------------------------------------
# Pfade - aus dem eigenen Ablageort, nicht ueber LoxBerry::System
# ---------------------------------------------------------------------------

def _home() -> str:
    h = os.environ.get("LBHOMEDIR", "")
    if h and os.path.isdir(h):
        return h
    for k in (lb_wurzel_ermitteln(), "/home/loxberry/loxberry"):
        if os.path.isdir(k):
            return k
    return str(HIER.parent.parent)


HOME = _home()
ORDNER = HIER.name if (HIER.name != "bin") else HIER.parent.name
# bin/plugins/<ordner> -> <ordner>
if HIER.parent.name == "plugins":
    ORDNER = HIER.name
else:
    ORDNER = HIER.parent.name

CONFIGDIR = os.path.join(HOME, "config", "plugins", ORDNER)
DATADIR = os.path.join(HOME, "data", "plugins", ORDNER)
LOGDIR = os.path.join(HOME, "log", "plugins", ORDNER)
TEMPLATES = os.path.join(HOME, "templates", "plugins", ORDNER)

DATEI_CONFIG = os.path.join(CONFIGDIR, "dashboard.json")
DATEI_GEHEIM = os.path.join(CONFIGDIR, "zugang.json")      # Rechte 0600
DATEI_DASHBOARD = os.path.join(CONFIGDIR, "seiten.json")
DATEI_KACHELN = os.path.join(TEMPLATES, "kacheln.json")
# Der Rueckfallweg gilt dem ARCHIV, nicht der Installation.
#
# Installiert liegt die Tabelle unter <home>/templates/plugins/<ordner>/ -
# das ist DATEI_KACHELN. Im ausgepackten Archiv dagegen liegt bin/ neben
# templates/, und dann stimmt HIER.parent/"templates". Auf einem
# installierten System zeigt dieser Pfad ins Leere
# (<home>/bin/plugins/templates/) - das ist kein Fehler, sondern der
# Normalfall fuer einen zweiten Versuch, der nicht greift.
DATEI_KACHELN_ARCHIV = str(HIER.parent / "templates" / "kacheln.json")
DATEI_STRUKTUR = os.path.join(DATADIR, "struktur.json")
DATEI_ABBILD = os.path.join(DATADIR, "abbild.json")
DATEI_ZUSTAND = os.path.join(DATADIR, "zustand.json")
DATEI_VERLAUF = os.path.join(DATADIR, "verlauf.json")
DATEI_TAFEL = os.path.join(DATADIR, "tafel.json")
DATEI_PID = os.path.join(DATADIR, "dienst.pid")
DATEI_SOLL = os.path.join(DATADIR, "soll_laufen")
ORDNER_BEFEHLE = os.path.join(DATADIR, "befehle")
ORDNER_ANTWORTEN = os.path.join(DATADIR, "antworten")
DATEI_LOG = os.path.join(LOGDIR, "dashboard.log")

# Muessen zu db_vorgaben() in webfrontend/html/db_lib.php passen. Bis 0.9.5
# fehlte hier 'haptik', obwohl der Kommentar Gleichheit zusicherte.
VORGABEN = {
    "miniserver": "1",
    "tls": 0,
    "takt": 2,               # Sekunden zwischen zwei Abbildern
    "http_rueckfall": 1,
    "http_takt": 10,
    "steuerung_ein": 1,
    "aktionstoken": "",
    "wartezeit": 8,
    "vollbild": 1,
    "wach": 1,
    "haptik": 1,
    "farbe": "dunkel",
    # Neu in 0.9.6. Alle ab Werk AUS beziehungsweise wirkungslos - eine neue
    # Funktion, die beim ersten Aufruf nach dem Update ungefragt etwas
    # aendert, ist ein Fehler (Hausregel).
    "rotation": 0,           # Sekunden bis zur naechsten Seite, 0 = aus
    "nacht_von": "",         # "22:30" - leer heisst: kein Zeitplan
    "nacht_bis": "",
    "nacht_helligkeit": 15,  # Prozent, 0 = Bildschirm schwarz
    "verlauf": 0,            # Verlaufskurve je Kachel
    "verlauf_punkte": 60,    # ein Punkt je Minute -> eine Stunde
    "sse": 0,                # Werte werden geschoben statt abgefragt
    "tafelsteuerung": 0,     # Loxone darf die Anzeigeseite umschalten
    "gesichert_schalten": 0, # gesicherte Bausteine mit Visu-Passwort schalten
}

_LOG = logging.getLogger("dashboard")


def log_einrichten(stufe=logging.INFO, nach_stdout: bool = False) -> None:
    """Protokoll einrichten.

    Der StreamHandler nach stdout gehoert AUSSCHLIESSLICH den einmaligen
    Betriebsarten (--selbsttest, --einmal, --entwurf, --anmeldeprobe): dort
    sammelt die Oberflaeche die Ausgabe per exec() ein.

    Im Dauerbetrieb darf er nicht gesetzt werden. dienst.sh leitet stdout mit
    'nohup ... >> dashboard.log' in DIESELBE Datei, in die auch der
    RotatingFileHandler schreibt. Bis 0.9.5 stand deshalb jede Zeile doppelt
    darin - und schlimmer: nach dem Umbenennen durch die Rotation schrieb der
    Shell-Deskriptor in die umbenannte Datei weiter. Nach zwei weiteren
    Rotationen loeschte backupCount=2 sie, der Deskriptor hielt sie am Leben,
    und der Platz auf der SD-Karte blieb belegt, ohne dass 'ls' etwas zeigte.
    """
    os.makedirs(LOGDIR, exist_ok=True)
    _LOG.setLevel(stufe)
    if _LOG.handlers:
        return
    h = logging.handlers.RotatingFileHandler(DATEI_LOG, maxBytes=512000, backupCount=2,
                                             encoding="utf-8")
    h.setFormatter(logging.Formatter("[%(asctime)s] %(levelname)s %(message)s",
                                     "%Y-%m-%d %H:%M:%S"))
    _LOG.addHandler(h)
    if nach_stdout:
        k = logging.StreamHandler(sys.stdout)
        k.setFormatter(logging.Formatter("%(levelname)s %(message)s"))
        _LOG.addHandler(k)


# ---------------------------------------------------------------------------
# Dateien
# ---------------------------------------------------------------------------

def json_lesen(pfad: str) -> dict:
    try:
        with open(pfad, "r", encoding="utf-8") as fh:
            d = json.load(fh)
        return d if isinstance(d, dict) else {}
    except (OSError, ValueError):
        return {}


def json_schreiben(pfad: str, daten: Any, rechte: int | None = None) -> bool:
    """Erst in eine Nebendatei, dann umbenennen - sonst liest die Oberflaeche
    irgendwann eine halb geschriebene Datei."""
    try:
        os.makedirs(os.path.dirname(pfad), exist_ok=True)
        tmp = pfad + ".tmp"
        with open(tmp, "w", encoding="utf-8") as fh:
            json.dump(daten, fh, ensure_ascii=False, indent=1)
        if rechte is not None:
            os.chmod(tmp, rechte)
        os.replace(tmp, pfad)
        return True
    except OSError as f:
        _LOG.error("Konnte %s nicht schreiben: %s", pfad, f)
        return False


def config() -> dict:
    c = dict(VORGABEN)
    c.update(json_lesen(DATEI_CONFIG))
    return c


def kacheltabelle() -> dict:
    for k in (DATEI_KACHELN, DATEI_KACHELN_ARCHIV):
        d = json_lesen(k)
        if d.get("typen"):
            return d
    return {"typen": {}, "generisch": {"kachel": "generisch", "zustaende": [], "befehle": []},
            "vorgabegroesse": {}}


# ---------------------------------------------------------------------------
# Zugangsdaten
# ---------------------------------------------------------------------------

def _vielleicht_base64(s: str) -> str:
    """LoxBerry legt Benutzer und Kennwort in der general.json base64-kodiert ab.

    Aeltere Fassungen taten das nicht. Statt zu raten, welche Fassung laeuft,
    wird geprueft: nur wenn sich der Text sauber dekodieren UND identisch
    wieder kodieren laesst, war er base64. Sonst bleibt er, wie er ist.
    """
    if not s or len(s) % 4 != 0:
        return s
    try:
        roh = base64.b64decode(s, validate=True)
        text = roh.decode("utf-8")
    except Exception:
        return s
    if not text or any(ord(c) < 32 for c in text):
        return s
    if base64.b64encode(text.encode("utf-8")).decode("ascii") != s:
        return s
    return text


def miniserver_daten(nummer: str = "1") -> dict:
    """Zugangsdaten aus der LoxBerry-Konfiguration.

    Der Anwender hat sie dort schon gepflegt - danach noch einmal zu fragen
    waere zumutbar, aber unnoetig. Wer einen anderen Zugang will, traegt ihn
    in zugang.json ein (Rechte 0600); der hat dann Vorrang.
    """
    eigen = json_lesen(DATEI_GEHEIM)
    if eigen.get("adresse") and eigen.get("benutzer"):
        return {"quelle": "eigen", "name": eigen.get("name") or "eigener Zugang",
                "adresse": str(eigen["adresse"]), "port": int(eigen.get("port") or 80),
                "benutzer": str(eigen["benutzer"]), "passwort": str(eigen.get("passwort") or "")}
    d = json_lesen(os.path.join(HOME, "config", "system", "general.json"))
    alle = d.get("Miniserver") or {}
    ms = alle.get(str(nummer)) or (list(alle.values())[0] if alle else None)
    if not isinstance(ms, dict):
        return {}
    adresse = ms.get("Ipaddress") or ms.get("IPAddress") or ""
    if not adresse:
        return {}
    return {
        "quelle": "loxberry",
        "name": ms.get("Name") or ("Miniserver " + str(nummer)),
        "adresse": str(adresse),
        "port": int(ms.get("Port") or 80),
        "benutzer": _vielleicht_base64(str(ms.get("Admin") or ms.get("Username") or "")),
        "passwort": _vielleicht_base64(str(ms.get("Pass") or ms.get("Password") or "")),
    }


def token_merken(token: str, kennung: str, hashalg: str = "") -> None:
    """Das Miniserver-Token gehoert nicht in die Konfiguration, die die
    Oberflaeche anzeigt. Eigene Datei, Rechte 0600.

    Mitgemerkt wird das Hashverfahren des Benutzers aus getkey2. Ohne das
    riet die Wiederanmeldung auf SHA256, scheiterte bei SHA1-Benutzern
    lautlos und liess sich bei jedem Dienststart ein neues Token ausstellen.
    """
    d = json_lesen(DATEI_GEHEIM)
    d["ms_token"] = token
    d["ms_kennung"] = kennung
    if hashalg:
        d["ms_hashalg"] = hashalg
    json_schreiben(DATEI_GEHEIM, d, 0o600)


def token_holen() -> tuple[str, str, str]:
    d = json_lesen(DATEI_GEHEIM)
    return (str(d.get("ms_token") or ""), str(d.get("ms_kennung") or ""),
            str(d.get("ms_hashalg") or ""))


def visu_passwort() -> str:
    """Das Visualisierungs-Passwort aus der Geheimnisdatei (Rechte 0600).

    Es steht NICHT in dashboard.json - die zeigt die Oberflaeche an. Es
    verlaesst den LoxBerry auch nicht: der Dienst bildet daraus den Hash und
    schickt nur den. Weder Endpunkt noch Anzeigeseite sehen es je.
    """
    return str(json_lesen(DATEI_GEHEIM).get("visu_pw") or "")


def baustein_finden(uuid: str, struktur: dict) -> dict:
    for b in (struktur.get("bausteine") or []):
        if b.get("uuid") == uuid:
            return b
    return {}


# ---------------------------------------------------------------------------
# Abbild fuer die Anzeigeseite
# ---------------------------------------------------------------------------

def zustands_index(struktur: dict) -> dict:
    """Zustands-UUID -> (Baustein-UUID, Name der Rolle).

    Wird EINMAL nach dem Holen der Struktur gebaut und danach wiederverwendet.
    Bis 0.9.5 lief abbild_bauen() bei jedem Takt (alle zwei Sekunden) ueber
    die volle Struktur und baute dabei jedes Baustein-Woerterbuch neu auf.
    Gemessen: 1,01 ms bei 500, 3,57 ms bei 1500, 8,24 ms bei 3000 Bausteinen
    je Aufruf, auf einem Rechner - auf einem Raspberry Pi ein Vielfaches
    davon, und alles sofort wieder weggeworfen.
    """
    index: dict[str, tuple] = {}
    for b in (struktur.get("bausteine") or []):
        for name, uuid in (b.get("zustaende") or {}).items():
            index[str(uuid)] = (str(b.get("uuid") or ""), str(name))
    return index


def abbild_bauen(ms: Miniserver, index: dict, ok: int | None = None) -> dict:
    """Aus dem Zwischenspeicher das, was die Kacheln brauchen - und nur das.

    Bewusst NICHT das ganze Rohabbild: eine Strukturdatei mit 1700 Bausteinen
    ueber die Leitung an ein Tablet zu schicken, alle zwei Sekunden, waere
    Verschwendung. Die Anzeigeseite bekommt Werte, die Struktur holt sie
    einmal.

    'ok' laesst sich uebersteuern: im HTTP-Notnagel steht 'verbunden' auf
    False (es gibt ja keinen WebSocket), die Werte sind aber frisch. Bis
    0.9.5 meldete sich der Notnagel dadurch selbst als tot, und das Tablet
    zeigte eine Stoerung, obwohl Werte ankamen.
    """
    werte: dict[str, Any] = {}
    for zuuid, wert in ms.zustaende.items():
        ziel = index.get(zuuid)
        if ziel is None:
            continue
        werte.setdefault(ziel[0], {})[ziel[1]] = wert
    return {
        "ok": (1 if ms.verbunden else 0) if ok is None else int(ok),
        "ts": int(time.time()),
        "weg": ms.weg,
        "fehler": ms.letzter_fehler,
        "werte": werte,
        "anzahl_bausteine": len(ms.struktur.get("controls") or {}),
        "anzahl_zustaende": len(ms.zustaende),
        "uebergangen": dict(getattr(ms, "uebergangen", {}) or {}),
    }


def haupt_index(struktur: dict) -> dict:
    """Zustands-UUID -> Baustein-UUID, aber NUR fuer den Hauptzustand.

    Fuer die Verlaufskurve. Alle Zustaende mitzuschreiben waere Ballast: auf
    der Kachel steht ohnehin nur einer gross.
    """
    index: dict[str, str] = {}
    for b in (struktur.get("bausteine") or []):
        haupt = str(b.get("haupt") or "")
        if not haupt:
            continue
        uuid = (b.get("zustaende") or {}).get(haupt)
        if uuid:
            index[str(uuid)] = str(b.get("uuid") or "")
    return index


def verlauf_fortschreiben(ms: Miniserver, index: dict, punkte: int) -> None:
    """Je Baustein einen Zahlenwert anhaengen, aelteste verwerfen.

    Wird einmal je Minute aufgerufen, nicht je Takt: 1.440 Schreibvorgaenge am
    Tag statt 43.200. Nur Zahlen - eine Kurve aus Texten gibt es nicht.
    """
    punkte = max(10, min(240, int(punkte or 60)))
    d = json_lesen(DATEI_VERLAUF)
    reihen = d.get("reihen") if isinstance(d.get("reihen"), dict) else {}
    for zuuid, buuid in index.items():
        wert = ms.zustaende.get(zuuid)
        if not isinstance(wert, (int, float)) or isinstance(wert, bool):
            continue
        reihe = reihen.get(buuid)
        if not isinstance(reihe, list):
            reihe = []
        reihe.append(round(float(wert), 3))
        reihen[buuid] = reihe[-punkte:]
    json_schreiben(DATEI_VERLAUF, {"ts": int(time.time()), "punkte": punkte,
                                   "reihen": reihen})


def struktur_ablegen(ms: Miniserver, tabelle: dict) -> None:
    """Die aufbereitete Struktur - einmal geschrieben, oft gelesen."""
    b = bausteine_sammeln(ms.struktur, tabelle)
    json_schreiben(DATEI_STRUKTUR, {
        "ts": int(time.time()),
        "lastModified": str(ms.struktur.get("lastModified") or ""),
        "msinfo": ms.struktur.get("msInfo") or {},
        "raeume": {k: {"name": v.get("name"), "bewertung": v.get("defaultRating")}
                   for k, v in (ms.struktur.get("rooms") or {}).items()},
        "kategorien": {k: {"name": v.get("name"), "typ": v.get("type")}
                       for k, v in (ms.struktur.get("cats") or {}).items()},
        # Die Klartexte zu den Wetterlagen. [S, weatherTypeTexts]: "Each
        # forecast and the actual weather situation has a type that is
        # visualized differently. This section gives the user friendly texts
        # for each of this weather situations." Ohne sie stuende auf der
        # Kachel eine nackte Zahl.
        "wettertexte": ms.struktur.get("weatherTypeTexts") or {},
        "bausteine": b,
    })


# ---------------------------------------------------------------------------
# Befehlswarteschlange
# ---------------------------------------------------------------------------

def befehl_erlaubt(uuid: str, befehl: str, struktur: dict) -> tuple[bool, str]:
    """Zweite Pruefung - die erste macht der Endpunkt.

    Erlaubt ist nur, was die Kacheltabelle fuer genau diesen Bausteintyp
    nennt. Ein Befehl, der fuer einen Dimmer richtig waere, ist an einer
    Alarmanlage falsch, und ein geratener Befehl an eine Alarmanlage ist
    schlimmer als ein fehlender Knopf.
    """
    for b in struktur.get("bausteine") or []:
        if b.get("uuid") != uuid:
            continue
        if b.get("nurlesen"):
            return False, "Dieser Baustein ist in Loxone auf 'nur lesen' gesetzt."
        erlaubt = b.get("befehle") or []
        if not erlaubt:
            return False, ("Fuer den Typ %s kennt das Plugin keinen Befehl. "
                           "Geraten wird hier nicht." % b.get("loxtyp"))
        for e in erlaubt:
            if e == befehl:
                return True, ""
            # Benannte Formen - dieselben wie in db_lib.php, und aus demselben
            # Grund hier ausgeschrieben statt als Ausdruck in kacheln.json.
            if e == "$hsv":
                m = re.match(r"^hsv\((\d{1,3}),(\d{1,3}),(\d{1,3})\)$", befehl)
                if m and int(m.group(1)) <= 360 and int(m.group(2)) <= 100 \
                        and int(m.group(3)) <= 100:
                    return True, ""
                continue
            # temp(Helligkeit,Kelvin) und lumitech(Helligkeit,Kelvin) - dieselbe
            # Form, zwei Namen: ColorPickerV2 nimmt temp, der aeltere
            # ColorPicker lumitech. Beides belegt in [S].
            if e in ("$temp", "$lumitech"):
                wort = "temp" if e == "$temp" else "lumitech"
                m = re.match(r"^%s\((\d{1,3}),(\d{4,5})\)$" % wort, befehl)
                if m and int(m.group(1)) <= 100 \
                        and 1000 <= int(m.group(2)) <= 12000:
                    return True, ""
                continue
            if e.startswith("$"):
                # reiner Wert, etwa beim Dimmer
                try:
                    float(befehl)
                    return True, ""
                except ValueError:
                    continue
            if e.endswith("/$wert"):
                # Muster wie "jalousie/$wert": alles vor dem Platzhalter muss
                # woertlich stimmen, der Rest muss eine Zahl sein.
                #
                # Bis 0.9.0 stand hier e[:-5] und befehl[len(e)-5:]. Das ging
                # gut, aber nur zufaellig: '/$wert' ist SECHS Zeichen lang,
                # abgeschnitten wurden fuenf - der Schraegstrich blieb also
                # stehen und gehoerte zum Vergleichsstueck. Wer den Platzhalter
                # spaeter in '$w' oder '/$value' aendert, bekommt eine stille
                # Fehlfunktion. Jetzt wird geteilt statt gerechnet.
                vorspann = e[:-len("$wert")]
                if not befehl.startswith(vorspann):
                    continue
                rest = befehl[len(vorspann):]
                try:
                    float(rest)
                    return True, ""
                except ValueError:
                    continue
        return False, "Der Befehl '%s' ist fuer den Typ %s nicht vorgesehen." % (
            befehl, b.get("loxtyp"))
    return False, "Diesen Baustein gibt es in der Struktur nicht."


async def warteschlange(ms: Miniserver, struktur: dict) -> None:
    """Auftraege abarbeiten.

    Ein Auftrag ist entweder ein einzelner Befehl ({uuid, befehl}) oder eine
    Szene ({befehle: [{uuid, befehl}, ...]}) - mehrere Schaltbefehle auf einen
    Druck. Geprueft wird JEDER Einzelbefehl gegen dieselbe Positivliste; eine
    Szene ist keine Abkuerzung an der Pruefung vorbei.
    """
    try:
        namen = sorted(os.listdir(ORDNER_BEFEHLE))
    except OSError:
        return
    for name in namen:
        if not name.endswith(".json"):
            continue
        pfad = os.path.join(ORDNER_BEFEHLE, name)
        auftrag = json_lesen(pfad)
        try:
            os.remove(pfad)
        except OSError:
            pass
        kennung = name[:-5]
        antwortdatei = os.path.join(ORDNER_ANTWORTEN, kennung + ".json")

        schritte = auftrag.get("befehle")
        if not isinstance(schritte, list) or not schritte:
            schritte = [{"uuid": auftrag.get("uuid"), "befehl": auftrag.get("befehl")}]

        cfg = config()
        erledigt, meldungen, gesamt_ok = 0, [], 1
        for schritt in schritte:
            uuid = str((schritt or {}).get("uuid") or "")
            befehl = str((schritt or {}).get("befehl") or "")
            ok, grund = befehl_erlaubt(uuid, befehl, struktur)
            if not ok:
                _LOG.warning("Befehl abgewiesen: %s %s - %s", uuid, befehl, grund)
                meldungen.append(grund)
                gesamt_ok = 0
                continue
            # Gesicherte Bausteine: nur mit ausdruecklich eingeschalteter
            # Erlaubnis UND hinterlegtem Visualisierungs-Passwort. Beides
            # fehlt ab Werk. Das ist die zweite Pruefung - der Endpunkt hat
            # dasselbe schon geprueft, aber hier geht es wirklich hinaus.
            visu = ""
            if baustein_finden(uuid, struktur).get("gesichert"):
                if not int(cfg.get("gesichert_schalten") or 0):
                    grund = ("Dieser Baustein ist gesichert. Das Schalten "
                             "gesicherter Bausteine ist im Reiter Einstellungen "
                             "abgeschaltet.")
                    _LOG.warning("Befehl abgewiesen: %s %s - %s", uuid, befehl, grund)
                    meldungen.append(grund)
                    gesamt_ok = 0
                    continue
                visu = visu_passwort()
                if not visu:
                    grund = ("Dieser Baustein ist gesichert, aber es ist kein "
                             "Visualisierungs-Passwort hinterlegt.")
                    _LOG.warning("Befehl abgewiesen: %s %s - %s", uuid, befehl, grund)
                    meldungen.append(grund)
                    gesamt_ok = 0
                    continue
            try:
                antwort = await ms.befehl_senden(uuid, befehl, visu_pw=visu)
                _LOG.info("Befehl ausgefuehrt: %s %s", uuid, befehl)
                erledigt += 1
                meldungen.append(str(antwort))
            except (LoxFehler, asyncio.TimeoutError, OSError) as f:
                _LOG.warning("Befehl misslungen: %s %s - %s", uuid, befehl, f)
                meldungen.append(str(f))
                gesamt_ok = 0

        if len(schritte) > 1:
            # Bei einer Szene wird gesagt, wie viele Schritte durchgingen -
            # "ok" oder "Fehler" allein waere bei 7 von 9 eine Falschaussage.
            text = "%d von %d Schritten ausgefuehrt. %s" % (
                erledigt, len(schritte), " | ".join(meldungen[:6]))
        else:
            text = meldungen[0] if meldungen else ""
        json_schreiben(antwortdatei, {"ok": gesamt_ok, "meldung": text,
                                      "erledigt": erledigt, "schritte": len(schritte)})


def antworten_aufraeumen(alter: int = 120) -> None:
    jetzt = time.time()
    try:
        for n in os.listdir(ORDNER_ANTWORTEN):
            p = os.path.join(ORDNER_ANTWORTEN, n)
            if jetzt - os.path.getmtime(p) > alter:
                os.remove(p)
    except OSError:
        pass


# ---------------------------------------------------------------------------
# Dauerbetrieb
# ---------------------------------------------------------------------------

class Dienst:
    def __init__(self) -> None:
        self.laeuft = True
        self.ms: Miniserver | None = None

    def anhalten(self, *_a) -> None:
        self.laeuft = False

    def _bauen(self, cfg: dict) -> tuple[Miniserver, str]:
        """Ein Client-Objekt aus der Konfiguration - ohne zu verbinden."""
        d = miniserver_daten(str(cfg.get("miniserver") or "1"))
        if not d:
            raise LoxFehler(
                "In der LoxBerry-Konfiguration steht kein Miniserver. "
                "Unter System, Miniserver eintragen - oder eigene Zugangsdaten "
                "im Reiter Einstellungen hinterlegen.")
        if not d.get("benutzer"):
            raise LoxFehler("Fuer den Miniserver '%s' ist kein Benutzer hinterlegt."
                            % d.get("name"))
        altes, kennung, alg = token_holen()
        ms = Miniserver(d["adresse"], d["port"], d["benutzer"], d["passwort"],
                        tls=bool(int(cfg.get("tls") or 0)), kennung=kennung,
                        hashalg=alg)
        return ms, altes

    async def einmal_verbinden(self, cfg: dict, tabelle: dict) -> Miniserver:
        """Verbinden, anmelden, Struktur holen, Zustaende einschalten.

        JEDER Fehlerweg schliesst hinter sich zu. Bis 0.9.5 fehlte das: ein
        401 beim Token liess den WebSocket samt Leser-Task stehen, und die
        Warteschleife legte alle 5/10/20/40/80/120 s eine weitere Leiche an.
        Der Miniserver laesst nur 31 Clients gleichzeitig Zustaende empfangen.
        """
        ms, altes = self._bauen(cfg)
        try:
            await ms.verbinden(altes_token=altes)
            token_merken(str(ms.token.get("token") or altes), ms.kennung, ms.hashalg)
            await ms.struktur_holen()
            struktur_ablegen(ms, tabelle)
            await ms.zustaende_anfordern()
        except BaseException:
            await ms.schliessen()
            raise
        return ms

    async def laufen(self) -> int:
        cfg = config()
        tabelle = kacheltabelle()
        os.makedirs(ORDNER_BEFEHLE, exist_ok=True)
        os.makedirs(ORDNER_ANTWORTEN, exist_ok=True)
        takt = max(1, min(30, int(cfg.get("takt") or 2)))
        wartezeit = 5

        while self.laeuft:
            ms = None
            try:
                ms = await self.einmal_verbinden(cfg, tabelle)
                self.ms = ms
                struktur = json_lesen(DATEI_STRUKTUR)
                index = zustands_index(struktur)
                hindex = haupt_index(struktur)
                letzter_verlauf = 0.0
                _LOG.info("Verbunden. %d Bausteine, %d Zustaende.",
                          len(struktur.get("bausteine") or []), len(ms.zustaende))
                wartezeit = 5
                letzte_pruefung = 0.0
                letztes_abbild = ""
                letztes_schreiben = 0.0
                letzter_zustand = 0.0
                while self.laeuft and ms.verbunden:
                    await warteschlange(ms, struktur)
                    # Nur schreiben, wenn sich etwas geaendert hat. Bei einem
                    # Takt von 2 s waeren es sonst rund 86.000 Schreibvorgaenge
                    # am Tag auf die SD-Karte - fuer Werte, die sich nachts
                    # stundenlang nicht ruehren.
                    abbild = abbild_bauen(ms, index)
                    fingerabdruck = json.dumps(abbild["werte"], sort_keys=True)
                    # Alle 30 s einmal schreiben, auch ohne Aenderung - sonst
                    # haelt die Anzeigeseite die Werte fuer veraltet.
                    if fingerabdruck != letztes_abbild \
                            or time.time() - letztes_schreiben > 30:
                        letztes_abbild = fingerabdruck
                        letztes_schreiben = time.time()
                        json_schreiben(DATEI_ABBILD, abbild)
                    if int(cfg.get("verlauf") or 0) and time.time() - letzter_verlauf > 60:
                        letzter_verlauf = time.time()
                        verlauf_fortschreiben(ms, hindex, cfg.get("verlauf_punkte"))
                    if time.time() - letzter_zustand > 15:
                        letzter_zustand = time.time()
                        json_schreiben(DATEI_ZUSTAND, {
                            "ok": 1, "ts": int(time.time()), "weg": ms.weg,
                            "fehler": "", "miniserver": ms.host,
                            "bausteine": len(struktur.get("bausteine") or []),
                            "zustaende": len(ms.zustaende),
                            "msinfo": struktur.get("msinfo") or {},
                            "uebergangen": dict(ms.uebergangen)})
                    if time.time() - letzte_pruefung > 60:
                        letzte_pruefung = time.time()
                        try:
                            await ms.keepalive()
                        except Exception:
                            break
                        # Hat sich die Konfiguration im Miniserver geaendert?
                        try:
                            neu = await ms._befehl("jdev/sps/LoxAPPversion3")
                            alt = str(ms.struktur.get("lastModified") or "")
                            if neu and str(neu) != alt:
                                _LOG.info("Die Loxone-Konfiguration hat sich geaendert "
                                          "(%s -> %s). Struktur wird neu geholt.", alt, neu)
                                await ms.struktur_holen()
                                struktur_ablegen(ms, tabelle)
                                struktur = json_lesen(DATEI_STRUKTUR)
                                index = zustands_index(struktur)
                        except Exception as f:
                            _LOG.info("Versionsabfrage misslungen: %s", f)
                            # Eine Zeitueberschreitung heisst: die Zuordnung
                            # von Befehl und Antwort ist nicht mehr sicher.
                            # Lieber neu verbinden als raten.
                            break
                    antworten_aufraeumen()
                    await asyncio.sleep(takt)
            except asyncio.CancelledError:
                if ms is not None:
                    await ms.schliessen()
                raise
            except Exception as f:
                _LOG.error("%s", f)
                json_schreiben(DATEI_ZUSTAND, {"ok": 0, "ts": int(time.time()),
                                               "weg": "", "fehler": str(f)})
                if int(cfg.get("http_rueckfall") or 0):
                    await self.http_weg(cfg, tabelle)
            finally:
                # Immer schliessen - auch nach einem Fehler. Sonst bleibt der
                # WebSocket offen und belegt einen der 31 Plaetze.
                if ms is not None:
                    await ms.schliessen()
                self.ms = None
            if not self.laeuft:
                break
            _LOG.info("Neuer Versuch in %d s.", wartezeit)
            for _ in range(wartezeit * 2):
                if not self.laeuft:
                    break
                await asyncio.sleep(0.5)
            wartezeit = min(120, wartezeit * 2)
        return 0

    async def http_weg(self, cfg: dict, tabelle: dict) -> None:
        """Notnagel: Zustaende einzeln ueber HTTP holen.

        Das belastet den Miniserver mit einer Anfrage je Baustein und Takt.
        Deshalb ist der Takt hier von Haus aus deutlich langsamer, und es wird
        laut ins Log geschrieben - damit niemand den Notnagel fuer den
        Regelbetrieb haelt.

        Vier Dinge waren bis 0.9.5 falsch:

        1. Der Weg setzte 'self.ms' voraus, das erst NACH einer erfolgreichen
           WebSocket-Verbindung gesetzt wird. In genau den Faellen, fuer die
           der Notnagel gedacht ist, griff er also nicht. Jetzt baut er sich
           seinen eigenen Client aus dem gespeicherten Token.
        2. Der Zaehler 'fehler' wurde nie zurueckgesetzt; sechs Bausteine ohne
           Zustaende brachen den ganzen Weg mit "Auch der HTTP-Weg antwortet
           nicht mehr" ab, obwohl der Miniserver einwandfrei antwortete.
        3. Die Aufrufe blockierten die Ereignisschleife. Jetzt laufen sie in
           einem Nebenlaeufer.
        4. Das Abbild meldete 'ok: 0', obwohl frische Werte ankamen - das
           Tablet zeigte eine Stoerung an, waehrend es Werte bekam.
        """
        token, kennung, alg = token_holen()
        if not token:
            _LOG.info("Kein gespeichertes Token - der HTTP-Notnagel entfaellt.")
            return
        try:
            ms, _ = self._bauen(cfg)
        except LoxFehler as f:
            _LOG.info("HTTP-Notnagel nicht moeglich: %s", f)
            return
        ms.token = {"token": token}
        ms.hashalg = alg or "SHA256"
        struktur = json_lesen(DATEI_STRUKTUR)
        index = zustands_index(struktur)
        # Nur Bausteine, die ueberhaupt einen Zustand haben.
        paare = [(b["uuid"], list((b.get("zustaende") or {}).values())[0])
                 for b in (struktur.get("bausteine") or [])
                 if (b.get("zustaende") or {})]
        if not paare:
            _LOG.info("Keine Struktur zwischengespeichert - der HTTP-Notnagel entfaellt.")
            return
        takt = max(5, min(120, int(cfg.get("http_takt") or 10)))
        _LOG.warning("Der WebSocket steht nicht. Es wird auf HTTP-Abfrage "
                     "zurueckgefallen: %d Bausteine alle %d s. Das ist der Notnagel, "
                     "nicht der Regelweg.", len(paare), takt)
        ms.weg = "http"
        schleife = asyncio.get_running_loop()
        ende = time.time() + 60
        while self.laeuft and time.time() < ende:
            try:
                marke = await schleife.run_in_executor(None, ms.http_marke)
            except Exception as f:
                _LOG.warning("Der HTTP-Weg bekommt keine Beglaubigung: %s", f)
                return
            fehler = 0
            for buuid, zuuid in paare:
                if not self.laeuft:
                    break
                try:
                    ms.zustaende[zuuid] = await schleife.run_in_executor(
                        None, ms.http_zustand, buuid, marke)
                except Exception:
                    fehler += 1
                    if fehler > 5:
                        _LOG.warning("Auch der HTTP-Weg antwortet nicht mehr "
                                     "(%d Fehlversuche hintereinander).", fehler)
                        return
                else:
                    fehler = 0
            json_schreiben(DATEI_ABBILD, abbild_bauen(ms, index, ok=1))
            json_schreiben(DATEI_ZUSTAND, {
                "ok": 1, "ts": int(time.time()), "weg": "http",
                "fehler": "Notnagel: der WebSocket steht nicht.",
                "miniserver": ms.host,
                "bausteine": len(paare), "zustaende": len(ms.zustaende)})
            await asyncio.sleep(takt)


# ---------------------------------------------------------------------------
# Betriebsarten
# ---------------------------------------------------------------------------

async def einmal() -> int:
    cfg, tabelle = config(), kacheltabelle()
    d = Dienst()
    ms = None
    try:
        ms = await d.einmal_verbinden(cfg, tabelle)
        await asyncio.sleep(2)          # den ersten Schwall Zustaende abwarten
        struktur = json_lesen(DATEI_STRUKTUR)
        json_schreiben(DATEI_ABBILD, abbild_bauen(ms, zustands_index(struktur)))
        json_schreiben(DATEI_ZUSTAND, {"ok": 1, "ts": int(time.time()), "weg": ms.weg,
                                       "fehler": "", "miniserver": ms.host,
                                       "bausteine": len(struktur.get("bausteine") or []),
                                       "zustaende": len(ms.zustaende),
                                       "msinfo": struktur.get("msinfo") or {},
                                       "uebergangen": dict(ms.uebergangen)})
        print("Struktur und Abbild geschrieben: %d Bausteine, %d Zustaende."
              % (len(struktur.get("bausteine") or []), len(ms.zustaende)))
        return 0
    except Exception as f:
        print("Fehlgeschlagen: %s" % f)
        json_schreiben(DATEI_ZUSTAND, {"ok": 0, "ts": int(time.time()), "fehler": str(f)})
        return 1
    finally:
        if ms is not None:
            await ms.schliessen()


async def anmeldeprobe() -> int:
    """Einmal anmelden, das Ergebnis nennen, wieder abmelden.

    Der Knopf im Reiter Test. Er beantwortet genau eine Frage - kommen die
    Zugangsdaten durch? - ohne den Dienst zu starten und ohne etwas zu
    schalten. Er schliesst hinter sich zu; ein Prueflauf, der einen der 31
    Plaetze liegen laesst, waere schlimmer als keiner.
    """
    cfg = config()
    d = Dienst()
    ms = None
    t0 = time.time()
    try:
        ms, altes = d._bauen(cfg)
        print("Miniserver %s:%d, Benutzer '%s'" % (ms.host, ms.port, ms.benutzer))
        print("Gespeichertes Token: %s" % ("vorhanden" if altes else "keines"))
        await ms.verbinden(altes_token=altes)
        token_merken(str(ms.token.get("token") or altes), ms.kennung, ms.hashalg)
        print("[OK]   Anmeldung durchgegangen (%.1f s), Hashverfahren %s"
              % (time.time() - t0, ms.hashalg or "unbekannt"))
        try:
            stand = await ms._befehl("jdev/sps/LoxAPPversion3", zeit=15)
            print("[OK]   Stand der Loxone-Konfiguration: %s" % stand)
        except Exception as f:
            print("[FEHL] Die Versionsabfrage kam nicht durch: %s" % f)
            return 1
        return 0
    except Exception as f:
        print("[FEHL] %s" % f)
        return 1
    finally:
        if ms is not None:
            await ms.schliessen()
            print("Verbindung wieder geschlossen.")


async def visuprobe() -> int:
    """Das Visualisierungs-Passwort pruefen, ohne etwas zu schalten.

    Loxone bietet dafuer einen eigenen Dienst an
    ("jdev/sps/checkuservisupwd/{hash}", [K, Seite 15]) - deshalb muss hier
    nichts an einem echten Baustein ausprobiert werden. Kein Geraet bewegt
    sich, keine Alarmanlage wird scharf.
    """
    cfg = config()
    d = Dienst()
    ms = None
    pw = visu_passwort()
    if not pw:
        print("[FEHL] Es ist kein Visualisierungs-Passwort hinterlegt.")
        print("       Reiter Einstellungen, Abschnitt Miniserver.")
        return 1
    try:
        ms, altes = d._bauen(cfg)
        await ms.verbinden(altes_token=altes)
        token_merken(str(ms.token.get("token") or altes), ms.kennung, ms.hashalg)
        ok = await ms.visu_pruefen(pw)
        if ok:
            print("[OK]   Das Visualisierungs-Passwort stimmt.")
            print("       Gesicherte Bausteine lassen sich damit schalten, sobald")
            print("       der Haken im Reiter Einstellungen gesetzt ist.")
            return 0
        print("[FEHL] Der Miniserver weist das Visualisierungs-Passwort ab.")
        print("       Geprueft wurde ueber jdev/sps/checkuservisupwd - es wurde")
        print("       dabei nichts geschaltet.")
        return 1
    except Exception as f:
        print("[FEHL] %s" % f)
        return 1
    finally:
        if ms is not None:
            await ms.schliessen()


async def httpprobe() -> int:
    """Den HTTP-Notnagel EINMAL an einem Baustein ausprobieren.

    Grund: dass 'jdev/sps/io/{uuid}/state' ein gueltiger Befehl ist, steht in
    keinem der beiden Loxone-Dokumente. Statt das im Quelltext zu behaupten,
    wird es hier an der eigenen Anlage gemessen.
    """
    cfg = config()
    d = Dienst()
    token, kennung, alg = token_holen()
    if not token:
        print("[FEHL] Kein gespeichertes Token. Erst 'Anmeldung jetzt pruefen'.")
        return 1
    struktur = json_lesen(DATEI_STRUKTUR)
    paare = [(b["uuid"], b.get("name"), list((b.get("zustaende") or {}).values())[0])
             for b in (struktur.get("bausteine") or []) if (b.get("zustaende") or {})]
    if not paare:
        print("[FEHL] Keine Struktur zwischengespeichert. Erst 'Struktur holen'.")
        return 1
    try:
        ms, _ = d._bauen(cfg)
    except LoxFehler as f:
        print("[FEHL] %s" % f)
        return 1
    ms.token = {"token": token}
    ms.hashalg = alg or "SHA256"
    buuid, name, _z = paare[0]
    try:
        marke = ms.http_marke()
        wert = ms.http_zustand(buuid, marke)
        print("[OK]   HTTP-Abfrage an '%s' beantwortet: %r" % (name, wert))
        print("       Der Notnagel traegt an dieser Anlage.")
        return 0
    except Exception as f:
        print("[FEHL] HTTP-Abfrage an '%s' misslungen: %s" % (name, f))
        print("       Der Notnagel traegt hier NICHT. Im Reiter Einstellungen")
        print("       abschalten, dann sucht der Dienst nicht vergeblich.")
        return 1


async def entwurf_erzeugen(von_vorn: bool = False) -> int:
    tabelle = kacheltabelle()
    struktur = json_lesen(DATEI_STRUKTUR)
    if not struktur.get("bausteine"):
        # Ohne Struktur laesst sich nichts entwerfen - dann erst verbinden.
        if await einmal() != 0:
            return 1
        struktur = json_lesen(DATEI_STRUKTUR)
    roh = {"controls": {}, "rooms": {}, "cats": {}, "lastModified": struktur.get("lastModified")}
    for b in struktur["bausteine"]:
        roh["controls"][b["uuid"]] = {
            "name": b["name"], "type": b["loxtyp"], "uuidAction": b["uuid"],
            "room": b["raum"], "cat": b["kategorie"], "defaultRating": b["bewertung"],
            "isFavorite": bool(b["favorit"]), "isSecured": bool(b["gesichert"]),
            "states": b["zustaende"], "details": b.get("details") or {}}
    for k, v in (struktur.get("raeume") or {}).items():
        roh["rooms"][k] = {"name": v.get("name"), "defaultRating": v.get("bewertung") or 0}
    for k, v in (struktur.get("kategorien") or {}).items():
        roh["cats"][k] = {"name": v.get("name")}
    alt = None if von_vorn else json_lesen(DATEI_DASHBOARD)
    neu = entwurf_bauen(roh, tabelle, alt)
    json_schreiben(DATEI_DASHBOARD, neu)
    print("Entwurf gespeichert: %d Seiten, %d Kacheln."
          % (len(neu["seiten"]), sum(len(s["kacheln"]) for s in neu["seiten"])))
    return 0


def selbsttest() -> int:
    zeilen: list[tuple[int, str]] = []
    zeilen.append((1, "Python %s" % sys.version.split()[0]))
    for paket in ("websockets", "cryptography"):
        try:
            __import__(paket)
            zeilen.append((1, "Paket %s geladen" % paket))
        except ImportError:
            zeilen.append((0, "Paket %s fehlt - ohne das geht nichts. "
                              "Plugin neu installieren." % paket))
    for name, p in (("Konfiguration", CONFIGDIR), ("Daten", DATADIR), ("Log", LOGDIR)):
        os.makedirs(p, exist_ok=True)
        zeilen.append((1 if os.access(p, os.W_OK) else 0,
                       "Ordner %s beschreibbar: %s" % (name, p)))
    d = miniserver_daten(str(config().get("miniserver") or "1"))
    if not d:
        zeilen.append((0, "Kein Miniserver gefunden. Unter System, Miniserver eintragen."))
    else:
        zeilen.append((1, "Miniserver '%s' auf %s:%d, Zugangsdaten aus %s"
                       % (d["name"], d["adresse"], d["port"],
                          "der LoxBerry-Konfiguration" if d["quelle"] == "loxberry"
                          else "der eigenen Datei")))
        zeilen.append((1 if d.get("benutzer") else 0,
                       "Benutzername hinterlegt" if d.get("benutzer")
                       else "Kein Benutzername hinterlegt"))
        zeilen.append((1 if d.get("passwort") else -1,
                       "Kennwort hinterlegt" if d.get("passwort")
                       else "Kein Kennwort hinterlegt - das geht nur bei einem "
                            "Benutzer ohne Kennwort"))
    t = kacheltabelle()
    zeilen.append((1 if t.get("typen") else 0,
                   "Kacheltabelle: %d eigene Typen" % len(t.get("typen") or {})))
    s = json_lesen(DATEI_STRUKTUR)
    zeilen.append((1 if s.get("bausteine") else -1,
                   "Struktur zwischengespeichert: %d Bausteine, Stand %s"
                   % (len(s.get("bausteine") or []), s.get("lastModified") or "-")
                   if s.get("bausteine") else
                   "Noch keine Struktur geholt - Dienst starten oder 'Jetzt holen'"))
    db = json_lesen(DATEI_DASHBOARD)
    zeilen.append((1 if db.get("seiten") else -1,
                   "Dashboard: %d Seiten, %d Kacheln"
                   % (len(db.get("seiten") or []),
                      sum(len(x.get("kacheln") or []) for x in (db.get("seiten") or [])))
                   if db.get("seiten") else "Noch kein Dashboard - Entwurf erzeugen"))

    z = json_lesen(DATEI_ZUSTAND)
    ueb = z.get("uebergangen") if isinstance(z.get("uebergangen"), dict) else {}
    if ueb.get("wetter") or ueb.get("tageszeit"):
        zeilen.append((-1, "Vom Miniserver kamen %d Wetter- und %d Tageszeittabellen. "
                           "Sie werden bewusst nicht ausgewertet - siehe README."
                       % (int(ueb.get("wetter") or 0), int(ueb.get("tageszeit") or 0))))
    _t, _k, alg = token_holen()
    zeilen.append((1 if alg else -1,
                   "Hashverfahren des Benutzers gemerkt: %s" % alg if alg
                   else "Hashverfahren noch nicht gemerkt - beim ersten Anmelden "
                        "wird es aus getkey2 uebernommen"))

    print("Selbsttest des Dashboard-Dienstes")
    fehlt = 0
    for stand, text in zeilen:
        print({1: "[OK]   ", 0: "[FEHL] ", -1: "[INFO] "}[stand] + text)
        fehlt += 1 if stand == 0 else 0
    print()
    # Einmal aufrufen, nicht zweimal: die Pruefungen stellen einen
    # Verbindungsschluss und eine verspaetete Antwort nach und wuerden ihre
    # Protokollzeilen sonst doppelt schreiben. Waehrenddessen wird der
    # Protokollkanal des Clients stillgelegt - diese Meldungen gehoeren zum
    # Pruefaufbau, nicht zum Betrieb.
    _leise = logging.getLogger("dashboard.lox")
    _stufe = _leise.level
    _leise.setLevel(logging.WARNING)
    try:
        pruefungen = selbstpruefung()
    finally:
        _leise.setLevel(_stufe)
    print("Bausteine des Miniserver-Clients, einzeln geprueft (%d Pruefungen):"
          % len(pruefungen))
    for ok, text in pruefungen:
        print(("[OK]   " if ok else "[FEHL] ") + text)
        fehlt += 0 if ok else 1
    print()
    print("Nicht geprueft, weil dafuer ein echter Miniserver noetig ist:")
    print("  - ob die Token-Anmeldung an Ihrer Firmware durchgeht")
    print("    (dafuer gibt es den Knopf 'Anmeldung jetzt pruefen')")
    print("  - ob 'jdev/sps/io/<uuid>/state' als HTTP-Notnagel traegt")
    print("    (dafuer gibt es den Knopf 'HTTP-Notnagel messen')")
    print("  - ob die Kachel-Befehle am Geraet die erwartete Wirkung haben")
    print("  - wie schnell die Anzeige bei Ihrer Anzahl Bausteine nachzieht")
    return 1 if fehlt else 0


def main() -> int:
    einmalig = ("--selbsttest", "--einmal", "--entwurf", "--anmeldeprobe",
                "--httpprobe", "--visuprobe")
    log_einrichten(nach_stdout=any(a in sys.argv for a in einmalig))
    if "--selbsttest" in sys.argv:
        return selbsttest()
    if "--einmal" in sys.argv:
        return asyncio.run(einmal())
    if "--entwurf" in sys.argv:
        return asyncio.run(entwurf_erzeugen("--von-vorn" in sys.argv))
    if "--anmeldeprobe" in sys.argv:
        return asyncio.run(anmeldeprobe())
    if "--httpprobe" in sys.argv:
        return asyncio.run(httpprobe())
    if "--visuprobe" in sys.argv:
        return asyncio.run(visuprobe())

    os.makedirs(DATADIR, exist_ok=True)
    with open(DATEI_PID, "w", encoding="utf-8") as fh:
        fh.write(str(os.getpid()))
    d = Dienst()
    signal.signal(signal.SIGTERM, d.anhalten)
    signal.signal(signal.SIGINT, d.anhalten)
    _LOG.info("Dienst gestartet (PID %d).", os.getpid())
    try:
        return asyncio.run(d.laufen())
    finally:
        _LOG.info("Dienst beendet.")
        try:
            os.remove(DATEI_PID)
        except OSError:
            pass


if __name__ == "__main__":
    sys.exit(main())
