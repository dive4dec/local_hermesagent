<?php
defined('MOODLE_INTERNAL') || die();

$string['pluginname'] = 'Hermes Agent';
$string['pluginadministration'] = 'Hermes Agent administration';

// Settings
$string['settings_description'] = 'Configure the Hermes Agent bridge and provider settings.';
$string['hermes_description'] = 'Path to the hermes CLI. Leave blank to search PATH.';
$string['bridge_port'] = 'Bridge port';
$string['bridge_port_desc'] = 'Local port for the ACP bridge HTTP service.';
$string['bridge_status'] = 'Bridge status';
$string['hermes_model'] = 'Model override';
$string['hermes_model_desc'] = 'Override the model used by Hermes. Leave blank to use your default profile.';
$string['hermes_home'] = 'Hermes home directory';
$string['hermes_home_desc'] = 'Custom HERMES_HOME path. Leave blank to use default.';

// Chat
$string['conversations'] = 'Conversations';
$string['newconversation'] = 'New conversation';
$string['type_message'] = 'Ask Hermes anything about your Moodle instance...';
$string['send'] = 'Send';
$string['approve'] = 'Approve';
$string['reject'] = 'Reject';
$string['tool_request'] = 'Tool Request';
$string['confirm_action'] = 'Do you want to approve this action?';

// Capabilities
$string['use'] = 'Access Hermes Agent chat';
$string['configure'] = 'Configure Hermes Agent';
$string['manage_skills'] = 'Manage learned skills';
$string['approve_tools'] = 'Approve tool execution';

// Privacy
$string['privacy:metadata'] = 'The Hermes Agent plugin stores chat conversations and learned skills.';

$string['backto'] = 'Back to settings';
$string['rename', 'local_hermesagent'] = 'Rename';
