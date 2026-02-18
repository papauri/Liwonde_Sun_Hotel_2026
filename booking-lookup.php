<?php
/**
 * Booking Lookup Page
 * Allows guests to check their booking status using reference number and email
 * Also supports booking cancellation by guest
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once 'config/security.php';
require_once 'config/database.php';

sendSecurityHeaders();

$booking = null;
$error = null;
$success = null;
$search_performed = false;

// Handle booking lookup
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['lookup'])) {
    requireCsrfValidation();
    $search_performed = true;
    
    $reference = trim($_POST['booking_reference'] ?? '');
    $email = trim($_POST['guest_email'] ?? '');
    
    if (empty($reference) || empty($email)) {
        $error = 'Please enter both your booking reference and email address.';
    } else {
        try {
            $stmt = $pdo->prepare("
                SELECT b.*, r.name as room_name, r.image_url as room_image,
                       r.short_description as room_description
                FROM bookings b
                JOIN rooms r ON b.room_id = r.id
                WHERE b.booking_reference = ? AND b.guest_email = ?
            ");
            $stmt->execute([$reference, $email]);
            $booking = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$booking) {
                $error = 'No booking found with that reference and email combination. Please check your details and try again.';
            }
        } catch (PDOException $e) {
            error_log("Booking lookup error: " . $e->getMessage());
            $error = 'Unable to look up booking. Please try again later.';
        }
    }
}

// Handle guest cancellation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cancel_booking'])) {
    requireCsrfValidation();
    
    $reference = trim($_POST['booking_reference'] ?? '');
    $email = trim($_POST['guest_email'] ?? '');
    $cancel_reason = trim($_POST['cancel_reason'] ?? 'Cancelled by guest');
    
    if (!empty($reference) && !empty($email)) {
        try {
            // Verify booking exists and belongs to this guest
            $stmt = $pdo->prepare("
                SELECT b.*, r.name as room_name
                FROM bookings b
                JOIN rooms r ON b.room_id = r.id
                WHERE b.booking_reference = ? AND b.guest_email = ?
            ");
            $stmt->execute([$reference, $email]);
            $cancel_booking = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$cancel_booking) {
                $error = 'Booking not found.';
            } elseif (in_array($cancel_booking['status'], ['cancelled', 'checked-in', 'checked-out', 'no-show'])) {
                $error = 'This booking cannot be cancelled (current status: ' . ucfirst($cancel_booking['status']) . ').';
            } else {
                // Cancel the booking
                $update = $pdo->prepare("UPDATE bookings SET status = 'cancelled', updated_at = NOW() WHERE id = ?");
                $update->execute([$cancel_booking['id']]);
                
                // Restore room availability if confirmed
                if ($cancel_booking['status'] === 'confirmed') {
                    $pdo->prepare("UPDATE rooms SET rooms_available = rooms_available + 1 WHERE id = ? AND rooms_available < total_rooms")
                        ->execute([$cancel_booking['room_id']]);
                }
                
                // Send cancellation email
                require_once 'config/email.php';
                $email_result = sendBookingCancelledEmail($cancel_booking, $cancel_reason);
                
                // Log cancellation
                logCancellationToDatabase(
                    $cancel_booking['id'],
                    $cancel_booking['booking_reference'],
                    'room',
                    $cancel_booking['guest_email'],
                    0, // guest-initiated (no admin user)
                    'Guest cancelled: ' . $cancel_reason,
                    $email_result['success'],
                    $email_result['message']
                );
                
                $success = 'Your booking has been cancelled successfully. A confirmation email has been sent to your email address.';
                $booking = null; // Clear the booking display
            }
        } catch (PDOException $e) {
            error_log("Booking cancellation error: " . $e->getMessage());
            $error = 'Unable to cancel booking. Please contact us directly.';
        }
    }
}

$site_name = getSetting('site_name');
$currency_symbol = getSetting('currency_symbol');
$phone_main = getSetting('phone_main');
$email_reservations = getSetting('email_reservations');
$whatsapp_number = getSetting('whatsapp_number');

// Fetch policies for footer modals
$policies = [];
try {
    $policyStmt = $pdo->query("SELECT slug, title, summary, content FROM policies WHERE is_active = 1 ORDER BY display_order ASC, id ASC");
    $policies = $policyStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error fetching policies: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0, user-scalable=yes, viewport-fit=cover">
    <meta name="theme-color" content="#0A1929">
    <title>Check Booking Status | <?php echo htmlspecialchars($site_name); ?></title>
    
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;500;600;700&family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet" media="print" onload="this.media='all'">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" media="print" onload="this.media='all'">
    <link rel="stylesheet" href="css/header.css">
    <link rel="stylesheet" href="css/style.css">
    <link rel="stylesheet" href="css/footer.css">
    <style>
        .lookup-page { padding-top: 100px; min-height: 100vh; background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%); }
        .lookup-container { max-width: 700px; margin: 0 auto; padding: 0 20px 60px; }
        .lookup-header { text-align: center; margin-bottom: 40px; }
        .lookup-header h1 { font-family: 'Playfair Display', serif; font-size: 36px; color: var(--navy); margin-bottom: 12px; }
        .lookup-header p { color: #666; font-size: 16px; }
        .lookup-card {
            background: white; border-radius: 16px; padding: 40px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.08);
        }
        .lookup-card .form-group { margin-bottom: 20px; }
        .lookup-card label { display: block; font-weight: 600; color: var(--navy); margin-bottom: 8px; font-size: 14px; }
        .lookup-card .form-control {
            width: 100%; padding: 14px 16px; border: 2px solid #e0e0e0; border-radius: 10px;
            font-size: 16px; font-family: 'Poppins', sans-serif; transition: border-color 0.3s;
            box-sizing: border-box;
        }
        .lookup-card .form-control:focus { outline: none; border-color: var(--gold); }
        .btn-lookup {
            width: 100%; padding: 16px; background: linear-gradient(135deg, var(--gold) 0%, #c49b2e 100%);
            color: var(--deep-navy); border: none; border-radius: 10px; font-size: 16px;
            font-weight: 700; cursor: pointer; transition: all 0.3s;
        }
        .btn-lookup:hover { transform: translateY(-2px); box-shadow: 0 6px 20px rgba(212,175,55,0.4); }
        
        .alert { padding: 16px 20px; border-radius: 10px; margin-bottom: 24px; font-size: 14px; }
        .alert-error { background: #f8d7da; color: #842029; border-left: 4px solid #dc3545; }
        .alert-success { background: #d1e7dd; color: #0f5132; border-left: 4px solid #198754; }
        
        .booking-result {
            background: white; border-radius: 16px; padding: 32px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.08); margin-top: 24px;
        }
        .result-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px; padding-bottom: 16px; border-bottom: 2px solid #f0f0f0; }
        .result-header h2 { font-family: 'Playfair Display', serif; color: var(--navy); font-size: 22px; }
        .result-ref { font-family: 'Courier New', monospace; font-size: 18px; font-weight: 700; color: var(--gold); }
        
        .result-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; }
        .result-item label { display: block; font-size: 11px; color: #999; text-transform: uppercase; letter-spacing: 1px; margin-bottom: 4px; }
        .result-item .value { font-size: 16px; font-weight: 600; color: var(--navy); }
        
        .status-badge {
            display: inline-block; padding: 6px 16px; border-radius: 20px;
            font-size: 13px; font-weight: 600; text-transform: uppercase;
        }
        .status-pending { background: #fff3cd; color: #856404; }
        .status-confirmed { background: #d1e7dd; color: #0f5132; }
        .status-tentative { background: linear-gradient(135deg, #fff8e1 0%, #ffecb3 100%); color: var(--navy); }
        .status-checked-in { background: #cfe2ff; color: #084298; }
        .status-checked-out { background: #e2e3e5; color: #41464b; }
        .status-cancelled { background: #f8d7da; color: #842029; }
        .status-no-show { background: #e2e3e5; color: #41464b; }
        .status-paid { background: #d1e7dd; color: #0f5132; }
        .status-unpaid { background: #fff3cd; color: #856404; }
        
        .cancel-section {
            margin-top: 24px; padding-top: 24px; border-top: 2px solid #f0f0f0;
        }
        .cancel-section h4 { color: #dc3545; margin-bottom: 12px; }
        .btn-cancel-booking {
            padding: 12px 24px; background: #dc3545; color: white; border: none;
            border-radius: 8px; font-weight: 600; cursor: pointer; transition: all 0.3s;
        }
        .btn-cancel-booking:hover { background: #bb2d3b; }
        
        .contact-info { margin-top: 30px; text-align: center; color: #666; font-size: 14px; }
        .contact-info a { color: var(--gold); text-decoration: none; font-weight: 600; }
        
        @media (max-width: 768px) {
            .lookup-page { padding-top: 80px; }
            .lookup-card { padding: 24px; }
            .booking-result { padding: 20px; }
            .result-grid { grid-template-columns: 1fr; }
            .result-header { flex-direction: column; gap: 8px; }
        }
    </style>
</head>
<body class="lookup-page">
    <?php include 'includes/loader.php'; ?>
    <?php include 'includes/header.php'; ?>
    
    <div class="lookup-container">
        <div class="lookup-header">
            <h1><i class="fas fa-search" style="color: var(--gold);"></i> Check Booking Status</h1>
            <p>Enter your booking reference and email to view your reservation details</p>
        </div>
        
        <?php if ($error): ?>
            <div class="alert alert-error"><i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        
        <?php if ($success): ?>
            <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($success); ?></div>
        <?php endif; ?>
        
        <div class="lookup-card">
            <form method="POST">
                <?php echo getCsrfField(); ?>
                <input type="hidden" name="lookup" value="1">
                
                <div class="form-group">
                    <label for="booking_reference"><i class="fas fa-hashtag" style="color: var(--gold);"></i> Booking Reference</label>
                    <input type="text" id="booking_reference" name="booking_reference" class="form-control" 
                           placeholder="e.g. LSH20260001" required
                           value="<?php echo htmlspecialchars($_POST['booking_reference'] ?? $_GET['ref'] ?? ''); ?>">
                </div>
                
                <div class="form-group">
                    <label for="guest_email"><i class="fas fa-envelope" style="color: var(--gold);"></i> Email Address</label>
                    <input type="email" id="guest_email" name="guest_email" class="form-control" 
                           placeholder="The email used during booking" required
                           value="<?php echo htmlspecialchars($_POST['guest_email'] ?? ''); ?>">
                </div>
                
                <button type="submit" class="btn-lookup">
                    <i class="fas fa-search"></i> Look Up Booking
                </button>
            </form>
        </div>
        
        <?php if ($booking): ?>
        <div class="booking-result">
            <div class="result-header">
                <h2>Your Booking</h2>
                <div class="result-ref"><?php echo htmlspecialchars($booking['booking_reference']); ?></div>
            </div>
            
            <div class="result-grid">
                <div class="result-item">
                    <label>Room</label>
                    <div class="value"><?php echo htmlspecialchars($booking['room_name']); ?></div>
                </div>
                <div class="result-item">
                    <label>Guest Name</label>
                    <div class="value"><?php echo htmlspecialchars($booking['guest_name']); ?></div>
                </div>
                <div class="result-item">
                    <label>Check-in</label>
                    <div class="value"><?php echo date('D, M j, Y', strtotime($booking['check_in_date'])); ?></div>
                </div>
                <div class="result-item">
                    <label>Check-out</label>
                    <div class="value"><?php echo date('D, M j, Y', strtotime($booking['check_out_date'])); ?></div>
                </div>
                <div class="result-item">
                    <label>Nights</label>
                    <div class="value"><?php echo $booking['number_of_nights']; ?> night<?php echo $booking['number_of_nights'] > 1 ? 's' : ''; ?></div>
                </div>
                <div class="result-item">
                    <label>Guests</label>
                    <div class="value"><?php echo $booking['number_of_guests']; ?></div>
                </div>
                <div class="result-item">
                    <label>Total Amount</label>
                    <div class="value" style="color: var(--gold);"><?php echo $currency_symbol; ?><?php echo number_format($booking['total_amount'], 0); ?></div>
                </div>
                <div class="result-item">
                    <label>Booking Status</label>
                    <div class="value">
                        <span class="status-badge status-<?php echo $booking['status']; ?>"><?php echo ucfirst(str_replace('-', ' ', $booking['status'])); ?></span>
                    </div>
                </div>
                <div class="result-item">
                    <label>Payment Status</label>
                    <div class="value">
                        <span class="status-badge status-<?php echo $booking['payment_status']; ?>">
                            <?php echo ucfirst($booking['payment_status'] ?: 'unpaid'); ?>
                        </span>
                    </div>
                </div>
                <div class="result-item">
                    <label>Booked On</label>
                    <div class="value"><?php echo date('M j, Y', strtotime($booking['created_at'])); ?></div>
                </div>
            </div>
            
            <?php if ($booking['special_requests']): ?>
            <div style="margin-top: 20px;">
                <label style="display: block; font-size: 11px; color: #999; text-transform: uppercase; letter-spacing: 1px; margin-bottom: 4px;">Special Requests</label>
                <div style="font-size: 14px; color: #333; line-height: 1.6;"><?php echo nl2br(htmlspecialchars($booking['special_requests'])); ?></div>
            </div>
            <?php endif; ?>
            
            <?php if ($booking['status'] === 'tentative' && $booking['tentative_expires_at']): ?>
            <div style="margin-top: 20px; padding: 16px; background: #fff8e1; border-radius: 10px; border-left: 4px solid var(--gold);">
                <strong style="color: var(--navy);"><i class="fas fa-clock" style="color: var(--gold);"></i> Tentative Booking</strong>
                <p style="margin: 8px 0 0; color: #666; font-size: 14px;">
                    This booking expires on <strong><?php echo date('M j, Y \a\t g:i A', strtotime($booking['tentative_expires_at'])); ?></strong>.
                    Please contact us to confirm before expiry.
                </p>
            </div>
            <?php endif; ?>
            
            <?php if (in_array($booking['status'], ['pending', 'confirmed', 'tentative'])): ?>
            <div class="cancel-section">
                <h4><i class="fas fa-exclamation-triangle"></i> Need to Cancel?</h4>
                <p style="color: #666; font-size: 14px; margin-bottom: 16px;">
                    If you need to cancel your reservation, please provide a reason below.
                </p>
                <form method="POST" onsubmit="return confirm('Are you sure you want to cancel this booking? This action cannot be undone.')">
                    <?php echo getCsrfField(); ?>
                    <input type="hidden" name="cancel_booking" value="1">
                    <input type="hidden" name="booking_reference" value="<?php echo htmlspecialchars($booking['booking_reference']); ?>">
                    <input type="hidden" name="guest_email" value="<?php echo htmlspecialchars($booking['guest_email']); ?>">
                    <div class="form-group" style="margin-bottom: 12px;">
                        <textarea name="cancel_reason" class="form-control" rows="2" placeholder="Reason for cancellation (optional)" style="width:100%; box-sizing:border-box; padding: 10px; border: 2px solid #e0e0e0; border-radius: 8px; font-family: 'Poppins', sans-serif;"></textarea>
                    </div>
                    <button type="submit" class="btn-cancel-booking">
                        <i class="fas fa-times"></i> Cancel My Booking
                    </button>
                </form>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>
        
        <div class="contact-info">
            <p>Can't find your booking? Contact us:</p>
            <p>
                <a href="mailto:<?php echo htmlspecialchars($email_reservations); ?>"><i class="fas fa-envelope"></i> <?php echo htmlspecialchars($email_reservations); ?></a>
                &nbsp;|&nbsp;
                <a href="tel:<?php echo htmlspecialchars($phone_main); ?>"><i class="fas fa-phone"></i> <?php echo htmlspecialchars($phone_main); ?></a>
                <?php if ($whatsapp_number): ?>
                &nbsp;|&nbsp;
                <a href="https://wa.me/<?php echo preg_replace('/[^0-9]/', '', $whatsapp_number); ?>" target="_blank"><i class="fab fa-whatsapp"></i> WhatsApp</a>
                <?php endif; ?>
            </p>
        </div>
    </div>
    
    <?php include 'includes/footer.php'; ?>
    <?php include 'includes/scroll-to-top.php'; ?>
</body>
</html>
