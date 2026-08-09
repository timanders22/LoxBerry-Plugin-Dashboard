#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Dashboard-Designer - der Dienst.

Er haelt EINE Verbindung zum Miniserver und bedient damit beliebig viele
Tablets. Das ist der ganze Grund, warum es diesen Dienst gibt: der Miniserver
laesst nur 31 Clients gleichzeitig Zustaende empfangen
["Communicating with the Miniserver" 16.0, Seite 9], und jedes Tablet, das
selbst eine Verbindung aufmachte, wuerde einen dieser Plaetze verbrauchen.

Aufgabenteilung im Plugin:

    dieser Dienst      spricht mit dem Miniserver, sonst niemand
    webfrontend/html/index.php   liest den Zwischenspeicher, legt Befehle ab
    webfrontend/html/tafel.php   die Anzeigeseite fuer das Tablet
    webfrontend/htmlauth/        Einrichtung und Designer

Weder Oberflaeche noch Anzeigeseite sprechen je selbst mit dem Miniserver.

Aufrufe:
    dashboard_dienst.py             Dauerbetrieb
    dashboard_dienst.py --einmal    einmal verbinden, Abbild schreiben, Ende
    dashboard_dienst.py --selbsttest
    dashboard_dienst.py --entwurf   Erstentwurf erzeugen und speichern
"""

from __future__ import annotations

import asyncio
import base64
import json
import logging
import logging.handlers
import os
import signal
import sys
import time
from pathlib import Path
from typing import Any

HIER = Path(__file__).resolve().parent
sys.path.insert(0, str(HIER))

from entwurf import entwurf_bauen, bausteine_sammeln          # noqa: E402
from lox_client import Miniserver, LoxFehler, selbstpruefung   # noqa: E402


# ---------------------------------------------------------------------------
# Pfade - aus dem eigenen Ablageort, nicht ueber LoxBerry::System
# ---------------------------------------------------------------------------

def _home() -> str:
    h = os.environ.get("LBHOMEDIR", "")
    if h and os.path.isdir(h):
        return h
    for k in ("/opt/loxberry", "/home/loxberry/loxberry"):
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
DATEI_PID = os.path.join(DATADIR, "dienst.pid")
DATEI_SOLL = os.path.join(DATADIR, "soll_laufen")
ORDNER_BEFEHLE = os.path.join(DATADIR, "befehle")
ORDNER_ANTWORTEN = os.path.join(DATADIR, "antworten")
DATEI_LOG = os.path.join(LOGDIR, "dashboard.log")

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
    "farbe": "dunkel",
}

_LOG = logging.getLogger("dashboard")


def log_einrichten(stufe=logging.INFO) -> None:
    os.makedirs(LOGDIR, exist_ok=True)
    _LOG.setLevel(stufe)
    if _LOG.handlers:
        return
    h = logging.handlers.RotatingFileHandler(DATEI_LOG, maxBytes=512000, backupCount=2,
                                             encoding="utf-8")
    h.setFormatter(logging.Formatter("[%(asctime)s] %(levelname)s %(message)s",
                                     "%Y-%m-%d %H:%M:%S"))
    _LOG.addHandler(h)
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


def token_merken(token: str, kennung: str) -> None:
    """Das Miniserver-Token gehoert nicht in die Konfiguration, die die
    Oberflaeche anzeigt. Eigene Datei, Rechte 0600."""
    d = json_lesen(DATEI_GEHEIM)
    d["ms_token"] = token
    d["ms_kennung"] = kennung
    json_schreiben(DATEI_GEHEIM, d, 0o600)


def token_holen() -> tuple[str, str]:
    d = json_lesen(DATEI_GEHEIM)
    return str(d.get("ms_token") or ""), str(d.get("ms_kennung") or "")


# ---------------------------------------------------------------------------
# Abbild fuer die Anzeigeseite
# ---------------------------------------------------------------------------

def abbild_bauen(ms: Miniserver, cfg: dict, tabelle: dict) -> dict:
    """Aus Struktur und Zustaenden das, was die Kacheln brauchen - und nur das.

    Bewusst NICHT das ganze Rohabbild: eine Strukturdatei mit 1700 Bausteinen
    ueber die Leitung an ein Tablet zu schicken, alle zwei Sekunden, waere
    Verschwendung. Die Anzeigeseite bekommt Werte, die Struktur holt sie
    einmal.
    """
    werte: dict[str, Any] = {}
    for b in bausteine_sammeln(ms.struktur, tabelle):
        for name, uuid in (b.get("zustaende") or {}).items():
            if uuid in ms.zustaende:
                werte.setdefault(b["uuid"], {})[name] = ms.zustaende[uuid]
    return {
        "ok": 1 if ms.verbunden else 0,
        "ts": int(time.time()),
        "weg": ms.weg,
        "fehler": ms.letzter_fehler,
        "werte": werte,
        "anzahl_bausteine": len(ms.struktur.get("controls") or {}),
        "anzahl_zustaende": len(ms.zustaende),
    }


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
        uuid = str(auftrag.get("uuid") or "")
        befehl = str(auftrag.get("befehl") or "")
        ok, grund = befehl_erlaubt(uuid, befehl, struktur)
        if not ok:
            _LOG.warning("Befehl abgewiesen: %s %s - %s", uuid, befehl, grund)
            json_schreiben(os.path.join(ORDNER_ANTWORTEN, kennung + ".json"),
                           {"ok": 0, "meldung": grund})
            continue
        try:
            antwort = await ms.befehl_senden(uuid, befehl)
            _LOG.info("Befehl ausgefuehrt: %s %s", uuid, befehl)
            json_schreiben(os.path.join(ORDNER_ANTWORTEN, kennung + ".json"),
                           {"ok": 1, "meldung": str(antwort)})
        except (LoxFehler, asyncio.TimeoutError, OSError) as f:
            _LOG.warning("Befehl misslungen: %s %s - %s", uuid, befehl, f)
            json_schreiben(os.path.join(ORDNER_ANTWORTEN, kennung + ".json"),
                           {"ok": 0, "meldung": str(f)})


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

    async def einmal_verbinden(self, cfg: dict, tabelle: dict) -> Miniserver:
        d = miniserver_daten(str(cfg.get("miniserver") or "1"))
        if not d:
            raise LoxFehler(
                "In der LoxBerry-Konfiguration steht kein Miniserver. "
                "Unter System, Miniserver eintragen - oder eigene Zugangsdaten "
                "im Reiter Einstellungen hinterlegen.")
        if not d.get("benutzer"):
            raise LoxFehler("Fuer den Miniserver '%s' ist kein Benutzer hinterlegt."
                            % d.get("name"))
        altes, kennung = token_holen()
        ms = Miniserver(d["adresse"], d["port"], d["benutzer"], d["passwort"],
                        tls=bool(int(cfg.get("tls") or 0)), kennung=kennung)
        await ms.verbinden(altes_token=altes)
        token_merken(str(ms.token.get("token") or altes), ms.kennung)
        await ms.struktur_holen()
        struktur_ablegen(ms, tabelle)
        await ms.zustaende_anfordern()
        return ms

    async def laufen(self) -> int:
        cfg = config()
        tabelle = kacheltabelle()
        os.makedirs(ORDNER_BEFEHLE, exist_ok=True)
        os.makedirs(ORDNER_ANTWORTEN, exist_ok=True)
        takt = max(1, min(30, int(cfg.get("takt") or 2)))
        wartezeit = 5

        while self.laeuft:
            try:
                ms = await self.einmal_verbinden(cfg, tabelle)
                self.ms = ms
                struktur = json_lesen(DATEI_STRUKTUR)
                _LOG.info("Verbunden. %d Bausteine, %d Zustaende.",
                          len(struktur.get("bausteine") or []), len(ms.zustaende))
                wartezeit = 5
                letzte_pruefung = 0.0
                while self.laeuft and ms.verbunden:
                    await warteschlange(ms, struktur)
                    json_schreiben(DATEI_ABBILD, abbild_bauen(ms, cfg, tabelle))
                    json_schreiben(DATEI_ZUSTAND, {
                        "ok": 1, "ts": int(time.time()), "weg": ms.weg,
                        "fehler": "", "miniserver": ms.host,
                        "bausteine": len(struktur.get("bausteine") or []),
                        "zustaende": len(ms.zustaende)})
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
                        except Exception as f:
                            _LOG.info("Versionsabfrage misslungen: %s", f)
                    antworten_aufraeumen()
                    await asyncio.sleep(takt)
                await ms.schliessen()
            except asyncio.CancelledError:
                raise
            except Exception as f:
                _LOG.error("%s", f)
                json_schreiben(DATEI_ZUSTAND, {"ok": 0, "ts": int(time.time()),
                                               "weg": "", "fehler": str(f)})
                if int(cfg.get("http_rueckfall") or 0) and self.ms is not None \
                        and self.ms.token.get("token"):
                    await self.http_weg(cfg, tabelle)
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
        """
        ms = self.ms
        if ms is None:
            return
        struktur = json_lesen(DATEI_STRUKTUR)
        bausteine = struktur.get("bausteine") or []
        takt = max(5, min(120, int(cfg.get("http_takt") or 10)))
        _LOG.warning("Der WebSocket steht nicht. Es wird auf HTTP-Abfrage "
                     "zurueckgefallen: %d Bausteine alle %d s. Das ist der Notnagel, "
                     "nicht der Regelweg.", len(bausteine), takt)
        ms.weg = "http"
        ende = time.time() + 60
        while self.laeuft and time.time() < ende:
            fehler = 0
            for b in bausteine:
                if not self.laeuft:
                    break
                try:
                    ms.zustaende[list(b["zustaende"].values())[0]] = ms.http_zustand(b["uuid"])
                except Exception:
                    fehler += 1
                    if fehler > 5:
                        _LOG.warning("Auch der HTTP-Weg antwortet nicht mehr.")
                        return
            json_schreiben(DATEI_ABBILD, abbild_bauen(ms, cfg, tabelle))
            await asyncio.sleep(takt)


# ---------------------------------------------------------------------------
# Betriebsarten
# ---------------------------------------------------------------------------

async def einmal() -> int:
    cfg, tabelle = config(), kacheltabelle()
    d = Dienst()
    try:
        ms = await d.einmal_verbinden(cfg, tabelle)
        await asyncio.sleep(2)          # den ersten Schwall Zustaende abwarten
        json_schreiben(DATEI_ABBILD, abbild_bauen(ms, cfg, tabelle))
        json_schreiben(DATEI_ZUSTAND, {"ok": 1, "ts": int(time.time()), "weg": ms.weg,
                                       "fehler": "", "miniserver": ms.host,
                                       "zustaende": len(ms.zustaende)})
        print("Struktur und Abbild geschrieben: %d Zustaende." % len(ms.zustaende))
        await ms.schliessen()
        return 0
    except Exception as f:
        print("Fehlgeschlagen: %s" % f)
        json_schreiben(DATEI_ZUSTAND, {"ok": 0, "ts": int(time.time()), "fehler": str(f)})
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

    print("Selbsttest des Dashboard-Dienstes")
    fehlt = 0
    for stand, text in zeilen:
        print({1: "[OK]   ", 0: "[FEHL] ", -1: "[INFO] "}[stand] + text)
        fehlt += 1 if stand == 0 else 0
    print()
    print("Bausteine des Miniserver-Clients, einzeln geprueft:")
    for ok, text in selbstpruefung():
        print(("[OK]   " if ok else "[FEHL] ") + text)
        fehlt += 0 if ok else 1
    print()
    print("Nicht geprueft, weil dafuer ein echter Miniserver noetig ist:")
    print("  - ob die Token-Anmeldung an Ihrer Firmware durchgeht")
    print("  - ob die Kachel-Befehle am Geraet die erwartete Wirkung haben")
    print("  - wie schnell die Anzeige bei Ihrer Anzahl Bausteine nachzieht")
    return 1 if fehlt else 0


def main() -> int:
    log_einrichten()
    if "--selbsttest" in sys.argv:
        return selbsttest()
    if "--einmal" in sys.argv:
        return asyncio.run(einmal())
    if "--entwurf" in sys.argv:
        return asyncio.run(entwurf_erzeugen("--von-vorn" in sys.argv))

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
