<?php
// Gemicunt Debug Console - /config/debug.php
// Slutcore Dark Theme - Developer Utility
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ACPS DEBUG CONSOLE</title>
    <link rel="stylesheet" href="assets/css/style.css?v=<?php echo time(); ?>">
    <style>
        .debug-shell {
            max-width: 1000px;
            margin: 20px auto;
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            height: calc(100vh - 120px);
        }
        .control-card {
            background: var(--bg-panel);
            border: 1px solid var(--border-color);
            border-radius: var(--radius);
            padding: 20px;
            display: flex;
            flex-direction: column;
            gap: 15px;
        }
        .terminal-card {
            background: #000;
            border: 1px solid var(--border-color);
            border-radius: var(--radius);
            padding: 0;
            display: flex;
            flex-direction: column;
            overflow: hidden;
            font-family: 'Courier New', monospace;
        }
        .term-header {
            background: #222;
            padding: 8px 15px;
            font-size: 0.8rem;
            color: #aaa;
            border-bottom: 1px solid #333;
            display: flex;
            justify-content: space-between;
        }
        .term-body {
            flex: 1;
            padding: 15px;
            overflow-y: auto;
            font-size: 0.85rem;
            line-height: 1.4;
            color: #00ff41; /* Matrix Green */
        }
        .payload-editor {
            background: #111;
            color: #ffab00;
            border: 1px solid #333;
            border-radius: 4px;
            padding: 10px;
            width: 100%;
            height: 150px;
            resize: vertical;
            font-family: monospace;
            font-size: 0.8rem;
        }
        .btn-group {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
        }
        .btn {
            padding: 10px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-weight: bold;
            text-transform: uppercase;
            font-size: 0.75rem;
            transition: opacity 0.2s;
        }
        .btn:hover { opacity: 0.8; }
        .btn-cash { background: var(--success-color); color: white; }
        .btn-square { background: var(--accent-color); color: white; }
        .btn-qr { background: #6f42c1; color: white; }
        .btn-void { background: var(--danger-color); color: white; }
        .btn-secondary { background: #444; color: white; }
        
        .log-entry { margin-bottom: 8px; border-bottom: 1px solid #111; padding-bottom: 4px; }
        .log-time { color: #888; font-size: 0.7rem; }
        .log-type { font-weight: bold; margin-right: 5px; }
        .type-req { color: #007bff; }
        .type-res { color: #28a745; }
        .type-err { color: #dc3545; }
        
        .field-group { display: flex; flex-direction: column; gap: 5px; }
        label { font-size: 0.7rem; color: #888; text-transform: uppercase; }
        input { 
            background: #222; 
            border: 1px solid #333; 
            color: #fff; 
            padding: 8px; 
            border-radius: 4px; 
            outline: none;
        }
        input:focus { border-color: var(--accent-color); }

        /* Additional styles for documentation section */
        .docs-section {
            max-width: 1000px;
            margin: 0 auto 40px auto;
            padding: 0 20px;
        }
        .docs-section h2 {
            color: var(--text-muted);
            font-size: 1rem;
            margin-bottom: 15px;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        .docs-section .control-card {
            font-size: 0.8rem;
        }
        .docs-section h3 {
            color: var(--success-color);
            border-bottom: 1px solid #333;
            padding-bottom: 5px;
            margin-bottom: 10px;
        }
        .docs-section p {
            color: #888;
            margin-bottom: 10px;
        }
        .docs-section pre {
            background: #111;
            padding: 8px;
            border-radius: 4px;
            color: #ccc;
            margin: 5px 0;
        }
        .docs-section pre.result {
            background: #000;
            color: #28a745;
            border: 1px solid #222;
        }
    </style>
</head>
<body>

    <header class="app-header">
        <div class="logo-area">
            <img src="/public/assets/images/ACPS.png" alt="ACPS Logo" class="app-logo">
            <h1 class="app-title">Debug Console <span class="badge badge-fire">SYSTEM</span></h1>
        </div>
        <div class="header-controls">
            <div id="clock" class="clock">00:00:00</div>
            <a href="index.php" class="btn btn-secondary" style="text-decoration: none;">Exit Debug</a>
        </div>
    </header>

    <main class="debug-shell">
        <div class="control-card">
            <div class="field-group">
                <label>Target Order ID</label>
                <input type="text" id="order-id" placeholder="Enter Order Number (e.g. 1012)">
            </div>
            
            <div class="field-group">
                <label>Transaction ID / Reference</label>
                <input type="text" id="trans-id" value="DEBUG-<?php echo strtoupper(bin2hex(random_bytes(4))); ?>">
            </div>

            <div class="field-group">
                <label>Payload Preview (JSON)</label>
                <textarea id="payload-preview" class="payload-editor"></textarea>
            </div>

            <div class="btn-group">
                <button class="btn btn-cash" onclick="triggerAction('paid', 'cash')">Pay Cash</button>
                <button class="btn btn-square" onclick="triggerAction('paid', 'square')">Square Success</button>
                <button class="btn btn-qr" onclick="triggerAction('paid', 'qr')">QR Pay Success</button>
                <button class="btn btn-void" onclick="triggerAction('void', 'cash')">Void Order</button>
                <button class="btn btn-secondary" onclick="simulateDecline('square')" style="grid-column: span 2;">Simulate Terminal Decline</button>
            </div>
            
            <div style="margin-top: auto; font-size: 0.7rem; color: #555;">
                * This tool directly hits <code>order_action.php</code>. It bypasses actual payment gateways.
            </div>
        </div>

        <div class="terminal-card">
            <div class="term-header">
                <span>SYSTEM LOG</span>
                <span id="term-status">READY</span>
            </div>
            <div id="term-body" class="term-body">
                <div class="log-entry">
                    <span class="log-time">[<?php echo date('H:i:s'); ?>]</span>
                    <span class="log-type type-res">INIT</span>
                    Debug Console Ready. Awaiting trigger...
                </div>
            </div>
            <div class="term-header" style="border-top: 1px solid #333; border-bottom: none;">
                <button onclick="clearLog()" style="background: none; border: none; color: #888; cursor: pointer; font-size: 0.7rem;">Clear Log</button>
            </div>
        </div>
    </main>

    <section class="docs-section" style="max-width: 1000px; margin: 0 auto 40px auto; padding: 0 20px;">
        <h2 style="color: var(--text-muted); font-size: 1rem; margin-bottom: 15px; text-transform: uppercase; letter-spacing: 1px;">API Usage Reference</h2>
        
        <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 20px;">
            <!-- Cash Example -->
            <div class="control-card" style="font-size: 0.8rem;">
                <h3 style="color: var(--success-color); border-bottom: 1px solid #333; padding-bottom: 5px; margin-bottom: 10px;">CASH PAYMENT</h3>
                <p style="color: #888; margin-bottom: 10px;">Standard cash workflow. No fee adjustment.</p>
                <strong>POST Parameters:</strong>
                <pre style="background: #111; padding: 8px; border-radius: 4px; color: #ccc; margin: 5px 0;">action: "paid"
payment_method: "cash"
order: "1016"</pre>
                <strong>Receipt Result:</strong>
                <pre class="result" style="background: #000; padding: 8px; border-radius: 4px; color: #28a745; margin: 5px 0; border: 1px solid #222;">CASH ORDER: $16.54 PAID
...</pre>
            </div>

            <!-- QR Example -->
            <div class="control-card" style="font-size: 0.8rem;">
                <h3 style="color: #6f42c1; border-bottom: 1px solid #333; padding-bottom: 5px; margin-bottom: 10px;">QR PAY (VENMO/CASHAPP)</h3>
                <p style="color: #888; margin-bottom: 10px;">Mobile payment via QR. Uses reference ID.</p>
                <strong>POST Parameters:</strong>
                <pre style="background: #111; padding: 8px; border-radius: 4px; color: #ccc; margin: 5px 0;">action: "paid"
payment_method: "qr"
order: "1016"
transaction_id: "VMO-9921X"</pre>
                <strong>Receipt Result:</strong>
                <pre class="result" style="background: #000; padding: 8px; border-radius: 4px; color: #6f42c1; margin: 5px 0; border: 1px solid #222;">QR PAY: $16.54 PAID
...
QR CONFIRMATION: VMO-9921X</pre>
            </div>

            <!-- Square Example -->
            <div class="control-card" style="font-size: 0.8rem;">
                <h3 style="color: var(--accent-color); border-bottom: 1px solid #333; padding-bottom: 5px; margin-bottom: 10px;">SQUARE TERMINAL</h3>
                <p style="color: #888; margin-bottom: 10px;">Terminal transaction. Applies 3.5% fee + 6.75% tax.</p>
                <strong>POST Parameters:</strong>
                <pre style="background: #111; padding: 8px; border-radius: 4px; color: #ccc; margin: 5px 0;">action: "paid"
payment_method: "square"
order: "1016"
transaction_id: "7VbMZL..."</pre>
                <strong>Receipt Result:</strong>
                <pre class="result" style="background: #000; padding: 8px; border-radius: 4px; color: var(--accent-color); margin: 5px 0; border: 1px solid #222;">SQUARE ORDER: $18.27 PAID
...
SQUARE CONFIRMATION: 7VbMZL...</pre>
            </div>
        </div>
    </section>

    <script>
        const elements = {
            orderId: document.getElementById('order-id'),
            transId: document.getElementById('trans-id'),
            payload: document.getElementById('payload-preview'),
            term: document.getElementById('term-body'),
            status: document.getElementById('term-status')
        };

        function updateClock() {
            document.getElementById('clock').textContent = new Date().toLocaleTimeString('en-US', { hour12: false });
        }
        setInterval(updateClock, 1000);
        updateClock();

        function updatePayloadPreview() {
            const data = {
                order: elements.orderId.value,
                action: 'paid',
                payment_method: 'cash',
                transaction_id: elements.transId.value,
                autoprint: '1'
            };
            elements.payload.value = JSON.stringify(data, null, 2);
        }

        elements.orderId.addEventListener('input', updatePayloadPreview);
        elements.transId.addEventListener('input', updatePayloadPreview);
        updatePayloadPreview();

        function log(type, msg, raw = null) {
            const entry = document.createElement('div');
            entry.className = 'log-entry';
            const time = new Date().toLocaleTimeString('en-US', { hour12: false });
            
            let typeClass = 'type-req';
            if (type === 'RES') typeClass = 'type-res';
            if (type === 'ERR') typeClass = 'type-err';

            let html = `<span class="log-time">[${time}]</span> <span class="log-type ${typeClass}">${type}</span> ${msg}`;
            if (raw) {
                html += `<pre style="font-size: 0.7rem; color: #888; margin-top: 5px; white-space: pre-wrap;">${JSON.stringify(raw, null, 2)}</pre>`;
            }
            
            entry.innerHTML = html;
            elements.term.appendChild(entry);
            elements.term.scrollTop = elements.term.scrollHeight;
        }

        function clearLog() {
            elements.term.innerHTML = '';
            log('INIT', 'Log cleared.');
        }

        function simulateDecline(method) {
            const orderId = elements.orderId.value || 'UNKNOWN';
            log('REQ', `Simulating ${method} Decline for Order #${orderId}`);
            
            elements.status.textContent = 'DECLINED';
            elements.status.style.color = 'var(--danger-color)';
            
            setTimeout(() => {
                log('ERR', `Terminal response: FAILED. Transaction for Order #${orderId} was declined by the bank or cancelled.`, {
                    status: 'error',
                    terminal_status: 'FAILED',
                    message: 'Transaction declined'
                });
            }, 800);
        }

        async function triggerAction(action, method) {
            const orderId = elements.orderId.value;
            if (!orderId) {
                alert('Please enter an Order ID first.');
                return;
            }

            // Try to parse payload from editor if modified
            let data;
            try {
                data = JSON.parse(elements.payload.value);
                // Override with button specific values
                data.action = action;
                data.payment_method = method;
                if (!data.transaction_id) data.transaction_id = elements.transId.value;
            } catch (e) {
                log('ERR', 'Invalid JSON in payload editor. Reverting to defaults.');
                data = {
                    order: orderId,
                    action: action,
                    payment_method: method,
                    transaction_id: elements.transId.value,
                    autoprint: '1'
                };
            }

            elements.status.textContent = 'PENDING...';
            elements.status.style.color = 'var(--warning-color)';
            
            log('REQ', `Sending ${action} via ${method} for Order #${orderId}`, data);

            try {
                const formData = new FormData();
                for (const key in data) {
                    formData.append(key, data[key]);
                }

                const response = await fetch('api/order_action.php', {
                    method: 'POST',
                    body: formData
                });
                
                const result = await response.json();
                
                if (result.status === 'success') {
                    log('RES', `Success: ${result.message}`, result);
                    elements.status.textContent = 'SUCCESS';
                    elements.status.style.color = 'var(--success-color)';
                } else {
                    log('ERR', `Failed: ${result.message}`, result);
                    elements.status.textContent = 'FAILED';
                    elements.status.style.color = 'var(--danger-color)';
                }
            } catch (err) {
                log('ERR', 'Network or system error occurred.', err);
                elements.status.textContent = 'NET ERROR';
                elements.status.style.color = 'var(--danger-color)';
            }
        }
    </script>
</body>
</html>
