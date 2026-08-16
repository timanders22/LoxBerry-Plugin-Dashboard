#!/bin/bash
# Dashboard-Designer - uninstall
# command <TEMPFOLDER> <NAME> <FOLDER> <VERSION> <BASEFOLDER>
#
# Warum es diese Datei gibt: preupgrade.sh legt die Sicherungen der drei
# Konfigurationsdateien EINE EBENE UEBER dem Pluginordner ab
# (config/plugins/<ordner>.backup.*). Die Deinstallation von LoxBerry
# entfernt nur config/plugins/<ordner>/ - die Sicherungen bleiben also
# liegen. Zwei Folgen, beide unerwuenscht:
#
#   1. dashboard.backup.zugang.json enthaelt Miniserver-Benutzer und
#      -Kennwort. Ein Geheimnis soll nur dort liegen, wo man es vermutet.
#   2. Eine spaetere NEUinstallation fand die alte Konfiguration samt altem
#      Aktionstoken vor und stellte sie stillschweigend wieder her. Eine
#      "saubere" Neuinstallation war damit keine.
#
# Der Dienst wird zuerst angehalten - sonst schreibt er noch in Ordner, die
# gleich verschwinden.

ARGV3=$3
ARGV5=$5
PFOLDER="${ARGV3:-dashboard}"
BASE="${ARGV5:-$LBHOMEDIR}"
if [ -z "$BASE" ] || [ ! -d "$BASE" ]; then
    SELF=$(cd "$(dirname "$0")" && pwd)
    BASE=$(cd "$SELF/../.." 2>/dev/null && pwd)
fi

DIENST="$BASE/bin/plugins/$PFOLDER/dienst.sh"
if [ -x "$DIENST" ]; then
    "$DIENST" stop >/dev/null 2>&1
    echo "<INFO> Dienst angehalten."
fi

ANZAHL=0
for f in dashboard.json seiten.json zugang.json; do
    BK="$BASE/config/plugins/$PFOLDER.backup.$f"
    if [ -f "$BK" ]; then
        rm -f "$BK" && ANZAHL=$((ANZAHL + 1))
    fi
done
# Die aeltere Sicherung der Oberflaeche (bis 0.9.5 hiess sie nur
# "<ordner>.backup.json") ebenfalls entfernen.
if [ -f "$BASE/config/plugins/$PFOLDER.backup.json" ]; then
    rm -f "$BASE/config/plugins/$PFOLDER.backup.json" && ANZAHL=$((ANZAHL + 1))
fi

echo "<OK> $ANZAHL Sicherungsdatei(en) mit Konfiguration und Zugangsdaten entfernt."
exit 0
