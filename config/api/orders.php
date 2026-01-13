<?php
// Gemicunt API - Orders Endpoint
// Returns list of pending cash orders (and potentially others) as JSON

header('Content-Type: application/json');
require_once __DIR__ . '/../../admin/config.php';

$response = [
    'status' => 'ok',
    'orders' => [],
    'debug'  => []
];

try {
    // Base directory for photos/receipts
    $baseDir = realpath(__DIR__ . '/../../photos');

    if ($baseDir === false) {
        throw new Exception("Could not resolve base photos directory.");
    }

    $date_path   = date('Y/m/d');
    $receiptsDir = rtrim($baseDir, '/') . '/' . $date_path . '/receipts';
    
    $viewFilter = $_GET['view'] ?? 'due'; // due, paid, all
    
    // --- Load Auto Print Status ---
    $autoprintStatusPath = realpath(__DIR__ . "/../../config/autoprint_status.txt");
    $autoPrint = true;
    if ($autoprintStatusPath !== false && file_exists($autoprintStatusPath)) {
        $content = @file_get_contents($autoprintStatusPath);
        if ($content !== false && trim($content) === '0') {
            $autoPrint = false;
        }
    }
    $response['autoprint'] = $autoPrint;

    if (is_dir($receiptsDir)) {
        $files = glob($receiptsDir . '/*.txt') ?: [];
        
        foreach ($files as $receiptFile) {
            $raw = @file_get_contents($receiptFile);
            if ($raw === false || trim($raw) === '') continue;

            $lines = preg_split('/\r\n|\r|\n/', $raw);

            // Parsing Logic
            // 1. Identify Cash Order
            $isCash = false;
            $amount = 0.0;
            $orderId = null;
            $station = 'MS';
            $orderDate = '';
            $label = '';
            $items = []; 


            // Simple parser for cash due
            foreach ($lines as $line) {
                $lineTrim = trim($line);
                // Check for Cash Due
                if (preg_match('/^CASH ORDER:\s*\$([0-9]+(?:\.[0-9]{2})?)\s+DUE\s*$/i', $lineTrim, $m)) {
                    $isCash = true;
                    $amount = (float)$m[1];
                }
                // Check for Order ID
                if ($orderId === null && preg_match('/^Order (?:Number|#):\s*(\d+)(?:\s*-\s*([A-Z0-9]+))?/i', $lineTrim, $m)) {
                    $orderId = $m[1];
                    $station = $m[2] ?? 'MS';
                }
                // Check for Date
                if ($orderDate === '' && preg_match('/^Order Date:\s*(.+)$/i', $lineTrim, $m)) {
                    $orderDate = trim($m[1]);
                }
                // Check for Label
                if ($label === '') {
                    if (strpos($lineTrim, '|') !== false) {
                        $parts = explode('|', $lineTrim);
                        $label = trim($parts[0]); // Take left side (Email)
                    } elseif (strpos($lineTrim, '@') !== false) {
                        $label = $lineTrim;
                    }
                }
            }

            // Calculate Square payment amount (cc_totaltaxed) using pay.php logic
            $amount_with_tax = $amount;
            $amount_without_tax = $amount_with_tax / 1.0675;
            $surcharge = $amount_without_tax * 0.035;
            $cc_total = $amount_without_tax * 1.035;
            $cc_totaltaxed = $cc_total * 1.0675;

            if ($orderId === null) {
                $orderId = pathinfo($receiptFile, PATHINFO_FILENAME);
            }

            // Determine Status
            $isPaid = false;
            if (stripos($raw, 'PAID') !== false && !$isCash) {
                $isPaid = true;
            }
            
            // Determine type
            $type = 'Standard';
            if (stripos($raw, 'VOID') !== false) {
                $type = 'Void';
            } elseif ($isCash) $type = 'Cash Pending';
            elseif ($isPaid) $type = 'Paid';
            
            // Timestamp for elapsed calculation
            $fileTime = filemtime($receiptFile);

            // Filter Logic
            $include = false;
            if ($viewFilter === 'all') {
                $include = true;
            } elseif ($viewFilter === 'paid') {
                if ($isPaid) $include = true;
            } else { // Default: due
                if ($isCash) $include = true;
            }

            if ($include) {
                $dt = strtotime($orderDate);
                $formattedTime = $dt ? date('g:i a', $dt) : '';
                $emoji = ($station === 'FS') ? '🔥' : '📷';

                $response['orders'][] = [
                    'id'       => (string)$orderId,
                    'emoji'    => $emoji,
                    'name'     => $label,
                    'total'    => $amount,
                    'station'  => $station,
                    'cc_totaltaxed' => round($cc_totaltaxed, 2),
                    'time'     => $formattedTime,
                    'timestamp'=> $fileTime,  // Add timestamp
                    'type'     => $type,
                    'filename' => basename($receiptFile),
                    'raw_snippet' => substr($raw, 0, 100) . '...'
                ];
            }
        }
    } else {
        $response['debug'][] = "Receipts directory not found: $receiptsDir";
    }

    // Sort by ID descending (newest first)
    usort($response['orders'], function ($a, $b) {
        return $b['id'] <=> $a['id'];
    });

} catch (Exception $e) {
    $response['status'] = 'error';
    $response['message'] = $e->getMessage();
}

echo json_encode($response);
