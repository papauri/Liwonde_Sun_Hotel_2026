<?php
require 'config/database.php';

// Test: Create a sample restaurant reservation
echo "=== Restaurant Reservation Insertion Test ===" . PHP_EOL . PHP_EOL;

try {
    $test_reference = 'REST-TEST' . substr(uniqid(), -4);
    
    $stmt = $pdo->prepare("
        INSERT INTO restaurant_inquiries 
        (reference_number, name, email, phone, preferred_date, preferred_time, guests, occasion, special_requests, status)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    
    $result = $stmt->execute([
        $test_reference,
        'Test User',
        'test@example.com',
        '+27123456789',
        date('Y-m-d', strtotime('+7 days')),
        '19:00',
        4,
        'Birthday Celebration',
        'Window seat preferred, no peanuts due to allergy',
        'new'
    ]);
    
    if ($result) {
        echo "✓ Test reservation inserted successfully!" . PHP_EOL;
        echo "  Reference: " . $test_reference . PHP_EOL;
        echo "  ID: " . $pdo->lastInsertId() . PHP_EOL . PHP_EOL;
        
        // Verify the insertion
        $stmt = $pdo->prepare("SELECT * FROM restaurant_inquiries WHERE reference_number = ?");
        $stmt->execute([$test_reference]);
        $reservation = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($reservation) {
            echo "✓ Verification: Reservation retrieved successfully!" . PHP_EOL;
            echo "  Name: " . $reservation['name'] . PHP_EOL;
            echo "  Email: " . $reservation['email'] . PHP_EOL;
            echo "  Date: " . $reservation['preferred_date'] . " at " . $reservation['preferred_time'] . PHP_EOL;
            echo "  Guests: " . $reservation['guests'] . PHP_EOL;
            echo "  Status: " . $reservation['status'] . PHP_EOL;
            echo "  Created: " . $reservation['created_at'] . PHP_EOL;
        }
    } else {
        echo "✗ Failed to insert test reservation" . PHP_EOL;
    }
} catch (PDOException $e) {
    echo "✗ Error: " . $e->getMessage() . PHP_EOL;
}

echo PHP_EOL . "=== Table Statistics ===" . PHP_EOL;

try {
    $result = $pdo->query("SELECT COUNT(*) as total FROM restaurant_inquiries");
    $count = $result->fetch(PDO::FETCH_ASSOC);
    echo "Total reservations in table: " . $count['total'] . PHP_EOL;
    
    $result = $pdo->query("SELECT status, COUNT(*) as count FROM restaurant_inquiries GROUP BY status");
    echo PHP_EOL . "Reservations by status:" . PHP_EOL;
    while ($row = $result->fetch(PDO::FETCH_ASSOC)) {
        echo "  " . ucfirst($row['status']) . ": " . $row['count'] . PHP_EOL;
    }
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage() . PHP_EOL;
}

echo PHP_EOL . "All tests completed!" . PHP_EOL;
?>
