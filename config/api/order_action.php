<?php
// Gemicunt API - Order Action Endpoint (PAID/VOID/EMAIL)
// Replaces legacy /admin/admin_cash_order_action.php logic

header('Content-Type: application/json');
require_once __DIR__ . '/../../admin/config.php';

ini_set('memory_limit', '-1');
set_time_limit(0);
ignore_user_abort();


// Location identification (prefer LOCATION_NAME, fallback to LOCATION_SLUG, then UNKNOWN)
$location = getenv('LOCATION_NAME');
if ($location && trim($location) !== '') {
    $location = trim($location);
} else if (getenv('LOCATION_SLUG') && trim(getenv('LOCATION_SLUG')) !== '') {
    $location = trim(getenv('LOCATION_SLUG'));
} else {
    $location = 'UNKNOWN';
}

$orderID = isset($_POST['order'])  ? trim($_POST['order'])  : '';
$action  = isset($_POST['action']) ? trim($_POST['action']) : '';
$paymentMethod = isset($_POST['payment_method']) ? trim($_POST['payment_method']) : 'cash';
$transactionId = isset($_POST['transaction_id']) ? trim($_POST['transaction_id']) : '';

if ($orderID === '' || !preg_match('/^\d+$/', $orderID)) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid or missing order number.']);
    exit;
}

$action = strtolower($action);
if (!in_array($action, ['paid', 'void', 'email'], true)) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid action.']);
    exit;
}

$baseDir = realpath(__DIR__ . '/../../photos');
if (!$baseDir) {
    echo json_encode(['status' => 'error', 'message' => 'Photo base directory not found.']);
    exit;
}

$date_path   = date('Y/m/d');
$receiptPath = $baseDir . '/' . $date_path . '/receipts/' . $orderID . '.txt';

if (!file_exists($receiptPath)) {
    echo json_encode(['status' => 'error', 'message' => "Receipt not found for Order #$orderID"]);
    exit;
}

$receiptData = file_get_contents($receiptPath);
if ($receiptData === false || $receiptData === '') {
    echo json_encode(['status' => 'error', 'message' => "Unable to read receipt for Order #$orderID"]);
    exit;
}

// --- Helper: Get Auto Print Status from config file
function acp_get_autoprint_status(): bool {
    $statusFilePath = realpath(__DIR__ . "/../../config/autoprint_status.txt");
    if ($statusFilePath && file_exists($statusFilePath)) {
        $content = @file_get_contents($statusFilePath);
        return trim($content) === '1';
    }
    return true;
}

function acp_log_event($orderID, $event) {
    $log_data = [
        'log_message' => "Order {$orderID} | {$event}",
    ];
    $payload = http_build_query($log_data);
    $host = $_SERVER['HTTP_HOST'] ?? '127.0.0.1';
    $port = $_SERVER['SERVER_PORT'] ?? 80;
    if (strpos($host, ':') !== false) {
        list($host, $port) = explode(':', $host);
    }
    $fp = @fsockopen($host, $port, $errno, $errstr, 1);
    if ($fp) {
        $out = "POST /admin/admin_cash_order_log.php?action=log HTTP/1.1\r\n";
        $out .= "Host: {$host}\r\n";
        $out .= "Content-Type: application/x-www-form-urlencoded\r\n";
        $out .= "Content-Length: " . strlen($payload) . "\r\n";
        $out .= "Connection: Close\r\n\r\n";
        $out .= $payload;
        fwrite($fp, $out);
        fclose($fp);
        return true;
    }
    return false;
}

function acp_sync_log_to_master($location, $dateISO, $type, $count, $amount) {
    $url = 'https://alleycatphoto.net/admin/index.php?' . http_build_query([
        'action'   => 'log',
        'date'     => $dateISO,
        'location' => $location,
        'type'     => $type,
        'count'    => $count,
        'amount'   => $amount
    ]);
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        curl_exec($ch);
        curl_close($ch);
    } else {
        @file_get_contents($url);
    }
}

function acp_parse_receipt_items(array $lines): array {
    $items = [];
    foreach ($lines as $line) {
        $lineTrim = trim($line);
        if (preg_match('/\[(\d+)\]\s*(.+?)\s*\((\d+)\)/', $lineTrim, $m)) {
            $qty       = intval($m[1]);
            $item_name = trim($m[2]);
            $photo_id  = trim($m[3]);
            if (preg_match('/(\d+x\d+)/', $item_name, $size)) {
                $prod_code = $size[1];
            } elseif (stripos($item_name, 'email') !== false) {
                $prod_code = 'EML';
            } else {
                $prod_code = 'UNK';
            }
            $items[] = [
                'prod_code' => $prod_code,
                'photo_id'  => $photo_id,
                'quantity'  => $qty
            ];
        }
    }
    return $items;
}

function acp_update_cash_status(string $receipt, string $newStatus): array {
    $count = 0;
    $updated = preg_replace(
        '/^(CASH ORDER:\s*\$[0-9]+(?:\.[0-9]{2})?)\s+DUE\s*$/mi',
        '$1 ' . strtoupper($newStatus),
        $receipt,
        1,
        $count
    );
    if ($updated === null) {
        return [$receipt, 0];
    }
    return [$updated, $count];
}

function acp_update_square_status(string $receipt, string $transactionId): array {
    $count = 0;
    // Find the original amount $X
    if (preg_match('/CASH ORDER:\s*\$([0-9]+(?:\.[0-9]{2})?)\s+DUE/i', $receipt, $m)) {
        $originalAmt = floatval($m[1]);
        $taxedAmt = ($originalAmt * 1.035) * 1.0675;
        $taxedAmtStr = number_format($taxedAmt, 2);
        $originalAmtStr = number_format($originalAmt, 2);

        // 1. Replace "CASH ORDER: $X DUE" with "SQUARE ORDER: $Y PAID"
        // Note: Use \\$ to escape the dollar sign so preg_replace doesn't treat $1 as a backreference
        $updated = preg_replace(
            '/^CASH ORDER:\s*\$[0-9]+(?:\.[0-9]{2})?\s+DUE\s*$/mi',
            'SQUARE ORDER: \\$' . $taxedAmtStr . ' PAID',
            $receipt,
            1,
            $count
        );

        // 2. "update the two places with the untaxed totals"
        // Replace all occurrences of the original amount string (e.g. "$10.00") with the new taxed one.
        $updated = str_replace('$' . $originalAmtStr, '$' . $taxedAmtStr, $updated);
        
        // 3. Add Square Confirmation ID at the bottom
        $updated .= "\nSQUARE CONFIRMATION: " . $transactionId . "\n";
        
        return [$updated, 1];
    }
    return [$receipt, 0];
}

function acp_update_qr_status(string $receipt, string $transactionId): array {
    $count = 0;
    // Find the original amount $X
    if (preg_match('/CASH ORDER:\s*\$([0-9]+(?:\.[0-9]{2})?)\s+DUE/i', $receipt, $m)) {
        $originalAmt = floatval($m[1]);
        $taxedAmt = ($originalAmt * 1.035) * 1.0675;
        $taxedAmtStr = number_format($taxedAmt, 2);
        $originalAmtStr = number_format($originalAmt, 2);

        // 1. Replace "CASH ORDER: $X DUE" with "SQUARE ORDER: $Y PAID"
        $updated = preg_replace(
            '/^CASH ORDER:\s*\$[0-9]+(?:\.[0-9]{2})?\s+DUE\s*$/mi',
            'SQUARE ORDER: \\$' . $taxedAmtStr . ' PAID',
            $receipt,
            1,
            $count
        );

        // 2. Update all occurrences of the original amount string with the new taxed one.
        $updated = str_replace('$' . $originalAmtStr, '$' . $taxedAmtStr, $updated);
        
        // 3. Add QR Confirmation ID at the bottom
        $updated .= "\nQR CONFIRMATION: " . $transactionId . "\n";
        
        return [$updated, 1];
    }
    return [$receipt, 0];
}

function acp_send_digital_email($orderID): array {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $url    = $scheme . '://' . $host . '/mailer.php?order=' . urlencode($orderID);
    $body = '';
    $ok   = false;
    $payload = http_build_query([]);
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Accept: text/plain,*/*']);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        
        // --- PATCH: Fire and Forget (don't wait for emailer to finish) ---
        curl_setopt($ch, CURLOPT_TIMEOUT, 1); // Timeout after 1 second
        curl_setopt($ch, CURLOPT_NOSIGNAL, 1);

        $cacertPath = realpath(__DIR__ . '/../../cacert.pem');
        if ($cacertPath && file_exists($cacertPath)) {
            curl_setopt($ch, CURLOPT_CAINFO, $cacertPath);
        }
        $body   = curl_exec($ch);
        $errNo  = curl_errno($ch); // 28 is timeout
        $errStr = curl_error($ch);
        curl_close($ch);

        // Treat timeout (28) as success for "background" sending
        if ($errNo === 0 || $errNo === 28) {
            $ok = true; 
            $body = "Email triggered in background (Timeout set to 1s).";
        } else {
            $body = 'cURL error: ' . $errStr;
        }
    } else {
        $context = stream_context_create([
            'http' => [
                'method'  => 'POST',
                'header'  => "Content-Type: application/x-www-form-urlencoded\r\n" .
                             "Accept: text/plain,*/*\r\n",
                'content' => $payload,
                'timeout' => 10,
            ]
        ]);
        $body = @file_get_contents($url, false, $context);
        if ($body !== false) {
            $text = preg_replace('/<[^>]*>/', '', $body);
            $ok   = (bool)preg_match('/Message has been sent/i', $text);
        } else {
            $body = 'HTTP request to /mailer.php failed.';
        }
    }
    return [
        'success' => $ok,
        'raw'     => $body,
    ];
}

function acp_print_order_items($orderID, $baseDir, $date_path, array $items, string $receiptData): array {
    $defaultOutputDir = getenv('PRINT_OUTPUT_DIR') ?: "../orders";
    $fsOutputDir      = getenv('PRINT_OUTPUT_DIR_FS') ?: "../orders_fs";
    if (strpos($receiptData, '- FS') !== false) {
        $orderOutputDir = $fsOutputDir;
    } else {
        $orderOutputDir = $defaultOutputDir;
    }
    if (!is_dir($orderOutputDir)) {
        @mkdir($orderOutputDir, 0777, true);
    }
    $copiedFiles = [];
    foreach ($items as $item) {
        $prod_code = $item['prod_code'];
        $photo_id  = $item['photo_id'];
        $quantity  = $item['quantity'];
        if ($prod_code === 'EML') {
            continue;
        }
        $sourcefile = $baseDir . '/' . $date_path . '/raw/' . $photo_id . '.jpg';
        if (!file_exists($sourcefile)) {
            continue;
        }
        $orientation = 'V';
        $imgInfo = @getimagesize($sourcefile);
        if ($imgInfo && isset($imgInfo[0], $imgInfo[1])) {
            $orientation = ($imgInfo[0] > $imgInfo[1]) ? 'H' : 'V';
        }
        for ($i = 1; $i <= $quantity; $i++) {
            $destfile = sprintf(
                "%s/%s-%s-%s%s-%d.jpg",
                $orderOutputDir,
                $orderID,
                $photo_id,
                $prod_code,
                $orientation,
                $i
            );
            if (@copy($sourcefile, $destfile)) {
                $copiedFiles[] = basename($destfile);
            }
        }
    }
    return $copiedFiles;
}

function acp_stage_email_items(
    $orderID,
    $baseDir,
    $date_path,
    array $items,
    array $lines,
    string $receiptData
): array {
    $result = [
        'has_email_items' => false,
        'staged'          => false,
        'email'           => null,
        'message'         => null,
        'copied'          => [],
        'error'           => null,
    ];
    $emailItems = [];
    foreach ($items as $it) {
        if ($it['prod_code'] === 'EML') {
            $emailItems[] = $it;
        }
    }
    if (empty($emailItems)) {
        return $result;
    }
    $result['has_email_items'] = true;
    $user_email = '';
    foreach ($lines as $line) {
        if (preg_match('/([A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,})/i', $line, $m)) {
            $user_email = trim(strtolower($m[1]));
            break;
        }
    }
    if ($user_email === '') {
        $result['error'] = 'No email address found in receipt.';
        return $result;
    }
    $result['email']   = $user_email;
    $result['message'] = 'Full receipt in info.txt';
    $emailsUserDir = $baseDir . '/' . $date_path . '/emails/' . $user_email;
    if (!is_dir($emailsUserDir) && !@mkdir($emailsUserDir, 0777, true)) {
        $result['error'] = 'Unable to create email image directory.';
        return $result;
    }
    $seenPhoto = [];
    foreach ($emailItems as $it) {
        $photo_id = $it['photo_id'];
        if (isset($seenPhoto[$photo_id])) {
            continue;
        }
        $seenPhoto[$photo_id] = true;
        $sourcefile = $baseDir . '/' . $date_path . '/raw/' . $photo_id . '.jpg';
        if (!file_exists($sourcefile)) {
            continue;
        }
        $destfile = $emailsUserDir . '/' . $photo_id . '.jpg';
        if (@copy($sourcefile, $destfile)) {
            $result['copied'][] = $destfile;
        }
    }
    if (empty($result['copied'])) {
        $result['error'] = 'No digital image files could be copied for email.';
        return $result;
    }
    $cashEmailDir = $baseDir . '/' . $date_path . '/cash_email/' . $orderID;
    if (!is_dir($cashEmailDir) && !@mkdir($cashEmailDir, 0777, true)) {
        $result['error'] = 'Unable to create cash_email info directory.';
        return $result;
    }
    if (@file_put_contents($cashEmailDir . '/info.txt', $receiptData) === false) {
        $result['error'] = 'Unable to write info.txt for email.';
        return $result;
    }
    $result['staged'] = true;
    return $result;
}

// --- Main Action Logic ---
$normalized  = str_replace("\r", "", $receiptData);
$lines       = explode("\n", $normalized);
$isCashDue = preg_match('/^CASH ORDER:\s*\$[0-9]+(?:\.[0-9]{2})?\s+DUE\s*$/mi', $receiptData);
$items          = acp_parse_receipt_items($lines);
$copiedFiles    = [];
$emailAttempted = false;
$emailSuccess   = false;
$emailRaw       = null;
$emailStageInfo = null;

if ($action === 'email') {
    $emailResult = acp_send_digital_email($orderID);
    if ($emailResult['success']) {
        acp_log_event($orderID, "EMAIL_OK");
        echo json_encode([
            'status'          => 'success',
            'message'         => "Email sent for Order #$orderID.",
            'email_attempted' => true,
            'email_success'   => true,
        ]);
    } else {
        acp_log_event($orderID, "EMAIL_ERROR: {$emailResult['raw']}");
        echo json_encode([
            'status'          => 'error',
            'message'         => "Email step failed for Order #$orderID.",
            'email_attempted' => true,
            'email_success'   => false,
            'email_raw'       => $emailResult['raw'],
        ]);
    }
    exit;
}

if ($action === 'paid') {
    $shouldAutoPrint = acp_get_autoprint_status();
    if ($shouldAutoPrint) {
        $copiedFiles = acp_print_order_items($orderID, $baseDir, $date_path, $items, $receiptData);
        if (!empty($copiedFiles)) {
            acp_log_event($orderID, "PRINT_OK (x".count($copiedFiles).")");
        }
    } else {
        acp_log_event($orderID, "PRINT_SKIP (Auto Print OFF)");
    }
    $emailStageInfo = acp_stage_email_items(
        $orderID,
        $baseDir,
        $date_path,
        $items,
        $lines,
        $receiptData
    );
    if ($emailStageInfo['has_email_items']) {
        $emailAttempted = true;
        if ($emailStageInfo['staged']) {
            $sendResult  = acp_send_digital_email($orderID);
            $emailSuccess = $sendResult['success'];
            $emailRaw     = $sendResult['raw'];
        } else {
            $emailSuccess = false;
            $emailRaw     = 'Staging failed: ' . $emailStageInfo['error'];
            acp_log_event($orderID, "STAGE_ERROR: {$emailStageInfo['error']}");
        }
    }

    if ($paymentMethod === 'square') {
        list($updatedReceipt, $changed) = acp_update_square_status($receiptData, $transactionId);
    } else if ($paymentMethod === 'qr') {
        list($updatedReceipt, $changed) = acp_update_qr_status($receiptData, $transactionId);
    } else {
        list($updatedReceipt, $changed) = acp_update_cash_status($receiptData, 'PAID');
    }

    if ($changed > 0) {
        file_put_contents($receiptPath, $updatedReceipt);
        $receiptData = $updatedReceipt;
        acp_log_event($orderID, strtoupper($paymentMethod));

        // --- Log to Daily CSV ---
        if (preg_match('/(?:CASH|SQUARE|QR) ORDER:\s*\$([0-9]+\.[0-9]{2})\s*PAID/i', $updatedReceipt, $m)) {
            $txtAmt = floatval($m[1]);
            
            $csvFile = __DIR__ . '/../../sales/transactions.csv';
            $today = date("m/d/Y");
            $locationKey = $location;

            $data = [];
            if (file_exists($csvFile)) {
                $handle = @fopen($csvFile, 'r');
                if ($handle !== false) {
                    $header = fgetcsv($handle); // Skip header
                    while (($row = fgetcsv($handle)) !== false) {
                        $key = $row[0] . '|' . $row[1] . '|' . ($row[3] ?? '');
                        if (isset($row[4])) $row[4] = (float)str_replace(['$', '"', ','], '', $row[4]);
                        $data[$key] = $row;
                    }
                    fclose($handle);
                }
            }

            $pType = ($paymentMethod === 'square' || $paymentMethod === 'qr') ? 'Credit' : 'Cash';
            
            $key = $locationKey . '|' . $today . '|' . $pType;
            if (!isset($data[$key])) {
                $data[$key] = [$locationKey, $today, 0, $pType, 0];
            }
            $data[$key][2] += 1;
            $data[$key][4] += $txtAmt;

            // Ensure directory exists
            if (!is_dir(dirname($csvFile))) {
                @mkdir(dirname($csvFile), 0777, true);
            }

            $fp = @fopen($csvFile, 'w');
            if ($fp !== false) {
                fputcsv($fp, ['Location', 'Order Date', 'Orders', 'Payment Type', 'Amount']);
                foreach ($data as $row) {
                    $row[4] = '$' . number_format($row[4], 2);
                    fputcsv($fp, $row);
                }
                fclose($fp);

                // Real-time sync to master log
                $dateISO = date('Y-m-d');
                acp_sync_log_to_master($locationKey, $dateISO, $pType, $data[$key][2], $data[$key][4]);
            } else {
                error_log("Failed to open CSV for writing in order_action.php: " . $csvFile);
            }
        }
    }
    $statusMsg = "Order #$orderID marked " . strtoupper($paymentMethod) . " PAID.";
} else if ($action === 'void') {
    list($updatedReceipt, $changed) = acp_update_cash_status($receiptData, 'VOID');
    if ($changed > 0) {
        file_put_contents($receiptPath, $updatedReceipt);
        $receiptData = $updatedReceipt;
        acp_log_event($orderID, "VOID");
    }
    $statusMsg = "Order #$orderID voided.";
}

echo json_encode([
    'status'          => 'success',
    'message'         => $statusMsg ?? '',
    'action'          => $action,
    'files'           => $copiedFiles,
    'email_attempted' => $emailAttempted,
    'email_success'   => $emailSuccess,
    'email_raw'       => $emailRaw,
    'receipt'         => nl2br(htmlspecialchars($receiptData, ENT_QUOTES, 'UTF-8')),
    'email_stage'     => $emailStageInfo,
]);
