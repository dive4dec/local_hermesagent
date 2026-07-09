#!/bin/sh
# Bootstrap portable Hermes in moodledata/.hermes/
# All artifacts survive pod restarts (NFS-backed).
#
# This script is idempotent: running it multiple times is safe.
# It does NOT do `git pull` — plugin code updates come from the host
# via `make sync` (kubectl cp into the pod).

HERMES_HOME="${HERMES_HOME:-/var/www/moodledata/.hermes}"
PLUGIN_DIR="${PLUGIN_DIR:-$(dirname "$(dirname "$0")")}"

echo "=== Hermes Portable Bootstrap ==="
echo "Target: $HERMES_HOME"
echo "Plugin dir: $PLUGIN_DIR"
echo ""

mkdir -p "$HERMES_HOME/logs" "$HERMES_HOME/mcp_servers" "$HERMES_HOME/classes/bridge"

# Step 1: Download standalone Python if needed (musl for Alpine)
PYTHON_BIN="$HERMES_HOME/python/bin/python3.12"
if [ ! -f "$PYTHON_BIN" ]; then
    echo "[1/5] Downloading standalone Python (musl)..."
    ARCH=$(uname -m)
    case "$ARCH" in
        x86_64) ARCH_URL="x86_64" ;;
        aarch64) ARCH_URL="aarch64" ;;
        *) echo "ERROR: Unsupported architecture: $ARCH"; exit 1 ;;
    esac

    TAG="20260610"
    PYVER="3.12.13"
    URL="https://github.com/astral-sh/python-build-standalone/releases/download/${TAG}/cpython-${PYVER}%2B${TAG}-${ARCH_URL}-unknown-linux-musl-install_only.tar.gz"
    TMPFILE="$HERMES_HOME/python.tar.gz"

    echo "  Downloading from $URL ..."
    if curl -fSL --progress-bar -o "$TMPFILE" "$URL" 2>&1; then
        mkdir -p "$HERMES_HOME/python"
        tar xzf "$TMPFILE" -C "$HERMES_HOME/python" --strip-components=1 2>/dev/null
        rm -f "$TMPFILE"
        echo "  Python installed: $PYTHON_BIN"
    else
        echo "ERROR: Failed to download Python"
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
    echo "  venv created"
else
    echo "[2/5] venv already exists"
fi
echo ""

# Step 3: Install/update packages
TARGET_VERSION="$1"

if [ -n "$TARGET_VERSION" ]; then
    echo "[3/5] Downgrading/Installing specific Hermes version: v$TARGET_VERSION ..."
    "$HERMES_HOME/venv/bin/python" -m pip install --quiet hermes-agent==$TARGET_VERSION aiohttp pymysql mcp 2>&1 || \
        echo "  WARNING: pip install for v$TARGET_VERSION had errors"
else
    echo "[3/5] Installing/Upgrading to LATEST hermes-agent..."
    "$HERMES_HOME/venv/bin/python" -m pip install --quiet --upgrade hermes-agent aiohttp pymysql mcp 2>&1 || \
        echo "  WARNING: pip install had errors"
fi
HERMES_VERSION=$("$HERMES_HOME/venv/bin/hermes" --version 2>&1 || echo "unknown")
echo "  Current Hermes Version after sync: $HERMES_VERSION"

# Step 3b: Install acp_bridge.py to persistent location
echo "[3b/5] Installing bridge scripts..."
if [ -f "$PLUGIN_DIR/classes/bridge/acp_bridge.py" ]; then
    rm -f "$HERMES_HOME/classes/bridge/acp_bridge.py"
    cp "$PLUGIN_DIR/classes/bridge/acp_bridge.py" "$HERMES_HOME/classes/bridge/acp_bridge.py"
    echo "  acp_bridge.py: installed"
else
    echo "  WARNING: acp_bridge.py not found in plugin dir"
fi

# Patch ACP adapter to use configurable approval timeout (default 600s)
if [ -f "$PLUGIN_DIR/scripts/patch_acp_timeout.py" ]; then
    "$HERMES_HOME/venv/bin/python" "$PLUGIN_DIR/scripts/patch_acp_timeout.py" 2>&1 || \
        echo "  WARNING: ACP timeout patch failed (non-fatal)"
fi

# Install MCP server script
if [ -f "$PLUGIN_DIR/scripts/moodle_db_mcp.py" ]; then
    rm -f "$HERMES_HOME/mcp_servers/moodle_db_mcp.py"
    cp "$PLUGIN_DIR/scripts/moodle_db_mcp.py" "$HERMES_HOME/mcp_servers/moodle_db_mcp.py"
    chmod +x "$HERMES_HOME/mcp_servers/moodle_db_mcp.py"
    echo "  moodle_db_mcp.py: installed"
else
    echo "  WARNING: moodle_db_mcp.py not found in plugin dir"
fi
echo ""

# Step 4: Configure Hermes config.yaml with MCP server and environment hint
echo "[4/5] Configuring MCP servers and environment hint..."
CONFIG_FILE="$HERMES_HOME/config.yaml"
if [ ! -f "$CONFIG_FILE" ]; then
    "$HERMES_HOME/venv/bin/hermes" config check >/dev/null 2>&1 || true
    if [ ! -f "$CONFIG_FILE" ]; then
        cat > "$CONFIG_FILE" << 'YAMLEOF'
model:
  default: custom
  provider: custom
YAMLEOF
        echo "  Created minimal config.yaml"
    fi
fi

# Set environment_hint so Hermes knows to use control scripts, not foreground processes
"$HERMES_HOME/venv/bin/python" -c "
import yaml, os
path = os.environ.get('CONFIG_FILE')
with open(path) as f:
    cfg = yaml.safe_load(f) or {}
if 'agent' not in cfg:
    cfg['agent'] = {}
hint = ('IMPORTANT: This is a Moodle plugin environment running as www-data inside a Kubernetes pod (phpfpm-0). '
        'The Hermes gateway and ACP bridge run as background daemons managed by shell scripts. '
        'NEVER run \"hermes gateway run\" or \"hermes acp\" directly in the foreground — they block forever '
        'and will be killed by the terminal timeout. Instead use the control scripts: '
        '/var/www/html/public/local/hermesagent/hermes-gateway-control.sh {start|stop|restart|status} for the gateway, '
        'and /var/www/html/public/local/hermesagent/hermes-bridge-control.sh {start|stop|restart|status} for the ACP bridge. '
        'To edit config files, write directly to /var/www/moodledata/.hermes/config.yaml and '
        '/var/www/moodledata/.hermes/.env (these are the single source of truth). '
        'The venv is at /var/www/moodledata/.hermes/venv/bin/hermes.')
cfg['agent']['environment_hint'] = hint
# Set approval timeout to 600s (10 min) — allows time for browser-based approval
cfg.setdefault('approvals', {})
cfg['approvals']['timeout'] = 600
# Configure vision auxiliary to use the custom provider (same as main model)
cfg.setdefault('auxiliary', {})
cfg['auxiliary'].setdefault('vision', {})
cfg['auxiliary']['vision']['provider'] = 'custom:Socratic.cs.cityu.edu.hk'
cfg['auxiliary']['vision']['model'] = 'Socrates'
cfg['auxiliary']['vision']['base_url'] = 'https://socratic.cs.cityu.edu.hk/ai-test/v1'
cfg['auxiliary']['vision']['api_key'] = '1d90785d9594f5001583f921c1878fb57d711b94ab774d3f8136631c6c253706'
cfg['auxiliary']['vision']['timeout'] = 120
cfg['auxiliary']['vision']['download_timeout'] = 30
with open(path, 'w') as f:
    yaml.dump(cfg, f, default_flow_style=False, sort_keys=False, width=200)
print('  environment_hint: set')
print('  approvals.timeout: 600')
print('  auxiliary.vision: custom:Socratic.cs.cityu.edu.hk / Socrates')
" CONFIG_FILE=\"$CONFIG_FILE\" 2>&1

# Add moodle_db MCP server config if not present
if grep -q "moodle_db:" "$CONFIG_FILE" 2>/dev/null; then
    echo "  MCP config already present"
else
    "$HERMES_HOME/venv/bin/python" -c "
import yaml, os
path = os.environ.get('CONFIG_FILE')
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
print('  MCP config written')
" CONFIG_FILE="$CONFIG_FILE" HERMES_HOME="$HERMES_HOME" 2>&1
fi
echo ""

# Step 5: Write DB credentials for MCP server
echo "[5/5] Writing DB credentials..."
CRED_DIR="$HERMES_HOME/.credentials"
mkdir -p "$CRED_DIR"
chmod 700 "$CRED_DIR"
# Credentials are written by lib.php at bridge start time, but we ensure
# the directory exists here. The MCP server reads from $CRED_DIR/db.env.
echo "  Credentials dir: $CRED_DIR (populated at bridge start)"
echo ""

# Verification
echo "=== Verification ==="
if "$HERMES_HOME/venv/bin/hermes" --version >/dev/null 2>&1; then
    echo "  hermes: OK ($("$HERMES_HOME/venv/bin/hermes" --version 2>&1 | head -1))"
else
    echo "  WARNING: hermes --version failed"
fi

if [ -f "$HERMES_HOME/classes/bridge/acp_bridge.py" ]; then
    echo "  acp_bridge.py: present"
else
    echo "  acp_bridge.py: MISSING"
fi

if [ -f "$HERMES_HOME/mcp_servers/moodle_db_mcp.py" ]; then
    echo "  moodle_db_mcp.py: present"
else
    echo "  moodle_db_mcp.py: MISSING"
fi

if [ -f "$CONFIG_FILE" ] && grep -q "moodle_db:" "$CONFIG_FILE" 2>/dev/null; then
    echo "  mcp_servers.moodle_db: configured"
else
    echo "  mcp_servers.moodle_db: NOT configured"
fi

# Bridge health check (not tmux!)
echo ""
echo "=== Bridge Status ==="
if curl -sf "http://127.0.0.1:9118/health" >/dev/null 2>&1; then
    echo "  Bridge: RUNNING (port 9118)"
else
    echo "  Bridge: NOT running (will auto-start on first chat)"
fi

echo ""
echo "=== Bootstrap complete ==="
echo "HERMES_HOME=$HERMES_HOME"
