# Quick Reference - What's Been Fixed

## The Three Issues You Reported

### 1. ❌ "Dumping everyone of them in the folder"
**What was happening:** When you placed an order, ALL print files got copied into the printer queue at once
**Why it happened:** Code in `cart_process_cash.php` was directly copying to printer + `order_action.php` also copying on "Paid" click = duplicates
**Fix Applied:** Removed the duplicate file creation. Now files only get queued through `order_action.php` when you click "Paid"

### 2. ❌ "Mail looks like it's still spinning and doesn't queue the mail"
**What was happening:** Email wasn't being sent after payment
**Why it happened:** `order_action.php` had a `popen()` call that wasn't reliable on Windows
**Fix Applied:** Removed that. Now the spooler's `tick_mailer` (which runs every 1.5 seconds via dashboard) detects queued email and calls `gmailer.php`

### 3. ❌ "It needs to call the gmail and it shouldn't trigger until that cash button is paid"
**What was happening:** Email was queuing immediately on order creation, not waiting for payment
**Root Cause:** `cart_process_cash.php` was calling `mailer.php` immediately
**Fix Applied:** Removed that trigger. Email only queues when staff clicks "Paid" button → `order_action.php` → spooler handles the rest

## How to Monitor It Now

### Watch the Activity Indicator
Under the active tab in your dashboard, you'll see:
- **"Checking printer queue..."** (yellow pulsing dot) - when you switch to Printer tab
- **"Processing N print(s)..."** (orange dot) - when prints are in queue
- **"Checking email queue..."** (yellow pulsing dot) - when you switch to Mailer tab
- **"Sending N email(s)..."** (blue dot) - when emails are sending
- **"Ready"** (gray dot) - when queues are empty

### Check the Logs
```
/logs/cash_orders_event.log
```

Each order operation now logs:
- When payment action starts
- When print files are queued (with filename and quantity)
- When email queuing starts and completes
- When gmailer.php is called and whether it succeeded

**Example log line:**
```
2026-01-22 14:35:20 | Order 1234 | PRINT_QUEUED: 1234-photo1-4x6V-1.jpg (qty=1/1, code=4x6, orient=V)
```

## The New Flow

```
SQUARE/QR PAYMENT (with email):
1. Customer pays at kiosk → order created (no printing yet)
2. Confirmation email sent to customer immediately
3. You click "Paid" in dashboard ↓
4. Print files queued to /spool/printer/ ← SINGLE COPY (fixed!)
5. Email photos queued to /spool/mailer/ (if email exists) ← ONLY AFTER PAID (fixed!)
6. Activity indicator shows "Processing 2 prints..." and "Sending email..."
7. Dashboard's spooler (every 1.5s) detects queues
8. Spooler calls gmailer.php to send email ← WORKING (fixed!)
9. Email moves from /spool/mailer/ to /emails/ (archived)
```

## What to Test

1. **Place an order with 2 prints** - should NOT dump both files immediately
2. **Wait before clicking Paid** - email should NOT queue yet
3. **Click Paid button** - should see activity indicator show status
4. **Check /spool/printer/** - should have exactly 2 JPG files (not 4)
5. **Check /spool/mailer/** - should have order folder with photos + info.txt
6. **Wait 5-10 seconds** - email should move to /emails/ directory
7. **Check customer inbox** - email should arrive within 30 seconds
8. **Check logs** - `/logs/cash_orders_event.log` should show each step

## Files Changed

- `cart_process_cash.php` - Removed auto-print and auto-mailer calls
- `config/api/order_action.php` - Added logging, removed unreliable popen()
- `gmailer.php` - Added logging to track email sending

---

**Key Point:** Everything is now centralized in `order_action.php` and the spooler. No more multiple copy attempts, no more race conditions, no more mystery about where emails went. Check the logs!
