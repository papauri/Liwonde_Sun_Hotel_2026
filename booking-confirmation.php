<?php
// Load security configuration first (before session_start)
require_once 'config/security.php';

session_start();
require_once 'config/database.php';

// Send security headers
sendSecurityHeaders();

// Get booking reference from URL
$booking_reference = $_GET['ref'] ?? null;

if (!$booking_reference) {
    header('Location: booking.php');
    exit;
}

// Fetch booking details
try {
    $stmt = $pdo->prepare("
        SELECT b.*, r.name as room_name, r.image_url as room_image
        FROM bookings b
        JOIN rooms r ON b.room_id = r.id
        WHERE b.booking_reference = ?
    ");
    $stmt->execute([$booking_reference]);
    $booking = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$booking) {
        $error = "Booking not found.";
    }
} catch (PDOException $e) {
    $error = "Unable to retrieve booking details.";
}

$site_name = getSetting('site_name');
$currency_symbol = getSetting('currency_symbol');
$phone_main = getSetting('phone_main');
$email_reservations = getSetting('email_reservations');
$whatsapp_number = getSetting('whatsapp_number');
$payment_policy = getSetting('payment_policy');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0, user-scalable=yes, viewport-fit=cover">
    <meta name="theme-color" content="#0A1929">
    <title>Booking Confirmed | <?php echo htmlspecialchars($site_name); ?></title>
    
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;500;600;700&family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet" media="print" onload="this.media='all'">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" media="print" onload="this.media='all'">
    <link rel="stylesheet" href="css/theme-dynamic.php">
    <link rel="stylesheet" href="css/header.css">
    <link rel="stylesheet" href="css/style.css">
    <link rel="stylesheet" href="css/footer.css">
    <style>
        .confirmation-page {
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            min-height: 100vh;
            padding: 80px 20px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .confirmation-container {
            max-width: 700px;
            width: 100%;
        }
        .success-icon {
            text-align: center;
            margin-bottom: 30px;
            animation: scaleIn 0.6s ease-out;
        }
        .success-icon i {
            font-size: 80px;
            color: #28a745;
            background: white;
            width: 140px;
            height: 140px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            box-shadow: 0 10px 40px rgba(40, 167, 69, 0.3);
        }
        .success-icon.tentative i {
            color: var(--gold);
            box-shadow: 0 10px 40px rgba(212, 175, 55, 0.3);
        }
        @keyframes scaleIn {
            from {
                transform: scale(0);
                opacity: 0;
            }
            to {
                transform: scale(1);
                opacity: 1;
            }
        }
        .confirmation-card {
            background: white;
            border-radius: 20px;
            padding: 50px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.1);
            animation: slideUp 0.6s ease-out;
        }
        @keyframes slideUp {
            from {
                transform: translateY(30px);
                opacity: 0;
            }
            to {
                transform: translateY(0);
                opacity: 1;
            }
        }
        .confirmation-card h1 {
            font-family: var(--font-serif);
            font-size: 32px;
            color: var(--navy);
            text-align: center;
            margin-bottom: 10px;
        }
        .confirmation-card .subtitle {
            text-align: center;
            color: #666;
            font-size: 16px;
            margin-bottom: 40px;
        }
        .booking-reference-box {
            background: linear-gradient(135deg, var(--deep-navy) 0%, var(--navy) 100%);
            padding: 24px;
            border-radius: 12px;
            text-align: center;
            margin-bottom: 40px;
        }
        .booking-reference-box label {
            display: block;
            color: var(--gold);
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 2px;
            margin-bottom: 8px;
        }
        .booking-reference-box .reference-number {
            font-family: 'Courier New', monospace;
            font-size: 28px;
            font-weight: 700;
            color: white;
            letter-spacing: 3px;
        }
        .booking-details-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 24px;
            margin-bottom: 32px;
        }
        .detail-item {
            padding: 16px;
            background: #f8f9fa;
            border-radius: 10px;
            border-left: 4px solid var(--gold);
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
        .detail-item.full-width {
            grid-column: 1 / -1;
        }
        .payment-info {
            background: #fff3cd;
            border-left: 4px solid #ffc107;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 32px;
        }
        .payment-info h3 {
            margin: 0 0 12px 0;
            color: #856404;
            font-size: 16px;
            font-weight: 600;
        }
        .payment-info p {
            margin: 0;
            color: #856404;
            font-size: 14px;
            line-height: 1.6;
        }
        .action-buttons {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 12px;
            margin-top: 32px;
        }
        .btn {
            padding: 14px 24px;
            border-radius: 10px;
            font-weight: 600;
            text-decoration: none;
            text-align: center;
            transition: all 0.3s ease;
            border: none;
            cursor: pointer;
            font-size: 14px;
        }
        .btn-primary {
            background: linear-gradient(135deg, var(--gold) 0%, #c49b2e 100%);
            color: var(--deep-navy);
        }
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(212, 175, 55, 0.4);
        }
        .btn-secondary {
            background: white;
            color: var(--navy);
            border: 2px solid var(--navy);
        }
        .btn-secondary:hover {
            background: var(--navy);
            color: white;
        }
        .btn-whatsapp {
            background: #25d366;
            color: white;
        }
        .btn-whatsapp:hover {
            background: #20ba5a;
        }
        .next-steps {
            margin-top: 40px;
            padding-top: 40px;
            border-top: 2px solid #e0e0e0;
        }
        .next-steps h3 {
            font-family: var(--font-serif);
            color: var(--navy);
            margin-bottom: 20px;
            font-size: 20px;
        }
        .next-steps ol {
            padding-left: 24px;
            color: #666;
            line-height: 1.8;
        }
        .next-steps ol li {
            margin-bottom: 10px;
        }

        @media print {
            body { background: white !important; padding: 0 !important; }
            .confirmation-container { max-width: 100%; }
            .confirmation-card { box-shadow: none; border: 1px solid #ddd; }
            .success-icon { margin-bottom: 15px; }
            .success-icon i { font-size: 40px; width: 70px; height: 70px; box-shadow: none; }
            .action-buttons, .next-steps, .scroll-to-top { display: none !important; }
            .booking-reference-box { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            .detail-item { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        }

        /* Tentative Booking Styles */
        .tentative-badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: linear-gradient(135deg, var(--gold) 0%, #c49b2e 100%);
            color: var(--deep-navy);
            padding: 8px 16px;
            border-radius: 20px;
            font-weight: 600;
            font-size: 14px;
            margin-bottom: 20px;
        }
        .tentative-info-box {
            background: linear-gradient(135deg, #fff8e1 0%, #ffecb3 100%);
            border-left: 4px solid var(--gold);
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 24px;
        }
        .tentative-info-box h3 {
            margin: 0 0 12px 0;
            color: var(--navy);
            font-size: 16px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .tentative-info-box p {
            margin: 0;
            color: #666;
            font-size: 14px;
            line-height: 1.6;
        }
        .tentative-info-box .expires-at {
            margin-top: 12px;
            padding-top: 12px;
            border-top: 1px solid rgba(212, 175, 55, 0.3);
            font-weight: 600;
            color: var(--navy);
        }
        .tentative-info-box .expires-at i {
            color: #dc3545;
            margin-right: 6px;
        }
        .booking-type-indicator {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 1px;
            margin-left: 10px;
        }
        .booking-type-indicator.standard {
            background: #d4edda;
            color: #155724;
        }
        .booking-type-indicator.tentative {
            background: linear-gradient(135deg, #fff8e1 0%, #ffecb3 100%);
            color: var(--navy);
        }

        @media (max-width: 768px) {
            .confirmation-card {
                padding: 30px 24px;
            }
            .booking-details-grid {
                grid-template-columns: 1fr;
            }
            .action-buttons {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body class="confirmation-page">
    <?php if (isset($error)): ?>
    <div class="confirmation-container">
        <div class="confirmation-card">
            <div style="text-align: center;">
                <i class="fas fa-exclamation-circle" style="font-size: 60px; color: #dc3545; margin-bottom: 20px;"></i>
                <h1>Error</h1>
                <p><?php echo htmlspecialchars($error); ?></p>
                <a href="booking.php" class="btn btn-primary" style="display: inline-block; margin-top: 20px;">
                    Back to Booking
                </a>
            </div>
        </div>
    </div>
    <?php else: ?>
    <?php
        $is_tentative = ($booking['status'] === 'tentative' || $booking['is_tentative'] == 1);
        $icon_class = $is_tentative ? 'fa-clock' : 'fa-check-circle';
        $icon_class_wrapper = $is_tentative ? 'tentative' : '';
        $heading = $is_tentative ? 'Tentative Booking Received!' : 'Booking Confirmed!';
        $subtitle = $is_tentative
            ? 'Your room has been placed on temporary hold. We\'ll send you a reminder before expiration.'
            : 'Thank you for choosing ' . htmlspecialchars($site_name) . '. Your reservation has been received.';
    ?>
    <div class="confirmation-container">
        <div class="success-icon <?php echo $icon_class_wrapper; ?>">
            <i class="fas <?php echo $icon_class; ?>"></i>
        </div>

        <div class="confirmation-card">
            <h1>
                <?php echo $heading; ?>
                <span class="booking-type-indicator <?php echo $is_tentative ? 'tentative' : 'standard'; ?>">
                    <?php echo $is_tentative ? 'Tentative' : 'Standard'; ?>
                </span>
            </h1>
            <p class="subtitle"><?php echo $subtitle; ?></p>

            <?php if ($is_tentative && $booking['tentative_expires_at']): ?>
            <div class="tentative-badge">
                <i class="fas fa-hourglass-half"></i>
                Room on Hold
            </div>
            <?php endif; ?>

            <div class="booking-reference-box">
                <label>Booking Reference</label>
                <div class="reference-number"><?php echo htmlspecialchars($booking['booking_reference']); ?></div>
            </div>

            <div class="booking-details-grid">
                <div class="detail-item full-width">
                    <label>Room</label>
                    <div class="value"><?php echo htmlspecialchars($booking['room_name']); ?></div>
                </div>
                <div class="detail-item">
                    <label>Guest Name</label>
                    <div class="value"><?php echo htmlspecialchars($booking['guest_name']); ?></div>
                </div>
                <div class="detail-item">
                    <label>Email</label>
                    <div class="value"><?php echo htmlspecialchars($booking['guest_email']); ?></div>
                </div>
                <div class="detail-item">
                    <label>Check-in</label>
                    <div class="value"><?php echo date('M j, Y', strtotime($booking['check_in_date'])); ?></div>
                </div>
                <div class="detail-item">
                    <label>Check-out</label>
                    <div class="value"><?php echo date('M j, Y', strtotime($booking['check_out_date'])); ?></div>
                </div>
                <div class="detail-item">
                    <label>Number of Nights</label>
                    <div class="value"><?php echo $booking['number_of_nights']; ?> <?php echo $booking['number_of_nights'] == 1 ? 'night' : 'nights'; ?></div>
                </div>
                <div class="detail-item">
                    <label>Number of Guests</label>
                    <div class="value"><?php echo $booking['number_of_guests']; ?> <?php echo $booking['number_of_guests'] == 1 ? 'guest' : 'guests'; ?></div>
                </div>
                <div class="detail-item full-width">
                    <label>Total Amount</label>
                    <div class="value" style="font-size: 24px; color: var(--gold);">
                        <?php echo $currency_symbol; ?><?php echo number_format($booking['total_amount'], 0); ?>
                    </div>
                </div>
                <div class="detail-item">
                    <label>Check-in Time</label>
                    <div class="value"><?php echo htmlspecialchars(getSetting('check_in_time', '2:00 PM')); ?></div>
                </div>
                <div class="detail-item">
                    <label>Check-out Time</label>
                    <div class="value"><?php echo htmlspecialchars(getSetting('check_out_time', '11:00 AM')); ?></div>
                </div>
            </div>

            <?php if ($is_tentative && $booking['tentative_expires_at']): ?>
            <div class="tentative-info-box">
                <h3><i class="fas fa-clock"></i> Tentative Booking Details</h3>
                <p>
                    Your room has been placed on temporary hold. You'll receive a reminder email before expiration.
                    To confirm this booking, please contact us before the expiration time.
                </p>
                <div class="expires-at">
                    <i class="fas fa-exclamation-triangle"></i>
                    Expires: <?php echo date('M j, Y \a\t g:i A', strtotime($booking['tentative_expires_at'])); ?>
                </div>
            </div>
            <?php endif; ?>

            <div class="payment-info">
                <h3><i class="fas fa-info-circle"></i> <?php echo $is_tentative ? 'Next Steps' : 'Payment Information'; ?></h3>
                <p>
                    <?php if ($is_tentative): ?>
                        <strong>1. Confirm your booking:</strong> Contact us before expiration to convert this to a confirmed reservation.<br>
                        <strong>2. Payment:</strong> Once confirmed, payment of <?php echo $currency_symbol . number_format($booking['total_amount'], 0); ?> will be collected at check-in.<br>
                        <strong>3. Reminder:</strong> You'll receive a reminder email <?php echo (int)getSetting('tentative_reminder_hours', 24); ?> hours before expiration.<br>
                        <strong>4. Questions?</strong> Contact us anytime at <?php echo htmlspecialchars($phone_main); ?>.
                    <?php else: ?>
                        <?php echo getSetting('payment_policy', 'Payment will be made at the hotel upon arrival.<br>We accept cash payments only. Please bring the total amount of <strong>' . $currency_symbol . number_format($booking['total_amount'], 0) . '</strong> with you.'); ?>
                    <?php endif; ?>
                </p>
            </div>

            <div class="action-buttons">
                <a href="tel:<?php echo str_replace(' ', '', $phone_main); ?>" class="btn btn-secondary">
                    <i class="fas fa-phone"></i> Call Hotel
                </a>
                <a href="https://wa.me/<?php echo $whatsapp_number; ?>?text=Hi, I have a booking (<?php echo $booking['booking_reference']; ?>)" class="btn btn-whatsapp" target="_blank">
                    <i class="fab fa-whatsapp"></i> WhatsApp
                </a>
                <a href="mailto:<?php echo $email_reservations; ?>?subject=Booking <?php echo $booking['booking_reference']; ?>" class="btn btn-secondary">
                    <i class="fas fa-envelope"></i> Email
                </a>
                <button onclick="window.print()" class="btn btn-secondary">
                    <i class="fas fa-print"></i> Print
                </button>
                <a href="index.php" class="btn btn-primary">
                    <i class="fas fa-home"></i> Back to Home
                </a>
            </div>

            
            <div class="next-steps">
                <h3>What Happens Next?</h3>
                <ol>
                    <?php if ($is_tentative): ?>
                        <li><strong>Tentative booking email sent</strong> to <?php echo htmlspecialchars($booking['guest_email']); ?> - please check your inbox</li>
                        <li><strong>Room is on hold</strong> until <?php echo date('M j, Y \a\t g:i A', strtotime($booking['tentative_expires_at'])); ?></li>
                        <li>You'll receive a <strong>reminder email</strong> <?php echo (int)getSetting('tentative_reminder_hours', 24); ?> hours before expiration</li>
                        <li><strong>Contact us</strong> before expiration to confirm your booking and secure your reservation</li>
                        <li>Once confirmed, payment of <strong><?php echo $currency_symbol; ?><?php echo number_format($booking['total_amount'], 0); ?></strong> will be collected at check-in</li>
                    <?php else: ?>
                        <li><strong>Confirmation email sent</strong> to <?php echo htmlspecialchars($booking['guest_email']); ?> - please check your inbox</li>
                        <li>Our reception team will review your booking and may contact you to confirm details</li>
                        <li>Please save your booking reference: <strong><?php echo $booking['booking_reference']; ?></strong></li>
                        <li>Arrive on your check-in date and present your booking reference at reception</li>
                        <li>Payment of <strong><?php echo $currency_symbol; ?><?php echo number_format($booking['total_amount'], 0); ?></strong> will be collected at check-in</li>
                    <?php endif; ?>
                </ol>
            </div>

            <p style="text-align: center; margin-top: 32px; color: #999; font-size: 13px;">
                <i class="fas fa-question-circle"></i> Questions? Contact us at <?php echo htmlspecialchars($phone_main); ?>
            </p>
        </div>
    </div>
    <?php endif; ?>

    <script>
        // Optional: Auto-print confirmation
        // window.print();
    </script>

    <?php include 'includes/scroll-to-top.php'; ?>
</body>
</html>
