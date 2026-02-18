<?php
/**
 * Database Migration: Add User Permissions System
 * Run this once to add the required tables and columns
 * 
 * Access via browser: https://yoursite.com/admin/migrations/add-user-permissions.php
 */

require_once '../../config/database.php';

// Security check
session_start();
if (!isset($_SESSION['admin_role']) || $_SESSION['admin_role'] !== 'admin') {
    echo "Access denied. Admin role required.";
    exit;
}

echo "<pre>";
echo "=================================================================\n";
echo "  User Permissions Migration\n";
echo "=================================================================\n\n";

$errors = [];
$successes = [];

// 1. Create password_resets table
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS password_resets (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            token VARCHAR(255) NOT NULL,
            expires_at DATETIME NOT NULL,
            used_at DATETIME DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_token (token),
            INDEX idx_user_id (user_id),
            CONSTRAINT fk_password_resets_user FOREIGN KEY (user_id) REFERENCES admin_users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $successes[] = "password_resets table created/verified";
} catch (PDOException $e) {
    if (strpos($e->getMessage(), 'already exists') !== false) {
        $successes[] = "password_resets table already exists";
    } else {
        $errors[] = "password_resets: " . $e->getMessage();
    }
}

// 2. Create user_permissions table
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS user_permissions (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            permission_key VARCHAR(100) NOT NULL,
            is_granted TINYINT(1) NOT NULL DEFAULT 1,
            granted_by INT UNSIGNED DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uk_user_permission (user_id, permission_key),
            INDEX idx_user_id (user_id),
            CONSTRAINT fk_user_permissions_user FOREIGN KEY (user_id) REFERENCES admin_users(id) ON DELETE CASCADE,
            CONSTRAINT fk_user_permissions_granted_by FOREIGN KEY (granted_by) REFERENCES admin_users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $successes[] = "user_permissions table created/verified";
} catch (PDOException $e) {
    if (strpos($e->getMessage(), 'already exists') !== false) {
        $successes[] = "user_permissions table already exists";
    } else {
        $errors[] = "user_permissions: " . $e->getMessage();
    }
}

// 3. Add email column to admin_users if missing
try {
    $stmt = $pdo->query("SHOW COLUMNS FROM admin_users LIKE 'email'");
    if ($stmt->rowCount() === 0) {
        $pdo->exec("ALTER TABLE admin_users ADD COLUMN email VARCHAR(255) NOT NULL DEFAULT '' AFTER username");
        $successes[] = "Added email column to admin_users";
    } else {
        $successes[] = "admin_users.email column already exists";
    }
} catch (PDOException $e) {
    $errors[] = "admin_users.email: " . $e->getMessage();
}

// Print results
echo "Results:\n";
echo "--------\n";
foreach ($successes as $s) {
    echo "  ✓ $s\n";
}
foreach ($errors as $e) {
    echo "  ✗ $e\n";
}

echo "\n=================================================================\n";
echo empty($errors) ? "Migration completed successfully!\n" : "Migration completed with errors.\n";
echo "=================================================================\n";
echo "</pre>";

echo '<br><a href="../user-management.php">Go to User Management</a>';
?>
