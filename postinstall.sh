#!/bin/bash
# Dashboard-Designer - postinstall
# command <TEMPFOLDER> <NAME> <FOLDER> <VERSION> <BASEFOLDER>
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
if [ -z "$BASE" ] || [ ! -d "$BASE" ]; then
    SELF=$(cd "$(dirname "$0")" && pwd)
    BASE=$(cd "$SELF/../.." 2>/dev/null && pwd)
fi

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

for f in dashboard.json seiten.json zugang.json; do
    BK="$BASE/config/plugins/$PFOLDER.backup.$f"
    CF="$PCONFIG/$f"
    if [ -f "$BK" ]; then
        INHALT=$(cat "$CF" 2>/dev/null)
        if [ ! -s "$CF" ] || [ "$INHALT" = "{}" ] || [ "$INHALT" = '{"seiten":[]}' ]; then
            cp -p "$BK" "$CF" && echo "<OK> $f aus Sicherung wiederhergestellt."
        fi
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
# Reihenfolge: erst nachsehen, ob das SYSTEM sie schon hat, dann apt, und
# erst zuletzt pip.
#
# Warum diese Reihenfolge: bis 0.9.0 ging es sofort ueber pip in eine
# abgeschottete venv. Das setzt eine Internetverbindung voraus - und ein
# LoxBerry haengt oft in einem Netz ohne Weg nach draussen. Schlimmer noch
# bei 'cryptography': gibt es fuer die Architektur kein fertiges Paket, baut
# pip es aus dem Quelltext, und dafuer braucht es einen C- UND einen
# Rust-Uebersetzer. Auf einem Raspberry Pi dauert das eine halbe Stunde und
# scheitert meist an fehlendem Arbeitsspeicher.
#
# Debian 12 bringt beide Pakete fertig mit: python3-websockets und
# python3-cryptography. Die venv wird deshalb MIT --system-site-packages
# angelegt - dann sieht sie die Systempakete, und pip muss gar nichts mehr
# holen. Erst wenn beides fehlt, wird pip bemueht.
BRAUCHT_PIP=0
for MODUL in websockets cryptography; do
    if "$PY3" -c "import $MODUL" >/dev/null 2>&1; then
        echo "<OK> Python-Modul $MODUL ist systemweit vorhanden."
    else
        BRAUCHT_PIP=1
    fi
done

if [ "$BRAUCHT_PIP" = "1" ]; then
    echo "<INFO> Es fehlt mindestens ein Modul - Versuch ueber apt."
    if command -v apt-get >/dev/null 2>&1; then
        DEBIAN_FRONTEND=noninteractive apt-get install -y --no-install-recommends \
            python3-websockets python3-cryptography >/tmp/dashboard_apt.log 2>&1 \
            && echo "<OK> Pakete ueber apt eingerichtet." \
            || { echo "<INFO> apt konnte die Pakete nicht einrichten:";
                 tail -n 5 /tmp/dashboard_apt.log; }
        rm -f /tmp/dashboard_apt.log
    fi
    BRAUCHT_PIP=0
    for MODUL in websockets cryptography; do
        "$PY3" -c "import $MODUL" >/dev/null 2>&1 || BRAUCHT_PIP=1
    done
fi

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
echo "<INFO> Selbsttest:"
"$VENV/bin/python3" "$PBIN/dashboard_dienst.py" --selbsttest 2>&1 | sed 's/^/<INFO> /' || true

echo "<INFO> Naechste Schritte:"
echo "<INFO>   1. Plugin oeffnen, Reiter Einstellungen, Dienst starten"
echo "<INFO>   2. Reiter Dashboards, 'Entwurf erzeugen' - danach steht schon etwas da"
echo "<INFO>   3. Reiter Designer, Kacheln ordnen"
echo "<INFO>   4. Reiter Dashboards, Adresse auf dem Tablet oeffnen"
echo "<OK> Installation abgeschlossen."
exit 0
