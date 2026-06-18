<?php
require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

require_login();
require_capability('local/hermesagent:configure', context_system::instance());

$hermes_home = '${HERMES_HOME:-/var/www/moodledata/.hermes}';
$venv_bin = "$hermes_home/venv/bin";
$log_dir = "/tmp/hermes_terminal";
@mkdir($log_dir, 0700, true);

$check = optional_param('check', 0, PARAM_INT);
if ($check) {
    header('Content-Type: application/json');
    echo json_encode(['installed' => is_dir($venv_bin)]);
    exit;
}

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

    // Exitfile wins - command definitely done
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
        // No PID file, no exit file - brief startup window
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

$command = required_param('command', PARAM_RAW);
confirm_sesskey();

$hermes_installed = is_dir($venv_bin);
$base_path = getenv('PATH') ?: '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin';
$env_path = $hermes_installed ? "$venv_bin:$base_path" : $base_path;

$cmd_id = md5(uniqid((string)getmypid(), true));
$logfile = "$log_dir/{$cmd_id}.log";
$pidfile = "$log_dir/{$cmd_id}.pid";
$exitfile = "$log_dir/{$cmd_id}.exit";
$scriptfile = "$log_dir/{$cmd_id}.sh";

@unlink($logfile);
@unlink($pidfile);
@unlink($exitfile);
@unlink($scriptfile);

// Script writes its own PID, runs command, writes exit code, deletes itself
$script = '#!/bin/sh' . "\n";
$script .= 'echo $$ > "' . $pidfile . '"\n';
$script .= "cd /var/www\n";
$script .= $command . "\n";
$script .= 'RC=$?' . "\n";
$script .= "echo \$RC > '" . $exitfile . "'\n";
$script .= "rm -f '" . $scriptfile . "'\n";
file_put_contents($scriptfile, $script);
chmod($scriptfile, 0700);

// Execute - script still on disk, will delete itself
$cmd = "sh '" . $scriptfile . "' > '" . $logfile . "' 2>&1 &";
exec($cmd);

header('Content-Type: application/json');
echo json_encode([
    'id' => $cmd_id,
    'running' => true,
    'output' => '',
    'offset' => 0,
]);
