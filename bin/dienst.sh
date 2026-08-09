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
if [ ! -x "$PY" ]; then
    PY=$(command -v python3 2>/dev/null)
fi

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
if [ "$(id -u)" = "0" ] && id loxberry >/dev/null 2>&1; then
    exec su loxberry -c "$(printf '%q ' "$0" "$@")"
fi

mkdir -p "$PDATA" "$PLOG" 2>/dev/null

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
    if [ ! -x "$PY" ]; then
        echo "FEHLER: virtuelle Python-Umgebung fehlt ($PY). Plugin neu installieren."
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
    # Ausgabe geht in die Logdatei. Das Python-Skript protokolliert deshalb
    # NICHT zusaetzlich nach stdout - sonst stuende jede Zeile doppelt darin.
    nohup "$PY" "$SKRIPT" >> "$LOGDATEI" 2>&1 &
    echo $! > "$PID"
    sleep 1
    if laeuft; then
        echo "gestartet (PID $(cat "$PID"))"
        return 0
    fi
    echo "FEHLER: Start fehlgeschlagen - siehe $LOGDATEI"
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
        "$PY" "$SKRIPT" --einmal
        ;;
    entwurf)
        "$PY" "$SKRIPT" --entwurf $2
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
        echo "Aufruf: $0 {start|stop|restart|status|selbsttest|einmal|entwurf|waechter}"
        exit 2
        ;;
esac
