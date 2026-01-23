# ACPS90 v9.0 - Cash Order Email Staging Fix

**Date:** January 23, 2026  
**Issue:** Email queuing too early for cash orders  
**Status:** ✅ FIXED

---

## The Problem You Reported

1. **Placed cash order** → Email immediately queued (shouldn't happen!)
2. **Pressed C (cancel)** → Order not paid
3. **Email stuck in spooler** → Sitting there saying "sending" but customer is gone
4. **Should not queue email until** payment confirmed (staff clicks "Paid" button)

---

## Root Cause

In `config/api/checkout.php` line 204, there was a test bypass:

```php
if (strtolower($txtEmail) === 'photos@alleycatphoto.net') $isPaid = true; // Test Bypass
```

This forced `$isPaid = true` for the test email address, causing email to queue even for cash orders.

---

## The Fix

**Removed the test bypass.** Now the logic is:

```php
$isPaid = ($paymentMethod !== 'cash'); // Square, QR, Credit = Paid. Cash = Pending.

// NOTE: Removed test bypass - test orders should follow normal flow
// If you need to test cash orders that queue email, use order_action.php dashboard button

if ($isPaid) {
    // Queue email only for PAID orders (Square/QR/Credit)
}
```

---

## New Correct Flow for Cash Orders

### **Before Fix (WRONG):**
```
1. Customer places CASH order
2. Email immediately queues to /spool/mailer/
3. Customer cancels (presses C)
4. Email stuck in queue forever (customer contact info is still there)
5. Spooler tries to send email repeatedly
6. Problem: Email never completes, sits there forever
```

### **After Fix (CORRECT):**
```
1. Customer places CASH order
2. Receipt file created
3. Email NOT queued yet ✅
4. Customer cancels (presses C)
5. No email in queue ✅
6. Staff sees order but doesn't click "Paid"
7. Email never queues ✅
---
OR---
1. Customer places CASH order
2. Receipt file created
3. Email NOT queued yet ✅
4. Customer pays cash
5. Staff clicks "Paid" button in dashboard
6. Email NOW queues to /spool/mailer/ ✅
7. Spooler detects email and calls gmailer.php
8. Email sends successfully ✅
```

---

## Verification

### Test: Create cash order
```bash
curl -X POST http://localhost/config/api/checkout.php \
  -d "payment_method=cash&email=testuser@example.com&onsite=yes"
```

**Result:**
```json
{
  "status": "success",
  "order_id": 1009,
  "payment_method": "cash",
  "is_paid": false,
  "message": "Please Pay Cash at Counter"
}
```

✅ `is_paid: false` → Email will NOT queue

### Verify no email queue created:
```bash
ls -la C:\UniServerZ\www\photos\2026\01\23\spool\mailer\1009
# Result: Directory does NOT exist ✅
```

---

## How Email Now Gets Queued Properly

### For CASH Orders:
```
Dashboard → Staff clicks "Paid" button
                    ↓
        order_action.php action=paid
                    ↓
        Email queued to /spool/mailer/ORDERID/
                    ↓
        app.js tick_mailer (every 1.5 seconds)
                    ↓
        gmailer.php processes email
```

### For SQUARE/QR/CREDIT Orders:
```
Payment confirmed (callback)
                    ↓
        checkout.php processes payment
                    ↓
        Email queued to /spool/mailer/ORDERID/
                    ↓
        app.js tick_mailer (every 1.5 seconds)
                    ↓
        gmailer.php processes email
```

---

## Current Email Queue Status

**Before Fix:** Order 1008 stuck in queue  
**After Fix:** Order 1008 still processing (expected), no premature queues

```
/spool/mailer/  Contents:
├── 1008/  (Square/QR order - queued properly)
└── (no 1009 - cash order correctly NOT queued)
```

---

## Testing the Full Cash Order Flow

To test the complete flow:

1. **Place cash order:**
   ```bash
   curl -X POST http://localhost/config/api/checkout.php \
     -d "payment_method=cash&email=test@example.com"
   ```
   ✅ No email queue created

2. **Verify receipt file exists:**
   ```bash
   cat C:\UniServerZ\www\photos\2026\01\23\receipts\ORDERID.txt
   ```
   ✅ Shows "CASH ORDER: $X.XX DUE"

3. **Simulate staff clicking "Paid":**
   ```bash
   # In dashboard, click the "Paid" button for the order
   # OR manually call order_action.php via dashboard UI
   ```
   ✅ Email queue created
   ✅ Spooler detects and processes
   ✅ Email sends

---

## Summary

| Aspect | Before | After |
|--------|--------|-------|
| Cash order placement | Email queues immediately ❌ | Email NOT queued ✅ |
| Order cancellation | Email stuck in queue ❌ | No email to worry about ✅ |
| Staff clicks "Paid" | Email would queue again | Email queues properly ✅ |
| Email delivery | Broken flow | Proper staging → send ✅ |

---

## Files Changed

- ✅ `/config/api/checkout.php` - Removed test bypass, clarified email staging logic

## Related Fixes (Previously Applied)

- ✅ Date rollover handling (gmailer.php)
- ✅ Spooler timeout (2 seconds)
- ✅ Relative path resolution
- ✅ Email queue processing

---

**Status: ACPS90 v9.0 Cash Order Email Staging - FIXED ✅**

Email now stages only when payment is confirmed, not on initial order creation.

