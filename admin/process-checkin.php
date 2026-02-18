<?php
// Include admin initialization (PHP-only, no HTML output)
require_once 'admin-init.php';

header('Content-Type: application/json');

require_once '../config/email-simple.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['action'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid request']);
    exit;
}

$action = $_POST['action'];
$booking_id = (int)($_POST['booking_id'] ?? 0);

if ($booking_id <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid booking ID']);
    exit;
}

try {
    if ($action === 'checkin') {
        // Only allow check-in when booking is confirmed AND fully paid
        $stmt = $pdo->prepare("UPDATE bookings SET status = 'checked-in' WHERE id = ? AND status = 'confirmed' AND payment_status = 'paid'");
        $stmt->execute([$booking_id]);

        if ($stmt->rowCount() > 0) {
            // Get booking details for email notification
            $booking_stmt = $pdo->prepare("
                SELECT b.*, r.name as room_name 
                FROM bookings b 
                INNER JOIN rooms r ON b.room_id = r.id 
                WHERE b.id = ?
            ");
            $booking_stmt->execute([$booking_id]);
            $booking = $booking_stmt->fetch(PDO::FETCH_ASSOC);
            
            // Send status update email
            if ($booking) {
                $email_result = sendSimpleStatusUpdateEmail($booking, 'checked-in');
                if (!$email_result['success']) {
                    error_log("Failed to send check-in email: " . $email_result['message']);
                }
            }
            
            echo json_encode(['success' => true, 'message' => 'Guest checked in successfully']);
            exit;
        }

        // Give a helpful reason
        $check = $pdo->prepare("SELECT status, payment_status FROM bookings WHERE id = ?");
        $check->execute([$booking_id]);
        $row = $check->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Booking not found']);
            exit;
        }

        if ($row['status'] !== 'confirmed') {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => "Cannot check in: booking must be confirmed (current: {$row['status']})"]);
            exit;
        }

        if ($row['payment_status'] !== 'paid') {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => "Cannot check in: payment must be PAID (current: {$row['payment_status']})"]);
            exit;
        }

        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Cannot check in: booking not eligible']);
        exit;
    }

    if ($action === 'cancel_checkin') {
        // Undo a check-in (revert to confirmed)
        $stmt = $pdo->prepare("UPDATE bookings SET status = 'confirmed' WHERE id = ? AND status = 'checked-in'");
        $stmt->execute([$booking_id]);

        if ($stmt->rowCount() > 0) {
            // Get booking details for email notification
            $booking_stmt = $pdo->prepare("
                SELECT b.*, r.name as room_name 
                FROM bookings b 
                INNER JOIN rooms r ON b.room_id = r.id 
                WHERE b.id = ?
            ");
            $booking_stmt->execute([$booking_id]);
            $booking = $booking_stmt->fetch(PDO::FETCH_ASSOC);
            
            // Send status update email
            if ($booking) {
                $email_result = sendSimpleStatusUpdateEmail($booking, 'confirmed');
                if (!$email_result['success']) {
                    error_log("Failed to send status update email: " . $email_result['message']);
                }
            }
            
            echo json_encode(['success' => true, 'message' => 'Check-in cancelled (reverted to confirmed)']);
            exit;
        }

        $check = $pdo->prepare("SELECT status FROM bookings WHERE id = ?");
        $check->execute([$booking_id]);
        $row = $check->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Booking not found']);
            exit;
        }

        http_response_code(400);
        echo json_encode(['success' => false, 'message' => "Cannot cancel check-in: booking is not checked-in (current: {$row['status']})"]);
        exit;
    }

    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Unknown action']);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
?>