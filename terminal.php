<?php
require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

require_login();
require_capability('local/hermesagent:configure', context_system::instance());

$PAGE->set_url('/local/hermesagent/terminal.php');
$PAGE->set_context(context_system::instance());
$PAGE->set_title(get_string('terminal', 'local_hermesagent'));
$PAGE->set_heading(get_string('pluginname', 'local_hermesagent'));

$hermes_home = '${HERMES_HOME:-/var/www/moodledata/.hermes}';
$hermes_installed = file_exists("$hermes_home/venv/bin/hermes");

// Load CSS/JS from files — avoids PHP quoting issues with embedded content
$css_file = __DIR__ . '/styles/terminal.css';
$js_file = __DIR__ . '/styles/terminal.js';

echo $OUTPUT->header();

// Inline CSS
if (file_exists($css_file)) {
    echo '<style>' . file_get_contents($css_file) . '</style>';
}

echo $OUTPUT->heading(get_string('terminal', 'local_hermesagent'), 2);

if (!$hermes_installed) {
    echo '<div class="alert alert-warning">';
    echo 'Hermes is not installed yet. ';
    echo '<button type="button" id="btn-bootstrap" class="btn btn-sm btn-primary">Bootstrap Hermes</button>';
    echo ' (downloads standalone Python ~50MB and installs hermes-agent)';
    echo '</div>';
}

// Terminal container with data attributes
echo '<div id="hermes-terminal-container" ';
echo 'data-sesskey="' . sesskey() . '" ';
echo 'data-wwwroot="' . $CFG->wwwroot . '" ';
echo 'data-hermesinstalled="' . ($hermes_installed ? 'true' : 'false') . '">';
echo '<pre id="hermes-terminal-output" class="hermes-terminal-output"></pre>';
echo '<div class="hermes-terminal-input-row">';
echo '<span id="hermes-terminal-prompt">~$ </span>';
echo '<input type="text" id="hermes-terminal-input" class="hermes-terminal-input" autocomplete="off" spellcheck="false" />';
echo '</div>';
echo '</div>';

echo '<div class="mt-3"><small class="text-muted">';
echo 'Run <code>hermes --help</code>, <code>hermes config set ...</code>, <code>hermes acp --check</code>';
echo '</small></div>';

echo '<div class="mt-3">';
echo $OUTPUT->single_button(new moodle_url('/admin/settings.php?section=local_hermesagent_settings'), get_string('backto', 'local_hermesagent'));
echo '</div>';

echo $OUTPUT->footer();

// Inline JS (after footer so DOM elements exist)
if (file_exists($js_file)) {
    echo '<script>' . file_get_contents($js_file) . '</script>';
}
