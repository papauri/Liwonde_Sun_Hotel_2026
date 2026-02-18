<?php
/**
 * Database-Driven Email Configuration for any hotel
 * All settings stored in database - no hardcoded files
 */

// Require database connection for settings
require_once __DIR__ . '/database.php';

// Load PHPMailer
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// Load PHPMailer from local directory
if (file_exists(__DIR__ . '/../PHPMailer/src/PHPMailer.php')) {
    require_once __DIR__ . '/../PHPMailer/src/PHPMailer.php';
    require_once __DIR__ . '/../PHPMailer/src/SMTP.php';
    require_once __DIR__ . '/../PHPMailer/src/Exception.php';
} elseif (file_exists(__DIR__ . '/../vendor/autoload.php')) {
    // Fallback to Composer autoloader
    require_once __DIR__ . '/../vendor/autoload.php';
}

// Get email settings from database - NO HARCODED DEFAULTS
$email_from_name = getEmailSetting('email_from_name', '');
$email_from_email = getEmailSetting('email_from_email', '');
$email_admin_email = getEmailSetting('email_admin_email', '');
$email_site_name = getSetting('site_name', '');
$email_site_url = getSetting('site_url', '');

// SMTP Configuration - From database only
$smtp_host = getEmailSetting('smtp_host', '');
$smtp_port = (int)getEmailSetting('smtp_port', 0);
$smtp_username = getEmailSetting('smtp_username', '');
$smtp_password = getEmailSetting('smtp_password', '');
$smtp_secure = getEmailSetting('smtp_secure', '');
$smtp_timeout = (int)getEmailSetting('smtp_timeout', 30);
$smtp_debug = (int)getEmailSetting('smtp_debug', 0);

// Email settings
$email_bcc_admin = (bool)getEmailSetting('email_bcc_admin', 0);
$email_development_mode = (bool)getEmailSetting('email_development_mode', 0);
$email_log_enabled = (bool)getEmailSetting('email_log_enabled', 0);
$email_preview_enabled = (bool)getEmailSetting('email_preview_enabled', 0);

// Check if we're on localhost
$is_localhost = isset($_SERVER['HTTP_HOST']) && (
    strpos($_SERVER['HTTP_HOST'], 'localhost') !== false || 
    strpos($_SERVER['HTTP_HOST'], '127.0.0.1') !== false ||
    strpos($_SERVER['HTTP_HOST'], '.local') !== false
);

// Development mode: show previews on localhost unless explicitly disabled
$development_mode = $is_localhost && $email_development_mode;

/**
 * Send email using PHPMailer
 * 
 * @param string $to Recipient email
 * @param string $toName Recipient name
 * @param string $subject Email subject
 * @param string $htmlBody HTML email body
 * @param string $textBody Plain text body (optional)
 * @return array Result array with success status and message
 */
function sendEmail($to, $toName, $subject, $htmlBody, $textBody = '') {
    global $email_from_name, $email_from_email, $email_admin_email;
    global $smtp_host, $smtp_port, $smtp_username, $smtp_password, $smtp_secure, $smtp_timeout, $smtp_debug;
    global $email_bcc_admin, $development_mode, $email_log_enabled, $email_preview_enabled;
    
    // If in development mode and no password or preview enabled, show preview
    if ($development_mode && (empty($smtp_password) || $email_preview_enabled)) {
        return createEmailPreview($to, $toName, $subject, $htmlBody, $textBody);
    }
    
    try {
        // Create PHPMailer instance
        $mail = new PHPMailer(true);
        
        // Server settings
        $mail->isSMTP();
        $mail->Host = $smtp_host;
        $mail->SMTPAuth = true;
        $mail->Username = $smtp_username;
        $mail->Password = $smtp_password;
        $mail->SMTPSecure = $smtp_secure;
        $mail->Port = $smtp_port;
        $mail->Timeout = $smtp_timeout;
        
        if ($smtp_debug > 0) {
            $mail->SMTPDebug = $smtp_debug;
        }
        
        // Recipients
        $mail->setFrom($smtp_username, $email_from_name);
        $mail->addAddress($to, $toName);
        $mail->addReplyTo($email_from_email, $email_from_name);
        
        // Add BCC for admin if enabled
        if ($email_bcc_admin && !empty($email_admin_email)) {
            $mail->addBCC($email_admin_email);
        }
        
        // Content
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body = $htmlBody;
        $mail->AltBody = $textBody ?: strip_tags($htmlBody);
        
        $mail->send();
        
        // Log email if enabled
        if ($email_log_enabled) {
            logEmail($to, $toName, $subject, 'sent');
        }
        
        return [
            'success' => true,
            'message' => 'Email sent successfully via SMTP'
        ];
        
    } catch (Exception $e) {
        error_log("PHPMailer Error: " . $e->getMessage());
        
        // Log error if enabled
        if ($email_log_enabled) {
            logEmail($to, $toName, $subject, 'failed', $e->getMessage());
        }
        
        // If development mode, show preview instead of failing
        if ($development_mode) {
            return createEmailPreview($to, $toName, $subject, $htmlBody, $textBody);
        }
        
        return [
            'success' => false,
            'message' => 'Failed to send email: ' . $e->getMessage()
        ];
    }
}

/**
 * Create email preview for development mode
 */
function createEmailPreview($to, $toName, $subject, $htmlBody, $textBody = '') {
    global $email_from_name, $email_from_email, $email_admin_email, $email_site_name, $email_site_url;
    global $email_log_enabled;
    
    // Log email if enabled
    if ($email_log_enabled) {
        logEmail($to, $toName, $subject, 'preview');
    }
    
    // Create email preview file
    $previewDir = __DIR__ . '/../logs/email-previews';
    if (!file_exists($previewDir)) {
        mkdir($previewDir, 0755, true);
    }
    
    $previewFile = $previewDir . '/' . date('Y-m-d-His') . '-' . preg_replace('/[^a-z0-9]/i', '-', strtolower($subject)) . '.html';
    $previewContent = '<!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <title>Email Preview: ' . htmlspecialchars($subject) . '</title>
        <style>
            body { font-family: Arial, sans-serif; padding: 20px; background: #f5f5f5; }
            .email-preview { max-width: 800px; margin: 0 auto; background: white; padding: 30px; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
            .email-info { background: #e3f2fd; padding: 15px; border-radius: 5px; margin-bottom: 20px; }
            .email-info h3 { margin-top: 0; color: #1565c0; }
            .email-info p { margin: 5px 0; }
            .email-content { border: 1px solid #ddd; padding: 20px; border-radius: 5px; }
            .dev-note { background: #fff3cd; padding: 10px; border-left: 4px solid #ffc107; margin-top: 20px; border-radius: 5px; }
        </style>
    </head>
    <body>
        <div class="email-preview">
            <div class="email-info">
                <h3>📧 Email Preview (Development Mode)</h3>
                <p><strong>From:</strong> ' . htmlspecialchars($email_from_name) . ' <' . htmlspecialchars($email_from_email) . '></p>
                <p><strong>To:</strong> ' . htmlspecialchars($toName) . ' <' . htmlspecialchars($to) . '></p>
                <p><strong>Subject:</strong> ' . htmlspecialchars($subject) . '</p>
                <p><strong>Time:</strong> ' . date('Y-m-d H:i:s') . '</p>
                <p><strong>Status:</strong> Preview only - email would be sent via SMTP in production</p>
            </div>
            <div class="email-content">' . $htmlBody . '</div>
            <div class="dev-note">
                <p><strong>💡 Development Note:</strong> This is a preview. In production, emails will be sent automatically using SMTP.</p>
            </div>
        </div>
    </body>
    </html>';
    
    file_put_contents($previewFile, $previewContent);
    
    return [
        'success' => true,
        'message' => 'Email preview created (development mode)',
        'preview_url' => str_replace(__DIR__ . '/../', '', $previewFile)
    ];
}

/**
 * Log email activity
 */
function logEmail($to, $toName, $subject, $status, $error = '') {
    $logDir = __DIR__ . '/../logs';
    if (!file_exists($logDir)) {
        mkdir($logDir, 0755, true);
    }
    
    $logFile = $logDir . '/email-log.txt';
    $logEntry = "[" . date('Y-m-d H:i:s') . "] [$status] $subject to $to ($toName)";
    if ($error) {
        $logEntry .= " - Error: $error";
    }
    $logEntry .= "\n";
    file_put_contents($logFile, $logEntry, FILE_APPEND);
}

/**
 * Log cancellation to database
 *
 * @param int $booking_id The booking ID
 * @param string $booking_reference The booking reference
 * @param string $booking_type Type of booking (room/conference)
 * @param string $guest_email Guest email address
 * @param int $cancelled_by Admin user ID who cancelled
 * @param string $cancellation_reason Reason for cancellation
 * @param bool $email_sent Whether email was sent successfully
 * @param string $email_status Email status message
 * @return bool Success status
 */
function logCancellationToDatabase($booking_id, $booking_reference, $booking_type, $guest_email, $cancelled_by, $cancellation_reason = '', $email_sent = false, $email_status = '') {
    global $pdo;
    
    try {
        $stmt = $pdo->prepare("
            INSERT INTO cancellation_log
            (booking_id, booking_reference, booking_type, guest_email, cancelled_by, cancellation_reason, email_sent, email_status)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $booking_id,
            $booking_reference,
            $booking_type,
            $guest_email,
            $cancelled_by,
            $cancellation_reason,
            $email_sent ? 1 : 0,
            $email_status
        ]);
        return true;
    } catch (Exception $e) {
        error_log("Failed to log cancellation to database: " . $e->getMessage());
        return false;
    }
}

/**
 * Log cancellation to file
 *
 * @param string $booking_reference The booking reference
 * @param string $booking_type Type of booking (room/conference)
 * @param string $guest_email Guest email address
 * @param string $cancelled_by_name Name of admin who cancelled
 * @param string $cancellation_reason Reason for cancellation
 * @param bool $email_sent Whether email was sent successfully
 * @param string $email_status Email status message
 * @return bool Success status
 */
function logCancellationToFile($booking_reference, $booking_type, $guest_email, $cancelled_by_name, $cancellation_reason = '', $email_sent = false, $email_status = '') {
    $logDir = __DIR__ . '/../logs';
    if (!file_exists($logDir)) {
        mkdir($logDir, 0755, true);
    }
    
    $logFile = $logDir . '/cancellations.log';
    $timestamp = date('Y-m-d H:i:s');
    $emailStatus = $email_sent ? 'SENT' : 'FAILED';
    
    $logEntry = "[$timestamp] CANCELLATION - Ref: $booking_reference | Type: $booking_type | Email: $guest_email | Cancelled by: $cancelled_by_name | Reason: $cancellation_reason | Email: $emailStatus ($email_status)\n";
    
    $result = file_put_contents($logFile, $logEntry, FILE_APPEND);
    return $result !== false;
}

/**
 * Send booking received email (sent immediately when user submits booking)
 */
function sendBookingReceivedEmail($booking) {
    global $pdo, $email_from_name, $email_from_email, $email_admin_email, $email_site_name, $email_site_url;
    
    try {
        // Get room details
        $stmt = $pdo->prepare("SELECT * FROM rooms WHERE id = ?");
        $stmt->execute([$booking['room_id']]);
        $room = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$room) {
            throw new Exception("Room not found");
        }
        
        // Prepare email content
        $htmlBody = '
        <h1 style="color: #0A1929; text-align: center;">Booking Received - Awaiting Confirmation</h1>
        <p>Dear ' . htmlspecialchars($booking['guest_name']) . ',</p>
        <p>Thank you for your booking request with <strong>' . htmlspecialchars($email_site_name) . '</strong>. We have received your reservation and it is currently being reviewed by our team.</p>
        
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
            
            <div style="display: flex; justify-content: space-between; padding: 10px 0; border-bottom: 1px solid #ddd;">
                <span style="font-weight: bold; color: #0A1929;">Number of Nights:</span>
                <span style="color: #333;">' . $booking['number_of_nights'] . ' night' . ($booking['number_of_nights'] != 1 ? 's' : '') . '</span>
            </div>
            
            <div style="display: flex; justify-content: space-between; padding: 10px 0; border-bottom: 1px solid #ddd;">
                <span style="font-weight: bold; color: #0A1929;">Number of Guests:</span>
                <span style="color: #333;">' . $booking['number_of_guests'] . ' guest' . ($booking['number_of_guests'] != 1 ? 's' : '') . '</span>
            </div>
            
            <div style="display: flex; justify-content: space-between; padding: 10px 0;">
                <span style="font-weight: bold; color: #0A1929;">Total Amount:</span>
                <span style="color: #D4AF37; font-weight: bold; font-size: 18px;">' . getSetting('currency_symbol') . ' ' . number_format($booking['total_amount'], 0) . '</span>
            </div>
        </div>
        
        <div style="background: #e7f3ff; padding: 15px; border-left: 4px solid #0d6efd; border-radius: 5px; margin: 20px 0;">
            <h3 style="color: #0d6efd; margin-top: 0;">What Happens Next?</h3>
            <p style="color: #0d6efd; margin: 0;">
                <strong>Our team will review your booking and contact you within 24 hours to confirm availability.</strong><br>
                Once confirmed, you will receive a second email with final confirmation.
            </p>
        </div>
        
        <div style="background: #fff3cd; padding: 15px; border-left: 4px solid #ffc107; border-radius: 5px; margin: 20px 0;">
            <h3 style="color: #856404; margin-top: 0;">Payment Information</h3>
            <p style="color: #856404; margin: 0;">
                ' . getSetting('payment_policy', 'Payment will be made at the hotel upon arrival.<br>We accept cash payments only. Please bring the total amount of <strong>' . getSetting('currency_symbol') . ' ' . number_format($booking['total_amount'], 0) . '</strong> with you.') . '
            </p>
        </div>';
        
        if (!empty($booking['special_requests'])) {
            $htmlBody .= '
            <div style="background: #e7f3ff; padding: 15px; border-left: 4px solid #0d6efd; border-radius: 5px; margin: 20px 0;">
                <h3 style="color: #0d6efd; margin-top: 0;">Special Requests</h3>
                <p style="color: #0d6efd; margin: 0;">' . htmlspecialchars($booking['special_requests']) . '</p>
            </div>';
        }
        
        $htmlBody .= '
        <p>If you have any questions, please contact us at <a href="mailto:' . htmlspecialchars($email_from_email) . '">' . htmlspecialchars($email_from_email) . '</a> or call ' . getSetting('phone_main') . '.</p>
        
        <p>Thank you for choosing ' . htmlspecialchars($email_site_name) . '!</p>
        
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
            'Booking Received - ' . htmlspecialchars($email_site_name) . ' [' . $booking['booking_reference'] . ']',
            $htmlBody
        );
        
    } catch (Exception $e) {
        error_log("Send Booking Received Email Error: " . $e->getMessage());
        return [
            'success' => false,
            'message' => $e->getMessage()
        ];
    }
}

/**
 * Send booking confirmed email (sent when admin approves booking)
 */
function sendBookingConfirmedEmail($booking) {
    global $pdo, $email_from_name, $email_from_email, $email_admin_email, $email_site_name, $email_site_url;
    
    try {
        // Get room details
        $stmt = $pdo->prepare("SELECT * FROM rooms WHERE id = ?");
        $stmt->execute([$booking['room_id']]);
        $room = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$room) {
            throw new Exception("Room not found");
        }
        
        // Prepare email content
        $htmlBody = '
        <h1 style="color: #0A1929; text-align: center;">Booking Confirmed!</h1>
        <p>Dear ' . htmlspecialchars($booking['guest_name']) . ',</p>
        <p>Great news! Your booking with <strong>' . htmlspecialchars($email_site_name) . '</strong> has been confirmed by our team.</p>
        
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
            
            <div style="display: flex; justify-content: space-between; padding: 10px 0; border-bottom: 1px solid #ddd;">
                <span style="font-weight: bold; color: #0A1929;">Number of Nights:</span>
                <span style="color: #333;">' . $booking['number_of_nights'] . ' night' . ($booking['number_of_nights'] != 1 ? 's' : '') . '</span>
            </div>
            
            <div style="display: flex; justify-content: space-between; padding: 10px 0; border-bottom: 1px solid #ddd;">
                <span style="font-weight: bold; color: #0A1929;">Number of Guests:</span>
                <span style="color: #333;">' . $booking['number_of_guests'] . ' guest' . ($booking['number_of_guests'] != 1 ? 's' : '') . '</span>
            </div>
            
            <div style="display: flex; justify-content: space-between; padding: 10px 0;">
                <span style="font-weight: bold; color: #0A1929;">Total Amount:</span>
                <span style="color: #D4AF37; font-weight: bold; font-size: 18px;">' . getSetting('currency_symbol') . ' ' . number_format($booking['total_amount'], 0) . '</span>
            </div>
        </div>
        
        <div style="background: #d4edda; padding: 15px; border-left: 4px solid #28a745; border-radius: 5px; margin: 20px 0;">
            <h3 style="color: #155724; margin-top: 0;">✅ Booking Status: Confirmed</h3>
            <p style="color: #155724; margin: 0;">
                <strong>Your booking is now confirmed and guaranteed!</strong><br>
                We look forward to welcoming you to ' . htmlspecialchars($email_site_name) . '.
            </p>
        </div>
        
        <div style="background: #fff3cd; padding: 15px; border-left: 4px solid #ffc107; border-radius: 5px; margin: 20px 0;">
            <h3 style="color: #856404; margin-top: 0;">Payment Information</h3>
            <p style="color: #856404; margin: 0;">
                ' . getSetting('payment_policy', 'Payment will be made at the hotel upon arrival.<br>We accept cash payments only. Please bring the total amount of <strong>' . getSetting('currency_symbol') . ' ' . number_format($booking['total_amount'], 0) . '</strong> with you.') . '
            </p>
        </div>
        
        <div style="background: #e7f3ff; padding: 15px; border-left: 4px solid #0d6efd; border-radius: 5px; margin: 20px 0;">
            <h3 style="color: #0d6efd; margin-top: 0;">Next Steps</h3>
            <p style="color: #0d6efd; margin: 0;">
                <strong>Please save your booking reference:</strong> ' . htmlspecialchars($booking['booking_reference']) . '<br>
                <strong>Check-in time:</strong> ' . getSetting('check_in_time', '2:00 PM') . '<br>
                <strong>Check-out time:</strong> ' . getSetting('check_out_time', '11:00 AM') . '<br>
                <strong>Contact us:</strong> If you need to make any changes, please contact us at least ' . getSetting('booking_change_policy', '48 hours') . ' before your arrival.
            </p>
        </div>';
        
        if (!empty($booking['special_requests'])) {
            $htmlBody .= '
            <div style="background: #e7f3ff; padding: 15px; border-left: 4px solid #0d6efd; border-radius: 5px; margin: 20px 0;">
                <h3 style="color: #0d6efd; margin-top: 0;">Special Requests</h3>
                <p style="color: #0d6efd; margin: 0;">' . htmlspecialchars($booking['special_requests']) . '</p>
            </div>';
        }
        
        $htmlBody .= '
        <p>If you have any questions, please contact us at <a href="mailto:' . htmlspecialchars($email_from_email) . '">' . htmlspecialchars($email_from_email) . '</a> or call ' . getSetting('phone_main') . '.</p>
        
        <p>We look forward to welcoming you to ' . htmlspecialchars($email_site_name) . '!</p>
        
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
            'Booking Confirmed - ' . htmlspecialchars($email_site_name) . ' [' . $booking['booking_reference'] . ']',
            $htmlBody
        );
        
    } catch (Exception $e) {
        error_log("Send Booking Confirmed Email Error: " . $e->getMessage());
        return [
            'success' => false,
            'message' => $e->getMessage()
        ];
    }
}

/**
 * Send booking modified email (sent when admin edits a booking)
 */
function sendBookingModifiedEmail($booking, $changes = []) {
    global $pdo, $email_from_name, $email_from_email, $email_admin_email, $email_site_name, $email_site_url;
    
    try {
        $stmt = $pdo->prepare("SELECT * FROM rooms WHERE id = ?");
        $stmt->execute([$booking['room_id']]);
        $room = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$room) {
            throw new Exception("Room not found");
        }
        
        $changesHtml = '';
        if (!empty($changes)) {
            $changesHtml = '
            <div style="background: #e3f2fd; padding: 15px; border-left: 4px solid #0d6efd; border-radius: 5px; margin: 20px 0;">
                <h3 style="color: #0d6efd; margin-top: 0;">What Changed</h3>
                <table style="width: 100%; border-collapse: collapse; font-size: 14px;">';
            
            $fieldLabels = [
                'room' => 'Room', 'check_in_date' => 'Check-in Date', 'check_out_date' => 'Check-out Date',
                'number_of_nights' => 'Number of Nights', 'number_of_guests' => 'Number of Guests',
                'occupancy_type' => 'Occupancy Type', 'total_amount' => 'Total Amount',
                'guest_name' => 'Guest Name', 'guest_email' => 'Email', 'guest_phone' => 'Phone', 'guest_country' => 'Country'
            ];
            
            foreach ($changes as $field => $change) {
                $label = $fieldLabels[$field] ?? ucfirst(str_replace('_', ' ', $field));
                $changesHtml .= '
                    <tr style="border-bottom: 1px solid #bbdefb;">
                        <td style="padding: 8px; font-weight: bold; color: #0d6efd; width: 40%;">' . htmlspecialchars($label) . '</td>
                        <td style="padding: 8px; color: #999; text-decoration: line-through;">' . htmlspecialchars($change['old']) . '</td>
                        <td style="padding: 8px; color: #155724; font-weight: 600;">' . htmlspecialchars($change['new']) . '</td>
                    </tr>';
            }
            $changesHtml .= '</table></div>';
        }
        
        $htmlBody = '
        <h1 style="color: #0A1929; text-align: center;">Booking Updated</h1>
        <p>Dear ' . htmlspecialchars($booking['guest_name']) . ',</p>
        <p>Your booking with <strong>' . htmlspecialchars($email_site_name) . '</strong> has been updated by our team. Please review the details below.</p>
        ' . $changesHtml . '
        <div style="background: #f8f9fa; border: 2px solid #0A1929; padding: 20px; margin: 20px 0; border-radius: 10px;">
            <h2 style="color: #0A1929; margin-top: 0;">Updated Booking Details</h2>
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
            <div style="display: flex; justify-content: space-between; padding: 10px 0; border-bottom: 1px solid #ddd;">
                <span style="font-weight: bold; color: #0A1929;">Number of Nights:</span>
                <span style="color: #333;">' . $booking['number_of_nights'] . ' night' . ($booking['number_of_nights'] != 1 ? 's' : '') . '</span>
            </div>
            <div style="display: flex; justify-content: space-between; padding: 10px 0; border-bottom: 1px solid #ddd;">
                <span style="font-weight: bold; color: #0A1929;">Number of Guests:</span>
                <span style="color: #333;">' . $booking['number_of_guests'] . ' guest' . ($booking['number_of_guests'] != 1 ? 's' : '') . '</span>
            </div>
            <div style="display: flex; justify-content: space-between; padding: 10px 0;">
                <span style="font-weight: bold; color: #0A1929;">Total Amount:</span>
                <span style="color: #D4AF37; font-weight: bold; font-size: 18px;">' . getSetting('currency_symbol') . ' ' . number_format($booking['total_amount'], 0) . '</span>
            </div>
        </div>
        <div style="background: #d4edda; padding: 15px; border-left: 4px solid #28a745; border-radius: 5px; margin: 20px 0;">
            <h3 style="color: #155724; margin-top: 0;">Next Steps</h3>
            <p style="color: #155724; margin: 0;">
                <strong>Check-in time:</strong> ' . getSetting('check_in_time', '2:00 PM') . '<br>
                <strong>Check-out time:</strong> ' . getSetting('check_out_time', '11:00 AM') . '<br>
                <strong>Contact us:</strong> If you have questions about these changes, please reach out.
            </p>
        </div>
        <p>If you have any questions, please contact us at <a href="mailto:' . htmlspecialchars($email_from_email) . '">' . htmlspecialchars($email_from_email) . '</a> or call ' . getSetting('phone_main') . '.</p>
        <p>Thank you for choosing ' . htmlspecialchars($email_site_name) . '!</p>
        <div style="text-align: center; margin-top: 30px; padding-top: 20px; border-top: 2px solid #0A1929;">
            <p style="color: #666; font-size: 14px;">
                <strong>The ' . htmlspecialchars($email_site_name) . ' Team</strong><br>
                <a href="' . htmlspecialchars($email_site_url) . '">' . htmlspecialchars($email_site_url) . '</a>
            </p>
        </div>';
        
        return sendEmail(
            $booking['guest_email'],
            $booking['guest_name'],
            'Booking Updated - ' . htmlspecialchars($email_site_name) . ' [' . $booking['booking_reference'] . ']',
            $htmlBody
        );
    } catch (Exception $e) {
        error_log("Send Booking Modified Email Error: " . $e->getMessage());
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

/**
 * Send admin notification email
 */
function sendAdminNotificationEmail($booking) {
    global $email_from_name, $email_from_email, $email_admin_email, $email_site_name, $email_site_url;
    
    try {
        $htmlBody = '
        <h1 style="color: #0A1929; text-align: center;">📋 New Booking Received</h1>
        <p>A new booking has been made on the website.</p>
        
        <div style="background: #f8f9fa; border: 2px solid #0A1929; padding: 20px; margin: 20px 0; border-radius: 10px;">
            <h2 style="color: #0A1929; margin-top: 0;">Booking Details</h2>
            
            <div style=\'display: flex; justify-content: space-between; padding: 10px 0; border-bottom: 1px solid #ddd;\'>
                <span style="font-weight: bold; color: #0A1929;">Booking Reference:</span>
                <span style="color: #D4AF37; font-weight: bold;">' . htmlspecialchars($booking['booking_reference']) . '</span>
            </div>
            
            <div style=\'display: flex; justify-content: space-between; padding: 10px 0; border-bottom: 1px solid #ddd;\'>
                <span style="font-weight: bold; color: #0A1929;">Guest Name:</span>
                <span style="color: #333;">' . htmlspecialchars($booking['guest_name']) . '</span>
            </div>
            
            <div style=\'display: flex; justify-content: space-between; padding: 10px 0; border-bottom: 1px solid #ddd;\'>
                <span style="font-weight: bold; color: #0A1929;">Guest Email:</span>
                <span style="color: #333;">' . htmlspecialchars($booking['guest_email']) . '</span>
            </div>
            
            <div style=\'display: flex; justify-content: space-between; padding: 10px 0; border-bottom: 1px solid #ddd;\'>
                <span style="font-weight: bold; color: #0A1929;">Guest Phone:</span>
                <span style="color: #333;">' . htmlspecialchars($booking['guest_phone']) . '</span>
            </div>
            
            <div style=\'display: flex; justify-content: space-between; padding: 10px 0; border-bottom: 1px solid #ddd;\'>
                <span style="font-weight: bold; color: #0A1929;">Check-in Date:</span>
                <span style="color: #333;">' . date('F j, Y', strtotime($booking['check_in_date'])) . '</span>
            </div>
            
            <div style=\'display: flex; justify-content: space-between; padding: 10px 0; border-bottom: 1px solid #ddd;\'>
                <span style="font-weight: bold; color: #0A1929;">Check-out Date:</span>
                <span style="color: #333;">' . date('F j, Y', strtotime($booking['check_out_date'])) . '</span>
            </div>
            
            <div style=\'display: flex; justify-content: space-between; padding: 10px 0;\'>
                <span style="font-weight: bold; color: #0A1929;">Total Amount:</span>
                <span style="color: #D4AF37; font-weight: bold;">' . getSetting('currency_symbol') . ' ' . number_format($booking['total_amount'], 0) . '</span>
            </div>
        </div>
        
        <div style="text-align: center; margin-top: 30px;">
            <a href="' . htmlspecialchars($email_site_url) . '/admin/bookings.php" style="display: inline-block; background: #D4AF37; color: #0A1929; padding: 12px 24px; text-decoration: none; border-radius: 5px; font-weight: bold; font-size: 16px;">
                View Booking in Admin Panel
            </a>
        </div>';
        
        // Send email
        return sendEmail(
            $email_admin_email,
            'Reservations Team',
            'New Booking - ' . htmlspecialchars($email_site_name) . ' [' . $booking['booking_reference'] . ']',
            $htmlBody
        );
        
    } catch (Exception $e) {
        error_log("Send Admin Notification Error: " . $e->getMessage());
        return [
            'success' => false,
            'message' => $e->getMessage()
        ];
    }
}

/**
 * Send conference enquiry email (sent when customer submits enquiry)
 */
function sendConferenceEnquiryEmail($data) {
    global $pdo, $email_from_name, $email_from_email, $email_admin_email, $email_site_name, $email_site_url;
    
    try {
        // Get conference room details
        $stmt = $pdo->prepare("SELECT * FROM conference_rooms WHERE id = ?");
        $stmt->execute([$data['conference_room_id']]);
        $room = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$room) {
            throw new Exception("Conference room not found");
        }
        
        $currency_symbol = getSetting('currency_symbol');
        $total_amount = $data['total_amount'] ? number_format($data['total_amount'], 0) : 'To be determined';
        
        // Prepare template data
        $template_data = [
            'contact_person' => $data['contact_person'],
            'inquiry_reference' => $data['inquiry_reference'],
            'company_name' => $data['company_name'],
            'room_name' => $room['name'],
            'event_date' => date('F j, Y', strtotime($data['event_date'])),
            'start_time' => date('H:i', strtotime($data['start_time'])),
            'end_time' => date('H:i', strtotime($data['end_time'])),
            'number_of_attendees' => (int)$data['number_of_attendees'],
            'total_amount' => $total_amount,
            'event_type' => $data['event_type'] ?? '',
            'catering_required' => $data['catering_required'] ?? false,
            'av_equipment' => $data['av_equipment'] ?? '',
            'special_requirements' => $data['special_requirements'] ?? ''
        ];
        
        // Try to load template, fall back to hardcoded HTML if template not found
        $htmlBody = loadEmailTemplate('conference-enquiry-customer.html', $template_data);
        
        if (empty($htmlBody)) {
            // Fallback to hardcoded HTML if template fails
            $htmlBody = '
            <h1 style="color: #0A1929; text-align: center;">Conference Enquiry Received</h1>
            <p>Dear ' . htmlspecialchars($data['contact_person']) . ',</p>
            <p>Thank you for your conference enquiry with <strong>' . htmlspecialchars($email_site_name) . '</strong>. We have received your request and it is currently being reviewed by our team.</p>
            
            <div style="background: #f8f9fa; border: 2px solid #0A1929; padding: 20px; margin: 20px 0; border-radius: 10px;">
                <h2 style="color: #0A1929; margin-top: 0;">Enquiry Details</h2>
                
                <div style="display: flex; justify-content: space-between; padding: 10px 0; border-bottom: 1px solid #ddd;">
                    <span style="font-weight: bold; color: #0A1929;">Enquiry Reference:</span>
                    <span style="color: #D4AF37; font-weight: bold; font-size: 18px;">' . htmlspecialchars($data['inquiry_reference']) . '</span>
                </div>
                
                <div style="display: flex; justify-content: space-between; padding: 10px 0; border-bottom: 1px solid #ddd;">
                    <span style="font-weight: bold; color: #0A1929;">Company:</span>
                    <span style="color: #333;">' . htmlspecialchars($data['company_name']) . '</span>
                </div>
                
                <div style="display: flex; justify-content: space-between; padding: 10px 0; border-bottom: 1px solid #ddd;">
                    <span style="font-weight: bold; color: #0A1929;">Conference Room:</span>
                    <span style="color: #333;">' . htmlspecialchars($room['name']) . '</span>
                </div>
                
                <div style="display: flex; justify-content: space-between; padding: 10px 0; border-bottom: 1px solid #ddd;">
                    <span style="font-weight: bold; color: #0A1929;">Event Date:</span>
                    <span style="color: #333;">' . date('F j, Y', strtotime($data['event_date'])) . '</span>
                </div>
                
                <div style="display: flex; justify-content: space-between; padding: 10px 0; border-bottom: 1px solid #ddd;">
                    <span style="font-weight: bold; color: #0A1929;">Event Time:</span>
                    <span style="color: #333;">' . date('H:i', strtotime($data['start_time'])) . ' - ' . date('H:i', strtotime($data['end_time'])) . '</span>
                </div>
                
                <div style="display: flex; justify-content: space-between; padding: 10px 0; border-bottom: 1px solid #ddd;">
                    <span style="font-weight: bold; color: #0A1929;">Number of Attendees:</span>
                    <span style="color: #333;">' . (int) $data['number_of_attendees'] . '</span>
                </div>
                
                <div style="display: flex; justify-content: space-between; padding: 10px 0;">
                    <span style="font-weight: bold; color: #0A1929;">Estimated Amount:</span>
                    <span style="color: #D4AF37; font-weight: bold; font-size: 18px;">' . $currency_symbol . ' ' . $total_amount . '</span>
                </div>
            </div>
            
            <div style="background: #e7f3ff; padding: 15px; border-left: 4px solid #0d6efd; border-radius: 5px; margin: 20px 0;">
                <h3 style="color: #0d6efd; margin-top: 0;">What Happens Next?</h3>
                <p style="color: #0d6efd; margin: 0;">
                    <strong>Our team will review your enquiry and contact you within 24 hours to confirm availability and finalize details.</strong><br>
                    Once confirmed, you will receive a second email with final confirmation and payment instructions.
                </p>
            </div>';
            
            if (!empty($data['event_type'])) {
                $htmlBody .= '
                <div style="background: #e7f3ff; padding: 15px; border-left: 4px solid #0d6efd; border-radius: 5px; margin: 20px 0;">
                    <h3 style="color: #0d6efd; margin-top: 0;">Event Type</h3>
                    <p style="color: #0d6efd; margin: 0;">' . htmlspecialchars($data['event_type']) . '</p>
                </div>';
            }
            
            if ($data['catering_required']) {
                $htmlBody .= '
                <div style="background: #e7f3ff; padding: 15px; border-left: 4px solid #0d6efd; border-radius: 5px; margin: 20px 0;">
                    <h3 style="color: #0d6efd; margin-top: 0;">Catering</h3>
                    <p style="color: #0d6efd; margin: 0;">Catering services have been requested for your event.</p>
                </div>';
            }
            
            if (!empty($data['av_equipment'])) {
                $htmlBody .= '
                <div style="background: #e7f3ff; padding: 15px; border-left: 4px solid #0d6efd; border-radius: 5px; margin: 20px 0;">
                    <h3 style="color: #0d6efd; margin-top: 0;">AV Equipment Requirements</h3>
                    <p style="color: #0d6efd; margin: 0;">' . htmlspecialchars($data['av_equipment']) . '</p>
                </div>';
            }
            
            if (!empty($data['special_requirements'])) {
                $htmlBody .= '
                <div style="background: #e7f3ff; padding: 15px; border-left: 4px solid #0d6efd; border-radius: 5px; margin: 20px 0;">
                    <h3 style="color: #0d6efd; margin-top: 0;">Special Requirements</h3>
                    <p style="color: #0d6efd; margin: 0;">' . htmlspecialchars($data['special_requirements']) . '</p>
                </div>';
            }
            
            $htmlBody .= '
            <p>If you have any questions, please contact us at <a href="mailto:' . htmlspecialchars($email_from_email) . '">' . htmlspecialchars($email_from_email) . '</a> or call ' . getSetting('phone_main') . '.</p>
            
            <p>Thank you for considering ' . htmlspecialchars($email_site_name) . ' for your event!</p>
            
            <div style="text-align: center; margin-top: 30px; padding-top: 20px; border-top: 2px solid #0A1929;">
                <p style="color: #666; font-size: 14px;">
                    <strong>The ' . htmlspecialchars($email_site_name) . ' Team</strong><br>
                    <a href="' . htmlspecialchars($email_site_url) . '">' . htmlspecialchars($email_site_url) . '</a>
                </p>
            </div>';
        }
        
        // Send email
        return sendEmail(
            $data['email'],
            $data['contact_person'],
            'Conference Enquiry Received - ' . htmlspecialchars($email_site_name) . ' [' . $data['inquiry_reference'] . ']',
            $htmlBody
        );
        
    } catch (Exception $e) {
        error_log("Send Conference Enquiry Email Error: " . $e->getMessage());
        return [
            'success' => false,
            'message' => $e->getMessage()
        ];
    }
}

/**
 * Send admin notification for conference enquiry
 */
function sendConferenceAdminNotificationEmail($data) {
    global $pdo, $email_from_name, $email_from_email, $email_admin_email, $email_site_name, $email_site_url;
    
    try {
        // Get conference room details
        $stmt = $pdo->prepare("SELECT * FROM conference_rooms WHERE id = ?");
        $stmt->execute([$data['conference_room_id']]);
        $room = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $currency_symbol = getSetting('currency_symbol');
        $total_amount = $data['total_amount'] ? number_format($data['total_amount'], 0) : 'To be determined';
        
        // Get conference email with fallback to main contact email
        $conference_email = getSetting('conference_email');
        if (empty($conference_email) || !filter_var($conference_email, FILTER_VALIDATE_EMAIL)) {
            $conference_email = getSetting('email_main');
        }
        if (empty($conference_email) || !filter_var($conference_email, FILTER_VALIDATE_EMAIL)) {
            $conference_email = $email_admin_email;
        }
        
        // Prepare template data
        $template_data = [
            'inquiry_reference' => $data['inquiry_reference'],
            'company_name' => $data['company_name'],
            'contact_person' => $data['contact_person'],
            'email' => $data['email'],
            'phone' => $data['phone'],
            'room_name' => $room['name'],
            'event_date' => date('F j, Y', strtotime($data['event_date'])),
            'start_time' => date('H:i', strtotime($data['start_time'])),
            'end_time' => date('H:i', strtotime($data['end_time'])),
            'number_of_attendees' => (int)$data['number_of_attendees'],
            'event_type' => $data['event_type'] ?: 'Not specified',
            'total_amount' => $total_amount,
            'catering_required' => $data['catering_required'] ?? false,
            'av_equipment' => $data['av_equipment'] ?? '',
            'special_requirements' => $data['special_requirements'] ?? ''
        ];
        
        // Try to load template, fall back to hardcoded HTML if template not found
        $htmlBody = loadEmailTemplate('conference-enquiry-admin.html', $template_data);
        
        if (empty($htmlBody)) {
            // Fallback to hardcoded HTML if template fails
            $htmlBody = '
            <h1 style="color: #0A1929; text-align: center;">📋 New Conference Enquiry Received</h1>
            <p>A new conference enquiry has been submitted on the website.</p>
            
            <div style="background: #f8f9fa; border: 2px solid #0A1929; padding: 20px; margin: 20px 0; border-radius: 10px;">
                <h2 style="color: #0A1929; margin-top: 0;">Enquiry Details</h2>
                
                <div style="display: flex; justify-content: space-between; padding: 10px 0; border-bottom: 1px solid #ddd;">
                    <span style="font-weight: bold; color: #0A1929;">Enquiry Reference:</span>
                    <span style="color: #D4AF37; font-weight: bold;">' . htmlspecialchars($data['inquiry_reference']) . '</span>
                </div>
                
                <div style="display: flex; justify-content: space-between; padding: 10px 0; border-bottom: 1px solid #ddd;">
                    <span style="font-weight: bold; color: #0A1929;">Company:</span>
                    <span style="color: #333;">' . htmlspecialchars($data['company_name']) . '</span>
                </div>
                
                <div style="display: flex; justify-content: space-between; padding: 10px 0; border-bottom: 1px solid #ddd;">
                    <span style="font-weight: bold; color: #0A1929;">Contact Person:</span>
                    <span style="color: #333;">' . htmlspecialchars($data['contact_person']) . '</span>
                </div>
                
                <div style="display: flex; justify-content: space-between; padding: 10px 0; border-bottom: 1px solid #ddd;">
                    <span style="font-weight: bold; color: #0A1929;">Email:</span>
                    <span style="color: #333;">' . htmlspecialchars($data['email']) . '</span>
                </div>
                
                <div style="display: flex; justify-content: space-between; padding: 10px 0; border-bottom: 1px solid #ddd;">
                    <span style="font-weight: bold; color: #0A1929;">Phone:</span>
                    <span style="color: #333;">' . htmlspecialchars($data['phone']) . '</span>
                </div>
                
                <div style="display: flex; justify-content: space-between; padding: 10px 0; border-bottom: 1px solid #ddd;">
                    <span style="font-weight: bold; color: #0A1929;">Conference Room:</span>
                    <span style="color: #333;">' . htmlspecialchars($room['name']) . '</span>
                </div>
                
                <div style="display: flex; justify-content: space-between; padding: 10px 0; border-bottom: 1px solid #ddd;">
                    <span style="font-weight: bold; color: #0A1929;">Event Date:</span>
                    <span style="color: #333;">' . date('F j, Y', strtotime($data['event_date'])) . '</span>
                </div>
                
                <div style="display: flex; justify-content: space-between; padding: 10px 0; border-bottom: 1px solid #ddd;">
                    <span style="font-weight: bold; color: #0A1929;">Event Time:</span>
                    <span style="color: #333;">' . date('H:i', strtotime($data['start_time'])) . ' - ' . date('H:i', strtotime($data['end_time'])) . '</span>
                </div>
                
                <div style="display: flex; justify-content: space-between; padding: 10px 0; border-bottom: 1px solid #ddd;">
                    <span style="font-weight: bold; color: #0A1929;">Number of Attendees:</span>
                    <span style="color: #333;">' . (int) $data['number_of_attendees'] . '</span>
                </div>
                
                <div style="display: flex; justify-content: space-between; padding: 10px 0; border-bottom: 1px solid #ddd;">
                    <span style="font-weight: bold; color: #0A1929;">Event Type:</span>
                    <span style="color: #333;">' . htmlspecialchars($data['event_type'] ?: 'Not specified') . '</span>
                </div>
                
                <div style="display: flex; justify-content: space-between; padding: 10px 0;">
                    <span style="font-weight: bold; color: #0A1929;">Estimated Amount:</span>
                    <span style="color: #D4AF37; font-weight: bold;">' . $currency_symbol . ' ' . $total_amount . '</span>
                </div>
            </div>';
            
            if ($data['catering_required']) {
                $htmlBody .= '
                <div style="background: #e7f3ff; padding: 15px; border-left: 4px solid #0d6efd; border-radius: 5px; margin: 20px 0;">
                    <h3 style="color: #0d6efd; margin-top: 0;">Catering Required</h3>
                    <p style="color: #0d6efd; margin: 0;">Yes - catering services requested</p>
                </div>';
            }
            
            if (!empty($data['av_equipment'])) {
                $htmlBody .= '
                <div style="background: #e7f3ff; padding: 15px; border-left: 4px solid #0d6efd; border-radius: 5px; margin: 20px 0;">
                    <h3 style="color: #0d6efd; margin-top: 0;">AV Equipment Requirements</h3>
                    <p style="color: #0d6efd; margin: 0;">' . htmlspecialchars($data['av_equipment']) . '</p>
                </div>';
            }
            
            if (!empty($data['special_requirements'])) {
                $htmlBody .= '
                <div style="background: #e7f3ff; padding: 15px; border-left: 4px solid #0d6efd; border-radius: 5px; margin: 20px 0;">
                    <h3 style="color: #0d6efd; margin-top: 0;">Special Requirements</h3>
                    <p style="color: #0d6efd; margin: 0;">' . htmlspecialchars($data['special_requirements']) . '</p>
                </div>';
            }
            
            $htmlBody .= '
            <div style="text-align: center; margin-top: 30px;">
                <a href="' . htmlspecialchars($email_site_url) . '/admin/conference-management.php" style="display: inline-block; background: #D4AF37; color: #0A1929; padding: 12px 24px; text-decoration: none; border-radius: 5px; font-weight: bold; font-size: 16px;">
                    View Enquiry in Admin Panel
                </a>
            </div>';
        }
        
        // Send email
        return sendEmail(
            $conference_email,
            'Conference Team',
            'New Conference Enquiry - ' . htmlspecialchars($email_site_name) . ' [' . $data['inquiry_reference'] . ']',
            $htmlBody
        );
        
    } catch (Exception $e) {
        error_log("Send Conference Admin Notification Error: " . $e->getMessage());
        return [
            'success' => false,
            'message' => $e->getMessage()
        ];
    }
}

/**
 * Send conference payment confirmation email
 */
function sendConferencePaymentEmail($data) {
    global $pdo, $email_from_name, $email_from_email, $email_admin_email, $email_site_name, $email_site_url;
    
    try {
        // Get conference room details
        $stmt = $pdo->prepare("SELECT * FROM conference_rooms WHERE id = ?");
        $stmt->execute([$data['conference_room_id']]);
        $room = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$room) {
            throw new Exception("Conference room not found");
        }
        
        $currency_symbol = getSetting('currency_symbol');
        $total_amount = number_format($data['total_amount'], 0);
        $payment_amount = number_format($data['payment_amount'], 0);
        $payment_date = date('F j, Y', strtotime($data['payment_date']));
        
        // Prepare email content
        $htmlBody = '
        <h1 style="color: #0A1929; text-align: center;">Payment Confirmation</h1>
        <p>Dear ' . htmlspecialchars($data['contact_person']) . ',</p>
        <p>We are pleased to confirm that we have received your payment for the conference booking at <strong>' . htmlspecialchars($email_site_name) . '</strong>.</p>
        
        <div style="background: #d4edda; padding: 15px; border-left: 4px solid #28a745; border-radius: 5px; margin: 20px 0;">
            <h3 style="color: #155724; margin-top: 0;">✅ Payment Received</h3>
            <p style="color: #155724; margin: 0;">
                <strong>Payment Date:</strong> ' . $payment_date . '<br>
                <strong>Amount Paid:</strong> ' . $currency_symbol . ' ' . $payment_amount . '<br>
                <strong>Payment Method:</strong> ' . htmlspecialchars($data['payment_method'] ?: 'Cash') . '<br>
                <strong>Transaction Reference:</strong> ' . htmlspecialchars($data['payment_reference'] ?: $data['inquiry_reference']) . '
            </p>
        </div>
        
        <div style="background: #f8f9fa; border: 2px solid #0A1929; padding: 20px; margin: 20px 0; border-radius: 10px;">
            <h2 style="color: #0A1929; margin-top: 0;">Final Booking Summary</h2>
            
            <div style="display: flex; justify-content: space-between; padding: 10px 0; border-bottom: 1px solid #ddd;">
                <span style="font-weight: bold; color: #0A1929;">Booking Reference:</span>
                <span style="color: #D4AF37; font-weight: bold; font-size: 18px;">' . htmlspecialchars($data['inquiry_reference']) . '</span>
            </div>
            
            <div style="display: flex; justify-content: space-between; padding: 10px 0; border-bottom: 1px solid #ddd;">
                <span style="font-weight: bold; color: #0A1929;">Company:</span>
                <span style="color: #333;">' . htmlspecialchars($data['company_name']) . '</span>
            </div>
            
            <div style="display: flex; justify-content: space-between; padding: 10px 0; border-bottom: 1px solid #ddd;">
                <span style="font-weight: bold; color: #0A1929;">Conference Room:</span>
                <span style="color: #333;">' . htmlspecialchars($room['name']) . '</span>
            </div>
            
            <div style="display: flex; justify-content: space-between; padding: 10px 0; border-bottom: 1px solid #ddd;">
                <span style="font-weight: bold; color: #0A1929;">Event Date:</span>
                <span style="color: #333;">' . date('F j, Y', strtotime($data['event_date'])) . '</span>
            </div>
            
            <div style="display: flex; justify-content: space-between; padding: 10px 0; border-bottom: 1px solid #ddd;">
                <span style="font-weight: bold; color: #0A1929;">Event Time:</span>
                <span style="color: #333;">' . date('H:i', strtotime($data['start_time'])) . ' - ' . date('H:i', strtotime($data['end_time'])) . '</span>
            </div>
            
            <div style="display: flex; justify-content: space-between; padding: 10px 0; border-bottom: 1px solid #ddd;">
                <span style="font-weight: bold; color: #0A1929;">Number of Attendees:</span>
                <span style="color: #333;">' . (int) $data['number_of_attendees'] . '</span>
            </div>
            
            <div style="display: flex; justify-content: space-between; padding: 10px 0;">
                <span style="font-weight: bold; color: #0A1929;">Total Amount:</span>
                <span style="color: #D4AF37; font-weight: bold; font-size: 18px;">' . $currency_symbol . ' ' . $total_amount . '</span>
            </div>
        </div>
        
        <div style="background: #d4edda; padding: 15px; border-left: 4px solid #28a745; border-radius: 5px; margin: 20px 0;">
            <h3 style="color: #155724; margin-top: 0;">✅ Booking Status: Fully Paid</h3>
            <p style="color: #155724; margin: 0;">
                <strong>Your conference booking is now fully paid and confirmed!</strong><br>
                We look forward to hosting your event at ' . htmlspecialchars($email_site_name) . '.
            </p>
        </div>';
        
        if ($data['catering_required']) {
            $htmlBody .= '
            <div style="background: #e7f3ff; padding: 15px; border-left: 4px solid #0d6efd; border-radius: 5px; margin: 20px 0;">
                <h3 style="color: #0d6efd; margin-top: 0;">Catering</h3>
                <p style="color: #0d6efd; margin: 0;">Catering services have been confirmed for your event.</p>
            </div>';
        }
        
        if (!empty($data['av_equipment'])) {
            $htmlBody .= '
            <div style="background: #e7f3ff; padding: 15px; border-left: 4px solid #0d6efd; border-radius: 5px; margin: 20px 0;">
                <h3 style="color: #0d6efd; margin-top: 0;">AV Equipment</h3>
                <p style="color: #0d6efd; margin: 0;">' . htmlspecialchars($data['av_equipment']) . '</p>
            </div>';
        }
        
        if (!empty($data['special_requirements'])) {
            $htmlBody .= '
            <div style="background: #e7f3ff; padding: 15px; border-left: 4px solid #0d6efd; border-radius: 5px; margin: 20px 0;">
                <h3 style="color: #0d6efd; margin-top: 0;">Special Requirements</h3>
                <p style="color: #0d6efd; margin: 0;">' . htmlspecialchars($data['special_requirements']) . '</p>
            </div>';
        }
        
        $htmlBody .= '
        <div style="background: #e7f3ff; padding: 15px; border-left: 4px solid #0d6efd; border-radius: 5px; margin: 20px 0;">
            <h3 style="color: #0d6efd; margin-top: 0;">Next Steps</h3>
            <p style="color: #0d6efd; margin: 0;">
                <strong>Please save your booking reference:</strong> ' . htmlspecialchars($data['inquiry_reference']) . '<br>
                <strong>Arrival:</strong> Please arrive at least 30 minutes before your event start time<br>
                <strong>Contact us:</strong> If you need to make any changes, please contact us at least ' . getSetting('booking_change_policy', '48 hours') . ' before your event.
            </p>
        </div>
        
        <p>If you have any questions, please contact us at <a href="mailto:' . htmlspecialchars($email_from_email) . '">' . htmlspecialchars($email_from_email) . '</a> or call ' . getSetting('phone_main') . '.</p>
        
        <p>Thank you for your payment! We look forward to hosting your event at ' . htmlspecialchars($email_site_name) . '.</p>
        
        <div style="text-align: center; margin-top: 30px; padding-top: 20px; border-top: 2px solid #0A1929;">
            <p style="color: #666; font-size: 14px;">
                <strong>The ' . htmlspecialchars($email_site_name) . ' Team</strong><br>
                <a href="' . htmlspecialchars($email_site_url) . '">' . htmlspecialchars($email_site_url) . '</a>
            </p>
        </div>';
        
        // Send email
        return sendEmail(
            $data['email'],
            $data['contact_person'],
            'Payment Confirmation - ' . htmlspecialchars($email_site_name) . ' [' . $data['inquiry_reference'] . ']',
            $htmlBody
        );
        
    } catch (Exception $e) {
        error_log("Send Conference Payment Email Error: " . $e->getMessage());
        return [
            'success' => false,
            'message' => $e->getMessage()
        ];
    }
}

/**
 * Send conference enquiry confirmed email
 */
function sendConferenceConfirmedEmail($enquiry) {
        global $pdo, $email_from_name, $email_from_email, $email_admin_email, $email_site_name, $email_site_url;
        
        try {
            // Get conference room details
            $stmt = $pdo->prepare("SELECT * FROM conference_rooms WHERE id = ?");
            $stmt->execute([$enquiry['conference_room_id']]);
            $room = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$room) {
                throw new Exception("Conference room not found");
            }
            
            $currency_symbol = getSetting('currency_symbol');
            $total_amount = $enquiry['total_amount'] ? number_format($enquiry['total_amount'], 0) : 'To be determined';
            
            // Prepare email content
            $htmlBody = '
            <h1 style="color: #0A1929; text-align: center;">Conference Booking Confirmed!</h1>
            <p>Dear ' . htmlspecialchars($enquiry['contact_person']) . ',</p>
            <p>Great news! Your conference booking with <strong>' . htmlspecialchars($email_site_name) . '</strong> has been confirmed by our team.</p>
            
            <div style="background: #f8f9fa; border: 2px solid #0A1929; padding: 20px; margin: 20px 0; border-radius: 10px;">
                <h2 style="color: #0A1929; margin-top: 0;">Conference Details</h2>
                
                <div style="display: flex; justify-content: space-between; padding: 10px 0; border-bottom: 1px solid #ddd;">
                    <span style="font-weight: bold; color: #0A1929;">Reference:</span>
                    <span style="color: #D4AF37; font-weight: bold; font-size: 18px;">' . htmlspecialchars($enquiry['inquiry_reference']) . '</span>
                </div>
                
                <div style="display: flex; justify-content: space-between; padding: 10px 0; border-bottom: 1px solid #ddd;">
                    <span style="font-weight: bold; color: #0A1929;">Company:</span>
                    <span style="color: #333;">' . htmlspecialchars($enquiry['company_name']) . '</span>
                </div>
                
                <div style="display: flex; justify-content: space-between; padding: 10px 0; border-bottom: 1px solid #ddd;">
                    <span style="font-weight: bold; color: #0A1929;">Conference Room:</span>
                    <span style="color: #333;">' . htmlspecialchars($room['name']) . '</span>
                </div>
                
                <div style="display: flex; justify-content: space-between; padding: 10px 0; border-bottom: 1px solid #ddd;">
                    <span style="font-weight: bold; color: #0A1929;">Event Date:</span>
                    <span style="color: #333;">' . date('F j, Y', strtotime($enquiry['event_date'])) . '</span>
                </div>
                
                <div style="display: flex; justify-content: space-between; padding: 10px 0; border-bottom: 1px solid #ddd;">
                    <span style="font-weight: bold; color: #0A1929;">Event Time:</span>
                    <span style="color: #333;">' . date('H:i', strtotime($enquiry['start_time'])) . ' - ' . date('H:i', strtotime($enquiry['end_time'])) . '</span>
                </div>
                
                <div style="display: flex; justify-content: space-between; padding: 10px 0; border-bottom: 1px solid #ddd;">
                    <span style="font-weight: bold; color: #0A1929;">Number of Attendees:</span>
                    <span style="color: #333;">' . (int) $enquiry['number_of_attendees'] . '</span>
                </div>
                
                <div style="display: flex; justify-content: space-between; padding: 10px 0;">
                    <span style="font-weight: bold; color: #0A1929;">Total Amount:</span>
                    <span style="color: #D4AF37; font-weight: bold; font-size: 18px;">' . $currency_symbol . ' ' . $total_amount . '</span>
                </div>
            </div>
            
            <div style="background: #d4edda; padding: 15px; border-left: 4px solid #28a745; border-radius: 5px; margin: 20px 0;">
                <h3 style="color: #155724; margin-top: 0;">✅ Booking Status: Confirmed</h3>
                <p style="color: #155724; margin: 0;">
                    <strong>Your conference booking is now confirmed!</strong><br>
                    We look forward to hosting your event at ' . htmlspecialchars($email_site_name) . '.
                </p>
            </div>';
            
            if ($enquiry['catering_required']) {
                $htmlBody .= '
                <div style="background: #e7f3ff; padding: 15px; border-left: 4px solid #0d6efd; border-radius: 5px; margin: 20px 0;">
                    <h3 style="color: #0d6efd; margin-top: 0;">Catering</h3>
                    <p style="color: #0d6efd; margin: 0;">Catering services have been requested for your event.</p>
                </div>';
            }
            
            if (!empty($enquiry['av_equipment'])) {
                $htmlBody .= '
                <div style="background: #e7f3ff; padding: 15px; border-left: 4px solid #0d6efd; border-radius: 5px; margin: 20px 0;">
                    <h3 style="color: #0d6efd; margin-top: 0;">AV Equipment</h3>
                    <p style="color: #0d6efd; margin: 0;">' . htmlspecialchars($enquiry['av_equipment']) . '</p>
                </div>';
            }
            
            if (!empty($enquiry['special_requirements'])) {
                $htmlBody .= '
                <div style="background: #e7f3ff; padding: 15px; border-left: 4px solid #0d6efd; border-radius: 5px; margin: 20px 0;">
                    <h3 style="color: #0d6efd; margin-top: 0;">Special Requirements</h3>
                    <p style="color: #0d6efd; margin: 0;">' . htmlspecialchars($enquiry['special_requirements']) . '</p>
                </div>';
            }
            
            $htmlBody .= '
            <div style="background: #e7f3ff; padding: 15px; border-left: 4px solid #0d6efd; border-radius: 5px; margin: 20px 0;">
                <h3 style="color: #0d6efd; margin-top: 0;">Next Steps</h3>
                <p style="color: #0d6efd; margin: 0;">
                    <strong>Please save your booking reference:</strong> ' . htmlspecialchars($enquiry['inquiry_reference']) . '<br>
                    <strong>Arrival:</strong> Please arrive at least 30 minutes before your event start time<br>
                    <strong>Contact us:</strong> If you need to make any changes, please contact us at least ' . getSetting('booking_change_policy', '48 hours') . ' before your event.
                </p>
            </div>
            
            <p>If you have any questions, please contact us at <a href="mailto:' . htmlspecialchars($email_from_email) . '">' . htmlspecialchars($email_from_email) . '</a> or call ' . getSetting('phone_main') . '.</p>
            
            <p>We look forward to hosting your event at ' . htmlspecialchars($email_site_name) . '!</p>
            
            <div style="text-align: center; margin-top: 30px; padding-top: 20px; border-top: 2px solid #0A1929;">
                <p style="color: #666; font-size: 14px;">
                    <strong>The ' . htmlspecialchars($email_site_name) . ' Team</strong><br>
                    <a href="' . htmlspecialchars($email_site_url) . '">' . htmlspecialchars($email_site_url) . '</a>
                </p>
            </div>';
            
            // Send email
            return sendEmail(
                $enquiry['email'],
                $enquiry['contact_person'],
                'Conference Confirmed - ' . htmlspecialchars($email_site_name) . ' [' . $enquiry['inquiry_reference'] . ']',
                $htmlBody
            );
            
        } catch (Exception $e) {
            error_log("Send Conference Confirmed Email Error: " . $e->getMessage());
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
}

/**
 * Send conference cancelled email
 */
function sendConferenceCancelledEmail($enquiry) {
        global $pdo, $email_from_name, $email_from_email, $email_admin_email, $email_site_name, $email_site_url;
        
        try {
            // Get conference room details
            $stmt = $pdo->prepare("SELECT * FROM conference_rooms WHERE id = ?");
            $stmt->execute([$enquiry['conference_room_id']]);
            $room = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // Prepare email content
            $htmlBody = '
            <h1 style="color: #dc3545; text-align: center;">Conference Booking Cancelled</h1>
            <p>Dear ' . htmlspecialchars($enquiry['contact_person']) . ',</p>
            <p>We regret to inform you that your conference booking with <strong>' . htmlspecialchars($email_site_name) . '</strong> has been cancelled.</p>
            
            <div style="background: #f8f9fa; border: 2px solid #dc3545; padding: 20px; margin: 20px 0; border-radius: 10px;">
                <h2 style="color: #dc3545; margin-top: 0;">Cancelled Booking Details</h2>
                
                <div style="display: flex; justify-content: space-between; padding: 10px 0; border-bottom: 1px solid #ddd;">
                    <span style="font-weight: bold; color: #0A1929;">Reference:</span>
                    <span style="color: #dc3545; font-weight: bold; font-size: 18px;">' . htmlspecialchars($enquiry['inquiry_reference']) . '</span>
                </div>
                
                <div style="display: flex; justify-content: space-between; padding: 10px 0; border-bottom: 1px solid #ddd;">
                    <span style="font-weight: bold; color: #0A1929;">Company:</span>
                    <span style="color: #333;">' . htmlspecialchars($enquiry['company_name']) . '</span>
                </div>
                
                <div style="display: flex; justify-content: space-between; padding: 10px 0; border-bottom: 1px solid #ddd;">
                    <span style="font-weight: bold; color: #0A1929;">Conference Room:</span>
                    <span style="color: #333;">' . htmlspecialchars($room['name'] ?? 'N/A') . '</span>
                </div>
                
                <div style="display: flex; justify-content: space-between; padding: 10px 0; border-bottom: 1px solid #ddd;">
                    <span style="font-weight: bold; color: #0A1929;">Event Date:</span>
                    <span style="color: #333;">' . date('F j, Y', strtotime($enquiry['event_date'])) . '</span>
                </div>
                
                <div style="display: flex; justify-content: space-between; padding: 10px 0;">
                    <span style="font-weight: bold; color: #0A1929;">Event Time:</span>
                    <span style="color: #333;">' . date('H:i', strtotime($enquiry['start_time'])) . ' - ' . date('H:i', strtotime($enquiry['end_time'])) . '</span>
                </div>
            </div>
            
            <div style="background: #f8d7da; padding: 15px; border-left: 4px solid #dc3545; border-radius: 5px; margin: 20px 0;">
                <h3 style="color: #721c24; margin-top: 0;">❌ Booking Status: Cancelled</h3>
                <p style="color: #721c24; margin: 0;">
                    <strong>This booking has been cancelled.</strong><br>
                    If you believe this is an error, please contact us immediately.
                </p>
            </div>
            
            <p>If you have any questions or would like to reschedule, please contact us at <a href="mailto:' . htmlspecialchars($email_from_email) . '">' . htmlspecialchars($email_from_email) . '</a> or call ' . getSetting('phone_main') . '.</p>
            
            <p>We hope to have the opportunity to serve you in the future.</p>
            
            <div style="text-align: center; margin-top: 30px; padding-top: 20px; border-top: 2px solid #0A1929;">
                <p style="color: #666; font-size: 14px;">
                    <strong>The ' . htmlspecialchars($email_site_name) . ' Team</strong><br>
                    <a href="' . htmlspecialchars($email_site_url) . '">' . htmlspecialchars($email_site_url) . '</a>
                </p>
            </div>';
            
            // Send email
            return sendEmail(
                $enquiry['email'],
                $enquiry['contact_person'],
                'Conference Cancelled - ' . htmlspecialchars($email_site_name) . ' [' . $enquiry['inquiry_reference'] . ']',
                $htmlBody
            );
            
        } catch (Exception $e) {
            error_log("Send Conference Cancelled Email Error: " . $e->getMessage());
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
}

/**
 * Send booking cancelled email
 */
function sendBookingCancelledEmail($booking, $cancellation_reason = '') {
    global $pdo, $email_from_name, $email_from_email, $email_admin_email, $email_site_name, $email_site_url;
    
    try {
        // Get room details
        $stmt = $pdo->prepare("SELECT * FROM rooms WHERE id = ?");
        $stmt->execute([$booking['room_id']]);
        $room = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$room) {
            throw new Exception("Room not found");
        }
        
        // Prepare email content
        $htmlBody = '
        <h1 style="color: #dc3545; text-align: center;">Booking Cancelled</h1>
        <p>Dear ' . htmlspecialchars($booking['guest_name']) . ',</p>
        <p>We regret to inform you that your booking with <strong>' . htmlspecialchars($email_site_name) . '</strong> has been cancelled.</p>
        
        <div style="background: #f8f9fa; border: 2px solid #dc3545; padding: 20px; margin: 20px 0; border-radius: 10px;">
            <h2 style="color: #dc3545; margin-top: 0;">Cancelled Booking Details</h2>
            
            <div style="display: flex; justify-content: space-between; padding: 10px 0; border-bottom: 1px solid #ddd;">
                <span style="font-weight: bold; color: #0A1929;">Booking Reference:</span>
                <span style="color: #dc3545; font-weight: bold; font-size: 18px;">' . htmlspecialchars($booking['booking_reference']) . '</span>
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
            
            <div style="display: flex; justify-content: space-between; padding: 10px 0; border-bottom: 1px solid #ddd;">
                <span style="font-weight: bold; color: #0A1929;">Number of Nights:</span>
                <span style="color: #333;">' . $booking['number_of_nights'] . ' night' . ($booking['number_of_nights'] != 1 ? 's' : '') . '</span>
            </div>
            
            <div style="display: flex; justify-content: space-between; padding: 10px 0; border-bottom: 1px solid #ddd;">
                <span style="font-weight: bold; color: #0A1929;">Number of Guests:</span>
                <span style="color: #333;">' . $booking['number_of_guests'] . ' guest' . ($booking['number_of_guests'] != 1 ? 's' : '') . '</span>
            </div>
            
            <div style="display: flex; justify-content: space-between; padding: 10px 0;">
                <span style="font-weight: bold; color: #0A1929;">Total Amount:</span>
                <span style="color: #D4AF37; font-weight: bold; font-size: 18px;">' . getSetting('currency_symbol') . ' ' . number_format($booking['total_amount'], 0) . '</span>
            </div>
        </div>';
        
        if ($cancellation_reason) {
            $htmlBody .= '
            <div style="background: #fff3cd; padding: 15px; border-left: 4px solid #ffc107; border-radius: 5px; margin: 20px 0;">
                <h3 style="color: #856404; margin-top: 0;">Cancellation Reason</h3>
                <p style="color: #856404; margin: 0;">' . htmlspecialchars($cancellation_reason) . '</p>
            </div>';
        }
        
        $htmlBody .= '
        <div style="background: #f8d7da; padding: 15px; border-left: 4px solid #dc3545; border-radius: 5px; margin: 20px 0;">
            <h3 style="color: #721c24; margin-top: 0;">❌ Booking Status: Cancelled</h3>
            <p style="color: #721c24; margin: 0;">
                <strong>This booking has been cancelled.</strong><br>
                If you believe this is an error, please contact us immediately.
            </p>
        </div>
        
        <p>If you have any questions or would like to make a new booking, please contact us at <a href="mailto:' . htmlspecialchars($email_from_email) . '">' . htmlspecialchars($email_from_email) . '</a> or call ' . getSetting('phone_main') . '.</p>
        
        <p>We hope to have the opportunity to serve you in the future.</p>
        
        <div style="text-align: center; margin-top: 30px; padding-top: 20px; border-top: 2px solid #0A1929;">
            <p style="color: #666; font-size: 14px;">
                <strong>The ' . htmlspecialchars($email_site_name) . ' Team</strong><br>
                <a href="' . htmlspecialchars($email_site_url) . '">' . htmlspecialchars($email_site_url) . '</a>
            </p>
        </div>';
        
        // Get CC emails for invoice recipients
        $ccEmails = getCCEmails();
        
        // Send email with CC to admin/invoice recipients
        return sendEmailWithCC(
            $booking['guest_email'],
            $booking['guest_name'],
            'Booking Cancelled - ' . htmlspecialchars($email_site_name) . ' [' . $booking['booking_reference'] . ']',
            $htmlBody,
            '',
            $ccEmails
        );
        
    } catch (Exception $e) {
        error_log("Send Booking Cancelled Email Error: " . $e->getMessage());
        return [
            'success' => false,
            'message' => $e->getMessage()
        ];
    }
}

/**
 * Send email with CC recipients
 */
function sendEmailWithCC($to, $toName, $subject, $htmlBody, $textBody = '', $ccEmails = []) {
    global $email_from_name, $email_from_email, $email_admin_email;
    global $smtp_host, $smtp_port, $smtp_username, $smtp_password, $smtp_secure, $smtp_timeout, $smtp_debug;
    global $email_bcc_admin, $development_mode, $email_log_enabled, $email_preview_enabled;
    
    // If in development mode and no password or preview enabled, show preview
    if ($development_mode && (empty($smtp_password) || $email_preview_enabled)) {
        return createEmailPreview($to, $toName, $subject, $htmlBody, $textBody);
    }
    
    try {
        // Create PHPMailer instance
        $mail = new PHPMailer(true);
        
        // Server settings
        $mail->isSMTP();
        $mail->Host = $smtp_host;
        $mail->SMTPAuth = true;
        $mail->Username = $smtp_username;
        $mail->Password = $smtp_password;
        $mail->SMTPSecure = $smtp_secure;
        $mail->Port = $smtp_port;
        $mail->Timeout = $smtp_timeout;
        
        if ($smtp_debug > 0) {
            $mail->SMTPDebug = $smtp_debug;
        }
        
        // Recipients
        $mail->setFrom($smtp_username, $email_from_name);
        $mail->addAddress($to, $toName);
        $mail->addReplyTo($email_from_email, $email_from_name);
        
        // Add CC recipients
        if (!empty($ccEmails)) {
            foreach ($ccEmails as $ccEmail) {
                $mail->addCC($ccEmail);
            }
        }
        
        // Add BCC for admin if enabled
        if ($email_bcc_admin && !empty($email_admin_email)) {
            $mail->addBCC($email_admin_email);
        }
        
        // Content
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body = $htmlBody;
        $mail->AltBody = $textBody ?: strip_tags($htmlBody);
        
        $mail->send();
        
        // Log email if enabled
        if ($email_log_enabled) {
            logEmail($to, $toName, $subject, 'sent');
        }
        
        return [
            'success' => true,
            'message' => 'Email sent successfully via SMTP'
        ];
        
    } catch (Exception $e) {
        error_log("PHPMailer Error: " . $e->getMessage());
        
        // Log error if enabled
        if ($email_log_enabled) {
            logEmail($to, $toName, $subject, 'failed', $e->getMessage());
        }
        
        // If development mode, show preview instead of failing
        if ($development_mode) {
            return createEmailPreview($to, $toName, $subject, $htmlBody, $textBody);
        }
        
        return [
            'success' => false,
            'message' => 'Failed to send email: ' . $e->getMessage()
        ];
    }
}

/**
 * Get CC emails from settings
 */
function getCCEmails() {
    global $pdo;
    
    try {
        $stmt = $pdo->query("SELECT email_value FROM email_settings WHERE email_key = 'cc_emails' LIMIT 1");
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($result && !empty($result['email_value'])) {
            $emails = json_decode($result['email_value'], true);
            if (is_array($emails) && !empty($emails)) {
                return array_filter($emails, function($email) {
                    return filter_var($email, FILTER_VALIDATE_EMAIL);
                });
            }
        }
    } catch (Exception $e) {
        error_log("Error getting CC emails: " . $e->getMessage());
    }
    
    return [];
}

/**
 * Generate WhatsApp link with pre-filled message
 */
function generateWhatsAppLink($booking, $room) {
    $whatsapp_number = getSetting('whatsapp_number', getSetting('phone_main', ''));
    
    // Remove any non-numeric characters except +
    $whatsapp_number = preg_replace('/[^0-9+]/', '', $whatsapp_number);
    
    // Remove + if present (WhatsApp API format doesn't use +)
    $whatsapp_number = ltrim($whatsapp_number, '+');
    
    // Create pre-filled message
    $message = "Hello! I would like to confirm my tentative booking:\n\n";
    $message .= "📅 Booking Reference: " . $booking['booking_reference'] . "\n";
    $message .= "👤 Guest Name: " . $booking['guest_name'] . "\n";
    $message .= "🏨 Room: " . $room['name'] . "\n";
    $message .= "📆 Check-in: " . date('F j, Y', strtotime($booking['check_in_date'])) . "\n";
    $message .= "📆 Check-out: " . date('F j, Y', strtotime($booking['check_out_date'])) . "\n";
    $message .= "💰 Total Amount: " . getSetting('currency_symbol') . ' ' . number_format($booking['total_amount'], 0) . "\n\n";
    $message .= "Please confirm my booking. Thank you!";
    
    return 'https://wa.me/' . $whatsapp_number . '?text=' . urlencode($message);
}

/**
 * Send tentative booking confirmed email (sent when tentative booking is created)
 */
function sendTentativeBookingConfirmedEmail($booking) {
    global $pdo, $email_from_name, $email_from_email, $email_admin_email, $email_site_name, $email_site_url;
    
    try {
        // Get room details
        $stmt = $pdo->prepare("SELECT * FROM rooms WHERE id = ?");
        $stmt->execute([$booking['room_id']]);
        $room = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$room) {
            throw new Exception("Room not found");
        }
        
        // Get tentative settings
        $tentative_duration_hours = (int)getSetting('tentative_duration_hours', 48);
        $expires_at = new DateTime($booking['tentative_expires_at']);
        $hours_until_expiry = (new DateTime())->diff($expires_at)->h + ((new DateTime())->diff($expires_at)->days * 24);
        
        // Generate WhatsApp link
        $whatsapp_link = generateWhatsAppLink($booking, $room);
        
        // Prepare email content
        $htmlBody = '
        <h1 style="color: #0A1929; text-align: center;">Tentative Booking Confirmed</h1>
        <p>Dear ' . htmlspecialchars($booking['guest_name']) . ',</p>
        <p>Thank you for your interest in <strong>' . htmlspecialchars($email_site_name) . '</strong>. Your room has been placed on tentative hold.</p>
        
        <div style="background: #fff3cd; padding: 15px; border-left: 4px solid #ffc107; border-radius: 5px; margin: 20px 0;">
            <h3 style="color: #856404; margin-top: 0;">⏰ Tentative Hold Period</h3>
            <p style="color: #856404; margin: 0;">
                <strong>Your room is reserved until:</strong><br>
                ' . $expires_at->format('F j, Y') . ' at ' . $expires_at->format('g:i A') . '<br><br>
                You will receive a reminder email 24 hours before expiration.
            </p>
        </div>
        
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
            
            <div style="display: flex; justify-content: space-between; padding: 10px 0; border-bottom: 1px solid #ddd;">
                <span style="font-weight: bold; color: #0A1929;">Number of Nights:</span>
                <span style="color: #333;">' . $booking['number_of_nights'] . ' night' . ($booking['number_of_nights'] != 1 ? 's' : '') . '</span>
            </div>
            
            <div style="display: flex; justify-content: space-between; padding: 10px 0; border-bottom: 1px solid #ddd;">
                <span style="font-weight: bold; color: #0A1929;">Number of Guests:</span>
                <span style="color: #333;">' . $booking['number_of_guests'] . ' guest' . ($booking['number_of_guests'] != 1 ? 's' : '') . '</span>
            </div>
            
            <div style="display: flex; justify-content: space-between; padding: 10px 0;">
                <span style="font-weight: bold; color: #0A1929;">Total Amount:</span>
                <span style="color: #D4AF37; font-weight: bold; font-size: 18px;">' . getSetting('currency_symbol') . ' ' . number_format($booking['total_amount'], 0) . '</span>
            </div>
        </div>
        
        <div style="background: #d4edda; padding: 15px; border-left: 4px solid #28a745; border-radius: 5px; margin: 20px 0;">
            <h3 style="color: #155724; margin-top: 0;">What Happens Next?</h3>
            <p style="color: #155724; margin: 0;">
                <strong>1.</strong> You will receive a reminder 24 hours before expiration<br>
                <strong>2.</strong> Confirm your booking anytime before expiration via WhatsApp<br>
                <strong>3.</strong> No penalty if you decide not to book
            </p>
        </div>
        
        <div style="text-align: center; margin-top: 30px;">
            <a href="' . htmlspecialchars($whatsapp_link) . '"
               style="display: inline-block; background: #25D366; color: white; padding: 12px 24px; text-decoration: none; border-radius: 5px; font-weight: bold; font-size: 16px;"
               target="_blank">
                💬 Confirm My Booking Now via WhatsApp
            </a>
        </div>';
        
        if (!empty($booking['special_requests'])) {
            $htmlBody .= '
            <div style="background: #e7f3ff; padding: 15px; border-left: 4px solid #0d6efd; border-radius: 5px; margin: 20px 0;">
                <h3 style="color: #0d6efd; margin-top: 0;">Special Requests</h3>
                <p style="color: #0d6efd; margin: 0;">' . htmlspecialchars($booking['special_requests']) . '</p>
            </div>';
        }
        
        $htmlBody .= '
        <p>If you have any questions, please contact us at <a href="mailto:' . htmlspecialchars($email_from_email) . '">' . htmlspecialchars($email_from_email) . '</a> or call ' . getSetting('phone_main') . '.</p>
        
        <p>Thank you for considering ' . htmlspecialchars($email_site_name) . '!</p>
        
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
            'Tentative Booking Confirmed - ' . htmlspecialchars($email_site_name) . ' [' . $booking['booking_reference'] . ']',
            $htmlBody
        );
        
    } catch (Exception $e) {
        error_log("Send Tentative Booking Confirmed Email Error: " . $e->getMessage());
        return [
            'success' => false,
            'message' => $e->getMessage()
        ];
    }
}

/**
 * Send tentative booking reminder email (24 hours before expiration)
 */
function sendTentativeBookingReminderEmail($booking) {
    global $pdo, $email_from_name, $email_from_email, $email_admin_email, $email_site_name, $email_site_url;
    
    try {
        // Get room details
        $stmt = $pdo->prepare("SELECT * FROM rooms WHERE id = ?");
        $stmt->execute([$booking['room_id']]);
        $room = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$room) {
            throw new Exception("Room not found");
        }
        
        $expires_at = new DateTime($booking['tentative_expires_at']);
        
        // Prepare email content
        $htmlBody = '
        <h1 style="color: #dc3545; text-align: center;">⏰ Your Tentative Booking Expires Soon</h1>
        <p>Dear ' . htmlspecialchars($booking['guest_name']) . ',</p>
        <p>This is a friendly reminder that your tentative booking at <strong>' . htmlspecialchars($email_site_name) . '</strong> will expire in 24 hours.</p>
        
        <div style="background: #f8d7da; padding: 15px; border-left: 4px solid #dc3545; border-radius: 5px; margin: 20px 0;">
            <h3 style="color: #721c24; margin-top: 0;">Expiration Details</h3>
            <p style="color: #721c24; margin: 0;">
                <strong>Expires:</strong> ' . $expires_at->format('F j, Y') . ' at ' . $expires_at->format('g:i A') . '<br>
                <strong>Booking Reference:</strong> ' . htmlspecialchars($booking['booking_reference']) . '<br>
                <strong>Room:</strong> ' . htmlspecialchars($room['name']) . '
            </p>
        </div>
        
        <div style="background: #f8f9fa; border: 2px solid #0A1929; padding: 20px; margin: 20px 0; border-radius: 10px;">
            <h2 style="color: #0A1929; margin-top: 0;">Your Booking</h2>
            
            <div style="display: flex; justify-content: space-between; padding: 10px 0; border-bottom: 1px solid #ddd;">
                <span style="font-weight: bold; color: #0A1929;">Check-in:</span>
                <span style="color: #333;">' . date('F j, Y', strtotime($booking['check_in_date'])) . '</span>
            </div>
            
            <div style="display: flex; justify-content: space-between; padding: 10px 0; border-bottom: 1px solid #ddd;">
                <span style="font-weight: bold; color: #0A1929;">Check-out:</span>
                <span style="color: #333;">' . date('F j, Y', strtotime($booking['check_out_date'])) . '</span>
            </div>
            
            <div style="display: flex; justify-content: space-between; padding: 10px 0;">
                <span style="font-weight: bold; color: #0A1929;">Total Amount:</span>
                <span style="color: #D4AF37; font-weight: bold; font-size: 18px;">' . getSetting('currency_symbol') . ' ' . number_format($booking['total_amount'], 0) . '</span>
            </div>
        </div>
        
        <div style="text-align: center; margin-top: 30px;">
            <a href="' . htmlspecialchars(generateWhatsAppLink($booking, $room)) . '"
               style="display: inline-block; background: #25D366; color: white; padding: 12px 24px; text-decoration: none; border-radius: 5px; font-weight: bold; font-size: 16px;"
               target="_blank">
                💬 Confirm My Booking Now via WhatsApp
            </a>
        </div>
        
        <p style="margin-top: 20px;">If you have any questions, please contact us at <a href="mailto:' . htmlspecialchars($email_from_email) . '">' . htmlspecialchars($email_from_email) . '</a> or call ' . getSetting('phone_main') . '.</p>
        
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
            'Reminder: Tentative Booking Expiring Soon - ' . $booking['booking_reference'],
            $htmlBody
        );
        
    } catch (Exception $e) {
        error_log("Send Tentative Booking Reminder Email Error: " . $e->getMessage());
        return [
            'success' => false,
            'message' => $e->getMessage()
        ];
    }
}

/**
 * Send tentative booking expired email (when booking expires)
 */
function sendTentativeBookingExpiredEmail($booking) {
    global $pdo, $email_from_name, $email_from_email, $email_admin_email, $email_site_name, $email_site_url;
    
    try {
        // Get room details
        $stmt = $pdo->prepare("SELECT * FROM rooms WHERE id = ?");
        $stmt->execute([$booking['room_id']]);
        $room = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Prepare email content
        $htmlBody = '
        <h1 style="color: #6c757d; text-align: center;">Tentative Booking Expired</h1>
        <p>Dear ' . htmlspecialchars($booking['guest_name']) . ',</p>
        <p>Your tentative booking at <strong>' . htmlspecialchars($email_site_name) . '</strong> has expired.</p>
        
        <div style="background: #e2e3e5; padding: 15px; border-left: 4px solid #6c757d; border-radius: 5px; margin: 20px 0;">
            <h3 style="color: #383d41; margin-top: 0;">What This Means</h3>
            <p style="color: #383d41; margin: 0;">
                Your room hold has been released and is now available for other guests.<br>
                There is no penalty for an expired tentative booking.
            </p>
        </div>
        
        <div style="background: #f8f9fa; border: 2px solid #0A1929; padding: 20px; margin: 20px 0; border-radius: 10px;">
            <h2 style="color: #0A1929; margin-top: 0;">Expired Booking Details</h2>
            
            <div style="display: flex; justify-content: space-between; padding: 10px 0; border-bottom: 1px solid #ddd;">
                <span style="font-weight: bold; color: #0A1929;">Booking Reference:</span>
                <span style="color: #6c757d; font-weight: bold; font-size: 18px;">' . htmlspecialchars($booking['booking_reference']) . '</span>
            </div>
            
            <div style="display: flex; justify-content: space-between; padding: 10px 0; border-bottom: 1px solid #ddd;">
                <span style="font-weight: bold; color: #0A1929;">Room:</span>
                <span style="color: #333;">' . htmlspecialchars($room['name'] ?? 'N/A') . '</span>
            </div>
            
            <div style="display: flex; justify-content: space-between; padding: 10px 0; border-bottom: 1px solid #ddd;">
                <span style="font-weight: bold; color: #0A1929;">Check-in Date:</span>
                <span style="color: #333;">' . date('F j, Y', strtotime($booking['check_in_date'])) . '</span>
            </div>
            
            <div style="display: flex; justify-content: space-between; padding: 10px 0;">
                <span style="font-weight: bold; color: #0A1929;">Check-out Date:</span>
                <span style="color: #333;">' . date('F j, Y', strtotime($booking['check_out_date'])) . '</span>
            </div>
        </div>
        
        <p>If you are still interested in booking with us, please visit our website to check availability and make a new booking.</p>
        
        <div style="text-align: center; margin-top: 30px;">
            <a href="' . htmlspecialchars($email_site_url) . '/booking.php"
               style="display: inline-block; background: #D4AF37; color: #0A1929; padding: 12px 24px; text-decoration: none; border-radius: 5px; font-weight: bold;">
                Make a New Booking
            </a>
        </div>
        
        <p style="margin-top: 20px;">If you have any questions, please contact us at <a href="mailto:' . htmlspecialchars($email_from_email) . '">' . htmlspecialchars($email_from_email) . '</a> or call ' . getSetting('phone_main') . '.</p>
        
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
            'Tentative Booking Expired - ' . $booking['booking_reference'],
            $htmlBody
        );
        
    } catch (Exception $e) {
        error_log("Send Tentative Booking Expired Email Error: " . $e->getMessage());
        return [
            'success' => false,
            'message' => $e->getMessage()
        ];
    }
}

/**
 * Send tentative booking converted email (when converted to confirmed)
 */
function sendTentativeBookingConvertedEmail($booking) {
    global $pdo, $email_from_name, $email_from_email, $email_admin_email, $email_site_name, $email_site_url;
    
    try {
        // Debug: Log what data we received
        error_log("sendTentativeBookingConvertedEmail called with booking data: " . json_encode($booking));
        
        // Check if room_id exists
        if (!isset($booking['room_id']) || empty($booking['room_id'])) {
            throw new Exception("Room ID not found in booking data. Available keys: " . implode(', ', array_keys($booking)));
        }
        
        // Get room details
        $stmt = $pdo->prepare("SELECT * FROM rooms WHERE id = ?");
        $stmt->execute([$booking['room_id']]);
        $room = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$room) {
            throw new Exception("Room not found with ID: " . $booking['room_id']);
        }
        
        error_log("Room found: " . json_encode($room));
        
        // Prepare email content specifically for conversion
        $htmlBody = '
        <h1 style="color: #0A1929; text-align: center;">Booking Confirmed!</h1>
        <p>Dear ' . htmlspecialchars($booking['guest_name']) . ',</p>
        
        <div style="background: #d4edda; padding: 15px; border-left: 4px solid #28a745; border-radius: 5px; margin: 20px 0;">
            <h3 style="color: #155724; margin-top: 0;">✅ Great News!</h3>
            <p style="color: #155724; margin: 0;">
                <strong>Your tentative booking has been successfully converted to a confirmed booking!</strong><br>
                Your reservation is now guaranteed and we look forward to welcoming you to ' . htmlspecialchars($email_site_name) . '.
            </p>
        </div>
        
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
            
            <div style="display: flex; justify-content: space-between; padding: 10px 0; border-bottom: 1px solid #ddd;">
                <span style="font-weight: bold; color: #0A1929;">Number of Nights:</span>
                <span style="color: #333;">' . $booking['number_of_nights'] . ' night' . ($booking['number_of_nights'] != 1 ? 's' : '') . '</span>
            </div>
            
            <div style="display: flex; justify-content: space-between; padding: 10px 0; border-bottom: 1px solid #ddd;">
                <span style="font-weight: bold; color: #0A1929;">Number of Guests:</span>
                <span style="color: #333;">' . $booking['number_of_guests'] . ' guest' . ($booking['number_of_guests'] != 1 ? 's' : '') . '</span>
            </div>
            
            <div style="display: flex; justify-content: space-between; padding: 10px 0;">
                <span style="font-weight: bold; color: #0A1929;">Total Amount:</span>
                <span style="color: #D4AF37; font-weight: bold; font-size: 18px;">' . getSetting('currency_symbol') . ' ' . number_format($booking['total_amount'], 0) . '</span>
            </div>
        </div>
        
        <div style="background: #d4edda; padding: 15px; border-left: 4px solid #28a745; border-radius: 5px; margin: 20px 0;">
            <h3 style="color: #155724; margin-top: 0;">✅ Booking Status: Confirmed</h3>
            <p style="color: #155724; margin: 0;">
                <strong>Your booking is now confirmed and guaranteed!</strong><br>
                We look forward to welcoming you to ' . htmlspecialchars($email_site_name) . '.
            </p>
        </div>
        
        <div style="background: #fff3cd; padding: 15px; border-left: 4px solid #ffc107; border-radius: 5px; margin: 20px 0;">
            <h3 style="color: #856404; margin-top: 0;">Payment Information</h3>
            <p style="color: #856404; margin: 0;">
                ' . getSetting('payment_policy', 'Payment will be made at the hotel upon arrival.<br>We accept cash payments only. Please bring the total amount of <strong>' . getSetting('currency_symbol') . ' ' . number_format($booking['total_amount'], 0) . '</strong> with you.') . '
            </p>
        </div>
        
        <div style="background: #e7f3ff; padding: 15px; border-left: 4px solid #0d6efd; border-radius: 5px; margin: 20px 0;">
            <h3 style="color: #0d6efd; margin-top: 0;">Next Steps</h3>
            <p style="color: #0d6efd; margin: 0;">
                <strong>Please save your booking reference:</strong> ' . htmlspecialchars($booking['booking_reference']) . '<br>
                <strong>Check-in time:</strong> ' . getSetting('check_in_time', '2:00 PM') . '<br>
                <strong>Check-out time:</strong> ' . getSetting('check_out_time', '11:00 AM') . '<br>
                <strong>Contact us:</strong> If you need to make any changes, please contact us at least ' . getSetting('booking_change_policy', '48 hours') . ' before your arrival.
            </p>
        </div>';
        
        if (!empty($booking['special_requests'])) {
            $htmlBody .= '
            <div style="background: #e7f3ff; padding: 15px; border-left: 4px solid #0d6efd; border-radius: 5px; margin: 20px 0;">
                <h3 style="color: #0d6efd; margin-top: 0;">Special Requests</h3>
                <p style="color: #0d6efd; margin: 0;">' . htmlspecialchars($booking['special_requests']) . '</p>
            </div>';
        }
        
        $htmlBody .= '
        <p>If you have any questions, please contact us at <a href="mailto:' . htmlspecialchars($email_from_email) . '">' . htmlspecialchars($email_from_email) . '</a> or call ' . getSetting('phone_main') . '.</p>
        
        <p>We look forward to welcoming you to ' . htmlspecialchars($email_site_name) . '!</p>
        
        <div style="text-align: center; margin-top: 30px; padding-top: 20px; border-top: 2px solid #0A1929;">
            <p style="color: #666; font-size: 14px;">
                <strong>The ' . htmlspecialchars($email_site_name) . ' Team</strong><br>
                <a href="' . htmlspecialchars($email_site_url) . '">' . htmlspecialchars($email_site_url) . '</a>
            </p>
        </div>';
        
        // Send email with unique subject line for conversion
        return sendEmail(
            $booking['guest_email'],
            $booking['guest_name'],
            'Booking Confirmed (Converted) - ' . htmlspecialchars($email_site_name) . ' [' . $booking['booking_reference'] . ']',
            $htmlBody
        );
        
    } catch (Exception $e) {
        error_log("Send Tentative Booking Converted Email Error: " . $e->getMessage());
        return [
            'success' => false,
            'message' => $e->getMessage()
        ];
    }
}

/**
 * Send pending booking expired email (when pending booking expires)
 */
function sendPendingBookingExpiredEmail($booking, $room = null) {
    global $pdo, $email_from_name, $email_from_email, $email_admin_email, $email_site_name, $email_site_url;
    
    try {
        // Get room details if not provided
        if (!$room) {
            $stmt = $pdo->prepare("SELECT * FROM rooms WHERE id = ?");
            $stmt->execute([$booking['room_id']]);
            $room = $stmt->fetch(PDO::FETCH_ASSOC);
        }
        
        // Prepare email content
        $htmlBody = '
        <h1 style="color: #6c757d; text-align: center;">Pending Booking Expired</h1>
        <p>Dear ' . htmlspecialchars($booking['guest_name']) . ',</p>
        <p>Your pending booking at <strong>' . htmlspecialchars($email_site_name) . '</strong> has expired due to lack of confirmation.</p>
        
        <div style="background: #e2e3e5; padding: 15px; border-left: 4px solid #6c757d; border-radius: 5px; margin: 20px 0;">
            <h3 style="color: #383d41; margin-top: 0;">What This Means</h3>
            <p style="color: #383d41; margin: 0;">
                Your booking request was not confirmed within the required time period.<br>
                The room has been released and is now available for other guests.<br>
                There is no penalty for an expired pending booking.
            </p>
        </div>
        
        <div style="background: #f8f9fa; border: 2px solid #0A1929; padding: 20px; margin: 20px 0; border-radius: 10px;">
            <h2 style="color: #0A1929; margin-top: 0;">Expired Booking Details</h2>
            
            <div style="display: flex; justify-content: space-between; padding: 10px 0; border-bottom: 1px solid #ddd;">
                <span style="font-weight: bold; color: #0A1929;">Booking Reference:</span>
                <span style="color: #6c757d; font-weight: bold; font-size: 18px;">' . htmlspecialchars($booking['booking_reference']) . '</span>
            </div>
            
            <div style="display: flex; justify-content: space-between; padding: 10px 0; border-bottom: 1px solid #ddd;">
                <span style="font-weight: bold; color: #0A1929;">Room:</span>
                <span style="color: #333;">' . htmlspecialchars($room['name'] ?? 'N/A') . '</span>
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
                <span style="font-weight: bold; color: #0A1929;">Total Amount:</span>
                <span style="color: #D4AF37; font-weight: bold; font-size: 18px;">' . getSetting('currency_symbol') . ' ' . number_format($booking['total_amount'], 0) . '</span>
            </div>
        </div>
        
        <p>If you are still interested in booking with us, please visit our website to check availability and make a new booking.</p>
        
        <div style="text-align: center; margin-top: 30px;">
            <a href="' . htmlspecialchars($email_site_url) . '/booking.php"
               style="display: inline-block; background: #D4AF37; color: #0A1929; padding: 12px 24px; text-decoration: none; border-radius: 5px; font-weight: bold;">
                Make a New Booking
            </a>
        </div>
        
        <p style="margin-top: 20px;">If you have any questions, please contact us at <a href="mailto:' . htmlspecialchars($email_from_email) . '">' . htmlspecialchars($email_from_email) . '</a> or call ' . getSetting('phone_main') . '.</p>
        
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
            'Pending Booking Expired - ' . $booking['booking_reference'],
            $htmlBody
        );
        
    } catch (Exception $e) {
        error_log("Send Pending Booking Expired Email Error: " . $e->getMessage());
        return [
            'success' => false,
            'message' => $e->getMessage()
        ];
    }
}

/**
 * Send admin notification for expired booking
 */
function sendAdminBookingExpiredNotification($booking, $booking_type = 'tentative') {
    global $pdo, $email_from_name, $email_from_email, $email_admin_email, $email_site_name, $email_site_url;
    
    try {
        // Get room details
        $stmt = $pdo->prepare("SELECT * FROM rooms WHERE id = ?");
        $stmt->execute([$booking['room_id']]);
        $room = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Get admin notification email with fallback
        $admin_notification_email = getSetting('admin_notification_email');
        if (empty($admin_notification_email) || !filter_var($admin_notification_email, FILTER_VALIDATE_EMAIL)) {
            $admin_notification_email = $email_admin_email;
        }
        
        // Determine reason based on booking type
        $reason = $booking_type === 'tentative'
            ? 'Tentative booking expired (not confirmed within time limit)'
            : 'Pending booking expired (not confirmed within time limit)';
        
        $type_label = $booking_type === 'tentative' ? 'Tentative' : 'Pending';
        
        // Prepare email content
        $htmlBody = '
        <h1 style="color: #dc3545; text-align: center;">🔔 Booking Auto-Expired</h1>
        <p>A ' . strtolower($type_label) . ' booking has been automatically expired by the system.</p>
        
        <div style="background: #f8d7da; padding: 15px; border-left: 4px solid #dc3545; border-radius: 5px; margin: 20px 0;">
            <h3 style="color: #721c24; margin-top: 0;">Expiration Details</h3>
            <p style="color: #721c24; margin: 0;">
                <strong>Booking Type:</strong> ' . htmlspecialchars($type_label) . '<br>
                <strong>Reason:</strong> ' . htmlspecialchars($reason) . '<br>
                <strong>Expired At:</strong> ' . date('F j, Y g:i A') . '
            </p>
        </div>
        
        <div style="background: #f8f9fa; border: 2px solid #0A1929; padding: 20px; margin: 20px 0; border-radius: 10px;">
            <h2 style="color: #0A1929; margin-top: 0;">Booking Details</h2>
            
            <div style="display: flex; justify-content: space-between; padding: 10px 0; border-bottom: 1px solid #ddd;">
                <span style="font-weight: bold; color: #0A1929;">Booking Reference:</span>
                <span style="color: #dc3545; font-weight: bold; font-size: 18px;">' . htmlspecialchars($booking['booking_reference']) . '</span>
            </div>
            
            <div style="display: flex; justify-content: space-between; padding: 10px 0; border-bottom: 1px solid #ddd;">
                <span style="font-weight: bold; color: #0A1929;">Guest Name:</span>
                <span style="color: #333;">' . htmlspecialchars($booking['guest_name']) . '</span>
            </div>
            
            <div style="display: flex; justify-content: space-between; padding: 10px 0; border-bottom: 1px solid #ddd;">
                <span style="font-weight: bold; color: #0A1929;">Guest Email:</span>
                <span style="color: #333;">' . htmlspecialchars($booking['guest_email']) . '</span>
            </div>
            
            <div style="display: flex; justify-content: space-between; padding: 10px 0; border-bottom: 1px solid #ddd;">
                <span style="font-weight: bold; color: #0A1929;">Guest Phone:</span>
                <span style="color: #333;">' . htmlspecialchars($booking['guest_phone']) . '</span>
            </div>
            
            <div style="display: flex; justify-content: space-between; padding: 10px 0; border-bottom: 1px solid #ddd;">
                <span style="font-weight: bold; color: #0A1929;">Room:</span>
                <span style="color: #333;">' . htmlspecialchars($room['name'] ?? 'N/A') . '</span>
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
                <span style="font-weight: bold; color: #0A1929;">Total Amount:</span>
                <span style="color: #D4AF37; font-weight: bold; font-size: 18px;">' . getSetting('currency_symbol') . ' ' . number_format($booking['total_amount'], 0) . '</span>
            </div>
        </div>
        
        <div style="background: #e7f3ff; padding: 15px; border-left: 4px solid #0d6efd; border-radius: 5px; margin: 20px 0;">
            <h3 style="color: #0d6efd; margin-top: 0;">Actions Taken</h3>
            <p style="color: #0d6efd; margin: 0;">
                <strong>✓</strong> Booking status changed to "expired"<br>
                <strong>✓</strong> Room availability restored<br>
                <strong>✓</strong> Expiration email sent to guest<br>
                <strong>✓</strong> Logged to tentative_booking_log
            </p>
        </div>
        
        <div style="text-align: center; margin-top: 30px;">
            <a href="' . htmlspecialchars($email_site_url) . '/admin/tentative-bookings.php?status=expired"
               style="display: inline-block; background: #D4AF37; color: #0A1929; padding: 12px 24px; text-decoration: none; border-radius: 5px; font-weight: bold; font-size: 16px;">
                View Expired Bookings
            </a>
        </div>';
        
        // Send email
        return sendEmail(
            $admin_notification_email,
            'Admin Team',
            'Booking Auto-Expired: ' . $booking['booking_reference'] . ' - ' . $booking['guest_name'],
            $htmlBody
        );
        
    } catch (Exception $e) {
        error_log("Send Admin Booking Expired Notification Error: " . $e->getMessage());
        return [
            'success' => false,
            'message' => $e->getMessage()
        ];
    }
}

/**
 * Send gym booking confirmation email to customer
 *
 * @param array $data Booking data with keys: name, email, phone, preferred_date, preferred_time, package_choice, guests, goals
 * @return array Result array with success status and message
 */
function sendGymBookingEmail($data) {
    global $email_from_name, $email_from_email, $email_admin_email, $email_site_name, $email_site_url;
    
    try {
        $site_name = getSetting('site_name');
        
        // Prepare email content
        $htmlBody = '
        <h1 style="color: #0A1929; text-align: center;">Gym Booking Request Received</h1>
        <p>Dear ' . htmlspecialchars($data['name']) . ',</p>
        <p>Thank you for your gym booking request with <strong>' . htmlspecialchars($site_name) . '</strong>. We have received your submission and will confirm your booking shortly.</p>
        
        <div style="background: #f8f9fa; border: 2px solid #0A1929; padding: 20px; margin: 20px 0; border-radius: 10px;">
            <h2 style="color: #0A1929; margin-top: 0;">Booking Details</h2>
            
            <div style="display: flex; justify-content: space-between; padding: 10px 0; border-bottom: 1px solid #ddd;">
                <span style="font-weight: bold; color: #0A1929;">Name:</span>
                <span style="color: #333;">' . htmlspecialchars($data['name']) . '</span>
            </div>
            
            <div style="display: flex; justify-content: space-between; padding: 10px 0; border-bottom: 1px solid #ddd;">
                <span style="font-weight: bold; color: #0A1929;">Email:</span>
                <span style="color: #333;">' . htmlspecialchars($data['email']) . '</span>
            </div>
            
            <div style="display: flex; justify-content: space-between; padding: 10px 0; border-bottom: 1px solid #ddd;">
                <span style="font-weight: bold; color: #0A1929;">Phone:</span>
                <span style="color: #333;">' . htmlspecialchars($data['phone']) . '</span>
            </div>
            
            <div style="display: flex; justify-content: space-between; padding: 10px 0; border-bottom: 1px solid #ddd;">
                <span style="font-weight: bold; color: #0A1929;">Preferred Date:</span>
                <span style="color: #333;">' . htmlspecialchars($data['preferred_date']) . '</span>
            </div>
            
            <div style="display: flex; justify-content: space-between; padding: 10px 0; border-bottom: 1px solid #ddd;">
                <span style="font-weight: bold; color: #0A1929;">Preferred Time:</span>
                <span style="color: #333;">' . htmlspecialchars($data['preferred_time']) . '</span>
            </div>
            
            <div style="display: flex; justify-content: space-between; padding: 10px 0; border-bottom: 1px solid #ddd;">
                <span style="font-weight: bold; color: #0A1929;">Package:</span>
                <span style="color: #333;">' . htmlspecialchars($data['package_choice']) . '</span>
            </div>
            
            <div style="display: flex; justify-content: space-between; padding: 10px 0;">';
        
        if (!empty($data['guests']) && $data['guests'] > 1) {
            $htmlBody .= '
                <span style="font-weight: bold; color: #0A1929;">Number of Guests:</span>
                <span style="color: #333;">' . (int)$data['guests'] . '</span>
            </div>';
        } else {
            $htmlBody .= '
                <span style="font-weight: bold; color: #0A1929;">Number of Guests:</span>
                <span style="color: #333;">1</span>
            </div>';
        }
        
        $htmlBody .= '
        </div>';
        
        if (!empty($data['goals'])) {
            $htmlBody .= '
            <div style="background: #e7f3ff; padding: 15px; border-left: 4px solid #0d6efd; border-radius: 5px; margin: 20px 0;">
                <h3 style="color: #0d6efd; margin-top: 0;">Fitness Goals / Notes</h3>
                <p style="color: #0d6efd; margin: 0;">' . nl2br(htmlspecialchars($data['goals'])) . '</p>
            </div>';
        }
        
        $htmlBody .= '
        <div style="background: #e7f3ff; padding: 15px; border-left: 4px solid #0d6efd; border-radius: 5px; margin: 20px 0;">
            <h3 style="color: #0d6efd; margin-top: 0;">What Happens Next?</h3>
            <p style="color: #0d6efd; margin: 0;">
                <strong>Our team will contact you within 24 hours to confirm your booking.</strong><br>
                If you have any questions in the meantime, please contact us.
            </p>
        </div>
        
        <p>If you have any questions, please contact us at <a href="mailto:' . htmlspecialchars($email_from_email) . '">' . htmlspecialchars($email_from_email) . '</a> or call ' . getSetting('phone_main') . '.</p>
        
        <p>Thank you for choosing ' . htmlspecialchars($site_name) . '!</p>
        
        <div style="text-align: center; margin-top: 30px; padding-top: 20px; border-top: 2px solid #0A1929;">
            <p style="color: #666; font-size: 14px;">
                <strong>The ' . htmlspecialchars($site_name) . ' Team</strong><br>
                <a href="' . htmlspecialchars($email_site_url) . '">' . htmlspecialchars($email_site_url) . '</a>
            </p>
        </div>';
        
        // Send email
        return sendEmail(
            $data['email'],
            $data['name'],
            'Gym Booking Request Received - ' . htmlspecialchars($site_name),
            $htmlBody
        );
        
    } catch (Exception $e) {
        error_log("Send Gym Booking Email Error: " . $e->getMessage());
        return [
            'success' => false,
            'message' => $e->getMessage()
        ];
    }
}

/**
 * Send gym booking notification email to admin
 *
 * @param array $data Booking data with keys: name, email, phone, preferred_date, preferred_time, package_choice, guests, goals
 * @return array Result array with success status and message
 */
function sendGymAdminNotificationEmail($data) {
    global $email_from_name, $email_from_email, $email_admin_email, $email_site_name, $email_site_url;
    
    try {
        $site_name = getSetting('site_name');
        
        // Get gym email with fallback to main contact email
        $gym_email = getSetting('gym_email');
        if (empty($gym_email) || !filter_var($gym_email, FILTER_VALIDATE_EMAIL)) {
            $gym_email = getSetting('email_main');
        }
        if (empty($gym_email) || !filter_var($gym_email, FILTER_VALIDATE_EMAIL)) {
            $gym_email = $email_admin_email;
        }
        
        // Prepare email content
        $htmlBody = '
        <h1 style="color: #0A1929; text-align: center;">📋 New Gym Booking Request</h1>
        <p>A new gym booking request has been submitted on the website.</p>
        
        <div style="background: #f8f9fa; border: 2px solid #0A1929; padding: 20px; margin: 20px 0; border-radius: 10px;">
            <h2 style="color: #0A1929; margin-top: 0;">Booking Details</h2>
            
            <div style="display: flex; justify-content: space-between; padding: 10px 0; border-bottom: 1px solid #ddd;">
                <span style="font-weight: bold; color: #0A1929;">Name:</span>
                <span style="color: #333;">' . htmlspecialchars($data['name']) . '</span>
            </div>
            
            <div style="display: flex; justify-content: space-between; padding: 10px 0; border-bottom: 1px solid #ddd;">
                <span style="font-weight: bold; color: #0A1929;">Email:</span>
                <span style="color: #333;">' . htmlspecialchars($data['email']) . '</span>
            </div>
            
            <div style="display: flex; justify-content: space-between; padding: 10px 0; border-bottom: 1px solid #ddd;">
                <span style="font-weight: bold; color: #0A1929;">Phone:</span>
                <span style="color: #333;">' . htmlspecialchars($data['phone']) . '</span>
            </div>
            
            <div style="display: flex; justify-content: space-between; padding: 10px 0; border-bottom: 1px solid #ddd;">
                <span style="font-weight: bold; color: #0A1929;">Preferred Date:</span>
                <span style="color: #333;">' . htmlspecialchars($data['preferred_date']) . '</span>
            </div>
            
            <div style="display: flex; justify-content: space-between; padding: 10px 0; border-bottom: 1px solid #ddd;">
                <span style="font-weight: bold; color: #0A1929;">Preferred Time:</span>
                <span style="color: #333;">' . htmlspecialchars($data['preferred_time']) . '</span>
            </div>
            
            <div style="display: flex; justify-content: space-between; padding: 10px 0; border-bottom: 1px solid #ddd;">
                <span style="font-weight: bold; color: #0A1929;">Package:</span>
                <span style="color: #333;">' . htmlspecialchars($data['package_choice']) . '</span>
            </div>
            
            <div style="display: flex; justify-content: space-between; padding: 10px 0;">';
        
        if (!empty($data['guests']) && $data['guests'] > 1) {
            $htmlBody .= '
                <span style="font-weight: bold; color: #0A1929;">Number of Guests:</span>
                <span style="color: #333;">' . (int)$data['guests'] . '</span>
            </div>';
        } else {
            $htmlBody .= '
                <span style="font-weight: bold; color: #0A1929;">Number of Guests:</span>
                <span style="color: #333;">1</span>
            </div>';
        }
        
        $htmlBody .= '
        </div>';
        
        if (!empty($data['goals'])) {
            $htmlBody .= '
            <div style="background: #e7f3ff; padding: 15px; border-left: 4px solid #0d6efd; border-radius: 5px; margin: 20px 0;">
                <h3 style="color: #0d6efd; margin-top: 0;">Fitness Goals / Notes</h3>
                <p style="color: #0d6efd; margin: 0;">' . nl2br(htmlspecialchars($data['goals'])) . '</p>
            </div>';
        }
        
        $htmlBody .= '
        <div style="text-align: center; margin-top: 30px;">
            <a href="mailto:' . htmlspecialchars($data['email']) . '"
               style="display: inline-block; background: #D4AF37; color: #0A1929; padding: 12px 24px; text-decoration: none; border-radius: 5px; font-weight: bold; font-size: 16px;">
                Reply to Customer
            </a>
        </div>';
        
        // Send email
        return sendEmail(
            $gym_email,
            'Gym Team',
            'New Gym Booking Request - ' . htmlspecialchars($data['name']),
            $htmlBody
        );
        
    } catch (Exception $e) {
        error_log("Send Gym Admin Notification Email Error: " . $e->getMessage());
        return [
            'success' => false,
            'message' => $e->getMessage()
        ];
    }
}

/**
 * Load and populate an email template
 *
 * @param string $template Template filename (without path)
 * @param array $data Data to populate template with
 * @return string Populated HTML content
 */
function loadEmailTemplate($template, $data) {
    $template_path = __DIR__ . '/../templates/emails/' . $template;
    
    // Check if template exists
    if (!file_exists($template_path)) {
        error_log("Email template not found: $template_path");
        return '';
    }
    
    $html = file_get_contents($template_path);
    
    // Add common data
    $site_name = getSetting('site_name');
    $site_url = getSetting('site_url');
    $contact_email = getSetting('email_main');
    $phone = getSetting('phone_main');
    $currency_symbol = getSetting('currency_symbol');
    
    $common_data = [
        'site_name' => $site_name,
        'site_url' => $site_url,
        'contact_email' => $contact_email,
        'phone' => $phone,
        'currency_symbol' => $currency_symbol,
        'submission_source' => $_SERVER['HTTP_HOST'] ?? 'localhost',
        'admin_url' => $site_url . '/admin/conference-management.php'
    ];
    
    // Merge user data with common data
    $data = array_merge($common_data, $data);
    
    // Replace simple placeholders {{key}}
    $html = preg_replace_callback('/\{\{(\w+)\}\}/', function($matches) use ($data) {
        $key = $matches[1];
        return $data[$key] ?? '';
    }, $html);
    
    // Handle conditional blocks {{#if key}}...{{/if}}
    $html = preg_replace_callback('/\{\{#if\s+(\w+)\}\}(.*?)\{\{\/if\}\}/s', function($matches) use ($data) {
        $key = $matches[1];
        $content = $matches[2];
        // Show content if key exists and is not empty
        if (!empty($data[$key])) {
            return $content;
        }
        return '';
    }, $html);
    
    return $html;
}
