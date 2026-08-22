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

    // Poll until healthy (up to 30s). A cold bridge start takes 5-15s on a
    // 4-GPU pod (venv + hermes + acp child spawn); the old "sleep(1) then
    // return false" raced the boot and produced the "Connection error —
    // check console" that self-healed on the next attempt.
    $deadline = time() + 30;
    do {
        sleep(1);
        $ch = curl_init("http://127.0.0.1:$bridge_port/health");
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 2]);
        curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($http_code === 200) {
            error_log('HERMES [AUTO-START]: bridge healthy after ' . ($deadline - time() + 1) . 's');
            return true;
        }
    } while (time() < $deadline);

    // Still not healthy after 30s — genuine failure, let the user retry.
    error_log('HERMES [AUTO-START]: bridge not healthy after 30s poll');
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
 * Write the gateway .env file from the textarea setting.
 * Merges with existing .env (preserves non-platform lines).
 * Called when the user clicks Start/Restart Gateway.
 */
function local_hermesagent_write_gateway_env(): void {
    $hermes_home = getenv('HERMES_HOME') ?: '/var/www/moodledata/.hermes';
    $env_file = "$hermes_home/.env";

    // Get the textarea content from Moodle settings
    $new_env = get_config('local_hermesagent', 'gateway_env') ?: '';

    // Platform env var prefixes (these get replaced on each write)
    $platform_prefixes = [
        'MATRIX_', 'TELEGRAM_', 'DISCORD_', 'SIGNAL_', 'MATTERMOST_',
        'WHATSAPP_', 'WEIXIN_', 'IRC_', 'EMAIL_', 'LINE_', 'FEISHU_',
        'DINGTALK_', 'GOOGLE_CHAT_', 'QQ_', 'NTFY_', 'BLUEBUBBLES_',
        'YUANBAO_', 'HOME_ASSISTANT_',
    ];

    // Read existing .env, remove old platform lines
    $existing = [];
    if (file_exists($env_file)) {
        $lines = file($env_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            $is_platform = false;
            foreach ($platform_prefixes as $prefix) {
                if (strpos($line, $prefix) === 0) {
                    $is_platform = true;
                    break;
                }
            }
            if (!$is_platform) {
                $existing[] = $line;
            }
        }
    }

    // Append new platform lines from the textarea
    $new_lines = array_filter(array_map('trim', explode("\n", $new_env)));
    foreach ($new_lines as $line) {
        if (!empty($line) && strpos($line, '#') !== 0) {
            $existing[] = $line;
        }
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
 * Check if any platform config is present (in Moodle settings or .env).
 */
function local_hermesagent_is_gateway_configured(): bool {
    // Check the Moodle textarea setting
    $env_text = get_config('local_hermesagent', 'gateway_env') ?: '';
    if (!empty(trim($env_text))) {
        return true;
    }
    // Check the .env file directly (dashboard may have written it)
    $hermes_home = getenv('HERMES_HOME') ?: '/var/www/moodledata/.hermes';
    $env_file = "$hermes_home/.env";
    if (file_exists($env_file)) {
        $content = file_get_contents($env_file);
        return (bool) preg_match('/^(MATRIX_|TELEGRAM_|DISCORD_|SIGNAL_|MATTERMOST_|WHATSAPP_|WEIXIN_|IRC_|EMAIL_|LINE_|FEISHU_|DINGTALK_|GOOGLE_CHAT_|QQ_|NTFY_|BLUEBUBBLES_)/m', $content);
    }
    return false;
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

/**
 * Serve files from the local_hermesagent plugin file areas.
 *
 * @param stdClass $course Course object
 * @param stdClass $cm Course module object
 * @param context $context Context object
 * @param string $filearea File area
 * @param array $args Extra arguments
 * @param bool $forcedownload Whether to force download
 * @param array $options Additional options
 * @return void
 */
function local_hermesagent_pluginfile($course, $cm, $context, $filearea, $args, $forcedownload, array $options = []) {
    // Only serve chatimage file area
    if ($filearea !== 'chatimage') {
        return false;
    }

    // Must be logged in
    require_login();

    // Check capability
    if (!is_siteadmin() && !has_capability('local/hermesagent:use', $context)) {
        return false;
    }

    // Extract filename from args
    $itemid = array_shift($args);
    $filename = array_pop($args);
    $filepath = $args ? '/' . implode('/', $args) . '/' : '/';

    $fs = get_file_storage();
    $file = $fs->get_file($context->id, 'local_hermesagent', $filearea, $itemid, $filepath, $filename);

    if (!$file || $file->is_directory()) {
        return false;
    }

    // Send the file
    send_stored_file($file, 86400, 0, $forcedownload, $options);
}
