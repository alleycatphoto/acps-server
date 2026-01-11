<?php
//*********************************************************************//
//       _____  .__  .__                 _________         __          //
//      /  _  \ |  | |  |   ____ ___.__. \_   ___ \_____ _/  |_        //
//     /  /_\  \|  | |  | _/ __ <   |  | /    \  \/\__  \\   __\       //
//    /    |    \  |_|  |_\  ___/\___  | \     \____/ __ \|  |         //
//    \____|__  /____/____/\___  > ____|  \______  (____  /__|         //
//            \/               \/\/              \/     \/             //
// *********************** INFORMATION ********************************//
// AlleyCat PhotoStation v3.0.1                                        //
// Author: Paul K. Smith (photos@alleycatphoto.net)                    //
// Date: 09/25/2025                                                    //
// Last Revision 09/25/2025  (PKS)                                     //
// ------------------------------------------------------------------- //
//*********************************************************************//

require_once("config.php");

// --- Pending Cash Orders Scan (today only) --------------------------
$pendingCashOrders = [];
$cashScanDebug     = [];
// --- Load Auto Print Status --------------------------
$autoprintStatusPath = realpath(__DIR__ . "/../config/autoprint_status.txt");
$initialAutoPrint = '1'; // Default to ON

if ($autoprintStatusPath !== false && file_exists($autoprintStatusPath)) {
    $content = @file_get_contents($autoprintStatusPath);
    if ($content !== false) {
        $initialAutoPrint = trim($content) === '0' ? '0' : '1';
    }
}
// -----------------------------------------------------

$pendingCashCount = count($pendingCashOrders);
try {
    $baseDir = realpath(__DIR__ . "/../photos");

    if ($baseDir === false) {
        $cashScanDebug[] = "ERROR: Could not resolve baseDir.";
    } else {
        $date_path   = date('Y/m/d');
        $receiptsDir = rtrim($baseDir, '/').'/'.$date_path.'/receipts';

        $cashScanDebug[] = "Scanning receipts for pending cash orders...";
        $cashScanDebug[] = "Base dir: " . $baseDir;
        $cashScanDebug[] = "Date path: " . $date_path;
        $cashScanDebug[] = "Receipts dir: " . $receiptsDir;

        if (!is_dir($receiptsDir)) {
            $cashScanDebug[] = "!!! Directory does not exist.";
        } else {
            $files = glob($receiptsDir.'/*.txt') ?: [];
            $cashScanDebug[] = "Found " . count($files) . " .txt files.";

            foreach ($files as $receiptFile) {
                $cashScanDebug[] = "--- Checking file: " . basename($receiptFile);
                $raw = @file_get_contents($receiptFile);
                if ($raw === false || trim($raw) === '') {
                    $cashScanDebug[] = "    Could not read or file empty, skipping.";
                    continue;
                }

                $lines = preg_split('/\r\n|\r|\n/', $raw);

                // 1) Look for a CASH ORDER line that ends with DUE
                $isCash        = false;
                $amount        = 0.0;
                $cashLineDebug = '';

                foreach ($lines as $line) {
                    $lineTrim = trim($line);

                    if (stripos($lineTrim, 'CASH ORDER:') !== false) {
                        $cashLineDebug = $lineTrim;
                    }

                    if (preg_match('/^CASH ORDER:\s*\$([0-9]+(?:\.[0-9]{2})?)\s+DUE\s*$/i', $lineTrim, $m)) {
                        $isCash = true;
                        $amount = (float)$m[1];
                        break;
                    }
                }

                if (!$isCash) {
                    if ($cashLineDebug !== '') {
                        $cashScanDebug[] = "    Found CASH ORDER line but not DUE: \"{$cashLineDebug}\"";
                    } else {
                        $cashScanDebug[] = "    No CASH ORDER: ... DUE line, skipping.";
                    }
                    continue;
                }

                // 2) Pull out order number, date, and label
                $orderId   = null;
                $orderDate = '';
                $label     = '';

                foreach ($lines as $line) {
                    $trim = trim($line);

                    if ($orderId === null && preg_match('/^Order (Number|#):\s*(\d+)/i', $trim, $m)) {
                        // handles "Order #: 1000" and "Order Number: 1002"
                        $orderId = $m[2];
                    }

                    if ($orderDate === '' && preg_match('/^Order Date:\s*(.+)$/i', $trim, $m)) {
                        $orderDate = trim($m[1]);
                    }

                    if ($label === '' && strpos($trim, '@') !== false) {
                        $label = $trim;
                    }
                }

                if ($orderId === null) {
                    $orderId = pathinfo($receiptFile, PATHINFO_FILENAME);
                    $cashScanDebug[] = "    Order ID not found in text, using filename: {$orderId}";
                }

                $cashScanDebug[] = sprintf(
                    "    -> PENDING CASH: order %s, amount %0.2f, label \"%s\", date \"%s\"",
                    $orderId,
                    $amount,
                    $label,
                    $orderDate
                );

                $pendingCashOrders[] = [
                    'id'    => (int)$orderId,
                    'name'  => $label,
                    'total' => $amount,
                    'date'  => $orderDate,
                ];
            }

            $cashScanDebug[] = "Total pending cash orders: " . count($pendingCashOrders);

            usort($pendingCashOrders, function ($a, $b) {
                return $a['id'] <=> $b['id'];
            });
        }
    }
} catch (Throwable $e) {
    $cashScanDebug[]   = "Exception while scanning: " . $e->getMessage();
    $pendingCashOrders = [];
}
$cashScanDebug = [];
$pendingCashCount = count($pendingCashOrders);

// $timestamp should come from config.php or earlier in your bootstrap
$token = md5('unique_salt' . $timestamp);
?>
<!DOCTYPE html 
  PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml">

<head>
  <meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
  <title><?php echo htmlspecialchars($locationName); ?> PhotoStation Administration : Manual Import</title>
  <link rel="stylesheet" href="/public/assets/importer/css/bootstrap.min.css">
  <link href="/public/assets/importer/css/jquery.dm-uploader.css" rel="stylesheet">
  <link href="/public/assets/importer/css/styles.css" rel="stylesheet">
  <style>
  #openProcessOrderModal {
    padding: 10px 16px;
    border-radius: 10px;
    border: 1px solid #444;
    background: #696969;
    color: #fff;
    cursor: pointer;
    box-shadow: inset 0 0 5px rgba(0,0,0,.5);
  }
  #openProcessOrderModal:hover { background:#7a7a7a; }

  .cemodal { position: fixed; inset: 0; display: none; }
  .cemodal.is-open { display: block; }
  .cemodal__backdrop { position:absolute; inset:0; background:rgba(0,0,0,.6); }
  .cemodal__dialog {
    position: relative;
    margin: 8vh auto 0;
    width: min(520px, 92vw);
    background: #111;
    color: #eee;
    border-radius: 14px;
    box-shadow: 0 10px 30px rgba(0,0,0,.5);
    padding: 20px;
    border: 1px solid #333;
  }
  .cemodal__close {
    position:absolute; right: 10px; top: 8px;
    background: transparent; color: #bbb; border: 0; font-size: 28px; cursor: pointer;
  }
  .cemodal__close:hover { color: #fff; }
  #orderInput {
    width: 100%;
    padding: 10px 12px;
    border-radius: 10px;
    border: 1px solid #444;
    background: #000;
    color: #fff;
    box-shadow: inset 0 0 5px rgba(0,0,0,.5);
    font-size: 16px;
  }
  .cemodal__hint { margin: 6px 0 14px; font-size: 12px; color: #aaa; }
  .cemodal__actions { display: flex; gap: 10px; align-items: center; }
  .cemodal__actions button {
    padding: 10px 14px; border-radius: 10px; border: 1px solid #444;
    background: #b22222; color: #fff; cursor: pointer;
  }
  .cemodal__actions button.secondary { background: #333; }
  .cemodal__actions button[disabled] { opacity: .7; cursor: wait; }
  .cemodal__status { margin-top: 12px; min-height: 1.2em; font-size: 14px; }
  .cemodal__status.success { color: #5cd65c; }
  .cemodal__status.error { color: #ff6b6b; }

  .btn-like {
    display: inline-block;
    padding: 10px 16px;
    border-radius: 10px;
    border: 1px solid #444;
    background: #696969;
    color: #fff;
    text-decoration: none;
    cursor: pointer;
    box-shadow: inset 0 0 5px rgba(0,0,0,.5);
  }
  .btn-like:hover { background: #7a7a7a; }

  /* Cash orders widget base */
  #cash-orders-widget {
    margin: 20px 0 30px;
    background: #111;
    border-radius: 10px;
    border: 1px solid #333;
    box-shadow: 0 10px 25px rgba(0,0,0,.45);
    color: #eee;
  }
  #cash-orders-widget .card-header {
    background: #151515;
    color: #f5f5f5;
    font-weight: 600;
    font-size: 14px;
    padding: 8px 12px;
    border-bottom: 1px solid #333;
  }
  #cashOrdersTable {
    margin-bottom: 0;
  }
  #cashOrdersTable th,
  #cashOrdersTable td {
    vertical-align: middle;
    font-size: 13px;
    padding-top: 6px;
    padding-bottom: 6px;
  }
  .cash-order-actions button {
    margin-right: 4px;
    padding: 4px 8px;
    font-size: 11px;
    line-height: 1.2;
    border-radius: 6px;
    border: 1px solid #444;
    background: #444;
    color: #fff;
    cursor: pointer;
  }
  .cash-order-actions button:last-child {
    margin-right: 0;
  }
  .cash-order-actions button[data-action="paid"] {
    background: #237b36;
    border-color: #1c5f2a;
  }
  .cash-order-actions button[data-action="void"] {
    background: #8b1a1a;
    border-color: #5f1212;
  }
  .cash-order-actions button[disabled] {
    opacity: .6;
    cursor: wait;
  }
  #cashOrdersPager {
    padding: 6px 10px 10px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    font-size: 12px;
  }
  #cashOrdersPager button {
    padding: 4px 10px;
    border-radius: 8px;
    border: 1px solid #444;
    background: #333;
    color: #eee;
    cursor: pointer;
  }
  #cashOrdersPager button[disabled] {
    opacity: .4;
    cursor: default;
  }
  #cashOrdersStatus {
    font-size: 12px;
    padding: 4px 10px 8px;
    min-height: 18px;
    color: #ccc;
  }
  #cashOrdersStatus.success { color: #5cd65c; }
  #cashOrdersStatus.error  { color: #ff6b6b; }

  /* Header layout / collapse + controls */
  .cash-header-bar {
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:12px;
    font-size:13px;
  }
  .cash-header-left {
    display:flex;
    align-items:center;
    gap:8px;
    cursor:pointer;
  }
  .cash-header-title {
    font-weight:600;
  }
  .cash-count-badge {
    display:inline-flex;
    align-items:center;
    justify-content:center;
    min-width:26px;
    padding:2px 8px;
    border-radius:16px;
    background:linear-gradient(135deg,#2a2a2a,#101010);
    border:1px solid #444;
    font-size:11px;
    color:#d6ffd6;
  }
  .cash-toggle-icon {
    font-size:16px;
    margin-left:4px;
    color:#bbb;
  }
  .cash-header-actions {
    display:flex;
    align-items:center;
    gap:10px;
    font-size:11px;
  }

  /* Auto Print pill */
  .auto-print-wrap {
    display:flex;
    align-items:center;
    gap:6px;
    color:#aaa;
  }
  .auto-print-label {
    text-transform:uppercase;
    letter-spacing:.09em;
    font-size:10px;
  }
  #autoPrintToggle {
    position:relative;
    width:66px;
    height:24px;
    border-radius:999px;
    border:1px solid #4a4a4a;
    background:radial-gradient(circle at 20% 0%,#2b2b2b,#101010);
    box-shadow:inset 0 0 0 1px rgba(0,0,0,.8);
    cursor:pointer;
    padding:0;
    outline:none;
    display:inline-flex;
    align-items:center;
    justify-content:flex-start;
    transition:background .18s ease,border-color .18s ease,box-shadow .18s ease;
  }
  #autoPrintToggle .auto-print-knob {
    position:absolute;
    top:3px;
    left:3px;
    width:18px;
    height:18px;
    border-radius:999px;
    background:linear-gradient(145deg,#f8f8f8,#b5b5b5);
    box-shadow:0 1px 2px rgba(0,0,0,.85);
    transition:transform .18s ease;
  }
  #autoPrintToggle .auto-print-text {
    width:100%;
    display:flex;
    justify-content:space-between;
    padding:0 9px 0 20px;
    font-size:9px;
    font-weight:600;
    text-transform:uppercase;
  }
  #autoPrintToggle .auto-print-on {
    color:#3ba85c;
    opacity:.2;
  }
  #autoPrintToggle .auto-print-off {
    color:#d35454;
    opacity:.9;
  }
  #autoPrintToggle.is-on {
    border-color:#1b6b3a;
    background:radial-gradient(circle at 10% 0%,#1f402c,#050b06);
    box-shadow:0 0 0 1px rgba(55,189,108,.35),0 0 12px rgba(55,189,108,.45);
  }
  #autoPrintToggle.is-on .auto-print-knob {
    transform:translateX(38px);
  }
  #autoPrintToggle.is-on .auto-print-on { opacity:.95; }
  #autoPrintToggle.is-on .auto-print-off { opacity:.2; }

  /* Refresh spinner + countdown */
  .refresh-wrap {
    display:flex;
    align-items:center;
    gap:4px;
  }
  #cashRefreshBtn {
    position:relative;
    width:28px;
    height:28px;
    border-radius:999px;
    border:1px solid #444;
    background:radial-gradient(circle at 30% 0%,#252525,#050505);
    cursor:pointer;
    padding:0;
    display:flex;
    align-items:center;
    justify-content:center;
    overflow:hidden;
  }
  #cashRefreshBtn::before {
    content:"";
    position:absolute;
    inset:3px;
    border-radius:50%;
    border:2px solid rgba(120,200,255,.55);
    border-top-color:transparent;
    border-left-color:transparent;
    animation:cash-spin 1s linear infinite;
  }
  #cashRefreshBtn.is-paused::before {
    animation-play-state:paused;
    opacity:.25;
  }
  #refreshCountdown {
    position:relative;
    z-index:1;
    font-size:10px;
    color:#d0eaff;
    text-shadow:0 0 4px rgba(0,0,0,.9);
  }
  @keyframes cash-spin {
    to { transform:rotate(360deg); }
  }

  /* Log button */
  .cash-log-btn {
    border-radius:999px;
    border:1px solid #444;
    background:#191919;
    color:#e7e7e7;
    padding:4px 10px;
    font-size:11px;
    display:flex;
    align-items:center;
    gap:6px;
    cursor:pointer;
  }
  .cash-log-btn span.icon { font-size:13px; }
  .cash-log-btn:hover {
    background:#242424;
    border-color:#4f4f4f;
  }

  /* Log modal */
  #cashLogModal {
    position:fixed;
    inset:0;
    display:none;
    z-index:1080;
  }
  #cashLogModal.is-open { display:block; }
  .cashlog-backdrop {
    position:absolute;
    inset:0;
    background:rgba(0,0,0,.7);
  }
  .cashlog-dialog {
    position:relative;
    margin:7vh auto 0;
    width:min(900px,96vw);
    max-height:80vh;
    background:#050505;
    border-radius:12px;
    border:1px solid #333;
    box-shadow:0 20px 45px rgba(0,0,0,.9);
    padding:14px 16px 12px;
    color:#d5d5d5;
    font-family:SFMono-Regular,Menlo,Monaco,Consolas,"Liberation Mono","Courier New",monospace;
    font-size:11px;
  }
  .cashlog-header {
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:10px;
    padding-bottom:6px;
    border-bottom:1px solid #222;
  }
  .cashlog-header-title {
    font-size:12px;
    letter-spacing:.08em;
    text-transform:uppercase;
    color:#9fe79f;
  }
  .cashlog-close {
    border:0;
    background:transparent;
    color:#aaa;
    font-size:18px;
    cursor:pointer;
  }
  .cashlog-close:hover { color:#fff; }
  .cashlog-body {
    margin-top:8px;
    max-height:64vh;
    overflow:auto;
    background:#000;
    padding:8px 10px;
    border-radius:8px;
    border:1px solid #202020;
  }
  .cashlog-line {
    white-space:pre;
    padding:1px 0;
  }
  .cashlog-line.cash-paid        { color:#8aff8a; }
  .cashlog-line.cash-void        { color:#ff8787; }
  .cashlog-line.cash-email-ok    { color:#79dfff; }
  .cashlog-line.cash-email-error { color:#ffc184; }
  .cashlog-statusbar {
    margin-top:6px;
    font-size:10px;
    color:#888;
  }
  </style>

</head>

<body>

  <main role="main" class="container">

    <div align="center">
      <p><img src="/public/assets/images/alley_admin_header.png" width="550" height="169" alt="Administration Header"
          style="zoom: .70;" /><br />
        MANUAL IMPORT - <a href="/admin/admin_categories.php">CATEGORY MANAGEMENT</a> <br/> <br/>

      </p>

      <div id="cash-orders-widget" class="card text-left">
        <div class="card-header cash-orders-toggle">
          <div class="cash-header-bar">
            <div class="cash-header-left" id="cashHeaderClickRegion">
              <span class="cash-header-title">💵 Pending Cash Orders</span>
              <span id="cashOrdersCount" class="cash-count-badge">
                <?php echo (int)$pendingCashCount; ?>
              </span>
              <span id="cashOrdersToggleIcon" class="cash-toggle-icon" aria-hidden="true">+</span>
            </div>

            <div class="cash-header-actions">
              <div class="auto-print-wrap">
                <span class="auto-print-label">Auto Print</span>
                <button type="button"
                        id="autoPrintToggle"
                        class="auto-print-toggle"
                        aria-pressed="false">
                  <span class="auto-print-knob"></span>
                  <span class="auto-print-text">
                    <span class="auto-print-off">OFF</span>
                    <span class="auto-print-on">ON</span>
                  </span>
                </button>
              </div>

              <div class="refresh-wrap">
                <button type="button"
                        id="cashRefreshBtn"
                        title="Toggle auto refresh">
                  <span id="refreshCountdown">60</span>
                </button>
              </div>

              <button type="button"
                      id="cashLogBtn"
                      class="cash-log-btn"
                      title="View cash order log">
                <span class="icon">⌘</span>
                <span>Log</span>
              </button>
            </div>
          </div>
        </div>

        <div id="cashOrdersPanel" style="display:none;">
          <div class="table-responsive">
            <table id="cashOrdersTable" class="table table-dark table-striped table-sm mb-0">
              <thead>
                <tr>
                  <th scope="col">Order #</th>
                  <th scope="col">Name</th>
                  <th scope="col">Total</th>
                  <th scope="col">Date</th>
                  <th scope="col">Type</th>
                  <th scope="col" style="width: 160px;">Actions</th>
                </tr>
              </thead>
              <tbody id="cashOrdersBody">
                <tr>
                  <td colspan="6" class="text-center text-muted">
                    <?php echo $pendingCashCount ? 'Loading pending cash orders…' : 'No pending cash orders.'; ?>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
          <div id="cashOrdersPager">
            <button type="button" id="cashPrevPage">&laquo; Prev</button>
            <span id="cashOrdersPageLabel">Page 1 / 1</span>
            <button type="button" id="cashNextPage">Next &raquo;</button>
          </div>
          <div id="cashOrdersStatus" aria-live="polite"></div>
        </div>
      </div>

      <?php if (!empty($cashScanDebug)): ?>
      <details style="margin-top:8px;font-size:11px;color:#aaa;background:#111;border-radius:6px;border:1px solid #333;padding:6px 8px;">
        <summary>Cash scan debug</summary>
        <pre style="white-space:pre-wrap;margin:6px 0 0;"><?php
          echo htmlspecialchars(implode("\n", $cashScanDebug), ENT_QUOTES, 'UTF-8');
        ?></pre>
      </details>
      <?php endif; ?>

      Select the destination for your files below and click 'select files' button. <br />
      Choose the file(s) you wish to import and hit okay to begin the process. <br />
      <br />
      <font color="yellow">IMPORT MAY TAKE A FEW MINUTES PLEASE BE PATIENT</font>
      <form action="/admin/admin_import_proc.php" method="post" name="frmImport" id="frmImport">
        <input type="hidden" name="token" id="token" value="<?php echo htmlspecialchars($token); ?>" />
        <table border="0">
          <tr>
            <td align="center">
              <div id="chooser_group"><b>CHOOSE DESTINATION:</b><br />
                <select class="chooser" name="custom_target">
                  <?php foreach ($cat as $key => $value): ?>
                    <?php if (trim($value) !== ''): ?>
                      <option value="<?php echo htmlspecialchars($key); ?>">
                        <?php echo htmlspecialchars($value); ?>
                      </option>
                    <?php endif; ?>
                  <?php endforeach; ?>
                </select>
              </div>
            </td>
            <td width="50%" align="center">
              <div id="chooser_time">
                <strong>CHOOSE TIME:</strong><br />
                <select class="chooser" name="selTime" id="selTime">
                  <option value="8:00:01"  <?php if (date('H:i') >= '08:00' && date('H:i') <= '09:59') echo 'selected'; ?>>08:00AM - 10:00AM</option>
                  <option value="10:00:01" <?php if (date('H:i') >= '10:00' && date('H:i') <= '11:59') echo 'selected'; ?>>10:00AM - 12:00PM</option>
                  <option value="12:00:01" <?php if (date('H:i') >= '12:00' && date('H:i') <= '13:59') echo 'selected'; ?>>12:00PM - 02:00PM</option>
                  <option value="14:00:01" <?php if (date('H:i') >= '14:00' && date('H:i') <= '15:59') echo 'selected'; ?>>02:00PM - 04:00PM</option>
                  <option value="16:00:01" <?php if (date('H:i') >= '16:00' && date('H:i') <= '17:59') echo 'selected'; ?>>04:00PM - 06:00PM</option>
                  <option value="18:00:01" <?php if (date('H:i') >= '18:00' && date('H:i') <= '19:59') echo 'selected'; ?>>06:00PM - 08:00PM</option>
                  <option value="20:00:01" <?php if (date('H:i') >= '20:00' && date('H:i') <= '21:59') echo 'selected'; ?>>08:00PM - 10:00PM</option>
                </select>
              </div>
            </td>
          </tr>
        </table>
      </form>

      <div class="row">
        <div class="col-md-6 col-sm-12">

          <div id="drag-and-drop-zone" class="dm-uploader p-5">
            <h3 class="mb-5 mt-5 text-muted">Drag &amp; drop files here </h3>

            <div class="btn btn-primary btn-block mb-5">
              <span>OPEN THE FILE BROWSER</span>
              <input type="file" title="Click to add Files" />
              <input type="hidden" id="token" value="<?php echo htmlspecialchars($token); ?>" />
              <input type="hidden" id="timestamp" value="<?php echo htmlspecialchars($timestamp); ?>" />
            </div>
            <p id="process-finished-text" style="color: green; text-align: center;"></p>
          </div></div>
        <div class="col-md-6 col-sm-12">
          <div class="card h-100">
            <div class="card-header">
              File List
            </div>

            <ul class="list-unstyled p-2 d-flex flex-column col" id="files">
              <li class="text-muted text-center empty">No files uploaded.</li>
            </ul>
          </div>
        </div>
      </div>
      <div class="row">
        <div class="col-12">
          <div class="card h-100">
            <div class="card-header">
              Debug Messages
            </div>

            <ul class="list-group list-group-flush" id="debug">
              <li class="list-group-item text-muted empty">Loading photo importer....</li>
            </ul>
          </div>
        </div>
      </div> <div id="process-modal" style="display:none;">
        <div class="process-container">
          <h3 style="color: grey;">Processing...</h3>
          <div class="process-bar-wrapper">
            <div class="process-bar" id="process-bar" style="background-color: green;"></div>
          </div>
          <p id="process-text" style="color: red; text-align: center;">0%</p>
        </div>
      </div>

  </main>
  <footer class="text-center">
    <p>&copy; Alley Cat &middot;
      <a href="https://www.alleycatphoto.net">alleycatphoto.net : <?php echo htmlspecialchars($locationName); ?></a>
    </p>
  </footer>

  <script src="/public/assets/importer/js/jquery-3.2.1.min.js"
    integrity="sha256-hwg4gsxgFZhOsEEamdOYGBf13FyQuiTwlAQgxVSNgt4="
    crossorigin="anonymous"></script>
  <script src="/public/assets/importer/js/bootstrap.min.js"
    integrity="sha384-a5N7Y/aK3qNeh15eJKGWxsqtnX/wWdSZSKp+81YjTmS15nvnvxKHuzaWwXHDli+4"
    crossorigin="anonymous"></script>

  <script src="/public/assets/importer/js/jquery.dm-uploader.js"></script>
  <script src="/public/assets/importer/js/main.js"></script>
  <script src="/public/assets/importer/js/ui.js"></script>
  <script src="/public/assets/importer/js/conf.js"></script>

  <script type="text/html" id="files-template">
    <li class="media">
      <div class="media-body mb-1">
        <p class="mb-2">
          <strong>%%filename%%</strong> - Status:
          <span class="text-muted">Waiting</span>
        </p>
        <div class="progress mb-2">
          <div class="progress-bar progress-bar-striped progress-bar-animated bg-primary"
            role="progressbar"
            style="width: 0%"
            aria-valuenow="0" aria-valuemin="0" aria-valuemax="100">
          </div>
        </div>
        <hr class="mt-1 mb-1" />
      </div>
    </li>
  </script>

  <script type="text/html" id="debug-template">
    <li class="list-group-item text-%%color%%">
      <strong>%%date%%</strong>: %%message%%
    </li>
  </script>

  <div id="processOrderModal" class="cemodal" aria-hidden="true">
    <div class="cemodal__backdrop" data-close></div>
    <div class="cemodal__dialog" role="dialog" aria-modal="true" aria-labelledby="processModalTitle">
      <button class="cemodal__close" type="button" title="Close" data-close>&times;</button>
      <h2 id="processModalTitle">Process Cash Order</h2>

      <form id="processOrderForm" novalidate>
        <label for="processOrderInput">Order #</label>
        <input id="processOrderInput" name="order" type="text"
               autocomplete="off" placeholder="e.g. 10005" required />

        <p class="cemodal__hint">Will print the order and send email if required.</p>

        <div class="cemodal__actions">
          <button type="submit" id="processBtn">Start Process</button>
          <button type="button" class="secondary" data-close>Cancel</button>
        </div>

        <div id="processStatus" class="cemodal__status" aria-live="polite"></div>
        <div id="processSpinner" style="display:none;text-align:center;">
          <img src="/public/assets/images/loader.gif" width="80" height="80" alt="Loading..." />
        </div>
        <div id="processResult" style="margin-top:10px;max-height:220px;overflow:auto;font-size:12px;"></div>
      </form>
    </div>
  </div>

  <div id="cashLogModal" aria-hidden="true">
    <div class="cashlog-backdrop"></div>
    <div class="cashlog-dialog" role="dialog" aria-modal="true" aria-labelledby="cashLogTitle">
      <div class="cashlog-header">
        <div class="cashlog-header-title" id="cashLogTitle">
          Cash Order Event Log
        </div>
        <button type="button" class="cashlog-close" title="Close log">&times;</button>
      </div>
      <div class="cashlog-body">
        <div class="cashlog-line">Loading…</div>
      </div>
      <div class="cashlog-statusbar">
        <span>Waiting for data…</span>
      </div>
    </div>
  </div>

  <script>
  (function(){
    const btnOpen   = document.getElementById('openProcessOrderModal');
    const modal     = document.getElementById('processOrderModal');
    const form      = document.getElementById('processOrderForm');
    const input     = document.getElementById('processOrderInput');
    const statusRow = document.getElementById('processStatus');
    const resultBox = document.getElementById('processResult');
    const spinner   = document.getElementById('processSpinner');
    const processBtn= document.getElementById('processBtn');

    if (!btnOpen || !modal || !form) return;

    const open = () => {
      modal.classList.add('is-open');
      statusRow.textContent = '';
      statusRow.className   = 'cemodal__status';
      resultBox.innerHTML   = '';
      spinner.style.display = 'none';
      input.value           = '';
      setTimeout(() => input.focus(), 50);
    };
    const close = () => modal.classList.remove('is-open');

    btnOpen.addEventListener('click', e => { e.preventDefault(); open(); });
    modal.addEventListener('click', e => {
      if (e.target.matches('[data-close], .cemodal__backdrop')) close();
    });
    document.addEventListener('keydown', e => {
      if (e.key === 'Escape' && modal.classList.contains('is-open')) close();
    });

    form.addEventListener('submit', async e => {
      e.preventDefault();
      const order = input.value.trim().replace(/[^0-9]/g, '');
      if (!order) {
        statusRow.textContent = 'Enter a valid order #';
        statusRow.className   = 'cemodal__status error';
        return;
      }

      processBtn.disabled   = true;
      spinner.style.display = 'block';
      statusRow.textContent = 'Fetching receipt & starting print job…';
      statusRow.className   = 'cemodal__status';
      resultBox.innerHTML   = '';

      try {
        const printResp = await fetch('/admin/admin_print_order.php', {
          method: 'POST',
          headers: {'Content-Type':'application/x-www-form-urlencoded'},
          body: new URLSearchParams({order}).toString()
        });
        const printData = await printResp.json();

        resultBox.innerHTML = printData.receipt || '';

        if (printData.status !== 'success') {
          statusRow.textContent = printData.message || 'Print failed.';
          statusRow.className   = 'cemodal__status error';
          spinner.style.display = 'none';
          processBtn.disabled   = false;
          return;
        }

        statusRow.textContent = 'Print complete. Checking for digital delivery…';
        statusRow.className   = 'cemodal__status success';

        const hasEmail = /digital\s+email/i.test(printData.receipt || '');
        if (hasEmail) {
          statusRow.textContent = 'Digital Email found — sending mailer.php…';
          const mailerURL = `${window.location.origin}/mailer.php?order=${encodeURIComponent(order)}`;
          const mailResp  = await fetch(mailerURL, {
            method:'POST',
            headers:{'Accept':'text/plain,*/*'}
          });
          const mailRaw  = await mailResp.text();
          const mailText = mailRaw.replace(/<[^>]*>/g,'');

          if (/Message has been sent/i.test(mailText)) {
            statusRow.textContent = 'Email sent successfully.';
            statusRow.className   = 'cemodal__status success';
          } else {
            statusRow.textContent = 'Email step failed.';
            statusRow.className   = 'cemodal__status error';
          }
        } else {
          statusRow.textContent = 'No digital delivery found.';
          statusRow.className   = 'cemodal__status success';
        }

        spinner.style.display = 'none';
        setTimeout(close, 2500);

      } catch (err) {
        console.error(err);
        statusRow.textContent = 'Network or server error.';
        statusRow.className   = 'cemodal__status error';
        spinner.style.display = 'none';
      } finally {
        processBtn.disabled = false;
      }
    });
  })();
  </script>

<script>
  window.pendingCashOrders = <?php
    echo json_encode($pendingCashOrders, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
  ?>;
  window.initialAutoPrint = '<?php echo $initialAutoPrint; ?>';
</script>

  <script>
  (function(){
    const PAGE_SIZE = 5;
    const AUTO_REFRESH_SECONDS = 10;

    const bodyEl       = document.getElementById('cashOrdersBody');
    const prevBtn      = document.getElementById('cashPrevPage');
    const nextBtn      = document.getElementById('cashNextPage');
    const pageLabel    = document.getElementById('cashOrdersPageLabel');
    const statusEl     = document.getElementById('cashOrdersStatus');
    const panel        = document.getElementById('cashOrdersPanel');
    const headerClick  = document.getElementById('cashHeaderClickRegion');
    const toggleIcon   = document.getElementById('cashOrdersToggleIcon');
    const countBadgeEl = document.getElementById('cashOrdersCount');

    const autoPrintBtn = document.getElementById('autoPrintToggle');
    const refreshBtn   = document.getElementById('cashRefreshBtn');
    const countdownEl  = document.getElementById('refreshCountdown');
    const logBtn       = document.getElementById('cashLogBtn');

    const logModal     = document.getElementById('cashLogModal');
    const logBackdrop  = logModal ? logModal.querySelector('.cashlog-backdrop') : null;
    const logCloseBtn  = logModal ? logModal.querySelector('.cashlog-close') : null;
    const logBody      = logModal ? logModal.querySelector('.cashlog-body') : null;
    const logStatus    = logModal ? logModal.querySelector('.cashlog-statusbar span') : null;

    if (!bodyEl || !statusEl) return;

    // Orders from PHP
    let orders = (window.pendingCashOrders && Array.isArray(window.pendingCashOrders))
      ? window.pendingCashOrders
      : [];

    let currentPage = 1;

    // --- Collapse behaviour ---
    let panelOpen = false;

    function syncPanel(open) {
      if (!panel || !toggleIcon) return;
      panel.style.display = open ? 'block' : 'none';
      toggleIcon.textContent = open ? '–' : '+';
    }
    syncPanel(panelOpen);

    if (headerClick) {
      headerClick.addEventListener('click', function(){
        panelOpen = !panelOpen;
        syncPanel(panelOpen);
      });
    }

    // --- Auto Print state (localStorage) ---
    let autoPrintOn = true;
    const LS_KEY = 'cashAutoPrint';
    
    // 1. Initialization: Load from PHP first, fallback to localStorage
    try {
        if (window.initialAutoPrint !== undefined) {
            autoPrintOn = window.initialAutoPrint === '1';
        } else {
            const stored = window.localStorage.getItem(LS_KEY);
            if (stored === '0') autoPrintOn = false;
        }
    } catch(e) {}

    function syncAutoPrintUI(){
        if (!autoPrintBtn) return;
        if (autoPrintOn){
            autoPrintBtn.classList.add('is-on');
            autoPrintBtn.setAttribute('aria-pressed','true');
        } else {
            autoPrintBtn.classList.remove('is-on');
            autoPrintBtn.setAttribute('aria-pressed','false');
        }
    }
    syncAutoPrintUI();

    if (autoPrintBtn) {
        autoPrintBtn.addEventListener('click', async function(ev){
            ev.stopPropagation(); // don’t collapse when toggling
            autoPrintOn = !autoPrintOn;
            syncAutoPrintUI();
            
            // 2. NEW: Call the PHP setter script
            const status = autoPrintOn ? '1' : '0';
            try {
                const resp = await fetch('/admin/admin_set_autoprint.php', {
                    method: 'POST',
                    headers: {'Content-Type':'application/x-www-form-urlencoded'},
                    body: new URLSearchParams({status}).toString()
                });
                const data = await resp.json();
                if (data.status === 'success') {
                    // Update successful, log to console for confirmation
                    console.log(data.message);
                    setStatus('Auto Print: ' + (autoPrintOn ? 'ON' : 'OFF'), 'success');
                } else {
                    throw new Error(data.message || 'Update failed.');
                }
            } catch(e) {
                console.error('Auto Print toggle failed:', e);
                setStatus('Failed to save Auto Print status.', 'error');
                // Roll back UI state if save fails
                autoPrintOn = !autoPrintOn; 
                syncAutoPrintUI();
            }

            // Also keep local storage for quick local persistence
            try { window.localStorage.setItem(LS_KEY, autoPrintOn ? '1' : '0'); } catch(e){}
        });
    }
    // --- End Auto Print state ---

    // --- Paging / rendering ---
    function getPageCount(){
      return Math.max(1, Math.ceil(orders.length / PAGE_SIZE));
    }

    function updateCountBadge(){
      if (!countBadgeEl) return;
      countBadgeEl.textContent = orders.length;
    }

    function renderPage(){
      const totalPages = getPageCount();
      if (currentPage > totalPages) currentPage = totalPages;
      if (currentPage < 1) currentPage = 1;

      bodyEl.innerHTML = '';

      if (!orders.length){
        const tr = document.createElement('tr');
        tr.innerHTML = '<td colspan="5" class="text-center text-muted">No pending cash orders.</td>';
        bodyEl.appendChild(tr);
        if (pageLabel) pageLabel.textContent = 'Page 1 / 1';
        if (prevBtn) prevBtn.disabled = true;
        if (nextBtn) nextBtn.disabled = true;
        updateCountBadge();
        return;
      }

      const start = (currentPage - 1) * PAGE_SIZE;
      const end   = start + PAGE_SIZE;
      const slice = orders.slice(start, end);

      slice.forEach(order => {
        const tr = document.createElement('tr');
        tr.setAttribute('data-order-id', order.id);
        let typeLabel = '';
        if (order.payment_type === 'square') {
          typeLabel = '<span style="color:#4cf;">SQUARE</span>';
        } else {
          typeLabel = '<span style="color:#8aff8a;">CASH</span>';
        }
        let squareInfo = '';
        if (order.payment_type === 'square') {
          if (order.square_confirmation) {
            squareInfo += `<div style='font-size:10px;color:#4cf;'>CONF: ${order.square_confirmation}</div>`;
          }
          if (order.square_response) {
            squareInfo += `<div style='font-size:10px;color:#4cf;'>RESP: ${order.square_response}</div>`;
          }
        }
        tr.innerHTML = `
          <td>${order.id}</td>
          <td>${order.name || ''}</td>
          <td>$${Number(order.total || 0).toFixed(2)}</td>
          <td>${order.date || ''}</td>
          <td>${typeLabel}${squareInfo}</td>
          <td class="cash-order-actions">
            <button type="button" data-action="paid">Paid</button>
            <button type="button" data-action="void">Void</button>
          </td>
        `;
        bodyEl.appendChild(tr);
      });

      if (pageLabel) {
        pageLabel.textContent = 'Page ' + currentPage + ' / ' + totalPages;
      }
      if (prevBtn) prevBtn.disabled = currentPage === 1;
      if (nextBtn) nextBtn.disabled = currentPage === totalPages;

      updateCountBadge();
    }

    function setStatus(msg, type){
      statusEl.textContent = msg || '';
      statusEl.className = '';
      statusEl.id = 'cashOrdersStatus';
      if (type) statusEl.classList.add(type);
    }

    // --- Auto refresh spinner + countdown ---
    let autoRefreshEnabled = true;
    let refreshTimer = null;
    let refreshRemaining = AUTO_REFRESH_SECONDS;

    // NEW: Function to stop the timer
    function stopRefreshTimer() {
        clearInterval(refreshTimer);
        refreshBtn.classList.add('is-paused');
    }

    // NEW: Function to restart the timer
    function restartRefreshTimer() {
        if (autoRefreshEnabled) {
            startRefreshTimer();
        }
    }

    function syncRefreshUI(){
      if (!refreshBtn || !countdownEl) return;
      countdownEl.textContent = refreshRemaining;
      if (autoRefreshEnabled) {
        refreshBtn.classList.remove('is-paused');
      } else {
        refreshBtn.classList.add('is-paused');
      }
    }

    function startRefreshTimer(){
      clearInterval(refreshTimer);
      refreshRemaining = AUTO_REFRESH_SECONDS;
      syncRefreshUI();
      if (!autoRefreshEnabled) return;

      refreshTimer = setInterval(function(){
        refreshRemaining--;
        if (refreshRemaining <= 0){
          refreshRemaining = AUTO_REFRESH_SECONDS;
          reloadOrders();
        }
        syncRefreshUI();
      }, 1000);
    }

    if (refreshBtn) {
      refreshBtn.addEventListener('click', function(ev){
        ev.stopPropagation(); // don’t collapse header
        autoRefreshEnabled = !autoRefreshEnabled;
        if (autoRefreshEnabled){
          startRefreshTimer();
          setStatus('Auto refresh enabled.', 'success');
        } else {
          clearInterval(refreshTimer);
          setStatus('Auto refresh paused.', '');
        }
        syncRefreshUI();
      });
    }

    // Server endpoint for reloading: admin_cash_orders_api.php
    async function reloadOrders(){
      try {
        // NOTE: The original index.php doesn't have an admin_cash_orders_api.php,
        // it had admin_cash_orders_json.php. We assume admin_cash_orders_api.php is the new one.
        const resp = await fetch('/admin/admin_cash_orders_api.php', {cache:'no-store'});
        if (!resp.ok) {
          console.error('Fetch failed for /admin/admin_cash_orders_api.php:', resp.status, resp.statusText);
          throw new Error('HTTP ' + resp.status);
        }
        // NOTE: The new JS block uses data.status === 'ok', but admin_cash_orders_json.php
        // uses data.status === 'success'. Assuming 'ok' is correct for the new API.
        const text = await resp.text();
        let data;
        try {
          data = JSON.parse(text);
        } catch (e) {
          console.error('Failed to parse JSON from /admin/admin_cash_orders_api.php. Response text:', text);
          throw new Error('Invalid response format');
        }
        if (!data || data.status !== 'ok' || !Array.isArray(data.orders)) {
          throw new Error('Invalid response data');
        }
        orders = data.orders;
        currentPage = 1;
        renderPage();
        setStatus('Orders refreshed.', 'success');
      } catch (err) {
        console.error(err);
        setStatus('Auto-refresh failed: ' + err.message, 'error');
      }
    }

    // --- Paid / Void actions (includes autoprint flag for backend) ---
    function handleAction(action, orderId, button){
      if (!orderId) return;
      if (action === 'void') {
        const ok = window.confirm('Void this cash order?');
        if (!ok) return;
      }

      if (button) button.disabled = true;
      // PAUSE THE REFRESHER
      stopRefreshTimer(); 
      setStatus('Working…', '');

      const payload = new URLSearchParams({
        order: orderId,
        action: action,
        autoprint: autoPrintOn ? '1' : '0' // Pass autoPrintOn state to backend
      });

      fetch('/admin/admin_cash_order_action.php', {
        method: 'POST',
        headers: {'Content-Type':'application/x-www-form-urlencoded'},
        body: payload.toString()
      })
      .then(r => r.json())
      .then(data => {
        if (data && data.status === 'success') {
          if (action === 'paid' || action === 'void') {
            orders = orders.filter(o => String(o.id) !== String(orderId));
            renderPage();
          }
          setStatus(data.message || 'Order updated.', 'success');
        } else {
          setStatus((data && data.message) || 'Action failed.', 'error');
        }
      })
      .catch(err => {
        console.error(err);
        setStatus('Network or server error.', 'error');
      })
      .finally(() => {
          // RESTART THE REFRESHER, whether successful or failed
          restartRefreshTimer();
        if (button) button.disabled = false;
      });
    }

    if (prevBtn) {
      prevBtn.addEventListener('click', function(){
        if (currentPage > 1){
          currentPage--;
          renderPage();
        }
      });
    }

    if (nextBtn) {
      nextBtn.addEventListener('click', function(){
        if (currentPage < getPageCount()){
          currentPage++;
          renderPage();
        }
      });
    }

    if (bodyEl) {
      bodyEl.addEventListener('click', function(e){
        const btn = e.target.closest('button[data-action]');
        if (!btn) return;
        const action  = btn.getAttribute('data-action');
        const row     = btn.closest('tr');
        const orderId = row && row.getAttribute('data-order-id');
        if (action === 'paid' || action === 'void') {
          handleAction(action, orderId, btn);
        }
      });
    }

    // --- Log modal wiring ---
    function openLogModal(){
      if (!logModal) return;
      logModal.classList.add('is-open');
      document.body.style.overflow = 'hidden';
      loadLog();
    }
    function closeLogModal(){
      if (!logModal) return;
      logModal.classList.remove('is-open');
      document.body.style.overflow = '';
    }
    async function loadLog(){
      if (!logBody) return;
      logBody.innerHTML = '<div class="cashlog-line">Loading log…</div>';
      if (logStatus) logStatus.textContent = 'Fetching latest events…';

      try {
        const resp = await fetch('/admin/admin_cash_order_log.php?action=view', {cache:'no-store'});
        const text = await resp.text();
        const lines = text.split(/\r?\n/);
        logBody.innerHTML = '';

        lines.forEach(line => {
          if (!line.trim()) return;
          const div = document.createElement('div');
          div.className = 'cashlog-line';
          if (/\bPAID\b/.test(line))             div.classList.add('cash-paid');
          else if (/\bVOID\b/.test(line))        div.classList.add('cash-void');
          else if (/\bEMAIL_OK\b/.test(line))    div.classList.add('cash-email-ok');
          else if (/\bEMAIL_ERROR\b/.test(line)) div.classList.add('cash-email-error');
          div.textContent = line;
          logBody.appendChild(div);
        });

        if (logStatus) logStatus.textContent = 'Showing most recent entries.';
      } catch (err) {
        console.error(err);
        logBody.innerHTML = '<div class="cashlog-line cash-email-error">Failed to load log: ' + err.message + '</div>';
        if (logStatus) logStatus.textContent = 'Unable to read log file.';
      }
    }

    if (logBtn) {
      logBtn.addEventListener('click', function(ev){
        ev.stopPropagation();
        openLogModal();
      });
    }
    if (logBackdrop) {
      logBackdrop.addEventListener('click', closeLogModal);
    }
    if (logCloseBtn) {
      logCloseBtn.addEventListener('click', closeLogModal);
    }
    document.addEventListener('keydown', function(e){
      if (e.key === 'Escape' && logModal && logModal.classList.contains('is-open')) {
        closeLogModal();
      }
    });

    // initial render + timer
    renderPage();
    startRefreshTimer();
  })();
  </script>

</body>
</html>