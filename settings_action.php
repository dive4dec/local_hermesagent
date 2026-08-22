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

// Launch a hermes-venv.sh subcommand in the background (it takes minutes),
// writing output to a pidfile (liveness) + log (tail). Returns the log path.
function hermes_venv_bg(string $op, string $arg, string $logname, string $hermes_home): string {
    $script = __DIR__ . '/scripts/hermes-venv.sh';
    $log = $hermes_home . '/' . $logname;
    $pid = $hermes_home . '/.' . $logname . '.pid';
    $argq = escapeshellarg($arg);
    $cmd = '( HERMES_HOME=' . escapeshellarg($hermes_home) . ' sh ' . escapeshellarg($script) . ' ' . $op . ' ' . $argq
        . ' >> ' . escapeshellarg($log) . ' 2>&1; rm -f ' . escapeshellarg($pid) . ' ) & echo $! > ' . escapeshellarg($pid);
    exec($cmd);
    return $log;
}

// Is any venv operation (update / snapshot / restore) in progress?
// Each backgrounds a subshell that writes a pidfile and removes it on exit.
// We check liveness with posix_kill so a stale pidfile (crashed op) is ignored.
function hermes_venv_op_running(string $hermes_home): bool {
    foreach (['bootstrap.pid', '.venv_snapshot.log.pid', '.venv_restore.log.pid'] as $pf) {
        $path = $hermes_home . '/' . $pf;
        if (is_file($path)) {
            $pid = intval(trim((string)@file_get_contents($path)));
            if ($pid > 0 && @posix_kill($pid, 0)) {
                return true;
            }
            @unlink($path); // stale
        }
    }
    return false;
}

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
        // Update = auto-snapshot current venv, THEN bootstrap (uv --upgrade
        // hermes-agent). Both run in ONE backgrounded subshell (the snapshot
        // alone takes ~60s and bootstrap minutes, so chaining them avoids two
        // round-trips and guarantees a rollback point exists before anything
        // changes). If the snapshot fails we still proceed with the upgrade
        // (a failed backup should not block an update) — the log records it.
        if (!is_dir($hermes_home)) {
            mkdir($hermes_home, 0777, true);
        }
        // Guard against a concurrent venv operation.
        if (hermes_venv_op_running($hermes_home)) {
            $message = 'A snapshot / update / restore is already in progress. Check its status below.';
            redirect($redirect_url, $message, 5, \core\output\notification::NOTIFY_WARNING);
        }
        $bootstrap_script = escapeshellarg(__DIR__ . '/scripts/bootstrap.sh');
        $venv_script = escapeshellarg(__DIR__ . '/scripts/hermes-venv.sh');
        $bridge_script = escapeshellarg(__DIR__ . '/hermes-bridge-control.sh');
        $log_file = $hermes_home . '/bootstrap_update.log';
        $pid_file = $hermes_home . '/bootstrap.pid';
        $env = 'HERMES_HOME=' . escapeshellarg($hermes_home);
        // Backgrounded SUBSHELL: (1) snapshot, (2) bootstrap (uv --upgrade),
        // (3) restart the bridge so the new code actually loads, then remove
        // the pidfile. The subshell PID ($!) stays alive for the whole run —
        // the reliable liveness marker settings.php checks via posix_kill.
        $cmd = '( ' . $env . ' sh ' . $venv_script . ' snapshot >> ' . escapeshellarg($log_file)
            . ' 2>&1; ' . $env . ' sh ' . $bootstrap_script . ' >> ' . escapeshellarg($log_file)
            . ' 2>&1; sh ' . $bridge_script . ' restart >> ' . escapeshellarg($log_file)
            . ' 2>&1; rm -f ' . escapeshellarg($pid_file) . ' ) & echo $! > ' . escapeshellarg($pid_file);
        exec($cmd);
        $message = 'Update started in background — it snapshots the current venv (rollback point), '
            . 'upgrades hermes-agent, then restarts the bridge. Check the status below.';
        break;

    case 'snapshot':
        // Manual snapshot only (a rollback point) — no upgrade.
        if (hermes_venv_op_running($hermes_home)) {
            $message = 'A venv operation is already in progress. Check its status below.';
            redirect($redirect_url, $message, 5, \core\output\notification::NOTIFY_WARNING);
        }
        hermes_venv_bg('snapshot', '', 'venv_snapshot.log', $hermes_home);
        $message = 'Snapshot started in background (current venv is the rollback point). See status below.';
        break;

    case 'restore':
        // Roll the venv back to a specific snapshot. The snapshot name comes
        // from the admin clicking a row in the backups table.
        $snap = required_param('snapshot', PARAM_FILE); // filename only, e.g. venv-0.18.2_20260822_035914.tar.gz
        $snap_path = $hermes_home . '/backups/' . $snap;
        if (!is_file($snap_path)) {
            $message = 'Snapshot not found: ' . $snap . ' (it may have been pruned).';
            redirect($redirect_url, $message, 5, \core\output\notification::NOTIFY_ERROR);
        }
        if (hermes_venv_op_running($hermes_home)) {
            $message = 'A venv operation is already in progress. Check its status below.';
            redirect($redirect_url, $message, 5, \core\output\notification::NOTIFY_WARNING);
        }
        hermes_venv_bg('restore', $snap, 'venv_restore.log', $hermes_home);
        $message = 'Restore started in background — rolling venv back to ' . $snap
            . '. The bridge stops and restarts during this. See status below.';
        break;

    case 'sync_scripts':
        // Removed: deployments should go through standard plugin installation
        // (make sync + Update & Bootstrap), not a separate quick-sync button.
        $message = 'Sync Scripts has been removed. Use "Update & Bootstrap" after installing the plugin.';
        break;

    default:
        $message = 'Unknown action: ' . $action;
}

redirect($redirect_url, $message, 5, \core\output\notification::NOTIFY_INFO);
