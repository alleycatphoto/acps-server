<?php
//*********************************************************************//
//       _____  .__  .__                 _________         __          //
//      /  _  \ |  | |  |   ____ ___.__. \_   ___ \_____ _/  |_        //
//     /  /_\  \|  | |  | _/ __ <   |  | /    \  \/\__  \\   __\       //
//    /    |    \  |_|  |_\  ___/\___  | \     \____/ __ \|  |         //
//    \____|__  /____/____/\___  > ____|  \______  (____  /__|         //
//            \/               \/\/              \/     \/             //
// *********************** INFORMATION ********************************//
// AlleyCat PhotoStation v3.3.0                                        //
// Author: Paul K. Smith (photos@alleycatphoto.net)                    //
// Date: 10/14/2025                                                    //
// Updated for pricing logic + stability                               //
//*********************************************************************//

require_once "admin/config.php";
if (session_status() === PHP_SESSION_NONE) session_start();

// --- Helper: Get Auto Print Status ---
function acp_get_autoprint_status(): bool {
    $statusFilePath = realpath(__DIR__ . "/config/autoprint_status.txt");
    if ($statusFilePath && file_exists($statusFilePath)) {
        $content = @file_get_contents($statusFilePath);
        return trim($content) === '1'; 
    }
    return true; 
}

ignore_user_abort(true);
ini_set('memory_limit', '-1');
set_time_limit(0);

include('shopping_cart.class.php');
$Cart = new Shopping_Cart('shopping_cart');

// --- GET REQUEST VARS ---
$txtName   = $_REQUEST['txtName']   ?? '';
$txtAddr   = $_REQUEST['txtAddr']   ?? '';
$txtCity   = $_REQUEST['txtCity']   ?? '';
$txtState  = $_REQUEST['txtState']  ?? '';
$txtZip    = $_REQUEST['txtZip']    ?? '';
$txtAmt    = floatval($_REQUEST['txtAmt'] ?? 0);
$txtEmail  = trim($_REQUEST['txtEmail'] ?? '');
$isOnsite  = $_REQUEST['isOnsite']  ?? 'no';

$dirname   = "photos/";
$date_path = date('Y/m/d');
$server_addy = $_SERVER['HTTP_HOST'] ?? '';
// --- ORDER ID ---
$filename = $dirname . $date_path . "/orders.txt";
if (!file_exists($filename)) {
    mkdir(dirname($filename), 0777, true);
    file_put_contents($filename, "1000");
    $orderID = 1000;
} else {
    $orderID = (int) trim(file_get_contents($filename));
    $orderID++;
    file_put_contents($filename, $orderID);
}

// --- MESSAGE BUILD ---
$stationID = ($server_addy == '192.168.2.126') ? "FS" : "MS";
$message = "";
$total_price = 0;
$to = $locationEmail;
$subject = "Alley Cat Photo : " . $locationName . " " . $stationID . " New Order - (Cash Due): " . $orderID;

// Detect payment type and Square response/confirmation
$paymentType = $_REQUEST['payment_type'] ?? (($_POST['is_square_payment'] ?? '0') == '1' || ($_POST['is_qr_payment'] ?? '0') == '1' ? 'square' : 'cash');

// Apply tax only if QR code callback (Square) happened
if ($paymentType === 'square') {
    $txtAmt = $txtAmt * 1.0675;
}

$squareResponse = $_REQUEST['square_response'] ?? '';
$squareOrderId = $_REQUEST['square_order_id'] ?? '';
$message .= "$txtEmail | \r\n";
if ($paymentType === 'square') {
    $message .= "SQUARE ORDER: $" . number_format($txtAmt, 2) . " PAID\r\n";
} else {
    $message .= "CASH ORDER: $" . number_format($txtAmt, 2) . " DUE\r\n";
}
if ($isOnsite == 'yes') {
    $message .= "Delivery: Pickup On Site\r\n";
} else {
    $message .= "Delivery: Postal Mail\r\n";
    $message .= "CUSTOMER ADDRESS:\r\n";
    $message .= "-----------------------------\r\n";
    $message .= "$txtName\r\n$txtAddr\r\n$txtCity, $txtState $txtZip\r\n\r\n";
}
$message .= "Order #: $orderID - $stationID\r\n";
$message .= "Order Date: " . date("F j, Y, g:i a") . "\r\n";
$message .= "Order Total: $" . number_format($txtAmt, 2) . "\r\n\r\n";
$message .= "ITEMS ORDERED:\r\n-----------------------------\r\n";

// --- FALLBACK for getImageID() ---
if (!method_exists($Cart, 'getImageID')) {
    function getImageID_Fallback($order_code) {
        $p = explode('-', $order_code);
        return trim($p[1] ?? '');
    }
}

// --- CART ITEMS LOOP ---
foreach ($Cart->getItems() as $order_code => $quantity) {
    $price = $Cart->getItemPrice($order_code);
    $total_price += ($quantity * $price);
    $imgID = method_exists($Cart, 'getImageID')
        ? $Cart->getImageID($order_code)
        : getImageID_Fallback($order_code);
    $message .= "[$quantity] " . $Cart->getItemName($order_code) . " ($imgID)\r\n";
}


$message .= "-----------------------------\r\nCheck out your pictures later at:\r\nhttp://www.alleycatphoto.net\r\n";
// If Square, append confirmation/response at bottom
if ($paymentType === 'square') {
    if ($squareOrderId) {
        $message .= "\r\nSQUARE CONFIRMATION: $squareOrderId\r\n";
    }
    if ($squareResponse) {
        $message .= "SQUARE RESPONSE: $squareResponse\r\n";
    }
}
$message .= "\r\n";

// --- SEND STAFF MAIL ---
$header = "From: Alley Cat Photo <" . $locationEmail . ">\r\n";
@mail($to, $subject, $message, $header);

// --- WRITE RECEIPT FILES ---
$receiptDir = "photos/" . $date_path . "/receipts";
mkdir($receiptDir, 0777, true);
file_put_contents("$receiptDir/$orderID.txt", $message);

// --- FIRE MIRRORING ---
$server_addy = $_SERVER['HTTP_HOST'] ?? '';
$firePath = ($server_addy == '192.168.2.126')
    ? "photos/receipts/fire"
    : "photos/receipts";
mkdir($firePath, 0777, true);
file_put_contents("$firePath/$orderID.txt", $message);

// --- COPY EMAIL PHOTOS (if provided) ---
if ($txtEmail != '') {
    $toPath  = "photos/" . $date_path . "/cash_email/" . $orderID;
    $filePath = "photos/" . $date_path . "/emails/" . $txtEmail;
    mkdir($toPath, 0777, true);
    mkdir($filePath, 0777, true);

    // info.txt must always be email|message (first line is email|message)
    $infoTxt = "$txtEmail|" . ($paymentType === 'square' ? "SQUARE ORDER: $" . number_format($txtAmt, 2) . " PAID" : "CASH ORDER: $" . number_format($txtAmt, 2) . " DUE");
    if ($paymentType === 'square') {
        if ($squareOrderId) {
            $infoTxt .= "|SQUARE CONFIRMATION: $squareOrderId";
        }
        if ($squareResponse) {
            $infoTxt .= "|SQUARE RESPONSE: $squareResponse";
        }
    }
    $infoTxt .= "\r\n" . $message;
    file_put_contents("$toPath/info.txt", $infoTxt);

    foreach ($Cart->items as $order_code => $quantity) {
        [$prod_code, $photo_id] = explode('-', $order_code);
        if (trim($prod_code) == 'EML' && $quantity > 0) {
            $sourcefile = "photos/$date_path/raw/$photo_id.jpg";
            $destfile   = "$filePath/$photo_id.jpg";
            @copy($sourcefile, $destfile);
        }
    }
}

// --- HANDLE AUTO PRINT FOR SQUARE PAYMENTS ---
if ($paymentType === 'square') {
    $shouldAutoPrint = acp_get_autoprint_status();
    if ($shouldAutoPrint) {
        $orderOutputDir = ($server_addy == '192.168.2.126') ? "R:/orders" : "C:/orders";
        if (!is_dir($orderOutputDir)) @mkdir($orderOutputDir, 0777, true);

        foreach ($Cart->items as $order_code => $quantity) {
            [$prod_code, $photo_id] = explode('-', $order_code);
            if (trim($prod_code) != 'EML' && $quantity > 0) {
                $sourcefile = "photos/$date_path/raw/$photo_id.jpg";
                if (file_exists($sourcefile)) {
                    $imgInfo = @getimagesize($sourcefile);
                    $orientation = ($imgInfo && $imgInfo[0] > $imgInfo[1]) ? 'H' : 'V';

                    for ($i = 1; $i <= $quantity; $i++) {
                        $destfile = sprintf("%s/%s-%s-%s%s-%d.jpg", $orderOutputDir, $orderID, $photo_id, $prod_code, $orientation, $i);
                        @copy($sourcefile, $destfile);
                    }
                }
            }
        }
    }
    // Trigger mailer for digital items immediately if paid
    if ($txtEmail != '') {
        exec('start /B php mailer.php');
    }
}

// --- CLEAR CART ---
$Cart->clearCart();

// --- LOG TRANSACTION TO DAILY TOTALS CSV ---
$csvFile = __DIR__ . '/sales/transactions.csv';
$today = date("m/d/Y");
// User wants Location Slug without spaces/quotes in CSV
$rawLocation = getenv('LOCATION_SLUG') ?: $locationName;
$location = str_replace(' ', '', $rawLocation); 

$paymentTypeDisplay = ($paymentType === 'square') ? 'Credit' : 'Cash';
// For CSV, if Credit/Square, use the full taxed amount. If Cash, use untaxed.
// $txtAmt is already adjusted above: if Square, it has tax added. If Cash, it is base.
$logAmount = $txtAmt; 

// Read existing data
$data = [];
if (file_exists($csvFile)) {
    $handle = @fopen($csvFile, 'r');
    if ($handle !== false) {
        $header = fgetcsv($handle);
        while (($row = fgetcsv($handle)) !== false) {
            // Key is Location | Date | Payment Type
            $key = $row[0] . '|' . $row[1] . '|' . ($row[3] ?? ''); 
            // Sanitize amount (remove $ and ,)
            if (isset($row[4])) {
                $row[4] = (float)str_replace(['$', ','], '', $row[4]);
            }
            $data[$key] = $row;
        }
        fclose($handle);
    }
}

// Update or create entry for today
$key = $location . '|' . $today . '|' . $paymentTypeDisplay;
if (!isset($data[$key])) {
    $data[$key] = [$location, $today, 0, $paymentTypeDisplay, 0];
}
$data[$key][2] += 1; // Orders
$data[$key][4] += $logAmount; // Amount

// Write back to CSV
if (!is_dir(dirname($csvFile))) {
    @mkdir(dirname($csvFile), 0777, true);
}
$fp = @fopen($csvFile, 'w');
if ($fp !== false) {
    fputcsv($fp, ['Location', 'Order Date', 'Orders', 'Payment Type', 'Amount']);
    foreach ($data as $row) {
        // Format amount with $
        $row[4] = '$' . number_format($row[4], 2);
        fputcsv($fp, $row);
    }
    fclose($fp);
} else {
    error_log("Failed to open CSV for writing: " . $csvFile);
}

// Determine UI Display
$isApproved = ($paymentType === 'square');
?>
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml">
<head>
<meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
<title>Alley Cat Photo : Processing transaction...</title>
<link rel="stylesheet" href="/public/assets/css/acps.css">
<script src="/public/assets/js/jquery-1.11.1.min.js"></script>
<script>
document.oncontextmenu=()=>false;	
document.onmousedown=e=>false;
$(document).ready(function(){ setTimeout(()=>{location.href="/";},60000); });
</script>
</head>
<body link="#cc0000ff" vlink="#ff0000ff" alink="#990000ff">
<div align="center">
  <p><img src="/public/assets/images/alley_logo_sm.png" alt="Alley Cat Photo" width="223" height="auto"/></p>
  
  <?php if ($isApproved): ?>
    <h1 style="color:#6F0; font-size: 3rem; margin-bottom: 1rem;">APPROVED</h1>
    <span style="font-size: 24px; color:#fff; font-weight:bold;">Thank you for your order!</span><br/><br/>
    <?php if ($isOnsite == 'yes'): ?>
        <span style="font-size: 20px; color:#6F0;">Your prints will be ready at the sales counter in just a few minutes.</span>
    <?php else: ?>
        <span style="font-size: 20px; color:#ccc;">Your order will be processed and mailed shortly.</span>
    <?php endif; ?>
  <?php else: ?>
    <span style="font-size: 24px; color:#c81c1c; font-weight:bold;">Payment needed</span><br/><br/>
    <span style="font-size: 20px; color:#6F0;">Please go to the sales counter now to pay for and pick up your order.</span>
  <?php endif; ?>

  <br /><br /> <span style="font-size: 20px;">Your Order Number Is:<br /><br /> 
  <span style="font-size: 250px; color:#FF0;"><?php echo $orderID; ?></span><br /><br />
  
  <div style="text-align:center;margin-top:1.2rem;">
        <a href="/"><button type="button" class="btn">Return to Alley Cat Photo</button></a>
      </div>
</div>
</body>
</html>
