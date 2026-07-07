<?php
defined('MOODLE_INTERNAL') || die();

$string['pluginname'] = 'Hermes Agent';
$string['pluginadministration'] = 'Hermes Agent administration';

// Terminal
$string['terminal'] = 'Hermes Terminal';

// Settings
$string['quick_links'] = 'Quick Links';
$string['acp_bridge'] = 'ACP Bridge';
$string['hermes_config'] = 'Hermes Configuration';
$string['settings_description'] = 'Configure the Hermes Agent bridge and provider settings.';
$string['hermes_description'] = 'Path to the hermes CLI. Leave blank to search PATH.';
$string['bridge_port'] = 'Bridge port';
$string['bridge_port_desc'] = 'Local port for the ACP bridge HTTP service.';
$string['config_yaml'] = 'Hermes config.yaml';
$string['config_yaml_desc'] = 'Direct editor for $HERMES_HOME/config.yaml — the Hermes configuration file (model, provider, agent, toolsets, etc.). Changes are written on Save. This is the single source of truth: the dashboard and CLI edit the same file.';
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
$string['privacy:metadata:conversation'] = 'Conversation metadata including name and timestamps.';
$string['privacy:metadata:conversation:id'] = 'Conversation ID';
$string['privacy:metadata:conversation:name'] = 'Conversation name';
$string['privacy:metadata:conversation:usermodified'] = 'User who modified the conversation';
$string['privacy:metadata:conversation:acpsessionid'] = 'ACP session identifier';
$string['privacy:metadata:conversation:timemodified'] = 'Last modified timestamp';
$string['privacy:metadata:conversation:timecreated'] = 'Created timestamp';
$string['privacy:metadata:message'] = 'Chat message content including role, text, and tool data.';
$string['privacy:metadata:message:id'] = 'Message ID';
$string['privacy:metadata:message:conversationid'] = 'Parent conversation ID';
$string['privacy:metadata:message:role'] = 'Message role (user/assistant)';
$string['privacy:metadata:message:content'] = 'Message text content';
$string['privacy:metadata:message:toolcalls'] = 'Tool call data';
$string['privacy:metadata:message:toolresults'] = 'Tool execution results';
$string['privacy:metadata:message:timemodified'] = 'Last modified timestamp';
$string['privacy:conversations'] = 'Conversations';
$string['privacy:messages'] = 'Messages';

$string['backto'] = 'Back to settings';
$string['rename'] = 'Rename';

// Gateway
$string['gateway'] = 'Messaging Gateway';
$string['gateway_desc'] = 'Connect Hermes to messaging platforms (Matrix, Telegram, Discord, Signal, and 15+ others) so you can chat with the AI from Element, Telegram, or other apps.';
$string['gateway_env'] = 'Gateway .env';
$string['gateway_env_desc'] = 'Direct editor for $HERMES_HOME/.env — platform environment variables (one per line). Supports MATRIX_*, TELEGRAM_*, DISCORD_*, SIGNAL_*, and more. Changes are written on Save. The Dashboard edits this same file — they stay in sync.';
$string['gateway_not_configured'] = 'No platform config found — paste env vars below or use the Dashboard, then click Start.';

// Slash commands
$string['slash_help'] = 'Slash commands: /stop (abort response), /new (new conversation), /clear (clear view), /status (bridge health), /help (this message)';
$string['slash_stopped'] = 'Response stopped.';
$string['slash_cleared'] = 'Conversation view cleared.';
