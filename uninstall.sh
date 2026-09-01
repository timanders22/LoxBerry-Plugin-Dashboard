#!/bin/bash
# Dashboard-Designer - uninstall
# Aufruf: <ZUFALLSKENNUNG> <NAME> <FOLDER> <VERSION> <BASEFOLDER> <TEMPFOLDER>
# ACHTUNG: $1 ist NICHT der Arbeitsordner, sondern eine zehnstellige
# Zufallskennung. Gearbeitet wird deshalb mit $3 und $5.
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
#
# ACHTUNG: Diese Datei und uninstall/uninstall raeumen SEIT 0.9.13 dasselbe
# ab. Bis 0.9.12 waren die Aufgaben aufgeteilt, und keines der beiden war
# vollstaendig: hier fehlte data/plugins/<ordner>.upgrade_sicherung, dort das
# Anhalten des Dienstes. Welches LoxBerry ausfuehrt, ist hier nicht
# nachgemessen - deshalb tut jetzt jedes die ganze Arbeit. Beide sind
# mehrfach ausfuehrbar; der zweite Lauf findet nichts mehr und meldet 0.

# Aufwaerts suchen, bis ein Verzeichnis gefunden ist, das nachweislich eine
# LoxBerry-Wurzel IST. Bis 0.9.12 stand hier eine feste Zahl '..' - und die
# ist nur die naechste Wette: je nach Ablageort sind es drei Ebenen oder vier.
lb_wurzel_suchen() {
    v=$(cd "$(dirname "$(readlink -f "$0")")" 2>/dev/null && pwd)
    i=0
    while [ -n "$v" ] && [ "$v" != "/" ] && [ $i -lt 8 ]; do
        if [ -d "$v/config/plugins" ] && [ -d "$v/data/plugins" ]; then
            echo "$v"; return 0
        fi
        v=$(dirname "$v"); i=$((i + 1))
    done
    return 1
}

PFOLDER="${3:-dashboard}"
BASE="${5:-$LBHOMEDIR}"
if [ -z "$BASE" ] || [ ! -d "$BASE" ]; then
    BASE=$(lb_wurzel_suchen)
fi
if [ -z "$BASE" ] || [ ! -d "$BASE" ]; then
    echo "<INFO> Kein Wurzelverzeichnis gefunden - nichts abgeraeumt."
    exit 0
fi

DIENST="$BASE/bin/plugins/$PFOLDER/dienst.sh"
if [ -x "$DIENST" ]; then
    "$DIENST" stop >/dev/null 2>&1
    echo "<INFO> Dienst angehalten."
fi

# Das Sternchen trifft nur die eigenen Zweitschriften: der Punkt hinter dem
# Ordnernamen trennt den Nachbarn vom Ordner. Bis 0.9.12 stand hier eine
# feste Liste von drei Namen - die aeltere "<ordner>.backup.json" musste
# deshalb eigens nachgetragen werden, und jede kuenftige waere durchgefallen.
ANZAHL=0
for f in "$BASE/config/plugins/$PFOLDER".backup.*; do
    [ -e "$f" ] || continue
    rm -f "$f" && ANZAHL=$((ANZAHL + 1))
done
if [ -e "$BASE/data/plugins/$PFOLDER.upgrade_sicherung" ]; then
    rm -rf "$BASE/data/plugins/$PFOLDER.upgrade_sicherung" && ANZAHL=$((ANZAHL + 1))
fi

echo "<OK> $ANZAHL Datei(en)/Ordner mit Konfiguration, Handarbeit und Zugangsdaten entfernt."
exit 0
