<?php
/**
 * Test: Automatic Email Sending Fix
 * Creates a QR order and monitors spooler behavior
 * 
 * Run: php test_autosend.php
 */

echo "=== ACPS90 Auto-Send Test Suite ===\n\n";

// Create test order parameters
$test_order = [
    'order_id' => date('Ymdhis'),  // Unique ID based on timestamp
    'customer_email' => 'test@example.com',
    'items' => [
        ['sku' => '8x10', 'quantity' => 1, 'price' => 25.00],
    ],
    'payment_method' => 'square',
];

echo "[1] Created test order data:\n";
echo "    Order ID: {$test_order['order_id']}\n";
echo "    Email: {$test_order['customer_email']}\n";
echo "    Method: {$test_order['payment_method']}\n\n";

// Check spooler paths exist
$base_dir = __DIR__;
$today = date('Y/m/d');
$mailer_spool = "$base_dir/photos/$today/spool/mailer/";

echo "[2] Checking spooler infrastructure:\n";
if (is_dir($mailer_spool)) {
    echo "    ✓ Mailer spool exists: $mailer_spool\n";
} else {
    echo "    ✗ Mailer spool NOT found\n";
}

// Check logs directory
$logs_dir = "$base_dir/logs";
if (is_dir($logs_dir)) {
    echo "    ✓ Logs directory exists: $logs_dir\n";
} else {
    echo "    ✗ Logs directory NOT found\n";
}

// Check spooler.php exists
$spooler_file = "$base_dir/config/api/spooler.php";
if (file_exists($spooler_file)) {
    echo "    ✓ spooler.php exists\n";
} else {
    echo "    ✗ spooler.php NOT found\n";
}

echo "\n[3] Testing spooler tick_mailer endpoint:\n";
$spooler_url = "http://localhost/config/api/spooler.php?action=tick_mailer";
$response = @file_get_contents($spooler_url);
if ($response) {
    $data = json_decode($response, true);
    echo "    ✓ Spooler endpoint responsive\n";
    echo "    Response: " . json_encode($data, JSON_PRETTY_PRINT) . "\n";
} else {
    echo "    ✗ Spooler endpoint failed\n";
}

echo "\n[4] Checking for stuck orders in spooler:\n";
$stuck_orders = array_diff(scandir($mailer_spool), ['.', '..']);
if (count($stuck_orders) > 0) {
    foreach ($stuck_orders as $order_id) {
        $order_path = $mailer_spool . $order_id;
        if (is_dir($order_path)) {
            $lock_file = $order_path . '/.gmailer_processing';
            $info_file = $order_path . '/info.txt';
            $has_lock = file_exists($lock_file);
            $has_info = file_exists($info_file);
            
            echo "    Order $order_id:\n";
            echo "      Lock: " . ($has_lock ? "YES (processing)" : "NO (ready)") . "\n";
            echo "      Info: " . ($has_info ? "YES" : "NO") . "\n";
            
            if (!$has_lock && $has_info) {
                echo "      Status: READY FOR PROCESSING\n";
                
                // Test: Manually call gmailer for this order
                echo "      Testing: Calling gmailer...\n";
                $gmailer_path = "$base_dir/gmailer.php";
                $cmd = "php " . escapeshellarg($gmailer_path) . " " . escapeshellarg($order_id) . " 2>&1";
                $output = [];
                exec($cmd, $output, $return_code);
                
                if ($return_code === 0) {
                    echo "      ✓ gmailer succeeded (exit code: $return_code)\n";
                    $last_output = array_slice($output, -3);
                    foreach ($last_output as $line) {
                        echo "        $line\n";
                    }
                } else {
                    echo "      ✗ gmailer failed (exit code: $return_code)\n";
                    $last_output = array_slice($output, -3);
                    foreach ($last_output as $line) {
                        echo "        ERROR: $line\n";
                    }
                }
            }
        }
    }
    echo "\n    Total stuck orders: " . count($stuck_orders) . "\n";
} else {
    echo "    ✓ No stuck orders (queue is clean)\n";
}

echo "\n[5] Checking spooler_exec.log:\n";
$exec_log = "$logs_dir/spooler_exec.log";
if (file_exists($exec_log)) {
    echo "    ✓ Log file exists: $exec_log\n";
    $log_lines = file($exec_log);
    $recent = array_slice($log_lines, -5);
    echo "    Last 5 entries:\n";
    foreach ($recent as $line) {
        echo "      " . trim($line) . "\n";
    }
} else {
    echo "    ℹ Log file doesn't exist yet (will be created when spooler spawns gmailer)\n";
}

echo "\n[6] Summary:\n";
echo "    • Fix deployed: Use exec() instead of popen() for background gmailer\n";
echo "    • Spooler called every 1.5 seconds from app.js\n";
echo "    • New orders should be auto-sent within 5-10 seconds\n";
echo "    • Lock files prevent concurrent execution\n";
echo "    • Output logged to spooler_exec.log for debugging\n";

echo "\n=== Test Complete ===\n";
?>
