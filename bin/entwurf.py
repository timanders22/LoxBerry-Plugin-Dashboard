#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Aus der Strukturdatei einen ersten Dashboard-Entwurf bauen.

Der Grundgedanke: nach der Installation soll etwas Brauchbares dastehen, ohne
dass jemand eine einzige Kachel gezogen hat. Erst danach wird von Hand
verfeinert.

Zwei Regeln bestimmen den Entwurf:

  1. Es wird je Raum eine Seite gebaut, nicht je Kategorie. Wer vor einem
     Wandtablet steht, denkt in Raeumen ("das Licht hier"), nicht in
     Gewerken.
  2. Innerhalb einer Seite bestimmt die Loxone-Bewertung (defaultRating) die
     Reihenfolge, absteigend. Diese Bewertung hat der Anwender in Loxone
     Config schon einmal vergeben - sie noch einmal abzufragen waere
     unhoeflich.

Was NICHT in den Entwurf kommt:
  - Bausteine mit leerem Typ. Die Strukturdatei sagt dazu ausdruecklich:
    "an empty string as type indicates a control that should not be
    visualized" [Structure File, Abschnitt Mandatory fields].
  - Bausteine ohne Raum. Sie haetten keine Seite, auf die sie gehoeren.
  - Bausteine mit gesetztem Bit 0 oder 4 der 'restrictions' (nur referenziert)
    [Structure File, Abschnitt Optional fields].

Ein neuer Entwurf ueberschreibt NIE, was von Hand geaendert wurde: bestehende
Seiten bleiben, es kommen nur neue Kacheln fuer Bausteine hinzu, die noch
nirgends vorkommen. Wer wirklich von vorn anfangen will, sagt das ausdruecklich.
"""

from __future__ import annotations

import re
from typing import Any

# Bits der 'restrictions' [Structure File, Optional fields]
NUR_REFERENZIERT = 0x01 | 0x10
NUR_LESEN = 0x02 | 0x20


def kachel_art(typ: str, tabelle: dict) -> dict:
    """Die Zeile aus kacheln.json fuer einen Loxone-Typ, oder die generische."""
    t = (tabelle.get("typen") or {}).get(typ)
    if t:
        return dict(t, loxtyp=typ, bekannt=1)
    return dict(tabelle.get("generisch") or {}, loxtyp=typ, bekannt=0)


def schluessel(text: str) -> str:
    """Aus einem Namen einen Schluessel machen, der sich in eine Adresse tippen laesst."""
    t = (text or "").lower()
    for a, b in (("ä", "ae"), ("ö", "oe"), ("ü", "ue"), ("ß", "ss")):
        t = t.replace(a, b)
    t = re.sub(r"[^a-z0-9]+", "-", t).strip("-")
    return t or "seite"


def _lesbar(rest: int) -> bool:
    return not (rest & NUR_REFERENZIERT)


def bausteine_sammeln(struktur: dict, tabelle: dict) -> list[dict]:
    """Alle anzeigbaren Bausteine mit ihrer Kachelart und ihren Zustaenden."""
    raeume = struktur.get("rooms") or {}
    kats = struktur.get("cats") or {}
    ergebnis = []
    for uuid, c in (struktur.get("controls") or {}).items():
        if not isinstance(c, dict):
            continue
        typ = str(c.get("type") or "")
        if typ == "":
            continue
        rest = int(c.get("restrictions") or 0)
        if not _lesbar(rest):
            continue
        art = kachel_art(typ, tabelle)
        zustaende = c.get("states") or {}
        if not isinstance(zustaende, dict):
            zustaende = {}
        # Bei bekannten Typen nur die genannten Zustaende, bei unbekannten
        # alle - dort weiss die Tabelle ja nichts.
        gewuenscht = art.get("zustaende") or []
        genommen = {k: v for k, v in zustaende.items()
                    if not gewuenscht or k in gewuenscht}
        if not genommen:
            genommen = dict(zustaende)
        raum = str(c.get("room") or "")
        kat = str(c.get("cat") or "")
        ergebnis.append({
            "uuid": str(c.get("uuidAction") or uuid),
            "name": str(c.get("name") or "?"),
            "loxtyp": typ,
            "kachel": art.get("kachel") or "generisch",
            "bekannt": art.get("bekannt", 0),
            "haupt": art.get("haupt") or "",
            "befehle": list(art.get("befehle") or []),
            "zustaende": genommen,
            "raum": raum,
            "raumname": str((raeume.get(raum) or {}).get("name") or ""),
            "kategorie": kat,
            "katname": str((kats.get(kat) or {}).get("name") or ""),
            "bewertung": int(c.get("defaultRating") or 0),
            "favorit": 1 if c.get("isFavorite") else 0,
            "gesichert": 1 if c.get("isSecured") else 0,
            "nurlesen": 1 if (rest & NUR_LESEN) else 0,
            "format": str(((c.get("details") or {}) if isinstance(c.get("details"), dict) else {})
                          .get("format") or ""),
            "details": c.get("details") if isinstance(c.get("details"), dict) else {},
        })

    # Der Wetterdienst steht NICHT unter 'controls'.
    #
    # [S, Abschnitt weatherServer]: "If a Cloud Weather is configured, this
    # section is added to the Structure-File" - ein eigener Abschnitt neben
    # rooms und cats, mit genau zwei Zustaenden, 'actual' und 'forecast'.
    # Wer nur ueber 'controls' laeuft, findet ihn nie. Damit die Kachel ihn
    # wie jeden anderen Baustein behandeln kann, wird hier ein Eintrag
    # daraus gebaut.
    ws = struktur.get("weatherServer")
    if isinstance(ws, dict) and isinstance(ws.get("states"), dict):
        zust = {k: str(v) for k, v in ws["states"].items() if v}
        aktuell = zust.get("actual") or (list(zust.values())[0] if zust else "")
        if aktuell:
            art = kachel_art("WeatherServer", tabelle)
            ergebnis.append({
                "uuid": aktuell,
                "name": str(ws.get("name") or "Wetter"),
                "loxtyp": "WeatherServer",
                "kachel": art.get("kachel") or "generisch",
                "bekannt": art.get("bekannt", 0),
                "haupt": art.get("haupt") or "actual",
                "befehle": list(art.get("befehle") or []),
                "zustaende": zust,
                "raum": "", "raumname": "",
                "kategorie": "", "katname": "",
                "bewertung": 0, "favorit": 0, "gesichert": 0,
                # Der Wetterdienst laesst sich nicht schalten - das ist keine
                # Einschraenkung dieses Plugins, sondern seine Natur.
                "nurlesen": 1,
                "format": "",
                "details": {"format": ws.get("format") or {}},
            })
    return ergebnis


def entwurf_bauen(struktur: dict, tabelle: dict, vorhanden: dict | None = None) -> dict:
    """Seiten je Raum, Kacheln nach Bewertung sortiert.

    'vorhanden' ist ein bereits gepflegtes Dashboard. Alles darin bleibt
    unangetastet; es werden nur Bausteine ergaenzt, die noch auf keiner Seite
    stehen. So kostet ein erneuter Entwurf keine Handarbeit.
    """
    vorhanden = vorhanden or {}
    seiten = list(vorhanden.get("seiten") or [])
    schon = set()
    for s in seiten:
        for k in (s.get("kacheln") or []):
            schon.add(str(k.get("uuid") or ""))

    bausteine = bausteine_sammeln(struktur, tabelle)
    groessen = tabelle.get("vorgabegroesse") or {}

    # Nach Raum buendeln. Bausteine ohne Raum kommen auf eine Sammelseite -
    # sie verschwinden zu lassen waere die schlechtere Loesung: dann wundert
    # sich jemand, warum ein Baustein fehlt.
    nach_raum: dict[str, list] = {}
    for b in bausteine:
        nach_raum.setdefault(b["raum"], []).append(b)

    vorhandene_schluessel = {str(s.get("schluessel") or "") for s in seiten}

    def seite_finden(sch: str):
        for s in seiten:
            if str(s.get("schluessel") or "") == sch:
                return s
        return None

    raumliste = struktur.get("rooms") or {}
    reihenfolge = sorted(
        nach_raum.keys(),
        key=lambda r: (-int((raumliste.get(r) or {}).get("defaultRating") or 0),
                       str((raumliste.get(r) or {}).get("name") or "￿")))

    for raum in reihenfolge:
        liste = nach_raum[raum]
        name = liste[0]["raumname"] or "Ohne Raum"
        sch = schluessel(name)
        seite = seite_finden(sch)
        if seite is None:
            n, sch2 = 2, sch
            while sch2 in vorhandene_schluessel:
                sch2, n = "%s-%d" % (sch, n), n + 1
            sch = sch2
            vorhandene_schluessel.add(sch)
            seite = {"schluessel": sch, "name": name, "spalten": 6,
                     "pin": "", "kacheln": []}
            seiten.append(seite)
        for b in sorted(liste, key=lambda x: (-x["favorit"], -x["bewertung"], x["name"])):
            if b["uuid"] in schon:
                continue
            schon.add(b["uuid"])
            seite["kacheln"].append({
                "uuid": b["uuid"],
                "titel": b["name"],
                "kachel": b["kachel"],
                "groesse": groessen.get(b["kachel"], "1x1"),
                "sichtbar": 1,
            })

    return {"seiten": seiten,
            "erzeugt_aus": str(struktur.get("lastModified") or ""),
            "anzahl_bausteine": len(bausteine)}


def probe() -> list[tuple[bool, str]]:
    """Prueft den Entwurf gegen eine kleine Struktur von Hand."""
    tabelle = {
        "typen": {"Switch": {"kachel": "schalter", "haupt": "active",
                             "zustaende": ["active"], "befehle": ["on", "off"]}},
        "generisch": {"kachel": "generisch", "haupt": "", "zustaende": [], "befehle": []},
        "vorgabegroesse": {"schalter": "1x1", "generisch": "2x1"},
    }
    struktur = {
        "lastModified": "2026-01-01 00:00:00",
        "rooms": {"r1": {"name": "Küche", "defaultRating": 5},
                  "r2": {"name": "Bad", "defaultRating": 9}},
        "cats": {},
        "controls": {
            "c1": {"name": "Licht", "type": "Switch", "uuidAction": "c1", "room": "r1",
                   "defaultRating": 1, "states": {"active": "s1"}},
            "c2": {"name": "Fav", "type": "Switch", "uuidAction": "c2", "room": "r1",
                   "defaultRating": 0, "isFavorite": True, "states": {"active": "s2"}},
            "c3": {"name": "Unsichtbar", "type": "", "uuidAction": "c3", "room": "r1",
                   "states": {}},
            "c4": {"name": "Nur intern", "type": "Switch", "uuidAction": "c4", "room": "r1",
                   "restrictions": 1, "states": {"active": "s4"}},
            "c5": {"name": "Fremd", "type": "Phantasie", "uuidAction": "c5", "room": "r2",
                   "states": {"a": "s5", "b": "s6"}},
            "c6": {"name": "Heimatlos", "type": "Switch", "uuidAction": "c6",
                   "states": {"active": "s7"}},
        },
    }
    e = []
    d = entwurf_bauen(struktur, tabelle)
    seiten = {s["schluessel"]: s for s in d["seiten"]}
    e.append((set(seiten) == {"bad", "kueche", "ohne-raum"},
              "Seiten je Raum, Umlaut geglaettet, Heimatlose gesammelt: %s" % sorted(seiten)))
    e.append((d["seiten"][-1]["schluessel"] == "ohne-raum",
              "Die Sammelseite steht ganz hinten"))
    e.append((d["seiten"][0]["schluessel"] == "bad",
              "Raum mit hoeherer Bewertung steht vorn: %s" % d["seiten"][0]["schluessel"]))
    kueche = [k["titel"] for k in seiten["kueche"]["kacheln"]]
    e.append((kueche == ["Fav", "Licht"], "Favorit vor Bewertung: %s" % kueche))
    e.append((all(k["titel"] not in ("Unsichtbar", "Nur intern") for s in d["seiten"]
                  for k in s["kacheln"]),
              "Leerer Typ und 'nur referenziert' ausgelassen"))
    e.append((seiten["bad"]["kacheln"][0]["kachel"] == "generisch",
              "Unbekannter Typ bekommt die generische Kachel"))
    e.append((seiten["bad"]["kacheln"][0]["groesse"] == "2x1",
              "Vorgabegroesse aus der Tabelle uebernommen"))

    # Zweiter Lauf: nichts darf sich verdoppeln, Handarbeit bleibt.
    d["seiten"][0]["name"] = "Badezimmer"
    d["seiten"][0]["kacheln"][0]["titel"] = "Umbenannt"
    d2 = entwurf_bauen(struktur, tabelle, d)
    alle = [k["uuid"] for s in d2["seiten"] for k in s["kacheln"]]
    e.append((len(alle) == len(set(alle)), "Zweiter Entwurf verdoppelt nichts"))
    e.append((d2["seiten"][0]["name"] == "Badezimmer"
              and d2["seiten"][0]["kacheln"][0]["titel"] == "Umbenannt",
              "Zweiter Entwurf laesst Handarbeit stehen"))

    # Neuer Baustein taucht auf
    struktur["controls"]["c7"] = {"name": "Neu", "type": "Switch", "uuidAction": "c7",
                                  "room": "r2", "states": {"active": "s8"}}
    d3 = entwurf_bauen(struktur, tabelle, d2)
    e.append((any(k["uuid"] == "c7" for s in d3["seiten"] for k in s["kacheln"]),
              "Neuer Baustein wird ergaenzt"))

    b = bausteine_sammeln(struktur, tabelle)
    fremd = [x for x in b if x["uuid"] == "c5"][0]
    e.append((set(fremd["zustaende"]) == {"a", "b"},
              "Unbekannter Typ: alle Zustaende uebernommen"))
    schalter = [x for x in b if x["uuid"] == "c1"][0]
    e.append((set(schalter["zustaende"]) == {"active"},
              "Bekannter Typ: nur die genannten Zustaende"))
    return e


if __name__ == "__main__":
    fehlt = 0
    for ok, text in probe():
        print(("[OK]   " if ok else "[FEHL] ") + text)
        fehlt += 0 if ok else 1
    raise SystemExit(1 if fehlt else 0)
