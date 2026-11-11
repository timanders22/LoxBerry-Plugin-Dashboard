#!/bin/bash
# Dashboard-Designer - postupgrade
# Aufruf: <ZUFALLSKENNUNG> <NAME> <FOLDER> <VERSION> <BASEFOLDER> <TEMPFOLDER>
# ACHTUNG: $1 ist NICHT der Arbeitsordner, sondern eine zehnstellige
# Zufallskennung.
#
# Bis 0.9.42 war hier nichts zu tun - und das war Absicht. Seit 0.9.43 steht
# unten genau ein Schritt (Konfiguration vervollstaendigen); sonst gilt:
#
# Bis 0.9.12 rief diese Datei postinstall.sh auf. LoxBerry fuehrt beim Update
# aber ohnehin postinstall aus (Reihenfolge: preroot, preinstall, preupgrade,
# postinstall, postupgrade, postroot). Der Aufruf hier liess also die ganze
# Installation ein ZWEITES Mal laufen: Selbsttest, Rechtevergabe und die
# vollstaendige Ausgabe samt "<OK> Installation abgeschlossen." standen
# doppelt im Installationsprotokoll. Schaden hat es nicht angerichtet -
# postinstall.sh ist durchgehend mehrfach ausfuehrbar, und die Sicherungen
# sind nach dem ersten Lauf abgeraeumt -, aber ein Protokoll, in dem alles
# zweimal steht, verdeckt beim naechsten Mal die eine Zeile, auf die es
# ankommt.
#
# Was frueher hier stand und WO es jetzt steht:
#   - Konfiguration zurueckspielen        postinstall.sh
#   - Langzeitwerte zurueckholen          postinstall.sh (Gegenstueck zu preupgrade.sh)
#   - Dienst wieder starten, falls er lief postinstall.sh (seit 0.9.13)
#
# Seit 0.9.43 steht hier doch eine Sache (Regeln/05): fehlende Vorgaben einmal in dashboard.json eintragen, ueber
# bin/db_vervollstaendigen.php und damit ueber db_lib.php, den einzigen Schreiber. Hier und nicht in postinstall.sh:
# dort liegt noch die Marke "Aktualisierung laeuft", und solange sie liegt, schreibt das Vervollstaendigen nichts -
# auch nicht beim Dienststart, den postinstall.sh mit Ausnahme von der Marke ausloest. postinstall.sh hat die
# Konfiguration zurueckgespielt und endpunkt_ein (E6) gesetzt; postupgrade laeuft danach (Regeln/06).
PFOLDER="${3:-dashboard}"
case "$PFOLDER" in
    ''|*/*|*..*) echo "<WARNING> postupgrade: unzulaessiger Ordnername - Konfiguration nicht vervollstaendigt."; exit 0 ;;
esac
BASE="${5:-$LBHOMEDIR}"
DB_VV="$BASE/bin/plugins/$PFOLDER/db_vervollstaendigen.php"
if [ -n "$BASE" ] && [ -f "$BASE/config/plugins/$PFOLDER/dashboard.json" ] && [ -f "$DB_VV" ]; then
    DB_VV_ZEILE=$(cd / && LBHOMEDIR="$BASE" php "$DB_VV" "$PFOLDER" 2>&1 | tail -1)
    case "$DB_VV_ZEILE" in
        *";STAND=ergaenzt;"*)     echo "<OK> Konfiguration vervollstaendigt: ${DB_VV_ZEILE#*SCHLUESSEL=}" ;;
        *";STAND=vollstaendig;"*) echo "<OK> Konfiguration vollstaendig, nichts ergaenzt." ;;
        *";STAND=ruht;"*)         echo "<INFO> Konfiguration nicht vervollstaendigt: die Marke \"Aktualisierung laeuft\" liegt noch. Der Dienst holt es bei seinem naechsten Start nach." ;;
        *";STAND=ohne_merkwort;"*|*";STAND=ohne_datei;"*) echo "<INFO> Konfiguration nicht vervollstaendigt (keine lesbare dashboard.json mit Aktionstoken) - das Speichern im Formular schreibt sie vollstaendig." ;;
        *) echo "<WARNING> Konfiguration nicht vervollstaendigt: ${DB_VV_ZEILE:-keine Ausgabe}. Der Dienst versucht es bei seinem naechsten Start erneut." ;;
    esac
fi
exit 0
