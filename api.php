<?php
/**
 * API endpoint — proxies to ACP bridge (With Fatal Error Trapping)
 *
 * @package    local_hermesagent
 * @copyright  2026
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

require_login();
require_capability('local/hermesagent:use', context_system::instance());

$PAGE->set_context(context_system::instance());

$action = required_param('action', PARAM_ALPHA);
if ($action === 'stream') {
    $stream_sesskey = optional_param('sesskey', '', PARAM_ALPHANUM);
    if ($stream_sesskey === '' || !confirm_sesskey($stream_sesskey)) {
        send_json_response(['error' => 'Invalid sesskey']);
    }
} else {
    require_sesskey();
}

switch ($action) {
    case 'send': api_send_message(); break;
    case 'stream': api_stream_response(); break;
    case 'status': api_bridge_status(); break;
    case 'history': api_get_history(); break;
    case 'conversations': api_list_conversations(); break;
    case 'tool_response': api_tool_response(); break;
    default: send_json_response(['error' => 'Unknown action']);
}

function api_send_message(): void {
    global $DB, $USER;
    
    $message = required_param('message', PARAM_TEXT);
    $conversationid = required_param('conversationid', PARAM_INT);
    
    if (empty($message)) send_json_response(['error' => 'Empty message']);

    $conv = $DB->get_record('local_hermesagent_conversations', [
        'id' => $conversationid,
        'usermodified' => $USER->id,
    ], '*');

    if (!$conv) send_json_response(['error' => 'Invalid conversation']);

    $rec = new stdClass();
    $rec->conversationid = $conversationid;
    $rec->role = 'user';
    $rec->content = $message;
    $rec->timemodified = time();
    $msgid = $DB->insert_record('local_hermesagent_messages', $rec);

    $conv->timemodified = time();
    if ($conv->name == 'New conversation') {
        $conv->name = clean_param(substr($message, 0, 60), PARAM_NOTAGS);
    }
    $DB->update_record('local_hermesagent_conversations', $conv);
    
    send_json_response(['messageid' => $msgid, 'conversationid' => $conversationid]);
}

function api_stream_response(): void {
    global $DB, $USER;
    
    try {
        $conversationid = required_param('conversationid', PARAM_INT);
        $conv = $DB->get_record('local_hermesagent_conversations', [
            'id' => $conversationid, 'usermodified' => $USER->id
        ], '*');

        if (!$conv) {
            header('Content-Type: text/event-stream');
            echo "event: error\ndata: " . json_encode(['error' => 'Invalid conversation']) . "\n\n";
            die();
        }

        $bridge_port = local_hermesagent_get_bridge_port() ?: '9118';
        $bridge_url = "http://127.0.0.1:$bridge_port";
        
        $sid = $conv->acp_session_id ?? null;
        $session_ok = false;
        if ($sid) {
            $ch = curl_init("$bridge_url/session/$sid/info");
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 2]);
            $res = curl_exec($ch);
            if (curl_getinfo($ch, CURLINFO_HTTP_CODE) == 200) {
                $info = json_decode($res, true);
                if (!empty($info['alive'])) $session_ok = true;
            }
            curl_close($ch);
        }
        
        if (!$session_ok) {
            $ch = curl_init("$bridge_url/session/create");
            curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5]);
            $res = curl_exec($ch);
            if (curl_getinfo($ch, CURLINFO_HTTP_CODE) == 200) {
                $data = json_decode($res, true);
                $sid = $data['sid'];
                
                // ⚠️ 这里就是最可能引发 500 崩溃的地方！
                $conv->acp_session_id = $sid;
                $DB->update_record('local_hermesagent_conversations', $conv);
            } else {
                header('Content-Type: text/event-stream');
                echo "event: error\ndata: " . json_encode(['error' => 'Failed to initialize backend session process. Curl Response: ' . $res]) . "\n\n";
                die();
            }
            curl_close($ch);
        }
        
        $messages = $DB->get_records('local_hermesagent_messages', ['conversationid' => $conversationid], 'id ASC');
        $last_msg = '';
        foreach ($messages as $m) {
            if ($m->role === 'user') $last_msg = $m->content;
        }
        
        while (ob_get_level()) { ob_end_clean(); }
        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache');
        header('X-Accel-Buffering: no');
        header('Connection: keep-alive');
        \core\session\manager::write_close();
        
        $ch = curl_init("$bridge_url/session/$sid/send");
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode(['message' => $last_msg]),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_HEADER => false,
            CURLOPT_TIMEOUT => 300,
            CURLOPT_WRITEFUNCTION => function($curl, $data) use ($conversationid, $DB, $sid) {
                static $assistant_content = '';
                static $message_id = null;
                static $buffer = '';
                
                $buffer .= $data;
                while (($pos = strpos($buffer, "\n")) !== false) {
                    $line = trim(substr($buffer, 0, $pos));
                    $buffer = substr($buffer, $pos + 1);
                    
                    if (strpos($line, 'data: ') === 0) {
                        $payload = json_decode(substr($line, 6), true);
                        if (!$payload || !isset($payload['type'])) continue;
                        
                        if ($payload['type'] === 'message' || $payload['type'] === 'raw') {
                            $acp_data = $payload['data'];
                            $is_done = false;
                            $delta = '';
                            
                            if (is_array($acp_data)) {
                                if (isset($acp_data['params']['delta'])) $delta = $acp_data['params']['delta'];
                                elseif (isset($acp_data['delta'])) $delta = $acp_data['delta'];
                                elseif (isset($acp_data['result']) && is_string($acp_data['result'])) {
                                    $delta = $acp_data['result'];
                                    $is_done = true;
                                }
                                if (!empty($acp_data['done'])) $is_done = true;
                                if (isset($acp_data['method']) && strpos($acp_data['method'], 'done') !== false) $is_done = true;
                            } elseif (is_string($acp_data)) {
                                $delta = $acp_data;
                            }
                            
                            if ($delta !== '') {
                                if ($message_id === null) {
                                    $assistant_content = '';
                                    $rec = new stdClass();
                                    $rec->conversationid = $conversationid;
                                    $rec->role = 'assistant';
                                    $rec->content = '';
                                    $rec->timemodified = time();
                                    $message_id = $DB->insert_record('local_hermesagent_messages', $rec);
                                    echo "event: session\ndata: " . json_encode(['session_id' => $sid]) . "\n\n";
                                }
                                $assistant_content .= $delta;
                                echo "event: message\ndata: " . json_encode(['delta' => $delta, 'full' => $assistant_content]) . "\n\n";
                                flush();
                            }
                            if ($is_done) return 0;
                        }
                    }
                }
                return strlen($data);
            }
        ]);
        
        curl_exec($ch);
        curl_close($ch);
        
        echo "event: done\ndata: [DONE]\n\n";
        flush();
        die();

    } catch (\Throwable $e) {
        // 🔥 捕获一切崩溃并传回前端！
        while (ob_get_level()) { ob_end_clean(); }
        header('Content-Type: text/event-stream');
        $error_msg = "PHP Fatal Error in api.php: " . $e->getMessage() . " at line " . $e->getLine();
        echo "event: error\ndata: " . json_encode(['error' => $error_msg]) . "\n\n";
        die();
    }
}

function api_bridge_status(): void {
    $bridge_port = local_hermesagent_get_bridge_port() ?: '9118';
    $bridge_status = local_hermesagent_get_setting('bridge_status', 'stopped');
    
    $online = false;
    $ch = curl_init("http://127.0.0.1:$bridge_port/health");
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 3]);
    $resp = curl_exec($ch);
    if ($resp !== false && curl_getinfo($ch, CURLINFO_HTTP_CODE) === 200) {
        $online = true;
        local_hermesagent_set_setting('bridge_status', 'running');
        $bridge_status = 'running';
    }
    curl_close($ch);
    
    send_json_response(['status' => $bridge_status, 'online' => $online, 'port' => $bridge_port]);
}

function api_get_history(): void {
    global $DB, $USER;
    $conversationid = required_param('conversationid', PARAM_INT);
    $conv = $DB->get_record('local_hermesagent_conversations', ['id' => $conversationid, 'usermodified' => $USER->id], '*');
    if (!$conv) send_json_response(['error' => 'Invalid conversation']);

    $messages = $DB->get_records('local_hermesagent_messages', ['conversationid' => $conversationid], 'id ASC');
    $result = [];
    foreach ($messages as $msg) {
        $result[] = ['id' => $msg->id, 'role' => $msg->role, 'content' => $msg->content, 'timemodified' => $msg->timemodified];
    }
    send_json_response(['messages' => $result]);
}

function api_list_conversations(): void {
    global $DB, $USER;
    $conversations = $DB->get_records('local_hermesagent_conversations', ['usermodified' => $USER->id], 'timemodified DESC');
    $result = [];
    foreach ($conversations as $conv) {
        $result[] = ['id' => $conv->id, 'name' => $conv->name, 'timemodified' => $conv->timemodified];
    }
    send_json_response(['conversations' => $result]);
}

function api_tool_response(): void {
    $messageid = required_param('messageid', PARAM_INT);
    $approved = required_param('approved', PARAM_BOOL);
    send_json_response(['status' => 'ok', 'messageid' => $messageid, 'approved' => $approved]);
}

function send_json_response(array $data): void {
    header('Content-Type: application/json');
    echo json_encode($data);
    die();
}