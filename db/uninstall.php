<?php
// This file is part of the local_hermesagent plugin for Moodle.
//
// It runs during plugin uninstall. By default it stops the ACP bridge and
// removes the Hermes home directory (venv, config, plugins, skills, etc.)
// so the system is left clean. Admins who want to keep the Hermes data
// across a reinstall can set "Retain Hermes data on uninstall" on the
// plugin settings page before uninstalling — the directory will be left
// in place and the next install will reuse it.

defined('MOODLE_INTERNAL') || die();

function xmldb_local_hermesagent_uninstall(): bool {
    global $DB;

    // Check the "retain data" setting (stored in config_plugins by admin_setting).
    // Default is to clean up everything.
    $retain = get_config('local_hermesagent', 'retain_data_on_uninstall') === '1';

    if ($retain) {
        mtrace('local_hermesagent: retain_data_on_uninstall is enabled — leaving Hermes data in place.');
        return true;
    }

    // --- Determine Hermes home directory ---
    $hermes_home = '';
    if ($DB->get_manager()->table_exists('local_hermesagent_settings')) {
        $record = $DB->get_record('local_hermesagent_settings', ['name' => 'hermes_home']);
        if ($record && !empty($record->value)) {
            $hermes_home = $record->value;
        }
    }
    if (empty($hermes_home)) {
        $hermes_home = getenv('HERMES_HOME') ?: '/var/www/moodledata/.hermes';
    }

    // --- Stop the ACP bridge if it is running ---
    $bridge_script = dirname(__DIR__) . '/hermes-bridge-control.sh';
    if (file_exists($bridge_script)) {
        mtrace('local_hermesagent: stopping ACP bridge...');
        @exec(escapeshellarg($bridge_script) . ' stop 2>&1', $out, $ret);
    }

    // --- Remove the Hermes home directory ---
    if (is_dir($hermes_home)) {
        mtrace('local_hermesagent: removing Hermes home directory: ' . $hermes_home);
        // The venv contains many files; remove_dir handles recursion + symlinks safely.
        remove_dir($hermes_home);
    }

    // --- Remove plugin config_plugins entries ---
    $DB->delete_records('config_plugins', ['plugin' => 'local_hermesagent']);

    return true;
}
