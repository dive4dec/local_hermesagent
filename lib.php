<?php
/**
 * Core library functions
 *
 * @package    local_hermesagent
 * @copyright  2026
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Get plugin setting
 */
function local_hermesagent_get_setting(string $name, string $default = ''): string {
    global $DB;
    $record = $DB->get_record('local_hermesagent_settings', ['name' => $name], 'value', MUST_EXIST);
    return $record->value ?: $default;
}

/**
 * Set plugin setting
 */
function local_hermesagent_set_setting(string $name, string $value, string $description = ''): void {
    global $DB, $USER;
    $record = $DB->get_record('local_hermesagent_settings', ['name' => $name]);
    if ($record) {
        $record->value = $value;
        $record->description = $description;
        $record->timemodified = time();
        $DB->update_record('local_hermesagent_settings', $record);
    } else {
        $DB->insert_record('local_hermesagent_settings', (object)[
            'name' => $name,
            'value' => $value,
            'description' => $description,
            'timemodified' => time(),
        ]);
    }
}

/**
 * Get bridge port
 */
function local_hermesagent_get_bridge_port(): int {
    return (int)local_hermesagent_get_setting('bridge_port', '9118');
}

/**
 * Live-check the ACP bridge health via HTTP.
 * Does NOT write to the DB — this is called frequently and a DB write
 * on every check causes lock contention.
 */
function local_hermesagent_check_bridge_status(): string {
    $bridge_port = local_hermesagent_get_bridge_port();

    $ch = curl_init("http://127.0.0.1:$bridge_port/health");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 2,
    ]);

    $resp = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($resp !== false && $http_code === 200) {
        return 'running';
    }
    return 'stopped';
}

/**
 * Get all learned skills (enabled only)
 */
function local_hermesagent_get_skills(?string $category = null, bool $enabled_only = true): array {
    global $DB;
    $params = [];
    $where = '';
    if ($enabled_only) {
        $where = 'WHERE enabled = 1';
    }
    if ($category) {
        $where .= ($where ? ' AND ' : 'WHERE') . 'category = :cat';
        $params['cat'] = $category;
    }
    return $DB->get_records_sql("SELECT * FROM {local_hermesagent_skills} $where ORDER BY name ASC", $params);
}

/**
 * Ensure the ACP bridge is running. Starts it lazily if not.
 * Returns true if bridge is healthy after this call.
 *
 * This function does NOT block for 3 seconds — it starts the bridge and
 * does a single quick health check. The bridge takes ~2-5s to boot, so
 * the first request may fail; the frontend retries automatically.
 */
function local_hermesagent_ensure_bridge_running(int $bridge_port): bool {
    global $CFG;

    // Fast path: health check
    $ch = curl_init("http://127.0.0.1:$bridge_port/health");
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 2]);
    $resp = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($http_code === 200) {
        return true;
    }

    // Check if already starting (pidfile exists and process alive)
    $hermes_home = '/var/www/moodledata/.hermes';
    $pidfile = "$hermes_home/pids/acp-bridge.pid";
    if (file_exists($pidfile)) {
        $existing_pid = trim(file_get_contents($pidfile));
        if ($existing_pid && posix_kill(intval($existing_pid), 0)) {
            // Bridge is booting — don't block, let the user retry
            return false;
        }
        // Stale pidfile
        @unlink($pidfile);
    }

    // Slow path: start the bridge via the control script
    $control_script = $CFG->dirroot . '/local/hermesagent/hermes-bridge-control.sh';
    $cmd = escapeshellarg($control_script) . ' start 2>&1';
    exec($cmd, $output, $ret);
    error_log('HERMES [AUTO-START]: launching bridge via control script: ' . implode(' ', $output));

    // Give it a brief moment, then check (don't block for 3s)
    sleep(1);
    $ch = curl_init("http://127.0.0.1:$bridge_port/health");
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 2]);
    $resp = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($http_code === 200) {
        error_log('HERMES [AUTO-START]: bridge healthy after 1s');
        return true;
    }

    // Bridge is still booting — don't block, let user retry
    error_log('HERMES [AUTO-START]: bridge still booting, user should retry in ~5s');
    return false;
}

/**
 * Restart the ACP bridge process.
 * Returns true if healthy after restart.
 */
function local_hermesagent_restart_bridge(int $bridge_port): bool {
    global $CFG;

    $control_script = $CFG->dirroot . '/local/hermesagent/hermes-bridge-control.sh';
    $cmd = escapeshellarg($control_script) . ' restart 2>&1';
    exec($cmd, $output, $ret);
    sleep(2);

    // Health check
    $ch = curl_init("http://127.0.0.1:$bridge_port/health");
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 3]);
    $resp = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ($http_code === 200);
}

/**
 * Write the Matrix gateway config to $HERMES_HOME/.env so the gateway
 * process picks it up on start. Called when settings are saved.
 */
function local_hermesagent_write_gateway_env(): void {
    $hermes_home = getenv('HERMES_HOME') ?: '/var/www/moodledata/.hermes';
    $env_file = "$hermes_home/.env";

    $homeserver = get_config('local_hermesagent', 'matrix_homeserver');
    $user_id    = get_config('local_hermesagent', 'matrix_user_id');
    $token      = get_config('local_hermesagent', 'matrix_access_token');
    $rooms      = get_config('local_hermesagent', 'matrix_allowed_rooms');
    $device_id  = get_config('local_hermesagent', 'matrix_device_id');

    // Read existing .env, remove old MATRIX_ lines
    $existing = [];
    if (file_exists($env_file)) {
        $lines = file($env_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            if (strpos($line, 'MATRIX_') !== 0) {
                $existing[] = $line;
            }
        }
    }

    // Append new MATRIX_ lines
    if ($homeserver) {
        $existing[] = "MATRIX_HOMESERVER=$homeserver";
    }
    if ($user_id) {
        $existing[] = "MATRIX_USER_ID=$user_id";
    }
    if ($token) {
        $existing[] = "MATRIX_ACCESS_TOKEN=$token";
    }
    if ($rooms) {
        $existing[] = "MATRIX_ALLOWED_ROOMS=$rooms";
    }
    if ($device_id) {
        $existing[] = "MATRIX_DEVICE_ID=$device_id";
    }

    file_put_contents($env_file, implode("\n", $existing) . "\n");
    @chmod($env_file, 0600);
}

/**
 * Check if gateway is running (PID file + process alive).
 */
function local_hermesagent_is_gateway_running(): bool {
    $hermes_home = getenv('HERMES_HOME') ?: '/var/www/moodledata/.hermes';
    $pidfile = "$hermes_home/pids/gateway.pid";
    if (!file_exists($pidfile)) {
        return false;
    }
    $pid = trim(file_get_contents($pidfile));
    return $pid && posix_kill(intval($pid), 0);
}

/**
 * Check if Matrix gateway config is present.
 */
function local_hermesagent_is_gateway_configured(): bool {
    $homeserver = get_config('local_hermesagent', 'matrix_homeserver');
    $token      = get_config('local_hermesagent', 'matrix_access_token');
    return !empty($homeserver) && !empty($token);
}

/**
 * Register admin navigation — only visible to users with capability
 */
function local_hermesagent_extend_navigation_navigation(settings_navigation $nav, context_system $context) {
    if (!has_capability('local/hermesagent:use', $context)) {
        return;
    }

    $node = navigation_node::create(
        get_string('pluginname', 'local_hermesagent'),
        new moodle_url('/local/hermesagent/chat.php'),
        navigation_node::NODETYPE_LEAF,
        null,
        null,
        new pix_icon('i/settings', '')
    );

    $adminnode = $nav->get('root')->get('localplugins');
    if ($adminnode) {
        $adminnode->add_node($node);
    }
}
