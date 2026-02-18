<?php
/**
 * Admin Directory Index
 * Redirects to login page if not authenticated, or dashboard if authenticated
 */

session_start();

// If user is logged in, redirect to dashboard
if (isset($_SESSION['admin_user_id'])) {
    header('Location: dashboard.php');
    exit;
}

// Otherwise, redirect to login page
header('Location: login.php');
exit;
?>
