#!/usr/bin/env python3
"""Tool-aware HTTP proxy for Hermes ACP chat."""
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

# Tool definitions for the LLM
TOOLS = [
    {"type": "function", "function": {"name": "mcp_moodle_db_query",
        "description": "Run a safe read-only SQL query against the Moodle database.",
        "parameters": {"type": "object", "properties": {"query": {"type": "string"}}, "required": ["query"]}}},
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
    """Execute an MCP tool and return the result."""
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
        # 1. Send initialize
        init_line = json.dumps(init_msg) + "\n"
        proc.stdin.write(init_line.encode())
        await proc.stdin.drain()
        
        # Wait for initialize response
        init_resp = await asyncio.wait_for(proc.stdout.readline(), timeout=10)
        log.info("exec_mcp_tool: got initialize response (%d bytes)", len(init_resp))
        
        # 2. Send initialized notification
        init_done = json.dumps({"jsonrpc": "2.0", "method": "notifications/initialized"}) + "\n"
        proc.stdin.write(init_done.encode())
        await proc.stdin.drain()
        
        # 3. Send tools/call
        call_line = json.dumps(call_msg) + "\n"
        proc.stdin.write(call_line.encode())
        await proc.stdin.drain()
        
        # Wait for tools/call response
        call_resp = await asyncio.wait_for(proc.stdout.readline(), timeout=30)
        log.info("exec_mcp_tool: got tools/call response (%d bytes)", len(call_resp))
        
        # Close stdin to signal EOF
        proc.stdin.close()
        try:
            await proc.wait()
        except:
            pass
        
        # Parse the response
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
    """Parse tool calls like call:prefix:name{json} from text.
    Must match the specific pattern: call:[prefix]:[name]{JSON}
    where name is non-empty and JSON contains recognized keys."""
    results = []
    for m in re.finditer(r"call:[\w_]+:([\w_]+)\s*(\{[^}]*(?:\{[^}]*\}[^}]*)*\})", text):
        name = m.group(1).strip()
        if not name:
            continue
        if len(name) < 2:  # Skip very short names (likely false matches)
            continue
        try:
            args = json.loads(m.group(2))
            # Verify it looks like a real tool call (has at least one key)
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
                accumulated_content = ''  # Track actual content for safety net
                accumulated_reasoning = ''  # Track reasoning for safety net
                tool_results = []  # Track tool results for multi-turn
                tool_call_messages = []  # Track tool calls for multi-turn
                has_final_content = False  # Track if we got content after tool calls
                in_tool_result = False  # Track if we're processing tool results
                while True:
                    chunk = await asyncio.wait_for(resp.content.readline(), 300)
                    if not chunk:
                        break
                    text = chunk.decode("utf-8", errors="replace").strip()
                    
                    if text.startswith("data: "):
                        payload = text[6:]
                        if payload == "[DONE]":
                            # MULTI-TURN LOOP: Execute tools iteratively until the model produces content
                            MAX_TOOL_ITERATIONS = 5
                            iteration = 0
                            iter_produced_content = []  # Track if each iteration produced content
                            while tool_results and not has_final_content and iteration < MAX_TOOL_ITERATIONS:
                                iteration += 1
                                log.info("MULTI-TURN: iteration %d, making API call with %d tool results", iteration, len(tool_results))
                                # Build request with tool results appended
                                second_body = dict(body_dict)
                                second_body["messages"] = list(body_dict["messages"])
                                # Add assistant message with tool calls
                                assistant_msg = {"role": "assistant", "content": accumulated_content, "tool_calls": tool_call_messages}
                                second_body["messages"].append(assistant_msg)
                                # Add tool result messages
                                for tr in tool_results:
                                    second_body["messages"].append({
                                        "role": "tool",
                                        "tool_call_id": "call_1",
                                        "content": json.dumps(tr["result"], ensure_ascii=False)
                                    })
                                # Log messages
                                for i, msg in enumerate(second_body.get("messages", [])):
                                    role = msg.get("role", "unknown")
                                    content_len = len(msg.get("content", ""))
                                    has_tc = "tool_calls" in msg
                                    has_tcid = "tool_call_id" in msg
                                    log.info("MULTI-TURN[%d]: msg[%d] role=%s content_len=%d has_tool_calls=%s has_tool_call_id=%s",
                                             iteration, i, role, content_len, has_tc, has_tcid)
                                async with sess.post(upstream, json=second_body, headers=uh,
                                        timeout=aiohttp.ClientTimeout(total=300)) as resp2:
                                    # Accumulate tool call deltas across chunks (they stream incrementally)
                                    pending_tc = {}  # index -> {name, args_json}
                                    had_content = False
                                    finish_reason = None
                                    iter_content = ""  # Accumulate content for iteration 2
                                    iter_reasoning = ""  # Accumulate reasoning for iteration 2
                                    while True:
                                        chunk2 = await asyncio.wait_for(resp2.content.readline(), 300)
                                        if not chunk2:
                                            break
                                        text2 = chunk2.decode("utf-8", errors="replace").strip()
                                        if text2.startswith("data: "):
                                            payload2 = text2[6:]
                                            if payload2 == "[DONE]":
                                                continue
                                            try:
                                                obj2 = json.loads(payload2)
                                                if obj2.get("choices"):
                                                    for c2 in obj2["choices"]:
                                                        d2 = c2.get("delta", {})
                                                        fr = c2.get("finish_reason")
                                                        if fr:
                                                            finish_reason = fr
                                                        # Intercept content/reasoning deltas - re-wrap for JS
                                                        if d2.get("reasoning"):
                                                            iter_reasoning += d2["reasoning"]
                                                            rewrapped = {"delta": d2["reasoning"], "full": iter_reasoning, "type": "reasoning"}
                                                            if session_id:
                                                                rewrapped["session_id"] = session_id
                                                            writer.write(("data: " + json.dumps(rewrapped) + "\n\n").encode())
                                                            await writer.drain()
                                                        if d2.get("content"):
                                                            if d2["content"].strip():
                                                                had_content = True
                                                            iter_content += d2["content"]
                                                            rewrapped = {"delta": d2["content"], "full": iter_content}
                                                            if session_id:
                                                                rewrapped["session_id"] = session_id
                                                            writer.write(("data: " + json.dumps(rewrapped) + "\n\n").encode())
                                                            await writer.drain()
                                                        # Accumulate streaming tool call deltas
                                                        for tc2 in d2.get("tool_calls", []):
                                                            tc2_idx = tc2.get("index", 0)
                                                            tc2_name_delta = tc2.get("function", {}).get("name", "")
                                                            tc2_args_delta = tc2.get("function", {}).get("arguments", "")
                                                            if tc2_idx not in pending_tc:
                                                                pending_tc[tc2_idx] = {"name": tc2_name_delta, "args_json": tc2_args_delta}
                                                            else:
                                                                if tc2_name_delta:
                                                                    pending_tc[tc2_idx]["name"] = tc2_name_delta
                                                                pending_tc[tc2_idx]["args_json"] += tc2_args_delta
                                                if obj2.get("done"):
                                                    log.info("MULTI-TURN[%d]: done_event, finish_reason=%s, pending_tc=%d", iteration, finish_reason, len(pending_tc))
                                                    # Send final answer delta if we have content that wasn't part of a tool call flow
                                                    # The content deltas above already sent incrementally
                                            except:
                                                pass
                                        # Don't forward raw API chunks - we re-wrapped content/reasoning above
                                        # Only forward tool_call events and other metadata
                                    # Process accumulated tool calls AFTER streaming completes
                                    if finish_reason == "tool_calls" and pending_tc:
                                        log.info("MULTI-TURN[%d]: finish=tool_calls, executing %d accumulated calls", iteration, len(pending_tc))
                                        more_tool_calls = []
                                        more_tool_results = []
                                        for tc_idx in sorted(pending_tc.keys()):
                                            pt = pending_tc[tc_idx]
                                            tc2_name = pt["name"]
                                            tc2_args_raw = pt["args_json"]
                                            if not tc2_name:
                                                continue
                                            try:
                                                tc2_args = json.loads(tc2_args_raw) if tc2_args_raw.strip() else {}
                                            except json.JSONDecodeError as e:
                                                log.warning("MULTI-TURN[%d]: bad JSON for %s: %s [%s]", iteration, tc2_name, str(e), tc2_args_raw[:200])
                                                tc2_args = {}
                                            tool_def = next((t for t in TOOLS if t["type"] == "function" and t["function"]["name"] == tc2_name), None)
                                            if not tool_def:
                                                log.warning("MULTI-TURN[%d]: unknown tool %s", iteration, tc2_name)
                                                continue
                                            required_args = tool_def["function"]["parameters"].get("required", [])
                                            if not tc2_args and required_args:
                                                log.warning("MULTI-TURN[%d]: %s has no args (needs %s)", iteration, tc2_name, required_args)
                                                continue
                                            log.info("MULTI-TURN[%d]: executing %s(%s)", iteration, tc2_name, json.dumps(tc2_args)[:200])
                                            result2 = await exec_mcp_tool(tc2_name, tc2_args)
                                            if not result2 or (isinstance(result2, dict) and result2.get("error")):
                                                log.warning("MULTI-TURN[%d]: %s -> %s", iteration, tc2_name, str(result2))
                                                result2 = {"error": str(result2) if result2 else "No result"}
                                            more_tool_results.append({"name": tc2_name, "result": result2})
                                            more_tool_calls.append({"id": "call_1", "type": "function", "function": {"name": tc2_name, "arguments": json.dumps(tc2_args)}})
                                            # Send to browser
                                            tool_evt = {"tool_call": {"id": "call_1", "name": tc2_name, "input": tc2_args, "result": result2, "status": "completed"}}
                                            writer.write(("data: " + json.dumps(tool_evt) + "\n\n").encode())
                                            await writer.drain()
                                            if isinstance(result2, dict):
                                                rt = str(result2.get("count", len(result2))) + " rows/keys"
                                                if "error" in result2:
                                                    rt = "Error: " + str(result2["error"])
                                            else:
                                                rt = str(result2)[:200]
                                            td = {"delta": "\n🔧 Tool: " + tc2_name + " -> " + rt + "]\n\n"}
                                            if session_id:
                                                td["session_id"] = session_id
                                            writer.write(("data: " + json.dumps(td) + "\n\n").encode())
                                            await writer.drain()
                                        # Loop again with new tool results
                                        tool_results = more_tool_results
                                        tool_call_messages = more_tool_calls
                                        accumulated_content = ""
                                        continue  # Back to while loop for next iteration
                                    elif had_content:
                                        log.info("MULTI-TURN[%d]: content produced, done", iteration)
                                        iter_produced_content.append(True)
                                        has_final_content = True
                                    else:
                                        log.info("MULTI-TURN[%d]: no content, no tools, finish=%s, breaking", iteration, finish_reason)
                                        iter_produced_content.append(False)
                                        has_final_content = True

                                    # Dump the raw second response for debugging
                                    # raw response logging removed
                            # SAFETY NET: If no actual content was produced in ANY iteration but we have reasoning,
                            # send accumulated reasoning as content so the frontend shows something
                            # Only fire if no iteration produced content
                            if not any(iter_produced_content) and accumulated_content.strip() == '' and accumulated_reasoning.strip():
                                log.info("SAFETY NET: merging %d bytes of reasoning into content", len(accumulated_reasoning))
                                reason_evt = {"delta": accumulated_reasoning, "session_id": session_id}
                                reason_str = "data: " + json.dumps(reason_evt) + "\n\n"
                                writer.write(reason_str.encode())
                                await writer.drain()
                            writer.write(b"data: {\"done\":true}\n\n")
                            await writer.drain()
                            break
                        
                        try:
                            data = json.loads(payload)
                            if "id" in data and not session_id:
                                session_id = data["id"]
                            
                            for choice in data.get("choices", []):
                                delta = choice.get("delta", {})
                                content = delta.get("content", "")
                                reasoning = delta.get("reasoning", "")
                                
                                # Handle tool calls from delta
                                for tc in delta.get("tool_calls", []):
                                    tc_name = tc.get("function", {}).get("name", "")
                                    if not tc_name:
                                        continue  # Skip empty tool names
                                    try:
                                        tc_args = json.loads(tc.get("function", {}).get("arguments", "{}"))
                                    except:
                                        tc_args = {}
                                    # Check if tool actually requires arguments
                                    tool_def = next((t for t in TOOLS if t["type"] == "function" and t["function"]["name"] == tc_name), None)
                                    required_args = tool_def["function"]["parameters"].get("required", []) if tool_def else []
                                    if not tc_args and required_args:
                                        log.warning("Skipping tool call '%s' with empty args (requires: %s)", tc_name, required_args)
                                        continue
                                    result = await exec_mcp_tool(tc_name, tc_args)
                                    # Skip invalid results
                                    if not result or (isinstance(result, dict) and result.get("error")):
                                        log.warning("Skipping invalid tool result: %s -> %s", tc_name, str(result))
                                        continue
                                    log.info("Tool result: %s -> %s", tc_name, str(result)[:200])
                                    # Track for multi-turn
                                    tool_results.append({"name": tc_name, "result": result})
                                    tool_call_messages.append({"id": "call_1", "type": "function", "function": {"name": tc_name, "arguments": json.dumps(tc_args)}})
                                    in_tool_result = True
                                    # Send tool_call event to browser
                                    tool_evt = {"tool_call": {"id": "call_1", "name": tc_name, "input": tc_args, "result": result, "status": "completed"}}
                                    evt_str = "data: " + json.dumps(tool_evt) + "\n\n"
                                    writer.write(evt_str.encode())
                                    await writer.drain()
                                    # Send tool result as a clean, readable delta (NOT raw JSON)
                                    if isinstance(result, dict):
                                        rt_display = str(result.get("count", len(result))) + " rows/keys returned"
                                        if "error" in result:
                                            rt_display = "Error: " + str(result["error"])
                                    else:
                                        rt_display = str(result)[:200]
                                    tool_result_delta = {"delta": "\n🔧 Tool: " + tc_name + " -> " + rt_display + "]\n\n"}
                                    if session_id:
                                        tool_result_delta["session_id"] = session_id
                                    tr_str = "data: " + json.dumps(tool_result_delta) + "\n\n"
                                    writer.write(tr_str.encode())
                                    await writer.drain()
                                
                                # Also check text content for tool calls (legacy format)
                                for tc in parse_tool_call(content):
                                    result = await exec_mcp_tool(tc["name"], tc["arguments"])
                                    # Skip invalid results
                                    if not result or isinstance(result, dict) and result.get("error"):
                                        log.warning("Skipping invalid tool result: %s -> %s", tc["name"], str(result))
                                        continue
                                    log.info("Tool result (text): %s -> %s", tc["name"], str(result)[:200])
                                    # Track for multi-turn
                                    tool_results.append({"name": tc["name"], "result": result})
                                    tool_call_messages.append({"id": "call_1", "type": "function", "function": {"name": tc["name"], "arguments": json.dumps(tc["arguments"])}})
                                    in_tool_result = True
                                    tool_evt = {"tool_call": {"id": "call_1", "name": tc["name"], "input": tc["arguments"], "result": result, "status": "completed"}}
                                    evt_str = "data: " + json.dumps(tool_evt) + "\n\n"
                                    writer.write(evt_str.encode())
                                    await writer.drain()
                                    rt_display = str(result)[:200] if result else "empty"
                                    result_delta = {"delta": "\n🔧 Tool: " + tc["name"] + " -> " + rt_display + "]\n\n"}
                                    if session_id:
                                        result_delta["session_id"] = session_id
                                    rd_str = "data: " + json.dumps(result_delta) + "\n\n"
                                    writer.write(rd_str.encode())
                                    await writer.drain()
                                
                                if content or reasoning:
                                    # Track accumulated content/reasoning for safety net
                                    accumulated_content += content
                                    accumulated_reasoning += reasoning
                                    # Track if we got content after a tool call
                                    if in_tool_result and content:
                                        has_final_content = True
                                        log.info("Final content after tool call: %d chars", len(content))
                                    transformed = {"delta": content}
                                    if reasoning:
                                        transformed["reasoning"] = reasoning
                                    if session_id:
                                        transformed["session_id"] = session_id
                                    msg_str = "data: " + json.dumps(transformed) + "\n\n"
                                    writer.write(msg_str.encode())
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
        except:
            pass

async def main():
    srv = await asyncio.start_server(handle, "127.0.0.1", PORT)
    log.info("Proxy on 127.0.0.1:%d -> %s (tool-aware)", PORT, UPSTREAM)
    print("Proxy on 127.0.0.1:%d -> %s (tool-aware)" % (PORT, UPSTREAM), flush=True)
    async with srv:
        await srv.serve_forever()

if __name__ == "__main__":
    asyncio.run(main())
