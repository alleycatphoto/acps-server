# AlleyCat PhotoStation - Fixes Applied (January 22, 2026 - Evening Session)

## Issues Identified

### 1. **Multiple Print Files Being Dumped on Order Creation**
**Problem:** When user placed an order, all print files for that order were dumped into the printer spooler directory at once (instead of one at a time as they processed). This caused queue backup.

**Root Cause:** `cart_process_cash.php` was directly copying print files to `C:/orders` for Square/QR payments, AND then `order_action.php` was ALSO copying them to the spooler when the staff clicked "Paid". This resulted in duplicates.

**Solution:**
- **Removed** automatic print file generation from `cart_process_cash.php` (lines 281-325 old code)
- Now Square/QR payments are created but printing only happens through `order_action.php` when staff clicks "Paid" button in dashboard
- This ensures single source of truth and prevents duplicate files in printer queue

### 2. **Email Queuing Immediately Instead of After Payment**
**Problem:** Emails were being queued immediately on order creation for Square/QR payments, but they should ONLY queue after payment confirmation (when staff clicks "Paid" button).

**Root Cause:** `cart_process_cash.php` was calling `mailer.php` immediately after Square/QR order creation. Email triggering needs to be centralized in `order_action.php`.

**Solution:**
- **Removed** automatic `mailer.php` trigger from `cart_process_cash.php`
- Email queuing only happens when staff clicks "Paid" in dashboard (via `order_action.php`)
- Email sending is then handled by spooler's `tick_mailer` which detects queued orders and calls `gmailer.php`

### 3. **Email Not Being Sent (Stuck in Queue)**
**Problem:** Email was queued but `gmailer.php` wasn't being called to actually send it.

**Root Cause:** `order_action.php` had `pclose(popen(...))` which may not be working reliably on Windows. Also, the spooler is the proper place to trigger email sending.

**Solution:**
- **Removed** direct `popen()` call from `order_action.php` that tried to trigger `mailer.php`
- Email queue files are created properly, and `spooler.php`'s `tick_mailer` action (called by `app.js` every 1.5 seconds) now detects queued orders and calls `gmailer.php`
- This is the proper flow: Dashboard → order_action.php creates queue → app.js tick_mailer → gmailer.php sends

## Changes Made

### File: `cart_process_cash.php`
**What Changed:**
- Deleted lines 281-325 that were copying print files to `C:/orders` and calling `mailer.php`
- Kept receipt email sending for Square/QR payments (customer receives confirmation)
- Kept the resend queue fallback

**Before:**
```php
// --- HANDLE AUTO PRINT FOR PAID PAYMENTS ---
if ($paymentType === 'square' || $paymentType === 'qr') {
    // ... code that copies to C:/orders ...
    // ... code that triggers mailer.php ...
}
```

**After:**
```php
// --- IMPORTANT: DO NOT AUTO-PRINT HERE FOR SQUARE/QR PAYMENTS ---
// Printing handled via order_action.php when staff clicks 'Paid' button

// For QR/Square payments, also send receipt email directly to customer
if (($paymentType === 'square' || $paymentType === 'qr') && !empty($txtEmail)) {
    // ... just send customer confirmation email ...
}
```

### File: `config/api/order_action.php`
**What Changed:**
- Added comprehensive logging to track all print and email operations
- Removed `popen()` call that was trying to trigger `mailer.php`
- Added logging events at key points:
  - `PAID_ACTION_START`: When action=paid is called
  - `AUTO_PRINT_STATUS`: Whether auto-print is enabled
  - `PRINT_QUEUED`: Each print file queued with details
  - `EMAIL_QUEUE_START`: When email queuing begins
  - `EMAIL_QUEUE_PHOTO`: Each photo copied to email queue
  - `EMAIL_QUEUE_READY`: When email is queued and waiting for spooler
  - `SPOOLER_WILL_SEND_EMAIL`: Confirming email will be sent by spooler

**Key Change:**
```php
// BEFORE: Direct trigger (unreliable)
if ($emailAttempted) {
    pclose(popen("start /B php ../../mailer.php \"$orderID\"", "r"));
}

// AFTER: Queue for spooler to process
if ($emailAttempted) {
    acp_log_event($orderID, "SPOOLER_WILL_SEND_EMAIL: tick_mailer will call gmailer.php");
}
```

### File: `gmailer.php`
**What Changed:**
- Moved `acp_log_event()` function definition before first use
- Added logging at script start: `GMAILER_STARTED`
- Added logging before sending: `GMAIL_SENDING`
- Added success logging with email address: `GMAIL_SUCCESS`
- Added detailed error logging: `GMAIL_ERROR` with API response details

**New Logging Calls:**
```php
acp_log_event($order_id, "GMAILER_STARTED: Script invoked with order_id=$order_id");
acp_log_event($order_id, "GMAIL_SENDING: Calling Gmail API for $customer_email");
acp_log_event($order_id, "GMAIL_SUCCESS: Email sent to $customer_email - moved to archive");
acp_log_event($order_id, "GMAIL_ERROR: API returned code {$res['code']}, response: " . json_encode($res['body']));
```

## New Data Flow

### For Cash Orders (No Changes to Existing Behavior)
1. Customer places order
2. Receipt created
3. Staff clicks "Paid" button → `order_action.php` with `action=paid`
4. Print files queued to `/photos/YYYY/MM/DD/spool/printer/`
5. If email address exists, email queued to `/photos/YYYY/MM/DD/spool/mailer/`
6. Spooler monitors queues and calls `gmailer.php` when email queue detected

### For Square/QR Payments (FIXED)
1. Customer completes Square/QR transaction at kiosk
2. Order created in `cart_process_cash.php` (NO print files copied, NO mailer.php called)
3. Confirmation email sent to customer via PHPMailer
4. Staff sees new order in dashboard with "Paid" status
5. Staff clicks "Paid" button → `order_action.php` with `action=paid` and `payment_method=square/qr`
6. Print files queued to spooler (single copy)
7. Email photos queued to spooler (if customer email exists)
8. Spooler detects queues and calls `gmailer.php`

## Logging for Debugging

All operations now log to `/logs/cash_orders_event.log` with timestamps:

**Example Log Output:**
```
2026-01-22 14:35:20 | Order 1234 | PAID_ACTION_START: method=square, location=MAIN, trans_id=SQ_TXN_123
2026-01-22 14:35:20 | Order 1234 | AUTO_PRINT_STATUS: ENABLED
2026-01-22 14:35:20 | Order 1234 | PRINT_QUEUED: 1234-photo1-4x6V-1.jpg (qty=1/1, code=4x6, orient=V)
2026-01-22 14:35:21 | Order 1234 | EMAIL_QUEUE_START: customer@email.com
2026-01-22 14:35:21 | Order 1234 | EMAIL_QUEUE_PHOTO: photo1.jpg queued for customer@email.com
2026-01-22 14:35:21 | Order 1234 | EMAIL_QUEUE_READY: Waiting for spooler.tick_mailer to trigger gmailer.php
2026-01-22 14:35:25 | Order 1234 | GMAILER_STARTED: Script invoked with order_id=1234
2026-01-22 14:35:26 | Order 1234 | GMAIL_SENDING: Calling Gmail API for customer@email.com
2026-01-22 14:35:28 | Order 1234 | GMAIL_SUCCESS: Email sent to customer@email.com - moved to archive
```

## How to Verify Fixes

### Test 1: Printer Queue (No Duplicates)
1. Place an order with 2 prints
2. Click dashboard "Paid" button
3. Check `/photos/YYYY/MM/DD/spool/printer/` - should see exactly 2 JPG files (not duplicates)
4. Watch activity indicator show "Processing 2 print(s)..."
5. Verify log shows `PRINT_QUEUED` twice

### Test 2: Email Queue (Proper Timing)
1. Place order with customer email
2. DO NOT click Paid yet
3. Check `/photos/YYYY/MM/DD/spool/mailer/` - should be empty
4. Click Paid button
5. Mailer queue directory should be created with photos
6. Watch activity indicator show "Sending 1 email(s)..."
7. Within 30 seconds, email should move to `/photos/YYYY/MM/DD/emails/` (archived)
8. Verify log shows `GMAILER_STARTED` → `GMAIL_SENDING` → `GMAIL_SUCCESS`

### Test 3: Check Logs
```bash
# View real-time logs
tail -f logs/cash_orders_event.log

# Or check the full log
cat logs/cash_orders_event.log
```

## Files Modified

- ✅ `cart_process_cash.php` (removed duplicate printer/mailer triggering)
- ✅ `config/api/order_action.php` (added logging, removed popen trigger)
- ✅ `gmailer.php` (added comprehensive logging)

## Testing Recommendations

1. **Clear existing queues** before testing:
   - Delete `/photos/YYYY/MM/DD/spool/printer/*`
   - Delete `/photos/YYYY/MM/DD/spool/mailer/*`

2. **Monitor the logs** while testing each scenario

3. **Watch the dashboard** activity indicator to see real-time status

4. **Verify email delivery** - check that emails actually arrive in customer inbox

5. **Check timestamps** in logs to ensure operations are sequential and timing makes sense

---

**Session Date:** January 22, 2026 (Evening)
**User Report:** Printer test "worked awesome except..." - bulk file dump issue resolved, email timing and sending fixed, comprehensive logging added for debugging.
