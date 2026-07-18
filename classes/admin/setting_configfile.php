<?php
/**
 * Admin setting that reads/writes a file directly (not the Moodle DB).
 *
 * The file is the single source of truth — no stale DB copy.
 * Used for config.yaml and other Hermes config files.
 *
 * @package    local_hermesagent
 * @copyright  2026
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_hermesagent\admin;

defined('MOODLE_INTERNAL') || die();

class setting_configfile extends \admin_setting_configtextarea {

    /** @var string Full path to the file this setting reads/writes */
    protected string $filepath;

    /**
     * @param string $name        Setting name (unique key)
     * @param string $visiblename Label
     * @param string $description Help text
     * @param string $filepath    Absolute path to the file
     * @param mixed  $default     Default content if file doesn't exist
     */
    public function __construct(string $name, string $visiblename, string $description,
                                string $filepath, string $default = '') {
        $this->filepath = $filepath;
        parent::__construct($name, $visiblename, $description, $default, PARAM_RAW);
    }

    /**
     * Read the current value from the file (not the DB).
     */
    public function get_setting(): ?string {
        if (file_exists($this->filepath) && is_readable($this->filepath)) {
            return file_get_contents($this->filepath);
        }
        return $this->defaultsetting;
    }

    /**
     * Write the value directly to the file (not the DB).
     */
    public function write_setting($data): string {
        // Ensure directory exists
        $dir = dirname($this->filepath);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        // Write to file
        $written = file_put_contents($this->filepath, $data);
        if ($written === false) {
            // Provide a helpful error explaining the likely cause.
            $owner = function_exists('posix_getpwuid')
                ? @posix_getpwuid(fileowner($this->filepath)) : null;
            $ownerstr = $owner ? $owner['name'] : fileowner($this->filepath);
            return get_string('errorsetting', 'admin') . ' '
                . 'Cannot write to ' . $this->filepath
                . ' (owner: ' . $ownerstr
                . ', PHP runs as: ' . get_current_user() . ').'
                . ' Run: chown www-data:www-data ' . $this->filepath;
        }

        // Set appropriate permissions for config files
        @chmod($this->filepath, 0644);

        return '';
    }

    /**
     * Override to prevent Moodle from storing this in config_plugins.
     */
    public function post_write_settings($data): bool {
        return true;
    }
}
