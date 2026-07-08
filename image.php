<?php
/**
 * Serve chat images directly from the local filesystem.
 * This bypasses Moodle's pluginfile authentication so images
 * display in the browser without session complications.
 *
 * Access is still controlled: requires Moodle login.
 *
 * @package    local_hermesagent
 */

require_once('../../config.php');

require_login();

$filename = required_param('f', PARAM_FILE);

// Prevent directory traversal
$filename = basename($filename);

$hermes_home = getenv('HERMES_HOME') ?: '/var/www/moodledata/.hermes';
$filepath = $hermes_home . '/images/' . $filename;

if (!file_exists($filepath)) {
    http_response_code(404);
    die('Image not found');
}

// Determine mime type from extension
$ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
$mimes = [
    'png' => 'image/png',
    'jpg' => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'gif' => 'image/gif',
    'webp' => 'image/webp',
    'svg' => 'image/svg+xml',
];
$mime = $mimes[$ext] ?? 'application/octet-stream';

// Send the file
header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($filepath));
header('Cache-Control: private, max-age=86400');
readfile($filepath);
