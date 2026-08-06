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

PID="$BASE/data/plugins/$PFOLDER/dienst.pid"
if [ -f "$PID" ]; then
    kill "$(cat "$PID")" 2>/dev/null || true
    sleep 2
    kill -9 "$(cat "$PID")" 2>/dev/null || true
    rm -f "$PID"
    echo "<INFO> Laufender Dienst angehalten."
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
exit 0
