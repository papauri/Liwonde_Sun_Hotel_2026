<?php
// Include admin initialization (PHP-only, no HTML output)
require_once 'admin-init.php';

require_once '../includes/modal.php';
require_once '../includes/alert.php';

$user = [
    'id' => $_SESSION['admin_user_id'],
    'username' => $_SESSION['admin_username'],
    'role' => $_SESSION['admin_role'],
    'full_name' => $_SESSION['admin_full_name']
];
$today = date('Y-m-d');

// Fetch dashboard statistics
try {
    // Today's check-ins
    $checkins_stmt = $pdo->prepare("SELECT COUNT(*) as count FROM bookings WHERE check_in_date = ? AND status IN ('confirmed', 'pending')");
    $checkins_stmt->execute([$today]);
    $today_checkins = $checkins_stmt->fetch(PDO::FETCH_ASSOC)['count'];

    // Today's check-outs
    $checkouts_stmt = $pdo->prepare("SELECT COUNT(*) as count FROM bookings WHERE check_out_date = ? AND status = 'checked-in'");
    $checkouts_stmt->execute([$today]);
    $today_checkouts = $checkouts_stmt->fetch(PDO::FETCH_ASSOC)['count'];

    // Pending bookings
    $pending_stmt = $pdo->query("SELECT COUNT(*) as count FROM bookings WHERE status = 'pending'");
    $pending_bookings = $pending_stmt->fetch(PDO::FETCH_ASSOC)['count'];

    // Currently checked in
    $current_stmt = $pdo->query("SELECT COUNT(*) as count FROM bookings WHERE status = 'checked-in'");
    $current_guests = $current_stmt->fetch(PDO::FETCH_ASSOC)['count'];

    // Pending conference enquiries
    $pending_conf_stmt = $pdo->query("SELECT COUNT(*) as count FROM conference_inquiries WHERE status = 'pending'");
    $pending_conference = $pending_conf_stmt->fetch(PDO::FETCH_ASSOC)['count'];

    // Expired bookings (last 24 hours)
    $expired_stmt = $pdo->query("SELECT COUNT(*) as count FROM bookings WHERE status = 'expired' AND expired_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)");
    $expired_bookings = $expired_stmt->fetch(PDO::FETCH_ASSOC)['count'];

    // Today's conference events
    $today_conf_stmt = $pdo->prepare("SELECT COUNT(*) as count FROM conference_inquiries WHERE event_date = ? AND status IN ('confirmed', 'pending')");
    $today_conf_stmt->execute([$today]);
    $today_conferences = $today_conf_stmt->fetch(PDO::FETCH_ASSOC)['count'];

    // Recent bookings (last 10)
    $recent_stmt = $pdo->query("
        SELECT b.*, r.name as room_name,
               b.total_amount, b.amount_paid, b.amount_due, b.payment_status
        FROM bookings b
        JOIN rooms r ON b.room_id = r.id
        ORDER BY b.created_at DESC
        LIMIT 10
    ");
    $recent_bookings = $recent_stmt->fetchAll(PDO::FETCH_ASSOC);

    // Upcoming check-ins (next 7 days)
    $upcoming_stmt = $pdo->prepare("
        SELECT b.*, r.name as room_name,
               b.total_amount, b.amount_paid, b.amount_due, b.payment_status
        FROM bookings b
        JOIN rooms r ON b.room_id = r.id
        WHERE b.check_in_date BETWEEN ? AND DATE_ADD(?, INTERVAL 7 DAY)
        AND b.status IN ('pending', 'confirmed')
        ORDER BY b.check_in_date ASC
    ");
    $upcoming_stmt->execute([$today, $today]);
    $upcoming_checkins = $upcoming_stmt->fetchAll(PDO::FETCH_ASSOC);

    // Recent conference enquiries (last 10)
    $recent_conf_stmt = $pdo->query("
        SELECT ci.*, cr.name as room_name
        FROM conference_inquiries ci
        LEFT JOIN conference_rooms cr ON ci.conference_room_id = cr.id
        ORDER BY ci.created_at DESC
        LIMIT 10
    ");
    $recent_conferences = $recent_conf_stmt->fetchAll(PDO::FETCH_ASSOC);

    // Today's conference events
    $today_conf_events_stmt = $pdo->prepare("
        SELECT ci.*, cr.name as room_name
        FROM conference_inquiries ci
        LEFT JOIN conference_rooms cr ON ci.conference_room_id = cr.id
        WHERE ci.event_date = ?
        AND ci.status IN ('confirmed', 'pending')
        ORDER BY ci.start_time ASC
    ");
    $today_conf_events_stmt->execute([$today]);
    $today_conference_events = $today_conf_events_stmt->fetchAll(PDO::FETCH_ASSOC);

    // Upcoming conference events (next 7 days)
    $upcoming_conf_stmt = $pdo->prepare("
        SELECT ci.*, cr.name as room_name
        FROM conference_inquiries ci
        LEFT JOIN conference_rooms cr ON ci.conference_room_id = cr.id
        WHERE ci.event_date BETWEEN ? AND DATE_ADD(?, INTERVAL 7 DAY)
        AND ci.status IN ('pending', 'confirmed')
        ORDER BY ci.event_date ASC, ci.start_time ASC
    ");
    $upcoming_conf_stmt->execute([$today, $today]);
    $upcoming_conferences = $upcoming_conf_stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    $error = "Unable to load dashboard data.";
}

// Fetch recent login activity (admin only)
$activity_log = [];
if ($user['role'] === 'admin') {
    try {
        $log_stmt = $pdo->query("
            SELECT al.*, au.full_name 
            FROM admin_activity_log al 
            LEFT JOIN admin_users au ON al.user_id = au.id 
            ORDER BY al.created_at DESC 
            LIMIT 20
        ");
        $activity_log = $log_stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        // Table may not exist yet - that's fine
        $activity_log = [];
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
    <title>Dashboard | <?php echo htmlspecialchars($site_name); ?> Admin</title>
    
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;500;600;700&family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../css/style.css">
    <link rel="stylesheet" href="../css/theme-dynamic.php">
    <link rel="stylesheet" href="css/admin-styles.css">
    <link rel="stylesheet" href="css/admin-components.css">
    
    <style>
        /* Dashboard-specific styles */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 24px;
            margin-bottom: 40px;
        }
        
        .quick-actions {
            display: flex;
            gap: 8px;
        }
        
        /* Today's Check-ins section specific styling */
        .today-checkins-section {
            background: white;
            padding: 24px;
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow);
            margin-bottom: 32px;
        }
        
        .today-checkins-section h3 {
            margin-bottom: 20px;
            color: var(--navy);
            font-size: 20px;
            font-weight: 700;
        }
        
        .today-checkins-section h3 i {
            margin-right: 10px;
        }
        
        .empty-state {
            text-align: center;
            padding: 40px;
            color: #999;
        }
        
        .empty-state i {
            font-size: 48px;
            margin-bottom: 12px;
            color: #ddd;
        }
        
        /* Payment status badges */
        .badge-paid {
            background: #d4edda;
            color: #155724;
        }
        
        .badge-partial {
            background: #fff3cd;
            color: #856404;
        }
        
        .badge-unpaid {
            background: #f8d7da;
            color: #721c24;
        }

        /* Warning stat card for expired bookings */
        .stat-card-warning {
            background: linear-gradient(135deg, #fff3cd 0%, #ffe9a8 100%);
            border-left: 4px solid #ffc107;
        }

        .stat-card-warning .stat-icon {
            background: #ffc107;
            color: #856404;
        }

        .stat-card-warning .stat-value {
            color: #856404;
        }
    </style>
</head>
<body>

    <?php require_once 'includes/admin-header.php'; ?>
    
    <div class="content">
        <?php if (isset($_GET['error']) && $_GET['error'] === 'access_denied'): ?>
        <div style="background:#fff3e0; border:1px solid #ffe0b2; border-radius:8px; padding:14px 20px; margin-bottom:20px; color:#e65100; display:flex; align-items:center; gap:10px; font-size:14px;">
            <i class="fas fa-exclamation-triangle"></i> You do not have permission to access that page. Contact your administrator to request access.
        </div>
        <?php endif; ?>
        
        <h2 class="section-title">Dashboard Overview</h2>
        
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon">
                    <i class="fas fa-calendar-check"></i>
                </div>
                <div class="stat-value"><?php echo $today_checkins; ?></div>
                <div class="stat-label">Today's Check-ins</div>
            </div>

            <div class="stat-card">
                <div class="stat-icon">
                    <i class="fas fa-calendar-times"></i>
                </div>
                <div class="stat-value"><?php echo $today_checkouts; ?></div>
                <div class="stat-label">Today's Check-outs</div>
            </div>

            <div class="stat-card">
                <div class="stat-icon">
                    <i class="fas fa-clock"></i>
                </div>
                <div class="stat-value"><?php echo $pending_bookings; ?></div>
                <div class="stat-label">Pending Bookings</div>
            </div>

            <div class="stat-card">
                <div class="stat-icon">
                    <i class="fas fa-users"></i>
                </div>
                <div class="stat-value"><?php echo $current_guests; ?></div>
                <div class="stat-label">Current Guests</div>
                </div>
    
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-users-cog"></i>
                    </div>
                    <div class="stat-value"><?php echo $pending_conference; ?></div>
                    <div class="stat-label">Pending Conference Enquiries</div>
                </div>
    
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-calendar-day"></i>
                    </div>
                    <div class="stat-value"><?php echo $today_conferences; ?></div>
                    <div class="stat-label">Today's Conference Events</div>
                </div>

                <div class="stat-card stat-card-warning">
                    <div class="stat-icon">
                        <i class="fas fa-clock"></i>
                    </div>
                    <div class="stat-value"><?php echo $expired_bookings; ?></div>
                    <div class="stat-label">Expired (24h)</div>
                </div>
            </div>

        <!-- Today's Check-ins Management -->
        <div class="today-checkins-section">
            <h3>
                <i class="fas fa-door-open"></i> Today's Check-ins (<?php echo $today_checkins; ?>)
            </h3>
            
            <?php
            $today_checkin_list = $pdo->prepare("
                SELECT b.*, r.name as room_name,
                       b.total_amount, b.amount_paid, b.amount_due, b.payment_status
                FROM bookings b
                JOIN rooms r ON b.room_id = r.id
                WHERE b.check_in_date = ? 
                AND b.status IN ('confirmed', 'pending')
                ORDER BY b.created_at ASC
            ");
            $today_checkin_list->execute([$today]);
            $checkin_bookings = $today_checkin_list->fetchAll(PDO::FETCH_ASSOC);
            ?>
            
            <?php if (!empty($checkin_bookings)): ?>
                <div class="table-container">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Booking Ref</th>
                                <th>Guest Name</th>
                                <th>Room</th>
                                <th>Check-out</th>
                                <th>Status</th>
                                <th>Payment</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($checkin_bookings as $booking): ?>
                                <tr id="checkin-row-<?php echo $booking['id']; ?>">
                                    <td><strong><?php echo htmlspecialchars($booking['booking_reference']); ?></strong></td>
                                    <td><?php echo htmlspecialchars($booking['guest_name']); ?></td>
                                    <td><?php echo htmlspecialchars($booking['room_name']); ?></td>
                                    <td><?php echo date('M d, Y', strtotime($booking['check_out_date'])); ?></td>
                                    <td>
                                        <span class="badge badge-<?php echo $booking['status']; ?>" id="status-<?php echo $booking['id']; ?>">
                                            <?php echo ucfirst($booking['status']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="badge badge-<?php echo $booking['payment_status']; ?>">
                                            <?php echo ucfirst($booking['payment_status']); ?>
                                        </span>
                                        <br><small style="color: #666; font-size: 11px; margin-top: 4px; display: block;">
                                            <?php echo $currency_symbol . number_format($booking['amount_paid'], 0); ?> / <?php echo $currency_symbol . number_format($booking['total_amount'], 0); ?>
                                            <?php if ($booking['amount_due'] > 0): ?>
                                                <span style="color: #dc3545; font-weight: 600;">(Due: <?php echo $currency_symbol . number_format($booking['amount_due'], 0); ?>)</span>
                                            <?php endif; ?>
                                        </small>
                                    </td>
                                    <td>
                                        <?php if ($booking['status'] !== 'checked-in'): ?>
                                            <?php $can_checkin = ($booking['status'] === 'confirmed' && $booking['payment_status'] === 'paid'); ?>
                                            <button onclick="<?php echo $can_checkin ? "processCheckIn({$booking['id']}, '" . htmlspecialchars(addslashes($booking['guest_name'])) . "')" : "Alert.show('Cannot check in: booking must be CONFIRMED and PAID.', 'error')"; ?>"
                                                    id="checkin-btn-<?php echo $booking['id']; ?>"
                                                    class="btn <?php echo $can_checkin ? 'btn-primary' : 'btn-light'; ?>" <?php echo $can_checkin ? '' : 'disabled'; ?>>
                                                <i class="fas fa-check"></i> Check In
                                            </button>
                                        <?php else: ?>
                                            <button onclick="cancelCheckIn(<?php echo $booking['id']; ?>, '<?php echo htmlspecialchars(addslashes($booking['guest_name'])); ?>')"
                                                    id="cancel-checkin-btn-<?php echo $booking['id']; ?>"
                                                    class="btn btn-dark">
                                                <i class="fas fa-undo"></i> Cancel Check-in
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
                    <i class="fas fa-inbox"></i>
                    <p>No check-ins scheduled for today</p>
                </div>
            <?php endif; ?>
        </div>

        <!-- Today's Conference Events -->
        <div class="today-checkins-section">
            <h3>
                <i class="fas fa-calendar-check"></i> Today's Conference Events (<?php echo $today_conferences; ?>)
            </h3>
            
            <?php if (!empty($today_conference_events)): ?>
                <div class="table-container">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Reference</th>
                                <th>Company</th>
                                <th>Contact</th>
                                <th>Room</th>
                                <th>Time</th>
                                <th>Attendees</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($today_conference_events as $conf): ?>
                                <tr>
                                    <td><strong><?php echo htmlspecialchars($conf['inquiry_reference']); ?></strong></td>
                                    <td><?php echo htmlspecialchars($conf['company_name']); ?></td>
                                    <td><?php echo htmlspecialchars($conf['contact_person']); ?></td>
                                    <td><?php echo htmlspecialchars($conf['room_name'] ?? 'N/A'); ?></td>
                                    <td>
                                        <?php echo date('H:i', strtotime($conf['start_time'])); ?> -
                                        <?php echo date('H:i', strtotime($conf['end_time'])); ?>
                                    </td>
                                    <td><?php echo (int) $conf['number_of_attendees']; ?></td>
                                    <td>
                                        <span class="badge badge-<?php echo $conf['status']; ?>">
                                            <?php echo ucfirst($conf['status']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <a href="conference-management.php" class="btn btn-primary btn-sm">Manage</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-calendar-times"></i>
                    <p>No conference events scheduled for today</p>
                </div>
            <?php endif; ?>
        </div>

        <h3 class="section-title">Upcoming Check-ins (Next 7 Days)</h3>
        <div class="table-container">
            <table class="table">
                <thead>
                    <tr>
                        <th>Booking Ref</th>
                        <th>Guest Name</th>
                        <th>Room</th>
                        <th>Check-in</th>
                        <th>Nights</th>
                        <th>Status</th>
                        <th>Payment</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($upcoming_checkins)): ?>
                    <tr>
                        <td colspan="8" class="empty-state">
                            <i class="fas fa-calendar"></i>
                            <p>No upcoming check-ins in the next 7 days</p>
                        </td>
                    </tr>
                    <?php else: ?>
                    <?php foreach ($upcoming_checkins as $booking): ?>
                    <tr>
                        <td><strong><?php echo htmlspecialchars($booking['booking_reference']); ?></strong></td>
                        <td><?php echo htmlspecialchars($booking['guest_name']); ?></td>
                        <td><?php echo htmlspecialchars($booking['room_name']); ?></td>
                        <td><?php echo date('M j, Y', strtotime($booking['check_in_date'])); ?></td>
                        <td><?php echo $booking['number_of_nights']; ?></td>
                        <td>
                            <span class="badge badge-<?php echo $booking['status']; ?>">
                                <?php echo ucfirst($booking['status']); ?>
                            </span>
                        </td>
                        <td>
                            <span class="badge badge-<?php echo $booking['payment_status']; ?>">
                                <?php echo ucfirst($booking['payment_status']); ?>
                            </span>
                            <br><small style="color: #666; font-size: 11px; margin-top: 4px; display: block;">
                                <?php echo $currency_symbol . number_format($booking['amount_paid'], 0); ?> / <?php echo $currency_symbol . number_format($booking['total_amount'], 0); ?>
                                <?php if ($booking['amount_due'] > 0): ?>
                                    <span style="color: #dc3545; font-weight: 600;">(Due: <?php echo $currency_symbol . number_format($booking['amount_due'], 0); ?>)</span>
                                <?php endif; ?>
                            </small>
                        </td>
                        <td>
                            <div class="quick-actions">
                                <?php if ($booking['status'] == 'pending'): ?>
                                <a href="booking-details.php?id=<?php echo $booking['id']; ?>&action=confirm" class="btn btn-success btn-sm">Confirm</a>
                                <?php elseif ($booking['status'] == 'confirmed'): ?>
                                <?php if ($booking['payment_status'] === 'paid'): ?>
                                <a href="booking-details.php?id=<?php echo $booking['id']; ?>&action=checkin" class="btn btn-primary btn-sm">Check In</a>
                                <?php else: ?>
                                <a href="booking-details.php?id=<?php echo $booking['id']; ?>" class="btn btn-primary btn-sm disabled" onclick="Alert.show('Cannot check in: booking must be PAID first.', 'error'); return false;">Check In</a>
                                <?php endif; ?>
                                <?php endif; ?>
                                <a href="booking-details.php?id=<?php echo $booking['id']; ?>" class="btn btn-primary btn-sm">View</a>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <h3 class="section-title mt-4">Recent Bookings</h3>
        <div class="table-container">
            <table class="table">
                <thead>
                    <tr>
                        <th>Booking Ref</th>
                        <th>Guest Name</th>
                        <th>Room</th>
                        <th>Dates</th>
                        <th>Total</th>
                        <th>Status</th>
                        <th>Payment</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recent_bookings as $booking): ?>
                    <tr>
                        <td><strong><?php echo htmlspecialchars($booking['booking_reference']); ?></strong></td>
                        <td><?php echo htmlspecialchars($booking['guest_name']); ?></td>
                        <td><?php echo htmlspecialchars($booking['room_name']); ?></td>
                        <td>
                            <?php echo date('M j', strtotime($booking['check_in_date'])); ?> - 
                            <?php echo date('M j, Y', strtotime($booking['check_out_date'])); ?>
                        </td>
                        <td><?php echo $currency_symbol; ?><?php echo number_format($booking['total_amount'], 0); ?></td>
                        <td>
                            <span class="badge badge-<?php echo $booking['status']; ?>">
                                <?php echo ucfirst($booking['status']); ?>
                            </span>
                        </td>
                        <td>
                            <span class="badge badge-<?php echo $booking['payment_status']; ?>">
                                <?php echo ucfirst($booking['payment_status']); ?>
                            </span>
                            <br><small style="color: #666; font-size: 11px; margin-top: 4px; display: block;">
                                <?php echo $currency_symbol . number_format($booking['amount_paid'], 0); ?> / <?php echo $currency_symbol . number_format($booking['total_amount'], 0); ?>
                                <?php if ($booking['amount_due'] > 0): ?>
                                    <span style="color: #dc3545; font-weight: 600;">(Due: <?php echo $currency_symbol . number_format($booking['amount_due'], 0); ?>)</span>
                                <?php endif; ?>
                            </small>
                        </td>
                        <td>
                            <a href="booking-details.php?id=<?php echo $booking['id']; ?>" class="btn btn-primary btn-sm">View</a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <h3 class="section-title mt-4">Upcoming Conference Events (Next 7 Days)</h3>
        <div class="table-container">
            <table class="table">
                <thead>
                    <tr>
                        <th>Reference</th>
                        <th>Company</th>
                        <th>Contact</th>
                        <th>Event Date</th>
                        <th>Time</th>
                        <th>Attendees</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($upcoming_conferences)): ?>
                    <tr>
                        <td colspan="8" class="empty-state">
                            <i class="fas fa-calendar"></i>
                            <p>No upcoming conference events in the next 7 days</p>
                        </td>
                    </tr>
                    <?php else: ?>
                    <?php foreach ($upcoming_conferences as $conf): ?>
                    <tr>
                        <td><strong><?php echo htmlspecialchars($conf['inquiry_reference']); ?></strong></td>
                        <td><?php echo htmlspecialchars($conf['company_name']); ?></td>
                        <td><?php echo htmlspecialchars($conf['contact_person']); ?></td>
                        <td><?php echo date('M j, Y', strtotime($conf['event_date'])); ?></td>
                        <td>
                            <?php echo date('H:i', strtotime($conf['start_time'])); ?> -
                            <?php echo date('H:i', strtotime($conf['end_time'])); ?>
                        </td>
                        <td><?php echo (int) $conf['number_of_attendees']; ?></td>
                        <td>
                            <span class="badge badge-<?php echo $conf['status']; ?>">
                                <?php echo ucfirst($conf['status']); ?>
                            </span>
                        </td>
                        <td>
                            <a href="conference-management.php" class="btn btn-primary btn-sm">Manage</a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <h3 class="section-title mt-4">Recent Conference Enquiries</h3>
        <div class="table-container">
            <table class="table">
                <thead>
                    <tr>
                        <th>Reference</th>
                        <th>Company</th>
                        <th>Contact</th>
                        <th>Event Date</th>
                        <th>Attendees</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recent_conferences as $conf): ?>
                    <tr>
                        <td><strong><?php echo htmlspecialchars($conf['inquiry_reference']); ?></strong></td>
                        <td><?php echo htmlspecialchars($conf['company_name']); ?></td>
                        <td><?php echo htmlspecialchars($conf['contact_person']); ?></td>
                        <td><?php echo date('M j, Y', strtotime($conf['event_date'])); ?></td>
                        <td><?php echo (int) $conf['number_of_attendees']; ?></td>
                        <td>
                            <span class="badge badge-<?php echo $conf['status']; ?>">
                                <?php echo ucfirst($conf['status']); ?>
                            </span>
                        </td>
                        <td>
                            <a href="conference-management.php" class="btn btn-primary btn-sm">View</a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php if ($user['role'] === 'admin' && !empty($activity_log)): ?>
    <!-- Login Activity Log -->
    <div class="today-checkins-section">
        <h3><i class="fas fa-shield-alt"></i> Recent Login Activity</h3>
        <div style="overflow-x: auto;">
            <table class="table" style="width:100%; border-collapse:collapse; font-size:13px;">
                <thead>
                    <tr style="background:linear-gradient(135deg, var(--deep-navy, #05090F) 0%, var(--navy, #0A1929) 100%); color:white;">
                        <th style="padding:10px 14px; text-align:left; font-weight:600; font-size:12px; text-transform:uppercase;">Time</th>
                        <th style="padding:10px 14px; text-align:left; font-weight:600; font-size:12px; text-transform:uppercase;">User</th>
                        <th style="padding:10px 14px; text-align:left; font-weight:600; font-size:12px; text-transform:uppercase;">Action</th>
                        <th style="padding:10px 14px; text-align:left; font-weight:600; font-size:12px; text-transform:uppercase;">Details</th>
                        <th style="padding:10px 14px; text-align:left; font-weight:600; font-size:12px; text-transform:uppercase;">IP Address</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($activity_log as $log): 
                        $action_colors = [
                            'login_success' => ['bg' => '#e8f5e9', 'color' => '#2e7d32', 'icon' => 'fa-sign-in-alt', 'label' => 'Login'],
                            'login_failed' => ['bg' => '#fbe9e7', 'color' => '#c62828', 'icon' => 'fa-times-circle', 'label' => 'Failed Login'],
                            'login_blocked' => ['bg' => '#fff3e0', 'color' => '#e65100', 'icon' => 'fa-lock', 'label' => 'Blocked'],
                            'logout' => ['bg' => '#e3f2fd', 'color' => '#1565c0', 'icon' => 'fa-sign-out-alt', 'label' => 'Logout'],
                            'password_reset' => ['bg' => '#f3e5f5', 'color' => '#7b1fa2', 'icon' => 'fa-key', 'label' => 'Password Reset'],
                        ];
                        $ac = $action_colors[$log['action']] ?? ['bg' => '#f5f5f5', 'color' => '#666', 'icon' => 'fa-info-circle', 'label' => $log['action']];
                    ?>
                    <tr style="border-bottom:1px solid #f0f0f0;">
                        <td style="padding:10px 14px; white-space:nowrap; color:#888; font-size:12px;">
                            <?php echo date('M j, g:ia', strtotime($log['created_at'])); ?>
                        </td>
                        <td style="padding:10px 14px;">
                            <strong><?php echo htmlspecialchars($log['full_name'] ?? $log['username'] ?? '—'); ?></strong>
                            <?php if ($log['username']): ?>
                                <span style="color:#999; font-size:11px;">(<?php echo htmlspecialchars($log['username']); ?>)</span>
                            <?php endif; ?>
                        </td>
                        <td style="padding:10px 14px;">
                            <span style="display:inline-flex; align-items:center; gap:5px; padding:3px 10px; border-radius:12px; font-size:11px; font-weight:600; background:<?php echo $ac['bg']; ?>; color:<?php echo $ac['color']; ?>;">
                                <i class="fas <?php echo $ac['icon']; ?>"></i> <?php echo $ac['label']; ?>
                            </span>
                        </td>
                        <td style="padding:10px 14px; color:#555; font-size:12px;">
                            <?php echo htmlspecialchars($log['details'] ?? ''); ?>
                        </td>
                        <td style="padding:10px 14px; font-family:monospace; font-size:12px; color:#888;">
                            <?php echo htmlspecialchars($log['ip_address'] ?? ''); ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <script src="js/admin-components.js"></script>
    <script src="js/admin-mobile.js"></script>
    <script>
        function processCheckIn(bookingId, guestName) {
            if (!confirm(`Check in ${guestName}?`)) {
                return;
            }

            const formData = new FormData();
            formData.append('action', 'checkin');
            formData.append('booking_id', bookingId);

            fetch('process-checkin.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Update UI
                    const statusBadge = document.getElementById(`status-${bookingId}`);
                    statusBadge.className = 'badge badge-checked-in';
                    statusBadge.textContent = 'Checked-in';
                    
                    const button = document.getElementById(`checkin-btn-${bookingId}`);
                    button.outerHTML = `<button onclick="cancelCheckIn(${bookingId}, '${guestName.replace(/'/g, "\\'")}')" id="cancel-checkin-btn-${bookingId}" class="btn btn-dark"><i class="fas fa-undo"></i> Cancel Check-in</button>`;
                    
                    Alert.show(`${guestName} successfully checked in!`, 'success');
                } else {
                    Alert.show('Error: ' + (data.message || 'Failed to check in guest'), 'error');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                Alert.show('An error occurred during check-in', 'error');
            });
        }

        function cancelCheckIn(bookingId, guestName) {
            if (!confirm(`Cancel check-in for ${guestName}?`)) {
                return;
            }

            const formData = new FormData();
            formData.append('action', 'cancel_checkin');
            formData.append('booking_id', bookingId);

            fetch('process-checkin.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    const statusBadge = document.getElementById(`status-${bookingId}`);
                    statusBadge.className = 'badge badge-confirmed';
                    statusBadge.textContent = 'Confirmed';

                    const button = document.getElementById(`cancel-checkin-btn-${bookingId}`);
                    button.outerHTML = `<span class="badge badge-confirmed">Reverted to confirmed</span>`;

                    Alert.show(`Check-in cancelled for ${guestName}.`, 'success');
                } else {
                    Alert.show('Error: ' + (data.message || 'Failed to cancel check-in'), 'error');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                Alert.show('An error occurred while cancelling check-in', 'error');
            });
        }
    </script>

    <?php require_once 'includes/admin-footer.php'; ?>
