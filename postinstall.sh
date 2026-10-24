#!/bin/bash
# Dashboard-Designer - postinstall
# Aufruf: <ZUFALLSKENNUNG> <NAME> <FOLDER> <VERSION> <BASEFOLDER> <TEMPFOLDER>
# ACHTUNG: $1 ist NICHT der Arbeitsordner, sondern eine zehnstellige
# Zufallskennung. Gearbeitet wird deshalb mit $3 und $5. Bis 0.9.12 stand
# hier die falsche Reihenfolge - folgenlos, weil keines der Skripte $1
# benutzt, aber uninstall/uninstall schrieb es im selben Plugin richtig hin.
#
# In die eigene venv kommen genau zwei Pakete:
#   websockets    das Protokoll zum Miniserver
#   cryptography  RSA und AES fuer die Token-Anmeldung
# Beide sind Pflicht - ohne sie kommt keine Verbindung zustande.
#
# PEP 668 laesst ein systemweites 'pip3 install' auf Debian 12/13 nicht zu,
# deshalb die venv. JEDER Rueckgabewert wird geprueft.

ARGV3=$3
ARGV5=$5
PFOLDER="${ARGV3:-dashboard}"
# D6 (0.9.37): der Ordnername darf keinen Pfadtrenner und kein ".." tragen - dieselbe Schranke wie in
# preinstall.sh, direkt hinter der Ordnerbestimmung (plugininstall.pl laesst ohnehin nur [A-Za-z0-9_-] zu;
# ein Handaufruf mit "../<anderes>" traf bis 0.9.36 die Zweitschriften eines fremden Plugins).
case "$PFOLDER" in
    ''|*/*|*..*) echo "<FAIL> postinstall: unzulaessiger Ordnername '$PFOLDER' - es wurde nichts eingerichtet."; exit 1 ;;
esac
BASE="${ARGV5:-$LBHOMEDIR}"

# Aufwaerts suchen, bis ein Verzeichnis gefunden ist, das nachweislich eine
# LoxBerry-Wurzel IST. Bis 0.9.21 stand hier 'cd "$SELF/../.."' ohne jede
# Pruefung - eine feste Zahl '..' ist nur die naechste Wette. preupgrade.sh
# und uninstall/uninstall tragen die geprueften Stufen seit 0.9.13 (bis 0.9.24
# auch das uninstall.sh im Wurzelordner, seit 0.9.25 entfernt, I7); dieses
# Skript war der letzte Ausreisser.
#
# Gemessen am 18.09.2026 (Pruefung-Dashboard-0.9.22, Fall 16): von Hand ohne
# Argumente aus <Wurzel>/pruefung/tief/dashboard aufgerufen, legte das Skript
# config/plugins/dashboard, data/plugins/dashboard und log/plugins/dashboard
# unter <Wurzel>/pruefung an - in einem Verzeichnis, das keine Wurzel ist.
# Dieselbe Klasse wie Bestand-2026-09-18/klasse-H.
#
# Drittes Merkmal ist config/system/general.json: ein LoxBerry hat sie immer,
# ein Rest aus Pruefstaenden nie (Regeln/06). Ohne sie legte dieses Skript in
# einem fremden Baum mit config/plugins und data/plugins die Plugin-Ordner an
# (gemessen am 18.09.2026 in WSL, Pruefung-Dashboard-0.9.24, Fall F4).
lb_wurzel_suchen() {
    v=$(cd "$(dirname "$(readlink -f "$0")")" 2>/dev/null && pwd)
    i=0
    while [ -n "$v" ] && [ "$v" != "/" ] && [ $i -lt 8 ]; do
        if [ -d "$v/config/plugins" ] && [ -d "$v/data/plugins" ] \
           && [ -f "$v/config/system/general.json" ]; then
            echo "$v"; return 0
        fi
        v=$(dirname "$v"); i=$((i + 1))
    done
    return 1
}
if [ -z "$BASE" ] || [ ! -d "$BASE" ]; then
    BASE=$(lb_wurzel_suchen)
fi
if [ -z "$BASE" ] || [ ! -d "$BASE" ]; then
    echo "<FAIL> Kein LoxBerry-Wurzelverzeichnis gefunden - es wurde NICHTS"
    echo "<FAIL> angelegt und nichts zurueckgespielt. Der Installer uebergibt"
    echo "<FAIL> die Wurzel als fuenftes Argument; von Hand aufgerufen braucht"
    echo "<FAIL> dieses Skript ein gesetztes LBHOMEDIR."
    exit 1
fi

# ---------- Die Marke "Aktualisierung laeuft" faellt am Ende dieses Skripts ----------
#
# preupgrade.sh legt sie an (dort steht, was sie abwendet). Entfernt wird sie
# HIER und nicht erst in postupgrade.sh: in dieser Linie ist postinstall.sh das
# letzte Hakenskript, das etwas tut - es spielt die Einstellungen zurueck und
# startet den Dienst wieder; postupgrade.sh ist absichtlich leer.
#
# Ueber einen trap auf EXIT, nicht am Dateiende: dieses Skript steigt hinter
# dieser Zeile an fuenf Stellen mit 'exit 1' aus (Ordner, kein Python,
# Python zu alt, pip, Module). Ohne trap
# bliebe der Dienst nach einer gescheiterten Installation bis zu einer Stunde
# gesperrt, ohne dass irgendwo stuende, warum (Regeln/06, Nachtrag 17.09.2026).
#
# Der trap laeuft NACH dem Dienststart weiter unten. Das ist Absicht und
# gemessen (Pruefung-Dashboard-0.9.23, messe_reihenfolge.sh): zwischen dem
# 'touch soll_laufen' in dienst.sh und dem Anlaufen des Prozesses ist ein
# Fenster offen, in dem der Minutentakt denselben Dienst ein zweites Mal
# startet. Solange die Marke liegt, ist es zu; der eigene Start bekommt dafuer
# die Ausnahme DB_START_TROTZ_MARKE=1 (bin/dienst.sh, marke_sperrt()).
MARKE="$BASE/data/plugins/$PFOLDER.upgrade_laeuft"
db_marke_weg() { rm -f "$MARKE"; }
trap db_marke_weg EXIT

PBIN="$BASE/bin/plugins/$PFOLDER"
PDATA="$BASE/data/plugins/$PFOLDER"
PLOG="$BASE/log/plugins/$PFOLDER"
PCONFIG="$BASE/config/plugins/$PFOLDER"
VENV="$PBIN/venv"

mkdir -p "$PDATA/befehle" "$PDATA/antworten" "$PLOG" "$PCONFIG" || {
    echo "<FAIL> Ordner konnten nicht angelegt werden."
    exit 1
}
chmod 755 "$PDATA" "$PLOG" "$PCONFIG" 2>/dev/null

[ -f "$PCONFIG/dashboard.json" ] || echo '{}' > "$PCONFIG/dashboard.json"
# C11/I6 (Durchgang 29.09.2026): 0600 statt 0644 - die Datei traegt das
# Aktionstoken (Regeln/05). Gemessen vorher: 644 nach Neuinstallation und
# Upgrade (Installer-Pruefer, Befund 6).
chmod 600 "$PCONFIG/dashboard.json"

# zugang.json enthaelt Zugangsdaten - Rechte 0600, und nur anlegen, nie
# ueberschreiben.
[ -f "$PCONFIG/zugang.json" ] || echo '{}' > "$PCONFIG/zugang.json"
chmod 600 "$PCONFIG/zugang.json"

# seiten.json ist Nutzerinhalt (die geordneten Kacheln): nie ueberschreiben.
[ -f "$PCONFIG/seiten.json" ] || echo '{"seiten":[]}' > "$PCONFIG/seiten.json"
# C11: seiten.json traegt die PIN-Pruefwerte - ebenfalls 0600.
chmod 600 "$PCONFIG/seiten.json"

# ---------- Zurueckspielen: nach INHALT, nicht nach GROESSE ----------
#
# Bis 0.9.21 stand hier
#     if [ ! -s "$CF" ] || [ "$INHALT" = "{}" ] || [ "$INHALT" = '{"seiten":[]}' ]
# und unmittelbar darauf ein 'rm -f "$BK"' OHNE Bedingung. Eine
# abgeschnittene Datei ist aber weder leer noch "{}": sie bestand die
# Pruefung, wurde deshalb NICHT zurueckgespielt - und die einzige heile
# Abschrift wurde danach geloescht.
#
# Gemessen am 18.09.2026 in WSL (Bestand-2026-09-18/klasse-C, Fall 15, und
# Pruefung-Dashboard-0.9.22, Faelle 1 bis 4): Konfiguration abgeschnitten,
# Zweitschrift weg, Merktoken nirgends mehr heil. Betroffen waren
# dashboard.json, seiten.json (die ganze Handarbeit) und zugang.json mit dem
# Miniserver-Kennwort.
#
# Gefragt wird jetzt dasselbe, was die Oberflaeche fragt: laesst sich die
# Datei lesen, ist es ein Objekt, und steht etwas darin? Bauart wie
# Intercom-2.2.12/preupgrade.sh (cf_mit_inhalt) und
# GardenaSmartSystem-1.2.10/preupgrade.sh (json_heil).
#
# Rueckgabe: 0 = traegt Inhalt, 1 = traegt keinen, 2 = nicht pruefbar.
# 'Nicht pruefbar' faellt geschlossen aus (CLAUDE.md, "ein Schutz faellt
# geschlossen aus"): dann wird weder zurueckgespielt noch weggeraeumt.
#
# D5 (0.9.37, Bauform Heimkino 1.3.30 H14): "Inhalt" heisst ein ANWENDERFELD. Fuer zugang zaehlen adresse, benutzer,
# passwort und visu_pw (nicht leer) und - als EIN Feld - ein vollstaendiger Token-Satz (ms_token UND ms_kennung nicht
# leer): mit den Zugangsdaten aus general.json traegt zugang.json nur die Token-Anmeldung des Dienstes (am Geraet
# gemessen 24.10.2026: ms_hashalg, ms_kennung, ms_token - der erste Bau meldete dort faelschlich "kein Inhalt"). Ein
# einzelnes ms_token ohne Kennung zaehlt nicht. Fuer
# dashboard zaehlt jeder Schluessel ausser aktionstoken, vorlagen, endpunkt_ein, mqtt_ein und befehle_mqtt_ein (Token,
# Merker und die Haken, die postinstall selbst setzt). Bis 0.9.36 genuegte ein beliebiger Schluessel: ein Stand nur mit
# Token bzw. ms_token verdraengte nach einem abgebrochenen Update die heile Zweitschrift (Pruefbericht D5, Faelle B/C).
# Mit $3 = zahl gibt die Funktion zusaetzlich die Zahl der Anwenderfelder aus (H14-Zaehlregel in preupgrade.sh).
db_inhalt() {      # $1 Datei  $2 Art: dashboard | seiten | zugang  [$3 zahl]
    if [ ! -s "$1" ]; then
        [ "${3:-}" = zahl ] && echo 0
        return 1
    fi
    if command -v php >/dev/null 2>&1; then
        php -r '
            $d = json_decode((string) @file_get_contents($argv[1]), true);
            $n = 0;
            if (is_array($d)) {
                if ($argv[2] === "seiten") {
                    $n = isset($d["seiten"]) && is_array($d["seiten"]) ? count($d["seiten"]) : 0;
                } elseif ($argv[2] === "zugang") {
                    foreach (array("adresse", "benutzer", "passwort", "visu_pw") as $k) {
                        if (isset($d[$k]) && is_scalar($d[$k]) && (string) $d[$k] !== "") { $n++; }
                    }
                    if (isset($d["ms_token"], $d["ms_kennung"]) && is_scalar($d["ms_token"]) && is_scalar($d["ms_kennung"])
                        && (string) $d["ms_token"] !== "" && (string) $d["ms_kennung"] !== "") { $n++; }
                } else {
                    foreach (array_keys($d) as $k) {
                        if (!in_array((string) $k, array("aktionstoken", "vorlagen", "endpunkt_ein", "mqtt_ein",
                                                          "befehle_mqtt_ein"), true)) { $n++; }
                    }
                }
            }
            if ($argv[3] === "zahl") { echo $n, "\n"; }
            exit($n > 0 ? 0 : 1);' -- "$1" "$2" "${3:-}" 2>/dev/null
    elif command -v python3 >/dev/null 2>&1; then
        python3 -c '
import json, sys
n = 0
try:
    d = json.load(open(sys.argv[1]))
except Exception:
    d = None
if isinstance(d, dict):
    if sys.argv[2] == "seiten":
        s = d.get("seiten")
        n = len(s) if isinstance(s, list) else 0
    elif sys.argv[2] == "zugang":
        n = sum(1 for k in ("adresse", "benutzer", "passwort", "visu_pw") if str(d.get(k) or "") != "")
        n += 1 if (str(d.get("ms_token") or "") != "" and str(d.get("ms_kennung") or "") != "") else 0
    else:
        n = sum(1 for k in d if k not in ("aktionstoken", "vorlagen", "endpunkt_ein", "mqtt_ein", "befehle_mqtt_ein"))
if sys.argv[3] == "zahl":
    print(n)
sys.exit(0 if n > 0 else 1)' "$1" "$2" "${3:-}" 2>/dev/null
    else
        return 2
    fi
    DB_RC=$?
    [ "$DB_RC" = 0 ] || [ "$DB_RC" = 1 ] || return 2
    return "$DB_RC"
}

# I1 (Durchgang 29.09.2026, Entscheidung 1): eingespielt wird NUR bei einer
# Aktualisierung, erkannt an der Marke (kein Altersvergleich). Sie wird hier
# gelesen, bevor der trap sie am Ende entfernt. Bei einer Neuinstallation hat
# preinstall.sh liegengebliebene Sicherungen schon nach .alt gelegt; was
# trotzdem noch liegt, wird nicht angefasst. Gemessen vorher (Fall B): eine
# Neuinstallation spielte Token, Zugang und Seiten einer frueheren ein.
DB_UPGRADE=0
[ -f "$MARKE" ] && DB_UPGRADE=1
for f in dashboard.json seiten.json zugang.json; do
    BK="$BASE/config/plugins/$PFOLDER.backup.$f"
    CF="$PCONFIG/$f"
    [ -f "$BK" ] || continue
    [ "$DB_UPGRADE" = 1 ] || continue
    case "$f" in
        seiten.json) ART=seiten ;;
        zugang.json) ART=zugang ;;
        *)           ART=dashboard ;;
    esac
    db_inhalt "$CF" "$ART"; RC_CF=$?
    db_inhalt "$BK" "$ART"; RC_BK=$?
    # D5 (0.9.37, H14-Zaehlregel, Nachzug Geraetemessung): tragen beide etwas, gewinnt die Zweitschrift nur, wenn sie
    # MEHR Anwenderfelder traegt (z. B. eigener Zugang mit Benutzer/Kennwort gegen einen Token-Satz aus der Luecke
    # eines abgebrochenen Updates - das Token holt der Dienst neu). seiten.json bleibt bei ihrer strengen Regel.
    if [ "$RC_CF" = 0 ] && [ "$RC_BK" = 0 ] && [ "$ART" != seiten ]; then
        N_CF=$(db_inhalt "$CF" "$ART" zahl)
        N_BK=$(db_inhalt "$BK" "$ART" zahl)
        case "$N_CF:$N_BK" in
            *[!0-9:]*|:*|*:) ;;
            *)
                if [ "$N_CF" -lt "$N_BK" ]; then
                    echo "<INFO> $f traegt weniger Einstellungen ($N_CF) als die Zweitschrift ($N_BK) - sie wird zurueckgespielt."
                    RC_CF=1
                fi ;;
        esac
    fi
    # Zurueckgespielt wird nur, wenn die vorhandene Datei nachweislich nichts
    # traegt UND die Zweitschrift nachweislich etwas.
    if [ "$RC_CF" = 1 ] && [ "$RC_BK" = 0 ]; then
        if cp -p "$BK" "$CF"; then
            echo "<OK> $f aus Sicherung wiederhergestellt."
            RC_CF=0
        else
            echo "<WARNING> $f liess sich nicht aus der Sicherung zurueckholen."
        fi
    fi
    # Die Sicherung wegraeumen - aber nur, wenn die Konfiguration danach
    # nachweislich Inhalt traegt. Sie liegt eine Ebene UEBER dem Pluginordner
    # und ueberlebt deshalb eine Deinstallation. Bis 0.9.5 blieb sie immer
    # liegen - eine spaetere Neuinstallation holte daraus stillschweigend die
    # alte Konfiguration samt altem Aktionstoken zurueck, und
    # dashboard.backup.zugang.json mit dem Miniserver-Kennwort lag unbegrenzt
    # im Dateisystem. Deshalb wird sie weiterhin weggeraeumt, nur eben nicht
    # mehr blind: uninstall/uninstall entfernt die liegengebliebene ohnehin
    # mit. (Bis 0.9.24 stand hier zusaetzlich "uninstall.sh" - die Datei rief
    # der Installer nie auf; seit 0.9.25 ist sie entfernt, I7.)
    if [ "$RC_CF" = 0 ]; then
        rm -f "$BK"
    elif [ "$RC_CF" = 2 ] || [ "$RC_BK" = 2 ]; then
        echo "<WARNING> Der Inhalt von $f liess sich nicht pruefen (fehlt php und python3?)."
        echo "<WARNING> Es wurde nichts zurueckgespielt, und die Sicherung bleibt liegen:"
        echo "<WARNING>   $BK"
    else
        echo "<WARNING> $f traegt keinen lesbaren Inhalt, und die Sicherung ebenso wenig."
        echo "<WARNING> Die Sicherung bleibt liegen, damit nicht auch noch die letzte"
        echo "<WARNING> Abschrift verschwindet:"
        echo "<WARNING>   $BK"
        echo "<WARNING> Bitte die Einstellungen nach der Installation ansehen."
    fi
done
chmod 600 "$PCONFIG/zugang.json"
# C11: 'cp -p' bringt die Rechte der Zweitschrift mit - danach nachziehen.
chmod 600 "$PCONFIG/dashboard.json" "$PCONFIG/seiten.json"

# ---------- Uebergang der Haken auf 0.9.37 (Gruppe 12, Entscheidung E6) ----------
# NUR BEI EINER AKTUALISIERUNG und nur, wenn endpunkt_ein in der Datei noch FEHLT (ein zweiter Anlauf nach einem
# Abbruch aendert nichts mehr): der Loxone-Abruf ueber HTTP (aktion=status und aktion=tafel) war bis 0.9.36 immer
# offen. Wer ein gueltiges Aktionstoken hat (dieselbe Regel wie db_token_gueltig: [A-Za-z0-9_.-]{1,64}, nicht
# "Array"), behaelt ihn (endpunkt_ein = 1; jede benutzte Anlage hat eins, die Tablets brauchen es), sonst 0.
# mqtt_ein (Vorgabe 1) und befehle_mqtt_ein (Vorgabe 0) brauchen keinen Uebergang - ein fehlender Schluessel gilt mit
# seiner Vorgabe; tafel_mqtt behaelt seinen Wert. Neuinstallation: nichts geschrieben, es gilt die Vorgabe 0.
# Geschrieben ueber python3 in eine Nebendatei, geprueft (lesbar, Schluesselzahl +1, Token gleich), dann mv, 0600 -
# fuer die Konfiguration UND eine noch liegende Zweitschrift. Scheitert es, gilt die Vorgabe 0 (Abruf aus).
db_haken_uebergang() {   # $1 Datei
    python3 - "$1" <<'DB_UEBERGANG'
import json, os, re, sys
f = sys.argv[1]
try:
    with open(f, encoding="utf-8") as h:
        d = json.load(h)
except Exception:
    sys.exit(3)
if not isinstance(d, dict) or not d:
    sys.exit(3)
if "endpunkt_ein" in d:
    print("nichts")
    sys.exit(0)
t = d.get("aktionstoken")
gueltig = isinstance(t, str) and t != "Array" and re.fullmatch(r"[A-Za-z0-9_.\-]{1,64}", t) is not None
neu = dict(d)
neu["endpunkt_ein"] = 1 if gueltig else 0
tmp = f + ".uebergang"
fd = os.open(tmp, os.O_WRONLY | os.O_CREAT | os.O_TRUNC, 0o600)
with os.fdopen(fd, "w", encoding="utf-8") as h:
    json.dump(neu, h, ensure_ascii=False, indent=4)
os.chmod(tmp, 0o600)
with open(tmp, encoding="utf-8") as h:
    c = json.load(h)
if len(c) != len(d) + 1 or c.get("aktionstoken") != d.get("aktionstoken"):
    os.unlink(tmp)
    sys.exit(4)
os.replace(tmp, f)
print("endpunkt_ein=%d" % neu["endpunkt_ein"])
DB_UEBERGANG
}
if [ "$DB_UPGRADE" = 1 ]; then
    for DB_HF in "$PCONFIG/dashboard.json" "$BASE/config/plugins/$PFOLDER.backup.dashboard.json"; do
        [ -f "$DB_HF" ] || continue
        if DB_HA=$(db_haken_uebergang "$DB_HF" 2>&1); then
            [ "$DB_HA" = nichts ] || echo "<OK> Uebergang auf 0.9.37 ($(basename "$DB_HF")): $DB_HA."
        else
            echo "<WARNING> Der Haken endpunkt_ein liess sich in $(basename "$DB_HF") nicht setzen - es gilt die Vorgabe 0 (Loxone-Abruf ueber HTTP aus)."
            echo "<INFO> Abhilfe: im Plugin Reiter Einbindung den Haken 'Loxone-Abruf ueber HTTP annehmen' setzen."
        fi
    done
fi

# ---------- Abo-Datei fuer das MQTT-Gateway V1 (0.9.37, Gruppe 12) ----------
# config/plugins/<ordner>/mqtt_subscriptions.cfg = "<praefix>/#" (Praefix tafel_mqtt_praefix aus der Konfiguration,
# sonst dashboard - dieselbe Regel wie db_mqtt_abo_inhalt()), 0644, nur wenn sie fehlt. Ebenso beim Dienststart und
# beim Speichern des Praefixes; der Seitenaufbau schreibt sie nicht. Das Gateway V2 liest sie nicht.
DB_ABO="$PCONFIG/mqtt_subscriptions.cfg"
if [ ! -e "$DB_ABO" ]; then
    DB_PRAEFIX=$(python3 -c 'import json, re, sys
try:
    with open(sys.argv[1], encoding="utf-8") as h:
        p = json.load(h).get("tafel_mqtt_praefix")
except Exception:
    p = ""
p = p if isinstance(p, str) else ""
print(p if re.fullmatch(r"[A-Za-z0-9_\-]{1,32}(/[A-Za-z0-9_\-]{1,32}){0,3}", p) else "dashboard")' "$PCONFIG/dashboard.json" 2>/dev/null)
    [ -n "$DB_PRAEFIX" ] || DB_PRAEFIX=dashboard
    if printf '%s/#\n' "$DB_PRAEFIX" > "$DB_ABO.tmp.$$" 2>/dev/null && chmod 644 "$DB_ABO.tmp.$$" \
       && mv -f "$DB_ABO.tmp.$$" "$DB_ABO"; then
        echo "<INFO> Abo-Datei fuer das MQTT-Gateway V1 angelegt: $DB_PRAEFIX/#"
    else
        rm -f "$DB_ABO.tmp.$$" 2>/dev/null
        echo "<WARNING> Die Abo-Datei fuer das MQTT-Gateway V1 liess sich nicht anlegen ($DB_ABO) - der Dienst versucht es beim Start erneut."
    fi
fi

# ---------- Python ----------
PY3=$(command -v python3)
if [ -z "$PY3" ]; then
    echo "<FAIL> python3 ist nicht vorhanden. Ohne Python laeuft der Dienst nicht."
    exit 1
fi
PYVER=$("$PY3" -c 'import sys;print("%d.%d"%sys.version_info[:2])' 2>/dev/null)
echo "<INFO> Gefundenes Python: $PYVER"
"$PY3" -c 'import sys;sys.exit(0 if sys.version_info>=(3,8) else 1)' || {
    echo "<FAIL> Python 3.8 oder neuer wird gebraucht, gefunden wurde $PYVER."
    exit 1
}

# ---------- Die beiden Pakete ----------
#
# Die Debian-Pakete stehen in der Datei 'dpkg/apt' des Archivs:
#
#     python3-websockets
#     python3-cryptography
#     python3-venv
#
# LoxBerry installiert sie SELBST und als root, bevor dieses Skript laeuft.
# Bis 0.9.5 stand hier stattdessen ein eigener 'apt-get install'-Aufruf. Der
# konnte nie greifen: plugininstall.pl startet postinstall.sh mit
# 'sudo -n -u loxberry', und als loxberry scheitert apt-get an
# /var/lib/dpkg/lock-frontend. Genau der Weg, den der Kommentar als Loesung
# fuer Netze ohne Internet beschrieb, war also tot.
#
# Warum das wichtig ist: 'pip install cryptography' braucht eine
# Internetverbindung, und gibt es fuer die Architektur kein fertiges Paket,
# uebersetzt pip aus dem Quelltext - dafuer braeuchte es einen C- UND einen
# Rust-Uebersetzer. pip bleibt deshalb der letzte Ausweg, nicht der erste.
#
# Die venv wird MIT --system-site-packages angelegt; dann sieht sie die
# Systempakete, und pip muss gar nichts mehr holen.
BRAUCHT_PIP=0
for MODUL in websockets cryptography; do
    if "$PY3" -c "import $MODUL" >/dev/null 2>&1; then
        echo "<OK> Python-Modul $MODUL ist systemweit vorhanden."
    else
        echo "<INFO> Python-Modul $MODUL fehlt systemweit (dpkg/apt hat es nicht eingerichtet)."
        BRAUCHT_PIP=1
    fi
done

# Die venv sieht die Systempakete. Ohne --system-site-packages waere sie
# abgeschottet, und alles oben waere umsonst gewesen.
#
# I4 (Durchgang 29.09.2026): fehlt python3-venv, scheitert 'venv' (rc=1),
# hinterlaesst aber eine ausfuehrbare venv/bin/python3 OHNE Zugriff auf die
# Systempakete. Bis 0.9.25 folgten darauf "laeuft trotzdem", "<OK> ... sieht
# die Systempakete" und "<FAIL> cryptography fehlt" mit rc=1 - obwohl das
# System-Python cryptography hat (gemessen in WSL, Installer-Pruefer Befund 4).
# Jetzt: eine halb angelegte venv wird entfernt, und der zugesagte Rueckfall
# auf das System-Python greift wirklich (dienst.sh nimmt es, wenn es keine
# venv/bin/python3 gibt). "vorhanden" steht nur da, wenn die venv die Module
# tatsaechlich sieht.
venv_wegraeumen() {
    case "$VENV" in
        */bin/plugins/"$PFOLDER"/venv) rm -rf "${VENV:?}" 2>/dev/null ;;
        *) echo "<WARNING> Unerwarteter Pfad der virtuellen Umgebung ($VENV) - nicht entfernt." ;;
    esac
}
if [ ! -x "$VENV/bin/python3" ]; then
    if ! "$PY3" -m venv --system-site-packages "$VENV"; then
        echo "<INFO> Die virtuelle Umgebung liess sich nicht anlegen (fehlt python3-venv?)."
        if [ -e "$VENV" ]; then
            venv_wegraeumen
            echo "<INFO> Die halb angelegte Umgebung wurde entfernt."
        fi
        echo "<INFO> Der Dienst benutzt das System-Python $PY3."
    fi
fi
if [ -x "$VENV/bin/python3" ] && [ "$BRAUCHT_PIP" = "0" ]; then
    if "$VENV/bin/python3" -c "import websockets, cryptography" >/dev/null 2>&1; then
        echo "<OK> Virtuelle Umgebung vorhanden (sieht die Systempakete)."
    else
        echo "<INFO> Die virtuelle Umgebung sieht die Systempakete nicht - sie wird entfernt,"
        echo "<INFO> und der Dienst benutzt das System-Python $PY3."
        venv_wegraeumen
    fi
elif [ -x "$VENV/bin/python3" ]; then
    echo "<INFO> Virtuelle Umgebung vorhanden; die fehlenden Module werden ueber pip versucht."
fi

if [ "$BRAUCHT_PIP" = "1" ] && [ -x "$VENV/bin/pip" ]; then
    echo "<INFO> Letzter Versuch ueber pip - das braucht eine Internetverbindung."
    FEHLT=0
    for PAKET in websockets cryptography; do
        # D4 (0.9.37): die Ausgabe in einer Variable statt in einer festen Datei unter /tmp. Gehoerte die einem
        # anderen Benutzer, scheiterte schon die Umlenkung, pip lief gar nicht, und hier stand die falsche
        # Ursache (Pruefbericht D4). Erfolg am Rueckgabewert von pip.
        PIPAUSGABE=$("$VENV/bin/pip" install --no-cache-dir "$PAKET" 2>&1); RC=$?
        if [ "$RC" = 0 ]; then
            echo "<OK> Paket $PAKET ueber pip eingerichtet."
        else
            echo "<INFO> Paket $PAKET liess sich ueber pip nicht einrichten."
            printf '%s\n' "$PIPAUSGABE" | tail -n 8
            FEHLT=1
        fi
    done
    if [ "$FEHLT" = "1" ]; then
        echo "<FAIL> Ohne websockets UND cryptography kommt keine Verbindung zum"
        echo "<FAIL> Miniserver zustande. Von Hand nachholen:"
        echo "<FAIL>   sudo apt-get install -y python3-websockets python3-cryptography"
        exit 1
    fi
fi

# Gegenprobe mit dem Interpreter, der den Dienst spaeter wirklich startet.
PYTEST="$VENV/bin/python3"
[ -x "$PYTEST" ] || PYTEST="$PY3"
for MODUL in websockets cryptography; do
    "$PYTEST" -c "import $MODUL" >/dev/null 2>&1 \
        && echo "<OK> $MODUL ist fuer den Dienst erreichbar." \
        || { echo "<FAIL> $MODUL fehlt dem Interpreter $PYTEST."; exit 1; }
done

# ---------- Rechte ----------
# Gruppe 12 (0.9.37): dazu die Befehlsbruecke. KEIN Start hier - der Waechter in cron.01min startet sie binnen
# einer Minute, wenn "Befehle ueber MQTT annehmen" gesetzt ist (und nie, solange die Marke liegt).
chmod 755 "$PBIN/dienst.sh" "$PBIN/befehlsbruecke.sh" 2>/dev/null
chmod 755 "$PBIN"/*.py 2>/dev/null

# ---------- Miniserver ----------
MSDATEI="$BASE/config/system/general.json"
if [ -f "$MSDATEI" ] && grep -q '"Miniserver"' "$MSDATEI"; then
    echo "<OK> In der LoxBerry-Konfiguration ist mindestens ein Miniserver eingetragen."
    echo "<INFO> Das Plugin benutzt dessen Zugangsdaten - es fragt nicht noch einmal danach."
else
    echo "<INFO> In der LoxBerry-Konfiguration steht noch kein Miniserver."
    echo "<INFO> Unter System, Miniserver eintragen - sonst kann sich der Dienst nicht anmelden."
fi

# ---------- Selbsttest ----------
#
# Mit $PYTEST, nicht fest mit der venv: gibt es keine venv, waere der Aufruf
# bis 0.9.5 an "No such file or directory" gescheitert - und wegen '|| true'
# haette die Installation trotzdem "abgeschlossen" gemeldet. Die
# Abschlusspruefung waere also genau in dem Fall ausgefallen, fuer den der
# Rueckfall gebaut wurde.
echo "<INFO> Selbsttest:"
PYTHONDONTWRITEBYTECODE=1 "$PYTEST" "$PBIN/dashboard_dienst.py" --selbsttest 2>&1 | sed 's/^/<INFO> /' || true

# Kein __pycache__ ausliefern und keines zuruecklassen.
rm -rf "$PBIN/__pycache__" 2>/dev/null

# I5 (Durchgang 29.09.2026): die Schritte der Ersteinrichtung nur bei einer
# Neuinstallation. Bis 0.9.25 riet jedes Upgrade-Protokoll "Dienst starten"
# und meldete gleich darauf, der Dienst sei wieder gestartet (gemessen,
# Faelle C, D, F des Installer-Pruefers; Regeln/06).
if [ "$DB_UPGRADE" = 0 ]; then
    echo "<INFO> Naechste Schritte:"
    echo "<INFO>   1. Plugin oeffnen, Reiter Einstellungen, Dienst starten"
    echo "<INFO>   2. Reiter Dashboards, 'Entwurf erzeugen' - danach steht schon etwas da"
    echo "<INFO>   3. Reiter Designer, Kacheln ordnen"
    echo "<INFO>   4. Reiter Dashboards, Adresse auf dem Tablet oeffnen"
fi
echo "<OK> Installation abgeschlossen."

# ---------- Langzeitwerte zurueckholen ----------
# Gegenstueck zu preupgrade.sh. Zwischen beiden Skripten hat der Installer
# data/plugins/<x>/ vollstaendig geloescht; der Nachbar mit dem Punkt hat es
# ueberstanden. Zurueckgeholt wird nur, was fehlt - eine Neuinstallation
# findet nichts vor und faengt sauber bei null an.
LANG_SICHER="$BASE/data/plugins/$PFOLDER.upgrade_sicherung"
# I1/I3: Langzeitwerte und "lief vorher" gelten nur bei einer Aktualisierung
# (Marke), nie aus einem frueheren Vorgang.
if [ "$DB_UPGRADE" = 1 ] && [ -d "$LANG_SICHER" ]; then
    for LANG_F in verlauf.json; do
        if [ -f "$LANG_SICHER/$LANG_F" ] \
           && [ ! -s "$BASE/data/plugins/$PFOLDER/$LANG_F" ]; then
            mkdir -p "$BASE/data/plugins/$PFOLDER" 2>/dev/null
            cp -p "$LANG_SICHER/$LANG_F" "$BASE/data/plugins/$PFOLDER/$LANG_F" \
                2>/dev/null && echo "<OK> $LANG_F ueber das Update gerettet."
        fi
    done
    # Lief der Dienst vor dem Update, laeuft er auch danach wieder.
    # Gestartet wird ueber dienst.sh - das setzt den Sollmerker selbst und
    # nimmt ihn im Fehlerfall zurueck; ein von Hand gelegter Merker ohne
    # laufenden Dienst waere genau die Endlosschleife, die dienst.sh
    # vermeidet.
    if [ -f "$LANG_SICHER/lief_vorher" ]; then
        if [ -x "$PBIN/dienst.sh" ]; then
            if DB_START_TROTZ_MARKE=1 "$PBIN/dienst.sh" start >/dev/null 2>&1; then
                echo "<OK> Der Dienst lief vor dem Update und wurde wieder gestartet."
            else
                echo "<INFO> Der Dienst lief vor dem Update, liess sich aber nicht wieder"
                echo "<INFO> starten. Reiter Einstellungen, 'Dienst starten' - dort steht,"
                echo "<INFO> woran es liegt."
            fi
        else
            echo "<INFO> Der Dienst lief vor dem Update; dienst.sh ist nicht ausfuehrbar."
        fi
    fi
    rm -rf "$LANG_SICHER" 2>/dev/null
fi
exit 0
