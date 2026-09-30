#!/bin/bash
# Dashboard-Designer - preinstall (neu in 0.9.25)
# Aufruf: <ZUFALLSKENNUNG> <NAME> <FOLDER> <VERSION> <BASEFOLDER> <TEMPFOLDER>
# ACHTUNG: $1 ist NICHT der Arbeitsordner, sondern eine zehnstellige
# Zufallskennung. Gearbeitet wird deshalb mit $3 und $5.
#
# Entscheidung 1 des Hausherrn (29.09.2026), Bauform AudiConnect 0.9.22 und
# Vorschlag des Installer-Pruefers (Durchgang 29.09.2026, Befund 1). Der
# Installer ruft dieses Skript bei JEDEM Einbau, nach dem Aufraeumen der alten
# Fassung und VOR Cron, Oberflaeche und postinstall.sh.
#
# Eine Aktualisierung erkennt es allein an der Marke
# data/plugins/<ordner>.upgrade_laeuft (kein Altersvergleich); dann tut es
# nichts - preupgrade.sh hat die Sicherungen eben erst angelegt.
# Ohne Marke ist es eine NEUINSTALLATION: liegengebliebene Zweitschriften und
# der Bestand einer frueheren Installation gehen nach <name>.alt, gemeldet mit
# genau einer <WARNING>; uninstall/uninstall raeumt sie ab. Gemessen vorher
# (Fall B): 0.9.25 spielte Aktionstoken, Miniserver-Kennwort und -Token und
# die Seiten einer frueheren Installation ein und startete den Dienst damit.
#
# S5 (0.9.26, Sicherungsverlauf): dazu gehoert config/plugins/<ordner>.sicherungen/.
# Er traegt Aktionstoken und Zugangsdaten einer frueheren Installation und geht
# bei einer Neuinstallation als Ganzes nach .sicherungen.alt. Beim Update liegt
# die Marke; dann tut dieses Skript nichts, und der Verlauf bleibt stehen.
ARGV3=$3
ARGV5=$5
PFOLDER="${ARGV3:-dashboard}"
BASE="${ARGV5:-$LBHOMEDIR}"
if [ -z "$BASE" ] || [ ! -d "$BASE/config/plugins" ] || [ ! -d "$BASE/data/plugins" ] \
   || [ ! -f "$BASE/config/system/general.json" ]; then
    echo "<WARNING> Kein LoxBerry-Wurzelverzeichnis erkannt ('$BASE') - nichts beiseitegelegt."
    exit 0
fi
case "$PFOLDER" in
    ''|*/*|*..*) echo "<WARNING> Unzulaessiger Ordnername '$PFOLDER' - nichts beiseitegelegt."; exit 0 ;;
esac
[ -f "$BASE/data/plugins/$PFOLDER.upgrade_laeuft" ] && exit 0

BEISEITE=""
FEST=""
for ZIEL in "$BASE/config/plugins/$PFOLDER.backup.dashboard.json" \
            "$BASE/config/plugins/$PFOLDER.backup.seiten.json" \
            "$BASE/config/plugins/$PFOLDER.backup.zugang.json" \
            "$BASE/config/plugins/$PFOLDER.backup.json" \
            "$BASE/data/plugins/$PFOLDER.upgrade_sicherung" \
            "$BASE/config/plugins/$PFOLDER.sicherungen"; do
    if [ -e "$ZIEL" ] || [ -L "$ZIEL" ]; then
        rm -rf "${ZIEL:?}.alt" 2>/dev/null
        if mv -f "$ZIEL" "$ZIEL.alt" 2>/dev/null; then
            BEISEITE="$BEISEITE $ZIEL.alt"
        else
            FEST="$FEST $ZIEL"
        fi
    fi
done
# Die Zweitschriften tragen Aktionstoken, PIN-Pruefwerte und Zugangsdaten.
for A in "$BASE/config/plugins/$PFOLDER.backup.dashboard.json.alt" \
         "$BASE/config/plugins/$PFOLDER.backup.seiten.json.alt" \
         "$BASE/config/plugins/$PFOLDER.backup.zugang.json.alt" \
         "$BASE/config/plugins/$PFOLDER.backup.json.alt"; do
    [ -f "$A" ] && [ ! -L "$A" ] && chmod 600 "$A" 2>/dev/null
done
# Der beiseitegelegte Sicherungsverlauf: Ordner 0700 (die Dateien darin tragen
# ihre 0600 schon; mv laesst Rechte stehen).
V_ALT="$BASE/config/plugins/$PFOLDER.sicherungen.alt"
[ -d "$V_ALT" ] && [ ! -L "$V_ALT" ] && chmod 700 "$V_ALT" 2>/dev/null
if [ -n "$BEISEITE" ] || [ -n "$FEST" ]; then
    T="<WARNING> Neuinstallation: Einstellungen, Seiten, Zugangsdaten und Sicherungsverlauf einer frueheren Installation werden NICHT eingespielt."
    [ -n "$BEISEITE" ] && T="$T Beiseitegelegt:$BEISEITE (die Deinstallation raeumt sie ab)."
    [ -n "$FEST" ] && T="$T Nicht zu verschieben, bitte von Hand entfernen:$FEST"
    echo "$T"
fi
exit 0
