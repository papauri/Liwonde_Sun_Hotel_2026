<?php
/**
 * Admin - Create Booking Manually
 * For walk-in guests, phone bookings, and agent bookings
 * Creates a booking directly in the system without guest interaction
 */

require_once 'admin-init.php';
require_once '../includes/validation.php';

$message = '';
$error = '';

// Fetch available rooms
try {
    $rooms_stmt = $pdo->query("
        SELECT id, name, price_per_night, price_single_occupancy, price_double_occupancy, 
               price_triple_occupancy, max_guests, rooms_available, total_rooms, short_description
        FROM rooms WHERE is_active = 1 ORDER BY display_order ASC
    ");
    $rooms = $rooms_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $rooms = [];
    $error = 'Failed to load rooms.';
}

$currency_symbol = getSetting('currency_symbol', 'MK');

// Handle booking creation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_booking'])) {
    try {
        // Validate inputs
        $room_id = (int)($_POST['room_id'] ?? 0);
        $guest_name = trim($_POST['guest_name'] ?? '');
        $guest_email = trim($_POST['guest_email'] ?? '');
        $guest_phone = trim($_POST['guest_phone'] ?? '');
        $guest_country = trim($_POST['guest_country'] ?? '');
        $guest_address = trim($_POST['guest_address'] ?? '');
        $number_of_guests = (int)($_POST['number_of_guests'] ?? 1);
        $check_in_date = $_POST['check_in_date'] ?? '';
        $check_out_date = $_POST['check_out_date'] ?? '';
        $occupancy_type = $_POST['occupancy_type'] ?? 'double';
        $special_requests = trim($_POST['special_requests'] ?? '');
        $booking_status = $_POST['booking_status'] ?? 'confirmed';
        $payment_status = $_POST['payment_status'] ?? 'unpaid';
        $admin_notes = trim($_POST['admin_notes'] ?? '');
        $send_email = isset($_POST['send_email']);

        // Basic validation
        $errors = [];
        if ($room_id <= 0) $errors[] = 'Please select a room';
        if (empty($guest_name)) $errors[] = 'Guest name is required';
        if (empty($guest_email) || !filter_var($guest_email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Valid email is required';
        if (empty($guest_phone)) $errors[] = 'Phone number is required';
        if ($number_of_guests < 1) $errors[] = 'At least 1 guest required';
        if (empty($check_in_date)) $errors[] = 'Check-in date is required';
        if (empty($check_out_date)) $errors[] = 'Check-out date is required';
        
        if (empty($errors)) {
            $checkIn = new DateTime($check_in_date);
            $checkOut = new DateTime($check_out_date);
            
            if ($checkOut <= $checkIn) {
                $errors[] = 'Check-out must be after check-in';
            }
        }
        
        if (!empty($errors)) {
            throw new Exception(implode('; ', $errors));
        }
        
        // Get room details
        $room_stmt = $pdo->prepare("SELECT * FROM rooms WHERE id = ? AND is_active = 1");
        $room_stmt->execute([$room_id]);
        $room = $room_stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$room) throw new Exception('Room not found');
        
        // Enforce maximum guest capacity
        if ($number_of_guests > (int)$room['max_guests']) {
            throw new Exception('Number of guests (' . $number_of_guests . ') exceeds room capacity of ' . $room['max_guests'] . ' guests. Please reduce guests or book an additional room.');
        }
        
        // Calculate pricing
        if ($occupancy_type === 'single' && !empty($room['price_single_occupancy'])) {
            $room_price = $room['price_single_occupancy'];
        } elseif ($occupancy_type === 'double' && !empty($room['price_double_occupancy'])) {
            $room_price = $room['price_double_occupancy'];
        } elseif ($occupancy_type === 'triple' && !empty($room['price_triple_occupancy'])) {
            $room_price = $room['price_triple_occupancy'];
        } else {
            $room_price = $room['price_per_night'];
        }
        
        $checkIn = new DateTime($check_in_date);
        $checkOut = new DateTime($check_out_date);
        $number_of_nights = $checkIn->diff($checkOut)->days;
        
        // Allow price override
        $price_override = isset($_POST['price_override']) && !empty($_POST['price_override']) 
            ? (float)$_POST['price_override'] : null;
        
        $total_amount = $price_override !== null ? $price_override : ($room_price * $number_of_nights);
        
        // Generate unique booking reference
        $ref_prefix = getSetting('booking_reference_prefix', 'LSH');
        do {
            $booking_reference = $ref_prefix . date('Y') . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
            $ref_check = $pdo->prepare("SELECT COUNT(*) as count FROM bookings WHERE booking_reference = ?");
            $ref_check->execute([$booking_reference]);
            $ref_exists = $ref_check->fetch(PDO::FETCH_ASSOC)['count'] > 0;
        } while ($ref_exists);
        
        // Determine tentative settings
        $is_tentative = ($booking_status === 'tentative') ? 1 : 0;
        $tentative_expires_at = null;
        if ($is_tentative) {
            $tentative_hours = (int)getSetting('tentative_duration_hours', 48);
            $tentative_expires_at = date('Y-m-d H:i:s', strtotime("+{$tentative_hours} hours"));
        }
        
        // Insert booking
        $pdo->beginTransaction();
        
        $insert = $pdo->prepare("
            INSERT INTO bookings (
                booking_reference, room_id, guest_name, guest_email, guest_phone,
                guest_country, guest_address, number_of_guests, check_in_date,
                check_out_date, number_of_nights, total_amount, special_requests, status,
                payment_status, is_tentative, tentative_expires_at, occupancy_type, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        
        $insert->execute([
            $booking_reference, $room_id, $guest_name, $guest_email, $guest_phone,
            $guest_country, $guest_address, $number_of_guests, $check_in_date,
            $check_out_date, $number_of_nights, $total_amount, $special_requests,
            $booking_status, $payment_status, $is_tentative, $tentative_expires_at, $occupancy_type
        ]);
        
        $new_booking_id = $pdo->lastInsertId();
        
        // If confirmed, decrement room availability
        if ($booking_status === 'confirmed') {
            $pdo->prepare("UPDATE rooms SET rooms_available = rooms_available - 1 WHERE id = ? AND rooms_available > 0")
                ->execute([$room_id]);
        }
        
        // If paid, create payment record
        if ($payment_status === 'paid') {
            $vatEnabled = in_array(getSetting('vat_enabled'), ['1', 1, true, 'true', 'on'], true);
            $vatRate = $vatEnabled ? (float)getSetting('vat_rate') : 0;
            $vatAmount = $vatEnabled ? ($total_amount * ($vatRate / 100)) : 0;
            $totalWithVat = $total_amount + $vatAmount;
            
            $payment_reference = 'PAY-' . date('Y') . '-' . str_pad($new_booking_id, 6, '0', STR_PAD_LEFT);
            $payment_method = $_POST['payment_method'] ?? 'cash';
            
            $insert_payment = $pdo->prepare("
                INSERT INTO payments (
                    payment_reference, booking_type, booking_id, booking_reference,
                    payment_date, payment_amount, vat_rate, vat_amount, total_amount,
                    payment_method, payment_type, payment_status, invoice_generated,
                    status, recorded_by
                ) VALUES (?, 'room', ?, ?, CURDATE(), ?, ?, ?, ?, ?, 'full_payment', 'completed', 1, 'completed', ?)
            ");
            $insert_payment->execute([
                $payment_reference, $new_booking_id, $booking_reference,
                $total_amount, $vatRate, $vatAmount, $totalWithVat,
                $payment_method, $user['id']
            ]);
            
            $pdo->prepare("
                UPDATE bookings SET amount_paid = ?, amount_due = 0, vat_rate = ?, vat_amount = ?,
                    total_with_vat = ?, last_payment_date = CURDATE() WHERE id = ?
            ")->execute([$totalWithVat, $vatRate, $vatAmount, $totalWithVat, $new_booking_id]);
        }
        
        // Add admin note if provided
        if (!empty($admin_notes)) {
            $pdo->prepare("INSERT INTO booking_notes (booking_id, note_text, created_by) VALUES (?, ?, ?)")
                ->execute([$new_booking_id, 'Admin booking: ' . $admin_notes, $user['id']]);
        }
        
        // Log creation source
        $pdo->prepare("INSERT INTO booking_notes (booking_id, note_text, created_by) VALUES (?, ?, ?)")
            ->execute([$new_booking_id, 'Booking created manually by admin (' . ($user['full_name'] ?? $user['username']) . ')', $user['id']]);
        
        $pdo->commit();
        
        // Send email if requested
        if ($send_email) {
            require_once '../config/email.php';
            $booking_data = [
                'id' => $new_booking_id,
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
                'room_name' => $room['name']
            ];
            
            if ($booking_status === 'confirmed') {
                $email_result = sendBookingConfirmedEmail($booking_data);
            } elseif ($is_tentative) {
                $email_result = sendTentativeBookingConfirmedEmail($booking_data);
            } else {
                $email_result = sendBookingReceivedEmail($booking_data);
            }
            
            $email_msg = $email_result['success'] ? ' Email sent to guest.' : ' (Email failed: ' . $email_result['message'] . ')';
        } else {
            $email_msg = '';
        }
        
        $message = "Booking <strong>{$booking_reference}</strong> created successfully!{$email_msg} <a href='booking-details.php?id={$new_booking_id}'>View Details</a>";
        
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $error = $e->getMessage();
    }
}

// Build rooms JSON for JavaScript
$rooms_json = json_encode(array_map(function($r) {
    return [
        'id' => (int)$r['id'],
        'name' => $r['name'],
        'max_guests' => (int)$r['max_guests'],
        'price_per_night' => (float)$r['price_per_night'],
        'price_single' => (float)($r['price_single_occupancy'] ?? $r['price_per_night']),
        'price_double' => (float)($r['price_double_occupancy'] ?? $r['price_per_night'] * 1.2),
        'price_triple' => (float)($r['price_triple_occupancy'] ?? $r['price_per_night'] * 1.4),
        'rooms_available' => (int)$r['rooms_available'],
    ];
}, $rooms));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create Booking - Admin Panel</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;500;600;700&family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../css/style.css">
    <link rel="stylesheet" href="../css/theme-dynamic.php">
    <link rel="stylesheet" href="css/admin-styles.css">
    <link rel="stylesheet" href="css/admin-components.css">
    <style>
        .create-booking-container { max-width: 900px; margin: 0 auto; padding: 20px; }
        .page-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px; }
        .page-title { font-family: 'Playfair Display', serif; font-size: 28px; color: var(--navy); }
        .form-card { background: white; border-radius: 12px; padding: 32px; box-shadow: 0 2px 8px rgba(0,0,0,0.08); margin-bottom: 24px; }
        .form-card h3 { color: var(--navy); margin-bottom: 20px; padding-bottom: 12px; border-bottom: 2px solid var(--gold); font-size: 18px; }
        .form-card h3 i { color: var(--gold); margin-right: 8px; }
        .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 16px; }
        .form-row.three-col { grid-template-columns: 1fr 1fr 1fr; }
        .form-group { margin-bottom: 16px; }
        .form-group label { display: block; font-weight: 600; color: var(--navy); margin-bottom: 6px; font-size: 13px; }
        .form-group label .required { color: #dc3545; }
        .form-group input, .form-group select, .form-group textarea {
            width: 100%; padding: 10px 14px; border: 2px solid #e0e0e0; border-radius: 8px;
            font-size: 14px; font-family: 'Poppins', sans-serif; box-sizing: border-box; transition: border-color 0.3s;
        }
        .form-group input:focus, .form-group select:focus, .form-group textarea:focus { outline: none; border-color: var(--gold); }
        .summary-box { background: #f8f9fa; border-radius: 10px; padding: 20px; border-left: 4px solid var(--gold); }
        .summary-row { display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px solid #e9ecef; }
        .summary-row:last-child { border-bottom: none; font-weight: 700; font-size: 18px; color: var(--gold); }
        .btn-create { 
            padding: 14px 32px; background: linear-gradient(135deg, var(--gold) 0%, #c49b2e 100%);
            color: var(--deep-navy); border: none; border-radius: 10px; font-size: 16px;
            font-weight: 700; cursor: pointer; transition: all 0.3s; width: 100%;
        }
        .btn-create:hover { transform: translateY(-2px); box-shadow: 0 6px 20px rgba(212,175,55,0.4); }
        .checkbox-group { display: flex; align-items: center; gap: 8px; margin-bottom: 12px; }
        .checkbox-group input[type="checkbox"] { width: auto; }
        .alert { padding: 16px; border-radius: 8px; margin-bottom: 20px; }
        .alert-success { background: #d1e7dd; color: #0f5132; border-left: 4px solid #198754; }
        .alert-error { background: #f8d7da; color: #842029; border-left: 4px solid #dc3545; }
        @media (max-width: 768px) {
            .form-row, .form-row.three-col { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
    <?php require_once 'includes/admin-header.php'; ?>
    
    <div class="content">
        <div class="create-booking-container">
            <div class="page-header">
                <h1 class="page-title"><i class="fas fa-plus-circle" style="color: var(--gold);"></i> Create Booking</h1>
                <a href="bookings.php" style="color: var(--gold); text-decoration: none; font-weight: 600;"><i class="fas fa-arrow-left"></i> Back to Bookings</a>
            </div>
            
            <?php if ($message): ?>
                <div class="alert alert-success"><?php echo $message; ?></div>
            <?php endif; ?>
            <?php if ($error): ?>
                <div class="alert alert-error"><i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>
            
            <form method="POST" id="createBookingForm">
                <input type="hidden" name="create_booking" value="1">
                
                <!-- Room Selection -->
                <div class="form-card">
                    <h3><i class="fas fa-bed"></i> Room Selection</h3>
                    <div class="form-row">
                        <div class="form-group">
                            <label>Room <span class="required">*</span></label>
                            <select name="room_id" id="roomSelect" required onchange="updateRoomInfo()">
                                <option value="">-- Select Room --</option>
                                <?php foreach ($rooms as $room): ?>
                                    <option value="<?php echo $room['id']; ?>" 
                                            data-max-guests="<?php echo $room['max_guests']; ?>"
                                            data-available="<?php echo $room['rooms_available']; ?>"
                                            <?php echo ($room['rooms_available'] <= 0) ? 'disabled' : ''; ?>>
                                        <?php echo htmlspecialchars($room['name']); ?> 
                                        (<?php echo $currency_symbol; ?><?php echo number_format($room['price_per_night']); ?>/night)
                                        <?php echo ($room['rooms_available'] <= 0) ? ' - FULLY BOOKED' : " - {$room['rooms_available']} available"; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Occupancy Type</label>
                            <select name="occupancy_type" id="occupancyType" onchange="calculateTotal()">
                                <option value="single">Single Occupancy</option>
                                <option value="double" selected>Double Occupancy</option>
                                <option value="triple">Triple Occupancy</option>
                            </select>
                        </div>
                    </div>
                </div>
                
                <!-- Guest Information -->
                <div class="form-card">
                    <h3><i class="fas fa-user"></i> Guest Information</h3>
                    <div class="form-row">
                        <div class="form-group">
                            <label>Full Name <span class="required">*</span></label>
                            <input type="text" name="guest_name" required value="<?php echo htmlspecialchars($_POST['guest_name'] ?? ''); ?>">
                        </div>
                        <div class="form-group">
                            <label>Email <span class="required">*</span></label>
                            <input type="email" name="guest_email" required value="<?php echo htmlspecialchars($_POST['guest_email'] ?? ''); ?>">
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label>Phone <span class="required">*</span></label>
                            <input type="tel" name="guest_phone" required value="<?php echo htmlspecialchars($_POST['guest_phone'] ?? ''); ?>">
                        </div>
                        <div class="form-group">
                            <label>Country</label>
                            <input type="text" name="guest_country" value="<?php echo htmlspecialchars($_POST['guest_country'] ?? ''); ?>">
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Address</label>
                        <textarea name="guest_address" rows="2"><?php echo htmlspecialchars($_POST['guest_address'] ?? ''); ?></textarea>
                    </div>
                </div>
                
                <!-- Stay Details -->
                <div class="form-card">
                    <h3><i class="fas fa-calendar-alt"></i> Stay Details</h3>
                    <div class="form-row three-col">
                        <div class="form-group">
                            <label>Check-in Date <span class="required">*</span></label>
                            <input type="date" name="check_in_date" id="checkInDate" required onchange="calculateTotal()" value="<?php echo htmlspecialchars($_POST['check_in_date'] ?? ''); ?>">
                        </div>
                        <div class="form-group">
                            <label>Check-out Date <span class="required">*</span></label>
                            <input type="date" name="check_out_date" id="checkOutDate" required onchange="calculateTotal()" value="<?php echo htmlspecialchars($_POST['check_out_date'] ?? ''); ?>">
                        </div>
                        <div class="form-group">
                            <label>Number of Guests <span class="required">*</span></label>
                            <input type="number" name="number_of_guests" id="numGuests" min="1" max="20" value="<?php echo htmlspecialchars($_POST['number_of_guests'] ?? '2'); ?>" required>
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Special Requests</label>
                        <textarea name="special_requests" rows="3" placeholder="Early check-in, extra pillows, etc."><?php echo htmlspecialchars($_POST['special_requests'] ?? ''); ?></textarea>
                    </div>
                </div>
                
                <!-- Booking & Payment Status -->
                <div class="form-card">
                    <h3><i class="fas fa-cog"></i> Booking & Payment</h3>
                    <div class="form-row three-col">
                        <div class="form-group">
                            <label>Booking Status</label>
                            <select name="booking_status" id="bookingStatus">
                                <option value="pending">Pending</option>
                                <option value="confirmed" selected>Confirmed</option>
                                <option value="tentative">Tentative</option>
                                <option value="checked-in">Checked In (Walk-in)</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Payment Status</label>
                            <select name="payment_status" id="paymentStatus" onchange="togglePaymentMethod()">
                                <option value="unpaid">Unpaid</option>
                                <option value="paid">Paid</option>
                            </select>
                        </div>
                        <div class="form-group" id="paymentMethodGroup" style="display: none;">
                            <label>Payment Method</label>
                            <select name="payment_method">
                                <option value="cash">Cash</option>
                                <option value="bank_transfer">Bank Transfer</option>
                                <option value="mobile_money">Mobile Money</option>
                                <option value="card">Card</option>
                                <option value="other">Other</option>
                            </select>
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Price Override (leave blank for auto-calculated)</label>
                        <input type="number" name="price_override" id="priceOverride" step="0.01" min="0" 
                               placeholder="Total amount override" onchange="calculateTotal()">
                        <small style="color: #666;">Override the automatically calculated total</small>
                    </div>
                    <div class="form-group">
                        <label>Admin Notes (internal only)</label>
                        <textarea name="admin_notes" rows="2" placeholder="Walk-in guest, phone booking, special arrangement, etc."><?php echo htmlspecialchars($_POST['admin_notes'] ?? ''); ?></textarea>
                    </div>
                    <div class="checkbox-group">
                        <input type="checkbox" name="send_email" id="sendEmail" checked>
                        <label for="sendEmail" style="margin-bottom: 0;">Send confirmation email to guest</label>
                    </div>
                </div>
                
                <!-- Booking Summary -->
                <div class="form-card">
                    <h3><i class="fas fa-receipt"></i> Booking Summary</h3>
                    <div class="summary-box" id="summaryBox">
                        <div class="summary-row">
                            <span>Room</span>
                            <span id="sumRoom">--</span>
                        </div>
                        <div class="summary-row">
                            <span>Rate per Night</span>
                            <span id="sumRate">--</span>
                        </div>
                        <div class="summary-row">
                            <span>Nights</span>
                            <span id="sumNights">--</span>
                        </div>
                        <div class="summary-row">
                            <span>Total</span>
                            <span id="sumTotal">--</span>
                        </div>
                    </div>
                </div>
                
                <button type="submit" class="btn-create">
                    <i class="fas fa-plus-circle"></i> Create Booking
                </button>
            </form>
        </div>
    </div>
    
    <script>
        const roomsData = <?php echo $rooms_json; ?>;
        const currency = '<?php echo $currency_symbol; ?>';
        
        function updateRoomInfo() {
            const roomId = parseInt(document.getElementById('roomSelect').value);
            const room = roomsData.find(r => r.id === roomId);
            if (room) {
                const guestsInput = document.getElementById('numGuests');
                guestsInput.max = room.max_guests;
                // Clamp current value to max capacity
                if (parseInt(guestsInput.value) > room.max_guests) {
                    guestsInput.value = room.max_guests;
                }
            }
            calculateTotal();
        }
        
        function calculateTotal() {
            const roomId = parseInt(document.getElementById('roomSelect').value);
            const room = roomsData.find(r => r.id === roomId);
            const checkIn = document.getElementById('checkInDate').value;
            const checkOut = document.getElementById('checkOutDate').value;
            const occupancy = document.getElementById('occupancyType').value;
            const priceOverride = document.getElementById('priceOverride').value;
            
            if (!room || !checkIn || !checkOut) {
                document.getElementById('sumRoom').textContent = '--';
                document.getElementById('sumRate').textContent = '--';
                document.getElementById('sumNights').textContent = '--';
                document.getElementById('sumTotal').textContent = '--';
                return;
            }
            
            const nights = Math.ceil((new Date(checkOut) - new Date(checkIn)) / (1000*60*60*24));
            if (nights <= 0) return;
            
            let rate = room.price_per_night;
            if (occupancy === 'single') rate = room.price_single;
            else if (occupancy === 'double') rate = room.price_double;
            else if (occupancy === 'triple') rate = room.price_triple;
            
            const total = priceOverride ? parseFloat(priceOverride) : (rate * nights);
            
            document.getElementById('sumRoom').textContent = room.name;
            document.getElementById('sumRate').textContent = currency + rate.toLocaleString();
            document.getElementById('sumNights').textContent = nights;
            document.getElementById('sumTotal').textContent = currency + total.toLocaleString();
        }
        
        function togglePaymentMethod() {
            const status = document.getElementById('paymentStatus').value;
            document.getElementById('paymentMethodGroup').style.display = (status === 'paid') ? 'block' : 'none';
        }
    </script>
    <script src="js/admin-components.js"></script>
    <script src="js/admin-mobile.js"></script>
</body>
</html>
