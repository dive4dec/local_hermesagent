#!/usr/bin/env python3
import asyncio, json, aiohttp, logging, os, subprocess, sys, time, re

UPSTREAM = "https://socratic.cs.cityu.edu.hk/ai-test"
PORT = 9118
API_KEY="1d90785d9594f5001583f921c1878fb57d711b94ab774d3f8136631c6c253706"
HERMES_HOME = os.environ.get("HERMES_HOME", "/var/www/moodledata/.hermes")
MCP_SCRIPT = os.path.join(HERMES_HOME, "mcp_servers", "moodle_db_mcp.py")
MCP_PYTHON = os.path.join(HERMES_HOME, "venv", "bin", "python")
LOG_FILE = os.path.join(HERMES_HOME, "logs", "proxy.log")

logging.basicConfig(filename=LOG_FILE, level=logging.INFO,
    format="%(asctime)s %(levelname)s %(message)s")
log = logging.getLogger("proxy")

TOOLS = [
    {"type": "function", "function": {"name": "mcp_moodle_db_query",
        "description": "Run a safe read-only SQL query against the Moodle database. CRITICAL: You MUST provide the 'query' argument! Example: {\"query\": \"SELECT * FROM mdl_course LIMIT 5\"}. NEVER send empty arguments {}. Must be a single-line string.",
        "parameters": {"type": "object", "properties": {"query": {"type": "string", "description": "The exact SQL query to execute. MUST be on a single line."}}, "required": ["query"]}}},
    {"type": "function", "function": {"name": "mcp_moodle_db_list_tables",
        "description": "List all Moodle database tables with row counts",
        "parameters": {"type": "object", "properties": {}, "required": []}}},
    {"type": "function", "function": {"name": "mcp_moodle_db_describe_table",
        "description": "Show the structure of a specific table",
        "parameters": {"type": "object", "properties": {"table": {"type": "string"}}, "required": ["table"]}}},
    {"type": "function", "function": {"name": "mcp_moodle_db_schema_hints",
        "description": "Show key table descriptions to help construct queries",
        "parameters": {"type": "object", "properties": {}, "required": []}}}
]

# MCP tool execution
async def exec_mcp_tool(tool_name, arguments):
    mcp_name = tool_name
    if mcp_name.startswith("mcp_moodle_db_"):
        mcp_name = mcp_name[len("mcp_moodle_db_"):]
    init_msg = {"jsonrpc": "2.0", "id": 1, "method": "initialize",
        "params": {"protocolVersion": "2024-11-05", "capabilities": {},
                    "clientInfo": {"name": "hermes-proxy", "version": "1.0"}}}
    call_msg = {"jsonrpc": "2.0", "id": 2, "method": "tools/call",
        "params": {"name": mcp_name, "arguments": arguments}}
    try:
        log.info("exec_mcp_tool: spawning %s", MCP_SCRIPT)
        proc = await asyncio.create_subprocess_exec(
            MCP_PYTHON, MCP_SCRIPT,
            stdin=asyncio.subprocess.PIPE, stdout=asyncio.subprocess.PIPE,
            stderr=asyncio.subprocess.PIPE, limit=1048576)
        
        # Send messages one at a time with proper flushing
        init_line = json.dumps(init_msg) + "\n"
        proc.stdin.write(init_line.encode())
        await proc.stdin.drain()
        
        init_resp = await asyncio.wait_for(proc.stdout.readline(), timeout=10)
        log.info("exec_mcp_tool: got initialize response (%d bytes)", len(init_resp))
        
        init_done = json.dumps({"jsonrpc": "2.0", "method": "notifications/initialized"}) + "\n"
        proc.stdin.write(init_done.encode())
        await proc.stdin.drain()
        
        call_line = json.dumps(call_msg) + "\n"
        proc.stdin.write(call_line.encode())
        await proc.stdin.drain()
        
        call_resp = await asyncio.wait_for(proc.stdout.readline(), timeout=30)
        log.info("exec_mcp_tool: got tools/call response (%d bytes)", len(call_resp))
        
        proc.stdin.close()
        try:
            await proc.wait()
        except:
            pass
        
        resp_text = call_resp.decode().strip()
        if not resp_text:
            log.warning("exec_mcp_tool: empty response")
            return {"error": "No result"}
        
        try:
            data = json.loads(resp_text)
            log.info("exec_mcp_tool: got response id=%s", data.get("id"))
            if data.get("id") == 2:
                result = data.get("result", {})
                for c in result.get("content", []):
                    if c.get("type") == "text":
                        try:
                            log.info("exec_mcp_tool: returning parsed text result")
                            return json.loads(c["text"])
                        except:
                            log.info("exec_mcp_tool: returning raw text result")
                            return {"result": c["text"]}
                if "error" in result:
                    log.info("exec_mcp_tool: returning error result: %s", result["error"])
                    return {"error": str(result["error"])}
        except json.JSONDecodeError:
            log.warning("exec_mcp_tool: invalid JSON: %s", resp_text[:200])
        
        log.warning("exec_mcp_tool: No result found in response: %s", resp_text[:500])
        return {"error": "No result"}
    except asyncio.TimeoutError:
        log.error("exec_mcp_tool: timeout")
        proc.kill()
        await proc.wait()
        return {"error": "Timeout"}
    except Exception as e:
        log.error("MCP tool error: %s %s", tool_name, e)
        return {"error": str(e)}

def parse_tool_call(text):
    results = []
    for m in re.finditer(r"call:[\w_]+:([\w_]+)\s*(\{[^}]*(?:\{[^}]*\}[^}]*)*\})", text):
        name = m.group(1).strip()
        if not name or len(name) < 2:
            continue
        try:
            args = json.loads(m.group(2))
            if not isinstance(args, dict) or len(args) == 0:
                continue
            results.append({"name": name, "arguments": args})
        except:
            pass
    return results

async def handle(reader, writer):
    try:
        req_line = await asyncio.wait_for(reader.readline(), 300)
        if not req_line:
            writer.close(); return
        parts = req_line.decode().strip().split()
        if len(parts) < 2:
            writer.close(); return
        method, http_path = parts[0], parts[1]
        
        if http_path == "/health":
            writer.write(b"HTTP/1.1 200 OK\r\nContent-Type: application/json\r\n\r\n{\"status\":\"ok\"}")
            await writer.drain(); writer.close(); return
        
        headers = {}; cl = 0
        while True:
            ln = await asyncio.wait_for(reader.readline(), 300)
            if ln in (b"\r\n", b"\n", b""):
                break
            if ":" not in ln.decode():
                break
            k, v = ln.decode().strip().split(":", 1)
            headers[k.strip().lower()] = v.strip()
            if k.strip().lower() == "content-length":
                cl = int(v.strip())
        
        body = b""
        if cl > 0:
            body = await asyncio.wait_for(reader.read(cl), 300)
        
        body_dict = json.loads(body) if body else {}
        body_dict.setdefault("stream", True)
        body_dict.setdefault("tools", TOOLS)
        body_dict.setdefault("tool_choice", "auto")
        
        log.info("Request: %d msgs, tools=auto", len(body_dict.get("messages", [])))
        
        upstream = UPSTREAM + http_path
        async with aiohttp.ClientSession() as sess:
            uh = {"Content-Type": "application/json"}
            uh["Authorization"] = headers.get("authorization") or ("Bearer " + API_KEY)
            async with sess.post(upstream, json=body_dict, headers=uh,
                    timeout=aiohttp.ClientTimeout(total=300)) as resp:
                writer.write(b"HTTP/1.1 " + str(resp.status).encode() + b" OK\r\n")
                writer.write(b"Content-Type: text/event-stream\r\n")
                writer.write(b"Cache-Control: no-cache\r\n")
                writer.write(b"Connection: keep-alive\r\n\r\n")
                await writer.drain()
                
                session_id = None
                accumulated_content = ''  
                accumulated_reasoning = ''  
                tool_results = []  
                tool_call_messages = []  
                has_final_content = False  
                in_tool_result = False  
                first_pass_tc = {}  

                while True:
                    chunk = await asyncio.wait_for(resp.content.readline(), 300)
                    if not chunk:
                        break
                    text = chunk.decode("utf-8", errors="replace").strip()
                    
                    if text.startswith("data: "):
                        payload = text[6:]
                        if payload == "[DONE]":
                            
                            for tc_idx in sorted(first_pass_tc.keys()):
                                pt = first_pass_tc[tc_idx]
                                tc_name = pt["name"]
                                tc_args_raw = pt["args_json"]
                                if not tc_name: 
                                    continue
                                try:
                                    tc_args = json.loads(tc_args_raw) if tc_args_raw.strip() else {}
                                except json.JSONDecodeError as e:
                                    log.warning("JSON parsing error: %s for args: %s", e, tc_args_raw)
                                    tc_args = {}
                                
                                tool_def = next((t for t in TOOLS if t["type"] == "function" and t["function"]["name"] == tc_name), None)
                                required_args = tool_def["function"]["parameters"].get("required", []) if tool_def else []
                                if not tc_args and required_args:
                                    log.warning("Skipping tool call '%s' with empty args. Raw string was: %s", tc_name, tc_args_raw)
                                    continue
                                
                                result = await exec_mcp_tool(tc_name, tc_args)
                                if not result or (isinstance(result, dict) and result.get("error")):
                                    continue
                                
                                tool_results.append({"name": tc_name, "result": result})
                                tool_call_messages.append({"id": "call_1", "type": "function", "function": {"name": tc_name, "arguments": tc_args_raw}})
                                in_tool_result = True
                                
                                tool_evt = {"tool_call": {"id": "call_1", "name": tc_name, "input": tc_args, "result": result, "status": "completed"}}
                                writer.write(("data: " + json.dumps(tool_evt) + "\n\n").encode())
                                await writer.drain()
                                
                                rt_display = str(result.get("count", len(result))) + " rows/keys returned" if isinstance(result, dict) else str(result)[:200]
                                if isinstance(result, dict) and "error" in result: rt_display = "Error: " + str(result["error"])
                                tr_str = "data: " + json.dumps({"delta": "\n🔧 Tool: " + tc_name + " -> " + rt_display + "]\n\n", "session_id": session_id}) + "\n\n"
                                writer.write(tr_str.encode())
                                await writer.drain()
                                
                            first_pass_tc.clear() 

                            MAX_TOOL_ITERATIONS = 5
                            iteration = 0
                            iter_produced_content = []  
                            while tool_results and not has_final_content and iteration < MAX_TOOL_ITERATIONS:
                                iteration += 1
                                log.info("MULTI-TURN: iteration %d, making API call with %d tool results", iteration, len(tool_results))
                                second_body = dict(body_dict)
                                second_body["messages"] = list(body_dict["messages"])
                                assistant_msg = {"role": "assistant", "content": accumulated_content, "tool_calls": tool_call_messages}
                                second_body["messages"].append(assistant_msg)
                                for tr in tool_results:
                                    second_body["messages"].append({
                                        "role": "tool",
                                        "tool_call_id": "call_1",
                                        "content": json.dumps(tr["result"], ensure_ascii=False)
                                    })
                                async with sess.post(upstream, json=second_body, headers=uh,
                                        timeout=aiohttp.ClientTimeout(total=300)) as resp2:
                                    pending_tc = {}  
                                    had_content = False
                                    finish_reason = None
                                    iter_content = ""  
                                    iter_reasoning = ""  
                                    while True:
                                        chunk2 = await asyncio.wait_for(resp2.content.readline(), 300)
                                        if not chunk2: break
                                        text2 = chunk2.decode("utf-8", errors="replace").strip()
                                        if text2.startswith("data: "):
                                            payload2 = text2[6:]
                                            if payload2 == "[DONE]": continue
                                            try:
                                                obj2 = json.loads(payload2)
                                                if obj2.get("choices"):
                                                    for c2 in obj2["choices"]:
                                                        d2 = c2.get("delta", {})
                                                        fr = c2.get("finish_reason")
                                                        if fr: finish_reason = fr
                                                        if d2.get("reasoning"):
                                                            iter_reasoning += d2["reasoning"]
                                                            rewrapped = {"delta": d2["reasoning"], "full": iter_reasoning, "type": "reasoning"}
                                                            if session_id: rewrapped["session_id"] = session_id
                                                            writer.write(("data: " + json.dumps(rewrapped) + "\n\n").encode())
                                                            await writer.drain()
                                                        if d2.get("content"):
                                                            if d2["content"].strip(): had_content = True
                                                            iter_content += d2["content"]
                                                            rewrapped = {"delta": d2["content"], "full": iter_content}
                                                            if session_id: rewrapped["session_id"] = session_id
                                                            writer.write(("data: " + json.dumps(rewrapped) + "\n\n").encode())
                                                            await writer.drain()
                                                        for tc2 in d2.get("tool_calls", []):
                                                            tc2_idx = tc2.get("index", 0)
                                                            tc2_name_delta = tc2.get("function", {}).get("name", "")
                                                            tc2_args_delta = tc2.get("function", {}).get("arguments", "")
                                                            if tc2_idx not in pending_tc:
                                                                pending_tc[tc2_idx] = {"name": tc2_name_delta, "args_json": tc2_args_delta}
                                                            else:
                                                                if tc2_name_delta: pending_tc[tc2_idx]["name"] = tc2_name_delta
                                                                pending_tc[tc2_idx]["args_json"] += tc2_args_delta
                                            except: pass
                                    
                                    if finish_reason == "tool_calls" and pending_tc:
                                        log.info("MULTI-TURN[%d]: finish=tool_calls, executing %d accumulated calls", iteration, len(pending_tc))
                                        more_tool_calls = []
                                        more_tool_results = []
                                        for tc_idx in sorted(pending_tc.keys()):
                                            pt = pending_tc[tc_idx]
                                            tc2_name = pt["name"]
                                            tc2_args_raw = pt["args_json"]
                                            if not tc2_name: continue
                                            try:
                                                tc2_args = json.loads(tc2_args_raw) if tc2_args_raw.strip() else {}
                                            except json.JSONDecodeError as e:
                                                log.warning("MULTI-TURN[%d]: bad JSON for %s: %s [%s]", iteration, tc2_name, str(e), tc2_args_raw[:200])
                                                tc2_args = {}
                                            tool_def = next((t for t in TOOLS if t["type"] == "function" and t["function"]["name"] == tc2_name), None)
                                            if not tool_def: continue
                                            required_args = tool_def["function"]["parameters"].get("required", [])
                                            if not tc2_args and required_args: continue
                                            
                                            result2 = await exec_mcp_tool(tc2_name, tc2_args)
                                            if not result2 or (isinstance(result2, dict) and result2.get("error")):
                                                result2 = {"error": str(result2) if result2 else "No result"}
                                            more_tool_results.append({"name": tc2_name, "result": result2})
                                            more_tool_calls.append({"id": "call_1", "type": "function", "function": {"name": tc2_name, "arguments": json.dumps(tc2_args)}})
                                            
                                            tool_evt = {"tool_call": {"id": "call_1", "name": tc2_name, "input": tc2_args, "result": result2, "status": "completed"}}
                                            writer.write(("data: " + json.dumps(tool_evt) + "\n\n").encode())
                                            await writer.drain()
                                            
                                            if isinstance(result2, dict):
                                                rt = str(result2.get("count", len(result2))) + " rows/keys"
                                                if "error" in result2: rt = "Error: " + str(result2["error"])
                                            else:
                                                rt = str(result2)[:200]
                                            td = {"delta": "\n🔧 Tool: " + tc2_name + " -> " + rt + "]\n\n"}
                                            if session_id: td["session_id"] = session_id
                                            writer.write(("data: " + json.dumps(td) + "\n\n").encode())
                                            await writer.drain()
                                            
                                        tool_results = more_tool_results
                                        tool_call_messages = more_tool_calls
                                        accumulated_content = ""
                                        continue  
                                    elif had_content:
                                        iter_produced_content.append(True)
                                        has_final_content = True
                                    else:
                                        iter_produced_content.append(False)
                                        has_final_content = True

                            if not any(iter_produced_content) and accumulated_content.strip() == '' and accumulated_reasoning.strip():
                                log.info("SAFETY NET: merging %d bytes of reasoning into content", len(accumulated_reasoning))
                                reason_evt = {"delta": accumulated_reasoning, "session_id": session_id}
                                writer.write(("data: " + json.dumps(reason_evt) + "\n\n").encode())
                                await writer.drain()
                                
                            writer.write(b"data: {\"done\":true}\n\n")
                            await writer.drain()
                            break
                        
                        try:
                            data = json.loads(payload)
                            if "id" in data and not session_id: session_id = data["id"]
                            
                            for choice in data.get("choices", []):
                                delta = choice.get("delta", {})
                                content = delta.get("content", "")
                                reasoning = delta.get("reasoning", "")
                                
                                for tc in delta.get("tool_calls", []):
                                    tc_idx = tc.get("index", 0)
                                    tc_name = tc.get("function", {}).get("name", "")
                                    tc_args_delta = tc.get("function", {}).get("arguments", "")
                                    
                                    if tc_idx not in first_pass_tc:
                                        first_pass_tc[tc_idx] = {"name": tc_name, "args_json": tc_args_delta}
                                    else:
                                        if tc_name:
                                            first_pass_tc[tc_idx]["name"] = tc_name
                                        first_pass_tc[tc_idx]["args_json"] += tc_args_delta

                                # Also check text content for tool calls (legacy format)
                                for tc in parse_tool_call(content):
                                    result = await exec_mcp_tool(tc["name"], tc["arguments"])
                                    if not result or isinstance(result, dict) and result.get("error"):
                                        continue
                                    tool_results.append({"name": tc["name"], "result": result})
                                    tool_call_messages.append({"id": "call_1", "type": "function", "function": {"name": tc["name"], "arguments": json.dumps(tc["arguments"])}})
                                    in_tool_result = True
                                    tool_evt = {"tool_call": {"id": "call_1", "name": tc["name"], "input": tc["arguments"], "result": result, "status": "completed"}}
                                    writer.write(("data: " + json.dumps(tool_evt) + "\n\n").encode())
                                    await writer.drain()
                                    rt_display = str(result)[:200] if result else "empty"
                                    result_delta = {"delta": "\n🔧 Tool: " + tc["name"] + " -> " + rt_display + "]\n\n"}
                                    if session_id: result_delta["session_id"] = session_id
                                    writer.write(("data: " + json.dumps(result_delta) + "\n\n").encode())
                                    await writer.drain()
                                
                                if content or reasoning:
                                    accumulated_content += content
                                    accumulated_reasoning += reasoning
                                    if in_tool_result and content:
                                        has_final_content = True
                                    transformed = {"delta": content}
                                    if reasoning: transformed["reasoning"] = reasoning
                                    if session_id: transformed["session_id"] = session_id
                                    writer.write(("data: " + json.dumps(transformed) + "\n\n").encode())
                                    await writer.drain()
                        
                        except json.JSONDecodeError:
                            writer.write(chunk)
                    else:
                        writer.write(chunk)
                    await writer.drain()
        
        writer.close()
    
    except Exception as e:
        log.error("Handler error: %s", e, exc_info=True)
        try:
            writer.write(("HTTP/1.1 500\r\n\r\n" + str(e)).encode())
            await writer.drain()
            writer.close()
        except: pass

async def main():
    srv = await asyncio.start_server(handle, "127.0.0.1", PORT)
    log.info("Proxy on 127.0.0.1:%d -> %s (tool-aware)", PORT, UPSTREAM)
    print("Proxy on 127.0.0.1:%d -> %s (tool-aware)" % (PORT, UPSTREAM), flush=True)
    async with srv:
        await srv.serve_forever()

if __name__ == "__main__":
    asyncio.run(main())