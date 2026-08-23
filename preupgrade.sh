#!/bin/bash
# Dashboard-Designer - preupgrade
# command <TEMPFOLDER> <NAME> <FOLDER> <VERSION> <BASEFOLDER>
#
# Gesichert werden Konfiguration UND das gepflegte Dashboard. Die Kacheln von
# Hand zu ordnen kostet einen Abend - das darf eine Aktualisierung nicht
# kosten.
ARGV3=$3
ARGV5=$5
PFOLDER="${ARGV3:-dashboard}"
BASE="${ARGV5:-$LBHOMEDIR}"

# Anhalten ueber dienst.sh, nicht mit einem eigenen kill.
#
# Der entscheidende Unterschied ist nicht die Geduld, sondern der Sollmerker:
# dienst.sh stop loescht 'soll_laufen'. Ohne das steht die Datei weiter da,
# und der minuetliche Waechter aus dem Cron startet den Dienst MITTEN im
# Update wieder - mit halb ausgetauschten Dateien.
#
# Zwei Sekunden waren ohnehin zu knapp: der Dienst haelt einen WebSocket
# offen und meldet sich beim Miniserver ordentlich ab.
DIENST="$BASE/bin/plugins/$PFOLDER/dienst.sh"
PID="$BASE/data/plugins/$PFOLDER/dienst.pid"
if [ -x "$DIENST" ]; then
    "$DIENST" stop >/dev/null 2>&1
    echo "<INFO> Laufender Dienst ueber dienst.sh angehalten (Sollmerker entfernt)."
elif [ -f "$PID" ]; then
    rm -f "$BASE/data/plugins/$PFOLDER/soll_laufen"
    P=$(cat "$PID" 2>/dev/null)
    if [ -n "$P" ] && kill -0 "$P" 2>/dev/null; then
        kill "$P" 2>/dev/null || true
        i=0
        while [ $i -lt 15 ] && kill -0 "$P" 2>/dev/null; do
            sleep 1
            i=$((i + 1))
        done
        if kill -0 "$P" 2>/dev/null && grep -qa "dashboard_dienst.py" "/proc/$P/cmdline" 2>/dev/null; then
            kill -9 "$P" 2>/dev/null || true
        fi
    fi
    rm -f "$PID"
    echo "<INFO> Laufender Dienst angehalten (Rueckfallebene ohne dienst.sh)."
fi

for f in dashboard.json seiten.json zugang.json; do
    CF="$BASE/config/plugins/$PFOLDER/$f"
    if [ -f "$CF" ]; then
        cp -p "$CF" "$BASE/config/plugins/$PFOLDER.backup.$f"
        echo "<INFO> $f gesichert."
    fi
done
# zugang.json enthaelt Zugangsdaten - die Sicherung ebenso schuetzen.
chmod 600 "$BASE/config/plugins/$PFOLDER.backup.zugang.json" 2>/dev/null
echo "<OK> preupgrade abgeschlossen."

# ---------- Langzeitwerte retten ----------
# der Verlauf, aus dem die Kurven der letzten Tage entstehen.
# Der Installer loescht data/plugins/<x>/ bei JEDEM Update - gemessen an
# sbin/plugininstall.pl (Zweig master, 23.08.2026): &purge_installation steht
# im Upgrade-Zweig (:886), und ihr Rumpf loescht ohne Bedingung (:1631).
# Deshalb NEBEN den Ordner: "rm -rf .../<x>/" trifft den Nachbarn mit dem
# Punkt nicht. postinstall.sh holt ihn zurueck und raeumt ihn weg.
LANG_SICHER="$BASE/data/plugins/$PFOLDER.upgrade_sicherung"
mkdir -p "$LANG_SICHER" 2>/dev/null
chmod 0700 "$LANG_SICHER" 2>/dev/null
for LANG_F in verlauf.json; do
    [ -f "$BASE/data/plugins/$PFOLDER/$LANG_F" ] \
        && cp -p "$BASE/data/plugins/$PFOLDER/$LANG_F" "$LANG_SICHER/$LANG_F" 2>/dev/null
done
# Die Wirkung pruefen, nicht den Rueckgabewert: liegt hinterher etwas da?
if [ -n "$(ls -A "$LANG_SICHER" 2>/dev/null)" ]; then
    echo "<OK> Langzeitwerte gesichert."
fi
exit 0
