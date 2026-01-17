<?php
//*********************************************************************//
// AlleyCat PhotoStation : Admin Cash Order Action (AJAX)
// Handles: paid / void / email for CASH ORDER receipts
//  - "paid":
//      * print order (copy files to C:\orders)
//      * stage digital email images for mailer.php
//      * call /mailer.php?order=####
//      * mark CASH ORDER line DUE -> PAID
//  - "void": mark CASH ORDER line DUE -> VOID
//  - "email": (re)send digital email only, no print, no status change
//*********************************************************************//

require_once("config.php");

ini_set('memory_limit', '-1');
set_time_limit(0);
ignore_user_abort();
header('Content-Type: application/json');

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
// The new JS sends this flag, but we don't strictly need it in the action file
// $autoprint = isset($_POST['autoprint']) ? trim($_POST['autoprint']) : '0';

if ($orderID === '' || !preg_match('/^\d+$/', $orderID)) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid or missing order number.']);
    exit;
}

$action = strtolower($action);
if (!in_array($action, ['paid', 'void', 'email'], true)) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid action.']);
    exit;
}

// ---------------------------------------------------------------------
// Common: locate today’s receipt
// ---------------------------------------------------------------------
$baseDir = realpath(__DIR__ . "/../photos");
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

// Normalise line endings and split into lines
$normalized  = str_replace("\r", "", $receiptData);
$lines       = explode("\n", $normalized);
// ---------------------------------------------------------------------
// Helper: Get Auto Print Status from config file
// ---------------------------------------------------------------------
function acp_get_autoprint_status(): bool {
    // Define the file path relative to your admin script
    $statusFilePath = realpath(__DIR__ . "/../config/autoprint_status.txt");

    if ($statusFilePath && file_exists($statusFilePath)) {
        $content = @file_get_contents($statusFilePath);
        return trim($content) === '1';
    }
    // Default to true (ON) if file doesn't exist or cannot be read.
    return true;
}
// ---------------------------------------------------------------------
// NEW Helper: Log Event to admin_cash_order_log.php
// FIX: Replaced non-existent URLSearchParams with PHP's http_build_query
// ---------------------------------------------------------------------
function acp_log_event($orderID, $event) {
    // 1. Prepare POST data using native PHP function
    $log_data = [
        'log_message' => "Order {$orderID} | {$event}",
    ];
    $payload = http_build_query($log_data);

    // 2. Use non-blocking request to the local log script
    $host = $_SERVER['HTTP_HOST'] ?? '127.0.0.1'; // Use localhost if HTTP_HOST is missing
    $port = $_SERVER['SERVER_PORT'] ?? 80;

    // Fallback if HTTP_HOST contains port
    if (strpos($host, ':') !== false) {
        list($host, $port) = explode(':', $host);
    }

    $fp = @fsockopen($host, $port, $errno, $errstr, 1); // 1 second timeout

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
    // error_log("Failed to non-blocking log event via fsockopen: {$errstr}");
    return false;
}

// ---------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------

// Parse receipt for item lines: [QTY] Description (PHOTOID)
function acp_parse_receipt_items(array $lines): array {
    $items = [];
    foreach ($lines as $line) {
        $lineTrim = trim($line);
        if (preg_match('/\[(\d+)\]\s*(.+?)\s*\((\d+)\)/', $lineTrim, $m)) {
            $qty       = intval($m[1]);
            $item_name = trim($m[2]);
            $photo_id  = trim($m[3]);

            if (preg_match('/(\d+x\d+)/', $item_name, $size)) {
                $prod_code = $size[1];     // e.g. 5x7, 8x10
            } elseif (stripos($item_name, 'email') !== false) {
                $prod_code = 'EML';        // Digital Email
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

// Update CASH ORDER line DUE -> PAID/VOID
function acp_update_cash_status(string $receipt, string $newStatus): array {
    $count = 0;
    $updated = preg_replace(
        '/^(CASH ORDER:\s*\$[0-9]+(?:\.[0-9]{2})?)\s+DUE\s*$/mi',
        '$1 ' . strtoupper($newStatus),
        $receipt,
        1,       // only first match
        $count
    );

    if ($updated === null) {
        return [$receipt, 0];
    }
    return [$updated, $count];
}

// Send Digital Email via /mailer.php?order=...
function acp_send_digital_email($orderID): array {
    // Always hit /mailer.php at the web root, NOT /admin/mailer.php
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $url    = $scheme . '://' . $host . '/mailer.php?order=' . urlencode($orderID);

    $body = '';
    $ok   = false;

    // Use http_build_query for POST body for consistency (empty array if no data)
    $payload = http_build_query([]);

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true); // Still use POST as mailer.php expects it
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Accept: text/plain,*/*']);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        // --- PATCH: Force cURL to use the correct CA cert for SSL ---
        $cacertPath = realpath(__DIR__ . '/../cacert.pem');
        if ($cacertPath && file_exists($cacertPath)) {
            curl_setopt($ch, CURLOPT_CAINFO, $cacertPath);
        }
        $body   = curl_exec($ch);
        $errNo  = curl_errno($ch);
        $errStr = curl_error($ch);
        curl_close($ch);

        if ($errNo === 0 && $body !== false) {
            $text = preg_replace('/<[^>]*>/', '', $body);
            $ok   = (bool)preg_match('/Message has been sent/i', $text);
        } else {
            $body = 'cURL error: ' . $errStr;
        }
    } else {
        // Fallback: stream_context_create
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

// Copy print items (non-EML) into C:\orders
// Copy print items (non-EML) into C:\orders or R:\orders based on receipt tag
function acp_print_order_items($orderID, $baseDir, $date_path, array $items, string $receiptData): array {
    $defaultOutputDir = getenv('PRINT_OUTPUT_DIR') ?: "../orders";
    $fsOutputDir      = getenv('PRINT_OUTPUT_DIR_FS') ?: "../orders_fs";

    // Check for specific string "- FS" to determine output directory
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

        // Skip pure email items
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

    // Collect EML items
    $emailItems = [];
    foreach ($items as $it) {
        if ($it['prod_code'] === 'EML') {
            $emailItems[] = $it;
        }
    }
    if (empty($emailItems)) {
        return $result; // no digital email items
    }

    $result['has_email_items'] = true;

    // Extract email address from receipt lines
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

    // For reference only, we keep it in result (but info.txt will be *full receipt*)
    $result['email']   = $user_email;
    $result['message'] = 'Full receipt in info.txt';

    // Ensure emails/{email} directory exists
    $emailsUserDir = $baseDir . '/' . $date_path . '/emails/' . $user_email;
    if (!is_dir($emailsUserDir) && !@mkdir($emailsUserDir, 0777, true)) {
        $result['error'] = 'Unable to create email image directory.';
        return $result;
    }

    // Copy each distinct photo_id for EML items into emails/{email}
    $seenPhoto = [];
    foreach ($emailItems as $it) {
        $photo_id = $it['photo_id'];

        if (isset($seenPhoto[$photo_id])) {
            continue; // avoid duplicate files
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

    // Create cash_email/{order}/info.txt containing the *full receipt*
    // The receipt itself already has: "email@domain.com | ..." on the first line,
    // so mailer.php's explode('|', ...) will still see:
    //   [0] => email, [1] => full receipt body
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

// ---------------------------------------------------------------------
// ACTION: EMAIL ONLY
// ---------------------------------------------------------------------
if ($action === 'email') {
    // For email-only action, we assume staging has already been done (from "paid").
    $emailResult = acp_send_digital_email($orderID);

    if ($emailResult['success']) {
        acp_log_event($orderID, "EMAIL_OK"); // Log success
        echo json_encode([
            'status'          => 'success',
            'message'         => "Email sent for Order #$orderID.",
            'email_attempted' => true,
            'email_success'   => true,
        ]);
    } else {
        acp_log_event($orderID, "EMAIL_ERROR: {$emailResult['raw']}"); // Log error
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

// ---------------------------------------------------------------------
// ACTION: PAID or VOID
// ---------------------------------------------------------------------
$isCashDue = preg_match('/^CASH ORDER:\s*\$[0-9]+(?:\.[0-9]{2})?\s+DUE\s*$/mi', $receiptData);
// (If not cash due, we still proceed, but that's a soft guard.)

$items          = acp_parse_receipt_items($lines);
$copiedFiles    = [];
$emailAttempted = false;
$emailSuccess   = false;
$emailRaw       = null;
$emailStageInfo = null;

if ($action === 'paid') {
    // 1) PRINT physical items to C:\orders

    // --- Check the master auto-print status before printing ---
    $shouldAutoPrint = acp_get_autoprint_status(); // Use the new function

    // 1) PRINT physical items to C:\orders ONLY IF Auto Print is ON
    if ($shouldAutoPrint) {
        // UPDATED: Passed $receiptData as the 5th argument
        $copiedFiles = acp_print_order_items($orderID, $baseDir, $date_path, $items, $receiptData);

        if (!empty($copiedFiles)) {
            acp_log_event($orderID, "PRINT_OK (x".count($copiedFiles).")");
        }
    } else {
        // Log that printing was skipped due to the toggle state
        acp_log_event($orderID, "PRINT_SKIP (Auto Print OFF)");
    }

    // 2) Stage digital email items, if any
    $emailStageInfo = acp_stage_email_items(
        $orderID,
        $baseDir,
        $date_path,
        $items,
        $lines,
        $receiptData // full text from the .txt receipt file
    );

    if ($emailStageInfo['has_email_items']) {
        $emailAttempted = true;

        if ($emailStageInfo['staged']) {
            // 3) Now that files + info.txt are staged, call /mailer.php?order=####
            $sendResult  = acp_send_digital_email($orderID);
            $emailSuccess = $sendResult['success'];
            $emailRaw     = $sendResult['raw'];

            if ($emailSuccess) {
                // Log success *after* successful send
                // acp_log_event($orderID, "EMAIL_OK"); // Replaced by log in mailer.php if implemented there
            } else {
                acp_log_event($orderID, "EMAIL_ERROR: Staging OK, Send Failed | {$emailRaw}");
            }
        } else {
            $emailSuccess = false;
            $emailRaw     = 'Staging failed: ' . $emailStageInfo['error'];
            acp_log_event($orderID, "STAGE_ERROR: {$emailStageInfo['error']}");
        }
    }

    // 4) Mark CASH ORDER as PAID in receipt
    list($updatedReceipt, $changed) = acp_update_cash_status($receiptData, 'PAID');
    if ($changed > 0) {
        file_put_contents($receiptPath, $updatedReceipt);
        $receiptData = $updatedReceipt;
        acp_log_event($orderID, "PAID"); // Log final status change
    }
    $statusMsg = "Order #$orderID marked PAID.";
} else {
    // VOID: just flip status
    list($updatedReceipt, $changed) = acp_update_cash_status($receiptData, 'VOID');
    if ($changed > 0) {
        file_put_contents($receiptPath, $updatedReceipt);
        $receiptData = $updatedReceipt;
        acp_log_event($orderID, "VOID"); // Log final status change
    }
    $statusMsg = "Order #$orderID voided.";
}


// Final JSON response
echo json_encode([
    'status'          => 'success',
    'message'         => $statusMsg,
    'action'          => $action,
    'files'           => $copiedFiles,
    'email_attempted' => $emailAttempted,
    'email_success'   => $emailSuccess,
    'email_raw'       => $emailRaw,
    'receipt'         => nl2br(htmlspecialchars($receiptData, ENT_QUOTES, 'UTF-8')),
    'email_stage'     => $emailStageInfo,
    'location'        => $location,
]);
