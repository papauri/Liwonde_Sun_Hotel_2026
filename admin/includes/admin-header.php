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
        <?php if (_canShowNavItem('dashboard')): ?>
        <li><a href="dashboard.php" class="<?php echo $current_page === 'dashboard.php' ? 'active' : ''; ?>"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
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
        <?php if (_canShowNavItem('rooms')): ?>
        <li><a href="room-management.php" class="<?php echo $current_page === 'room-management.php' ? 'active' : ''; ?>"><i class="fas fa-bed"></i> Rooms</a></li>
        <?php endif; ?>
        <?php if (_canShowNavItem('gallery')): ?>
        <li><a href="gallery-management.php" class="<?php echo $current_page === 'gallery-management.php' ? 'active' : ''; ?>"><i class="fas fa-images"></i> Gallery</a></li>
        <?php endif; ?>
        <?php if (_canShowNavItem('conference')): ?>
        <li><a href="conference-management.php" class="<?php echo $current_page === 'conference-management.php' ? 'active' : ''; ?>"><i class="fas fa-briefcase"></i> Conference Rooms</a></li>
        <?php endif; ?>
        <?php if (_canShowNavItem('gym')): ?>
        <li><a href="gym-inquiries.php" class="<?php echo $current_page === 'gym-inquiries.php' ? 'active' : ''; ?>"><i class="fas fa-dumbbell"></i> Gym Inquiries</a></li>
        <?php endif; ?>
        <?php if (_canShowNavItem('menu')): ?>
        <li><a href="menu-management.php" class="<?php echo $current_page === 'menu-management.php' ? 'active' : ''; ?>"><i class="fas fa-utensils"></i> Menu</a></li>
        <?php endif; ?>
        <?php if (_canShowNavItem('events')): ?>
        <li><a href="events-management.php" class="<?php echo $current_page === 'events-management.php' ? 'active' : ''; ?>"><i class="fas fa-calendar-alt"></i> Events</a></li>
        <?php endif; ?>
        <?php if (_canShowNavItem('reviews')): ?>
        <li><a href="reviews.php" class="<?php echo $current_page === 'reviews.php' ? 'active' : ''; ?>"><i class="fas fa-star"></i> Reviews</a></li>
        <?php endif; ?>
        <?php if (_canShowNavItem('accounting')): ?>
        <li><a href="accounting-dashboard.php" class="<?php echo $current_page === 'accounting-dashboard.php' ? 'active' : ''; ?>"><i class="fas fa-calculator"></i> Accounting</a></li>
        <?php endif; ?>
        <?php if (_canShowNavItem('payments')): ?>
        <li><a href="payments.php" class="<?php echo $current_page === 'payments.php' ? 'active' : ''; ?>"><i class="fas fa-money-bill-wave"></i> Payments</a></li>
        <?php endif; ?>
        <?php if (_canShowNavItem('invoices')): ?>
        <li><a href="invoices.php" class="<?php echo $current_page === 'invoices.php' ? 'active' : ''; ?>"><i class="fas fa-file-invoice-dollar"></i> Invoices</a></li>
        <?php endif; ?>
        <?php if (_canShowNavItem('payment_add')): ?>
        <li><a href="payment-add.php" class="<?php echo $current_page === 'payment-add.php' ? 'active' : ''; ?>"><i class="fas fa-plus-circle"></i> Add Payment</a></li>
        <?php endif; ?>
        <?php if (_canShowNavItem('reports')): ?>
        <li><a href="reports.php" class="<?php echo $current_page === 'reports.php' ? 'active' : ''; ?>"><i class="fas fa-chart-bar"></i> Reports</a></li>
        <?php endif; ?>
        <li><a href="../index.php" target="_blank"><i class="fas fa-external-link-alt"></i> View Website</a></li>
        <?php if (_canShowNavItem('theme')): ?>
        <li><a href="theme-management.php" class="<?php echo $current_page === 'theme-management.php' ? 'active' : ''; ?>"><i class="fas fa-palette"></i> Theme Management</a></li>
        <?php endif; ?>
        <?php if (_canShowNavItem('section_headers')): ?>
        <li><a href="section-headers-management.php" class="<?php echo $current_page === 'section-headers' ? 'active' : ''; ?>"><i class="fas fa-heading"></i> Section Headers</a></li>
        <?php endif; ?>
        <?php if (_canShowNavItem('booking_settings')): ?>
        <li><a href="booking-settings.php" class="<?php echo $current_page === 'booking-settings.php' ? 'active' : ''; ?>"><i class="fas fa-cog"></i> Booking Settings</a></li>
        <?php endif; ?>
        <?php if (_canShowNavItem('pages')): ?>
        <li><a href="page-management.php" class="<?php echo $current_page === 'page-management.php' ? 'active' : ''; ?>"><i class="fas fa-file-alt"></i> Page Management</a></li>
        <?php endif; ?>
        <?php if (_canShowNavItem('cache')): ?>
        <li><a href="cache-management.php" class="<?php echo $current_page === 'cache-management.php' ? 'active' : ''; ?>"><i class="fas fa-bolt"></i> Cache Management</a></li>
        <?php endif; ?>
        <?php if (_canShowNavItem('user_management')): ?>
        <li><a href="user-management.php" class="<?php echo $current_page === 'user-management.php' ? 'active' : ''; ?>"><i class="fas fa-users-cog"></i> User Management</a></li>
        <?php endif; ?>
        <?php if (_canShowNavItem('reports')): ?>
        <li><a href="visitor-analytics.php" class="<?php echo $current_page === 'visitor-analytics.php' ? 'active' : ''; ?>"><i class="fas fa-chart-line"></i> Visitor Analytics</a></li>
        <?php endif; ?>
    </ul>
</nav>
<script>
(function() {
    var toggle = document.getElementById('adminNavToggle');
    var nav = document.querySelector('.admin-nav');
    var icon = document.getElementById('navToggleIcon');
    if (toggle && nav) {
        toggle.addEventListener('click', function() {
            var isOpen = nav.classList.toggle('nav-open');
            toggle.setAttribute('aria-expanded', isOpen);
            icon.className = isOpen ? 'fas fa-times' : 'fas fa-bars';
        });
        // Close nav when a link is clicked (mobile)
        nav.querySelectorAll('a').forEach(function(link) {
            link.addEventListener('click', function() {
                if (window.innerWidth <= 768) {
                    nav.classList.remove('nav-open');
                    toggle.setAttribute('aria-expanded', 'false');
                    icon.className = 'fas fa-bars';
                }
            });
        });
        // Close nav when clicking outside
        document.addEventListener('click', function(e) {
            if (window.innerWidth <= 768 && !nav.contains(e.target) && !toggle.contains(e.target)) {
                nav.classList.remove('nav-open');
                toggle.setAttribute('aria-expanded', 'false');
                icon.className = 'fas fa-bars';
            }
        });
    }
})();
</script>