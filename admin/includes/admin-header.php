<?php
/**
 * Admin Header HTML Output
 * Shared header and navbar for admin pages
 *
 * NOTE: This file outputs HTML. Include admin-init.php FIRST
 * before including this file to ensure proper initialization.
 *
 * Usage:
 * 1. require_once 'admin-init.php';  // BEFORE <head>
 * 2. ... <head> with CSS links ...
 * 3. require_once 'includes/admin-header.php';  // AFTER <head>
 */

// Load permissions system
require_once __DIR__ . '/permissions.php';

// Get the current user's permissions (cached for this request)
$_user_permissions = getUserPermissions($user['id']);

/**
 * Check if nav item should be shown for current user
 */
function _canShowNavItem($permission_key) {
    global $_user_permissions;
    if (!$permission_key) return true; // No permission required (e.g. "View Website")
    return isset($_user_permissions[$permission_key]) && $_user_permissions[$permission_key];
}
?>
<div class="admin-header">
    <h1><i class="fas fa-hotel"></i> <?php echo htmlspecialchars($site_name); ?></h1>
    <div class="user-info">
        <div>
            <div class="user-name"><?php echo htmlspecialchars($user['full_name']); ?></div>
            <div class="user-role"><?php echo htmlspecialchars($user['role']); ?></div>
        </div>
        <button class="admin-nav-toggle" id="adminNavToggle" aria-label="Toggle navigation" aria-expanded="false">
            <i class="fas fa-bars" id="navToggleIcon"></i>
        </button>
        <a href="logout.php" class="btn-logout">
            <i class="fas fa-sign-out-alt"></i> <span>Logout</span>
        </a>
    </div>
</div>
<nav class="admin-nav">
    <ul>

        <!-- ── OVERVIEW ─────────────────────────────────── -->
        <?php if (_canShowNavItem('dashboard')): ?>
        <li><a href="dashboard.php" class="<?php echo $current_page === 'dashboard.php' ? 'active' : ''; ?>"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
        <?php endif; ?>

        <!-- ── RESERVATIONS ──────────────────────────────── -->
        <?php if (_canShowNavItem('bookings') || _canShowNavItem('calendar') || _canShowNavItem('blocked_dates')): ?>
        <li class="nav-section-label">Reservations</li>
        <?php endif; ?>
        <?php if (_canShowNavItem('bookings')): ?>
        <li><a href="bookings.php" class="<?php echo $current_page === 'bookings.php' ? 'active' : ''; ?>"><i class="fas fa-calendar-check"></i> Bookings</a></li>
        <?php endif; ?>
        <?php if (_canShowNavItem('calendar')): ?>
        <li><a href="calendar.php" class="<?php echo $current_page === 'calendar.php' ? 'active' : ''; ?>"><i class="fas fa-calendar"></i> Calendar</a></li>
        <?php endif; ?>
        <?php if (_canShowNavItem('blocked_dates')): ?>
        <li><a href="blocked-dates.php" class="<?php echo $current_page === 'blocked-dates.php' ? 'active' : ''; ?>"><i class="fas fa-ban"></i> Blocked Dates</a></li>
        <?php endif; ?>

        <!-- ── FINANCE ───────────────────────────────────── -->
        <?php if (_canShowNavItem('payments') || _canShowNavItem('payment_add') || _canShowNavItem('invoices') || _canShowNavItem('accounting') || _canShowNavItem('reports')): ?>
        <li class="nav-section-label">Finance</li>
        <?php endif; ?>
        <?php if (_canShowNavItem('payments')): ?>
        <li><a href="payments.php" class="<?php echo $current_page === 'payments.php' ? 'active' : ''; ?>"><i class="fas fa-money-bill-wave"></i> Payments</a></li>
        <?php endif; ?>
        <?php if (_canShowNavItem('payment_add')): ?>
        <li><a href="payment-add.php" class="<?php echo $current_page === 'payment-add.php' ? 'active' : ''; ?>"><i class="fas fa-plus-circle"></i> Add Payment</a></li>
        <?php endif; ?>
        <?php if (_canShowNavItem('invoices')): ?>
        <li><a href="invoices.php" class="<?php echo $current_page === 'invoices.php' ? 'active' : ''; ?>"><i class="fas fa-file-invoice-dollar"></i> Invoices</a></li>
        <?php endif; ?>
        <?php if (_canShowNavItem('accounting')): ?>
        <li><a href="accounting-dashboard.php" class="<?php echo $current_page === 'accounting-dashboard.php' ? 'active' : ''; ?>"><i class="fas fa-calculator"></i> Accounting</a></li>
        <?php endif; ?>
        <?php if (_canShowNavItem('reports')): ?>
        <li><a href="reports.php" class="<?php echo $current_page === 'reports.php' ? 'active' : ''; ?>"><i class="fas fa-chart-bar"></i> Reports</a></li>
        <li><a href="visitor-analytics.php" class="<?php echo $current_page === 'visitor-analytics.php' ? 'active' : ''; ?>"><i class="fas fa-chart-line"></i> Visitor Analytics</a></li>
        <?php endif; ?>

        <!-- ── MARKETING ─────────────────────────────────── -->
        <?php if (_canShowNavItem('campaigns')): ?>
        <li class="nav-section-label">Marketing</li>
        <li><a href="campaigns.php" class="<?php echo $current_page === 'campaigns.php' ? 'active' : ''; ?>"><i class="fas fa-bullhorn"></i> Campaigns</a></li>
        <?php endif; ?>

        <!-- ── SERVICES ──────────────────────────────────── -->
        <?php if (_canShowNavItem('conference') || _canShowNavItem('events') || _canShowNavItem('rooms') || _canShowNavItem('employees') || _canShowNavItem('gym') || _canShowNavItem('gym_management') || _canShowNavItem('maintenance')): ?>
        <li class="nav-section-label">Services</li>
        <?php endif; ?>
        <?php if (_canShowNavItem('employees')): ?>
        <li><a href="employees.php" class="<?php echo $current_page === 'employees.php' ? 'active' : ''; ?>"><i class="fas fa-user-tie"></i> Employees</a></li>
        <?php endif; ?>
        <?php if (_canShowNavItem('maintenance')): ?>
        <li><a href="maintenance.php" class="<?php echo $current_page === 'maintenance.php' ? 'active' : ''; ?>"><i class="fas fa-tools"></i> Maintenance</a></li>
        <?php endif; ?>
        <?php if (_canShowNavItem('conference')): ?>
        <li><a href="conference-management.php" class="<?php echo $current_page === 'conference-management.php' ? 'active' : ''; ?>"><i class="fas fa-briefcase"></i> Conference Rooms</a></li>
        <?php endif; ?>
        <?php if (_canShowNavItem('events')): ?>
        <li><a href="events-management.php" class="<?php echo $current_page === 'events-management.php' ? 'active' : ''; ?>"><i class="fas fa-calendar-alt"></i> Events</a></li>
        <?php endif; ?>
        <?php if (_canShowNavItem('rooms')): ?>
        <li><a href="room-management.php" class="<?php echo $current_page === 'room-management.php' ? 'active' : ''; ?>"><i class="fas fa-bed"></i> Rooms</a></li>
        <li><a href="room-promotions.php" class="<?php echo $current_page === 'room-promotions.php' ? 'active' : ''; ?>"><i class="fas fa-tags"></i> Room Promotions</a></li>
        <?php endif; ?>
        <?php if (_canShowNavItem('gym')): ?>
        <li><a href="gym-inquiries.php" class="<?php echo $current_page === 'gym-inquiries.php' ? 'active' : ''; ?>"><i class="fas fa-dumbbell"></i> Gym Inquiries</a></li>
        <?php endif; ?>
        <?php if (_canShowNavItem('gym_management')): ?>
        <li><a href="gym-management.php" class="<?php echo $current_page === 'gym-management.php' ? 'active' : ''; ?>"><i class="fas fa-sliders-h"></i> Gym Management</a></li>
        <?php endif; ?>

        <!-- ── CONTENT ───────────────────────────────────── -->
        <?php if (_canShowNavItem('reviews') || _canShowNavItem('gallery') || _canShowNavItem('menu') || _canShowNavItem('pages') || _canShowNavItem('section_headers')): ?>
        <li class="nav-section-label">Content</li>
        <?php endif; ?>
        <?php if (_canShowNavItem('reviews')): ?>
        <li><a href="reviews.php" class="<?php echo $current_page === 'reviews.php' ? 'active' : ''; ?>"><i class="fas fa-star"></i> Reviews</a></li>
        <?php endif; ?>
        <?php if (_canShowNavItem('gallery')): ?>
        <li><a href="gallery-management.php" class="<?php echo $current_page === 'gallery-management.php' ? 'active' : ''; ?>"><i class="fas fa-images"></i> Gallery</a></li>
        <?php endif; ?>
        <?php if (_canShowNavItem('menu')): ?>
        <li><a href="menu-management.php" class="<?php echo $current_page === 'menu-management.php' ? 'active' : ''; ?>"><i class="fas fa-utensils"></i> Menu</a></li>
        <?php endif; ?>
        <?php if (_canShowNavItem('pages')): ?>
        <li><a href="page-management.php" class="<?php echo $current_page === 'page-management.php' ? 'active' : ''; ?>"><i class="fas fa-file-alt"></i> Page Management</a></li>
        <?php endif; ?>
        <?php if (_canShowNavItem('section_headers')): ?>
        <li><a href="section-headers-management.php" class="<?php echo $current_page === 'section-headers-management.php' ? 'active' : ''; ?>"><i class="fas fa-heading"></i> Section Headers</a></li>
        <?php endif; ?>

        <!-- ── SETTINGS ──────────────────────────────────── -->
        <?php if (_canShowNavItem('theme') || _canShowNavItem('booking_settings') || _canShowNavItem('cache') || _canShowNavItem('activity_logs') || _canShowNavItem('user_management') || ($user['role'] ?? '') === 'admin'): ?>
        <li class="nav-section-label">Settings</li>
        <?php endif; ?>
        <?php if (_canShowNavItem('theme')): ?>
        <li><a href="theme-management.php" class="<?php echo $current_page === 'theme-management.php' ? 'active' : ''; ?>"><i class="fas fa-palette"></i> Theme</a></li>
        <?php endif; ?>
        <?php if (_canShowNavItem('booking_settings')): ?>
        <li><a href="booking-settings.php" class="<?php echo $current_page === 'booking-settings.php' ? 'active' : ''; ?>"><i class="fas fa-cog"></i> Booking Settings</a></li>
        <?php endif; ?>
        <?php if (_canShowNavItem('cache')): ?>
        <li><a href="cache-management.php" class="<?php echo $current_page === 'cache-management.php' ? 'active' : ''; ?>"><i class="fas fa-bolt"></i> Cache</a></li>
        <?php endif; ?>
        <?php if (_canShowNavItem('activity_logs')): ?>
        <li><a href="activity-log.php" class="<?php echo $current_page === 'activity-log.php' ? 'active' : ''; ?>"><i class="fas fa-history"></i> Activity Logs</a></li>
        <?php endif; ?>
        <?php if (_canShowNavItem('user_management')): ?>
        <li><a href="user-management.php" class="<?php echo $current_page === 'user-management.php' ? 'active' : ''; ?>"><i class="fas fa-users-cog"></i> User Management</a></li>
        <?php endif; ?>
        <?php if (($user['role'] ?? '') === 'admin'): ?>
        <li><a href="api-keys.php" class="<?php echo $current_page === 'api-keys.php' ? 'active' : ''; ?>"><i class="fas fa-file-code"></i> PHP API Client</a></li>
        <li><a href="deleted-records-backup.php" class="<?php echo $current_page === 'deleted-records-backup.php' ? 'active' : ''; ?>"><i class="fas fa-database"></i> Deleted Backups</a></li>
        <?php endif; ?>

        <!-- ── EXTERNAL ──────────────────────────────────── -->
        <li class="nav-section-label">External</li>
        <li><a href="../index.php" target="_blank"><i class="fas fa-external-link-alt"></i> View Website</a></li>

    </ul>
</nav>
<style>
    /* ── Nav section labels / separators ──────────────── */
    .admin-nav li.nav-section-label {
        display: block;
        padding: 16px 20px 4px;
        font-size: 9px;
        font-weight: 700;
        letter-spacing: 0.12em;
        text-transform: uppercase;
        color: rgba(212, 175, 55, 0.75);  /* gold at reduced opacity */
        pointer-events: none;
        user-select: none;
        border-top: 1px solid rgba(255, 255, 255, 0.06);
        margin-top: 4px;
    }

    /* No top border on the very first label (Dashboard stands alone) */
    .admin-nav li.nav-section-label:first-of-type {
        border-top: none;
        margin-top: 0;
    }

    /* ── Admin content loader ─────────────────────────── */
    .admin-content-loader {
        position: fixed;
        inset: 0;
        display: none;
        align-items: center;
        justify-content: center;
        background: rgba(15, 23, 42, 0.35);
        backdrop-filter: blur(2px);
        z-index: 2000;
        pointer-events: none;
    }

    .admin-content-loader.show {
        display: flex;
    }

    .admin-content-loader-card {
        display: inline-flex;
        align-items: center;
        gap: 12px;
        background: #ffffff;
        color: #0f172a;
        border: 1px solid #e2e8f0;
        border-radius: 12px;
        padding: 12px 16px;
        box-shadow: 0 12px 24px rgba(15, 23, 42, 0.18);
        font-weight: 600;
        font-size: 14px;
    }

    .admin-content-loader-spinner {
        width: 20px;
        height: 20px;
        border: 3px solid #e2e8f0;
        border-top-color: var(--gold);
        border-radius: 50%;
        animation: adminLoaderSpin 0.8s linear infinite;
    }

    @keyframes adminLoaderSpin {
        to {
            transform: rotate(360deg);
        }
    }

</style>
<div class="admin-content-loader" id="adminContentLoader" aria-hidden="true">
    <div class="admin-content-loader-card" role="status" aria-live="polite">
        <span class="admin-content-loader-spinner" aria-hidden="true"></span>
        <span>Loading section...</span>
    </div>
</div>
<script>
(function() {
    var toggle = document.getElementById('adminNavToggle');
    var nav = document.querySelector('.admin-nav');
    var icon = document.getElementById('navToggleIcon');
    var loader = document.getElementById('adminContentLoader');
    var contentContainer = document.querySelector('.content, .admin-content');

    function getContentContainer() {
        if (!contentContainer || !document.body.contains(contentContainer)) {
            contentContainer = document.querySelector('.content, .admin-content');
        }
        return contentContainer;
    }

    function clearStuckOverlays() {
        if (loader) {
            loader.classList.remove('show');
            loader.setAttribute('aria-hidden', 'true');
        }

        var container = getContentContainer();
        if (container) {
            container.setAttribute('aria-busy', 'false');
        }

        document.querySelectorAll('.modal-overlay.active, [data-modal-overlay].active').forEach(function(el) {
            el.classList.remove('active');
        });
        document.querySelectorAll('.modal-wrapper.active, [data-modal].active').forEach(function(el) {
            el.classList.remove('active');
        });
        document.querySelectorAll('.bk-modal-overlay.open').forEach(function(el) {
            el.classList.remove('open');
        });
        document.body.classList.remove('modal-open');
    }

    function setLoadingState(isLoading) {
        var container = getContentContainer();
        if (!loader || !container) {
            return;
        }

        loader.classList.toggle('show', isLoading);
        loader.setAttribute('aria-hidden', isLoading ? 'false' : 'true');
        container.setAttribute('aria-busy', isLoading ? 'true' : 'false');
    }

    function updateActiveNav(pathname) {
        if (!nav) return;
        var currentPath = (pathname || '').split('/').pop();
        nav.querySelectorAll('a').forEach(function(link) {
            var href = link.getAttribute('href') || '';
            var linkPath = href.split('?')[0].split('#')[0].split('/').pop();
            var shouldActivate = currentPath && linkPath === currentPath;
            link.classList.toggle('active', shouldActivate);
        });
    }

    function closeMobileNav() {
        if (!toggle || !nav || !icon) {
            return;
        }

        nav.classList.remove('nav-open');
        toggle.setAttribute('aria-expanded', 'false');
        icon.className = 'fas fa-bars';
    }

    function normalizeNavForViewport() {
        if (!toggle || !nav || !icon) {
            return;
        }

        if (window.innerWidth > 768) {
            // Ensure no mobile-only state leaks into desktop after resize.
            closeMobileNav();
            nav.style.maxHeight = '';
        }
    }

    function syncDynamicStyles(doc) {
        // Remove previous page-level injected styles.
        document.querySelectorAll('style[data-admin-dynamic-style]').forEach(function(styleTag) {
            styleTag.remove();
        });

        // Re-apply inline styles from fetched page head/body so per-page styling is preserved.
        doc.querySelectorAll('head style, body style').forEach(function(styleTag) {
            var cloned = document.createElement('style');
            cloned.setAttribute('data-admin-dynamic-style', 'true');
            cloned.textContent = styleTag.textContent || '';
            document.head.appendChild(cloned);
        });
    }

    async function runPageScripts(doc, remoteContent, requestUrl) {
        var scripts = [];
        doc.querySelectorAll('script').forEach(function(script) {
            if (!remoteContent || !(remoteContent.compareDocumentPosition(script) & Node.DOCUMENT_POSITION_FOLLOWING)) {
                return;
            }

            var src = (script.getAttribute('src') || '').trim();
            if (src && /js\/admin-(components|mobile)\.js(?:\?|$)/i.test(src)) {
                return;
            }

            scripts.push({
                src: src ? new URL(src, requestUrl).href : null,
                code: script.textContent || ''
            });
        });

        for (var i = 0; i < scripts.length; i++) {
            var scriptInfo = scripts[i];
            if (scriptInfo.src) {
                var existingScript = Array.prototype.find.call(document.querySelectorAll('script[src]'), function(existing) {
                    return new URL(existing.src, window.location.origin).href === scriptInfo.src;
                });

                if (existingScript) {
                    continue;
                }

                var scriptEl = document.createElement('script');
                scriptEl.src = scriptInfo.src;
                scriptEl.async = false;
                await new Promise(function(resolve) {
                    scriptEl.onload = resolve;
                    scriptEl.onerror = resolve;
                    document.body.appendChild(scriptEl);
                });
            } else if (scriptInfo.code.trim()) {
                try {
                    new Function(scriptInfo.code)();
                } catch (err) {
                    console.error('Failed to run loaded script', err);
                }
            }
        }
    }

    async function loadAdminContent(url, pushState) {
        var container = getContentContainer();
        if (!container) {
            window.location.href = url;
            return;
        }

        setLoadingState(true);

        try {
            var response = await fetch(url, {
                credentials: 'same-origin',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            });

            if (!response.ok) {
                throw new Error('Navigation request failed.');
            }

            var html = await response.text();
            var doc = new DOMParser().parseFromString(html, 'text/html');
            var remoteContent = doc.querySelector('.content');

            if (!remoteContent) {
                window.location.href = url;
                return;
            }

            container.innerHTML = remoteContent.innerHTML;

            if (doc.title) {
                document.title = doc.title;
            }

            updateActiveNav(new URL(url, window.location.origin).pathname);

            if (pushState) {
                window.history.pushState({ path: url }, '', url);
            }

            await runPageScripts(doc, remoteContent, url);
                syncDynamicStyles(doc);
            document.dispatchEvent(new CustomEvent('admin:contentLoaded', { detail: { url: url } }));
            window.scrollTo({ top: 0, behavior: 'smooth' });
        } catch (error) {
            console.error('Admin content load failed', error);
            window.location.href = url;
        } finally {
            setLoadingState(false);
            closeMobileNav();
        }
    }

    function shouldUseAjaxNavigation(link, event) {
        if (!link || !getContentContainer()) {
            return false;
        }

        if (event && (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey)) {
            return false;
        }

        if (link.target === '_blank' || link.hasAttribute('download') || link.hasAttribute('data-no-ajax')) {
            return false;
        }

        var href = (link.getAttribute('href') || '').trim();
        if (!href || href.startsWith('#') || href.startsWith('mailto:') || href.startsWith('tel:') || href.toLowerCase().startsWith('javascript:')) {
            return false;
        }

        var targetUrl;
        try {
            targetUrl = new URL(link.href, window.location.href);
        } catch (error) {
            return false;
        }

        if (targetUrl.origin !== window.location.origin) {
            return false;
        }

        if (targetUrl.pathname.indexOf('/admin/') === -1) {
            return false;
        }

        var fileName = targetUrl.pathname.split('/').pop().toLowerCase();
        if (!fileName || !/\.php$/i.test(fileName) || fileName === 'logout.php') {
            return false;
        }

        var currentUrl = new URL(window.location.href);
        if (targetUrl.pathname === currentUrl.pathname && targetUrl.search === currentUrl.search && targetUrl.hash === currentUrl.hash) {
            return false;
        }

        return true;
    }

    clearStuckOverlays();
    window.addEventListener('pageshow', clearStuckOverlays);
    document.addEventListener('visibilitychange', function() {
        if (document.visibilityState === 'visible') {
            clearStuckOverlays();
        }
    });

    if (toggle && nav) {
        toggle.addEventListener('click', function() {
            var isOpen = nav.classList.toggle('nav-open');
            toggle.setAttribute('aria-expanded', isOpen);
            icon.className = isOpen ? 'fas fa-times' : 'fas fa-bars';
        });

        document.addEventListener('click', function(event) {
            var link = event.target.closest('a');
            if (!shouldUseAjaxNavigation(link, event)) {
                return;
            }

            event.preventDefault();
            loadAdminContent(link.href, true);
        });

            // Show loader on synchronous form submissions across admin pages.
            document.addEventListener('submit', function(event) {
                var form = event.target;
                if (!form || !form.matches('form')) {
                    return;
                }
                if (form.hasAttribute('data-no-loader')) {
                    return;
                }

                setLoadingState(true);
                // Safety fallback if navigation is blocked by validation or prevented submit.
                window.setTimeout(function() {
                    setLoadingState(false);
                }, 8000);
            });

        document.addEventListener('click', function(e) {
            if (window.innerWidth <= 768 && !nav.contains(e.target) && !toggle.contains(e.target)) {
                nav.classList.remove('nav-open');
                toggle.setAttribute('aria-expanded', 'false');
                icon.className = 'fas fa-bars';
            }
        });

        window.addEventListener('popstate', function() {
            loadAdminContent(window.location.href, false);
        });

        window.addEventListener('resize', normalizeNavForViewport);
        normalizeNavForViewport();

        updateActiveNav(window.location.pathname);
    }
})();
</script>
