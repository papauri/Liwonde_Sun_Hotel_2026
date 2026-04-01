<?php
/**
 * Run Encryption Functions Migration
 * This script creates the MySQL encryption/decryption functions
 * and updates the SMTP password to work properly
 */

require_once 'config/database.php';

echo "Starting Encryption Migration...\n";
echo "=====================================\n\n";

try {
    // Read the migration SQL file
    $sqlFile = __DIR__ . '/Database/migrations/add-encryption-functions.sql';
    if (!file_exists($sqlFile)) {
        die("Migration file not found: $sqlFile\n");
    }
    
    $sql = file_get_contents($sqlFile);
    
    // Split the SQL into individual statements
    $statements = array_filter(array_map('trim', explode(';', $sql)));
    
    foreach ($statements as $index => $statement) {
        if (empty($statement)) continue;
        
        echo "Running statement " . ($index + 1) . "...\n";
        
        try {
            $pdo->exec($statement);
            echo "✓ Success\n\n";
        } catch (PDOException $e) {
            echo "✗ Error: " . $e->getMessage() . "\n\n";
            // Continue even if some statements fail (like IF NOT EXISTS)
        }
    }
    
    echo "=====================================\n";
    echo "Migration completed!\n";
    echo "\nVerifying SMTP password setting...\n";
    
    $stmt = $pdo->query("SELECT setting_key, LEFT(setting_value, 3) as password_preview, is_encrypted FROM email_settings WHERE setting_key = 'smtp_password'");
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($result) {
        echo "Password preview: " . $result['password_preview'] . "...\n";
        echo "Encrypted: " . ($result['is_encrypted'] ? 'Yes' : 'No') . "\n";
        echo "\n✓ Email functions should now work properly!\n";
    } else {
        echo "Warning: smtp_password not found in email_settings\n";
    }
    
} catch (Exception $e) {
    die("Error: " . $e->getMessage() . "\n");
}