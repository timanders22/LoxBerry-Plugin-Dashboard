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
        # Die Sicherung danach wegraeumen. Sie liegt eine Ebene UEBER dem
        # Pluginordner und ueberlebt deshalb eine Deinstallation. Bis 0.9.5
        # blieb sie liegen - eine spaetere Neuinstallation holte daraus
        # stillschweigend die alte Konfiguration samt altem Aktionstoken
        # zurueck, und dashboard.backup.zugang.json mit dem
        # Miniserver-Kennwort lag unbegrenzt im Dateisystem.
        rm -f "$BK"
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
exit 0
