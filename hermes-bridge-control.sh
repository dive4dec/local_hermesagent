#!/bin/sh
# Hermes ACP Bridge process manager
# Runs as www-data (no sudo needed)
# Actions: start, stop, restart, status

HERMES_HOME="${HERMES_HOME:-/var/www/moodledata/.hermes}"
PID_DIR="$HERMES_HOME/pids"
mkdir -p "$PID_DIR"

PROXY_PID_FILE="$PID_DIR/hermes-proxy.pid"
ACP_PID_FILE="$PID_DIR/hermes-acp.pid"
BRIDGE_PID_FILE="$PID_DIR/acp-bridge.pid"

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
            sleep 1
            kill -0 "$pid" 2>/dev/null && kill -9 "$pid" 2>/dev/null
        fi
        rm -f "$pid_file"
    fi
}

stop_by_pattern() {
    # Fallback: kill processes matching the given command pattern
    pattern="$1"
    pids=$(pgrep -f "$pattern" 2>/dev/null)
    if [ -n "$pids" ]; then
        for pid in $pids; do
            # Don't kill ourselves
            [ "$pid" = "$$" ] && continue
            kill "$pid" 2>/dev/null
        done
        sleep 1
        # Force kill anything still alive
        pids=$(pgrep -f "$pattern" 2>/dev/null)
        for pid in $pids; do
            [ "$pid" = "$$" ] && continue
            kill -9 "$pid" 2>/dev/null
        done
    fi
}

start_bridge() {
    if pid_is_running "$BRIDGE_PID_FILE"; then
        cat "$BRIDGE_PID_FILE"
        return 0
    fi
    HERMES_HOME="$HERMES_HOME" nohup "$HERMES_HOME/venv/bin/python" "$HERMES_HOME/classes/bridge/acp_bridge.py" >/dev/null 2>&1 &
    echo $! > "$BRIDGE_PID_FILE"
    sleep 2
    if pid_is_running "$BRIDGE_PID_FILE"; then
        cat "$BRIDGE_PID_FILE"
        return 0
    else
        rm -f "$BRIDGE_PID_FILE"
        echo "FAILED" >&2
        return 1
    fi
}

do_stop() {
    # First try PID files
    stop_by_pid "$BRIDGE_PID_FILE"

    # Fallback: kill by command pattern (catches orphaned processes)
    stop_by_pattern "hermes_proxy_forward.py"
    stop_by_pattern "acp_bridge.py"
    stop_by_pattern "hermes acp"
    stop_by_pattern "moodle_db_mcp.py"

    echo "stopped"
}

do_start() {
    # Kill any stale processes first
    stop_by_pattern "hermes_proxy_forward.py"
    stop_by_pattern "acp_bridge.py"
    stop_by_pattern "hermes acp"
    stop_by_pattern "moodle_db_mcp.py"
    rm -f "$PROXY_PID_FILE" "$ACP_PID_FILE" "$BRIDGE_PID_FILE"
    sleep 1

    bridge_pid=$(start_bridge)
    bridge_ret=$?
    echo "bridge=$bridge_pid ret=$bridge_ret"
}

do_restart() {
    do_stop
    sleep 1
    do_start
}

case "$1" in
    start)
        do_start
        ;;
    stop)
        do_stop
        ;;
    restart)
        do_restart
        ;;
    status)
        proxy_pid=""
        acp_pid=""
        if [ -f "$PROXY_PID_FILE" ]; then
            pid=$(cat "$PROXY_PID_FILE" 2>/dev/null | tr -d '[:space:]')
            if [ -n "$pid" ] && kill -0 "$pid" 2>/dev/null; then
                proxy_pid="$pid"
            else
                rm -f "$PROXY_PID_FILE"
            fi
        fi
        if [ -f "$ACP_PID_FILE" ]; then
            pid=$(cat "$ACP_PID_FILE" 2>/dev/null | tr -d '[:space:]')
            if [ -n "$pid" ] && kill -0 "$pid" 2>/dev/null; then
                acp_pid="$pid"
            else
                rm -f "$ACP_PID_FILE"
            fi
        fi
        echo "proxy=$proxy_pid acp=$acp_pid"
        ;;
    *)
        echo "Usage: $0 {start|stop|restart|status}" >&2
        exit 1
        ;;
esac
