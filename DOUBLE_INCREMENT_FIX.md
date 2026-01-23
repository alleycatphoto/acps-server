# Double-Increment Counter Skip Fix - Critical Issue Resolution

## Problem Identified

**Symptom:** Live observation showed order counter jumping HIGH (1033) then order appearing as LOW (1032)
- User saw yellow "1033" displayed briefly
- Thank you page redirected to order 1032
- Indicates checkout.php was being called multiple times concurrently

## Root Cause

**Race condition in QR polling mechanism:**

The `startQrPolling()` function in `public/assets/js/acps.js` had a critical flaw:

1. Polling interval runs every 1 second checking Square payment status
2. When payment detected, it sets local `isProcessing = true` flag
3. **BUT** - Multiple polling intervals could be running concurrently if:
   - User somehow triggered QR generation twice (edge case)
   - OR multiple interval ticks fired before clearing interval
4. Each would call `fetch('config/api/checkout.php')` independently
5. Both fetches would race to increment the counter
6. Order created at 1033, but maybe second checkout attempt somehow affected order_id

**Secondary issue:**
- `processCash()` and `processSwipe()` had no protection against being called multiple times
- If user rapidly clicked button or form double-submitted, both would call checkout.php

## Solution Implemented

### 1. Added Global Checkout Lock (acps.js)

```javascript
let state = {
    email: '',
    onsite: 'yes',
    address: { name:'', street:'', city:'', state:'', zip:'' },
    skipDelivery: false,
    total: 0,
    isCheckoutProcessing: false  // NEW: PREVENT CONCURRENT CHECKOUTS
};
```

### 2. Protected All Checkout Entry Points

**processCash():**
```javascript
function processCash() {
    if (state.isCheckoutProcessing) {
        console.warn("Checkout already processing, ignoring duplicate cash submission");
        return;
    }
    
    state.isCheckoutProcessing = true;
    // ... process ...
    // Clear flag in .then() and .catch()
    state.isCheckoutProcessing = false;
}
```

**processSwipe():** Same protection for credit card payments

**QR Polling:** Uses BOTH local `isProcessing` AND global `state.isCheckoutProcessing` flags:
```javascript
async function checkPaymentStatus() {
    // PREVENT CONCURRENT CHECKOUTS AT GLOBAL LEVEL
    if (state.isCheckoutProcessing || isProcessing || !squareOrderId || pollCount >= maxPolls) return;
    
    // ...when payment detected...
    isProcessing = true;  // LOCAL flag
    state.isCheckoutProcessing = true;  // GLOBAL flag
    clearInterval(pollingInterval);  // CLEAR INTERVAL IMMEDIATELY
    
    fetch('config/api/checkout.php', {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(data => {
        state.isCheckoutProcessing = false;  // UNLOCK GLOBAL
        // ...redirect...
    })
    .catch(err => {
        state.isCheckoutProcessing = false;  // UNLOCK GLOBAL  
        // ...error handling...
    });
}
```

### 3. Enhanced Button Locking in pay.php

Added explicit console warning when duplicate button click detected:
```javascript
window.processCash = function() {
    var btn = document.getElementById('cashPayBtn');
    if (btn && btn.disabled) {
        console.warn("Cash pay button already disabled, ignoring duplicate click");
        return;
    }
    // ...disable button and process...
}
```

## Files Modified

1. **public/assets/js/acps.js**
   - Added `isCheckoutProcessing` flag to state object
   - Updated `processCash()` to check and set flag
   - Updated `processSwipe()` to check and set flag  
   - Updated `startQrPolling()` with dual-flag protection

2. **pay.php**
   - Enhanced console logging in processCash wrapper

## How It Works

1. First time user clicks Cash/QR/Swipe:
   - `state.isCheckoutProcessing = true`
   - Checkout API called
   - Button disabled
   - Loader shown

2. If user double-clicks or polling triggers multiple times:
   - Guard check: `if (state.isCheckoutProcessing) return;`
   - Process ignored
   - Console warning logged

3. After checkout completes (success or error):
   - `state.isCheckoutProcessing = false`
   - User redirected to thank you page OR can retry

4. QR Polling specifically:
   - Uses local `isProcessing` flag for this polling instance
   - Also checks global `state.isCheckoutProcessing` flag
   - Clears interval IMMEDIATELY when payment detected
   - Sets BOTH flags before making checkout call
   - Ensures no other polling tick can interfere

## Verification Needed

✅ **To verify fix working:**
1. Make a QR payment from main gallery checkout
2. Watch order numbers increment sequentially (no skips)
3. Verify yellow displayed number matches thank you page order ID
4. Check console for any "duplicate" warnings

**Expected behavior:**
- Each payment creates exactly ONE order
- Order numbers increment by 1
- No numbers skipped
- Yellow display and thank you page show same order ID

## Technical Details

**Why this specific fix?**
- Counter increment is protected by file locks (checkout.php lines 80-95)
- So multiple concurrent calls DON'T cause double-increment
- Instead, what WAS happening: Two checkouts racing, second one increments to 1033, first one somehow shows in UI but then redirect uses different order_id
- By preventing concurrent calls at JavaScript level, we guarantee exactly one checkout.php call per user action

**Why QR polling needed special handling?**
- Unlike cash (single button click) or swipe (single card read), QR polling runs on interval
- Multiple async operations could complete at different times
- Race condition window between detecting payment and clearing interval
- Dual flags ensure even if multiple interval ticks detect payment, only first one proceeds

## Commits

- Single commit bundling all three files (acps.js and pay.php modifications)
- Includes this documentation

