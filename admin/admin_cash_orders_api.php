<?php
//*********************************************************************//
// AlleyCat PhotoStation : Pending Cash Orders JSON API (for refresh)
//*********************************************************************//
error_log("API HIT: admin_cash_orders_api.php");
require_once("config.php");

header('Content-Type: application/json');

$pendingCashOrders = [];

try {
    // Determine the base path to the photos directory
    $baseDir = realpath(__DIR__ . "/../photos");
    if (!$baseDir) {
        echo json_encode(['status' => 'error', 'message' => 'Photo base directory not found.']);
        exit;
    }

    $date_path   = date('Y/m/d');
    $receiptsDir = rtrim($baseDir, '/').'/'.$date_path.'/receipts';

    if (!is_dir($receiptsDir)) {
        // If the receipts directory doesn't exist, return empty list (status 'ok' expected by JS)
        echo json_encode([
            'status' => 'ok',
            'count'  => 0,
            'orders' => []
        ]);
        exit;
    }

    // Scan all receipt files for today
    $files = glob($receiptsDir.'/*.txt') ?: [];

    foreach ($files as $receiptFile) {
        $raw = @file_get_contents($receiptFile);
        if ($raw === false || trim($raw) === '') {
            continue;
        }

        $lines = preg_split('/\r\n|\r|\n/', $raw);

        $paymentType = 'cash';
        $amount = 0.0;
        $foundOrderType = false;
        foreach ($lines as $line) {
            $lineTrim = trim($line);
            if (preg_match('/^CASH ORDER:\s*\$([0-9]+(?:\.[0-9]{2})?)\s+DUE\s*$/i', $lineTrim, $m)) {
                $paymentType = 'cash';
                $amount = (float)$m[1];
                $foundOrderType = true;
                break;
            } elseif (preg_match('/^SQUARE ORDER:\s*\$([0-9]+(?:\.[0-9]{2})?)\s+PAID\s*$/i', $lineTrim, $m)) {
                $paymentType = 'square';
                $amount = (float)$m[1];
                $foundOrderType = true;
                break;
            }
        }
        if (!$foundOrderType) {
            continue;
        }

        // 2) Extract order details
        $orderId   = null;
        $orderDate = '';
        $label     = '';
        $squareConfirmation = '';
        $squareResponse = '';

        foreach ($lines as $line) {
            $trim = trim($line);
            if ($orderId === null && preg_match('/^Order (Number|#):\s*(\d+)/i', $trim, $m)) {
                $orderId = $m[2];
            }
            if ($orderDate === '' && preg_match('/^Order Date:\s*(.+)$/i', $trim, $m)) {
                $orderDate = trim($m[1]);
            }
            if ($label === '' && strpos($trim, '@') !== false) {
                $label = $trim;
            }
            if ($paymentType === 'square' && $squareConfirmation === '' && stripos($trim, 'SQUARE CONFIRMATION:') === 0) {
                $squareConfirmation = trim(substr($trim, strlen('SQUARE CONFIRMATION:')));
            }
            if ($paymentType === 'square' && $squareResponse === '' && stripos($trim, 'SQUARE RESPONSE:') === 0) {
                $squareResponse = trim(substr($trim, strlen('SQUARE RESPONSE:')));
            }
        }
        if ($orderId === null) {
            $orderId = pathinfo($receiptFile, PATHINFO_FILENAME);
        }
        $pendingCashOrders[] = [
            'id'    => (int)$orderId,
            'name'  => $label,
            'total' => $amount,
            'date'  => $orderDate,
            'payment_type' => $paymentType,
            'square_confirmation' => $squareConfirmation,
            'square_response' => $squareResponse,
        ];
    }

    // Sort by order ID
    usort($pendingCashOrders, function ($a, $b) {
        return $a['id'] <=> $b['id'];
    });

    // Return status 'ok' as expected by frontend JS
    echo json_encode([
        'status' => 'ok',
        'count'  => count($pendingCashOrders),
        'orders' => $pendingCashOrders,
    ]);

} catch (Throwable $e) {
    echo json_encode([
        'status'  => 'error',
        'message' => $e->getMessage()
    ]);
}