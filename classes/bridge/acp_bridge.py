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
ACP_TIMEOUT_SECONDS = float(os.environ.get("ACP_TIMEOUT", "300"))
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

    def start(self):
        """Start the hermes acp subprocess."""
        log.info("Starting hermes acp subprocess from %s", HERMES_BIN)
        env = os.environ.copy()
        env["HERMES_HOME"] = HERMES_HOME_ENV

        try:
            self.proc = __import__("subprocess").Popen(
                [str(HERMES_BIN), "acp"],
                stdin=__import__("subprocess").PIPE,
                stdout=__import__("subprocess").PIPE,
                stderr=__import__("subprocess").PIPE,
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
        """Handle notifications and session/update events. Returns True if consumed."""
        msg_id = msg.get("id")
        method = msg.get("method")

        # session/update notifications - these are the streaming chunks
        if method == "session/update":
            params = msg.get("params", {})
            update = params.get("update", {})
            kind = str(update.get("sessionUpdate", "")).strip()
            content = update.get("content", {})
            text = ""
            if isinstance(content, dict):
                text = str(content.get("text", ""))

            if kind == "agent_message_chunk" and text:
                if text_parts is not None:
                    text_parts.append(text)
                log.debug("agent_message_chunk: %s", text[:200])
            elif kind == "agent_thought_chunk" and text:
                if reasoning_parts is not None:
                    reasoning_parts.append(text)
                log.debug("agent_thought_chunk: %s", text[:200])
            return True

        # session/request_permission - auto-approve for trusted Moodle environment
        if method == "session/request_permission" and msg_id is not None:
            log.info("Auto-approving permission request %s", msg_id)
            response = {
                "jsonrpc": "2.0",
                "id": msg_id,
                "result": {"status": "accepted"},
            }
            self.proc.stdin.write(json.dumps(response) + "\n")
            self.proc.stdin.flush()
            log.info("Sent approval for permission %s", msg_id)
            return True

        # fs/* and terminal/* requests from ACP - respond with error if not handled
        if msg_id is not None and method and ("/" in method):
            log.warning("Unhandled ACP method: %s (id=%s)", method, msg_id)
            response = {
                "jsonrpc": "2.0",
                "id": msg_id,
                "error": {
                    "code": -32601,
                    "message": f"Method '{method}' not supported by this bridge",
                },
            }
            self.proc.stdin.write(json.dumps(response) + "\n")
            self.proc.stdin.flush()
            return True

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

    def send_prompt_streaming(self, session_id, prompt_text, timeout=None):
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

        deadline = time.monotonic() + timeout
        accumulated_text = ""
        accumulated_reasoning = ""
        done = False

        while time.monotonic() < deadline and not done:
            if self.proc.poll() is not None:
                stderr = "\n".join(self.stderr_tail[-20:])
                log.error("ACP process exited! stderr:\n%s", stderr)
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
                continue

            # Skip messages for other requests
            msg_id = msg.get("id")
            method = msg.get("method")

            # Handle session/update notifications
            if method == "session/update":
                params = msg.get("params", {})
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

            # Handle session/request_permission - auto-approve
            if method == "session/request_permission" and msg_id is not None:
                log.info("Auto-approving permission request %s", msg_id)
                response = {
                    "jsonrpc": "2.0",
                    "id": msg_id,
                    "result": {"status": "accepted"},
                }
                self.proc.stdin.write(json.dumps(response) + "\n")
                self.proc.stdin.flush()
                log.info("Sent approval for permission %s", msg_id)
                continue

            # Handle fs/* and terminal/* requests
            if msg_id is not None and method and "/" in method:
                log.warning("Unhandled ACP method: %s (id=%s)", method, msg_id)
                response = {
                    "jsonrpc": "2.0",
                    "id": msg_id,
                    "error": {
                        "code": -32601,
                        "message": f"Method '{method}' not supported by this bridge",
                    },
                }
                self.proc.stdin.write(json.dumps(response) + "\n")
                self.proc.stdin.flush()
                continue

            # Look for our response (has matching id)
            if msg_id == request_id:
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
            log.warning("Timed out waiting for prompt response")
            yield {
                "type": "timeout",
                "text": accumulated_text,
                "reasoning": accumulated_reasoning,
            }


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
    """Health check."""
    if acp.proc and acp.proc.poll() is None:
        return {"status": "ok", "acp_running": True}
    return {"status": "degraded", "acp_running": False}


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

    log.info("=== New prompt: conversationid=%s, prompt_len=%d ===", conversationid, len(prompt_text))

    # Get or create ACP session for this conversation
    if conversationid not in acp._sessions:
        acp_session_id = acp.create_session()
        acp._sessions[conversationid] = acp_session_id
        log.info("Created new ACP session %s for conversation %s", acp_session_id, conversationid)
    else:
        acp_session_id = acp._sessions[conversationid]
        log.info("Reusing ACP session %s for conversation %s", acp_session_id, conversationid)

    # Build prompt text — include system prompt as first message
    if system_prompt and messages:
        full_prompt = f"[SYSTEM]\n{system_prompt}\n\n[/SYSTEM]\n\n{prompt_text}"
    else:
        full_prompt = prompt_text

    log.info("Sending full prompt (len=%d) to ACP session %s", len(full_prompt), acp_session_id)

    def event_generator():
        try:
            for event in acp.send_prompt_streaming(acp_session_id, full_prompt):
                event_type = event.get("type", "unknown")
                log.info("Event type: %s", event_type)

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
            yield f"data: {json.dumps(data)}\n\n"

    return StreamingResponse(
        event_generator(),
        media_type="text/event-stream",
        headers={
            "Cache-Control": "no-cache",
            "Connection": "keep-alive",
            "X-Accel-Buffering": "no",
        },
    )


@app.get("/sessions")
def list_sessions():
    """List active sessions (debug)."""
    return {
        "sessions": acp._sessions,
        "acp_running": acp.proc is not None and acp.proc.poll() is None,
    }


if __name__ == "__main__":
    log.info("Starting ACP Bridge on port %d...", PORT)
    uvicorn.run(app, host="127.0.0.1", port=PORT, log_level="debug")
