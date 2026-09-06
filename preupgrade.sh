#!/bin/bash
# Dashboard-Designer - preupgrade
# Aufruf: <ZUFALLSKENNUNG> <NAME> <FOLDER> <VERSION> <BASEFOLDER> <TEMPFOLDER>
# ACHTUNG: $1 ist NICHT der Arbeitsordner, sondern eine zehnstellige
# Zufallskennung. Gearbeitet wird deshalb mit $3 und $5. Bis 0.9.12 stand
# hier die falsche Reihenfolge - folgenlos, weil keines der Skripte $1
# benutzt, aber uninstall/uninstall schrieb es im selben Plugin richtig hin.
#
# Gesichert werden Konfiguration UND das gepflegte Dashboard. Die Kacheln von
# Hand zu ordnen kostet einen Abend - das darf eine Aktualisierung nicht
# kosten.
ARGV3=$3
ARGV5=$5
PFOLDER="${ARGV3:-dashboard}"
BASE="${ARGV5:-$LBHOMEDIR}"

# Aufwaerts suchen, bis ein Verzeichnis gefunden ist, das nachweislich eine
# LoxBerry-Wurzel IST. Bis 0.9.12 fehlte diese Pruefung hier als einzigem der
# vier Skripte - und ausgerechnet preupgrade ist das EINZIGE Rettungsfenster:
# was hier nicht herausgetragen wird, loescht purge_installation gleich
# darauf. Eine Erfolgsmeldung ohne Wirkung ist hier teurer als anderswo.
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
if [ -z "$BASE" ] || [ ! -d "$BASE" ]; then
    BASE=$(lb_wurzel_suchen)
fi
if [ -z "$BASE" ] || [ ! -d "$BASE" ]; then
    echo "<FAIL> Kein LoxBerry-Wurzelverzeichnis gefunden - es wurde NICHTS gesichert."
    echo "<FAIL> Konfiguration, Dashboards und Zugangsdaten gehen bei diesem Update"
    echo "<FAIL> verloren. Das Update jetzt abbrechen und von Hand sichern."
    exit 1
fi

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

# ZUERST merken, ob der Dienst laufen SOLL - danach wird der Merker durch
# 'dienst.sh stop' geloescht, und was davon uebrig bliebe, raeumt gleich
# darauf purge_installation mit dem ganzen data/plugins/<x>/ weg.
#
# Ohne diese Zeilen war die Folge still und unangenehm: nach jedem Update
# stand das Plugin, der Cron-Waechter fand keinen Sollmerker und startete
# nichts, die Installation meldete Erfolg, und die Oberflaeche zeigte
# "gestoppt" - als haette der Betreiber ihn selbst angehalten.
LIEF_VORHER=0
[ -f "$BASE/data/plugins/$PFOLDER/soll_laufen" ] && LIEF_VORHER=1

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
# Der Sollmerker faehrt neben dem Ordner mit - derselbe Weg wie der Verlauf.
[ "$LIEF_VORHER" = "1" ] && : > "$LANG_SICHER/lief_vorher"
for LANG_F in verlauf.json; do
    [ -f "$BASE/data/plugins/$PFOLDER/$LANG_F" ] \
        && cp -p "$BASE/data/plugins/$PFOLDER/$LANG_F" "$LANG_SICHER/$LANG_F" 2>/dev/null
done
# Die Wirkung pruefen, nicht den Rueckgabewert: liegt hinterher etwas da?
if [ -n "$(ls -A "$LANG_SICHER" 2>/dev/null)" ]; then
    echo "<OK> Langzeitwerte gesichert."
fi
exit 0
