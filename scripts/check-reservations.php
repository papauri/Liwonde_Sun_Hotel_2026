<?php
require 'config/database.php';

try {
    // Count current reservations
    $result = $pdo->query("SELECT COUNT(*) as total FROM restaurant_inquiries");
    $count = $result->fetch(PDO::FETCH_ASSOC);
    echo "Total reservations in table: " . $count['total'] . PHP_EOL;
    
    // Show existing reservations
    $result = $pdo->query("SELECT reference_number, name, preferred_date, status FROM restaurant_inquiries ORDER BY created_at DESC LIMIT 5");
    echo "\nLatest 5 reservations:" . PHP_EOL;
    while ($row = $result->fetch(PDO::FETCH_ASSOC)) {
        echo "- " . $row['reference_number'] . " | " . $row['name'] . " | " . $row['preferred_date'] . " | " . $row['status'] . PHP_EOL;
    }
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage() . PHP_EOL;
}
?>