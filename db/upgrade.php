<?php
/**
 * Database upgrades
 *
 * @package    local_hermesagent
 * @copyright  2026
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

function xmldb_local_hermesagent_upgrade($oldversion) {
    global $DB;

    $dbman = $DB->get_manager();

    if ($oldversion < 2026061200) {
        // Insert default settings on first upgrade
        $defaults = [
            ['name' => 'bridge_port', 'value' => '9118', 'description' => 'Local port for ACP bridge'],
            ['name' => 'hermes_model', 'value' => '', 'description' => 'Override model for this plugin'],
            ['name' => 'hermes_home', 'value' => '', 'description' => 'Custom HERMES_HOME path'],
            ['name' => 'bridge_status', 'value' => 'stopped', 'description' => 'Bridge status'],
            ['name' => 'last_schema_refresh', 'value' => '0', 'description' => 'Last schema refresh timestamp'],
        ];

        foreach ($defaults as $setting) {
            if (!$DB->record_exists('local_hermesagent_settings', ['name' => $setting['name']])) {
                $record = (object)[
                    'name' => $setting['name'],
                    'value' => $setting['value'],
                    'description' => $setting['description'],
                    'timemodified' => time(),
                ];
                $DB->insert_record('local_hermesagent_settings', $record);
            }
        }

        upgrade_plugin_savepoint(true, 2026061200, 'local', 'hermesagent');
    }

    if ($oldversion < 2026061204) {
        // Purge requirejs cache so our AMD modules are included.
        // Moodle's requirejs cache is tied to the theme revision and does not
        // automatically rebuild when a new plugin is installed.
        $requirejs_dir = $CFG->localcachedir . '/requirejs/';
        if (is_dir($requirejs_dir)) {
            $files = glob($requirejs_dir . '*');
            foreach ($files as $f) {
                if (is_file($f)) {
                    unlink($f);
                }
            }
        }

        upgrade_plugin_savepoint(true, 2026061204, 'local', 'hermesagent');
    }

    
    if ($oldversion < 2026061303) {
        // Flush caches to pick up new web services (rename_conversation, save_assistant_response)
        // Moodle 5.x rebuilds web service cache on upgrade_plugin_savepoint + purge
        purge_all_caches();
        
        upgrade_plugin_savepoint(true, 2026061303, 'local', 'hermesagent');
    }

    if ($oldversion < 2026061304) {
        // Alter text fields in local_hermesagent_messages to LONGTEXT to support larger content.
        // Moodle's XMLDB "text" type maps to MEDIUMTEXT (16MB) by default. Adding LENGTH="long"
        // maps to LONGTEXT (4GB) for chat messages that may exceed 16MB.
        $table = new xmldb_table('local_hermesagent_messages');

        // Content field.
        if ($dbman->field_exists($table, new xmldb_field('content'))) {
            $field = new xmldb_field('content', XMLDB_TYPE_TEXT, 'long', null, XMLDB_NOTNULL, null, null, 'role');
            $dbman->change_field_type($table, $field);
        }

        // Tool calls field.
        if ($dbman->field_exists($table, new xmldb_field('tool_calls'))) {
            $field = new xmldb_field('tool_calls', XMLDB_TYPE_TEXT, 'long', null, null, null, null, 'content');
            $dbman->change_field_type($table, $field);
        }

        // Tool results field.
        if ($dbman->field_exists($table, new xmldb_field('tool_results'))) {
            $field = new xmldb_field('tool_results', XMLDB_TYPE_TEXT, 'long', null, null, null, null, 'tool_calls');
            $dbman->change_field_type($table, $field);
        }

        upgrade_plugin_savepoint(true, 2026061304, 'local', 'hermesagent');
    }

    if ($oldversion < 2026071604) {
        // Purge all caches (especially requirejs) so updated AMD modules
        // are picked up. Moodle's upgrade flow calls purge_all_caches() at
        // the end, but the requirejs cache can be rebuilt from stale state
        // during the upgrade process if web requests race with the upgrade.
        // Calling it here in the savepoint guarantees a clean rebuild.
        purge_all_caches();

        upgrade_plugin_savepoint(true, 2026071604, 'local', 'hermesagent');
    }

    return true;
}
