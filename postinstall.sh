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
BASE="${ARGV5:-$LBHOMEDIR}"

# Aufwaerts suchen, bis ein Verzeichnis gefunden ist, das nachweislich eine
# LoxBerry-Wurzel IST. Bis 0.9.21 stand hier 'cd "$SELF/../.."' ohne jede
# Pruefung - eine feste Zahl '..' ist nur die naechste Wette. preupgrade.sh,
# uninstall.sh und uninstall/uninstall tragen die geprueften Stufen seit
# 0.9.13; dieses Skript war der letzte Ausreisser.
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
chmod 644 "$PCONFIG/dashboard.json"

# zugang.json enthaelt Zugangsdaten - Rechte 0600, und nur anlegen, nie
# ueberschreiben.
[ -f "$PCONFIG/zugang.json" ] || echo '{}' > "$PCONFIG/zugang.json"
chmod 600 "$PCONFIG/zugang.json"

# seiten.json ist Nutzerinhalt (die geordneten Kacheln): nie ueberschreiben.
[ -f "$PCONFIG/seiten.json" ] || echo '{"seiten":[]}' > "$PCONFIG/seiten.json"

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
db_inhalt() {      # $1 Datei  $2 Art: dashboard | seiten | zugang
    [ -s "$1" ] || return 1
    if command -v php >/dev/null 2>&1; then
        php -r '
            $d = json_decode((string) @file_get_contents($argv[1]), true);
            if (!is_array($d)) { exit(1); }
            if ($argv[2] === "seiten") {
                exit(isset($d["seiten"]) && is_array($d["seiten"]) && count($d["seiten"]) > 0 ? 0 : 1);
            }
            exit(count($d) > 0 ? 0 : 1);' -- "$1" "$2" 2>/dev/null
    elif command -v python3 >/dev/null 2>&1; then
        python3 -c '
import json, sys
try:
    d = json.load(open(sys.argv[1]))
except Exception:
    sys.exit(1)
if not isinstance(d, dict):
    sys.exit(1)
if sys.argv[2] == "seiten":
    s = d.get("seiten")
    sys.exit(0 if isinstance(s, list) and len(s) > 0 else 1)
sys.exit(0 if len(d) > 0 else 1)' "$1" "$2" 2>/dev/null
    else
        return 2
    fi
    DB_RC=$?
    [ "$DB_RC" = 0 ] || [ "$DB_RC" = 1 ] || return 2
    return "$DB_RC"
}

for f in dashboard.json seiten.json zugang.json; do
    BK="$BASE/config/plugins/$PFOLDER.backup.$f"
    CF="$PCONFIG/$f"
    [ -f "$BK" ] || continue
    case "$f" in
        seiten.json) ART=seiten ;;
        zugang.json) ART=zugang ;;
        *)           ART=dashboard ;;
    esac
    db_inhalt "$CF" "$ART"; RC_CF=$?
    db_inhalt "$BK" "$ART"; RC_BK=$?
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
    # mehr blind: uninstall.sh und uninstall/uninstall entfernen die
    # liegengebliebene ohnehin mit.
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
if [ ! -x "$VENV/bin/python3" ]; then
    "$PY3" -m venv --system-site-packages "$VENV" || {
        echo "<INFO> Die virtuelle Umgebung liess sich nicht anlegen (fehlt python3-venv?)."
        echo "<INFO> Das Plugin laeuft trotzdem, solange die Module systemweit da sind."
    }
fi
if [ -x "$VENV/bin/python3" ]; then
    echo "<OK> Virtuelle Umgebung vorhanden (sieht die Systempakete)."
fi

if [ "$BRAUCHT_PIP" = "1" ] && [ -x "$VENV/bin/pip" ]; then
    echo "<INFO> Letzter Versuch ueber pip - das braucht eine Internetverbindung."
    FEHLT=0
    for PAKET in websockets cryptography; do
        if "$VENV/bin/pip" install --no-cache-dir "$PAKET" >/tmp/dashboard_pip.log 2>&1; then
            echo "<OK> Paket $PAKET ueber pip eingerichtet."
        else
            echo "<INFO> Paket $PAKET liess sich ueber pip nicht einrichten."
            tail -n 8 /tmp/dashboard_pip.log
            FEHLT=1
        fi
    done
    rm -f /tmp/dashboard_pip.log
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
chmod 755 "$PBIN/dienst.sh" 2>/dev/null
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

echo "<INFO> Naechste Schritte:"
echo "<INFO>   1. Plugin oeffnen, Reiter Einstellungen, Dienst starten"
echo "<INFO>   2. Reiter Dashboards, 'Entwurf erzeugen' - danach steht schon etwas da"
echo "<INFO>   3. Reiter Designer, Kacheln ordnen"
echo "<INFO>   4. Reiter Dashboards, Adresse auf dem Tablet oeffnen"
echo "<OK> Installation abgeschlossen."

# ---------- Langzeitwerte zurueckholen ----------
# Gegenstueck zu preupgrade.sh. Zwischen beiden Skripten hat der Installer
# data/plugins/<x>/ vollstaendig geloescht; der Nachbar mit dem Punkt hat es
# ueberstanden. Zurueckgeholt wird nur, was fehlt - eine Neuinstallation
# findet nichts vor und faengt sauber bei null an.
LANG_SICHER="$BASE/data/plugins/$PFOLDER.upgrade_sicherung"
if [ -d "$LANG_SICHER" ]; then
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
