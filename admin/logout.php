<?php
session_start();

// Log the logout before destroying session
if (isset($_SESSION['admin_user_id'])) {
    try {
        require_once '../config/database.php';
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $ua = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500);
        $stmt = $pdo->prepare("INSERT INTO admin_activity_log (user_id, username, action, details, ip_address, user_agent) VALUES (?, ?, 'logout', 'User logged out', ?, ?)");
        $stmt->execute([$_SESSION['admin_user_id'], $_SESSION['admin_username'] ?? '', $ip, $ua]);
    } catch (Exception $e) {
        // Don't block logout if logging fails
    }
}

// Clear all admin session variables
unset($_SESSION['admin_user']);
unset($_SESSION['admin_user_id']);
unset($_SESSION['admin_username']);
unset($_SESSION['admin_role']);
unset($_SESSION['admin_full_name']);
session_destroy();
header('Location: login.php');
exit;
