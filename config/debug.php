<?php
// ACPS Debug Console
// Access via Gear Icon on Dashboard

$logDir = __DIR__ . '/../../logs';
$spoolDir = __DIR__ . '/../../photos/' . date('Y/m/d') . '/spool';

// Helper: Tail File
function tail($filepath, $lines = 50) {
    if (!file_exists($filepath)) return "File not found.";
    return shell_exec("tail -n $lines " . escapeshellarg($filepath)); // UNIX style, might fail on pure Windows if no tail.
    // Fallback for Windows
    $data = file($filepath);
    $data = array_slice($data, -$lines);
    return implode("", $data);
}

$action = $_GET['action'] ?? 'view';
$file = $_GET['file'] ?? '';

if ($action === 'tail' && $file) {
    echo tail($logDir . '/' . basename($file));
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>ACPS Debug</title>
    <style>
        body { background: #111; color: #eee; font-family: monospace; padding: 20px; }
        h1 { color: #dc3545; border-bottom: 1px solid #444; padding-bottom: 10px; }
        .grid { display: grid; grid-template-columns: 250px 1fr; gap: 20px; height: 90vh; }
        .sidebar { border-right: 1px solid #333; padding-right: 20px; }
        .main { overflow-y: auto; background: #000; padding: 15px; border: 1px solid #333; }
        .log-link { display: block; padding: 5px; color: #aaa; text-decoration: none; cursor: pointer; }
        .log-link:hover { color: #fff; background: #222; }
        .log-content { white-space: pre-wrap; font-size: 12px; color: #0f0; }
        .status-card { background: #222; padding: 10px; margin-bottom: 10px; border-left: 3px solid #007bff; }
    </style>
    <script>
        function loadLog(file) {
            fetch('?action=tail&file=' + file)
                .then(r => r.text())
                .then(txt => document.getElementById('log-viewer').textContent = txt);
        }
    </script>
</head>
<body>
    <h1>ACPS DEBUG CONSOLE</h1>
    <div class="grid">
        <div class="sidebar">
            <h3>System Logs</h3>
            <?php
            $logs = glob($logDir . '/*.log');
            foreach($logs as $log) {
                $name = basename($log);
                echo "<div class='log-link' onclick=\"loadLog('$name')\">$name</div>";
            }
            ?>
            <h3>JSON Logs</h3>
            <?php
            $jsons = glob($logDir . '/*.json');
            foreach($jsons as $json) {
                $name = basename($json);
                echo "<div class='log-link' onclick=\"loadLog('$name')\">$name</div>";
            }
            ?>
        </div>
        <div class="main">
            <div id="log-viewer" class="log-content">Select a log to view...</div>
        </div>
    </div>
</body>
</html>
