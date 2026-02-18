<?php
/**
 * Simple Email Functions for Status Updates
 * Lightweight email functions for booking status changes
 */

require_once __DIR__ . '/email.php';

/**
 * Send simple status update email for booking status changes
 * 
 * @param array $booking Booking details array
 * @param string $status New status (e.g., 'checked-in', 'confirmed', 'cancelled')
 * @return array Result array with success status and message
 */
function sendSimpleStatusUpdateEmail($booking, $status) {
    global $email_site_name, $email_from_email, $email_site_url;
    
    try {
        // Get room details
        global $pdo;
        $stmt = $pdo->prepare("SELECT * FROM rooms WHERE id = ?");
        $stmt->execute([$booking['room_id']]);
        $room = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$room) {
            throw new Exception("Room not found");
        }
        
        // Determine email content based on status
        $statusMessages = [
            'checked-in' => [
                'subject' => 'Check-in Confirmed',
                'title' => 'Welcome! You are Checked In',
                'message' => 'You have been successfully checked in. We hope you enjoy your stay!',
                'color' => '#28a745'
            ],
            'confirmed' => [
                'subject' => 'Booking Status Updated',
                'title' => 'Booking Status: Confirmed',
                'message' => 'Your booking status has been updated to confirmed.',
                'color' => '#0d6efd'
            ],
            'cancelled' => [
                'subject' => 'Booking Cancelled',
                'title' => 'Booking Cancelled',
                'message' => 'Your booking has been cancelled.',
                'color' => '#dc3545'
            ]
        ];
        
        if (!isset($statusMessages[$status])) {
            throw new Exception("Unknown status: $status");
        }
        
        $msg = $statusMessages[$status];
        
        // Prepare email content
        $htmlBody = '
        <h1 style="color: ' . $msg['color'] . '; text-align: center;">' . htmlspecialchars($msg['title']) . '</h1>
        <p>Dear ' . htmlspecialchars($booking['guest_name']) . ',</p>
        <p>' . $msg['message'] . '</p>
        
        <div style="background: #f8f9fa; border: 2px solid #0A1929; padding: 20px; margin: 20px 0; border-radius: 10px;">
            <h2 style="color: #0A1929; margin-top: 0;">Booking Details</h2>
            
            <div style="display: flex; justify-content: space-between; padding: 10px 0; border-bottom: 1px solid #ddd;">
                <span style="font-weight: bold; color: #0A1929;">Booking Reference:</span>
                <span style="color: #D4AF37; font-weight: bold; font-size: 18px;">' . htmlspecialchars($booking['booking_reference']) . '</span>
            </div>
            
            <div style="display: flex; justify-content: space-between; padding: 10px 0; border-bottom: 1px solid #ddd;">
                <span style="font-weight: bold; color: #0A1929;">Room:</span>
                <span style="color: #333;">' . htmlspecialchars($room['name']) . '</span>
            </div>
            
            <div style="display: flex; justify-content: space-between; padding: 10px 0; border-bottom: 1px solid #ddd;">
                <span style="font-weight: bold; color: #0A1929;">Check-in Date:</span>
                <span style="color: #333;">' . date('F j, Y', strtotime($booking['check_in_date'])) . '</span>
            </div>
            
            <div style="display: flex; justify-content: space-between; padding: 10px 0; border-bottom: 1px solid #ddd;">
                <span style="font-weight: bold; color: #0A1929;">Check-out Date:</span>
                <span style="color: #333;">' . date('F j, Y', strtotime($booking['check_out_date'])) . '</span>
            </div>
            
            <div style="display: flex; justify-content: space-between; padding: 10px 0;">
                <span style="font-weight: bold; color: #0A1929;">Status:</span>
                <span style="color: ' . $msg['color'] . '; font-weight: bold; text-transform: uppercase;">' . htmlspecialchars($status) . '</span>
            </div>
        </div>
        
        <p>If you have any questions, please contact us at <a href="mailto:' . htmlspecialchars($email_from_email) . '">' . htmlspecialchars($email_from_email) . '</a>.</p>
        
        <div style="text-align: center; margin-top: 30px; padding-top: 20px; border-top: 2px solid #0A1929;">
            <p style="color: #666; font-size: 14px;">
                <strong>The ' . htmlspecialchars($email_site_name) . ' Team</strong><br>
                <a href="' . htmlspecialchars($email_site_url) . '">' . htmlspecialchars($email_site_url) . '</a>
            </p>
        </div>';
        
        // Send email
        return sendEmail(
            $booking['guest_email'],
            $booking['guest_name'],
            $msg['subject'] . ' - ' . htmlspecialchars($email_site_name) . ' [' . $booking['booking_reference'] . ']',
            $htmlBody
        );
        
    } catch (Exception $e) {
        error_log("Send Simple Status Update Email Error: " . $e->getMessage());
        return [
            'success' => false,
            'message' => $e->getMessage()
        ];
    }
}
