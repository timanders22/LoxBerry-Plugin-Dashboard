#!/bin/bash
# Dashboard-Designer - Start, Stopp und Waechter des Dienstes.
#
# Die Pfade werden aus dem EIGENEN Ablageort abgeleitet, nicht ueber
# LoxBerry::System. Grund: LoxBerry::System leitet den Pluginordner aus dem
# Aufrufort ab; wird dieses Skript aus postinstall.sh oder aus dem Cron
# gestartet, kommt dort ueberall Leerstring zurueck - das Skript werkelt dann
# gegen /-Pfade und meldet trotzdem Erfolg.

# readlink -f loest Symlinks auf, BEVOR das Verzeichnis bestimmt wird.
# LoxBerry legt Daemons als Symlink unter system/daemons/plugins/ ab; von
# dort aufgerufen waere PNAME buchstaeblich "plugins", und PID-Datei,
# Sollmerker und Protokoll landeten neben statt in ihrem Ordner.
SELF=$(cd "$(dirname "$(readlink -f "$0")")" && pwd)   # <home>/bin/plugins/<ordner>

# ---------- Wurzel und Ordnername: GELESEN, nicht geraten ----------
#
# Bis 0.9.21 stand hier
#     PNAME=$(basename "$SELF")
#     LBHOMEDIR=$(cd "$SELF/../../.." && pwd)
# und gleich darauf ein 'mkdir -p' auf oberster Ebene. Der eigene Ablageort
# war damit die EINZIGE Quelle: ein gesetztes $LBHOMEDIR wurde ueberschrieben,
# der Ordnername kam aus dem Verzeichnisnamen, und der geratene Pfad wurde bei
# JEDEM Aufruf angelegt - auch bei 'status', also bei einem Aufruf, den jeder
# fuer folgenlos haelt.
#
# Gemessen am 18.09.2026 in WSL (Pruefung-Dashboard-0.9.22, Faelle 12 bis 14;
# dieselbe Bauart im Bestand unter Bestand-2026-09-18/klasse-H, Bauart H1,
# dort an AnkerSolix 0.9.18 nachgestellt):
#   - 'dienst.sh status' aus einem Pruefarchiv unter
#     <Wurzel>/pruefung/dashboard/bin legte in der LAUFENDEN Installation
#     data/plugins/bin und log/plugins/bin an;
#   - dieselbe Datei aus einem ausgepackten Archiv rechnete die Wurzel drei
#     Ebenen ueber sich aus und uebersah das gesetzte $LBHOMEDIR - der
#     laufende Dienst der Installation galt als "gestoppt";
#   - nach einem purge_installation legte schon ein 'status' den
#     Datenordner wieder an; "der Ordner ist da" sagte in der Upgrade-Luecke
#     damit nichts ueber eine gelungene Ruecksicherung aus.
#
# Hausform (Regeln/03 und Regeln/06): Stufe 1 ist die gelesene Umgebung,
# Stufe 2 die Aufwaertssuche nach einem Verzeichnis, das nachweislich eine
# Wurzel IST. Eine feste Zahl '..' waere nur die naechste Wette.
lb_wurzel_taugt() {          # $1 Kandidat
    [ -n "$1" ] && [ -d "$1/config/plugins" ] && [ -d "$1/data/plugins" ]
}
lb_wurzel_suchen() {
    v="$SELF"
    i=0
    while [ -n "$v" ] && [ "$v" != "/" ] && [ $i -lt 8 ]; do
        if lb_wurzel_taugt "$v"; then echo "$v"; return 0; fi
        v=$(dirname "$v"); i=$((i + 1))
    done
    return 1
}
if lb_wurzel_taugt "$LBHOMEDIR"; then
    :
else
    LBHOMEDIR=$(lb_wurzel_suchen)
fi
# Der Ordnername ebenso. $LBPPLUGINDIR steht am Geraet zwar nie in der
# Umgebung (Regeln/03, am 17.09.2026 gemessen) - wer sie setzt, meint sie aber
# ernst, und sie ist die einzige Quelle, die ein Aufruf von aussen mitgeben
# kann.
if [ -n "$LBPPLUGINDIR" ]; then
    PNAME=$(basename "$LBPPLUGINDIR")
else
    PNAME=$(basename "$SELF")
fi

# Die Gegenprobe steht VOR dem ersten Anlegen, nicht danach: ein Schutz, der
# erst hinter der Wirkung greift, ist keiner.
LBH_R=$(readlink -f "$LBHOMEDIR" 2>/dev/null)
if [ -z "$LBHOMEDIR" ] || [ ! -d "$LBHOMEDIR" ]; then
    echo "FEHLER: Es wurde kein LoxBerry-Wurzelverzeichnis gefunden."
    echo "        \$LBHOMEDIR ist nicht gesetzt, und oberhalb von"
    echo "        $SELF traegt kein Verzeichnis config/plugins und data/plugins."
    echo "        Es wurde nichts angelegt und nichts gestartet."
    exit 1
fi
if [ "$SELF" != "$LBH_R/bin/plugins/$PNAME" ] \
   && [ ! -d "$LBHOMEDIR/config/plugins/$PNAME" ]; then
    echo "FEHLER: '$PNAME' ist unter $LBHOMEDIR kein eingerichtetes Plugin,"
    echo "        und $SELF ist nicht dessen bin-Ordner."
    echo "        Der Aufruf kommt offenbar aus einem ausgepackten Archiv oder"
    echo "        einem Pruefordner. Es wurde nichts angelegt."
    echo "        Abhilfe: LBHOMEDIR und LBPPLUGINDIR setzen oder dienst.sh"
    echo "        aus <LoxBerry-Wurzel>/bin/plugins/<ordner> aufrufen."
    exit 1
fi

# Gearbeitet wird ab hier ausschliesslich mit der gelesenen Wurzel und dem
# gelesenen Ordnernamen - auch fuer das Dienstskript und die venv. Sonst
# verwaltete eine Datei aus dem Archiv den Dienst des Archivs, waehrend der
# Aufrufer die Installation meinte.
PBIN="$LBHOMEDIR/bin/plugins/$PNAME"
PDATA="$LBHOMEDIR/data/plugins/$PNAME"
PLOG="$LBHOMEDIR/log/plugins/$PNAME"
PCONFIG="$LBHOMEDIR/config/plugins/$PNAME"
PID="$PDATA/dienst.pid"
SOLL="$PDATA/soll_laufen"
# Die Marke "Aktualisierung laeuft". Sie liegt NEBEN dem Datenordner, weil
# purge_installation data/plugins/<ordner>/ beim Upgrade restlos abraeumt
# (Regeln/06) - im Ordner waere sie genau dann fort, wenn sie gebraucht wird.
# preupgrade.sh legt sie als Erstes an, postinstall.sh entfernt sie per trap.
MARKE="$LBHOMEDIR/data/plugins/$PNAME.upgrade_laeuft"
LOGDATEI="$PLOG/dashboard.log"
SKRIPT="$PBIN/dashboard_dienst.py"
# Zweite Schreibweise desselben Skripts fuer den Vergleich weiter unten: wurde
# der Dienst ueber einen anderen Weg auf dieselbe Datei gestartet (Symlink im
# Pfad, LBHOMEDIR gegen den aufgeloesten Ablageort), steht in seiner
# Befehlszeile eine andere Zeichenkette fuer dieselbe Datei. Ein Vergleich, der
# das uebersieht, meldet "laeuft nicht" und laesst den Dienst stehen.
SKRIPT_R=$(readlink -f "$SKRIPT" 2>/dev/null)
[ -n "$SKRIPT_R" ] || SKRIPT_R="$SKRIPT"
# Der Dienst laeuft als loxberry; wo es den Benutzer nicht gibt, als der
# eigene. Die Suche ueber /proc sieht nur dessen Prozesse an.
DIENST_UID=$(id -u loxberry 2>/dev/null || id -u)
# Welcher Python? Die venv wird bevorzugt, der System-Python ist die
# Rueckfallebene - postinstall.sh legt die Umgebung inzwischen MIT
# --system-site-packages an und kommt notfalls auch ganz ohne sie aus.
PY="$PBIN/venv/bin/python3"
PYQUELLE="virtuelle Umgebung"
if [ ! -x "$PY" ]; then
    PY=$(command -v python3 2>/dev/null)
    PYQUELLE="System-Python"
fi
# Kein __pycache__ neben den Plugin-Dateien anlegen.
export PYTHONDONTWRITEBYTECODE=1

# Als loxberry laufen, nicht als root.
#
# Der minuetliche Waechter kommt aus dem Cron. Laeuft der als root - und je
# nach Ablage des Cronjobs tut er das -, dann gehoerten PID-Datei,
# Sollmerker und Protokoll danach root. Die Oberflaeche laeuft als loxberry
# und koennte den Dienst anschliessend weder anhalten noch neu starten: sie
# darf die Dateien nicht mehr schreiben.
#
# Deshalb setzt sich das Skript selbst auf loxberry herunter, EINMAL, bevor
# es irgendetwas anlegt. exec, damit kein zusaetzlicher Prozess stehen
# bleibt.
# '-s /bin/bash' ausdruecklich: ohne das nimmt su die Login-Shell aus
# /etc/passwd. Steht dort nologin oder /bin/false, endet dieses Skript hier
# still und ohne Meldung - und weil es 'exec' ist, kaeme nicht einmal ein
# Rueckgabewert zurueck. Auf einem regulaeren LoxBerry ist der Zweig
# ohnehin unerreichbar (der Cron laeuft bereits als loxberry); er greift nur,
# wenn jemand von Hand mit sudo aufruft.
if [ "$(id -u)" = "0" ] && id loxberry >/dev/null 2>&1; then
    exec su -s /bin/bash loxberry -c "$(printf '%q ' "$0" "$@")"
fi

# Angelegt wird dort, wo wirklich geschrieben wird - nicht bei jedem Aufruf.
# Bis 0.9.21 stand hier ein unbedingtes 'mkdir -p "$PDATA" "$PLOG"'; schon ein
# 'status' legte damit Ordner an, und zwar im geratenen Pfad (siehe oben).
ordner_anlegen() {
    mkdir -p "$PDATA" "$PLOG" 2>/dev/null
}

# Zeitgrenze fuer die einmaligen Betriebsarten. 'timeout' gehoert zu
# coreutils und ist auf jedem Debian da; fehlt es doch, wird ohne gearbeitet
# statt den Aufruf zu verweigern.
if command -v timeout >/dev/null 2>&1; then
    ZEITGRENZE="timeout 25"
else
    ZEITGRENZE=""
fi

# ---------- Die eigenen Prozesse erkennen ----------
#
# Argumentweise, nicht ueber eine Teilzeichenkette (Regeln/03, "Prozesse
# argumentweise erkennen"). Bis 0.9.21 stand hier
#     grep -qa "dashboard_dienst.py" "/proc/$P/cmdline"
# und das trifft JEDE Befehlszeile, in der die Zeichenkette irgendwo vorkommt:
# einen Editor mit der Datei offen, ein Sicherungsskript, das den Ordner
# durchsucht, und den Einmallauf der eigenen Oberflaeche. In WSL gemessen
# (Bestand-2026-09-18/klasse-F-nachmessung, Zeile 8): ein fremder Prozess
# "tail -f <dienstpfad>", dessen Nummer in der PID-Datei stand, galt als
# Dienst - "status" meldete "laeuft 9000", und nach "stop" war er tot.
#
# Ein Treffer hat GENAU zwei Argumente: argv[0] ist ein Python, argv[1] ist
# genau der eigene Dienstpfad. Das dritte Argument schliesst die Einmallaeufe
# aus (--selbsttest, --einmal, --entwurf, --anmeldeprobe, --httpprobe,
# --visuprobe) - sie laufen als eigener Prozess, sind aber nicht der
# Dauerlaeufer und duerfen von "stop" nicht getroffen werden. Der Dauerlaeufer
# wird an genau einer Stelle gestartet, in starten(), als  "$PY" "$SKRIPT".
#
# Gelesen wird ohne Hilfsprogramm: "read -d ''" zerlegt die Befehlszeile am
# Nullbyte. Das spart je Prozess einen Aufruf von tr - der Waechter laeuft
# minuetlich.
ist_dienst() {
    [ -r "/proc/$1/cmdline" ] || return 1
    {
        IFS= read -r -d '' db_a0 || return 1
        IFS= read -r -d '' db_a1 || return 1
        case "${db_a0##*/}" in python|python3|python3.*) ;; *) return 1 ;; esac
        if [ "$db_a1" != "$SKRIPT" ]; then
            [ "$(readlink -f "$db_a1" 2>/dev/null)" = "$SKRIPT_R" ] || return 1
        fi
        IFS= read -r -d '' db_a2 && return 1
        return 0
    } < "/proc/$1/cmdline"
}

# Alle eigenen Dienste, aufsteigend und ohne Dubletten.
#
# Zwei Quellen, weil keine allein reicht:
#   - die Suche ueber /proc findet auch einen Dienst OHNE PID-Datei.
#     purge_installation raeumt data/plugins/<ordner>/ bei jedem Upgrade ab
#     (Regeln/06); der Minutentakt kann in der Luecke einen zweiten starten.
#     In WSL gemessen (Pruefung-Dashboard-0.9.21, Fall 3): "stop" meldete
#     "angehalten", und danach lief noch ein eigener Dienst.
#   - die PID-Datei findet auch einen Dienst, der einem anderen Benutzer
#     gehoert (von Hand als root gestartet) und deshalb durch den
#     Benutzerfilter faellt.
dienste() {
    {
        for db_d in /proc/[0-9]*; do
            ist_dienst "${db_d#/proc/}" || continue
            [ "$(stat -c %u "$db_d" 2>/dev/null)" = "$DIENST_UID" ] || continue
            echo "${db_d#/proc/}"
        done
        db_p=""
        [ -f "$PID" ] && IFS= read -r db_p < "$PID" 2>/dev/null
        case "$db_p" in
            ''|*[!0-9]*) ;;
            *) ist_dienst "$db_p" && echo "$db_p" ;;
        esac
    } | sort -un
}

laeuft() {
    [ -n "$(dienste)" ]
}

# Laeuft gerade eine Aktualisierung dieses Plugins?
#
# Gemessen am 18.09.2026 in WSL (Pruefung-Dashboard-0.9.23, Faelle L3 und C1):
# ohne diese Frage startete der Knopf "Dienst starten" mitten in der
# Upgrade-Luecke einen Dienst und legte dabei soll_laufen an. Ein Dienst, der
# vor dem Update BEWUSST angehalten war, lief danach wieder - und der
# Minutentakt hielt ihn am Leben.
#
# Ausgaenge:
#   Marke hoechstens 3600 s alt  -> gesperrt (C1, C7)
#   aelter, aus der Zukunft, leer oder unlesbar -> sie gilt nicht (C2 bis C5;
#                                   eine abgebrochene Installation darf den
#                                   Dienst nicht fuer immer stilllegen)
#   keine lesbare Uhr            -> die Pruefung faellt GESCHLOSSEN aus (C6)
#   DB_START_TROTZ_MARKE=1       -> Ausnahme fuer postinstall.sh (C10)
#
# Die Grenze 3600 s steht ein zweites Mal in webfrontend/html/db_lib.php,
# db_upgrade_marke(); wer eine aendert, aendert beide.
marke_sperrt() {
    [ -f "$MARKE" ] || return 1
    [ "${DB_START_TROTZ_MARKE:-0}" = "1" ] && return 1
    JETZT=$(date +%s 2>/dev/null)
    case "$JETZT" in ''|*[!0-9]*) return 0 ;; esac
    SEIT=$(cat "$MARKE" 2>/dev/null)
    case "$SEIT" in ''|*[!0-9]*) return 1 ;; esac
    ALTER=$((JETZT - SEIT))
    [ "$ALTER" -lt 0 ] && return 1
    [ "$ALTER" -le 3600 ]
}

starten() {
    ordner_anlegen
    LAUFEND=$(dienste)
    if [ -n "$LAUFEND" ]; then
        ERSTE=$(printf '%s\n' "$LAUFEND" | head -n 1)
        # Die PID-Datei nachziehen, wenn sie fehlt oder veraltet ist. Die
        # Nummer ist argumentweise geprueft - eine ungepruefte Nummer aus einer
        # Mustersuche darf hier nie hinein.
        echo "$ERSTE" > "$PID" 2>/dev/null
        echo "laeuft bereits (PID $ERSTE)"
        return 0
    fi
    # Diese Frage steht VOR dem 'touch "$SOLL"' weiter unten. Stuende sie
    # dahinter, legte der abgewiesene Start den Merker trotzdem an, und der
    # Waechter startete den Dienst nach dem Update doch (so an Govee 0.9.19
    # gemessen, dort Fall A6). Rueckgabewert 0: eine laufende Aktualisierung
    # ist kein Fehlschlag.
    if marke_sperrt; then
        echo "Eine Aktualisierung dieses Plugins laeuft - der Dienst wird jetzt nicht gestartet."
        echo "Lief er vor der Aktualisierung, startet die Installation ihn am Ende selbst wieder."
        return 0
    fi
    # Die Meldung muss sagen, was wirklich fehlt. Bis 0.9.5 stand hier
    # "virtuelle Python-Umgebung fehlt" - dieser Zweig wird aber NUR erreicht,
    # wenn es ueberhaupt kein python3 auf dem System gibt, und dann hilft
    # "Plugin neu installieren" nicht weiter.
    if [ -z "$PY" ] || [ ! -x "$PY" ]; then
        echo "FEHLER: Auf diesem System ist kein python3 zu finden - weder unter"
        echo "        $PBIN/venv/bin/python3 noch im Suchpfad."
        echo "        Abhilfe:  sudo apt-get install -y python3 python3-venv"
        return 1
    fi
    if [ ! -f "$SKRIPT" ]; then
        echo "FEHLER: $SKRIPT fehlt. Plugin neu installieren."
        return 1
    fi
    if [ ! -f "$PCONFIG/dashboard.json" ]; then
        echo "FEHLER: Konfiguration fehlt ($PCONFIG/dashboard.json). Erst die Oberflaeche oeffnen."
        return 1
    fi
    touch "$SOLL"
    # Die Ausgabe geht nach /dev/null, NICHT in die Logdatei: das Python-Skript
    # schreibt sie im Dauerbetrieb selbst, mit Rotation. Bis 0.9.5 zeigten
    # beide auf dieselbe Datei - jede Zeile stand doppelt darin, und nach dem
    # Umbenennen durch die Rotation schrieb dieser Deskriptor in die
    # umbenannte (spaeter geloeschte) Datei weiter, die dadurch den Platz auf
    # der SD-Karte unsichtbar belegte.
    nohup "$PY" "$SKRIPT" >/dev/null 2>&1 &
    echo $! > "$PID"
    sleep 1
    if laeuft; then
        echo "gestartet (PID $(cat "$PID"), $PYQUELLE)"
        return 0
    fi
    # Der Sollmerker wird wieder entfernt. Bliebe er stehen, versuchte der
    # minuetliche Waechter den Start jede Minute erneut - dauerhaft, mit rund
    # 2.880 Logzeilen am Tag, waehrend die Oberflaeche "gestoppt" zeigt.
    rm -f "$SOLL"
    echo "FEHLER: Start fehlgeschlagen - siehe $LOGDATEI"
    echo "        Letzte Zeilen:"
    tail -n 5 "$LOGDATEI" 2>/dev/null | sed 's/^/        /'
    rm -f "$PID"
    return 1
}

anhalten() {
    rm -f "$SOLL"
    # ALLE eigenen Dienste, nicht nur den aus der PID-Datei.
    ZIEL=$(dienste)
    if [ -z "$ZIEL" ]; then
        rm -f "$PID"
        echo "laeuft nicht"
        return 0
    fi
    kill $ZIEL 2>/dev/null
    for i in 1 2 3 4 5 6 7 8 9 10; do
        [ -n "$(dienste)" ] || break
        sleep 1
    done
    # Vor dem harten Signal wird NEU gesucht, nicht die Liste von vorhin
    # wiederverwendet: zwischen den beiden Signalen kann ein Prozess enden und
    # seine Nummer neu vergeben werden, und der Minutentakt kann waehrend der
    # Wartezeit einen zweiten Dienst gestartet haben (in WSL gemessen,
    # Pruefung-Dashboard-0.9.21, Fall 10).
    REST=$(dienste)
    if [ -n "$REST" ]; then
        kill -9 $REST 2>/dev/null
        sleep 1
    fi
    rm -f "$PID"
    # "angehalten" ist eine Zusicherung, kein Rueckgabewert: es wird nachgesehen
    # (CLAUDE.md, "Wirkung pruefen, nicht Rueckgabewert").
    UEBRIG=$(dienste)
    if [ -n "$UEBRIG" ]; then
        echo "FEHLER: Dienst laeuft weiter (PID $(printf '%s' "$UEBRIG" | tr '\n' ' '))"
        return 1
    fi
    echo "angehalten"
    return 0
}

case "$1" in
    start)   starten ;;
    stop)    anhalten ;;
    restart) anhalten; sleep 1; starten ;;
    status)
        # Gemeldet werden die gefundenen Nummern, nicht der Inhalt der
        # PID-Datei: liegt dort eine fremde oder veraltete Nummer, waere sie
        # eine Falschaussage. Laufen zwei, stehen beide da.
        LAUFEND=$(dienste)
        if [ -n "$LAUFEND" ]; then
            echo "laeuft $(printf '%s' "$LAUFEND" | tr '\n' ' ')"
            exit 0
        fi
        echo "gestoppt"
        exit 1
        ;;
    selbsttest)
        "$PY" "$SKRIPT" --selbsttest
        ;;
    einmal)
        # Mit Zeitgrenze. Die Oberflaeche ruft das ueber exec() auf und liest
        # bis EOF; antwortet der Miniserver gar nicht, haengt der Aufruf am
        # TCP-Zeitlimit des Betriebssystems (typisch rund 130 s) und damit
        # weit ueber PHPs max_execution_time - der Anwender saehe eine weisse
        # Seite statt einer Meldung.
        $ZEITGRENZE "$PY" "$SKRIPT" --einmal
        ;;
    entwurf)
        # $2 bewusst OHNE Anfuehrungszeichen: ist es nicht gesetzt, entsteht
        # so gar kein Argument. Quotiert entstuende ein leeres.
        $ZEITGRENZE "$PY" "$SKRIPT" --entwurf $2
        ;;
    anmeldeprobe)
        $ZEITGRENZE "$PY" "$SKRIPT" --anmeldeprobe
        ;;
    httpprobe)
        $ZEITGRENZE "$PY" "$SKRIPT" --httpprobe
        ;;
    visuprobe)
        $ZEITGRENZE "$PY" "$SKRIPT" --visuprobe
        ;;
    waechter)
        # Nur neu starten, wenn der Dienst laufen SOLL. Ein bewusst
        # angehaltener Dienst bleibt angehalten.
        if [ -f "$SOLL" ] && ! laeuft; then
            # Erst hier anlegen: der Waechter laeuft minuetlich, und ohne
            # Sollmerker hat er nichts zu schreiben.
            ordner_anlegen
            echo "[$(date '+%Y-%m-%d %H:%M:%S')] Waechter: Dienst lief nicht, wird neu gestartet." >> "$LOGDATEI"
            starten >> "$LOGDATEI" 2>&1
        fi
        ;;
    *)
        echo "Aufruf: $0 {start|stop|restart|status|selbsttest|einmal|entwurf|anmeldeprobe|httpprobe|visuprobe|waechter}"
        exit 2
        ;;
esac
