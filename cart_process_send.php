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
// Last Revision: Updated for pricing + stability                      //
//*********************************************************************//
//error_reporting(E_ALL);
//ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/cart_process_error.log');

require_once "admin/config.php";
if (session_status() === PHP_SESSION_NONE) session_start();

include('shopping_cart.class.php');
$Cart = new Shopping_Cart('shopping_cart');

// Debug log
//@file_put_contents('logs/cart_post_debug.log', print_r($_POST, true), FILE_APPEND);
// Validate token early so we fail with a clear message instead of a low-level auth validation exception
if (!empty($token)) {
    $logDir = __DIR__ . '/logs';
    if (!is_dir($logDir)) { @mkdir($logDir, 0777, true); }
    $logFile = $logDir . '/cart_post.log';
    $logMessage = "[" . date('Y-m-d H:i:s') . "] CART POST .\n" . print_r($_POST, true);
    file_put_contents($logFile, $logMessage, FILE_APPEND);
    return false;
}
ignore_user_abort(true);
ini_set('memory_limit', '-1');
set_time_limit(0);

$dirname = "photos/";
$date_path = date('Y/m/d');
// ---------------------------------------------------------------------
// Helper: Get Auto Print Status from config file
// ---------------------------------------------------------------------
function acp_get_autoprint_status(): bool {
    // Define the file path relative to this script
    $statusFilePath = realpath(__DIR__ . "/admin/../config/autoprint_status.txt");
    
    if ($statusFilePath && file_exists($statusFilePath)) {
        $content = @file_get_contents($statusFilePath);
        // Returns true (ON) only if file content is strictly '1'
        return trim($content) === '1'; 
    }
    // Default to true (ON) if file doesn't exist or cannot be read.
    return true; 
}
// ---------------------------------------------------------------------
// --- Extract POST vars safely ---
$txtSwipeData = $_POST['txtSwipeData'] ?? '';
$txtFname     = $_POST['txtFname'] ?? '';
$txtLname     = $_POST['txtLname'] ?? '';
$txtCardNum   = $_POST['txtCardNum'] ?? '';
$txtExpMonth  = $_POST['txtExpMonth'] ?? '';
$txtExpYear   = $_POST['txtExpYear'] ?? '';
$txtName      = $_POST['txtName'] ?? '';
$txtAddr      = $_POST['txtAddr'] ?? '';
$txtCity      = $_POST['txtCity'] ?? '';
$txtState     = $_POST['txtState'] ?? '';
$txtZip       = $_POST['txtZip'] ?? '';
$txtAmt       = floatval($_POST['txtAmt'] ?? 0);
$txtEmail     = trim($_POST['txtEmail'] ?? '');
$isOnsite     = $_POST['isOnsite'] ?? 'yes';
$isQrPayment  = isset($_POST['is_qr_payment']) && $_POST['is_qr_payment'] === '1';

// --- ORDER ID GENERATION / ACQUISITION ---

// For all other payment types, generate a new ID.
$filename = $dirname.$date_path."/orders.txt";

if (!file_exists($filename)) {
    mkdir(dirname($filename), 0777, true);
    file_put_contents($filename, "1000");
    $orderID = 1000;
} else {
    $orderID = (int) trim(file_get_contents($filename));
    $orderID++;
}

// --- PAYMENT POST / BYPASS LOGIC ---
$amount = number_format($txtAmt, 2, '.', '');

if (strtolower($txtEmail) === 'photos@alleycatphoto.net' || $isQrPayment) {
    // --- BYPASS eProcessing for internal test email or QR Payment ---
    $responseMode = "approved";
    $responseMsg  = $isQrPayment ? "Paid via QR code." : "Bypass active for internal test (photos@alleycatphoto.net).";
    $resultStatus = 200;
    $approval     = 'Y';
} else {
    // --- NORMAL eProcessingNetwork call ---
    $remote_url = 'https://www.eprocessingnetwork.com/cgi-bin/epn/secure/tdbe/transact.pl';
    $post_data = [
        'ePNAccount' => $_ENV['EPN_ACCOUNT'] ?? getenv('EPN_ACCOUNT') ?: '',
        'CardNo' => $txtCardNum,
        'ExpMonth' => $txtExpMonth,
        'ExpYear' => $txtExpYear,
        'Total' => $amount,
        'Address' => $txtAddr,
        'City' => $txtCity,
        'State' => $txtState,
        'Zip' => $txtZip,
        'HTML' => 'No',
        'RestrictKey' => $_ENV['EPN_RESTRICT_KEY'] ?? getenv('EPN_RESTRICT_KEY') ?: '',
        'Description' => $locationName . " ($orderID)",
        'Company' => $locationName,
        'FirstName' => $txtFname,
        'LastName' => $txtLname,
        'EMail' => $txtEmail,
        'SKIP_MISSING' => 1
    ];

    // --- CURL HANDLER ---
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $remote_url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post_data));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($ch);
    $resultStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    // --- RESPONSE PARSE ---
    $response_array = explode(',', $response ?? '');
    $approval = substr($response_array[0] ?? '', 1, 1);
    $responseMode = ($resultStatus == 200 && $approval === 'Y') ? "approved" : "declined";
    $responseMsg = $responseMod == 'approved'
        ? "Your transaction has been approved."
        : "Your transaction was declined or could not be processed.";
}


// --- IF APPROVED ---
if ($responseMode == "approved") {

    // --- Ensure Order ID File updated ---
    file_put_contents($filename, $orderID);

    // --- MESSAGE BUILD ---
    $server_addy = $_SERVER['HTTP_HOST'] ?? '';
    $stationID = ($server_addy == '192.168.2.126') ? "FS" : "MS";
    $message = "";
    $total_price = 0;
    $to = $locationEmail;
    $subject = "Alley Cat Photo : " . $locationName . " " . $stationID . " New Order - (" . ($isOnsite == 'yes' ? "Pickup" : "Postal Mail") . "): " . $orderID;

    // SQUARE receipt logic
    if ($isQrPayment) {
        $message .= "$txtEmail |\r\n";
        $message .= "SQUARE ORDER: $" . number_format($txtAmt, 2) . " PAID\r\n";
    } else {
        $message .= "$txtEmail |\r\n";
        $message .= "CASH ORDER: $" . number_format($txtAmt, 2) . " DUE\r\n";
    }
    $message .= "Order #: $orderID - $stationID\r\n";
    $message .= "Order Date: " . date("F j, Y, g:i a") . "\r\n";
    $message .= "Order Total: $" . number_format($txtAmt, 2) . "\r\n";
    if ($isOnsite == 'yes') {
        $message .= "Delivery: Pickup On Site\r\n";
    } else {
        $message .= "Delivery: Postal Mail\r\nCUSTOMER ADDRESS:\r\n-----------------------------\r\n";
        $message .= "$txtName\r\n$txtAddr\r\n$txtCity, $txtState $txtZip\r\n\r\n";
    }
    $message .= "ITEMS ORDERED:\r\n-----------------------------\r\n";

    // --- Safe getImageID fallback ---
    if (!method_exists($Cart, 'getImageID')) {
        function getImageID_Fallback($order_code) {
            $p = explode('-', $order_code);
            return trim($p[1] ?? '');
        }
    }

    foreach ($Cart->getItems() as $order_code => $quantity) {
        $price = $Cart->getItemPrice($order_code);
        $total_price += ($quantity * $price);
        $imgID = method_exists($Cart, 'getImageID') ? $Cart->getImageID($order_code) : getImageID_Fallback($order_code);
        $message .= "[$quantity] " . $Cart->getItemName($order_code) . " ($imgID)\r\n";
    }

    $message .= "-----------------------------\r\nVisit us online:\r\nhttp://www.alleycatphoto.net\r\n";

    // --- Email staff copy ---
    $header = "From: Alley Cat Photo <" . $locationEmail . ">\r\n";
    @mail($to, $subject, $message, $header);

    // --- Write receipt ---
    $receiptPath = "photos/" . $date_path . "/receipts";
    mkdir($receiptPath, 0777, true);
    file_put_contents("$receiptPath/$orderID.txt", $message);

    // --- Mirror to fire folder ---
    $server_addy = $_SERVER['HTTP_HOST'] ?? '';
    $firePath = ($server_addy == '192.168.2.126') ? "photos/receipts/fire" : "photos/receipts";
    mkdir($firePath, 0777, true);
    file_put_contents("$firePath/$orderID.txt", $message);

    // --- Copy email photos ---
    if ($txtEmail != '') {
        $toPath = "photos/" . $date_path . "/pending_email";
        $filePath = "photos/" . $date_path . "/emails/" . $txtEmail;
        mkdir($toPath, 0777, true);
        mkdir($filePath, 0777, true);
        // info.txt must always be email|message (first line is email|message)
        $infoTxt = "$txtEmail|" . ($isQrPayment ? "SQUARE ORDER: $" . number_format($txtAmt, 2) . " PAID" : "CASH ORDER: $" . number_format($txtAmt, 2) . " DUE") . "\r\n";
        $infoTxt .= $message;
        file_put_contents("$toPath/info.txt", $infoTxt);

        foreach ($Cart->items as $order_code => $quantity) {
            [$prod_code, $photo_id] = explode('-', $order_code);
            if (trim($prod_code) == 'EML' && $quantity > 0) {
                $sourcefile = "photos/$date_path/raw/$photo_id.jpg";
                $destfile   = "$filePath/$photo_id.jpg";
                @copy($sourcefile, $destfile);
            }
        }

        // Launch mailer background process
        exec('start /B php mailer.php');
    }
    
// ---------------------------------------------------------------------
    // --- HANDLE NON-EMAIL PHOTO ITEMS FOR PRINT WATCHER (AUTO PRINT CHECK) ---
    // ---------------------------------------------------------------------
    $shouldAutoPrint = acp_get_autoprint_status();
    
    if ($shouldAutoPrint) {
        // --- Handle non-email photo items for print watcher ---
        $orderOutputDir = ($server_addy == '192.168.2.126') ? "R:/orders" : "C:/orders";
        //$orderOutputDir = "R:/orders";
        if (!is_dir($orderOutputDir)) {
            @mkdir($orderOutputDir, 0777, true);
        }

        foreach ($Cart->items as $order_code => $quantity) {
            [$prod_code, $photo_id] = explode('-', $order_code);

            if (trim($prod_code) != 'EML' && $quantity > 0) {
                $sourcefile = "photos/$date_path/raw/$photo_id.jpg";

                if (file_exists($sourcefile)) {
                    // Determine orientation
                    $imgInfo = @getimagesize($sourcefile);
                    $orientation = 'V';
                    if ($imgInfo && isset($imgInfo[0], $imgInfo[1])) {
                        $orientation = ($imgInfo[0] > $imgInfo[1]) ? 'H' : 'V';
                    }

                    // Copy for each quantity
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
                        @copy($sourcefile, $destfile);
                    }
                }
            }
        }
    }
    $Cart->clearCart();
}
?>
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml">
<head>
<meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
<title>Alley Cat Photo <?php echo htmlspecialchars($locationName); ?> : Processing transaction...</title>
<link rel="stylesheet" href="/public/assets/css/acps.css">
<script src="/public/assets/js/jquery-1.11.1.min.js"></script>
<script type="text/javascript">
document.oncontextmenu=()=>false;
document.onmousedown=e=>false;
$(document).ready(function(){setTimeout(()=>{location.href="/";},60000);});
</script>
</head>
<body>
<div id="pay-app">
  <div class="app-view active" style="text-align: center;">
    <div class="logo-section" style="margin-bottom: 2rem;">
      <img src="/public/assets/images/alley_logo_sm.png" alt="Alley Cat Photo" width="300">
    </div>
    
    <?php if ($responseMode == 'approved') { ?>
      <h1 style="color:#6F0; font-size: 2.5rem; margin-bottom: 1rem;">APPROVED</h1>
      
      <div style="font-size: 2.2rem; color:#ffff66; margin-bottom: 1rem;">Your Order Number Is:</div>
      <div style="font-size: 17rem; color:#FF0; font-weight: bold; margin: 1rem 0;"><?php echo $orderID; ?></div>
      
      <?php if ($isOnsite=='yes') { ?>
        <div style="font-size: 2.5rem; color:#6F0; font-weight:bold; margin-top: 2rem;">Your order is being processed now!</div>
        <div style="font-size: 2rem; color:#fff; margin-top: 1rem;">Prints will be ready in just a minute.</div>
        <div style="font-size: 1.8rem; color:#fff;">Digital images are emailed immediately.</div>
        <div style="font-size: 1.8rem; color:#c81c1c; margin-top: 1.5rem;">Please go to the sales counter to pick up your order.</div>
      <?php } else { ?>
        <div style="font-size: 2rem; color:#fff; margin-top: 2rem;">Thank you for your order.</div>
        <div style="font-size: 1.8rem; color:#CCC; margin-top: 0.5rem;">If you have purchased digital emails they will be sent shortly.</div>
        <div style="font-size: 1.8rem; color:#CCC;">If you have chosen postal mail delivery, expect prints within 2–3 weeks.</div>
      <?php } ?>
      
      <div style="margin-top: 3rem;">
        <button type="button" class="btn-action" onclick="location.href='/'">RETURN TO GALLERY</button>
      </div>
    <?php } else { ?>
      <h1 style="color:#ff0000; font-size: 3rem; margin-bottom: 2rem;">DECLINED</h1>
      
      <div style="font-size: 1.8rem; color:#CCC; margin-bottom: 3rem;">
        <?php echo htmlspecialchars($responseMsg); ?>
      </div>
      
      <?php
      // Store data in session for retry
      $_SESSION['retry_email'] = $txtEmail;
      $_SESSION['retry_onsite'] = $isOnsite;
      if ($isOnsite == 'no') {
          $_SESSION['retry_name'] = $txtName;
          $_SESSION['retry_addr'] = $txtAddr;
          $_SESSION['retry_city'] = $txtCity;
          $_SESSION['retry_state'] = $txtState;
          $_SESSION['retry_zip'] = $txtZip;
      }
      // Calculate original amount before transaction fee was added
      $originalAmount = $txtAmt / 1.035;
      ?>
      
      <div class="form-actions" style="max-width: 600px; margin: 0 auto; justify-content: center; gap: 1.5rem;">
        <button type="button" class="btn-action" onclick="location.href='pay.php?amt=<?php echo urlencode(number_format($originalAmount, 2, '.', '')); ?>&retry=1'">TRY ANOTHER METHOD</button>
        <button type="button" class="btn-action" onclick="location.href='/'">CANCEL AND RETURN TO GALLERY</button>
      </div>
    <?php } ?>
  </div>
</div>
</body>
</html>
