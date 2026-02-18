/**
 * Admin Mobile Enhancements
 * Optimizes admin tables and components for 320px screens
 */

(function() {
    'use strict';

    // Initialize when DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    function init() {
        enhanceMobileTables();
        addTableDataLabels();
        detectOverflowingTables();
        addTouchGestures();
        optimizeQuickActions();
    }

    /**
     * Transform tables into card layouts on mobile
     */
    function enhanceMobileTables() {
        const tables = document.querySelectorAll('.table');
        
        tables.forEach(table => {
            if (window.innerWidth <= 480) {
                transformTableToCards(table);
            }
        });

        // Re-check on resize
        let resizeTimer;
        window.addEventListener('resize', function() {
            clearTimeout(resizeTimer);
            resizeTimer = setTimeout(function() {
                tables.forEach(table => {
                    if (window.innerWidth <= 480) {
                        transformTableToCards(table);
                    } else {
                        restoreTableFromCards(table);
                    }
                });
            }, 250);
        });
    }

    /**
     * Transform table rows into card layout
     */
    function transformTableToCards(table) {
        const tbody = table.querySelector('tbody');
        if (!tbody) return;

        const rows = tbody.querySelectorAll('tr');
        const headers = table.querySelectorAll('thead th');

        rows.forEach(row => {
            const cells = row.querySelectorAll('td');
            
            cells.forEach((cell, index) => {
                // Get header text for this column
                let labelText = '';
                if (headers[index]) {
                    labelText = headers[index].textContent.trim();
                } else {
                    // Fallback: try to get label from data-label attribute
                    labelText = cell.getAttribute('data-label') || '';
                }
                
                // Set data-label attribute for CSS
                if (labelText && !cell.getAttribute('data-label')) {
                    cell.setAttribute('data-label', labelText);
                }
            });
        });

        // Mark table as mobile-enhanced
        table.classList.add('mobile-enhanced');
    }

    /**
     * Restore table from card layout
     */
    function restoreTableFromCards(table) {
        table.classList.remove('mobile-enhanced');
    }

    /**
     * Add data-label attributes to table cells
     * These are used by CSS to show labels on mobile
     */
    function addTableDataLabels() {
        const tables = document.querySelectorAll('.booking-table, .table');
        
        tables.forEach(table => {
            const headers = table.querySelectorAll('thead th');
            const tbody = table.querySelector('tbody');
            
            if (!tbody) return;

            const rows = tbody.querySelectorAll('tr');
            
            rows.forEach(row => {
                const cells = row.querySelectorAll('td');
                
                cells.forEach((cell, index) => {
                    if (headers[index] && !cell.getAttribute('data-label')) {
                        const labelText = headers[index].textContent.trim();
                        cell.setAttribute('data-label', labelText);
                    }
                });
            });
        });
    }

    /**
     * Detect tables that overflow and add scroll indicator
     */
    function detectOverflowingTables() {
        const tableContainers = document.querySelectorAll('.table-responsive');
        
        tableContainers.forEach(container => {
            checkOverflow(container);
            
            // Re-check on resize
            window.addEventListener('resize', function() {
                checkOverflow(container);
            });
        });
    }

    function checkOverflow(container) {
        const table = container.querySelector('table');
        if (!table) return;

        if (table.scrollWidth > container.clientWidth) {
            container.classList.add('overflowing');
        } else {
            container.classList.remove('overflowing');
        }
    }

    /**
     * Add touch gestures for mobile tables
     */
    function addTouchGestures() {
        const tableContainers = document.querySelectorAll('.table-responsive');
        
        tableContainers.forEach(container => {
            let startX = 0;
            let scrollLeft = 0;

            container.addEventListener('touchstart', function(e) {
                startX = e.touches[0].pageX - container.offsetLeft;
                scrollLeft = container.scrollLeft;
            }, { passive: true });

            container.addEventListener('touchmove', function(e) {
                if (!startX) return;
                
                const x = e.touches[0].pageX - container.offsetLeft;
                const walk = (x - startX) * 2; // Scroll-fast
                container.scrollLeft = scrollLeft - walk;
            }, { passive: true });

            container.addEventListener('touchend', function() {
                startX = 0;
            });
        });
    }

    /**
     * Optimize quick action buttons on mobile
     */
    function optimizeQuickActions() {
        if (window.innerWidth <= 480) {
            const quickActions = document.querySelectorAll('.quick-action');
            
            quickActions.forEach(button => {
                // Add title attribute for tooltips
                if (!button.getAttribute('title')) {
                    const buttonText = button.textContent.trim();
                    if (buttonText) {
                        button.setAttribute('title', buttonText);
                    }
                }
            });

            // Group action buttons into dropdown if too many
            const actionCells = document.querySelectorAll('td:last-child');
            
            actionCells.forEach(cell => {
                const buttons = cell.querySelectorAll('.quick-action, .btn');
                
                if (buttons.length > 3) {
                    createActionsDropdown(cell, buttons);
                }
            });
        }
    }

    /**
     * Create dropdown for action buttons
     */
    function createActionsDropdown(cell, buttons) {
        // Check if already converted
        if (cell.querySelector('.actions-dropdown')) return;

        // Create dropdown container
        const dropdown = document.createElement('div');
        dropdown.className = 'actions-dropdown';
        dropdown.innerHTML = `
            <button class="actions-dropdown-toggle" onclick="toggleActionsDropdown(this)">
                <i class="fas fa-ellipsis-v"></i>
            </button>
            <div class="actions-dropdown-menu">
                <!-- Buttons will be moved here -->
            </div>
        `;

        // Move buttons to dropdown (except first 2)
        const buttonsToMove = Array.from(buttons).slice(2);
        const dropdownMenu = dropdown.querySelector('.actions-dropdown-menu');
        
        buttonsToMove.forEach(btn => {
            const wrapper = document.createElement('div');
            wrapper.className = 'dropdown-item';
            wrapper.appendChild(btn);
            dropdownMenu.appendChild(wrapper);
        });

        cell.appendChild(dropdown);
    }

    /**
     * Toggle actions dropdown
     */
    window.toggleActionsDropdown = function(toggleBtn) {
        const dropdown = toggleBtn.closest('.actions-dropdown');
        const menu = dropdown.querySelector('.actions-dropdown-menu');
        
        // Close other dropdowns
        document.querySelectorAll('.actions-dropdown.active').forEach(other => {
            if (other !== dropdown) {
                other.classList.remove('active');
            }
        });
        
        // Toggle current dropdown
        dropdown.classList.toggle('active');
    };

    /**
     * Close dropdowns when clicking outside
     */
    document.addEventListener('click', function(e) {
        if (!e.target.closest('.actions-dropdown')) {
            document.querySelectorAll('.actions-dropdown.active').forEach(dropdown => {
                dropdown.classList.remove('active');
            });
        }
    });

    /**
     * Add swipe functionality to tabs on mobile
     */
    function initTabSwipeGestures() {
        const tabsHeaders = document.querySelectorAll('.tabs-header');
        
        tabsHeaders.forEach(header => {
            let startX = 0;
            let scrollLeft = 0;

            header.addEventListener('touchstart', function(e) {
                startX = e.touches[0].pageX - header.offsetLeft;
                scrollLeft = header.scrollLeft;
            }, { passive: true });

            header.addEventListener('touchmove', function(e) {
                const x = e.touches[0].pageX - header.offsetLeft;
                const walk = (x - startX) * 1.5;
                header.scrollLeft = scrollLeft - walk;
            }, { passive: true });
        });
    }

    // Initialize tab swipe gestures
    setTimeout(initTabSwipeGestures, 100);

})();