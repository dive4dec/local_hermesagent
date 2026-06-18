<?php
defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    $settings = new admin_settingpage('local_hermesagent_settings', get_string('pluginname', 'local_hermesagent'));

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

    $action = optional_param('action', '', PARAM_ALPHANUM);
    if (!empty($action)) {
        require_sesskey();
        
        if ($action === 'start') {
            $cmd = "HERMES_HOME=" . escapeshellarg($hermes_home) . " " . 
                   escapeshellarg("$hermes_home/venv/bin/python") . " " . 
                   escapeshellarg("/var/www/html/public/local/hermesagent/classes/bridge/acp_bridge.py") . " > /dev/null 2>&1 &";
            exec($cmd);
        } else if ($action === 'stop') {
            exec("pkill -f acp_bridge.py");
        }
        
        redirect(new moodle_url('/admin/settings.php', ['section' => 'local_hermesagent_settings']));
    }

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

    $bridge_html = '<div class="hermes-status-panel">';
    $bridge_html .= '<table class="generaltable">';
    $bridge_html .= '<tr><td>ACP Bridge</td><td>' . ($is_running ? '<span class="text-success">Running</span>' : '<span class="text-danger">Stopped</span>') . ' (port ' . $bridge_port . ')</td></tr>';
    $bridge_html .= '<tr><td>Hermes</td><td>' . htmlspecialchars($hermes_version) . '</td></tr>';
    if ($health_data && isset($health_data['sessions'])) {
        $bridge_html .= '<tr><td>Active Sessions</td><td>' . $health_data['sessions'] . '</td></tr>';
    }
    $bridge_html .= '</table>';
    $bridge_html .= '<div class="mt-2">';
    $bridge_html .= '<a href="' . $CFG->wwwroot . '/admin/settings.php?section=local_hermesagent_settings&action=start&sesskey=' . sesskey() . '" class="btn btn-sm btn-success">Start</a> ';
    $bridge_html .= '<a href="' . $CFG->wwwroot . '/admin/settings.php?section=local_hermesagent_settings&action=stop&sesskey=' . sesskey() . '" class="btn btn-sm btn-danger">Stop</a> ';
    $bridge_html .= '</div>';
    
    $bridge_html .= '</div>';

    $settings->add(new admin_setting_description('local_hermesagent/status', '', $bridge_html));

    $settings->add(new admin_setting_configtext('local_hermesagent/bridge_port',
        get_string('bridge_port', 'local_hermesagent'),
        get_string('bridge_port_desc', 'local_hermesagent'),
        $bridge_port, PARAM_INT));

    // Terminal 面板
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