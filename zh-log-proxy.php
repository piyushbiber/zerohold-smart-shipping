<?php
/**
 * Log Proxy for Antigravity Debugging
 * To access: https://yourdomain.com/market/wp-content/plugins/zerohold-smart-shipping/zh-log-proxy.php?token=zerohold_debug_2026
 */

$secret_token = 'zerohold_debug_2026';

if (!isset($_GET['token']) || $_GET['token'] !== $secret_token) {
    header('HTTP/1.0 403 Forbidden');
    die('Unauthorized access.');
}

$log_file = dirname(__DIR__, 3) . '/debug.log';

if (!file_exists($log_file)) {
    header('Content-Type: text/plain');
    die('Debug log file not found at: ' . $log_file);
}

// Read only the last 200 lines to save bandwidth and memory
$lines = 200;
$data = shell_exec("tail -n $lines " . escapeshellarg($log_file));

header('Content-Type: text/plain');
if ($data) {
    echo "--- LAST $lines LINES OF DEBUG LOG ---\n";
    echo $data;
} else {
    // Fallback for systems without shell_exec or tail
    $file_content = file($log_file);
    $last_lines = array_slice($file_content, -$lines);
    echo "--- LAST $lines LINES OF DEBUG LOG (PHP Fallback) ---\n";
    echo implode("", $last_lines);
}
