<?php
require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

require_login();
require_capability('local/hermesagent:configure', context_system::instance());

$action = required_param('action', PARAM_ALPHA);
confirm_sesskey();

$bridge_port = get_config('local_hermesagent', 'bridge_port');
if (empty($bridge_port)) $bridge_port = '9118';

<<<<<<< HEAD
$hermes_home = '${HERMES_HOME:-/var/www/moodledata/.hermes}';
=======
$hermes_home = '/var/www/moodledata/.hermes';
>>>>>>> upstream/main
$bridge_script = $CFG->dirroot . '/local/hermesagent/classes/bridge/acp_bridge.py';

if ($action === 'start') {
    if (!file_exists("$hermes_home/venv/bin/hermes")) {
        throw new moodle_exception('Hermes not installed. Please bootstrap first.', '',
            new moodle_url('/admin/settings.php?section=local_hermesagent_settings'));
    }

    // Write DB credentials to a temporary file with restricted permissions instead
    // of passing the password as an environment variable (visible in /proc/PID/environ).
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
        '${HERMES_HOME} BRIDGE_PORT=%d MOODLE_DB_CREDENTIALS_FILE=%s nohup %s/venv/bin/python %s > ${HERMES_HOME:-/var/www/moodledata/.hermes}/logs/bridge.log 2>&1 & echo $!',
        escapeshellarg($hermes_home),
        $bridge_port,
        escapeshellarg($cred_file),
        $hermes_home,
        escapeshellarg($bridge_script)
    );

    $output = [];
    exec($cmd, $output, $return);
    $pid = trim(implode("
", $output));
    set_config('bridge_pid', $pid, 'local_hermesagent');
    set_config('bridge_status', 'running', 'local_hermesagent');
    sleep(1);

} elseif ($action === 'stop') {
    $pid = get_config('local_hermesagent', 'bridge_pid');
    if ($pid) {
        exec("kill $pid 2>/dev/null");
        // Fallback: kill by port
        exec("fuser -k ${bridge_port}/tcp 2>/dev/null || true");
    }
    set_config('bridge_status', 'stopped', 'local_hermesagent');
    set_config('bridge_pid', '', 'local_hermesagent');
    // Securely remove credential file
    $cred_file = "$hermes_home/.credentials/db.env";
    if (file_exists($cred_file)) {
        unlink($cred_file);
    }
}

redirect(new moodle_url('/admin/settings.php?section=local_hermesagent_settings'));
