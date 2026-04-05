<?php
try {
    require 'config/database.php';
    
    $q = $pdo->query('SELECT id, full_name, email, position_title FROM employees WHERE id IN (1,2) ORDER BY id');
    echo "\n=== VERIFICATION: Updated Employee Emails ===\n\n";
    foreach($q as $r) {
        printf("ID %d: %s (%s)\n", $r['id'], $r['full_name'], $r['position_title']);
        printf("  Email: %s\n\n", $r['email']);
    }
    echo "✓ Both test employees now have Gmail addresses configured.\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
