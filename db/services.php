<?php
defined('MOODLE_INTERNAL') || die();

$functions = [
    'local_hermesagent_send_message' => [
        'classname'     => 'local_hermesagent\external\chat_api',
        'methodname'    => 'send_message',
        'description'   => 'Send a chat message',
        'type'          => 'write',
        'ajax'          => true,
        'capabilities'  => 'local/hermesagent:use',
    ],
    'local_hermesagent_get_history' => [
        'classname'     => 'local_hermesagent\external\chat_api',
        'methodname'    => 'get_history',
        'description'   => 'Get conversation history',
        'type'          => 'read',
        'ajax'          => true,
        'capabilities'  => 'local/hermesagent:use',
    ],
    'local_hermesagent_tool_response' => [
        'classname'     => 'local_hermesagent\external\chat_api',
        'methodname'    => 'tool_response',
        'description'   => 'Approve/reject tool execution',
        'type'          => 'write',
        'ajax'          => true,
        'capabilities'  => 'local/hermesagent:approve_tools',
    ],
    'local_hermesagent_get_conversations' => [
        'classname'     => 'local_hermesagent\external\chat_api',
        'methodname'    => 'get_conversations',
        'description'   => 'List all conversations',
        'type'          => 'read',
        'ajax'          => true,
        'capabilities'  => 'local/hermesagent:use',
    ],
    'local_hermesagent_delete_conversation' => [
        'classname'     => 'local_hermesagent\external\chat_api',
        'methodname'    => 'delete_conversation',
        'description'   => 'Delete a conversation',
        'type'          => 'write',
        'ajax'          => true,
        'capabilities'  => 'local/hermesagent:use',
    ],
    'local_hermesagent_rename_conversation' => [
        'classname'     => 'local_hermesagent\\external\\chat_api',
        'methodname'    => 'rename_conversation',
        'description'   => 'Rename a conversation',
        'type'          => 'write',
        'ajax'          => true,
        'capabilities'  => 'local/hermesagent:use',
    ],
];
