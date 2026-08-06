#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Client fuer den Loxone Miniserver: Token-Anmeldung, WebSocket, Ereignistabellen.

Diese Datei baut KEIN Protokoll nach, sondern bedient das dokumentierte.
Alle Angaben stammen aus zwei Loxone-Dokumenten:

  [K] "Communicating with the Miniserver", Fassung 16.0 vom 03.06.2025
  [S] "Structure File", Fassung 16.0 vom 03.06.2025 (Abschnitte, die im Auszug
      fehlten, aus Fassung 12.2 und 8.3)

Die Stellen sind im Quelltext mit [K] beziehungsweise [S] belegt. Wo etwas
nicht belegt werden konnte, steht das ausdruecklich dabei.

Ablauf der Anmeldung [K, Seite 8-9, "Step-by-step guide"]:

   1. jdev/cfg/api            Erreichbarkeit, Fassung, httpsStatus
   2. jdev/sys/getPublicKey   oeffentlicher Schluessel (X.509 in ANS.1)
   3. WebSocket ws://<host>:<port>/ws/rfc6455, Unterprotokoll "remotecontrol"
   4. AES-256-Schluessel und 16-Byte-IV wuerfeln
   5. "{key}:{iv}" mit RSA verschluesseln -> Sitzungsschluessel (Base64)
   6. jdev/sys/keyexchange/{sitzungsschluessel}
   7. jdev/sys/getkey2/{user}  ->  key, salt, hashAlg
   8. pwHash  = GROSSBUCHSTABEN( hashAlg("{passwort}:{userSalt}") )
      hash    = HMAC-hashAlg( "{user}:{pwHash}", key )          [K, Seite 29-30]
   9. jdev/sys/getjwt/{hash}/{user}/{permission}/{uuid}/{info}
      MUSS verschluesselt gesendet werden - unverschluesselt weist der
      Miniserver mit 400 ab [K, Seite 30]
  10. jdev/sps/enablebinstatusupdate  -> ab jetzt kommen Ereignistabellen [K, S.18]

Zustaende kommen NICHT als Text "uuid:wert", wie oft behauptet, sondern als
binaere Ereignistabellen mit einem 8-Byte-Kopf davor [K, Seite 19-22].

Der HTTP-Rueckfall ist bewusst schlichter: er fragt je Zustand einzeln ab. Er
ist der Notnagel, nicht der Regelweg - bei vielen Kacheln belastet er den
Miniserver spuerbar. Deshalb sagt der Dienst auch deutlich, wenn er ihn benutzt.
"""

from __future__ import annotations

import asyncio
import base64
import hashlib
import hmac
import json
import logging
import re
import struct
import time
import urllib.parse
import urllib.request
import uuid as uuidmod
from typing import Any, Callable

_LOG = logging.getLogger("dashboard.lox")

# Rechte-Bitmaske [K, Seite 16]. 2 = Web (kurzlebig), 4 = App (langlebig).
# Wir nehmen 4: das Dashboard laeuft auf einem Wandtablet und soll nicht
# jeden Tag neu anmelden muessen.
RECHT_APP = 4

# Kennungen im Nachrichtenkopf [K, Seite 19-20]
KOPF_TEXT = 0
KOPF_BINDATEI = 1
KOPF_WERTE = 2
KOPF_TEXTE = 3
KOPF_TAGESZEIT = 4
KOPF_AUSSER_BETRIEB = 5
KOPF_KEEPALIVE = 6
KOPF_WETTER = 7


class LoxFehler(Exception):
    """Ein benannter Fehler. Die Meldung ist fuer den Anwender gedacht."""


# --------------------------------------------------------------------------
# Kleinteile
# --------------------------------------------------------------------------

def uuid_lesen(roh: bytes) -> str:
    """16 Byte -> Loxone-Schreibweise.

    [K, Seite 23]: Data1 32 Bit, Data2 16 Bit, Data3 16 Bit - alle little
    endian - dann 8 Byte roh. Ausgabe:
    "%08x-%04x-%04x-%02x%02x%02x%02x%02x%02x%02x%02x"

    Achtung: die letzten acht Byte werden OHNE Trennstrich angehaengt. Das
    weicht von der ueblichen UUID-Schreibweise ab und ist genau so gewollt -
    die Strukturdatei benutzt dieselbe Form.
    """
    d1, d2, d3 = struct.unpack_from("<IHH", roh, 0)
    rest = roh[8:16]
    return "%08x-%04x-%04x-%s" % (d1, d2, d3, rest.hex())


def _pkcs1_rsa(pubkey_pem: str, klartext: bytes) -> bytes:
    """RSA/ECB/PKCS1 [K, Seite 26]. Braucht 'cryptography'."""
    from cryptography.hazmat.primitives import serialization
    from cryptography.hazmat.primitives.asymmetric import padding

    schluessel = serialization.load_pem_public_key(pubkey_pem.encode("ascii"))
    return schluessel.encrypt(klartext, padding.PKCS1v15())


def _aes_cbc(schluessel: bytes, iv: bytes, klartext: bytes) -> bytes:
    """AES-256-CBC mit ZeroBytePadding [K, Seite 26].

    Ausdruecklich NICHT PKCS7: Loxone nennt ZeroBytePadding. Bei einem Text
    ohne Nullbytes ist das eindeutig entschluesselbar.
    """
    from cryptography.hazmat.primitives.ciphers import Cipher, algorithms, modes

    fehlt = (-len(klartext)) % 16
    gefuellt = klartext + b"\x00" * fehlt
    c = Cipher(algorithms.AES(schluessel), modes.CBC(iv)).encryptor()
    return c.update(gefuellt) + c.finalize()


def oeffentlicher_schluessel_aufbereiten(roh: str) -> str:
    """Loxone liefert den Schluessel mit CERTIFICATE- statt PUBLIC KEY-Rahmen.

    Der Inhalt ist trotzdem ein X.509-SubjectPublicKeyInfo. Ohne dieses
    Zurechtruecken lehnt jede PEM-Bibliothek ihn ab. Nachgesehen an einer
    echten Antwort ist der Rahmen:
        -----BEGIN CERTIFICATE----- <base64> -----END CERTIFICATE-----
    """
    kern = re.sub(r"-----(BEGIN|END)[A-Z ]*-----", "", roh).strip()
    kern = re.sub(r"\s+", "", kern)
    zeilen = [kern[i:i + 64] for i in range(0, len(kern), 64)]
    return "-----BEGIN PUBLIC KEY-----\n" + "\n".join(zeilen) + "\n-----END PUBLIC KEY-----\n"


def passwort_hash(passwort: str, user_salt: str, alg: str) -> str:
    """{pwHash} = GROSSBUCHSTABEN( alg("{passwort}:{userSalt}") ) [K, Seite 29]."""
    h = hashlib.sha256 if str(alg).upper() == "SHA256" else hashlib.sha1
    return h(("%s:%s" % (passwort, user_salt)).encode("utf-8")).hexdigest().upper()


def hmac_hash(text: str, key_hex: str, alg: str) -> str:
    """HMAC mit dem hex-kodierten Schluessel aus getkey2 [K, Seite 15].

    'the key ... are hex-encoded and might need to be converted to ASCII
    before being used' - gemeint ist: der Hex-String ist die Byte-Folge.
    Ergebnis wieder als Hex, unveraendert in der Gross-/Kleinschreibung.
    """
    h = hashlib.sha256 if str(alg).upper() == "SHA256" else hashlib.sha1
    return hmac.new(bytes.fromhex(key_hex), text.encode("utf-8"), h).hexdigest()


def _wert_aus_antwort(nachricht: str) -> Any:
    """Aus der LL-Antwort das Feld 'value' holen.

    Der Miniserver antwortet mit {"LL":{"control":..,"value":..,"Code":"200"}}.
    'Code' ist je nach Fassung Text oder Zahl - beides wird zugelassen.
    """
    try:
        d = json.loads(nachricht)
    except ValueError:
        raise LoxFehler("Der Miniserver hat etwas geantwortet, das kein JSON ist.")
    ll = d.get("LL") or d.get("ll") or {}
    code = str(ll.get("Code", ll.get("code", "")))
    if code and code != "200":
        raise LoxFehler("Der Miniserver hat mit Code %s geantwortet." % code)
    return ll.get("value")


# --------------------------------------------------------------------------
# Der Client
# --------------------------------------------------------------------------

class Miniserver:
    """Haelt eine Verbindung zum Miniserver und meldet Zustandsaenderungen.

    Zugangsdaten werden NIE auf der Kommandozeile uebergeben und nie in eine
    Logdatei geschrieben. Sie kommen aus der LoxBerry-Konfiguration, die der
    Anwender ohnehin schon gepflegt hat.
    """

    def __init__(self, host: str, port: int, benutzer: str, passwort: str,
                 tls: bool = False, kennung: str = "") -> None:
        self.host = host
        self.port = int(port)
        self.benutzer = benutzer
        self._passwort = passwort
        self.tls = bool(tls)
        # Die Kennung identifiziert diesen Client beim Miniserver [K, Seite 30].
        # Sie muss die dort genannte Form haben und ueber Neustarts gleich
        # bleiben, damit nicht bei jedem Start ein neues Token entsteht.
        self.kennung = kennung or self._kennung_bauen()
        self.ws = None
        self.token: dict = {}
        self.struktur: dict = {}
        self.zustaende: dict[str, Any] = {}
        self._aes_key = b""
        self._aes_iv = b""
        self._salt = ""
        self._pubkey = ""
        self._warten: dict[str, asyncio.Future] = {}
        self._kopf: tuple | None = None
        self.verbunden = False
        self.weg = ""          # 'websocket' oder 'http'
        self.letzter_fehler = ""
        self.auf_aenderung: Callable[[str, Any], None] | None = None

    # ---------------- Grundlagen ----------------

    def _kennung_bauen(self) -> str:
        """Form laut [K, Seite 30]: '098802e1-02b4-603c-ffffeee000d80cfd'."""
        r = uuidmod.uuid4().hex
        return "%s-%s-%s-%s" % (r[0:8], r[8:12], r[12:16], r[16:32])

    @property
    def _http_basis(self) -> str:
        return "%s://%s:%d" % ("https" if self.tls else "http", self.host, self.port)

    def _http(self, pfad: str, zeit: int = 10) -> str:
        """Ein unverschluesselter HTTP-Aufruf ohne Zugangsdaten.

        Nur fuer die drei Aufrufe, die ausdruecklich keine brauchen:
        jdev/cfg/api, jdev/sys/getPublicKey und der Rueckfallweg mit Token.
        """
        url = "%s/%s" % (self._http_basis, pfad.lstrip("/"))
        req = urllib.request.Request(url, headers={"User-Agent": "LoxBerry-Dashboard"})
        with urllib.request.urlopen(req, timeout=zeit) as a:
            return a.read().decode("utf-8", "replace")

    # ---------------- Anmeldung ----------------

    async def _befehl(self, cmd: str, zeit: int = 15) -> Any:
        """Einen Befehl ueber den WebSocket senden und auf die Antwort warten.

        Der Miniserver antwortet auf Textbefehle mit einem Nachrichtenkopf
        (Kennung 0) und danach der Textnachricht. Das Zusammensetzen macht
        _lesen(); hier wird nur auf das Ergebnis gewartet.
        """
        f: asyncio.Future = asyncio.get_running_loop().create_future()
        # Der Miniserver ordnet Antworten ueber das Feld 'control' zu - das ist
        # der gesendete Befehl. Bei verschluesselten Befehlen ist es der
        # verschluesselte Text, deshalb merken wir uns beides.
        self._warten["*"] = f
        await self.ws.send(cmd)
        try:
            return await asyncio.wait_for(f, timeout=zeit)
        finally:
            self._warten.pop("*", None)

    async def _befehl_verschluesselt(self, cmd: str, zeit: int = 15) -> Any:
        """jdev/sys/enc/... [K, Seite 27].

        'salt/{salt}/{cmd}' wird AES-verschluesselt, Base64 kodiert und
        URI-kodiert angehaengt. Der Salt wird nach jedem Befehl gewechselt -
        das verlangt [K, Seite 9] ausdruecklich gegen Wiedereinspielungen.
        """
        klar = "salt/%s/%s" % (self._salt, cmd)
        cipher = _aes_cbc(self._aes_key, self._aes_iv, klar.encode("utf-8"))
        b64 = base64.b64encode(cipher).decode("ascii")
        voll = "jdev/sys/enc/" + urllib.parse.quote(b64, safe="")
        try:
            return await self._befehl(voll, zeit)
        finally:
            self._salt = self._neuer_salt()

    @staticmethod
    def _neuer_salt() -> str:
        import os
        return os.urandom(2).hex()

    async def _schluesseltausch(self) -> None:
        import os
        self._aes_key = os.urandom(32)
        self._aes_iv = os.urandom(16)
        self._salt = self._neuer_salt()
        nutzlast = ("%s:%s" % (self._aes_key.hex(), self._aes_iv.hex())).encode("ascii")
        sitzung = base64.b64encode(_pkcs1_rsa(self._pubkey, nutzlast)).decode("ascii")
        await self._befehl("jdev/sys/keyexchange/" + urllib.parse.quote(sitzung, safe=""))

    async def _token_holen(self) -> dict:
        roh = await self._befehl("jdev/sys/getkey2/%s" % urllib.parse.quote(self.benutzer))
        if not isinstance(roh, dict):
            raise LoxFehler("Die Antwort auf getkey2 hatte nicht die erwartete Form.")
        key = str(roh.get("key", ""))
        salt = str(roh.get("salt", ""))
        alg = str(roh.get("hashAlg", "SHA1"))
        if not key or not salt:
            raise LoxFehler("Der Miniserver hat auf getkey2 keinen Schluessel geliefert. "
                            "Meist stimmt der Benutzername nicht.")
        pw = passwort_hash(self._passwort, salt, alg)
        h = hmac_hash("%s:%s" % (self.benutzer, pw), key, alg)
        cmd = "jdev/sys/getjwt/%s/%s/%d/%s/%s" % (
            h, urllib.parse.quote(self.benutzer), RECHT_APP, self.kennung,
            urllib.parse.quote("LoxBerry Dashboard"))
        # Muss verschluesselt sein [K, Seite 30].
        try:
            antwort = await self._befehl_verschluesselt(cmd)
        except LoxFehler as f:
            # Der blosse Code hilft niemandem weiter. 401 heisst an dieser
            # Stelle immer dasselbe, und das gehoert dem Anwender gesagt.
            if "401" in str(f):
                raise LoxFehler(
                    "Der Miniserver hat das Token verweigert (401). Fast immer "
                    "stimmt das Passwort nicht - oder der Benutzer hat in Loxone "
                    "Config kein Recht fuer die Visualisierung.") from f
            raise
        if not isinstance(antwort, dict) or not antwort.get("token"):
            raise LoxFehler("Der Miniserver hat kein Token ausgestellt, aber auch "
                            "keinen Fehler genannt. Die Antwort war unerwartet.")
        return antwort

    async def _mit_token_anmelden(self, token: str) -> bool:
        try:
            roh = await self._befehl("jdev/sys/getkey")
            key = str(roh) if not isinstance(roh, dict) else str(roh.get("key", ""))
            if not key:
                return False
            # Seit 11.2 darf das Token auch im Klartext stehen [K, Seite 31].
            # Wir hashen trotzdem - das geht auf jeder Fassung ab 9.0.
            h = hmac_hash(token, key, "SHA256")
            await self._befehl_verschluesselt(
                "authwithtoken/%s/%s" % (h, urllib.parse.quote(self.benutzer)))
            return True
        except (LoxFehler, asyncio.TimeoutError, OSError) as f:
            _LOG.info("Anmeldung mit vorhandenem Token misslungen: %s", f)
            return False

    # ---------------- Verbindung ----------------

    async def verbinden(self, altes_token: str = "") -> None:
        import websockets

        api = json.loads(self._http("jdev/cfg/api")).get("LL", {}).get("value", "{}")
        if isinstance(api, str):
            try:
                api = json.loads(api.replace("'", '"'))
            except ValueError:
                api = {}
        _LOG.info("Miniserver erreichbar: Version %s", (api or {}).get("version", "?"))

        self._pubkey = oeffentlicher_schluessel_aufbereiten(
            str(_wert_aus_antwort(self._http("jdev/sys/getPublicKey"))))

        url = "%s://%s:%d/ws/rfc6455" % ("wss" if self.tls else "ws", self.host, self.port)
        # 'remotecontrol' als Unterprotokoll [K, Seite 8, Punkt 3b].
        self.ws = await websockets.connect(
            url, subprotocols=["remotecontrol"], open_timeout=15,
            ping_interval=None, max_size=None)

        self._leser = asyncio.ensure_future(self._lesen())
        await self._schluesseltausch()

        if altes_token and await self._mit_token_anmelden(altes_token):
            self.token = {"token": altes_token}
            _LOG.info("Mit dem gespeicherten Token angemeldet.")
        else:
            self.token = await self._token_holen()
            _LOG.info("Neues Token erhalten, gueltig bis %s (Sekunden seit 2009).",
                      self.token.get("validUntil"))
            if self.token.get("unsecurePass"):
                _LOG.warning("Der Miniserver meldet ein schwaches Passwort fuer diesen "
                             "Benutzer. Das sollte geaendert werden.")

        self.verbunden = True
        self.weg = "websocket"
        self.letzter_fehler = ""

    async def struktur_holen(self) -> dict:
        """data/LoxAPP3.json kommt als Textnachricht [K, Seite 21]."""
        roh = await self._befehl("data/LoxAPP3.json", zeit=60)
        if isinstance(roh, dict) and "controls" in roh:
            self.struktur = roh
            return roh
        raise LoxFehler("Die Strukturdatei kam nicht in der erwarteten Form an.")

    async def zustaende_anfordern(self) -> None:
        """Ohne diesen Befehl kommt kein einziger Wert [K, Seite 18]."""
        await self._befehl("jdev/sps/enablebinstatusupdate", zeit=30)

    async def befehl_senden(self, uuid: str, befehl: str) -> Any:
        """jdev/sps/io/{uuid}/{befehl} [K, Seite 13].

        Der Aufrufer hat bereits gegen die Positivliste geprueft. Hier wird
        trotzdem noch einmal auf die Form geachtet - eine zweite Pruefung an
        der Stelle, wo es wirklich hinausgeht, kostet nichts.
        """
        if not re.match(r"^[0-9a-fA-F-]{8,60}$", uuid):
            raise LoxFehler("Das ist keine gueltige UUID.")
        if not re.match(r"^[A-Za-z0-9_./+%:-]{1,120}$", befehl):
            raise LoxFehler("Das ist kein gueltiger Befehl.")
        return await self._befehl("jdev/sps/io/%s/%s" % (uuid, befehl))

    async def keepalive(self) -> None:
        """[K, Seite 34]: Der Miniserver antwortet mit Kennung 6."""
        await self.ws.send("keepalive")

    async def schliessen(self) -> None:
        self.verbunden = False
        try:
            if self.ws is not None:
                await self.ws.close()
        except Exception:
            pass

    # ---------------- Empfangsschleife ----------------

    async def _lesen(self) -> None:
        """Kopf und Nutzlast kommen als ZWEI getrennte Nachrichten [K, Seite 19]."""
        try:
            async for nachricht in self.ws:
                if isinstance(nachricht, bytes) and len(nachricht) == 8 \
                        and nachricht[0] == 0x03 and self._kopf is None:
                    kennung = nachricht[1]
                    info = nachricht[2]
                    laenge = struct.unpack_from("<I", nachricht, 4)[0]
                    if info & 0x01:
                        # Geschaetzt - es folgt ein genauer Kopf [K, Seite 20].
                        continue
                    if kennung == KOPF_KEEPALIVE:
                        continue
                    if kennung == KOPF_AUSSER_BETRIEB:
                        _LOG.warning("Der Miniserver meldet 'ausser Betrieb' "
                                     "(meist eine Aktualisierung) und trennt gleich.")
                        continue
                    self._kopf = (kennung, laenge)
                    continue

                kennung = self._kopf[0] if self._kopf else KOPF_TEXT
                self._kopf = None

                if kennung == KOPF_WERTE and isinstance(nachricht, bytes):
                    self._werte_lesen(nachricht)
                elif kennung == KOPF_TEXTE and isinstance(nachricht, bytes):
                    self._texte_lesen(nachricht)
                elif kennung in (KOPF_TAGESZEIT, KOPF_WETTER):
                    # Tageszeit- und Wettertabellen werden bewusst nicht
                    # ausgewertet: keine Kachel braucht sie, und ein halb
                    # verstandener Datensatz ist schlechter als keiner.
                    pass
                elif isinstance(nachricht, (str, bytes)):
                    self._text_verarbeiten(
                        nachricht.decode("utf-8", "replace")
                        if isinstance(nachricht, bytes) else nachricht)
        except asyncio.CancelledError:
            raise
        except Exception as f:
            self.verbunden = False
            self.letzter_fehler = str(f)
            _LOG.warning("Die Verbindung zum Miniserver ist abgerissen: %s", f)

    def _text_verarbeiten(self, text: str) -> None:
        f = self._warten.get("*")
        if f is None or f.done():
            return
        # Zwei Sorten Textnachricht: eine LL-Antwort auf einen Befehl, oder
        # eine ganze Datei (LoxAPP3.json). Unterschieden wird am LL-Umschlag -
        # NICHT daran, ob sich der Text als JSON lesen laesst: die
        # Strukturdatei ist ebenfalls JSON und wuerde sonst als leere Antwort
        # durchgehen.
        try:
            d = json.loads(text)
        except ValueError:
            f.set_result(text)
            return
        if isinstance(d, dict) and ("LL" in d or "ll" in d):
            try:
                f.set_result(_wert_aus_antwort(text))
            except LoxFehler as fehler:
                f.set_exception(fehler)
            return
        f.set_result(d)

    def _merken(self, uuid: str, wert: Any) -> None:
        alt = self.zustaende.get(uuid)
        if alt == wert:
            return
        self.zustaende[uuid] = wert
        if self.auf_aenderung is not None:
            try:
                self.auf_aenderung(uuid, wert)
            except Exception as f:
                _LOG.warning("Melder hat einen Fehler geworfen: %s", f)

    def _werte_lesen(self, roh: bytes) -> None:
        """Je Eintrag 24 Byte: 16 Byte UUID + 8 Byte double [K, Seite 21]."""
        for i in range(0, len(roh) - 23, 24):
            uuid = uuid_lesen(roh[i:i + 16])
            (wert,) = struct.unpack_from("<d", roh, i + 16)
            self._merken(uuid, wert)

    def _texte_lesen(self, roh: bytes) -> None:
        """UUID, Icon-UUID, Laenge, Text - auf ein Vielfaches von 4 aufgefuellt.

        [K, Seite 22]: 'If textLength is not a multiple of 4 then padding bytes
        are appended, that are to be ignored.'
        """
        i = 0
        n = len(roh)
        while i + 36 <= n:
            uuid = uuid_lesen(roh[i:i + 16])
            laenge = struct.unpack_from("<I", roh, i + 32)[0]
            start = i + 36
            ende = start + laenge
            if ende > n:
                break
            self._merken(uuid, roh[start:ende].decode("utf-8", "replace"))
            i = start + laenge + ((-laenge) % 4)

    # ---------------- Rueckfall auf HTTP ----------------

    def http_zustand(self, uuid: str) -> Any:
        """Einen einzelnen Zustand ueber HTTP holen - der Notnagel.

        Das Token wird als Parameter angehaengt [K, Seite 31]:
        '?autht={hash}&user={user}'. Ohne gueltiges Token weist der
        Miniserver mit 401 ab. Es wird bewusst NICHT auf Basic-Auth
        zurueckgefallen: das Passwort stuende dann in der Adresse.
        """
        token = str(self.token.get("token", ""))
        if not token:
            raise LoxFehler("Fuer den HTTP-Weg fehlt ein gueltiges Token.")
        schluessel = str(_wert_aus_antwort(self._http("jdev/sys/getkey")))
        h = hmac_hash(token, schluessel, "SHA256")
        pfad = "jdev/sps/io/%s/state?autht=%s&user=%s" % (
            uuid, h, urllib.parse.quote(self.benutzer))
        return _wert_aus_antwort(self._http(pfad))


# --------------------------------------------------------------------------
# Selbstpruefung ohne Miniserver
# --------------------------------------------------------------------------

def selbstpruefung() -> list[tuple[bool, str]]:
    """Prueft die Teile, die sich ohne Miniserver pruefen lassen.

    Die Erwartungswerte stammen aus den Loxone-Dokumenten, nicht aus dieser
    Datei - sonst wuerde sich der Quelltext selbst bestaetigen.
    """
    e: list[tuple[bool, str]] = []

    # UUID-Wandlung: Beispiel aus [K, Seite 23] rueckwaerts gerechnet.
    roh = struct.pack("<IHH", 0x0af17bf3, 0x0125, 0x029b) + bytes.fromhex("ffff112233445566")
    ist = uuid_lesen(roh)
    soll = "0af17bf3-0125-029b-ffff112233445566"
    e.append((ist == soll, "UUID-Wandlung: %s (Soll %s)" % (ist, soll)))

    # Passwort-Hash: Gross geschrieben, mit Doppelpunkt getrennt [K, Seite 29].
    ist = passwort_hash("geheim", "abcd", "SHA256")
    soll = hashlib.sha256(b"geheim:abcd").hexdigest().upper()
    e.append((ist == soll, "Passwort-Hash SHA256 in Grossbuchstaben"))
    ist = passwort_hash("geheim", "abcd", "SHA1")
    soll = hashlib.sha1(b"geheim:abcd").hexdigest().upper()
    e.append((ist == soll, "Passwort-Hash SHA1 in Grossbuchstaben"))

    # HMAC mit hex-kodiertem Schluessel [K, Seite 15].
    ist = hmac_hash("admin:ABC", "0011aabb", "SHA1")
    soll = hmac.new(bytes.fromhex("0011aabb"), b"admin:ABC", hashlib.sha1).hexdigest()
    e.append((ist == soll, "HMAC-SHA1 mit hex-kodiertem Schluessel"))

    # AES mit ZeroBytePadding [K, Seite 26]: Laenge immer ein Vielfaches von 16.
    c = _aes_cbc(b"\x01" * 32, b"\x02" * 16, b"salt/ab/jdev/sps/io/x/on")
    e.append((len(c) % 16 == 0 and len(c) == 32, "AES-256-CBC mit Nullfuellung: %d Byte" % len(c)))

    # Werte-Ereignistabelle: 24 Byte je Eintrag [K, Seite 21].
    m = Miniserver.__new__(Miniserver)
    m.zustaende = {}
    m.auf_aenderung = None
    paket = roh + struct.pack("<d", 21.5) + roh + struct.pack("<d", 0.0)
    Miniserver._werte_lesen(m, paket)
    e.append((m.zustaende.get(soll if False else "0af17bf3-0125-029b-ffff112233445566") == 0.0
              and len(m.zustaende) == 1,
              "Werte-Tabelle: 2 Eintraege gelesen, letzter gewinnt"))

    # Text-Ereignistabelle mit Fuellbytes [K, Seite 22].
    m.zustaende = {}
    text = "Kueche".encode("utf-8")           # 6 Byte -> 2 Byte Fuellung
    paket = roh + b"\x00" * 16 + struct.pack("<I", len(text)) + text + b"\x00\x00"
    Miniserver._texte_lesen(m, paket)
    e.append((m.zustaende.get("0af17bf3-0125-029b-ffff112233445566") == "Kueche",
              "Text-Tabelle mit Fuellbytes gelesen"))

    # Kennung: die von Loxone genannte Form [K, Seite 30].
    k = Miniserver._kennung_bauen(None)  # type: ignore[arg-type]
    e.append((re.match(r"^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{16}$", k) is not None,
              "Client-Kennung in der dokumentierten Form: %s" % k))

    return e


if __name__ == "__main__":
    fehlt = 0
    for ok, text in selbstpruefung():
        print(("[OK]   " if ok else "[FEHL] ") + text)
        fehlt += 0 if ok else 1
    raise SystemExit(1 if fehlt else 0)
