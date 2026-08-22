#!/bin/sh
# hermes-venv.sh — Snapshot / Restore / List the Hermes venv for fast rollback.
#
# Subcommands:
#   snapshot                 Create a tar.gz snapshot of $HERMES_HOME/venv
#   restore <snapshot_file>  Replace venv from a snapshot (stop→restore→patch→start)
#   list                     List available snapshots (newest first)
#   latest                   Print the newest snapshot path (or "none")
#
# Usage (as www-data or root — re-execs as www-data if root):
#   hermes-venv.sh snapshot
#   hermes-venv.sh restore venv-0.18.2_20260822_035914.tar.gz
#   hermes-venv.sh list
#   hermes-venv.sh latest
#
# The venv directory (~125MB, contains hermes-agent + all pip deps) is the
# upgrade/revert unit.  The standalone Python ($HERMES_HOME/python), Node,
# ripgrep, config.yaml, .env, logs, and conversations are NOT touched.

HERMES_HOME="${HERMES_HOME:-/var/www/moodledata/.hermes}"
BACKUP_DIR="${HERMES_VENV_BACKUP_DIR:-$HERMES_HOME/backups}"
KEEP="${HERMES_VENV_KEEP:-3}"
PID_FILE="$HERMES_HOME/.hermes-venv.pid"
LOG_FILE="$HERMES_HOME/.hermes-venv.log"
BRIDGE_CONTROL="${BRIDGE_CONTROL:-/var/www/html/public/local/hermesagent/hermes-bridge-control.sh}"
PLUGIN_DIR="${PLUGIN_DIR:-/var/www/html/public/local/hermesagent}"

# Re-exec as www-data if root
if [ "$(id -u)" = "0" ]; then
    mkdir -p "$BACKUP_DIR" "$HERMES_HOME"
    chown www-data:www-data "$BACKUP_DIR" "$HERMES_HOME" 2>/dev/null || true
    echo "=== Re-executing as www-data (was root) ==="
    exec su www-data -s /bin/sh -c \
        "HERMES_HOME='$HERMES_HOME' BACKUP_DIR='$BACKUP_DIR' KEEP='$KEEP' \
         BRIDGE_CONTROL='$BRIDGE_CONTROL' PLUGIN_DIR='$PLUGIN_DIR' \
         exec /bin/sh '$0' $*"
fi

# --- helpers ---
log() {
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] $*" | tee -a "$LOG_FILE"
}

get_version() {
    "$HERMES_HOME/venv/bin/hermes" --version 2>/dev/null | head -1 | \
        grep -oE '[0-9]+\.[0-9]+\.[0-9]+' | head -1
}

# Prune old snapshots, keep last $KEEP
prune() {
    local count=0
    for f in $(ls -1t "$BACKUP_DIR"/venv-*.tar.gz 2>/dev/null); do
        count=$((count + 1))
        if [ "$count" -gt "$KEEP" ]; then
            log "Pruning old snapshot: $(basename "$f")"
            rm -f "$f"
        fi
    done
}

# --- subcommands ---

cmd_snapshot() {
    mkdir -p "$BACKUP_DIR"
    local ver ts file
    ver=$(get_version)
    [ -z "$ver" ] && ver="unknown"
    ts=$(date +%Y%m%d_%H%M%S)
    file="$BACKUP_DIR/venv-${ver}_${ts}.tar.gz"

    log "=== Snapshot: venv (version=$ver) ==="
    local start end
    start=$(date +%s)
    if tar -czf "$file" -C "$HERMES_HOME" venv 2>>"$LOG_FILE"; then
        end=$(date +%s)
        local size
        size=$(du -h "$file" 2>/dev/null | cut -f1)
        log "Snapshot complete: $(basename "$file") ($size, $((end - start))s)"
        prune
        echo "$file"
        return 0
    else
        log "ERROR: tar failed"
        rm -f "$file"
        return 1
    fi
}

cmd_restore() {
    local snap="$1"
    if [ -z "$snap" ]; then
        echo "Usage: hermes-venv.sh restore <snapshot_file>" >&2
        return 1
    fi
    # Accept just a filename (search in BACKUP_DIR) or a full path
    if [ ! -f "$snap" ]; then
        snap="$BACKUP_DIR/$1"
    fi
    if [ ! -f "$snap" ]; then
        log "ERROR: snapshot not found: $1"
        return 1
    fi

    log "=== Restore: $(basename "$snap") ==="
    local start end
    start=$(date +%s)

    # 1. Stop the bridge AND wait until the venv is no longer in use.
    #    We rely on the bridge's own health endpoint as the liveness check:
    #    while it answers, hermes acp (which holds venv .so files open) is up.
    #    pgrep -f is unreliable here — it self-matches the kubectl/su wrapper
    #    command line that contains "acp_bridge.py". On NFS, deleting a file
    #    still open makes it a .nfs* silly-rename, so we must be sure the
    #    acp child is gone (do_stop sends SIGKILL; we just wait for the health
    #    port to stop answering, then give it a moment to release handles).
    if [ -f "$BRIDGE_CONTROL" ]; then
        log "Stopping bridge..."
        sh "$BRIDGE_CONTROL" stop 2>&1 | tee -a "$LOG_FILE"
    fi
    local bport="${BRIDGE_PORT:-9118}"
    local wait_i=0
    while [ $wait_i -lt 30 ]; do
        if ! curl -sf --max-time 2 "http://127.0.0.1:$bport/health" >/dev/null 2>&1; then
            break
        fi
        sleep 1; wait_i=$((wait_i + 1))
    done
    # Brief settle so the dead process releases its open file handles before we move the tree.
    sleep 3
    if curl -sf --max-time 2 "http://127.0.0.1:$bport/health" >/dev/null 2>&1; then
        log "WARNING: bridge still answering health after 33s — proceeding anyway"
    else
        log "Bridge down, venv free (waited ${wait_i}s)"
    fi

    # 2. Extract the snapshot into a TEMP dir, then swap it in. This avoids
    #    `rm -rf venv`-then-tar (which, on NFS, can leave .nfs* debris and
    #    risk a half-written tree). If extract fails, the old venv is intact.
    local tmp="$HERMES_HOME/.venv-restore.$$.tmp"
    log "Extracting snapshot to temp dir..."
    mkdir -p "$tmp"
    if ! tar -xzf "$snap" -C "$tmp" 2>>"$LOG_FILE"; then
        log "ERROR: tar extract failed — existing venv left untouched. No change made."
        rm -rf "$tmp"
        return 1
    fi

    # 3. Swap: old venv -> venv.old, new -> venv. (mv across the same FS is
    #    near-atomic; both dirs live on the same NFS mount.)
    local old="$HERMES_HOME/.venv.old.$$.tmp"
    log "Swapping venv..."
    rm -rf "$old" 2>/dev/null || true
    [ -d "$HERMES_HOME/venv" ] && mv "$HERMES_HOME/venv" "$old"
    if ! mv "$tmp/venv" "$HERMES_HOME/venv"; then
        log "ERROR: swap-in failed. Restoring previous venv..."
        rm -rf "$HERMES_HOME/venv" 2>/dev/null || true
        [ -d "$old" ] && mv "$old" "$HERMES_HOME/venv"
        rm -rf "$tmp"
        return 1
    fi
    # Best-effort cleanup of the old tree (may fail on NFS .nfs* — harmless).
    rm -rf "$old" 2>/dev/null || log "WARNING: old venv had NFS-held files; left at $old for manual cleanup"
    rm -rf "$tmp" 2>/dev/null || true

    # 4. Re-apply patches (bootstrap's step 5b: patch_acp_timeout + bridge)
    log "Re-applying patches..."
    if [ -f "$PLUGIN_DIR/scripts/patch_acp_timeout.py" ]; then
        "$HERMES_HOME/venv/bin/python" "$PLUGIN_DIR/scripts/patch_acp_timeout.py" \
            2>&1 | tee -a "$LOG_FILE" || log "WARNING: patch_acp_timeout failed"
    fi
    # Re-install the bridge script (it's in the venv's classpath)
    if [ -f "$PLUGIN_DIR/classes/bridge/acp_bridge.py" ]; then
        rm -f "$HERMES_HOME/classes/bridge/acp_bridge.py"
        cp "$PLUGIN_DIR/classes/bridge/acp_bridge.py" "$HERMES_HOME/classes/bridge/acp_bridge.py"
    fi

    # 5. Verify
    local ver
    ver=$(get_version)
    if [ -z "$ver" ]; then
        log "ERROR: hermes --version failed after restore"
        return 1
    fi

    # 6. Start the bridge
    log "Starting bridge..."
    if [ -f "$BRIDGE_CONTROL" ]; then
        sh "$BRIDGE_CONTROL" start 2>&1 | tee -a "$LOG_FILE"
        sleep 2
    fi

    end=$(date +%s)
    log "Restore complete: now on $ver ($((end - start))s)"
    echo "$ver"
    return 0
}

cmd_list() {
    echo "Available snapshots (newest first, keep=$KEEP):"
    echo "  DIR: $BACKUP_DIR"
    echo ""
    if [ -d "$BACKUP_DIR" ]; then
        for f in $(ls -1t "$BACKUP_DIR"/venv-*.tar.gz 2>/dev/null); do
            local name size mtime
            name=$(basename "$f")
            size=$(du -h "$f" 2>/dev/null | cut -f1)
            mtime=$(date -r "$f" '+%Y-%m-%d %H:%M' 2>/dev/null || echo "?")
            echo "  $name  ($size, $mtime)"
        done
    fi
    # Show current
    local cur
    cur=$(get_version)
    echo ""
    echo "  Current: venv on $cur"
}

cmd_latest() {
    local f
    f=$(ls -1t "$BACKUP_DIR"/venv-*.tar.gz 2>/dev/null | head -1)
    if [ -n "$f" ]; then
        echo "$f"
    else
        echo "none"
    fi
}

# --- main ---
case "$1" in
    snapshot)  cmd_snapshot ;;
    restore)   cmd_restore "$2" ;;
    list)      cmd_list ;;
    latest)    cmd_latest ;;
    *)
        echo "Usage: $0 {snapshot|restore <file>|list|latest}" >&2
        exit 1
        ;;
esac
