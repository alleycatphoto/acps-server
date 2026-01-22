# ?? ACPS 9.0 MASTER ARCHITECTURE

> **Status:** DRAFT (In Active Development)
> **Goal:** Complete centralization of logic into `config/api/` and elimination of root-level legacy scripts.

---

## 1. ??? The Central API Doctrine (`config/api/`)

All logic moves here. No more root-level processing scripts.

*   **`orders.php`**: (EXISTING) Fetches order lists for the Dashboard.
*   **`order_action.php`**: (EXISTING) Handles Paid/Void/Email triggers from Admin.
*   **`spooler.php`**: (ENHANCED) Handles Printer/Mailer queues, Silent Print, and Health Checks.
*   **`checkout.php`**: (**NEW**) Will replace `cart_process_send.php` and `cart_process_cash.php`. Handles the initial order creation from `pay.php`.
*   **`terminal.php`**: (EXISTING) Handles Square Terminal interactions.
*   **`google_auth.php`**: (NEW) Handles OAuth flow for GMailer/Drive.

## 2. ?? The Kill List (Legacy Retirement)

These files are deprecated and will be removed/archived:

*   `cart_process_send.php` -> Migrating logic to `api/checkout.php`.
*   `cart_process_cash.php` -> Migrating logic to `api/checkout.php`.
*   `admin/admin_cash_order_action.php`
*   `admin/admin_cash_order_log.php`
*   `admin/admin_cash_orders_json.php`
*   `admin/admin_cash_orders_api.php`

## 3. ?? The Environment Strategy (`.env`)

Every location variation MUST be handled via `.env`. No code changes between deployments.

**Current Variables:**
*   `LOCATION_NAME` ("Hawksnest")
*   `LOCATION_SLUG` ("HAWK")
*   `LOCATION_EMAIL`
*   `SQUARE_ACCESS_TOKEN`
*   `SQUARE_LOCATION_ID`
*   `USPS_...`
*   `EPN_ACCOUNT` / `EPN_RESTRICT_KEY`

**New Needs:**
*   `PRINTER_IP_FIRE` (e.g., 192.168.2.126)
*   `PRINTER_HOTFOLDER_MAIN`
*   `PRINTER_HOTFOLDER_FIRE`

## 4. ??? Checkout Flow (The New Way)

1.  **Frontend (`pay.php`)**:
    *   Customer selects Cash/Card.
    *   AJAX POST to `config/api/checkout.php`.
2.  **API (`checkout.php`)**:
    *   Validates input.
    *   Generates Order ID.
    *   Creates Receipt (`photos/YYYY/MM/DD/receipts/ORDERID.txt`).
    *   **IF PAID (Square/QR):** Triggers Spooler immediately.
    *   **IF CASH:** Marks as "Cash Pending" (DUE). Does NOT spool.
    *   Returns JSON success.
3.  **Dashboard (`config/index.php`)**:
    *   Auto-refreshes.
    *   Shows "Cash Pending".
    *   Staff takes cash -> Clicks "Cash" -> Calls `api/order_action.php`.
4.  **Action (`order_action.php`)**:
    *   Updates Receipt to PAID.
    *   Moves photos to `spool/printer` and `spool/mailer`.
    *   Logs to Sales CSV.

## 5. ??? Next Steps

1.  Create `config/api/checkout.php` by refactoring `cart_process_send.php`.
2.  Update `pay.php` to point to the new API.
3.  Delete the old root scripts.
4.  Verify `.env` coverage.

---
**Signed:** Gemicunt Daemon
