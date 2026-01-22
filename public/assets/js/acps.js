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
    
    // Always hide keyboard on view change
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
        initPayment();
    } else {
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
                state.address.name = name;
                state.address.street = resp.validatedAddress.street; 
                state.address.city = resp.validatedAddress.city;     
                state.address.state = resp.validatedAddress.state;   

                let fullZip = resp.validatedAddress.zipCode;
                if(resp.validatedAddress.zipPlus4) fullZip += '-' + resp.validatedAddress.zipPlus4;
                state.address.zip = fullZip;

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
    fetchQR();
    initCardReader();
}

function fetchQR() {
    const email = state.email;
    const total = window.acps_base_total;

    if (!email || total <= 0) {
        console.error("Cannot generate QR: Missing email or total.");
        return;
    }

    $('#qr-placeholder').html('<p style="color:black; font-weight:bold;">Generating QR...</p>');

    $.ajax({
        type: 'POST',
        url: 'cart_generate_qr.php',
        data: { email: email, total: total },
        dataType: 'json',
        success: function(resp) {
            if (resp.status === 'success') {
                $('#qr-placeholder').html('<img src="' + resp.qr_url + '" alt="QR Code" />');
                $('#square_order_id').val(resp.order_id);
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

// --- NEW PROCESSORS USING CHECKOUT.PHP ---

function processSwipe(swipeData) {
    showLoader('Processing Card...');

    try {
        const parts = swipeData.split("^");
        const nameParts = parts[1]?.split("/") || ["",""];
        const cardNum = parts[0]?.substring(1) || "";
        const lastPart = parts[2] || "";
        const expYear = lastPart.substring(0,2);
        const expMonth = lastPart.substring(2,4);

        const formData = new FormData();
        formData.append('payment_method', 'credit');
        formData.append('swipe_data', swipeData);
        formData.append('card_num', cardNum);
        formData.append('exp_month', expMonth);
        formData.append('exp_year', expYear);
        formData.append('email', state.email);
        formData.append('amount', window.acps_base_total); // Tax calculated in PHP for receipt, but ePN takes total? ePN logic in checkout.php takes 'Total' which is formatted from passed amount. Passed amount should be total including tax. Wait, pay.php said "Input is Tax Inclusive" for cart_process.
        // window.acps_base_total is from $_GET['amt'].
        // Checkout.php takes 'amount' and formats it.
        
        // Add Common Fields
        appendCommonFields(formData);

        fetch('config/api/checkout.php', {
            method: 'POST',
            body: formData
        })
        .then(r => r.json())
        .then(data => {
            if (data.status === 'success') {
                window.location.href = `thankyou.php?order=${data.order_id}&status=paid&onsite=${state.onsite}`;
            } else {
                hideLoader();
                showErrorModal(data.message || "Card Declined");
            }
        })
        .catch(err => {
            hideLoader();
            console.error(err);
            showErrorModal("Payment Error. Try Again.");
        });

    } catch(e) {
        hideLoader();
        showErrorModal("Card Read Error.<br>Please Try Again.");     
    }
}

function processCash() {
    showLoader('Creating Order...');
    
    // Cash Amount (Base)
    const baseAmt = window.acps_amount_without_tax; // Passed from pay.php
    
    const formData = new FormData();
    formData.append('payment_method', 'cash');
    formData.append('amount', baseAmt); // Cash pending uses base amount
    formData.append('email', state.email);
    
    appendCommonFields(formData);

    fetch('config/api/checkout.php', {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(data => {
        if (data.status === 'success') {
            window.location.href = `thankyou.php?order=${data.order_id}&status=pending&onsite=${state.onsite}`;
        } else {
            hideLoader();
            showErrorModal(data.message || "Order Error");
        }
    })
    .catch(err => {
        hideLoader();
        console.error(err);
        showErrorModal("Connection Error.");
    });
}

function appendCommonFields(formData) {
    formData.append('delivery_method', state.onsite === 'yes' ? 'pickup' : 'mail');
    formData.append('name', state.address.name);
    formData.append('address', state.address.street);
    formData.append('city', state.address.city);
    formData.append('state', state.address.state);
    formData.append('zip', state.address.zip);
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

            // Process via Checkout API
            const formData = new FormData();
            formData.append('payment_method', 'qr');
            formData.append('email', state.email);
            formData.append('amount', window.acps_base_total); // QR paid full amount? 
            // Logic in checkout.php for QR doesn't re-calc tax, it just prints receipt.
            // Assuming base_total is correct for receipt.
            
            appendCommonFields(formData);

            fetch('config/api/checkout.php', {
                method: 'POST',
                body: formData
            })
            .then(r => r.json())
            .then(data => {
                 window.location.href = `thankyou.php?order=${data.order_id}&status=paid&onsite=${state.onsite}`;
            });
          }
        } catch (error) {
          console.error("Polling error:", error);
        }
    }

    pollingInterval = setInterval(checkPaymentStatus, 3000);
}

// Init Logic
$(document).ready(function() {
    if (typeof window.acps_skip_delivery !== 'undefined') {
        state.skipDelivery = window.acps_skip_delivery;
    }
    if (typeof window.acps_total !== 'undefined') {
        state.total = window.acps_total;
    }

    if(window.jsKeyboard) {
        jsKeyboard.init("virtualKeyboard");
    }

    if (window.acps_is_retry) {
        state.email = window.acps_retry_email || '';
        state.onsite = window.acps_retry_onsite || 'yes';
        state.address.name = window.acps_retry_name || '';
        state.address.street = window.acps_retry_addr || '';
        state.address.city = window.acps_retry_city || '';
        state.address.state = window.acps_retry_state || '';
        state.address.zip = window.acps_retry_zip || '';
        setTimeout(function() { initPayment(); }, 100);
    }
});
