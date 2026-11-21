# -*- coding: utf-8 -*-
"""Dashboard-Designer - Haus-Themen fuer die Kachel "Abfahrt" lesen (0.9.49).

Nur wenn der Haken "Haus-Themen fuer die Kachel Abfahrt lesen" an ist
(haus_abfahrt_ein, ab Werk aus), haelt der Dienst eine eigene Leseverbindung
zum Broker und abonniert:

    haus/abfahrt/in_min, zaehler, strecke_km, bedarf_km, ladewarnung, fahrzeuge
    haus/fahrzeug/+/reichweite_km, haus/fahrzeug/+/zaehler

Anbieter ist der Abfahrts-Assistent, die Fahrzeugthemen kommen von den
Fahrzeug-Plugins (Regeln/07, Haus-Themen). Gelesen wird nur; dieser Leser
sendet nie ein Thema und raeumt nie eines ab.

Zurueckbehaltene (retained) Werte werden ANGENOMMEN - sie sind der Zustand,
und ohne sie stuende nach jedem Neustart bis zur naechsten Aenderung nichts
da. Anders als bei den Tafelbefehlen (tafel_mqtt.py verwirft retained) ist
ein Wert hier kein Befehl.

Frisch sind die Werte einer Quelle nur, solange ihr Zaehler (nie retained)
sich binnen 120 s LIVE geaendert hat. "seit" ist die Uhrzeit des LoxBerry,
zu der der Zaehler zuletzt live mit einem neuen Wert kam (der erste live
empfangene Wert einer Quelle zaehlt als neu). Ein zurueckbehaltener Zaehler
- den es nach dem Vertrag nicht gibt - bewegt "seit" nie. Ob die Werte frisch
sind, rechnet die Oberflaeche bei jeder Anfrage aus "seit" und der eigenen
Uhr (db_abfahrt_nutzlast in db_lib.php); die Uhr des Tablets zaehlt nicht.

Stand: data/plugins/<ordner>/haus.json (0600) mit je Quelle werte, zaehler,
seit und lage ("nie" = noch kein Zaehler live, "live" = mindestens einmal).

Verbindung, Pakete und Zugang wie tafel_mqtt.py (MQTT 3.1.1 von Hand, QoS 0,
Zugang aus der general.json, Kennwort nur im CONNECT-Paket, Rueckfall 5 s bis
120 s).
"""

from __future__ import annotations

import asyncio
import os
import re
import struct
import time

import tafel_mqtt as tm

ABFAHRT = ("in_min", "zaehler", "strecke_km", "bedarf_km", "ladewarnung", "fahrzeuge")
FAHRZEUG = ("reichweite_km", "zaehler")
MUSTER_NAME = re.compile(r"^[a-z0-9_-]{1,40}\Z")
FRIST = 120               # Sekunden: so lange gilt ein live geaenderter Zaehler
FAHRZEUGE_MAX = 16        # so viele Fahrzeugnamen merkt sich der Leser (angezeigt hoechstens 4)
WERT_MAX = 300            # Zeichen je Wert; "fahrzeuge" mit vier Namen braucht hoechstens 172
SCHREIB_ABSTAND = 1.0     # Sekunden zwischen zwei Standdateien bei Aenderung
STATUS_TAKT = 60          # die Standdatei wenigstens so oft auffrischen


def themen() -> list:
    return (["haus/abfahrt/%s" % t for t in ABFAHRT]
            + ["haus/fahrzeug/+/%s" % t for t in FAHRZEUG])


def quelle_neu() -> dict:
    return {"werte": {}, "zaehler": None, "seit": 0, "lage": "nie"}


def frisch(q, jetzt: float) -> bool:
    """Dieselbe Regel wie db_haus_frisch() in db_lib.php - fuer den Selbsttest
    und die Eichung; die Anzeige rechnet in PHP."""
    if not isinstance(q, dict):
        return False
    seit = q.get("seit")
    if not isinstance(seit, (int, float)) or isinstance(seit, bool) or seit <= 0:
        return False
    alter = jetzt - seit
    return 0 <= alter <= FRIST


class HausMqtt:
    """Haelt die Leseverbindung, solange laeuft() wahr ist."""

    def __init__(self, *, general_pfad: str, stand_pfad: str, json_lesen, json_schreiben,
                 log, laeuft, uhr=time.time) -> None:
        self.general_pfad = general_pfad
        self.stand_pfad = stand_pfad
        self.json_lesen = json_lesen
        self.json_schreiben = json_schreiben
        self.log = log
        self.laeuft = laeuft
        self.uhr = uhr
        self.stand = {"lage": "startet", "grund": "", "broker": "", "themen": themen(),
                      "verbunden_seit": 0, "nachrichten": 0, "retained": 0,
                      "zaehler_retained": 0, "verworfen": 0,
                      "quellen": {"abfahrt": quelle_neu(), "fahrzeug": {}}}
        self._schmutzig = True
        self._geschrieben = 0.0
        self._gemeldet: dict = {}

    # -- Protokoll und Stand
    def _gebremst(self, schluessel: str, text: str, *args) -> None:
        jetzt = time.monotonic()
        if jetzt - self._gemeldet.get(schluessel, -tm.BREMSE - 1) >= tm.BREMSE:
            self._gemeldet[schluessel] = jetzt
            self.log.warning(text, *args)

    def status(self, **aenderung) -> None:
        self.stand.update(aenderung)
        self.stand["ts"] = int(self.uhr())
        self.json_schreiben(self.stand_pfad, self.stand, 0o600)
        self._schmutzig = False
        self._geschrieben = time.monotonic()

    def _vielleicht_schreiben(self, zwingend: bool = False) -> None:
        jetzt = time.monotonic()
        if zwingend or (self._schmutzig and jetzt - self._geschrieben >= SCHREIB_ABSTAND) \
                or jetzt - self._geschrieben >= STATUS_TAKT:
            self.status()

    # -- eine Nachricht
    def _quelle(self, teile: list):
        """(quelle, feld) oder (None, grund)."""
        if len(teile) == 3 and teile[0] == "haus" and teile[1] == "abfahrt" and teile[2] in ABFAHRT:
            return self.stand["quellen"]["abfahrt"], teile[2]
        if len(teile) == 4 and teile[0] == "haus" and teile[1] == "fahrzeug" and teile[3] in FAHRZEUG:
            name = teile[2]
            if not MUSTER_NAME.match(name):
                return None, "NAME"
            fz = self.stand["quellen"]["fahrzeug"]
            if name not in fz:
                if len(fz) >= FAHRZEUGE_MAX:
                    return None, "ZU_VIELE"
                fz[name] = quelle_neu()
            return fz[name], teile[3]
        return None, "FREMD"

    def nachricht(self, thema: str, nutzlast: bytes, retain: bool) -> str:
        """Rueckgabe: was geschah (fuer Stand und Eichung)."""
        q, feld = self._quelle(thema.split("/"))
        if q is None:
            if feld != "FREMD":
                self.stand["verworfen"] += 1
                self._gebremst("verworfen_" + feld, "Haus-Themen: %s verworfen (%s).", thema[:120],
                               "Name ausserhalb der Namensregel" if feld == "NAME"
                               else "mehr als %d Fahrzeugnamen" % FAHRZEUGE_MAX)
            return feld
        self.stand["nachrichten"] += 1
        if retain:
            self.stand["retained"] += 1
        text = nutzlast[:WERT_MAX * 4].decode("utf-8", "replace").strip()[:WERT_MAX]
        if feld == "zaehler":
            if retain:
                # Nach dem Vertrag nie retained. Kommt er doch so, sagt er nichts ueber
                # das Leben des Anbieters - "seit" bleibt stehen.
                self.stand["zaehler_retained"] += 1
                self._schmutzig = True
                return "ZAEHLER_RETAINED"
            if not nutzlast:
                return "ZAEHLER_LEER"
            neu = q.get("zaehler") is None or q.get("lage") != "live" or text != q.get("zaehler")
            q["zaehler"] = text
            if neu:
                q["seit"] = int(self.uhr())
                q["lage"] = "live"
                self._schmutzig = True
                return "ZAEHLER_LIVE"
            return "ZAEHLER_GLEICH"
        if not nutzlast:
            # Eine leere Nutzlast loescht einen zurueckbehaltenen Wert am Broker.
            if feld in q["werte"]:
                del q["werte"][feld]
                self._schmutzig = True
            return "GELOESCHT"
        if q["werte"].get(feld) != text:
            q["werte"][feld] = text
            self._schmutzig = True
        return "WERT_RETAINED" if retain else "WERT_LIVE"

    # -- Verbindung
    async def _sitzung(self, z: dict) -> None:
        reader, writer = await asyncio.wait_for(asyncio.open_connection(z["host"], z["port"]), 10)
        try:
            writer.write(tm.connect_paket("dashboard-haus-%d" % os.getpid(), z["user"], z["pass"]))
            await writer.drain()
            typ, rumpf = await tm.paket_lesen(reader, 10, 10)
            if typ != 0x20 or len(rumpf) < 2:
                raise tm.MqttFehler("keine gueltige Antwort auf CONNECT")
            if rumpf[1] != 0:
                raise tm.MqttFehler("Broker lehnt die Anmeldung ab (CONNACK %d: %s)"
                                    % (rumpf[1], tm.CONNACK_TEXT.get(rumpf[1], "?")))
            writer.write(tm.subscribe_paket(1, themen()))
            await writer.drain()
            abonniert = False
            frist_suback = time.monotonic() + 10
            letzte_aktivitaet = time.monotonic()
            ping_offen = 0.0
            while self.laeuft():
                try:
                    typ, rumpf = await tm.paket_lesen(reader, 1.0)
                except asyncio.TimeoutError:
                    typ = None
                jetzt = time.monotonic()
                if typ is None:
                    pass
                elif typ & 0xF0 == 0x30:
                    letzte_aktivitaet = jetzt
                    thema, nutz, retain, qos, pid = tm.publish_zerlegen(typ, rumpf)
                    if qos == 1 and pid is not None:
                        writer.write(b"\x40\x02" + struct.pack("!H", pid))
                        await writer.drain()
                    elif qos == 2:
                        raise tm.MqttFehler("QoS 2 geliefert - abonniert war QoS 0")
                    self.nachricht(thema, nutz, retain)
                elif typ == 0x90:
                    letzte_aktivitaet = jetzt
                    codes = rumpf[2:]
                    if len(rumpf) < 3 or any(c >= 0x80 for c in codes):
                        raise tm.MqttFehler("Broker lehnt das Abo ab (SUBACK %s) - Rechte des "
                                            "Benutzers am Broker pruefen" % codes.hex())
                    abonniert = True
                    self.log.info("Haus-Themen: verbunden mit %s:%d, %d Abos (haus/abfahrt/..., "
                                  "haus/fahrzeug/+/...).", z["host"], z["port"], len(themen()))
                    self.status(lage="verbunden", grund="", verbunden_seit=int(self.uhr()),
                                broker="%s:%d" % (z["host"], z["port"]))
                elif typ == 0xD0:
                    letzte_aktivitaet = jetzt
                    ping_offen = 0.0
                if not abonniert and jetzt > frist_suback:
                    raise tm.MqttFehler("keine Antwort auf SUBSCRIBE")
                if ping_offen and jetzt - ping_offen > tm.KEEPALIVE:
                    raise tm.MqttFehler("Broker antwortet nicht mehr (PINGRESP fehlt)")
                if not ping_offen and jetzt - letzte_aktivitaet >= tm.PING_NACH:
                    writer.write(tm.PINGREQ)
                    await writer.drain()
                    ping_offen = jetzt
                if abonniert:
                    self._vielleicht_schreiben()
        finally:
            try:
                writer.write(tm.DISCONNECT)
                await asyncio.wait_for(writer.drain(), 2)
            except Exception:
                pass
            writer.close()

    async def laufen(self) -> None:
        warte = 5
        self.status()
        while self.laeuft():
            z = tm.zugang(self.json_lesen(self.general_pfad))
            if not z["port"]:
                self.status(lage="kein_broker", broker="",
                            grund="In der general.json steht kein Brokerport (Mqtt.Brokerport).")
                self._gebremst("kein_broker", "Haus-Themen: in der general.json steht kein "
                               "Brokerport - die Kachel Abfahrt bleibt ohne Werte.")
            else:
                self.stand["broker"] = "%s:%d" % (z["host"], z["port"])
                try:
                    await self._sitzung(z)
                    if not self.laeuft():
                        break
                    grund = "Verbindung beendet"
                except (EOFError, asyncio.IncompleteReadError):
                    grund = "der Broker hat die Verbindung beendet"
                except ConnectionRefusedError:
                    grund = "der Broker nimmt keine Verbindung an"
                except asyncio.TimeoutError:
                    grund = "keine Antwort des Brokers"
                except (OSError, tm.MqttFehler) as f:
                    grund = str(f) or f.__class__.__name__
                if self.stand.get("lage") == "verbunden":
                    warte = 5
                self.status(lage="getrennt", grund=grund[:200])
                self._gebremst("getrennt_" + grund[:60],
                               "Haus-Themen: keine Verbindung zum Broker %s (%s). Neuer Versuch.",
                               self.stand["broker"], grund[:200])
            for _ in range(warte * 2):
                if not self.laeuft():
                    break
                await asyncio.sleep(0.5)
            warte = min(120, warte * 2)
        self.status(lage="beendet", grund="Der Dienst wurde angehalten.")

    async def laufen_sicher(self) -> None:
        """laufen() mit Auffangnetz (Bauart tafel_mqtt.TafelMqtt.laufen_sicher)."""
        try:
            await self.laufen()
        except asyncio.CancelledError:
            raise
        except Exception as f:  # noqa: BLE001 - bewusst breit, siehe oben
            self.log.error("Haus-Themen: unerwarteter Fehler (%s: %s) - der Leser ruht bis zum "
                           "naechsten Dienststart.", f.__class__.__name__, f)
            self.status(lage="fehler", grund=("%s: %s" % (f.__class__.__name__, f))[:200])
