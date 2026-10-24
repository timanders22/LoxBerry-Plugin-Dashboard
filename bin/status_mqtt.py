#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Dashboard-Designer - die Zustandswerte ueber das MQTT-Gateway (seit 0.9.37, Gruppe 12 / MQTT).

Der Dienst sendet jede Minute ueber den UDP-Eingang des LoxBerry-MQTT-Gateways (general.json Mqtt.Udpinport, sonst
11884; an 127.0.0.1) je eine Zeile "publish <praefix>/<thema> <wert>" - nie "retain":

    ok              1 = mit dem Miniserver verbunden und Werte frisch, sonst 0 (auch beim geordneten Anhalten)
    bausteine       Zahl der Bausteine aus struktur.json
    seiten          Zahl der Seiten aus seiten.json
    kacheln         Zahl aller Kacheln
    alter           Alter des Abbilds in s, -1 = kein Abbild / Zeitstempel unbrauchbar
    status/zaehler  Lebenszeichen 0..999, je Takt +1, laeuft um

Die Werte entstehen nach DERSELBEN Regel wie db_status_werte() in webfrontend/html/db_lib.php (ok: Abbild ok=1 UND
0 <= alter <= Frist; Frist 3 x max(takt, 30 s), im Notnagel 3 x http_takt). Der Reiter Test haelt beide gegeneinander
(EINE Regel, zwei Sprachen - wie VORGABEN/db_vorgaben). Nichts ist retained: ok und alter sind Dienstaussagen bzw. ein
Alter (Regel 18.09.), die Zahlen kommen ohnehin jede Minute (Entwurf Gruppe 12, 2.2/2.3).

Bauart wie tafel_mqtt.py: reine Funktionen und EINE asyncio-Aufgabe neben der Miniserver-Verbindung - sie haengt
nicht an ihr, sendet also auch im Notnagel und in der Wartezeit zwischen zwei Verbindungsversuchen. Senden nur mit
dem Haken mqtt_ein ("Zustand ueber MQTT senden", ab Werk an). Der Stand des Senders steht in
data/plugins/<ordner>/status_mqtt.json (lage, letzte Werte, zaehler, ts) fuer den Reiter Test.
"""

from __future__ import annotations

import asyncio
import socket
import time

TAKT = 60                 # fest; zwei Takte = 120 s < Tmc 180 s der Analogwertvalidierung im Musterprojekt
THEMEN = ("ok", "bausteine", "seiten", "kacheln", "alter", "status/zaehler")
UDP_VORGABE = 11884


def _ganz(w, ersatz: int = 0) -> int:
    """(int) wie in PHP fuer Zahlen und Ziffernfolgen; sonst ersatz."""
    if isinstance(w, bool):
        return int(w)
    if isinstance(w, (int, float)):
        return int(w)
    if isinstance(w, str):
        s = w.strip()
        n = ""
        for i, c in enumerate(s):
            if c.isdigit() or (i == 0 and c in "+-"):
                n += c
            else:
                break
        try:
            return int(n)
        except ValueError:
            return ersatz
    return ersatz


def udp_port(general: dict) -> int:
    """Der UDP-Eingang des Gateways aus general.json (Mqtt.Udpinport), sonst 11884."""
    m = general.get("Mqtt") or general.get("mqtt") or {}
    if not isinstance(m, dict):
        return UDP_VORGABE
    p = _ganz(m.get("Udpinport") or m.get("udpinport"), 0)
    return p if 1 <= p <= 65535 else UDP_VORGABE


def alter(abbild: dict, jetzt: int) -> int:
    """Wie db_alter(): -1 ohne Abbild, bei ts 0/fehlend/keine Zahl oder in der Zukunft."""
    ts = abbild.get("ts") if isinstance(abbild, dict) else None
    if isinstance(ts, bool) or not isinstance(ts, (int, float, str)):
        return -1
    if isinstance(ts, str):
        try:
            float(ts)
        except ValueError:
            return -1
    t = _ganz(ts, 0)
    if t <= 0:
        return -1
    d = jetzt - t
    return -1 if d < 0 else d


def frist(abbild: dict, cfg: dict) -> int:
    """Wie db_frist(): im Notnagel (weg = http) 3 x http_takt (5..120), sonst 3 x max(takt 1..30, 30)."""
    if isinstance(abbild, dict) and abbild.get("weg") == "http":
        return 3 * max(5, min(120, _ganz(cfg.get("http_takt"), 0)))
    return 3 * max(max(1, min(30, _ganz(cfg.get("takt"), 0))), 30)


def werte(abbild: dict, cfg: dict, struktur: dict, seiten_d: dict, jetzt: int) -> dict:
    """Die fuenf Zustandswerte - dieselbe Regel wie db_status_werte() (ohne ZEIT; das Lebenszeichen ist der
    Zaehler). Alles int, nichts leer."""
    abbild = abbild if isinstance(abbild, dict) else {}
    seiten = seiten_d.get("seiten") if isinstance(seiten_d, dict) else None
    seiten = seiten if isinstance(seiten, list) else []
    kacheln = 0
    for s in seiten:
        k = s.get("kacheln") if isinstance(s, dict) else None
        kacheln += len(k) if isinstance(k, list) else 0
    bst = struktur.get("bausteine") if isinstance(struktur, dict) else None
    a = alter(abbild, jetzt)
    ok = 1 if (abbild.get("ok") not in (None, 0, "", "0", False) and 0 <= a <= frist(abbild, cfg)) else 0
    return {"ok": ok, "bausteine": len(bst) if isinstance(bst, list) else 0, "seiten": len(seiten),
            "kacheln": kacheln, "alter": a}


def zeilen(praefix: str, w: dict, zaehler) -> list:
    """Die Zeilen fuer den UDP-Eingang, in der Reihenfolge von THEMEN; status/zaehler nur, wenn zaehler nicht None.
    Immer "publish", nie "retain" (Regeln/07)."""
    aus = []
    for t in THEMEN:
        if t == "status/zaehler":
            if zaehler is not None:
                aus.append("publish %s/%s %d" % (praefix, t, int(zaehler)))
            continue
        aus.append("publish %s/%s %d" % (praefix, t, int(w.get(t, 0))))
    return aus


def senden(liste: list, port: int, host: str = "127.0.0.1") -> int:
    """Je Zeile ein Datagramm an den UDP-Eingang des Gateways. Rueckgabe: Zahl der gesendeten Zeilen."""
    n = 0
    s = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
    try:
        for z in liste:
            s.sendto(z.encode("utf-8"), (host, port))
            n += 1
    finally:
        s.close()
    return n


class StatusMqtt:
    """Die Aufgabe im Dienst. Alles, was sie liest und schreibt, kommt von aussen (wie TafelMqtt) - so laesst sie
    sich ohne Dienst pruefen."""

    def __init__(self, *, config, praefix_regel, general_pfad: str, abbild_pfad: str, struktur_pfad: str,
                 seiten_pfad: str, stand_pfad: str, json_lesen, json_schreiben, log, laeuft,
                 sender=senden, takt: int = TAKT, uhr=time.time):
        self.config = config
        self.praefix_regel = praefix_regel
        self.general_pfad = general_pfad
        self.abbild_pfad = abbild_pfad
        self.struktur_pfad = struktur_pfad
        self.seiten_pfad = seiten_pfad
        self.stand_pfad = stand_pfad
        self.json_lesen = json_lesen
        self.json_schreiben = json_schreiben
        self.log = log
        self.laeuft = laeuft
        self.sender = sender
        self.takt = takt
        self.uhr = uhr
        self.zaehler = 0
        self.gesendet_einmal = False
        self._gemeldet = ""

    def _praefix(self, cfg: dict) -> str:
        p = cfg.get("tafel_mqtt_praefix")
        return p if self.praefix_regel(p) else "dashboard"

    def _melden(self, schluessel: str, text: str) -> None:
        if self._gemeldet != schluessel:
            self._gemeldet = schluessel
            self.log.warning("%s", text)

    def ein_takt(self) -> dict:
        """Ein Sendetakt. Rueckgabe: der geschriebene Stand."""
        cfg = self.config()
        jetzt = int(self.uhr())
        try:
            an = _ganz(cfg.get("mqtt_ein"), 1) == 1
        except Exception:
            an = True
        if not an:
            stand = {"ts": jetzt, "lage": "aus", "zaehler": self.zaehler}
            self.json_schreiben(self.stand_pfad, stand)
            return stand
        praefix = self._praefix(cfg)
        abbild = self.json_lesen(self.abbild_pfad)
        abbild = abbild if isinstance(abbild, dict) else {}
        w = werte(abbild, cfg, self.json_lesen(self.struktur_pfad), self.json_lesen(self.seiten_pfad), jetzt)
        port = udp_port(self.json_lesen(self.general_pfad))
        try:
            n = self.sender(zeilen(praefix, w, self.zaehler), port)
            lage, fehler = "gesendet", ""
            self._gemeldet = ""
        except OSError as f:
            n, lage, fehler = 0, "fehler", str(f)[:200]
            self._melden("fehler", "Zustand ueber MQTT: Senden an den UDP-Eingang %d misslungen (%s)." % (port, f))
        # "abbild": die Eingaben der Rechnung - der Reiter Test rechnet damit dieselbe Regel in PHP nach.
        stand = {"ts": jetzt, "lage": lage, "praefix": praefix, "port": port, "werte": w,
                 "zaehler": self.zaehler, "zeilen": n, "fehler": fehler,
                 "abbild": {"ts": abbild.get("ts"), "weg": abbild.get("weg", ""), "ok": abbild.get("ok", 0)}}
        self.json_schreiben(self.stand_pfad, stand)
        self.zaehler = (self.zaehler + 1) % 1000
        self.gesendet_einmal = self.gesendet_einmal or lage == "gesendet"
        return stand

    def abmelden(self) -> None:
        """Beim geordneten Anhalten einmal ok 0 (der Zaehler laeuft nicht weiter)."""
        cfg = self.config()
        if _ganz(cfg.get("mqtt_ein"), 1) != 1:
            return
        try:
            self.sender(["publish %s/ok 0" % self._praefix(cfg)], udp_port(self.json_lesen(self.general_pfad)))
        except OSError:
            pass

    async def laufen(self) -> None:
        while self.laeuft():
            try:
                self.ein_takt()
            except Exception as f:          # die Aufgabe darf den Dienst nie mitreissen
                self._melden("ausnahme", "Zustand ueber MQTT: %s" % f)
            for _ in range(self.takt * 2):
                if not self.laeuft():
                    break
                await asyncio.sleep(0.5)
