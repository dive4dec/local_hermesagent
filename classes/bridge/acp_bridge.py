#!/usr/bin/env python3
"""
ACP Bridge — FastAPI server that connects Moodle to `hermes acp`.

Architecture:
  Moodle browser -> api.php -> acp_bridge.py (port 9118) -> hermes acp subprocess

Concurrency model (v2):
  A SINGLE long-lived `hermes acp` process serves MULTIPLE ACP sessions in
  parallel. The old bridge used one global `_prompt_lock` that serialized every
  prompt and a nuclear `restart()` on abort. This version:
    * runs concurrent prompts (different sessions) with no global lock;
    * serializes only per-ACP-session (two prompts on the same session);
    * aborts a session with `session/cancel` (proven: returns in ~0.3s,
      other sessions are untouched — no process restart);
    * scopes per-user identity to the ACP session id, so two admins chatting
      at once never cross-attribute uploads/courses. The ACP session id is the
      same value hermes binds as `HERMES_SESSION_KEY` (a per-session
      ContextVar) for in-process plugin tools and into the child env for
      terminal/skill processes.

The bridge speaks the official `acp` Python SDK (ClientSideConnection over
stdio), NOT hand-rolled JSON-RPC.
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
ACP_TIMEOUT_SECONDS = float(os.environ.get("ACP_TIMEOUT", "600"))  # 10 min — tool approval
KEEPALIVE_INTERVAL = float(os.environ.get("BRIDGE_KEEPALIVE", "15"))
PORT = int(os.environ.get("BRIDGE_PORT", "9118"))
DEFAULT_CWD = os.environ.get("ACP_CWD", "/var/www/html")
# Prune per-session identity files older than this (sessions die with the
# hermes acp process, so orphaned files are safe to remove).
IDENTITY_TTL = int(os.environ.get("IDENTITY_TTL", "7200"))  # 2h

app = FastAPI(title="Hermes ACP Bridge")


# ---------------------------------------------------------------------------
# Small helpers
# ---------------------------------------------------------------------------
async def _request_json(request: Request) -> dict:
    try:
        return await request.json()
    except Exception:
        return {}


def sse_data(payload: dict) -> str:
    """Plain `data:` SSE frame (no `event:` name). api.php keys off json['type']."""
    return "data: " + json.dumps(payload) + "\n\n"


def sse_named(name: str, payload: dict) -> str:
    """Named SSE frame: `event: <name>` + `data: <json>`."""
    return "event: " + name + "\ndata: " + json.dumps(payload) + "\n\n"


def _block_text(content) -> str:
    """Extract text from an ACP content block (single block or list of blocks).

    Handles the nested tool-call shape hermes actually emits:
        [ContentToolCallContent(content=TextContentBlock(text=...), type='content')]
    as well as a bare block / list of bare blocks.
    """
    if content is None:
        return ""
    if isinstance(content, str):
        return content
    if isinstance(content, (list, tuple)):
        return "".join(_block_text(b) for b in content)
    t = getattr(content, "text", None)
    if t:
        return t
    # Unwrap a ContentToolCallContent / similar wrapper whose payload lives in
    # its `.content` field (the real TextContentBlock is nested one level down).
    inner = getattr(content, "content", None)
    if inner is not None:
        return _block_text(inner)
    # Resource / embedded-resource fallback
    uri = getattr(content, "uri", None)
    return uri or ""


def _describe_tool_call(tc):
    """Build (title, kind, description) for a permission prompt from a ToolCall."""
    title = getattr(tc, "title", None) or "Unknown tool"
    kind = getattr(tc, "kind", None) or "execute"
    desc_parts = []
    content = getattr(tc, "content", None)
    if isinstance(content, list):
        for item in content:
            old = getattr(item, "old_text", None)
            new = getattr(item, "new_text", None)
            if new:
                desc_parts.append("+ " + str(new).strip())
            if old:
                desc_parts.append("- " + str(old).strip())
            txt = getattr(item, "text", None)
            if txt:
                desc_parts.append(str(txt))
    if not desc_parts:
        ri = getattr(tc, "raw_input", None)
        if ri:
            desc_parts.append(json.dumps(ri, indent=2, default=str)[:2000])
    return title, kind, "\n".join(desc_parts)


def _raw_io_text(value) -> str:
    """Render a tool's raw_input / raw_output (arbitrary JSON) as displayable
    text for the collapsible tool-call box. A dict of kwargs is shown as
    'key: value' lines (commands/paths readable at a glance); a list of
    arguments is shown as a space-joined command line; anything else falls
    back to compact JSON. Empty/None → '' so the frontend omits the section."""
    if value is None or value == "" or value == [] or value == {}:
        return ""
    if isinstance(value, str):
        return value
    if isinstance(value, list):
        # e.g. terminal command args: ["git", "status"] → "git status"
        parts = [str(v) for v in value]
        return " ".join(parts)
    if isinstance(value, dict):
        # e.g. {"command": "...", "workdir": "..."} or {"path": "...", "content": "..."}
        lines = []
        for k, v in value.items():
            if isinstance(v, (dict, list)):
                v = json.dumps(v, default=str)
            lines.append(f"{k}: {v}")
        return "\n".join(lines)
    try:
        return json.dumps(value, indent=2, default=str)
    except Exception:
        return str(value)


# ---------------------------------------------------------------------------
# ACP Client (the side of the protocol the bridge plays)
# ---------------------------------------------------------------------------
class BridgeAcpClient:
    """Implements the ACP `Client` interface. `hermes acp` (the agent) calls
    these over stdio; we route streaming updates to per-session queues and
    resolve permission requests by awaiting futures the HTTP endpoints set."""

    def __init__(self, mgr):
        self.mgr = mgr

    # -- streaming updates -------------------------------------------------
    async def session_update(self, session_id, update, **kwargs):
        self.mgr.dispatch_update(session_id, update)

    # -- permission --------------------------------------------------------
    async def request_permission(self, options, session_id, tool_call, **kwargs):
        return await self.mgr.handle_permission(options, session_id, tool_call)

    # -- filesystem (agent may delegate file reads/writes to the client) ---
    async def read_text_file(self, path, session_id, **kwargs):
        from acp.schema import ReadTextFileResponse
        try:
            with open(path, "r", encoding="utf-8") as f:
                return ReadTextFileResponse(content=f.read())
        except FileNotFoundError:
            from acp import RequestError
            raise RequestError.resource_not_found(path)
        except Exception as e:
            from acp import RequestError
            raise RequestError.internal_error({"path": path, "error": str(e)})

    async def write_text_file(self, content, path, session_id, **kwargs):
        from acp.schema import WriteTextFileResponse
        parent = os.path.dirname(path)
        if parent:
            os.makedirs(parent, exist_ok=True)
        with open(path, "w", encoding="utf-8") as f:
            f.write(content)
        return WriteTextFileResponse()

    # -- terminal (not delegated; hermes runs its own terminal tool) --------
    async def create_terminal(self, *args, **kwargs):
        from acp import RequestError
        raise RequestError.method_not_found("create_terminal")

    async def terminal_output(self, *args, **kwargs):
        from acp import RequestError
        raise RequestError.method_not_found("terminal_output")

    async def release_terminal(self, *args, **kwargs):
        return None

    async def wait_for_terminal_exit(self, *args, **kwargs):
        from acp import RequestError
        raise RequestError.method_not_found("wait_for_terminal_exit")

    async def kill_terminal(self, *args, **kwargs):
        return None

    # -- extension / connect ----------------------------------------------
    async def ext_method(self, method, params):
        from acp import RequestError
        raise RequestError.method_not_found(method)

    async def ext_notification(self, method, params):
        pass

    def on_connect(self, conn):
        pass


# ---------------------------------------------------------------------------
# ACP manager — owns ONE `hermes acp` process + a dedicated asyncio loop
# ---------------------------------------------------------------------------
class ACPManager:
    """Long-lived `hermes acp` process with a background event loop.

    Serves multiple ACP sessions concurrently. Same-session prompts are
    serialized with a per-session asyncio.Lock; different sessions run in
    parallel (proven against the live hermes acp).
    """

    def __init__(self):
        self._loop = None
        self._loop_thread = None
        self._boot_lock = threading.Lock()
        self._booted = False
        self._proc = None
        self._conn = None
        self._client = None

        self._sessions = {}          # moodle_conv_id -> acp_session_id
        self._session_locks = {}     # acp_session_id -> asyncio.Lock
        self._active_queue = {}      # acp_session_id -> queue.Queue (current prompt)

        self._perm_lock = threading.Lock()
        self._next_perm = 0
        self._pending_perms = {}     # perm_id -> {future, sid, options:set}
        self._acc = {}               # acp_session_id -> [text, reasoning]

    # -- background loop ---------------------------------------------------
    def _ensure_loop(self):
        if self._loop is not None and not self._loop.is_closed():
            return
        self._loop = asyncio.new_event_loop()
        self._loop_thread = threading.Thread(
            target=self._run_loop, daemon=True, name="acp-bridge-loop")
        self._loop_thread.start()

    def _run_loop(self):
        asyncio.set_event_loop(self._loop)
        self._loop.run_forever()

    def _run_async(self, coro, timeout=60):
        self._ensure_loop()
        fut = asyncio.run_coroutine_threadsafe(coro, self._loop)
        return fut.result(timeout)

    # -- boot: spawn + connect + initialize --------------------------------
    async def _boot(self):
        from acp import PROTOCOL_VERSION, connect_to_agent
        from acp.schema import (ClientCapabilities, FileSystemCapabilities,
                                Implementation)
        env = os.environ.copy()
        env["HERMES_HOME"] = HERMES_HOME_ENV
        self._proc = await asyncio.create_subprocess_exec(
            str(HERMES_BIN), "acp",
            stdin=asyncio.subprocess.PIPE,
            stdout=asyncio.subprocess.PIPE,
            stderr=asyncio.subprocess.PIPE,
            limit=50 * 1024 * 1024,
            start_new_session=True,
            env=env,
        )
        self._client = BridgeAcpClient(self)
        self._conn = connect_to_agent(
            self._client, self._proc.stdin, self._proc.stdout)
        await self._conn.initialize(
            PROTOCOL_VERSION,
            ClientCapabilities(
                fs=FileSystemCapabilities(read_text_file=True, write_text_file=True),
                terminal=False,
            ),
            Implementation(name="moodle-bridge", title="Moodle ACP Bridge", version="0.2.0"),
        )
        log.info("hermes acp spawned + ACP initialized (pid=%s)", self._proc.pid)

    def ensure_alive(self, timeout=45):
        """Boot (or re-boot after a crash) the hermes acp process.

        On reboot all prior ACP session ids are invalid, so we clear the
        moodle->acp session map (the next prompt creates a fresh session)."""
        self._ensure_loop()
        alive = (self._proc is not None and self._proc.returncode is None
                 and self._conn is not None)
        if alive:
            return
        with self._boot_lock:
            alive = (self._proc is not None and self._proc.returncode is None
                     and self._conn is not None)
            if alive:
                return
            self._sessions = {}
            self._session_locks = {}
            self._pending_perms = {}
            self._acc = {}
            self._active_queue = {}
            log.info("Booting hermes acp ...")
            self._run_async(self._boot(), timeout=timeout)
            self._booted = True

    @property
    def alive(self):
        return (self._proc is not None and self._proc.returncode is None)

    # -- sessions ----------------------------------------------------------
    def _acp_lock(self, sid):
        if sid not in self._session_locks:
            self._session_locks[sid] = asyncio.Lock()
        return self._session_locks[sid]

    def get_or_create_session(self, moodle_conv_id, cwd=None):
        """Return (acp_session_id, is_new) for a Moodle conversation."""
        self.ensure_alive()
        with self._boot_lock:
            sid = self._sessions.get(moodle_conv_id)
            if sid:
                return sid, False

        from acp.schema import TextContentBlock  # noqa: F401
        async def _new():
            sess = await self._conn.new_session(cwd=cwd or DEFAULT_CWD)
            return sess.session_id
        sid = self._run_async(_new(), timeout=60)
        with self._boot_lock:
            self._sessions[moodle_conv_id] = sid
        log.info("New ACP session %s for conversation %s", sid, moodle_conv_id)
        return sid, True

    # -- streaming update dispatch (runs on the asyncio loop) --------------
    def dispatch_update(self, session_id, update):
        from acp.schema import (AgentMessageChunk, AgentThoughtChunk,
                                ToolCallStart, ToolCallProgress)
        q = self._active_queue.get(session_id)
        if q is None:
            return  # no listener (e.g. cancelled) — drop
        kind = type(update).__name__

        if isinstance(update, AgentMessageChunk):
            text = _block_text(update.content)
            if not text:
                return
            acc = self._acc.setdefault(session_id, ["", ""])
            acc[0] += text
            q.put({"type": "message", "delta": text, "full": acc[0]})
        elif isinstance(update, AgentThoughtChunk):
            text = _block_text(update.content)
            if not text:
                return
            acc = self._acc.setdefault(session_id, ["", ""])
            acc[1] += text
            q.put({"type": "reasoning", "delta": text, "full": acc[1]})
        elif isinstance(update, (ToolCallStart, ToolCallProgress)):
            content = getattr(update, "content", None)
            content_text = _block_text(content).strip()
            is_start = isinstance(update, ToolCallStart)
            raw_in = getattr(update, "raw_input", None)
            raw_out = getattr(update, "raw_output", None)
            if is_start:
                # Command / arguments. hermes does NOT populate raw_input — it
                # carries the command in the *start* event's content text
                # (e.g. "$ echo x" for terminal, the write target for edit).
                # Prefer structured raw_input if a different agent ever sets it.
                input_text = _raw_io_text(raw_in) or content_text
                result_text = ""
            else:
                # Result. hermes carries it in the *progress* event's content
                # text (e.g. "terminal result\n- **output:** ... \n- **exit_code:** 0").
                result_text = _raw_io_text(raw_out) or content_text
                input_text = ""
            q.put({"type": "tool_call", "tool_call": {
                "title": getattr(update, "title", "") or "tool",
                "kind": getattr(update, "kind", "") or "other",
                "status": getattr(update, "status", "") or "pending",
                "toolcall_id": getattr(update, "tool_call_id", ""),
                "result_text": result_text,
                "input_text": input_text,
                "output_text": result_text,
                "session_update": "tool_call" if is_start else "tool_call_update",
            }})
        # other update types (plan, modes, usage, commands) — not forwarded

    # -- permission (runs on the asyncio loop) -----------------------------
    async def handle_permission(self, options, session_id, tool_call):
        from acp.schema import RequestPermissionResponse, AllowedOutcome, DeniedOutcome
        title, kind, desc = _describe_tool_call(tool_call)
        with self._perm_lock:
            self._next_perm += 1
            perm_id = self._next_perm
            fut = asyncio.get_running_loop().create_future()
            offered = {getattr(o, "option_id", "") for o in (options or [])}
            self._pending_perms[perm_id] = {"future": fut, "sid": session_id,
                                            "options": offered}
        q = self._active_queue.get(session_id)
        if q is not None:
            q.put({"type": "permission", "permission_id": perm_id, "title": title,
                   "description": desc, "kind": kind, "options": [
                       getattr(o, "option_id", "") for o in (options or [])]})
        try:
            selected = await asyncio.wait_for(fut, timeout=ACP_TIMEOUT_SECONDS)
        except asyncio.TimeoutError:
            return RequestPermissionResponse(outcome=DeniedOutcome(outcome="cancelled"))
        finally:
            with self._perm_lock:
                self._pending_perms.pop(perm_id, None)

        # The /session/permission endpoint already validated `selected` against
        # the offered options and applied the allow_once fallback before calling
        # resolve_permission(), so we just build the outcome here.
        if selected and selected != "deny":
            log.info("Permission %s approved (%s)", perm_id, selected)
            return RequestPermissionResponse(outcome=AllowedOutcome(option_id=selected, outcome="selected"))
        log.info("Permission %s denied by user", perm_id)
        return RequestPermissionResponse(outcome=DeniedOutcome(outcome="cancelled"))

    # -- prompt (scheduled on the loop; streams via queue) -----------------
    async def _run_prompt(self, sid, text, q):
        from acp.schema import TextContentBlock
        lock = self._acp_lock(sid)
        async with lock:
            self._acc[sid] = ["", ""]
            try:
                resp = await self._conn.prompt(
                    [TextContentBlock(text=text, type="text")], session_id=sid)
                sr = getattr(resp, "stop_reason", "end_turn")
                if sr == "cancelled":
                    q.put({"type": "aborted", "message": "Response stopped by user"})
                else:
                    q.put({"type": "done", "stop_reason": sr})
            except asyncio.CancelledError:
                q.put({"type": "aborted", "message": "Response stopped by user"})
                raise
            except Exception as e:
                log.exception("Prompt failed for session %s", sid)
                q.put({"type": "error", "error": str(e)})

    def start_prompt(self, sid, text):
        """Kick off a prompt for session `sid`; return its queue.Queue.

        The queue is where streaming updates + the terminal done/aborted/error
        event land. Callers read it in a generator thread."""
        self.ensure_alive()
        q = queue.Queue()
        self._active_queue[sid] = q
        self._ensure_loop()
        asyncio.run_coroutine_threadsafe(self._run_prompt(sid, text, q), self._loop)
        return q

    def resolve_permission(self, perm_id, option_id):
        """Set the user's decision for a pending permission (HTTP endpoint)."""
        with self._perm_lock:
            entry = self._pending_perms.get(perm_id)
        if not entry:
            return False
        fut = entry["future"]
        try:
            self._loop.call_soon_threadsafe(fut.set_result, option_id)
            return True
        except Exception as e:
            log.error("resolve_permission(%s) failed: %s", perm_id, e)
            return False

    def permission_exists(self, perm_id):
        with self._perm_lock:
            return perm_id in self._pending_perms

    def permission_options(self, perm_id):
        with self._perm_lock:
            e = self._pending_perms.get(perm_id)
            return set(e["options"]) if e else set()

    def cancel_session(self, sid):
        """Abort one session. NOT a process restart, so other sessions are safe.

        A bare ACP `session/cancel` is not enough: a prompt that is parked
        *waiting for permission approval* does not release on session/cancel, so
        it would hang at the gate until ACP_TIMEOUT_SECONDS. We therefore:
          1. deny any pending permission for this session (unblocks the
             requestPermission callback -> the tool is denied and the prompt
             completes instead of running unattended),
          2. send session/cancel (covers mid-generation prompts),
          3. drop the active-queue + accumulator entries so active_prompts is
             accurate even while the SSE client is still connected.
        """
        self._ensure_loop()
        if not self.alive:
            return False
        self._deny_permissions_for(sid)
        try:
            self._run_async(self._conn.cancel(sid), timeout=10)
        except Exception as e:
            log.warning("cancel(%s) failed: %s", sid, e)
        self._active_queue.pop(sid, None)
        self._acc.pop(sid, None)
        return True

    def _deny_permissions_for(self, sid):
        """Resolve (deny) every permission still awaiting user input for this
        session, so a stranded prompt can't keep running after the client left."""
        with self._perm_lock:
            ids = [pid for pid, e in self._pending_perms.items()
                   if e.get("sid") == sid]
        loop = self._loop
        for pid in ids:
            with self._perm_lock:
                entry = self._pending_perms.get(pid)
            if not entry:
                continue
            fut = entry["future"]
            if fut.done():
                continue
            if loop is not None:
                loop.call_soon_threadsafe(fut.set_result, "deny")
            else:
                fut.set_result("deny")
        if ids:
            log.info("Abort: denied %d pending permission(s) for session %s",
                     len(ids), sid)


# Singleton
acp = ACPManager()


# ---------------------------------------------------------------------------
# FastAPI endpoints (HTTP/SSE contract is unchanged from v1 — api.php works)
# ---------------------------------------------------------------------------
@app.get("/health")
def health():
    acp.ensure_alive()
    return {"status": "ok", "acp_running": acp.alive}


@app.get("/status")
def status():
    acp.ensure_alive()
    return {
        "status": "ok" if acp.alive else "degraded",
        "acp_running": acp.alive,
        "sessions": len(acp._sessions),
        "active_prompts": len(acp._active_queue),
        "pid": os.getpid(),
    }


@app.post("/session/new")
async def session_new(request: Request):
    body = await _request_json(request)
    moodle_conv_id = body.get("conversationid") or str(uuid.uuid4())[:8]
    sid, _ = acp.get_or_create_session(moodle_conv_id, cwd=body.get("cwd"))
    return {"session_id": sid, "moodle_conv_id": moodle_conv_id}


@app.post("/session/prompt")
async def session_prompt(request: Request):
    body = await _request_json(request)
    conversationid = body.get("conversationid", "")
    prompt_text = body.get("message", "")
    system_prompt = body.get("system_prompt", "")
    messages = body.get("messages", [])

    # 1) Scope this admin's identity to the ACP session (concurrency-safe).
    #    The ACP session id is the same value hermes binds as HERMES_SESSION_KEY
    #    for in-process plugin tools and into terminal/skill child env.
    moodle_username = body.get("moodle_username", "")
    moodle_userid = body.get("moodle_userid", "")
    msession = body.get("msession")  # dict from api.php (cookie + moodle_url)

    # Get / create the ACP session for this conversation.
    sid, is_new = acp.get_or_create_session(conversationid)
    _write_identity(sid, moodle_username, moodle_userid, msession)

    log.info("=== New prompt: conversationid=%s acp=%s new=%s len=%d ===",
             conversationid, sid, is_new, len(prompt_text))

    # 2) Build the prompt (history + system prompt on new sessions only).
    if system_prompt and is_new and messages:
        max_chars = 50000
        hist = ""
        truncated = False
        for m in messages:
            role = m.get("role", "")
            content = m.get("content", "")
            entry = f"{'User' if role == 'user' else 'Assistant'}: {content}\n\n"
            if len(hist) + len(entry) > max_chars:
                truncated = True
                break
            hist += entry
        if truncated:
            hist = f"[Note: earlier messages omitted]\n\n" + hist
        full = (f"[SYSTEM]\n{system_prompt}\n\n[/SYSTEM]\n\n"
                f"[CONVERSATION HISTORY]\n{hist}[/CONVERSATION HISTORY]\n\n{prompt_text}")
    elif system_prompt:
        full = f"[SYSTEM]\n{system_prompt}\n\n[/SYSTEM]\n\n{prompt_text}"
    else:
        full = prompt_text

    q = acp.start_prompt(sid, full)

    def event_generator():
        last_keep = time.monotonic()
        try:
            while True:
                try:
                    ev = q.get(timeout=0.5)
                except queue.Empty:
                    if time.monotonic() - last_keep >= KEEPALIVE_INTERVAL:
                        last_keep = time.monotonic()
                        yield ": keepalive\n\n"
                    continue
                ev_type = ev.get("type")
                if ev_type == "message":
                    yield sse_data({"delta": ev.get("delta", ""), "full": ev.get("full", ""),
                                    "type": "message", "session_id": sid})
                elif ev_type == "reasoning":
                    yield sse_data({"delta": ev.get("delta", ""), "full": ev.get("full", ""),
                                    "type": "reasoning", "session_id": sid})
                elif ev_type == "tool_call":
                    yield sse_named("tool_call", {"type": "tool_call",
                                                  "tool_call": ev.get("tool_call", {}),
                                                  "session_id": sid})
                elif ev_type == "permission":
                    log.info("Forwarding permission %s: %s", ev.get("permission_id"), ev.get("title"))
                    yield sse_named("permission", {
                        "type": "permission",
                        "permission_id": ev.get("permission_id"),
                        "title": ev.get("title", "Unknown tool"),
                        "description": ev.get("description", ""),
                        "kind": ev.get("kind", "execute"),
                        "session_id": sid,
                    })
                elif ev_type == "done":
                    yield sse_named("done", {"type": "done", "session_id": sid})
                    return
                elif ev_type == "aborted":
                    yield sse_named("aborted", {"type": "aborted",
                                                "message": ev.get("message", "Response stopped by user")})
                    return
                elif ev_type == "error":
                    yield sse_named("error", {"type": "error", "error": ev.get("error", "Unknown error")})
                    return
        except Exception as e:
            log.error("Event generator error: %s", e, exc_info=True)
            yield sse_data({"type": "error", "error": str(e)})
        finally:
            acp._active_queue.pop(sid, None)
            acp._acc.pop(sid, None)

    return StreamingResponse(
        event_generator(),
        media_type="text/event-stream",
        headers={"Cache-Control": "no-cache", "Connection": "keep-alive",
                 "X-Accel-Buffering": "no"},
    )


@app.post("/session/permission")
async def session_permission(request: Request):
    body = await _request_json(request)
    permission_id = body.get("permission_id")
    if permission_id is None:
        return {"status": "error", "message": "permission_id required"}
    if not acp.alive:
        return {"status": "error", "message": "ACP process not running"}
    if not acp.permission_exists(permission_id):
        return {"status": "error",
                "message": "This permission request has expired — the agent may have timed out or moved on. Please send a new message to continue."}

    outcome = body.get("outcome")
    if outcome is None:
        outcome = "allow_once" if body.get("approved", False) else "deny"

    # Validate against offered options; fall back to allow_once if not offered.
    offered = acp.permission_options(permission_id)
    if outcome != "deny" and offered and outcome not in offered:
        if "allow_once" in offered:
            log.info("Permission %s: '%s' not offered, falling back to allow_once",
                     permission_id, outcome)
            outcome = "allow_once"
        else:
            allowed = ", ".join(sorted(offered - {"deny"})) or "none"
            return {"status": "error",
                    "message": f"This permission request only supports: {allowed}. "
                               f"Requested '{outcome}' is not available for this tool."}

    ok = acp.resolve_permission(permission_id, outcome)
    if not ok:
        return {"status": "error", "message": "Failed to resolve permission"}
    log.info("Permission %s -> %s", permission_id, outcome)
    return {"status": "ok", "outcome": outcome}


@app.post("/session/abort")
async def session_abort(request: Request):
    """Abort one conversation's stream. session/cancel — NO process restart,
    so other admins' in-flight prompts keep streaming untouched."""
    body = await _request_json(request)
    conversationid = body.get("conversationid", "")
    with acp._boot_lock:
        sid = acp._sessions.get(conversationid)
    if not sid:
        return {"status": "ok", "aborted": False, "message": "No active stream for this conversation"}
    ok = acp.cancel_session(sid)
    log.info("Abort session %s (conversation %s) -> %s", sid, conversationid, ok)
    return {"status": "ok", "aborted": True}


@app.get("/sessions")
def list_sessions():
    with acp._boot_lock:
        sessions = dict(acp._sessions)
    return {"sessions": sessions, "acp_running": acp.alive}


# ---------------------------------------------------------------------------
# Per-session identity files (concurrency-safe — keyed by ACP session id)
# ---------------------------------------------------------------------------
def _identity_dir():
    d = Path(HERMES_HOME_ENV) / "run"
    d.mkdir(parents=True, exist_ok=True)
    try:
        d.chmod(0o770)
    except Exception:
        pass
    return d


def _write_identity(sid, username, userid, msession):
    """Write per-session identity files under $HERMES_HOME/run/identity/.

    * `<sid>.identity.json` — {username, userid}  -> read by moodle-bridge plugin
    * `<sid>.msession.json` — moodle cookie + url  -> read by moodle_quiz_audit skill
    Also write the legacy single .moodle_identity for backward compat (harmless;
    the plugin now prefers the per-session file)."""
    try:
        d = _identity_dir() / "identity"
        d.mkdir(parents=True, exist_ok=True)
        try:
            d.chmod(0o770)
        except Exception:
            pass
        if username:
            (d / f"{sid}.identity.json").write_text(
                json.dumps({"username": username, "userid": userid}))
            (d / f"{sid}.identity.json").chmod(0o660)
            # legacy single file (last-writer) for any old reader
            try:
                legacy = Path(HERMES_HOME_ENV) / ".moodle_identity"
                legacy.write_text(json.dumps({"username": username, "userid": userid}))
                legacy.chmod(0o660)
            except Exception:
                pass
        if isinstance(msession, dict) and msession.get("cookie_value"):
            (d / f"{sid}.msession.json").write_text(json.dumps(msession))
            (d / f"{sid}.msession.json").chmod(0o660)
    except Exception as e:
        log.warning("Failed to write per-session identity for %s: %s", sid, e)


def prune_stale_identity():
    """Remove per-session identity files older than IDENTITY_TTL (sessions die
    with the hermes acp process, so old files are orphaned)."""
    try:
        d = Path(HERMES_HOME_ENV) / "run" / "identity"
        if not d.is_dir():
            return
        now = time.time()
        for f in d.glob("*.json"):
            try:
                if now - f.stat().st_mtime > IDENTITY_TTL:
                    f.unlink()
            except Exception:
                pass
    except Exception:
        pass


# ---------------------------------------------------------------------------
# Startup / main
# ---------------------------------------------------------------------------
@app.on_event("startup")
def startup():
    log.info("Starting ACP Bridge (v2 concurrent) on port %d ...", PORT)
    try:
        acp.ensure_alive()
        log.info("=== ACP Bridge started on port %d ===", PORT)
    except Exception as e:
        log.error("Failed to start ACP bridge: %s", e, exc_info=True)
        raise
    # Background identity-file janitor (every 30 min)
    def _janitor():
        while True:
            time.sleep(1800)
            prune_stale_identity()
    threading.Thread(target=_janitor, daemon=True, name="identity-janitor").start()


if __name__ == "__main__":
    uvicorn.run(app, host="127.0.0.1", port=PORT, log_level="debug")
