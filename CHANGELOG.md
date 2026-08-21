# Changelog

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
