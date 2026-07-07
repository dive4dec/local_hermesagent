<?php
defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/local/hermesagent/lib.php');

if ($hassiteconfig) {
    $settings = new admin_settingpage('local_hermesagent_settings', get_string('pluginname', 'local_hermesagent'));

// Add a link to open the chat interface
    $chat_url = new moodle_url('/local/hermesagent/chat.php?action=new');
    $chat_link_html = '<div style="margin-bottom: 20px;">';
    $chat_link_html .= '<a href="' . $chat_url->out() . '" class="btn btn-primary" target="_blank">';
    $chat_link_html .= '<i class="icon fa fa-comments"></i> Open Hermes Chat';
    $chat_link_html .= '</a>';
    $chat_link_html .= '</div>';
    $settings->add(new admin_setting_heading('hermesagent_chat_link', '', $chat_link_html));

    $bridge_port = get_config('local_hermesagent', 'bridge_port');
    if (empty($bridge_port)) {
        $bridge_port = '9118';
    }

    $hermes_home = getenv('HERMES_HOME') ?: '/var/www/moodledata/.hermes';

    // Handle actions inline (restart, start, stop — for both bridge and gateway)
    $action = optional_param('action', '', PARAM_ALPHANUM);
    $target = optional_param('target', 'bridge', PARAM_ALPHA);
    if (in_array($action, ['restart', 'start', 'stop'])) {
        require_sesskey();

        if ($target === 'gateway') {
            // Write .env before starting/restarting gateway
            if ($action !== 'stop') {
                local_hermesagent_write_gateway_env();
            }
            $control_script = $CFG->dirroot . '/local/hermesagent/hermes-gateway-control.sh';
            $cmd = escapeshellarg($control_script) . ' ' . escapeshellarg($action) . ' 2>&1';
            exec($cmd, $output, $ret);
            $verb = ($action === 'stop') ? 'stopped' : (($action === 'restart') ? 'restarted' : 'started');
            $message = 'Gateway ' . $verb . ' — ' . implode(' ', $output);
            redirect(new moodle_url('/admin/settings.php', ['section' => 'local_hermesagent_settings']), $message);
        } else {
            // Bridge action (existing code)
            $control_script = $CFG->dirroot . '/local/hermesagent/hermes-bridge-control.sh';
            $cmd = escapeshellarg($control_script) . ' ' . escapeshellarg($action) . ' 2>&1';
            exec($cmd, $output, $ret);

            if ($action === 'stop') {
                $message = 'ACP Bridge stopped';
            } else {
                $healthy = false;
                for ($i = 0; $i < 20; $i++) {
                    sleep(1);
                    $ch = curl_init("http://127.0.0.1:$bridge_port/health");
                    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 2]);
                    $resp = curl_exec($ch);
                    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                    curl_close($ch);
                    if ($resp !== false && $http === 200) {
                        $healthy = true;
                        break;
                    }
                }
                $verb = ($action === 'restart') ? 'restarted' : 'started';
                $message = $healthy
                    ? 'ACP Bridge ' . $verb . ' (ready after ' . ($i + 1) . 's)'
                    : 'Bridge ' . $verb . ' but not responding after 20s. Check bridge.log.';
            }
            redirect(new moodle_url('/admin/settings.php', ['section' => 'local_hermesagent_settings']), $message);
        }
    }

    // Health check
    $is_running = false;
    $health_data = null;
    $ch = curl_init("http://127.0.0.1:$bridge_port/health");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 2,
    ]);
    $bridge_health = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $is_running = ($bridge_health !== false && $http_code == 200);
    if ($is_running) {
        $health_data = json_decode($bridge_health, true);
    }

    $hermes_installed = file_exists("$hermes_home/venv/bin/hermes");
    $hermes_version = 'Not installed';
    if ($hermes_installed) {
        $output = [];
        exec("$hermes_home/venv/bin/hermes --version 2>&1", $output, $rc);
        $hermes_version = implode(' ', array_slice($output, 0, 2));
    }

    // Status block
    $bridge_html = '<div class="hermes-status-panel">';
    $bridge_html .= '<table class="generaltable">';
    $bridge_html .= '<tr><td>ACP Bridge</td><td>' . ($is_running ? '<span class="text-success">Running</span>' : '<span class="text-warning">Stopped — will auto-start on first chat</span>') . ' (port ' . $bridge_port . ')</td></tr>';
    $bridge_html .= '<tr><td>Hermes</td><td>' . htmlspecialchars($hermes_version) . '</td></tr>';
    if ($health_data && isset($health_data['sessions'])) {
        $bridge_html .= '<tr><td>Active Sessions</td><td>' . $health_data['sessions'] . '</td></tr>';
    }
    $bridge_html .= '</table>';
    $bridge_html .= '<div class="mt-2">';
    if ($is_running) {
        $bridge_html .= '<a href="' . $CFG->wwwroot . '/admin/settings.php?section=local_hermesagent_settings&action=restart&sesskey=' . sesskey() . '" class="btn btn-sm btn-warning">Restart ACP</a> ';
        $bridge_html .= '<a href="' . $CFG->wwwroot . '/admin/settings.php?section=local_hermesagent_settings&action=stop&sesskey=' . sesskey() . '" class="btn btn-sm btn-danger">Stop ACP</a> ';
    } else {
        $bridge_html .= '<a href="' . $CFG->wwwroot . '/admin/settings.php?section=local_hermesagent_settings&action=start&sesskey=' . sesskey() . '" class="btn btn-sm btn-success">Start ACP</a> ';
    }
    $bridge_html .= '<a href="' . $CFG->wwwroot . '/local/hermesagent/settings_action.php?action=update&sesskey=' . sesskey() . '" class="btn btn-sm btn-info">Update &amp; Bootstrap</a> ';
    $bridge_html .= '<a href="' . $CFG->wwwroot . '/local/hermesagent/dashboard.php/" target="_blank" class="btn btn-sm btn-primary">Dashboard</a> ';
    $bridge_html .= '</div>';
    $bridge_html .= '</div>';

    $settings->add(new admin_setting_description('local_hermesagent/status', '', $bridge_html));

    $settings->add(new admin_setting_configtext('local_hermesagent/bridge_port',
        get_string('bridge_port', 'local_hermesagent'),
        get_string('bridge_port_desc', 'local_hermesagent'),
        $bridge_port, PARAM_INT));

    // --- Gateway section ---
    $gw_running = local_hermesagent_is_gateway_running();
    $gw_configured = local_hermesagent_is_gateway_configured();
    $gw_home = getenv('HERMES_HOME') ?: '/var/www/moodledata/.hermes';

    $gw_html = '<div class="hermes-status-panel">';
    $gw_html .= '<h4>' . get_string('gateway', 'local_hermesagent') . '</h4>';
    $gw_html .= '<p class="text-muted">' . get_string('gateway_desc', 'local_hermesagent') . '</p>';
    $gw_html .= '<table class="generaltable">';
    if (!$gw_configured) {
        $gw_html .= '<tr><td>Status</td><td><span class="text-warning">' . get_string('gateway_not_configured', 'local_hermesagent') . '</span></td></tr>';
    } elseif ($gw_running) {
        $gw_html .= '<tr><td>Status</td><td><span class="text-success">Running</span></td></tr>';
        // Show last log line
        $gw_log = "$gw_home/logs/gateway.log";
        if (file_exists($gw_log)) {
            $last_line = trim(shell_exec("tail -1 " . escapeshellarg($gw_log) . " 2>/dev/null"));
            if ($last_line) {
                $gw_html .= '<tr><td>Last log</td><td><code style="font-size:11px;word-break:break-all;">' . htmlspecialchars(substr($last_line, 0, 200)) . '</code></td></tr>';
            }
        }
    } else {
        $gw_html .= '<tr><td>Status</td><td><span class="text-secondary">Stopped</span></td></tr>';
    }
    $gw_html .= '</table>';
    $gw_html .= '<div class="mt-2">';
    if ($gw_configured) {
        if ($gw_running) {
            $gw_html .= '<a href="' . $CFG->wwwroot . '/admin/settings.php?section=local_hermesagent_settings&action=restart&target=gateway&sesskey=' . sesskey() . '" class="btn btn-sm btn-warning">Restart Gateway</a> ';
            $gw_html .= '<a href="' . $CFG->wwwroot . '/admin/settings.php?section=local_hermesagent_settings&action=stop&target=gateway&sesskey=' . sesskey() . '" class="btn btn-sm btn-danger">Stop Gateway</a> ';
        } else {
            $gw_html .= '<a href="' . $CFG->wwwroot . '/admin/settings.php?section=local_hermesagent_settings&action=start&target=gateway&sesskey=' . sesskey() . '" class="btn btn-sm btn-success">Start Gateway</a> ';
        }
    }
    $gw_html .= '</div>';
    $gw_html .= '</div>';

    $settings->add(new admin_setting_description('local_hermesagent/gateway_status', '', $gw_html));

    $settings->add(new admin_setting_configtext('local_hermesagent/matrix_homeserver',
        get_string('gateway_matrix_homeserver', 'local_hermesagent'),
        get_string('gateway_matrix_homeserver_desc', 'local_hermesagent'),
        '', PARAM_URL));

    $settings->add(new admin_setting_configtext('local_hermesagent/matrix_user_id',
        get_string('gateway_matrix_user_id', 'local_hermesagent'),
        get_string('gateway_matrix_user_id_desc', 'local_hermesagent'),
        '', PARAM_RAW));

    $settings->add(new admin_setting_configpassword('local_hermesagent/matrix_access_token',
        get_string('gateway_matrix_access_token', 'local_hermesagent'),
        get_string('gateway_matrix_access_token_desc', 'local_hermesagent'),
        ''));

    $settings->add(new admin_setting_configtext('local_hermesagent/matrix_allowed_rooms',
        get_string('gateway_matrix_allowed_rooms', 'local_hermesagent'),
        get_string('gateway_matrix_allowed_rooms_desc', 'local_hermesagent'),
        '', PARAM_RAW));

    $settings->add(new admin_setting_configtext('local_hermesagent/matrix_device_id',
        get_string('gateway_matrix_device_id', 'local_hermesagent'),
        get_string('gateway_matrix_device_id_desc', 'local_hermesagent'),
        '', PARAM_RAW));

    // Terminal link
    $term_link = '<div class="hermes-terminal-link">';
    $term_link .= '<h4>' . get_string('terminal', 'local_hermesagent') . '</h4>';
    $term_link .= '<p>Open the live Hermes CLI terminal to configure providers, run commands, and debug.</p>';
    $term_link .= '<a href="' . $CFG->wwwroot . '/local/hermesagent/terminal.php" class="btn btn-primary">Open Terminal</a>';
    if (!$hermes_installed) {
        $term_link .= ' <a href="' . $CFG->wwwroot . '/local/hermesagent/terminal.php" class="btn btn-secondary">Bootstrap Hermes</a>';
    }
    $term_link .= '</div>';

    $settings->add(new admin_setting_description('local_hermesagent/terminal_link', '', $term_link));

    $ADMIN->add('localplugins', $settings);
}
