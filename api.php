<?php
/**
 * API endpoint — proxies to ACP bridge
 *
 * @package    local_hermesagent
 * @copyright  2026
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// Debug logging - write directly to file before Moodle interferes
$api_log = '/var/www/moodledata/.hermes/logs/api_debug.log';
$api_log_prefix = date('c') . ' ';
function _hermes_log($msg) {
    global $api_log, $api_log_prefix;
    @file_put_contents($api_log, $api_log_prefix . $msg . PHP_EOL, FILE_APPEND | LOCK_EX);
}
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    _hermes_log("PHP_ERROR [{$errno}] {$errstr} at {$errfile}:{$errline}");
    return true;
});
register_shutdown_function(function() {
    $e = error_get_last();
    if ($e && ($e['type'] >= E_ERROR)) {
        _hermes_log("FATAL [{$e['type']}] {$e['message']} at {$e['file']}:{$e['line']}");
    }
});

_hermes_log("REQUEST: " . $_SERVER['REQUEST_METHOD'] . " " . $_SERVER['REQUEST_URI'] . " IP=" . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

require_login();

_hermes_log("Moodle loaded, user=" . ($USER->id ?? 'not set') . " action=" . ($_GET['action'] ?? 'none'));
_hermes_log("Logged in: user=" . $USER->id . " sesskey_len=" . strlen(sesskey()));
// Soft capability check - don't redirect
$context = context_system::instance();
if (!has_capability('local/hermesagent:use', $context) && !is_siteadmin($USER)) {
    _hermes_log('WARNING: user ' . $USER->id . ' lacks local/hermesagent:use capability');
}

$PAGE->set_context(context_system::instance());

// CSRF protection: validate sesskey for POST actions.
// Stream is GET-only (read-only SSE) so we skip sesskey to avoid Moodle's single-use conflict.
$action = required_param('action', PARAM_ALPHA);
if ($action !== 'stream') {
    require_sesskey();
}

switch ($action) {
    case 'send':
        api_send_message();
        break;
    case 'stream':
        // DEBUG: Log EVERY request to stream endpoint
        $trace_file = '/var/www/moodledata/.hermes/logs/stream_trace.log';
        file_put_contents($trace_file, date('Y-m-d H:i:s') . ' API:stream conv=' . ($_GET['conversationid'] ?? 'NONE') . ' user=' . ($USER->id ?? 'NONE') . "\n", FILE_APPEND);
        error_log('HERMES-DEBUG: api.php action=stream conversationid=' . ($_GET['conversationid'] ?? 'NONE'));
        api_stream_response();
        break;
    case 'status':
        api_bridge_status();
        break;
    case 'history':
        api_get_history();
        break;
    case 'conversations':
        api_list_conversations();
        break;
    case 'tool_response':
        api_tool_response();
        break;
    default:
        send_json_response(['error' => 'Unknown action']);
}

/**
 * Send a message to the ACP bridge
 */
function api_send_message(): void {
    global $DB, $USER;
    
    $message = required_param('message', PARAM_TEXT);
    _hermes_log('api_stream_response: START conversationid=' . $_GET['conversationid']);
    $conversationid = required_param('conversationid', PARAM_INT);
    
    if (empty($message)) {
        send_json_response(['error' => 'Empty message']);
    }

    // Check conversation ownership
    $conv = $DB->get_record('local_hermesagent_conversations', [
        'id' => $conversationid,
        'usermodified' => $USER->id,
    ], '*');

    if (!$conv) {
        send_json_response(['error' => 'Invalid conversation']);
    }

    // Save user message
    $rec = new stdClass();
    $rec->conversationid = $conversationid;
    $rec->role = 'user';
    $rec->content = $message;
    $rec->timemodified = time();
    $msgid = $DB->insert_record('local_hermesagent_messages', $rec);

    // Update conversation timestamp
    $conv->timemodified = time();
    if ($conv->name == 'New conversation') {
        $conv->name = clean_param(substr($message, 0, 60), PARAM_NOTAGS);
    }
    $DB->update_record('local_hermesagent_conversations', $conv);
    
    send_json_response([
        'messageid' => $msgid,
        'conversationid' => $conversationid,
    ]);
}

/**
 * Stream response from ACP bridge
 */
function api_stream_response(): void {
    global $DB, $USER;
    error_log('HERMES [API]: api_stream_response START conv=' . ($_GET['conversationid'] ?? 'NONE'));
    
    // CRITICAL: Log EVERY stream request
    error_log('HERMES [API]: START conversationid=' . ($_GET['conversationid'] ?? 'NONE') . ' user=' . ($USER->id ?? 'NONE'));
    _hermes_log('api_stream_response: START conversationid=' . $_GET['conversationid']);
    $conversationid = required_param('conversationid', PARAM_INT);

    // Check conversation ownership
    $conv = $DB->get_record('local_hermesagent_conversations', [
        'id' => $conversationid,
        'usermodified' => $USER->id,
    ], '*');

    if (!$conv) {
        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache');
        echo "event: error\ndata: " . json_encode(['error' => 'Invalid conversation']) . "\n\n";
        die();
    }

    $bridge_port = local_hermesagent_get_bridge_port();
    $bridge_url = "http://127.0.0.1:$bridge_port";
    
    // Build messages array from conversation history
    $messages = $DB->get_records('local_hermesagent_messages', ['conversationid' => $conversationid], 'id ASC');
    
    $history = [];
    foreach ($messages as $msg) {
        $history[] = [
            'role' => $msg->role,
            'content' => $msg->content,
        ];
    }
    
    // Get conversation's ACP session ID
    $conv = $DB->get_record('local_hermesagent_conversations', ['id' => $conversationid]);
    $acp_session = $conv ? $conv->acp_session_id : null;
    
    // Get loaded skills
    $skills = local_hermesagent_get_skills(null, true);
    $skill_content = '';
    foreach ($skills as $skill) {
        $skill_content .= "## {$skill->name}\n{$skill->description}\n\n{$skill->content}\n\n";
    }
    
    // Build system prompt with output contract enforcement
    $system_prompt = "You are a helpful assistant with access to Moodle database tools.\n\n";
    $system_prompt .= "## OUTPUT CONTRACT (MANDATORY)\n\n";
    $system_prompt .= "1. ALWAYS produce user-visible answer in `delta` field - never leave it empty\n";
    $system_prompt .= "2. Put internal thinking in `reasoning` field only - it is hidden from user\n";
    $system_prompt .= "3. When a tool is needed, emit `tool_call` action - do NOT just describe it\n";
    $system_prompt .= "4. Final answer must exist: complete, natural language, properly formatted\n";
    $system_prompt .= "5. Do NOT put the actual answer inside reasoning - it will be dropped\n\n";
    $system_prompt .= "## TOOL USAGE\n";
    $system_prompt .= "- If tool is available and relevant: call it immediately\n";
    $system_prompt .= "- After tool returns: summarize results in delta as final answer\n";
    $system_prompt .= "- If tool fails: explain what went wrong in delta\n";
    if ($skill_content) {
        $system_prompt .= "\n\n## Available Skills\n" . $skill_content;
    }
    
    // Prepend system message to history
    array_unshift($history, [
        'role' => 'system',
        'content' => $system_prompt,
    ]);
    
    // Build request to ACP bridge
    $request = [
        'messages' => $history,
        'session_id' => $acp_session,
        'skills' => $skill_content,
        'model' => local_hermesagent_get_setting('hermes_model', ''),
    ];
    
    // CRITICAL: Release session and flush buffers BEFORE any output
    ignore_user_abort(true);
    session_write_close();
    while (ob_get_level() > 0) {
        ob_end_flush();
    }
    
    // Set headers for SSE streaming
    header('Content-Type: text/event-stream');
    header('Cache-Control: no-cache');
    header('X-Accel-Buffering: no');
    header('Connection: keep-alive');
    
    _hermes_log('api_stream_response: Connecting to ' . $bridge_url . '/v1/chat/completions');
    _hermes_log('api_stream_response: ' . count($request['messages']) . ' messages, model=' . $request['model']);
    
    // Request ID for tracing
    $req_id = 'R' . substr(md5(uniqid(rand(), true)), 0, 10);
    _hermes_log("[$req_id] ===== STREAM START =====");
    
    // Call ACP bridge with streaming
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $bridge_url . '/v1/chat/completions',
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($request),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => false,
        CURLOPT_HEADER => false,
        CURLOPT_TIMEOUT => 300,
        CURLOPT_WRITEFUNCTION => function($curl, $data) use ($conversationid, $DB) {
            _hermes_log('api_stream_response: Received ' . strlen($data) . ' bytes from bridge');
            static $assistant_content = '';
            static $reasoning_content = '';  // Track reasoning separately for safety net
            static $message_id = null;
            
            // Parse SSE data
            $lines = explode("\n", $data);
            foreach ($lines as $line) {
                if (strpos($line, 'data: ') === 0) {
                    $payload = substr($line, 6);
                    $json = json_decode($payload, true);
                    if (!$json) continue;
                    if (isset($json['done']) && $json['done']) {
                        _hermes_log("[$req_id] DONE received - assistant_content_len=" . strlen($assistant_content) . " reasoning_content_len=" . strlen($reasoning_content));
                        // SAFETY NET: If delta is effectively empty but reasoning has content,
                        // use reasoning as the answer for DB storage (already shown in collapsible)
                        if ((trim($assistant_content) === '' || trim($assistant_content) === "\n\n") && !empty($reasoning_content)) {
                            _hermes_log("[$req_id] *** SAFETY NET: delta empty, merging " . strlen($reasoning_content) . " chars of reasoning for DB");
                            $assistant_content = $reasoning_content;
                            // Update DB — reasoning already displayed in collapsible
                            if ($message_id) {
                                $rec = $DB->get_record('local_hermesagent_messages', ['id' => $message_id]);
                                if ($rec) {
                                    $rec->content = $reasoning_content;
                                    $DB->update_record('local_hermesagent_messages', $rec);
                                }
                            }
                            // CRITICAL: Do NOT echo reasoning again - already streamed via type='reasoning' events
                            // Only update DB for persistence
                        }
                        
                        // Finalize
                        flush();
                        return strlen($data);
                    }
                    
                    // Record session on first event
                    // Log every parsed chunk
                    $keys = array_keys($json);
                    $dl = strlen($json['delta'] ?? '');
                    $rl = strlen($json['reasoning'] ?? '');
                    $tc_flag = isset($json['tool_call']) ? '[TC]' : '';
                    $dn_flag = isset($json['done']) ? '[DN]' : '';
                    _hermes_log("[$req_id] CHUNK keys=" . json_encode($keys) . " delta=$dl reasoning=$rl $tc_flag $dn_flag");
                    
                    if (isset($json['session_id']) && $message_id === null) {
                        $assistant_content = '';
                        $rec = new stdClass();
                        $rec->conversationid = $conversationid;
                        $rec->role = 'assistant';
                        $rec->content = '';
                        $rec->timemodified = time();
                        $message_id = $DB->insert_record('local_hermesagent_messages', $rec);
                        
                        $conv = $DB->get_record('local_hermesagent_conversations', ['id' => $conversationid]);
                        if ($conv) {
                            $conv->acp_session_id = $json['session_id'];
                            $DB->update_record('local_hermesagent_conversations', $conv);
                        }
                        
                        echo "event: session\ndata: " . json_encode(['session_id' => $json['session_id']]) . "\n\n";
                        flush();
                        // Don't continue — there may also be reasoning/delta in this chunk
                    }
                    
                    // Handle reasoning (thinking process) — this is where the bridge puts most content
                    if (isset($json['reasoning']) && $json['reasoning'] !== '') {
                        $chunk = $json['reasoning'];
                        $reasoning_content .= $chunk;
                        // Send reasoning_content as 'full' so JS can render it
                        echo "event: message\ndata: " . json_encode(['delta' => $chunk, 'full' => $reasoning_content, 'type' => 'reasoning']) . "\n\n";
                        flush();
                        _hermes_log("[$req_id] >>> ECHOED REASONING event (total_reasoning=" . strlen($reasoning_content) . ")");
                    }
                    
                    // Handle delta (actual model output)
                    if (isset($json['delta']) && $json['delta'] !== '') {
                        $chunk = $json['delta'];
                        
                        // Filter out [Result: ...] proxy tool output — not user-facing
                        $chunk_trimmed = trim($chunk);
                        if (strpos($chunk_trimmed, '[Result:') === 0) {
                            _hermes_log("[$req_id] SKIP proxy [Result] delta (" . strlen($chunk) . " chars)");
                            // Don't accumulate or echo tool result JSON to browser
                        }
                        // NOTE: Removed reasoning duplicate filter — the safety net delta IS the answer
                        else {
                            $assistant_content .= $chunk;
                            _hermes_log("[$req_id] DELTA chunk len=" . strlen($chunk) . ": " . substr(json_encode($chunk), 0, 50));
                            echo "event: message\ndata: " . json_encode(['delta' => $chunk, 'full' => $assistant_content]) . "\n\n";
                            flush();
                            _hermes_log("[$req_id] >>> ECHOED DELTA event (total_delta=" . strlen($assistant_content) . ")");
                        }
                    }
                    
                    if (isset($json['tool_call'])) {
                        $tc_name = $json['tool_call']['name'] ?? 'unknown';
                        $tc_has_result = isset($json['tool_call']['result']) ? 'YES' : 'NO';
                        $tc_result_type = isset($json['tool_call']['result']) ? gettype($json['tool_call']['result']) : 'N/A';
                        _hermes_log("[$req_id] ECHO TOOL_CALL: name=$tc_name result=$tc_has_result type=$tc_result_type");
                        // Debug: log the full tool_call payload structure
                        _hermes_log("[$req_id] TOOL_CALL payload keys: " . json_encode(array_keys($json['tool_call'])));
                        echo "event: tool_call\ndata: " . json_encode(['tool_call' => $json['tool_call']]) . "\n\n";
                        flush();
                        _hermes_log("[$req_id] >>> ECHOED TOOL_CALL event: $tc_name (keys: " . json_encode(array_keys($json['tool_call'])) . ")");
                    }
                }
            }
            
            return strlen($data);
        },
    ]);
    
    curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    _hermes_log("[$req_id_log] ===== STREAM END: http=$http_code error=" . ($curl_error ?: 'none') . " =====");
    curl_close($ch);
    error_log('HERMES [API]: curl done http_code=' . $http_code . ' error=' . ($curl_error ?: 'none') . ' bytes=' . strlen($data));
    _hermes_log("[$req_id] ===== curl done http_code=$http_code =====");
    error_log("HERMES [$req_id]: curl done http_code=$http_code bytes=" . strlen($data ?? ''));
    _hermes_log('api_stream_response: curl done, http_code=' . $http_code . ', error=' . ($curl_error ?: 'none'));
    
    if ($http_code !== 200) {
        echo "event: error\ndata: " . json_encode(['error' => 'Bridge error', 'code' => $http_code]) . "\n\n";
    }
    
    echo "event: done\ndata: [DONE]\n\n";
    flush();
    
    die();
}

/**
 * Get bridge status
 */
function api_bridge_status(): void {
    $bridge_port = local_hermesagent_get_bridge_port();
    $bridge_status = local_hermesagent_check_bridge_status();
    $online = ($bridge_status === 'running');

    send_json_response([
        'status' => $bridge_status,
        'online' => $online,
        'port' => $bridge_port,
    ]);
}

/**
 * Get conversation history
 */
function api_get_history(): void {
    global $DB, $USER;

    _hermes_log('api_stream_response: START conversationid=' . $_GET['conversationid']);
    $conversationid = required_param('conversationid', PARAM_INT);

    // Check conversation ownership
    $conv = $DB->get_record('local_hermesagent_conversations', [
        'id' => $conversationid,
        'usermodified' => $USER->id,
    ], '*');

    if (!$conv) {
        send_json_response(['error' => 'Invalid conversation']);
    }

    $messages = $DB->get_records('local_hermesagent_messages', ['conversationid' => $conversationid], 'id ASC');
    
    $result = [];
    foreach ($messages as $msg) {
        $result[] = [
            'id' => $msg->id,
            'role' => $msg->role,
            'content' => $msg->content,
            'timemodified' => $msg->timemodified,
        ];
    }
    
    send_json_response(['messages' => $result]);
}

/**
 * List conversations
 */
function api_list_conversations(): void {
    global $DB, $USER;
    
    $conversations = $DB->get_records('local_hermesagent_conversations', ['usermodified' => $USER->id], 'timemodified DESC');
    
    $result = [];
    foreach ($conversations as $conv) {
        $result[] = [
            'id' => $conv->id,
            'name' => $conv->name,
            'timemodified' => $conv->timemodified,
        ];
    }
    
    send_json_response(['conversations' => $result]);
}

/**
 * Handle tool response (approve/reject)
 */
function api_tool_response(): void {
    $messageid = required_param('messageid', PARAM_INT);
    $approved = required_param('approved', PARAM_BOOL);
    
    send_json_response([
        'status' => 'ok',
        'messageid' => $messageid,
        'approved' => $approved,
    ]);
}

/**
 * Send JSON response and exit
 */
function send_json_response(array $data): void {
    header('Content-Type: application/json');
    echo json_encode($data);
    die();
}
