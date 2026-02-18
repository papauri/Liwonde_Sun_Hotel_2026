<?php
// Include admin initialization (PHP-only, no HTML output)
require_once 'admin-init.php';

$user = [
    'id' => $_SESSION['admin_user_id'],
    'username' => $_SESSION['admin_username'],
    'role' => $_SESSION['admin_role'],
    'full_name' => $_SESSION['admin_full_name']
];
$booking_id = filter_var($_GET['id'] ?? 0, FILTER_VALIDATE_INT);

if (!$booking_id) {
    header('Location: dashboard.php');
    exit;
}

// Handle status changes (POST-only for CSRF protection)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['booking_action'])) {
    $action = $_POST['booking_action'];
    
    try {
        switch ($action) {
            case 'convert':
                // Convert tentative booking to confirmed
                $stmt = $pdo->prepare("
                    SELECT b.*, r.name as room_name, r.slug as room_slug
                    FROM bookings b
                    LEFT JOIN rooms r ON b.room_id = r.id
                    WHERE b.id = ?
                ");
                $stmt->execute([$booking_id]);
                $booking_data = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$booking_data) {
                    $_SESSION['error_message'] = 'Booking not found.';
                } elseif ($booking_data['status'] !== 'tentative' || $booking_data['is_tentative'] != 1) {
                    $_SESSION['error_message'] = 'This is not a tentative booking.';
                } else {
                    // Convert to confirmed
                    $update = $pdo->prepare("UPDATE bookings SET status = 'confirmed', is_tentative = 0, updated_at = NOW() WHERE id = ?");
                    $update->execute([$booking_id]);
                    
                    // Log the conversion
                    try {
                        $log_stmt = $pdo->prepare("
                            INSERT INTO tentative_booking_log (
                                booking_id, action, action_by, action_at, notes
                            ) VALUES (?, 'converted', ?, NOW(), ?)
                        ");
                        $log_stmt->execute([
                            $booking_id,
                            $user['id'],
                            'Converted from tentative to confirmed by admin'
                        ]);
                    } catch (PDOException $logError) {
                        error_log("Tentative log error: " . $logError->getMessage());
                    }
                    
                    // Send conversion email
                    require_once '../config/email.php';
                    $email_result = sendTentativeBookingConvertedEmail($booking_data);
                    
                    if ($email_result['success']) {
                        $_SESSION['success_message'] = 'Tentative booking converted to confirmed! Conversion email sent to guest.';
                    } else {
                        $_SESSION['success_message'] = 'Tentative booking converted! (Email failed: ' . $email_result['message'] . ')';
                    }
                }
                break;
            
            case 'confirm':
                $stmt = $pdo->prepare("UPDATE bookings SET status = 'confirmed', updated_at = NOW() WHERE id = ? AND status = 'pending'");
                $stmt->execute([$booking_id]);
                
                // Decrement room availability
                $room_stmt = $pdo->prepare("SELECT room_id FROM bookings WHERE id = ?");
                $room_stmt->execute([$booking_id]);
                $booking_room = $room_stmt->fetch(PDO::FETCH_ASSOC);
                if ($booking_room) {
                    $pdo->prepare("UPDATE rooms SET rooms_available = rooms_available - 1 WHERE id = ? AND rooms_available > 0")
                        ->execute([$booking_room['room_id']]);
                }
                
                // Send confirmation email
                require_once '../config/email.php';
                $conf_stmt = $pdo->prepare("SELECT b.*, r.name as room_name FROM bookings b LEFT JOIN rooms r ON b.room_id = r.id WHERE b.id = ?");
                $conf_stmt->execute([$booking_id]);
                $conf_booking = $conf_stmt->fetch(PDO::FETCH_ASSOC);
                if ($conf_booking) {
                    $email_result = sendBookingConfirmedEmail($conf_booking);
                    $_SESSION['success_message'] = 'Booking confirmed.' . ($email_result['success'] ? ' Confirmation email sent.' : '');
                } else {
                    $_SESSION['success_message'] = 'Booking confirmed successfully.';
                }
                break;
            
            case 'checkin':
                // Enforce payment check on check-in
                $check_stmt = $pdo->prepare("SELECT status, payment_status FROM bookings WHERE id = ?");
                $check_stmt->execute([$booking_id]);
                $check_row = $check_stmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$check_row) {
                    $_SESSION['error_message'] = 'Booking not found.';
                } elseif ($check_row['status'] !== 'confirmed') {
                    $_SESSION['error_message'] = 'Only confirmed bookings can be checked in.';
                } elseif ($check_row['payment_status'] !== 'paid') {
                    $_SESSION['error_message'] = 'Cannot check in: booking must be PAID first.';
                } else {
                    $stmt = $pdo->prepare("UPDATE bookings SET status = 'checked-in', updated_at = NOW() WHERE id = ?");
                    $stmt->execute([$booking_id]);
                    $_SESSION['success_message'] = 'Guest checked in successfully.';
                }
                break;
            
            case 'checkout':
                $stmt = $pdo->prepare("UPDATE bookings SET status = 'checked-out', updated_at = NOW() WHERE id = ? AND status = 'checked-in'");
                $stmt->execute([$booking_id]);
                if ($stmt->rowCount() > 0) {
                    // Restore room availability
                    $room_stmt = $pdo->prepare("SELECT room_id FROM bookings WHERE id = ?");
                    $room_stmt->execute([$booking_id]);
                    $checkout_room = $room_stmt->fetch(PDO::FETCH_ASSOC);
                    if ($checkout_room) {
                        $pdo->prepare("UPDATE rooms SET rooms_available = rooms_available + 1 WHERE id = ? AND rooms_available < total_rooms")
                            ->execute([$checkout_room['room_id']]);
                    }
                    $_SESSION['success_message'] = 'Guest checked out successfully. Room availability restored.';
                } else {
                    $_SESSION['error_message'] = 'Only checked-in guests can be checked out.';
                }
                break;
            
            case 'noshow':
                $check_stmt = $pdo->prepare("SELECT status, room_id FROM bookings WHERE id = ?");
                $check_stmt->execute([$booking_id]);
                $noshow_row = $check_stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($noshow_row && in_array($noshow_row['status'], ['confirmed', 'pending'])) {
                    $stmt = $pdo->prepare("UPDATE bookings SET status = 'no-show', updated_at = NOW() WHERE id = ?");
                    $stmt->execute([$booking_id]);
                    
                    // Restore room availability if was confirmed
                    if ($noshow_row['status'] === 'confirmed') {
                        $pdo->prepare("UPDATE rooms SET rooms_available = rooms_available + 1 WHERE id = ? AND rooms_available < total_rooms")
                            ->execute([$noshow_row['room_id']]);
                    }
                    $_SESSION['success_message'] = 'Booking marked as no-show.';
                } else {
                    $_SESSION['error_message'] = 'Cannot mark as no-show from current status.';
                }
                break;
            
            case 'cancel':
                // Get booking details before cancelling
                $booking_stmt = $pdo->prepare("
                    SELECT b.*, r.name as room_name
                    FROM bookings b
                    LEFT JOIN rooms r ON b.room_id = r.id
                    WHERE b.id = ?
                ");
                $booking_stmt->execute([$booking_id]);
                $booking = $booking_stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($booking) {
                    // Update booking status
                    $stmt = $pdo->prepare("UPDATE bookings SET status = 'cancelled', updated_at = NOW() WHERE id = ?");
                    $stmt->execute([$booking_id]);
                    
                    // Restore room availability
                    if ($booking['status'] === 'confirmed') {
                        $update_room = $pdo->prepare("UPDATE rooms SET rooms_available = rooms_available + 1 WHERE id = ? AND rooms_available < total_rooms");
                        $update_room->execute([$booking['room_id']]);
                    }
                    
                    // Send cancellation email
                    require_once '../config/email.php';
                    $cancellation_reason = $_POST['cancellation_reason'] ?? 'Cancelled by admin';
                    $email_result = sendBookingCancelledEmail($booking, $cancellation_reason);
                    
                    // Log cancellation to database
                    $email_sent = $email_result['success'];
                    $email_status = $email_result['message'];
                    logCancellationToDatabase(
                        $booking['id'],
                        $booking['booking_reference'],
                        'room',
                        $booking['guest_email'],
                        $user['id'],
                        $cancellation_reason,
                        $email_sent,
                        $email_status
                    );
                    
                    // Log cancellation to file
                    logCancellationToFile(
                        $booking['booking_reference'],
                        'room',
                        $booking['guest_email'],
                        $user['full_name'] ?? $user['username'],
                        $cancellation_reason,
                        $email_sent,
                        $email_status
                    );
                    
                    if ($email_sent) {
                        $_SESSION['success_message'] = 'Booking cancelled. Cancellation email sent to guest.';
                    } else {
                        $_SESSION['success_message'] = 'Booking cancelled. (Email failed: ' . $email_status . ')';
                    }
                } else {
                    $_SESSION['error_message'] = 'Booking not found.';
                }
                break;
        }
    } catch (PDOException $e) {
        $_SESSION['error_message'] = 'Action failed. Please try again.';
        error_log("Booking action error: " . $e->getMessage());
    }
    
    header("Location: booking-details.php?id=$booking_id");
    exit;
}

// Fetch booking details with payment status from payments table
try {
    $stmt = $pdo->prepare("
        SELECT b.*,
               r.name as room_name,
               r.price_per_night,
               COALESCE(p.payment_status, b.payment_status) as actual_payment_status,
               p.payment_reference,
               p.payment_date as last_payment_date,
               p.payment_amount,
               p.vat_rate,
               p.vat_amount,
               p.total_amount as payment_total_with_vat
        FROM bookings b
        JOIN rooms r ON b.room_id = r.id
        LEFT JOIN payments p ON b.id = p.booking_id AND p.booking_type = 'room' AND p.status = 'completed'
        WHERE b.id = ?
    ");
    $stmt->execute([$booking_id]);
    $booking = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$booking) {
        $_SESSION['error_message'] = 'Booking not found.';
        header('Location: dashboard.php');
        exit;
    }

    // Fetch booking notes
    $notes_stmt = $pdo->prepare("
        SELECT n.*, u.full_name as created_by_name 
        FROM booking_notes n
        LEFT JOIN admin_users u ON n.created_by = u.id
        WHERE n.booking_id = ?
        ORDER BY n.created_at DESC
    ");
    $notes_stmt->execute([$booking_id]);
    $notes = $notes_stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    $_SESSION['error_message'] = 'Unable to load booking details.';
    header('Location: dashboard.php');
    exit;
}

// Handle note submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_note'])) {
    $note_text = trim($_POST['note_text'] ?? '');
    
    if ($note_text) {
        try {
            $insert_stmt = $pdo->prepare("INSERT INTO booking_notes (booking_id, note_text, created_by) VALUES (?, ?, ?)");
            $insert_stmt->execute([$booking_id, $note_text, $user['id']]);
            $_SESSION['success_message'] = 'Note added successfully.';
            header("Location: booking-details.php?id=$booking_id");
            exit;
        } catch (PDOException $e) {
            $error_message = 'Failed to add note.';
        }
    }
}

// Handle payment status update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_payment'])) {
    $payment_status = $_POST['payment_status'];
    $previous_status = $booking['payment_status'];
    
    try {
        // Get VAT settings - more flexible check
        $vatEnabled = in_array(getSetting('vat_enabled'), ['1', 1, true, 'true', 'on'], true);
        $vatRate = $vatEnabled ? (float)getSetting('vat_rate') : 0;
        
        // Calculate amounts
        $totalAmount = (float)$booking['total_amount'];
        $vatAmount = $vatEnabled ? ($totalAmount * ($vatRate / 100)) : 0;
        $totalWithVat = $totalAmount + $vatAmount;
        
        // Update booking payment status
        $update_stmt = $pdo->prepare("UPDATE bookings SET payment_status = ?, updated_at = NOW() WHERE id = ?");
        $update_stmt->execute([$payment_status, $booking_id]);
        
        // If marking as paid, insert into payments table and update booking amounts
        if ($payment_status === 'paid' && $previous_status !== 'paid') {
            // Generate payment reference
            $payment_reference = 'PAY-' . date('Y') . '-' . str_pad($booking_id, 6, '0', STR_PAD_LEFT);
            
            // Insert into payments table
            $insert_payment = $pdo->prepare("
                INSERT INTO payments (
                    payment_reference, booking_type, booking_id, booking_reference,
                    payment_date, payment_amount, vat_rate, vat_amount, total_amount,
                    payment_method, payment_type, payment_status, invoice_generated,
                    status, recorded_by
                ) VALUES (?, 'room', ?, ?, CURDATE(), ?, ?, ?, ?, 'cash', 'full_payment', 'completed', 1, 'completed', ?)
            ");
            $insert_payment->execute([
                $payment_reference,
                $booking_id,
                $booking['booking_reference'],
                $totalAmount,
                $vatRate,
                $vatAmount,
                $totalWithVat,
                $user['id']
            ]);
            
            // Update booking payment tracking columns
            $update_amounts = $pdo->prepare("
                UPDATE bookings
                SET amount_paid = ?, amount_due = 0, vat_rate = ?, vat_amount = ?,
                    total_with_vat = ?, last_payment_date = CURDATE()
                WHERE id = ?
            ");
            $update_amounts->execute([$totalWithVat, $vatRate, $vatAmount, $totalWithVat, $booking_id]);
            
            // Send invoice email
            require_once '../config/invoice.php';
            $invoice_result = sendPaymentInvoiceEmail($booking_id);
            
            if ($invoice_result['success']) {
                $_SESSION['success_message'] = 'Payment status updated. Payment recorded. Invoice sent successfully!';
            } else {
                error_log("Invoice email failed: " . $invoice_result['message']);
                $_SESSION['success_message'] = 'Payment status updated. Payment recorded. (Invoice email failed - check logs)';
            }
        } else {
            $_SESSION['success_message'] = 'Payment status updated.';
        }
        
        header("Location: booking-details.php?id=$booking_id");
        exit;
    } catch (PDOException $e) {
        $error_message = 'Failed to update payment status: ' . $e->getMessage();
        error_log("Payment update error: " . $e->getMessage());
    }
}

$site_name = getSetting('site_name');
$currency_symbol = getSetting('currency_symbol');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Booking Details | <?php echo htmlspecialchars($site_name); ?> Admin</title>
    
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;500;600;700&family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../css/style.css">
    <link rel="stylesheet" href="../css/theme-dynamic.php">
    <link rel="stylesheet" href="css/admin-styles.css">
    <link rel="stylesheet" href="css/admin-components.css">
    
    <style>
        /* Booking details specific styles */
        .booking-details-container {
            max-width: 1200px;
            margin: 32px auto;
            padding: 0 32px;
        }
        .details-card {
            background: white;
            border-radius: 12px;
            padding: 32px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
            margin-bottom: 24px;
        }
        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 24px;
            padding-bottom: 16px;
            border-bottom: 2px solid #f0f0f0;
        }
        .card-header h2 {
            font-family: 'Playfair Display', serif;
            font-size: 24px;
            color: var(--navy);
        }
        .booking-ref {
            font-family: 'Courier New', monospace;
            font-size: 20px;
            font-weight: 700;
            color: var(--gold);
        }
        .details-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 24px;
            margin-bottom: 24px;
        }
        .detail-item label {
            display: block;
            font-size: 12px;
            color: #666;
            text-transform: uppercase;
            letter-spacing: 1px;
            margin-bottom: 6px;
        }
        .detail-item .value {
            font-size: 16px;
            font-weight: 600;
            color: var(--navy);
        }
        .status-badge {
            display: inline-block;
            padding: 6px 16px;
            border-radius: 16px;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
        }
        .status-pending {
            background: #fff3cd;
            color: #856404;
        }
        .status-confirmed {
            background: #d1ecf1;
            color: #0c5460;
        }
        .status-checked-in {
            background: #d4edda;
            color: #155724;
        }
        .status-checked-out {
            background: #e2e3e5;
            color: #383d41;
        }
        .status-cancelled {
            background: #f8d7da;
            color: #721c24;
        }
        .status-tentative {
            background: linear-gradient(135deg, #fff8e1 0%, #ffecb3 100%);
            color: var(--navy);
            font-weight: 600;
        }
        
        /* Payment status badges from payments table */
        .status-completed {
            background: #d4edda;
            color: #155724;
        }
        .status-payment-pending {
            background: #fff3cd;
            color: #856404;
        }
        .status-failed {
            background: #f8d7da;
            color: #721c24;
        }
        .status-refunded {
            background: #e2e3e5;
            color: #383d41;
        }
        .status-partially_refunded {
            background: #e2e3e5;
            color: #383d41;
        }
        .tentative-info {
            background: linear-gradient(135deg, #fff8e1 0%, #ffecb3 100%);
            border-left: 4px solid var(--gold);
            padding: 16px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        .tentative-info h4 {
            margin: 0 0 8px 0;
            color: var(--navy);
            font-size: 16px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .tentative-info p {
            margin: 0;
            color: #666;
            font-size: 14px;
        }
        .tentative-info .expires-at {
            margin-top: 12px;
            padding-top: 12px;
            border-top: 1px solid rgba(212, 175, 55, 0.3);
            font-weight: 600;
            color: var(--navy);
        }
        .tentative-info .expires-at i {
            color: #dc3545;
            margin-right: 6px;
        }
        .action-buttons {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
        }
        .btn {
            padding: 10px 24px;
            border: none;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
            transition: all 0.3s ease;
        }
        .btn-success {
            background: #28a745;
            color: white;
        }
        .btn-primary {
            background: var(--gold);
            color: var(--deep-navy);
        }
        .btn-danger {
            background: #dc3545;
            color: white;
        }
        .btn-warning {
            background: #ffc107;
            color: var(--deep-navy);
        }
        .notes-section {
            margin-top: 32px;
        }
        .note-item {
            background: #f8f9fa;
            padding: 16px;
            border-radius: 8px;
            margin-bottom: 12px;
            border-left: 3px solid var(--gold);
        }
        .note-header {
            display: flex;
            justify-content: space-between;
            margin-bottom: 8px;
            font-size: 12px;
            color: #666;
        }
        .note-text {
            color: var(--navy);
            line-height: 1.6;
        }
        .add-note-form textarea {
            width: 100%;
            padding: 12px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            font-family: 'Poppins', sans-serif;
            font-size: 14px;
            resize: vertical;
            min-height: 80px;
        }
        .add-note-form textarea:focus {
            outline: none;
            border-color: var(--gold);
        }
        select.form-control {
            padding: 10px 16px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            font-size: 14px;
        }
        @media (max-width: 768px) {
            .booking-details-container {
                padding: 0 20px;
            }
            .details-grid {
                grid-template-columns: 1fr;
            }
            .action-buttons {
                flex-direction: column;
            }
        }
    </style>
</head>
<body>

 <?php require_once 'includes/admin-header.php'; ?>
    
    <div class="booking-details-container">
        <div class="details-card">
            <div class="card-header">
                <div>
                    <h2>Booking Details</h2>
                    <div class="booking-ref"><?php echo htmlspecialchars($booking['booking_reference']); ?></div>
                </div>
                <div style="text-align: right;">
                    <small style="color: #666; font-size: 12px;">
                        <i class="fas fa-clock"></i> Created: <?php echo date('M j, Y \a\t g:i A', strtotime($booking['created_at'])); ?>
                    </small>
                    <?php if ($booking['updated_at'] && $booking['updated_at'] != $booking['created_at']): ?>
                        <br><small style="color: #999; font-size: 11px;">
                            <i class="fas fa-edit"></i> Updated: <?php echo date('M j, Y \a\t g:i A', strtotime($booking['updated_at'])); ?>
                        </small>
                    <?php endif; ?>
                </div>
            </div>

            <div class="details-grid">
                <div class="detail-item">
                    <label>Guest Name</label>
                    <div class="value"><?php echo htmlspecialchars($booking['guest_name']); ?></div>
                </div>
                <div class="detail-item">
                    <label>Email</label>
                    <div class="value"><?php echo htmlspecialchars($booking['guest_email']); ?></div>
                </div>
                <div class="detail-item">
                    <label>Phone</label>
                    <div class="value"><?php echo htmlspecialchars($booking['guest_phone']); ?></div>
                </div>
                <div class="detail-item">
                    <label>Country</label>
                    <div class="value"><?php echo htmlspecialchars($booking['guest_country'] ?: 'N/A'); ?></div>
                </div>
                <div class="detail-item">
                    <label>Room</label>
                    <div class="value"><?php echo htmlspecialchars($booking['room_name']); ?></div>
                </div>
                <div class="detail-item">
                    <label>Number of Guests</label>
                    <div class="value"><?php echo $booking['number_of_guests']; ?></div>
                </div>
                <div class="detail-item">
                    <label>Check-in Date</label>
                    <div class="value"><?php echo date('M j, Y', strtotime($booking['check_in_date'])); ?></div>
                </div>
                <div class="detail-item">
                    <label>Check-out Date</label>
                    <div class="value"><?php echo date('M j, Y', strtotime($booking['check_out_date'])); ?></div>
                </div>
                <div class="detail-item">
                    <label>Number of Nights</label>
                    <div class="value"><?php echo $booking['number_of_nights']; ?></div>
                </div>
                <div class="detail-item">
                    <label>Total Amount</label>
                    <div class="value" style="color: var(--gold); font-size: 20px;">
                        <?php echo $currency_symbol; ?><?php echo number_format($booking['total_amount'], 0); ?>
                    </div>
                </div>
                <div class="detail-item">
                    <label>Booking Status</label>
                    <div class="value">
                        <span class="status-badge status-<?php echo $booking['status']; ?>">
                            <?php echo ucfirst($booking['status']); ?>
                        </span>
                    </div>
                </div>
                <div class="detail-item">
                    <label>Payment Status</label>
                    <div class="value">
                        <span class="status-badge status-<?php echo $booking['actual_payment_status']; ?>">
                            <?php
                                $status = $booking['actual_payment_status'];
                                $status_labels = [
                                    'paid' => 'Paid',
                                    'unpaid' => 'Unpaid',
                                    'partial' => 'Partial',
                                    'completed' => 'Paid',
                                    'pending' => 'Pending',
                                    'failed' => 'Failed',
                                    'refunded' => 'Refunded',
                                    'partially_refunded' => 'Partial Refund'
                                ];
                                echo $status_labels[$status] ?? ucfirst($status);
                            ?>
                        </span>
                        <?php if ($booking['payment_reference']): ?>
                            <br><small style="color: #666; font-size: 11px; margin-top: 4px; display: block;">
                                <i class="fas fa-receipt"></i> <?php echo htmlspecialchars($booking['payment_reference']); ?>
                                <?php if ($booking['last_payment_date']): ?>
                                    <br><i class="fas fa-calendar"></i> <?php echo date('M j, Y \a\t g:i A', strtotime($booking['last_payment_date'] . ' 12:00:00')); ?>
                                <?php endif; ?>
                            </small>
                        <?php endif; ?>
                    </div>
                </div>
                
                <?php if ($booking['payment_status'] !== 'paid'): ?>
                <div class="detail-item">
                    <label>Update Payment</label>
                    <div class="value">
                        <form method="POST" style="display: inline;">
                            <select name="payment_status" class="form-control" onchange="this.form.submit()">
                                <option value="">Mark as Paid...</option>
                                <option value="paid">Mark Paid</option>
                            </select>
                            <input type="hidden" name="update_payment" value="1">
                        </form>
                    </div>
                </div>
                <?php endif; ?>
            </div>

            <?php
                $is_tentative = ($booking['status'] === 'tentative' || $booking['is_tentative'] == 1);
                if ($is_tentative && $booking['tentative_expires_at']):
            ?>
            <div class="tentative-info">
                <h4><i class="fas fa-clock"></i> Tentative Booking</h4>
                <p>This booking is currently on hold. The guest has not yet confirmed their reservation.</p>
                <div class="expires-at">
                    <i class="fas fa-exclamation-triangle"></i>
                    Expires: <?php echo date('M j, Y \a\t g:i A', strtotime($booking['tentative_expires_at'])); ?>
                </div>
            </div>
            <?php endif; ?>

            <?php if ($booking['special_requests']): ?>
            <div class="detail-item" style="margin-top: 20px;">
                <label>Special Requests</label>
                <div class="value"><?php echo nl2br(htmlspecialchars($booking['special_requests'])); ?></div>
            </div>
            <?php endif; ?>

            <div style="margin-top: 32px;">
                <label style="display: block; margin-bottom: 12px; font-weight: 600;">Quick Actions</label>
                <div class="action-buttons">
                    <?php if ($booking['status'] == 'tentative' || $booking['is_tentative'] == 1): ?>
                    <form method="POST" style="display:inline;" onsubmit="return confirm('Convert this tentative booking to confirmed?')">
                        <input type="hidden" name="booking_action" value="convert">
                        <button type="submit" class="btn btn-success"><i class="fas fa-check"></i> Convert to Confirmed</button>
                    </form>
                    <?php endif; ?>
                    
                    <?php if ($booking['status'] == 'pending'): ?>
                    <form method="POST" style="display:inline;" onsubmit="return confirm('Confirm this booking?')">
                        <input type="hidden" name="booking_action" value="confirm">
                        <button type="submit" class="btn btn-success"><i class="fas fa-check"></i> Confirm Booking</button>
                    </form>
                    <?php endif; ?>

                    <?php if ($booking['status'] == 'confirmed'): ?>
                    <?php $can_checkin = ($booking['actual_payment_status'] === 'paid' || $booking['actual_payment_status'] === 'completed'); ?>
                    <form method="POST" style="display:inline;" onsubmit="return confirm('Check in this guest?')">
                        <input type="hidden" name="booking_action" value="checkin">
                        <button type="submit" class="btn btn-primary" <?php echo $can_checkin ? '' : 'disabled title="Guest must pay before check-in"'; ?>>
                            <i class="fas fa-sign-in-alt"></i> Check In
                        </button>
                    </form>
                    <?php if (!$can_checkin): ?>
                        <small style="color: #dc3545; display: block; margin-top: 4px;"><i class="fas fa-info-circle"></i> Payment required before check-in</small>
                    <?php endif; ?>
                    <?php endif; ?>

                    <?php if ($booking['status'] == 'checked-in'): ?>
                    <form method="POST" style="display:inline;" onsubmit="return confirm('Check out this guest?')">
                        <input type="hidden" name="booking_action" value="checkout">
                        <button type="submit" class="btn btn-warning"><i class="fas fa-sign-out-alt"></i> Check Out</button>
                    </form>
                    <?php endif; ?>
                    
                    <?php if (in_array($booking['status'], ['confirmed', 'pending']) && strtotime($booking['check_in_date']) < strtotime('today')): ?>
                    <form method="POST" style="display:inline;" onsubmit="return confirm('Mark this booking as no-show? The room will be released.')">
                        <input type="hidden" name="booking_action" value="noshow">
                        <button type="submit" class="btn" style="background: #6c757d; color: white;"><i class="fas fa-user-slash"></i> No-Show</button>
                    </form>
                    <?php endif; ?>

                    <?php if (!in_array($booking['status'], ['checked-out', 'cancelled', 'no-show'])): ?>
                    <form method="POST" style="display:inline;" onsubmit="return confirm('Cancel this booking? This cannot be undone.')">
                        <input type="hidden" name="booking_action" value="cancel">
                        <input type="hidden" name="cancellation_reason" value="Cancelled by admin">
                        <button type="submit" class="btn btn-danger"><i class="fas fa-times"></i> Cancel Booking</button>
                    </form>
                    <?php endif; ?>
                    
                    <a href="bookings.php" class="btn" style="background: #f0f0f0; color: #333;"><i class="fas fa-arrow-left"></i> Back to Bookings</a>
                    <a href="edit-booking.php?id=<?php echo $booking_id; ?>" class="btn" style="background: #007bff; color: white;"><i class="fas fa-edit"></i> Edit Booking</a>
                </div>
            </div>
        </div>

        <div class="details-card">
            <h3 style="margin-bottom: 20px; color: var(--navy);">Internal Notes</h3>
            
            <div class="add-note-form">
                <form method="POST">
                    <textarea name="note_text" placeholder="Add a note about this booking..." required></textarea>
                    <button type="submit" name="add_note" class="btn btn-primary" style="margin-top: 12px;">
                        <i class="fas fa-plus"></i> Add Note
                    </button>
                </form>
            </div>

            <div class="notes-section">
                <?php if (empty($notes)): ?>
                <p style="color: #999; text-align: center; padding: 20px;">No notes yet</p>
                <?php else: ?>
                <?php foreach ($notes as $note): ?>
                <div class="note-item">
                    <div class="note-header">
                        <span><strong><?php echo htmlspecialchars($note['created_by_name'] ?? 'Unknown'); ?></strong></span>
                        <span><?php echo date('M j, Y g:i A', strtotime($note['created_at'])); ?></span>
                    </div>
                    <div class="note-text"><?php echo nl2br(htmlspecialchars($note['note_text'])); ?></div>
                </div>
                <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <script src="js/admin-components.js"></script>
    <script src="js/admin-mobile.js"></script>
</body>
</html>
