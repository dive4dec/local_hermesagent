# Changelog

All notable changes to the `local_hermesagent` plugin are documented here.
Format is loosely based on [Keep a Changelog](https://keepachangelog.com/).

---

## [0.5.2] — 2026-07-18

### Fixed

#### Chat page non-functional after zip install (stale JS cache)
- When the plugin is installed via Moodle's zip upload, the AMD module
  `local_hermesagent/chat` was not included in Moodle's requirejs bundle.
  The browser got "No define call for local_hermesagent/chat" and the send
  button did nothing.
- Root cause: `db/install.php` did not purge caches. Moodle's post-install
  redirect to `admin/index.php?cache=0` purges caches, but the requirejs
  cache can be rebuilt from stale state if web requests race with the
  install process.
- Fix: `db/install.php` now calls `purge_all_caches()` at the end of
  installation. `db/upgrade.php` also calls it on every version bump via
  a new savepoint (2026071604).

#### Bootstrap never runs as root
- `bootstrap.sh` now detects if it is running as root (e.g. via
  `kubectl exec`) and re-executes itself as `www-data` using `su`.
- All files created during bootstrap (venv, config.yaml, .env, plugins,
  skills) are now www-data-owned from creation, preventing the "Could not
  save setting" error on the settings page.

#### ACP Bridge fails to start (missing agent-client-protocol)
- `bootstrap.sh` installed `hermes-agent` without the `[acp]` extra.
  The ACP bridge spawns `hermes acp` which requires
  `agent-client-protocol==0.9.0`. Without it, the subprocess exits
  immediately with "ACP dependencies not installed".
- Fix: bootstrap now installs `hermes-agent[acp]` (both pinned and latest
  version branches).

---

## [0.5.1] — 2026-07-18

### Fixed

#### Bootstrap never started on first install
- `settings_action.php` redirected bootstrap output to
  `$hermes_home/bootstrap_update.log`, but `.hermes/` doesn't exist on first
  install. The shell silently failed to create the log file in a nonexistent
  directory, so bootstrap never started and the settings page showed
  "Never run".
- Fix: `mkdir($hermes_home, 0777, true)` before the `exec()`.

---

## [0.5.0] — 2026-07-16

### Added

#### Bootstrap status on settings page
- Settings page now shows bootstrap status: **Running**, **Completed**, or
  **Incomplete**, with timestamp and last 3 log lines.
- Detects running state via `ps aux | grep bootstrap.sh`.
- Detects completion by checking for `=== Bootstrap complete ===` marker in
  `bootstrap_update.log`.

#### Background bootstrap execution
- `settings_action.php` now runs `bootstrap.sh` in the background (`&`) with
  output redirected to `bootstrap_update.log`.
- Returns immediately to avoid nginx/ingress HTTP timeouts (bootstrap takes
  minutes for git clone, pip install, etc.).

#### Bootstrap installs ALL plugins from synapse repo
- `bootstrap.sh` now discovers all plugins in `local_hermes-synapse` by
  cloning the repo once and scanning `plugins/*/` for `plugin.yaml`.
- Installs each plugin individually via `hermes plugins install` with the
  correct subdir path (e.g.,
  `dive4dec/local_hermes-synapse/plugins/moodle-bridge`).
- Falls back to tarball copy per-plugin if git install fails.
- Reads `pip_dependencies` from each `plugin.yaml` and installs them.
- New plugins added to the repo are automatically installed on next bootstrap
  — no script changes needed.

#### Git-first plugin install with tarball fallback
- `bootstrap.sh` uses `hermes plugins install` (git clone) as primary install
  method, with tarball download as fallback when git is unavailable.
- Requires git + openssh-client in the phpfpm Docker image (added in e-quiz
  v0.4.0).

### Fixed

#### Bootstrap status always showed "Incomplete"
- `file_get_contents()` with `offset=-1` returns only 1 byte (`"\n"`) — PHP
  does not support negative offsets. The code never found the
  `=== Bootstrap complete ===` marker and always fell through to the
  `$errored` branch.
- Fixed by reading the whole file (typically <5KB).

#### Ownership after plugin/skill install
- `hermes plugins install` and `hermes skills install` run as root when
  bootstrap is triggered via `kubectl exec`, creating root-owned files.
- Added `chown -R www-data:www-data` safety net after all install steps.

---

## [0.4.4] — 2026-07-16

### Fixed

#### SSE buffer for large permission events (critical)
- **Permission requests for large file edits were silently dropped** — curl's
  `WRITEFUNCTION` delivers data in arbitrary chunks. When the ACP bridge sent
  a large SSE event (e.g. a permission request containing the full
  `config.yaml` diff — 16KB+), curl split it across two calls (16378 + 8480
  bytes). The old code called `json_decode()` on each chunk independently,
  which returned `null` on the partial JSON, and `if (!$json) continue;`
  silently dropped the permission event. The browser never saw the
  Approve/Reject buttons, and the ACP subprocess hung sending keepalives
  until the 600-second stream timeout.
- **Fix: static `$sse_buffer`** accumulates incoming data across curl calls.
  The parser only processes complete SSE events (delimited by `\n\n`),
  ensuring `json_decode()` always receives the full JSON payload. Added
  warning log when `json_decode` fails on a complete event.
- This was the root cause of the recurring "stuck chat" issue whenever the
  agent tried to patch a large file (config.yaml, chat.js, etc.).

---

## [0.4.3] — 2026-07-15

### Added

#### Text-based approval commands
- **`!approve`, `!approve session`, `!approve always`, `!reject`** — users can
  type approval commands directly in the chat input instead of clicking
  buttons. The Send button is now enabled during permission waits so these
  commands can be sent while the SSE stream is open. Permission responses go
  via a separate POST endpoint (`/session/permission`), so typing a normal
  message while a permission is pending is safe.
- Three approval levels supported: `allow_once` (default), `allow_session`
  (auto-approve for the rest of the session), `allow_always` (auto-approve
  all future calls to the same tool).

#### Tool call forwarding to chat UI
- **Tool call events are now forwarded from ACP → bridge → api.php → browser**
  via a new `tool_call` SSE event type. Previously, tool calls were invisible
  to the user — only the final text response appeared.
- Each tool call renders as a collapsible `<details>` block showing the tool
  name, status (executing/completed), and result text.
- **Download links for `moodle_upload_file`** — when the agent uploads a file
  to Moodle, a blue **⬇ Download** button appears inside the tool call card,
  linking to `mod/resource/view.php?id={cmid}`.

#### Auto-linking of bare URLs in agent responses
- **Code-block URLs → download buttons** — when the agent puts a download URL
  inside a markdown code block (```` ``` ````), it renders as a blue
  **⬇ Download {filename}** button instead of plain text.
- **Bare URLs anywhere in the response** are auto-linked as clickable
  hyperlinks.

### Fixed

#### SSE keepalive during all idle periods
- **Keepalive now fires every 15 seconds unconditionally** during all idle
  periods (permission waits, LLM API calls, tool execution), not just during
  permission waits. Previously, a 65-second LLM API call with no SSE output
  would trigger the K8s ingress 60-second `proxy_read_timeout`, causing a
  "Connection error" in the browser. The 15-second keepalive (`: keepalive\n\n`,
  ~12 bytes) keeps the connection alive well within the 60s timeout.

#### Bridge deadline removed
- The bridge's internal deadline competed with the ACP adapter's own
  `ACP_APPROVAL_TIMEOUT` (600s), causing approvals to time out faster than
  expected. The bridge deadline is removed entirely; the ACP adapter is the
  sole timeout authority.

#### Terminal text selection
- **Clicking inside the terminal no longer steals text selection** — the
  click handler now checks `event.target.closest('input, textarea, button')`
  before calling `focus()`, so selecting and copying output text works
  correctly. Fixed in both `amd/src/terminal.js` (AMD module) and
  `styles/terminal.js` (inline terminal page).

#### Duplicate "Thinking..." blocks
- **Only one "Thinking..." block per assistant response** — previously, each
  tool call incremented `msgCounter`, which was used to derive the reasoning
  element ID. When a tool call arrived mid-stream, the next reasoning event
  couldn't find the existing element and created a new one. With N tool calls,
  you'd get N+1 "Thinking..." blocks. The reasoning ID is now captured once
  at stream start and stays stable for the entire stream.

#### Conversation duplication on refresh
- **PRG (Post/Redirect/Get) pattern for new conversations** — after creating
  a conversation via `?action=new`, the server now redirects to
  `?conversationid=X`. Previously, the URL stayed as `?action=new`, so an F5
  refresh would re-trigger the INSERT and create a duplicate conversation.

#### Tool calls and permissions inside same message bubble
- **Tool calls, permissions, and reasoning now render inside the same
  assistant message bubble** instead of as separate full messages. Previously,
  each tool call created a separate `.hermes-message` with its own avatar and
  bubble, causing excessive screen space. Permission prompts were also
  separate messages, often hidden in collapsed content. Now everything shares
  one avatar and one bubble:
  - Reasoning ("Thinking...") — collapsible, above the response text
  - Tool calls — collapsible `<details>` blocks, with download links
  - Permission prompts — visible (not collapsed), with Approve/Reject buttons
  - Final text response — at the bottom of the bubble

#### `.gitignore` fix
- Removed overly broad `build/` pattern that was ignoring Moodle's
  `amd/build/` directory. Added `amd/build/.gitkeep` to preserve the
  directory in git.

#### `sync.sh` NFS safety
- Replaced `rm -rf` + tar with tar `--overwrite` overlay. The `rm -rf` on
  NFS-backed PVC left `.nfs` stale handles that prevented deletion, causing
  sync to fail and partially wipe the plugin.

---

## [0.4.2] — 2026-07-14

### Fixed

#### Approval error messaging
- **Permission buttons now show error when approval fails** — previously,
  if the user clicked Approve/Reject after the agent had timed out or the
  bridge had restarted, the buttons either silently re-enabled (no feedback)
  or showed a generic error. Three failure modes now display a clear message:
  1. **Expired permission** (agent timed out or bridge restarted): the bridge
     tracks pending permissions in `_pending_permissions`; when the stream
     ends (done/timeout/error/abort/process exit), the set is cleared. The
     `/session/permission` endpoint returns: "This permission request has
     expired — the agent may have timed out or moved on."
  2. **Bridge unreachable** (process down): `api.php` detects
     `curl_exec() === false` and returns: "Bridge unreachable: <curl error>"
  3. **Server unreachable** (network error): `chat.js` `.fail()` handler
     shows: "⚠ Failed to reach server: <status>"
- **`api.php`** — `api_permission_response()` now captures `curl_error()`,
  decodes the bridge JSON response, and checks `status === "error"` to pass
  the bridge's error message through to the browser (was returning generic
  "Bridge error").
- **`acp_bridge.py`** — `_pending_permissions` set tracks active permission
  IDs; cleared on all stream exit paths (done, timeout, error, abort, process
  exit); `discard()` on successful response. Abort path now clears
  permissions (was missing).
- **`chat.js`** — `.done()` handler checks `resp.status === "error"` and
  displays the message; `.fail()` handler shows error text instead of
  silently re-enabling buttons.

#### Bootstrap cross-platform support
- **`bootstrap.sh` now works on macOS and WSL2** (in addition to Alpine musl
  and glibc Linux). Platform detection via `uname -s` / `uname -m`:
  - macOS Intel: darwin x86_64 builds (Python, uv, ripgrep, Node.js)
  - macOS Apple Silicon: darwin aarch64 builds
  - WSL2: detected as Linux glibc — uses gnu builds
  - macOS Gatekeeper fix: `xattr -dr com.apple.quarantine` on downloaded
    binaries (same as hermes-usb-portable)
- **aarch64 fixes**: ripgrep has no musl aarch64 build — uses gnu build
  (statically linked, works on musl). Node.js has no musl aarch64 build —
  falls back to gnu build.
- **`CONFIG_FILE` env var fix** — Python config writer had env vars after
  the command (passed as argv, not environment). Fixed with `export` before
  the Python call.

---

## [0.4.1] — 2026-07-08

### Fixed

#### Abort / Stop streaming (critical)
- **Abort now properly stops streaming and prevents stale messages** —
  previously, after clicking Stop, the `hermes acp` process kept running
  and writing to the shared inbox queue. The next prompt would pick up
  stale messages from the aborted session, producing garbled output.
  Three fixes applied to `acp_bridge.py`:
  1. `abort_event` passed into `send_prompt_streaming()` — checked during
     the 0.5s inbox wait gap to break early instead of hanging.
  2. `session_id` filtering — `session/update` notifications with the
     wrong `session_id` are discarded, preventing cross-session pollution.
  3. Inbox drain (3 rounds × 0.3s pauses) after abort to clear residual
     messages.
- Tested: `prompt_in_progress` releases within 2s, SSE sends
  `event: aborted`, new prompt after abort produces clean output.

#### Settings page Stop button (critical)
- **Stop button now actually stops the bridge** — the bridge was running
  as **root** (started via `kubectl exec`), but PHP-FPM runs as
  `www-data`. The `kill` command silently failed because `www-data`
  cannot kill root-owned processes. Fixed by killing root processes and
  restarting as `www-data` via the control script.
- **`hermes-bridge-control.sh`** now warns when `kill` fails:
  "WARNING: cannot kill pid X" instead of silent failure.

#### Settings page status freshness
- **"Stopped — will auto-start on first chat"** → just **"Stopped"**.
  The bridge does not auto-start; this message was misleading.
- **Status now refreshes after stop/start/restart** — added
  cache-busting `?t=time()` parameter to the redirect URL so the page
  re-renders with fresh bridge health.
- **Post-stop verification** — after calling stop, checks bridge health
  and warns if still running (e.g. "ACP Bridge may not have stopped —
  check that it is running as www-data, not root").

#### Conversation timestamp
- **Timestamp now reflects the latest message** —
  `save_assistant_response()` only updated the message's `timemodified`
  but not the conversation's. The sidebar showed the time of the user's
  last message, not the assistant's reply. Now both `send_message` and
  `save_assistant_response` update `conv->timemodified`.

### Changed

#### Conversation history management
- **ACP session ID persisted to Moodle DB** — the `acp_session_id`
  column existed in `local_hermesagent_conversations` but was never
  used. Now saved from the bridge's SSE response on every prompt.
- **History sent on new sessions after bridge restart** — previously,
  the conversation→ACP session mapping was in-memory only
  (`acp._sessions = {}`). When the bridge restarted, all mappings were
  lost and each conversation got a new empty ACP session — the agent
  forgot all prior context. Now, when creating a new session, the bridge
  includes conversation history in the first prompt so the agent has
  context.
- **History limited to 40 most recent messages** (~20 user+assistant
  turns) to prevent context window overflow on long conversations.
  Character-based safety net in the bridge: `MAX_HISTORY_CHARS=50000`
  (~12K tokens) with truncation note.
- **`session/load` not used** — hermes acp's `session/load` often fails
  ("Failed to recreate agent" for old sessions), so we always create
  fresh sessions and include history in the prompt instead.
- **Subsequent prompts only send the latest message** — relies on the
  ACP session's internal memory with automatic compaction
  (`archive_and_compact` at 50% of context window). No duplication.
- **`api.php` sends `messages` array and `acp_session_id`** to the
  bridge on every request; the bridge decides whether to include history
  based on `is_new_session`.

#### Chat sidebar
- **Removed bridge status indicator from chat sidebar footer** — it was
  stale (only checked at page load) and polling would be inefficient.
  The settings page is the authoritative place for bridge status.

---

## [0.4.0] — 2026-07-08

### Added

#### Conversation management
- **Bulk delete / duplicate** — long-press (mobile) or right-click (desktop)
  on a conversation in the sidebar to enter selection mode. Checkboxes
  appear on each conversation; tap to toggle. Bulk Delete (with
  confirmation) and Bulk Duplicate buttons appear in a toolbar. ✕ exits
  selection mode.
- **Per-conversation duplicate button** (⧉) — visible on hover, creates a
  full copy of the conversation with all messages.
- New web services: `bulk_delete_conversations`, `duplicate_conversation`.

#### Quote / reply to messages
- **↩ Quote button** on every message bubble — click to show a visual
  quote preview bar above the input with "Quoting Hermes:" or "Quoting
  me:" and the first 200 characters of the quoted message.
- When sent, the quote is prepended as a markdown blockquote
  (`> **Hermes said:**\n> ...`) so Hermes sees the context.
- ✕ on the quote bar cancels. Quote auto-clears after sending.
- **Blockquote styling** — indigo left border (3px), light gray
  background, works in both user and assistant bubbles.

#### Edit / delete individual messages
- **✎ Edit button** — click to inline-edit: message content turns into a
  textarea with Save/Cancel buttons. Markdown re-renders after saving.
- **🗑 Delete button** — deletes with confirmation, message fades out.
- New web services: `edit_message`, `delete_message`.
- Message IDs are now passed from `send_message` → `addUserMessage` so
  edit/delete buttons have correct DB IDs. The `send_message` web service
  is called first (returns `messageid`), then the SSE stream opens.

#### Image paste support
- **Ctrl+V an image** into the input textarea — automatically uploads and
  inserts a markdown image link.
- Images saved as flat files to `/var/www/moodledata/.hermes/images/`
  (owned by `www-data`, 0644 permissions).
- **`image.php`** — serves images for browser display (requires login,
  no pluginfile complexity). `renderMarkdown` rewrites local file paths
  to `image.php?f=filename` URLs for display.
- The markdown sent to Hermes uses the **local filesystem path** so
  Hermes can read the file directly via `vision_analyze` — no
  authentication needed (previous pluginfile.php approach required
  session cookies Hermes couldn't provide).
- New web service: `upload_image`.
- 10MB size limit per image.

#### Collapsible + resizable sidebar
- **◀ Collapse button** in sidebar header — hides sidebar completely.
- **▶ Expand button** appears when collapsed — click to restore.
- **Drag handle** (6px gray bar) between sidebar and chat area — drag to
  resize from 160px to 500px. Turns indigo on hover/drag.
- Sidebar width **persists** across page loads via `localStorage`.
- **Touch support** for resizing on tablets.
- Mobile (< 600px): resize/collapse buttons hidden, uses header-tap
  to expand/collapse (sidebar shows as 42px tappable header bar).

#### Per-message copy & text selection
- **📋 Copy button** on every message — copies raw markdown/text to
  clipboard (navigator.clipboard + execCommand fallback).
- **Double-click** message content to select all text in that bubble.
- Normal click-drag text selection for partial copying.

#### Vision support
- **`auxiliary.vision` configured** in `config.yaml` to use the custom
  provider (`custom:Socratic.cs.cityu.edu.hk` / `Socrates` model).
  Previously set to `auto` which couldn't detect the custom provider,
  causing "No LLM provider configured for task=vision" errors.
- `bootstrap.sh` updated to set this on future deploys.

### Fixed

- **`local_hermesagent_get_bridge_port()` undefined** — `lib.php` was
  accidentally overwritten with only the `pluginfile()` function,
  deleting all 11 helper functions. Restored all original functions and
  appended `pluginfile()` at the end.
- **`services.php` double backslashes** — 6 service entries had
  `\\\\` (double-escaped) instead of `\\` in the classname field. This
  prevented Moodle from finding the class.
- **`tool_response()` missing `global $USER`** — would fail with
  "undefined variable" when checking permissions.
- **Markdown not rendering after sending** — user messages were rendered
  with `escapeHtml()` (plain text), so blockquote `>` syntax showed as
  raw characters. Now both user and assistant messages go through
  `renderMarkdown()`.
- **Closure bug in `renderMessages`** — async `renderMarkdown().then()`
  callbacks captured loop variables by reference, so all messages would
  render into the last element. Fixed with IIFE wrapper.
- **Resize handle invisible** — was inside the sidebar div (child, not
  sibling); the sidebar's flex/overflow layout swallowed it. Moved
  outside the sidebar closing tag. Also gave it a visible background.
- **Mobile sidebar not tappable** — `max-height: 0` hid the header too.
  Changed to `max-height: 42px` so the header is always visible.
- **Removed unused `external_optional_param` import** from `chat_api.php`.
- **Removed unused `core/str` import** from `chat.js` AMD define().
- **Removed dead `bulkMode` variable** — was set but never read; the
  `.hermes-bulk-mode` CSS class on items is the actual state indicator.
- **Removed dead tool confirmation modal** HTML from `chat.php` (was
  replaced by inline permission buttons in 0.3.11).

### Changed

- `sendMessage()` now calls `send_message` web service first to get the
  message ID, then opens the SSE stream. Previously `streamResponse()`
  called `send_message` internally.
- `saveAssistantResponse()` now returns the ajax promise so the `done`
  event handler can use the returned `messageid` for action buttons.
- `buildMessageActions()` helper centralizes action button HTML
  generation (reply, copy, edit, delete) for all message render paths.
- Sidebar `min-width` changed from 260px to 160px for more flexibility.

---

## [0.3.11] — 2026-07-08

### Added

#### Tool approval (inline, non-blocking)
- **Inline approve/reject buttons** in the chat stream — when Hermes wants
  to execute a tool (write_file, terminal, etc.), a yellow-tinted permission
  bubble appears inline in the chat with the tool name, a diff/description,
  and Approve / Reject buttons. Non-blocking: you can scroll, read context,
  and approve later. Multiple pending requests each get their own buttons.
  After clicking, buttons are replaced with "✓ Approved" or "✗ Rejected".
- **Bridge forwards `session/request_permission`** as an SSE `permission`
  event to the browser (was previously auto-approved silently by the bridge).
- **`POST /session/permission`** endpoint on the bridge — receives the
  browser's approval/rejection and sends the correct ACP
  `RequestPermissionResponse` back to the `hermes acp` process.
- **`permission_response` action** in `api.php` — bridges the browser's
  POST to the bridge's `/session/permission` endpoint.
- **`scripts/patch_acp_timeout.py`** — patches the ACP adapter
  (`acp_adapter/permissions.py` and `edit_approval.py`) to read
  `ACP_APPROVAL_TIMEOUT` env var instead of the hardcoded 60s default.
  Called automatically by `bootstrap.sh` after every `hermes update`.

#### Mobile / foldable responsive chat
- **Collapsible sidebar** — on phones (< 600px), the conversation list
  collapses to a tappable header with a ▼ indicator. Tap to expand/collapse.
- **Foldable layout** (601–1024px) — 220px sidebar, 85% message width,
  compact conversation items. No more cramped full-width sidebar.
- **iOS zoom prevention** — `font-size: 16px` on the input textarea.
- **Compact bubbles** — smaller avatars, tighter padding on mobile.

### Fixed

- **Tool approval "Unknown tool"** — the bridge looked for `params.call.*`
  but the ACP protocol sends `params.toolCall.*` with `rawInput.tool` and
  `rawInput.arguments`. Now correctly extracts tool name (e.g.
  `write_file: /tmp/hello.py`, `terminal: cat > /tmp/hello.py`).

- **Tool approval "Connection error"** — three root causes, all fixed:
  1. **ACP 60s internal timeout** — `acp_adapter/permissions.py` and
     `edit_approval.py` hardcode a 60s `future.result(timeout=60)` that is
     separate from `config.yaml`'s `approvals.timeout`. Patched to read
     `ACP_APPROVAL_TIMEOUT` env var (default 600s). The
     `hermes-bridge-control.sh` script now exports
     `ACP_APPROVAL_TIMEOUT=600`.
  2. **Wrong ACP response format** — the bridge sent
     `{"result": {"status": "accepted"}}` but the ACP protocol's
     `RequestPermissionResponse` expects
     `{"result": {"outcome": {"outcome": "selected", "optionId": "allow_once"}}}`
     for approve, or `{"result": {"outcome": {"outcome": "cancelled"}}}`
     for reject. The old format caused a Pydantic validation error and the
     ACP treated it as "denied" — even when the approval arrived in 0.39s.
  3. **`PARAM_ALPHA` stripped underscores** — Moodle's `PARAM_ALPHA` only
     allows a–z and silently strips everything else. `permission_response`
     was converted to `permissionresponse`, which didn't match any `switch`
     case — so `api_permission_response()` was never called and the
     browser's approval was silently discarded. Changed to
     `PARAM_ALPHANUMEXT` (allows a–z, 0–9, `_`, `-`).

- **Chat stuck loading / send button not working** — Moodle's AMD loader
  only serves files from `amd/build/`, not `amd/src/`. The stale
  `amd/build/chat.js` (from June 16, old code with `handleToolResponse`)
  was being served instead of the updated source. Created
  `amd/build/chat.js` and `amd/build/chat.min.js` as copies of the source.

- **Bridge deadlocks after failed approval** — when the SSE stream drops
  or the ACP times out waiting for a permission response, the prompt lock
  is never released, causing all subsequent requests to fail with
  `TimeoutError: Timed out waiting for ACP response to session/new`.
  Restarting the bridge clears the lock.

- **`config.yaml` `approvals.timeout: 600`** — set in both the live config
  and `bootstrap.sh` so the CLI callbacks path also uses 600s (was 60s).

### Changed

- **`api.php` cURL timeout** increased from 300s to 600s to allow time for
  tool approval within a single SSE stream.
- **`acp_bridge.py` ACP timeout** increased from 300s to 600s.
- **`_handle_notification`** for `session/request_permission` now returns
  `False` (don't consume) instead of `True`, letting the streaming loop
  handle the permission event and yield it to the browser.

---

## [0.3.10] — 2026-07-08

### Added

#### Messaging Gateway (multi-platform)
- **`hermes-gateway-control.sh`** — process manager for the Hermes gateway
  (start/stop/restart/status via nohup + PID file). Connects Hermes to
  messaging platforms so you can chat with the AI from Element, Telegram,
  Discord, Signal, and 15+ other apps.
- **Gateway `.env` direct editor** on the settings page — paste any
  platform's environment variables (one per line). Supports all Hermes
  gateway platforms: Matrix, Telegram, Discord, Signal, Mattermost, IRC,
  Email, Line, Feishu, DingTalk, Google Chat, QQ, ntfy, BlueBubbles, etc.
- **Gateway status panel** with Start/Stop/Restart buttons and last log
  line display. Detects any platform config by checking for known env var
  prefixes in the `.env` file.

#### File-based config editors
- **`classes/admin/setting_configfile.php`** — custom `admin_setting` class
  that reads/writes a file directly (not the Moodle DB). The file is the
  single source of truth — no stale DB copy. Edits via Moodle, the
  dashboard, or the CLI all modify the same file. Used by both config.yaml
  and gateway `.env`.
- **Hermes config.yaml direct editor** on the settings page — edit the
  full Hermes configuration (model, provider, agent settings, toolsets,
  etc.) directly. Changes are written to `$HERMES_HOME/config.yaml` on save.

#### Other
- **Configurable dashboard port** — new `dashboard_port` setting (default
  9119), read by `dashboard.php` when starting the dashboard.
- **`local_hermesagent_is_gateway_running()`** /
  **`is_gateway_configured()`** helper functions in `lib.php`.
- **Documentation links** — 📖 Configuration docs under config.yaml,
  📖 Gateway docs under `.env`.

### Changed

#### Settings page redesign
- **Four clear sections** with proper headings: Tools, ACP Bridge, Hermes
  Configuration, Messaging Gateway.
- **"Tools" section** (renamed from "Quick Links") — each tool now has a
  description: Chat, Terminal, Dashboard, Update & Bootstrap, Docs. Laid
  out as a table with button + description.
- **Shorter button labels** — "Chat" (was "Open Hermes Chat"), "Terminal"
  (was "Open Terminal"), "Restart"/"Stop"/"Start" (dropped "ACP"/"Gateway"
  suffixes since context is clear from section heading).
- **No duplicate buttons** — Dashboard only in Tools (removed from gateway
  section), Update & Bootstrap moved to Tools (removed from bridge section).
- **Port fields use static defaults** — `'9118'` and `'9119'` passed
  directly to constructors instead of reading stale DB values.

### Fixed

- **`admin_setting_configpassword` not found** — Moodle doesn't have this
  class; replaced with `admin_setting_configpasswordunmask` (then later
  removed entirely when Matrix-specific fields were replaced with the
  generic `.env` textarea).
- **Stale `bridge_port = 0`** — empty field was cast to `0` by `PARAM_INT`
  and stored in DB, shown instead of the default `9118`. Fixed by using
  static defaults and clearing the DB value.

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
