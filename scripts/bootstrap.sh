#!/bin/sh
# Bootstrap portable Hermes in moodledata/.hermes/
# All artifacts survive pod restarts (NFS-backed).
#
# Cross-platform: works on Alpine (musl), glibc Linux (Debian/Ubuntu/RHEL),
# macOS (Darwin), and Windows WSL2 (glibc Linux).
#
# This script is idempotent: running it multiple times is safe.
# It does NOT do `git pull` — plugin code updates come from the host
# via `make sync` (kubectl cp into the pod).
#
# Usage:
#   bootstrap.sh              # install/upgrade to latest hermes-agent
#   bootstrap.sh 0.17.0       # install specific hermes-agent version

HERMES_HOME="${HERMES_HOME:-/var/www/moodledata/.hermes}"
PLUGIN_DIR="${PLUGIN_DIR:-$(dirname "$(dirname "$0")")}"
TARGET_VERSION="$1"

echo "=== Hermes Portable Bootstrap ==="
echo "Target: $HERMES_HOME"
echo "Plugin dir: $PLUGIN_DIR"
if [ -n "$TARGET_VERSION" ]; then
    echo "Hermes version: $TARGET_VERSION (pinned)"
else
    echo "Hermes version: latest"
fi
echo ""

mkdir -p "$HERMES_HOME/logs" "$HERMES_HOME/mcp_servers" "$HERMES_HOME/classes/bridge" "$HERMES_HOME/bin"

# ---------------------------------------------------------------------------
# Detect platform: OS + architecture + libc variant
# Supports: Alpine (musl), glibc Linux (Debian/Ubuntu/RHEL/WSL2), macOS
# ---------------------------------------------------------------------------
OS_RAW=$(uname -s)
case "$OS_RAW" in
    Linux)  OS="linux" ;;
    Darwin) OS="macos" ;;
    *) echo "ERROR: Unsupported OS: $OS_RAW"; exit 1 ;;
esac

ARCH_RAW=$(uname -m)
case "$ARCH_RAW" in
    x86_64|amd64) ARCH="x86_64"; NODE_ARCH="x64" ;;
    aarch64|arm64) ARCH="aarch64"; NODE_ARCH="arm64" ;;
    *) echo "ERROR: Unsupported architecture: $ARCH_RAW"; exit 1 ;;
esac

# Detect libc (Linux only); macOS doesn't use musl/gnu distinction
LIBC=""
if [ "$OS" = "linux" ]; then
    LIBC="gnu"
    if ldd --version 2>&1 | grep -qi musl; then
        LIBC="musl"
    elif [ -f "/lib/ld-musl-x86_64.so.1" ] || [ -f "/lib/ld-musl-aarch64.so.1" ]; then
        LIBC="musl"
    fi
fi

echo "Platform: $OS/$ARCH (libc: ${LIBC:-n/a})"
echo ""

# ---------------------------------------------------------------------------
# Step 1: Standalone Python (platform-matched build)
# Linux: musl or gnu build matches host libc; macOS: darwin build
# ---------------------------------------------------------------------------
PYTHON_BIN="$HERMES_HOME/python/bin/python3.12"
if [ ! -f "$PYTHON_BIN" ]; then
    echo "[1/6] Downloading standalone Python..."
    TAG="20260610"
    PYVER="3.12.13"
    if [ "$OS" = "macos" ]; then
        URL="https://github.com/astral-sh/python-build-standalone/releases/download/${TAG}/cpython-${PYVER}%2B${TAG}-${ARCH}-apple-darwin-install_only.tar.gz"
    else
        URL="https://github.com/astral-sh/python-build-standalone/releases/download/${TAG}/cpython-${PYVER}%2B${TAG}-${ARCH}-unknown-linux-${LIBC}-install_only.tar.gz"
    fi
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
    echo "[1/6] Python already installed: $PYTHON_BIN"
fi
echo ""

# ---------------------------------------------------------------------------
# Step 2: uv
# Linux: musl build (statically linked, works on both musl and glibc)
# macOS: darwin build
# ---------------------------------------------------------------------------
UV_BIN="$HERMES_HOME/bin/uv"
if [ ! -f "$UV_BIN" ]; then
    echo "[2/6] Downloading uv..."
    UV_VERSION="0.11.28"
    if [ "$OS" = "macos" ]; then
        URL="https://github.com/astral-sh/uv/releases/download/${UV_VERSION}/uv-${ARCH}-apple-darwin.tar.gz"
    else
        URL="https://github.com/astral-sh/uv/releases/download/${UV_VERSION}/uv-${ARCH}-unknown-linux-musl.tar.gz"
    fi
    TMPFILE="$HERMES_HOME/uv.tar.gz"

    echo "  Downloading from $URL ..."
    if curl -fSL --progress-bar -o "$TMPFILE" "$URL" 2>&1; then
        tar xzf "$TMPFILE" -C "$HERMES_HOME/bin" --strip-components=1 2>/dev/null
        chmod +x "$UV_BIN"
        rm -f "$TMPFILE"
        echo "  uv installed: $UV_BIN"
    else
        echo "ERROR: Failed to download uv"
        rm -f "$TMPFILE"
        exit 1
    fi
else
    echo "[2/6] uv already installed: $UV_BIN"
fi
echo ""

# ---------------------------------------------------------------------------
# Step 3: ripgrep
# Linux x86_64: musl build (statically linked, works on both musl and glibc)
# Linux aarch64: gnu build (no musl build exists, but gnu is also static)
# macOS: darwin build
# ---------------------------------------------------------------------------
RG_BIN="$HERMES_HOME/bin/rg"
if [ ! -f "$RG_BIN" ]; then
    echo "[3/6] Downloading ripgrep..."
    RG_VERSION="15.1.0"
    if [ "$OS" = "macos" ]; then
        URL="https://github.com/BurntSushi/ripgrep/releases/download/${RG_VERSION}/ripgrep-${RG_VERSION}-${ARCH}-apple-darwin.tar.gz"
    elif [ "$ARCH" = "x86_64" ]; then
        URL="https://github.com/BurntSushi/ripgrep/releases/download/${RG_VERSION}/ripgrep-${RG_VERSION}-${ARCH}-unknown-linux-musl.tar.gz"
    else
        # aarch64: no musl build exists; gnu build is statically linked
        URL="https://github.com/BurntSushi/ripgrep/releases/download/${RG_VERSION}/ripgrep-${RG_VERSION}-${ARCH}-unknown-linux-gnu.tar.gz"
    fi
    TMPFILE="$HERMES_HOME/rg.tar.gz"

    echo "  Downloading from $URL ..."
    if curl -fSL --progress-bar -o "$TMPFILE" "$URL" 2>&1; then
        TMPDIR="$HERMES_HOME/_rg_tmp"
        rm -rf "$TMPDIR"
        mkdir -p "$TMPDIR"
        tar xzf "$TMPFILE" -C "$TMPDIR" --strip-components=1 2>/dev/null
        cp "$TMPDIR/rg" "$RG_BIN" 2>/dev/null || cp "$TMPDIR/bin/rg" "$RG_BIN" 2>/dev/null
        chmod +x "$RG_BIN"
        rm -rf "$TMPDIR" "$TMPFILE"
        echo "  ripgrep installed: $RG_BIN"
    else
        echo "  WARNING: Failed to download ripgrep — search_files will use grep fallback"
        rm -f "$TMPFILE"
    fi
else
    echo "[3/6] ripgrep already installed: $RG_BIN"
fi
echo ""

# ---------------------------------------------------------------------------
# Step 4: Node.js (platform-matched build)
# Linux musl x64:  unofficial-builds (musl tar.xz)
# Linux musl arm64: no musl build exists — fall back to gnu, warn if fails
# Linux gnu:       official nodejs.org (tar.xz)
# macOS:           official nodejs.org (tar.gz)
# ---------------------------------------------------------------------------
NODE_BIN="$HERMES_HOME/node/bin/node"
if [ ! -f "$NODE_BIN" ]; then
    echo "[4/6] Downloading Node.js..."
    NODE_VERSION="22.14.0"
    if [ "$OS" = "macos" ]; then
        URL="https://nodejs.org/dist/v${NODE_VERSION}/node-v${NODE_VERSION}-darwin-${NODE_ARCH}.tar.gz"
        TMPFILE="$HERMES_HOME/node.tar.gz"
    elif [ "$LIBC" = "musl" ] && [ "$NODE_ARCH" = "x64" ]; then
        URL="https://unofficial-builds.nodejs.org/download/release/v${NODE_VERSION}/node-v${NODE_VERSION}-linux-${NODE_ARCH}-musl.tar.xz"
        TMPFILE="$HERMES_HOME/node.tar.xz"
    else
        URL="https://nodejs.org/dist/v${NODE_VERSION}/node-v${NODE_VERSION}-linux-${NODE_ARCH}.tar.xz"
        TMPFILE="$HERMES_HOME/node.tar.xz"
    fi

    # Remove stale dir (may contain root-owned files from old apk install)
    rm -rf "$HERMES_HOME/node" 2>/dev/null || true
    mkdir -p "$HERMES_HOME/node"

    echo "  Downloading from $URL ..."
    if curl -fSL --progress-bar -o "$TMPFILE" "$URL" 2>&1; then
        tar xf "$TMPFILE" -C "$HERMES_HOME/node" --strip-components=1 2>/dev/null
        rm -f "$TMPFILE"
        echo "  Node.js installed: $NODE_BIN ($("$NODE_BIN" --version 2>&1))"
    else
        echo "  WARNING: Failed to download Node.js — browser/web tools may not work"
        rm -f "$TMPFILE"
    fi
else
    echo "[4/6] Node.js already installed: $NODE_BIN ($("$NODE_BIN" --version 2>&1))"
fi

# macOS: remove Gatekeeper quarantine attributes from downloaded binaries
if [ "$OS" = "macos" ]; then
    xattr -dr com.apple.quarantine "$HERMES_HOME/python" 2>/dev/null || true
    xattr -dr com.apple.quarantine "$HERMES_HOME/node" 2>/dev/null || true
    xattr -dr com.apple.quarantine "$HERMES_HOME/bin" 2>/dev/null || true
fi
echo ""

# ---------------------------------------------------------------------------
# Step 5: Create venv + install packages
# ---------------------------------------------------------------------------
if [ ! -f "$HERMES_HOME/venv/bin/python" ]; then
    echo "[5/6] Creating virtual environment..."
    "$PYTHON_BIN" -m venv "$HERMES_HOME/venv"
    echo "  venv created"
else
    echo "[5/6] venv already exists"
fi

# Use uv for package installation (10-100x faster than pip)
VENV_PYTHON="$HERMES_HOME/venv/bin/python"
if [ -n "$TARGET_VERSION" ]; then
    echo "  Installing hermes-agent==$TARGET_VERSION + deps via uv..."
    "$UV_BIN" pip install --python "$VENV_PYTHON" \
        "hermes-agent==$TARGET_VERSION" aiohttp pymysql mcp 2>&1 || \
        echo "  WARNING: uv install had errors (may still be usable)"
else
    echo "  Installing/upgrading hermes-agent + deps via uv..."
    "$UV_BIN" pip install --python "$VENV_PYTHON" \
        --upgrade hermes-agent aiohttp pymysql mcp 2>&1 || \
        echo "  WARNING: uv install had errors (may still be usable)"
fi
HERMES_VERSION=$("$HERMES_HOME/venv/bin/hermes" --version 2>&1 || echo "unknown")
echo "  Hermes: $HERMES_VERSION"
echo ""

# Step 5b: Install acp_bridge.py to persistent location
echo "[5b/6] Installing bridge scripts..."
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

# ---------------------------------------------------------------------------
# Step 6: Configure Hermes config.yaml
# ---------------------------------------------------------------------------
echo "[6/6] Configuring environment..."

# Create shell-init.sh inside HERMES_HOME (self-contained, no external files)
# On Alpine, /etc/profile resets PATH completely; hermes terminal tool uses
# login shell snapshot. This script restores our dirs via config.yaml
# terminal.shell_init_files — no ~/.profile or other external files needed.
# On glibc/macOS this is harmless (dirs already on PATH, prepend is a no-op).
cat > "$HERMES_HOME/shell-init.sh" << 'SHEOF'
# Restore Hermes tool paths (clobbered by /etc/profile in login shell on Alpine)
HERMES_HOME="${HERMES_HOME:-/var/www/moodledata/.hermes}"
if [ -d "$HERMES_HOME/bin" ]; then
    PATH="$HERMES_HOME/bin:$PATH"
fi
if [ -d "$HERMES_HOME/node/bin" ]; then
    PATH="$HERMES_HOME/node/bin:$PATH"
fi
export PATH
SHEOF
echo "  shell-init.sh: written to $HERMES_HOME/shell-init.sh"
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
export CONFIG_FILE HERMES_HOME
"$HERMES_HOME/venv/bin/python" -c "
import yaml, os
path = os.environ.get('CONFIG_FILE')
with open(path) as f:
    cfg = yaml.safe_load(f) or {}
if 'agent' not in cfg:
    cfg['agent'] = {}
hint = ('IMPORTANT: This is a Moodle plugin environment running as www-data inside a Kubernetes pod (phpfpm-0). '\
        'The Hermes gateway and ACP bridge run as background daemons managed by shell scripts. '\
        'NEVER run \\\"hermes gateway run\\\" or \\\"hermes acp\\\" directly in the foreground — they block forever '\
        'and will be killed by the terminal timeout. Instead use the control scripts: '\
        '/var/www/html/public/local/hermesagent/hermes-gateway-control.sh {start|stop|restart|status} for the gateway, '\
        'and /var/www/html/public/local/hermesagent/hermes-bridge-control.sh {start|stop|restart|status} for the ACP bridge. '\
        'To edit config files, write directly to /var/www/moodledata/.hermes/config.yaml and '\
        '/var/www/moodledata/.hermes/.env (these are the single source of truth). '\
        'The venv is at /var/www/moodledata/.hermes/venv/bin/hermes. '\
        'ripgrep is at /var/www/moodledata/.hermes/bin/rg. '\
        'Node.js is at /var/www/moodledata/.hermes/node/bin/node.')
cfg['agent']['environment_hint'] = hint
# Set approval timeout to 600s (10 min) — allows time for browser-based approval
cfg.setdefault('approvals', {})
cfg['approvals']['timeout'] = 600
# Configure terminal: use shell-init.sh to restore PATH after /etc/profile
# clobbers it (Alpine). Disable auto_source_bashrc (same PATH clobbering issue).
cfg.setdefault('terminal', {})
cfg['terminal']['shell_init_files'] = [os.path.join(os.environ.get('HERMES_HOME', '/var/www/moodledata/.hermes'), 'shell-init.sh')]
cfg['terminal']['auto_source_bashrc'] = False
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
" 2>&1


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
" 2>&1
fi
echo ""

# ---------------------------------------------------------------------------
# DB credentials directory
# ---------------------------------------------------------------------------
CRED_DIR="$HERMES_HOME/.credentials"
mkdir -p "$CRED_DIR"
chmod 700 "$CRED_DIR"
echo "  Credentials dir: $CRED_DIR (populated at bridge start)"
echo ""

# ---------------------------------------------------------------------------
# Verification
# ---------------------------------------------------------------------------
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

if [ -f "$UV_BIN" ]; then
    echo "  uv: OK ($("$UV_BIN" --version 2>&1))"
else
    echo "  uv: MISSING"
fi

if [ -f "$RG_BIN" ]; then
    echo "  ripgrep: OK ($("$RG_BIN" --version 2>&1 | head -1))"
else
    echo "  ripgrep: MISSING (grep fallback)"
fi

if [ -f "$NODE_BIN" ]; then
    echo "  node.js: OK ($("$NODE_BIN" --version 2>&1))"
else
    echo "  node.js: MISSING (browser/web tools limited)"
fi

# Bridge health check
echo ""
echo "=== Bridge Status ==="
if curl -sf "http://127.0.0.1:9118/health" >/dev/null 2>&1; then
    echo "  Bridge: RUNNING (port 9118)"
else
    echo "  Bridge: NOT running (will auto-start on first chat)"
fi

# Step 7: Install/update skills and plugins from local_hermes-synapse
echo ""
echo "=== Installing/updating synapse skills and plugins ==="
HERMES_BIN="$HERMES_HOME/venv/bin/hermes"
export HERMES_HOME  # ensure hermes CLI uses the right home, not ~/.hermes

if [ -x "$HERMES_BIN" ]; then
    # Add skill tap (idempotent — fails silently if already added)
    "$HERMES_BIN" skills tap add dive4dec/local_hermes-synapse 2>/dev/null || true

    # Install or update moodle-pdf-generation skill (--yes skips interactive confirm)
    # On first install: installs the skill. On re-run: re-installs over the existing copy,
    # pulling the latest version from GitHub via the Contents API.
    "$HERMES_BIN" skills install --yes --force dive4dec/local_hermes-synapse/skills/moodle-pdf-generation 2>/dev/null || true
    echo "  Skill: moodle-pdf-generation installed/updated"

    # Install dompdf dependency (one-time, pure PHP, platform-independent)
    # install_deps.sh is idempotent — skips if already installed.
    if [ ! -f "$HERMES_HOME/lib/dompdf/vendor/autoload.php" ]; then
        echo "  Installing dompdf v3.1.5..."
        sh "$HERMES_HOME/skills/moodle-pdf-generation/scripts/install_deps.sh" 2>/dev/null || \
            echo "  WARNING: dompdf install failed — PDF generation will not work"
    else
        echo "  dompdf: already installed"
    fi

    # Install/update moodle-bridge plugin
    # git + openssh-client are available in the phpfpm Docker image, so
    # `hermes plugins install/update` works natively via git clone.
    # Falls back to tarball download if git is unavailable or clone fails.
    SYNAPSE_PLUGIN_ID="dive4dec/local_hermes-synapse/plugins/moodle-bridge"
    PLUGIN_INSTALLED_VIA_GIT=false
    if command -v git >/dev/null 2>&1; then
        echo "  Updating moodle-bridge plugin via git..."
        if "$HERMES_BIN" plugins install --force --enable "$SYNAPSE_PLUGIN_ID" 2>/dev/null; then
            echo "  Plugin: moodle-bridge installed/updated via git"
            PLUGIN_INSTALLED_VIA_GIT=true
        else
            echo "  Git install failed, trying tarball fallback..."
        fi
    fi
    # Tarball fallback: works without git credentials, always available
    if [ "$PLUGIN_INSTALLED_VIA_GIT" = "false" ]; then
        echo "  Installing moodle-bridge via tarball fallback..."
        SYNAPSE_TARBALL="/tmp/local_hermes-synapse.tar.gz"
        SYNAPSE_EXTRACT="/tmp/local_hermes-synapse-main"
        curl -sL "https://github.com/dive4dec/local_hermes-synapse/archive/refs/heads/main.tar.gz" -o "$SYNAPSE_TARBALL"
        if tar -xzf "$SYNAPSE_TARBALL" -C /tmp/ 2>/dev/null; then
            mkdir -p "$HERMES_HOME/plugins"
            rm -rf "$HERMES_HOME/plugins/moodle-bridge"
            cp -r "$SYNAPSE_EXTRACT/plugins/moodle-bridge" "$HERMES_HOME/plugins/"
            echo "  Plugin: moodle-bridge installed via tarball"
        else
            echo "  WARNING: Failed to download local_hermes-synapse — plugin not updated"
        fi
        rm -f "$SYNAPSE_TARBALL"
        rm -rf "$SYNAPSE_EXTRACT"
    fi
    # Install pip dependencies (PyMySQL required by moodle-bridge)
    "$HERMES_HOME/venv/bin/pip" install PyMySQL 2>/dev/null || true
    # Enable the plugin (idempotent — works for both git and tarball installs)
    "$HERMES_BIN" plugins enable moodle-bridge 2>/dev/null || true

    # Set MOODLE_CONFIG_PATH for the plugin
    if ! grep -q "MOODLE_CONFIG_PATH" "$HERMES_HOME/.env" 2>/dev/null; then
        echo "MOODLE_CONFIG_PATH=/var/www/html/config.php" >> "$HERMES_HOME/.env"
    fi

    # Fix ownership: hermes plugins/skills install may have created root-owned
    # files (when bootstrap is run via kubectl exec as root). The bridge and
    # Moodle dashboard run as www-data, so everything must be www-data-owned.
    if command -v chown >/dev/null 2>&1; then
        chown -R www-data:www-data "$HERMES_HOME/plugins" "$HERMES_HOME/skills" "$HERMES_HOME/lib" "$HERMES_HOME/.env" 2>/dev/null || true
    fi
else
    echo "  WARNING: hermes binary not found — skipping skill/plugin install"
fi

echo ""
echo "=== Bootstrap complete ==="
echo "HERMES_HOME=$HERMES_HOME"
