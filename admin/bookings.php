<?php
// Include admin initialization (PHP-only, no HTML output)
require_once 'admin-init.php';

require_once '../includes/modal.php';
require_once '../includes/alert.php';
$message = '';
$error = '';

if (!ensureRoomUnitInfrastructure()) {
    $error = 'Room unit infrastructure could not be initialized. Assignment badges may be incomplete.';
}

function resolveRoomUnitAssignmentSource(array $booking) {
    $source = strtolower(trim((string)($booking['room_unit_assignment_source'] ?? '')));
    if ($source === 'auto' || $source === 'manual' || $source === 'released') {
        return $source;
    }
    if (!empty($booking['room_unit_id'])) {
        return 'auto';
    }
    return '';
}

// Handle booking actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $action = $_POST['action'] ?? '';

        if ($action === 'resend_email') {
            $booking_id = (int)($_POST['booking_id'] ?? 0);
            $email_type = $_POST['email_type'] ?? '';
            $cc_emails = $_POST['cc_emails'] ?? '';
            
            if ($booking_id <= 0) {
                throw new Exception('Invalid booking id');
            }
            
            // Get booking details
            $stmt = $pdo->prepare("
                SELECT b.*, r.name as room_name 
                FROM bookings b
                LEFT JOIN rooms r ON b.room_id = r.id
                WHERE b.id = ?
            ");
            $stmt->execute([$booking_id]);
            $booking = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$booking) {
                throw new Exception('Booking not found');
            }
            
            // Include email functions
            require_once '../config/email.php';
            
            // Parse CC emails
            $cc_array = [];
            if (!empty($cc_emails)) {
                $cc_array = array_filter(array_map('trim', explode(',', $cc_emails)));
                $cc_array = array_filter($cc_array, function($email) {
                    return filter_var($email, FILTER_VALIDATE_EMAIL);
                });
            }
            
            // Send appropriate email based on type
            $email_result = ['success' => false, 'message' => 'Invalid email type'];
            
            switch ($email_type) {
                case 'booking_received':
                    $email_result = sendBookingReceivedEmail($booking);
                    break;
                case 'booking_confirmed':
                    $email_result = sendBookingConfirmedEmail($booking);
                    break;
                case 'tentative_confirmed':
                    $booking['tentative_expires_at'] = $booking['tentative_expires_at'] ?? date('Y-m-d H:i:s', strtotime('+48 hours'));
                    $email_result = sendTentativeBookingConfirmedEmail($booking);
                    break;
                case 'tentative_converted':
                    $email_result = sendTentativeBookingConvertedEmail($booking);
                    break;
                case 'booking_cancelled':
                    $cancellation_reason = 'Resent by admin';
                    $email_result = sendBookingCancelledEmail($booking, $cancellation_reason);
                    break;
                default:
                    throw new Exception('Invalid email type selected');
            }
            
            if ($email_result['success']) {
                $message = 'Email sent successfully to ' . htmlspecialchars($booking['guest_email']);
                if (!empty($cc_array)) {
                    $message .= ' (CC: ' . implode(', ', array_map(function($email) {
                        return htmlspecialchars($email);
                    }, $cc_array)) . ')';
                }
            } else {
                throw new Exception('Failed to send email: ' . $email_result['message']);
            }
            
        } elseif ($action === 'make_tentative') {
            $booking_id = (int)($_POST['id'] ?? 0);
            
            if ($booking_id <= 0) {
                throw new Exception('Invalid booking id');
            }
            
            // Get booking details
            $stmt = $pdo->prepare("SELECT * FROM bookings WHERE id = ?");
            $stmt->execute([$booking_id]);
            $booking = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$booking) {
                throw new Exception('Booking not found');
            }
            
            if ($booking['status'] !== 'pending') {
                throw new Exception('Only pending bookings can be made tentative');
            }
            
            // Get tentative duration setting
            $tentative_hours = (int)getSetting('tentative_duration_hours', 48);
            $expires_at = date('Y-m-d H:i:s', strtotime("+$tentative_hours hours"));
            
            // Convert to tentative status
            $update_stmt = $pdo->prepare("
                UPDATE bookings
                SET status = 'tentative',
                    is_tentative = 1,
                    tentative_expires_at = ?
                WHERE id = ?
            ");
            $update_stmt->execute([$expires_at, $booking_id]);
            
            // Log the action
            $log_stmt = $pdo->prepare("
                INSERT INTO tentative_booking_log (
                    booking_id, action, new_expires_at, performed_by, created_at
                ) VALUES (?, 'created', ?, ?, NOW())
            ");
            $log_stmt->execute([
                $booking_id,
                $expires_at,
                $user['id']
            ]);
            
            // Send tentative booking email
            require_once '../config/email.php';
            $booking['tentative_expires_at'] = $expires_at;
            $email_result = sendTentativeBookingConfirmedEmail($booking);
            
            if ($email_result['success']) {
                $message = 'Booking converted to tentative! Confirmation email sent to guest.';
            } else {
                $message = 'Booking made tentative! (Email failed: ' . $email_result['message'] . ')';
            }
            
        } elseif ($action === 'convert_tentative') {
            $booking_id = (int)($_POST['id'] ?? 0);
            
            if ($booking_id <= 0) {
                throw new Exception('Invalid booking id');
            }
            
            // Get booking details WITH room information
            $stmt = $pdo->prepare("
                SELECT b.*, r.name as room_name, r.slug as room_slug
                FROM bookings b
                LEFT JOIN rooms r ON b.room_id = r.id
                WHERE b.id = ?
            ");
            $stmt->execute([$booking_id]);
            $booking = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$booking) {
                throw new Exception('Booking not found');
            }
            
            if ($booking['status'] !== 'tentative' || $booking['is_tentative'] != 1) {
                throw new Exception('This is not a tentative booking');
            }
            
            // Convert to confirmed status
            $update_stmt = $pdo->prepare("UPDATE bookings SET status = 'confirmed', is_tentative = 0 WHERE id = ?");
            $update_stmt->execute([$booking_id]);
            
            // Log the conversion
            $log_stmt = $pdo->prepare("
                INSERT INTO tentative_booking_log (
                    booking_id, action, action_reason, performed_by, created_at
                ) VALUES (?, 'converted', ?, ?, NOW())
            ");
            $log_stmt->execute([
                $booking_id,
                'Converted from tentative to confirmed by admin',
                $user['id']
            ]);
            
            // Send conversion email
            require_once '../config/email.php';
            $email_result = sendTentativeBookingConvertedEmail($booking);
            
            // Log email result for debugging
            error_log("Email sending result for booking {$booking_id}: " . json_encode($email_result));
            
            if ($email_result['success']) {
                if (isset($email_result['preview_url'])) {
                    $message = 'Tentative booking converted to confirmed! <a href="../' . htmlspecialchars($email_result['preview_url']) . '" target="_blank">View email preview</a> (Development Mode)';
                } else {
                    $message = 'Tentative booking converted to confirmed! Conversion email sent to ' . htmlspecialchars($booking['guest_email']);
                }
            } else {
                $message = 'Tentative booking converted! <strong>Email failed:</strong> ' . htmlspecialchars($email_result['message']);
                error_log("FAILED to send email for converted booking {$booking_id}: " . $email_result['message']);
            }
            
        } elseif ($action === 'convert_to_tentative') {
            $booking_id = (int)($_POST['id'] ?? 0);
            
            if ($booking_id <= 0) {
                throw new Exception('Invalid booking id');
            }
            
            // Get booking details
            $stmt = $pdo->prepare("
                SELECT b.*, r.name as room_name, r.slug as room_slug
                FROM bookings b
                LEFT JOIN rooms r ON b.room_id = r.id
                WHERE b.id = ?
            ");
            $stmt->execute([$booking_id]);
            $booking = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$booking) {
                throw new Exception('Booking not found');
            }
            
            if ($booking['status'] !== 'confirmed') {
                throw new Exception('Only confirmed bookings can be converted to tentative');
            }
            
            // Get tentative duration setting
            $tentative_hours = (int)getSetting('tentative_duration_hours', 48);
            $expires_at = date('Y-m-d H:i:s', strtotime("+$tentative_hours hours"));
            
            // Convert to tentative status
            $update_stmt = $pdo->prepare("
                UPDATE bookings
                SET status = 'tentative',
                    is_tentative = 1,
                    tentative_expires_at = ?
                WHERE id = ?
            ");
            $update_stmt->execute([$expires_at, $booking_id]);
            
            // Log the conversion
            $log_stmt = $pdo->prepare("
                INSERT INTO tentative_booking_log (
                    booking_id, action, new_expires_at, action_reason, performed_by, created_at
                ) VALUES (?, 'created', ?, 'Converted from confirmed to tentative by admin', ?, NOW())
            ");
            $log_stmt->execute([
                $booking_id,
                $expires_at,
                $user['id']
            ]);
            
            // Send tentative booking email
            require_once '../config/email.php';
            $booking['tentative_expires_at'] = $expires_at;
            $email_result = sendTentativeBookingConfirmedEmail($booking);
            
            if ($email_result['success']) {
                $message = 'Confirmed booking converted to tentative! Email sent to guest.';
            } else {
                $message = 'Booking converted to tentative! (Email failed: ' . $email_result['message'] . ')';
            }
            
        } elseif ($action === 'update_status') {
            $booking_id = (int)($_POST['id'] ?? 0);
            $new_status = $_POST['status'] ?? '';

            if ($booking_id <= 0) {
                throw new Exception('Invalid booking id');
            }

            // Enforce business rules:
            // - Check-in only allowed when confirmed AND paid
            // - Cancel check-in (undo) allowed only when currently checked-in
            if ($new_status === 'checked-in') {
                $stmt = $pdo->prepare("UPDATE bookings SET status = 'checked-in' WHERE id = ? AND status = 'confirmed' AND payment_status = 'paid'");
                $stmt->execute([$booking_id]);
                if ($stmt->rowCount() === 0) {
                    $check = $pdo->prepare("SELECT status, payment_status FROM bookings WHERE id = ?");
                    $check->execute([$booking_id]);
                    $row = $check->fetch(PDO::FETCH_ASSOC);
                    if (!$row) {
                        throw new Exception('Booking not found');
                    }
                    throw new Exception("Cannot check in unless booking is CONFIRMED and PAID (current: status={$row['status']}, payment={$row['payment_status']})");
                }
                $message = 'Guest checked in!';

            } elseif ($new_status === 'cancel-checkin') {
                $stmt = $pdo->prepare("UPDATE bookings SET status = 'confirmed' WHERE id = ? AND status = 'checked-in'");
                $stmt->execute([$booking_id]);
                if ($stmt->rowCount() === 0) {
                    $check = $pdo->prepare("SELECT status FROM bookings WHERE id = ?");
                    $check->execute([$booking_id]);
                    $row = $check->fetch(PDO::FETCH_ASSOC);
                    if (!$row) {
                        throw new Exception('Booking not found');
                    }
                    throw new Exception("Cannot cancel check-in unless booking is currently checked-in (current: {$row['status']})");
                }
                $message = 'Check-in cancelled (reverted to confirmed).';
            } else {
                $allowed = ['pending', 'confirmed', 'checked-out', 'cancelled'];
                if (!in_array($new_status, $allowed, true)) {
                    throw new Exception('Invalid status');
                }
                
                // Get current booking status and date range before updating
                $check_stmt = $pdo->prepare("SELECT status, room_id, room_unit_id, check_in_date, check_out_date FROM bookings WHERE id = ?");
                $check_stmt->execute([$booking_id]);
                $current_booking = $check_stmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$current_booking) {
                    throw new Exception('Booking not found');
                }
                
                $current_status = $current_booking['status'];
                $room_id = $current_booking['room_id'];

                // Guard: cannot confirm an expired booking (check-out date already past)
                if ($new_status === 'confirmed') {
                    $exp_stmt = $pdo->prepare("SELECT check_out_date FROM bookings WHERE id = ?");
                    $exp_stmt->execute([$booking_id]);
                    $exp_row = $exp_stmt->fetch(PDO::FETCH_ASSOC);
                    if ($exp_row && strtotime($exp_row['check_out_date']) < strtotime('today')) {
                        throw new Exception('Cannot confirm an expired booking - the check-out date has already passed.');
                    }
                }
                
                if ($current_status === 'pending' && $new_status === 'confirmed') {
                    $pdo->beginTransaction();

                    if (empty($current_booking['room_unit_id'])) {
                        $allocation_error = null;
                        $allocated_room_unit_id = allocateRoomUnitForBooking(
                            $room_id,
                            $current_booking['check_in_date'],
                            $current_booking['check_out_date'],
                            null,
                            $booking_id,
                            $allocation_error
                        );

                        if ($allocated_room_unit_id === false) {
                            throw new Exception($allocation_error ?: 'Could not auto-assign a room unit for this booking.');
                        }

                        $assign_stmt = $pdo->prepare("UPDATE bookings SET room_unit_id = ?, room_unit_assignment_source = 'auto', updated_at = NOW() WHERE id = ?");
                        $assign_stmt->execute([$allocated_room_unit_id, $booking_id]);
                    }

                    $reserve_error = null;
                    if (!reserveRoomForDateRange($room_id, $current_booking['check_in_date'], $current_booking['check_out_date'], $booking_id, $reserve_error)) {
                        throw new Exception($reserve_error ?: 'Room capacity reached for selected dates.');
                    }

                    $stmt = $pdo->prepare("UPDATE bookings SET status = ? WHERE id = ? AND status = 'pending'");
                    $stmt->execute([$new_status, $booking_id]);
                    if ($stmt->rowCount() === 0) {
                        throw new Exception('Booking could not be confirmed because it was changed by another action.');
                    }

                    $pdo->commit();
                    $message = 'Booking status updated! Room capacity reserved.';
                    
                    // Send booking confirmed email
                    $booking_stmt = $pdo->prepare("
                        SELECT b.*, r.name as room_name 
                        FROM bookings b
                        LEFT JOIN rooms r ON b.room_id = r.id
                        WHERE b.id = ?
                    ");
                    $booking_stmt->execute([$booking_id]);
                    $booking = $booking_stmt->fetch(PDO::FETCH_ASSOC);
                    
                    if ($booking) {
                        // Include email functions
                        require_once '../config/email.php';
                        
                        // Send booking confirmed email
                        $email_result = sendBookingConfirmedEmail($booking);
                        
                        if ($email_result['success']) {
                            $message .= ' Confirmation email sent to guest.';
                        } else {
                            $message .= ' (Note: Confirmation email failed: ' . $email_result['message'] . ')';
                        }
                    }
                    
                } elseif ($current_status === 'confirmed' && $new_status === 'cancelled') {
                    // Update booking status
                    $stmt = $pdo->prepare("UPDATE bookings SET status = ?, room_unit_id = NULL, room_unit_assignment_source = 'released', updated_at = NOW() WHERE id = ?");
                    $stmt->execute([$new_status, $booking_id]);
                    $message = 'Booking status updated!';

                    // Booking cancelled: increment rooms_available
                    $update_room = $pdo->prepare("UPDATE rooms SET rooms_available = rooms_available + 1 WHERE id = ? AND rooms_available < total_rooms");
                    $update_room->execute([$room_id]);
                    
                    if ($update_room->rowCount() > 0) {
                        $message .= ' Room availability restored.';
                    }
                    
                    // Get booking details for email and logging
                    $booking_stmt = $pdo->prepare("
                        SELECT b.*, r.name as room_name
                        FROM bookings b
                        LEFT JOIN rooms r ON b.room_id = r.id
                        WHERE b.id = ?
                    ");
                    $booking_stmt->execute([$booking_id]);
                    $booking = $booking_stmt->fetch(PDO::FETCH_ASSOC);
                    
                    if ($booking) {
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
                            $message .= ' Cancellation email sent.';
                        } else {
                            $message .= ' (Email failed: ' . $email_status . ')';
                        }
                    }
                } else {
                    // Update booking status
                    $stmt = $pdo->prepare("UPDATE bookings SET status = ? WHERE id = ?");
                    $stmt->execute([$new_status, $booking_id]);
                    $message = 'Booking status updated!';
                }
            }

        } elseif ($action === 'update_payment') {
            $booking_id = (int)($_POST['id'] ?? 0);
            $payment_type_input = trim((string)($_POST['payment_type'] ?? 'full'));
            $amount_paid_input = (float)($_POST['amount_paid'] ?? 0);
            $payment_notes_input = trim((string)($_POST['payment_notes'] ?? ''));

            if ($booking_id <= 0) {
                throw new Exception('Invalid booking id');
            }
            if (!in_array($payment_type_input, ['full', 'partial'], true)) {
                throw new Exception('Invalid payment type');
            }
            if ($payment_type_input === 'partial' && $amount_paid_input <= 0) {
                throw new Exception('Amount must be greater than zero for partial payments');
            }
            $payment_status = ($payment_type_input === 'partial') ? 'partial' : 'paid';

            $pdo->beginTransaction();

            // Lock row so payment transition and ledger insert remain consistent.
            $check = $pdo->prepare("SELECT payment_status, amount_paid, total_amount, booking_reference FROM bookings WHERE id = ? FOR UPDATE");
            $check->execute([$booking_id]);
            $row = $check->fetch(PDO::FETCH_ASSOC);

            if (!$row) {
                throw new Exception('Booking not found');
            }

            $previous_status = $row['payment_status'] ?? 'unpaid';
            $booking_reference = $row['booking_reference'];
            $current_amount_paid = (float)($row['amount_paid'] ?? 0);

            // Recalculate totals before recording payment to keep accounting in sync with booking changes.
            $recalculated = recalculateRoomBookingFinancials($booking_id);
            $total_amount = (float)($recalculated['subtotal'] ?? $row['total_amount']);
            $vatRate = (float)($recalculated['vat_rate'] ?? 0);
            $vatAmount = (float)($recalculated['vat_amount'] ?? 0);
            $totalWithVat = (float)($recalculated['grand_total'] ?? ($total_amount + $vatAmount));

            $send_invoice = false;

            if ($payment_type_input === 'full') {
                // --- Full payment path ---
                if ($previous_status === 'paid') {
                    throw new Exception('This booking is already marked as fully paid.');
                }

                $stmt = $pdo->prepare("UPDATE bookings SET payment_status = 'paid', updated_at = NOW() WHERE id = ?");
                $stmt->execute([$booking_id]);
                $message = 'Full payment recorded!';

                // Prevent duplicate completed full payment records.
                $existing_payment_stmt = $pdo->prepare("SELECT id FROM payments WHERE booking_type = 'room' AND booking_id = ? AND payment_type = 'full_payment' AND status = 'completed' LIMIT 1");
                $existing_payment_stmt->execute([$booking_id]);
                $existing_payment = $existing_payment_stmt->fetch(PDO::FETCH_ASSOC);

                if (!$existing_payment) {
                    $payment_reference = 'PAY-' . date('Y') . '-' . str_pad($booking_id, 6, '0', STR_PAD_LEFT);
                    $insert_payment = $pdo->prepare("INSERT INTO payments (payment_reference, booking_type, booking_id, booking_reference, payment_date, payment_amount, vat_rate, vat_amount, total_amount, payment_method, payment_type, payment_status, notes, invoice_generated, status, recorded_by) VALUES (?, 'room', ?, ?, CURDATE(), ?, ?, ?, ?, 'cash', 'full_payment', 'completed', ?, 1, 'completed', ?)");
                    $insert_payment->execute([
                        $payment_reference,
                        $booking_id,
                        $booking_reference,
                        $totalWithVat,
                        $vatRate,
                        $vatAmount,
                        $totalWithVat,
                        $payment_notes_input ?: null,
                        $user['id']
                    ]);
                }

                $update_amounts = $pdo->prepare("UPDATE bookings SET amount_paid = ?, amount_due = 0, vat_rate = ?, vat_amount = ?, total_with_vat = ?, last_payment_date = CURDATE(), updated_at = NOW() WHERE id = ?");
                $update_amounts->execute([$totalWithVat, $vatRate, $vatAmount, $totalWithVat, $booking_id]);
                $message .= ' Payment recorded in accounting system.';
                $send_invoice = true;

            } elseif ($payment_type_input === 'partial') {
                // --- Partial payment path ---
                $remaining = max(0, $totalWithVat - $current_amount_paid);
                if ($amount_paid_input > $remaining + 0.01) {
                    throw new Exception('Amount (K ' . number_format($amount_paid_input, 0) . ') exceeds remaining balance (K ' . number_format($remaining, 0) . ').');
                }

                $new_amount_paid = $current_amount_paid + $amount_paid_input;
                $new_amount_due = max(0, $totalWithVat - $new_amount_paid);
                $new_payment_status = ($new_amount_due <= 0.01) ? 'paid' : 'partial';

                $payment_reference = 'PAY-' . date('Y') . '-' . str_pad($booking_id, 6, '0', STR_PAD_LEFT) . '-P' . date('His');
                $insert_partial = $pdo->prepare("INSERT INTO payments (payment_reference, booking_type, booking_id, booking_reference, payment_date, payment_amount, vat_rate, vat_amount, total_amount, payment_method, payment_type, payment_status, notes, invoice_generated, status, recorded_by) VALUES (?, 'room', ?, ?, CURDATE(), ?, ?, ?, ?, 'cash', 'partial_payment', 'completed', ?, 0, 'completed', ?)");
                $insert_partial->execute([
                    $payment_reference,
                    $booking_id,
                    $booking_reference,
                    $amount_paid_input,
                    $vatRate,
                    $vatAmount,
                    $totalWithVat,
                    $payment_notes_input ?: null,
                    $user['id']
                ]);

                $upd_partial = $pdo->prepare("UPDATE bookings SET payment_status = ?, amount_paid = ?, amount_due = ?, vat_rate = ?, vat_amount = ?, total_with_vat = ?, last_payment_date = CURDATE(), updated_at = NOW() WHERE id = ?");
                $upd_partial->execute([
                    $new_payment_status,
                    $new_amount_paid,
                    $new_amount_due,
                    $vatRate,
                    $vatAmount,
                    $totalWithVat,
                    $booking_id
                ]);

                $message = 'Partial payment of K ' . number_format($amount_paid_input, 0) . ' recorded.';
                if ($new_payment_status === 'paid') {
                    $message .= ' Booking is now fully paid!';
                    $send_invoice = true;
                } else {
                    $message .= ' Remaining balance: K ' . number_format($new_amount_due, 0) . '.';
                }
            }

            $pdo->commit();

            // Send invoice email after commit to avoid impacting payment persistence.
            if ($send_invoice) {
                require_once '../config/invoice.php';
                $invoice_result = sendPaymentInvoiceEmail($booking_id);

                if ($invoice_result['success']) {
                    $message .= ' Invoice sent successfully!';
                } else {
                    error_log("Invoice email failed: " . $invoice_result['message']);
                    $message .= ' (Invoice email failed - check logs)';
                }
            }
        } elseif ($action === 'checkout') {
            // Checkout a checked-in booking
            $booking_id = intval($_POST['id'] ?? 0);
            if ($booking_id > 0) {
                // Get booking info
                $check_stmt = $pdo->prepare("SELECT status, room_id, booking_reference FROM bookings WHERE id = ?");
                $check_stmt->execute([$booking_id]);
                $bk = $check_stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($bk && $bk['status'] === 'checked-in') {
                    // Update status
                    $upd = $pdo->prepare("UPDATE bookings SET status = 'checked-out', updated_at = NOW() WHERE id = ?");
                    $upd->execute([$booking_id]);
                    
                    // Restore room availability
                    $restore = $pdo->prepare("UPDATE rooms SET rooms_available = rooms_available + 1 WHERE id = ?");
                    $restore->execute([$bk['room_id']]);
                    
                    $message = 'Booking ' . htmlspecialchars($bk['booking_reference']) . ' checked out successfully. Room availability restored.';
                } else {
                    $error = 'Booking is not in checked-in status.';
                }
            }

        } elseif ($action === 'noshow') {
            // Mark a confirmed booking as no-show
            $booking_id = intval($_POST['id'] ?? 0);
            if ($booking_id > 0) {
                // Get booking info
                $check_stmt = $pdo->prepare("SELECT status, room_id, booking_reference FROM bookings WHERE id = ?");
                $check_stmt->execute([$booking_id]);
                $bk = $check_stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($bk && $bk['status'] === 'confirmed') {
                    // Update status to no-show
                    $upd = $pdo->prepare("UPDATE bookings SET status = 'no-show', room_unit_id = NULL, room_unit_assignment_source = 'released', updated_at = NOW() WHERE id = ?");
                    $upd->execute([$booking_id]);
                    
                    // Restore room availability (was decremented at confirmation)
                    $restore = $pdo->prepare("UPDATE rooms SET rooms_available = rooms_available + 1 WHERE id = ?");
                    $restore->execute([$bk['room_id']]);
                    
                    $message = 'Booking ' . htmlspecialchars($bk['booking_reference']) . ' marked as No-Show. Room availability restored.';
                } else {
                    $error = 'Only confirmed bookings can be marked as no-show.';
                }
            }
        } elseif ($action === 'release_room') {
            $booking_id = intval($_POST['id'] ?? 0);
            $release_reason = trim((string)($_POST['release_reason'] ?? ''));
            if ($release_reason === '') {
                $release_reason = 'Manual room release by admin';
            }
            if ($booking_id <= 0) {
                throw new Exception('Invalid booking id for release.');
            }

            $pdo->beginTransaction();

            $booking_stmt = $pdo->prepare("SELECT id, room_id, status, booking_reference, guest_email FROM bookings WHERE id = ? FOR UPDATE");
            $booking_stmt->execute([$booking_id]);
            $booking_row = $booking_stmt->fetch(PDO::FETCH_ASSOC);

            if (!$booking_row) {
                throw new Exception('Booking not found.');
            }

            if (!in_array($booking_row['status'], ['confirmed', 'checked-in'], true)) {
                throw new Exception('Only confirmed or checked-in bookings can be manually released.');
            }

            $release_stmt = $pdo->prepare("\n                UPDATE bookings\n                SET status = 'cancelled',\n                    room_unit_id = NULL,\n                    room_unit_assignment_source = 'released',\n                    updated_at = NOW()\n                WHERE id = ?\n                  AND status IN ('confirmed', 'checked-in')\n            ");
            $release_stmt->execute([$booking_id]);

            if ($release_stmt->rowCount() === 0) {
                throw new Exception('Booking could not be released because it changed in another session.');
            }

            $restore_stmt = $pdo->prepare("UPDATE rooms SET rooms_available = rooms_available + 1 WHERE id = ? AND rooms_available < total_rooms");
            $restore_stmt->execute([(int)$booking_row['room_id']]);

            require_once '../config/email.php';
            $email_status = 'Manual release audit entry (no email sent)';
            logCancellationToDatabase(
                (int)$booking_row['id'],
                $booking_row['booking_reference'],
                'room',
                $booking_row['guest_email'] ?? null,
                $user['id'],
                $release_reason,
                false,
                $email_status
            );
            logCancellationToFile(
                $booking_row['booking_reference'],
                'room',
                $booking_row['guest_email'] ?? '',
                $user['full_name'] ?? $user['username'],
                $release_reason,
                false,
                $email_status
            );

            $pdo->commit();
            $message = 'Booking ' . htmlspecialchars($booking_row['booking_reference']) . ' released and room availability restored.';
        } elseif ($action === 'delete_booking') {
            // Only super admin can delete expired bookings
            if ($user['role'] !== 'admin') {
                throw new Exception('Only the super admin can delete bookings.');
            }
            $booking_id = (int)($_POST['id'] ?? 0);
            if ($booking_id <= 0) {
                throw new Exception('Invalid booking id.');
            }
            $exp_stmt = $pdo->prepare("SELECT check_out_date, booking_reference FROM bookings WHERE id = ?");
            $exp_stmt->execute([$booking_id]);
            $exp_row = $exp_stmt->fetch(PDO::FETCH_ASSOC);
            if (!$exp_row) {
                throw new Exception('Booking not found.');
            }
            if (strtotime($exp_row['check_out_date']) >= strtotime('today')) {
                throw new Exception('Only expired bookings (past check-out date) can be deleted.');
            }
            $backup_ok = backupRecordBeforeDelete('bookings', $booking_id, 'id', [
                'reason' => 'expired_booking_cleanup',
                'booking_reference' => $exp_row['booking_reference'] ?? null,
                'deleted_by' => $user['id'] ?? null,
                'deleted_from' => 'admin/bookings.php'
            ]);
            if (!$backup_ok) {
                throw new Exception('Unable to back up booking record. Deletion cancelled.');
            }
            $del_stmt = $pdo->prepare("DELETE FROM bookings WHERE id = ?");
            $del_stmt->execute([$booking_id]);
            $message = 'Expired booking ' . htmlspecialchars($exp_row['booking_reference']) . ' deleted successfully.';
        }

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $error = 'Error: ' . $e->getMessage();
    }
}

// Handle CSV export
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    try {
        // Apply same filters as the main view
        $exp_search = trim($_GET['search'] ?? '');
        $exp_status = $_GET['filter_status'] ?? '';
        $exp_payment = $_GET['filter_payment'] ?? '';
        $exp_from   = $_GET['date_from'] ?? '';
        $exp_to     = $_GET['date_to'] ?? '';

        $exp_where  = [];
        $exp_params = [];

        if (!empty($exp_search)) {
            $exp_where[] = "(b.booking_reference LIKE ? OR b.guest_name LIKE ? OR b.guest_email LIKE ? OR b.guest_phone LIKE ? OR r.name LIKE ?)";
            $sp = "%{$exp_search}%";
            $exp_params = array_merge($exp_params, [$sp, $sp, $sp, $sp, $sp]);
        }
        if (!empty($exp_status)) {
            $exp_where[] = "b.status = ?";
            $exp_params[] = $exp_status;
        }
        if (!empty($exp_payment)) {
            $exp_where[] = "b.payment_status = ?";
            $exp_params[] = $exp_payment;
        }
        if (!empty($exp_from)) {
            $exp_where[] = "b.check_in_date >= ?";
            $exp_params[] = $exp_from;
        }
        if (!empty($exp_to)) {
            $exp_where[] = "b.check_out_date <= ?";
            $exp_params[] = $exp_to;
        }

        $exp_where_sql = !empty($exp_where) ? 'WHERE ' . implode(' AND ', $exp_where) : '';

        $export_stmt = $pdo->prepare("SELECT b.booking_reference, b.guest_name, b.guest_email, b.guest_phone, b.guest_country, r.name as room_name, b.check_in_date, b.check_out_date, b.number_of_nights, b.number_of_guests, b.total_amount, COALESCE(b.amount_paid, 0) as amount_paid, GREATEST(0, COALESCE(b.total_with_vat, b.total_amount) - COALESCE(b.amount_paid, 0)) as balance_due, b.status, b.payment_status, b.occupancy_type, b.special_requests, b.created_at FROM bookings b LEFT JOIN rooms r ON b.room_id = r.id {$exp_where_sql} ORDER BY b.created_at DESC");
        $export_stmt->execute($exp_params);
        $export_data = $export_stmt->fetchAll(PDO::FETCH_ASSOC);

        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="bookings-export-' . date('Y-m-d') . '.csv"');

        $output = fopen('php://output', 'w');
        fputcsv($output, ['Reference', 'Guest Name', 'Email', 'Phone', 'Country', 'Room',
                          'Check-in', 'Check-out', 'Nights', 'Guests', 'Total (excl. VAT)',
                          'Amount Paid', 'Balance Due', 'Status', 'Payment Status',
                          'Occupancy', 'Special Requests', 'Created']);

        foreach ($export_data as $row) {
            fputcsv($output, array_values($row));
        }

        fclose($output);
        exit;
    } catch (PDOException $e) {
        $error = 'Export failed: ' . $e->getMessage();
    }
}

// Handle search
$search_query = trim($_GET['search'] ?? '');
$filter_status = $_GET['filter_status'] ?? '';
$filter_date_from = $_GET['date_from'] ?? '';
$filter_date_to = $_GET['date_to'] ?? '';
$filter_payment = $_GET['filter_payment'] ?? '';

// Fetch all bookings with room details and payment status from payments table
try {
    $where_clauses = [];
    $params = [];

    if (!empty($search_query)) {
        $where_clauses[] = "(b.booking_reference LIKE ? OR b.guest_name LIKE ? OR b.guest_email LIKE ? OR b.guest_phone LIKE ? OR r.name LIKE ?)";
        $search_param = "%{$search_query}%";
        $params = array_merge($params, [$search_param, $search_param, $search_param, $search_param, $search_param]);
    }

    if (!empty($filter_status)) {
        $where_clauses[] = "b.status = ?";
        $params[] = $filter_status;
    }

    if (!empty($filter_date_from)) {
        $where_clauses[] = "b.check_in_date >= ?";
        $params[] = $filter_date_from;
    }

    if (!empty($filter_date_to)) {
        $where_clauses[] = "b.check_out_date <= ?";
        $params[] = $filter_date_to;

        if (!empty($filter_payment)) {
            $where_clauses[] = "b.payment_status = ?";
            $params[] = $filter_payment;
        }
    }

    $where_sql = !empty($where_clauses) ? 'WHERE ' . implode(' AND ', $where_clauses) : '';

    $stmt = $pdo->prepare("\n        SELECT b.*,\n             r.name as room_name,\n             ru.unit_label as room_unit_label,\n             COALESCE(NULLIF(b.room_unit_assignment_source, ''), CASE WHEN b.room_unit_id IS NULL THEN NULL ELSE 'auto' END) as room_unit_assignment_source,\n             COALESCE(p.payment_status, b.payment_status) as actual_payment_status,\n             p.payment_reference,\n             p.payment_date as last_payment_date\n        FROM bookings b\n        LEFT JOIN rooms r ON b.room_id = r.id\n        LEFT JOIN room_units ru ON b.room_unit_id = ru.id\n        LEFT JOIN payments p ON b.id = p.booking_id AND p.booking_type = 'room' AND p.status = 'completed'\n        {$where_sql}\n        ORDER BY b.created_at DESC\n    ");
    $stmt->execute($params);
    $bookings = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Also fetch conference inquiries
    $conf_stmt = $pdo->query("\n        SELECT * FROM conference_inquiries\n        ORDER BY created_at DESC\n    ");
    $conference_inquiries = $conf_stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    $error = 'Error fetching bookings: ' . $e->getMessage();
    $bookings = [];
    $conference_inquiries = [];
}

// Count statistics
$total_bookings = count($bookings);
$pending = count(array_filter($bookings, fn($b) => $b['status'] === 'pending'));
$tentative = count(array_filter($bookings, fn($b) => $b['status'] === 'tentative' || $b['is_tentative'] == 1));
$confirmed = count(array_filter($bookings, fn($b) => $b['status'] === 'confirmed'));
$checked_in = count(array_filter($bookings, fn($b) => $b['status'] === 'checked-in'));

// Additional statistics for new tabs
$checked_out = count(array_filter($bookings, fn($b) => $b['status'] === 'checked-out'));
$cancelled = count(array_filter($bookings, fn($b) => $b['status'] === 'cancelled'));
$no_show = count(array_filter($bookings, fn($b) => $b['status'] === 'no-show'));

// Count paid/unpaid based on actual payment status from payments table
$paid = count(array_filter($bookings, fn($b) =>
    $b['actual_payment_status'] === 'paid' || $b['actual_payment_status'] === 'completed'
));
$partial_paid = count(array_filter($bookings, fn($b) => $b['actual_payment_status'] === 'partial'));
$unpaid = count(array_filter($bookings, fn($b) =>
    $b['actual_payment_status'] !== 'paid' && $b['actual_payment_status'] !== 'completed' && $b['actual_payment_status'] !== 'partial'
));

// Count expiring soon (tentative bookings expiring within 24 hours)
$now = new DateTime();
$expiring_soon = 0;
foreach ($bookings as $booking) {
    if (($booking['status'] === 'tentative' || $booking['is_tentative'] == 1) && $booking['tentative_expires_at']) {
        $expires_at = new DateTime($booking['tentative_expires_at']);
        $hours_until_expiry = ($expires_at->getTimestamp() - $now->getTimestamp()) / 3600;
        if ($hours_until_expiry <= 24 && $hours_until_expiry > 0) {
            $expiring_soon++;
        }
    }
}

// Count today's check-ins (confirmed bookings with check-in today)
$today = new DateTime();
$today_str = $today->format('Y-m-d');
$today_checkins = count(array_filter($bookings, fn($b) =>
    $b['status'] === 'confirmed' && $b['check_in_date'] === $today_str
));

// Count today's check-outs (checked-in bookings with check-out today)
$today_checkouts = count(array_filter($bookings, fn($b) =>
    $b['status'] === 'checked-in' && $b['check_out_date'] === $today_str
));

// Count today's bookings (created today)
$today_bookings = count(array_filter($bookings, fn($b) =>
    date('Y-m-d', strtotime($b['created_at'])) === $today_str
));

// Count this week's bookings (created within the last 7 days)
$week_start = (clone $today)->modify('-7 days');
$week_bookings = count(array_filter($bookings, fn($b) =>
    strtotime($b['created_at']) >= $week_start->getTimestamp()
));

// Count this month's bookings (created this month)
$month_start = $today->format('Y-m-01');
$month_bookings = count(array_filter($bookings, fn($b) =>
    date('Y-m', strtotime($b['created_at'])) === date('Y-m')
));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>All Bookings - Admin Panel</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;500;600;700&family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../css/style.css">
    <link rel="stylesheet" href="../css/theme-dynamic.php">
    <link rel="stylesheet" href="css/admin-styles.css">
    <link rel="stylesheet" href="css/admin-components.css">
    <style>
        /* Bookings specific styles */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 32px;
        }
        .stat-card h3 {
            font-size: 14px;
            color: #666;
            margin-bottom: 8px;
        }
        .stat-card .number {
            font-size: 32px;
            font-weight: 700;
            color: var(--navy);
        }
        .stat-card.pending .number { color: #ffc107; }
        .stat-card.confirmed .number { color: #28a745; }
        .stat-card.checked-in .number { color: #17a2b8; }
        .bookings-section {
            background: white;
            border-radius: 12px;
            padding: 24px;
            margin-bottom: 24px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
        }
        .section-title {
            font-size: 20px;
            font-weight: 700;
            color: var(--navy);
            margin-bottom: 16px;
            padding-bottom: 12px;
            border-bottom: 2px solid var(--gold);
        }
        .booking-table {
            width: 100%;
            border-collapse: collapse;
            min-width: 1260px;
            border: 1px solid #d0d7de;
        }
        .booking-table th {
            background: #f6f8fa;
            padding: 12px;
            text-align: left;
            font-size: 13px;
            font-weight: 600;
            color: #666;
            text-transform: uppercase;
            border: 1px solid #d0d7de;
        }
        .booking-table td {
            padding: 12px;
            border: 1px solid #d0d7de;
            vertical-align: middle;
            background: white;
        }
        .booking-table tbody tr:hover {
            background: #f8f9fa;
        }
        .badge-new { background: #17a2b8; color: white; }
        .badge-contacted { background: #6c757d; color: white; }
        .room-unit-chip {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            margin-top: 4px;
            padding: 3px 8px;
            border-radius: 999px;
            font-size: 11px;
            font-weight: 600;
            background: #eef4ff;
            color: #234;
        }
        .assignment-chip {
            display: inline-flex;
            align-items: center;
            margin-top: 4px;
            padding: 2px 7px;
            border-radius: 999px;
            font-size: 10px;
            font-weight: 700;
            letter-spacing: 0.03em;
            text-transform: uppercase;
        }
        .assignment-chip.auto {
            background: #d1ecf1;
            color: #0c5460;
        }
        .assignment-chip.manual {
            background: #fff3cd;
            color: #856404;
        }
        .quick-action {
            padding: 6px 14px;
            border: none;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s ease;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            white-space: nowrap;
        }
        .quick-action:hover {
            transform: translateY(-1px);
            box-shadow: 0 2px 8px rgba(0,0,0,0.15);
        }
        .quick-action.confirm {
            background: #28a745;
            color: white;
        }
        .quick-action.confirm:hover {
            background: #229954;
        }
        .quick-action.check-in {
            background: #17a2b8;
            color: white;
        }
        .quick-action.check-in:hover {
            background: #138496;
        }
        .quick-action.undo-checkin {
            background: #6c757d;
            color: white;
        }
        .quick-action.undo-checkin:hover {
            background: #5a6268;
        }
        .quick-action.disabled {
            opacity: 0.55;
            cursor: not-allowed;
        }
        .quick-action.paid {
            background: var(--gold);
            color: var(--deep-navy);
        }
        .quick-action.paid:hover {
            background: #c19b2e;
        }
        .empty-state {
            text-align: center;
            padding: 40px;
            color: #999;
        }
        .empty-state i {
            font-size: 48px;
            margin-bottom: 16px;
            color: #ddd;
        }
        @media (max-width: 768px) {
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
                gap: 12px;
            }

            .bookings-toolbar {
                padding: 14px !important;
            }

            .bookings-toolbar form {
                width: 100%;
            }

            .bookings-toolbar form > div:first-child {
                min-width: 0 !important;
                width: 100%;
            }

            .bookings-toolbar form select,
            .bookings-toolbar form input[type="date"],
            .bookings-toolbar form button,
            .bookings-toolbar form a {
                width: 100%;
            }

            .bookings-toolbar > div {
                width: 100%;
                display: grid !important;
                grid-template-columns: 1fr;
            }

            .stat-card {
                padding: 16px;
            }
            .stat-card .number {
                font-size: 24px;
            }
            .booking-table {
                font-size: 12px;
                min-width: 1100px;
            }
            .booking-table th,
            .booking-table td {
                padding: 8px;
            }
            .booking-table th {
                font-size: 11px;
            }
        }
        @media (max-width: 480px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }

            .bookings-section {
                padding: 14px;
            }

            .booking-table {
                font-size: 11px;
            }
            .booking-table th,
            .booking-table td {
                padding: 6px;
            }
            .booking-table {
                min-width: 900px;
            }
            .booking-table th,
            .booking-table td {
                white-space: nowrap;
            }
            .booking-table td:last-child,
            .booking-table th:last-child {
                position: sticky;
                right: 0;
                background: #fff;
                z-index: 2;
                box-shadow: -8px 0 10px -8px rgba(15, 23, 42, 0.35);
            }
            .booking-table thead th:last-child {
                background: #f6f8fa;
                z-index: 3;
            }
            .actions-cell .quick-action {
                font-size: 11px;
                padding: 5px 8px;
                flex: 1 1 100%;
                justify-content: center;
            }
        }

        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 24px;
        }
        .page-title {
            font-family: 'Playfair Display', serif;
            font-size: 28px;
            color: var(--navy);
        }

        /* Additional status badges (booking & payment specific) */
        .badge-tentative { background: linear-gradient(135deg, var(--gold) 0%, #c49b2e 100%); color: var(--deep-navy); }
        .badge-expired { background: #6c757d; color: white; }
        .badge-completed { background: #28a745; color: white; }
        .badge-failed { background: #dc3545; color: white; }
        .badge-refunded { background: #6c757d; color: white; }
        .badge-partially_refunded { background: #e2e3e5; color: #383d41; }
        
        /* Tentative booking specific styles */
        .tentative-indicator {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            font-size: 10px;
            color: var(--gold);
            font-weight: 600;
        }
        .tentative-indicator i {
            animation: pulse 2s infinite;
        }
        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.5; }
        }
        .expires-soon {
            color: #dc3545;
            font-weight: 600;
            font-size: 11px;
        }
        .expires-soon i {
            margin-right: 4px;
        }
        .quick-action.cancel {
            background: #dc3545;
            color: white;
        }
        .quick-action.cancel:hover {
            background: #c82333;
        }
        .quick-action.release {
            background: #fd7e14;
            color: white;
        }
        .quick-action.release:hover {
            background: #dc6502;
        }
        .quick-action.partial {
            background: #17a2b8;
            color: white;
        }
        .quick-action.partial:hover {
            background: #138496;
        }
        .actions-cell {
            display: flex;
            flex-wrap: wrap;
            gap: 4px;
            align-items: flex-start;
        }
        .badge-partial { background: #17a2b8; color: white; }
        /* Tab Navigation Styles */
        .tabs-container {
            background: white;
            border-radius: 12px 12px 0 0;
            padding: 0;
            margin-bottom: 0;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
            overflow: hidden;
        }

        .tabs-header {
            display: flex;
            flex-wrap: wrap;
            gap: 0;
            border-bottom: 2px solid #e0e0e0;
            overflow-x: auto;
        }

        .tab-button {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 16px 20px;
            background: white;
            border: none;
            border-bottom: 3px solid transparent;
            cursor: pointer;
            font-family: 'Poppins', sans-serif;
            font-size: 14px;
            font-weight: 500;
            color: #666;
            transition: all 0.3s ease;
            white-space: nowrap;
            position: relative;
        }

        .tab-button:hover {
            background: #f8f9fa;
            color: var(--navy);
        }

        .tab-button.active {
            color: var(--navy);
            border-bottom-color: var(--gold);
            background: linear-gradient(to bottom, #fff8e1 0%, white 100%);
        }

        .tab-button i {
            font-size: 16px;
        }

        .tab-count {
            background: #f0f0f0;
            color: #666;
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 600;
            min-width: 20px;
            text-align: center;
        }

        .tab-button.active .tab-count {
            background: var(--gold);
            color: var(--deep-navy);
        }

        /* Tab-specific active colors */
        .tab-button[data-tab="pending"].active .tab-count {
            background: #ffc107;
            color: #212529;
        }

        .tab-button[data-tab="tentative"].active .tab-count {
            background: linear-gradient(135deg, var(--gold) 0%, #c49b2e 100%);
            color: var(--deep-navy);
        }

        .tab-button[data-tab="expiring-soon"].active .tab-count {
            background: #ff6b35;
            color: white;
            animation: pulse 2s infinite;
        }

        .tab-button[data-tab="confirmed"].active .tab-count {
            background: #28a745;
            color: white;
        }

        .tab-button[data-tab="today-checkins"].active .tab-count {
            background: #007bff;
            color: white;
        }

        .tab-button[data-tab="today-checkouts"].active .tab-count {
            background: #6f42c1;
            color: white;
        }

        .tab-button[data-tab="checked-in"].active .tab-count {
            background: #17a2b8;
            color: white;
        }

        .tab-button[data-tab="checked-out"].active .tab-count {
            background: #6c757d;
            color: white;
        }

        .tab-button[data-tab="cancelled"].active .tab-count {
            background: #dc3545;
            color: white;
        }

        .tab-button[data-tab="no-show"].active .tab-count {
            background: #795548;
            color: white;
        }

        .tab-button[data-tab="paid"].active .tab-count {
            background: #28a745;
            color: white;
        }

        .tab-button[data-tab="unpaid"].active .tab-count {

                    .tab-button[data-tab="partial"].active .tab-count {
                        background: #17a2b8;
                        color: white;
                    }
            background: #dc3545;
            color: white;
        }

        .tab-button[data-tab="today-bookings"].active .tab-count {
            background: #007bff;
            color: white;
        }

        .tab-button[data-tab="week-bookings"].active .tab-count {
            background: #6f42c1;
            color: white;
        }

        .tab-button[data-tab="month-bookings"].active .tab-count {
            background: #fd7e14;
            color: white;
        }

        /* Adjust bookings section to connect with tabs */
        .bookings-section {
            border-radius: 0 0 12px 12px !important;
            margin-top: -1px !important;
        }

        /* Responsive tabs */
        @media (max-width: 1024px) {
            .tabs-header {
                justify-content: flex-start;
            }
            
            .tab-button {
                padding: 12px 16px;
                font-size: 13px;
            }
            
            .tab-count {
                font-size: 11px;
                padding: 2px 6px;
            }
        }

        @media (max-width: 768px) {
            .tabs-header {
                gap: 0;
            }
            
            .tab-button {
                padding: 10px 12px;
                font-size: 12px;
                flex: 0 0 auto;
            }
            
            .tab-button span:not(.tab-count) {
                display: none;
            }
            
            .tab-button i {
                font-size: 18px;
            }
        }
    </style>
</head>
<body>

    <?php require_once 'includes/admin-header.php'; ?>
    
    <div class="content">
        <div class="stats-grid">
            <div class="stat-card">
                <h3>Total Bookings</h3>
                <div class="number"><?php echo $total_bookings; ?></div>
            </div>
            <div class="stat-card pending">
                <h3>Pending</h3>
                <div class="number"><?php echo $pending; ?></div>
            </div>
            <div class="stat-card" style="background: linear-gradient(135deg, #fff8e1 0%, #ffecb3 100%);">
                <h3 style="color: var(--navy);">Tentative</h3>
                <div class="number" style="color: var(--gold);"><?php echo $tentative; ?></div>
            </div>
            <div class="stat-card confirmed">
                <h3>Confirmed</h3>
                <div class="number"><?php echo $confirmed; ?></div>
            </div>
            <div class="stat-card checked-in">
                <h3>Checked In</h3>
                <div class="number"><?php echo $checked_in; ?></div>
            </div>
        </div>

        <?php if ($message): ?>
            <?php showAlert($message, 'success'); ?>
        <?php endif; ?>

        <?php if ($error): ?>
            <?php showAlert($error, 'error'); ?>
        <?php endif; ?>

        <!-- Search & Tools Bar -->
        <div class="bookings-toolbar" style="background: white; border-radius: 12px; padding: 16px 20px; margin-bottom: 16px; box-shadow: 0 2px 8px rgba(0,0,0,0.08); display: flex; flex-wrap: wrap; gap: 12px; align-items: center;">
            <form method="GET" style="display: flex; flex-wrap: wrap; gap: 10px; flex: 1; align-items: center;">
                <div style="position: relative; flex: 1; min-width: 200px;">
                    <i class="fas fa-search" style="position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: #999;"></i>
                    <input type="text" name="search" value="<?php echo htmlspecialchars($search_query); ?>" 
                           placeholder="Search by name, reference, email, phone..."
                           style="width: 100%; padding: 10px 12px 10px 36px; border: 1px solid #ddd; border-radius: 8px; font-size: 14px; font-family: 'Poppins', sans-serif;">
                </div>
                <select name="filter_status" style="padding: 10px 12px; border: 1px solid #ddd; border-radius: 8px; font-size: 14px; font-family: 'Poppins', sans-serif; min-width: 140px;">
                    <option value="">All Statuses</option>
                    <option value="pending" <?php echo $filter_status === 'pending' ? 'selected' : ''; ?>>Pending</option>
                    <option value="tentative" <?php echo $filter_status === 'tentative' ? 'selected' : ''; ?>>Tentative</option>
                    <option value="confirmed" <?php echo $filter_status === 'confirmed' ? 'selected' : ''; ?>>Confirmed</option>
                    <option value="checked-in" <?php echo $filter_status === 'checked-in' ? 'selected' : ''; ?>>Checked In</option>
                    <option value="checked-out" <?php echo $filter_status === 'checked-out' ? 'selected' : ''; ?>>Checked Out</option>
                    <option value="cancelled" <?php echo $filter_status === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                    <option value="no-show" <?php echo $filter_status === 'no-show' ? 'selected' : ''; ?>>No-Show</option>
                </select>
                                <select name="filter_payment" style="padding: 10px 12px; border: 1px solid #ddd; border-radius: 8px; font-size: 14px; font-family: 'Poppins', sans-serif; min-width: 140px;">
                                    <option value="">All Payments</option>
                                    <option value="unpaid" <?php echo $filter_payment === 'unpaid' ? 'selected' : ''; ?>>Unpaid</option>
                                    <option value="partial" <?php echo $filter_payment === 'partial' ? 'selected' : ''; ?>>Partial</option>
                                    <option value="paid" <?php echo $filter_payment === 'paid' ? 'selected' : ''; ?>>Paid</option>
                                </select>
                <input type="date" name="date_from" value="<?php echo htmlspecialchars($filter_date_from); ?>" 
                       placeholder="From" title="Check-in from"
                       style="padding: 10px 12px; border: 1px solid #ddd; border-radius: 8px; font-size: 14px; font-family: 'Poppins', sans-serif;">
                <input type="date" name="date_to" value="<?php echo htmlspecialchars($filter_date_to); ?>" 
                       placeholder="To" title="Check-out to"
                       style="padding: 10px 12px; border: 1px solid #ddd; border-radius: 8px; font-size: 14px; font-family: 'Poppins', sans-serif;">
                <button type="submit" style="padding: 10px 20px; background: var(--navy, #1a1a2e); color: white; border: none; border-radius: 8px; cursor: pointer; font-weight: 600; font-size: 14px;">
                    <i class="fas fa-filter"></i> Filter
                </button>
                <?php if (!empty($search_query) || !empty($filter_status) || !empty($filter_date_from) || !empty($filter_date_to)): ?>
                    <a href="bookings.php" style="padding: 10px 16px; color: #666; text-decoration: none; font-size: 14px; border: 1px solid #ddd; border-radius: 8px;">
                        <i class="fas fa-times"></i> Clear
                    </a>
                <?php endif; ?>
            </form>
            <div style="display: flex; gap: 8px;">
                <?php
                $export_url = 'bookings.php?' . http_build_query(array_filter([
                    'export'         => 'csv',
                    'search'         => $search_query,
                    'filter_status'  => $filter_status,
                    'filter_payment' => $filter_payment,
                    'date_from'      => $filter_date_from,
                    'date_to'        => $filter_date_to,
                ]));
                ?>
                <a href="<?php echo htmlspecialchars($export_url); ?>" class="quick-action" style="padding: 10px 16px; background: #28a745; color: white; text-decoration: none; border-radius: 8px; font-size: 13px;">
                    <i class="fas fa-file-csv"></i> Export CSV
                </a>
                <a href="create-booking.php" class="quick-action" style="padding: 10px 16px; background: var(--gold, #d4a843); color: var(--deep-navy, #0d0d1a); text-decoration: none; border-radius: 8px; font-size: 13px; font-weight: 600;">
                    <i class="fas fa-plus"></i> New Booking
                </a>
            </div>
        </div>

        <!-- Tab Navigation -->
        <div class="tabs-container">
            <div class="tabs-header">
                <button class="tab-button active" data-tab="all" data-count="<?php echo $total_bookings; ?>">
                    <i class="fas fa-list"></i>
                    All
                    <span class="tab-count"><?php echo $total_bookings; ?></span>
                </button>
                <button class="tab-button" data-tab="pending" data-count="<?php echo $pending; ?>">
                    <i class="fas fa-clock"></i>
                    Pending
                    <span class="tab-count"><?php echo $pending; ?></span>
                </button>
                <button class="tab-button" data-tab="tentative" data-count="<?php echo $tentative; ?>">
                    <i class="fas fa-hourglass-half"></i>
                    Tentative
                    <span class="tab-count"><?php echo $tentative; ?></span>
                </button>
                <button class="tab-button" data-tab="expiring-soon" data-count="<?php echo $expiring_soon; ?>">
                    <i class="fas fa-exclamation-triangle"></i>
                    Expiring Soon
                    <span class="tab-count"><?php echo $expiring_soon; ?></span>
                </button>
                <button class="tab-button" data-tab="confirmed" data-count="<?php echo $confirmed; ?>">
                    <i class="fas fa-check-circle"></i>
                    Confirmed
                    <span class="tab-count"><?php echo $confirmed; ?></span>
                </button>
                <button class="tab-button" data-tab="today-checkins" data-count="<?php echo $today_checkins; ?>">
                    <i class="fas fa-calendar-day"></i>
                    Today's Check-ins
                    <span class="tab-count"><?php echo $today_checkins; ?></span>
                </button>
                <button class="tab-button" data-tab="today-checkouts" data-count="<?php echo $today_checkouts; ?>">
                    <i class="fas fa-calendar-times"></i>
                    Today's Check-outs
                    <span class="tab-count"><?php echo $today_checkouts; ?></span>
                </button>
                <button class="tab-button" data-tab="checked-in" data-count="<?php echo $checked_in; ?>">
                    <i class="fas fa-sign-in-alt"></i>
                    Checked In
                    <span class="tab-count"><?php echo $checked_in; ?></span>
                </button>
                <button class="tab-button" data-tab="checked-out" data-count="<?php echo $checked_out; ?>">
                    <i class="fas fa-sign-out-alt"></i>
                    Checked Out
                    <span class="tab-count"><?php echo $checked_out; ?></span>
                </button>
                <button class="tab-button" data-tab="cancelled" data-count="<?php echo $cancelled; ?>">
                    <i class="fas fa-times-circle"></i>
                    Cancelled
                    <span class="tab-count"><?php echo $cancelled; ?></span>
                </button>
                <button class="tab-button" data-tab="no-show" data-count="<?php echo $no_show; ?>">
                    <i class="fas fa-user-slash"></i>
                    No-Show
                    <span class="tab-count"><?php echo $no_show; ?></span>
                </button>
                <button class="tab-button" data-tab="paid" data-count="<?php echo $paid; ?>">
                    <i class="fas fa-dollar-sign"></i>
                    Paid
                    <span class="tab-count"><?php echo $paid; ?></span>
                                <button class="tab-button" data-tab="partial" data-count="<?php echo $partial_paid; ?>">
                                    <i class="fas fa-adjust"></i>
                                    Partial
                                    <span class="tab-count"><?php echo $partial_paid; ?></span>
                                </button>
                </button>
                <button class="tab-button" data-tab="unpaid" data-count="<?php echo $unpaid; ?>">
                    <i class="fas fa-exclamation-circle"></i>
                    Unpaid
                    <span class="tab-count"><?php echo $unpaid; ?></span>
                </button>
                <button class="tab-button" data-tab="today-bookings" data-count="<?php echo $today_bookings; ?>">
                    <i class="fas fa-calendar-day"></i>
                    Today's Bookings
                    <span class="tab-count"><?php echo $today_bookings; ?></span>
                </button>
                <button class="tab-button" data-tab="week-bookings" data-count="<?php echo $week_bookings; ?>">
                    <i class="fas fa-calendar-week"></i>
                    This Week
                    <span class="tab-count"><?php echo $week_bookings; ?></span>
                </button>
                <button class="tab-button" data-tab="month-bookings" data-count="<?php echo $month_bookings; ?>">
                    <i class="fas fa-calendar-alt"></i>
                    This Month
                    <span class="tab-count"><?php echo $month_bookings; ?></span>
                </button>
            </div>
        </div>

        <!-- Room Bookings -->
        <div class="bookings-section">
            <h3 class="section-title">
                <i class="fas fa-bed"></i> Room Bookings
                <span style="font-size: 14px; font-weight: normal; color: #666;">
                    (<?php echo count($bookings); ?> total)
                </span>
            </h3>

            <?php if (!empty($bookings)): ?>
                <div class="table-responsive">
                    <table class="booking-table">
                    <thead>
                        <tr>
                            <th style="width: 120px;">Ref</th>
                            <th style="width: 200px;">Guest Name</th>
                            <th style="width: 180px;">Room</th>
                            <th style="width: 140px;">Check In</th>
                            <th style="width: 140px;">Check Out</th>
                            <th style="width: 80px;">Nights</th>
                            <th style="width: 80px;">Guests</th>
                            <th style="width: 120px;">Total</th>
                                                        <th style="width: 110px;">Balance</th>
                            <th style="width: 120px;">Status</th>
                            <th style="width: 120px;">Payment</th>
                            <th style="width: 150px;">Created</th>
                            <th style="width: 400px;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($bookings as $booking): ?>
                            <?php
                                $is_tentative = ($booking['status'] === 'tentative' || $booking['is_tentative'] == 1);
                                $unit_assignment_source = resolveRoomUnitAssignmentSource($booking);
                                $expires_soon = false;
                                if ($is_tentative && $booking['tentative_expires_at']) {
                                    $expires_at = new DateTime($booking['tentative_expires_at']);
                                    $now = new DateTime();
                                    $hours_until_expiry = ($expires_at->getTimestamp() - $now->getTimestamp()) / 3600;
                                    $expires_soon = $hours_until_expiry <= 24 && $hours_until_expiry > 0;
                                }
                                // Expired: check-out date past, booking not yet finalized
                                $is_expired = !in_array($booking['status'], ['checked-out', 'cancelled', 'no-show'])
                                    && strtotime($booking['check_out_date']) < strtotime('today');
                            ?>
                            <tr <?php
                                if ($is_expired) echo 'style="opacity:0.6; background-color:#f0f0f0 !important;"';
                                elseif ($is_tentative) echo 'style="background: linear-gradient(90deg, rgba(212, 175, 55, 0.05) 0%, white 10%);"';
                            ?>>
                                <td>
                                    <strong><?php echo htmlspecialchars($booking['booking_reference']); ?></strong>
                                    <?php if ($is_tentative): ?>
                                        <br><span class="tentative-indicator"><i class="fas fa-clock"></i> Tentative</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php echo htmlspecialchars($booking['guest_name']); ?>
                                    <br><small style="color: #666;"><?php echo htmlspecialchars($booking['guest_phone']); ?></small>
                                </td>
                                <td>
                                    <?php echo htmlspecialchars($booking['room_name']); ?>
                                    <?php if (!empty($booking['room_unit_label'])): ?>
                                        <br><span class="room-unit-chip"><i class="fas fa-door-closed"></i> <?php echo htmlspecialchars($booking['room_unit_label']); ?></span>
                                    <?php endif; ?>
                                    <?php if ($unit_assignment_source === 'auto'): ?>
                                        <br><span class="assignment-chip auto">Auto Assigned</span>
                                    <?php elseif ($unit_assignment_source === 'manual'): ?>
                                        <br><span class="assignment-chip manual">Manual Unit</span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo date('M d, Y', strtotime($booking['check_in_date'])); ?></td>
                                <td><?php echo date('M d, Y', strtotime($booking['check_out_date'])); ?></td>
                                <td><?php echo $booking['number_of_nights']; ?></td>
                                <td><?php echo $booking['number_of_guests']; ?></td>
                                <td>
                                    <strong>K <?php echo number_format($booking['total_amount'], 0); ?></strong>
                                    <?php if ($is_tentative && $booking['tentative_expires_at']): ?>
                                        <?php if ($expires_soon): ?>
                                            <br><span class="expires-soon"><i class="fas fa-exclamation-triangle"></i> Expires soon!</span>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php
                                        $b_balance = max(0, (float)($booking['total_with_vat'] ?? $booking['total_amount']) - (float)($booking['amount_paid'] ?? 0));
                                        if ($b_balance <= 0) {
                                            echo '<span style="color:#28a745;font-weight:600;font-size:11px;">Settled</span>';
                                        } elseif ((float)($booking['amount_paid'] ?? 0) > 0) {
                                            echo '<span style="color:#fd7e14;font-weight:600;">K ' . number_format($b_balance, 0) . '</span>';
                                        } else {
                                            echo 'K ' . number_format($b_balance, 0);
                                        }
                                    ?>
                                </td>
                                <td>
                                    <span class="badge badge-<?php echo $booking['status']; ?>">
                                        <?php echo ucfirst($booking['status']); ?>
                                    </span>
                                    <?php if ($is_expired): ?>
                                        <br><span style="background:#6c757d;color:white;font-size:10px;padding:2px 6px;border-radius:4px;display:inline-block;margin-top:3px;">Expired</span>
                                    <?php endif; ?>
                                    <?php if ($is_tentative && $booking['tentative_expires_at']): ?>
                                        <br><small style="color: #666; font-size: 10px;">
                                            <?php
                                                $expires = new DateTime($booking['tentative_expires_at']);
                                                echo 'Expires: ' . $expires->format('M d, H:i');
                                            ?>
                                        </small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="badge badge-<?php echo $booking['actual_payment_status']; ?>">
                                        <?php
                                            $status = $booking['actual_payment_status'];
                                            // Map payment statuses to user-friendly labels
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
                                        <br><small style="color: #666; font-size: 10px;">
                                            <?php echo htmlspecialchars($booking['payment_reference']); ?>
                                        </small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <small style="color: #666; font-size: 11px;">
                                        <i class="fas fa-clock"></i> <?php echo date('M j, H:i', strtotime($booking['created_at'])); ?>
                                    </small>
                                </td>
                                <td>
                                <div class="actions-cell">
                                    <a href="booking-details.php?id=<?php echo $booking['id']; ?>" class="quick-action" style="background: #6f42c1; color: white; text-decoration: none;">
                                        <i class="fas fa-eye"></i> View
                                    </a>
                                    <button class="quick-action" style="background: #007bff; color: white;" onclick="openResendEmailModal(<?php echo $booking['id']; ?>, '<?php echo htmlspecialchars($booking['booking_reference'], ENT_QUOTES); ?>', '<?php echo htmlspecialchars($booking['status']); ?>')">
                                        <i class="fas fa-envelope"></i> Email
                                    </button>
                                    <?php if ($is_tentative): ?>
                                        <button class="quick-action confirm" onclick="convertTentativeBooking(<?php echo $booking['id']; ?>)">
                                            <i class="fas fa-check"></i> Convert
                                        </button>
                                        <button class="quick-action cancel" onclick="cancelBooking(<?php echo $booking['id']; ?>, '<?php echo htmlspecialchars($booking['booking_reference'], ENT_QUOTES); ?>')">
                                            <i class="fas fa-times"></i> Cancel
                                        </button>
                                    <?php elseif ($booking['status'] === 'pending'): ?>
                                        <?php if (!$is_expired): ?>
                                        <button class="quick-action confirm" onclick="updateStatus(<?php echo $booking['id']; ?>, 'confirmed')">
                                            <i class="fas fa-check"></i> Confirm
                                        </button>
                                        <button class="quick-action" style="background: linear-gradient(135deg, var(--gold) 0%, #c49b2e 100%); color: var(--deep-navy);" onclick="makeTentative(<?php echo $booking['id']; ?>)">
                                            <i class="fas fa-clock"></i> Make Tentative
                                        </button>
                                        <?php endif; ?>
                                        <button class="quick-action cancel" onclick="cancelBooking(<?php echo $booking['id']; ?>, '<?php echo htmlspecialchars($booking['booking_reference'], ENT_QUOTES); ?>')">
                                            <i class="fas fa-times"></i> Cancel
                                        </button>
                                    <?php endif; ?>
                                    <?php if ($booking['status'] === 'confirmed'): ?>
                                        <?php $can_checkin = ($booking['payment_status'] === 'paid'); ?>
                                        <button class="quick-action" style="background: linear-gradient(135deg, var(--gold) 0%, #c49b2e 100%); color: var(--deep-navy);" onclick="convertToTentative(<?php echo $booking['id']; ?>)">
                                            <i class="fas fa-clock"></i> Make Tentative
                                        </button>
                                        <button class="quick-action check-in <?php echo $can_checkin ? '' : 'disabled'; ?>"
                                                onclick="<?php echo $can_checkin ? "updateStatus({$booking['id']}, 'checked-in')" : "Alert.show('Cannot check in: booking must be PAID first.', 'error')"; ?>">
                                            <i class="fas fa-sign-in-alt"></i> Check In
                                        </button>
                                        <?php
                                            // Show no-show button if check-in date has passed
                                            $checkin_date = new DateTime($booking['check_in_date']);
                                            $today_dt = new DateTime('today');
                                            if ($checkin_date < $today_dt):
                                        ?>
                                        <button class="quick-action" style="background: #795548; color: white;" onclick="markNoShow(<?php echo $booking['id']; ?>, '<?php echo htmlspecialchars($booking['booking_reference'], ENT_QUOTES); ?>')">
                                            <i class="fas fa-user-slash"></i> No-Show
                                        </button>
                                        <?php endif; ?>
                                        <button class="quick-action cancel" onclick="cancelBooking(<?php echo $booking['id']; ?>, '<?php echo htmlspecialchars($booking['booking_reference'], ENT_QUOTES); ?>')">
                                            <i class="fas fa-times"></i> Cancel
                                        </button>
                                    <?php endif; ?>
                                    <?php if ($booking['status'] === 'checked-in'): ?>
                                        <button class="quick-action" style="background: #6c757d; color: white;" onclick="checkoutBooking(<?php echo $booking['id']; ?>, '<?php echo htmlspecialchars($booking['booking_reference'], ENT_QUOTES); ?>')">
                                            <i class="fas fa-sign-out-alt"></i> Checkout
                                        </button>
                                        <button class="quick-action undo-checkin" onclick="updateStatus(<?php echo $booking['id']; ?>, 'cancel-checkin')">
                                            <i class="fas fa-undo"></i> Cancel Check-in
                                        </button>
                                    <?php endif; ?>
                                    <?php if (in_array($booking['status'], ['confirmed', 'checked-in'], true)): ?>
                                        <button class="quick-action release" onclick="releaseRoom(<?php echo $booking['id']; ?>, '<?php echo htmlspecialchars($booking['booking_reference'], ENT_QUOTES); ?>')">
                                            <i class="fas fa-unlock"></i> Release Room
                                        </button>
                                    <?php endif; ?>
                                    <?php if ($booking['payment_status'] !== 'paid'): ?>
                                        <button class="quick-action paid" onclick="openPaymentModal(<?php echo $booking['id']; ?>, '<?php echo htmlspecialchars($booking['booking_reference'], ENT_QUOTES); ?>', <?php echo (float)($booking['total_with_vat'] ?? $booking['total_amount']); ?>, '<?php echo htmlspecialchars($booking['actual_payment_status'] ?? 'unpaid', ENT_QUOTES); ?>')">
                                            <i class="fas fa-dollar-sign"></i> Record Payment
                                        </button>
                                    <?php endif; ?>
                                    <?php if ($is_expired && $user['role'] === 'admin'): ?>
                                        <button class="quick-action" style="background:#dc3545;color:white;" onclick="deleteBooking(<?php echo $booking['id']; ?>, '<?php echo htmlspecialchars($booking['booking_reference'], ENT_QUOTES); ?>')">
                                            <i class="fas fa-trash"></i> Delete
                                        </button>
                                    <?php endif; ?>
                                </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-calendar-times"></i>
                    <p>No room bookings yet.</p>
                </div>
            <?php endif; ?>
        </div>

        <!-- Conference Inquiries -->
        <div class="bookings-section">
            <h3 class="section-title">
                <i class="fas fa-users"></i> Conference Inquiries
                <span style="font-size: 14px; font-weight: normal; color: #666;">
                    (<?php echo count($conference_inquiries); ?> total)
                </span>
            </h3>

            <?php if (!empty($conference_inquiries)): ?>
                <div class="table-responsive">
                    <table class="booking-table">
                    <thead>
                        <tr>
                            <th style="width: 140px;">Date Received</th>
                            <th style="width: 220px;">Company/Name</th>
                            <th style="width: 220px;">Contact</th>
                            <th style="width: 180px;">Event Type</th>
                            <th style="width: 140px;">Expected Date</th>
                            <th style="width: 100px;">Attendees</th>
                            <th style="width: 140px;">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($conference_inquiries as $inquiry): ?>
                            <?php $is_conf_expired = !empty($inquiry['event_date']) && strtotime($inquiry['event_date']) < strtotime('today'); ?>
                            <tr<?php if ($is_conf_expired) echo ' style="opacity:0.6;background-color:#f0f0f0 !important;"'; ?>>
                                <td><?php echo date('M d, Y', strtotime($inquiry['created_at'])); ?></td>
                                <td>
                                    <strong><?php echo htmlspecialchars($inquiry['company_name']); ?></strong>
                                    <br><small><?php echo htmlspecialchars($inquiry['contact_person']); ?></small>
                                </td>
                                <td>
                                    <?php echo htmlspecialchars($inquiry['email']); ?>
                                    <br><small style="color: #666;"><?php echo htmlspecialchars($inquiry['phone']); ?></small>
                                </td>
                                <td><?php echo htmlspecialchars($inquiry['event_type']); ?></td>
                                <td>
                                    <?php echo !empty($inquiry['event_date']) ? date('M d, Y', strtotime($inquiry['event_date'])) : '&mdash;'; ?>
                                    <?php if ($is_conf_expired): ?>
                                        <br><span style="background:#6c757d;color:white;font-size:10px;padding:2px 6px;border-radius:4px;display:inline-block;margin-top:2px;">Expired</span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo $inquiry['number_of_attendees']; ?></td>
                                <td>
                                    <span class="badge badge-<?php echo $inquiry['status']; ?>">
                                        <?php echo ucfirst($inquiry['status']); ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-inbox"></i>
                    <p>No conference inquiries yet.</p>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
        // Tab switching functionality
        let currentTab = 'all';

        function switchTab(tabName) {
            currentTab = tabName;
            
            // Update active tab button
            document.querySelectorAll('.tab-button').forEach(btn => {
                btn.classList.remove('active');
                if (btn.dataset.tab === tabName) {
                    btn.classList.add('active');
                }
            });
            
            // Filter table rows
            filterBookingsTable(tabName);
            
            // Update section title
            updateSectionTitle(tabName);
        }

        function filterBookingsTable(tabName) {
            const table = document.querySelector('.booking-table tbody');
            if (!table) return;
            
            const rows = table.querySelectorAll('tr');
            let visibleCount = 0;
            
            // Get today's date for comparison
            const today = new Date();
            today.setHours(0, 0, 0, 0);
            const todayStr = today.toISOString().split('T')[0];
            
            // Calculate week start (7 days ago)
            const weekStart = new Date(today);
            weekStart.setDate(weekStart.getDate() - 7);
            
            // Calculate month start (first day of current month)
            const monthStart = new Date(today.getFullYear(), today.getMonth(), 1);
            
            rows.forEach(row => {
                                const statusCell = row.querySelector('td:nth-child(10)'); // Status column (shifted +1 for Balance col)
                                const paymentCell = row.querySelector('td:nth-child(11)'); // Payment column (shifted +1)
                                const checkInCell = row.querySelector('td:nth-child(4)'); // Check-in date column
                                const checkOutCell = row.querySelector('td:nth-child(5)'); // Check-out date column
                                const createdCell = row.querySelector('td:nth-child(12)'); // Created timestamp column (shifted +1)
                
                if (!statusCell || !paymentCell) return;
                
                const statusBadge = statusCell.querySelector('.badge');
                const paymentBadge = paymentCell.querySelector('.badge');
                
                if (!statusBadge || !paymentBadge) return;
                
                const status = statusBadge.textContent.trim().toLowerCase().replace(' ', '-');
                const payment = paymentBadge.textContent.trim().toLowerCase();
                
                // Parse dates from table cells
                const checkInDate = checkInCell ? new Date(checkInCell.textContent.trim()) : null;
                const checkOutDate = checkOutCell ? new Date(checkOutCell.textContent.trim()) : null;
                
                // Parse created_at timestamp from column 11
                // Format: "Feb 1, 14:30" or similar
                let createdDate = null;
                if (createdCell) {
                    const createdText = createdCell.textContent.trim();
                    // Parse the date format "M j, H:i" (e.g., "Feb 1, 14:30")
                    const currentYear = today.getFullYear();
                    const createdMatch = createdText.match(/(\w+)\s+(\d+),\s+(\d+):(\d+)/);
                    if (createdMatch) {
                        const months = { 'Jan': 0, 'Feb': 1, 'Mar': 2, 'Apr': 3, 'May': 4, 'Jun': 5,
                                        'Jul': 6, 'Aug': 7, 'Sep': 8, 'Oct': 9, 'Nov': 10, 'Dec': 11 };
                        const month = months[createdMatch[1]];
                        const day = parseInt(createdMatch[2]);
                        const hour = parseInt(createdMatch[3]);
                        const minute = parseInt(createdMatch[4]);
                        createdDate = new Date(currentYear, month, day, hour, minute);
                    }
                }
                
                // Check if tentative booking is expiring soon (within 24 hours)
                const isExpiringSoon = row.innerHTML.includes('Expires soon') ||
                                      (status === 'tentative' && row.querySelector('.expires-soon'));
                
                // Check if check-in/check-out is today
                const isTodayCheckIn = checkInDate &&
                                      checkInDate.toISOString().split('T')[0] === todayStr &&
                                      status === 'confirmed';
                const isTodayCheckOut = checkOutDate &&
                                       checkOutDate.toISOString().split('T')[0] === todayStr &&
                                       status === 'checked-in';
                
                // Check time-based filters
                const isTodayBooking = createdDate &&
                                      createdDate.toISOString().split('T')[0] === todayStr;
                const isWeekBooking = createdDate &&
                                     createdDate >= weekStart;
                const isMonthBooking = createdDate &&
                                      createdDate >= monthStart;
                
                let isVisible = false;
                
                switch(tabName) {
                    case 'all':
                        isVisible = true;
                        break;
                    case 'pending':
                        isVisible = status === 'pending';
                        break;
                    case 'tentative':
                        isVisible = status === 'tentative' || row.innerHTML.includes('Tentative');
                        break;
                    case 'expiring-soon':
                        isVisible = isExpiringSoon;
                        break;
                    case 'confirmed':
                        isVisible = status === 'confirmed';
                        break;
                    case 'today-checkins':
                        isVisible = isTodayCheckIn;
                        break;
                    case 'today-checkouts':
                        isVisible = isTodayCheckOut;
                        break;
                    case 'checked-in':
                        isVisible = status === 'checked-in';
                        break;
                    case 'checked-out':
                        isVisible = status === 'checked-out';
                        break;
                    case 'cancelled':
                        isVisible = status === 'cancelled';
                        break;
                    case 'no-show':
                        isVisible = status === 'no-show';
                        break;
                    case 'paid':
                        isVisible = payment === 'paid' || payment === 'completed';
                        break;
                    case 'partial':
                        isVisible = payment === 'partial';
                        break;
                    case 'unpaid':
                        isVisible = payment !== 'paid' && payment !== 'completed' && payment !== 'partial';
                        break;
                    case 'today-bookings':
                        isVisible = isTodayBooking;
                        break;
                    case 'week-bookings':
                        isVisible = isWeekBooking;
                        break;
                    case 'month-bookings':
                        isVisible = isMonthBooking;
                        break;
                }
                
                if (isVisible) {
                    row.style.display = '';
                    visibleCount++;
                } else {
                    row.style.display = 'none';
                }
            });
            
            // Update count in section title
            const countSpan = document.querySelector('.section-title span');
            if (countSpan) {
                countSpan.textContent = `(${visibleCount} shown)`;
            }
        }

        function updateSectionTitle(tabName) {
            const titleElement = document.querySelector('.section-title');
            if (!titleElement) return;
            
            const tabTitles = {
                'all': 'All Room Bookings',
                'pending': 'Pending Bookings',
                'tentative': 'Tentative Bookings',
                'expiring-soon': 'Expiring Soon (Urgent)',
                'confirmed': 'Confirmed Bookings',
                'today-checkins': "Today's Check-ins",
                'today-checkouts': "Today's Check-outs",
                'checked-in': 'Checked In Guests',
                'checked-out': 'Checked Out Bookings',
                'cancelled': 'Cancelled Bookings',
                'no-show': 'No-Show Bookings',
                'paid': 'Paid Bookings',
                'partial': 'Partial Payments',
                'unpaid': 'Unpaid Bookings',
                'today-bookings': "Today's Bookings",
                'week-bookings': "This Week's Bookings",
                'month-bookings': "This Month's Bookings"
            };
            
            const icon = titleElement.querySelector('i');
            const countSpan = titleElement.querySelector('span');
            
            let newTitle = tabTitles[tabName] || 'Room Bookings';
            let newIcon = 'fa-bed';
            
            if (tabName === 'pending') newIcon = 'fa-clock';
            if (tabName === 'tentative') newIcon = 'fa-hourglass-half';
            if (tabName === 'expiring-soon') newIcon = 'fa-exclamation-triangle';
            if (tabName === 'confirmed') newIcon = 'fa-check-circle';
            if (tabName === 'today-checkins') newIcon = 'fa-calendar-day';
            if (tabName === 'today-checkouts') newIcon = 'fa-calendar-times';
            if (tabName === 'checked-in') newIcon = 'fa-sign-in-alt';
            if (tabName === 'checked-out') newIcon = 'fa-sign-out-alt';
            if (tabName === 'cancelled') newIcon = 'fa-times-circle';
            if (tabName === 'no-show') newIcon = 'fa-user-slash';
            if (tabName === 'paid') newIcon = 'fa-dollar-sign';
            if (tabName === 'partial') newIcon = 'fa-adjust';
            if (tabName === 'unpaid') newIcon = 'fa-exclamation-circle';
            if (tabName === 'today-bookings') newIcon = 'fa-calendar-day';
            if (tabName === 'week-bookings') newIcon = 'fa-calendar-week';
            if (tabName === 'month-bookings') newIcon = 'fa-calendar-alt';
            
            titleElement.innerHTML = `<i class="fas ${newIcon}"></i> ${newTitle} `;
            if (countSpan) {
                titleElement.appendChild(countSpan);
            }
        }

        // Initialize tab click handlers
        document.addEventListener('DOMContentLoaded', function() {
            const tabButtons = document.querySelectorAll('.tab-button');
            tabButtons.forEach(button => {
                button.addEventListener('click', function() {
                    const tabName = this.dataset.tab;
                    switchTab(tabName);
                });
            });
            
            // Initial filter
            switchTab('all');
        });

        function makeTentative(id) {
            if (!confirm('Convert this pending booking to a tentative reservation? This will hold the room for 48 hours and send a confirmation email to the guest.')) {
                return;
            }
            
            const formData = new FormData();
            formData.append('action', 'make_tentative');
            formData.append('id', id);
            
            fetch(window.location.href, {
                method: 'POST',
                body: formData
            })
            .then(response => {
                if (response.ok) {
                    window.location.reload();
                } else {
                    Alert.show('Error converting booking to tentative', 'error');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                Alert.show('Error converting booking to tentative', 'error');
            });
        }
        
        function convertTentativeBooking(id) {
            if (!confirm('Convert this tentative booking to a confirmed reservation? This will send a confirmation email to the guest.')) {
                return;
            }
            
            const formData = new FormData();
            formData.append('action', 'convert_tentative');
            formData.append('id', id);
            
            fetch(window.location.href, {
                method: 'POST',
                body: formData
            })
            .then(response => {
                if (response.ok) {
                    window.location.reload();
                } else {
                    Alert.show('Error converting booking', 'error');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                Alert.show('Error converting booking', 'error');
            });
        }
        
        function convertToTentative(id) {
            if (!confirm('Convert this confirmed booking to tentative? This will place the booking on hold for 48 hours and send an email to the guest.')) {
                return;
            }
            
            const formData = new FormData();
            formData.append('action', 'convert_to_tentative');
            formData.append('id', id);
            
            fetch(window.location.href, {
                method: 'POST',
                body: formData
            })
            .then(response => {
                if (response.ok) {
                    window.location.reload();
                } else {
                    Alert.show('Error converting booking to tentative', 'error');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                Alert.show('Error converting booking to tentative', 'error');
            });
        }
        
        function updateStatus(id, status) {
            const formData = new FormData();
            formData.append('action', 'update_status');
            formData.append('id', id);
            formData.append('status', status);
            
            fetch(window.location.href, {
                method: 'POST',
                body: formData
            })
            .then(response => {
                if (response.ok) {
                    window.location.reload();
                } else {
                    Alert.show('Error updating status', 'error');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                Alert.show('Error updating status', 'error');
            });
        }

        function openPaymentModal(bookingId, bookingRef, totalAmount, currentStatus) {
            document.getElementById('paymentModal').style.display = 'flex';
            document.getElementById('payment_booking_id').value = bookingId;
            document.getElementById('payment_booking_ref').value = bookingRef;
            document.getElementById('payment_amount_display').value = 'K ' + new Intl.NumberFormat('en-US').format(Math.round(totalAmount));
            document.getElementById('payment_amount_input').value = '';
            document.getElementById('payment_notes_input').value = '';
            document.getElementById('payment_type_full').checked = true;
            document.getElementById('partial_amount_group').style.display = 'none';
            document.getElementById('payment_amount_input').required = false;
        }

        function closePaymentModal() {
            document.getElementById('paymentModal').style.display = 'none';
            document.getElementById('paymentForm').reset();
        }

        function togglePaymentAmountField() {
            const isPartial = document.getElementById('payment_type_partial').checked;
            document.getElementById('partial_amount_group').style.display = isPartial ? 'block' : 'none';
            document.getElementById('payment_amount_input').required = isPartial;
        }

        function submitPaymentForm() {
            const isPartial = document.getElementById('payment_type_partial').checked;
            const amountInput = document.getElementById('payment_amount_input');
            if (isPartial && (!amountInput.value || parseFloat(amountInput.value) <= 0)) {
                Alert.show('Please enter a valid payment amount.', 'error');
                return;
            }
            const formData = new FormData(document.getElementById('paymentForm'));
            fetch(window.location.href, {
                method: 'POST',
                body: formData
            })
            .then(response => response.text())
            .then(data => {
                window.location.reload();
            })
            .catch(error => {
                console.error('Error:', error);
                Alert.show('Error recording payment', 'error');
            });
        }

        function cancelBooking(id, reference) {
            const reason = prompt('Enter cancellation reason (optional):');
            if (reason === null) {
                return; // User cancelled
            }
            
            const formData = new FormData();
            formData.append('action', 'update_status');
            formData.append('id', id);
            formData.append('status', 'cancelled');
            formData.append('cancellation_reason', reason || 'Cancelled by admin');
            
            fetch(window.location.href, {
                method: 'POST',
                body: formData
            })
            .then(response => {
                if (response.ok) {
                    window.location.reload();
                } else {
                    Alert.show('Error cancelling booking', 'error');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                Alert.show('Error cancelling booking', 'error');
            });
        }

        function checkoutBooking(id, reference) {
            if (!confirm('Check out booking ' + reference + '?')) return;
            
            const formData = new FormData();
            formData.append('action', 'checkout');
            formData.append('id', id);
            
            fetch(window.location.href, {
                method: 'POST',
                body: formData
            })
            .then(response => {
                if (response.ok) {
                    window.location.reload();
                } else {
                    Alert.show('Error checking out booking', 'error');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                Alert.show('Error checking out booking', 'error');
            });
        }

        function markNoShow(id, reference) {
            if (!confirm('Mark booking ' + reference + ' as No-Show? This will restore room availability.')) return;
            
            const formData = new FormData();
            formData.append('action', 'noshow');
            formData.append('id', id);
            
            fetch(window.location.href, {
                method: 'POST',
                body: formData
            })
            .then(response => {
                if (response.ok) {
                    window.location.reload();
                } else {
                    Alert.show('Error marking as no-show', 'error');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                Alert.show('Error marking as no-show', 'error');
            });
        }

        function releaseRoom(id, reference) {
            const reason = prompt('Release reason for booking ' + reference + ':', 'Manual room release by admin');
            if (reason === null) return;
            if (!confirm('Release booking ' + reference + '? This will cancel the booking and restore room availability.')) return;

            const formData = new FormData();
            formData.append('action', 'release_room');
            formData.append('id', id);
            formData.append('release_reason', (reason || '').trim());

            fetch(window.location.href, {
                method: 'POST',
                body: formData
            })
            .then(response => {
                if (response.ok) {
                    window.location.reload();
                } else {
                    Alert.show('Error releasing room', 'error');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                Alert.show('Error releasing room', 'error');
            });
        }

        function deleteBooking(id, reference) {
            if (!confirm('PERMANENTLY delete expired booking ' + reference + '?\n\nThis action cannot be undone.')) return;
            var form = document.createElement('form');
            form.method = 'POST';
            form.action = window.location.href;
            [['action', 'delete_booking'], ['id', id]].forEach(function(pair) {
                var input = document.createElement('input');
                input.type = 'hidden';
                input.name = pair[0];
                input.value = pair[1];
                form.appendChild(input);
            });
            document.body.appendChild(form);
            form.submit();
        }
    </script>
    
    <!-- Email Resend Modal -->
        <!-- Payment Modal -->
        <div id="paymentModal" class="modal" style="display: none;">
            <div class="modal-content" style="max-width: 520px;">
                <div class="modal-header">
                    <h3><i class="fas fa-dollar-sign"></i> Record Payment</h3>
                    <button class="close-modal" onclick="closePaymentModal()">&times;</button>
                </div>
                <form id="paymentForm">
                    <input type="hidden" name="action" value="update_payment">
                    <input type="hidden" name="id" id="payment_booking_id" value="">
                    <div class="modal-body">
                        <div class="form-group">
                            <label><i class="fas fa-hashtag"></i> Booking Reference:</label>
                            <input type="text" id="payment_booking_ref" class="form-control" readonly style="background: #f5f5f5;">
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-list-ul"></i> Payment Type:</label>
                            <div style="display: flex; gap: 20px; margin-top: 8px;">
                                <label style="display: flex; align-items: center; gap: 8px; cursor: pointer; font-weight: normal;">
                                    <input type="radio" name="payment_type" id="payment_type_full" value="full" checked onchange="togglePaymentAmountField()">
                                    <span>Full Payment</span>
                                </label>
                                <label style="display: flex; align-items: center; gap: 8px; cursor: pointer; font-weight: normal;">
                                    <input type="radio" name="payment_type" id="payment_type_partial" value="partial" onchange="togglePaymentAmountField()">
                                    <span>Partial Payment</span>
                                </label>
                            </div>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-coins"></i> Total Booking Amount (MWK):</label>
                            <input type="text" id="payment_amount_display" class="form-control" readonly style="background: #f5f5f5;">
                        </div>
                        <div class="form-group" id="partial_amount_group" style="display: none;">
                            <label for="payment_amount_input"><i class="fas fa-coins"></i> Amount Being Paid Now (MWK):</label>
                            <input type="number" name="amount_paid" id="payment_amount_input" class="form-control" min="1" step="0.01" placeholder="Enter amount received">
                            <small style="color:#666;">Enter the actual amount received for this partial payment.</small>
                        </div>
                        <div class="form-group">
                            <label for="payment_notes_input"><i class="fas fa-sticky-note"></i> Notes (Optional):</label>
                            <textarea name="payment_notes" id="payment_notes_input" class="form-control" rows="3" placeholder="e.g., Bank transfer ref #12345, paid by cheque..."></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" onclick="closePaymentModal()">Cancel</button>
                        <button type="button" class="btn btn-primary" onclick="submitPaymentForm()"><i class="fas fa-check-circle"></i> Record Payment</button>
                    </div>
                </form>
            </div>
        </div>

    <div id="resendEmailModal" class="modal" style="display: none;">
        <div class="modal-content" style="max-width: 500px;">
            <div class="modal-header">
                <h3><i class="fas fa-envelope"></i> Resend Email</h3>
                <button class="close-modal" onclick="closeResendEmailModal()">&times;</button>
            </div>
            <form id="resendEmailForm" method="POST" action="">
                <?php echo getCsrfField(); ?>
                <input type="hidden" name="action" value="resend_email">
                <input type="hidden" name="booking_id" id="modal_booking_id" value="">
                
                <div class="modal-body">
                    <div class="form-group">
                        <label><i class="fas fa-hashtag"></i> Booking Reference:</label>
                        <input type="text" id="modal_booking_reference" class="form-control" readonly style="background: #f5f5f5;">
                    </div>
                    
                    <div class="form-group">
                        <label for="email_type"><i class="fas fa-envelope"></i> Email Type:</label>
                        <select name="email_type" id="email_type" class="form-control" required>
                            <option value="">-- Select Email Type --</option>
                            <option value="booking_received">Booking Received (Initial confirmation)</option>
                            <option value="booking_confirmed">Booking Confirmed</option>
                            <option value="tentative_confirmed">Tentative Booking Confirmed</option>
                            <option value="tentative_converted">Tentative Converted to Confirmed</option>
                            <option value="booking_cancelled">Booking Cancelled</option>
                        </select>
                        <small style="color: #666;">Select the type of email to resend based on current booking status</small>
                    </div>
                    
                    <div class="form-group">
                        <label for="cc_emails"><i class="fas fa-users"></i> CC Emails (Optional):</label>
                        <input type="text" name="cc_emails" id="cc_emails" class="form-control" placeholder="email1@example.com, email2@example.com">
                        <small style="color: #666;">Comma-separated email addresses to CC (e.g., hotel manager, accounting)</small>
                    </div>
                </div>
                
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeResendEmailModal()">Cancel</button>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-paper-plane"></i> Send Email</button>
                </div>
            </form>
        </div>
    </div>
    
    <style>
        @keyframes slideDown {
            from {
                transform: translateY(-50px);
                opacity: 0;
            }
            to {
                transform: translateY(0);
                opacity: 1;
            }
        }
        
        .close-modal {
            background: none;
            border: none;
            color: white;
            font-size: 28px;
            cursor: pointer;
            padding: 0;
            width: 30px;
            height: 30px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            transition: background 0.3s;
        }
        
        .close-modal:hover {
            background: rgba(255,255,255,0.2);
        }
        
        .modal-footer {
            padding: 20px;
            border-top: 1px solid #ddd;
            display: flex;
            justify-content: flex-end;
            gap: 10px;
        }
        
        .form-group label i {
            color: var(--gold);
            margin-right: 5px;
        }
        
        .btn-primary {
            background: var(--gold);
            color: var(--deep-navy);
        }
        
        .btn-primary:hover {
            background: #c19b2e;
            transform: translateY(-1px);
        }
        
        .btn-secondary {
            background: #6c757d;
            color: white;
        }
        
        .btn-secondary:hover {
            background: #5a6268;
        }
    </style>
    
    <script>
        function openResendEmailModal(bookingId, bookingReference, bookingStatus) {
            document.getElementById('resendEmailModal').style.display = 'flex';
            document.getElementById('modal_booking_id').value = bookingId;
            document.getElementById('modal_booking_reference').value = bookingReference;
            
            // Set default email type based on booking status
            const emailTypeSelect = document.getElementById('email_type');
            emailTypeSelect.value = '';
            
            // Show/hide appropriate options based on status
            const options = emailTypeSelect.querySelectorAll('option');
            options.forEach(option => {
                option.style.display = '';
            });
            
            // Disable options that don't make sense for current status
            switch(bookingStatus) {
                case 'pending':
                    emailTypeSelect.value = 'booking_received';
                    break;
                case 'tentative':
                    emailTypeSelect.value = 'tentative_confirmed';
                    break;
                case 'confirmed':
                    emailTypeSelect.value = 'booking_confirmed';
                    break;
                case 'cancelled':
                    emailTypeSelect.value = 'booking_cancelled';
                    break;
            }
        }
        
        function closeResendEmailModal() {
            document.getElementById('resendEmailModal').style.display = 'none';
            document.getElementById('resendEmailForm').reset();
        }
        
        // Close modal when clicking outside
        window.onclick = function(event) {
            const emailModal = document.getElementById('resendEmailModal');
            const payModal = document.getElementById('paymentModal');
            if (event.target === emailModal) {
                closeResendEmailModal();
            }
            if (event.target === payModal) {
                closePaymentModal();
            }
        }
        
        // Form submission
        const resendEmailForm = document.getElementById('resendEmailForm');
        if (resendEmailForm) {
            resendEmailForm.addEventListener('submit', function(e) {
                e.preventDefault();
                
                const formData = new FormData(this);
                
                fetch(window.location.href, {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.text())
                .then(data => {
                    // Reload page to see success/error message
                    window.location.reload();
                })
                .catch(error => {
                    console.error('Error:', error);
                    Alert.show('Error sending email', 'error');
                });
            });
        }
    </script>
    <script src="js/admin-components.js"></script>
    <script src="js/admin-mobile.js"></script>

    <?php require_once 'includes/admin-footer.php'; ?>