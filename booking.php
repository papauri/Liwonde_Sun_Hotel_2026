<?php
/**
 * Room Booking Page with Enhanced Security
 * Features:
 * - CSRF protection
 * - Secure session management
 * - Input validation
 */

// Start session first (before loading security.php which uses $_SESSION)
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Load security configuration first
require_once 'config/security.php';

require_once 'config/database.php';
require_once 'includes/page-guard.php';
require_once 'config/email.php';
require_once 'includes/validation.php';

// Send security headers
sendSecurityHeaders();

// Handle booking submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validate CSRF token
    requireCsrfValidation();
    
    try {
        // Rate limiting: max 5 booking submissions per 10 minutes
        if (!isset($_SESSION['booking_attempts'])) {
            $_SESSION['booking_attempts'] = [];
        }
        $_SESSION['booking_attempts'] = array_filter($_SESSION['booking_attempts'], function($t) {
            return $t > time() - 600;
        });
        if (count($_SESSION['booking_attempts']) >= 5) {
            throw new Exception('Too many booking attempts. Please wait a few minutes before trying again.');
        }
        $_SESSION['booking_attempts'][] = time();

        // Initialize validation errors array
        $validation_errors = [];
        $sanitized_data = [];
        
        // Validate room_id
        $room_validation = validateRoomId($_POST['room_id'] ?? '');
        if (!$room_validation['valid']) {
            $validation_errors['room_id'] = $room_validation['error'];
        } else {
            $sanitized_data['room_id'] = $room_validation['room']['id'];
        }
        
        // Validate guest_name
        $name_validation = validateName($_POST['guest_name'] ?? '', 2, true);
        if (!$name_validation['valid']) {
            $validation_errors['guest_name'] = $name_validation['error'];
        } else {
            $sanitized_data['guest_name'] = sanitizeString($name_validation['value'], 100);
        }
        
        // Validate guest_email
        $guest_email_value = $_POST['guest_email'] ?? '';
        
        if (empty($guest_email_value)) {
            $validation_errors['guest_email'] = 'Guest email is required';
        } else {
            $guest_email_value = trim($guest_email_value);
            
            if (!filter_var($guest_email_value, FILTER_VALIDATE_EMAIL)) {
                $validation_errors['guest_email'] = 'Please enter a valid email address';
            } else {
                $sanitized_data['guest_email'] = sanitizeString($guest_email_value, 254);
            }
        }
        
        // Validate guest_phone
        $phone_validation = validatePhone($_POST['guest_phone'] ?? '');
        if (!$phone_validation['valid']) {
            $validation_errors['guest_phone'] = $phone_validation['error'];
        } else {
            $sanitized_data['guest_phone'] = $phone_validation['sanitized'];
        }
        
        // Validate guest_country (optional)
        $country_validation = validateText($_POST['guest_country'] ?? '', 0, 100, false);
        if (!$country_validation['valid']) {
            $validation_errors['guest_country'] = $country_validation['error'];
        } else {
            $sanitized_data['guest_country'] = sanitizeString($country_validation['value'], 100);
        }
        
        // Validate guest_address (optional)
        $address_validation = validateText($_POST['guest_address'] ?? '', 0, 500, false);
        if (!$address_validation['valid']) {
            $validation_errors['guest_address'] = $address_validation['error'];
        } else {
            $sanitized_data['guest_address'] = sanitizeString($address_validation['value'], 500);
        }
        
        // Validate number_of_guests
        $guests_validation = validateNumber($_POST['number_of_guests'] ?? '', 1, 20, true);
        if (!$guests_validation['valid']) {
            $validation_errors['number_of_guests'] = $guests_validation['error'];
        } else {
            $sanitized_data['number_of_guests'] = $guests_validation['value'];
        }
        
        // Validate check_in_date
        $check_in_validation = validateDate($_POST['check_in_date'] ?? '', false, true);
        if (!$check_in_validation['valid']) {
            $validation_errors['check_in_date'] = $check_in_validation['error'];
        } else {
            $sanitized_data['check_in_date'] = $check_in_validation['date']->format('Y-m-d');
        }
        
        // Validate check_out_date
        $check_out_validation = validateDate($_POST['check_out_date'] ?? '', false, true);
        if (!$check_out_validation['valid']) {
            $validation_errors['check_out_date'] = $check_out_validation['error'];
        } else {
            $sanitized_data['check_out_date'] = $check_out_validation['date']->format('Y-m-d');
        }
        
        // Validate date range
        if (empty($validation_errors['check_in_date']) && empty($validation_errors['check_out_date'])) {
            $date_range_validation = validateDateRange($sanitized_data['check_in_date'], $sanitized_data['check_out_date'], 30);
            if (!$date_range_validation['valid']) {
                $validation_errors['dates'] = $date_range_validation['error'];
            }
        }
        
        // Validate special_requests (optional)
        $requests_validation = validateText($_POST['special_requests'] ?? '', 0, 1000, false);
        if (!$requests_validation['valid']) {
            $validation_errors['special_requests'] = $requests_validation['error'];
        } else {
            $sanitized_data['special_requests'] = sanitizeString($requests_validation['value'], 1000);
        }
        
        // Check for validation errors
        if (!empty($validation_errors)) {
            $error_messages = [];
            foreach ($validation_errors as $field => $message) {
                $error_messages[] = ucfirst(str_replace('_', ' ', $field)) . ': ' . $message;
            }
            throw new Exception(implode('; ', $error_messages));
        }
        
        // Use enhanced validation with availability check
        $validation_result = validateBookingWithAvailability($sanitized_data);

        if (!$validation_result['valid']) {
            // Handle validation errors
            if ($validation_result['type'] === 'availability') {
                // Room availability issue - provide detailed conflict info
                $conflict_message = $validation_result['errors']['availability'];
                if (!empty($validation_result['conflicts'])) {
                    $conflict_message .= ' ' . $validation_result['errors']['conflicts'];
                }
                throw new Exception($conflict_message);
            } elseif ($validation_result['type'] === 'capacity') {
                // Room capacity issue
                throw new Exception($validation_result['errors']['number_of_guests']);
            } else {
                // General validation errors
                $error_messages = [];
                foreach ($validation_result['errors'] as $field => $message) {
                    $error_messages[] = "$field: $message";
                }
                throw new Exception(implode('; ', $error_messages));
            }
        }

        // All validations passed - proceed with booking
        $room_id = $sanitized_data['room_id'];
        $guest_name = $sanitized_data['guest_name'];
        $guest_email = $sanitized_data['guest_email'];
        $guest_phone = $sanitized_data['guest_phone'];
        $guest_country = $sanitized_data['guest_country'];
        $guest_address = $sanitized_data['guest_address'];
        $number_of_guests = $sanitized_data['number_of_guests'];
        $check_in_date = $sanitized_data['check_in_date'];
        $check_out_date = $sanitized_data['check_out_date'];
        $special_requests = $sanitized_data['special_requests'];

        // Get booking type (standard or tentative)
        $is_tentative_booking = isset($_POST['booking_type']) && $_POST['booking_type'] === 'tentative';
        
        // Get room details for pricing
        $room = $validation_result['availability']['room'];
        $number_of_nights = $validation_result['availability']['nights'];
        
        // Determine pricing based on occupancy type
        $occupancy_type = $_POST['occupancy_type'] ?? 'double';
        
        // Get the correct price based on occupancy
        if ($occupancy_type === 'single' && !empty($room['price_single_occupancy'])) {
            $room_price = $room['price_single_occupancy'];
        } elseif ($occupancy_type === 'double' && !empty($room['price_double_occupancy'])) {
            $room_price = $room['price_double_occupancy'];
        } elseif ($occupancy_type === 'triple' && !empty($room['price_triple_occupancy'])) {
            $room_price = $room['price_triple_occupancy'];
        } else {
            // Fallback to default price
            $room_price = $room['price_per_night'];
        }
        
        $total_amount = $room_price * $number_of_nights;

        // Check for duplicate bookings (same email, room, overlapping dates)
        $dup_check = $pdo->prepare("
            SELECT COUNT(*) as count FROM bookings 
            WHERE guest_email = ? AND room_id = ? 
            AND status IN ('pending', 'tentative', 'confirmed', 'checked-in')
            AND check_in_date = ? AND check_out_date = ?
        ");
        $dup_check->execute([$sanitized_data['guest_email'], $sanitized_data['room_id'], $check_in, $check_out]);
        if ($dup_check->fetch(PDO::FETCH_ASSOC)['count'] > 0) {
            throw new Exception('A booking already exists for these dates and room. Please check your existing bookings.');
        }

        // Generate unique booking reference (guaranteed unique)
        $ref_prefix = getSetting('booking_reference_prefix', 'LSH');
        do {
            $booking_reference = $ref_prefix . date('Y') . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
            $ref_check = $pdo->prepare("SELECT COUNT(*) as count FROM bookings WHERE booking_reference = ?");
            $ref_check->execute([$booking_reference]);
            $ref_exists = $ref_check->fetch(PDO::FETCH_ASSOC)['count'] > 0;
        } while ($ref_exists);

        // Determine status and tentative expiration
        $booking_status = $is_tentative_booking ? 'tentative' : 'pending';
        $is_tentative = $is_tentative_booking ? 1 : 0;
        $tentative_expires_at = null;
        
        if ($is_tentative_booking) {
            // Get tentative duration from settings (default 48 hours)
            $tentative_duration_hours = (int)getSetting('tentative_duration_hours', 48);
            $tentative_expires_at = date('Y-m-d H:i:s', strtotime("+{$tentative_duration_hours} hours"));
        }

        // Insert booking with transaction for data integrity
        $pdo->beginTransaction(); // Start transaction
        
        try {
            $insert_stmt = $pdo->prepare("
                INSERT INTO bookings (
                    booking_reference, room_id, guest_name, guest_email, guest_phone,
                    guest_country, guest_address, number_of_guests, check_in_date,
                    check_out_date, number_of_nights, total_amount, special_requests, status,
                    is_tentative, tentative_expires_at, occupancy_type
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");

            $insert_stmt->execute([
                $booking_reference, $room_id, $guest_name, $guest_email, $guest_phone,
                $guest_country, $guest_address, $number_of_guests, $check_in_date,
                $check_out_date, $number_of_nights, $total_amount, $special_requests,
                $booking_status, $is_tentative, $tentative_expires_at, $occupancy_type
            ]);

            // Commit transaction - booking secured with foreign key constraints!
            $pdo->commit();

            // Send email notifications using working email system
            $booking_data = [
                'id' => $pdo->lastInsertId(),
                'booking_reference' => $booking_reference,
                'room_id' => $room_id,
                'guest_name' => $guest_name,
                'guest_email' => $guest_email,
                'guest_phone' => $guest_phone,
                'check_in_date' => $check_in_date,
                'check_out_date' => $check_out_date,
                'number_of_nights' => $number_of_nights,
                'number_of_guests' => $number_of_guests,
                'total_amount' => $total_amount,
                'special_requests' => $special_requests,
                'status' => $booking_status,
                'is_tentative' => $is_tentative,
                'tentative_expires_at' => $tentative_expires_at,
                'occupancy_type' => $occupancy_type,
                'room_price' => $room_price
            ];
            
            // Send appropriate email based on booking type
            if ($is_tentative_booking) {
                // Send tentative booking confirmation email
                $email_result = sendTentativeBookingConfirmedEmail($booking_data);
                $log_type = "Tentative booking confirmed";
            } else {
                // Send standard booking received email
                $email_result = sendBookingReceivedEmail($booking_data);
                $log_type = "Booking received";
            }
            
            // Log email result for debugging
            if (!$email_result['success']) {
                error_log("Failed to send {$log_type} email: " . $email_result['message']);
            } else {
                // Log success with preview URL if available
                $logMsg = "{$log_type} email processed (PHPMailer)";
                if (isset($email_result['preview_url'])) {
                    $logMsg .= " - Preview: " . $email_result['preview_url'];
                }
                error_log($logMsg);
            }
            
            // Send notification to admin (simplified PHPMailer)
            $admin_result = sendAdminNotificationEmail($booking_data);
            
            if (!$admin_result['success']) {
                error_log("Failed to send admin notification: " . $admin_result['message']);
            } else {
                // Log success with preview URL if available
                $logMsg = "Admin notification processed (PHPMailer)";
                if (isset($admin_result['preview_url'])) {
                    $logMsg .= " - Preview: " . $admin_result['preview_url'];
                }
                error_log($logMsg);
            }

            // Success - redirect to confirmation
            $_SESSION['booking_success'] = [
                'reference' => $booking_reference,
                'guest_name' => $guest_name,
                'room_name' => $room['name'],
                'check_in' => $check_in_date,
                'check_out' => $check_out_date,
                'nights' => $number_of_nights,
                'total' => $total_amount,
                'email_sent' => $email_result['success'],
                'is_tentative' => $is_tentative,
                'tentative_expires_at' => $tentative_expires_at
            ];

            header('Location: booking-confirmation.php?ref=' . $booking_reference);
            exit;
            
        } catch (Exception $e) {
            // Rollback on insert error
            $pdo->rollBack();
            throw $e;
        }

    } catch (Exception $e) {
        // Rollback transaction on any error
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $error_message = $e->getMessage();
    }
}

// Get pre-selected room from URL
$preselected_room_id = isset($_GET['room_id']) ? (int)$_GET['room_id'] : null;
$preselected_room = null;

// Fetch available rooms for booking form with all details needed for validation
$rooms_stmt = $pdo->query("SELECT id, name, price_per_night, price_single_occupancy, price_double_occupancy, price_triple_occupancy, max_guests, rooms_available, total_rooms, short_description, image_url FROM rooms WHERE is_active = 1 ORDER BY display_order ASC");
$available_rooms = $rooms_stmt->fetchAll(PDO::FETCH_ASSOC);

        // Build rooms data for JavaScript with occupancy pricing
        $rooms_data = [];
foreach ($available_rooms as $room) {
    $rooms_data[] = [
        'id' => (int)$room['id'],
        'name' => $room['name'],
        'max_guests' => (int)$room['max_guests'],
        'price_per_night' => (float)$room['price_per_night'],
        'price_single_occupancy' => (float)($room['price_single_occupancy'] ?? $room['price_per_night']),
        'price_double_occupancy' => (float)($room['price_double_occupancy'] ?? $room['price_per_night'] * 1.2),
        'price_triple_occupancy' => (float)($room['price_triple_occupancy'] ?? $room['price_per_night'] * 1.4),
        'rooms_available' => (int)$room['rooms_available'],
        'total_rooms' => (int)$room['total_rooms']
    ];
}

// Get pre-selected room details
if ($preselected_room_id) {
    foreach ($available_rooms as $room) {
        if ($room['id'] == $preselected_room_id) {
            $preselected_room = $room;
            break;
        }
    }
}

// Fetch site settings
$site_name = getSetting('site_name');
$site_logo = getSetting('site_logo');
$currency_symbol = getSetting('currency_symbol');
$phone_main = getSetting('phone_main');
$email_reservations = getSetting('email_reservations');
$email_reservations_esc = addslashes($email_reservations); // For JavaScript

// Get maximum advance booking days
$max_advance_days = (int)getSetting('max_advance_booking_days');
$max_advance_date = date('Y-m-d', strtotime("+{$max_advance_days} days"));

// Get blocked dates for the pre-selected room (or all rooms)
$blocked_dates = [];
if ($preselected_room_id) {
    $blocked_dates = getBlockedDates($preselected_room_id);
} else {
    $blocked_dates = getBlockedDates();
}

// Format blocked dates for JavaScript
$blocked_dates_array = [];
foreach ($blocked_dates as $bd) {
    $blocked_dates_array[] = $bd['block_date'];
}

// Get payment policy
$payment_policy = getSetting('payment_policy');

// Fetch policies for footer modals
$policies = [];
try {
    $policyStmt = $pdo->query("SELECT slug, title, summary, content FROM policies WHERE is_active = 1 ORDER BY display_order ASC, id ASC");
    $policies = $policyStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error fetching policies: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0, user-scalable=yes, viewport-fit=cover">
    <meta name="theme-color" content="#0A1929">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="format-detection" content="telephone=yes">
    <title>Book Your Stay | <?php echo htmlspecialchars($site_name); ?></title>
    
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;500;600;700&family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet" media="print" onload="this.media='all'">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" media="print" onload="this.media='all'">
    <link rel="stylesheet" href="css/theme-dynamic.php">
    <link rel="stylesheet" href="css/header.css">
    <link rel="stylesheet" href="css/style.css">
    <link rel="stylesheet" href="css/footer.css">
    <link rel="stylesheet" href="booking-new-styles.css">
    <!-- Flatpickr CSS -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <style>
        /* Minimal inline styles - all booking styles moved to booking-new-styles.css */
        .booking-page {
            padding-top: 90px;
        }
        
        @media (max-width: 768px) {
            .booking-page {
                padding-top: 70px;
            }
        }
    </style>
</head>
<body class="booking-page">
    <?php include 'includes/loader.php'; ?>
    
    <?php include 'includes/header.php'; ?>
    <?php include 'includes/alert.php'; ?>
    
    <div class="booking-container">
        <div class="booking-header">
            <h1>Book Your Stay</h1>
            <p>Complete the form below to reserve your room. Our team will confirm your booking shortly.</p>
        </div>

        <?php if (isset($error_message)): ?>
            <?php showAlert($error_message, 'error'); ?>
        <?php endif; ?>

        <form method="POST" action="<?php echo htmlspecialchars($_SERVER['PHP_SELF'] . (isset($_GET['room_id']) ? '?room_id=' . (int)$_GET['room_id'] : '')); ?>" class="booking-form-card" id="bookingForm">
            <?php echo getCsrfField(); ?>
            <!-- Room Selection (hidden if pre-selected) -->
            <?php if (!$preselected_room): ?>
            <div class="form-section">
                <h3 class="form-section-title"><i class="fas fa-bed"></i> Select Your Room</h3>
                <div class="room-selection">
                    <?php foreach ($available_rooms as $room): ?>
                    <label class="room-option" onclick="selectRoom(this)" data-room-id="<?php echo $room['id']; ?>" data-room-name="<?php echo htmlspecialchars($room['name']); ?>" data-room-price="<?php echo $room['price_per_night']; ?>" data-max-guests="<?php echo $room['max_guests']; ?>" data-rooms-available="<?php echo $room['rooms_available']; ?>">
                        <input type="radio" name="room_id" value="<?php echo $room['id']; ?>" required>
                        <div class="room-info">
                            <h4><?php echo htmlspecialchars($room['name']); ?></h4>
                            <p><?php echo htmlspecialchars($room['short_description']); ?></p>
                            <p><i class="fas fa-users"></i> Max <?php echo $room['max_guests']; ?> guests <?php echo $room['rooms_available'] > 1 ? "({$room['rooms_available']} rooms available)" : ''; ?></p>
                        </div>
                        <div class="room-price">
                            <div class="room-price-amount"><?php echo $currency_symbol; ?><?php echo number_format($room['price_per_night'], 0); ?></div>
                            <div class="room-price-period">per night</div>
                        </div>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- Booking Type Selection -->
            <div class="form-section">
                <h3 class="form-section-title"><i class="fas fa-clipboard-list"></i> Booking Type</h3>
                <div class="booking-type-selection">
                    <label class="booking-type-option" onclick="selectBookingType('standard')">
                        <input type="radio" name="booking_type" value="standard" checked>
                        <div class="booking-type-content">
                            <div class="booking-type-header">
                                <i class="fas fa-check-circle"></i>
                                <h4>Standard Booking</h4>
                            </div>
                            <p class="booking-type-description">
                                Confirm your booking immediately. Our team will review and confirm your reservation within 24 hours.
                                Payment details will be provided upon confirmation.
                            </p>
                            <div class="booking-type-badge recommended">
                                <i class="fas fa-star"></i> Recommended
                            </div>
                        </div>
                    </label>
                    
                    <label class="booking-type-option" onclick="selectBookingType('tentative')">
                        <input type="radio" name="booking_type" value="tentative">
                        <div class="booking-type-content">
                            <div class="booking-type-header">
                                <i class="fas fa-clock"></i>
                                <h4>Tentative Booking</h4>
                            </div>
                            <p class="booking-type-description">
                                Place this room on temporary hold for <?php echo (int)getSetting('tentative_duration_hours', 48); ?> hours without immediate confirmation.
                                Perfect when you need time to finalize travel plans. You'll receive a reminder before expiration.
                            </p>
                            <div class="booking-type-badge info">
                                <i class="fas fa-info-circle"></i> No payment required yet
                            </div>
                        </div>
                    </label>
                </div>
                <p style="margin-top: 15px; color: #666; font-size: 13px; text-align: center;">
                    <i class="fas fa-lightbulb" style="color: var(--gold);"></i>
                    <strong>Tentative bookings</strong> can be converted to standard bookings anytime before expiration.
                    After expiration, the room hold will be released automatically.
                </p>
            </div>

            <!-- Pre-selected Room Info (shown if room is pre-selected) -->
            <?php if ($preselected_room): ?>
            <div class="form-section">
                <h3 class="form-section-title"><i class="fas fa-bed"></i> Selected Room</h3>
                <div class="room-selection">
                    <div class="room-option selected" style="border-color: var(--gold); background: rgba(212, 175, 55, 0.1);">
                        <input type="hidden" name="room_id" value="<?php echo $preselected_room['id']; ?>" id="preselectedRoomId">
                        <div class="room-info">
                            <h4><?php echo htmlspecialchars($preselected_room['name']); ?></h4>
                            <p><?php echo htmlspecialchars($preselected_room['short_description']); ?></p>
                            <p><i class="fas fa-users"></i> Max <?php echo $preselected_room['max_guests']; ?> guests <?php echo $preselected_room['rooms_available'] > 1 ? "({$preselected_room['rooms_available']} rooms available)" : ''; ?></p>
                        </div>
                        <div class="room-price">
                            <div class="room-price-amount"><?php echo $currency_symbol; ?><?php echo number_format($preselected_room['price_per_night'], 0); ?></div>
                            <div class="room-price-period">per night</div>
                        </div>
                    </div>
                </div>
                <p style="margin-top: 10px; color: #666; font-size: 14px;">
                    <a href="booking.php" style="color: var(--gold); text-decoration: none;">
                        <i class="fas fa-arrow-left"></i> Choose a different room
                    </a>
                </p>
            </div>
            <?php endif; ?>

            <!-- Guest Information -->
            <div class="form-section">
                <h3 class="form-section-title"><i class="fas fa-user"></i> Guest Information</h3>
                <div class="form-grid">
                    <div class="form-group">
                        <label for="guest_name" class="required">Full Name</label>
                        <input type="text" id="guest_name" name="guest_name" class="form-control" required value="<?php echo isset($_POST['guest_name']) ? htmlspecialchars($_POST['guest_name']) : ''; ?>">
                    </div>
                    <div class="form-group">
                        <label for="guest_email" class="required">Email Address</label>
                        <input type="email" id="guest_email" name="guest_email" class="form-control" required value="<?php echo isset($_POST['guest_email']) ? htmlspecialchars($_POST['guest_email']) : ''; ?>">
                    </div>
                    <div class="form-group">
                        <label for="guest_phone" class="required">Phone Number</label>
                        <input type="tel" id="guest_phone" name="guest_phone" class="form-control" required value="<?php echo isset($_POST['guest_phone']) ? htmlspecialchars($_POST['guest_phone']) : ''; ?>">
                    </div>
                    <div class="form-group">
                        <label for="guest_country">Country</label>
                        <input type="text" id="guest_country" name="guest_country" class="form-control" value="<?php echo isset($_POST['guest_country']) ? htmlspecialchars($_POST['guest_country']) : ''; ?>">
                    </div>
                </div>
                <div class="form-group">
                    <label for="guest_address">Address</label>
                    <textarea id="guest_address" name="guest_address" class="form-control" rows="2"><?php echo isset($_POST['guest_address']) ? htmlspecialchars($_POST['guest_address']) : ''; ?></textarea>
                </div>
            </div>

            <!-- Booking Details -->
            <div class="form-section">
                <h3 class="form-section-title"><i class="fas fa-calendar-alt"></i> Booking Details</h3>
                <div class="form-grid">
                    <div class="form-group">
                        <label for="check_in_date" class="required">Check-in Date</label>
                        <div class="calendar-wrapper">
                            <input type="text" id="check_in_date" name="check_in_date" class="form-control" required
                                   placeholder="Select check-in date" readonly>
                        </div>
                        <small style="color: #666; font-size: 12px; margin-top: 5px; display: block;">
                            <i class="fas fa-info-circle"></i> Bookings can only be made up to <?php echo $max_advance_days; ?> days in advance
                        </small>
                    </div>
                    <div class="form-group">
                        <label for="check_out_date" class="required">Check-out Date</label>
                        <div class="calendar-wrapper">
                            <input type="text" id="check_out_date" name="check_out_date" class="form-control" required
                                   placeholder="Select check-out date" readonly>
                        </div>
                    </div>
                </div>
                
                <!-- Calendar Legend -->
                <div class="calendar-legend">
                    <div class="legend-item">
                        <div class="legend-color available"></div>
                        <span>Available</span>
                    </div>
                    <div class="legend-item">
                        <div class="legend-color blocked"></div>
                        <span>Blocked</span>
                    </div>
                    <div class="legend-item">
                        <div class="legend-color unavailable"></div>
                        <span>Unavailable</span>
                    </div>
                    <div class="legend-item">
                        <div class="legend-color selected"></div>
                        <span>Selected</span>
                    </div>
                    <div class="form-group">
                        <label for="number_of_guests" class="required">Number of Guests</label>
                        <select id="number_of_guests" name="number_of_guests" class="form-control" required>
                            <option value="">Select room first...</option>
                        </select>
                        <small id="guestCapacityHint" style="color: #666; font-size: 12px; margin-top: 5px; display: none;"></small>
                    </div>
                    
                    <!-- Occupancy Type Selection -->
                    <div class="form-group">
                        <label class="required">Occupancy Type</label>
                        <div style="display: flex; flex-wrap: wrap; gap: 15px; margin-top: 8px;">
                            <label style="flex: 1; min-width: 100px; cursor: pointer; padding: 12px; border: 2px solid #ddd; border-radius: 8px; text-align: center; transition: all 0.3s; display: flex; flex-direction: column; align-items: center; gap: 5px;" id="singleOccupancyLabel">
                                <input type="radio" name="occupancy_type" value="single" style="margin: 0;">
                                <strong style="color: var(--navy);">Single</strong>
                                <span style="font-size: 12px; color: #666;">1 Guest</span>
                                <span id="singlePriceDisplay" style="font-weight: 600; color: var(--gold);">-</span>
                            </label>
                            <label style="flex: 1; min-width: 100px; cursor: pointer; padding: 12px; border: 2px solid var(--gold); border-radius: 8px; text-align: center; transition: all 0.3s; background: rgba(212, 175, 55, 0.1); display: flex; flex-direction: column; align-items: center; gap: 5px;" id="doubleOccupancyLabel">
                                <input type="radio" name="occupancy_type" value="double" checked style="margin: 0;">
                                <strong style="color: var(--navy);">Double</strong>
                                <span style="font-size: 12px; color: #666;">2 Guests</span>
                                <span id="doublePriceDisplay" style="font-weight: 600; color: var(--gold);">-</span>
                            </label>
                            <label style="flex: 1; min-width: 100px; cursor: pointer; padding: 12px; border: 2px solid #ddd; border-radius: 8px; text-align: center; transition: all 0.3s; display: flex; flex-direction: column; align-items: center; gap: 5px;" id="tripleOccupancyLabel">
                                <input type="radio" name="occupancy_type" value="triple" style="margin: 0;">
                                <strong style="color: var(--navy);">Triple</strong>
                                <span style="font-size: 12px; color: #666;">3 Guests</span>
                                <span id="triplePriceDisplay" style="font-weight: 600; color: var(--gold);">-</span>
                            </label>
                        </div>
                        <small style="color: #666; font-size: 12px; margin-top: 5px; display: block;">
                            <i class="fas fa-info-circle"></i> Prices vary based on occupancy type
                        </small>
                    </div>
                    
                    <!-- Second Room Suggestion (hidden by default) -->
                    <div id="secondRoomSuggestion" style="display: none; margin-top: 15px; padding: 15px; background: linear-gradient(135deg, #fff8e1 0%, #ffecb3 100%); border-left: 4px solid var(--gold); border-radius: 8px;">
                        <div style="display: flex; align-items: start; gap: 12px;">
                            <i class="fas fa-info-circle" style="color: var(--gold); font-size: 20px; margin-top: 2px;"></i>
                            <div>
                                <h4 style="margin: 0 0 8px 0; color: var(--navy); font-size: 16px;">Consider Booking Multiple Rooms</h4>
                                <p style="margin: 0 0 10px 0; color: #666; font-size: 14px;">Your group size exceeds the maximum capacity for one room. You can book multiple rooms to accommodate all guests.</p>
                                <div id="secondRoomOptions" style="margin-top: 10px;"></div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="form-group">
                    <label for="special_requests">Special Requests (Optional)</label>
                    <textarea id="special_requests" name="special_requests" class="form-control" rows="3" placeholder="E.g., early check-in, airport pickup, dietary requirements..."><?php echo isset($_POST['special_requests']) ? htmlspecialchars($_POST['special_requests']) : ''; ?></textarea>
                </div>
            </div>

            <!-- Booking Summary -->
            <div class="booking-summary" id="bookingSummary" style="display: none;">
                <h3>Booking Summary</h3>
                <div class="summary-row">
                    <span>Room:</span>
                    <span id="summaryRoom">-</span>
                </div>
                <div class="summary-row">
                    <span>Check-in:</span>
                    <span id="summaryCheckIn">-</span>
                </div>
                <div class="summary-row">
                    <span>Check-out:</span>
                    <span id="summaryCheckOut">-</span>
                </div>
                <div class="summary-row">
                    <span>Number of Nights:</span>
                    <span id="summaryNights">-</span>
                </div>
                <div class="summary-row">
                    <span>Total Amount:</span>
                    <span id="summaryTotal">-</span>
                </div>
            </div>

            <button type="submit" class="btn-submit">
                <i class="fas fa-check-circle"></i> Confirm Booking
            </button>

            <p style="text-align: center; margin-top: 20px; color: #666; font-size: 13px;">
                <i class="fas fa-info-circle"></i> <?php echo htmlspecialchars($payment_policy); ?>
            </p>
        </form>
    </div>

    <?php include 'includes/footer.php'; ?>
    
    <script src="js/modal.js"></script>
    <script src="js/main.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    <script>
        // Site settings
        const emailReservations = '<?php echo $email_reservations_esc; ?>';
        const currencySymbol = '<?php echo htmlspecialchars($currency_symbol); ?>';
        
        // Blocked dates from server
        const blockedDates = <?php echo json_encode($blocked_dates_array); ?>;
        const preselectedRoomId = <?php echo $preselected_room_id ? $preselected_room_id : 'null'; ?>;
        const preselectedRoomPrice = <?php echo $preselected_room ? $preselected_room['price_per_night'] : 'null'; ?>;
        const preselectedRoomName = <?php echo $preselected_room ? '"' . addslashes($preselected_room['name']) . '"' : 'null'; ?>;
        const preselectedRoomMaxGuests = <?php echo $preselected_room ? $preselected_room['max_guests'] : 'null'; ?>;
        
        // Rooms data for dynamic validation
        const roomsData = <?php echo json_encode($rooms_data); ?>;
        
        let checkInCalendar = null;
        let checkOutCalendar = null;
        let selectedRoomId = preselectedRoomId;
        let selectedRoomPrice = preselectedRoomPrice;
        let selectedRoomName = preselectedRoomName;
        let selectedRoomMaxGuests = preselectedRoomMaxGuests;
        
        // Initialize calendars
        function initCalendars() {
            const today = new Date();
            const maxDate = new Date();
            maxDate.setDate(maxDate.getDate() + <?php echo $max_advance_days; ?>);
            
            // Check-in calendar
            checkInCalendar = flatpickr('#check_in_date', {
                minDate: 'today',
                maxDate: maxDate,
                dateFormat: 'Y-m-d',
                disable: blockedDates,
                onDayCreate: function(dObj, dStr, fp, dayElem) {
                    // Add custom class for blocked dates
                    const dateStr = fp.formatDate(dayElem.dateObj, 'Y-m-d');
                    if (blockedDates.includes(dateStr)) {
                        dayElem.classList.add('blocked-date');
                        dayElem.innerHTML += '<span class="blocked-indicator"></span>';
                    }
                },
                onChange: function(selectedDates, dateStr, instance) {
                    if (selectedDates.length > 0) {
                        // Update check-out calendar min date
                        const nextDay = new Date(selectedDates[0]);
                        nextDay.setDate(nextDay.getDate() + 1);
                        
                        if (checkOutCalendar) {
                            checkOutCalendar.set('minDate', nextDay);
                            
                            // If check-out is before new min date, clear it
                            const currentCheckOut = checkOutCalendar.selectedDates[0];
                            if (currentCheckOut && currentCheckOut < nextDay) {
                                checkOutCalendar.clear();
                            }
                        }
                    }
                    updateSummary();
                }
            });
            
            // Check-out calendar
            checkOutCalendar = flatpickr('#check_out_date', {
                minDate: 'today',
                maxDate: maxDate,
                dateFormat: 'Y-m-d',
                disable: blockedDates,
                onDayCreate: function(dObj, dStr, fp, dayElem) {
                    // Add custom class for blocked dates
                    const dateStr = fp.formatDate(dayElem.dateObj, 'Y-m-d');
                    if (blockedDates.includes(dateStr)) {
                        dayElem.classList.add('blocked-date');
                        dayElem.innerHTML += '<span class="blocked-indicator"></span>';
                    }
                },
                onChange: function() {
                    updateSummary();
                }
            });
        }
        
        // Initialize calendars on page load
        document.addEventListener('DOMContentLoaded', function() {
            initCalendars();
            
            // If room is pre-selected, initialize with that room
            if (preselectedRoomId) {
                selectedRoomId = preselectedRoomId;
                selectedRoomPrice = preselectedRoomPrice;
                selectedRoomName = preselectedRoomName;
                selectedRoomMaxGuests = preselectedRoomMaxGuests;
                updateGuestOptions(preselectedRoomMaxGuests);
                updateOccupancyPrices(preselectedRoomId);
                
                // Set number of guests to max capacity for pre-selected room
                const guestSelect = document.getElementById('number_of_guests');
                guestSelect.value = preselectedRoomMaxGuests;
                
                // Update price based on current occupancy selection
                updatePriceBasedOnOccupancy();
            }
            
            // Add occupancy type change listeners
            const occupancyRadios = document.querySelectorAll('input[name="occupancy_type"]');
            occupancyRadios.forEach(radio => {
                radio.addEventListener('change', function() {
                    // Update number of guests based on occupancy type
                    const guestSelect = document.getElementById('number_of_guests');
                    const occupancyType = this.value;
                    const guestCount = occupancyType === 'single' ? 1 : (occupancyType === 'double' ? 2 : 3);
                    guestSelect.value = guestCount;
                    
                    updatePriceBasedOnOccupancy();
                    updateSummary();
                    
                    // Update visual styling for selected occupancy
                    ['single', 'double', 'triple'].forEach(type => {
                        const label = document.getElementById(type + 'OccupancyLabel');
                        if (label) {
                            label.style.borderColor = this.value === type ? 'var(--gold)' : '#ddd';
                            label.style.background = this.value === type ? 'rgba(212, 175, 55, 0.1)' : 'white';
                        }
                    });
                });
            });
        });
        
        // Update price displays when occupancy type changes
        function updatePriceBasedOnOccupancy() {
            const occupancyType = document.querySelector('input[name="occupancy_type"]:checked').value;
            
            if (!selectedRoomId) return;
            
            // Find the selected room from roomsData
            const selectedRoom = roomsData.find(room => room.id === selectedRoomId);
            if (!selectedRoom) return;
            
            let newPrice;
            if (occupancyType === 'single') {
                newPrice = selectedRoom.price_single_occupancy;
            } else if (occupancyType === 'double') {
                newPrice = selectedRoom.price_double_occupancy;
            } else if (occupancyType === 'triple') {
                newPrice = selectedRoom.price_triple_occupancy;
            } else {
                newPrice = selectedRoom.price_per_night;
            }
            
            selectedRoomPrice = newPrice;
        }
        
        // Update occupancy price displays when room is selected
        function updateOccupancyPrices(roomId) {
            const room = roomsData.find(r => r.id === roomId);
            if (!room) return;
            
            const singlePrice = document.getElementById('singlePriceDisplay');
            const doublePrice = document.getElementById('doublePriceDisplay');
            const triplePrice = document.getElementById('triplePriceDisplay');
            
            if (singlePrice) {
                singlePrice.textContent = currencySymbol + room.price_single_occupancy.toLocaleString();
            }
            if (doublePrice) {
                doublePrice.textContent = currencySymbol + room.price_double_occupancy.toLocaleString();
            }
            if (triplePrice) {
                triplePrice.textContent = currencySymbol + room.price_triple_occupancy.toLocaleString();
            }
        }
        
        function updateSummaryWithDates(selection) {
            const roomRadio = document.querySelector('input[name="room_id"]:checked');
            if (!roomRadio) return;
            
            const roomOption = roomRadio.closest('.room-option');
            const roomName = roomOption.querySelector('h4').textContent;
            const roomPrice = parseFloat(roomOption.querySelector('.room-price-amount').textContent.replace(/[^0-9.]/g, ''));
            
            const checkInDate = new Date(selection.checkIn);
            const checkOutDate = new Date(selection.checkOut);
            
            document.getElementById('summaryRoom').textContent = roomName;
            document.getElementById('summaryCheckIn').textContent = checkInDate.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
            document.getElementById('summaryCheckOut').textContent = checkOutDate.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
            document.getElementById('summaryNights').textContent = selection.nights + (selection.nights === 1 ? ' night' : ' nights');
            document.getElementById('summaryTotal').textContent = currencySymbol + (roomPrice * selection.nights).toLocaleString();
            document.getElementById('bookingSummary').style.display = 'block';
            
            // Enable submit button
            const submitBtn = document.querySelector('.btn-submit');
            submitBtn.disabled = false;
            submitBtn.innerHTML = '<i class="fas fa-check-circle"></i> Confirm Booking';
            submitBtn.style.opacity = '1';
        }
        
        function selectRoom(label) {
            document.querySelectorAll('.room-option').forEach(opt => opt.classList.remove('selected'));
            label.classList.add('selected');
            
            const roomRadio = label.querySelector('input[type="radio"]');
            const roomId = parseInt(roomRadio.value);
            const roomName = label.getAttribute('data-room-name');
            const roomPrice = parseFloat(label.getAttribute('data-room-price'));
            
            selectedRoomId = roomId;
            selectedRoomName = roomName;
            selectedRoomMaxGuests = parseInt(label.getAttribute('data-max-guests'));
            
            // Update guest options based on room capacity
            updateGuestOptions(selectedRoomMaxGuests);
            
            // Set number of guests to max capacity for this room
            const guestSelect = document.getElementById('number_of_guests');
            guestSelect.value = selectedRoomMaxGuests;
            
            // Update occupancy prices for this room
            updateOccupancyPrices(roomId);
            
            // Get current occupancy type and set price
            updatePriceBasedOnOccupancy();
            
            // Reinitialize calendars with new room's blocked dates
            updateBlockedDatesForRoom(roomId);
        }
        
        // Update guest dropdown options based on room capacity
        function updateGuestOptions(maxGuests) {
            const guestSelect = document.getElementById('number_of_guests');
            const capacityHint = document.getElementById('guestCapacityHint');
            
            // Clear existing options
            guestSelect.innerHTML = '<option value="">Select number of guests...</option>';
            
            // Add options up to max guests
            for (let i = 1; i <= maxGuests; i++) {
                const option = document.createElement('option');
                option.value = i;
                option.textContent = i + (i === 1 ? ' Guest' : ' Guests');
                guestSelect.appendChild(option);
            }
            
            // Add option for more guests (will trigger second room suggestion)
            const moreOption = document.createElement('option');
            moreOption.value = maxGuests + 1;
            moreOption.textContent = (maxGuests + 1) + '+ Guests (Multiple Rooms)';
            moreOption.style.color = '#dc3545';
            moreOption.style.fontWeight = '600';
            guestSelect.appendChild(moreOption);
            
            // Update capacity hint
            capacityHint.textContent = `This room accommodates up to ${maxGuests} guest${maxGuests > 1 ? 's' : ''}.`;
            capacityHint.style.display = 'block';
            
            // Reset selection
            guestSelect.value = '';
            
            // Hide second room suggestion
            document.getElementById('secondRoomSuggestion').style.display = 'none';
        }
        
        // Check if guests exceed capacity and show second room suggestion
        function checkGuestCapacity() {
            const guestSelect = document.getElementById('number_of_guests');
            const numGuests = parseInt(guestSelect.value);
            const suggestionBox = document.getElementById('secondRoomSuggestion');
            const optionsContainer = document.getElementById('secondRoomOptions');
            
            if (!numGuests || !selectedRoomMaxGuests) {
                suggestionBox.style.display = 'none';
                return;
            }
            
            // Check if guests exceed room capacity
            if (numGuests > selectedRoomMaxGuests) {
                suggestionBox.style.display = 'block';
                
                // Calculate how many rooms needed
                const roomsNeeded = Math.ceil(numGuests / selectedRoomMaxGuests);
                
                // Build suggestion message
                let html = `
                    <div style="background: white; padding: 12px; border-radius: 6px; margin-top: 8px;">
                        <strong style="color: var(--navy);">Recommended: ${roomsNeeded} room${roomsNeeded > 1 ? 's' : ''}</strong>
                        <p style="margin: 5px 0 0 0; font-size: 13px; color: #666;">
                            Each ${selectedRoomName} can accommodate up to ${selectedRoomMaxGuests} guest${selectedRoomMaxGuests > 1 ? 's' : ''}.
                        </p>
                        <p style="margin: 8px 0 0 0; font-size: 13px; color: #666;">
                            <strong>Option 1:</strong> Complete this booking for up to ${selectedRoomMaxGuests} guests, then make another booking for the remaining ${numGuests - selectedRoomMaxGuests} guest${numGuests - selectedRoomMaxGuests > 1 ? 's' : ''}.
                        </p>
                        <p style="margin: 5px 0 0 0; font-size: 13px; color: #666;">
                            <strong>Option 2:</strong> Contact us directly at <a href="mailto:${emailReservations}" style="color: var(--gold);">${emailReservations}</a> for group booking assistance.
                        </p>
                    </div>
                `;
                
                optionsContainer.innerHTML = html;
                
                // Disable submit button
                const submitBtn = document.querySelector('.btn-submit');
                submitBtn.disabled = true;
                submitBtn.innerHTML = '<i class="fas fa-exclamation-triangle"></i> Please Adjust Guest Count';
                submitBtn.style.opacity = '0.6';
            } else {
                suggestionBox.style.display = 'none';
                
                // Enable submit button if all validations pass
                validateFormForSubmit();
            }
        }
        
        // Validate form for submit
        function validateFormForSubmit() {
            const checkIn = document.getElementById('check_in_date').value;
            const checkOut = document.getElementById('check_out_date').value;
            const numGuests = document.getElementById('number_of_guests').value;
            const submitBtn = document.querySelector('.btn-submit');
            
            if (selectedRoomId && checkIn && checkOut && numGuests) {
                submitBtn.disabled = false;
                submitBtn.innerHTML = '<i class="fas fa-check-circle"></i> Confirm Booking';
                submitBtn.style.opacity = '1';
            } else {
                submitBtn.disabled = true;
                submitBtn.innerHTML = '<i class="fas fa-calendar-check"></i> Complete All Fields';
                submitBtn.style.opacity = '0.6';
            }
        }
        
        function updateBlockedDatesForRoom(roomId) {
            // Fetch blocked dates for this room
            fetch('api/blocked-dates.php?room_id=' + roomId)
                .then(response => response.json())
                .then(data => {
                    if (data.success && data.data) {
                        const roomBlockedDates = data.data.map(bd => bd.block_date);
                        
                        // Update both calendars
                        checkInCalendar.set('disable', roomBlockedDates);
                        checkOutCalendar.set('disable', roomBlockedDates);
                    }
                })
                .catch(error => {
                    console.error('Failed to fetch blocked dates:', error);
                });
        }
        
        function checkRoomAvailability(roomId, checkIn, checkOut, callback) {
            const url = `check-availability.php?room_id=${roomId}&check_in=${checkIn}&check_out=${checkOut}`;
            
            fetch(url)
                .then(response => response.json())
                .then(callback)
                .catch(error => {
                    console.error('Availability check failed:', error);
                    callback({ available: false, message: 'Unable to check availability' });
                });
        }
        
        function showAvailabilityMessage(message, isSuccess) {
            // Use new Alert component
            Alert.show(message, isSuccess ? 'success' : 'error', {
                timeout: 5000,
                position: 'top'
            });
        }
        
        function updateSummary() {
            const checkIn = document.getElementById('check_in_date').value;
            const checkOut = document.getElementById('check_out_date').value;

            if (selectedRoomId && checkIn && checkOut) {
                const checkInDate = new Date(checkIn);
                const checkOutDate = new Date(checkOut);
                const nights = Math.ceil((checkOutDate - checkInDate) / (1000 * 60 * 60 * 24));

                if (nights > 0) {
                    // Get current occupancy type
                    const occupancyType = document.querySelector('input[name="occupancy_type"]:checked')?.value || 'double';
                    
                    // Find the selected room from roomsData
                    const selectedRoom = roomsData.find(room => room.id === selectedRoomId);
                    if (!selectedRoom) return;
                    
                    // Calculate price based on occupancy
                    let pricePerNight;
                    if (occupancyType === 'single') {
                        pricePerNight = selectedRoom.price_single_occupancy;
                    } else if (occupancyType === 'double') {
                        pricePerNight = selectedRoom.price_double_occupancy;
                    } else if (occupancyType === 'triple') {
                        pricePerNight = selectedRoom.price_triple_occupancy;
                    } else {
                        pricePerNight = selectedRoom.price_per_night;
                    }
                    
                    const total = pricePerNight * nights;
                    
                    document.getElementById('summaryRoom').textContent = selectedRoomName + ' (' + (occupancyType === 'single' ? 'Single' : occupancyType === 'double' ? 'Double' : 'Triple') + ' Occupancy)';
                    document.getElementById('summaryCheckIn').textContent = checkInDate.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
                    document.getElementById('summaryCheckOut').textContent = checkOutDate.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
                    document.getElementById('summaryNights').textContent = nights + (nights === 1 ? ' night' : ' nights');
                    document.getElementById('summaryTotal').textContent = currencySymbol + total.toLocaleString();
                    
                    document.getElementById('bookingSummary').style.display = 'block';
                    
                    // Enable submit button
                    const submitBtn = document.querySelector('.btn-submit');
                    submitBtn.disabled = false;
                    submitBtn.innerHTML = '<i class="fas fa-check-circle"></i> Confirm Booking';
                    submitBtn.style.opacity = '1';
                } else {
                    document.getElementById('bookingSummary').style.display = 'none';
                }
            }
        }

        document.getElementById('check_in_date').addEventListener('change', function() {
            const checkIn = new Date(this.value);
            const nextDay = new Date(checkIn);
            nextDay.setDate(checkIn.getDate() + 1);
            document.getElementById('check_out_date').min = nextDay.toISOString().split('T')[0];
            updateSummary();
            validateFormForSubmit();
        });

        document.getElementById('check_out_date').addEventListener('change', function() {
            updateSummary();
            validateFormForSubmit();
        });
        
        // Add guest count change listener
        document.getElementById('number_of_guests').addEventListener('change', function() {
            const guestCount = parseInt(this.value);
            
            // Update occupancy type based on number of guests
            const occupancyRadios = document.querySelectorAll('input[name="occupancy_type"]');
            if (guestCount === 1) {
                occupancyRadios[0].checked = true; // Single occupancy
                occupancyRadios[0].dispatchEvent(new Event('change'));
            } else if (guestCount === 2) {
                occupancyRadios[1].checked = true; // Double occupancy
                occupancyRadios[1].dispatchEvent(new Event('change'));
            } else if (guestCount >= 3) {
                occupancyRadios[2].checked = true; // Triple occupancy
                occupancyRadios[2].dispatchEvent(new Event('change'));
            }
            
            checkGuestCapacity();
        });
        
        // Booking type selection function
        function selectBookingType(type) {
            document.querySelectorAll('.booking-type-option').forEach(opt => {
                opt.classList.remove('selected');
            });
            
            const selectedOption = document.querySelector(`input[name="booking_type"][value="${type}"]`);
            if (selectedOption) {
                selectedOption.closest('.booking-type-option').classList.add('selected');
            }
        }
        
        // Initialize booking type selection on page load
        document.addEventListener('DOMContentLoaded', function() {
            selectBookingType('standard');
        });
    </script>

    <?php include 'includes/scroll-to-top.php'; ?>
</body>
</html>
