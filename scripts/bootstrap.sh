#!/bin/sh
# Bootstrap portable Hermes in moodledata/.hermes/
# All artifacts survive pod restarts (NFS-backed)

HERMES_HOME="${HERMES_HOME:-/var/www/moodledata/.hermes}"
echo "=== Hermes Portable Bootstrap ==="
echo "Target: $HERMES_HOME"
echo ""

mkdir -p "$HERMES_HOME"

# Step 0: Ensure PATH priority - venv hermes takes precedence over /usr/bin/hermes
# We add the venv bin dir to PATH for the current session and persist it
# This ensures hermes acp uses the plugin's venv, not a system install
echo "[0] Setting PATH priority for venv hermes..."
echo 'export PATH="$HERMES_HOME/venv/bin:$PATH"' >> /var/www/html/.bashrc 2>/dev/null || true
export PATH="$HERMES_HOME/venv/bin:$PATH"
echo "  ✅ PATH: $HERMES_HOME/venv/bin has priority"
echo ""

# Step 1: Download standalone Python if needed
PYTHON_BIN="$HERMES_HOME/python/bin/python3.12"
if [ ! -f "$PYTHON_BIN" ]; then
    echo "[1/5] Downloading standalone Python (musl)..."
    ARCH=$(uname -m)
    echo "  Architecture: $ARCH"
    case "$ARCH" in
        x86_64) ARCH_URL="x86_64" ;;
        aarch64) ARCH_URL="aarch64" ;;
        *) echo "ERROR: Unsupported architecture: $ARCH"; exit 1 ;;
    esac

    TAG="20260610"
    PYVER="3.12.13"
    URL="https://github.com/astral-sh/python-build-standalone/releases/download/${TAG}/cpython-${PYVER}%2B${TAG}-${ARCH_URL}-unknown-linux-musl-install_only.tar.gz"

    echo "  URL: $URL"
    TMPFILE="$HERMES_HOME/python.tar.gz"

    echo "  Downloading (may take 1-2 minutes)..."
    if curl -fSL --progress-bar -o "$TMPFILE" "$URL" 2>&1; then
        SIZE=$(du -h "$TMPFILE" 2>/dev/null | cut -f1)
        echo "  Downloaded: $SIZE"

        echo "  Extracting..."
        mkdir -p "$HERMES_HOME/python"
        tar xzf "$TMPFILE" -C "$HERMES_HOME/python" --strip-components=1 2>/dev/null
        rm -f "$TMPFILE"
        echo "  Python installed: $PYTHON_BIN"
    else
        echo "ERROR: Failed to download Python from $URL"
        rm -f "$TMPFILE"
        exit 1
    fi
else
    echo "[1/5] Python already installed: $PYTHON_BIN"
fi
echo ""

# Step 2: Create virtual environment
if [ ! -f "$HERMES_HOME/venv/bin/python" ]; then
    echo "[2/5] Creating virtual environment..."
    "$PYTHON_BIN" -m venv "$HERMES_HOME/venv"
    echo "  venv created at $HERMES_HOME/venv"
else
    echo "[2/5] venv already exists"
fi
echo ""

# Step 3: Install packages
echo "[3/6] Installing hermes-agent + aiohttp + pymysql + mcp..."
"$HERMES_HOME/venv/bin/python" -m pip install --quiet hermes-agent aiohttp pymysql mcp 2>&1
HERMES_VERSION=$("$HERMES_HOME/venv/bin/hermes" --version 2>&1)
echo "  $HERMES_VERSION"
echo "  aiohttp + pymysql + mcp installed"
echo ""

# Step 3b: Setup Moodle DB MCP server for Hermes
echo "[3b/6] Setting up Moodle DB MCP server..."
PLUGIN_DIR="$(dirname "$(dirname "$0")")"
MCP_SCRIPT="$PLUGIN_DIR/scripts/moodle_db_mcp.py"
MCP_DEST="$HERMES_HOME/mcp_servers/moodle_db_mcp.py"
mkdir -p "$HERMES_HOME/mcp_servers"
if [ -f "$MCP_SCRIPT" ]; then
    cp "$MCP_SCRIPT" "$MCP_DEST"
    chmod +x "$MCP_DEST"
    echo "  ✅ MCP server script: $MCP_DEST"
else
    echo "  ⚠ MCP script not found at $MCP_SCRIPT, skipping"
fi

# Add MCP server config to HERMES_HOME/config.yaml
CONFIG_FILE="$HERMES_HOME/config.yaml"
if [ -f "$CONFIG_FILE" ]; then
    if grep -q "moodle_db:" "$CONFIG_FILE" 2>/dev/null; then
        echo "  ✅ MCP config already in $CONFIG_FILE"
    else
        echo "  Adding mcp_servers.moodle_db to $CONFIG_FILE"
        "$HERMES_HOME/venv/bin/python" -c "
import yaml, os
path = os.environ.get('CFG')
with open(path) as f:
    cfg = yaml.safe_load(f) or {}
if 'mcp_servers' not in cfg:
    cfg['mcp_servers'] = {}
hermes_home = os.environ.get('HERMES_HOME', '/var/www/moodledata/.hermes')
cfg['mcp_servers']['moodle_db'] = {
    'command': os.path.join(hermes_home, 'venv/bin/python'),
    'args': [os.path.join(hermes_home, 'mcp_servers/moodle_db_mcp.py')],
    'timeout': 60,
    'connect_timeout': 30
}
with open(path, 'w') as f:
    yaml.dump(cfg, f, default_flow_style=False, sort_keys=False, width=120)
print('  ✅ MCP config written')
" CFG="$CONFIG_FILE" HERMES_HOME="$HERMES_HOME" 2>&1
    fi
else
    echo "  ⚠ No config.yaml found at $CONFIG_FILE, skipping MCP config"
fi
echo ""

# Step 4: Persist hermes_proxy_forward.py if it's only in /tmp/
mkdir -p "$HERMES_HOME/scripts"
if [ -f /tmp/hermes_proxy_forward.py ] && [ ! -f "$HERMES_HOME/scripts/hermes_proxy_forward.py" ]; then
    echo "[4/6] Persisting hermes_proxy_forward.py..."
    cp /tmp/hermes_proxy_forward.py "$HERMES_HOME/scripts/hermes_proxy_forward.py"
    chmod +x "$HERMES_HOME/scripts/hermes_proxy_forward.py"
    echo "  ✅ Copied to $HERMES_HOME/scripts/"
elif [ -f "$HERMES_HOME/scripts/hermes_proxy_forward.py" ]; then
    echo "[4/6] hermes_proxy_forward.py already persistent"
else
    echo "[4/6] hermes_proxy_forward.py not found anywhere"
fi
echo ""

# Step 5: Start Hermes ACP as www-data in tmux (not root!)
echo "[5/6] Starting Hermes ACP as www-data in tmux..."
if tmux has-session -t hermes-acp 2>/dev/null; then
    echo "  hermes-acp tmux session already running"
    # Check if it's actually running (not zombie)
    if tmux capture-pane -t hermes-acp -p 2>/dev/null | grep -q "ACP client connected"; then
        echo "  ✅ ACP is healthy"
    else
        echo "  ACP not healthy, restarting..."
        tmux kill-session -t hermes-acp 2>/dev/null
        sleep 1
        tmux new-session -d -s hermes-acp -x 80 -y 24 "su -s /bin/sh -c \"HERMES_HOME=$HERMES_HOME $HERMES_HOME/venv/bin/hermes acp\" www-data"
        echo "  ✅ Restarted"
    fi
else
    tmux new-session -d -s hermes-acp -x 80 -y 24 "su -s /bin/sh -c \"HERMES_HOME=$HERMES_HOME $HERMES_HOME/venv/bin/hermes acp\" www-data"
    echo "  ✅ Started as www-data"
fi
echo ""

# Step 6: Verify
echo "=== Verification ==="
if "$HERMES_HOME/venv/bin/hermes" --version >/dev/null 2>&1; then
    echo "  hermes: OK"
else
    echo "  WARNING: hermes --version failed"
fi

# Check MCP config
if [ -f "$HERMES_HOME/config.yaml" ] && grep -q "moodle_db:" "$HERMES_HOME/config.yaml" 2>/dev/null; then
    echo "  mcp_servers.moodle_db: configured"
    if [ -f "$HERMES_HOME/mcp_servers/moodle_db_mcp.py" ]; then
        echo "  mcp_server script: present"
    else
        echo "  mcp_server script: MISSING"
    fi
else
    echo "  mcp_servers.moodle_db: NOT configured"
fi

# Check ACP status
echo ""
echo "=== ACP Status ==="
if tmux has-session -t hermes-acp 2>/dev/null; then
    ACP_OUTPUT=$(tmux capture-pane -t hermes-acp -p 2>/dev/null)
    if echo "$ACP_OUTPUT" | grep -q "ACP client connected"; then
        echo "  hermes-acp: running (www-data)"
        # Check MCP tools
        if echo "$ACP_OUTPUT" | grep -q "mcp_moodle_db"; then
            MCP_COUNT=$(echo "$ACP_OUTPUT" | grep -c "mcp_moodle_db")
            echo "  MCP tools: $MCP_COUNT discovered"
        else
            echo "  MCP tools: NOT discovered yet (may need restart)"
        fi
    else
        echo "  hermes-acp: tmux exists but ACP not connected"
    fi
else
    echo "  hermes-acp: NOT running"
fi

echo ""
echo "=== Bootstrap complete ==="
echo "HERMES_HOME=$HERMES_HOME"
echo "To use: HERMES_HOME=$HERMES_HOME $HERMES_HOME/venv/bin/hermes acp"
