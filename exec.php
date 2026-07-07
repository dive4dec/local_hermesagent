<?php
/**
 * Execute a shell command on the pod as www-data.
 * Commands run with HERMES_HOME and venv/bin in PATH so `hermes` works.
 *
 * Two modes:
 *   1. POST with 'command' param → start a background command, return cmd_id
 *   2. GET with 'poll' param → poll output of a running/finished command
 *   3. GET with 'check' param → quick check if hermes is installed
 */

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

require_login();
require_capability('local/hermesagent:configure', context_system::instance());

$hermes_home = '/var/www/moodledata/.hermes';
$venv_bin = "$hermes_home/venv/bin";
$log_dir = "/tmp/hermes_terminal";
@mkdir($log_dir, 0700, true);

// Quick check: is hermes installed?
$check = optional_param('check', 0, PARAM_INT);
if ($check) {
    header('Content-Type: application/json');
    echo json_encode(['installed' => is_dir($venv_bin)]);
    exit;
}

// Poll mode: check output of a running command
$poll_id = optional_param('poll', '', PARAM_ALPHANUM);
if ($poll_id) {
    header('Content-Type: application/json');
    $logfile = "$log_dir/{$poll_id}.log";
    $pidfile = "$log_dir/{$poll_id}.pid";
    $exitfile = "$log_dir/{$poll_id}.exit";

    if (!file_exists($logfile)) {
        echo json_encode(['error' => 'Session expired', 'running' => false]);
        exit;
    }

    $running = false;
    $exit_code = null;

    if (file_exists($exitfile)) {
        $exit_code = (int)trim(file_get_contents($exitfile));
        $running = false;
    } elseif (file_exists($pidfile)) {
        $pid = (int)trim(file_get_contents($pidfile));
        if ($pid > 0 && @posix_kill($pid, 0)) {
            $running = true;
        } else {
            $running = false;
        }
    } else {
        $running = true;
    }

    $offset = optional_param('offset', 0, PARAM_INT);
    $content = @file_get_contents($logfile);
    if ($content === false) $content = '';
    $new_output = '';
    if (strlen($content) > $offset) {
        $new_output = substr($content, $offset);
    }
    $new_offset = strlen($content);

    if (!$running && file_exists($pidfile)) {
        @unlink($pidfile);
    }

    echo json_encode([
        'output' => $new_output,
        'offset' => $new_offset,
        'running' => $running,
        'exit' => $exit_code,
    ]);
    exit;
}

// Execute mode: start a command
$command = required_param('command', PARAM_RAW);
confirm_sesskey();

$hermes_installed = is_dir($venv_bin);

// Build the environment prefix: set HERMES_HOME and add venv to PATH
// so `hermes` is directly available without typing the full path.
$env_prefix = '';
if ($hermes_installed) {
    $env_prefix = "export HERMES_HOME='$hermes_home'\n";
    $env_prefix .= "export PATH='$venv_bin:'\$PATH\n";
}

$cmd_id = md5(uniqid((string)getmypid(), true));
$logfile = "$log_dir/{$cmd_id}.log";
$pidfile = "$log_dir/{$cmd_id}.pid";
$exitfile = "$log_dir/{$cmd_id}.exit";
$scriptfile = "$log_dir/{$cmd_id}.sh";

@unlink($logfile);
@unlink($pidfile);
@unlink($exitfile);
@unlink($scriptfile);

// Script writes its own PID, sets env, runs command, writes exit code, deletes itself
$script = "#!/bin/sh\n";
$script .= "echo \$\$ > '$pidfile'\n";
$script .= $env_prefix;
$script .= "cd /var/www\n";
$script .= $command . "\n";
$script .= "RC=$?\n";
$script .= "echo \$RC > '$exitfile'\n";
$script .= "rm -f '$scriptfile'\n";
file_put_contents($scriptfile, $script);
chmod($scriptfile, 0700);

// Execute in background
$cmd = "sh '" . $scriptfile . "' > '" . $logfile . "' 2>&1 &";
exec($cmd);

header('Content-Type: application/json');
echo json_encode([
    'id' => $cmd_id,
    'running' => true,
    'output' => '',
    'offset' => 0,
]);
