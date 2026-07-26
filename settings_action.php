<?php
/**
 * Handle settings actions (start/stop/restart/update) for local_hermesagent.
 * Accessed directly via URL from the admin settings page.
 *
 * Uses hermes-bridge-control.sh for process management (no tmux).
 */

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

require_login();
require_capability('local/hermesagent:configure', context_system::instance());

$hermes_home = '/var/www/moodledata/.hermes';
$action = required_param('action', PARAM_ALPHANUM);
confirm_sesskey();

$bridge_port = local_hermesagent_get_bridge_port();
$redirect_url = $CFG->wwwroot . '/admin/settings.php?section=local_hermesagent_settings';
$message = '';

// The control script lives in the plugin directory.
$control_script = __DIR__ . '/hermes-bridge-control.sh';

switch ($action) {
    case 'start':
        $cmd = escapeshellarg($control_script) . ' start 2>&1';
        exec($cmd, $output, $ret);
        $message = implode("\n", $output);
        if ($ret === 0 && strpos($message, 'FAILED') === false) {
            $message = 'ACP Bridge started: ' . $message;
        } else {
            $message = 'Failed to start: ' . $message;
        }
        break;

    case 'stop':
        $cmd = escapeshellarg($control_script) . ' stop 2>&1';
        exec($cmd, $output, $ret);
        $message = 'ACP Bridge stopped';
        break;

    case 'restart':
        $cmd = escapeshellarg($control_script) . ' restart 2>&1';
        exec($cmd, $output, $ret);
        $output_str = implode("\n", $output);
        sleep(2);
        // Health check after restart
        $ch = curl_init("http://127.0.0.1:$bridge_port/health");
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 3]);
        $resp = curl_exec($ch);
        $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($resp !== false && $http == 200) {
            $message = 'ACP Bridge restarted: ' . $output_str;
        } else {
            $message = 'Restarted but bridge not responding on port ' . $bridge_port . ': ' . $output_str;
        }
        break;

    case 'update':
        // Run bootstrap.sh to install/update Hermes venv + bridge + MCP scripts.
        // Plugin code updates come from the host via `make sync`, not git pull.
        // Run in background so the HTTP request returns immediately — bootstrap
        // takes minutes (git clone, pip install, etc.) which would exceed
        // nginx/ingress timeouts and cause ERR_CONNECTION_ABORTED.
        // Create .hermes dir first — it may not exist on first bootstrap.
        if (!is_dir($hermes_home)) {
            mkdir($hermes_home, 0777, true);
        }
        $bootstrap_script = escapeshellarg(__DIR__ . '/scripts/bootstrap.sh');
        $log_file = escapeshellarg($hermes_home . '/bootstrap_update.log');
        $env = 'HERMES_HOME=' . escapeshellarg($hermes_home);
        $cmd = $env . ' sh ' . $bootstrap_script . ' > ' . $log_file . ' 2>&1 &';
        exec($cmd);
        $message = 'Update started in background. Check the bridge status below or see '
            . $hermes_home . '/bootstrap_update.log for details.';
        break;

    case 'sync_scripts':
        // Quick sync: copy MCP server and bridge scripts from plugin dir to .hermes/,
        // then restart the bridge. Use this after `make sync` when only plugin code
        // changed (no need for full bootstrap which reinstalls the venv).
        $plugin_dir = __DIR__;
        $synced = [];
        $errors = [];

        // MCP server script
        $mcp_src = $plugin_dir . '/scripts/moodle_db_mcp.py';
        $mcp_dst = $hermes_home . '/mcp_servers/moodle_db_mcp.py';
        if (file_exists($mcp_src)) {
            if (!is_dir(dirname($mcp_dst))) {
                mkdir(dirname($mcp_dst), 0777, true);
            }
            if (copy($mcp_src, $mcp_dst)) {
                chmod($mcp_dst, 0755);
                $synced[] = 'moodle_db_mcp.py';
            } else {
                $errors[] = 'Failed to copy moodle_db_mcp.py';
            }
        }

        // ACP bridge script
        $bridge_src = $plugin_dir . '/classes/bridge/acp_bridge.py';
        $bridge_dst = $hermes_home . '/classes/bridge/acp_bridge.py';
        if (file_exists($bridge_src)) {
            if (!is_dir(dirname($bridge_dst))) {
                mkdir(dirname($bridge_dst), 0777, true);
            }
            if (copy($bridge_src, $bridge_dst)) {
                $synced[] = 'acp_bridge.py';
            } else {
                $errors[] = 'Failed to copy acp_bridge.py';
            }
        }

        // ACP timeout patch script
        $patch_src = $plugin_dir . '/scripts/patch_acp_timeout.py';
        if (file_exists($patch_src)) {
            $patch_cmd = escapeshellarg($hermes_home . '/venv/bin/python') . ' ' . escapeshellarg($patch_src) . ' 2>&1';
            exec($patch_cmd, $patch_out, $patch_ret);
            if ($patch_ret === 0) {
                $synced[] = 'acp_timeout patch';
            } else {
                $errors[] = 'ACP timeout patch failed (non-fatal)';
            }
        }

        // Restart bridge to pick up new scripts
        $restart_cmd = escapeshellarg($control_script) . ' restart 2>&1';
        exec($restart_cmd, $restart_out, $restart_ret);
        sleep(3);

        if (empty($errors)) {
            $message = 'Synced: ' . implode(', ', $synced) . '. Bridge restarted.';
        } else {
            $message = 'Synced: ' . implode(', ', $synced) . '. Errors: ' . implode('; ', $errors);
        }
        break;

    default:
        $message = 'Unknown action: ' . $action;
}

redirect($redirect_url, $message, 5, \core\output\notification::NOTIFY_INFO);
