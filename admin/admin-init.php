<?php
/**
 * Admin Initialization
 * PHP-only initialization for admin pages (no HTML output)
 * 
 * This file MUST be included BEFORE any HTML output
 * 
 * Features:
 * - Secure session management
 * - CSRF token generation
 * - Security headers
 * - Database connection
 * - User data setup
 */

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Load security configuration first
require_once '../config/security.php';

// Define admin access constant (for security checks in included files)
define('ADMIN_ACCESS', true);

// Check authentication
if (!isset($_SESSION['admin_user_id'])) {
    header('Location: login.php');
    exit;
}

require_once '../config/database.php';

// Send security headers
sendSecurityHeaders();

$site_name = getSetting('site_name');
$user = [
    'id' => $_SESSION['admin_user_id'],
    'username' => $_SESSION['admin_username'],
    'role' => $_SESSION['admin_role'],
    'full_name' => $_SESSION['admin_full_name']
];
$current_page = basename($_SERVER['PHP_SELF']);

// Generate CSRF token for use in forms
$csrf_token = generateCsrfToken();

// ---- Permission-based Access Control ----
// Load permissions system and enforce page-level access
require_once __DIR__ . '/includes/permissions.php';

$_required_permission = getPermissionForPage($current_page);
if ($_required_permission !== null && !hasPermission($user['id'], $_required_permission)) {
    // User doesn't have access to this page
    header('Location: dashboard.php?error=access_denied');
    exit;
}
?>
