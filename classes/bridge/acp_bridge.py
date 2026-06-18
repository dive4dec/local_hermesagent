#!/usr/bin/env python3
import os
import json
import uuid
import asyncio
import subprocess
import sys
from typing import Dict
from fastapi import FastAPI, Request, HTTPException
from fastapi.responses import StreamingResponse
import uvicorn

HERMES_HOME = os.environ.get("HERMES_HOME", "/var/www/moodledata/.hermes")
PORT = int(os.environ.get("BRIDGE_PORT", "9118"))

app = FastAPI()

class ACPSession:
    def __init__(self, sid: str):
        self.sid = sid
        self.acp_session_id = None
        cmd = [f"{HERMES_HOME}/venv/bin/hermes", "acp", "--accept-hooks"]

        env = {
            "OPENAI_API_KEY": "sk-09GH_GyHVObhjBuMN2AmqA",
            "OPENAI_BASE_URL": "https://socratic.cs.cityu.edu.hk/litellm/v1",
            "OPENAI_API_BASE": "https://socratic.cs.cityu.edu.hk/litellm/v1",
            "HERMES_MODEL": "Socrates",
            "OPENROUTER_API_KEY": "sk-09GH_GyHVObhjBuMN2AmqA",
            "DISABLE_OPENROUTER": "true"
        }

        self.proc = subprocess.Popen(
            cmd,
            stdin=subprocess.PIPE,
            stdout=subprocess.PIPE,
            stderr=sys.stderr,
            text=True,
            bufsize=1,
            cwd=HERMES_HOME,
            env=env
        )
        self.req_id = 1
        print(f"[{sid}] ACP Process started with PID: {self.proc.pid}", flush=True)

    async def send_rpc(self, method: str, params: dict):
        payload = {"jsonrpc": "2.0", "id": self.req_id, "method": method, "params": params}
        self.req_id += 1
        print(f"[SEND] {json.dumps(payload)}", flush=True)
        self.proc.stdin.write(json.dumps(payload) + "\n")
        self.proc.stdin.flush()

    async def read_response(self):
        while True:
            line = await asyncio.to_thread(self.proc.stdout.readline)
            if not line:
                return None
            line_str = line.strip()
            if not line_str:
                continue
            if not line_str.startswith("{"):
                print(f"[NOISY STDOUT IGNORED] {line_str}", flush=True)
                continue

            print(f"[RECV JSON] {line_str}", flush=True)
            try:
                return json.loads(line_str)
            except Exception as e:
                return {"error": {"message": f"JSON Decode Error: {e} | Raw: {line_str}"}}

sessions: Dict[str, ACPSession] = {}

@app.get("/health")
def health():
    return {"status": "ok"}

@app.get("/status")
def status():
    return {"status": "running"}

@app.get("/session/{sid}/info")
def session_info(sid: str):
    if sid not in sessions:
        raise HTTPException(status_code=404, detail="Session not found")
    proc = sessions[sid].proc
    return {"sid": sid, "alive": proc.poll() is None}

@app.post("/session/create")
async def create_session():
    sid = str(uuid.uuid4())[:8]
    s = ACPSession(sid)
    sessions[sid] = s
    
    await s.send_rpc("initialize", {"protocolVersion": "1.0", "capabilities": {}})
    await s.read_response()
    
    await s.send_rpc("session/new", {"cwd": HERMES_HOME, "mcpServers": []})
    resp = await s.read_response()
    
    if resp and "result" in resp and isinstance(resp["result"], dict):
        s.acp_session_id = resp["result"].get("sessionId", resp["result"].get("session_id", sid))
        print(f"[{sid}] Successfully mapped to ACP Session ID: {s.acp_session_id}", flush=True)
    else:
        s.acp_session_id = sid
        
    return {"sid": sid}

@app.post("/session/{sid}/send")
async def send_message(sid: str, request: Request):
    if sid not in sessions: raise HTTPException(status_code=404)
    data = await request.json()
    s = sessions[sid]
    
    user_msg = data.get("message", "")
    prompt_payload = [{"type": "text", "text": user_msg}]
    
    await s.send_rpc("session/prompt", {
        "sessionId": s.acp_session_id,
        "session_id": s.acp_session_id, 
        "prompt": prompt_payload
    })
    
    async def stream():
        while True:
            r = await s.read_response()
            if not r: break
            
            print(f"[STREAM DEBUG] {r}", flush=True)
            
            if "error" in r:
                yield f"data: {json.dumps({'type':'error', 'data':r['error'].get('message')})}\n\n"
                break
            
            params = r.get("params", {})
            update = params.get("update", {})
            session_update = update.get("sessionUpdate", "")

            if session_update == "agent_message_chunk":
                delta_text = update.get("content", {}).get("text", "")
                if delta_text:
                    yield f"data: {json.dumps({'type':'message', 'data':{'delta': delta_text}})}\n\n"
            
            if "result" in r and isinstance(r["result"], dict):
                if r["result"].get("stopReason") == "end_turn":
                    yield f"data: {json.dumps({'type':'message', 'data':{'done':True}})}\n\n"
                    break
                
    return StreamingResponse(stream(), media_type="text/event-stream")

if __name__ == "__main__":
    uvicorn.run(app, host="0.0.0.0", port=PORT)