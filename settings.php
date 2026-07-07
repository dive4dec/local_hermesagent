<?php
defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/local/hermesagent/lib.php');
require_once($CFG->dirroot . '/local/hermesagent/classes/admin/setting_configfile.php');

if ($hassiteconfig) {
    $settings = new admin_settingpage('local_hermesagent_settings', get_string('pluginname', 'local_hermesagent'));

    $hermes_home = getenv('HERMES_HOME') ?: '/var/www/moodledata/.hermes';
    $hermes_docs = 'https://hermes-agent.nousresearch.com/docs';
    $hermes_docs_config = 'https://hermes-agent.nousresearch.com/docs/user-guide/configuration';
    $hermes_docs_gateway = 'https://hermes-agent.nousresearch.com/docs/user-guide/messaging';

    // Handle actions inline (restart, start, stop — for both bridge and gateway)
    $action = optional_param('action', '', PARAM_ALPHANUM);
    $target = optional_param('target', 'bridge', PARAM_ALPHA);
    if (in_array($action, ['restart', 'start', 'stop'])) {
        require_sesskey();

        if ($target === 'gateway') {
            $control_script = $CFG->dirroot . '/local/hermesagent/hermes-gateway-control.sh';
            $cmd = escapeshellarg($control_script) . ' ' . escapeshellarg($action) . ' 2>&1';
            exec($cmd, $output, $ret);
            $verb = ($action === 'stop') ? 'stopped' : (($action === 'restart') ? 'restarted' : 'started');
            $message = 'Gateway ' . $verb . ' — ' . implode(' ', $output);
            redirect(new moodle_url('/admin/settings.php', ['section' => 'local_hermesagent_settings']), $message);
        } else {
            $control_script = $CFG->dirroot . '/local/hermesagent/hermes-bridge-control.sh';
            $cmd = escapeshellarg($control_script) . ' ' . escapeshellarg($action) . ' 2>&1';
            exec($cmd, $output, $ret);

            $bridge_port = get_config('local_hermesagent', 'bridge_port') ?: '9118';
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

    // ================================================================
    // Section 1: Tools
    // ================================================================
    $links_html = '<div class="hermes-tools" style="margin-bottom: 20px;">';
    $links_html .= '<table class="generaltable">';
    $links_html .= '<tr><td style="width:120px;"><a href="' . $CFG->wwwroot . '/local/hermesagent/chat.php?action=new" class="btn btn-primary" target="_blank"><i class="icon fa fa-comments"></i> Chat</a></td>';
    $links_html .= '<td>Chat with the Hermes AI agent in real time. Supports markdown, LaTeX math rendering, and tool approval prompts. Conversations are saved in Moodle.</td></tr>';
    $links_html .= '<tr><td><a href="' . $CFG->wwwroot . '/local/hermesagent/terminal.php" class="btn btn-secondary"><i class="icon fa fa-terminal"></i> Terminal</a></td>';
    $links_html .= '<td>Run non-interactive Hermes CLI commands from the browser (e.g. <code>hermes config</code>, <code>hermes mcp list</code>, <code>hermes update --yes</code>). Interactive TUI commands are not supported.</td></tr>';
    $links_html .= '<tr><td><a href="' . $CFG->wwwroot . '/local/hermesagent/dashboard.php/" target="_blank" class="btn btn-info"><i class="icon fa fa-tachometer"></i> Dashboard</a></td>';
    $links_html .= '<td>Full Hermes web UI — configure model/provider, browse sessions, manage MCP servers and toolsets, and set up messaging platforms. Opens in a new tab.</td></tr>';
    $links_html .= '<tr><td><a href="' . $CFG->wwwroot . '/local/hermesagent/settings_action.php?action=update&sesskey=' . sesskey() . '" class="btn btn-warning"><i class="icon fa fa-download"></i> Update &amp; Bootstrap</a></td>';
    $links_html .= '<td>Install or update the Hermes Python environment, bridge scripts, and MCP servers. Safe to run repeatedly (idempotent). Also repairs the installation if something is broken.</td></tr>';
    $links_html .= '<tr><td><a href="' . $hermes_docs . '" target="_blank" class="btn btn-link"><i class="icon fa fa-book"></i> Docs</a></td>';
    $links_html .= '<td>Official Hermes Agent documentation — configuration reference, gateway setup guides, and API docs.</td></tr>';
    $links_html .= '</table>';
    $links_html .= '</div>';
    $settings->add(new admin_setting_heading('hermesagent_links', get_string('tools', 'local_hermesagent'), $links_html));

    // ================================================================
    // Section 2: ACP Bridge
    // ================================================================
    $bridge_port = get_config('local_hermesagent', 'bridge_port') ?: '9118';

    $is_running = false;
    $health_data = null;
    $ch = curl_init("http://127.0.0.1:$bridge_port/health");
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 2]);
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
    $bridge_html .= '<tr><td style="width:150px;">ACP Bridge</td><td>' . ($is_running ? '<span class="text-success">Running</span>' : '<span class="text-warning">Stopped — will auto-start on first chat</span>') . ' (port ' . $bridge_port . ')</td></tr>';
    $bridge_html .= '<tr><td>Hermes</td><td>' . htmlspecialchars($hermes_version) . '</td></tr>';
    if ($health_data && isset($health_data['sessions'])) {
        $bridge_html .= '<tr><td>Active Sessions</td><td>' . $health_data['sessions'] . '</td></tr>';
    }
    $bridge_html .= '</table>';
    $bridge_html .= '<div class="mt-2">';
    if ($is_running) {
        $bridge_html .= '<a href="' . $CFG->wwwroot . '/admin/settings.php?section=local_hermesagent_settings&action=restart&sesskey=' . sesskey() . '" class="btn btn-sm btn-warning">Restart</a> ';
        $bridge_html .= '<a href="' . $CFG->wwwroot . '/admin/settings.php?section=local_hermesagent_settings&action=stop&sesskey=' . sesskey() . '" class="btn btn-sm btn-danger">Stop</a> ';
    } else {
        $bridge_html .= '<a href="' . $CFG->wwwroot . '/admin/settings.php?section=local_hermesagent_settings&action=start&sesskey=' . sesskey() . '" class="btn btn-sm btn-success">Start</a> ';
    }
    $bridge_html .= '</div>';
    $bridge_html .= '</div>';
    $settings->add(new admin_setting_heading('hermesagent_bridge', get_string('acp_bridge', 'local_hermesagent'), $bridge_html));

    $settings->add(new admin_setting_configtext('local_hermesagent/bridge_port',
        get_string('bridge_port', 'local_hermesagent'),
        get_string('bridge_port_desc', 'local_hermesagent'),
        '9118', PARAM_INT));

    $settings->add(new admin_setting_configtext('local_hermesagent/dashboard_port',
        get_string('dashboard_port', 'local_hermesagent'),
        get_string('dashboard_port_desc', 'local_hermesagent'),
        '9119', PARAM_INT));

    // ================================================================
    // Section 3: Hermes Configuration (config.yaml)
    // ================================================================
    $config_desc = get_string('config_yaml_desc', 'local_hermesagent');
    $config_desc .= '<br><a href="' . $hermes_docs_config . '" target="_blank">📖 Hermes Documentation</a>';
    $settings->add(new admin_setting_heading('hermesagent_config', get_string('hermes_config', 'local_hermesagent'), ''));

    $settings->add(new \local_hermesagent\admin\setting_configfile(
        'local_hermesagent/config_yaml',
        get_string('config_yaml', 'local_hermesagent'),
        $config_desc,
        "$hermes_home/config.yaml",
        ''
    ));

    // ================================================================
    // Section 4: Messaging Gateway
    // ================================================================
    $gw_running = local_hermesagent_is_gateway_running();
    $gw_env_file = "$hermes_home/.env";
    $gw_has_platform = false;
    if (file_exists($gw_env_file)) {
        $env_content = file_get_contents($gw_env_file);
        $gw_has_platform = preg_match('/^(MATRIX_|TELEGRAM_|DISCORD_|SIGNAL_|MATTERMOST_|WHATSAPP_|WEIXIN_|IRC_|EMAIL_|LINE_|FEISHU_|DINGTALK_|GOOGLE_CHAT_|QQ_|NTFY_|BLUEBUBBLES_)/m', $env_content);
    }

    $gw_html = '<div class="hermes-status-panel">';
    $gw_html .= '<table class="generaltable">';
    if (!$gw_has_platform) {
        $gw_html .= '<tr><td style="width:150px;">Status</td><td><span class="text-warning">' . get_string('gateway_not_configured', 'local_hermesagent') . '</span></td></tr>';
    } elseif ($gw_running) {
        $gw_html .= '<tr><td>Status</td><td><span class="text-success">Running</span></td></tr>';
        $gw_log = "$hermes_home/logs/gateway.log";
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
    if ($gw_running) {
        $gw_html .= '<a href="' . $CFG->wwwroot . '/admin/settings.php?section=local_hermesagent_settings&action=restart&target=gateway&sesskey=' . sesskey() . '" class="btn btn-sm btn-warning">Restart</a> ';
        $gw_html .= '<a href="' . $CFG->wwwroot . '/admin/settings.php?section=local_hermesagent_settings&action=stop&target=gateway&sesskey=' . sesskey() . '" class="btn btn-sm btn-danger">Stop</a> ';
    } else {
        $gw_html .= '<a href="' . $CFG->wwwroot . '/admin/settings.php?section=local_hermesagent_settings&action=start&target=gateway&sesskey=' . sesskey() . '" class="btn btn-sm btn-success">Start</a> ';
    }
    $gw_html .= '</div>';
    $gw_html .= '</div>';
    $settings->add(new admin_setting_heading('hermesagent_gateway', get_string('gateway', 'local_hermesagent'), $gw_html));

    $env_desc = get_string('gateway_env_desc', 'local_hermesagent');
    $env_desc .= '<br><a href="' . $hermes_docs_gateway . '" target="_blank">📖 Gateway Documentation</a>';
    $settings->add(new \local_hermesagent\admin\setting_configfile(
        'local_hermesagent/gateway_env',
        get_string('gateway_env', 'local_hermesagent'),
        $env_desc,
        "$hermes_home/.env",
        ''
    ));

    $ADMIN->add('localplugins', $settings);
}
