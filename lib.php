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

