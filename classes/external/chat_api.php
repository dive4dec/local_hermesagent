<?php
namespace local_hermesagent\external;

use external_api;
use external_function_parameters;
use external_single_structure;
use external_multiple_structure;
use external_value;
use external_optional_param;
use context_system;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');

class chat_api extends external_api {

    public static function send_message_parameters() {
        return new external_function_parameters([
            'conversationid' => new external_value(PARAM_INT, 'Conversation ID'),
            'message' => new external_value(PARAM_RAW, 'Message text'),
        ]);
    }

    public static function send_message($conversationid, $message) {
        global $DB, $USER;

        $params = self::validate_parameters(self::send_message_parameters(), [
            'conversationid' => $conversationid,
            'message' => $message,
        ]);

        // Soft capability check - capability may not be registered yet
if (!is_siteadmin($USER) && !has_capability('local/hermesagent:use', context_system::instance())) {
    throw new moodle_exception('nopermissions', '', '', '');
}

        // Check conversation ownership
        $conv = $DB->get_record('local_hermesagent_conversations', [
            'id' => $params['conversationid'],
            'usermodified' => $USER->id,
        ], '*');

        if (!$conv) {
            throw new \moodle_exception('invalidconversation');
        }

        $rec = new \stdClass();
        $rec->conversationid = $params['conversationid'];
        $rec->role = 'user';
        $rec->content = $params['message'];
        $rec->timemodified = time();
        $msgid = $DB->insert_record('local_hermesagent_messages', $rec);

        // Update conversation
        $conv->timemodified = time();
        if ($conv->name === get_string('newconversation', 'local_hermesagent')) {
            $conv->name = clean_param(substr($params['message'], 0, 60), PARAM_NOTAGS);
        }
        $DB->update_record('local_hermesagent_conversations', $conv);

        return ['messageid' => $msgid, 'conversationid' => $params['conversationid']];
    }

    public static function send_message_returns() {
        return new external_single_structure([
            'messageid' => new external_value(PARAM_INT, 'Message ID'),
            'conversationid' => new external_value(PARAM_INT, 'Conversation ID'),
        ]);
    }

    public static function get_history_parameters() {
        return new external_function_parameters([
            'conversationid' => new external_value(PARAM_INT, 'Conversation ID'),
        ]);
    }

    public static function get_history($conversationid) {
        global $DB, $USER;

        $params = self::validate_parameters(self::get_history_parameters(), [
            'conversationid' => $conversationid,
        ]);

        // Soft capability check - capability may not be registered yet
if (!is_siteadmin($USER) && !has_capability('local/hermesagent:use', context_system::instance())) {
    throw new moodle_exception('nopermissions', '', '', '');
}

        // Check conversation ownership
        $conv = $DB->get_record('local_hermesagent_conversations', [
            'id' => $params['conversationid'],
            'usermodified' => $USER->id,
        ], '*');

        if (!$conv) {
            throw new \moodle_exception('invalidconversation');
        }

        $messages = $DB->get_records('local_hermesagent_messages', ['conversationid' => $params['conversationid']], 'id ASC');

        $result = [];
        foreach ($messages as $msg) {
            $result[] = [
                'id' => $msg->id,
                'role' => $msg->role,
                'content' => $msg->content,
                'timemodified' => $msg->timemodified,
            ];
        }

        return ['messages' => $result];
    }

    public static function get_history_returns() {
        return new external_single_structure([
            'messages' => new external_multiple_structure(new external_single_structure([
                'id' => new external_value(PARAM_INT, 'Message ID'),
                'role' => new external_value(PARAM_ALPHA, 'Role: user, assistant, tool'),
                'content' => new external_value(PARAM_RAW, 'Message content'),
                'timemodified' => new external_value(PARAM_INT, 'Timestamp'),
            ])),
        ]);
    }

    public static function tool_response_parameters() {
        return new external_function_parameters([
            'messageid' => new external_value(PARAM_RAW, 'Tool call ID (string)'),
            'approved' => new external_value(PARAM_BOOL, 'Whether tool was approved'),
        ]);
    }

    public static function tool_response($messageid, $approved) {
        self::validate_parameters(self::tool_response_parameters(), [
            'messageid' => $messageid,
            'approved' => $approved,
        ]);

        // Soft capability check - capability may not be registered yet
if (!is_siteadmin($USER) && !has_capability('local/hermesagent:use', context_system::instance())) {
    throw new moodle_exception('nopermissions', '', '', '');
}

        return ['status' => 'ok', 'approved' => (bool)$approved];
    }

    public static function tool_response_returns() {
        return new external_single_structure([
            'status' => new external_value(PARAM_ALPHA, 'Status'),
            'approved' => new external_value(PARAM_BOOL, 'Approved'),
        ]);
    }

    public static function get_conversations_parameters() {
        return new external_function_parameters([]);
    }

    public static function get_conversations() {
        global $DB, $USER;

        // Soft capability check - capability may not be registered yet
if (!is_siteadmin($USER) && !has_capability('local/hermesagent:use', context_system::instance())) {
    throw new moodle_exception('nopermissions', '', '', '');
}

        $conversations = $DB->get_records('local_hermesagent_conversations', ['usermodified' => $USER->id], 'timemodified DESC');

        $result = [];
        foreach ($conversations as $conv) {
            $result[] = [
                'id' => $conv->id,
                'name' => $conv->name,
                'timemodified' => $conv->timemodified,
            ];
        }

        return ['conversations' => $result];
    }

    public static function get_conversations_returns() {
        return new external_single_structure([
            'conversations' => new external_multiple_structure(new external_single_structure([
                'id' => new external_value(PARAM_INT, 'Conversation ID'),
                'name' => new external_value(PARAM_TEXT, 'Conversation name'),
                'timemodified' => new external_value(PARAM_INT, 'Timestamp'),
            ])),
        ]);
    }

    public static function delete_conversation_parameters() {
        return new external_function_parameters([
            'conversationid' => new external_value(PARAM_INT, 'Conversation ID'),
        ]);
    }

    public static function delete_conversation($conversationid) {
        global $DB, $USER;

        $params = self::validate_parameters(self::delete_conversation_parameters(), [
            'conversationid' => $conversationid,
        ]);

        // Soft capability check - capability may not be registered yet
if (!is_siteadmin($USER) && !has_capability('local/hermesagent:use', context_system::instance())) {
    throw new moodle_exception('nopermissions', '', '', '');
}

        $conv = $DB->get_record('local_hermesagent_conversations', ['id' => $params['conversationid'], 'usermodified' => $USER->id]);
        if ($conv) {
            $DB->delete_records('local_hermesagent_messages', ['conversationid' => $params['conversationid']]);
            $DB->delete_records('local_hermesagent_conversations', ['id' => $params['conversationid']]);
        }

        return ['deleted' => true];
    }

    public static function delete_conversation_returns() {
        return new external_single_structure([
            'deleted' => new external_value(PARAM_BOOL, 'Deleted'),
        ]);
    }

    public static function rename_conversation_parameters() {
        return new external_function_parameters([
            'conversationid' => new external_value(PARAM_INT, 'Conversation ID'),
            'name' => new external_value(PARAM_TEXT, 'New conversation name'),
        ]);
    }

    public static function rename_conversation($conversationid, $name) {
        global $DB, $USER;

        $params = self::validate_parameters(self::rename_conversation_parameters(), [
            'conversationid' => $conversationid,
            'name' => $name,
        ]);

        // Soft capability check - capability may not be registered yet
if (!is_siteadmin($USER) && !has_capability('local/hermesagent:use', context_system::instance())) {
    throw new moodle_exception('nopermissions', '', '', '');
}

        $conv = $DB->get_record('local_hermesagent_conversations', [
            'id' => $params['conversationid'],
            'usermodified' => $USER->id,
        ], '*');

        if (!$conv) {
            throw new \moodle_exception('invalidconversation');
        }

        $conv->name = clean_param(substr($params['name'], 0, 200), PARAM_TEXT);
        $conv->timemodified = time();
        $DB->update_record('local_hermesagent_conversations', $conv);

        return ['status' => 'ok'];
    }

    public static function rename_conversation_returns() {
        return new external_single_structure([
            'status' => new external_value(PARAM_ALPHA, 'Status'),
        ]);
    }


    public static function save_assistant_response_parameters() {
        return new external_function_parameters([
            'conversationid' => new external_value(PARAM_INT, 'Conversation ID'),
            'content' => new external_value(PARAM_RAW, 'Assistant response content'),
        ]);
    }

    public static function save_assistant_response($conversationid, $content) {
        global $DB, $USER;

        $params = self::validate_parameters(self::save_assistant_response_parameters(), [
            'conversationid' => $conversationid,
            'content' => $content,
        ]);

        // Soft capability check - capability may not be registered yet
if (!is_siteadmin($USER) && !has_capability('local/hermesagent:use', context_system::instance())) {
    throw new moodle_exception('nopermissions', '', '', '');
}

        // Check conversation exists and belongs to user
        $conv = $DB->get_record('local_hermesagent_conversations', [
            'id' => $params['conversationid'],
            'usermodified' => $USER->id,
        ], '*');

        if (!$conv) {
            throw new \moodle_exception('invalidconversation');
        }

        // Update the latest assistant message for this conversation (created empty during streaming)
        // This avoids creating duplicate rows
        $existing = $DB->get_record_sql(
            "SELECT id FROM {local_hermesagent_messages}
             WHERE conversationid = ? AND role = 'assistant'
             ORDER BY id DESC LIMIT 1",
            [$params['conversationid']]
        );

        if ($existing) {
            // Update existing assistant message
            $rec = new \stdClass();
            $rec->id = $existing->id;
            $rec->content = $params['content'];
            $rec->timemodified = time();
            $DB->update_record('local_hermesagent_messages', $rec);
            return ['status' => 'updated', 'messageid' => $existing->id];
        } else {
            // No assistant message exists — create one (fallback)
            $rec = new \stdClass();
            $rec->conversationid = $params['conversationid'];
            $rec->role = 'assistant';
            $rec->content = $params['content'];
            $rec->timemodified = time();
            $DB->insert_record('local_hermesagent_messages', $rec);
            return ['status' => 'created'];
        }
    }

    public static function save_assistant_response_returns() {
        return new external_single_structure([
            'status' => new external_value(PARAM_ALPHA, 'Status'),
        ]);
    }


}
