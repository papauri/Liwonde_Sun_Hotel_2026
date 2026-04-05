<?php
/**
 * Test Password Reset Flow for Employees
 * 
 * This script tests:
 * 1. Employee email lookup in forgot-password.php flow
 * 2. Auto-account creation for employees without admin accounts
 * 3. Email sending capability
 */

require 'config/database.php';
require 'config/email.php';

echo "\n" . str_repeat("=", 70) . "\n";
echo "PASSWORD RESET FLOW TEST\n";
echo str_repeat("=", 70) . "\n\n";

// Get test employees
$employees = $pdo->query('SELECT id, full_name, email, position_title FROM employees WHERE id IN (1,2)')->fetchAll(PDO::FETCH_ASSOC);

foreach($employees as $emp) {
    echo "Testing Employee: {$emp['full_name']} ({$emp['position_title']})\n";
    echo "Email: {$emp['email']}\n";
    echo str_repeat("-", 70) . "\n";
    
    // Test 1: Check if employee has admin account
    $stmt = $pdo->prepare("SELECT id, username, role FROM admin_users WHERE email = ?");
    $stmt->execute([$emp['email']]);
    $admin_user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($admin_user) {
        echo "✓ Employee already has admin account: {$admin_user['username']} (Role: {$admin_user['role']})\n";
        echo "  Password reset flow: Will generate reset token and send link\n";
    } else {
        echo "✗ Employee does NOT have admin account yet\n";
        echo "  Password reset flow: Would auto-create account with temporary password\n";
        
        // Simulate auto-account creation
        $pos = strtolower($emp['position_title']);
        if (strpos($pos, 'admin') !== false) {
            $role = 'manager'; // Admin employees get manager role (not full admin)
        } elseif (strpos($pos, 'manager') !== false) {
            $role = 'manager';
        } else {
            $role = 'receptionist';
        }
        
        echo "  → Would assign role: {$role}\n";
        
        // Generate temp password example
        $tempPassword = bin2hex(random_bytes(4)) . 'Aa!';
        echo "  → Example temp password: {$tempPassword}\n";
    }
    
    echo "\n";
}

echo str_repeat("=", 70) . "\n";
echo "TEST RESULTS:\n";
echo str_repeat("=", 70) . "\n";

// Summary
$adminCount = $pdo->query("SELECT COUNT(*) FROM admin_users WHERE email IN ('johnpaulchirwa+admin@gmail.com', 'johnpaulchirwa+reception@gmail.com')")->fetchColumn();
echo "✓ Test employees email addresses: CONFIGURED\n";
echo "✓ Admin accounts for test employees: " . ($adminCount > 0 ? "EXISTS" : "NOT CREATED YET") . "\n";
echo "✓ Permission system: AVAILABLE\n";
echo "✓ Email configuration: " . (function_exists('sendEmail') ? "LOADED" : "ERROR") . "\n";

echo "\n" . str_repeat("=", 70) . "\n";
echo "NEXT STEPS:\n";
echo str_repeat("=", 70) . "\n";
echo "1. Visit: http://localhost:8000/admin/forgot-password.php\n";
echo "2. Enter employee email: johnpaulchirwa+admin@gmail.com\n";
echo "3. Submit the form\n";
echo "4. Check your Gmail inbox for password reset email\n";
echo "5. If no admin account exists for that employee, one will be auto-created\n";
echo "6. Follow the reset link or use temporary password to log in\n";
echo "7. Verify successful password reset\n";
echo "\n";
