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
    <!-- <link rel="stylesheet" href="assets/css/style.css"> -->
    <link rel="icon" href="/favicon.ico">
    <style>
        :root {
            --bg-color: #121212;
            --card-bg: #1e1e1e;
            --text-main: #e0e0e0;
            --accent-red: #ff0000;
            --accent-green: #00ff00;
            --accent-blue: #00aaff;
            --accent-yellow: #ffff00;
            --charcoal: #2c2c2c;
        }
        body {
            background-color: var(--bg-color);
            color: var(--text-main);
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            margin: 0;
            padding: 0;
            height: 100vh;
            display: flex;
            flex-direction: column;
        }
        .app-header {
            background-color: #000;
            padding: 10px 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 1px solid #333;
        }
        .logo-area { display: flex; align-items: center; gap: 15px; }
        .app-logo { height: 40px; }
        .app-title { font-size: 1.2rem; margin: 0; color: #fff; }
        .badge { padding: 2px 6px; border-radius: 4px; font-size: 0.7rem; font-weight: bold; }
        .badge-live { background-color: var(--accent-red); color: #fff; }
        
        .header-controls { display: flex; align-items: center; gap: 20px; }
        .control-group { display: flex; align-items: center; gap: 10px; font-size: 0.9rem; }
        
        /* Toggle Switch - Red Accent */
        .switch { position: relative; display: inline-block; width: 40px; height: 20px; }
        .switch input { opacity: 0; width: 0; height: 0; }
        .slider { position: absolute; cursor: pointer; top: 0; left: 0; right: 0; bottom: 0; background-color: #ccc; transition: .4s; border-radius: 20px; }
        .slider:before { position: absolute; content: ""; height: 16px; width: 16px; left: 2px; bottom: 2px; background-color: white; transition: .4s; border-radius: 50%; }
        input:checked + .slider { background-color: var(--accent-red); }
        input:checked + .slider:before { transform: translateX(20px); }
        
        .refresh-countdown { font-weight: bold; color: var(--accent-red); width: 20px; text-align: center; }
        .clock { font-family: monospace; font-size: 1.1rem; color: #888; }
        .btn-icon { background: none; border: none; color: #fff; font-size: 1.2rem; cursor: pointer; }
        
        .app-shell { flex: 1; padding: 20px; overflow: hidden; display: flex; flex-direction: column; }
        .orders-panel { background-color: var(--card-bg); border-radius: 8px; flex: 1; display: flex; flex-direction: column; overflow: hidden; }
        .panel-header { padding: 15px; border-bottom: 1px solid #333; display: flex; justify-content: space-between; align-items: center; }
        .orders-list { flex: 1; overflow-y: auto; padding: 10px; }
        
        /* Order Row Styling */
        .order-card {
            display: flex;
            flex-wrap: wrap;
            gap: 15px;
            align-items: center;
            padding: 10px;
            border-bottom: 1px solid #333;
            background-color: #252525;
            margin-bottom: 5px;
            border-radius: 4px;
        }
        .order-card:hover { background-color: #2a2a2a; }
        
        /* Grid alignment helpers for the flex items */
        .order-id { width: 80px; font-weight: bold; color: #fff; }
        .order-date { width: 140px; font-size: 0.9rem; color: #aaa; display: flex; flex-direction: column; }
        .order-name { flex: 1; min-width: 200px; color: #ddd; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .order-actions { display: flex; gap: 8px; align-items: center; }
        .order-details { width: 100%; display: none; background: #111; padding: 10px; margin-top: 10px; font-size: 0.9rem; color: #ccc; }
        .order-card.expanded .order-details { display: block; }

        /* Time Elapsed */
        .elapsed-time { font-style: italic; font-size: 0.8rem; color: #666; margin-top: 2px; }
        .elapsed-time.warn { color: var(--accent-yellow); }
        .elapsed-time.late { color: var(--accent-red); }
        
        /* Charcoal Buttons with Colored Borders */
        .btn, .action-btn {
            background-color: var(--charcoal);
            border: 2px solid #555;
            color: #fff;
            padding: 6px 12px;
            border-radius: 6px;
            cursor: pointer;
            font-weight: bold;
            font-size: 0.85rem;
            transition: all 0.2s;
            text-transform: uppercase;
        }
        .btn:hover, .action-btn:hover { background-color: #444; }
        
        .btn-cash { border-color: var(--accent-green); color: var(--accent-green); }
        .btn-square { border-color: var(--accent-blue); color: var(--accent-blue); }
        .btn-void { border-color: var(--accent-red); color: var(--accent-red); }
        .btn-receipt { border-color: var(--accent-yellow); color: var(--accent-yellow); }
        
        /* Status Pills */
        .order-type {
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 0.75rem;
            font-weight: bold;
            text-align: center;
            text-transform: uppercase;
        }
        .type-cash { background-color: rgba(255, 255, 0, 0.2); color: var(--accent-yellow); border: 1px solid var(--accent-yellow); }
        .type-paid { background-color: rgba(0, 255, 0, 0.2); color: var(--accent-green); border: 1px solid var(--accent-green); }
        .type-standard { background-color: #333; color: #aaa; border: 1px solid #555; }

        /* Modal Fix */
        .modal {
            display: none; /* Hidden by default */
            position: fixed;
            z-index: 2000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.85);
            align-items: center;
            justify-content: center;
        }
        .modal.open { display: flex; }
        .modal-content {
            background-color: var(--card-bg);
            padding: 20px;
            border-radius: 8px;
            width: 80%;
            max-width: 600px;
            border: 1px solid #444;
            color: #fff;
        }
        .modal-header { display: flex; justify-content: space-between; margin-bottom: 15px; border-bottom: 1px solid #444; padding-bottom: 10px; }
        .close-btn { background: none; border: none; color: #fff; font-size: 1.5rem; cursor: pointer; }
        
        /* Spinner */
        .spinner {
            border: 3px solid rgba(0, 170, 255, 0.3);
            border-radius: 50%;
            border-top: 3px solid var(--accent-blue);
            width: 16px;
            height: 16px;
            animation: spin 1s linear infinite;
            display: inline-block;
        }
        @keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
    </style>
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
    <script src="assets/js/app.js"></script>
</body>
</html>
