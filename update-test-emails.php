<?php
try {
    // Load database config with shorter timeout
    require 'config/database.php';
    
    // Update test employees
    $sql = "UPDATE employees SET email = CASE 
        WHEN id = 1 THEN 'johnpaulchirwa+admin@gmail.com'
        WHEN id = 2 THEN 'johnpaulchirwa+reception@gmail.com'
    END WHERE id IN (1, 2)";
    
    $pdo->exec($sql);
    echo "Success! Test employees updated.\n";
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
