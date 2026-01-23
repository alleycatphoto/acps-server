<?php
/**
 * Mail Queue Management API
 * Lists stuck mailer orders and allows retry/force send
 */

header('Content-Type: application/json');

$action = $_GET['action'] ?? $_POST['action'] ?? 'list';
$order_id = $_GET['order_id'] ?? $_POST['order_id'] ?? null;

// --- LIST QUEUE ---
if ($action === 'list') {
    $spool_base = __DIR__ . '/../../photos/2026/01/23/spool/mailer/';
    
    if (!is_dir($spool_base)) {
        echo json_encode(['status' => 'error', 'message' => 'Spool directory not found']);
        exit;
    }
    
    $orders = [];
    $dirs = scandir($spool_base);
    
    foreach ($dirs as $dir) {
        if ($dir === '.' || $dir === '..') continue;
        if (!is_numeric($dir)) continue;
        
        $order_dir = $spool_base . $dir;
        if (!is_dir($order_dir)) continue;
        
        $lock_file = $order_dir . '/.gmailer_processing';
        $info_file = $order_dir . '/info.txt';
        $image_count = count(glob($order_dir . '/*.jpg')) + count(glob($order_dir . '/*.png'));
        
        $info = [];
        if (file_exists($info_file)) {
            parse_str(file_get_contents($info_file), $info);
        }
        
        $age_seconds = time() - filemtime($order_dir);
        
        $orders[] = [
            'order_id' => intval($dir),
            'email' => $info['email'] ?? 'unknown',
            'locked' => file_exists($lock_file),
            'lock_age_seconds' => file_exists($lock_file) ? time() - filemtime($lock_file) : 0,
            'images' => $image_count,
            'age_seconds' => $age_seconds,
            'created' => date('Y-m-d H:i:s', filemtime($order_dir))
        ];
    }
    
    // Sort by order_id descending
    usort($orders, fn($a, $b) => $b['order_id'] - $a['order_id']);
    
    echo json_encode(['status' => 'success', 'orders' => $orders]);
    exit;
}

// --- REMOVE LOCK ---
if ($action === 'unlock' && $order_id) {
    $lock_file = __DIR__ . "/../../photos/2026/01/23/spool/mailer/$order_id/.gmailer_processing";
    
    if (!file_exists($lock_file)) {
        echo json_encode(['status' => 'error', 'message' => 'Order not locked']);
        exit;
    }
    
    if (@unlink($lock_file)) {
        echo json_encode(['status' => 'success', 'message' => "Unlocked order $order_id"]);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Could not remove lock file']);
    }
    exit;
}

// --- RETRY SEND ---
if ($action === 'retry' && $order_id) {
    // Remove lock first
    $lock_file = __DIR__ . "/../../photos/2026/01/23/spool/mailer/$order_id/.gmailer_processing";
    @unlink($lock_file);
    
    // Trigger gmailer for this order
    $spool_dir = __DIR__ . "/../../photos/2026/01/23/spool/mailer/$order_id/";
    
    if (!is_dir($spool_dir)) {
        echo json_encode(['status' => 'error', 'message' => 'Spool directory not found']);
        exit;
    }
    
    // Call gmailer.php
    $cmd = 'php ' . escapeshellarg(__DIR__ . '/../../gmailer.php') . ' ' . escapeshellarg($order_id);
    
    // Run in background but capture output
    $output = [];
    $return_var = 0;
    exec($cmd . ' 2>&1', $output, $return_var);
    
    echo json_encode([
        'status' => 'success',
        'message' => "Retry triggered for order $order_id",
        'return_code' => $return_var,
        'output' => implode("\n", array_slice($output, -20)) // Last 20 lines
    ]);
    exit;
}

echo json_encode(['status' => 'error', 'message' => 'Unknown action']);
