const App = {
    state: {
        orders: [],
        isLoading: false,
        lastUpdate: null,
        autoRefresh: true,
        viewMode: 'pending', // pending, paid, void, all
        autoPrint: true
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

        this.elements.refreshBtn.addEventListener('click', () => {
            this.state.countdown = 10; // Reset
            this.fetchOrders();
        });

        // Auto Print Toggle
        this.elements.autoPrintToggle.addEventListener('change', (e) => {
            this.toggleAutoPrint(e.target.checked);
        });

        // Auto Refresh Toggle
        this.elements.autoRefreshToggle.addEventListener('change', (e) => {
            this.state.autoRefresh = e.target.checked;
            if (this.state.autoRefresh) {
                this.startRefreshTimer();
            } else {
                this.elements.refreshCountdown.textContent = '--';
            }
        });

        // Modal events
        document.querySelectorAll('.close-btn').forEach(btn => {
            btn.addEventListener('click', () => this.closeModal());
        });
        
        this.elements.modal.addEventListener('click', (e) => {
            if (e.target === this.elements.modal) this.closeModal();
        });

        // Print
        document.getElementById('print-btn').addEventListener('click', () => {
            window.print(); 
        });
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
            this.elements.refreshCountdown.textContent = this.state.countdown;
        }, 1000);
    },

    updateClock() {
        const now = new Date();
        this.elements.clock.textContent = now.toLocaleTimeString('en-US', { hour12: false });
    },

    async fetchOrders(silent = false) {
        if (!silent) {
            this.state.isLoading = true;
            this.updateStatus('Scanning...', 'warning-color');
        }

        try {
            // Add timestamp to prevent caching. API returns ALL orders.
            const res = await fetch(`api/orders.php?_=${Date.now()}`);
            const data = await res.json();
            
            if (data.status === 'ok') {
                this.state.orders = data.orders;
                
                // Sync Auto Print UI from server state if fetched
                if (data.autoprint !== undefined) {
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
        // Calculate counts
        const pending = this.state.orders.filter(o => o.type === 'Cash Pending').length;
        const paid = this.state.orders.filter(o => o.type === 'Paid' || o.payment_method === 'square').length; // Square is technically paid
        const voided = this.state.orders.filter(o => o.type === 'Void').length;
        const all = this.state.orders.length;

        // Update DOM
        document.querySelector('.tab-pending .tab-count').textContent = `(${pending})`;
        document.querySelector('.tab-paid .tab-count').textContent = `(${paid})`;
        document.querySelector('.tab-void .tab-count').textContent = `(${voided})`;
        document.querySelector('.tab-all .tab-count').textContent = `(${all})`;
    },

    async toggleAutoPrint(enabled) {
        // Optimistic UI update
        this.state.autoPrint = enabled;
        try {
            const formData = new FormData();
            formData.append('status', enabled ? '1' : '0');
            
            await fetch('../admin/admin_set_autoprint.php', {
                method: 'POST',
                body: formData
            });
            // Success (silent)
        } catch (e) {
            console.error('Failed to toggle auto print', e);
            // Revert UI on failure
            this.elements.autoPrintToggle.checked = !enabled;
        }
    },

    updateStatus(text, colorVar) {
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

    render() {
        // Filter based on viewMode
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
            // Base class
            let cardClass = `order-card type-${order.type.toLowerCase().replace(' ', '-')}`;
            
            // Enhance card class based on payment method for border color
            if (order.payment_method === 'square') {
                cardClass += ' type-square';
            } else if (order.payment_method === 'cash') {
                cardClass += ' type-cash';
            } else if (order.type === 'Void') {
                cardClass += ' type-void';
            }

            card.className = cardClass;
            card.dataset.id = order.id;

            // Determine class for badge and text
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

            // Elapsed Time Logic
            let elapsedHtml = '';
            if (order.timestamp) {
                const now = Math.floor(Date.now() / 1000);
                const diff = Math.floor((now - order.timestamp) / 60); // minutes
                let elapsedClass = 'elapsed-time';
                
                if (diff > 20) elapsedClass += ' late';
                else if (diff > 10) elapsedClass += ' warn';
                
                elapsedHtml = `<span class="${elapsedClass}">${diff} mins ago</span>`;
            }

            // Action Buttons Logic
            // Hide payment/void actions if Paid, Void, or Square (assumed paid)
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
            // Receipt button always visible
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

            // Action Buttons
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

            // Receipt Button
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
            // Store original text just in case, though usually list refreshes
            btn.dataset.originalHtml = btn.innerHTML; 
            btn.innerHTML = '<span class="spinner"></span>';
        }

        try {
            const formData = new FormData();
            formData.append('order', orderId);
            formData.append('action', action);
            formData.append('autoprint', this.state.autoPrint ? '1' : '0');

            // Add extra parameters (e.g., payment_method, transaction_id)
            for (const key in extraParams) {
                formData.append(key, extraParams[key]);
            }

            // Force a minimum spinner delay for feedback
            await new Promise(resolve => setTimeout(resolve, 2000));

            await fetch('api/order_action.php', {
                method: 'POST',
                body: formData
            });
            
            // Short delay to ensure FS update is visible
            await new Promise(resolve => setTimeout(resolve, 500));

            // Refresh to update list
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
        // Pause main refresh
        this.state.autoRefresh = false; 
        this.elements.autoRefreshToggle.checked = false;
        this.elements.refreshCountdown.textContent = '--';

        const originalText = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = 'Sending...';
        // Add Cancel button next to Square while polling
        cancelBtn = document.createElement('button');
        cancelBtn.className = 'btn btn-void action-btn';
        cancelBtn.textContent = 'Cancel';
        cancelBtn.style.marginLeft = '8px';
        cancelBtn.style.display = 'none';
        btn.parentNode.appendChild(cancelBtn);
        
        try {
            // Create Checkout
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
        const maxAttempts = 100; // ~5 mins at 3s interval

        const interval = setInterval(async () => {
            attempts++;
            // Update button to show activity
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
                        btn.className = 'btn btn-cash action-btn'; // Turn green
                        if (cancelBtn) cancelBtn.remove();
                        // Mark as paid in system
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
                        btn.className = 'btn btn-void action-btn'; // Turn red
                        if (cancelBtn) cancelBtn.remove();
                        setTimeout(() => { 
                            btn.disabled = false; 
                            btn.innerHTML = originalText; 
                            btn.className = 'btn btn-square action-btn'; // Reset
                            this.resumeRefresh();
                        }, 3000);
                    } else {
                        // Still PENDING or IN_PROGRESS
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
        }, 3000); // 3 seconds
    },

    resumeRefresh() {
        this.state.autoRefresh = true;
        this.elements.autoRefreshToggle.checked = true;
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
            this.elements.receiptContent.textContent = "Network error fetching receipt.";
        }
    },

    closeModal() {
        this.elements.modal.classList.remove('open');
    }
};

document.addEventListener('DOMContentLoaded', () => App.init());