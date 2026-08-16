#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Client fuer den Loxone Miniserver: Token-Anmeldung, WebSocket, Ereignistabellen.

Diese Datei baut KEIN Protokoll nach, sondern bedient das dokumentierte.
Alle Angaben stammen aus zwei Loxone-Dokumenten:

  [K] "Communicating with the Miniserver", Fassung 17.0 vom 31.03.2026
  [S] "Structure File", Fassung 17.0 vom 31.03.2026

Bis 0.9.5 war gegen Fassung 16.0 gebaut. Der Abgleich auf 17.0 hat drei
Stellen geaendert (Alarm: nextLevelDelay und sensors sind seit Config 13.0
abgekuendigt; Radio: next/prev und die Ausgangsnamen aus den Details;
Meter: totalDay/totalWeek). Der Raumregler war unveraendert richtig.

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
import collections
import hashlib
import hmac
import json
import logging
import re
import ssl
import struct
import sys
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
    # Laenge zuerst pruefen. struct.unpack_from wirft sonst struct.error,
    # und der flaechse bis in die Leseschleife des WebSockets - ein einziges
    # verstuemmeltes Paket wuerfe damit die ganze Verbindung ab. Ein
    # unbrauchbarer Wert ist besser als ein abgebrochener Dienst.
    if roh is None or len(roh) < 16:
        return ""
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
                 tls: bool = False, kennung: str = "", hashalg: str = "") -> None:
        self.host = host
        self.port = int(port)
        self.benutzer = benutzer
        self._passwort = passwort
        self.tls = bool(tls)
        # Die Kennung identifiziert diesen Client beim Miniserver [K, Seite 30].
        # Sie muss die dort genannte Form haben und ueber Neustarts gleich
        # bleiben, damit nicht bei jedem Start ein neues Token entsteht.
        self.kennung = kennung or self._kennung_bauen()
        # Das Hashverfahren des Benutzers kommt aus getkey2 [K, Seite 15]. Es
        # gehoert zum Benutzer, nicht zur Fassung - deshalb wird es gemerkt und
        # beim naechsten Start fuer authwithtoken wiederverwendet, statt SHA256
        # zu raten (das war bis 0.9.5 der Fall und kostete bei jedem Start ein
        # neues Token).
        self.hashalg = str(hashalg).upper() if hashalg else ""
        self.ws = None
        self.token: dict = {}
        self.struktur: dict = {}
        self.zustaende: dict[str, Any] = {}
        self._aes_key = b""
        self._aes_iv = b""
        self._salt = ""
        self._voriger_salt = ""
        self._pubkey = ""
        self._fingerabdruck_gemeldet = False
        # Antworten kommen in der Reihenfolge der Befehle [K, Seite 19]:
        # Textnachrichten sind immer Antworten, nie unaufgefordert. Deshalb
        # eine Schlange statt eines einzelnen Platzes - und ein Zaehler fuer
        # verspaetete Antworten, deren Befehl schon aufgegeben hat. Ohne den
        # bekaeme der NAECHSTE Befehl die alte Antwort, und die Verschiebung
        # bliebe fuer den Rest der Sitzung bestehen (Befund 0.9.5).
        self._warten: collections.deque = collections.deque()
        self._verwerfen = 0
        self._kopf: tuple | None = None
        self._leser: asyncio.Future | None = None
        self._selbst_geschlossen = False
        # Tabellen, die bewusst nicht ausgewertet werden - gezaehlt, damit der
        # Reiter Test sagen kann, dass sie ankommen.
        self.uebergangen: dict[str, int] = {"tageszeit": 0, "wetter": 0, "datei": 0}
        self.verbunden = False
        self.weg = ""          # 'websocket' oder 'http'
        self.letzter_fehler = ""
        self.auf_aenderung: Callable[[str, Any], None] | None = None

    # ---------------- Grundlagen ----------------

    def _kennung_bauen(self) -> str:
        """Form laut [K, Seite 30]: '098802e1-02b4-603c-ffffeee000d80cfd'."""
        r = uuidmod.uuid4().hex
        return "%s-%s-%s-%s" % (r[0:8], r[8:12], r[12:16], r[16:32])

    # ---------------- TLS ----------------

    def _ssl_kontext(self) -> "ssl.SSLContext | None":
        """SSL-Kontext fuer den Miniserver - oder None ohne TLS.

        Ein Miniserver traegt ein SELBSTSIGNIERTES Zertifikat. Es gibt keine
        Zertifizierungsstelle, die es beglaubigt, und es lautet auf keinen
        Namen, den ein Zertifikat tragen koennte - man spricht ihn ueber seine
        IP-Adresse im eigenen Netz an.

        Bis 0.9.0 wurde gar kein Kontext uebergeben. Python prueft dann nach
        den Regeln des offenen Internets, und die Verbindung brach mit
        CERTIFICATE_VERIFY_FAILED ab, bevor ein einziges Byte floss. Mit
        eingeschaltetem TLS lief das Plugin also ueberhaupt nicht.

        WAS DIESE EINSTELLUNG BEDEUTET, und das gehoert ausgesprochen:
        die Verbindung ist damit VERSCHLUESSELT, aber der Gegenueber ist
        NICHT BEGLAUBIGT. Wer sich zwischen LoxBerry und Miniserver haengen
        kann, koennte sich als Miniserver ausgeben. Im heimischen Netz, in
        dem beide Geraete stehen, ist das die uebliche und vertretbare
        Abwaegung - im Internet waere sie es nicht. Deshalb sollte der
        Miniserver auch nicht am Internet haengen.

        Damit die Abwaegung nachpruefbar bleibt, wird der Fingerabdruck des
        Zertifikats einmal ins Protokoll geschrieben. Aendert er sich, ohne
        dass jemand am Miniserver etwas getan hat, ist das ein Grund
        nachzusehen.
        """
        if not self.tls:
            return None
        ctx = ssl.create_default_context()
        # Nicht ssl._create_unverified_context(): das ist eine private
        # Funktion, und wer sie liest, sieht ihr nicht an, was sie abschaltet.
        ctx.check_hostname = False
        ctx.verify_mode = ssl.CERT_NONE
        return ctx

    def _fingerabdruck_melden(self) -> None:
        """Den Fingerabdruck des Zertifikats einmal ins Protokoll schreiben."""
        if not self.tls or self._fingerabdruck_gemeldet:
            return
        self._fingerabdruck_gemeldet = True
        try:
            # Der Parameter 'timeout' gibt es erst ab Python 3.10; postinstall
            # laesst ab 3.8 zu. Ohne diese Fallunterscheidung wirft der Aufruf
            # dort TypeError, der Fingerabdruck wurde also NIE protokolliert -
            # obwohl die Beschreibung oben ihn zusichert (Befund 0.9.5).
            if sys.version_info >= (3, 10):
                roh = ssl.get_server_certificate((self.host, self.port), timeout=5)
            else:
                roh = ssl.get_server_certificate((self.host, self.port))
            der = ssl.PEM_cert_to_DER_cert(roh)
            abdruck = hashlib.sha256(der).hexdigest()
            _LOG.info("TLS: Zertifikat des Miniservers, SHA-256 %s",
                      ":".join(abdruck[i:i + 2] for i in range(0, len(abdruck), 2)).upper())
            _LOG.info("TLS: das Zertifikat ist selbstsigniert und wird deshalb nicht "
                      "beglaubigt - die Verbindung ist verschluesselt, der Gegenueber "
                      "aber nicht geprueft. Aendert sich der Fingerabdruck oben ohne "
                      "Ihr Zutun, bitte nachsehen.")
        except Exception as f:  # noqa: BLE001
            _LOG.debug("TLS-Fingerabdruck nicht ermittelbar: %s", f)

    @property
    def _http_basis(self) -> str:
        return "%s://%s:%d" % ("https" if self.tls else "http", self.host, self.port)

    def _http(self, pfad: str, zeit: int = 10) -> str:
        """Ein unverschluesselter HTTP-Aufruf ohne Zugangsdaten.

        Nur fuer die drei Aufrufe, die ausdruecklich keine brauchen:
        jdev/cfg/api, jdev/sys/getPublicKey und der Rueckfallweg mit Token.

        Ohne eigenen Oeffner nimmt urllib die Proxy-Einstellung aus dem
        Umfeld. Der Miniserver steht im eigenen Netz; ein Proxy dazwischen ist
        nie richtig, und wenn 'no_proxy' ihn nicht ausnimmt, laeuft die
        Anfrage stumm ins Leere.
        """
        url = "%s/%s" % (self._http_basis, pfad.lstrip("/"))
        req = urllib.request.Request(url, headers={
            "User-Agent": "LoxBerry-Dashboard",
            "Accept": "application/json, text/plain, */*",
            "Accept-Language": "de,en;q=0.8",
            "Accept-Encoding": "identity",
        })
        oeffner = urllib.request.build_opener(
            urllib.request.ProxyHandler({}),
            urllib.request.HTTPSHandler(context=self._ssl_kontext()))
        with oeffner.open(req, timeout=zeit) as a:
            return a.read().decode("utf-8", "replace")

    # ---------------- Anmeldung ----------------

    async def _befehl(self, cmd: str, zeit: int = 15) -> Any:
        """Einen Befehl ueber den WebSocket senden und auf die Antwort warten.

        Der Miniserver antwortet auf Textbefehle mit einem Nachrichtenkopf
        (Kennung 0) und danach der Textnachricht. Das Zusammensetzen macht
        _lesen(); hier wird nur auf das Ergebnis gewartet.

        Die Zuordnung laeuft ueber die REIHENFOLGE, nicht ueber das Feld
        'control': bei einem verschluesselten Befehl steht dort der
        verschluesselte Text, und ein Vergleich damit waere Ratearbeit.
        Antworten kommen laut [K, Seite 19] in der Reihenfolge der Befehle.

        Der Preis dieser Zuordnung ist die Zeitueberschreitung: gibt ein
        Befehl auf, kommt seine Antwort trotzdem noch. Sie wird gezaehlt und
        beim Eintreffen verworfen. Bis 0.9.5 landete sie beim naechsten
        Befehl, und die Verschiebung blieb fuer die ganze Sitzung - ein
        Schaltbefehl bekam dann die Antwort der Versionsabfrage, und
        struktur_holen() bekam eine Schaltbestaetigung.
        """
        if self.ws is None:
            raise LoxFehler("Es besteht keine Verbindung zum Miniserver.")
        f: asyncio.Future = asyncio.get_running_loop().create_future()
        self._warten.append(f)
        await self.ws.send(cmd)
        try:
            return await asyncio.wait_for(f, timeout=zeit)
        except asyncio.TimeoutError:
            try:
                self._warten.remove(f)
                # Nur zaehlen, wenn die Zukunft noch in der Schlange stand.
                # War sie schon entnommen, ist die Antwort bereits da gewesen.
                self._verwerfen += 1
            except ValueError:
                pass
            raise LoxFehler(
                "Der Miniserver hat auf '%s' innerhalb von %d s nicht geantwortet."
                % (cmd.split("/")[0] + "/...", zeit))

    async def _befehl_verschluesselt(self, cmd: str, zeit: int = 15) -> Any:
        """jdev/sys/enc/... [K, Seite 27].

        Der Klartext wird AES-verschluesselt, Base64 kodiert und URI-kodiert
        angehaengt. Der Salt wird nach jedem Befehl gewechselt - das verlangt
        [K, Seite 9] ausdruecklich gegen Wiedereinspielungen.

        Zwei Formen, und die Unterscheidung ist nicht kosmetisch [K, Seite 8]:

            salt/{salt}/{cmd}                        beim ersten Befehl
            nextSalt/{prevSalt}/{nextSalt}/{cmd}     bei jedem Salt-Wechsel

        Bis 0.9.5 wurde immer die erste Form gesendet, obwohl der Salt vorher
        gewechselt worden war. Das trifft den zweiten verschluesselten Befehl
        einer Sitzung - also genau den Fall 'gespeichertes Token abgelaufen,
        danach getjwt'.
        """
        klar = self._enc_klartext(cmd)
        cipher = _aes_cbc(self._aes_key, self._aes_iv, klar.encode("utf-8"))
        b64 = base64.b64encode(cipher).decode("ascii")
        voll = "jdev/sys/enc/" + urllib.parse.quote(b64, safe="")
        try:
            return await self._befehl(voll, zeit)
        finally:
            self._voriger_salt = self._salt
            self._salt = self._neuer_salt()

    def _enc_klartext(self, cmd: str) -> str:
        """Der Klartext vor dem Verschluesseln - eigene Funktion, damit die
        Selbstpruefung genau diesen Code misst und nicht eine Kopie davon."""
        if self._voriger_salt and self._voriger_salt != self._salt:
            return "nextSalt/%s/%s/%s" % (self._voriger_salt, self._salt, cmd)
        return "salt/%s/%s" % (self._salt, cmd)

    @staticmethod
    def _neuer_salt() -> str:
        import os
        return os.urandom(2).hex()

    async def _schluesseltausch(self) -> None:
        import os
        self._aes_key = os.urandom(32)
        self._aes_iv = os.urandom(16)
        self._salt = self._neuer_salt()
        self._voriger_salt = ""
        nutzlast = ("%s:%s" % (self._aes_key.hex(), self._aes_iv.hex())).encode("ascii")
        sitzung = base64.b64encode(_pkcs1_rsa(self._pubkey, nutzlast)).decode("ascii")
        # Der Sitzungsschluessel geht ROH ueber die Leitung - NICHT URI-kodiert.
        #
        # Das Dokument unterscheidet drei Stellen, und nur zwei davon werden
        # kodiert [K, "Step-by-step Guide HTTP Requests" und "Sending
        # encrypted commands over the websocket"]:
        #
        #   Schritt 11 (HTTP):      "URI-Component-Encode the {session-key}"
        #                           - dort steht er in ?sk=, also im Abfrageteil
        #   enc ueber WebSocket:    "URI-Component-Encode the {cipher}"
        #   Schritt 7 (WebSocket):  "Pass encrypted session-key to Miniserver
        #                           via jdev/sys/keyexchange/{...}" - KEINE
        #                           Kodierung genannt, nur Base64 aus Schritt 6
        #
        # Bis 0.9.6 wurde auch hier kodiert. Am 16.08.2026 an einem Miniserver
        # mit Fassung 17.1.7.27 gemessen:
        #
        #   URI-kodiert  -> Code 401     roh -> Code 200
        #
        # Die 401 ist dabei keine Anmeldefrage: an dieser Stelle ist noch kein
        # Kennwort im Spiel. Der Miniserver bekommt schlicht kein
        # entschluesselbares Paket - laut Dokument antwortet er darauf mit 401
        # ("If it cannot be decrypted ... it will return 401").
        #
        # Die Attrappe hat das NICHT gefunden: sie ruft beim Entschluesseln
        # urllib.parse.unquote() auf und nimmt deshalb beide Schreibweisen an.
        # Sie war dem Client nachgebaut, nicht dem Geraet.
        await self._befehl("jdev/sys/keyexchange/" + sitzung)

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
        # Das Verfahren gehoert zum Benutzer und wird fuer authwithtoken beim
        # naechsten Start gebraucht [K, Seite 15]. Der Aufrufer legt es neben
        # dem Token ab.
        self.hashalg = alg.upper()
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
        """Wiederanmeldung mit einem gespeicherten Token [K, Seite 31].

        Das Verfahren ist das des BENUTZERS aus getkey2, nicht pauschal
        SHA256. Bis 0.9.5 stand SHA256 fest im Code; bei einem Benutzer mit
        SHA1 schlug die Wiederanmeldung deshalb jedes Mal fehl. Das fiel nicht
        auf, weil sauber auf ein frisches Token zurueckgefallen wird - der
        Miniserver stellte dann bei JEDEM Dienststart ein neues aus.

        Das gemerkte Verfahren steht in zugang.json. Fehlt es (erste
        Aktualisierung auf 0.9.6), werden beide der Reihe nach versucht.
        """
        verfahren = [self.hashalg] if self.hashalg else ["SHA256", "SHA1"]
        for alg in verfahren:
            try:
                roh = await self._befehl("jdev/sys/getkey")
                key = str(roh) if not isinstance(roh, dict) else str(roh.get("key", ""))
                if not key:
                    return False
                h = hmac_hash(token, key, alg)
                await self._befehl_verschluesselt(
                    "authwithtoken/%s/%s" % (h, urllib.parse.quote(self.benutzer)))
                self.hashalg = alg
                return True
            except (LoxFehler, asyncio.TimeoutError, OSError) as f:
                _LOG.info("Anmeldung mit vorhandenem Token misslungen (%s): %s", alg, f)
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

        self._fingerabdruck_melden()
        url = "%s://%s:%d/ws/rfc6455" % ("wss" if self.tls else "ws", self.host, self.port)
        # 'remotecontrol' als Unterprotokoll [K, Seite 8, Punkt 3b].
        args = dict(subprotocols=["remotecontrol"], open_timeout=15,
                    ping_interval=None, max_size=None, ssl=self._ssl_kontext())
        # Ab websockets 14.2 hat connect() die Vorgabe proxy=True und liest
        # http_proxy/https_proxy aus dem Umfeld. Der Miniserver steht im
        # eigenen Netz - ein Proxy davor ist nie richtig. Die aeltere Fassung
        # (Debian 12 liefert 10.4) kennt den Parameter nicht und reicht
        # unbekannte Argumente an create_connection durch, was TypeError gibt.
        # Deshalb versuchen und im Fehlerfall ohne.
        try:
            self.ws = await websockets.connect(url, proxy=None, **args)
        except TypeError:
            self.ws = await websockets.connect(url, **args)

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

    # ---------------- Gesicherte Bausteine ----------------

    async def visu_hash(self, visu_pw: str) -> str:
        """Der Hash fuer einen gesicherten Befehl [K, Seite 14-15].

        Wortlaut des Dokuments, Abschnitt "Secured Commands":

          2. {key}, {salt} und {hashAlg} von "jdev/sys/getvisusalt/{user}"
          3. {visuPwHash} = hashAlg("{visuPw}:{salt}")
          4. {hash} = HMAC-hashAlg( GROSSBUCHSTABEN({visuPwHash}), {key} )
          5. "jdev/sps/ios/{hash}/{uuid}/{command}"

        Der Schluessel gilt nur kurz, deshalb wird der Hash unmittelbar vor
        jedem Befehl neu gebildet und nirgends aufbewahrt. Das
        Visualisierungs-Passwort selbst verlaesst den LoxBerry nie - es geht
        in den Hash ein und sonst nirgendwohin.
        """
        if not visu_pw:
            raise LoxFehler("Es ist kein Visualisierungs-Passwort hinterlegt.")
        roh = await self._befehl("jdev/sys/getvisusalt/%s"
                                 % urllib.parse.quote(self.benutzer))
        if not isinstance(roh, dict):
            raise LoxFehler("Die Antwort auf getvisusalt hatte nicht die erwartete Form.")
        key = str(roh.get("key", ""))
        salt = str(roh.get("salt", ""))
        alg = str(roh.get("hashAlg", "SHA1"))
        if not key or not salt:
            raise LoxFehler("Der Miniserver hat auf getvisusalt keinen Schluessel "
                            "geliefert. Meist ist fuer diesen Benutzer gar kein "
                            "Visualisierungs-Passwort gesetzt.")
        # passwort_hash() liefert bereits Grossbuchstaben - genau das verlangt
        # Punkt 4 ("using the uppercase {visuPwHash}").
        pwhash = passwort_hash(visu_pw, salt, alg)
        return hmac_hash(pwhash, key, alg)

    async def visu_pruefen(self, visu_pw: str) -> bool:
        """Das Visualisierungs-Passwort pruefen, OHNE etwas auszuloesen.

        [K, Seite 15]: 'To check the entered visualization password without
        triggering a function the webservice "jdev/sps/checkuservisupwd/{hash}"
        can be used.'

        Genau das ist der Grund, warum es hier einen Pruefknopf gibt und kein
        blindes Ausprobieren an einem echten Baustein: ein falsches Passwort
        soll nichts schalten und nichts oeffnen.
        """
        h = await self.visu_hash(visu_pw)
        try:
            await self._befehl("jdev/sps/checkuservisupwd/%s" % h)
            return True
        except LoxFehler as f:
            # Code 500 heisst laut Dokument "password was incorrect".
            if "500" in str(f) or "401" in str(f):
                return False
            # Den Dienst gibt es laut [K, Revision History] erst ab Fassung
            # 16.0 ("Webservice to check user visualization password"). Auf
            # aelterer Firmware kommt hier etwas anderes zurueck - und das
            # heisst NICHT, dass das Passwort falsch ist.
            if "400" in str(f) or "404" in str(f):
                raise LoxFehler(
                    "Dieser Miniserver kennt die Pruefung des "
                    "Visualisierungs-Passworts nicht. Sie gibt es laut Loxone "
                    "erst ab Fassung 16.0. Ob das Passwort stimmt, zeigt sich "
                    "dann erst beim Schalten eines gesicherten Bausteins.") from f
            raise

    async def befehl_senden(self, uuid: str, befehl: str, visu_pw: str = "") -> Any:
        """jdev/sps/io/{uuid}/{befehl} [K, Seite 13].

        Bei einem gesicherten Baustein statt dessen
        jdev/sps/ios/{hash}/{uuid}/{befehl} [K, Seite 15] - dieselbe Wirkung,
        mit dem Visualisierungs-Passwort davor.

        Der Aufrufer hat bereits gegen die Positivliste geprueft. Hier wird
        trotzdem noch einmal auf die Form geachtet - eine zweite Pruefung an
        der Stelle, wo es wirklich hinausgeht, kostet nichts.
        """
        if not re.match(r"^[0-9a-fA-F-]{8,60}$", uuid):
            raise LoxFehler("Das ist keine gueltige UUID.")
        if not re.match(r"^[A-Za-z0-9_./+%:-]{1,120}$", befehl):
            raise LoxFehler("Das ist kein gueltiger Befehl.")
        if visu_pw:
            h = await self.visu_hash(visu_pw)
            try:
                return await self._befehl("jdev/sps/ios/%s/%s/%s" % (h, uuid, befehl))
            except LoxFehler as f:
                if "500" in str(f):
                    raise LoxFehler(
                        "Der Miniserver hat den gesicherten Befehl mit Code 500 "
                        "abgewiesen. Laut Dokument heisst das: das "
                        "Visualisierungs-Passwort stimmt nicht.") from f
                raise
        return await self._befehl("jdev/sps/io/%s/%s" % (uuid, befehl))

    async def keepalive(self) -> None:
        """[K, Seite 34]: Der Miniserver antwortet mit Kennung 6."""
        if self.ws is None:
            raise LoxFehler("Es besteht keine Verbindung zum Miniserver.")
        await self.ws.send("keepalive")

    async def schliessen(self) -> None:
        """Verbindung UND Leser-Task abbauen.

        Der Leser-Task war bis 0.9.5 nirgends abgebrochen. Scheiterte die
        Anmeldung, blieb er samt offenem WebSocket stehen - der Miniserver
        trennt erst nach fuenf Minuten Leerlauf, und weil er nur 31 Clients
        gleichzeitig Zustaende empfangen laesst, hat eine Fehlerschleife genau
        die Ressource aufgebraucht, fuer deren Schonung es dieses Plugin gibt.
        Gemessen: vier Fehlversuche, vier offene Verbindungen.
        """
        self.verbunden = False
        self._selbst_geschlossen = True
        try:
            if self.ws is not None:
                await self.ws.close()
        except Exception:
            pass
        leser = self._leser
        self._leser = None
        if leser is not None and not leser.done():
            leser.cancel()
            try:
                await leser
            except (asyncio.CancelledError, Exception):
                pass
        # Wartende Befehle nicht haengen lassen.
        while self._warten:
            f = self._warten.popleft()
            if not f.done():
                f.set_exception(LoxFehler("Die Verbindung wurde geschlossen."))
        self._verwerfen = 0
        self.ws = None

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
                elif kennung == KOPF_WETTER and isinstance(nachricht, bytes):
                    self._wetter_lesen(nachricht)
                elif kennung == KOPF_TAGESZEIT and isinstance(nachricht, bytes):
                    self._tageszeit_lesen(nachricht)
                elif kennung == KOPF_BINDATEI:
                    # Eine angeforderte Datei (Symbol, Bild). Das Plugin
                    # fordert nichts dergleichen an; wuerde sie an
                    # _text_verarbeiten gereicht, loeste sie die Zukunft eines
                    # wartenden Befehls mit Binaermuell auf (Befund 0.9.5).
                    self.uebergangen["datei"] += 1
                elif isinstance(nachricht, (str, bytes)):
                    self._text_verarbeiten(
                        nachricht.decode("utf-8", "replace")
                        if isinstance(nachricht, bytes) else nachricht)
            # Hier endet die Schleife OHNE Ausnahme - das ist der regulaere
            # Verbindungsschluss (Code 1000/1001), und genau den meldet der
            # Miniserver mit Kennung 5 vor einer Aktualisierung an. Bis 0.9.5
            # blieb 'verbunden' dann auf True: der Dienst schrieb bis zu 60 s
            # lang "ok" mit frischem Zeitstempel und eingefrorenen Werten,
            # bis der naechste Keepalive stolperte. Eine stille Falschaussage.
            self.verbunden = False
            if self._selbst_geschlossen:
                # Wir haben zugemacht - das ist keine Meldung wert und schon
                # gar nicht als "der Miniserver hat geschlossen".
                return
            if not self.letzter_fehler:
                self.letzter_fehler = "Der Miniserver hat die Verbindung geschlossen."
            _LOG.info("Der Miniserver hat die Verbindung regulaer geschlossen "
                      "(meist eine Aktualisierung der Firmware).")
        except asyncio.CancelledError:
            self.verbunden = False
            raise
        except Exception as f:
            self.verbunden = False
            self.letzter_fehler = str(f)
            _LOG.warning("Die Verbindung zum Miniserver ist abgerissen: %s", f)

    def _text_verarbeiten(self, text: str) -> None:
        # Eine verspaetete Antwort, deren Befehl schon aufgegeben hat, wird
        # verworfen - sonst bekaeme sie der naechste Befehl.
        if self._verwerfen > 0:
            self._verwerfen -= 1
            _LOG.info("Verspaetete Antwort des Miniservers verworfen.")
            return
        f = None
        while self._warten:
            kandidat = self._warten.popleft()
            if not kandidat.done():
                f = kandidat
                break
        if f is None:
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

    # Wie viele Vorhersageeintraege aufbewahrt werden.
    #
    # Der Miniserver liefert die Vorhersage fuer 96 Stunden [S, weatherServer].
    # Alle 96 Eintraege mit je elf Feldern landeten sonst im Abbild, das die
    # Anzeigeseite bei jedem Takt holt. Vier Tage Vorhersage braucht keine
    # Kachel; zwei Tage sind reichlich.
    WETTER_MAX = 48

    def _wetter_lesen(self, roh: bytes) -> None:
        """Wetter-Ereignistabelle [K, Seite 20-21].

        Kopf   : 16 Byte UUID, 4 Byte lastUpdate (unsigned), 4 Byte nrEntries
        Eintrag: 5 x 4 Byte Ganzzahl, dann 6 x 8 Byte double  = 68 Byte

        Reihenfolge und Typen stehen im Dokument als PACKED struct - deshalb
        ohne Auffuellung. lastUpdate zaehlt Sekunden seit 2009 (UTC), wie
        ueberall bei Loxone.

        Mehrere Tabellen koennen hintereinander in einer Nachricht stehen -
        genauso wie bei den Wert- und Texttabellen. Deshalb die Schleife.
        """
        i, n = 0, len(roh)
        while i + 24 <= n:
            uuid = uuid_lesen(roh[i:i + 16])
            letzte, anzahl = struct.unpack_from("<Ii", roh, i + 16)
            i += 24
            if anzahl < 0 or i + anzahl * 68 > n:
                # Unbrauchbare Angabe: abbrechen statt zurechtbiegen.
                _LOG.info("Wettertabelle mit unplausibler Eintragszahl (%d) verworfen.",
                          anzahl)
                return
            eintraege = []
            for k in range(anzahl):
                if k < self.WETTER_MAX:
                    (zeit, art, windrichtung, strahlung, feuchte) = \
                        struct.unpack_from("<iiiii", roh, i)
                    (temp, gefuehlt, taupunkt, niederschlag, wind, druck) = \
                        struct.unpack_from("<dddddd", roh, i + 20)
                    eintraege.append({
                        "zeit": zeit, "art": art, "windrichtung": windrichtung,
                        "strahlung": strahlung, "feuchte": feuchte,
                        "temperatur": round(temp, 2), "gefuehlt": round(gefuehlt, 2),
                        "taupunkt": round(taupunkt, 2),
                        "niederschlag": round(niederschlag, 2),
                        "wind": round(wind, 2), "druck": round(druck, 1),
                    })
                i += 68
            self._merken(uuid, {"stand": letzte, "anzahl": anzahl,
                                "eintraege": eintraege})

    def _tageszeit_lesen(self, roh: bytes) -> None:
        """Tageszeit-Ereignistabelle [K, Seite 20].

        Kopf   : 16 Byte UUID, 8 Byte dDefValue (double), 4 Byte nrEntries
        Eintrag: nMode, nFrom, nTo, bNeedActivate (je 4 Byte), dValue (8 Byte)

        nFrom und nTo sind Minuten seit Mitternacht. Bei einem digitalen
        Zeitschalter bedeutet ein vorhandener Eintrag "ein", ein fehlender
        "aus"; dValue ist dann ohne Bedeutung.
        """
        i, n = 0, len(roh)
        while i + 28 <= n:
            uuid = uuid_lesen(roh[i:i + 16])
            (vorgabe,) = struct.unpack_from("<d", roh, i + 16)
            (anzahl,) = struct.unpack_from("<i", roh, i + 24)
            i += 28
            if anzahl < 0 or i + anzahl * 24 > n:
                _LOG.info("Tageszeittabelle mit unplausibler Eintragszahl (%d) verworfen.",
                          anzahl)
                return
            eintraege = []
            for _ in range(anzahl):
                (modus, von, bis, aktivieren) = struct.unpack_from("<iiii", roh, i)
                (wert,) = struct.unpack_from("<d", roh, i + 16)
                eintraege.append({"modus": modus, "von": von, "bis": bis,
                                  "aktivieren": aktivieren, "wert": round(wert, 3)})
                i += 24
            self._merken(uuid, {"vorgabe": round(vorgabe, 3), "eintraege": eintraege})

    # ---------------- Rueckfall auf HTTP ----------------

    def http_marke(self) -> str:
        """Die Beglaubigung fuer den HTTP-Weg - EINMAL je Runde, nicht je Wert.

        Bis 0.9.5 holte http_zustand() fuer jeden einzelnen Baustein erst
        'jdev/sys/getkey' und dann den Wert: bei 1500 Bausteinen 3000
        blockierende Umlaeufe je Durchgang. Jetzt wird die Marke einmal
        gebildet und fuer die ganze Runde weitergereicht.
        """
        token = str(self.token.get("token", ""))
        if not token:
            raise LoxFehler("Fuer den HTTP-Weg fehlt ein gueltiges Token.")
        schluessel = str(_wert_aus_antwort(self._http("jdev/sys/getkey")))
        h = hmac_hash(token, schluessel, self.hashalg or "SHA256")
        return "autht=%s&user=%s" % (h, urllib.parse.quote(self.benutzer))

    def http_zustand(self, uuid: str, marke: str = "") -> Any:
        """Einen einzelnen Zustand ueber HTTP holen - der Notnagel.

        Das Token wird als Parameter angehaengt [K, Seite 31]:
        '?autht={hash}&user={user}'. Ohne gueltiges Token weist der
        Miniserver mit 401 ab. Es wird bewusst NICHT auf Basic-Auth
        zurueckgefallen: das Passwort stuende dann in der Adresse.

        Achtung, ehrlich gesagt: dass '/state' ein gueltiger Befehl ist, steht
        in keinem der beiden Loxone-Dokumente. Belegt ist nur
        'jdev/sps/io/{uuid}/{befehl}'. Der Reiter Test hat deshalb einen Knopf,
        der genau diesen Aufruf einmal gegen die eigene Anlage probiert -
        gemessen ist besser als vermutet.
        """
        if not marke:
            marke = self.http_marke()
        pfad = "jdev/sps/io/%s/state?%s" % (uuid, marke)
        return _wert_aus_antwort(self._http(pfad))


# --------------------------------------------------------------------------
# Selbstpruefung ohne Miniserver
# --------------------------------------------------------------------------

def _pruefschluessel() -> str:
    """Ein Wegwerf-RSA-Schluessel fuer die Selbstpruefung.

    Er wird nur gebraucht, um einen echten Sitzungsschluessel zu erzeugen und
    dessen Schreibweise zu pruefen - er verlaesst diese Funktion nicht.
    """
    from cryptography.hazmat.primitives import serialization
    from cryptography.hazmat.primitives.asymmetric import rsa
    s = rsa.generate_private_key(public_exponent=65537, key_size=2048)
    return s.public_key().public_bytes(
        serialization.Encoding.PEM,
        serialization.PublicFormat.SubjectPublicKeyInfo).decode("ascii")


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

    # Wetter-Ereignistabelle [K, Seite 20-21]. Das Pruefstueck wird aus der
    # FELDREIHENFOLGE DES DOKUMENTS gepackt, nicht mit dem Leser gebaut -
    # sonst bestaetigte sich der Quelltext selbst.
    m5 = Miniserver.__new__(Miniserver)
    m5.zustaende = {}
    m5.auf_aenderung = None
    kopf = roh + struct.pack("<Ii", 555000000, 2)
    e1 = struct.pack("<iiiii", 1700000000, 7, 180, 300, 65) + \
         struct.pack("<dddddd", 21.5, 20.1, 12.3, 0.4, 3.2, 1013.5)
    e2 = struct.pack("<iiiii", 1700003600, 3, 190, 120, 70) + \
         struct.pack("<dddddd", 19.0, 18.0, 12.0, 1.1, 4.0, 1012.0)
    Miniserver._wetter_lesen(m5, kopf + e1 + e2)
    w = m5.zustaende.get("0af17bf3-0125-029b-ffff112233445566") or {}
    e.append((len(e1) == 68 and w.get("anzahl") == 2
              and len(w.get("eintraege") or []) == 2
              and w["eintraege"][0]["temperatur"] == 21.5
              and w["eintraege"][0]["feuchte"] == 65
              and w["eintraege"][1]["druck"] == 1012.0,
              "Wetter-Tabelle: 68 Byte je Eintrag, 2 Eintraege gelesen"))

    # Tageszeit-Ereignistabelle [K, Seite 20], ebenso gepackt.
    m5.zustaende = {}
    kopf = roh + struct.pack("<d", 8.0) + struct.pack("<i", 2)
    t1 = struct.pack("<iiii", 0, 360, 480, 0) + struct.pack("<d", 21.0)
    t2 = struct.pack("<iiii", 1, 1080, 1320, 1) + struct.pack("<d", 19.5)
    Miniserver._tageszeit_lesen(m5, kopf + t1 + t2)
    t = m5.zustaende.get("0af17bf3-0125-029b-ffff112233445566") or {}
    e.append((len(t1) == 24 and t.get("vorgabe") == 8.0
              and len(t.get("eintraege") or []) == 2
              and t["eintraege"][0]["von"] == 360 and t["eintraege"][0]["bis"] == 480
              and t["eintraege"][1]["modus"] == 1,
              "Tageszeit-Tabelle: 24 Byte je Eintrag, Minuten seit Mitternacht"))

    # Eine unplausible Eintragszahl darf nicht ins Leere greifen.
    m5.zustaende = {}
    Miniserver._wetter_lesen(m5, roh + struct.pack("<Ii", 1, 9999))
    e.append((m5.zustaende == {},
              "Wetter-Tabelle mit unplausibler Eintragszahl wird verworfen"))

    # Der Sitzungsschluessel darf NICHT URI-kodiert werden.
    #
    # Am 16.08.2026 an einem Miniserver 17.1.7.27 gemessen: URI-kodiert
    # antwortet er mit 401, roh mit 200. Die Attrappe hatte das nicht
    # gefunden, weil sie beim Entschluesseln unquote() aufrief - sie war dem
    # Client nachgebaut, nicht dem Geraet. Diese Pruefung ersetzt das nicht,
    # aber sie faengt die Rueckkehr des Fehlers ohne Miniserver ab.
    m6 = Miniserver.__new__(Miniserver)
    m6._aes_key, m6._aes_iv = b"\x01" * 32, b"\x02" * 16
    gesendet = []

    class _WsMerk:
        async def send(self, cmd):
            gesendet.append(cmd)

    async def _tausch():
        m6.ws = _WsMerk()
        m6._warten = collections.deque()
        m6._verwerfen = 0
        # Ein Schluessel, dessen Base64 sicher '+' und '/' enthaelt.
        m6._pubkey = _pruefschluessel()
        f = asyncio.get_running_loop().create_future()
        f.set_result(None)
        async def _sofort(cmd, zeit=15):
            gesendet.append(cmd)
            return None
        m6._befehl = _sofort
        await Miniserver._schluesseltausch(m6)

    try:
        asyncio.run(_tausch())
        cmd = gesendet[-1] if gesendet else ""
        e.append(("%" not in cmd and cmd.startswith("jdev/sys/keyexchange/"),
                  "Sitzungsschluessel roh, nicht URI-kodiert"))
    except Exception as f:  # noqa: BLE001
        e.append((False, "Sitzungsschluessel: Pruefung nicht durchgelaufen (%s)" % f))

    # Kennung: die von Loxone genannte Form [K, Seite 30].
    k = Miniserver._kennung_bauen(None)  # type: ignore[arg-type]
    e.append((re.match(r"^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{16}$", k) is not None,
              "Client-Kennung in der dokumentierten Form: %s" % k))

    # ---- Die drei Korrekturen aus 0.9.6, jede einzeln nachgestellt ----
    #
    # Ohne diese Zeilen waeren es Behauptungen. Sie laufen ohne Miniserver.

    # 1. Ein sauberer Verbindungsschluss muss 'verbunden' loeschen.
    class _WsZu:
        def __aiter__(self):
            return self

        async def __anext__(self):
            raise StopAsyncIteration

    m2 = Miniserver.__new__(Miniserver)
    m2.ws = _WsZu()
    m2._kopf = None
    m2._warten = collections.deque()
    m2._verwerfen = 0
    m2.zustaende = {}
    m2.auf_aenderung = None
    m2.verbunden = True
    m2.letzter_fehler = ""
    # OHNE dieses Feld lief die Pruefung in einen AttributeError, der vom
    # 'except Exception' in _lesen() gefangen wurde - und der setzt
    # 'verbunden' ebenfalls auf False. Die Pruefung war damit gruen, ohne den
    # Weg zu messen, den sie messen sollte. Aufgefallen an der Warnzeile im
    # Protokoll; eine Pruefung, die aus dem falschen Grund gruen ist, ist
    # schlimmer als keine.
    m2._selbst_geschlossen = False
    m2.uebergangen = {"tageszeit": 0, "wetter": 0, "datei": 0}
    asyncio.run(Miniserver._lesen(m2))
    e.append((m2.verbunden is False and m2.letzter_fehler != "",
              "Sauberer Verbindungsschluss: 'verbunden' False, Grund vermerkt"))

    # 2. Eine verspaetete Antwort darf nicht beim naechsten Befehl landen.
    async def _versatz():
        m3 = Miniserver.__new__(Miniserver)
        m3._warten = collections.deque()
        m3._verwerfen = 0
        schleife = asyncio.get_running_loop()
        alt = schleife.create_future()          # Befehl A, gibt gleich auf
        m3._warten.append(alt)
        m3._warten.remove(alt)
        m3._verwerfen += 1                      # so macht es _befehl() bei Timeout
        neu = schleife.create_future()          # Befehl B
        m3._warten.append(neu)
        Miniserver._text_verarbeiten(m3, json.dumps(
            {"LL": {"control": "a", "value": "ANTWORT-AUF-A", "Code": "200"}}))
        offen_danach = not neu.done()
        Miniserver._text_verarbeiten(m3, json.dumps(
            {"LL": {"control": "b", "value": "ANTWORT-AUF-B", "Code": "200"}}))
        return offen_danach, (neu.result() if neu.done() else None)

    offen, wert = asyncio.run(_versatz())
    e.append((offen and wert == "ANTWORT-AUF-B",
              "Verspaetete Antwort verworfen, Befehl bekommt die eigene: %r" % wert))

    # 3. Der Salt-Wechsel nimmt die Form nextSalt/{prev}/{next}/{cmd}
    #    [K, Seite 8]. Geprueft wird der erzeugte Klartext, nicht das Chiffrat.
    m4 = Miniserver.__new__(Miniserver)
    m4._voriger_salt, m4._salt = "", "aa11"
    erst = Miniserver._enc_klartext(m4, "jdev/sys/getjwt/x")
    m4._voriger_salt, m4._salt = "aa11", "bb22"
    zweit = Miniserver._enc_klartext(m4, "jdev/sys/getjwt/x")
    e.append((erst == "salt/aa11/jdev/sys/getjwt/x"
              and zweit == "nextSalt/aa11/bb22/jdev/sys/getjwt/x",
              "Salt: erster Befehl 'salt/', nach dem Wechsel 'nextSalt/'"))

    return e


if __name__ == "__main__":
    fehlt = 0
    for ok, text in selbstpruefung():
        print(("[OK]   " if ok else "[FEHL] ") + text)
        fehlt += 0 if ok else 1
    raise SystemExit(1 if fehlt else 0)
