#!/usr/bin/env python3
"""
Patch hermes-agent's MCP client for the `mcp` SDK 1.x -> 2.0.0 breaking change.

In `mcp` 2.0.0 the `CallToolResult.isError` field was renamed to `is_error`
(snake_case). hermes-agent's `tools/mcp_tool.py` still reads `result.isError`,
so on mcp 2.0.0 EVERY MCP tool call raises:

    AttributeError: 'CallToolResult' object has no attribute 'isError'

which silently breaks all MCP servers (e.g. moodle_db) — the agent then falls
back to raw terminal commands.

This makes the read version-tolerant: try `is_error` (2.0.0) first, fall back
to `isError` (1.x). Safe on both SDK versions.

Idempotent — safe to run on every bootstrap. Non-fatal if the pattern isn't
found (e.g. a future hermes version already handles it); we warn, not fail.

Run this after `hermes update` / `pip install --upgrade hermes-agent`.
"""
import sys
from pathlib import Path

VENV = Path(sys.prefix)
SITE = VENV / "lib" / f"python{sys.version_info.major}.{sys.version_info.minor}" / "site-packages"

FILES = [
    SITE / "tools" / "mcp_tool.py",
]

OLD = (
    "            # MCP CallToolResult has .content (list of content blocks) and .isError\n"
    "            if result.isError:\n"
)
NEW = (
    "            # MCP CallToolResult has .content (list of content blocks) + error flag.\n"
    "            # mcp 2.0.0 renamed CallToolResult.isError -> is_error; read both.\n"
    "            _is_err = getattr(result, \"is_error\", None)\n"
    "            if _is_err is None:  # mcp 1.x used camelCase isError\n"
    "                _is_err = getattr(result, \"isError\", False)\n"
    "            if _is_err:\n"
)

MARKER = "_is_err = getattr(result"

patched = 0
for f in FILES:
    if not f.exists():
        print(f"SKIP: {f} not found")
        continue
    text = f.read_text()
    if MARKER in text:
        print(f"OK: {f.name} already patched")
        patched += 1
        continue
    if OLD in text:
        text = text.replace(OLD, NEW, 1)
        f.write_text(text)
        print(f"PATCHED: {f.name}")
        patched += 1
    else:
        # Already fixed by a newer hermes, or the line moved — do not fail the
        # build; the MCP tool will simply use whatever hermes ships.
        if "is_error" in text:
            print(f"OK: {f.name} already version-tolerant (variant)")
            patched += 1
        else:
            print(f"WARN: {f.name} — pattern not found; MCP tool calls may fail on mcp 2.0.0")

if patched:
    print(f"\n{patched}/{len(FILES)} file(s) OK.")
else:
    # Non-fatal: never block a bootstrap on this cosmetic client patch.
    print("\nWARNING: no MCP client file patched (see lines above).")
