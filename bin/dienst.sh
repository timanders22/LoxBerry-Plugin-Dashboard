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
PNAME=$(basename "$SELF")
LBHOMEDIR=$(cd "$SELF/../../.." && pwd)
PDATA="$LBHOMEDIR/data/plugins/$PNAME"
PLOG="$LBHOMEDIR/log/plugins/$PNAME"
PCONFIG="$LBHOMEDIR/config/plugins/$PNAME"
PID="$PDATA/dienst.pid"
SOLL="$PDATA/soll_laufen"
LOGDATEI="$PLOG/dashboard.log"
SKRIPT="$SELF/dashboard_dienst.py"
# Welcher Python? Die venv wird bevorzugt, der System-Python ist die
# Rueckfallebene - postinstall.sh legt die Umgebung inzwischen MIT
# --system-site-packages an und kommt notfalls auch ganz ohne sie aus.
PY="$SELF/venv/bin/python3"
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

mkdir -p "$PDATA" "$PLOG" 2>/dev/null

# Zeitgrenze fuer die einmaligen Betriebsarten. 'timeout' gehoert zu
# coreutils und ist auf jedem Debian da; fehlt es doch, wird ohne gearbeitet
# statt den Aufruf zu verweigern.
if command -v timeout >/dev/null 2>&1; then
    ZEITGRENZE="timeout 25"
else
    ZEITGRENZE=""
fi

laeuft() {
    [ -f "$PID" ] || return 1
    P=$(cat "$PID" 2>/dev/null)
    [ -n "$P" ] || return 1
    kill -0 "$P" 2>/dev/null || return 1
    # Nummernrecycling ausschliessen: der Prozess muss unser Skript sein
    grep -qa "dashboard_dienst.py" "/proc/$P/cmdline" 2>/dev/null || return 1
    return 0
}

starten() {
    if laeuft; then
        echo "laeuft bereits (PID $(cat "$PID"))"
        return 0
    fi
    # Die Meldung muss sagen, was wirklich fehlt. Bis 0.9.5 stand hier
    # "virtuelle Python-Umgebung fehlt" - dieser Zweig wird aber NUR erreicht,
    # wenn es ueberhaupt kein python3 auf dem System gibt, und dann hilft
    # "Plugin neu installieren" nicht weiter.
    if [ -z "$PY" ] || [ ! -x "$PY" ]; then
        echo "FEHLER: Auf diesem System ist kein python3 zu finden - weder unter"
        echo "        $SELF/venv/bin/python3 noch im Suchpfad."
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
    if ! laeuft; then
        rm -f "$PID"
        echo "laeuft nicht"
        return 0
    fi
    P=$(cat "$PID")
    kill "$P" 2>/dev/null
    for i in 1 2 3 4 5 6 7 8 9 10; do
        laeuft || break
        sleep 1
    done
    if laeuft; then
        kill -9 "$P" 2>/dev/null
        sleep 1
    fi
    rm -f "$PID"
    echo "angehalten"
    return 0
}

case "$1" in
    start)   starten ;;
    stop)    anhalten ;;
    restart) anhalten; sleep 1; starten ;;
    status)
        if laeuft; then
            echo "laeuft $(cat "$PID")"
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
    waechter)
        # Nur neu starten, wenn der Dienst laufen SOLL. Ein bewusst
        # angehaltener Dienst bleibt angehalten.
        if [ -f "$SOLL" ] && ! laeuft; then
            echo "[$(date '+%Y-%m-%d %H:%M:%S')] Waechter: Dienst lief nicht, wird neu gestartet." >> "$LOGDATEI"
            starten >> "$LOGDATEI" 2>&1
        fi
        ;;
    *)
        echo "Aufruf: $0 {start|stop|restart|status|selbsttest|einmal|entwurf|anmeldeprobe|httpprobe|waechter}"
        exit 2
        ;;
esac
