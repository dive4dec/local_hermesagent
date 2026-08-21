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
// Abort is a lightweight signal, no sesskey needed.
// Status is read-only health check, no sesskey needed.
$action = required_param('action', PARAM_ALPHANUMEXT);  // allows underscores
if ($action !== 'stream' && $action !== 'abort' && $action !== 'status' && $action !== 'permission_response') {
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
    case 'permission_response':
        api_permission_response();
        break;
    case 'abort':
        api_abort_stream();
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
    global $DB, $USER, $CFG;
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

    // Lazy-start: if bridge isn't responding, start it now (transparent to user)
    // ensure_bridge_running polls until healthy or times out (~30s)
    if (!local_hermesagent_ensure_bridge_running($bridge_port)) {
        error_log("HERMES [API]: bridge failed to start, returning error");
        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache');
        echo "event: error\ndata: " . json_encode(['error' => 'Bridge failed to start. Please try again shortly.']) . "\n\n";
        die();
    }

    // Get conversation history from DB
    // Limit to most recent messages to avoid sending huge payloads on long
    // conversations. The ACP session maintains full history internally with
    // automatic compaction; this is only needed after a bridge restart.
    $MAX_HISTORY_MESSAGES = 40; // ~20 user+assistant turns
    $messages = $DB->get_records('local_hermesagent_messages', ['conversationid' => $conversationid], 'id ASC');
    $user_message = '';
    $history = [];
    foreach ($messages as $msg) {
        if ($msg->role === 'user') {
            $user_message = $msg->content;
        }
        // Skip empty assistant messages (created during streaming but not yet saved)
        if (trim($msg->content) !== '') {
            $history[] = [
                'role' => $msg->role,
                'content' => $msg->content,
            ];
        }
    }
    // Keep only the most recent messages if history is long
    if (count($history) > $MAX_HISTORY_MESSAGES) {
        $history = array_slice($history, -$MAX_HISTORY_MESSAGES);
    }

    // Get loaded skills and build system prompt
    $skills = local_hermesagent_get_skills(null, true);
    $skill_content = '';
    foreach ($skills as $skill) {
        $skill_content .= "## {$skill->name}\n{$skill->description}\n\n{$skill->content}\n\n";
    }

    $system_prompt = "You are a helpful assistant with access to Moodle database tools.\n\n## Output formatting\n\nUse **CommonMark** markdown for all responses:\n- Use fenced code blocks (```) for code. Never put math inside code blocks.\n- Use inline backticks (`) for identifiers, file paths, commands, and short code snippets.\n- Use \\(...\\) for inline math and \\[...\\] for display math.\n- Keep math delimiters outside of code blocks.\n\n";
    if ($skill_content) {
        $system_prompt .= "## Available Skills\n" . $skill_content;
    }

    // Build the user's Moodle session cookie data. The bridge writes this to a
    // PER-SESSION file ($HERMES_HOME/run/identity/<acp_session_id>.msession.json)
    // so concurrent admins each use their own Moodle session (no cross-attribution).
    // We also keep writing the legacy single $HERMES_HOME/run/msession.json for
    // backward compatibility with older bridge/skill versions.
    $hermes_home = getenv('HERMES_HOME') ?: '/var/www/moodledata/.hermes';
    $run_dir = $hermes_home . '/run';
    if (!is_dir($run_dir)) {
        @mkdir($run_dir, 0770, true);
    }
    $session_cookie_name = session_name();
    $session_cookie_value = $_COOKIE[$session_cookie_name] ?? '';
    $msession_data = null;
    if ($session_cookie_value) {
        $msession_data = [
            'cookie_name' => $session_cookie_name,
            'cookie_value' => $session_cookie_value,
            'domain' => parse_url($CFG->wwwroot, PHP_URL_HOST),
            'path' => $CFG->sessioncookiepath ?: '/',
            'moodle_url' => $CFG->wwwroot,
            'userid' => $USER->id,
            'username' => $USER->username,
            'written_at' => time(),
        ];
        // Legacy shared file (fallback for older skill versions)
        $msession_file = $run_dir . '/msession.json';
        @file_put_contents($msession_file, json_encode($msession_data));
        @chmod($msession_file, 0600);
    }

    // Build request to ACP bridge
    // The ACP session maintains conversation history internally with automatic
    // compaction (archive_and_compact) — old messages are summarized, not lost.
    // We send the full history only for the bridge to use when creating a NEW
    // session (after bridge restart). On subsequent prompts, the bridge only
    // sends the latest message, relying on the ACP session's internal memory.
    $request = [
        'conversationid' => $conversationid,
        'message' => $user_message,
        'system_prompt' => $system_prompt,
        'acp_session_id' => $conv->acp_session_id ?? null,
        'messages' => $history,
        'moodle_username' => $USER->username,
        'moodle_userid' => $USER->id,
        'msession' => $msession_data,
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
    
    _hermes_log('api_stream_response: Connecting to ACP bridge at ' . $bridge_url . '/session/prompt');
    _hermes_log('api_stream_response: conversationid=' . $conversationid . ' msg_len=' . strlen($request['message']));
    
    // Request ID for tracing
    $req_id = 'R' . substr(md5(uniqid(rand(), true)), 0, 10);
    _hermes_log("[$req_id] ===== STREAM START =====");
    
    // Send an initial keepalive comment so the browser's EventSource
    // receives data immediately and doesn't fire a premature 'error'
    // event while waiting for the ACP to produce the first chunk
    // (which can take 5-10 seconds during model initialization).
    echo ": keepalive\n\n";
    flush();
    
    // Call ACP bridge with streaming
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $bridge_url . '/session/prompt',
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($request),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => false,
        CURLOPT_HEADER => false,
        CURLOPT_TIMEOUT => 600,  // 10 min — allows time for tool approval
        CURLOPT_WRITEFUNCTION => function($curl, $data) use ($conversationid, $DB, $req_id, $bridge_url) {
            _hermes_log('api_stream_response: Received ' . strlen($data) . ' bytes from bridge');
            static $assistant_content = '';
            static $reasoning_content = '';
            static $message_id = null;
            static $acp_session_saved = false;
            static $sse_buffer = '';
            static $event_type = '';
            static $tool_calls = [];
            static $aborted_sent = false;
            
            // Append to buffer and process complete SSE events (delimited by \n\n).
            // curl WRITEFUNCTION delivers data in arbitrary chunks; a large SSE
            // event (e.g. permission request with full file diff) can span multiple
            // calls. Without buffering, json_decode() fails on the partial JSON
            // and the event is silently lost.
            $sse_buffer .= $data;
            
            // Process complete events (split on blank line = \n\n)
            while (($pos = strpos($sse_buffer, "\n\n")) !== false) {
                $raw_event = substr($sse_buffer, 0, $pos);
                $sse_buffer = substr($sse_buffer, $pos + 2);
                
                $lines = explode("\n", $raw_event);
                $event_type = '';
                $payload = '';
                foreach ($lines as $line) {
                    if (strpos($line, ': keepalive') === 0) {
                        $event_type = 'keepalive';
                    } elseif (strpos($line, 'event: ') === 0) {
                        $event_type = substr($line, 7);
                    } elseif (strpos($line, 'data: ') === 0) {
                        $payload = substr($line, 6);
                    }
                }
                
                // Forward SSE keepalive comments to keep browser↔nginx alive
                if ($event_type === 'keepalive') {
                    echo ": keepalive\n\n";
                    flush();
                    continue;
                }
                
                if ($payload === '') continue;
                $json = json_decode($payload, true);
                if (!$json) {
                    _hermes_log("[$req_id] WARNING: json_decode failed for event_type=$event_type payload_len=" . strlen($payload));
                    continue;
                }
                
                // Save the ACP session ID to DB on first chunk (once per stream)
                if (!$acp_session_saved && !empty($json['session_id'])) {
                    $acp_session_saved = true;
                    $upd = new stdClass();
                    $upd->id = $conversationid;
                    $upd->acp_session_id = $json['session_id'];
                    $DB->update_record('local_hermesagent_conversations', $upd);
                }
                
                $dl = strlen($json['delta'] ?? '');
                $rl = strlen($json['reasoning'] ?? '');
                $etype = $json['type'] ?? 'unknown';
                _hermes_log("[$req_id] CHUNK type=$etype delta=$dl reasoning=$rl");
                
                // Handle message events (content chunks)
                if ($etype === 'message') {
                    $chunk = $json['delta'] ?? '';
                    $full = $json['full'] ?? '';
                    $assistant_content .= $chunk;
                    echo "event: message\ndata: " . json_encode(['delta' => $chunk, 'full' => $full, 'type' => 'message']) . "\n\n";
                    flush();
                }
                
                // Handle reasoning events
                if ($etype === 'reasoning') {
                    $chunk = $json['delta'] ?? '';
                    $full = $json['full'] ?? '';
                    $reasoning_content .= $chunk;
                    echo "event: message\ndata: " . json_encode(['delta' => $chunk, 'full' => $full, 'type' => 'reasoning']) . "\n\n";
                    flush();
                }
                
                // Handle permission events — forward to browser
                if ($etype === 'permission') {
                    $perm_data = [
                        'type' => 'permission',
                        'permission_id' => $json['permission_id'] ?? null,
                        'title' => $json['title'] ?? 'Unknown tool',
                        'description' => $json['description'] ?? '',
                        'kind' => $json['kind'] ?? 'execute',
                    ];
                    echo "event: permission\ndata: " . json_encode($perm_data) . "\n\n";
                    flush();
                }
                
                // Handle tool_call events — forward to browser AND persist so the
                // transcript survives reload / conversation switch.
                if ($etype === 'tool_call') {
                    $tc = $json['tool_call'] ?? $json;
                    if (!empty($tc['toolcall_id'])) {
                        $tool_calls[$tc['toolcall_id']] = $tc;
                        // Persist now (not just at done) so a reload / switch /
                        // crash mid-turn still shows the tool calls.
                        _hermesagent_persist_assistant($DB, $conversationid,
                            $assistant_content, $reasoning_content, $tool_calls,
                            $message_id, $req_id);
                    }
                    echo "event: tool_call\ndata: " . json_encode($json) . "\n\n";
                    flush();
                }

                // FIX 4: if the browser left (switched conversation / closed the
                // tab) while the prompt is still running, abort it in the bridge
                // so nothing is stranded waiting at a permission gate and no
                // tokens/GPU are wasted on a stream nobody is watching.
                if (!$aborted_sent && connection_aborted()) {
                    $aborted_sent = true;
                    _hermes_log("[$req_id] CLIENT DISCONNECTED (conversation $conversationid) -> aborting prompt + saving partial");
                    _hermesagent_abort($bridge_url, $conversationid, $req_id);
                    // Flush whatever we've collected so far so it's not lost.
                    if (trim($assistant_content) === '' && !empty($reasoning_content)) {
                        $assistant_content = $reasoning_content;
                    }
                    _hermesagent_persist_assistant($DB, $conversationid,
                        $assistant_content, $reasoning_content, $tool_calls,
                        $message_id, $req_id);
                    // Stop pulling from the bridge.
                    return strlen($data);
                }

                // Handle done event
                if ($etype === 'done') {
                    _hermes_log("[$req_id] DONE - assistant=" . strlen($assistant_content) . " reasoning=" . strlen($reasoning_content));
                    
                    // Safety net: if no content but reasoning exists, use reasoning
                    if (trim($assistant_content) === '' && !empty($reasoning_content)) {
                        _hermes_log("[$req_id] SAFETY NET: using reasoning as answer");
                        $assistant_content = $reasoning_content;
                    }
                    
                    // Save to DB (upsert — content + all tool calls + results).
                    _hermesagent_persist_assistant($DB, $conversationid,
                        $assistant_content, $reasoning_content, $tool_calls,
                        $message_id, $req_id);

                    flush();
                    return strlen($data);
                }
            }
            
            return strlen($data);
        },
    ]);
    
    curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    _hermes_log("[$req_id] ===== STREAM END: http=$http_code error=" . ($curl_error ?: 'none') . " =====");
    curl_close($ch);
    error_log('HERMES [API]: curl done http_code=' . $http_code . ' error=' . ($curl_error ?: 'none'));
    
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
 * Forward permission response (approve/reject) to the ACP bridge
 */
function api_permission_response(): void {
    $permission_id = required_param('permission_id', PARAM_INT);
    // outcome param: allow_once | allow_session | allow_always | deny
    // For backwards compat, approved=1 → allow_once, approved=0 → deny
    $outcome = optional_param('outcome', '', PARAM_ALPHAEXT);
    $approved = optional_param('approved', null, PARAM_BOOL);

    if ($outcome === '') {
        $outcome = $approved ? 'allow_once' : 'deny';
    }

    $bridge_port = local_hermesagent_get_bridge_port();
    $bridge_url = "http://127.0.0.1:$bridge_port/session/permission";

    $ch = curl_init($bridge_url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode([
            'permission_id' => $permission_id,
            'outcome' => $outcome,
        ]),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
    ]);
    $resp = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlerr = curl_error($ch);
    curl_close($ch);

    if ($resp === false) {
        send_json_response(['status' => 'error', 'message' => 'Bridge unreachable: ' . $curlerr]);
    }

    $json = json_decode($resp, true);
    if ($http !== 200 || !$json || ($json['status'] ?? '') === 'error') {
        $msg = $json['message'] ?? 'Bridge error (HTTP ' . $http . ')';
        send_json_response(['status' => 'error', 'message' => $msg]);
    }

    send_json_response(['status' => 'ok', 'outcome' => $outcome]);
}

/**
 * Abort the current streaming response
 */
function api_abort_stream(): void {
    $conversationid = required_param('conversationid', PARAM_INT);
    
    $bridge_port = local_hermesagent_get_bridge_port();
    $bridge_url = "http://127.0.0.1:$bridge_port";
    
    // Signal the bridge to abort this conversation's stream
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $bridge_url . '/session/abort',
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode(['conversationid' => $conversationid]),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 5,
    ]);
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    send_json_response([
        'status' => 'ok',
        'http_code' => $http_code,
        'bridge_response' => json_decode($response, true),
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

/**
 * Persist the in-progress assistant turn (content + tool calls + results) to
 * the DB, creating the message row on first call and updating it thereafter.
 *
 * This runs on EVERY tool_call (not just at 'done') so a conversation switch,
 * page reload, or mid-turn crash still leaves a durable transcript.
 *
 * @param object      $DB            Moodle DML (has {local_hermesagent_messages})
 * @param int         $conversationid
 * @param string      $content       accumulated assistant text (may be '')
 * @param string      $reasoning     accumulated reasoning text (fallback)
 * @param array       $tool_calls    map of toolcall_id => tool_call object
 * @param int|null    $message_id    existing row id, or null to insert
 * @param string      $req_id        log correlation id
 * @return int|null  the (possibly new) message row id
 */
function _hermesagent_persist_assistant($DB, $conversationid, $content, $reasoning, array $tool_calls, $message_id, $req_id) {
    $content = ($content !== null && $content !== '') ? $content : ($reasoning ?? '');
    if (trim($content) === '' && empty($tool_calls)) {
        return $message_id; // nothing to persist yet
    }
    $tool_json = empty($tool_calls) ? null : json_encode(array_values($tool_calls), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    try {
        if ($message_id) {
            $rec = $DB->get_record('local_hermesagent_messages', ['id' => $message_id]);
            if ($rec) {
                $rec->content = $content;
                if ($tool_json !== null) {
                    $rec->tool_calls = $tool_json;
                }
                $rec->timemodified = time();
                $DB->update_record('local_hermesagent_messages', $rec);
                _hermes_log("[$req_id] persist assistant msg $message_id (content=" . strlen($content) . " tools=" . count($tool_calls) . ")");
                return $message_id;
            }
        }
        $newrec = new stdClass();
        $newrec->conversationid = $conversationid;
        $newrec->role = 'assistant';
        $newrec->content = $content;
        if ($tool_json !== null) {
            $newrec->tool_calls = $tool_json;
        }
        $newrec->timemodified = time();
        $newid = $DB->insert_record('local_hermesagent_messages', $newrec);
        _hermes_log("[$req_id] created assistant msg $newid (content=" . strlen($content) . " tools=" . count($tool_calls) . ")");
        return $newid;
    } catch (\Throwable $e) {
        _hermes_log("[$req_id] WARNING: persist_assistant failed: " . $e->getMessage());
        return $message_id;
    }
}

/**
 * Tell the bridge to abort this conversation's in-flight prompt (FIX 4) so a
 * client that left the page doesn't leave a prompt stranded at a permission
 * gate. Fire-and-forget, 5 s cap — never blocks the disconnect path long.
 */
function _hermesagent_abort($bridge_url, $conversationid, $req_id) {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $bridge_url . '/session/abort',
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode(['conversationid' => $conversationid]),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 2,
        CURLOPT_TIMEOUT => 5,
    ]);
    $resp = @curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    _hermes_log("[$req_id] abort sent for conversation $conversationid (http=" . $http . " err=" . ($err ?: 'none') . " resp=" . ($resp ?: 'none') . ")");
}
