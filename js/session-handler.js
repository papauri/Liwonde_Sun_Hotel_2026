/**
 * Session Handler & Navigation Manager
 * Prevents page loops and manages session state
 */

(function() {
    'use strict';

    // ============================================
    // SESSION MANAGEMENT
    // ============================================
    
    const SessionManager = {
        // Session storage keys
        KEYS: {
            PAGE_VISITS: 'lsh_page_visits',
            LAST_PAGE: 'lsh_last_page',
            NAVIGATION_LOCKED: 'lsh_nav_locked',
            MOBILE_MENU_STATE: 'lsh_mobile_menu',
            SCROLL_POSITIONS: 'lsh_scroll_positions'
        },

        // Get value from session storage
        get: function(key) {
            try {
                const value = sessionStorage.getItem(key);
                return value !== null ? JSON.parse(value) : null;
            } catch (e) {
                console.warn('Session get error:', e);
                return null;
            }
        },

        // Set value in session storage
        set: function(key, value) {
            try {
                sessionStorage.setItem(key, JSON.stringify(value));
            } catch (e) {
                console.warn('Session set error:', e);
            }
        },

        // Remove value from session storage
        remove: function(key) {
            try {
                sessionStorage.removeItem(key);
            } catch (e) {
                console.warn('Session remove error:', e);
            }
        },

        // Clear all session data
        clear: function() {
            Object.values(this.KEYS).forEach(key => sessionStorage.removeItem(key));
        },

        // Track page visit to prevent loops
        trackPageVisit: function(pageName) {
            const visits = this.get(this.KEYS.PAGE_VISITS) || {};
            const currentPage = window.location.pathname;
            
            // Reset counter if navigating to different page
            if (visits.lastPage !== currentPage) {
                visits[currentPage] = 1;
            } else {
                // Same page - increment counter to detect loops
                visits[currentPage] = (visits[currentPage] || 0) + 1;
            }
            
            visits.lastPage = currentPage;
            
            // Log suspicious navigation patterns
            if (visits[currentPage] > 3 && visits[currentPage] < 10) {
                console.warn('Possible navigation loop detected:', currentPage, visits[currentPage]);
                this.set(this.KEYS.NAVIGATION_LOCKED, Date.now());
            }
            
            this.set(this.KEYS.PAGE_VISITS, visits);
            
            return visits[currentPage];
        },

        // Check if navigation is locked (prevents loops)
        isNavigationLocked: function() {
            const lockTime = this.get(this.KEYS.NAVIGATION_LOCKED);
            if (!lockTime) return false;
            
            // Lock expires after 10 seconds
            const lockAge = Date.now() - lockTime;
            return lockAge < 10000;
        },

        // Unlock navigation
        unlockNavigation: function() {
            this.remove(this.KEYS.NAVIGATION_LOCKED);
        }
    };

    // ============================================
    // PAGE NAVIGATION HANDLER
    // ============================================
    
    const PageNavigation = {
        currentPath: window.location.pathname,
        isNavigating: false,
        navigationStartTime: 0,
        NAVIGATION_TIMEOUT: 3000, // 3 seconds

        // Initialize
        init: function() {
            this.currentPath = window.location.pathname;
            
            // Track navigation events
            this.bindEvents();
            
            // Check for navigation loops on page load
            setTimeout(() => this.checkForLoops(), 1000);
            
            // Clean up old session data
            this.cleanupOldSessionData();
        },

        // Bind navigation events
        bindEvents: function() {
            // Intercept all link clicks
            document.addEventListener('click', this.handleLinkClick.bind(this), true);
            
            // Intercept form submissions
            document.addEventListener('submit', this.handleFormSubmit.bind(this), true);
            
            // Handle browser navigation events
            window.addEventListener('popstate', this.handlePopState.bind(this));
            window.addEventListener('pushstate', this.handlePushState.bind(this));
        },

        // Handle link clicks
        handleLinkClick: function(e) {
            const link = e.target.closest('a');
            if (!link) return;
            
            const href = link.getAttribute('href');
            if (!href) return;
            
            // Skip hash links and JavaScript links
            if (href.startsWith('#') || href.startsWith('javascript:')) {
                return;
            }
            
            // Check if it's a same-page navigation
            const targetUrl = new URL(href, window.location.origin);
            if (targetUrl.pathname === this.currentPath) {
                // Allow same-page hash navigation
                return;
            }
            
            // Check if already navigating
            if (this.isNavigating) {
                console.warn('Already navigating, blocking click');
                e.preventDefault();
                e.stopPropagation();
                return;
            }
            
            // Check for navigation loop
            if (SessionManager.isNavigationLocked()) {
                console.warn('Navigation locked, blocking click');
                e.preventDefault();
                e.stopPropagation();
                
                // Show user-friendly message
                this.showNavigationError();
                return;
            }
            
            // Track page visit
            const visitCount = SessionManager.trackPageVisit(targetUrl.pathname);
            if (visitCount > 5) {
                console.warn('Excessive page visits detected, blocking navigation');
                e.preventDefault();
                e.stopPropagation();
                this.showNavigationError('Please wait a moment...');
                return;
            }
            
            // Mark as navigating
            this.isNavigating = true;
            this.navigationStartTime = Date.now();
            
            // Unlock navigation after timeout
            setTimeout(() => {
                this.isNavigating = false;
            }, this.NAVIGATION_TIMEOUT);
        },

        // Handle form submissions
        handleFormSubmit: function(e) {
            if (this.isNavigating) {
                console.warn('Already navigating, blocking form submission');
                e.preventDefault();
                return;
            }
        },

        // Handle browser back/forward
        handlePopState: function(event) {
            this.isNavigating = false;
            this.currentPath = window.location.pathname;
            SessionManager.unlockNavigation();
        },

        // Handle browser forward
        handlePushState: function(event) {
            this.isNavigating = false;
            this.currentPath = window.location.pathname;
            SessionManager.unlockNavigation();
        },

        // Check for navigation loops
        checkForLoops: function() {
            const currentPage = window.location.pathname;
            const visits = SessionManager.get(SessionManager.KEYS.PAGE_VISITS) || {};
            
            // If this page has been visited multiple times in short session
            if (visits[currentPage] > 3) {
                console.warn('Navigation loop detected:', currentPage);
                
                // Force reset after detecting loop
                visits[currentPage] = 0;
                SessionManager.set(SessionManager.KEYS.PAGE_VISITS, visits);
            }
        },

        // Show navigation error
        showNavigationError: function(message) {
            // Remove any existing error message
            const existing = document.querySelector('.navigation-error');
            if (existing) {
                existing.remove();
            }
            
            // Create error toast
            const toast = document.createElement('div');
            toast.className = 'navigation-error';
            toast.innerHTML = `
                <div class="error-content">
                    <i class="fas fa-exclamation-triangle"></i>
                    <span>${message}</span>
                </div>
            `;
            
            // Add styles
            toast.style.cssText = `
                position: fixed;
                top: 100px;
                left: 50%;
                transform: translateX(-50%);
                background: #dc3545;
                color: #fff;
                padding: 16px 24px;
                border-radius: 8px;
                z-index: 999999;
                box-shadow: 0 10px 40px rgba(0, 0, 0, 0.3);
                animation: errorSlideIn 0.3s ease;
                font-family: var(--font-sans);
                font-size: 14px;
                display: flex;
                align-items: center;
                gap: 12px;
            `;
            
            // Add animation styles
            const style = document.createElement('style');
            style.textContent = `
                @keyframes errorSlideIn {
                    from {
                        opacity: 0;
                        transform: translateX(-50%) translateY(-20px);
                    }
                    to {
                        opacity: 1;
                        transform: translateX(-50%) translateY(0);
                    }
                }
            `;
            document.head.appendChild(style);
            
            // Show toast
            document.body.appendChild(toast);
            
            // Auto-hide after 3 seconds
            setTimeout(() => {
                toast.style.opacity = '0';
                toast.style.transform = 'translateX(-50%) translateY(-10px)';
                setTimeout(() => toast.remove(), 300);
            }, 3000);
        },

        // Cleanup old session data
        cleanupOldSessionData: function() {
            // Clear old navigation locks
            const lockTime = SessionManager.get(SessionManager.KEYS.NAVIGATION_LOCKED);
            if (lockTime && (Date.now() - lockTime > 60000)) {
                SessionManager.remove(SessionManager.KEYS.NAVIGATION_LOCKED);
            }
        }
    };

    // ============================================
    // INITIALIZATION
    // ============================================
    
    // Initialize on page load
    document.addEventListener('DOMContentLoaded', function() {
        // Hide page loader immediately to prevent display issues
        const loader = document.getElementById('page-loader');
        if (loader) {
            loader.classList.add('hidden');
        }
        
        // Initialize navigation handler
        PageNavigation.init();
    });

})();