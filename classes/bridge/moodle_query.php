#!/usr/bin/env php
<?php
/**
 * Safe read-only Moodle database query tool for Hermes Agent
 * 
 * Security:
 * - Read-only queries only (SELECT, SHOW, DESCRIBE)
 * - Results limited to 100 rows by default
 * - Sensitive columns redacted
 * - Queries logged for audit
 */

// Database configuration
$dbhost = getenv('MOODLE_DB_HOST') ?: 'mariadb';
$dbname = getenv('MOODLE_DB_NAME') ?: 'moodle';
$dbuser = getenv('MOODLE_DB_USER') ?: 'moodleuser';
$dbpass = getenv('MOODLE_DB_PASS') ?: 'NJqnxkPqohs4kCyni8RVyg==';

// Sensitive columns to redact
$sensitive_columns = [
    'password', 'password_hash', 'mnethostid', 'auth', 'passwordsalt',
    'lastip', 'emailstop', 'idnumber', 'passwordreset',
];

// Logging
$log_file = getenv('HERMES_HOME') ? getenv('HERMES_HOME') . '/logs/moodle_query.log' : '/tmp/moodle_query.log';

function log_message($msg) {
    global $log_file;
    $dir = dirname($log_file);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    file_put_contents($log_file, date('Y-m-d H:i:s') . " " . $msg . "\n", FILE_APPEND);
}

// Check input
if (php_sapi_name() !== 'cli') {
    die("This tool is CLI-only\n");
}

if (empty($argv[1])) {
    echo json_encode([
        "error" => "Usage: moodle_query.php <SQL query>\n\n" .
                   "Read-only Moodle database query tool.\n" .
                   "Only SELECT, SHOW, DESCRIBE, EXPLAIN allowed.\n" .
                   "Results limited to 100 rows.\n" .
                   "Sensitive columns are redacted.\n"
    ]);
    exit(1);
}

$query = $argv[1];
log_message("Query request: " . substr($query, 0, 200));

// Sanitize query
$sanitized_query = trim($query);

// Remove trailing semicolons
$sanitized_query = rtrim($sanitized_query, ';\s');

// Check for dangerous keywords
$dangerous = [
    'INSERT', 'UPDATE', 'DELETE', 'DROP', 'CREATE', 'ALTER',
    'TRUNCATE', 'REPLACE', 'GRANT', 'REVOKE', 'EXEC', 'EXECUTE',
    'LOAD_FILE', 'INTO OUTFILE', 'INTO DUMPFILE', 'UNION SELECT'
];

$upper_query = strtoupper($sanitized_query);
foreach ($dangerous as $keyword) {
    if (strpos($upper_query, $keyword) !== false) {
        $error = "Query rejected: dangerous SQL keyword detected (" . $keyword . ")";
        log_message("REJECTED: " . $error);
        echo json_encode(["error" => $error]);
        exit(1);
    }
}

// Only allow SELECT, SHOW, DESCRIBE, EXPLAIN
$allowed_starts = ['SELECT', 'SHOW', 'DESCRIBE', 'EXPLAIN', 'DESC'];
$first_word = strtok($upper_query, ' \t');
if (!in_array($first_word, $allowed_starts)) {
    $error = "Only " . implode(', ', $allowed_starts) . " queries are allowed";
    log_message("REJECTED: " . $error);
    echo json_encode(["error" => $error]);
    exit(1);
}

// Add LIMIT if not present
if (stripos($sanitized_query, 'LIMIT') === false) {
    $sanitized_query .= " LIMIT 100";
}

// Connect to database
$link = new mysqli($dbhost, $dbuser, $dbpass, $dbname);
if ($link->connect_error) {
    $error = "Database connection failed: " . $link->connect_error;
    log_message("ERROR: " . $error);
    echo json_encode(["error" => $error]);
    exit(1);
}

// Execute query
$result = $link->query($sanitized_query);
if (!$result) {
    $error = "Query failed: " . $link->error;
    log_message("ERROR: " . $error);
    $link->close();
    echo json_encode(["error" => $error]);
    exit(1);
}

// Fetch results
$columns = [];
while ($field = $result->fetch_field()) {
    $columns[] = $field->name;
}

$rows = [];
while ($row = $result->fetch_assoc()) {
    $redacted_row = [];
    foreach ($row as $key => $value) {
        if (in_array(strtolower($key), $sensitive_columns)) {
            $redacted_row[$key] = "[REDACTED]";
        } else {
            $redacted_row[$key] = $value;
        }
    }
    $rows[] = $redacted_row;
}

// Build response
$output = [
    "columns" => $columns,
    "rows" => $rows,
    "count" => count($rows),
    "query" => $sanitized_query,
];

log_message("SUCCESS: " . count($rows) . " rows returned");

echo json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

$link->close();
