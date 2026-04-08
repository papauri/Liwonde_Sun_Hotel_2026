<?php
session_start();

// Log the logout before destroying session
if (isset($_SESSION['admin_user_id'])) {
    try {
        require_once '../config/database.php';
        require_once '../includes/activity-logger.php';
        ensureActivityLogInfrastructure($pdo);
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $ua = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500);
        $userId = (int)($_SESSION['admin_user_id'] ?? 0);
        $username = $_SESSION['admin_username'] ?? '';

        logAdminActivity($pdo, $userId, $username, 'logout', 'User logged out', $ip, $ua);

        $empRef = resolveEmployeeForAdminUser($pdo, $userId);
        if (!empty($empRef['employee_id'])) {
            logEmployeeActivity($pdo, (int)$empRef['employee_id'], $userId, $userId, 'logout', 'Employee-linked user logout', 'admin_logout', $ip, $ua);
        }
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