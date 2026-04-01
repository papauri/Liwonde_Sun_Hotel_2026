<?php
// Ensure proper output buffering for command-line execution
error_reporting(E_ALL);
ini_set('display_errors', '1');

require 'config/database.php';

// Add restaurant opening hours to site_settings
// Default hours: Breakfast 06:00-10:00, Lunch 12:00-15:00, Dinner 18:00-22:00

$restaurant_hours = [
    'restaurant_breakfast_start' => '06:00',
    'restaurant_breakfast_end' => '10:00',
    'restaurant_lunch_start' => '12:00',
    'restaurant_lunch_end' => '15:00',
    'restaurant_dinner_start' => '18:00',
    'restaurant_dinner_end' => '22:00',
    'restaurant_min_advance_days' => '1',  // Minimum 1 day in advance
];

try {
    echo "Database Connection Successful!" . PHP_EOL;
    echo "Adding restaurant opening hours to site_settings..." . PHP_EOL . PHP_EOL;
    
    $stmt = $pdo->prepare("
        INSERT INTO site_settings (setting_key, setting_value, setting_group, updated_at)
        VALUES (?, ?, 'restaurant', NOW())
        ON DUPLICATE KEY UPDATE 
            setting_value = VALUES(setting_value),
            updated_at = NOW()
    ");
    
    foreach ($restaurant_hours as $key => $value) {
        $stmt->execute([$key, $value]);
        echo "✓ {$key} => {$value}" . PHP_EOL;
    }
    
    echo PHP_EOL . "Restaurant opening hours seeded successfully!" . PHP_EOL;
    
    // Verify all settings were saved
    echo PHP_EOL . "Verification:" . PHP_EOL;
    $stmt = $pdo->prepare("SELECT setting_key, setting_value FROM site_settings WHERE setting_group = 'restaurant' ORDER BY setting_key");
    $stmt->execute();
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        echo "  " . $row['setting_key'] . " = " . $row['setting_value'] . PHP_EOL;
    }
    
} catch (PDOException $e) {
    echo "PDOException Error: " . $e->getMessage() . PHP_EOL;
} catch (Exception $e) {
    echo "Exception Error: " . $e->getMessage() . PHP_EOL;
}

ob_flush();
flush();
?>
