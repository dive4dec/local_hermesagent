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
 * Live-check the ACP bridge health and sync the DB.
 * Replaces stale DB-only reads with a real HTTP ping.
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
        local_hermesagent_set_setting('bridge_status', 'running');
        return 'running';
    } else {
        local_hermesagent_set_setting('bridge_status', 'stopped');
        return 'stopped';
    }
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
 */
function local_hermesagent_ensure_bridge_running(int $bridge_port): bool {
    global $CFG;

    // Fast path: health check
    $ch = curl_init("http://127.0.0.1:$bridge_port/health");
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 2]);
    $resp = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($resp !== false && $http_code === 200) {
        return true;
    }

    // Slow path: start the bridge
    $hermes_home = '/var/www/moodledata/.hermes';
    $bridge_script = $CFG->dirroot . '/local/hermesagent/classes/bridge/acp_bridge.py';

    // Write DB credentials
    $cred_dir = "$hermes_home/.credentials";
    @mkdir($cred_dir, 0700, true);
    $cred_file = "$cred_dir/db.env";
    $cred_contents = sprintf(
        "MOODLE_DB_HOST=%s\nMOODLE_DB_NAME=%s\nMOODLE_DB_USER=%s\nMOODLE_DB_PASS=%s\n",
        escapeshellarg($CFG->dbhost),
        escapeshellarg($CFG->dbname),
        escapeshellarg($CFG->dbuser),
        escapeshellarg($CFG->dbpass)
    );
    file_put_contents($cred_file, $cred_contents, LOCK_EX);
    chmod($cred_file, 0600);

    $cmd = sprintf(
        'HERMES_HOME=%s MOODLE_DB_CREDENTIALS_FILE=%s nohup %s/venv/bin/python %s >> /var/www/moodledata/.hermes/logs/bridge.log 2>&1 & echo $!',
        escapeshellarg($hermes_home),
        escapeshellarg($cred_file),
        $hermes_home,
        escapeshellarg($bridge_script)
    );
    exec($cmd, $output, $ret);
    error_log("HERMES [AUTO-START]: $cmd pid=" . trim(implode("\n", $output)));

    return false; // caller sleeps then retries
}

/**
 * Restart the ACP bridge process.
 * Returns true if healthy after restart.
 */
function local_hermesagent_restart_bridge(int $bridge_port): bool {
    $hermes_home = '/var/www/moodledata/.hermes';

    // Kill existing bridge + orphaned acp
    exec("pkill -f acp_bridge.py 2>/dev/null || true");
    exec("pkill -f 'hermes acp' 2>/dev/null || true");
    sleep(1);

    // Start fresh (reuse the same logic)
    return local_hermesagent_ensure_bridge_running($bridge_port);
}

/**
 * Register admin navigation — only visible to site admins
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

