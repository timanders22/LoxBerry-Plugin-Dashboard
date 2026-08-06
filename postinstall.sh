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

if [ ! -x "$VENV/bin/python3" ]; then
    "$PY3" -m venv "$VENV" || {
        echo "<FAIL> Die virtuelle Umgebung liess sich nicht anlegen."
        echo "<INFO> Meist fehlt das Paket python3-venv: sudo apt-get install python3-venv"
        exit 1
    }
    echo "<OK> Virtuelle Umgebung angelegt."
fi

"$VENV/bin/pip" install --upgrade pip >/dev/null 2>&1 || \
    echo "<INFO> pip liess sich nicht aktualisieren - das ist meist unschaedlich."

FEHLT=0
for PAKET in websockets cryptography; do
    if "$VENV/bin/pip" install --no-cache-dir "$PAKET" >/tmp/dashboard_pip.log 2>&1; then
        echo "<OK> Paket $PAKET eingerichtet."
    else
        echo "<FAIL> Paket $PAKET liess sich nicht einrichten."
        tail -n 12 /tmp/dashboard_pip.log
        FEHLT=1
    fi
done
rm -f /tmp/dashboard_pip.log
if [ "$FEHLT" = "1" ]; then
    echo "<FAIL> Ohne beide Pakete kommt keine Verbindung zum Miniserver zustande."
    echo "<INFO> Braucht 'cryptography' einen Uebersetzer, hilft meist:"
    echo "<INFO>   sudo apt-get install build-essential libssl-dev libffi-dev python3-dev"
    exit 1
fi

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
