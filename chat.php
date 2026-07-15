<?php
/**
 * Chat interface
 *
 * @package    local_hermesagent
 * @copyright  2026
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// Enable detailed error reporting for debugging
@ini_set('display_errors', 1);
@ini_set('display_startup_errors', 1);
@error_reporting(E_ALL);

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

require_login();
// Soft capability check - capability may not be registered yet
// Only site admins may access the chat interface
if (!is_siteadmin()) {
    if (!has_capability('local/hermesagent:use', context_system::instance())) {
        throw new moodle_exception('nopermissions', '', '', 'Access restricted to site administrators only.');
    }
}

$conversationid = optional_param('conversationid', 0, PARAM_INT);
$action = optional_param('action', '', PARAM_ALPHA);

$PAGE->set_url(new moodle_url('/local/hermesagent/chat.php', [
    'conversationid' => $conversationid,
    'action' => $action,
]));
$PAGE->set_context(context_system::instance());
$PAGE->set_title(get_string('pluginname', 'local_hermesagent'));
$PAGE->set_heading(get_string('pluginname', 'local_hermesagent'));
$PAGE->requires->css(new moodle_url('/local/hermesagent/styles/chat.css', ['v' => '20260708f']));
// Load the chat AMD module (proper Moodle way)
$PAGE->requires->js_call_amd('local_hermesagent/chat', 'init');



echo $OUTPUT->header();

// Conversation list sidebar
global $DB;
$conversations = $DB->get_records('local_hermesagent_conversations', ['usermodified' => $USER->id], 'timemodified DESC');

// Create new conversation if needed
$current_id = $conversationid;
if ($action == 'new') {
    // Explicitly requested new conversation — create then redirect (PRG pattern)
    // so that refreshing the page does not create duplicate conversations.
    $rec = new stdClass();
    $rec->name = get_string('newconversation', 'local_hermesagent');
    $rec->usermodified = $USER->id;
    $rec->timecreated = time();
    $rec->timemodified = time();
    $current_id = $DB->insert_record('local_hermesagent_conversations', $rec);
    redirect(new moodle_url('/local/hermesagent/chat.php', ['conversationid' => $current_id]));
} else if ($current_id > 0) {
    $conv = $DB->get_record('local_hermesagent_conversations', ['id' => $current_id], '*', MUST_EXIST);
} else if (!empty($conversations)) {
    // No conversationid in URL - default to most recent conversation
    $most_recent = reset($conversations);
    $current_id = $most_recent->id;
} else {
    // No existing conversations - create first one, then redirect (PRG pattern)
    $rec = new stdClass();
    $rec->name = get_string('newconversation', 'local_hermesagent');
    $rec->usermodified = $USER->id;
    $rec->timecreated = time();
    $rec->timemodified = time();
    $current_id = $DB->insert_record('local_hermesagent_conversations', $rec);
    redirect(new moodle_url('/local/hermesagent/chat.php', ['conversationid' => $current_id]));
}

// Get bridge status (live check)
$bridge_port = local_hermesagent_get_bridge_port();
$bridge_status = local_hermesagent_check_bridge_status();

// Conversation list
echo html_writer::start_div('hermes-chat-container');

echo html_writer::start_div('hermes-sidebar');
echo html_writer::start_div('hermes-sidebar-header');
echo html_writer::start_div('hermes-sidebar-title-row');
echo html_writer::tag('button', '+', [
    'id' => 'hermes-new-conv',
    'class' => 'hermes-new-conv-btn',
    'title' => get_string('newconversation', 'local_hermesagent'),
]);
echo html_writer::start_div('hermes-search-container');
echo html_writer::empty_tag('input', [
    'type' => 'text',
    'id' => 'hermes-conv-search',
    'class' => 'hermes-search-input form-control',
    'placeholder' => 'Filter conversations…',
    'autocomplete' => 'off',
]);
echo html_writer::tag('button', '✕', [
    'id' => 'hermes-search-clear',
    'class' => 'hermes-search-clear',
    'title' => 'Clear search',
    'type' => 'button',
    'style' => 'display:none;',
]);
echo html_writer::end_div();
echo html_writer::tag('button', '◀', [
    'id' => 'hermes-sidebar-collapse',
    'class' => 'hermes-sidebar-collapse-btn',
    'title' => 'Collapse sidebar',
]);
echo html_writer::end_div('hermes-sidebar-title-row');

// Bulk action toolbar (hidden until a checkbox is checked)
echo html_writer::start_div('hermes-bulk-actions', ['style' => 'display:none;']);
echo html_writer::tag('button', '🗑 Delete', [
    'id' => 'hermes-bulk-delete',
    'class' => 'btn btn-danger btn-sm',
    'title' => 'Delete selected',
]);
echo html_writer::tag('button', '⧉ Duplicate', [
    'id' => 'hermes-bulk-duplicate',
    'class' => 'btn btn-secondary btn-sm',
    'title' => 'Duplicate selected',
]);
echo html_writer::tag('button', '✕', [
    'id' => 'hermes-bulk-cancel',
    'class' => 'btn btn-link btn-sm hermes-bulk-cancel',
    'title' => 'Cancel selection',
]);
echo html_writer::end_div();

echo html_writer::end_div('hermes-sidebar-header');

echo html_writer::start_div('hermes-conversation-list');
foreach ($conversations as $conv) {
    $cls = $conv->id == $current_id ? ' active' : '';
    echo html_writer::start_div('hermes-conv-item' . $cls, [
        'data-conv-id' => $conv->id,
        'data-conv-name' => strtolower($conv->name),
        'class' => 'hermes-conv-item' . $cls,
    ]);
    echo html_writer::empty_tag('input', [
        'type' => 'checkbox',
        'class' => 'hermes-conv-checkbox',
        'data-conv-id' => $conv->id,
        'style' => 'display:none;',
    ]);
    echo html_writer::tag('span', format_text($conv->name, FORMAT_PLAIN), [
        'class' => 'hermes-conv-name',
        'data-conv-id' => $conv->id,
    ]);
    // Timestamp
    echo html_writer::tag('span', userdate($conv->timemodified, '%b %d, %H:%M'), [
        'class' => 'hermes-conv-time',
    ]);
    echo html_writer::tag('button', '✎', [
        'class' => 'hermes-conv-rename',
        'data-conv-id' => $conv->id,
        'data-conv-name' => s($conv->name),
        'title' => get_string('rename', 'local_hermesagent'),
    ]);
    echo html_writer::tag('button', '⧉', [
        'class' => 'hermes-conv-duplicate',
        'data-conv-id' => $conv->id,
        'title' => 'Duplicate',
    ]);
    echo html_writer::end_div();
}
echo html_writer::end_div('hermes-conversation-list');

echo html_writer::start_div('hermes-sidebar-footer');
echo html_writer::end_div('hermes-sidebar-footer');
echo html_writer::end_div('hermes-sidebar');

// Resize handle between sidebar and chat area (sibling of sidebar, not child)
echo html_writer::start_div('hermes-sidebar-resizer', ['id' => 'hermes-sidebar-resizer']);
echo html_writer::end_div('hermes-sidebar-resizer');

// Expand button (visible when sidebar is collapsed)
echo html_writer::tag('button', '▶', [
    'id' => 'hermes-sidebar-expand',
    'class' => 'hermes-sidebar-expand-btn',
    'title' => 'Expand sidebar',
    'style' => 'display:none;',
]);

// Main chat area
echo html_writer::start_div('hermes-main');
echo html_writer::start_div('hermes-chat-area', ['id' => 'hermes-chat-area']);
echo html_writer::end_div('hermes-chat-area');

// Input area
echo html_writer::start_div('hermes-input-area');

// Quote preview bar (hidden by default, shown when quoting)
echo html_writer::start_div('hermes-quote-preview', [
    'id' => 'hermes-quote-preview',
    'style' => 'display:none;',
]);
echo html_writer::tag('span', '', ['id' => 'hermes-quote-label', 'class' => 'hermes-quote-label']);
echo html_writer::tag('span', '', ['id' => 'hermes-quote-text', 'class' => 'hermes-quote-text']);
echo html_writer::tag('button', '✕', [
    'id' => 'hermes-quote-cancel',
    'class' => 'hermes-quote-cancel',
    'title' => 'Remove quote',
]);
echo html_writer::end_div('hermes-quote-preview');

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

echo html_writer::end_div('hermes-main');
echo html_writer::end_div('hermes-chat-container');

// Pass config to JS - stored in a hidden div with data-config attribute
$api_url_obj = new moodle_url('/local/hermesagent/api.php');
$api_url = $api_url_obj->out(false);
echo html_writer::start_div('hermes-config', [
    'id' => 'hermes-config',
    'style' => 'display:none;',
    'data-config' => json_encode([
        'conversationid' => $current_id,
        'userid' => $USER->id,
        'token' => sesskey(),
        'bridge_port' => $bridge_port,
        'sesskey' => sesskey(),
        'api_url' => $api_url,
    ]),
]);
echo html_writer::end_div();

try {
    echo $OUTPUT->footer();
} catch (Exception $e) {
    error_log('HERMES FATAL: ' . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine());
    die('Hermes agent error: ' . $e->getMessage());
}
