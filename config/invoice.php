<?php
/**
 * Invoice Generation and Email System
 * Generates professional PDF invoices for booking payments
 */

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/email.php';

// Import PHPMailer classes
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// Try to load TCPDF if available
$tcpdf_loaded = false;
if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
    // Try Composer autoload
    $autoload = include __DIR__ . '/../vendor/autoload.php';
    if (class_exists('TCPDF')) {
        // Check if constants are defined (they might not be with autoload only)
        if (!defined('PDF_PAGE_ORIENTATION') || !defined('PDF_UNIT') || !defined('PDF_PAGE_FORMAT')) {
            // Try to include tcpdf.php directly to get constants
            if (file_exists(__DIR__ . '/../vendor/tecnickcom/tcpdf/tcpdf.php')) {
                require_once __DIR__ . '/../vendor/tecnickcom/tcpdf/tcpdf.php';
            }
        }
        // Verify constants are now defined
        if (defined('PDF_PAGE_ORIENTATION') && defined('PDF_UNIT') && defined('PDF_PAGE_FORMAT')) {
            $tcpdf_loaded = true;
        }
    }
} elseif (file_exists(__DIR__ . '/../TCPDF/tcpdf.php')) {
    // Try direct TCPDF include
    require_once __DIR__ . '/../TCPDF/tcpdf.php';
    if (class_exists('TCPDF') && defined('PDF_PAGE_ORIENTATION') && defined('PDF_UNIT') && defined('PDF_PAGE_FORMAT')) {
        $tcpdf_loaded = true;
    }
}

// Define fallback constants if TCPDF is not loaded or constants are missing
if (!defined('PDF_PAGE_ORIENTATION')) {
    define('PDF_PAGE_ORIENTATION', 'P');
}
if (!defined('PDF_UNIT')) {
    define('PDF_UNIT', 'mm');
}
if (!defined('PDF_PAGE_FORMAT')) {
    define('PDF_PAGE_FORMAT', 'A4');
}

/**
 * Generate PDF invoice for a booking
 * 
 * @param int $booking_id Booking ID
 * @return string PDF file path or false on failure
 */
function generateInvoicePDF($booking_id) {
    global $pdo, $tcpdf_loaded;
    
    try {
        // Get booking details
        $stmt = $pdo->prepare("
            SELECT b.*, r.name as room_name, r.image_url,
                   s.setting_value as site_name
            FROM bookings b
            JOIN rooms r ON b.room_id = r.id
            JOIN site_settings s ON s.setting_key = 'site_name'
            WHERE b.id = ?
        ");
        $stmt->execute([$booking_id]);
        $booking = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$booking) {
            throw new Exception("Booking not found");
        }
        
        // Get hotel contact details
        $site_name = getSetting('site_name');
        $email_address = getSetting('email_from_email');
        $phone_number = getSetting('phone_main');
        $address = getSetting('address_line1') . ', ' .
                   getSetting('address_line2') . ', ' .
                   getSetting('address_country');
        $currency_symbol = getSetting('currency_symbol');
        
        // Create invoice directory if it doesn't exist
        $invoiceDir = __DIR__ . '/../invoices';
        if (!file_exists($invoiceDir)) {
            mkdir($invoiceDir, 0755, true);
        }
        
        // Generate unique invoice filename - use sequential invoice number from settings
        $invoice_prefix = getSetting('invoice_prefix', 'INV');
        $invoice_start = (int)getSetting('invoice_start_number', 1000);
        
        // Get the next invoice number
        $stmt = $pdo->prepare("SELECT MAX(CAST(SUBSTRING_INDEX(invoice_number, '-', -1) AS UNSIGNED)) as max_inv FROM payments WHERE invoice_number LIKE ?");
        $stmt->execute([$invoice_prefix . '-' . date('Y') . '%']);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $next_number = max($invoice_start, ($result['max_inv'] ?? 0) + 1);
        
        $invoice_number = $invoice_prefix . '-' . date('Y') . '-' . str_pad($next_number, 6, '0', STR_PAD_LEFT);
        $filename = $invoice_number . '.pdf';
        $filepath = $invoiceDir . '/' . $filename;
        
        if ($tcpdf_loaded) {
            // Use TCPDF for professional PDF generation
            $tcpdfClass = 'TCPDF';
            $pdf = new $tcpdfClass(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
            
            // Set document information
            $pdf->SetCreator($site_name);
            $pdf->SetAuthor($site_name);
            $pdf->SetTitle('Invoice ' . $invoice_number);
            $pdf->SetSubject('Payment Invoice');
            
            // Remove default header/footer
            $pdf->setPrintHeader(false);
            $pdf->setPrintFooter(false);
            
            // Set margins
            $pdf->SetMargins(15, 15, 15);
            
            // Add a page
            $pdf->AddPage();
            
            // Build HTML content
            $html = buildInvoiceHTML($booking, $invoice_number, $site_name, $email_address, $phone_number, $address, $currency_symbol);
            
            // Write HTML
            $pdf->writeHTML($html, true, false, true, false, '');
            
            // Save PDF
            $pdf->Output($filepath, 'F');
            
        } else {
            // Fallback: Generate HTML invoice and save as file
            $html = buildInvoiceHTML($booking, $invoice_number, $site_name, $email_address, $phone_number, $address, $currency_symbol);
            
            // Wrap in complete HTML document
            $fullHtml = '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Invoice ' . $invoice_number . '</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 0; padding: 20px; }
        .invoice-container { max-width: 800px; margin: 0 auto; border: 1px solid #ddd; }
        .invoice-header { background: linear-gradient(135deg, #0A1929 0%, #1a3a5c 100%); color: white; padding: 30px; }
        .invoice-header h1 { margin: 0; color: #D4AF37; }
        .invoice-body { padding: 30px; }
        .invoice-details { margin-bottom: 30px; }
        .invoice-row { display: flex; justify-content: space-between; padding: 10px 0; border-bottom: 1px solid #eee; }
        .invoice-label { font-weight: bold; color: #333; }
        .invoice-value { color: #666; }
        .total-section { background: #f8f9fa; padding: 20px; border-radius: 5px; margin-top: 20px; }
        .total-row { display: flex; justify-content: space-between; font-size: 18px; font-weight: bold; color: #D4AF37; }
        .footer { text-align: center; padding: 20px; background: #f8f9fa; border-top: 1px solid #ddd; }
    </style>
</head>
<body>' . $html . '</body></html>';
            
            // Save as HTML (can be opened in browser and printed as PDF)
            $htmlFilepath = str_replace('.pdf', '.html', $filepath);
            file_put_contents($htmlFilepath, $fullHtml);
            
            // Return array with both paths and invoice number
            return [
                'filepath' => $htmlFilepath,
                'invoice_number' => $invoice_number,
                'relative_path' => 'invoices/' . basename($htmlFilepath)
            ];
        }
        
        // Return array with both paths and invoice number
        return [
            'filepath' => $filepath,
            'invoice_number' => $invoice_number,
            'relative_path' => 'invoices/' . $filename
        ];
        
    } catch (Exception $e) {
        error_log("Generate Invoice PDF Error: " . $e->getMessage());
        return false;
    }
}

/**
 * Build HTML content for invoice
 */
function buildInvoiceHTML($booking, $invoice_number, $site_name, $email_address, $phone_number, $address, $currency_symbol) {
    global $pdo;
    
    $check_in = date('F j, Y', strtotime($booking['check_in_date']));
    $check_out = date('F j, Y', strtotime($booking['check_out_date']));
    
    // Get VAT settings - more flexible check
    $vatEnabled = in_array(getSetting('vat_enabled'), ['1', 1, true, 'true', 'on'], true);
    $vatRate = $vatEnabled ? (float)getSetting('vat_rate') : 0;
    $vatNumber = getSetting('vat_number');
    
    // Get payment details for this booking
    $paymentsStmt = $pdo->prepare("
        SELECT * FROM payments
        WHERE booking_type = 'room' AND booking_id = ?
        AND payment_status = 'completed' AND deleted_at IS NULL
        ORDER BY payment_date ASC
    ");
    $paymentsStmt->execute([$booking['id']]);
    $payments = $paymentsStmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Calculate totals
    $subtotal = (float)$booking['total_amount'];
    $vatAmount = $vatEnabled ? ($subtotal * ($vatRate / 100)) : 0;
    $totalWithVat = $subtotal + $vatAmount;
    
    // Build payment details HTML
    $paymentDetailsHTML = '';
    if (!empty($payments)) {
        $paymentDetailsHTML = '<div style="margin-top: 20px; padding: 15px; background: #f8f9fa; border-radius: 5px;">
                    <h4 style="color: #0A1929; margin-top: 0;">Payment History</h4>';
        
        foreach ($payments as $payment) {
            $paymentDetailsHTML .= '<div style="display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px solid #ddd;">
                        <span>' . date('M j, Y', strtotime($payment['payment_date'])) . ' (' . ucfirst(str_replace('_', ' ', $payment['payment_method'])) . ')</span>
                        <span>' . $currency_symbol . ' ' . number_format($payment['total_amount'], 2) . '</span>
                    </div>';
        }
        
        $paymentDetailsHTML .= '</div>';
    }
    
    // Build VAT section HTML
    $vatSectionHTML = '';
    if ($vatEnabled && $vatAmount > 0) {
        $vatSectionHTML = '<div class="invoice-row">
                    <span class="invoice-label">Subtotal (excl. VAT):</span>
                    <span class="invoice-value">' . $currency_symbol . ' ' . number_format($subtotal, 2) . '</span>
                </div>
                <div class="invoice-row">
                    <span class="invoice-label">VAT (' . number_format($vatRate, 2) . '%):</span>
                    <span class="invoice-value">' . $currency_symbol . ' ' . number_format($vatAmount, 2) . '</span>
                </div>';
        if ($vatNumber) {
            $vatSectionHTML .= '<div class="invoice-row">
                    <span class="invoice-label">VAT Number:</span>
                    <span class="invoice-value">' . htmlspecialchars($vatNumber) . '</span>
                </div>';
        }
    }
    
    return '
    <div class="invoice-container">
        <div class="invoice-header">
            <h1 style="color: #D4AF37; margin: 0 0 10px 0; font-size: 32px;">PAYMENT RECEIPT / INVOICE</h1>
            <p style="margin: 5px 0; font-size: 18px;">' . htmlspecialchars($site_name) . '</p>
            <p style="margin: 5px 0;">Invoice Number: <strong>' . htmlspecialchars($invoice_number) . '</strong></p>
            <p style="margin: 5px 0;">Date: ' . date('F j, Y') . '</p>
        </div>
        
        <div class="invoice-body">
            <div class="invoice-details">
                <h3 style="color: #0A1929; border-bottom: 2px solid #D4AF37; padding-bottom: 10px; margin-bottom: 20px;">Guest Information</h3>
                
                <div class="invoice-row">
                    <span class="invoice-label">Guest Name:</span>
                    <span class="invoice-value">' . htmlspecialchars($booking['guest_name']) . '</span>
                </div>
                <div class="invoice-row">
                    <span class="invoice-label">Email:</span>
                    <span class="invoice-value">' . htmlspecialchars($booking['guest_email']) . '</span>
                </div>
                <div class="invoice-row">
                    <span class="invoice-label">Phone:</span>
                    <span class="invoice-value">' . htmlspecialchars($booking['guest_phone']) . '</span>
                </div>
            </div>
            
            <div class="invoice-details">
                <h3 style="color: #0A1929; border-bottom: 2px solid #D4AF37; padding-bottom: 10px; margin-bottom: 20px;">Booking Details</h3>
                
                <div class="invoice-row">
                    <span class="invoice-label">Booking Reference:</span>
                    <span class="invoice-value" style="color: #D4AF37; font-weight: bold; font-size: 16px;">' . htmlspecialchars($booking['booking_reference']) . '</span>
                </div>
                <div class="invoice-row">
                    <span class="invoice-label">Room Type:</span>
                    <span class="invoice-value">' . htmlspecialchars($booking['room_name']) . '</span>
                </div>
                <div class="invoice-row">
                    <span class="invoice-label">Check-in Date:</span>
                    <span class="invoice-value">' . $check_in . '</span>
                </div>
                <div class="invoice-row">
                    <span class="invoice-label">Check-out Date:</span>
                    <span class="invoice-value">' . $check_out . '</span>
                </div>
                <div class="invoice-row">
                    <span class="invoice-label">Number of Nights:</span>
                    <span class="invoice-value">' . $booking['number_of_nights'] . ' night' . ($booking['number_of_nights'] != 1 ? 's' : '') . '</span>
                </div>
                <div class="invoice-row">
                    <span class="invoice-label">Number of Guests:</span>
                    <span class="invoice-value">' . $booking['number_of_guests'] . ' guest' . ($booking['number_of_guests'] != 1 ? 's' : '') . '</span>
                </div>
            </div>
            
            <div class="total-section">
                ' . $vatSectionHTML . '
                <div class="total-row">
                    <span>Total Amount' . ($vatEnabled ? ' (incl. VAT)' : '') . ':</span>
                    <span>' . $currency_symbol . ' ' . number_format($totalWithVat, 2) . '</span>
                </div>
                <p style="margin: 15px 0 0 0; color: #666; font-size: 14px;">
                    <strong>Payment Status:</strong> <span style="color: #28a745; font-weight: bold;">PAID</span>
                </p>
                <p style="margin: 5px 0; color: #666; font-size: 14px;">
                    <strong>Amount Paid:</strong> ' . $currency_symbol . ' ' . number_format($booking['amount_paid'] ?? $totalWithVat, 2) . '
                </p>
                ' . ($booking['amount_due'] > 0 ? '<p style="margin: 5px 0; color: #dc3545; font-size: 14px;">
                    <strong>Balance Due:</strong> ' . $currency_symbol . ' ' . number_format($booking['amount_due'], 2) . '
                </p>' : '') . '
            </div>
            
            ' . $paymentDetailsHTML . '
        </div>
        
        <div class="footer">
            <p style="margin: 10px 0;"><strong>' . htmlspecialchars($site_name) . '</strong></p>
            <p style="margin: 5px 0;">' . htmlspecialchars($address) . '</p>
            <p style="margin: 5px 0;">Email: ' . htmlspecialchars($email_address) . ' | Phone: ' . htmlspecialchars($phone_number) . '</p>
            <p style="margin: 15px 0 0 0; color: #999; font-size: 12px;">
                Thank you for your payment! We look forward to welcoming you.
            </p>
        </div>
    </div>';
}

/**
 * Send payment invoice email to guest and copy recipients
 * 
 * @param int $booking_id Booking ID
 * @return array Result array with success status and message
 */
function sendPaymentInvoiceEmail($booking_id) {
    global $pdo;
    
    try {
        // Check if invoice emails are enabled
        $send_invoices = (bool)getEmailSetting('send_invoice_emails', 0);
        if (!$send_invoices) {
            return ['success' => true, 'message' => 'Invoice emails disabled'];
        }
        
        // Get booking details
        $stmt = $pdo->prepare("SELECT * FROM bookings WHERE id = ?");
        $stmt->execute([$booking_id]);
        $booking = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$booking) {
            throw new Exception("Booking not found");
        }
        
        // Generate invoice PDF/HTML
        $invoice_result = generateInvoicePDF($booking_id);
        if (!$invoice_result) {
            throw new Exception("Failed to generate invoice");
        }
        
        $invoice_file = $invoice_result['filepath'];
        $invoice_number = $invoice_result['invoice_number'];
        $invoice_path = $invoice_result['relative_path'];
        
        // Update the payment record with invoice path and invoice number
        $update_stmt = $pdo->prepare("
            UPDATE payments
            SET invoice_path = ?, invoice_number = ?, invoice_generated = 1
            WHERE booking_type = 'room' AND booking_id = ?
            ORDER BY id DESC LIMIT 1
        ");
        $update_stmt->execute([$invoice_path, $invoice_number, $booking_id]);
        
        // Get invoice recipients (comma-separated)
        $invoice_recipients = getEmailSetting('invoice_recipients', '');
        $smtp_username = getEmailSetting('smtp_username', '');
        
        // Parse recipients from comma-separated string
        $cc_recipients = array_filter(array_map('trim', explode(',', $invoice_recipients)));
        
        // Always add SMTP username to CC list
        if (!empty($smtp_username) && !in_array($smtp_username, $cc_recipients)) {
            $cc_recipients[] = $smtp_username;
        }
        
        // Send invoice to guest with CC recipients
        $result = sendInvoiceEmailToGuestWithCC($booking, $invoice_file, $cc_recipients);
        
        return [
            'success' => $result['success'],
            'message' => $result['message'],
            'invoice_file' => $invoice_file,
            'invoice_number' => $invoice_number,
            'invoice_path' => $invoice_path,
            'cc_recipients' => $cc_recipients
        ];
        
    } catch (Exception $e) {
        error_log("Send Payment Invoice Email Error: " . $e->getMessage());
        return [
            'success' => false,
            'message' => $e->getMessage()
        ];
    }
}

/**
 * Send payment invoice email with custom CC recipients
 *
 * @param int $booking_id Booking ID
 * @param array $ccRecipients Array of CC email addresses
 * @return array Result array with success status and message
 */
function sendPaymentInvoiceEmailWithCC($booking_id, $ccRecipients = []) {
    global $pdo;
    
    try {
        // Check if invoice emails are enabled
        $send_invoices = (bool)getEmailSetting('send_invoice_emails', 0);
        if (!$send_invoices) {
            return ['success' => true, 'message' => 'Invoice emails disabled'];
        }
        
        // Get booking details
        $stmt = $pdo->prepare("SELECT * FROM bookings WHERE id = ?");
        $stmt->execute([$booking_id]);
        $booking = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$booking) {
            throw new Exception("Booking not found");
        }
        
        // Generate invoice PDF/HTML
        $invoice_result = generateInvoicePDF($booking_id);
        if (!$invoice_result) {
            throw new Exception("Failed to generate invoice");
        }
        
        $invoice_file = $invoice_result['filepath'];
        $invoice_number = $invoice_result['invoice_number'];
        $invoice_path = $invoice_result['relative_path'];
        
        // Update the payment record with invoice path and invoice number
        $update_stmt = $pdo->prepare("
            UPDATE payments
            SET invoice_path = ?, invoice_number = ?, invoice_generated = 1
            WHERE booking_type = 'room' AND booking_id = ?
            ORDER BY id DESC LIMIT 1
        ");
        $update_stmt->execute([$invoice_path, $invoice_number, $booking_id]);
        
        // Send invoice to guest with custom CC recipients
        $result = sendInvoiceEmailToGuestWithCC($booking, $invoice_file, $ccRecipients);
        
        return [
            'success' => $result['success'],
            'message' => $result['message'],
            'invoice_file' => $invoice_file,
            'invoice_number' => $invoice_number,
            'invoice_path' => $invoice_path,
            'cc_recipients' => $ccRecipients
        ];
        
    } catch (Exception $e) {
        error_log("Send Payment Invoice Email with CC Error: " . $e->getMessage());
        return [
            'success' => false,
            'message' => $e->getMessage()
        ];
    }
}

/**
 * Send invoice email to guest with CC recipients
 */
function sendInvoiceEmailToGuestWithCC($booking, $invoice_file, $cc_recipients = []) {
    global $pdo, $email_from_name, $email_from_email, $email_site_name, $email_site_url;
    
    try {
        // Get room details
        $stmt = $pdo->prepare("SELECT * FROM rooms WHERE id = ?");
        $stmt->execute([$booking['room_id']]);
        $room = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $currency_symbol = getSetting('currency_symbol');
        
        // Prepare email content
        $htmlBody = '
        <div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;">
            <div style="background: linear-gradient(135deg, #0A1929 0%, #1a3a5c 100%); padding: 30px; text-align: center; border-radius: 10px 10px 0 0;">
                <h1 style="color: #D4AF37; margin: 0; font-size: 32px;">✓ PAYMENT CONFIRMED</h1>
                <p style="color: white; margin: 10px 0 0 0; font-size: 18px;">Thank you for your payment!</p>
            </div>
            
            <div style="background: #f8f9fa; padding: 30px; border: 1px solid #ddd; border-top: none; border-radius: 0 0 10px 10px;">
                <p>Dear ' . htmlspecialchars($booking['guest_name']) . ',</p>
                
                <p>We are pleased to confirm that your payment has been received. Please find attached your official invoice/receipt for booking <strong>' . htmlspecialchars($booking['booking_reference']) . '</strong>.</p>
                
                <div style="background: white; padding: 20px; border-radius: 8px; margin: 20px 0; border-left: 4px solid #D4AF37;">
                    <h3 style="color: #0A1929; margin-top: 0;">Booking Summary</h3>
                    
                    <div style="display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px solid #eee;">
                        <span style="font-weight: bold; color: #333;">Room:</span>
                        <span style="color: #666;">' . htmlspecialchars($room['name']) . '</span>
                    </div>
                    
                    <div style="display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px solid #eee;">
                        <span style="font-weight: bold; color: #333;">Check-in:</span>
                        <span style="color: #666;">' . date('F j, Y', strtotime($booking['check_in_date'])) . '</span>
                    </div>
                    
                    <div style="display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px solid #eee;">
                        <span style="font-weight: bold; color: #333;">Check-out:</span>
                        <span style="color: #666;">' . date('F j, Y', strtotime($booking['check_out_date'])) . '</span>
                    </div>
                    
                    <div style="display: flex; justify-content: space-between; padding: 15px 0;">
                        <span style="font-weight: bold; color: #D4AF37; font-size: 18px;">Total Paid:</span>
                        <span style="color: #D4AF37; font-weight: bold; font-size: 18px;">' . $currency_symbol . ' ' . number_format($booking['total_amount'], 0) . '</span>
                    </div>
                </div>
                
                <div style="background: #d4edda; padding: 15px; border-left: 4px solid #28a745; border-radius: 5px; margin: 20px 0;">
                    <h3 style="color: #155724; margin-top: 0;">✅ Payment Status: PAID</h3>
                    <p style="color: #155724; margin: 0;">Your booking is now fully paid and confirmed. We look forward to welcoming you!</p>
                </div>
                
                <div style="background: #e7f3ff; padding: 15px; border-left: 4px solid #0d6efd; border-radius: 5px;">
                    <h3 style="color: #0d6efd; margin-top: 0;">Next Steps</h3>
                    <ul style="color: #0d6efd; margin: 10px 0; padding-left: 20px;">
                        <li>Please save your booking reference: <strong>' . htmlspecialchars($booking['booking_reference']) . '</strong></li>
                        <li>Check-in time: ' . getSetting('check_in_time', '2:00 PM') . '</li>
                        <li>Check-out time: ' . getSetting('check_out_time', '11:00 AM') . '</li>
                        <li>Bring your ID for registration</li>
                    </ul>
                </div>
                
                <p style="margin-top: 30px;">If you have any questions, please contact us at <a href="mailto:' . htmlspecialchars($email_from_email) . '">' . htmlspecialchars($email_from_email) . '</a>.</p>
                
                <p style="margin-top: 20px;">We look forward to welcoming you to <strong>' . htmlspecialchars($email_site_name) . '</strong>!</p>
                
                <div style="text-align: center; margin-top: 40px; padding-top: 20px; border-top: 2px solid #0A1929;">
                    <p style="color: #666; font-size: 14px; margin: 5px 0;"><strong>The ' . htmlspecialchars($email_site_name) . ' Team</strong></p>
                    <p style="color: #666; font-size: 14px; margin: 5px 0;"><a href="' . htmlspecialchars($email_site_url) . '">' . htmlspecialchars($email_site_url) . '</a></p>
                </div>
            </div>
        </div>';
        
        // Send email with attachment and CC recipients
        return sendEmailWithAttachmentAndCC(
            $booking['guest_email'],
            $booking['guest_name'],
            'Payment Invoice - ' . htmlspecialchars($email_site_name) . ' [' . $booking['booking_reference'] . ']',
            $htmlBody,
            $invoice_file,
            $cc_recipients
        );
        
    } catch (Exception $e) {
        error_log("Send Invoice to Guest Error: " . $e->getMessage());
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

/**
 * Send invoice copy emails
 */
function sendInvoiceCopyEmails($booking, $invoice_file, $recipients) {
    if (empty($recipients)) {
        return ['success' => true, 'message' => 'No copy recipients'];
    }
    
    global $email_site_name;
    $currency_symbol = getSetting('currency_symbol');
    
    $htmlBody = '
    <div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;">
        <div style="background: #0A1929; padding: 20px; text-align: center; border-radius: 10px 10px 0 0;">
            <h1 style="color: #D4AF37; margin: 0; font-size: 24px;">INVOICE COPY</h1>
            <p style="color: white; margin: 10px 0 0 0;">Administrative Copy</p>
        </div>
        
        <div style="background: #f8f9fa; padding: 30px; border: 1px solid #ddd; border-top: none; border-radius: 0 0 10px 10px;">
            <p>A payment has been received for booking <strong>' . htmlspecialchars($booking['booking_reference']) . '</strong>.</p>
            
            <div style="background: white; padding: 20px; border-radius: 8px; margin: 20px 0;">
                <h3 style="color: #0A1929; margin-top: 0;">Payment Details</h3>
                <p><strong>Guest:</strong> ' . htmlspecialchars($booking['guest_name']) . '</p>
                <p><strong>Email:</strong> ' . htmlspecialchars($booking['guest_email']) . '</p>
                <p><strong>Amount Paid:</strong> <span style="color: #D4AF37; font-weight: bold;">' . $currency_symbol . ' ' . number_format($booking['total_amount'], 0) . '</span></p>
                <p><strong>Payment Date:</strong> ' . date('F j, Y g:i A') . '</p>
            </div>
            
            <p>Please find the invoice attached for your records.</p>
        </div>
    </div>';
    
    // Send to all recipients
    $all_success = true;
    foreach ($recipients as $recipient) {
        $result = sendEmailWithAttachment(
            $recipient,
            'Accounts Team',
            'Invoice Copy - ' . htmlspecialchars($email_site_name) . ' [' . $booking['booking_reference'] . ']',
            $htmlBody,
            $invoice_file
        );
        if (!$result['success']) {
            $all_success = false;
            error_log("Failed to send invoice copy to $recipient: " . $result['message']);
        }
    }
    
    return ['success' => $all_success, 'message' => $all_success ? 'All copies sent' : 'Some copies failed'];
}

/**
 * Send email with attachment (wrapper for sendEmailWithAttachmentAndCC)
 *
 * @param string $to Recipient email
 * @param string $toName Recipient name
 * @param string $subject Email subject
 * @param string $htmlBody HTML email body
 * @param string $attachmentPath Path to attachment file
 * @return array Result array with success status and message
 */
function sendEmailWithAttachment($to, $toName, $subject, $htmlBody, $attachmentPath) {
    // Call the CC version with empty CC array
    return sendEmailWithAttachmentAndCC($to, $toName, $subject, $htmlBody, $attachmentPath, []);
}

/**
 * Send email with attachment and CC recipients
 * Uses the same email configuration as config/email.php
 */
function sendEmailWithAttachmentAndCC($to, $toName, $subject, $htmlBody, $attachmentPath, $ccRecipients = []) {
    global $email_from_name, $email_from_email, $email_admin_email;
    global $smtp_host, $smtp_port, $smtp_username, $smtp_password, $smtp_secure, $smtp_timeout, $smtp_debug;
    global $email_bcc_admin, $development_mode, $email_log_enabled, $email_preview_enabled;
    
    // Check if we're on localhost
    $is_localhost = isset($_SERVER['HTTP_HOST']) && (
        strpos($_SERVER['HTTP_HOST'], 'localhost') !== false || 
        strpos($_SERVER['HTTP_HOST'], '127.0.0.1') !== false ||
        strpos($_SERVER['HTTP_HOST'], '.local') !== false
    );
    
    // Development mode: show previews on localhost unless explicitly disabled
    $dev_mode = $is_localhost && $development_mode;
    
    // If in development mode and no password or preview enabled, show preview
    if ($dev_mode && (empty($smtp_password) || $email_preview_enabled)) {
        return createEmailPreview($to, $toName, $subject, $htmlBody);
    }
    
    try {
        $mail = new PHPMailer(true);
        
        // Server settings - loaded from database
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
        
        // Add CC recipients from invoice_recipients setting
        foreach ($ccRecipients as $cc) {
            if (!empty($cc) && filter_var($cc, FILTER_VALIDATE_EMAIL)) {
                $mail->addCC($cc);
            }
        }
        
        // Add BCC for admin if enabled
        if ($email_bcc_admin && !empty($email_admin_email)) {
            $mail->addBCC($email_admin_email);
        }
        
        // Add attachment
        if (file_exists($attachmentPath)) {
            $mail->addAttachment($attachmentPath, basename($attachmentPath));
        }
        
        // Content
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body = $htmlBody;
        $mail->AltBody = strip_tags($htmlBody);
        
        $mail->send();
        
        // Log email if enabled
        if ($email_log_enabled) {
            $cc_list = implode(', ', $ccRecipients);
            logEmail($to, $toName, $subject, 'sent', '', "CC: $cc_list");
        }
        
        return [
            'success' => true,
            'message' => 'Email sent successfully via SMTP with ' . count($ccRecipients) . ' CC recipients'
        ];
        
    } catch (Exception $e) {
        error_log("PHPMailer Error (sendEmailWithAttachmentAndCC): " . $e->getMessage());
        
        // Log error if enabled
        if ($email_log_enabled) {
            $cc_list = implode(', ', $ccRecipients);
            logEmail($to, $toName, $subject, 'failed', $e->getMessage(), "CC: $cc_list");
        }
        
        // If development mode, show preview instead of failing
        if ($dev_mode) {
            return createEmailPreview($to, $toName, $subject, $htmlBody);
        }
        
        return [
            'success' => false,
            'message' => 'Failed to send email: ' . $e->getMessage()
        ];
}
}

/**
 * Generate PDF invoice for a conference enquiry
 *
 * @param int $enquiry_id Conference enquiry ID
 * @return string PDF file path or false on failure
 */
function generateConferenceInvoicePDF($enquiry_id) {
    global $pdo, $tcpdf_loaded;
    
    try {
        // Get enquiry details
        $stmt = $pdo->prepare("
            SELECT ci.*, cr.name as room_name,
                   s.setting_value as site_name
            FROM conference_inquiries ci
            LEFT JOIN conference_rooms cr ON ci.conference_room_id = cr.id
            JOIN site_settings s ON s.setting_key = 'site_name'
            WHERE ci.id = ?
        ");
        $stmt->execute([$enquiry_id]);
        $enquiry = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$enquiry) {
            throw new Exception("Conference enquiry not found");
        }
        
        // Get hotel contact details
        $site_name = getSetting('site_name');
        $email_address = getSetting('email_from_email');
        $phone_number = getSetting('phone_main');
        $address = getSetting('address_line1') . ', ' .
                   getSetting('address_line2') . ', ' .
                   getSetting('address_country');
        $currency_symbol = getSetting('currency_symbol');
        
        // Create invoice directory if it doesn't exist
        $invoiceDir = __DIR__ . '/../invoices';
        if (!file_exists($invoiceDir)) {
            mkdir($invoiceDir, 0755, true);
        }
        
        // Generate unique invoice filename - use sequential invoice number from settings
        $invoice_prefix = getSetting('invoice_prefix', 'INV');
        $invoice_start = (int)getSetting('invoice_start_number', 1000);
        
        // Get the next invoice number for conference invoices
        $stmt = $pdo->prepare("SELECT MAX(CAST(SUBSTRING_INDEX(invoice_number, '-', -1) AS UNSIGNED)) as max_inv FROM payments WHERE invoice_number LIKE ?");
        $stmt->execute(['CONF-' . $invoice_prefix . '-' . date('Y') . '%']);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $next_number = max($invoice_start, ($result['max_inv'] ?? 0) + 1);
        
        $invoice_number = 'CONF-' . $invoice_prefix . '-' . date('Y') . '-' . str_pad($next_number, 6, '0', STR_PAD_LEFT);
        $filename = $invoice_number . '.pdf';
        $filepath = $invoiceDir . '/' . $filename;
        
        if ($tcpdf_loaded) {
            // Use TCPDF for professional PDF generation
            $tcpdfClass = 'TCPDF';
            $pdf = new $tcpdfClass(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
            
            // Set document information
            $pdf->SetCreator($site_name);
            $pdf->SetAuthor($site_name);
            $pdf->SetTitle('Invoice ' . $invoice_number);
            $pdf->SetSubject('Conference Payment Invoice');
            
            // Remove default header/footer
            $pdf->setPrintHeader(false);
            $pdf->setPrintFooter(false);
            
            // Set margins
            $pdf->SetMargins(15, 15, 15);
            
            // Add a page
            $pdf->AddPage();
            
            // Build HTML content
            $html = buildConferenceInvoiceHTML($enquiry, $invoice_number, $site_name, $email_address, $phone_number, $address, $currency_symbol);
            
            // Write HTML
            $pdf->writeHTML($html, true, false, true, false, '');
            
            // Save PDF
            $pdf->Output($filepath, 'F');
            
        } else {
            // Fallback: Generate HTML invoice and save as file
            $html = buildConferenceInvoiceHTML($enquiry, $invoice_number, $site_name, $email_address, $phone_number, $address, $currency_symbol);
            
            // Wrap in complete HTML document
            $fullHtml = '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Invoice ' . $invoice_number . '</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 0; padding: 20px; }
        .invoice-container { max-width: 800px; margin: 0 auto; border:1px solid #ddd; }
        .invoice-header { background: linear-gradient(135deg, #0A1929 0%, #1a3a5c 100%); color: white; padding: 30px; }
        .invoice-header h1 { margin: 0; color: #D4AF37; }
        .invoice-body { padding: 30px; }
        .invoice-details { margin-bottom: 30px; }
        .invoice-row { display: flex; justify-content: space-between; padding: 10px 0; border-bottom:1px solid #eee; }
        .invoice-label { font-weight: bold; color: #333; }
        .invoice-value { color: #666; }
        .total-section { background: #f8f9fa; padding: 20px; border-radius:5px; margin-top: 20px; }
        .total-row { display: flex; justify-content: space-between; font-size: 18px; font-weight: bold; color: #D4AF37; }
        .footer { text-align: center; padding: 20px; background: #f8f9fa; border-top: 1px solid #ddd; }
    </style>
</head>
<body>' . $html . '</body></html>';
            
            // Save as HTML (can be opened in browser and printed as PDF)
            $htmlFilepath = str_replace('.pdf', '.html', $filepath);
            file_put_contents($htmlFilepath, $fullHtml);
            
            // Return array with both paths and invoice number
            return [
                'filepath' => $htmlFilepath,
                'invoice_number' => $invoice_number,
                'relative_path' => 'invoices/' . basename($htmlFilepath)
            ];
        }
        
        // Return array with both paths and invoice number
        return [
            'filepath' => $filepath,
            'invoice_number' => $invoice_number,
            'relative_path' => 'invoices/' . $filename
        ];
        
    } catch (Exception $e) {
        error_log("Generate Conference Invoice PDF Error: " . $e->getMessage());
        return false;
    }
}

/**
 * Build HTML content for conference invoice
 */
function buildConferenceInvoiceHTML($enquiry, $invoice_number, $site_name, $email_address, $phone_number, $address, $currency_symbol) {
    global $pdo;
    
    $event_date = date('F j, Y', strtotime($enquiry['event_date']));
    
    // Get VAT settings - more flexible check
    $vatEnabled = in_array(getSetting('vat_enabled'), ['1', 1, true, 'true', 'on'], true);
    $vatRate = $vatEnabled ? (float)getSetting('vat_rate') : 0;
    $vatNumber = getSetting('vat_number');
    
    // Get payment details for this conference enquiry
    $paymentsStmt = $pdo->prepare("
        SELECT * FROM payments
        WHERE booking_type = 'conference' AND booking_id = ?
        AND payment_status = 'completed' AND deleted_at IS NULL
        ORDER BY payment_date ASC
    ");
    $paymentsStmt->execute([$enquiry['id']]);
    $payments = $paymentsStmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Calculate totals
    $subtotal = (float)$enquiry['total_amount'];
    $vatAmount = $vatEnabled ? ($subtotal * ($vatRate / 100)) : 0;
    $totalWithVat = $subtotal + $vatAmount;
    
    // Build payment details HTML
    $paymentDetailsHTML = '';
    if (!empty($payments)) {
        $paymentDetailsHTML = '<div style="margin-top: 20px; padding: 15px; background: #f8f9fa; border-radius: 5px;">
                    <h4 style="color: #0A1929; margin-top: 0;">Payment History</h4>';
        
        foreach ($payments as $payment) {
            $paymentDetailsHTML .= '<div style="display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px solid #ddd;">
                        <span>' . date('M j, Y', strtotime($payment['payment_date'])) . ' (' . ucfirst(str_replace('_', ' ', $payment['payment_method'])) . ')</span>
                        <span>' . $currency_symbol . ' ' . number_format($payment['total_amount'], 2) . '</span>
                    </div>';
        }
        
        $paymentDetailsHTML .= '</div>';
    }
    
    // Build deposit section HTML
    $depositSectionHTML = '';
    if (!empty($enquiry['deposit_required']) && $enquiry['deposit_required'] > 0) {
        $depositSectionHTML = '<div class="invoice-row">
                    <span class="invoice-label">Deposit Required:</span>
                    <span class="invoice-value">' . $currency_symbol . ' ' . number_format($enquiry['deposit_amount'], 2) . '</span>
                </div>
                <div class="invoice-row">
                    <span class="invoice-label">Deposit Paid:</span>
                    <span class="invoice-value" style="color: ' . ($enquiry['deposit_paid'] >= $enquiry['deposit_amount'] ? '#28a745' : '#dc3545') . '; font-weight: bold;">' . $currency_symbol . ' ' . number_format($enquiry['deposit_paid'] ?? 0, 2) . '</span>
                </div>';
    }
    
    // Build VAT section HTML
    $vatSectionHTML = '';
    if ($vatEnabled && $vatAmount > 0) {
        $vatSectionHTML = '<div class="invoice-row">
                    <span class="invoice-label">Subtotal (excl. VAT):</span>
                    <span class="invoice-value">' . $currency_symbol . ' ' . number_format($subtotal, 2) . '</span>
                </div>
                <div class="invoice-row">
                    <span class="invoice-label">VAT (' . number_format($vatRate, 2) . '%):</span>
                    <span class="invoice-value">' . $currency_symbol . ' ' . number_format($vatAmount, 2) . '</span>
                </div>';
        if ($vatNumber) {
            $vatSectionHTML .= '<div class="invoice-row">
                    <span class="invoice-label">VAT Number:</span>
                    <span class="invoice-value">' . htmlspecialchars($vatNumber) . '</span>
                </div>';
        }
    }
    
    return '
    <div class="invoice-container">
        <div class="invoice-header">
            <h1 style="color: #D4AF37; margin: 0 0 10px 0; font-size: 32px;">CONFERENCE INVOICE</h1>
            <p style="margin: 5px 0; font-size: 18px;">' . htmlspecialchars($site_name) . '</p>
            <p style="margin: 5px 0;">Invoice Number: <strong>' . htmlspecialchars($invoice_number) . '</strong></p>
            <p style="margin: 5px 0;">Date: ' . date('F j, Y') . '</p>
        </div>
        
        <div class="invoice-body">
            <div class="invoice-details">
                <h3 style="color: #0A1929; border-bottom: 2px solid #D4AF37; padding-bottom: 10px; margin-bottom: 20px;">Client Information</h3>
                
                <div class="invoice-row">
                    <span class="invoice-label">Company:</span>
                    <span class="invoice-value">' . htmlspecialchars($enquiry['company_name']) . '</span>
                </div>
                <div class="invoice-row">
                    <span class="invoice-label">Contact Person:</span>
                    <span class="invoice-value">' . htmlspecialchars($enquiry['contact_person']) . '</span>
                </div>
                <div class="invoice-row">
                    <span class="invoice-label">Email:</span>
                    <span class="invoice-value">' . htmlspecialchars($enquiry['email']) . '</span>
                </div>
                <div class="invoice-row">
                    <span class="invoice-label">Phone:</span>
                    <span class="invoice-value">' . htmlspecialchars($enquiry['phone']) . '</span>
                </div>
            </div>
            
            <div class="invoice-details">
                <h3 style="color: #0A1929; border-bottom: 2px solid #D4AF37; padding-bottom: 10px; margin-bottom: 20px;">Event Details</h3>
                
                <div class="invoice-row">
                    <span class="invoice-label">Reference:</span>
                    <span class="invoice-value" style="color: #D4AF37; font-weight: bold; font-size: 16px;">' . htmlspecialchars($enquiry['inquiry_reference']) . '</span>
                </div>
                <div class="invoice-row">
                    <span class="invoice-label">Conference Room:</span>
                    <span class="invoice-value">' . htmlspecialchars($enquiry['room_name']) . '</span>
                </div>
                <div class="invoice-row">
                    <span class="invoice-label">Event Date:</span>
                    <span class="invoice-value">' . $event_date . '</span>
                </div>
                <div class="invoice-row">
                    <span class="invoice-label">Event Time:</span>
                    <span class="invoice-value">' . date('H:i', strtotime($enquiry['start_time'])) . ' - ' . date('H:i', strtotime($enquiry['end_time'])) . '</span>
                </div>
                <div class="invoice-row">
                    <span class="invoice-label">Number of Attendees:</span>
                    <span class="invoice-value">' . (int) $enquiry['number_of_attendees'] . '</span>
                </div>
                <div class="invoice-row">
                    <span class="invoice-label">Event Type:</span>
                    <span class="invoice-value">' . htmlspecialchars($enquiry['event_type'] ?? 'N/A') . '</span>
                </div>
            </div>
            
            <div class="invoice-details">
                <h3 style="color: #0A1929; border-bottom: 2px solid #D4AF37; padding-bottom: 10px; margin-bottom: 20px;">Services</h3>
                
                <div class="invoice-row">
                    <span class="invoice-label">Catering:</span>
                    <span class="invoice-value">' . ($enquiry['catering_required'] ? 'Yes' : 'No') . '</span>
                </div>
                <div class="invoice-row">
                    <span class="invoice-label">AV Equipment:</span>
                    <span class="invoice-value">' . htmlspecialchars($enquiry['av_equipment'] ?? 'None') . '</span>
                </div>
            </div>
            
            <div class="total-section">
                ' . $depositSectionHTML . '
                ' . $vatSectionHTML . '
                <div class="total-row">
                    <span>Total Amount' . ($vatEnabled ? ' (incl. VAT)' : '') . ':</span>
                    <span>' . $currency_symbol . ' ' . number_format($totalWithVat, 2) . '</span>
                </div>
                <p style="margin: 15px 0 0 0; color: #666; font-size: 14px;">
                    <strong>Payment Status:</strong> <span style="color: #28a745; font-weight: bold;">PAID</span>
                </p>
                <p style="margin: 5px 0; color: #666; font-size: 14px;">
                    <strong>Amount Paid:</strong> ' . $currency_symbol . ' ' . number_format($enquiry['amount_paid'] ?? $totalWithVat, 2) . '
                </p>
                ' . ($enquiry['amount_due'] > 0 ? '<p style="margin: 5px 0; color: #dc3545; font-size: 14px;">
                    <strong>Balance Due:</strong> ' . $currency_symbol . ' ' . number_format($enquiry['amount_due'], 2) . '
                </p>' : '') . '
            </div>
            
            ' . $paymentDetailsHTML . '
        </div>
        
        <div class="footer">
            <p style="margin: 10px 0;"><strong>' . htmlspecialchars($site_name) . '</strong></p>
            <p style="margin: 5px 0;">' . htmlspecialchars($address) . '</p>
            <p style="margin: 5px 0;">Email: ' . htmlspecialchars($email_address) . ' | Phone: ' . htmlspecialchars($phone_number) . '</p>
            <p style="margin: 15px 0 0 0; color: #999; font-size: 12px;">
                Thank you for your payment! We look forward to hosting your event.
            </p>
        </div>
    </div>';
}

/**
 * Send conference payment invoice email
 *
 * @param int $enquiry_id Conference enquiry ID
 * @return array Result array with success status and message
 */
function sendConferenceInvoiceEmail($enquiry_id) {
    global $pdo;
    
    try {
        // Check if invoice emails are enabled
        $send_invoices = (bool)getEmailSetting('send_invoice_emails', 0);
        if (!$send_invoices) {
            return ['success' => true, 'message' => 'Invoice emails disabled'];
        }
        
        // Get enquiry details
        $stmt = $pdo->prepare("SELECT * FROM conference_inquiries WHERE id = ?");
        $stmt->execute([$enquiry_id]);
        $enquiry = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$enquiry) {
            throw new Exception("Conference enquiry not found");
        }
        
        // Generate invoice PDF/HTML
        $invoice_result = generateConferenceInvoicePDF($enquiry_id);
        if (!$invoice_result) {
            throw new Exception("Failed to generate invoice");
        }
        
        $invoice_file = $invoice_result['filepath'];
        $invoice_number = $invoice_result['invoice_number'];
        $invoice_path = $invoice_result['relative_path'];
        
        // Update the payment record with invoice path and invoice number
        $update_stmt = $pdo->prepare("
            UPDATE payments
            SET invoice_path = ?, invoice_number = ?, invoice_generated = 1
            WHERE booking_type = 'conference' AND booking_id = ?
            ORDER BY id DESC LIMIT 1
        ");
        $update_stmt->execute([$invoice_path, $invoice_number, $enquiry_id]);
        
        // Get invoice recipients (comma-separated)
        $invoice_recipients = getEmailSetting('invoice_recipients', '');
        $smtp_username = getEmailSetting('smtp_username', '');
        
        // Parse recipients from comma-separated string
        $cc_recipients = array_filter(array_map('trim', explode(',', $invoice_recipients)));
        
        // Always add SMTP username to CC list
        if (!empty($smtp_username) && !in_array($smtp_username, $cc_recipients)) {
            $cc_recipients[] = $smtp_username;
        }
        
        // Send invoice to client with CC recipients
        $result = sendConferenceInvoiceEmailToClient($enquiry, $invoice_file, $cc_recipients);
        
        return [
            'success' => $result['success'],
            'message' => $result['message'],
            'invoice_file' => $invoice_file,
            'invoice_number' => $invoice_number,
            'invoice_path' => $invoice_path,
            'cc_recipients' => $cc_recipients
        ];
        
    } catch (Exception $e) {
        error_log("Send Conference Invoice Email Error: " . $e->getMessage());
        return [
            'success' => false,
            'message' => $e->getMessage()
        ];
    }
}

/**
 * Send conference payment invoice email with custom CC recipients
 *
 * @param int $enquiry_id Conference enquiry ID
 * @param array $ccRecipients Array of CC email addresses
 * @return array Result array with success status and message
 */
function sendConferenceInvoiceEmailWithCC($enquiry_id, $ccRecipients = []) {
    global $pdo;
    
    try {
        // Check if invoice emails are enabled
        $send_invoices = (bool)getEmailSetting('send_invoice_emails', 0);
        if (!$send_invoices) {
            return ['success' => true, 'message' => 'Invoice emails disabled'];
        }
        
        // Get enquiry details
        $stmt = $pdo->prepare("SELECT * FROM conference_inquiries WHERE id = ?");
        $stmt->execute([$enquiry_id]);
        $enquiry = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$enquiry) {
            throw new Exception("Conference enquiry not found");
        }
        
        // Generate invoice PDF/HTML
        $invoice_result = generateConferenceInvoicePDF($enquiry_id);
        if (!$invoice_result) {
            throw new Exception("Failed to generate invoice");
        }
        
        $invoice_file = $invoice_result['filepath'];
        $invoice_number = $invoice_result['invoice_number'];
        $invoice_path = $invoice_result['relative_path'];
        
        // Update the payment record with invoice path and invoice number
        $update_stmt = $pdo->prepare("
            UPDATE payments
            SET invoice_path = ?, invoice_number = ?, invoice_generated = 1
            WHERE booking_type = 'conference' AND booking_id = ?
            ORDER BY id DESC LIMIT 1
        ");
        $update_stmt->execute([$invoice_path, $invoice_number, $enquiry_id]);
        
        // Send invoice to client with custom CC recipients
        $result = sendConferenceInvoiceEmailToClient($enquiry, $invoice_file, $ccRecipients);
        
        return [
            'success' => $result['success'],
            'message' => $result['message'],
            'invoice_file' => $invoice_file,
            'invoice_number' => $invoice_number,
            'invoice_path' => $invoice_path,
            'cc_recipients' => $ccRecipients
        ];
        
    } catch (Exception $e) {
        error_log("Send Conference Invoice Email with CC Error: " . $e->getMessage());
        return [
            'success' => false,
            'message' => $e->getMessage()
        ];
    }
}

/**
 * Send conference invoice email to client with CC recipients
 */
function sendConferenceInvoiceEmailToClient($enquiry, $invoice_file, $cc_recipients = []) {
    global $pdo, $email_from_name, $email_from_email, $email_site_name, $email_site_url;
    
    try {
        // Get conference room details
        $stmt = $pdo->prepare("SELECT * FROM conference_rooms WHERE id = ?");
        $stmt->execute([$enquiry['conference_room_id']]);
        $room = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $currency_symbol = getSetting('currency_symbol');
        
        // Prepare email content
        $htmlBody = '
        <div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;">
            <div style="background: linear-gradient(135deg, #0A1929 0%, #1a3a5c 100%); padding: 30px; text-align: center; border-radius: 10px 10px 0 0;">
                <h1 style="color: #D4AF37; margin: 0; font-size: 32px;">✓ PAYMENT CONFIRMED</h1>
                <p style="color: white; margin: 10px 0 0 0; font-size: 18px;">Thank you for your conference payment!</p>
            </div>
            
            <div style="background: #f8f9fa; padding: 30px; border:1px solid #ddd; border-top: none; border-radius: 0 0 10px 10px;">
                <p>Dear ' . htmlspecialchars($enquiry['contact_person']) . ',</p>
                
                <p>We are pleased to confirm that your payment has been received. Please find attached your official invoice/receipt for conference booking <strong>' . htmlspecialchars($enquiry['inquiry_reference']) . '</strong>.</p>
                
                <div style="background: white; padding: 20px; border-radius: 8px; margin: 20px 0; border-left: 4px solid #D4AF37;">
                    <h3 style="color: #0A1929; margin-top: 0;">Conference Summary</h3>
                    
                    <div style="display: flex; justify-content: space-between; padding: 8px 0; border-bottom:1px solid #eee;">
                        <span style="font-weight: bold; color: #333;">Conference Room:</span>
                        <span style="color: #666;">' . htmlspecialchars($room['name']) . '</span>
                    </div>
                    
                    <div style="display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px solid #eee;">
                        <span style="font-weight: bold; color: #333;">Event Date:</span>
                        <span style="color: #666;">' . date('F j, Y', strtotime($enquiry['event_date'])) . '</span>
                    </div>
                    
                    <div style="display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px solid #eee;">
                        <span style="font-weight: bold; color: #333;">Event Time:</span>
                        <span style="color: #666;">' . date('H:i', strtotime($enquiry['start_time'])) . ' - ' . date('H:i', strtotime($enquiry['end_time'])) . '</span>
                    </div>
                    
                    <div style="display: flex; justify-content: space-between; padding: 15px 0;">
                        <span style="font-weight: bold; color: #D4AF37; font-size: 18px;">Total Paid:</span>
                        <span style="color: #D4AF37; font-weight: bold; font-size: 18px;">' . $currency_symbol . ' ' . number_format($enquiry['total_amount'], 0) . '</span>
                    </div>
                </div>
                
                <div style="background: #d4edda; padding: 15px; border-left: 4px solid #28a745; border-radius: 5px; margin: 20px 0;">
                    <h3 style="color: #155724; margin-top: 0;">✅ Payment Status: PAID</h3>
                    <p style="color: #155724; margin: 0;">Your conference booking is now fully paid and confirmed. We look forward to hosting your event!</p>
                </div>
                
                <div style="background: #e7f3ff; padding: 15px; border-left: 4px solid #0d6efd; border-radius: 5px;">
                    <h3 style="color: #0d6efd; margin-top: 0;">Next Steps</h3>
                    <ul style="color: #0d6efd; margin: 10px 0; padding-left: 20px;">
                        <li>Please save your booking reference: <strong>' . htmlspecialchars($enquiry['inquiry_reference']) . '</strong></li>
                        <li>Arrive at least 30 minutes before your event start time</li>
                        <li>Contact us if you need to make any changes</li>
                    </ul>
                </div>
                
                <p style="margin-top: 30px;">If you have any questions, please contact us at <a href="mailto:' . htmlspecialchars($email_from_email) . '">' . htmlspecialchars($email_from_email) . '</a>.</p>
                
                <p style="margin-top: 20px;">We look forward to hosting your event at <strong>' . htmlspecialchars($email_site_name) . '</strong>!</p>
                
                <div style="text-align: center; margin-top: 40px; padding-top: 20px; border-top: 2px solid #0A1929;">
                    <p style="color: #666; font-size: 14px; margin: 5px 0;"><strong>The ' . htmlspecialchars($email_site_name) . ' Team</strong></p>
                    <p style="color: #666; font-size: 14px; margin: 5px 0;"><a href="' . htmlspecialchars($email_site_url) . '">' . htmlspecialchars($email_site_url) . '</a></p>
                </div>
            </div>
        </div>';
        
        // Send email with attachment and CC recipients
        return sendEmailWithAttachmentAndCC(
            $enquiry['email'],
            $enquiry['contact_person'],
            'Conference Payment Invoice - ' . htmlspecialchars($email_site_name) . ' [' . $enquiry['inquiry_reference'] . ']',
            $htmlBody,
            $invoice_file,
            $cc_recipients
        );
        
    } catch (Exception $e) {
        error_log("Send Conference Invoice to Client Error: " . $e->getMessage());
        return ['success' => false, 'message' => $e->getMessage()];
    }
}
