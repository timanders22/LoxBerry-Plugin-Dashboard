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

# ---------- Die eigenen Prozesse erkennen ----------
#
# Argumentweise (Regeln/03, "Prozesse argumentweise erkennen"): argv[0] ist ein
# Python, argv[1] ist genau der eigene Dienstpfad, ein drittes Argument gibt es
# nicht. Bis 0.9.21 stand im Rueckfallweg unten das erste Signal ganz ohne
# Pruefung und vor dem harten eine Teilzeichenkettensuche
# (grep -qa "dashboard_dienst.py"). In WSL gemessen
# (Pruefung-Dashboard-0.9.21, Fall 8): ein fremder Prozess
# "tail -f <dienstpfad>", dessen Nummer in der PID-Datei stand, war nach
# preupgrade.sh tot.
#
# Die zweite Schreibweise deckt den Fall ab, dass der Dienst ueber einen
# anderen Pfad auf dieselbe Datei gestartet wurde (bin/dienst.sh loest seinen
# Ablageort mit readlink -f auf, hier kommt er aus $5).
DB_SKRIPT="$BASE/bin/plugins/$PFOLDER/dashboard_dienst.py"
DB_SKRIPT_R=$(readlink -f "$DB_SKRIPT" 2>/dev/null)
[ -n "$DB_SKRIPT_R" ] || DB_SKRIPT_R="$DB_SKRIPT"
DB_UID=$(id -u loxberry 2>/dev/null || id -u)

db_ist_dienst() {
    [ -r "/proc/$1/cmdline" ] || return 1
    {
        IFS= read -r -d '' db_a0 || return 1
        IFS= read -r -d '' db_a1 || return 1
        case "${db_a0##*/}" in python|python3|python3.*) ;; *) return 1 ;; esac
        if [ "$db_a1" != "$DB_SKRIPT" ]; then
            [ "$(readlink -f "$db_a1" 2>/dev/null)" = "$DB_SKRIPT_R" ] || return 1
        fi
        IFS= read -r -d '' db_a2 && return 1
        return 0
    } < "/proc/$1/cmdline"
}

# Alle eigenen Dienste des eigenen Benutzers - auch die ohne PID-Datei.
db_dienste() {
    for db_d in /proc/[0-9]*; do
        db_ist_dienst "${db_d#/proc/}" || continue
        [ "$(stat -c %u "$db_d" 2>/dev/null)" = "$DB_UID" ] || continue
        echo "${db_d#/proc/}"
    done
    return 0
}

# Beendet sie: freundlich, bis zu zehn Sekunden Zeit, dann hart - und vor JEDEM
# Signal wird neu gesucht, auch vor dem kill -9. Gibt die Nummern aus, die beim
# ersten Signal gemeint waren.
db_dienste_beenden() {
    db_ziel=$(db_dienste)
    [ -n "$db_ziel" ] || return 0
    kill $db_ziel 2>/dev/null
    db_i=0
    while [ $db_i -lt 10 ] && [ -n "$(db_dienste)" ]; do
        sleep 1
        db_i=$((db_i + 1))
    done
    db_rest=$(db_dienste)
    [ -n "$db_rest" ] && kill -9 $db_rest 2>/dev/null
    echo $db_ziel
}

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

# Die Meldung haengt an LIEF_VORHER, nicht am blossen Aufruf.
#
# LIEF_VORHER steht zwei Zeilen darueber und wurde fuer die Meldung nie
# benutzt: `anhalten()` in dienst.sh gibt auch ohne laufenden Dienst 0
# zurueck, und die Zeile stand ohnehin unbedingt da. Das Protokoll meldete
# damit bei jedem Update einen angehaltenen Dienst - auch bei einem, den
# der Betreiber laengst selbst gestoppt hatte. Gemessen 11.09.2026.
if [ -x "$DIENST" ]; then
    "$DIENST" stop >/dev/null 2>&1
    if [ "$LIEF_VORHER" = "1" ]; then
        echo "<INFO> Laufender Dienst ueber dienst.sh angehalten (Sollmerker entfernt)."
    else
        echo "<INFO> Der Dienst lief nicht - es war nichts anzuhalten."
    fi
else
    # Rueckfallebene, falls das Dienstskript fehlt: dieselbe Sorgfalt von Hand.
    rm -f "$BASE/data/plugins/$PFOLDER/soll_laufen"
    if [ -f "$PID" ]; then
        P=$(cat "$PID" 2>/dev/null)
        # Geprueft wird VOR dem ersten Signal, nicht erst vor dem harten.
        # Prozessnummern werden wiederverwendet: liegt eine alte PID-Datei
        # herum und traegt ihre Zahl inzwischen einen fremden Vorgang, traf
        # das erste Signal genau den.
        if [ -n "$P" ] && kill -0 "$P" 2>/dev/null && db_ist_dienst "$P"; then
            kill "$P" 2>/dev/null || true
            i=0
            while [ $i -lt 15 ] && kill -0 "$P" 2>/dev/null && db_ist_dienst "$P"; do
                sleep 1
                i=$((i + 1))
            done
            # Vor dem harten Signal erneut pruefen - er kann inzwischen weg
            # und die Nummer neu vergeben sein.
            if kill -0 "$P" 2>/dev/null && db_ist_dienst "$P"; then
                kill -9 "$P" 2>/dev/null || true
            fi
            # Nur hier gemeldet: eine liegengebliebene PID-Datei allein ist
            # kein laufender Dienst.
            echo "<INFO> Laufender Dienst angehalten (Rueckfallebene ohne dienst.sh)."
        elif [ -n "$P" ] && kill -0 "$P" 2>/dev/null; then
            echo "<INFO> Die Nummer $P aus der PID-Datei gehoert einem fremden"
            echo "<INFO> Vorgang - es wurde nichts beendet, die Datei wird entfernt."
        fi
        rm -f "$PID"
    fi
    # Dazu jeder eigene Dienst OHNE PID-Datei. purge_installation raeumt
    # data/plugins/<ordner>/ bei jedem Upgrade ab (Regeln/06), der Minutentakt
    # kann in der Luecke einen zweiten starten. In WSL gemessen
    # (Pruefung-Dashboard-0.9.21, Fall 8): ohne diesen Schritt lief er durch
    # das ganze Upgrade weiter.
    WAISEN=$(db_dienste_beenden)
    if [ -n "$WAISEN" ]; then
        echo "<INFO> Ein Dienst ohne PID-Datei lief und wurde beendet (PID $WAISEN)."
    fi
fi

# ---------- Zweitschrift der Konfiguration ----------
#
# Bis 0.9.21 stand hier ein 'cp -p' ohne jede Pruefung. Das ist die Richtung
# "sichern" der Klasse C (Bestand-2026-09-18/klasse-C) in ihrer schwaechsten
# Form - schwaecher als das '[ -s ]', nach dem der Bestandslauf gesucht hat;
# deshalb steht diese Stelle in keiner seiner Listen.
#
# Der Schaden: bricht ein Update zwischen preupgrade und postinstall ab, liegt
# die heile Zweitschrift des vorigen Laufs noch da. Ein zweiter Anlauf kopierte
# dann die inzwischen abgeschnittene Konfiguration darueber, und der letzte
# heile Stand war fort - ohne eine Zeile im Protokoll. Gemessen am 18.09.2026
# (Pruefung-Dashboard-0.9.22, Fall 8).
#
# Gibt es noch GAR KEINE Zweitschrift, wird auch eine beschaedigte Datei
# kopiert: etwas ist besser als nichts, und es geht nichts verloren (Bauart
# GardenaSmartSystem-1.2.10/preupgrade.sh, json_heil()).
#
# Rueckgabe: 0 = traegt Inhalt, 1 = traegt keinen, 2 = nicht pruefbar. Die
# Funktion steht wortgleich in postinstall.sh - die Hakenskripte laufen
# einzeln, eine gemeinsame Bibliothek gibt es fuer sie nicht.
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
    CF="$BASE/config/plugins/$PFOLDER/$f"
    ZWEIT="$BASE/config/plugins/$PFOLDER.backup.$f"
    [ -f "$CF" ] || continue
    case "$f" in
        seiten.json) ART=seiten ;;
        zugang.json) ART=zugang ;;
        *)           ART=dashboard ;;
    esac
    # Der Rueckgabewert wird SOFORT gemerkt: hinter dem naechsten Test waere
    # $? der des Tests (CLAUDE.md, "$? hinter einer &&-Kette").
    db_inhalt "$CF" "$ART"; RC_CF=$?
    if [ -f "$ZWEIT" ] && [ "$RC_CF" != 0 ]; then
        echo "<WARNING> $f traegt keinen lesbaren Inhalt. Die vorhandene"
        echo "<WARNING> Zweitschrift bleibt unveraendert - aus ihr werden die"
        echo "<WARNING> Einstellungen am Ende der Installation zurueckgeholt:"
        echo "<WARNING>   $ZWEIT"
        continue
    fi
    if cp -p "$CF" "$ZWEIT"; then
        echo "<INFO> $f gesichert."
    else
        echo "<WARNING> Die Zweitschrift von $f liess sich NICHT anlegen."
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
