/**
 * Admin Components JavaScript
 * Modal and Alert functionality for admin pages
 */

(function() {
    'use strict';

    // ============================================
    // MODAL CONTROLLER
    // ============================================
    
    window.Modal = {
        activeModals: [],

        // Open a modal by ID
        open: function(modalId) {
            const modal = document.getElementById(modalId);
            const overlay = document.getElementById(modalId + '-overlay');
            
            if (!modal) {
                console.error('Modal not found:', modalId);
                return;
            }

            // Add to active modals stack
            this.activeModals.push(modalId);

            // Show modal and overlay
            modal.classList.add('active');
            if (overlay) overlay.classList.add('active');

            // Prevent body scroll
            document.body.classList.add('modal-open');

            // Focus first focusable element
            setTimeout(() => {
                const focusable = modal.querySelector('button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])');
                if (focusable) focusable.focus();
            }, 100);

            // Trigger custom event
            modal.dispatchEvent(new CustomEvent('modal:open', { detail: { modalId } }));
        },

        // Close a modal by ID
        close: function(modalId) {
            const modal = document.getElementById(modalId);
            const overlay = document.getElementById(modalId + '-overlay');
            
            if (!modal) return;

            // Remove from active modals
            this.activeModals = this.activeModals.filter(id => id !== modalId);

            // Hide modal and overlay
            modal.classList.remove('active');
            if (overlay) overlay.classList.remove('active');

            // Restore body scroll if no modals are open
            if (this.activeModals.length === 0) {
                document.body.classList.remove('modal-open');
            }

            // Trigger custom event
            modal.dispatchEvent(new CustomEvent('modal:close', { detail: { modalId } }));
        },

        // Close all open modals
        closeAll: function() {
            [...this.activeModals].forEach(id => this.close(id));
        },

        // Initialize modal event listeners
        init: function() {
            // Close button clicks
            document.querySelectorAll('[data-modal-close]').forEach(btn => {
                btn.addEventListener('click', (e) => {
                    const modal = btn.closest('[data-modal]');
                    if (modal) this.close(modal.id);
                });
            });

            // Overlay clicks
            document.querySelectorAll('[data-modal-overlay]').forEach(overlay => {
                overlay.addEventListener('click', (e) => {
                    if (e.target === overlay) {
                        const modalId = overlay.id.replace('-overlay', '');
                        const modal = document.getElementById(modalId);
                        if (modal && modal.dataset.closeOnOverlay !== 'false') {
                            this.close(modalId);
                        }
                    }
                });
            });

            // Escape key
            document.addEventListener('keydown', (e) => {
                if (e.key === 'Escape' && this.activeModals.length > 0) {
                    const topModalId = this.activeModals[this.activeModals.length - 1];
                    const modal = document.getElementById(topModalId);
                    if (modal && modal.dataset.closeOnEscape !== 'false') {
                        this.close(topModalId);
                    }
                }
            });

            // Open buttons
            document.querySelectorAll('[data-modal-open]').forEach(btn => {
                btn.addEventListener('click', (e) => {
                    e.preventDefault();
                    const modalId = btn.dataset.modalOpen;
                    this.open(modalId);
                });
            });
        }
    };

    // ============================================
    // ALERT CONTROLLER
    // ============================================
    
    const Alert = {
        alerts: [],

        // Show an alert
        show: function(message, type = 'info', options = {}) {
            const defaults = {
                dismissible: true,
                icon: null,
                timeout: 0,
                position: 'top',
                id: null,
                class: ''
            };
            
            const opts = { ...defaults, ...options };
            const alertId = opts.id || 'alert-' + Date.now();
            
            // Create alert element
            const alert = this.createAlert(message, type, opts, alertId);
            
            // Add to DOM
            this.addAlert(alert, opts.position);
            
            // Show with animation
            setTimeout(() => alert.classList.add('show'), 10);
            
            // Auto-dismiss if timeout is set
            if (opts.timeout > 0) {
                setTimeout(() => this.dismiss(alertId), opts.timeout);
            }
            
            return alertId;
        },

        // Create alert element
        createAlert: function(message, type, options, id) {
            const typeConfig = {
                success: { icon: 'fa-check-circle', bg: 'linear-gradient(135deg, #d4edda 0%, #c3e6cb 100%)', border: '#28a745', color: '#155724' },
                error: { icon: 'fa-exclamation-circle', bg: 'linear-gradient(135deg, #f8d7da 0%, #f5c6cb 100%)', border: '#dc3545', color: '#721c24' },
                warning: { icon: 'fa-exclamation-triangle', bg: 'linear-gradient(135deg, #fff3cd 0%, #ffeaa7 100%)', border: '#ffc107', color: '#856404' },
                info: { icon: 'fa-info-circle', bg: 'linear-gradient(135deg, #d1ecf1 0%, #bee5eb 100%)', border: '#17a2b8', color: '#0c5460' }
            };
            
            const config = typeConfig[type] || typeConfig.info;
            const icon = options.icon || config.icon;
            
            const wrapper = document.createElement('div');
            wrapper.className = 'alert-wrapper alert-' + options.position;
            
            const alert = document.createElement('div');
            alert.className = 'alert alert-' + type + ' ' + options.class;
            alert.id = id;
            alert.dataset.alert = '';
            alert.dataset.alertType = type;
            alert.style.setProperty('--alert-bg', config.bg);
            alert.style.setProperty('--alert-border', config.border);
            alert.style.setProperty('--alert-color', config.color);
            
            alert.innerHTML = `
                <div class="alert-icon">
                    <i class="fas ${icon}"></i>
                </div>
                <div class="alert-content">${message}</div>
                ${options.dismissible ? '<button class="alert-close" data-alert-close aria-label="Close alert"><i class="fas fa-times"></i></button>' : ''}
            `;
            
            wrapper.appendChild(alert);
            return wrapper;
        },

        // Add alert to DOM
        addAlert: function(wrapper, position) {
            // Find or create wrapper for position
            let container = document.querySelector('.alert-container-' + position);
            if (!container) {
                container = document.createElement('div');
                container.className = 'alert-container-' + position;
                document.body.appendChild(container);
            }
            container.appendChild(wrapper);
            this.alerts.push(wrapper);
        },

        // Dismiss an alert
        dismiss: function(id) {
            const alert = document.getElementById(id);
            if (alert) {
                alert.classList.remove('show');
                alert.classList.add('hide');
                setTimeout(() => {
                    const wrapper = alert.closest('.alert-wrapper');
                    if (wrapper) {
                        wrapper.remove();
                        this.alerts = this.alerts.filter(a => a !== wrapper);
                    }
                }, 300);
            }
        },

        // Dismiss all alerts
        dismissAll: function() {
            [...this.alerts].forEach(wrapper => {
                const alert = wrapper.querySelector('.alert');
                if (alert) {
                    alert.classList.remove('show');
                    alert.classList.add('hide');
                }
            });
            setTimeout(() => {
                document.querySelectorAll('.alert-wrapper').forEach(w => w.remove());
                this.alerts = [];
            }, 300);
        },

        // Initialize alert event listeners
        init: function() {
            // Close button clicks
            document.addEventListener('click', (e) => {
                const closeBtn = e.target.closest('[data-alert-close]');
                if (closeBtn) {
                    const alert = closeBtn.closest('.alert');
                    if (alert) this.dismiss(alert.id);
                }
            });

            // Initialize existing alerts
            document.querySelectorAll('[data-alert]').forEach(alert => {
                const timeout = parseInt(alert.dataset.alertTimeout) || 0;
                setTimeout(() => alert.classList.add('show'), 10);
                
                if (timeout > 0) {
                    setTimeout(() => this.dismiss(alert.id), timeout);
                }
            });
        }
    };

    // Expose Alert to global scope
    window.Alert = Alert;

    // ============================================
    // INITIALIZATION
    // ============================================
    
    // Initialize on DOM ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => {
            Modal.init();
            Alert.init();
        });
    } else {
        Modal.init();
        Alert.init();
    }
})();
