<?php
//*********************************************************************//
// AlleyCat PhotoStation - Unified Checkout & Payment (pay.php)
// Consolidates: checkout.php -> checkout_mailing.php -> cart_process.php
// Designed for Full-Screen Shadowbox / Modal
//*********************************************************************//

// --- 1. SETUP & INIT ---
require_once __DIR__ . '/vendor/autoload.php';
try {
    $dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
    $dotenv->load();
} catch (\Dotenv\Exception\InvalidPathException $e) {
    // Silently ignore
}

require_once "admin/config.php";
if (session_status() === PHP_SESSION_NONE) { session_start(); }
include('shopping_cart.class.php');
$Cart = new Shopping_Cart('shopping_cart');

// --- Check for retry mode ---
$isRetry = isset($_GET['retry']) && $_GET['retry'] == '1';
$retryEmail = $isRetry && isset($_SESSION['retry_email']) ? $_SESSION['retry_email'] : '';
$retryOnsite = $isRetry && isset($_SESSION['retry_onsite']) ? $_SESSION['retry_onsite'] : 'yes';
$retryName = $isRetry && isset($_SESSION['retry_name']) ? $_SESSION['retry_name'] : '';
$retryAddr = $isRetry && isset($_SESSION['retry_addr']) ? $_SESSION['retry_addr'] : '';
$retryCity = $isRetry && isset($_SESSION['retry_city']) ? $_SESSION['retry_city'] : '';
$retryState = $isRetry && isset($_SESSION['retry_state']) ? $_SESSION['retry_state'] : '';
$retryZip = $isRetry && isset($_SESSION['retry_zip']) ? $_SESSION['retry_zip'] : '';

// --- 2. GATHER CART DATA ---
$queryString = $_SERVER['QUERY_STRING']; 
parse_str($queryString, $queryString);

// Initial Total from GET (passed from gallery)
$thisTotal = isset($_GET['amt']) ? floatval($_GET['amt']) : 0.00;

// Analyze Cart Contents (Prints vs Email)
$emlCount = 0;
$otherCount = 0;
foreach ($Cart->items as $order_code => $quantity) {
    if ($quantity < 1) continue;
    list($prod_code, $photo_id) = explode('-', $order_code);
    if (trim($prod_code) == 'EML') {
        $emlCount += $quantity;
    } else {
        $otherCount += $quantity;
    }
}

// Logic: If ALL items are emails (no physical prints), skip delivery & address steps
$skipDelivery = ($emlCount > 0 && $otherCount == 0);

// --- 3. PREPARE PAYMENT DATA (For Step 4) ---
// Note: We calculate this early to generate QR code if needed, but display later
// cart_process.php logic: Input is Tax Inclusive
$amount_with_tax = $thisTotal;
$amount_without_tax = $amount_with_tax / 1.0675;
$tax = $amount_with_tax - $amount_without_tax;
$surcharge = $amount_without_tax * 0.035; // 2.9% fee
$cc_total = $amount_without_tax * 1.035;
$cc_totaltaxed = $cc_total * 1.0675;
// $cc_tax = $cc_total * 0.0675; // Unused in display usually

// Generate Order ID & Square Link (Only if we have a total)
$qr_code_url = null;
$squareOrderId = null;
$orderID = ""; 

if ($cc_totaltaxed > 0) {
    // Generate Order ID (Logic from cart_process.php)
    $dirname = "photos/";
    $date_path = date('Y/m/d');
    $filename = $dirname.$date_path."/orders.txt";
    
    if (file_exists($filename)) {
        $orderID = (int) trim(file_get_contents($filename)) + 1; // Anticipate next
    } else {
        $orderID = 1000;
    }
    
    // Generate Link
    // require_once __DIR__ . '/square_link.php';
    // $transactionId = uniqid('qr_');
    // // Using a placeholder/system email for the QR link generation since user email is not yet known
    // // Square requires an email, but we can't get it until Step 1. 
    // // Compromise: Use a system email for the link generation, or if possible, update it later.
    // // For now, consistent with legacy logic, we create it here.
    // $linkEmail = "kiosk@alleycatphoto.net"; 
    
    // $paymentLink_response = createSquarePaymentLink($cc_totaltaxed, $linkEmail, (string)$orderID, $transactionId);
    
    // if ($paymentLink_response) {
    //     $square_link_url = $paymentLink_response->getUrl();
    //     $squareOrderId = $paymentLink_response->getOrderId(); 
    //     $qr_code_url = 'https://api.qrserver.com/v1/create-qr-code/?size=250x250&data=' . urlencode($square_link_url);
    // }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Checkout | AlleyCat Photo</title>
    
    <link rel="stylesheet" href="/public/assets/css/bootstrap.min.css">
    <!-- <link rel="stylesheet" href="/public/assets/css/jsKeyboard.css"> OLD KEYBOARD -->
    <link rel="stylesheet" href="/public/assets/css/modern_keyboard.css"> <!-- NEW KEYBOARD -->
    <link rel="stylesheet" href="/public/assets/css/acps.css"> <!-- Master Styles -->
    
    <!-- Pass PHP vars to JS -->
    <script>
        window.acps_amount_without_tax = <?php echo json_encode($amount_without_tax); ?>;
        window.acps_skip_delivery = <?php echo $skipDelivery ? 'true' : 'false'; ?>;
        window.acps_total = <?php echo json_encode($cc_totaltaxed); ?>;
        window.acps_base_total = <?php echo json_encode($thisTotal); ?>;
        window.acps_is_retry = <?php echo $isRetry ? 'true' : 'false'; ?>;
        window.acps_retry_email = <?php echo json_encode($retryEmail); ?>;
        window.acps_retry_onsite = <?php echo json_encode($retryOnsite); ?>;
        window.acps_retry_name = <?php echo json_encode($retryName); ?>;
        window.acps_retry_addr = <?php echo json_encode($retryAddr); ?>;
        window.acps_retry_city = <?php echo json_encode($retryCity); ?>;
        window.acps_retry_state = <?php echo json_encode($retryState); ?>;
        window.acps_retry_zip = <?php echo json_encode($retryZip); ?>;
    </script>
</head>
<body>

<div id="pay-app">
    
    <!-- ================= Step 1: EMAIL ================= -->
    <div id="view-email" class="app-view active">
        <div class="logo-section" style="margin-bottom: 3rem;">
            <img src="/public/assets/images/alley_logo_sm.png" alt="Alley Cat Photo" width="250">
        </div>

        <h1>PLEASE ENTER YOUR EMAIL</h1>
        
        <div class="form-container" style="text-align: center;">
            <p style="color:#999; margin-bottom:1.5rem; font-size:1.2rem;">
                Used to send your receipt and any digital photos.
            </p>

            <div class="form-group">
                <input type="email" id="input-email" class="form-input" placeholder="name@example.com" style="text-align:center; font-size: 2rem;">
            </div>

            <div class="form-actions" style="justify-content: center; gap: 1.5rem;">
                <button type="button" class="btn-action" onclick="location.href='/'">RETURN TO GALLERY</button>
                <button type="button" class="btn-action" onclick="handleEmailSubmit()">CONTINUE</button>
            </div>
        </div>
        <!-- <div style="margin-top: 2rem;">
            <a href="/" style="color: #666; text-decoration: none; font-size: 1.2rem;">CANCEL ORDER</a>
        </div> -->
    </div>


    <!-- ================= Step 2: DELIVERY (Pickup/Mail) ================= -->
    <div id="view-delivery" class="app-view">
        <h1>GET YOUR PHOTOS</h1>
        
        <div style="width: 100%; max-width: 800px;">
            <!-- Pick Up Now -->
            <button type="button" class="btn-choice btn-green" onclick="selectPickup()">
                <span class="btn-title">PICK UP NOW</span>
                <span class="btn-subtitle">READY IN MINUTES</span>
                <span class="btn-sub-detail">Get your photos today</span>
            </button>

            <!-- Mail To Me -->
            <button type="button" class="btn-choice btn-white" onclick="selectMailConfirm()">
                <span class="btn-title">MAIL TO ME</span>
                <span class="btn-subtitle">2–3 WEEKS</span>
                <span class="btn-sub-detail">Get them later</span>
            </button>

            <div class="footer-text">Most guests choose Pick Up Now</div>
            
            <div class="form-actions" style="justify-content: center; margin-top: 3rem !important;">
                <button class="btn-action" style="border-color: #444; color: #666;" onclick="goToView('view-email')">BACK</button>
            </div>
        </div>
    </div>
    
    <!-- Modal: Mail Confirmation -->
    <div id="modal-mail-confirm" class="modal-overlay hidden">
        <div class="modal-box">
            <div class="modal-text">
                Just checking —<br><br>
                Mailed photos arrive in <span style="color:#fff">2–3 weeks</span>.<br>
                <span class="modal-highlight">Pick Up Now gets them today.</span>
            </div>
            <button type="button" class="btn-choice btn-green" onclick="selectPickupFromModal()">
                <span class="btn-title" style="font-size: 2rem;">GET THEM TODAY</span>
            </button>
            <button type="button" class="btn-choice btn-white" style="padding: 1rem; margin-bottom: 0;" onclick="confirmMail()">
                <span class="btn-subtitle" style="margin:0; font-size: 1.2rem;">Continue with Mail</span>
            </button>
        </div>
    </div>

    <!-- Modal: Generic Error -->
    <div id="modal-error" class="modal-overlay hidden" style="z-index: 3000;">
        <div class="modal-box" style="border-color: #ff0000; box-shadow: 0 0 50px rgba(153, 0, 0, 0.6);">
            <div class="modal-text" id="modal-error-msg">
                Error Message Here
            </div>
            <button type="button" class="btn-action" onclick="$('#modal-error').addClass('hidden')" style="width: 100%; font-size: 1.5rem; border-color: #ff0000; color: #ff0000;">
                OKAY
            </button>
        </div>
    </div>


    <!-- ================= Step 3: ADDRESS (Only if Mail) ================= -->
    <div id="view-address" class="app-view">
        <h1>ENTER MAILING ADDRESS</h1>
        
        <div class="form-container">
            <div class="form-group">
                <label class="form-label">Full Name</label>
                <input type="text" id="input-name" class="form-input" placeholder="Your Name">
            </div>
            <div class="form-group">
                <label class="form-label">Address</label>
                <input type="text" id="input-addr" class="form-input" placeholder="Street Address">
            </div>
            <div class="form-group">
                <label class="form-label">City</label>
                <input type="text" id="input-city" class="form-input" placeholder="City">
            </div>
            
            <div class="form-row-flex">
                <div class="form-group col-state">
                    <label class="form-label">State</label>
                    <input type="text" id="input-state" class="form-input" placeholder="ST" maxlength="2" style="text-align:center;">
                </div>
                <div class="form-group col-zip">
                    <label class="form-label">Zip Code</label>
                    <input type="text" id="input-zip" class="form-input" placeholder="12345" maxlength="10">
                </div>
            </div>

            <div class="form-actions" style="justify-content: center; gap: 1.5rem;">
                <button type="button" class="btn-action" onclick="goToView('view-delivery')">BACK</button>
                <button type="button" class="btn-action" onclick="validateAddress()">CONTINUE</button>
            </div>
        </div>
        <!-- Note: Keyboard logic uses #virtualKeyboard from Step 1, moved if needed or fixed at bottom -->
    </div>


    <!-- ================= Step 4: PAYMENT (Swipe/QR) ================= -->
    <div id="view-payment" class="app-view">
        <h1 class="animation" style="margin-bottom: 10px;"><span class="blinking">Please swipe your card now</span></h1>
        
        <div class="payment-container">
            <!-- Left: QR Code -->
            <div class="payment-left">
                <p class="scan-text" style="color:#eee; font-size:1.2rem; margin-bottom: 1rem;">scan to pay with mobile</p>
                <div id="qr-placeholder" class="qr-box">
                    <?php if ($qr_code_url): ?>
                        <img src="<?php echo $qr_code_url; ?>" alt="QR Code" />
                    <?php else: ?>
                        <p style="color:black; font-weight:bold;">Loading...</p>
                    <?php endif; ?>
                </div>
                <img src="/public/assets/images/pay_icons_250.png" alt="Icons" style="width: 200px; margin-top: 1rem;">
            </div>
            
            <!-- Right: Totals & Actions -->
            <div class="payment-right">
                <div style="text-align: center;">
                    <div class="total-label">TOTAL</div>
                    <div class="total-display">$<?php echo number_format($cc_totaltaxed, 2); ?></div>
                    <div class="sub-details">Includes NC Sales Tax  &amp; $<?php echo number_format($surcharge, 2); ?> Transaction Fee</div>
                </div>

                <button class="btn-choice btn-white" style="margin-bottom: 1rem; border-color: #178a00ff; color: #fff;" onclick="processCash()">
                    <span class="btn-title" style="font-size: 3rem;">Pay Cash/Card at the Counter</span>
                </button>
                
                <button class="btn-choice btn-white" style="border-color: #770000ff; color: #fff;" onclick="location.reload()">
                    <span class="btn-title" style="font-size: 3rem;">Cancel Transaction</span>
                </button>


            </div>
        </div>
    </div>

    <!-- Hidden Form for Final Submission -->
    <div id="virtualKeyboard"></div>
</div>

<!-- Loader -->
<div id="loader-overlay">
    <img src="/public/assets/images/loader.gif" width="150">
    <div class="loader-msg" id="loader-text">Processing...</div>
</div>

<!-- Hidden Form for Final Submission -->
<form id="frmFinal" method="post" action="cart_process_send.php">
    <!-- Variables populated by JS -->
    <input type="hidden" name="txtEmail" id="final-email">
    <input type="hidden" name="isOnsite" id="final-onsite" value="yes">
    <input type="hidden" name="txtAmt" value="<?php echo $cc_totaltaxed; ?>">
    <input type="hidden" name="txtName" id="final-name">
    <input type="hidden" name="txtAddr" id="final-addr">
    <input type="hidden" name="txtCity" id="final-city">
    <input type="hidden" name="txtState" id="final-state">
    <input type="hidden" name="txtZip" id="final-zip">
    
    <!-- Swipe Data -->
    <input type="hidden" name="txtSwipeData" id="txtSwipeData">
    <input type="hidden" name="txtFname" id="txtFname">
    <input type="hidden" name="txtLname" id="txtLname">
    <input type="hidden" name="txtCardNum" id="txtCardNum">
    <input type="hidden" name="txtExpMonth" id="txtExpMonth">
    <input type="hidden" name="txtExpYear" id="txtExpYear">
    
    <!-- QR Data -->
    <input type="hidden" name="is_qr_payment" id="is_qr_payment" value="0">
    <input type="hidden" name="square_order_id" id="square_order_id" value="<?php echo htmlspecialchars($squareOrderId ?? ''); ?>">
</form>

<!-- Scripts -->
<script src="/public/assets/js/vendor/jquery-1.9.1.min.js"></script>
<!-- <script src="/public/assets/js/jsKeyboard.js?v=<?php echo time(); ?>"></script> -->
<script src="/public/assets/js/modern_keyboard.js?v=<?php echo time(); ?>"></script>
<script src="/public/assets/js/CardReader.js"></script>
<script src="/public/assets/js/acps.js"></script> <!-- Master JS -->

<script>
// On-screen keyboard detection for kiosk (PC full-screen)
(function() {
    let clickedOnKeyboard = false;
    
    // Track clicks on keyboard to prevent closing
    document.addEventListener('mousedown', function(e) {
        // Check if click is on keyboard or its children
        if (e.target.closest('#virtualKeyboard')) {
            clickedOnKeyboard = true;
        } else {
            clickedOnKeyboard = false;
        }
    }, true);
    
    // Detect when any input is focused and shift content up
    document.addEventListener('focusin', function(e) {
        if (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA') {
            // Add class immediately when input is focused
            document.body.classList.add('keyboard-open');
        }
    });
    
    // Detect when input loses focus - close keyboard and shift content back
    document.addEventListener('focusout', function(e) {
        if (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA') {
            setTimeout(function() {
                // Don't close if we clicked on the keyboard
                if (clickedOnKeyboard) {
                    return;
                }
                
                // Only remove class and close keyboard if no inputs are focused
                if (!document.querySelector('input:focus, textarea:focus')) {
                    document.body.classList.remove('keyboard-open');
                    
                    // Close the modern keyboard using its API
                    if (window.ModernKeyboard && window.ModernKeyboard.hide) {
                        window.ModernKeyboard.hide();
                    }
                }
            }, 100);
        }
    });
})();
</script>

</body>
</html>