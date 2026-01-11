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

ignore_user_abort(true);
ini_set('memory_limit', '-1');
set_time_limit(0);

include('shopping_cart.class.php');
$Cart = new Shopping_Cart('shopping_cart');

// --- GET REQUEST VARS ---
$txtName   = $_GET['txtName']   ?? '';
$txtAddr   = $_GET['txtAddr']   ?? '';
$txtCity   = $_GET['txtCity']   ?? '';
$txtState  = $_GET['txtState']  ?? '';
$txtZip    = $_GET['txtZip']    ?? '';
$txtAmt    = floatval($_GET['txtAmt'] ?? 0);
$txtEmail  = trim($_GET['txtEmail'] ?? '');
$isOnsite  = $_GET['isOnsite']  ?? 'no';

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
$paymentType = $_REQUEST['payment_type'] ?? (isset($_POST['is_square_payment']) && $_POST['is_square_payment'] == '1' ? 'square' : 'cash');
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

// --- CLEAR CART ---
$Cart->clearCart();
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
  <span style="font-size: 24px; color:#c81c1c; font-weight:bold;">Payment needed</span><br/><br/>
  <span style="font-size: 20px; color:#6F0;">Please go to the sales counter now to pay for and pick up your order.</span>
  <br /><br /> <span style="font-size: 20px;">Your Order Number Is:<br /><br /> 
  <span style="font-size: 250px; color:#FF0;"><?php echo $orderID; ?></span><br /><br />
  <div style="text-align:center;margin-top:1.2rem;">
        <a href="/"><button type="button" class="btn">Return to Alley Cat Photo</button></a>
      </div>
</div>
</body>
</html>
