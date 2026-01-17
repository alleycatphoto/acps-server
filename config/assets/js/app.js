const App = {
    state: {
        orders: [],
        isLoading: false,
        lastUpdate: null,
        autoRefresh: true,
        viewMode: 'due', // due, paid, all
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
        viewFilter: null,
        autoPrintToggle: null,
        autoRefreshToggle: null,
        refreshCountdown: null,
        viewBadge: null
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
        this.elements.viewFilter = document.getElementById('view-filter');
        this.elements.autoPrintToggle = document.getElementById('autoprint-toggle');
        this.elements.autoRefreshToggle = document.getElementById('autorefresh-toggle');
        this.elements.refreshCountdown = document.getElementById('refresh-countdown');
        this.elements.viewBadge = document.getElementById('view-badge');

        this.updateClock();
        setInterval(() => this.updateClock(), 1000);
        
        this.fetchOrders();
        this.startRefreshTimer();

        this.elements.refreshBtn.addEventListener('click', () => {
            this.state.countdown = 10; // Reset
            this.fetchOrders();
        });
        
        // View Filter
        this.elements.viewFilter.addEventListener('change', (e) => {
            this.state.viewMode = e.target.value;
            this.updateBadge();
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

    updateBadge() {
        const badge = this.elements.viewBadge;
        if (this.state.viewMode === 'due') {
            badge.textContent = 'PENDING';
            badge.className = 'badge badge-fire';
        } else if (this.state.viewMode === 'paid') {
            badge.textContent = 'PAID';
            badge.className = 'badge badge-main';
        } else {
            badge.textContent = 'ALL';
            badge.className = 'badge badge-main';
        }
    },

    async fetchOrders(silent = false) {
        if (!silent) {
            this.state.isLoading = true;
            this.updateStatus('Scanning...', 'warning-color');
        }

        try {
            // Add timestamp to prevent caching
            const res = await fetch(`api/orders.php?view=${this.state.viewMode}&_=${Date.now()}`);
            const data = await res.json();
            
            if (data.status === 'ok') {
                this.state.orders = data.orders;
                
                // Sync Auto Print UI from server state if fetched
                if (data.autoprint !== undefined) {
                    this.state.autoPrint = data.autoprint;
                    this.elements.autoPrintToggle.checked = this.state.autoPrint;
                }

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

    async toggleAutoPrint(enabled) {
        // Optimistic UI update
        this.state.autoPrint = enabled;
        try {
            // We need a backend endpoint to save this status. 
            // Reuse admin_set_autoprint.php but routed via our API or direct call if allowed.
            // Since this is in /config, path is ../admin/admin_set_autoprint.php
            // But we need to call it via HTTP relative path.
            
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
        if (this.state.orders.length === 0) {
            this.elements.list.innerHTML = '<div class="loading-state"><p>No orders found.</p></div>';
            return;
        }

        this.elements.list.innerHTML = '';

        this.state.orders.forEach(order => {
            const card = document.createElement('div');
            card.className = `order-card type-${order.type.toLowerCase().replace(' ', '-')}`;
            card.dataset.id = order.id;

            // Determine class for badge and text
            let badgeClass = 'order-type';
            let badgeText = order.type;
            
            if (order.type.includes('Cash')) {
                badgeClass += ' type-cash';
                badgeText = 'PENDING';
            } else if (order.type.includes('Paid')) {
                badgeClass += ' type-paid';
                badgeText = 'PAID';
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
                    <button class="btn btn-square action-btn" data-action="square">Square</button>
                    <button class="btn btn-cash action-btn" data-action="cash">Cash</button>
                    <button class="btn btn-void action-btn" data-action="void">Void</button>
                    <button class="btn btn-receipt view-receipt-btn" data-file="${order.filename}">Receipt</button>
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

    async handleAction(action, orderId, btn = null) {
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
                        await this.handleAction('paid', orderId);
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