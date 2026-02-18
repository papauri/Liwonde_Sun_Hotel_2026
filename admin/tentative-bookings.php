<?php
// Include admin initialization (PHP-only, no HTML output)
require_once 'admin-init.php';

$user = [
    'id' => $_SESSION['admin_user_id'],
    'username' => $_SESSION['admin_username'],
    'role' => $_SESSION['admin_role'],
    'full_name' => $_SESSION['admin_full_name']
];
$message = '';
$error = '';

// Handle booking actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $action = $_POST['action'] ?? '';
        $booking_id = (int)($_POST['id'] ?? 0);

        if ($booking_id <= 0) {
            throw new Exception('Invalid booking id');
        }

        if ($action === 'convert') {
            // Get booking details
            $stmt = $pdo->prepare("SELECT * FROM bookings WHERE id = ?");
            $stmt->execute([$booking_id]);
            $booking = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$booking) {
                throw new Exception('Booking not found');
            }
            
            if ($booking['status'] !== 'tentative' || $booking['is_tentative'] != 1) {
                throw new Exception('This is not a tentative booking');
            }
            
            // Convert to confirmed
            $update_stmt = $pdo->prepare("UPDATE bookings SET status = 'confirmed', is_tentative = 0 WHERE id = ?");
            $update_stmt->execute([$booking_id]);
            
            // Log the conversion
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
            
            // Send conversion email
            require_once '../config/email.php';
            $email_result = sendTentativeBookingConvertedEmail($booking);
            
            if ($email_result['success']) {
                $message = 'Tentative booking converted to confirmed! Conversion email sent to guest.';
            } else {
                $message = 'Tentative booking converted! (Email failed: ' . $email_result['message'] . ')';
            }
            
        } elseif ($action === 'cancel') {
            // Get booking details
            $stmt = $pdo->prepare("SELECT * FROM bookings WHERE id = ?");
            $stmt->execute([$booking_id]);
            $booking = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$booking) {
                throw new Exception('Booking not found');
            }
            
            // Cancel booking
            $update_stmt = $pdo->prepare("UPDATE bookings SET status = 'cancelled' WHERE id = ?");
            $update_stmt->execute([$booking_id]);
            
            // Log the cancellation
            $log_stmt = $pdo->prepare("
                INSERT INTO tentative_booking_log (
                    booking_id, action, action_by, action_at, notes
                ) VALUES (?, 'cancelled', ?, NOW(), ?)
            ");
            $log_stmt->execute([
                $booking_id,
                $user['id'],
                'Cancelled by admin'
            ]);
            
            $message = 'Tentative booking cancelled successfully.';
        }

    } catch (Exception $e) {
        $error = 'Error: ' . $e->getMessage();
    }
}

// Fetch tentative bookings
try {
    $stmt = $pdo->query("
        SELECT b.*, r.name as room_name 
        FROM bookings b
        LEFT JOIN rooms r ON b.room_id = r.id
        WHERE b.status = 'tentative' OR b.is_tentative = 1
        ORDER BY b.tentative_expires_at ASC
    ");
    $tentative_bookings = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    $error = 'Error fetching tentative bookings: ' . $e->getMessage();
    $tentative_bookings = [];
}

$site_name = getSetting('site_name');
$currency_symbol = getSetting('currency_symbol');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tentative Bookings | <?php echo htmlspecialchars($site_name); ?> Admin</title>
    
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;500;600;700&family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../css/style.css">
    <link rel="stylesheet" href="../css/theme-dynamic.php">
    <link rel="stylesheet" href="css/admin-styles.css">
    <link rel="stylesheet" href="css/admin-components.css">
    
    <style>
        .tentative-page {
            background: #f8f9fa;
            min-height: 100vh;
            padding: 32px;
        }
        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 32px;
        }
        .page-title {
            font-family: 'Playfair Display', serif;
            font-size: 32px;
            color: var(--navy);
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .page-title i {
            color: var(--gold);
        }
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
        .bookings-container {
            background: white;
            border-radius: 12px;
            padding: 24px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
        }
        .booking-table {
            width: 100%;
            border-collapse: collapse;
        }
        .booking-table th {
            background: linear-gradient(135deg, var(--deep-navy) 0%, var(--navy) 100%);
            color: white;
            padding: 16px;
            text-align: left;
            font-size: 13px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        .booking-table td {
            padding: 16px;
            border-bottom: 1px solid #e0e0e0;
            vertical-align: middle;
        }
        .booking-table tbody tr:hover {
            background: #f8f9fa;
        }
        .booking-table tbody tr.expires-soon {
            background: linear-gradient(90deg, rgba(220, 53, 69, 0.05) 0%, white 10%);
        }
        .booking-table tbody tr.expired {
            background: linear-gradient(90deg, rgba(108, 117, 125, 0.1) 0%, white 10%);
            opacity: 0.7;
        }
        .badge {
            padding: 6px 12px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
        }
        .badge-tentative {
            background: linear-gradient(135deg, var(--gold) 0%, #c49b2e 100%);
            color: var(--deep-navy);
        }
        .expires-badge {
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 10px;
            font-weight: 600;
        }
        .expires-soon-badge {
            background: #dc3545;
            color: white;
            animation: pulse 2s infinite;
        }
        .expires-normal-badge {
            background: #28a745;
            color: white;
        }
        .expires-expired-badge {
            background: #6c757d;
            color: white;
        }
        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.7; }
        }
        .action-buttons {
            display: flex;
            gap: 8px;
        }
        .btn {
            padding: 8px 16px;
            border: none;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        .btn-convert {
            background: #28a745;
            color: white;
        }
        .btn-convert:hover {
            background: #229954;
            transform: translateY(-1px);
        }
        .btn-cancel {
            background: #dc3545;
            color: white;
        }
        .btn-cancel:hover {
            background: #c82333;
            transform: translateY(-1px);
        }
        .btn-view {
            background: var(--gold);
            color: var(--deep-navy);
        }
        .btn-view:hover {
            background: #c19b2e;
            transform: translateY(-1px);
        }
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: #999;
        }
        .empty-state i {
            font-size: 64px;
            margin-bottom: 20px;
            color: #ddd;
        }
        .empty-state h3 {
            font-size: 20px;
            color: #666;
            margin-bottom: 8px;
        }
        @media (max-width: 768px) {
            .tentative-page {
                padding: 16px;
            }
            .page-title {
                font-size: 24px;
            }
            .stats-grid {
                grid-template-columns: 1fr;
            }
            .booking-table {
                font-size: 12px;
            }
            .booking-table th,
            .booking-table td {
                padding: 10px;
            }
        }
    </style>
</head>
<body>
    <?php require_once 'includes/admin-header.php'; ?>

    <div class="tentative-page">
        <div class="page-header">
            <h1 class="page-title">
                <i class="fas fa-clock"></i>
                Tentative Bookings
            </h1>
            <a href="bookings.php" class="btn btn-view">
                <i class="fas fa-arrow-left"></i> Back to All Bookings
            </a>
        </div>

        <?php if ($message): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i>
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="alert alert-error">
                <i class="fas fa-exclamation-circle"></i>
                <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>

        <div class="stats-grid">
            <div class="stat-card">
                <h3>Total Tentative</h3>
                <div class="number"><?php echo count($tentative_bookings); ?></div>
            </div>
            <?php
                $expires_soon = 0;
                $expired = 0;
                $now = new DateTime();
                foreach ($tentative_bookings as $booking) {
                    if ($booking['tentative_expires_at']) {
                        $expires_at = new DateTime($booking['tentative_expires_at']);
                        $hours_until_expiry = ($expires_at->getTimestamp() - $now->getTimestamp()) / 3600;
                        if ($hours_until_expiry <= 0) {
                            $expired++;
                        } elseif ($hours_until_expiry <= 24) {
                            $expires_soon++;
                        }
                    }
                }
            ?>
            <div class="stat-card" style="border-left-color: #dc3545;">
                <h3>Expires Soon (24h)</h3>
                <div class="number" style="color: #dc3545;"><?php echo $expires_soon; ?></div>
            </div>
            <div class="stat-card" style="border-left-color: #6c757d;">
                <h3>Expired</h3>
                <div class="number" style="color: #6c757d;"><?php echo $expired; ?></div>
            </div>
        </div>

        <div class="bookings-container">
            <?php if (!empty($tentative_bookings)): ?>
                <table class="booking-table">
                    <thead>
                        <tr>
                            <th style="width: 120px;">Reference</th>
                            <th style="width: 200px;">Guest Name</th>
                            <th style="width: 180px;">Room</th>
                            <th style="width: 140px;">Check In</th>
                            <th style="width: 140px;">Check Out</th>
                            <th style="width: 80px;">Nights</th>
                            <th style="width: 80px;">Guests</th>
                            <th style="width: 120px;">Total</th>
                            <th style="width: 180px;">Expires At</th>
                            <th style="width: 100px;">Status</th>
                            <th style="width: 250px;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                            $now = new DateTime();
                            foreach ($tentative_bookings as $booking): 
                                $expires_at = new DateTime($booking['tentative_expires_at']);
                                $hours_until_expiry = ($expires_at->getTimestamp() - $now->getTimestamp()) / 3600;
                                $is_expired = $hours_until_expiry <= 0;
                                $expires_soon = !$is_expired && $hours_until_expiry <= 24;
                                $row_class = $is_expired ? 'expired' : ($expires_soon ? 'expires-soon' : '');
                        ?>
                            <tr class="<?php echo $row_class; ?>">
                                <td><strong><?php echo htmlspecialchars($booking['booking_reference']); ?></strong></td>
                                <td>
                                    <?php echo htmlspecialchars($booking['guest_name']); ?>
                                    <br><small style="color: #666;"><?php echo htmlspecialchars($booking['guest_email']); ?></small>
                                </td>
                                <td><?php echo htmlspecialchars($booking['room_name']); ?></td>
                                <td><?php echo date('M d, Y', strtotime($booking['check_in_date'])); ?></td>
                                <td><?php echo date('M d, Y', strtotime($booking['check_out_date'])); ?></td>
                                <td><?php echo $booking['number_of_nights']; ?></td>
                                <td><?php echo $booking['number_of_guests']; ?></td>
                                <td><strong><?php echo $currency_symbol; ?><?php echo number_format($booking['total_amount'], 0); ?></strong></td>
                                <td>
                                    <?php echo date('M d, H:i', strtotime($booking['tentative_expires_at'])); ?>
                                    <br>
                                    <?php if ($is_expired): ?>
                                        <span class="expires-badge expires-expired-badge">Expired</span>
                                    <?php elseif ($expires_soon): ?>
                                        <span class="expires-badge expires-soon-badge">Expires Soon!</span>
                                    <?php else: ?>
                                        <span class="expires-badge expires-normal-badge">Active</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="badge badge-tentative">Tentative</span>
                                </td>
                                <td>
                                    <div class="action-buttons">
                                        <?php if (!$is_expired): ?>
                                            <form method="POST" style="display: inline;">
                                                <input type="hidden" name="action" value="convert">
                                                <input type="hidden" name="id" value="<?php echo $booking['id']; ?>">
                                                <button type="submit" class="btn btn-convert" onclick="return confirm('Convert this tentative booking to confirmed?')">
                                                    <i class="fas fa-check"></i> Convert
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                        <form method="POST" style="display: inline;">
                                            <input type="hidden" name="action" value="cancel">
                                            <input type="hidden" name="id" value="<?php echo $booking['id']; ?>">
                                            <button type="submit" class="btn btn-cancel" onclick="return confirm('Cancel this tentative booking?')">
                                                <i class="fas fa-times"></i> Cancel
                                            </button>
                                        </form>
                                        <a href="booking-details.php?id=<?php echo $booking['id']; ?>" class="btn btn-view">
                                            <i class="fas fa-eye"></i> View
                                        </a>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-clock"></i>
                    <h3>No Tentative Bookings</h3>
                    <p>There are currently no tentative bookings in the system.</p>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
        // Auto-refresh every 5 minutes for expiring bookings
        setTimeout(function() {
            location.reload();
        }, 300000);
    </script>

    <?php require_once 'includes/admin-footer.php'; ?>
