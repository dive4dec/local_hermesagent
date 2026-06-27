local_hermesagent - Hermes Agent integration for Moodle
=======================================================

Plugin name   : Hermes Agent
Component     : local_hermesagent
Version       : 0.3.7 (2026062701)
Moodle req.   : 5.0+ (2024100700)
License       : GNU GPL v3 or later
              : https://www.gnu.org/copyleft/gpl.html
Maturity      : Beta


Motivation
----------

Moodle is the world's most widely deployed open-source LMS, but educators
often need to switch between platforms to access AI-powered assistance.
local_hermesagent bridges this gap by connecting Moodle directly to the
Hermes AI agent framework (https://github.com/nousresearch/hermes).

Rather than relying on external AI services outside your LMS, this plugin
provides a secure, budget-controlled AI interface operating within Moodle's
own authentication, user management, and role system.  Educators and
students can have AI-assisted conversations without ever leaving Moodle.

Key design goals:

  * Platform-native  -- uses Moodle auth (LDAP, SAML, etc.); no separate
    logins or API key management by end users.
  * Budget-controlled  -- per-user API key quotas prevent runaway LLM
    costs, with admin oversight of usage.
  * Secure by default  -- admin-only access during pilot ensures controlled
    rollout before opening to broader user groups.
  * Open and auditable  -- all conversation data stored in Moodle's database
    with full GDPR export/delete support.


Use cases
---------

Realistic scenarios where Hermes Agent inside Moodle adds value:

  * Answering student questions about course material -- clarification on
    lecture notes, readings, or assignments in plain language.
  * Explaining complex topics with rendered equations -- math, physics,
    chemistry, engineering with properly typeset LaTeX/MathJax equations.
  * Drafting assessment rubrics or learning objectives -- structured
    rubrics, Bloom's-taxonomy-aligned objectives, or sample quiz items.
  * Research assistance for faculty -- literature summaries, method
    suggestions, and writing feedback without leaving the LMS.
  * Explaining Moodle features or troubleshooting -- built-in help for
    navigating Moodle (grading, blocks, themes) reduces IT support tickets.


Features
--------

  * Real-time streaming responses (SSE)  -- answers appear word-by-word,
    not in a single delayed block.  Markdown rendering with syntax
    highlighting for code blocks.
  * Math equation rendering (MathJax)  -- inline ($...$) and display
    ($$...$$) equations render as high-quality typeset math.
  * Conversation history  -- persisted per user with rename and delete.
  * Per-user API key budget controls  -- admin-configured spending caps.
  * Admin-only access (pilot phase)  -- controlled rollout; broader access
    via Moodle roles and capabilities after pilot.
  * Moodle-native authentication  -- works with LDAP, SAML, database auth,
    or any other Moodle auth plugin.  No extra accounts needed.
  * Tool-calling with human-in-the-loop approval  -- SQL queries, schema
    exploration, admin lookups require explicit user confirmation.
  * Learned skills system  -- persistent instructions remembered across
    conversations, stored in the plugin database.
  * Built-in terminal for Hermes CLI  -- configure and update Hermes from
    within Moodle.


Requirements
------------

  * Moodle 5.0 or later (tested on Moodle 5.x).
  * PHP with curl extension enabled.
  * Python 3.12+ (standalone musl build provided by bootstrap script).
  * Hermes CLI (hermes-agent package) -- auto-installed by bootstrap or
    pre-installed manually.
  * The ACP Bridge (acp_bridge.py) -- shipped with the plugin, runs as
    www-data using a portable Python venv under moodledata/.hermes/.
  * MathJax CDN access (or a local MathJax mirror in Moodle).


Installation
------------

  1. Copy this plugin directory to your Moodle installation:

       moodle/local/hermesagent/

  2. Log in as admin and navigate to:

       Site administration > Notifications

     Moodle will detect the plugin and offer to upgrade the database.

  3. Click "Continue" to create the plugin tables and seed default settings.

  4. Go to:

       Site administration > Plugins > Local plugins > Hermes Agent

     to access the settings page.

  5. Bootstrap Hermes (first-time only):

     Use the "Update & Bootstrap" button in the settings page.  This
     downloads a standalone Python 3.12 build (~50 MB), creates a virtual
     environment, and installs the hermes-agent package.

  6. The ACP Bridge auto-starts on the first chat message.  You can also
     restart it manually via the "Restart ACP" button on the settings page.

Prerequisites:
    - www-data can write to moodledata/.hermes/
    - curl and tar available on the server
    - Outbound network access for downloading Python + hermes-agent


Accessing the plugin
--------------------

This plugin is currently restricted to **site administrators only** during
the pilot phase.

  1. Admin settings panel (recommended):

       Settings > Plugins > Local plugins > Hermes Agent > "Open Hermes Chat"

  2. Direct URL (admin only):

       https://your-moodle-site/local/hermesagent/chat.php

Non-admin users receive an access denied error.  Once the pilot is complete,
access can be broadened to teachers, students, or other roles via Moodle's
standard capability system.


Configuration
-------------

All settings are managed from:

  Site administration > Plugins > Local plugins > Hermes Agent

Settings:

  Bridge port     -- TCP port for ACP Bridge HTTP service (default: 9118).
                     Listens on 127.0.0.1 only.
  Model override  -- Override the LLM model.  Leave blank for default.
  Hermes home     -- Custom HERMES_HOME path.  Default:
                     ${HERMES_HOME:-/var/www/moodledata/.hermes}

The settings page also shows bridge status (Running/Stopped), current Hermes
CLI version, number of active ACP sessions, and links to the CLI terminal
and bootstrap.

Bridge management: started/stopped via settings.php as www-data.  On start,
a credential file (0600) is written for the bridge to read Moodle DB
settings.  On stop, it is securely deleted.


Capabilities
------------

  local/hermesagent:use          -- Access the chat interface.
  local/hermesagent:configure    -- Manage bridge settings and CLI terminal.
  local/hermesagent:manage_skills -- Manage learned skills.
  local/hermesagent:approve_tools -- Approve or reject tool execution.

All capabilities are scoped to CONTEXT_SYSTEM and carry RISK_CONFIG.


Privacy compliance
------------------

The plugin implements Moodle's privacy provider interfaces:

  * core_privacy\local\metadata\provider
  * core_privacy\local\request\core_user_data_provider
  * core_privacy\local\request\core_userlist_provider

Personal data stored:

  * Chat conversations (user input + assistant responses) linked to the
    creating user.
  * Conversation metadata (name, ACP session ID, timestamps).
  * Learned skills and persistent instructions (shared, not personal).

Data export:  Site administration > Users > Privacy > Export my data
Data deletion: Site administration > Users > Privacy > Delete my data

The delete handler cascades: tool_log -> messages -> conversations.

GDPR compliance:

  - All personal data stored within Moodle's own database.
  - Users can export or delete their conversation history at any time.
  - No data sent to third parties beyond the LLM provider configured by
    the site administrator.
  - Conversation data is not used for model training unless the upstream
    LLM provider does so (check your provider's privacy policy).


Database tables
---------------

  local_hermesagent_settings          -- Plugin settings (key-value store).
  local_hermesagent_conversations     -- User chat sessions (FK to user).
  local_hermesagent_messages          -- Chat messages (user, assistant, tool).
  local_hermesagent_skills            -- Learned skills / persistent instructions.
  local_hermesagent_tool_log          -- Tool execution audit log.


Architecture overview
---------------------

  Browser (chat.js)
    |
    |  AJAX + SSE stream
    v
  api.php  -->  acp_bridge.py (FastAPI)  -->  hermes acp  -->  LLM


Change History
--------------

  0.3.7 (2026-06-27)
    * fix: require lib.php in settings.php — resolves "undefined function"
      error when using Restart ACP button
    * fix: scope $req_id in api.php closure — resolves PHP fatal error
      during streaming responses
    * refactor: removed Start/Stop buttons from admin settings
    * Added PHP lazy-start: api.php auto-starts bridge on first chat
      message if bridge is down
    * Deleted redundant local_hermesagent_settings.php

  0.3.6 (2026-06-23)
    * fix: replace proxy with ACP bridge architecture (acp_bridge.py)
    * ACP runs in tmux session on pod, managed via lib.php PHP functions

  0.3.5 (2026-06-23)
    * fix: rename tables.xml to install.xml for Moodle XMLDB installer

  0.3.0 (2026-06-13)
    * Initial release with proxy-based architecture

Known limitations
-----------------

  * ACP Bridge must run on the same host as the Moodle web server
    (connects to 127.0.0.1).  Remote bridges not yet supported.
  * Each ACP session runs a dedicated `hermes acp` subprocess; long-lived
    conversations hold a process for their entire lifetime.
  * Math rendering needs internet access for MathJax CDN unless a local
    mirror is configured.
  * Bootstrap requires curl and tar on the server.
  * Moodle 5.x requirejs cache may need manual purge on first install.
  * Tool execution (especially SQL) runs as www-data with Moodle DB
    credentials -- use with caution on production instances.
  * Terminal does not support interactive programs needing stdin
    (e.g., vim, python REPL).


Links
-----

  Hermes Agent docs       : https://hermes-agent.nousresearch.com/docs
  Hermes Agent GitHub     : https://github.com/nousresearch/hermes
  Moodle Plugin Directory : https://moodle.org/plugins/local_hermesagent
  MathJax                 : https://www.mathjax.org/
  marked.js               : https://github.com/markedjs/marked


Credits
-------

  * Hermes Agent by Nous Research
  * marked.js -- markdown parser (MIT License)
  * MathJax -- mathematical typesetting (Apache 2.0 License)
  * Moodle -- learning management system (GPL v3+)
