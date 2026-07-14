#!/bin/sh
# Hermes ACP Bridge process manager
# Runs as www-data (no sudo needed)
# Actions: start, stop, restart, status
#
# The bridge (acp_bridge.py) is a FastAPI server that spawns `hermes acp`
# as a subprocess. It listens on port 9118 (configurable via BRIDGE_PORT env).
# The bridge script is copied to $HERMES_HOME/classes/bridge/acp_bridge.py
# by bootstrap.sh, and also exists in the plugin directory.

HERMES_HOME="${HERMES_HOME:-/var/www/moodledata/.hermes}"
BRIDGE_PORT="${BRIDGE_PORT:-9118}"
PID_DIR="$HERMES_HOME/pids"
mkdir -p "$PID_DIR"

BRIDGE_PID_FILE="$PID_DIR/acp-bridge.pid"

# Try the persistent copy first (survives plugin re-syncs), fall back to plugin dir
BRIDGE_SCRIPT="$HERMES_HOME/classes/bridge/acp_bridge.py"
if [ ! -f "$BRIDGE_SCRIPT" ]; then
    BRIDGE_SCRIPT="/var/www/html/public/local/hermesagent/classes/bridge/acp_bridge.py"
fi

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
    pattern="$1"
    pids=$(pgrep -f "$pattern" 2>/dev/null)
    if [ -n "$pids" ]; then
        for pid in $pids; do
            [ "$pid" = "$$" ] && continue
            kill "$pid" 2>/dev/null || echo "WARNING: cannot kill pid $pid (not owned by us)" >&2
        done
        sleep 1
        pids=$(pgrep -f "$pattern" 2>/dev/null)
        for pid in $pids; do
            [ "$pid" = "$$" ] && continue
            kill -9 "$pid" 2>/dev/null || echo "WARNING: cannot force-kill pid $pid" >&2
        done
    fi
}

start_bridge() {
    if pid_is_running "$BRIDGE_PID_FILE"; then
        echo "already running (pid $(cat "$BRIDGE_PID_FILE"))"
        return 0
    fi
    if [ ! -f "$BRIDGE_SCRIPT" ]; then
        echo "FAILED: bridge script not found" >&2
        return 1
    fi
    HERMES_HOME="$HERMES_HOME" BRIDGE_PORT="$BRIDGE_PORT" \
        ACP_APPROVAL_TIMEOUT=600 \
        PATH="$HERMES_HOME/bin:$HERMES_HOME/node/bin:$PATH" \
        nohup "$HERMES_HOME/venv/bin/python" "$BRIDGE_SCRIPT" \
        >> "$HERMES_HOME/logs/bridge.log" 2>&1 &
    echo $! > "$BRIDGE_PID_FILE"
    sleep 2
    if pid_is_running "$BRIDGE_PID_FILE"; then
        echo "started (pid $(cat "$BRIDGE_PID_FILE"))"
        return 0
    else
        rm -f "$BRIDGE_PID_FILE"
        echo "FAILED" >&2
        return 1
    fi
}

do_stop() {
    stop_by_pid "$BRIDGE_PID_FILE"
    # Fallback: kill by command pattern (catches orphaned processes)
    stop_by_pattern "acp_bridge.py"
    stop_by_pattern "hermes acp"
    stop_by_pattern "moodle_db_mcp.py"
    echo "stopped"
}

do_start() {
    # Kill any stale processes first
    stop_by_pattern "acp_bridge.py"
    stop_by_pattern "hermes acp"
    stop_by_pattern "moodle_db_mcp.py"
    rm -f "$BRIDGE_PID_FILE"
    sleep 1
    start_bridge
}

do_restart() {
    do_stop
    sleep 1
    do_start
}

do_status() {
    if pid_is_running "$BRIDGE_PID_FILE"; then
        pid=$(cat "$BRIDGE_PID_FILE")
        echo "running (pid $pid, port $BRIDGE_PORT)"
        # Quick health check
        if curl -sf "http://127.0.0.1:$BRIDGE_PORT/health" >/dev/null 2>&1; then
            echo "health: ok"
        else
            echo "health: no response (may be starting)"
        fi
    else
        echo "stopped"
    fi
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
        do_status
        ;;
    *)
        echo "Usage: $0 {start|stop|restart|status}" >&2
        exit 1
        ;;
esac
