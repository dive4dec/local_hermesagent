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
