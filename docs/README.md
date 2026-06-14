# local_hermesagent — Developer Documentation

**Version:** 0.3.0 (2026061304) | **Moodle:** 5.0+ | **License:** GPL v3+

---

## What Is This?

`local_hermesagent` is a Moodle plugin that connects the Moodle LMS to the [Hermes AI Agent framework](https://github.com/nousresearch/hermes). It provides:

- An in-Moodle chat interface with real-time streaming responses
- Math equation rendering via MathJax
- Conversation management (create, rename, delete, search)
- Integration with Moodle's auth, privacy, and user management systems
- A terminal interface for AI-assisted command execution

It uses the **ACP (Agent Communication Protocol)** bridge to communicate with a local Hermes instance, providing budget-controlled, secure AI access.

## Why Build This?

- Educational institutions want AI within their controlled LMS environment
- Existing Moodle AI plugins are either too simple (single-call completions) or require external hosting
- This plugin leverages the full Hermes agent ecosystem (tools, plugins, MCP servers) within Moodle
- Provides institutional cost controls via the Hermes budget system

## System Requirements

- Moodle 5.x (tested on 5.0+)
- Python 3.12+ with Hermes CLI installed
- PHP 8.1+ with cURL, JSON extensions
- MathJax v4 (CDN: cdn.jsdelivr.net)
- For the terminal feature: access to a Hermes-managed environment

## Architecture

```
Moodle Browser ←→ chat.php ←→ api.php ←→ ACP Bridge (localhost:9118) ←→ Hermes Agent ←→ LLM Provider
                     │              │
                conversation   web service
                   table       calls
```

### Detailed Data Flow

```
Browser (chat.js AMD module)
  │
  │  AJAX: local_hermesagent_send_message (Moodle web service)
  │  SSE:  api.php?action=stream (EventSource)
  v
api.php (Moodle PHP)
  │
  │  cURL POST → 127.0.0.1:<bridge_port>/v1/chat/completions
  v
acp_bridge.py (FastAPI + uvicorn, Python 3.12)
  │
  │  stdio JSON-RPC
  v
hermes acp subprocess (one per conversation)
  │
  │  HTTP → LLM provider (OpenAI, Anthropic, etc.)
  v
LLM API
```

### Sending a Message (Step by Step)

1. User types message in `chat.php`, clicks Send.
2. `chat.js` calls Moodle web service `local_hermesagent_send_message` via `core/ajax`.
3. PHP saves the user message to `local_hermesagent_messages`.
4. `chat.js` opens an `EventSource` to `api.php?action=stream`.
5. `api.php` fetches full conversation history, calls ACP Bridge via cURL.
6. `acp_bridge.py` forwards to the `hermes acp` subprocess via stdio JSON-RPC.
7. LLM tokens stream back through ACP → Bridge → api.php → SSE → `chat.js`.
8. `chat.js` renders markdown + math in real-time as deltas arrive.
9. On `done`, the full raw markdown is saved to DB via `save_assistant_response` web service.

### Key Components

| File | Role |
|------|------|
| `chat.php` | Main chat UI (sidebar + message area + input + tool modal) |
| `api.php` | SSE streaming proxy + JSON API endpoints |
| `amd/src/chat.js` | Client-side chat logic, markdown/math rendering, SSE handling |
| `amd/src/marked.js` | AMD wrapper for marked.js (handles Moodle's define.amd conflict) |
| `classes/bridge/acp_bridge.py` | FastAPI HTTP bridge to `hermes acp` subprocesses |
| `classes/external/chat_api.php` | Moodle external web service API (7 methods) |
| `local_hermesagent_settings.php` | Bridge start/stop handler |
| `terminal.php` | In-browser Hermes CLI terminal |
| `exec.php` | Command execution backend for terminal |
| `classes/tool/*.py` | Hermes tools (moodle_admin, moodle_schema, moodle_sql, skill_backup) |
| `scripts/bootstrap.sh` | Standalone Python + hermes-agent installer |

---

## Plugin Structure Tree

```
local/hermesagent/
├── chat.php                          # Chat interface page
├── terminal.php                      # CLI terminal page
├── api.php                           # API proxy (SSE + JSON)
├── exec.php                          # Terminal command executor
├── local_hermesagent_settings.php    # Bridge start/stop handler
├── settings.php                      # Admin settings page
├── lib.php                           # Core helper functions
├── version.php                       # Plugin version + requirements
├── README.txt                        # Moodle Plugin Directory README
├── lang/
│   └── en/
│       └── local_hermesagent.php     # Language strings
├── db/
│   ├── tables.xml                    # XMLDB schema (5 tables)
│   ├── access.php                    # Capability definitions (4)
│   ├── services.php                  # External web services (7)
│   ├── install.php                   # Default settings on install
│   └── upgrade.php                   # Database upgrade path
├── classes/
│   ├── external/
│   │   └── chat_api.php              # External API class
│   ├── privacy/
│   │   └── provider.php              # GDPR privacy provider
│   ├── bridge/
│   │   └── acp_bridge.py             # ACP bridge HTTP server
│   └── tool/
│       ├── moodle_admin.py           # Read-only admin queries
│       ├── moodle_schema.py          # Database schema explorer
│       ├── moodle_sql.py             # Safe SQL execution
│       └── skill_backup.py           # Skill export/import
├── amd/
│   ├── src/
│   │   ├── chat.js                   # Chat client (AMD module)
│   │   ├── marked.js                 # marked.js AMD wrapper
│   │   └── terminal.js               # Terminal client
│   └── build/
│       ├── chat.js                   # Built AMD module
│       ├── marked.js                 # Built AMD module
│       ├── terminal.js               # Built AMD module
│       └── vendor/
│           └── marked.min.js         # marked.js source (base64)
├── styles/
│   ├── chat.scss                     # Chat UI styles
│   ├── terminal.css                  # Terminal styles
│   └── terminal.js                   # Terminal inline JS
├── scripts/
│   ├── bootstrap.sh                  # Python + Hermes installer
│   └── install_plugins.php           # CLI plugin installer
└── docs/
    └── README.md                     # This file
```

---

<!-- ============================================================ -->
<!-- FOR INTEGRATORS                                               -->
<!-- ============================================================ -->

## For Integrators

This section covers the API endpoints and web services that connect external systems to the plugin.

### API Endpoints (api.php)

`api.php` exposes actions via the `action` query parameter. All actions require
`require_login()` and `local/hermesagent:use` capability. CSRF protection uses
Moodle's `sesskey`.

#### Actions

| Action | Method | Description |
|--------|--------|-------------|
| `send` | POST | Save a user message to the database. Returns `{messageid, conversationid}`. |
| `stream` | GET | SSE stream that proxies to ACP Bridge at `/v1/chat/completions`. Streams `message`, `session`, `tool_call`, `done`, and `error` events. |
| `status` | GET | Ping the ACP Bridge health endpoint. Returns `{status, online, port}`. |
| `history` | GET | Fetch all messages for a conversation. Returns `{messages: [{id, role, content, timemodified}]}`. |
| `conversations` | GET | List all conversations for the current user. Returns `{conversations: [{id, name, timemodified}]}`. |
| `tool_response` | POST | Approve/reject a tool call. Returns `{status, messageid, approved}`. |

#### SSE Stream Events

The `stream` action uses Server-Sent Events with these event types:

```
event: session
data: {"session_id": "abc12345"}

event: message
data: {"delta": "Hello ", "full": "Hello "}

event: message
data: {"delta": "world", "full": "Hello world"}

event: tool_call
data: {"name": "moodle_sql", "input": {"query": "SELECT ..."}, "id": 42}

event: done
data: [DONE]

event: error
data: {"error": "Bridge error", "code": 500}
```

The `message` event carries both `delta` (incremental token) and `full`
(complete accumulated content). The client uses `full` for real-time
re-rendering and saves the raw markdown on `done`.

### External Web Services (db/services.php)

Registered in `db/services.php` and implemented in
`classes/external/chat_api.php`. Called from the client via Moodle's
`core/ajax` AMD module.

| Web Service | Method | Type | Capability |
|-------------|--------|------|------------|
| `local_hermesagent_send_message` | `send_message($conversationid, $message)` | write | `local/hermesagent:use` |
| `local_hermesagent_get_history` | `get_history($conversationid)` | read | `local/hermesagent:use` |
| `local_hermesagent_tool_response` | `tool_response($messageid, $approved)` | write | `local/hermesagent:approve_tools` |
| `local_hermesagent_get_conversations` | `get_conversations()` | read | `local/hermesagent:use` |
| `local_hermesagent_delete_conversation` | `delete_conversation($conversationid)` | write | `local/hermesagent:use` |
| `local_hermesagent_rename_conversation` | `rename_conversation($conversationid, $name)` | write | `local/hermesagent:use` |
| `local_hermesagent_save_assistant_response` | `save_assistant_response($conversationid, $content)` | write | `local/hermesagent:use` |

All methods validate conversation ownership (`usermodified = $USER->id`)
before operating on data.

### ACP Bridge (acp_bridge.py)

The ACP Bridge is a FastAPI + uvicorn HTTP server that bridges Moodle to
`hermes acp` subprocesses. It runs as `www-data` on `127.0.0.1` on a
configurable port (default: 9118).

#### Bridge HTTP Endpoints

| Endpoint | Method | Description |
|----------|--------|-------------|
| `/health` | GET | Health check. Returns session count and Hermes availability. |
| `/session/create` | POST | Spawn a new `hermes acp` subprocess. Returns `{sid, status}`. |
| `/session/{sid}/send` | POST | Send message to ACP session. Returns SSE stream of tokens. |
| `/session/{sid}/tool_call` | POST | Execute a tool call on the session. Returns JSON response. |
| `/session/{sid}/info` | GET | Session info (PID, alive status). |
| `/session/{sid}` | DELETE | Kill the ACP subprocess. |

#### Environment Variables

| Variable | Default | Description |
|----------|---------|-------------|
| `HERMES_HOME` | `/var/www/moodledata/.hermes` | Hermes installation directory |
| `BRIDGE_PORT` | `9118` | HTTP listen port |
| `MOODLE_DB_HOST` | `mariadb` | Moodle database host |
| `MOODLE_DB_NAME` | `moodle` | Moodle database name |
| `MOODLE_DB_USER` | `moodleuser` | Moodle database user |
| `MOODLE_DB_PASS` | *(empty)* | Moodle database password |
| `MOODLE_DB_CREDENTIALS_FILE` | *(none)* | Alternative: path to DB env file |

#### Moodle Tool Plugins

| Tool | File | Description |
|------|------|-------------|
| `moodle_admin` | `classes/tool/moodle_admin.py` | Read-only queries: courses, users, enrolments, categories |
| `moodle_schema` | `classes/tool/moodle_schema.py` | Database schema exploration: tables, columns, keys, FKs |
| `moodle_sql` | `classes/tool/moodle_sql.py` | Safe SQL: SELECT auto-approved, INSERT/UPDATE/DELETE requires approval, DDL blocked |
| `skill_backup` | `classes/tool/skill_backup.py` | Export/import learned skills to/from JSON |

---

<!-- ============================================================ -->
<!-- FOR FRONTEND DEVELOPERS                                       -->
<!-- ============================================================ -->

## For Frontend Developers

This section documents the client-side JavaScript AMD modules and DOM structure.

### JavaScript AMD Module API (chat.js)

Module: `local_hermesagent/chat`

#### Public API

```javascript
require(['local_hermesagent/chat'], function(chat) {
    // Initialize the chat interface (auto-binds DOM events)
    chat.init();

    // Set configuration from PHP (alternative to data attribute)
    chat.setConfig({ conversationid: 1, sesskey: 'abc123' });
});
```

#### Internal Functions (documented for extension)

| Function | Description |
|----------|-------------|
| `init()` | Auto-init on DOM ready. Reads config from `#hermes-config[data-config]`. Sets up event listeners and loads history. |
| `setConfig(cfg)` | Set module config object (JSON or string). |
| `sendMessage()` | Read input, add user message to UI, start streaming. |
| `streamResponse(conversationid)` | Open EventSource to `api.php?action=stream`, handle SSE events. |
| `renderMessages(messages)` | Render a message array to `#hermes-chat-area`. Handles user/assistant roles. |
| `renderMarkdown(text)` | **Math-safe** markdown rendering. Protects delimiters → marked.js → unescape → returns Promise\<string\>. |
| `typesetMath(element)` | Load MathJax and typeset an HTMLElement. Uses Moodle's `filter_mathjaxloader/loader`. |
| `setMarkdownContent(element, text)` | Convenience: renderMarkdown + typesetMath on a jQuery element. |
| `loadMarked()` | Lazy-load marked.js v15 from CDN. Handles Moodle's `define.amd` conflict. |
| `configureMathJax()` | One-time MathJax configuration via Moodle's loader. Sets up inline/display math delimiters. |
| `showToolModal(toolCall)` | Display tool confirmation modal with Approve/Reject. |
| `handleToolResponse(approved)` | Send tool response via web service, close modal. |

### DOM Elements Used

| Selector | Purpose |
|----------|---------|
| `#hermes-config` | Hidden div with `data-config` JSON |
| `#hermes-chat-area` | Scrollable message container |
| `#hermes-message-input` | Textarea for user input |
| `#hermes-send-btn` | Send button (disabled during streaming) |
| `#hermes-tool-modal` | Tool approval modal (hidden by default) |
| `#hermes-tool-approve` / `#hermes-tool-reject` | Modal action buttons |
| `.hermes-conv-item` | Conversation list items (clickable) |
| `#hermes-new-conv` | "New conversation" button |

### Math Rendering Pipeline

The plugin renders math equations from LLM output through a 4-stage pipeline
in `amd/src/chat.js`. This is necessary because marked.js (the markdown parser)
mangles TeX delimiters like `\[` and `\]` (which it interprets as HTML entity
escape sequences).

```
LLM Markdown Output
     │
     ▼
┌─────────────────────────────┐
│ 1. protectMathDelimiters()  │  Replace \[...\], [...], $$...$$
│                             │  with Unicode placeholders (U+E000,
│                             │  U+E001) so marked.js skips them.
└──────────┬──────────────────┘
           │
           ▼
┌─────────────────────────────┐
│ 2. marked.parse()           │  Convert markdown → HTML.
│                             │  Math placeholders pass through
│                             │  untouched.
└──────────┬──────────────────┘
           │
           ▼
┌─────────────────────────────┐
│ 3. unescapeMathDelimiters() │  Restore U+E000 → \[ and
│                             │  U+E001 → \] in the HTML output.
└──────────┬──────────────────┘
           │
           ▼
┌─────────────────────────────┐
│ 4. typesetMath(element)     │  Load MathJax v4 via Moodle's
│                             │  filter_mathjaxloader/loader, then
│                             │  call MathJax.typesetPromise([el]).
└──────────┬──────────────────┘
           │
           ▼
      Rendered HTML + Math
```

#### Protected Delimiter Formats

| Format | Example | Protected? |
|--------|---------|------------|
| LaTeX display | `\[ E = mc^2 \]` | Yes |
| LaTeX inline | `\ (x + y\ )` | Converted to `\[...\]` |
| Display dollars | `$$ E = mc^2 $$` | Yes (converted) |
| Bare brackets | `[ x^2 ]` on own line | Yes (if math content) |
| Inline dollars | `$ x + y $` | Not supported (ambiguity) |

The `isMathContent()` heuristic checks for math symbols (`=`, `+`, `^`, `\frac`,
`\sin`, `\pi`, `\sum`, `\int`, etc.) to distinguish math from regular
bracketed text.

---

<!-- ============================================================ -->
<!-- FOR BACKEND DEVELOPERS                                        -->
<!-- ============================================================ -->

## For Backend Developers

This section covers the database schema, external PHP functions, and the privacy provider.

### Database Schema (db/tables.xml)

The plugin defines **5 tables** via XMLDB:

#### `local_hermesagent_conversations` — Chat sessions

| Column | Type | Key | Description |
|--------|------|-----|-------------|
| `id` | int(10) | PK | Auto-increment |
| `name` | char(255) | | Conversation name (default: "New conversation") |
| `usermodified` | int(10) | INDEX | Owner user ID (for ownership validation) |
| `acp_session_id` | char(255) | | ACP Bridge session identifier |
| `timemodified` | int(10) | | Last activity timestamp |
| `timecreated` | int(10) | | Creation timestamp |

#### `local_hermesagent_messages` — Chat messages

| Column | Type | Key | Description |
|--------|------|-----|-------------|
| `id` | int(10) | PK | Auto-increment |
| `conversationid` | int(10) | FK→conversations(id) | Parent conversation |
| `role` | char(20) | | "user" or "assistant" |
| `content` | text(long) | | Message body (raw markdown for assistant) |
| `tool_calls` | text(long) | | JSON of tool calls requested |
| `tool_results` | text(long) | | JSON of tool call results |
| `timemodified` | int(10) | | Timestamp |

#### `local_hermesagent_settings` — Plugin settings

| Column | Type | Key | Description |
|--------|------|-----|-------------|
| `id` | int(10) | PK | Auto-increment |
| `name` | char(100) | UNIQUE | Setting name |
| `value` | text | | Setting value |
| `description` | text | | Human-readable description |
| `timemodified` | int(10) | | Timestamp |

#### `local_hermesagent_skills` — Learned skills

| Column | Type | Key | Description |
|--------|------|-----|-------------|
| `id` | int(10) | PK | Auto-increment |
| `name` | char(255) | UNIQUE | Skill name |
| `description` | text | | Skill description |
| `content` | text | | Skill content/prompt |
| `category` | char(100) | | Category (default: "general") |
| `enabled` | int(1) | | Enabled flag (default: 1) |
| `timemodified` | int(10) | | Timestamp |
| `timecreated` | int(10) | | Timestamp |

#### `local_hermesagent_tool_log` — Tool execution audit log

| Column | Type | Key | Description |
|--------|------|-----|-------------|
| `id` | int(10) | PK | Auto-increment |
| `messageid` | int(10) | FK→messages(id) | Related message |
| `tool_name` | char(100) | | Tool that was executed |
| `input` | text | | Tool input (JSON) |
| `output` | text | | Tool output |
| `confirmed` | int(1) | | Human-confirmed flag (default: 0) |
| `timemodified` | int(10) | | Timestamp |

### External Functions (classes/external/chat_api.php)

The external API class provides 7 methods registered in `db/services.php`. All methods:

- Validate `external_function_parameters()` strictly
- Check capabilities before execution
- Verify conversation ownership (`usermodified = $USER->id`)
- Return typed responses via `external_single_structure` or `external_multiple_structure`

Key implementation notes:

- `send_message()` creates a default conversation if none exists
- `get_history()` returns messages ordered by `id ASC`
- `tool_response()` updates the `tool_results` field on the message
- `delete_conversation()` cascades to messages and tool_log entries
- `save_assistant_response()` stores the final rendered markdown

### Privacy Provider (classes/privacy/provider.php)

Implements three Moodle privacy interfaces:

- **`metadata\provider`** — Declares `local_hermesagent_conversations` and `local_hermesagent_messages` as containing personal data
- **`request\core_user_data_provider`** — Exports all conversations and messages for a user as JSON
- **`request\core_userlist_provider`** — Lists users who have data; deletes data respecting FK order (tool_log → messages → conversations)

---

<!-- ============================================================ -->
<!-- FOR MAINTAINERS                                               -->
<!-- ============================================================ -->

## For Maintainers

This section covers testing, deployment, and Moodle Plugin Directory submission.

### Testing Guide

#### PHPUnit Tests

Create tests under `tests/` following Moodle conventions:

```
tests/
├── chat_api_test.php           # External API unit tests
├── privacy_provider_test.php   # Privacy provider tests
└── generator/
    └── lib.php                 # Test data generator (optional)
```

Run tests:

```bash
php admin/tool/phpunit/cli/util.php --component=local_hermesagent
```

Key areas to test:

- Conversation ownership validation (user can't access another user's conversations)
- Message saving and retrieval
- Tool response handling
- Privacy data export and deletion
- Capability enforcement

#### JavaScript Testing

The AMD modules use jQuery and Moodle's `core/ajax`. For unit testing:

```bash
# Moodle's JS test framework
yarn run grunt watch  # in Moodle root, then test in browser
```

Key scenarios to verify:

- SSE event parsing and error recovery
- Math delimiter protection/unescape roundtrip
- Markdown rendering with embedded math
- Conversation list interactions

#### Manual Testing Checklist

1. Fresh install: run Notifications, verify tables created
2. Bootstrap: click "Bootstrap Hermes", verify Python + hermes installed
3. Start bridge: click Start, verify "Running" status
4. Send message: verify SSE streaming with spinner
5. Math test: ask "show me the quadratic formula" — verify rendering
6. Tool approval: trigger a SQL query — verify modal appears
7. Conversation management: create, rename, delete conversations
8. Privacy export: export user data — verify conversations included
9. Privacy delete: delete user data — verify cascading delete
10. Bridge stop: click Stop — verify graceful shutdown

### Development Workflow

#### Local Development Setup

```bash
# 1. Install the plugin
cp -r local/hermesagent /path/to/moodle/local/

# 2. Run Moodle upgrade
php admin/cli/upgrade.php

# 3. Bootstrap Hermes
#    (from admin settings page or manually)
/var/www/moodledata/.hermes/venv/bin/hermes --version

# 4. Start the bridge
#    (from admin settings page or manually)
HERMES_HOME=/var/www/moodledata/.hermes \
BRIDGE_PORT=9118 \
python /path/to/moodle/local/hermesagent/classes/bridge/acp_bridge.py

# 5. Enable debugging in Moodle for development
#    Site admin > Development > Debugging
#    Set: Developer (MDLDEVELOPER), Display debug messages = Yes
```

#### Building AMD Modules

After editing `amd/src/chat.js`:

```bash
# In Moodle root
yarn run grunt amd-build
```

This generates `amd/build/chat.js` (minified). The build is also
triggered automatically by Moodle's plugin checker in CI.

#### Purging RequireJS Cache

Moodle 5.x caches compiled RequireJS bundles. After installing or updating
AMD modules, purge the cache:

```bash
php admin/cli/purge_caches.php
```

The upgrade script (`db/upgrade.php`) also attempts this on version
2026061204, but a full `purge_all_caches()` call from admin is the
most reliable approach.

#### Adding a New Tool

1. Create `classes/tool/my_tool.py` with argparse CLI interface.
2. Register it in your Hermes skills/profile configuration.
3. Add capability enforcement in the tool (check permissions before
   executing write operations).
4. Document in the tool section above.

### Moodle Plugin Directory Submission

#### Required Files

- [x] `version.php` with `$plugin->component`, `$plugin->release`,
      `$plugin->version`, `$plugin->requires`, `$plugin->maturity`
- [x] `README.txt` (plain text, no markdown)
- [x] `LICENSE` (GPL v3+ header in all PHP files)
- [x] `lang/en/local_hermesagent.php` with pluginname string
- [x] `db/install.xml` or tables defined in `db/tables.xml`

#### Privacy

- [x] `classes/privacy/provider.php` implementing all three interfaces
- [x] Privacy metadata strings in language file
- [x] Data export functional
- [x] Data deletion functional (including cascading deletes)

#### Security

- [x] `defined('MOODLE_INTERNAL') || die()` in all includeable PHP
- [x] `require_login()` + capability checks on all pages
- [x] `require_sesskey()` / `confirm_sesskey()` for CSRF protection
- [x] External API methods validate parameters with `external_function_parameters`
- [x] Conversation ownership enforced (usermodified check)
- [x] SQL injection prevented (Moodle $DB API used throughout)
- [x] Credential file permissions set to 0600
- [x] Script/iframe injection prevented in markdown (stripped in renderMarkdown)

#### Compatibility

- [x] Moodle 5.0+ (requires = 2024100700)
- [x] PHP 8.1+ (Moodle 5.0 requirement)
- [x] No deprecated API usage
- [x] Properly namespaced classes

#### Code Quality

- [x] PHPDoc comments on public methods
- [x] Consistent indentation (4 spaces for PHP, no tabs)
- [x] No hardcoded strings (all user-facing text in language files)
- [x] CSS via SCSS/Moodle pipeline, not inline
- [x] JavaScript as AMD modules

#### Before Submitting

- [ ] Run Moodle's plugin checker: `php admin/cli/validate_plugin.php`
- [ ] Run codechecker: Site admin > Development > Code checker
- [ ] Verify all PHP files have proper license headers
- [ ] Test on a clean Moodle 5.0+ installation
- [ ] Update version.php release tag before each submission
- [ ] Add screenshots to the Moodle Plugin Directory listing
- [ ] Write a concise short description for the directory listing
