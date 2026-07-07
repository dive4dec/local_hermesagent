<?php
/**
 * Reverse proxy for the Hermes Dashboard.
 *
 * Starts the dashboard if not running (port 9119), then proxies all
 * requests to it — injecting the session token for API auth.
 *
 * The dashboard is a full SPA (HTML/CSS/JS) bundled in the hermes-agent
 * pip package. It provides a web UI for config, sessions, MCP, tools, etc.
 *
 * URL: /local/hermesagent/dashboard.php/<path>
 *   e.g. dashboard.php/              → HTML SPA
 *        dashboard.php/api/config    → JSON API (token injected)
 *        dashboard.php/api/sessions  → JSON API (token injected)
 */

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

require_login();
require_capability('local/hermesagent:configure', context_system::instance());

$hermes_home = '/var/www/moodledata/.hermes';
$venv_bin = "$hermes_home/venv/bin";
$dashboard_port = 9119;
$session_token = 'hermes-moodle-dashboard';

// --- Ensure dashboard is running -------------------------------------------
function ensure_dashboard_running(int $port, string $token, string $hermes_home, string $venv_bin): bool {
    // Fast path: health check
    $ch = curl_init("http://127.0.0.1:$port/api/status");
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 2]);
    curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($http === 200) {
        return true;
    }

    // Check if already starting
    $pidfile = "$hermes_home/pids/dashboard.pid";
    if (file_exists($pidfile)) {
        $pid = trim(file_get_contents($pidfile));
        if ($pid && posix_kill(intval($pid), 0)) {
            return false; // still booting
        }
        @unlink($pidfile);
    }

    // Start the dashboard
    $log_file = "$hermes_home/logs/dashboard.log";
    $cmd = sprintf(
        'HERMES_HOME=%s HERMES_DASHBOARD_SESSION_TOKEN=%s %s/hermes dashboard --port %d --no-open --skip-build >> %s 2>&1 & echo $! > %s',
        escapeshellarg($hermes_home),
        escapeshellarg($token),
        escapeshellarg($venv_bin),
        $port,
        escapeshellarg($log_file),
        escapeshellarg($pidfile)
    );
    exec($cmd);

    // Wait for it to be ready (up to 15s)
    for ($i = 0; $i < 15; $i++) {
        sleep(1);
        $ch = curl_init("http://127.0.0.1:$port/api/status");
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 2]);
        curl_exec($ch);
        $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($http === 200) {
            @unlink($pidfile);
            return true;
        }
    }
    return false;
}

// --- Proxy the request ------------------------------------------------------
// Parse the path after dashboard.php
$uri = $_SERVER['REQUEST_URI'] ?? '';
$prefix = '/local/hermesagent/dashboard.php';
$pos = strpos($uri, $prefix);
if ($pos === false) {
    $path = '/';
} else {
    $path = substr($uri, $pos + strlen($prefix));
    if (empty($path) || $path[0] !== '/') {
        $path = '/' . $path;
    }
}

// Remove query string from path (we'll re-append it)
$query = '';
if (($qpos = strpos($path, '?')) !== false) {
    $query = substr($path, $qpos);
    $path = substr($path, 0, $qpos);
}

// Ensure dashboard is running
if (!ensure_dashboard_running($dashboard_port, $session_token, $hermes_home, $venv_bin)) {
    http_response_code(503);
    echo '<!doctype html><html><body><h1>Dashboard not available</h1>';
    echo '<p>The Hermes Dashboard is starting. Please refresh in a few seconds.</p>';
    echo '<p>If this persists, check the dashboard log or run "Update &amp; Bootstrap".</p>';
    echo '</body></html>';
    exit;
}

// Build the target URL
$target_url = "http://127.0.0.1:$dashboard_port$path$query";

// Build headers to forward
$forward_headers = ['Content-Type', 'Accept', 'Accept-Encoding'];
$req_headers = [];
foreach ($forward_headers as $h) {
    $key = 'HTTP_' . strtoupper(str_replace('-', '_', $h));
    if (isset($_SERVER[$key])) {
        $req_headers[] = "$h: " . $_SERVER[$key];
    }
}

// Inject the session token for API authentication
if (strpos($path, '/api/') === 0) {
    $req_headers[] = "Authorization: Bearer $session_token";
}

// Handle request method
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$body = file_get_contents('php://input');

$ch = curl_init($target_url);
curl_setopt_array($ch, [
    CURLOPT_CUSTOMREQUEST => $method,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HEADER => true,
    CURLOPT_HTTPHEADER => $req_headers,
    CURLOPT_TIMEOUT => 300,
    CURLOPT_FOLLOWLOCATION => false,
]);

if ($method === 'POST' || $method === 'PUT' || $method === 'PATCH') {
    curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
}

$response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$header_size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
curl_close($ch);

// Split headers and body
$response_headers = substr($response, 0, $header_size);
$response_body = substr($response, $header_size);

// Forward status code
http_response_code($http_code);

// Forward relevant response headers (skip Transfer-Encoding, Connection, etc.)
$header_lines = explode("\r\n", $response_headers);
$skip_headers = ['transfer-encoding', 'connection', 'content-length', 'content-encoding', 'host'];
foreach ($header_lines as $line) {
    if (strpos($line, ':') === false) continue;
    [$key, $val] = explode(':', $line, 2);
    $key_lower = strtolower(trim($key));
    if (in_array($key_lower, $skip_headers)) continue;
    header("$key: $val");
}

// Rewrite HTML responses: fix asset paths and set base path for the SPA
if (strpos($path, '/api/') !== 0 && $http_code === 200) {
    // __HERMES_BASE_PATH__ must be a path (not full URL) — React Router's
    // basename expects a path prefix, and the SPA uses it for API fetch calls.
    // Extract the path component from wwwroot (e.g. "/edb") and append the proxy path.
    $wwwroot_path = parse_url($CFG->wwwroot, PHP_URL_PATH) ?? '';
    $proxy_base_path = rtrim($wwwroot_path, '/') . '/local/hermesagent/dashboard.php';
    // Rewrite absolute asset paths to go through the proxy (relative to server root)
    $response_body = str_replace('src="/assets/', 'src="' . $proxy_base_path . '/assets/', $response_body);
    $response_body = str_replace('href="/assets/', 'href="' . $proxy_base_path . '/assets/', $response_body);
    $response_body = str_replace('href="/favicon.ico', 'href="' . $proxy_base_path . '/favicon.ico', $response_body);
    // Set the SPA base path so API calls go through the proxy
    $response_body = str_replace(
        'window.__HERMES_BASE_PATH__=""',
        'window.__HERMES_BASE_PATH__="' . $proxy_base_path . '"',
        $response_body
    );
}

echo $response_body;
