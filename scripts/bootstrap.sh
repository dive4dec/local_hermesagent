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
        tar xzf "$TMPFILE" -C "$HERMES_HOME/python" --strip-components=1 2>&1
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
echo "[3/5] Installing hermes-agent + aiohttp + pymysql..."
"$HERMES_HOME/venv/bin/python" -m pip install --quiet hermes-agent aiohttp pymysql 2>&1
HERMES_VERSION=$("$HERMES_HOME/venv/bin/hermes" --version 2>&1)
echo "  $HERMES_VERSION"
echo "  aiohttp + pymysql installed"
echo ""

# Step 4: Persist hermes_proxy_forward.py if it's only in /tmp/
mkdir -p "$HERMES_HOME/scripts"
if [ -f /tmp/hermes_proxy_forward.py ] && [ ! -f "$HERMES_HOME/scripts/hermes_proxy_forward.py" ]; then
    echo "[4/5] Persisting hermes_proxy_forward.py..."
    cp /tmp/hermes_proxy_forward.py "$HERMES_HOME/scripts/hermes_proxy_forward.py"
    chmod +x "$HERMES_HOME/scripts/hermes_proxy_forward.py"
    echo "  ✅ Copied to $HERMES_HOME/scripts/"
elif [ -f "$HERMES_HOME/scripts/hermes_proxy_forward.py" ]; then
    echo "[4/5] hermes_proxy_forward.py already persistent"
else
    echo "[4/5] hermes_proxy_forward.py not found anywhere"
fi
echo ""

# Step 5: Start proxy via tmux if not already running
if tmux has-session -t hermes-proxy 2>/dev/null; then
    echo "[5/5] hermes-proxy tmux session already running"
else
    echo "[5/5] Starting hermes-proxy in tmux..."
    tmux new-session -d -s hermes-proxy -x 80 -y 24 "$HERMES_HOME/venv/bin/python3 $HERMES_HOME/scripts/hermes_proxy_forward.py"
    echo "  ✅ Started"
fi
echo ""

# Step 6: Verify
echo "=== Verification ==="
if "$HERMES_HOME/venv/bin/hermes" --version >/dev/null 2>&1; then
    echo "  hermes: OK"
    if "$HERMES_HOME/venv/bin/hermes" acp --help >/dev/null 2>&1; then
        echo "  hermes acp: OK"
    else
        echo "  hermes acp: needs config"
    fi
else
    echo "  WARNING: hermes --version failed"
fi

echo ""
echo "=== Bootstrap complete ==="
echo "HERMES_HOME=$HERMES_HOME"
echo "To use: HERMES_HOME=$HERMES_HOME $HERMES_HOME/venv/bin/hermes acp"
