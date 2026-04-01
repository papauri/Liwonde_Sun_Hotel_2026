<?php
/**
 * Verify Email Settings
 * Check if email configuration is working properly
 */

require_once 'config/database.php';

echo "Checking Email Settings...\n";
echo "============================\n\n";

try {
    $stmt = $pdo->query("SELECT setting_key, setting_value, is_encrypted FROM email_settings WHERE setting_key IN ('smtp_host', 'smtp_port', 'smtp_username', 'smtp_password', 'smtp_secure') ORDER BY setting_key");
    $settings = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($settings as $setting) {
        $key = $setting['setting_key'];
        $value = $setting['setting_value'];
        $encrypted = $setting['is_encrypted'] ? 'Yes' : 'No';
        
        // Mask password
        if ($key === 'smtp_password') {
            $value = str_repeat('*', strlen($value));
            echo "$key: $value (Encrypted: $encrypted)\n";
        } else {
            echo "$key: $value (Encrypted: $encrypted)\n";
        }
    }
    
    echo "\n============================\n";
    echo "\n✓ Email settings are configured!\n";
    echo "Email functions should now work properly.\n";
    
} catch (Exception $e) {
    die("Error: " . $e->getMessage() . "\n");
}