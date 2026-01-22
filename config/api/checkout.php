<?php
/**
 * ACPS 9.0 Central Checkout API
 * Replaces cart_process_send.php and cart_process_cash.php
 * Handles Order Creation, Receipt Generation, Payment Processing (ePN), and Spooling.
 */

header('Content-Type: application/json');
require_once __DIR__ . '/../../admin/config.php';
include(__DIR__ . '/../../shopping_cart.class.php');

// Initialize
if (session_status() === PHP_SESSION_NONE) session_start();
$Cart = new Shopping_Cart('shopping_cart');

// Helper: Auto Print Status
function acp_get_autoprint_status() {
    $f = realpath(__DIR__ . "/../../config/autoprint_status.txt");
    return ($f && file_exists($f) && trim(file_get_contents($f)) === '0') ? false : true;
}

// Helper: Get Image ID
function getImageID_Fallback($order_code) {
    $p = explode('-', $order_code);
    return trim($p[1] ?? '');
}

// --- INPUTS ---
$paymentMethod = $_POST['payment_method'] ?? 'cash'; // cash, square, qr, credit (swipe)
$txtEmail      = trim($_POST['email'] ?? '');
$isOnsite      = $_POST['delivery_method'] === 'pickup' ? 'yes' : 'no';
$amount        = floatval($_POST['amount'] ?? 0);
$customerInfo  = [
    'name'  => $_POST['name'] ?? '',
    'addr'  => $_POST['address'] ?? '',
    'city'  => $_POST['city'] ?? '',
    'state' => $_POST['state'] ?? '',
    'zip'   => $_POST['zip'] ?? ''
];

// --- 1. GENERATE ORDER ID ---
$date_path = date('Y/m/d');
$dirname   = __DIR__ . "/../../photos/";
$orderFile = $dirname . $date_path . "/orders.txt";

if (!file_exists(dirname($orderFile))) mkdir(dirname($orderFile), 0777, true);

$fp = fopen($orderFile . ".lock", "c+");
if (flock($fp, LOCK_EX)) {
    if (!file_exists($orderFile)) file_put_contents($orderFile, "1000");
    $orderID = (int)trim(file_get_contents($orderFile));
    $orderID++;
    file_put_contents($orderFile, $orderID);
    flock($fp, LOCK_UN);
} else {
    die(json_encode(['status'=>'error', 'message'=>'Could not lock order ID']));
}
fclose($fp);

// --- 2. PAYMENT PROCESSING (ePN) ---
$transaction_result = 'approved'; // Default for Cash/QR/Square(Pre-Verified)
$transaction_msg = 'Approved';
$auth_code = '';

if ($paymentMethod === 'credit') {
    // eProcessingNetwork Logic
    $epn_account = $_ENV['EPN_ACCOUNT'] ?? getenv('EPN_ACCOUNT') ?: '';
    $epn_key     = $_ENV['EPN_RESTRICT_KEY'] ?? getenv('EPN_RESTRICT_KEY') ?: '';
    
    // Swipe Data
    $swipeData = $_POST['swipe_data'] ?? '';
    $cardNum   = $_POST['card_num'] ?? '';
    $expMonth  = $_POST['exp_month'] ?? '';
    $expYear   = $_POST['exp_year'] ?? '';
    
    // Construct ePN Request
    $post_data = [
        'ePNAccount'  => $epn_account,
        'RestrictKey' => $epn_key,
        'CardNo'      => $cardNum,
        'ExpMonth'    => $expMonth,
        'ExpYear'     => $expYear,
        'Total'       => number_format($amount, 2, '.', ''),
        'Address'     => $customerInfo['addr'],
        'Zip'         => $customerInfo['zip'],
        'HTML'        => 'No',
        'Description' => "Order #$orderID",
        'EMail'       => $txtEmail
    ];
    
    // Execute Curl
    $ch = curl_init('https://www.eprocessingnetwork.com/cgi-bin/epn/secure/tdbe/transact.pl');
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post_data));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($ch);
    curl_close($ch);

    // Parse Response (Format: "Y,123456,Approved" or "N,Error")
    $parts = explode(',', $response);
    if (strtoupper(substr($parts[0], 1, 1)) === 'Y') { // Some responses are "Y" or "AY" or "A"
         $transaction_result = 'approved';
         $auth_code = $parts[1] ?? '';
    } else {
         $transaction_result = 'declined';
         $transaction_msg = $parts[1] ?? 'Declined';
    }
}

if ($transaction_result !== 'approved') {
    echo json_encode(['status'=>'error', 'message'=>$transaction_msg]);
    exit;
}

// --- 3. BUILD RECEIPT CONTENT ---
$server_addy = $_SERVER['HTTP_HOST'] ?? '';
$stationID   = ($server_addy == '192.168.2.126') ? "FS" : "MS";
$message     = "";
$total_price = 0;

// Header Logic
if ($paymentMethod === 'square' || $paymentMethod === 'qr' || $paymentMethod === 'credit') {
    $message .= "$txtEmail |\r\n";
    $message .= "SQUARE ORDER: $" . number_format($amount, 2) . " PAID\r\n"; // Using "SQUARE ORDER" as legacy trigger for PAID in parser
} else {
    $message .= "$txtEmail |\r\n";
    $message .= "CASH ORDER: $" . number_format($amount, 2) . " DUE\r\n";
}

$message .= "Order #: $orderID - $stationID\r\n";
$message .= "Order Date: " . date("F j, Y, g:i a") . "\r\n";
$message .= "Order Total: $" . number_format($amount, 2) . "\r\n";

if ($isOnsite == 'yes') {
    $message .= "Delivery: Pickup On Site\r\n";
} else {
    $message .= "Delivery: Postal Mail\r\nCUSTOMER ADDRESS:\r\n-----------------------------\r\n";
    $message .= "{$customerInfo['name']}\r\n{$customerInfo['addr']}\r\n{$customerInfo['city']}, {$customerInfo['state']} {$customerInfo['zip']}\r\n\r\n";
}

$message .= "ITEMS ORDERED:\r\n-----------------------------\r\n";

// Cart Items
$items = $Cart->getItems();
foreach ($items as $order_code => $quantity) {
    $price = $Cart->getItemPrice($order_code);
    $total_price += ($quantity * $price);
    $imgID = method_exists($Cart, 'getImageID') ? $Cart->getImageID($order_code) : getImageID_Fallback($order_code);
    $message .= "[$quantity] " . $Cart->getItemName($order_code) . " ($imgID)\r\n";
}

$message .= "-----------------------------\r\nVisit us online:\r\nhttp://www.alleycatphoto.net\r\n";

// --- 4. SAVE RECEIPT ---
$receiptPath = $dirname . $date_path . "/receipts";
if (!is_dir($receiptPath)) mkdir($receiptPath, 0777, true);
file_put_contents("$receiptPath/$orderID.txt", $message);

$firePath = ($server_addy == '192.168.2.126') ? $dirname . "receipts/fire" : $dirname . "receipts";
if (!is_dir($firePath)) mkdir($firePath, 0777, true);
file_put_contents("$firePath/$orderID.txt", $message);


// --- 5. PROCESSING (SPOOLING) ---
$isPaid = ($paymentMethod !== 'cash'); // Square, QR, Credit = Paid. Cash = Pending.
if (strtolower($txtEmail) === 'photos@alleycatphoto.net') $isPaid = true; // Test Bypass

if ($isPaid) {
    // A. SPOOL EMAIL
    if ($txtEmail != '') {
        $toPath = $dirname . $date_path . "/pending_email";
        $filePath = $dirname . $date_path . "/emails/" . $txtEmail;
        if (!is_dir($toPath)) mkdir($toPath, 0777, true);
        if (!is_dir($filePath)) mkdir($filePath, 0777, true);

        // Meta info
        $infoTxt = "$txtEmail|PAID\r\n" . $message;
        file_put_contents("$toPath/info.txt", $infoTxt);

        foreach ($items as $order_code => $quantity) {
            [$prod_code, $photo_id] = explode('-', $order_code);
            if (trim($prod_code) == 'EML' && $quantity > 0) {
                $src = $dirname . $date_path . "/raw/$photo_id.jpg";
                $dst = "$filePath/$photo_id.jpg";
                @copy($src, $dst);
            }
        }
        
        // Trigger Mailer
        pclose(popen("start /B php " . __DIR__ . "/../../mailer.php", "r"));
    }

    // B. SPOOL PRINTS
    if (acp_get_autoprint_status()) {
        $orderOutputDir = ($server_addy == '192.168.2.126') ? "R:/orders" : "C:/orders";
        if (!is_dir($orderOutputDir)) @mkdir($orderOutputDir, 0777, true);

        foreach ($items as $order_code => $quantity) {
            [$prod_code, $photo_id] = explode('-', $order_code);
            if (trim($prod_code) != 'EML' && $quantity > 0) {
                $src = $dirname . $date_path . "/raw/$photo_id.jpg";
                if (file_exists($src)) {
                    $imgInfo = @getimagesize($src);
                    $orient = ($imgInfo && $imgInfo[0] > $imgInfo[1]) ? 'H' : 'V';
                    
                    for ($i = 1; $i <= $quantity; $i++) {
                        $destName = sprintf("%s-%s-%s%s-%d.jpg", $orderID, $photo_id, $prod_code, $orient, $i);
                        @copy($src, "$orderOutputDir/$destName");
                    }
                }
            }
        }
    }
}

// Clear Cart
$Cart->clearCart();

// Response
echo json_encode([
    'status' => 'success',
    'order_id' => $orderID,
    'payment_method' => $paymentMethod,
    'is_paid' => $isPaid,
    'message' => $isPaid ? 'Order Processed' : 'Please Pay Cash at Counter'
]);
