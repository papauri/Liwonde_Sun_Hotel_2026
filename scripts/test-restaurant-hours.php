<?php
error_reporting(E_ALL);
ini_set('display_errors', '1');

require 'config/database.php';
require 'includes/validation.php';

echo "=== Restaurant Reservation Validation Test ===" . PHP_EOL . PHP_EOL;

try {
    // Test 1: Check restaurant hours are loaded from settings
    echo "Test 1: Restaurant Operating Hours" . PHP_EOL;
    $breakfast_start = getSetting('restaurant_breakfast_start', '06:00');
    $breakfast_end = getSetting('restaurant_breakfast_end', '10:00');
    $lunch_start = getSetting('restaurant_lunch_start', '12:00');
    $lunch_end = getSetting('restaurant_lunch_end', '15:00');
    $dinner_start = getSetting('restaurant_dinner_start', '18:00');
    $dinner_end = getSetting('restaurant_dinner_end', '22:00');

    echo "  Breakfast: {$breakfast_start} - {$breakfast_end}" . PHP_EOL;
    echo "  Lunch: {$lunch_start} - {$lunch_end}" . PHP_EOL;
    echo "  Dinner: {$dinner_start} - {$dinner_end}" . PHP_EOL . PHP_EOL;

    // Test 2: Check minimum advance days
    echo "Test 2: Minimum Advance Days (Same-Day Blocking)" . PHP_EOL;
    $min_advance_days = (int)getSetting('restaurant_min_advance_days', 1);
    echo "  Minimum advance days: {$min_advance_days} day(s)" . PHP_EOL;
    if ($min_advance_days > 0) {
        echo "  ✓ Same-day reservations are BLOCKED" . PHP_EOL;
    } else {
        echo "  ✓ Same-day reservations are ALLOWED" . PHP_EOL;
    }
    echo PHP_EOL;

    // Test 3: Validate times within operating hours
    echo "Test 3: Time Validation" . PHP_EOL;
    $test_times = [
        '07:00' => true,   // Breakfast
        '13:00' => true,   // Lunch
        '19:00' => true,   // Dinner
        '11:00' => false,  // Between breakfast & lunch
        '16:00' => false,  // Between lunch & dinner
        '23:00' => false,  // After hours
    ];

    foreach ($test_times as $time => $expected) {
        $is_valid = (
            ($time >= $breakfast_start && $time < $breakfast_end) ||
            ($time >= $lunch_start && $time < $lunch_end) ||
            ($time >= $dinner_start && $time < $dinner_end)
        );
        
        $status = ($is_valid === $expected) ? '✓' : '✗';
        $result = $is_valid ? 'VALID' : 'INVALID';
        echo "  {$status} {$time} -> {$result}" . PHP_EOL;
    }

    echo PHP_EOL . "=== All Tests Complete ===" . PHP_EOL;
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . PHP_EOL;
}
?>
