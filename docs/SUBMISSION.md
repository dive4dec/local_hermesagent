# Moodle Plugin Directory Submission Guide

## Current Status: **Not yet ready for submission**

This plugin has been significantly improved but still has blockers for Moodle Plugin Directory acceptance.

---

## What Has Been Fixed ✅

| Category | Issue | Fix |
|----------|-------|-----|
| **Security** | H4: User isolation missing | Added `usermodified` checks in `get_history()`, `send_message()`, `save_assistant_response()`, `api_get_history()`, `api_send_message()` |
| **Security** | H1: CSRF protection | Replaced spoofable `X-Requested-With` header with `confirm_sesskey()` in api.php |
| **Security** | H5: GDPR non-compliance | Replaced `null_provider` with full privacy provider (metadata, export, delete) |
| **Security** | H2: DB credential leak | Credentials now written to file with `0600` permissions instead of env vars |
| **Quality** | M1: Inline CSS | Merged into `styles/chat.scss`, loaded via `$PAGE->requires->scss()` |
| **Quality** | M2: TEXT column limit | Changed to `TYPE="text" LENGTH="long"` (LONGTEXT/4GB) |
| **Quality** | C3: Stale build artifacts | `amd/build/chat.js` now matches `amd/src/chat.js` |
| **Quality** | Chat.js cleanup | Removed duplicate comments, debug logging, consolidated math pipeline |
| **Documentation** | No README.txt | Written (7.7 KB) |
| **Documentation** | No developer docs | `docs/README.md` written (20 KB) |
| **Testing** | No tests | PHPUnit (299+190 lines) + Behat feature written |

---

## Blockers for Moodle Plugin Directory ❌

### 1. Arbitrary Command Execution (exec.php)
**Severity:** Critical — will be **rejected outright** by reviewers.

`exec.php` accepts `PARAM_RAW` commands and executes them on the server. This is a full RCE vulnerability.

**Options:**
- **Remove exec.php entirely** for directory submission (keep in your internal fork)
- **Add sandboxing**: restrict commands to a whitelist, run in a container/namespace
- **Justify**: Document why exec.php exists (terminal feature for research lab) and add `local/hermesagent:configure` capability requirement + IP restriction

### 2. CDN Dependency (marked.js from jsdelivr)
`amd/src/chat.js` line 413 loads marked.js from `https://cdn.jsdelivr.net/npm/marked@15.0.0/marked.min.js`

Moodle plugins should **bundle all JS**. The fix:
- The file `amd/src/marked.js` already bundles marked.js as a proper AMD module
- Change `loadMarked()` to use it: `define(['jquery', 'core/ajax', 'core/str', 'filter_mathjaxloader/loader', 'local_hermesagent/marked'], function($, ajax, Str, mathjaxLoader, marked) { ... })`
- Remove the dynamic script loading

### 3. Hardcoded Paths
Paths like `${HERMES_HOME:-/var/www/moodledata/.hermes}`, `/var/www/html`, `/tmp/hermes_terminal` appear in:
- `exec.php`, `terminal.php`, `local_hermesagent_settings.php`, `scripts/bootstrap.sh`, `terminal.js`

These must use plugin config or `$CFG->dataroot`. For example:
```php
$hermes_home = get_config('local_hermesagent', 'hermes_home') ?: $CFG->dataroot . '/hermes';
```

### 4. Research-Specific Dependencies
The plugin requires:
- Python 3.12+ with Hermes Agent installed
- ACP bridge (`acp_bridge.py`)
- An LLM backend (vLLM, LiteLLM, etc.)

These are research infrastructure dependencies that won't exist on typical Moodle installations. The plugin must either:
- Gracefully degrade when dependencies aren't found
- Clearly document that this is a **research plugin** not for production Moodle sites

---

## Submission Process (When Ready)

### Step 1: Create Moodle.org Account
- Go to https://moodle.org/ and create an account
- Verify your email

### Step 2: Fork the Plugin Directory
```bash
git clone https://github.com/moodle/moodle-plugin-directory.git
cd moodle-plugin-directory
git checkout -b local_hermesagent
```

### Step 3: Create Plugin Directory Structure
```
local_hermesagent/
├── submission.md      ← The form data
├── local_hermesagent/ ← Your plugin (copy from repo)
│   ├── version.php
│   ├── README.txt
│   ├── settings.php
│   └── ...
```

### Step 4: Write submission.md
```yaml
name: Hermes Agent
shortname: local_hermesagent
maturity: MATURITY_ALPHA
description: |
  An AI chat interface for Moodle that integrates with Hermes Agent (ACP protocol).
  Provides streaming LLM conversations with LaTeX math rendering, tool calling,
  and a terminal interface. Designed for research/educational use.
```

See the existing submission.md files in the directory for exact format.

### Step 5: Create Pull Request
- Push to your fork
- Create a PR on https://github.com/moodle/moodle-plugin-directory/pulls
- The Moodle plugins team will review (typically 1-2 weeks)

### Step 6: Respond to Reviewer Feedback
Common review requests:
- Security audit (especially exec.php)
- Coding style checks (`phpcs --standard=moodle`)
- PHPUnit test coverage
- Behat acceptance tests
- Privacy compliance documentation
- Language file completeness

---

## Alternative: Self-Host via GitHub

If Moodle Plugin Directory review is too strict for your research use case:

1. **Publish to GitHub**: `git push` your plugin to a public repo
2. **Install via Git**: `git clone https://github.com/YOUR/local_hermesagent.git $MOODLE/local/hermesagent`
3. **Install via Zip**: Download ZIP → extract to `$MOODLE/local/hermesagent`
4. **Install via Moodle CLI**: `php admin/cli/install_plugin.php --upload=/path/to/zip`

This is perfectly valid — the Plugin Directory is for *certified* plugins, not the only distribution channel.

---

## Pre-Submission Checklist

- [ ] Remove or sandbox exec.php (RCE)
- [ ] Bundle marked.js (remove CDN)
- [ ] Replace hardcoded paths with config settings
- [ ] Run `phpcs --standard=moodle` on all PHP files
- [ ] Run PHPUnit tests: `vendor/bin/phpunit local_hermesagent_testcase`
- [ ] Run Behat tests: `vendor/bin/behat --tags=@local_hermesagent`
- [ ] Test on a clean Moodle 5.0 install
- [ ] Verify privacy export/deletion works
- [ ] Write a compelling submission.md
- [ ] Create a nice plugin logo/screenshot
