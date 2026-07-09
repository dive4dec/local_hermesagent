<?php
/**
 * Handle settings actions (start/stop/restart/update/install/downgrade) for local_hermesagent.
 * Accessed directly via URL from the admin settings page.
 *
 * Uses hermes-bridge-control.sh for process management (no tmux).
 * Includes advanced Hermes pip version management.
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
$bootstrap_script = escapeshellarg(__DIR__ . '/scripts/bootstrap.sh');
$env = 'HERMES_HOME=' . escapeshellarg($hermes_home);

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
        $message = '';
        $hermes_bin = "$hermes_home/venv/bin/hermes";
        $pip_bin = "$hermes_home/venv/bin/pip";

        if (file_exists($hermes_bin) && file_exists($pip_bin)) {
            // Get current version
            exec("$hermes_bin --version 2>&1", $hermes_version_out, $hermes_ret);
            $current_version = implode(" ", $hermes_version_out);
            
            // Extract version number if possible
            if (preg_match('/Hermes Agent v([0-9.]+)/', $current_version, $matches)) {
                $current_version_num = $matches[1];
                $message .= "Current Hermes: v" . $current_version_num . " | ";
                
                // Check for updates using pip index versions
                exec("$pip_bin index versions hermes-agent 2>&1", $version_check_out, $check_ret);
                if ($check_ret === 0) {
                    $latest_version = null;
                    $available_versions = [];
                    $output_text = implode("\n", $version_check_out);
                    
                    // Try to extract LATEST version
                    if (preg_match('/LATEST:\s*([\d.]+)/', $output_text, $lat_matches)) {
                        $latest_version = $lat_matches[1];
                    }
                    
                    // Also look for version numbers in the list
                    if (preg_match_all('/^\s*([\d.]+)(?:\s+\(.*\))?$/m', $output_text, $ver_matches)) {
                        foreach ($ver_matches[1] as $ver) {
                            if ($ver !== $current_version_num && !in_array($ver, $available_versions)) {
                                $available_versions[] = $ver;
                            }
                        }
                    }
                    
                    // Compare current version with latest available
                    if ($latest_version) {
                        if (version_compare($latest_version, $current_version_num, '>')) {
                            $message .= "⚠️ NEW VERSION AVAILABLE: v" . $latest_version . " (Current: v" . $current_version_num . ") | ";
                            $message .= "Upgrading to v" . $latest_version . "... | ";
                            
                            $upgrade_command = "$pip_bin install --upgrade hermes-agent==$latest_version 2>&1";
                            exec($upgrade_command, $upgrade_output, $upgrade_ret);
                            
                            if ($upgrade_ret === 0) {
                                $message .= "✅ Successfully upgraded to v" . $latest_version . " | ";
                            } else {
                                $message .= "❌ Upgrade failed: " . implode(" ", $upgrade_output) . " | ";
                            }
                        } else if (version_compare($latest_version, $current_version_num, '<')) {
                            $message .= "⚠️ CURRENT VERSION IS NEWER THAN LATEST: v" . $current_version_num . " | ";
                        } else {
                            $message .= "Hermes is up to date (v" . $current_version_num . ") | ";
                        }
                    } else {
                        $message .= "Could not determine latest Hermes version from pip | ";
                    }
                } else {
                    $message .= "Failed to check Hermes version (pip command error) | ";
                }
            } else {
                 $message .= "Could not parse current Hermes version | ";
            }
        } else {
            $message .= "Hermes pip/bin not found, relying on bootstrap to install | ";
        }
        
        // Run bootstrap.sh to sync venv and restart bridge
        $cmd = $env . ' sh ' . $bootstrap_script . ' 2>&1';
        exec($cmd, $output, $ret);
        $output_str = implode("\n", $output);
        if ($ret === 0) {
            $message .= 'Bootstrap complete: ' . $output_str;
        } else {
            $message .= 'Bootstrap errors (exit ' . $ret . '): ' . $output_str;
        }
        break;

    case 'downgrade':
        $target_version = required_param('version', PARAM_TEXT);
        $message = '';
        $pip_bin = "$hermes_home/venv/bin/pip";

        if (empty($target_version)) {
            $message = "Error: No version selected for downgrade.";
        } elseif (!file_exists($pip_bin)) {
            $message = "Error: Pip executable not found.";
        } else {
            $sanitized_version = preg_replace('/[^0-9.]/', '', $target_version);
            if (empty($sanitized_version)) {
                $message = "Error: Invalid version format provided.";
            } else {
                // Pass the target version to bootstrap.sh as $1 argument.
                // bootstrap.sh will handle pip install of the specific version internally.
                // Previously, pip install was done here but bootstrap.sh was called without
                // the version argument, causing it to re-install the latest version and
                // silently undo the downgrade.
                $cmd = $env . ' sh ' . $bootstrap_script . ' ' . escapeshellarg($sanitized_version) . ' 2>&1';
                exec($cmd, $output, $ret);
                $output_str = implode("\n", $output);

                if ($ret === 0) {
                    $message = "✅ Downgraded Hermes to v" . htmlspecialchars($sanitized_version) . ". Bootstrap complete: " . $output_str;
                } else {
                    $message = "Failed to downgrade to v" . htmlspecialchars($sanitized_version) . ". Error: " . $output_str;
                }
            }
        }
        break;

    default:
        $message = 'Unknown action: ' . $action;
}

redirect($redirect_url, $message, 5, \core\output\notification::NOTIFY_INFO);