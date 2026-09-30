# -*- coding: utf-8 -*-
"""Dashboard-Designer - Tafeln ueber MQTT steuern (Tafel-1).

Zusaetzlich zum virtuellen Ausgang (Endpunkt aktion=tafel) nimmt der Dienst
Tafelbefehle ueber MQTT an - nur, wenn die Einstellung "Tafeln ueber MQTT
steuern" an ist (tafel_mqtt, ab Werk aus):

    <praefix>/tafel/alle/seite    Nutzlast: Schluessel der Seite oder ihre
                                  Nummer (1 = erste Seite im Reiter Dashboards)
    <praefix>/tafel/alle/wecken   Nutzlast: 1 = Bildschirm wecken

"alle" heisst: jede Tafel. Die Anzeigeseite kennt keine Namen je Tablet -
auch der virtuelle Ausgang schaltet alle Tafeln zugleich. Ein anderer Name
wird abgewiesen und steht gebremst im Protokoll.

Der Weg zur Tafel ist DERSELBE wie beim virtuellen Ausgang: der Befehl landet
in data/plugins/<ordner>/tafel.json, unter derselben Sperre (tafel.sperre),
mit derselben Zusammenfuehrung und derselben laufenden Nummer wie
db_tafel_befehl() in webfrontend/html/db_lib.php; die Anzeigeseite holt ihn
beim naechsten Takt ab. Die Seite wird wie am Endpunkt geprueft: es muss sie
geben.

Retained Befehle wirken NIE: was der Broker beim Abonnieren als
zurueckbehalten ausliefert (Retain-Merkmal gesetzt), wird verworfen und
gezaehlt. Sonst sprang jede Tafel nach jedem Neustart des Dienstes auf die
Seite des letzten Befehls, der irgendwann einmal retained gesendet wurde.

Die Linie hat kein paho (sie braucht allein websockets). Gesprochen wird
MQTT 3.1.1 von Hand - CONNECT, SUBSCRIBE mit QoS 0, PINGREQ, DISCONNECT -,
Bauart mqtt_behalten_liste() aus Matter2Lox 0.9.32. Der Zugang kommt aus der
general.json (Mqtt: Brokerhost, Brokerport, Brokeruser, Brokerpass;
Regeln/07 Abschnitt 2); das Kennwort steht nur im CONNECT-Paket, nie in
einem Protokoll, einer Ausgabe, der Standdatei oder auf einer Kommandozeile.

Faellt der Broker aus, versucht es der Dienst wieder (5 s, dann doppelt so
lang, hoechstens 120 s). Der virtuelle Ausgang wirkt davon unberuehrt weiter.
Den Stand schreibt er nach data/plugins/<ordner>/tafel_mqtt.json; der Reiter
Test liest ihn (db_tafel_mqtt_pruefzeile in webfrontend/htmlauth/db_test.php).
"""

from __future__ import annotations

import asyncio
import os
import re
import struct
import time

NAME_ALLE = "alle"
ARTEN = ("seite", "wecken")
MUSTER_PRAEFIX = re.compile(r"^[A-Za-z0-9_\-]{1,32}(/[A-Za-z0-9_\-]{1,32}){0,3}\Z")
MUSTER_SEITE = re.compile(r"^[a-z0-9-]{1,60}\Z")
MUSTER_NUMMER = re.compile(r"^([0-9]{1,3})(\.0+)?\Z")
KEEPALIVE = 60           # Sekunden, im CONNECT angesagt
PING_NACH = 30           # so lange Stille, dann PINGREQ
STATUS_TAKT = 60         # die Standdatei wenigstens so oft auffrischen
GROESSTES_PAKET = 65536  # ein Tafelbefehl hat wenige Byte
BREMSE = 3600            # dieselbe Abweisung hoechstens einmal je Stunde


class MqttFehler(Exception):
    pass


def praefix_gueltig(p) -> bool:
    return isinstance(p, str) and MUSTER_PRAEFIX.match(p) is not None


def themen(praefix: str) -> list:
    return ["%s/tafel/+/%s" % (praefix, a) for a in ARTEN]


def zugang(general: dict) -> dict:
    """Host, Port, Benutzer und Kennwort des Brokers aus der general.json.
    port 0 heisst: nicht angegeben oder unbrauchbar - dann wird NICHT 1883
    angenommen (Bauart Matter2Lox 0.9.32)."""
    m = general.get("Mqtt") or general.get("mqtt") or {}
    if not isinstance(m, dict):
        m = {}

    def hol(gross: str, klein: str) -> str:
        w = m.get(gross, m.get(klein, ""))
        return "" if w is None else str(w)

    host = hol("Brokerhost", "brokerhost").strip()
    if host in ("", "localhost"):
        host = "127.0.0.1"
    try:
        port = int(hol("Brokerport", "brokerport").strip())
    except ValueError:
        port = 0
    if not 0 < port < 65536:
        port = 0
    return {"host": host, "port": port,
            "user": hol("Brokeruser", "brokeruser"),
            "pass": hol("Brokerpass", "brokerpass")}


# ---------------------------------------------------------------- Pakete

def _zk(text: str) -> bytes:
    b = text.encode("utf-8")
    return struct.pack("!H", len(b)) + b


def _rest(n: int) -> bytes:
    aus = bytearray()
    while True:
        d = n % 128
        n //= 128
        if n:
            d |= 0x80
        aus.append(d)
        if not n:
            return bytes(aus)


def connect_paket(kennung: str, user: str, kennwort: str) -> bytes:
    """CONNECT, MQTT 3.1.1, saubere Sitzung. Ein Kennwort nur mit Benutzer
    (3.1.1, Abschnitt 3.1.2.9)."""
    flags = 0x02
    nutz = _zk(kennung)
    if user:
        flags |= 0x80
        if kennwort:
            flags |= 0x40
    var = _zk("MQTT") + bytes([4, flags]) + struct.pack("!H", KEEPALIVE)
    if user:
        nutz += _zk(user)
        if kennwort:
            nutz += _zk(kennwort)
    rumpf = var + nutz
    return bytes([0x10]) + _rest(len(rumpf)) + rumpf


def subscribe_paket(pid: int, filter_liste: list) -> bytes:
    rumpf = struct.pack("!H", pid) + b"".join(_zk(f) + b"\x00" for f in filter_liste)
    return bytes([0x82]) + _rest(len(rumpf)) + rumpf


PINGREQ = b"\xc0\x00"
DISCONNECT = b"\xe0\x00"
CONNACK_TEXT = {1: "Protokollfassung abgelehnt", 2: "Kennung abgelehnt",
                3: "Broker nicht verfuegbar", 4: "Benutzer oder Kennwort falsch",
                5: "nicht berechtigt"}


async def paket_lesen(reader, erstes_frist: float, rest_frist: float = 30.0):
    """Ein Paket. Das erste Byte mit kurzer Frist (asyncio.TimeoutError heisst
    dann: nichts gekommen, der Strom ist unversehrt); der Rest mit langer Frist
    - bricht er mitten im Paket ab, ist die Verbindung nicht mehr zu
    gebrauchen (MqttFehler)."""
    kopf = await asyncio.wait_for(reader.readexactly(1), erstes_frist)
    try:
        n = 0
        faktor = 1
        for i in range(4):
            b = (await asyncio.wait_for(reader.readexactly(1), rest_frist))[0]
            n += (b & 0x7F) * faktor
            if not b & 0x80:
                break
            faktor *= 128
            if i == 3:
                raise MqttFehler("Laengenfeld des Pakets zu lang")
        if n > GROESSTES_PAKET:
            raise MqttFehler("Paket mit %d Byte - erwartet sind wenige Byte" % n)
        rumpf = await asyncio.wait_for(reader.readexactly(n), rest_frist) if n else b""
    except asyncio.TimeoutError:
        raise MqttFehler("Paket bricht mitten ab (keine Daten mehr)")
    return kopf[0], rumpf


def publish_zerlegen(kopf: int, rumpf: bytes):
    """(thema, nutzlast, retain, qos, paketnummer)."""
    qos = (kopf >> 1) & 3
    retain = bool(kopf & 1)
    if len(rumpf) < 2:
        raise MqttFehler("PUBLISH ohne Thema")
    tl = struct.unpack("!H", rumpf[:2])[0]
    if len(rumpf) < 2 + tl:
        raise MqttFehler("PUBLISH kuerzer als sein Thema")
    thema = rumpf[2:2 + tl].decode("utf-8", "replace")
    pos = 2 + tl
    pid = None
    if qos:
        if len(rumpf) < pos + 2:
            raise MqttFehler("PUBLISH ohne Paketnummer")
        pid = struct.unpack("!H", rumpf[pos:pos + 2])[0]
        pos += 2
    return thema, rumpf[pos:], retain, qos, pid


# ---------------------------------------------------------------- Befehl

def seite_aufloesen(text: str, seiten: list):
    """(schluessel, '') oder ('', GRUND). Erst der Schluessel, dann die
    Nummer: eine Seite, die "2" heisst, gewinnt vor der zweiten Seite."""
    w = text.strip()
    schluessel = [str(s.get("schluessel") or "") for s in seiten if isinstance(s, dict)]
    if MUSTER_SEITE.match(w) and w in schluessel:
        return w, ""
    m = MUSTER_NUMMER.match(w)
    if m:
        n = int(m.group(1))
        if 1 <= n <= len(schluessel) and schluessel[n - 1]:
            return schluessel[n - 1], ""
        return "", "SEITE_UNBEKANNT"
    return "", ("SEITE_UNBEKANNT" if MUSTER_SEITE.match(w) else "SEITE_UNGUELTIG")


def _ganz(w, ersatz: int) -> int:
    """(int) wie in PHP fuer die Felder aus tafel.json: Zahl oder Ziffernfolge."""
    if isinstance(w, bool):
        return int(w)
    if isinstance(w, (int, float)):
        return int(w)
    if isinstance(w, str) and re.match(r"^\s*-?[0-9]+", w):
        return int(re.match(r"^\s*(-?[0-9]+)", w).group(1))
    return ersatz


def tafel_schreiben(felder: dict, takt, tafel_pfad: str, sperre_pfad: str,
                    json_lesen, json_schreiben, sperre) -> bool:
    """Dasselbe wie db_tafel_befehl() in db_lib.php: innerhalb von zwei
    Anzeigetakten (mindestens 2 s) bleiben die Felder des vorigen Befehls
    stehen, soweit der neue sie nicht nennt; danach gilt "nichts gesagt".
    Jeder Befehl erhoeht die laufende Nummer genau einmal."""
    fenster = max(2, 2 * max(1, min(30, _ganz(takt, 2))))
    with sperre(sperre_pfad):
        alt = json_lesen(tafel_pfad)
        d = {"seite": "", "wach": 0, "hell": -1, "ruhe": -1}
        ts_roh = alt.get("ts")
        ts = _ganz(ts_roh, 0) if isinstance(ts_roh, (int, float)) or (
            isinstance(ts_roh, str) and re.match(r"^\s*-?[0-9]+(\.[0-9]*)?\s*\Z", ts_roh)) else 0
        jetzt = int(time.time())
        abstand = jetzt - ts
        if ts > 0 and 0 <= abstand < fenster:
            for f in list(d):
                if f in alt:
                    d[f] = alt[f]
        for f in ("seite", "wach", "hell", "ruhe"):
            if f in felder:
                d[f] = felder[f]
        d["ts"] = jetzt
        d["nr"] = _ganz(alt.get("nr", 0), 0) + 1
        return bool(json_schreiben(tafel_pfad, d))


# ---------------------------------------------------------------- Abo

class TafelMqtt:
    """Haelt das Abo, solange laeuft() wahr ist. Alle Datei- und
    Konfigurationszugriffe kommen vom Dienst (dieselben Helfer wie dort)."""

    def __init__(self, praefix: str, *, general_pfad: str, tafel_pfad: str,
                 sperre_pfad: str, seiten_pfad: str, status_pfad: str,
                 config, json_lesen, json_schreiben, sperre, log, laeuft) -> None:
        self.praefix = praefix
        self.general_pfad = general_pfad
        self.tafel_pfad = tafel_pfad
        self.sperre_pfad = sperre_pfad
        self.seiten_pfad = seiten_pfad
        self.status_pfad = status_pfad
        self.config = config
        self.json_lesen = json_lesen
        self.json_schreiben = json_schreiben
        self.sperre = sperre
        self.log = log
        self.laeuft = laeuft
        self.stand = {"lage": "startet", "grund": "", "broker": "", "praefix": praefix,
                      "themen": themen(praefix), "seit": 0, "befehle": 0,
                      "verworfen": 0, "retained": 0, "letzter": {}}
        self._gemeldet: dict = {}

    # -- Protokoll und Stand
    def _gebremst(self, schluessel: str, text: str, *args) -> None:
        jetzt = time.monotonic()
        if jetzt - self._gemeldet.get(schluessel, -BREMSE - 1) >= BREMSE:
            self._gemeldet[schluessel] = jetzt
            self.log.warning(text, *args)

    def status(self, **aenderung) -> None:
        self.stand.update(aenderung)
        self.stand["ts"] = int(time.time())
        self.json_schreiben(self.status_pfad, dict(self.stand))

    # -- ein Befehl
    def befehl(self, thema: str, nutzlast: bytes, retain: bool) -> str:
        """Rueckgabe: was geschah (fuer Stand und Pruefung), nie die Nutzlast
        ungekuerzt."""
        p = self.praefix.split("/")
        t = thema.split("/")
        if len(t) != len(p) + 3 or t[:len(p)] != p or t[len(p)] != "tafel" \
                or t[len(p) + 2] not in ARTEN:
            return "FREMD"
        name, art = t[len(p) + 1], t[len(p) + 2]
        if retain:
            self.stand["retained"] += 1
            self._gebremst("retained_" + thema,
                           "Tafel ueber MQTT: zurueckbehaltener (retained) Befehl auf %s "
                           "verworfen - Tafelbefehle wirken nur, wenn sie gesendet werden, "
                           "nicht beim Verbinden.", thema)
            return "RETAINED"
        text = nutzlast[:100].decode("utf-8", "replace")
        grund = ""
        felder: dict = {}
        if name != NAME_ALLE:
            grund = "NAME"
        elif art == "seite":
            seiten = self.json_lesen(self.seiten_pfad).get("seiten")
            schl, grund = seite_aufloesen(text, seiten if isinstance(seiten, list) else [])
            if not grund:
                felder["seite"] = schl
        else:
            w = text.strip()
            if MUSTER_NUMMER.match(w) and int(MUSTER_NUMMER.match(w).group(1)) in (0, 1):
                if int(MUSTER_NUMMER.match(w).group(1)) == 1:
                    felder["wach"] = 1
                else:
                    # 0 heisst "nicht wecken" - dasselbe wie &wach=0 am
                    # Endpunkt: die Anzeigeseite tut dabei nichts.
                    felder["wach"] = 0
            else:
                grund = "WERT_UNGUELTIG"
        if grund:
            self.stand["verworfen"] += 1
            self._gebremst("ab_%s_%s" % (thema, grund),
                           "Tafel ueber MQTT: Befehl auf %s abgewiesen, Grund %s%s.", thema, grund,
                           " (nur der Name 'alle' ist vorgesehen)" if grund == "NAME" else "")
            self.stand["letzter"] = {"ts": int(time.time()), "thema": thema, "wirkung": grund}
            return grund
        cfg = self.config()
        if not tafel_schreiben(felder, cfg.get("takt"), self.tafel_pfad, self.sperre_pfad,
                               self.json_lesen, self.json_schreiben, self.sperre):
            self.stand["verworfen"] += 1
            self._gebremst("schreib", "Tafel ueber MQTT: tafel.json liess sich nicht schreiben.")
            self.stand["letzter"] = {"ts": int(time.time()), "thema": thema, "wirkung": "SCHREIBFEHLER"}
            return "SCHREIBFEHLER"
        self.stand["befehle"] += 1
        wirkung = ", ".join("%s=%s" % (k, v) for k, v in sorted(felder.items()))
        self.stand["letzter"] = {"ts": int(time.time()), "thema": thema, "wirkung": wirkung}
        return wirkung

    # -- Verbindung
    async def _sitzung(self, z: dict) -> None:
        reader, writer = await asyncio.wait_for(
            asyncio.open_connection(z["host"], z["port"]), 10)
        try:
            writer.write(connect_paket("dashboard-tafel-%d" % os.getpid(), z["user"], z["pass"]))
            await writer.drain()
            typ, rumpf = await paket_lesen(reader, 10, 10)
            if typ != 0x20 or len(rumpf) < 2:
                raise MqttFehler("keine gueltige Antwort auf CONNECT")
            if rumpf[1] != 0:
                raise MqttFehler("Broker lehnt die Anmeldung ab (CONNACK %d: %s)"
                                 % (rumpf[1], CONNACK_TEXT.get(rumpf[1], "?")))
            writer.write(subscribe_paket(1, themen(self.praefix)))
            await writer.drain()
            abonniert = False
            frist_suback = time.monotonic() + 10
            letzte_aktivitaet = time.monotonic()
            ping_offen = 0.0
            letzter_stand = 0.0
            while self.laeuft():
                try:
                    typ, rumpf = await paket_lesen(reader, 1.0)
                except asyncio.TimeoutError:
                    typ = None
                jetzt = time.monotonic()
                if typ is None:
                    pass
                elif typ & 0xF0 == 0x30:
                    letzte_aktivitaet = jetzt
                    thema, nutz, retain, qos, pid = publish_zerlegen(typ, rumpf)
                    if qos == 1 and pid is not None:
                        writer.write(b"\x40\x02" + struct.pack("!H", pid))
                        await writer.drain()
                    elif qos == 2:
                        raise MqttFehler("QoS 2 geliefert - abonniert war QoS 0")
                    if self.befehl(thema, nutz, retain) != "FREMD":
                        # Der Reiter Test zeigt Zaehler und letzten Befehl.
                        self.status()
                        letzter_stand = jetzt
                elif typ == 0x90:
                    letzte_aktivitaet = jetzt
                    codes = rumpf[2:]
                    if len(rumpf) < 3 or any(c >= 0x80 for c in codes):
                        raise MqttFehler("Broker lehnt das Abo ab (SUBACK %s) - Rechte des "
                                         "Benutzers am Broker pruefen" % codes.hex())
                    abonniert = True
                    self.log.info("Tafel ueber MQTT: verbunden mit %s:%d, Abo %s.",
                                  z["host"], z["port"], ", ".join(themen(self.praefix)))
                    self.status(lage="verbunden", grund="", seit=int(time.time()),
                                broker="%s:%d" % (z["host"], z["port"]))
                    letzter_stand = jetzt
                elif typ == 0xD0:
                    letzte_aktivitaet = jetzt
                    ping_offen = 0.0
                if not abonniert and jetzt > frist_suback:
                    raise MqttFehler("keine Antwort auf SUBSCRIBE")
                if ping_offen and jetzt - ping_offen > KEEPALIVE:
                    raise MqttFehler("Broker antwortet nicht mehr (PINGRESP fehlt)")
                if not ping_offen and jetzt - letzte_aktivitaet >= PING_NACH:
                    writer.write(PINGREQ)
                    await writer.drain()
                    ping_offen = jetzt
                if abonniert and jetzt - letzter_stand >= STATUS_TAKT:
                    self.status()
                    letzter_stand = jetzt
        finally:
            try:
                writer.write(DISCONNECT)
                await asyncio.wait_for(writer.drain(), 2)
            except Exception:
                pass
            writer.close()

    async def laufen(self) -> None:
        warte = 5
        while self.laeuft():
            z = zugang(self.json_lesen(self.general_pfad))
            if not z["port"]:
                self.status(lage="kein_broker", broker="",
                            grund="In der general.json steht kein Brokerport (Mqtt.Brokerport).")
                self._gebremst("kein_broker", "Tafel ueber MQTT: in der general.json steht "
                               "kein Brokerport - kein Abo; der virtuelle Ausgang wirkt weiter.")
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
                except (OSError, MqttFehler) as f:
                    grund = str(f) or f.__class__.__name__
                if self.stand.get("lage") == "verbunden":
                    warte = 5
                self.status(lage="getrennt", grund=grund[:200])
                self._gebremst("getrennt_" + grund[:60],
                               "Tafel ueber MQTT: keine Verbindung zum Broker %s (%s). Neuer "
                               "Versuch; der virtuelle Ausgang wirkt weiter.",
                               self.stand["broker"], grund[:200])
            for _ in range(warte * 2):
                if not self.laeuft():
                    break
                await asyncio.sleep(0.5)
            warte = min(120, warte * 2)
        self.status(lage="beendet", grund="Der Dienst wurde angehalten.")

    async def laufen_sicher(self) -> None:
        """laufen() mit Auffangnetz: ein unerwarteter Fehler steht im Protokoll
        und in der Standdatei, statt still in einer Aufgabe zu verschwinden."""
        try:
            await self.laufen()
        except asyncio.CancelledError:
            raise
        except Exception as f:  # noqa: BLE001 - bewusst breit, siehe oben
            self.log.error("Tafel ueber MQTT: unerwarteter Fehler (%s: %s) - das Abo ruht "
                           "bis zum naechsten Dienststart; der virtuelle Ausgang wirkt weiter.",
                           f.__class__.__name__, f)
            self.status(lage="fehler", grund=("%s: %s" % (f.__class__.__name__, f))[:200])
