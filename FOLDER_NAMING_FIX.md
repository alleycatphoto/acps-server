# Fix Applied: Email Queue Folder Naming Issue

## Problem Identified
Order 1007 was stuck in the email queue because of a **folder path mismatch**:
- The new system (gmailer.php) was looking for: `/photos/2026/01/22/spool/mailer/1007/`
- The photos were organized by email in: `/photos/2026/01/22/emails/photos@alleycatphoto.net/`
- Additionally, gmailer.php had **undefined path variables** (`$spool_path` and `$archive_path`)

## Root Causes

### Issue 1: Undefined Path Variables
The gmailer.php script was using `$spool_path` and `$archive_path` variables without ever defining them:
```php
// BROKEN: These variables were never defined!
$preview_img = process_images($spool_path, $brandingLogoPath);
rename($spool_path, $archive_path);
```

### Issue 2: No Fallback for Legacy Folder Structure
Old orders stored email photos organized by email address, but new system uses order ID as folder name.

### Issue 3: No Fallback for Old System Format
Some very old orders might use completely different folder structures.

## Solution Implemented

Added comprehensive path detection and resolution in `gmailer.php`:

```php
// Try new spooler path first (order_action.php uses: /photos/YYYY/MM/DD/spool/mailer/ORDER_ID/)
$spool_path = $base_dir . "/photos/$date_path/spool/mailer/$order_id/";

// Fall back to old cash_email path if new path doesn't exist
if (!file_exists($info_file)) {
    $spool_path = $base_dir . "/photos/$date_path/cash_email/$order_id/";
}

// Ultimate fallback: search in /emails directory if both above fail
if (!file_exists($info_file)) {
    // Scan /emails directory and look for order ID in info.txt
}

// Parse email from info.txt (handles both new JSON and old pipe-delimited formats)
$info_data = json_decode($info_raw, true);
if (!$info_data) {
    // Try old format: email|status|...
    $parts = explode('|', $info_raw);
    $customer_email = trim($parts[0] ?? '');
}

// Archive always goes to /photos/YYYY/MM/DD/emails/ORDER_ID/ (new standard)
$archive_path = $base_dir . "/photos/$date_path/emails/$order_id/";
```

## Testing Results

### Manual Test: Order 1007
```
Command: php gmailer.php 1007
Result: SUCCESS
```

**Log Output:**
```
2026-01-22 17:40:43 | Order 1007 | GMAILER_STARTED: Script invoked with order_id=1007
2026-01-22 17:40:43 | Order 1007 | PATH_RESOLVED: spool_path=...spool/mailer/1007/, email=photos@alleycatphoto.net
2026-01-22 17:40:43 | Order 1007 | ARCHIVE_PATH: ...emails/1007/
2026-01-22 17:41:31 | Order 1007 | GMAIL_SENDING: Calling Gmail API for photos@alleycatphoto.net
2026-01-22 17:41:40 | Order 1007 | GMAIL_SUCCESS: Email sent to photos@alleycatphoto.net - moved to archive
```

**Verification:**
- ✅ Email was sent to customer (Gmail API returned success)
- ✅ Photos moved from `/spool/mailer/1007/` to `/emails/1007/`
- ✅ Archive folder contains: photos (10001.jpg, 10006.jpg, 10010.jpg) + preview_grid.jpg + info.txt
- ✅ Mailer spool queue now empty

## Files Modified

- **gmailer.php**: 
  - Added path detection logic (tries: new spooler → old cash_email → search emails dir)
  - Added fallback for both JSON and pipe-delimited info.txt formats
  - Properly defines `$spool_path`, `$archive_path`, and `$customer_email` before use
  - Added detailed logging for each step

## How This Fixes the Original Issue

**Before:**
- Order 1007 created in spooler at `/photos/2026/01/22/spool/mailer/1007/`
- gmailer.php called but failed silently because paths were undefined
- Order stayed stuck in queue, never sent

**After:**
- Order 1007 created at `/photos/2026/01/22/spool/mailer/1007/`
- Dashboard spooler `tick_mailer` detects it and calls gmailer.php
- gmailer.php finds the order folder using new path detection
- Email processed and sent successfully
- Order moved to archive at `/photos/2026/01/22/emails/1007/`
- Dashboard activity indicator shows "Email queue ready"

## Backward Compatibility

The fix handles multiple folder naming conventions:
1. **New system** (2026): `/photos/YYYY/MM/DD/spool/mailer/ORDER_ID/`
2. **Old cash_email** (legacy): `/photos/YYYY/MM/DD/cash_email/ORDER_ID/`
3. **Very old** (fallback): Searches `/photos/YYYY/MM/DD/emails/` and matches by order ID in info.txt

## Info.txt Format Support

Handles both formats:
- **New (JSON)**: `{"email":"user@example.com","order_id":"1007","timestamp":...}`
- **Old (pipe-delimited)**: `user@example.com|PAID\n...message content...`

## Next Steps

1. Monitor logs for any orders that trigger the fallback paths
2. Verify all stuck orders are now processed
3. Dashboard should show email queue properly emptying as `tick_mailer` processes remaining orders
4. Consider standardizing all old orders to new folder structure in a future cleanup task

---

**Testing Date:** January 22, 2026
**Fixed Order:** 1007
**Status:** ✅ WORKING - Email sent successfully
