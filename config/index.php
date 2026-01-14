<?php
// Gemicunt Master Control Console
// Independent View - /config/index.php
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ACPS Master Control</title>
    <link rel="stylesheet" href="assets/css/style.css?v=<?php echo time(); ?>">
    <link rel="icon" href="/favicon.ico">
</head>
<body>

    <header class="app-header">
        <div class="logo-area">
            <img src="/public/assets/images/ACPS.png" alt="ACPS Logo" class="app-logo">
            <h1 class="app-title">Master Control <span class="badge badge-live">LIVE</span></h1>
        </div>
        <div class="header-controls">
            <div class="control-group">
                <span class="label">Auto Print:</span>
                <label class="switch">
                    <input type="checkbox" id="autoprint-toggle" checked>
                    <span class="slider round"></span>
                </label>
            </div>
            <div class="control-group">
                <span class="label">View:</span>
                <select id="view-filter" class="select-input">
                    <option value="due">Due Only</option>
                    <option value="paid">Paid Only</option>
                    <option value="all">All Receipts</option>
                </select>
            </div>
            <div class="control-group">
                <span class="label">Auto Refresh:</span>
                <label class="switch">
                    <input type="checkbox" id="autorefresh-toggle" checked>
                    <span class="slider round"></span>
                </label>
                <div id="refresh-countdown" class="refresh-countdown">10</div>
            </div>
            <div id="clock" class="clock">00:00:00</div>
            <button id="refreshBtn" class="btn btn-icon" title="Refresh Now">↻</button>
        </div>
    </header>

    <main class="app-shell">
        <div class="orders-panel">
            <div class="panel-header">
                <h2>Orders <span id="view-badge" class="badge badge-fire">PENDING</span></h2>
                <div class="panel-actions">
                    <span id="status-text" class="status-text">Ready</span>
                </div>
            </div>

            <div class="date-header">
                📅 <?php echo date('F j, Y'); ?>
            </div>
            
            <div class="orders-list" id="orders-list">
                <!-- Orders injected here -->
            </div>
        </div>
    </main>

    <!-- Receipt Modal -->
    <div id="receipt-modal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 id="modal-title">Receipt View</h3>
                <button class="close-btn">&times;</button>
            </div>
            <div class="modal-body">
                <div class="receipt-wrapper">
                    <pre id="receipt-content">Loading...</pre>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary close-btn">Close</button>
                <button class="btn btn-primary" id="print-btn">Print</button>
            </div>
        </div>
    </div>

    <!-- jQuery needed for the script below -->
    <script src="/public/assets/js/jquery-3.2.1.min.js"></script>
    <script src="assets/js/app.js?v=<?php echo time(); ?>"></script>
</body>
</html>
