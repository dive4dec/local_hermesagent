<?php
/**
 * Plugin library functions.
 *
 * @package    local_hermesagent
 * @copyright  2026
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Serve files from the local_hermesagent plugin file areas.
 *
 * @param stdClass $course Course object
 * @param stdClass $cm Course module object
 * @param context $context Context object
 * @param string $filearea File area
 * @param array $args Extra arguments
 * @param bool $forcedownload Whether to force download
 * @param array $options Additional options
 * @return void
 */
function local_hermesagent_pluginfile($course, $cm, $context, $filearea, $args, $forcedownload, array $options = []) {
    // Only serve chatimage file area
    if ($filearea !== 'chatimage') {
        return false;
    }

    // Must be logged in
    require_login();

    // Check capability
    if (!is_siteadmin() && !has_capability('local/hermesagent:use', $context)) {
        return false;
    }

    // Extract filename from args
    $itemid = array_shift($args);
    $filename = array_pop($args);
    $filepath = $args ? '/' . implode('/', $args) . '/' : '/';

    $fs = get_file_storage();
    $file = $fs->get_file($context->id, 'local_hermesagent', $filearea, $itemid, $filepath, $filename);

    if (!$file || $file->is_directory()) {
        return false;
    }

    // Send the file
    send_stored_file($file, 86400, 0, $forcedownload, $options);
}
