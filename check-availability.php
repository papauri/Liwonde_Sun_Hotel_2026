<?php
/**
 * Room Availability Check Endpoint (AJAX)
 * Called by booking.php JavaScript to check live availability
 * Returns JSON response with availability status
 */

require_once 'config/database.php';

ensureChildOccupancyInfrastructure();

header('Content-Type: application/json');

// Rate limiting: max 30 availability checks per minute per session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$rate_key = 'avail_checks';
if (!isset($_SESSION[$rate_key])) {
    $_SESSION[$rate_key] = [];
}
$_SESSION[$rate_key] = array_filter($_SESSION[$rate_key], function($t) {
    return $t > time() - 60;
});
if (count($_SESSION[$rate_key]) >= 30) {
    echo json_encode(['available' => false, 'message' => 'Too many requests. Please wait a moment.']);
    exit;
}
$_SESSION[$rate_key][] = time();

try {
    $room_id = isset($_GET['room_id']) ? (int)$_GET['room_id'] : 0;
    $check_in = $_GET['check_in'] ?? '';
    $check_out = $_GET['check_out'] ?? '';

    // Validate inputs
    if (!$room_id || empty($check_in) || empty($check_out)) {
        echo json_encode([
            'available' => false,
            'message' => 'Missing required parameters: room_id, check_in, check_out'
        ]);
        exit;
    }

    // Validate dates
    $checkInDate = new DateTime($check_in);
    $checkOutDate = new DateTime($check_out);
    $today = new DateTime('today');

    if ($checkInDate < $today) {
        echo json_encode([
            'available' => false,
            'message' => 'Check-in date cannot be in the past'
        ]);
        exit;
    }

    if ($checkOutDate <= $checkInDate) {
        echo json_encode([
            'available' => false,
            'message' => 'Check-out date must be after check-in date'
        ]);
        exit;
    }

    // Check advance booking restriction
    $maxAdvanceDays = (int)getSetting('max_advance_booking_days', 365);
    $maxAdvanceDate = new DateTime();
    $maxAdvanceDate->modify('+' . $maxAdvanceDays . ' days');

    if ($checkInDate > $maxAdvanceDate) {
        echo json_encode([
            'available' => false,
            'message' => "Bookings can only be made up to {$maxAdvanceDays} days in advance."
        ]);
        exit;
    }

    // Check if room exists and is active
    $roomStmt = $pdo->prepare("
        SELECT id, name, price_per_night, price_single_occupancy, price_double_occupancy, 
                   price_child_occupancy, max_guests, rooms_available, total_rooms
        FROM rooms 
        WHERE id = ? AND is_active = 1
    ");
    $roomStmt->execute([$room_id]);
    $room = $roomStmt->fetch(PDO::FETCH_ASSOC);

    if (!$room) {
        echo json_encode([
            'available' => false,
            'message' => 'Room not found or not available'
        ]);
        exit;
    }

    // Use the availability checking function from database.php
    $availability = checkRoomAvailability($room_id, $check_in, $check_out);
    $promoPricing = getRoomEffectivePricing($room, 'double', $check_in);
    $singlePricing = getRoomEffectivePricing($room, 'single', $check_in);
    $doublePricing = getRoomEffectivePricing($room, 'double', $check_in);
    $childPricing = getRoomEffectivePricing($room, 'child', $check_in);
    $effectiveNightlyRate = (float)($promoPricing['final_price'] ?? $room['price_per_night']);
    $originalNightlyRate = (float)($promoPricing['base_price'] ?? $room['price_per_night']);

    if ($availability['available']) {
        $nights = $checkInDate->diff($checkOutDate)->days;
        $total = $effectiveNightlyRate * $nights;

        echo json_encode([
            'available' => true,
            'room' => [
                'id' => (int)$room['id'],
                'name' => $room['name'],
                'price_per_night' => $effectiveNightlyRate,
                'price_per_night_original' => $originalNightlyRate,
                'price_single_occupancy' => (float)($singlePricing['final_price'] ?? $room['price_per_night']),
                'price_double_occupancy' => (float)($doublePricing['final_price'] ?? $room['price_per_night']),
                'price_child_occupancy' => isset($childPricing['final_price']) ? (float)$childPricing['final_price'] : null,
                'has_child_occupancy' => !empty($childPricing['child_available']),
                'has_active_promo' => !empty($promoPricing['has_promo']),
                'promo_title' => $promoPricing['promotion']['title'] ?? null,
                'promo_discount_percent' => (float)($promoPricing['discount_percent'] ?? 0),
                'max_guests' => (int)$room['max_guests'],
                'rooms_available' => (int)$room['rooms_available']
            ],
            'room_units_available' => (int)($availability['room_units_available'] ?? 0),
            'suggested_room_unit_id' => isset($availability['suggested_room_unit_id']) ? (int)$availability['suggested_room_unit_id'] : null,
            'suggested_room_unit_label' => $availability['suggested_room_unit_label'] ?? null,
            'nights' => $nights,
            'total' => $total,
            'message' => 'Room is available for your selected dates'
        ]);
    } else {
        echo json_encode([
            'available' => false,
            'message' => $availability['error'] ?? 'This room is not available for the selected dates.',
            'conflicts' => $availability['conflicts'] ?? [],
            'conflict_message' => $availability['conflict_message'] ?? ''
        ]);
    }

} catch (Exception $e) {
    error_log("Availability check error: " . $e->getMessage());
    echo json_encode([
        'available' => false,
        'message' => 'Unable to check availability. Please try again.'
    ]);
}