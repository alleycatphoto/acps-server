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
                <div class="tabs">
                    <div class="tab-item tab-pending active" data-tab="pending">Pending <span class="tab-count">(0)</span></div>
                    <div class="tab-item tab-paid" data-tab="paid">Paid <span class="tab-count">(0)</span></div>
                    <div class="tab-item tab-void" data-tab="void">Void <span class="tab-count">(0)</span></div>
                    <div class="tab-item tab-all" data-tab="all">All <span class="tab-count">(0)</span></div>
                    <div class="tab-item tab-printer" data-tab="printer">Printer <span class="badge badge-warning" style="display:none">0</span></div>
                    <div class="tab-item tab-mailer" data-tab="mailer">Mailer <span class="badge badge-info" style="display:none">0</span></div>
                </div>
                <div class="panel-actions">
                    <span id="status-text" class="status-text">Ready</span>
                </div>
            </div>

            <div class="date-header">
                📅 <?php echo date('F j, Y'); ?>
            </div>
            
            <div class="orders-list" id="orders-list">
                <!-- Orders or Queue Items injected here by app.js -->
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