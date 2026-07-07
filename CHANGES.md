# Changelog

All notable changes to the `local_hermesagent` plugin are documented here.
Format is loosely based on [Keep a Changelog](https://keepachangelog.com/).

---

## [0.3.10] — 2026-07-07

### Added

#### Messaging Gateway (Matrix/Element)
- **`hermes-gateway-control.sh`** — process manager for the Hermes gateway
  (start/stop/restart/status via nohup + PID file). The gateway connects
  Hermes to messaging platforms so you can chat with the AI from Element,
  Telegram, or other messaging apps.
- **Matrix config fields** on the settings page:
  - Homeserver URL (e.g. `https://matrix.org`)
  - User ID (bot account, e.g. `@hermes-bot:matrix.org`)
  - Access token (password-type field, from Element settings)
  - Allowed room IDs (comma-separated whitelist)
  - Device ID (optional, for E2EE persistence)
- **Gateway status panel** with Start/Stop/Restart buttons and last log
  line display. Buttons only appear when Matrix config is present.
- **`local_hermesagent_write_gateway_env()`** — writes Matrix config to
  `$HERMES_HOME/.env` (with 0600 permissions) before gateway start.
  Removes old `MATRIX_` lines on each write to avoid duplicates.
- **`local_hermesagent_is_gateway_running()`** / **`is_gateway_configured()`**
  helper functions in `lib.php`.

---

## [0.3.9] — 2026-07-07

### Fixed

#### Dashboard proxy
- **CSS font path rewriting** — the dashboard CSS references fonts via
  `url(/assets/...)` and `url(/fonts-terminal/...)` which bypassed the
  proxy and returned 404. Now rewrites `url()` references in CSS responses
  so fonts load through `dashboard.php/assets/...` and
  `dashboard.php/fonts-terminal/...`.
- **WebSocket retry spam suppressed** — the dashboard SPA uses WebSockets
  for real-time features (embedded chat, PTY terminal, event streaming)
  which PHP-FPM cannot proxy. Set `__HERMES_DASHBOARD_EMBEDDED_CHAT__=false`
  to disable the embedded chat widget, and injected a WebSocket guard
  script that silently rejects `ws://`/`wss://` connections targeting
  `dashboard.php` so the browser doesn't retry endlessly.

---

## [0.3.8] — 2026-07-07

### Fixed

#### ACP Bridge concurrency (critical)
- **Prompt serialization lock** — added `_prompt_lock` to `acp_bridge.py`
  preventing concurrent prompts from mixing responses in the shared inbox
  queue. `hermes acp` is a single stdio process that can only handle one
  prompt at a time; without the lock, two simultaneous chat requests would
  steal each other's streaming chunks.
- **New `/status` endpoint** — reports `prompt_in_progress`, `sessions`
  count, and `pid` without blocking on the prompt lock.
- **`/health` endpoint** — clarified as non-blocking (does not acquire the
  prompt lock).

#### Moodle freeze elimination
- **Removed DB writes from health check** — `lib.php` no longer calls
  `local_hermesagent_set_setting('bridge_status', ...)` on every health
  check. This was causing DB lock contention under concurrent access.
- **Reduced `sleep(3)` to `sleep(1)`** in `ensure_bridge_running()` — less
  blocking of PHP-FPM workers during lazy bridge startup.
- **Settings page health check** — reduced from 2s timeout to instant
  (< 1ms) since it no longer does DB writes.

#### Settings page overhaul
- **Start/Stop/Restart buttons** — now work correctly via
  `hermes-bridge-control.sh`. The old tmux-based code in
  `settings_action.php` was completely dead (Architecture 1) and never
  worked because tmux ran as root while PHP-FPM runs as www-data.
- **Health polling after start/restart** — the settings page now polls the
  `/health` endpoint for up to 20s (every 1s) instead of doing a single
  check after `sleep(3)`. The bridge takes ~10s to boot; the old code
  always reported "not responding" because it checked too early.
- **Dynamic button state** — when the bridge is running, shows "Restart"
  (yellow) + "Stop" (red). When stopped, shows "Start" (green). Always
  shows "Update & Bootstrap" (blue) and "Dashboard" (primary).

#### Terminal fixes
- **PATH now set correctly** — `exec.php` exports `HERMES_HOME` and
  prepends `venv/bin` to `PATH` in the generated shell script, so `hermes`
  is directly available without typing the full path. Previously, `$PATH`
  was interpolated by PHP as an empty string, wiping `/bin`, `/usr/bin`
  and causing `rm: not found` and similar errors.
- **Quick-action buttons** — added buttons for common non-interactive
  commands: `hermes --version`, `hermes config`, `hermes mcp list`,
  `hermes tools list`, `hermes acp --check`, `hermes status`.
- **Environment info** — terminal page now displays the `HERMES_HOME` path
  and notes that interactive TUI (`hermes chat`) is not supported; users
  should use `hermes chat -q` for single queries or the chat page.

#### Bootstrap script
- **Removed tmux checks** — the old `bootstrap.sh` checked for tmux
  sessions at the end, which always reported "NOT running" even when the
  bridge was fine. Now checks `curl /health` instead.
- **Fixed `/tmp/acp_bridge.py` copy** — was trying to copy from `/tmp/`
  which doesn't exist; now copies from the plugin directory.
- **Fixed `.bashrc` accumulation** — removed the `echo >> .bashrc` lines
  that duplicated PATH exports on every run.
- **Fixed busybox `cp` compatibility** — `cp -f` doesn't work on Alpine's
  busybox; replaced with `rm -f && cp`.
- **Removed `set -e`** — caused premature exit on non-fatal errors (e.g.,
  pip warnings).
- **Made MCP config creation idempotent** — uses `grep` check before
  adding moodle_db MCP server config.

#### Bridge control script
- **Fixed `status` command** — was checking nonexistent `PROXY_PID_FILE`
  and `ACP_PID_FILE`; now uses `BRIDGE_PID_FILE`.
- **Fixed bridge script path** — prefers the persistent copy at
  `$HERMES_HOME/classes/bridge/acp_bridge.py` (survives plugin re-syncs),
  falls back to the plugin directory.
- **Added `BRIDGE_PORT` env var support.**
- **Added health check in `status` output.**

### Added

#### Hermes Dashboard proxy
- **`dashboard.php`** — reverse proxy for the Hermes web dashboard
  (port 9119). Auto-starts the dashboard on first access, injects the
  session token for API authentication, and rewrites HTML asset paths so
  the SPA works behind the Moodle `/edb/` subpath.
- **Dashboard button** on the settings page (opens in new tab).
- The dashboard provides a full web UI for Hermes config, sessions, MCP
  servers, tools, model settings — accessible at
  `/local/hermesagent/dashboard.php/` without needing a separate port or
  direct network access.

### Removed

#### Dead code cleanup
- **`proxy_forward.py`** (root) — deleted. This was the old Architecture 2
  that bypassed `hermes acp` entirely, had a hardcoded API key, and
  conflicted over port 9118 with the ACP bridge.
- **`scripts/hermes_proxy_forward.py`** — deleted. Duplicate/divergent
  copy of `proxy_forward.py`.
- **All tmux code** removed from `settings_action.php` — the Start/Stop/
  Restart buttons now use `hermes-bridge-control.sh`.

### Migration notes

If upgrading from 0.3.7:
1. Run `make sync` to deploy the updated plugin files.
2. Run `make purge` to clear Moodle caches.
3. Copy the updated `acp_bridge.py` to the persistent location:
   ```bash
   kubectl exec -n edb phpfpm-0 -- cp \
     /var/www/html/public/local/hermesagent/classes/bridge/acp_bridge.py \
     /var/www/moodledata/.hermes/classes/bridge/acp_bridge.py
   ```
4. Click "Restart ACP" on the settings page to pick up the new bridge code.
5. Click "Update & Bootstrap" to run the fixed bootstrap script.

---

## [0.3.7] — 2026-06-27

- Bump version to 0.3.7 (2026062701)
- Require `lib.php` in settings and scope `$req_id` in api closure
- Refactor: remove Start/Stop, auto-start bridge on first chat
- Replace proxy with ACP bridge architecture

---

## [0.3.6] — 2026-06-23

- Replace tmux with nohup + PID-file approach (fixes www-data namespace mismatch)

---

## [0.3.2] — 2026-06-11

- Initial ACP bridge implementation
- 5 DB tables, 4 web services
- Chat interface with streaming, MathJax rendering, conversation management
