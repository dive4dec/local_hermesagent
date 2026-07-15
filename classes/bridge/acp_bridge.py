#!/usr/bin/env python3
"""
ACP Bridge — FastAPI server that connects Moodle to `hermes acp`.

Architecture:
  Moodle browser → api.php → acp_bridge.py (port 9118) → hermes acp subprocess
                                                                 (real agent loop with MCP)

The `hermes acp` subprocess handles the full agent loop internally:
- Multi-turn tool calling
- MCP tool execution
- Conversation management

This bridge just translates between HTTP/SSE and ACP stdio JSON-RPC.
"""

import asyncio
import json
import logging
import os
import queue
import subprocess
import sys
import threading
import time
import uuid
from http import HTTPStatus
from pathlib import Path

import uvicorn
from fastapi import FastAPI, Request
from fastapi.responses import StreamingResponse

# ---------------------------------------------------------------------------
# Logging — write to both stderr and a file for debugging
# ---------------------------------------------------------------------------
LOG_DIR = Path(os.environ.get("HERMES_HOME", "/tmp")) / "logs"
LOG_DIR.mkdir(parents=True, exist_ok=True)
LOG_FILE = LOG_DIR / "acp_bridge.log"

logging.basicConfig(
    level=logging.DEBUG,
    format="%(asctime)s [%(threadName)s] %(levelname)s %(message)s",
    handlers=[
        logging.FileHandler(LOG_FILE),
        logging.StreamHandler(sys.stderr),
    ],
)
log = logging.getLogger("acp_bridge")

# ---------------------------------------------------------------------------
# Configuration
# ---------------------------------------------------------------------------
HERMES_BIN = Path(os.environ.get("HERMES_HOME", "/tmp")) / "venv" / "bin" / "hermes"
HERMES_HOME_ENV = os.environ.get("HERMES_HOME", "/tmp")
ACP_TIMEOUT_SECONDS = float(os.environ.get("ACP_TIMEOUT", "600"))  # 10 min — allows time for tool approval
PORT = int(os.environ.get("BRIDGE_PORT", "9118"))

app = FastAPI(title="Hermes ACP Bridge")

# ---------------------------------------------------------------------------
# Global ACP process manager
# ---------------------------------------------------------------------------
class ACPProcess:
    """Manages a long-lived `hermes acp` subprocess."""

    def __init__(self):
        self.proc = None
        self.inbox = queue.Queue()
        self.stderr_tail = []
        self._out_thread = None
        self._err_thread = None
        self._next_id = 0
        self._lock = threading.Lock()
        self._sessions = {}  # moodle_session_id -> acp_session_id
        # Abort tracking: moodle_conv_id -> event to set when abort requested
        self._abort_events = {}  # moodle_conv_id -> threading.Event()
        # Prompt serialization: hermes acp is a single stdio process that can
        # only handle one prompt at a time. Without this lock, concurrent
        # prompts would both read from the shared inbox queue and steal each
        # other's session/update chunks.
        self._prompt_lock = threading.Lock()
        # Track pending permission requests so we can detect stale/expired ones
        self._pending_permissions = set()  # set of permission_ids (msg ids)
        self._permission_options = {}    # {permission_id: set(offered optionIds)}

    def start(self):
        """Start the hermes acp subprocess."""
        log.info("Starting hermes acp subprocess from %s", HERMES_BIN)
        env = os.environ.copy()
        env["HERMES_HOME"] = HERMES_HOME_ENV

        try:
            self.proc = subprocess.Popen(
                [str(HERMES_BIN), "acp"],
                stdin=subprocess.PIPE,
                stdout=subprocess.PIPE,
                stderr=subprocess.PIPE,
                text=True,
                bufsize=1,
                env=env,
            )
        except FileNotFoundError:
            log.error("hermes binary not found at %s", HERMES_BIN)
            raise

        if not self.proc.stdin or not self.proc.stdout:
            self.proc.kill()
            raise RuntimeError("hermes acp did not expose stdin/stdout")

        # Start reader threads
        self._out_thread = threading.Thread(target=self._stdout_reader, daemon=True, name="acp-stdout")
        self._err_thread = threading.Thread(target=self._stderr_reader, daemon=True, name="acp-stderr")
        self._out_thread.start()
        self._err_thread.start()

        # Initialize ACP protocol
        log.info("Initializing ACP protocol...")
        resp = self._request("initialize", {
            "protocolVersion": 1,
            "clientCapabilities": {},
            "clientInfo": {
                "name": "moodle-bridge",
                "title": "Moodle ACP Bridge",
                "version": "0.1.0",
            },
        }, timeout=30)
        log.info("ACP initialized, response: %s", resp)

    def _stdout_reader(self):
        """Read JSON-RPC messages from acp stdout, ignoring non-JSON log lines."""
        while True:
            try:
                line = self.proc.stdout.readline()
                if not line:
                    log.warning("ACP stdout EOF - process may have exited")
                    break
                line = line.strip()
                if not line:
                    continue
                try:
                    msg = json.loads(line)
                    log.debug("ACP stdout JSON-RPC: %s", json.dumps(msg, default=str)[:500])
                    self.inbox.put(msg)
                except json.JSONDecodeError:
                    # hermes acp writes INFO log lines to stdout too — skip them
                    log.debug("ACP stdout non-JSON (log line): %s", line[:300])
                    continue
            except Exception as e:
                log.error("Error reading ACP stdout: %s", e)
                break

    def _stderr_reader(self):
        """Read stderr from acp process."""
        while True:
            try:
                line = self.proc.stderr.readline()
                if not line:
                    break
                self.stderr_tail.append(line.strip())
                if len(self.stderr_tail) > 100:
                    self.stderr_tail.pop(0)
                if self.stderr_tail[-1]:
                    log.debug("ACP stderr: %s", self.stderr_tail[-1][:200])
            except Exception as e:
                log.error("Error reading ACP stderr: %s", e)
                break

    def _inc_id(self):
        with self._lock:
            self._next_id += 1
            return self._next_id

    def _send_jsonrpc(self, msg):
        """Write a JSON-RPC message to the ACP process stdin."""
        self.proc.stdin.write(json.dumps(msg) + "\n")
        self.proc.stdin.flush()

    def _send_jsonrpc_error(self, msg_id, code, message):
        """Send a JSON-RPC error response to the ACP process."""
        self._send_jsonrpc({
            "jsonrpc": "2.0",
            "id": msg_id,
            "error": {"code": code, "message": message},
        })

    def _request(self, method, params, timeout=60, text_parts=None, reasoning_parts=None, session_id_filter=None):
        """Send a JSON-RPC request and wait for response.

        Returns (result, text_parts, reasoning_parts) where parts accumulate
        session/update notifications.
        """
        request_id = self._inc_id()
        payload = {
            "jsonrpc": "2.0",
            "id": request_id,
            "method": method,
            "params": params,
        }
        log.info("ACP request %d: %s", request_id, json.dumps(payload, default=str)[:500])

        self.proc.stdin.write(json.dumps(payload) + "\n")
        self.proc.stdin.flush()

        deadline = time.monotonic() + timeout
        while time.monotonic() < deadline:
            if self.proc.poll() is not None:
                stderr = "\n".join(self.stderr_tail[-20:])
                raise RuntimeError(f"ACP process exited early. stderr:\n{stderr}")

            try:
                msg = self.inbox.get(timeout=0.1)
            except queue.Empty:
                continue

            # Handle notifications (no id) and session/update
            notif = self._handle_notification(msg, text_parts, reasoning_parts, session_id_filter)
            if notif:
                continue

            # Look for our response
            if msg.get("id") == request_id:
                if "error" in msg:
                    raise RuntimeError(f"ACP error: {msg['error']}")
                log.info("ACP response %d: %s", request_id, json.dumps(msg.get("result", {}), default=str)[:500])
                return msg.get("result"), text_parts, reasoning_parts

        raise TimeoutError(f"Timed out waiting for ACP response to {method}")

    def _handle_notification(self, msg, text_parts, reasoning_parts, session_id_filter):
        """Handle notifications during _request(). Returns True if consumed.

        Only called from the non-streaming _request() path (initialize, session/new).
        The streaming send_prompt_streaming() path handles notifications itself.
        """
        method = msg.get("method")

        # session/update notifications — accumulate text/reasoning
        if method == "session/update":
            params = msg.get("params", {})
            update = params.get("update", {})
            kind = str(update.get("sessionUpdate", "")).strip()
            content = update.get("content", {})
            text = str(content.get("text", "")) if isinstance(content, dict) else ""

            if kind == "agent_message_chunk" and text and text_parts is not None:
                text_parts.append(text)
            elif kind == "agent_thought_chunk" and text and reasoning_parts is not None:
                reasoning_parts.append(text)
            return True

        # session/request_permission — not expected during initialize/session/new;
        # let the caller handle it if it arrives
        if method == "session/request_permission":
            return False

        # Unknown requests with an id — respond with error
        msg_id = msg.get("id")
        if msg_id is not None and method and "/" in method:
            log.warning("Unhandled ACP method in _request: %s (id=%s)", method, msg_id)
            self._send_jsonrpc_error(msg_id, -32601, f"Method '{method}' not supported")

        return False

    def create_session(self, cwd=None):
        """Create a new ACP session. Returns session_id."""
        if cwd is None:
            cwd = "/var/www/html"
        result, _, _ = self._request("session/new", {
            "cwd": cwd,
            "mcpServers": [],  # MCP servers registered via Hermes config
        }, timeout=30)
        session_id = result.get("sessionId")
        log.info("Created ACP session: %s", session_id)
        return session_id

    def load_session(self, session_id, cwd=None):
        """Load an existing ACP session by ID. Returns result or None if not found."""
        # NOTE: session/load often fails because hermes acp can't recreate the
        # agent for old sessions ("Failed to recreate agent"). We keep this method
        # for future use but currently don't rely on it.
        if cwd is None:
            cwd = "/var/www/html"
        try:
            result, _, _ = self._request("session/load", {
                "cwd": cwd,
                "sessionId": session_id,
                "mcpServers": [],
            }, timeout=30)
            log.info("Loaded ACP session: %s", session_id)
            return result
        except RuntimeError as e:
            log.warning("session/load failed for %s: %s", session_id, e)
            return None

    def send_prompt_streaming(self, session_id, prompt_text, timeout=None, abort_event=None):
        """Send a prompt and yield SSE events as they arrive from the agent.

        This reads from the shared inbox and yields events for this specific
        prompt request, allowing real-time SSE streaming.
        """
        if timeout is None:
            timeout = ACP_TIMEOUT_SECONDS

        # Build prompt blocks
        prompt_blocks = [{"type": "text", "text": prompt_text}]

        # Generate unique request ID
        request_id = self._inc_id()
        payload = {
            "jsonrpc": "2.0",
            "id": request_id,
            "method": "session/prompt",
            "params": {
                "sessionId": session_id,
                "prompt": prompt_blocks,
            },
        }
        log.info("Sending prompt request %d to session %s", request_id, session_id)

        self.proc.stdin.write(json.dumps(payload) + "\n")
        self.proc.stdin.flush()

        # No deadline — the ACP adapter imposes its own timeout on approval
        # requests (future.result(timeout=ACP_APPROVAL_TIMEOUT) in permissions.py),
        # and the agent loop has max_turns.  Removing the bridge deadline avoids
        # a competing timer that kills the SSE stream while the user is still
        # deciding on an approval.  The loop exits on: final response (done),
        # process exit, or user abort.
        accumulated_text = ""
        accumulated_reasoning = ""
        done = False
        last_keepalive = 0.0  # monotonic timestamp of last keepalive sent

        while not done:
            if self.proc.poll() is not None:
                stderr = "\n".join(self.stderr_tail[-20:])
                log.error("ACP process exited! stderr:\n%s", stderr)
                self._pending_permissions.clear()
                self._permission_options.clear()
                yield {
                    "type": "error",
                    "error": f"ACP process exited. stderr: {stderr}",
                    "text": accumulated_text,
                    "reasoning": accumulated_reasoning,
                }
                return

            try:
                msg = self.inbox.get(timeout=0.5)
            except queue.Empty:
                # Check abort during the 0.5s wait gap
                if abort_event and abort_event.is_set():
                    self._pending_permissions.clear()
                    self._permission_options.clear()
                    return
                # Send SSE keepalive comment while waiting (e.g. during permission
                # approval).  Without this, the K8s ingress proxy_read_timeout
                # (60s default) kills the idle connection → "Connection error".
                if self._pending_permissions:
                    now = time.monotonic()
                    if now - last_keepalive >= 15:
                        last_keepalive = now
                        yield {"type": "keepalive"}
                continue
            # Skip messages for other requests
            msg_id = msg.get("id")
            method = msg.get("method")

            # Filter session/update notifications by session_id — after an abort,
            # hermes acp keeps running the old prompt and sends session/update
            # messages for the old session. These must be discarded so they don't
            # pollute the next prompt's stream.
            if method == "session/update":
                params = msg.get("params", {})
                msg_session_id = params.get("sessionId", "")
                if msg_session_id and msg_session_id != session_id:
                    log.debug("Discarding session/update from other session %s (expected %s)",
                              msg_session_id, session_id)
                    continue
                update = params.get("update", {})
                kind = str(update.get("sessionUpdate", "")).strip()
                content = update.get("content", {})
                text = ""
                if isinstance(content, dict):
                    text = str(content.get("text", ""))

                if kind == "agent_message_chunk" and text:
                    accumulated_text += text
                    log.debug("agent_message_chunk (%d total): %s", len(accumulated_text), text[:100])
                    yield {
                        "type": "message",
                        "delta": text,
                        "full": accumulated_text,
                    }
                elif kind == "agent_thought_chunk" and text:
                    accumulated_reasoning += text
                    log.debug("agent_thought_chunk (%d total): %s", len(accumulated_reasoning), text[:100])
                    yield {
                        "type": "reasoning",
                        "delta": text,
                        "full": accumulated_reasoning,
                    }
                continue

            # Handle session/request_permission - forward to browser for approval
            if method == "session/request_permission" and msg_id is not None:
                params = msg.get("params", {})
                options = params.get("options", [])
                # Extract tool call info — ACP uses "toolCall" not "call"
                tc = params.get("toolCall", {})
                raw = tc.get("rawInput", {})
                tool_name = raw.get("tool", "unknown")
                tool_args = raw.get("arguments", {})
                call_kind = tc.get("kind", "execute")
                content_items = tc.get("content", [])

                # Build title from rawInput
                if tool_args.get("path"):
                    call_title = f"{tool_name}: {tool_args['path']}"
                elif tool_args.get("command"):
                    cmd = tool_args["command"]
                    call_title = f"{tool_name}: {cmd[:120]}"
                else:
                    call_title = tool_name

                # Build a readable description
                desc_parts = []
                for item in content_items:
                    if isinstance(item, dict):
                        item_type = item.get("type", "")
                        if item_type == "diff":
                            old = item.get("oldText", "")
                            new = item.get("newText", "")
                            if new:
                                desc_parts.append(f"+ {new.strip()}")
                            if old:
                                desc_parts.append(f"- {old.strip()}")
                        elif item_type == "content":
                            ic = item.get("content", {})
                            if isinstance(ic, dict):
                                desc_parts.append(ic.get("text", ""))
                        elif "text" in item:
                            desc_parts.append(item["text"])
                # If no description from content, use raw arguments
                if not desc_parts and tool_args:
                    desc_parts.append(json.dumps(tool_args, indent=2, default=str)[:2000])

                log.info("Forwarding permission request %s to browser: %s", msg_id, call_title)

                # Yield a permission event to the browser
                self._pending_permissions.add(msg_id)
                # Store the offered options so /session/permission can validate
                self._permission_options[msg_id] = {
                    opt.get("optionId", "") for opt in options
                    if isinstance(opt, dict)
                }
                yield {
                    "type": "permission",
                    "permission_id": msg_id,
                    "title": call_title,
                    "kind": call_kind,
                    "description": "\n".join(desc_parts),
                    "options": options,
                }
                continue

            # Handle fs/* and terminal/* requests
            if msg_id is not None and method and "/" in method:
                log.warning("Unhandled ACP method in stream: %s (id=%s)", method, msg_id)
                self._send_jsonrpc_error(msg_id, -32601, f"Method '{method}' not supported")
                continue

            # Look for our response (has matching id)
            if msg_id == request_id:
                self._pending_permissions.clear()
                if "error" in msg:
                    log.error("ACP error: %s", msg["error"])
                    yield {
                        "type": "error",
                        "error": str(msg["error"]),
                        "text": accumulated_text,
                        "reasoning": accumulated_reasoning,
                    }
                else:
                    log.info("Got final response for request %d", request_id)
                    yield {
                        "type": "done",
                        "text": accumulated_text,
                        "reasoning": accumulated_reasoning,
                        "result": msg.get("result", {}),
                    }
                done = True

        if not done:
            # Loop exited without a final response — process died or user aborted.
            self._pending_permissions.clear()


# Singleton
acp = ACPProcess()

# ---------------------------------------------------------------------------
# FastAPI endpoints
# ---------------------------------------------------------------------------

@app.on_event("startup")
def startup():
    """Start the ACP subprocess on boot."""
    try:
        acp.start()
        log.info("=== ACP Bridge started on port %d ===", PORT)
    except Exception as e:
        log.error("Failed to start ACP bridge: %s", e, exc_info=True)
        raise


@app.get("/health")
def health():
    """Health check — does NOT block on the prompt lock."""
    if acp.proc and acp.proc.poll() is None:
        return {"status": "ok", "acp_running": True}
    return {"status": "degraded", "acp_running": False}


@app.get("/status")
def status():
    """Detailed status including prompt lock state."""
    lock_locked = acp._prompt_lock.locked()
    acp_alive = acp.proc is not None and acp.proc.poll() is None
    return {
        "status": "ok" if acp_alive else "degraded",
        "acp_running": acp_alive,
        "prompt_in_progress": lock_locked,
        "sessions": len(acp._sessions),
        "pid": os.getpid(),
    }


@app.post("/session/new")
async def session_new(request: Request):
    """Create a new ACP session for a conversation."""
    try:
        body = await request.json()
    except Exception:
        body = {}

    session_id = acp.create_session(cwd=body.get("cwd"))
    moodle_conv_id = body.get("conversationid", str(uuid.uuid4())[:8])
    acp._sessions[moodle_conv_id] = session_id
    log.info("Session mapping: moodle=%s -> acp=%s", moodle_conv_id, session_id)

    return {
        "session_id": session_id,
        "moodle_conv_id": moodle_conv_id,
    }


@app.post("/session/prompt")
async def session_prompt(request: Request):
    """Send a prompt and stream response as SSE."""
    try:
        body = await request.json()
    except Exception:
        body = {}

    conversationid = body.get("conversationid", "")
    prompt_text = body.get("message", "")
    system_prompt = body.get("system_prompt", "")
    messages = body.get("messages", [])
    stored_acp_session_id = body.get("acp_session_id", "")

    # Moodle user identity — written to a file so the moodle-bridge plugin can
    # read it during tool calls. The shared Hermes subprocess is single-threaded
    # (prompts are serialized via _prompt_lock), so a file is safe here.
    moodle_username = body.get("moodle_username", "")
    moodle_userid = body.get("moodle_userid", "")
    if moodle_username:
        identity_file = Path(HERMES_HOME_ENV) / ".moodle_identity"
        try:
            identity_file.write_text(
                json.dumps({"username": moodle_username, "userid": moodle_userid})
            )
        except Exception as exc:
            log.warning("Failed to write moodle identity file: %s", exc)

    log.info("=== New prompt: conversationid=%s, prompt_len=%d ===", conversationid, len(prompt_text))

    # Get or create ACP session for this conversation
    is_new_session = False
    if conversationid not in acp._sessions:
        # No in-memory mapping — bridge was restarted or this is a new conversation.
        # Always create a fresh ACP session. We don't use session/load because
        # hermes acp often can't recreate the agent for old sessions.
        # Instead, if there's conversation history, it will be included in the
        # first prompt so the agent has context.
        acp_session_id = acp.create_session()
        acp._sessions[conversationid] = acp_session_id
        is_new_session = True
        log.info("Created new ACP session %s for conversation %s (is_new=%s)",
                 acp_session_id, conversationid, is_new_session)
    else:
        acp_session_id = acp._sessions[conversationid]
        log.info("Reusing ACP session %s for conversation %s", acp_session_id, conversationid)

    # Build prompt text — include system prompt.
    # On new sessions (bridge restart or first message), include conversation
    # history so the agent has context. On subsequent prompts, the ACP session
    # maintains history internally with automatic compaction.
    # History is limited to recent messages by api.php (MAX_HISTORY_MESSAGES)
    # to avoid context window overflow on long conversations.
    if system_prompt and is_new_session and messages:
        MAX_HISTORY_CHARS = 50000  # Safety net: ~12K tokens
        history_text = ""
        truncated = False
        for m in messages:
            role = m.get("role", "")
            content = m.get("content", "")
            entry = f"{'User' if role == 'user' else 'Assistant'}: {content}\n\n"
            if len(history_text) + len(entry) > MAX_HISTORY_CHARS:
                truncated = True
                break
            history_text += entry
        if truncated:
            history_text = f"[Note: earlier messages omitted — showing most recent {len(history_text.split('User:'))-1 + len(history_text.split('Assistant:'))-2} messages]\n\n" + history_text
        full_prompt = f"[SYSTEM]\n{system_prompt}\n\n[/SYSTEM]\n\n[CONVERSATION HISTORY]\n{history_text}[/CONVERSATION HISTORY]\n\n{prompt_text}"
    elif system_prompt:
        full_prompt = f"[SYSTEM]\n{system_prompt}\n\n[/SYSTEM]\n\n{prompt_text}"
    else:
        full_prompt = prompt_text

    log.info("Sending full prompt (len=%d) to ACP session %s", len(full_prompt), acp_session_id)

    # Create abort event for this conversation
    abort_event = threading.Event()
    acp._abort_events[conversationid] = abort_event

    def event_generator():
        aborted = False
        # Serialize prompts: hermes acp is a single stdio process. Only one
        # prompt can read from the shared inbox at a time, or chunks mix.
        # The lock is released when the generator is exhausted or GC'd.
        acquired = acp._prompt_lock.acquire(blocking=True, timeout=300)
        if not acquired:
            data = {"type": "error", "error": "Another request is in progress (timeout waiting for lock)"}
            yield f"event: error\\ndata: {json.dumps(data)}\\n\\n"
            return
        try:
            for event in acp.send_prompt_streaming(acp_session_id, full_prompt, abort_event=abort_event):
                # Check if user requested abort
                if abort_event.is_set() and not aborted:
                    aborted = True
                    log.info("User requested abort for conversation %s", conversationid)
                    # Drain any messages already in the inbox so the next
                    # prompt doesn't pick up leftover chunks from this one.
                    # hermes acp doesn't support session/cancel, so it keeps
                    # running in the background, but send_prompt_streaming
                    # filters by session_id so stale messages from the old
                    # session won't be processed by the next prompt.
                    drained = 0
                    while True:
                        try:
                            acp.inbox.get_nowait()
                            drained += 1
                        except queue.Empty:
                            break
                    log.info("Drained %d stale messages from inbox after abort", drained)
                    data = {"type": "aborted", "message": "Response stopped by user"}
                    yield f"event: aborted\ndata: {json.dumps(data)}\n\n"
                    return

                event_type = event.get("type", "unknown")
                log.debug("Event type: %s", event_type)

                if event_type == "keepalive":
                    # SSE comment — keeps the connection alive through K8s ingress
                    # proxy_read_timeout (60s default) during long waits (e.g. permission
                    # approval).  Comments are ignored by EventSource listeners.
                    yield ": keepalive\n\n"
                    continue

                if event_type == "message":
                    text = event.get("delta", "")
                    full = event.get("full", "")
                    data = {
                        "delta": text,
                        "full": full,
                        "type": "message",
                        "session_id": acp_session_id,
                    }
                    yield f"data: {json.dumps(data)}\n\n"

                elif event_type == "reasoning":
                    text = event.get("delta", "")
                    full = event.get("full", "")
                    data = {
                        "delta": text,
                        "full": full,
                        "type": "reasoning",
                        "session_id": acp_session_id,
                    }
                    yield f"data: {json.dumps(data)}\n\n"

                elif event_type == "permission":
                    # Forward permission request to browser for approval
                    perm_id = event.get("permission_id")
                    title = event.get("title", "Unknown tool")
                    desc = event.get("description", "")
                    kind = event.get("kind", "execute")
                    log.info("Forwarding permission request %s: %s", perm_id, title)
                    data = {
                        "type": "permission",
                        "permission_id": perm_id,
                        "title": title,
                        "description": desc,
                        "kind": kind,
                        "session_id": acp_session_id,
                    }
                    yield f"event: permission\ndata: {json.dumps(data)}\n\n"

                elif event_type == "done":
                    # Content and reasoning were already streamed via session/update chunks.
                    # Just signal completion — do NOT re-send content.
                    log.info("Sent done event")
                    data = {
                        "type": "done",
                        "session_id": acp_session_id,
                    }
                    yield f"event: done\ndata: {json.dumps(data)}\n\n"

                elif event_type == "error":
                    text = event.get("text", "")
                    error = event.get("error", "Unknown error")
                    log.error("ACP error: %s", error)

                    if text:
                        data = {
                            "delta": text,
                            "full": text,
                            "type": "message",
                        }
                        yield f"data: {json.dumps(data)}\n\n"

                    data = {
                        "type": "error",
                        "error": error,
                    }
                    yield f"event: error\ndata: {json.dumps(data)}\n\n"

                elif event_type == "timeout":
                    text = event.get("text", "")
                    reasoning = event.get("reasoning", "")
                    log.warning("ACP timed out, partial text=%d, reasoning=%d", len(text), len(reasoning))

                    if text:
                        data = {"delta": text, "full": text, "type": "message"}
                        yield f"data: {json.dumps(data)}\n\n"

                    data = {"type": "timeout", "error": "Request timed out"}
                    yield f"event: timeout\ndata: {json.dumps(data)}\n\n"

        except Exception as e:
            log.error("Event generator error: %s", e, exc_info=True)
            data = {"type": "error", "error": str(e)}
            yield f"data: {json.dumps(data)}\\n\\n"
        finally:
            acp._prompt_lock.release()

    return StreamingResponse(
        event_generator(),
        media_type="text/event-stream",
        headers={
            "Cache-Control": "no-cache",
            "Connection": "keep-alive",
            "X-Accel-Buffering": "no",
        },
    )


@app.post("/session/permission")
async def session_permission(request: Request):
    """Respond to a permission request — approve or reject.

    Body fields:
        permission_id: int  — the JSON-RPC id from session/request_permission
        outcome: str        — one of "allow_once" (default), "allow_session",
                              "allow_always", "deny"

    For backwards compatibility, ``approved: true`` maps to ``allow_once``
    and ``approved: false`` maps to ``deny``.
    """
    try:
        body = await request.json()
    except Exception:
        body = {}

    permission_id = body.get("permission_id")
    if permission_id is None:
        return {"status": "error", "message": "permission_id required"}

    if not acp.proc or acp.proc.poll() is not None:
        return {"status": "error", "message": "ACP process not running"}

    if permission_id not in acp._pending_permissions:
        return {"status": "error", "message": "This permission request has expired — the agent may have timed out or moved on. Please send a new message to continue."}

    # Resolve the outcome: explicit outcome string > approved bool > default
    outcome_str = body.get("outcome")
    if outcome_str is None:
        outcome_str = "allow_once" if body.get("approved", False) else "deny"

    # Validate the outcome against the options the ACP actually offered.
    # Edit approvals (write_file/patch) only offer allow_once + deny;
    # sending allow_session/allow_always there would be rejected by the ACP.
    offered = acp._permission_options.get(permission_id, set())
    if outcome_str != "deny" and offered and outcome_str not in offered:
        allowed = ", ".join(sorted(offered - {"deny"})) or "none"
        return {
            "status": "error",
            "message": f"This permission request only supports: {allowed}. "
                       f"Requested '{outcome_str}' is not available for this tool.",
        }

    # Map outcome to ACP JSON-RPC result
    if outcome_str in ("allow_once", "allow_session", "allow_always"):
        result = {"outcome": {"outcome": "selected", "optionId": outcome_str}}
        log.info("Permission %s %s by user", permission_id, outcome_str)
    else:
        result = {"outcome": {"outcome": "cancelled"}}
        log.info("Permission %s denied by user", permission_id)

    response = {
        "jsonrpc": "2.0",
        "id": permission_id,
        "result": result,
    }
    acp._send_jsonrpc(response)
    acp._pending_permissions.discard(permission_id)
    acp._permission_options.pop(permission_id, None)
    return {"status": "ok", "outcome": outcome_str}


@app.get("/sessions")
def list_sessions():
    """List active sessions (debug)."""
    return {
        "sessions": acp._sessions,
        "acp_running": acp.proc is not None and acp.proc.poll() is None,
    }


@app.post("/session/abort")
async def session_abort(request: Request):
    """Abort the current streaming response for a conversation."""
    try:
        body = await request.json()
    except Exception:
        body = {}

    conversationid = body.get("conversationid", "")
    if conversationid in acp._abort_events:
        acp._abort_events[conversationid].set()
        log.info("Abort signal sent for conversation %s", conversationid)
        return {"status": "ok", "aborted": True}
    return {"status": "ok", "aborted": False, "message": "No active stream for this conversation"}


if __name__ == "__main__":
    log.info("Starting ACP Bridge on port %d...", PORT)
    uvicorn.run(app, host="127.0.0.1", port=PORT, log_level="debug")
