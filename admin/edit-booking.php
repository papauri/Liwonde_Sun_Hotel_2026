<?php
/**
 * Admin Edit Booking Page
 * Allows admin to modify booking details: dates, room, guest info, occupancy, amounts
 */

require_once __DIR__ . '/admin-init.php';
require_once __DIR__ . '/../includes/validation.php';

$booking_id = intval($_GET['id'] ?? 0);
if ($booking_id <= 0) {
    header('Location: bookings.php');
    exit;
}

$message = '';
$error = '';

// Fetch booking
try {
    $stmt = $pdo->prepare("
        SELECT b.*, r.name as room_name, r.price_per_night, r.total_rooms, r.rooms_available, r.max_guests
        FROM bookings b
        LEFT JOIN rooms r ON b.room_id = r.id
        WHERE b.id = ?
    ");
    $stmt->execute([$booking_id]);
    $booking = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$booking) {
        header('Location: bookings.php');
        exit;
    }
} catch (PDOException $e) {
    $error = 'Error loading booking: ' . $e->getMessage();
    $booking = null;
}

// Fetch all active rooms
try {
    $rooms_stmt = $pdo->query("SELECT id, name, price_per_night, total_rooms, rooms_available, max_guests, size_sqm,
                                      single_price, double_price, triple_price
                               FROM rooms WHERE is_active = 1 ORDER BY display_order, name");
    $rooms = $rooms_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $rooms = [];
}

// Get settings
$currency_symbol = getSetting('currency_symbol', 'MK');
$vatEnabled = in_array(getSetting('vat_enabled'), ['1', 'true', 'on']);
$vatRate = (float)getSetting('vat_rate', 0);

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $booking) {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $error = 'Invalid security token. Please try again.';
    } else {
        $room_id = intval($_POST['room_id'] ?? $booking['room_id']);
        $check_in = $_POST['check_in_date'] ?? $booking['check_in_date'];
        $check_out = $_POST['check_out_date'] ?? $booking['check_out_date'];
        $guest_name = trim($_POST['guest_name'] ?? '');
        $guest_email = trim($_POST['guest_email'] ?? '');
        $guest_phone = trim($_POST['guest_phone'] ?? '');
        $guest_country = trim($_POST['guest_country'] ?? '');
        $number_of_guests = intval($_POST['number_of_guests'] ?? 1);
        $occupancy_type = $_POST['occupancy_type'] ?? 'single';
        $special_requests = trim($_POST['special_requests'] ?? '');
        $total_amount = floatval($_POST['total_amount'] ?? 0);
        $admin_notes = trim($_POST['booking_notes'] ?? '');
        
        // Validate
        if (empty($guest_name) || empty($guest_email) || empty($check_in) || empty($check_out)) {
            $error = 'Guest name, email, check-in and check-out dates are required.';
        } elseif (strtotime($check_out) <= strtotime($check_in)) {
            $error = 'Check-out date must be after check-in date.';
        } else {
            // Enforce maximum guest capacity for selected room
            $cap_check = $pdo->prepare("SELECT max_guests FROM rooms WHERE id = ?");
            $cap_check->execute([$room_id]);
            $cap_room = $cap_check->fetch(PDO::FETCH_ASSOC);
            if ($cap_room && $number_of_guests > (int)$cap_room['max_guests']) {
                $error = 'Number of guests (' . $number_of_guests . ') exceeds room capacity of ' . $cap_room['max_guests'] . '. Please reduce guests or assign a different room.';
            }
        }
        
        if (empty($error)) {
            try {
                $pdo->beginTransaction();
                
                $number_of_nights = (strtotime($check_out) - strtotime($check_in)) / 86400;
                
                // Track changes for notification email
                $changes = [];
                $currency_sym = getSetting('currency_symbol', 'MK');
                
                $old_room_id = $booking['room_id'];
                $room_changed = ($room_id != $old_room_id);
                
                if ($room_changed) {
                    $old_room_name = $booking['room_name'] ?? 'Unknown';
                    $new_room_stmt = $pdo->prepare("SELECT name FROM rooms WHERE id = ?");
                    $new_room_stmt->execute([$room_id]);
                    $new_room_row = $new_room_stmt->fetch(PDO::FETCH_ASSOC);
                    $new_room_name = $new_room_row ? $new_room_row['name'] : 'Unknown';
                    $changes['room'] = ['old' => $old_room_name, 'new' => $new_room_name];
                }
                if ($check_in !== $booking['check_in_date']) {
                    $changes['check_in_date'] = ['old' => date('M j, Y', strtotime($booking['check_in_date'])), 'new' => date('M j, Y', strtotime($check_in))];
                }
                if ($check_out !== $booking['check_out_date']) {
                    $changes['check_out_date'] = ['old' => date('M j, Y', strtotime($booking['check_out_date'])), 'new' => date('M j, Y', strtotime($check_out))];
                }
                if ($number_of_guests != $booking['number_of_guests']) {
                    $changes['number_of_guests'] = ['old' => $booking['number_of_guests'], 'new' => $number_of_guests];
                }
                if ($occupancy_type !== ($booking['occupancy_type'] ?? 'single')) {
                    $changes['occupancy_type'] = ['old' => ucfirst($booking['occupancy_type'] ?? 'single'), 'new' => ucfirst($occupancy_type)];
                }
                if (abs($total_amount - (float)$booking['total_amount']) > 0.01) {
                    $changes['total_amount'] = ['old' => $currency_sym . ' ' . number_format($booking['total_amount'], 0), 'new' => $currency_sym . ' ' . number_format($total_amount, 0)];
                }
                if ($guest_name !== $booking['guest_name']) {
                    $changes['guest_name'] = ['old' => $booking['guest_name'], 'new' => $guest_name];
                }
                if ($guest_email !== $booking['guest_email']) {
                    $changes['guest_email'] = ['old' => $booking['guest_email'], 'new' => $guest_email];
                }
                if ($guest_phone !== ($booking['guest_phone'] ?? '')) {
                    $changes['guest_phone'] = ['old' => $booking['guest_phone'] ?? '', 'new' => $guest_phone];
                }
                
                if ($room_changed && in_array($booking['status'], ['confirmed', 'checked-in'])) {
                    // Restore old room availability
                    $restore = $pdo->prepare("UPDATE rooms SET rooms_available = rooms_available + 1 WHERE id = ?");
                    $restore->execute([$old_room_id]);
                    
                    // Check new room availability
                    $check_avail = $pdo->prepare("SELECT rooms_available FROM rooms WHERE id = ?");
                    $check_avail->execute([$room_id]);
                    $new_room = $check_avail->fetch(PDO::FETCH_ASSOC);
                    
                    if ($new_room['rooms_available'] <= 0) {
                        $pdo->rollBack();
                        $error = 'Selected room is not available.';
                    } else {
                        // Decrement new room availability
                        $decrement = $pdo->prepare("UPDATE rooms SET rooms_available = rooms_available - 1 WHERE id = ?");
                        $decrement->execute([$room_id]);
                    }
                }
                
                if (empty($error)) {
                    // Calculate VAT if enabled
                    $vat_amount = 0;
                    if ($vatEnabled && $vatRate > 0) {
                        $vat_amount = round($total_amount * ($vatRate / (100 + $vatRate)), 2);
                    }
                    
                    $update = $pdo->prepare("
                        UPDATE bookings SET
                            room_id = ?,
                            guest_name = ?,
                            guest_email = ?,
                            guest_phone = ?,
                            guest_country = ?,
                            check_in_date = ?,
                            check_out_date = ?,
                            number_of_nights = ?,
                            number_of_guests = ?,
                            occupancy_type = ?,
                            total_amount = ?,
                            vat_amount = ?,
                            special_requests = ?,
                            booking_notes = ?,
                            updated_at = NOW()
                        WHERE id = ?
                    ");
                    $update->execute([
                        $room_id, $guest_name, $guest_email, $guest_phone, $guest_country,
                        $check_in, $check_out, $number_of_nights, $number_of_guests,
                        $occupancy_type, $total_amount, $vat_amount, $special_requests,
                        $admin_notes, $booking_id
                    ]);
                    
                    $pdo->commit();
                    $message = 'Booking updated successfully.';
                    
                    // Send modification email to guest if there were meaningful changes
                    if (!empty($changes)) {
                        $stmt->execute([$booking_id]);
                        $updated_booking = $stmt->fetch(PDO::FETCH_ASSOC);
                        
                        if ($updated_booking) {
                            require_once __DIR__ . '/../config/email.php';
                            $email_result = sendBookingModifiedEmail($updated_booking, $changes);
                            if ($email_result['success']) {
                                $message .= ' Notification email sent to guest.';
                            } else {
                                $message .= ' Guest notification email could not be sent.';
                                error_log("Failed to send booking modification email: {$email_result['message']}");
                            }
                        }
                    }
                    
                    // Refresh booking data
                    $stmt->execute([$booking_id]);
                    $booking = $stmt->fetch(PDO::FETCH_ASSOC);
                }
            } catch (Exception $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $error = 'Error updating booking: ' . $e->getMessage();
            }
        }
    }
}


if (!$booking) {
    echo '<p>Booking not found.</p>';
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Booking <?php echo htmlspecialchars($booking['booking_reference']); ?> - Admin</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;500;600;700&family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../css/style.css">
    <link rel="stylesheet" href="../css/theme-dynamic.php">
    <link rel="stylesheet" href="css/admin-styles.css">
    <link rel="stylesheet" href="css/admin-components.css">
    <style>
        .edit-form { background: white; border-radius: 12px; padding: 32px; box-shadow: 0 2px 8px rgba(0,0,0,0.08); max-width: 900px; }
        .form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
        .form-group { margin-bottom: 20px; }
        .form-group label { display: block; font-weight: 600; margin-bottom: 6px; color: #333; font-size: 14px; }
        .form-group input, .form-group select, .form-group textarea { 
            width: 100%; padding: 10px 14px; border: 1px solid #ddd; border-radius: 8px; 
            font-size: 14px; font-family: 'Poppins', sans-serif; box-sizing: border-box;
        }
        .form-group textarea { resize: vertical; min-height: 80px; }
        .form-group input:focus, .form-group select:focus, .form-group textarea:focus {
            border-color: var(--gold); outline: none; box-shadow: 0 0 0 3px rgba(212,168,67,0.15);
        }
        .form-full { grid-column: 1 / -1; }
        .btn-bar { display: flex; gap: 12px; margin-top: 24px; }
        .btn-save { padding: 12px 32px; background: var(--gold, #d4a843); color: var(--deep-navy, #0d0d1a); border: none; border-radius: 8px; font-weight: 700; font-size: 15px; cursor: pointer; }
        .btn-save:hover { background: #c19b2e; }
        .btn-back { padding: 12px 24px; background: #6c757d; color: white; text-decoration: none; border-radius: 8px; font-weight: 600; font-size: 14px; display: inline-flex; align-items: center; gap: 6px; }
        .btn-back:hover { background: #5a6268; }
        .booking-ref { font-size: 14px; color: #666; }
        .status-badge { display: inline-block; padding: 4px 12px; border-radius: 20px; font-size: 12px; font-weight: 600; }
        .price-info { background: #f8f9fa; border-radius: 8px; padding: 16px; margin-top: 16px; }
        .price-info h4 { margin: 0 0 8px 0; font-size: 14px; color: #555; }
        @media (max-width: 768px) { .form-grid { grid-template-columns: 1fr; } }
    </style>
</head>
<body>

<?php require_once 'includes/admin-header.php'; ?>

<div class="content">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px;">
        <div>
            <h1 style="font-family: 'Playfair Display', serif; color: var(--navy); margin: 0;">
                Edit Booking
            </h1>
            <span class="booking-ref">
                <?php echo htmlspecialchars($booking['booking_reference']); ?> &mdash;
                <span class="badge badge-<?php echo htmlspecialchars($booking['status']); ?>">
                    <?php echo ucfirst(htmlspecialchars($booking['status'])); ?>
                </span>
            </span>
        </div>
        <div style="display: flex; gap: 8px;">
            <a href="booking-details.php?id=<?php echo $booking_id; ?>" class="btn-back">
                <i class="fas fa-eye"></i> View Details
            </a>
            <a href="bookings.php" class="btn-back">
                <i class="fas fa-arrow-left"></i> All Bookings
            </a>
        </div>
    </div>

    <?php if ($message): ?>
        <div style="background: #d4edda; color: #155724; padding: 12px 20px; border-radius: 8px; margin-bottom: 20px;">
            <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($message); ?>
        </div>
    <?php endif; ?>
    
    <?php if ($error): ?>
        <div style="background: #f8d7da; color: #721c24; padding: 12px 20px; border-radius: 8px; margin-bottom: 20px;">
            <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?>
        </div>
    <?php endif; ?>

    <div class="edit-form">
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
            
            <h3 style="margin-top: 0; color: var(--navy); border-bottom: 2px solid var(--gold, #d4a843); padding-bottom: 8px;">
                <i class="fas fa-user"></i> Guest Information
            </h3>
            <div class="form-grid">
                <div class="form-group">
                    <label for="guest_name">Full Name *</label>
                    <input type="text" id="guest_name" name="guest_name" required
                           value="<?php echo htmlspecialchars($booking['guest_name']); ?>">
                </div>
                <div class="form-group">
                    <label for="guest_email">Email *</label>
                    <input type="email" id="guest_email" name="guest_email" required
                           value="<?php echo htmlspecialchars($booking['guest_email']); ?>">
                </div>
                <div class="form-group">
                    <label for="guest_phone">Phone</label>
                    <input type="text" id="guest_phone" name="guest_phone"
                           value="<?php echo htmlspecialchars($booking['guest_phone']); ?>">
                </div>
                <div class="form-group">
                    <label for="guest_country">Country</label>
                    <input type="text" id="guest_country" name="guest_country"
                           value="<?php echo htmlspecialchars($booking['guest_country'] ?? ''); ?>">
                </div>
            </div>

            <h3 style="color: var(--navy); border-bottom: 2px solid var(--gold, #d4a843); padding-bottom: 8px;">
                <i class="fas fa-bed"></i> Room & Dates
            </h3>
            <div class="form-grid">
                <div class="form-group">
                    <label for="room_id">Room</label>
                    <select id="room_id" name="room_id" onchange="updatePricing()">
                        <?php foreach ($rooms as $room): ?>
                            <option value="<?php echo $room['id']; ?>" 
                                    data-price="<?php echo $room['price_per_night']; ?>"
                                    data-single="<?php echo $room['single_price'] ?? $room['price_per_night']; ?>"
                                    data-double="<?php echo $room['double_price'] ?? $room['price_per_night']; ?>"
                                    data-triple="<?php echo $room['triple_price'] ?? $room['price_per_night']; ?>"
                                    data-max-guests="<?php echo $room['max_guests']; ?>"
                                    data-available="<?php echo $room['rooms_available']; ?>"
                                    <?php echo $room['id'] == $booking['room_id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($room['name']); ?> 
                                (<?php echo $currency_symbol . ' ' . number_format($room['price_per_night']); ?>/night)
                                [<?php echo $room['rooms_available']; ?> avail]
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label for="occupancy_type">Occupancy Type</label>
                    <select id="occupancy_type" name="occupancy_type" onchange="updatePricing()">
                        <option value="single" <?php echo $booking['occupancy_type'] === 'single' ? 'selected' : ''; ?>>Single</option>
                        <option value="double" <?php echo $booking['occupancy_type'] === 'double' ? 'selected' : ''; ?>>Double</option>
                        <option value="triple" <?php echo $booking['occupancy_type'] === 'triple' ? 'selected' : ''; ?>>Triple</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="check_in_date">Check-in Date *</label>
                    <input type="date" id="check_in_date" name="check_in_date" required
                           value="<?php echo htmlspecialchars($booking['check_in_date']); ?>"
                           onchange="updatePricing()">
                </div>
                <div class="form-group">
                    <label for="check_out_date">Check-out Date *</label>
                    <input type="date" id="check_out_date" name="check_out_date" required
                           value="<?php echo htmlspecialchars($booking['check_out_date']); ?>"
                           onchange="updatePricing()">
                </div>
                <div class="form-group">
                    <label for="number_of_guests">Number of Guests</label>
                    <input type="number" id="number_of_guests" name="number_of_guests" min="1"
                           max="<?php echo $booking['max_guests'] ?? 10; ?>"
                           value="<?php echo $booking['number_of_guests']; ?>">
                    <small style="color: #888;">Max: <span id="maxGuestsHint"><?php echo $booking['max_guests'] ?? '?'; ?></span> for this room</small>
                </div>
                <div class="form-group">
                    <label for="total_amount">Total Amount (<?php echo $currency_symbol; ?>)</label>
                    <input type="number" id="total_amount" name="total_amount" step="0.01" min="0"
                           value="<?php echo $booking['total_amount']; ?>">
                </div>
            </div>

            <div class="price-info" id="priceCalculation">
                <h4><i class="fas fa-calculator"></i> Price Calculation</h4>
                <div id="priceBreakdown">
                    <span id="calcNights"><?php echo $booking['number_of_nights']; ?></span> night(s) × 
                    <span id="calcRate"><?php echo $currency_symbol . ' ' . number_format($booking['price_per_night'] ?? 0); ?></span>/night = 
                    <strong><span id="calcTotal"><?php echo $currency_symbol . ' ' . number_format($booking['total_amount']); ?></span></strong>
                </div>
                <small id="calcVatInfo" style="color: #888;">
                    <?php if ($vatEnabled): ?>
                        VAT (<?php echo $vatRate; ?>%): <?php echo $currency_symbol . ' ' . number_format($booking['vat_amount'] ?? 0, 2); ?>
                    <?php endif; ?>
                </small>
            </div>

            <h3 style="color: var(--navy); border-bottom: 2px solid var(--gold, #d4a843); padding-bottom: 8px; margin-top: 24px;">
                <i class="fas fa-sticky-note"></i> Additional Details
            </h3>
            <div class="form-group form-full">
                <label for="special_requests">Special Requests</label>
                <textarea id="special_requests" name="special_requests"><?php echo htmlspecialchars($booking['special_requests'] ?? ''); ?></textarea>
            </div>
            <div class="form-group form-full">
                <label for="booking_notes">Admin Notes</label>
                <textarea id="booking_notes" name="booking_notes"><?php echo htmlspecialchars($booking['booking_notes'] ?? ''); ?></textarea>
            </div>

            <div class="btn-bar">
                <button type="submit" class="btn-save">
                    <i class="fas fa-save"></i> Save Changes
                </button>
                <a href="booking-details.php?id=<?php echo $booking_id; ?>" class="btn-back">Cancel</a>
            </div>
        </form>
    </div>
</div>

<script>
    const currencySymbol = '<?php echo $currency_symbol; ?>';
    const vatEnabled = <?php echo $vatEnabled ? 'true' : 'false'; ?>;
    const vatRate = <?php echo $vatRate; ?>;

    function updatePricing() {
        const roomSelect = document.getElementById('room_id');
        const occupancy = document.getElementById('occupancy_type').value;
        const checkIn = document.getElementById('check_in_date').value;
        const checkOut = document.getElementById('check_out_date').value;
        
        if (!checkIn || !checkOut) return;
        
        const nights = Math.ceil((new Date(checkOut) - new Date(checkIn)) / 86400000);
        if (nights <= 0) return;
        
        const selected = roomSelect.options[roomSelect.selectedIndex];
        let rate = parseFloat(selected.dataset.price);
        
        if (occupancy === 'single' && selected.dataset.single) rate = parseFloat(selected.dataset.single);
        else if (occupancy === 'double' && selected.dataset.double) rate = parseFloat(selected.dataset.double);
        else if (occupancy === 'triple' && selected.dataset.triple) rate = parseFloat(selected.dataset.triple);
        
        const total = nights * rate;
        
        document.getElementById('calcNights').textContent = nights;
        document.getElementById('calcRate').textContent = currencySymbol + ' ' + rate.toLocaleString();
        document.getElementById('calcTotal').textContent = currencySymbol + ' ' + total.toLocaleString();
        document.getElementById('total_amount').value = total.toFixed(2);
        
        if (vatEnabled && vatRate > 0) {
            const vat = total * (vatRate / (100 + vatRate));
            document.getElementById('calcVatInfo').textContent = 'VAT (' + vatRate + '%): ' + currencySymbol + ' ' + vat.toFixed(2);
        }
    }

    // Update max guests when room changes
    document.getElementById('room_id').addEventListener('change', function() {
        const selected = this.options[this.selectedIndex];
        const maxGuests = parseInt(selected.dataset.maxGuests) || 10;
        const guestsInput = document.getElementById('number_of_guests');
        guestsInput.max = maxGuests;
        document.getElementById('maxGuestsHint').textContent = maxGuests;
        if (parseInt(guestsInput.value) > maxGuests) {
            guestsInput.value = maxGuests;
        }
    });
</script>

<script src="js/admin-components.js"></script>
<script src="js/admin-mobile.js"></script>
</body>
</html>
