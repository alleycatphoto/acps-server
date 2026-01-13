// --- State ---
let state = {
    email: '',
    onsite: 'yes',
    address: { name:'', street:'', city:'', state:'', zip:'' },
    skipDelivery: false,
    total: 0
};

// --- Navigation ---
function goToView(id) {
    $('.app-view').removeClass('active');
    $('#' + id).addClass('active');
    
    // Always hide keyboard on view change (it will reappear if user taps an input)
    if(window.ModernKeyboard && ModernKeyboard.hide) {
        ModernKeyboard.hide();
    } else if(window.jsKeyboard && jsKeyboard.hide) {
        jsKeyboard.hide();
    }
}

function showLoader(msg) {
    $('#loader-text').text(msg || 'Processing...');
    $('#loader-overlay').addClass('visible');
}
function hideLoader() { $('#loader-overlay').removeClass('visible'); }

function showErrorModal(msg) {
    $('#modal-error-msg').html(msg);
    $('#modal-error').removeClass('hidden');
}

// --- Step 1: Email ---
function handleEmailSubmit() {
    const email = $('#input-email').val().trim();
    const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
    
    if (!email || !emailRegex.test(email)) {
        showErrorModal('Please enter a valid<br>email address.');
        return;
    }
    
    state.email = email;
    
    if (state.skipDelivery) {
        // Email only order -> Payment
        initPayment();
    } else {
        // Needs delivery selection
        goToView('view-delivery');
    }
}

// --- Step 2: Delivery ---
function selectPickup() {
    state.onsite = 'yes';
    initPayment();
}

function selectPickupFromModal() {
    $('#modal-mail-confirm').addClass('hidden');
    selectPickup();
}

function selectMailConfirm() {
    $('#modal-mail-confirm').removeClass('hidden');
}

function confirmMail() {
    $('#modal-mail-confirm').addClass('hidden');
    state.onsite = 'no';
    goToView('view-address');
}

// --- Step 3: Address ---
function validateAddress() {
    const name = $('#input-name').val();
    const addr = $('#input-addr').val();
    const city = $('#input-city').val();
    const st   = $('#input-state').val();
    const zip  = $('#input-zip').val();

    if(!name || !addr || !city || !st || !zip) {
        showErrorModal("All address fields<br>are required.");
        return;
    }

    showLoader('Validating Address...');
    
    $.ajax({
        type: 'POST',
        url: 'validate_address.php',
        data: { txtAddr: addr, txtCity: city, txtState: st, txtZip: zip },
        dataType: 'json',
        success: function(resp) {
            hideLoader();
            if (resp.status === 'success') {
                // Update state with validated data
                state.address.name = name;
                state.address.street = resp.validatedAddress.street;
                state.address.city = resp.validatedAddress.city;
                state.address.state = resp.validatedAddress.state;
                
                let fullZip = resp.validatedAddress.zipCode;
                if(resp.validatedAddress.zipPlus4) fullZip += '-' + resp.validatedAddress.zipPlus4;
                state.address.zip = fullZip;
                
                // Proceed
                initPayment();
            } else {
                showErrorModal(resp.message || "Address invalid.");
            }
        },
        error: function() {
            hideLoader();
            showErrorModal("Validation error.<br>Please check connection.");
        }
    });
}

// --- Step 4: Payment ---
function initPayment() {
    goToView('view-payment');
    
    // 1. Generate QR Code dynamically with user email
    fetchQR();
    
    // 2. Initialize Card Reader
    initCardReader();
    
    // 3. Start Polling logic is handled inside fetchQR success now
}

function fetchQR() {
    const email = state.email;
    const total = window.acps_base_total;
    
    if (!email || total <= 0) {
        console.error("Cannot generate QR: Missing email or total.");
        return;
    }
    
    // Show loading state
    $('#qr-placeholder').html('<p style="color:black; font-weight:bold;">Generating QR...</p>');
    
    $.ajax({
        type: 'POST',
        url: 'cart_generate_qr.php',
        data: { email: email, total: total },
        dataType: 'json',
        success: function(resp) {
            if (resp.status === 'success') {
                // Update QR Image
                $('#qr-placeholder').html('<img src="' + resp.qr_url + '" alt="QR Code" />');
                
                // Update Order ID for polling and submission
                $('#square_order_id').val(resp.order_id);
                
                // Start polling with the NEW order ID
                startQrPolling(resp.order_id);
                
            } else {
                $('#qr-placeholder').html('<p style="color:red;">QR Error</p>');
                showErrorModal(resp.message || "Could not generate QR code.");
            }
        },
        error: function(xhr, status, error) {
            console.error("QR Generation Error:", error);
            $('#qr-placeholder').html('<p style="color:red;">Connection Error</p>');
        }
    });
}

// Card Reader Logic
function initCardReader() {
    if (window.CardReader && !window._cardReaderInitialized) {
        window._cardReaderInitialized = true;
        var reader = new CardReader();
        reader.observe(window);
        reader.cardRead(function(value) {
            processSwipe(value);
        });
    }
}

function processSwipe(swipeData) {
    showLoader('Processing Card...');
    
    // Parse Swipe
    try {
        const parts = swipeData.split("^");
        const nameParts = parts[1]?.split("/") || ["",""];
        const cardNum = parts[0]?.substring(1) || "";
        const lastPart = parts[2] || "";
        const expYear = lastPart.substring(0,2);
        const expMonth = lastPart.substring(2,4);
        
        // Fill Form
        $('#txtSwipeData').val(swipeData);
        $('#txtFname').val(nameParts[1]);
        $('#txtLname').val(nameParts[0]);
        $('#txtCardNum').val(cardNum);
        $('#txtExpMonth').val(expMonth);
        $('#txtExpYear').val(expYear);
        
        // Fill Other Data
        fillFinalForm();
        
        // Submit
        document.getElementById('frmFinal').submit();
        
    } catch(e) {
        hideLoader();
        showErrorModal("Card Read Error.<br>Please Try Again.");
    }
}

function processCash() {
    // Redirect to cash handler
    // Ensure taxFreeAmt is available (passed from PHP via global or data attr)
    // We will read it from a hidden input or global var set in pay.php
    const baseAmt = window.acps_base_total; 
    
    const q = `?txtAmt=${baseAmt}&isOnsite=${state.onsite}&txtEmail=${encodeURIComponent(state.email)}` +
              `&txtName=${encodeURIComponent(state.address.name)}&txtAddr=${encodeURIComponent(state.address.street)}` +
              `&txtCity=${encodeURIComponent(state.address.city)}&txtState=${encodeURIComponent(state.address.state)}` +
              `&txtZip=${encodeURIComponent(state.address.zip)}`;
    
    window.location.href = 'cart_process_cash.php' + q;
}

function fillFinalForm() {
    $('#final-email').val(state.email);
    $('#final-onsite').val(state.onsite);
    $('#final-name').val(state.address.name);
    $('#final-addr').val(state.address.street);
    $('#final-city').val(state.address.city);
    $('#final-state').val(state.address.state);
    $('#final-zip').val(state.address.zip);
}

function debugSwipe() {
    const debugData = '%B4111111111111111^SMITH/PAUL^25121010000000000000?';
    processSwipe(debugData);
}

// QR Polling
function startQrPolling(orderId) {
    let pollingInterval = null;
    let isProcessing = false;

    async function checkPaymentStatus() {
        if (isProcessing || !orderId) return;

        try {
          const response = await fetch(`https://alleycatphoto.net/pay/?status=${encodeURIComponent(orderId)}`, {
            method: 'GET',
            cache: 'no-cache',
            mode: 'cors',
            headers: { 'Accept': 'application/json' }
          });

          if (!response.ok) return;

          const data = await response.json();
          if (data && data.result === true) {
            isProcessing = true;
            clearInterval(pollingInterval);
            console.log("Payment confirmed for order " + orderId);

            showLoader("Processing QR Payment...");

            const form = document.getElementById('frmFinal');
            
            // Set QR flags
            $('#is_qr_payment').val('1');
            $('#square_order_id').val(orderId);
            
            fillFinalForm();
            form.submit();
          }
        } catch (error) {
          console.error("Polling error:", error);
        }
    }

    pollingInterval = setInterval(checkPaymentStatus, 3000);
}

// Init Logic
$(document).ready(function() {
    // Initialize state from PHP variables
    if (typeof window.acps_skip_delivery !== 'undefined') {
        state.skipDelivery = window.acps_skip_delivery;
    }
    if (typeof window.acps_total !== 'undefined') {
        state.total = window.acps_total;
    }
    
    // Initialize Virtual Keyboard
    if(window.jsKeyboard) {
        jsKeyboard.init("virtualKeyboard");
    }
    
    // Handle retry mode - pre-populate and skip to payment
    if (window.acps_is_retry) {
        state.email = window.acps_retry_email || '';
        state.onsite = window.acps_retry_onsite || 'yes';
        state.address.name = window.acps_retry_name || '';
        state.address.street = window.acps_retry_addr || '';
        state.address.city = window.acps_retry_city || '';
        state.address.state = window.acps_retry_state || '';
        state.address.zip = window.acps_retry_zip || '';
        
        // Pre-fill the hidden form fields
        $('#final-email').val(state.email);
        $('#final-onsite').val(state.onsite);
        $('#final-name').val(state.address.name);
        $('#final-addr').val(state.address.street);
        $('#final-city').val(state.address.city);
        $('#final-state').val(state.address.state);
        $('#final-zip').val(state.address.zip);
        
        // Skip directly to payment screen
        setTimeout(function() {
            initPayment();
        }, 100);
    }
});
