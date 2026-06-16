<?php
/**
 * Handle settings actions (start/stop/restart/update) for local_hermesagent
 * This is accessed directly via URL, not through admin navigation
 */

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

require_login();
require_capability('local/hermesagent:configure', context_system::instance());

$hermes_home = '/var/www/moodledata/.hermes';
$action = required_param('action', PARAM_ALPHANUM);
confirm_sesskey();

$bridge_port = get_config('local_hermesagent', 'bridge_port');
if (empty($bridge_port)) {
    $bridge_port = '9118';
}

$redirect_url = $CFG->wwwroot . '/admin/settings.php?section=local_hermesagent_settings';
$message = '';

switch ($action) {
        case 'start':
        exec('tmux has-session -t hermes-acp 2>&1', $output, $ret);
        if ($ret !== 0) {
            $cmd = 'tmux new-session -d -s hermes-acp -x 80 -y 24 "su -s /bin/sh -c \"HERMES_HOME=' . $hermes_home . ' ' . $hermes_home . '/venv/bin/hermes acp\" www-data" 2>&1';
            exec($cmd, $output, $ret);
            $message = $ret === 0 ? 'ACP started (www-data)' : 'Failed: ' . implode(' ', $output);
        } else {
            $message = 'ACP already running';
        }
        break;

    case 'stop':
        exec('tmux kill-session -t hermes-acp 2>&1', $output, $ret);
        $message = $ret === 0 ? 'ACP stopped' : 'Failed: ' . implode(' ', $output);
        break;

    case 'restart':
        exec('tmux kill-session -t hermes-acp 2>&1', $output, $ret);
        sleep(1);
        $cmd = 'tmux new-session -d -s hermes-acp -x 80 -y 24 "su -s /bin/sh -c \"HERMES_HOME=' . $hermes_home . ' ' . $hermes_home . '/venv/bin/hermes acp\" www-data" 2>&1';
        exec($cmd, $output, $ret);
        sleep(3);
        exec('tmux capture-pane -t hermes-acp -p 2>&1', $output, $ret2);
        $output_str = implode(' ', $output);
        if (strpos($output_str, 'ACP client connected') !== false) {
            $message = 'ACP restarted (www-data, MCP: ' . (strpos($output_str, 'mcp_moodle_db') !== false ? '✅' : '⚠') . ')';
        } else {
            $message = 'ACP restart: ' . htmlspecialchars($output_str);
        }
        break;
    case 'update':
        // Pull latest plugin code
        $plugin_dir = __DIR__;
        exec('cd "' . $plugin_dir . '" && git pull 2>&1', $output, $ret);
        $update_result = $ret === 0 ? 'git pull OK' : 'git pull: ' . implode(" ", $output);
        // Run bootstrap
        exec('"' . __DIR__ . '/scripts/bootstrap.sh" 2>&1', $bootstrap_output, $ret2);
        $bootstrap_result = implode(" ", $bootstrap_output);
        $message = $update_result . " | Bootstrap: " . $bootstrap_result;
        break;

    default:
        $message = "Unknown action: " . $action;
}

redirect($redirect_url, $message, 3, \core\output\notification::NOTIFY_INFO);
