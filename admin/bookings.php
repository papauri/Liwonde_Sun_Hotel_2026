<?php
// Include admin initialization (PHP-only, no HTML output)
require_once 'admin-init.php';

require_once '../includes/modal.php';
require_once '../includes/alert.php';
$message = '';
$error = '';

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
                
                // Get current booking status and room_id before updating
                $check_stmt = $pdo->prepare("SELECT status, room_id FROM bookings WHERE id = ?");
                $check_stmt->execute([$booking_id]);
                $current_booking = $check_stmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$current_booking) {
                    throw new Exception('Booking not found');
                }
                
                $current_status = $current_booking['status'];
                $room_id = $current_booking['room_id'];
                
                // Update booking status
                $stmt = $pdo->prepare("UPDATE bookings SET status = ? WHERE id = ?");
                $stmt->execute([$new_status, $booking_id]);
                $message = 'Booking status updated!';
                
                // Handle room availability changes
                if ($current_status === 'pending' && $new_status === 'confirmed') {
                    // Booking confirmed: decrement rooms_available
                    $update_room = $pdo->prepare("UPDATE rooms SET rooms_available = rooms_available - 1 WHERE id = ? AND rooms_available > 0");
                    $update_room->execute([$room_id]);
                    
                    if ($update_room->rowCount() === 0) {
                        // This shouldn't happen if availability checks are working, but handle it
                        $message .= ' (Warning: Could not update room availability - room may be fully booked)';
                    } else {
                        $message .= ' Room availability updated.';
                    }
                    
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
                }
            }

        } elseif ($action === 'update_payment') {
            $payment_status = $_POST['payment_status'];
            $booking_id = $_POST['id'];
            
            // Get previous payment status and booking details
            $check = $pdo->prepare("SELECT payment_status, total_amount, booking_reference FROM bookings WHERE id = ?");
            $check->execute([$booking_id]);
            $row = $check->fetch(PDO::FETCH_ASSOC);
            
            if (!$row) {
                throw new Exception('Booking not found');
            }
            
            $previous_status = $row['payment_status'] ?? 'unpaid';
            $total_amount = (float)$row['total_amount'];
            $booking_reference = $row['booking_reference'];
            
            // Get VAT settings - more flexible check
            $vatEnabled = in_array(getSetting('vat_enabled'), ['1', 1, true, 'true', 'on'], true);
            $vatRate = $vatEnabled ? (float)getSetting('vat_rate') : 0;
            
            // Calculate amounts
            $vatAmount = $vatEnabled ? ($total_amount * ($vatRate / 100)) : 0;
            $totalWithVat = $total_amount + $vatAmount;
            
            // Update payment status
            $stmt = $pdo->prepare("UPDATE bookings SET payment_status = ? WHERE id = ?");
            $stmt->execute([$payment_status, $booking_id]);
            $message = 'Payment status updated!';
            
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
                    $booking_reference,
                    $total_amount,
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
                
                $message .= ' Payment recorded in accounting system.';
                
                // Send invoice email
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
                    $upd = $pdo->prepare("UPDATE bookings SET status = 'no-show', updated_at = NOW() WHERE id = ?");
                    $upd->execute([$booking_id]);
                    
                    // Restore room availability (was decremented at confirmation)
                    $restore = $pdo->prepare("UPDATE rooms SET rooms_available = rooms_available + 1 WHERE id = ?");
                    $restore->execute([$bk['room_id']]);
                    
                    $message = 'Booking ' . htmlspecialchars($bk['booking_reference']) . ' marked as No-Show. Room availability restored.';
                } else {
                    $error = 'Only confirmed bookings can be marked as no-show.';
                }
            }
        }

    } catch (Throwable $e) {
        $error = 'Error: ' . $e->getMessage();
    }
}

// Handle CSV export
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    try {
        $export_stmt = $pdo->query("
            SELECT b.booking_reference, b.guest_name, b.guest_email, b.guest_phone, b.guest_country,
                   r.name as room_name, b.check_in_date, b.check_out_date, b.number_of_nights,
                   b.number_of_guests, b.total_amount, b.status, b.payment_status, b.occupancy_type,
                   b.special_requests, b.created_at
            FROM bookings b
            LEFT JOIN rooms r ON b.room_id = r.id
            ORDER BY b.created_at DESC
        ");
        $export_data = $export_stmt->fetchAll(PDO::FETCH_ASSOC);
        
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="bookings-export-' . date('Y-m-d') . '.csv"');
        
        $output = fopen('php://output', 'w');
        fputcsv($output, ['Reference', 'Guest Name', 'Email', 'Phone', 'Country', 'Room', 
                          'Check-in', 'Check-out', 'Nights', 'Guests', 'Total', 'Status', 
                          'Payment', 'Occupancy', 'Special Requests', 'Created']);
        
        foreach ($export_data as $row) {
            fputcsv($output, $row);
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
    }
    
    $where_sql = !empty($where_clauses) ? 'WHERE ' . implode(' AND ', $where_clauses) : '';
    
    $stmt = $pdo->prepare("
        SELECT b.*,
               r.name as room_name,
               COALESCE(p.payment_status, b.payment_status) as actual_payment_status,
               p.payment_reference,
               p.payment_date as last_payment_date
        FROM bookings b
        LEFT JOIN rooms r ON b.room_id = r.id
        LEFT JOIN payments p ON b.id = p.booking_id AND p.booking_type = 'room' AND p.status = 'completed'
        {$where_sql}
        ORDER BY b.created_at DESC
    ");
    $stmt->execute($params);
    $bookings = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Also fetch conference inquiries
    $conf_stmt = $pdo->query("
        SELECT * FROM conference_inquiries 
        ORDER BY created_at DESC
    ");
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
$unpaid = count(array_filter($bookings, fn($b) =>
    $b['actual_payment_status'] !== 'paid' && $b['actual_payment_status'] !== 'completed'
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
            min-width: 1200px;
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
        .quick-action {
            padding: 6px 14px;
            border: none;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s ease;
            margin-right: 4px;
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
            .stat-card {
                padding: 16px;
            }
            .stat-card .number {
                font-size: 24px;
            }
            .booking-table {
                font-size: 12px;
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
            .booking-table {
                font-size: 11px;
            }
            .booking-table th,
            .booking-table td {
                padding: 6px;
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
                <a href="bookings.php?export=csv" class="quick-action" style="padding: 10px 16px; background: #28a745; color: white; text-decoration: none; border-radius: 8px; font-size: 13px;">
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
                                $expires_soon = false;
                                if ($is_tentative && $booking['tentative_expires_at']) {
                                    $expires_at = new DateTime($booking['tentative_expires_at']);
                                    $now = new DateTime();
                                    $hours_until_expiry = ($expires_at->getTimestamp() - $now->getTimestamp()) / 3600;
                                    $expires_soon = $hours_until_expiry <= 24 && $hours_until_expiry > 0;
                                }
                            ?>
                            <tr <?php echo $is_tentative ? 'style="background: linear-gradient(90deg, rgba(212, 175, 55, 0.05) 0%, white 10%);"' : ''; ?>>
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
                                <td><?php echo htmlspecialchars($booking['room_name']); ?></td>
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
                                    <span class="badge badge-<?php echo $booking['status']; ?>">
                                        <?php echo ucfirst($booking['status']); ?>
                                    </span>
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
                                        <button class="quick-action confirm" onclick="updateStatus(<?php echo $booking['id']; ?>, 'confirmed')">
                                            <i class="fas fa-check"></i> Confirm
                                        </button>
                                        <button class="quick-action" style="background: linear-gradient(135deg, var(--gold) 0%, #c49b2e 100%); color: var(--deep-navy);" onclick="makeTentative(<?php echo $booking['id']; ?>)">
                                            <i class="fas fa-clock"></i> Make Tentative
                                        </button>
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
                                    <?php if ($booking['payment_status'] !== 'paid'): ?>
                                        <button class="quick-action paid" onclick="updatePayment(<?php echo $booking['id']; ?>, 'paid')">
                                            <i class="fas fa-dollar-sign"></i> Paid
                                        </button>
                                    <?php endif; ?>
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
                            <tr>
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
                                <td><?php echo date('M d, Y', strtotime($inquiry['expected_date'])); ?></td>
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
                const statusCell = row.querySelector('td:nth-child(9)'); // Status column
                const paymentCell = row.querySelector('td:nth-child(10)'); // Payment column
                const checkInCell = row.querySelector('td:nth-child(4)'); // Check-in date column
                const checkOutCell = row.querySelector('td:nth-child(5)'); // Check-out date column
                const createdCell = row.querySelector('td:nth-child(11)'); // Created timestamp column
                
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
                    case 'unpaid':
                        isVisible = payment !== 'paid' && payment !== 'completed';
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

        function updatePayment(id, payment_status) {
            const formData = new FormData();
            formData.append('action', 'update_payment');
            formData.append('id', id);
            formData.append('payment_status', payment_status);
            
            fetch(window.location.href, {
                method: 'POST',
                body: formData
            })
            .then(response => {
                if (response.ok) {
                    window.location.reload();
                } else {
                    Alert.show('Error updating payment', 'error');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                Alert.show('Error updating payment', 'error');
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
    </script>
    
    <!-- Email Resend Modal -->
    <div id="resendEmailModal" class="modal" style="display: none;">
        <div class="modal-content" style="max-width: 500px;">
            <div class="modal-header">
                <h3><i class="fas fa-envelope"></i> Resend Email</h3>
                <button class="close-modal" onclick="closeResendEmailModal()">&times;</button>
            </div>
            <form id="resendEmailForm" method="POST" action="">
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
            const modal = document.getElementById('resendEmailModal');
            if (event.target === modal) {
                closeResendEmailModal();
            }
        }
        
        // Form submission
        document.getElementById('resendEmailForm').addEventListener('submit', function(e) {
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
    </script>
    <script src="js/admin-components.js"></script>
    <script src="js/admin-mobile.js"></script>

    <?php require_once 'includes/admin-footer.php'; ?>
