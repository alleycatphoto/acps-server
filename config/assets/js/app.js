const App = {
    state: {
        orders: [],
        isLoading: false,
        lastUpdate: null,
        autoRefresh: true,
        viewMode: 'pending', // pending, paid, void, all, printer, mailer
        autoPrint: true,
        countdown: 10
    },

    elements: {
        list: null,
        clock: null,
        status: null,
        modal: null,
        modalTitle: null,
        receiptContent: null,
        refreshBtn: null,
        autoPrintToggle: null,
        autoRefreshToggle: null,
        refreshCountdown: null,
        tabs: []
    },

    init() {
        // Initialize elements after DOM is ready
        this.elements.list = document.getElementById('orders-list');
        this.elements.clock = document.getElementById('clock');
        this.elements.status = document.getElementById('status-text');
        this.elements.modal = document.getElementById('receipt-modal');
        this.elements.modalTitle = document.getElementById('modal-title');
        this.elements.receiptContent = document.getElementById('receipt-content');
        this.elements.refreshBtn = document.getElementById('refreshBtn');
        this.elements.autoPrintToggle = document.getElementById('autoprint-toggle');
        this.elements.autoRefreshToggle = document.getElementById('autorefresh-toggle');
        this.elements.refreshCountdown = document.getElementById('refresh-countdown');
        
        // Tabs
        this.elements.tabs = document.querySelectorAll('.tab-item');
        this.elements.tabs.forEach(tab => {
            tab.addEventListener('click', () => {
                this.switchTab(tab.dataset.tab);
            });
        });

        this.updateClock();
        setInterval(() => this.updateClock(), 1000);
        
        this.fetchOrders();
        this.startRefreshTimer();

        // Spooler Loop (1.5s interval)
        this.tickSpooler();
        setInterval(() => this.tickSpooler(), 1500);

        this.elements.refreshBtn.addEventListener('click', () => {
            this.state.countdown = 10; // Reset
            this.fetchOrders();
        });

        // Auto Print Toggle
        if (this.elements.autoPrintToggle) {
            this.elements.autoPrintToggle.addEventListener('change', (e) => {
                this.toggleAutoPrint(e.target.checked);
            });
        }

        // Auto Refresh Toggle
        if (this.elements.autoRefreshToggle) {
            this.elements.autoRefreshToggle.addEventListener('change', (e) => {
                this.state.autoRefresh = e.target.checked;
                if (this.state.autoRefresh) {
                    this.startRefreshTimer();
                } else {
                    this.elements.refreshCountdown.textContent = '--';
                }
            });
        }

        // Modal events
        document.querySelectorAll('.close-btn').forEach(btn => {
            btn.addEventListener('click', () => this.closeModal());
        });
        
        this.elements.modal.addEventListener('click', (e) => {
            if (e.target === this.elements.modal) this.closeModal();
        });

        // Print
        const printBtn = document.getElementById('print-btn');
        if (printBtn) {
            printBtn.addEventListener('click', () => {
                window.print(); 
            });
        }
    },

    switchTab(tabName) {
        this.state.viewMode = tabName;
        // Update Active Class
        this.elements.tabs.forEach(t => {
            if (t.dataset.tab === tabName) t.classList.add('active');
            else t.classList.remove('active');
        });
        this.render();
    },

    startRefreshTimer() {
        if (this.refreshInterval) clearInterval(this.refreshInterval);
        this.state.countdown = 10;
        
        this.refreshInterval = setInterval(() => {
            if (!this.state.autoRefresh) return;
            
            this.state.countdown--;
            if (this.state.countdown <= 0) {
                this.fetchOrders(true);
                this.state.countdown = 10;
            }
            if (this.elements.refreshCountdown) {
                this.elements.refreshCountdown.textContent = this.state.countdown;
            }
        }, 1000);
    },

    updateClock() {
        if (this.elements.clock) {
            const now = new Date();
            this.elements.clock.textContent = now.toLocaleTimeString('en-US', { hour12: false });
        }
    },

    async fetchOrders(silent = false) {
        if (!silent) {
            this.state.isLoading = true;
            this.updateStatus('Scanning...', 'warning-color');
        }

        try {
            const res = await fetch(`api/orders.php?_=${Date.now()}`);
            const data = await res.json();
            
            if (data.status === 'ok') {
                this.state.orders = data.orders;
                
                if (data.autoprint !== undefined && this.elements.autoPrintToggle) {
                    this.state.autoPrint = data.autoprint;
                    this.elements.autoPrintToggle.checked = this.state.autoPrint;
                }

                this.updateTabCounts();
                this.render();
                this.updateStatus('Live', 'success-color');
            } else {
                console.error(data.message);
                this.updateStatus('Error', 'danger-color');
            }
        } catch (err) {
            console.error(err);
            this.updateStatus('Network Error', 'danger-color');
        } finally {
            this.state.isLoading = false;
        }
    },

    updateTabCounts() {
        const pending = this.state.orders.filter(o => o.type === 'Cash Pending').length;
        const paid = this.state.orders.filter(o => o.type === 'Paid' || o.payment_method === 'square').length;
        const voided = this.state.orders.filter(o => o.type === 'Void').length;
        const all = this.state.orders.length;

        const elPending = document.querySelector('.tab-pending .tab-count');
        const elPaid = document.querySelector('.tab-paid .tab-count');
        const elVoid = document.querySelector('.tab-void .tab-count');
        const elAll = document.querySelector('.tab-all .tab-count');

        if (elPending) elPending.textContent = `(${pending})`;
        if (elPaid) elPaid.textContent = `(${paid})`;
        if (elVoid) elVoid.textContent = `(${voided})`;
        if (elAll) elAll.textContent = `(${all})`;
    },

    async toggleAutoPrint(enabled) {
        this.state.autoPrint = enabled;
        try {
            const formData = new FormData();
            formData.append('status', enabled ? '1' : '0');
            
            await fetch('../admin/admin_set_autoprint.php', {
                method: 'POST',
                body: formData
            });
        } catch (e) {
            console.error('Failed to toggle auto print', e);
            if (this.elements.autoPrintToggle) this.elements.autoPrintToggle.checked = !enabled;
        }
    },

    updateStatus(text, colorVar) {
        if (!this.elements.status) return;
        this.elements.status.textContent = text;
        this.elements.status.style.color = ''; 
        if (colorVar) {
             const map = {
                 'success-color': '#00c853',
                 'warning-color': '#ffab00',
                 'danger-color': '#d50000'
             };
             this.elements.status.style.color = map[colorVar] || '#ccc';
        }
    },

    // --- Spooler Methods ---

    tickSpooler() {
        // 1. Check queue status for badges and specific tab UI
        fetch('api/spooler.php?action=status')
            .then(r => r.json())
            .then(data => {
                this.updateBadge('printer', data.printer_count);
                this.updateBadge('mailer', data.mailer_count);
                
                if (this.state.viewMode === 'printer') this.renderPrinterQueue(data.printer_items);
                if (this.state.viewMode === 'mailer') this.renderMailerQueue(data.mailer_items);
            })
            .catch(err => console.error("Spooler status error", err));

        // 2. Heartbeat: Drive the physical printer (one at a time)
        fetch('api/spooler.php?action=tick_printer')
            .then(r => r.json())
            .then(data => {
                if (data.status === 'success') {
                    console.log("Sent to printer: " + data.moved);
                    this.updateStatus('Printing: ' + data.moved, 'success-color');
                }
            })
            .catch(err => console.error("Spooler tick error", err));
    },

    updateBadge(type, count) {
        const el = document.querySelector(`.tab-${type} .badge`);
        if (el) {
            el.innerText = count;
            el.style.display = count > 0 ? 'inline-block' : 'none';
        }
    },

    renderPrinterQueue(items) {
        const container = document.getElementById('printer-queue-list');
        if (!container) return;
        
        let html = items.map(item => `
            <div class="queue-item" style="display:flex; justify-content:space-between; align-items:center; padding:10px; border-bottom:1px solid #333;">
                <span>${item}</span>
                <button onclick="App.handleSpoolAction('void_print', '${item}')" class="btn btn-void" style="padding:5px 10px; font-size:12px;">Void</button>
            </div>
        `).join('');
        
        container.innerHTML = html || '<div class="loading-state"><p>No items in print spool.</p></div>';
    },

    renderMailerQueue(items) {
        const container = document.getElementById('mailer-queue-list');
        if (!container) return;
        
        let html = items.map(item => `
            <div class="queue-item" style="display:flex; justify-content:space-between; align-items:center; padding:10px; border-bottom:1px solid #333;">
                <span>Order #${item}</span>
                <button onclick="App.handleSpoolAction('retry_mail', '${item}')" class="btn btn-square" style="padding:5px 10px; font-size:12px;">Retry Send</button>
            </div>
        `).join('');
        
        container.innerHTML = html || '<div class="loading-state"><p>All emails sent.</p></div>';
    },

    async handleSpoolAction(action, target) {
        // Implementation for manual spool interventions
        console.log(`Action: ${action} on ${target}`);
        if (action === 'retry_mail') {
            await fetch(`api/spooler.php?action=trigger_mail&order_id=${target}`);
            this.tickSpooler();
        }
    },

    // --- End Spooler Methods ---

    render() {
        // If we are in printer or mailer mode, the tickSpooler() handles the innerHTML
        if (this.state.viewMode === 'printer' || this.state.viewMode === 'mailer') {
            this.elements.list.innerHTML = `<div id="${this.state.viewMode}-queue-list" class="queue-container">Loading queue...</div>`;
            return;
        }

        let filteredOrders = [];
        if (this.state.viewMode === 'all') {
            filteredOrders = this.state.orders;
        } else if (this.state.viewMode === 'pending') {
            filteredOrders = this.state.orders.filter(o => o.type === 'Cash Pending');
        } else if (this.state.viewMode === 'paid') {
            filteredOrders = this.state.orders.filter(o => o.type === 'Paid' || o.payment_method === 'square');
        } else if (this.state.viewMode === 'void') {
            filteredOrders = this.state.orders.filter(o => o.type === 'Void');
        }

        if (filteredOrders.length === 0) {
            this.elements.list.innerHTML = '<div class="loading-state"><p>No orders found.</p></div>';
            return;
        }

        this.elements.list.innerHTML = '';

        filteredOrders.forEach(order => {
            const card = document.createElement('div');
            let cardClass = `order-card type-${order.type.toLowerCase().replace(' ', '-')}`;
            
            if (order.payment_method === 'square') {
                cardClass += ' type-square';
            } else if (order.payment_method === 'cash') {
                cardClass += ' type-cash';
            } else if (order.type === 'Void') {
                cardClass += ' type-void';
            }

            card.className = cardClass;
            card.dataset.id = order.id;

            let badgeClass = 'order-type';
            let badgeText = order.type;
            
            if (order.type === 'Paid') {
                badgeClass += ' type-paid';
                badgeText = 'PAID';
            } else if (order.payment_method === 'square') {
                badgeClass += ' type-square';
                badgeText = 'SQUARE';
            } else if (order.payment_method === 'cash') {
                badgeClass += ' type-cash';
                badgeText = 'CASH';
            } else if (order.type === 'Void') {
                badgeClass += ' type-void';
                badgeText = 'VOID';
            } else {
                badgeClass += ' type-standard';
            }

            let elapsedHtml = '';
            if (order.timestamp) {
                const now = Math.floor(Date.now() / 1000);
                const diff = Math.floor((now - order.timestamp) / 60); 
                let elapsedClass = 'elapsed-time';
                if (diff > 20) elapsedClass += ' late';
                else if (diff > 10) elapsedClass += ' warn';
                elapsedHtml = `<span class="${elapsedClass}">${diff} mins ago</span>`;
            }

            const isPaidOrVoid = (order.type === 'Paid' || order.type === 'Void' || order.payment_method === 'square');
            let actionsHtml = '';
            
            if (!isPaidOrVoid) {
                actionsHtml += `
                    <button class="btn btn-square action-btn" data-action="square">Square</button>
                    <button class="btn btn-qr action-btn" data-action="qr">QR Pay</button>
                    <button class="btn btn-cash action-btn" data-action="cash">Cash</button>
                    <button class="btn btn-void action-btn" data-action="void">Void</button>
                `;
            }
            actionsHtml += `<button class="btn btn-receipt view-receipt-btn" data-file="${order.filename}">Receipt</button>`;

            card.innerHTML = `
                <div class="order-id">${order.emoji} #${order.id}</div>
                <div class="order-time-group">
                    <span class="order-time">${order.time}</span>
                    <span class="order-elapsed">${elapsedHtml}</span>
                </div>
                <div class="order-name">${order.name || 'Unknown'}</div>
                <div class="order-total" style="color: #28a745; font-weight: bold;">$${Number(order.total).toFixed(2)}</div>
                <div class="order-actions">
                    <div class="${badgeClass}">${badgeText}</div>
                    ${actionsHtml}
                </div>
                <div class="order-details">
                    <p><strong>Total:</strong> $${Number(order.total).toFixed(2)}</p>
                    <p><strong>Square Total:</strong> $${Number(order.cc_totaltaxed).toFixed(2)}</p>
                    <p><strong>File:</strong> ${order.filename}</p>
                    <p><strong>Preview:</strong> <br> ${order.raw_snippet}</p>
                </div>
            `;

            card.addEventListener('click', (e) => {
                if (e.target.tagName === 'BUTTON') return; 
                card.classList.toggle('expanded');
            });

            card.querySelectorAll('.action-btn').forEach(btn => {
                btn.addEventListener('click', (e) => {
                    e.stopPropagation();
                    const action = btn.dataset.action;
                    if (action === 'cash') this.handleAction('paid', order.id, btn);
                    if (action === 'void') this.handleAction('void', order.id, btn);
                    if (action === 'square') this.handleSquare(order.id, order.cc_totaltaxed, btn);
                    if (action === 'qr') this.handleQR(order.id, btn);
                });
            });

            const receiptBtn = card.querySelector('.view-receipt-btn');
            receiptBtn.addEventListener('click', (e) => {
                e.stopPropagation();
                this.openReceipt(receiptBtn.dataset.file, order.id);
            });

            this.elements.list.appendChild(card);
        });
    },

    async handleAction(action, orderId, btn = null, extraParams = {}) {
        if (action === 'void' && !confirm('Void this order?')) return;
        
        if (btn) {
            btn.disabled = true;
            btn.dataset.originalHtml = btn.innerHTML; 
            btn.innerHTML = '<span class="spinner"></span>';
        }

        try {
            const formData = new FormData();
            formData.append('order', orderId);
            formData.append('action', action);
            formData.append('autoprint', this.state.autoPrint ? '1' : '0');

            for (const key in extraParams) {
                formData.append(key, extraParams[key]);
            }

            await new Promise(resolve => setTimeout(resolve, 2000));
            await fetch('api/order_action.php', {
                method: 'POST',
                body: formData
            });
            
            await new Promise(resolve => setTimeout(resolve, 500));
            this.fetchOrders();
        } catch (e) {
            console.error(e);
            alert('Action failed.');
            if (btn) {
                btn.disabled = false;
                btn.innerHTML = btn.dataset.originalHtml || 'Retry';
            }
        }
    },

    async handleSquare(orderId, amount, btn) {
        let cancelBtn = null;
        this.state.autoRefresh = false; 
        if (this.elements.autoRefreshToggle) this.elements.autoRefreshToggle.checked = false;
        if (this.elements.refreshCountdown) this.elements.refreshCountdown.textContent = '--';

        const originalText = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = 'Sending...';
        
        cancelBtn = document.createElement('button');
        cancelBtn.className = 'btn btn-void action-btn';
        cancelBtn.textContent = 'Cancel';
        cancelBtn.style.marginLeft = '8px';
        cancelBtn.style.display = 'none';
        btn.parentNode.appendChild(cancelBtn);
        
        try {
            const formData = new FormData();
            formData.append('action', 'create');
            formData.append('order_id', orderId);
            formData.append('amount', amount);

            const res = await fetch('api/terminal.php', {
                method: 'POST',
                body: formData
            });
            const data = await res.json();
            
            if (data.status === 'success') {
                const checkoutId = data.checkout_id;
                cancelBtn.style.display = '';
                cancelBtn.onclick = async () => {
                    cancelBtn.disabled = true;
                    cancelBtn.textContent = 'Cancelling...';
                    try {
                        const formData = new FormData();
                        formData.append('action', 'cancel');
                        formData.append('checkout_id', checkoutId);
                        await fetch('api/terminal.php', { method: 'POST', body: formData });
                    } catch (e) {}
                };
                this.pollSquare(checkoutId, orderId, btn, originalText, cancelBtn);
            } else {
                alert('Square Error: ' + data.message);
                btn.disabled = false;
                btn.innerHTML = originalText;
                this.resumeRefresh();
            }
        } catch (e) {
            console.error(e);
            btn.disabled = false;
            btn.innerHTML = originalText;
            this.resumeRefresh();
        }
    },

    async handleQR(orderId, btn) {
        const ref = prompt('Enter QR Payment Reference (e.g. Venmo ID, CashApp Name):');
        if (!ref) return;

        await this.handleAction('paid', orderId, btn, {
            payment_method: 'qr',
            transaction_id: ref
        });
    },

    pollSquare(checkoutId, orderId, btn, originalText, cancelBtn) {
        let attempts = 0;
        const maxAttempts = 100;

        const interval = setInterval(async () => {
            attempts++;
            const dots = '.'.repeat((attempts % 3) + 1);
            btn.innerHTML = `Waiting${dots}`;
            if (cancelBtn) cancelBtn.style.display = '';

            try {
                const formData = new FormData();
                formData.append('action', 'poll');
                formData.append('checkout_id', checkoutId);
                
                const res = await fetch('api/terminal.php', { method: 'POST', body: formData });
                const data = await res.json();
                
                if (data.status === 'success') {
                    const status = data.terminal_status;
                    
                    if (status === 'COMPLETED') {
                        clearInterval(interval);
                        btn.innerHTML = 'SUCCESS';
                        btn.className = 'btn btn-cash action-btn'; 
                        if (cancelBtn) cancelBtn.remove();
                        await this.handleAction('paid', orderId, null, {
                            payment_method: 'square',
                            transaction_id: data.payment_id
                        });
                        setTimeout(() => {
                            this.resumeRefresh();
                        }, 1000);
                    } else if (status === 'CANCELED' || status === 'FAILED') {
                        clearInterval(interval);
                        btn.innerHTML = 'FAILED';
                        btn.className = 'btn btn-void action-btn'; 
                        if (cancelBtn) cancelBtn.remove();
                        setTimeout(() => { 
                            btn.disabled = false; 
                            btn.innerHTML = originalText; 
                            btn.className = 'btn btn-square action-btn'; 
                            this.resumeRefresh();
                        }, 3000);
                    } else {
                        if (attempts > maxAttempts) {
                            clearInterval(interval);
                            btn.innerHTML = 'TIMEOUT';
                            if (cancelBtn) cancelBtn.remove();
                            setTimeout(() => {
                                btn.disabled = false;
                                btn.innerHTML = originalText;
                                this.resumeRefresh();
                            }, 3000);
                        }
                    }
                }
            } catch (e) {
                console.error("Polling error", e);
            }
        }, 3000);
    },

    resumeRefresh() {
        this.state.autoRefresh = true;
        if (this.elements.autoRefreshToggle) this.elements.autoRefreshToggle.checked = true;
        this.startRefreshTimer();
    },

    async openReceipt(filename, orderId) {
        if (this.elements.modal) this.elements.modal.classList.add('open');
        if (this.elements.modalTitle) this.elements.modalTitle.textContent = `Receipt #${orderId}`;
        if (this.elements.receiptContent) this.elements.receiptContent.textContent = "Loading receipt data...";
        
        try {
            const res = await fetch(`api/receipt.php?file=${encodeURIComponent(filename)}`);
            const data = await res.json();
            
            if (data.status === 'ok') {
                this.elements.receiptContent.textContent = data.content;
            } else {
                this.elements.receiptContent.textContent = "Error: " + data.message;
            }
        } catch (e) {
            if (this.elements.receiptContent) this.elements.receiptContent.textContent = "Network error fetching receipt.";
        }
    },

    closeModal() {
        if (this.elements.modal) this.elements.modal.classList.remove('open');
    }
};

document.addEventListener('DOMContentLoaded', () => App.init());