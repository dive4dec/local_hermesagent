<?php
/**
 * Chat interface
 *
 * @package    local_hermesagent
 * @copyright  2026
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

require_login();
require_capability('local/hermesagent:use', context_system::instance());

$conversationid = optional_param('conversationid', 0, PARAM_INT);
$action = optional_param('action', '', PARAM_ALPHA);

$PAGE->set_url(new moodle_url('/local/hermesagent/chat.php', [
    'conversationid' => $conversationid,
    'action' => $action,
]));
$PAGE->set_context(context_system::instance());
$PAGE->set_title(get_string('pluginname', 'local_hermesagent'));
$PAGE->set_heading(get_string('pluginname', 'local_hermesagent'));
$PAGE->requires->js_call_amd('local_hermesagent/chat', 'init');

echo $OUTPUT->header();

// CSS for chat interface
$chat_css = '
    .hermes-chat-container {
        display: flex;
        min-height: calc(100vh - 200px);
    }
    .hermes-sidebar {
        width: 250px;
        border-right: 1px solid #dee2e6;
        display: flex;
        flex-direction: column;
        padding: 1rem;
    }
    .hermes-sidebar-header h3 {
        margin: 0 0 0.5rem 0;
    }
    .hermes-conversation-list {
        flex: 1;
        overflow-y: auto;
    }
    .hermes-conv-item {
        padding: 0.5rem;
        cursor: pointer;
        border-radius: 0.25rem;
        margin-bottom: 0.25rem;
    }
    .hermes-conv-item:hover {
        background: #f8f9fa;
    }
    .hermes-conv-item.active {
        background: #e9ecef;
        font-weight: bold;
    }
    .hermes-sidebar-footer {
        border-top: 1px solid #dee2e6;
        padding-top: 0.5rem;
        margin-top: 0.5rem;
    }
    .hermes-main {
        flex: 1;
        display: flex;
        flex-direction: column;
    }
    .hermes-chat-area {
        flex: 1;
        overflow-y: auto;
        padding: 1rem;
    }
    .hermes-message {
        display: flex;
        gap: 0.5rem;
        margin-bottom: 1rem;
        align-items: flex-start;
    }
    .hermes-message.user-message {
        flex-direction: row-reverse;
    }
    .hermes-avatar {
        width: 2rem;
        height: 2rem;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-weight: bold;
        flex-shrink: 0;
    }
    .hermes-avatar.user-avatar {
        background: #0d6efd;
        color: white;
    }
    .hermes-avatar.assistant-avatar {
        background: #6c757d;
        color: white;
    }
    .hermes-bubble {
        max-width: 70%;
        padding: 0.5rem 1rem;
        border-radius: 0.5rem;
        word-wrap: break-word;
    }
    .hermes-bubble.user-bubble {
        background: #0d6efd;
        color: white;
        border-bottom-right-radius: 0;
    }
    .hermes-bubble.assistant-bubble {
        background: #f8f9fa;
        border-bottom-left-radius: 0;
    }
    .hermes-streaming {
        border-right: 2px solid #0d6efd;
        animation: blink 1s infinite;
    }
    @keyframes blink {
        50% { border-color: transparent; }
    }
    .hermes-spinner {
        display: inline-block;
        width: 1rem;
        height: 1rem;
        border: 2px solid #0d6efd;
        border-radius: 50%;
        border-top-color: transparent;
        animation: spin 1s linear infinite;
    }
    @keyframes spin {
        to { transform: rotate(360deg); }
    }
    .hermes-input-area {
        border-top: 1px solid #dee2e6;
        padding: 1rem;
    }
    .hermes-input-container {
        display: flex;
        gap: 0.5rem;
    }
    .hermes-input-container textarea {
        flex: 1;
        resize: none;
    }
    .hermes-tool-modal {
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: rgba(0,0,0,0.5);
        z-index: 9999;
    }
    .hermes-tool-modal-content {
        position: absolute;
        top: 50%;
        left: 50%;
        transform: translate(-50%, -50%);
        background: white;
        padding: 1.5rem;
        border-radius: 0.5rem;
        min-width: 300px;
        max-width: 90%;
    }
    .hermes-tool-modal-actions {
        display: flex;
        gap: 0.5rem;
        margin-top: 1rem;
        justify-content: flex-end;
    }
    .hermes-error {
        color: #dc3545;
        padding: 0.5rem;
        background: #f8d7da;
        border-radius: 0.25rem;
    }
    .hermes-conv-item {
        display: flex;
        align-items: center;
        justify-content: space-between;
    }
    .hermes-conv-name {
        flex: 1;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }
    .hermes-conv-rename {
        display: none;
        padding: 0.1rem 0.3rem;
        font-size: 0.75rem;
        background: none;
        border: none;
        cursor: pointer;
        color: #6c757d;
        flex-shrink: 0;
        margin-left: 0.25rem;
    }
    .hermes-conv-item:hover .hermes-conv-rename {
        display: inline-block;
    }
    .hermes-conv-rename:hover {
        color: #0d6efd;
    }
';
echo html_writer::tag('style', $chat_css);



// Conversation list sidebar
global $DB;
$conversations = $DB->get_records('local_hermesagent_conversations', ['usermodified' => $USER->id], 'timemodified DESC');

// Create new conversation if needed
$current_id = $conversationid;
$newly_created = false;
if ($action == 'new' || ($current_id == 0 && !empty($conversations))) {
    $rec = new stdClass();
    $rec->name = get_string('newconversation', 'local_hermesagent');
    $rec->usermodified = $USER->id;
    $rec->timecreated = time();
    $rec->timemodified = time();
    $current_id = $DB->insert_record('local_hermesagent_conversations', $rec);
    $newly_created = true;
} else if ($current_id > 0) {
    $conv = $DB->get_record('local_hermesagent_conversations', ['id' => $current_id], '*', MUST_EXIST);
} else if (empty($conversations)) {
    $rec = new stdClass();
    $rec->name = get_string('newconversation', 'local_hermesagent');
    $rec->usermodified = $USER->id;
    $rec->timecreated = time();
    $rec->timemodified = time();
    $current_id = $DB->insert_record('local_hermesagent_conversations', $rec);
    $newly_created = true;
}

// If we just created a new conversation, re-fetch the list so it includes the new entry
if ($newly_created) {
    $conversations = $DB->get_records('local_hermesagent_conversations', ['usermodified' => $USER->id], 'timemodified DESC');
}

// Get bridge status
$bridge_port = local_hermesagent_get_bridge_port();
$bridge_status = local_hermesagent_get_setting('bridge_status', 'stopped');

// Conversation list
echo html_writer::start_div('hermes-chat-container');

echo html_writer::start_div('hermes-sidebar');
echo html_writer::start_div('hermes-sidebar-header');
echo html_writer::tag('h3', get_string('conversations', 'local_hermesagent'));
echo html_writer::empty_tag('hr');
echo html_writer::end_div('hermes-sidebar-header');

echo html_writer::start_div('hermes-conversation-list');
foreach ($conversations as $conv) {
    $cls = $conv->id == $current_id ? ' active' : '';
    echo html_writer::start_div('hermes-conv-item' . $cls, [
        'data-conv-id' => $conv->id,
        'class' => 'hermes-conv-item' . $cls,
        'title' => userdate($conv->timemodified),
    ]);
    echo html_writer::tag('span', format_text($conv->name, FORMAT_PLAIN), [
        'class' => 'hermes-conv-name',
        'data-conv-id' => $conv->id,
    ]);
    echo html_writer::tag('button', '✎', [
        'class' => 'hermes-conv-rename',
        'data-conv-id' => $conv->id,
        'data-conv-name' => s($conv->name),
        'title' => get_string('rename', 'local_hermesagent'),
    ]);
    echo html_writer::end_div();
}
echo html_writer::end_div('hermes-conversation-list');

echo html_writer::start_div('hermes-sidebar-footer');
echo html_writer::link(
    new moodle_url('/local/hermesagent/chat.php', ['action' => 'new']),
    get_string('newconversation', 'local_hermesagent'),
    ['class' => 'btn btn-secondary hermes-new-conv']
);
// Bridge status indicator
$status_cls = $bridge_status == 'running' ? 'text-success' : 'text-danger';
echo html_writer::tag('div', get_string('bridge_status', 'local_hermesagent') . ': <strong class="' . $status_cls . '">' . $bridge_status . '</strong>', [
    'class' => 'mt-2 hermes-bridge-status',
]);
echo html_writer::end_div('hermes-sidebar-footer');
echo html_writer::end_div('hermes-sidebar');

// Main chat area
echo html_writer::start_div('hermes-main');
echo html_writer::start_div('hermes-chat-area', ['id' => 'hermes-chat-area']);
echo html_writer::end_div('hermes-chat-area');

// Input area
echo html_writer::start_div('hermes-input-area');
echo html_writer::start_div('hermes-input-container');
echo html_writer::tag('textarea', '', [
    'id' => 'hermes-message-input',
    'placeholder' => get_string('type_message', 'local_hermesagent'),
    'rows' => '2',
]);
echo html_writer::tag('button', get_string('send', 'local_hermesagent'), [
    'id' => 'hermes-send-btn',
    'class' => 'btn btn-primary',
    'type' => 'button',
]);
echo html_writer::end_div('hermes-input-container');
echo html_writer::end_div('hermes-input-area');

// Tool confirmation modal
echo html_writer::start_div('hermes-tool-modal', [
    'id' => 'hermes-tool-modal',
    'style' => 'display:none;',
    'class' => 'hermes-tool-modal',
]);
echo html_writer::start_div('hermes-tool-modal-content');
echo html_writer::tag('div', '', ['id' => 'hermes-tool-modal-body']);
echo html_writer::start_div('hermes-tool-modal-actions');
echo html_writer::tag('button', get_string('approve', 'local_hermesagent'), [
    'id' => 'hermes-tool-approve',
    'class' => 'btn btn-success',
]);
echo html_writer::tag('button', get_string('reject', 'local_hermesagent'), [
    'id' => 'hermes-tool-reject',
    'class' => 'btn btn-danger',
]);
echo html_writer::end_div('hermes-tool-modal-actions');
echo html_writer::end_div('hermes-tool-modal-content');
echo html_writer::end_div('hermes-tool-modal');

echo html_writer::end_div('hermes-main');
echo html_writer::end_div('hermes-chat-container');

// Pass config to JS - stored in a hidden div with data-config attribute
echo html_writer::start_div('hermes-config', [
    'id' => 'hermes-config',
    'style' => 'display:none;',
    'data-config' => json_encode([
        'conversationid' => $current_id,
        'userid' => $USER->id,
        'token' => sesskey(),
        'bridge_port' => $bridge_port,
        'sesskey' => sesskey(),
    ]),
]);
echo html_writer::end_div();

echo $OUTPUT->footer();
