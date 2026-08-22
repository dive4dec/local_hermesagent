# Changelog

## [0.5.13] — 2026-08-22

### Added
- **One-click Hermes upgrade with automatic rollback (venv backups).**
  New `scripts/hermes-venv.sh` (snapshot / restore / list) plus a
  "Backups & Rollback" panel on the admin settings page:
  - **`Update & Bootstrap`** now first takes a venv snapshot (a rollback
    point named `venv-<version>_<ts>.tar.gz`, ~78 MB, ~60 s), then upgrades
    `hermes-agent`, then **restarts the bridge** so the new code actually
    loads — the whole thing is one backgrounded op you can watch complete.
  - **`Snapshot venv`** button for a manual rollback point at any time.
  - The panel lists every snapshot (version · size · created) with a
    **Restore** button; the newest is badged. The running
    snapshot/update/restore op shows live "in progress" status.
  - `restore` swaps the venv via a temp dir + `mv` (NFS-safe — no
    `rm -rf`-then-tar half-writes), waits on the bridge health endpoint for
    the old process to release its file handles before moving the tree,
    re-applies `patch_acp_timeout.py` + the bridge, re-verifies `hermes
    --version`, and restarts the bridge. Beyond the newest 3 snapshots are
    pruned automatically.
  - Verified end-to-end on edb: snapshot 0.18.2 → upgrade to latest
    (0.19.0) with bridge restart → **revert to 0.18.2** (old bridge killed,
    fresh one serving, zero `.nfs*` debris). Note: the index's latest
    hermes-agent is 0.19.0 — **0.20.x is not on PyPI** as of this date.

### Fixed
- **`moodle_db` MCP tool crash-looped after the venv rebuilt with `mcp` 2.0.0.**
  Bootstrap installs `mcp` unpinned, so the hermes-agent upgrade pulled the 2.0.0
  SDK — a full rewrite that broke the MCP stack on **both** ends:
  - *Server (ours):* `scripts/moodle_db_mcp.py` used the 1.x `Server` +
    `@app.list_tools()` / `@app.call_tool()` API, which 2.0.0 removed
    (`AttributeError: 'Server' object has no attribute 'list_tools'`), so the
    server was killed and restarted every few seconds.
  - *Client (hermes):* `tools/mcp_tool.py` read `result.isError`, but 2.0.0
    renamed it to `is_error`, so **every** tool call raised
    `AttributeError: 'CallToolResult' object has no attribute 'isError'` and the
    agent silently fell back to raw `php -r` terminal commands.

  Fix (tracks the newer SDK, matches how hermes 0.19.0 is built):
  - Ported `moodle_db_mcp.py` to the 2.0.0 API: `mcp.server.MCPServer` +
    per-tool `@app.tool(name=..., description=...)` decorators (type-annotated
    params become the JSON input schema) + `run_stdio_async()`. All four tools
    (`query`, `list_tables`, `describe_table`, `schema_hints`) preserved, same
    safety (SELECT-only, auto-LIMIT 100, sensitive-column redaction).
  - New `scripts/patch_mcp_iserror.py` (idempotent, non-fatal) makes hermes's
    client read the error flag version-tolerantly (`is_error` w/ `isError`
    fallback). `bootstrap.sh` runs it right after the existing ACP-timeout patch,
    so it is re-applied on **every** build — the venv patch is durable.

  Verified on edb: `moodle_db` stable (2.5 min uptime, 0 crash-loop growth);
  agent called `mcp__moodle_db__query` end-to-end and returned the real result
  (`SELECT COUNT(*) FROM mdl_course` → 4) with no `php -r` fallback.

- **Chat conversations permanently wedged after Stop/abort** ("Queued for the next
  turn." on every retry). Root cause: `hermes acp`'s `SessionState.is_running`
  flag was only cleared in the *normal* completion path. An abort that raced the
  in-flight turn (or an ACP `Internal error`) left the adapter's session
  `is_running=True` with a pending queued-prompt drain that would never fire —
  so every subsequent prompt on that conversation hit the adapter's
  `if state.is_running` gate and returned "Queued for the next turn." forever.

  Fix in `acp_bridge.py` (our file, not the vendored adapter):
  - New `ACPManager._forget_acp_session(conv_id, reason)` drops the
    moodle→acp session mapping + per-session state. The next prompt opens a
    brand-new ACP session; `[CONVERSATION HISTORY]` is replayed, so context is
    preserved.
  - `cancel_session()` now calls `_forget_acp_session` on abort (proactive).
  - `_run_prompt()` calls `_forget_acp_session` on both `CancelledError` and
    generic `Exception` (covers the `Internal error` race).
  - The SSE `event_generator` **self-heals** after the fact: if the prompt
    produced only a "Queued for the next turn." ack (no real message, no tool
    calls), the session is dropped. This recovers conversations that wedged
    *before* the fix landed (e.g. conversation 304) on the very next message.

  Verified on edb: abort a long prompt mid-generation → retry "hi" on the same
  conversation → gets a real answer (not "Queued"), bridge stays healthy,
  single `hermes acp` process, no duplicate sessions.
- **`Update & Bootstrap` returned 504 Gateway Time-out.** The backgrounded job
  (snapshot → bootstrap → restart, ~10 min) was launched with `sh … &` while
  still inheriting the PHP-FPM request's stdout/stderr pipe, so PHP's
  `exec()` blocked until the *entire* job finished — well past nginx's ~60 s
  timeout — and the browser showed 504 (even though the job was actually
  running fine in the background). Now the job is fully detached with
  `setsid sh -c '…' </dev/null >/dev/null 2>&1 &`: `exec()` returns in ~5 ms
  and the request completes immediately. Also fixed the liveness marker: the
  pidfile now holds the job's own PID (written by the job's first action
  `echo $$`), so the "already in progress" guard reliably blocks the
  duplicate clicks that the 504 was causing.
- **`sync.sh` stripped the executable bit from `.sh` scripts.** It ran a
  blanket `find … -exec chmod 0644` over every synced file, so control
  scripts (already `100755` in git) arrived as `644`. Anything guarding on
  `[ -x ]` silently skipped — including the hermes-venv restore's
  stop/start-bridge step, which left the *old* bridge running after a
  rollback. Now non-exec files are set `0644` and executable ones `0755`
  (tar carries the exec bit from source). Defense in depth: `hermes-venv.sh`
  guards on `[ -f "$BRIDGE_CONTROL" ]` and invokes it via `sh`, so it no
  longer depends on the exec bit at all.

## [0.5.12] — 2026-08-21

### Added
- **`admin/cli/cleanup_duplicate_toolcalls.php`** — one-off CLI to collapse the
  duplicated assistant rows left behind by the pre-v0.5.12 stream-persistence
  bug. Collapses any run of ≥2 consecutive assistant rows per conversation to
  the single most-complete row (max tool calls, then longest content, then
  latest). `--dry-run` previews; deletions are backed up to
  `$CFG->tempdir/hermesagent_msg_cleanup_<ts>.json`; idempotent. Verified on
  edb: 44 runs / 69 rows collapsed (538 → 469 rows), re-run reports 0.

### Fixed
- **Tool calls spanning multiple chat bubbles (Q5).** `api.php` was discarding
  the return value of `_hermesagent_persist_assistant()` on every
  `tool_call` event, so the first tool call INSERTed a new row and subsequent
  tool calls INSERTed further rows (the static `$message_id` was never
  assigned). On reload, each DB row became its own assistant bubble, so a
  single turn with N tool calls rendered as N+1 bubbles. Now the return value
  is captured on the `tool_call`, `abort`, and `done` paths so all events in
  one turn upsert the same row.
- **Tool-call expand arrow never flipped to ▾ (Q2).** The CSS block for
  `.hermes-tool-summary` targeted `.hermes-tool-details[open]`, but the JS
  builds `<details class="hermes-tool-call">` — so the rotation rule never
  matched. Corrected selectors appended to `chat.css` (the SCSS source is
  stale; see the warning added at the top of `chat.scss`).
- **Connection error after stopping the bridge (Q4).**
  `local_hermesagent_ensure_bridge_running()` only `sleep(1)`'d after
  launching the bridge before returning false, racing the 5–15s cold boot and
  surfacing "Connection error — check console" that self-healed on the next
  attempt. Now it polls `/health` for up to 30s before giving up.
- **Bootstrap completion detection was racy (Q3).** `settings.php` used
  `ps aux | grep bootstrap.sh` which misfires (the log is truncated at run
  start, and `grep` timing + a stale "complete" marker from the previous run
  could misreport). `settings_action.php` now launches bootstrap inside a
  backgrounded subshell that writes `$HERMES_HOME/bootstrap.pid` and removes
  it on exit; `settings.php` checks `posix_kill(pid, 0)` against that pidfile
  and only falls back to the old `ps | grep` when no pidfile is present.
- **Hyperlink contrast (Q6).** Moodle's default link blue was nearly
  invisible against the indigo user bubble (#4f46e5) and the light-grey
  inline-code background. Added explicit per-context link colors in
  `chat.css`: white/underline in user bubbles, indigo/underline in assistant
  bubbles, and a readable purple for `<a><code>` combos.

### Notes
- The CHANGELOG has a pre-existing gap: `[0.5.10]` and `[0.5.11]` entries were
  never written (the git tags exist but the sections are missing). 0.5.12 is
  the first properly-documented entry since 0.5.9. Backfilling 0.5.10/0.5.11
  is deferred — not part of this change.

## [0.5.9] — 2026-08-21

### Added
- **Per-session ACP concurrency (bridge rewrite).** `acp_bridge.py` now runs
  concurrent prompts across different ACP sessions with no global lock — only
  same-session prompts are serialized. Per-session `conn.cancel()` replaces the
  old "nuclear restart" on abort, so stopping one user's prompt no longer
  restarts the ACP for everyone. Each session gets its own lock, its own output
  queue, and its own permission routing.
- **Per-session identity (concurrency-safe).** `api.php` now passes the
  logged-in admin's Moodle session (`msession`: cookie + url + userid) into the
  bridge. The bridge writes a PER-SESSION identity file
  (`$HERMES_HOME/run/identity/<acp_session_id>.identity.json`, TTL-pruned by a
  janitor) so two concurrent admins each resolve their own identity. This is the
  mechanism the new `moodle_audit_quiz` plugin tool reads (see
  `local_hermes-synapse`).
- **acp integrity check in `bootstrap.sh`.** Bootstrap now verifies the `acp`
  package is importable/functional in the live venv before declaring the bridge
  healthy, surfacing a broken install early instead of at first prompt.

### Fixed
- **Cross-session identity race.** The previous single shared
  `$HERMES_HOME/.moodle_identity` (and the terminal-child `HERMES_SESSION_KEY`
  env) let concurrent admin sessions clobber each other. In-process tools now
  scope identity to the ACP session id; the child-process `moodle_quiz_audit`
  skill path that was unreliable under concurrency has been removed (superseded
  by the in-process `moodle_audit_quiz` tool).

## [0.5.8] — 2026-08-10

### Fixed
- **Math rendering: escape hell eliminated.** LLMs naturally output single-slash `\(...\)` and `\[...\]` for math, but CommonMark's backslash escaping was stripping the backslash before MathJax could see the delimiters. Fixed by registering `\(...\)` and `\[...\]` as official marked.js extension tokens (same approach as JupyterLab's markdown-it-texmath). No double slashes needed.
- **MathJax config: respects filter settings.** `configureMathJax()` now reads the existing `window.MathJax` config set by Moodle's `filter_mathjaxloader` and only fills in defaults for fields the admin hasn't configured. All fields (`inlineMath`, `displayMath`, `processEscapes`, `skipHtmlTags`) are conditional — admin config always takes priority.
- **Math delimiters: consistent with MathJax filter.** Removed `$...$` and `$$...$$` from the fallback defaults. Now uses MathJax's built-in defaults (`\(...\)` inline, `\[...\]` display), matching what the filter renders in regular Moodle content. No more confusion where dollar-sign math renders in chat but not in forums.
- **Removed ~200 lines of fragile custom math code.** The old `protectMathDelimiters`, `protectInlineDollars`, `convertLegacyDollars`, `protectCodeBlocks`, `restoreCodeBlocks`, `unescapeMathDelimiters`, `isMathContent`, and unicode placeholder system were all replaced by proper MathJax 4 configuration + marked.js extension API.
- **Permission fallback: `allow_session` falls back to `allow_once`.** When a tool requests `allow_session` or `allow_always` but only `allow_once` is offered, the bridge now falls back to `allow_once` instead of returning an error and hanging indefinitely.
- **Removed Sync Scripts button.** Deployment should go through standard plugin installation (`make sync` + Update & Bootstrap), not a separate quick-sync shortcut. The button made the code fragile and not portable to other environments.

### Changed
- System prompt updated to instruct LLM to use `\(...\)` for inline math and `\[...\]` for display math (single slash, natural LLM output).
- `renderMarkdown()` simplified to: marked.js parse → post-process links. MathJax handles math natively via `skipHtmlTags` config.

## [0.5.7] — 2026-07-29

### Added
- `msession.json` writer in `api.php` for Python skill HTTP access. Writes the user's Moodle session cookie to `$HERMES_HOME/run/msession.json` so standalone skill scripts can authenticate to Moodle.

## [0.5.6] — 2026-07-28

### Fixed
- SSE keepalive: send initial `: keepalive` before bridge responds to prevent proxy timeout.

## [0.5.5] — 2026-07-16

### Added
- Send/Stop toggle button — single button with triangle/square icons.
- Sync Scripts action for quick MCP/bridge updates.

## [0.5.4] — 2026-07-15

### Added
- File download buttons for code-block URLs.
- Auto-linking of bare URLs in assistant messages.

## [0.5.3] — 2026-07-14

### Fixed
- Terminal tool preview truncation.
- Image path rewriting for browser display.

## [0.5.2] — 2026-07-08

### Added
- Conversation history sidebar.
- Conversation filtering.

## [0.5.1] — 2026-06-27

### Fixed
- SSE streaming stability under nginx ingress.
- Proxy buffering disabled via ingress annotations.

## [0.5.0] — 2026-06-15

### Added
- Initial release: chat interface, bridge integration, MCP server, dashboard.
