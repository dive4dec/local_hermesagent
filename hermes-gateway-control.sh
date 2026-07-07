#!/bin/sh
# Hermes Gateway process manager
# Runs as www-data (no sudo needed)
# Actions: start, stop, restart, status
#
# The gateway connects Hermes to messaging platforms (Matrix, Telegram, etc).
# It runs as a long-running foreground process via nohup.
# Config is read from $HERMES_HOME/.env (written by settings.php).

HERMES_HOME="${HERMES_HOME:-/var/www/moodledata/.hermes}"
PID_DIR="$HERMES_HOME/pids"
LOG_DIR="$HERMES_HOME/logs"
mkdir -p "$PID_DIR" "$LOG_DIR"

GATEWAY_PID_FILE="$PID_DIR/gateway.pid"
GATEWAY_LOG="$LOG_DIR/gateway.log"
HERMES_BIN="$HERMES_HOME/venv/bin/hermes"

pid_is_running() {
    [ -f "$1" ] || return 1
    pid=$(cat "$1" 2>/dev/null | tr -d '[:space:]')
    [ -n "$pid" ] && kill -0 "$pid" 2>/dev/null || return 1
    return 0
}

stop_by_pid() {
    pid_file="$1"
    if [ -f "$pid_file" ]; then
        pid=$(cat "$pid_file" 2>/dev/null | tr -d '[:space:]')
        if [ -n "$pid" ]; then
            kill "$pid" 2>/dev/null
            sleep 2
            kill -0 "$pid" 2>/dev/null && kill -9 "$pid" 2>/dev/null
        fi
        rm -f "$pid_file"
    fi
}

stop_by_pattern() {
    pattern="$1"
    pids=$(pgrep -f "$pattern" 2>/dev/null)
    if [ -n "$pids" ]; then
        for pid in $pids; do
            [ "$pid" = "$$"] && continue
            kill "$pid" 2>/dev/null
        done
        sleep 1
        pids=$(pgrep -f "$pattern" 2>/dev/null)
        for pid in $pids; do
            [ "$pid" = "$$" ] && continue
            kill -9 "$pid" 2>/dev/null
        done
    fi
}

do_start() {
    if pid_is_running "$GATEWAY_PID_FILE"; then
        echo "already running (pid $(cat "$GATEWAY_PID_FILE"))"
        return 0
    fi

    if [ ! -f "$HERMES_BIN" ]; then
        echo "FAILED: hermes binary not found at $HERMES_BIN" >&2
        return 1
    fi

    # Kill any stale gateway processes first
    stop_by_pattern "hermes gateway run"
    rm -f "$GATEWAY_PID_FILE"
    sleep 1

    # Start gateway in background
    HERMES_HOME="$HERMES_HOME" \
        nohup "$HERMES_BIN" gateway run --accept-hooks \
        >> "$GATEWAY_LOG" 2>&1 &
    echo $! > "$GATEWAY_PID_FILE"
    sleep 3

    if pid_is_running "$GATEWAY_PID_FILE"; then
        echo "started (pid $(cat "$GATEWAY_PID_FILE"))"
        return 0
    else
        rm -f "$GATEWAY_PID_FILE"
        echo "FAILED — check $GATEWAY_LOG" >&2
        return 1
    fi
}

do_stop() {
    stop_by_pid "$GATEWAY_PID_FILE"
    stop_by_pattern "hermes gateway run"
    echo "stopped"
}

do_restart() {
    do_stop
    sleep 1
    do_start
}

do_status() {
    if pid_is_running "$GATEWAY_PID_FILE"; then
        pid=$(cat "$GATEWAY_PID_FILE")
        echo "running (pid $pid)"
        # Show last 3 log lines for quick diagnostics
        if [ -f "$GATEWAY_LOG" ]; then
            echo "recent log:"
            tail -3 "$GATEWAY_LOG" 2>/dev/null
        fi
    else
        echo "stopped"
    fi
}

case "$1" in
    start)   do_start ;;
    stop)    do_stop ;;
    restart) do_restart ;;
    status)  do_status ;;
    *)
        echo "Usage: $0 {start|stop|restart|status}" >&2
        exit 1
        ;;
esac
