# Sales Tracking Flow - Fixed

## Event Flow & CSV Updates

### CASH ORDER (DUE - No Payment)
```
1. Customer places order → pay.php
2. Clicks "Cash" button → config/api/checkout.php
3. Order created with status: "CASH ORDER: $XX.XX DUE"
4. Receipt written to: /photos/YYYY/MM/DD/receipts/ORDERID.txt
5. NO CSV update (order not paid yet)
6. Status: PENDING (awaiting cash payment)
```

### CASH ORDER (PAID - Staff Clicks Dashboard)
```
1. Staff clicks "Paid" button in dashboard
2. Calls: config/api/order_action.php?action=paid&order=ORDERID&payment_method=cash
3. Receipt updated: "CASH ORDER: $XX.XX PAID"
4. ✅ Print files queued to spooler (/photos/YYYY/MM/DD/spool/printer/ORDERID/)
5. ✅ Email photos queued to spooler (/photos/YYYY/MM/DD/spool/mailer/ORDERID/)
6. ✅ CSV UPDATED: sales/transactions.csv (Cash line incremented)
7. ✅ REMOTE SYNC: GET to https://alleycatphoto.net/admin/index.php?action=log&...
```

### SQUARE/QR PAYMENT (Already Paid)
```
1. Customer places order → pay.php
2. QR code OR Square Terminal processes payment
3. Callback/Response confirms payment
4. Checkout endpoint called: config/api/checkout.php?payment_method=square OR qr
5. Order created with status: "SQUARE ORDER: $XX.XX PAID"
6. Receipt written to: /photos/YYYY/MM/DD/receipts/ORDERID.txt
7. ✅ Email photos queued to spooler (NOT print files - that needs dashboard click)
8. ✅ CSV UPDATED: sales/transactions.csv (Credit line incremented)
9. ✅ REMOTE SYNC: GET to https://alleycatphoto.net/admin/index.php?action=log&...
10. Response shown: "Order Processed"
```

### TERMINAL (Credit Card Swipe)
```
1. Customer places order → pay.php
2. Swipe card → eProcessingNetwork API called from checkout.php
3. Response: Approved/Declined
4. If Approved:
   - Order created with status: "SQUARE ORDER: $XX.XX PAID"
   - ✅ CSV UPDATED with "Credit" payment type
   - ✅ REMOTE SYNC triggered
5. Response shown: "Order Processed"
```

---

## CSV Update Logic (Same for All Paid Transactions)

### When CSV Gets Updated:
- ✅ Cash order: When staff clicks "Paid" in dashboard → order_action.php
- ✅ Square: When checkout.php processes with payment_method=square
- ✅ QR: When checkout.php processes with payment_method=qr
- ✅ Terminal: When checkout.php processes with payment_method=credit
- ❌ NOT when order created (only when actually PAID)

### CSV File Format:
```
Location,Order Date,Orders,Payment Type,Amount
"Zip n Slip",01/22/2026,5,Cash,$397.00
"Zip n Slip",01/22/2026,2,Credit,$65.00
```

### CSV Update Code Location:
- **checkout.php**: Lines 215-264 (for Square/QR/Credit paid immediately)
- **order_action.php**: Lines 455-490 (for Cash paid via dashboard)

### Remote Sync (Master Server):
Both endpoints call `https://alleycatphoto.net/admin/index.php?action=log` with:
```
Parameters:
- action=log
- date=YYYY-MM-DD
- location=Location Name
- type=Cash OR Credit
- count=1 (orders)
- amount=XXX.XX
```

---

## Files Fixed

### `config/api/checkout.php`
- ✅ Removed C:/orders file copying (was causing duplicate files)
- ✅ Added spooler queue for email photos (proper queue path)
- ✅ Added CSV update logic for paid transactions
- ✅ Added remote sync to alleycatphoto.net master
- ❌ Still does NOT create print files (correct - only dashboard action does this)

### `config/api/order_action.php`
- ✅ Already has CSV update logic
- ✅ Already has remote sync
- ✅ Fires when staff clicks "Paid" button

### `sales/index.php`
- Reads and displays CSV data
- Can rescan receipts to rebuild CSV if needed

### `sales/transactions.csv`
- Daily aggregate of all paid orders
- Updated in real-time as orders are paid
- Synced to master server at alleycatphoto.net

---

## Testing Checklist

### Cash Order Flow:
- [ ] Place order → "CASH ORDER: $XX DUE" in receipt
- [ ] Check sales/transactions.csv → NO entry yet (order not paid)
- [ ] Click "Paid" in dashboard
- [ ] Check sales/transactions.csv → Cash line incremented
- [ ] Check logs → GET sent to alleycatphoto.net

### Square/QR Flow:
- [ ] Place order and complete Square/QR payment
- [ ] Check sales/transactions.csv → Credit line incremented immediately
- [ ] Check logs → GET sent to alleycatphoto.net
- [ ] Email queue should be processing

### Terminal (Credit Card) Flow:
- [ ] Place order and swipe card
- [ ] Check response → Approved or Declined
- [ ] If approved, check sales/transactions.csv → Credit line incremented
- [ ] Check logs → GET sent to alleycatphoto.net

---

## Key Points

1. **CSV Only Updates on PAID**: Not on order creation
2. **Three Events Trigger Updates**:
   - `checkout.php` with payment_method=square/qr/credit (immediate)
   - `order_action.php` with action=paid (when staff clicks Paid for cash)
   - `cart_process_cash.php` (legacy, still works for QR orders)

3. **Remote Sync**: All three endpoints call same remote URL to update master server

4. **Print Files**: NEVER created in C:/orders directly
   - Only queue to spooler when "Paid" is clicked
   - Spooler detects and processes them

5. **Email Queue**: 
   - Queued when payment confirmed (checkout.php)
   - Spooler detects and calls gmailer.php

---

**Summary**: Everything now fires at the RIGHT TIME and updates the CSV properly!
