<?php
/**
 * Calendar-Based Room Management
 * Hotel Website - Admin Panel
 */

// Include admin initialization (PHP-only, no HTML output)
require_once 'admin-init.php';

// Get date parameters
$currentYear = isset($_GET['year']) ? intval($_GET['year']) : date('Y');
$currentMonth = isset($_GET['month']) ? intval($_GET['month']) : date('m');

// Validate month
if ($currentMonth < 1) {
    $currentMonth = 12;
    $currentYear--;
} elseif ($currentMonth > 12) {
    $currentMonth = 1;
    $currentYear++;
}

// Get previous and next month
$prevMonth = $currentMonth - 1;
$prevYear = $currentYear;
if ($prevMonth < 1) {
    $prevMonth = 12;
    $prevYear--;
}

$nextMonth = $currentMonth + 1;
$nextYear = $currentYear;
if ($nextMonth > 12) {
    $nextMonth = 1;
    $nextYear++;
}

// Get all rooms
try {
    $stmt = $pdo->query("SELECT * FROM rooms WHERE is_active = 1 ORDER BY name");
    $rooms = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $error = "Error fetching rooms: " . $e->getMessage();
}

// Get blocked dates for current month
$blockedDatesByDate = [];
try {
    $startDate = sprintf('%04d-%02d-01', $currentYear, $currentMonth);
    $endDate = sprintf('%04d-%02d-31', $currentYear, $currentMonth);
    
    $stmt = $pdo->prepare("
        SELECT rbd.*, r.name as room_name
        FROM room_blocked_dates rbd
        LEFT JOIN rooms r ON rbd.room_id = r.id
        WHERE rbd.block_date >= :start_date AND rbd.block_date <= :end_date
        ORDER BY rbd.block_date ASC, rbd.room_id ASC
    ");
    $stmt->execute(['start_date' => $startDate, 'end_date' => $endDate]);
    $blockedDates = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Group blocked dates by date
    foreach ($blockedDates as $blocked) {
        $dateKey = $blocked['block_date'];
        if (!isset($blockedDatesByDate[$dateKey])) {
            $blockedDatesByDate[$dateKey] = [];
        }
        $blockedDatesByDate[$dateKey][] = $blocked;
    }
} catch (PDOException $e) {
    $error = "Error fetching blocked dates: " . $e->getMessage();
}

// Get bookings for the current month
$bookingsByDate = [];
try {
    $startDate = sprintf('%04d-%02d-01', $currentYear, $currentMonth);
    $endDate = sprintf('%04d-%02d-31', $currentYear, $currentMonth);
    
    $stmt = $pdo->prepare("
        SELECT b.*, r.name as room_name, r.id as room_id, r.price_per_night
        FROM bookings b
        INNER JOIN rooms r ON b.room_id = r.id
        WHERE b.status != 'cancelled'
        AND b.status != 'checked-out'
        AND (
            (b.check_in_date <= :end_date AND b.check_out_date >= :start_date)
        )
        ORDER BY b.check_in_date ASC, r.name ASC
    ");
    $stmt->execute(['start_date' => $startDate, 'end_date' => $endDate]);
    $bookings = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Group bookings by date and room
    foreach ($bookings as $booking) {
        $checkIn = new DateTime($booking['check_in_date']);
        $checkOut = new DateTime($booking['check_out_date']);
        
        $currentDate = clone $checkIn;
        while ($currentDate < $checkOut) {
            $dateKey = $currentDate->format('Y-m-d');
            $roomId = $booking['room_id'];
            
            if (!isset($bookingsByDate[$dateKey])) {
                $bookingsByDate[$dateKey] = [];
            }
            
            if (!isset($bookingsByDate[$dateKey][$roomId])) {
                $bookingsByDate[$dateKey][$roomId] = [];
            }
            
            $bookingsByDate[$dateKey][$roomId][] = $booking;
            $currentDate->modify('+1 day');
        }
    }
} catch (PDOException $e) {
    $error = "Error fetching bookings: " . $e->getMessage();
}

// Get days in month
$daysInMonth = date('t', mktime(0, 0, 0, $currentMonth, 1, $currentYear));
$firstDayOfWeek = date('w', mktime(0, 0, 0, $currentMonth, 1, $currentYear));

// Month names
$monthNames = [
    1 => 'January', 2 => 'February', 3 => 'March', 4 => 'April',
    5 => 'May', 6 => 'June', 7 => 'July', 8 => 'August',
    9 => 'September', 10 => 'October', 11 => 'November', 12 => 'December'
];

// Today's date for highlighting
$today = date('Y-m-d');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Room Calendar - Admin Panel</title>
    
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;500;600;700&family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../css/style.css">
    <link rel="stylesheet" href="../css/theme-dynamic.php">
    <link rel="stylesheet" href="css/admin-styles.css">
    <link rel="stylesheet" href="css/admin-components.css">
    <style>
        /* Calendar-specific styles - unique to this page */
        .calendar-container {
            background: white;
            border-radius: 8px;
            padding: 20px;
            margin: 20px 0;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .calendar-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            flex-wrap: wrap;
            gap: 10px;
        }
        .calendar-header h2 {
            margin: 0;
            color: #0A1929;
            font-size: 24px;
        }
        .calendar-nav {
            display: flex;
            gap: 10px;
        }
        .calendar-nav a {
            padding: 10px 20px;
            background: #D4AF37;
            color: #0A1929;
            text-decoration: none;
            border-radius: 5px;
            font-weight: 600;
            transition: all 0.3s ease;
        }
        .calendar-nav a:hover {
            background: #c19b2e;
            transform: translateY(-2px);
        }
        .calendar-nav .current {
            padding: 10px 20px;
            background: #0A1929;
            color: #D4AF37;
            border-radius: 5px;
            font-weight: 600;
            cursor: default;
        }
        .room-calendars {
            display: flex;
            flex-direction: column;
            gap: 30px;
        }
        .room-calendar {
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            overflow: hidden;
        }
        .room-header {
            background: linear-gradient(135deg, #0A1929 0%, #1a3a52 100%);
            color: white;
            padding: 15px 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .room-header h3 {
            margin: 0;
            font-size: 18px;
        }
        .room-price {
            background: #D4AF37;
            color: #0A1929;
            padding: 5px 15px;
            border-radius: 20px;
            font-weight: 600;
        }
        .calendar-grid {
            display: grid;
            grid-template-columns: repeat(7, 1fr);
        }
        .calendar-day-header {
            background: #f8f9fa;
            padding: 10px;
            text-align: center;
            font-weight: 600;
            border: 1px solid #e0e0e0;
            color: #0A1929;
        }
        .calendar-day {
            min-height: 100px;
            border: 1px solid #e0e0e0;
            padding: 5px;
            position: relative;
            background: white;
            transition: background 0.2s ease;
        }
        .calendar-day:hover {
            background: #f0f0f0;
        }
        .calendar-day.today {
            background: #e3f2fd;
        }
        .calendar-day.empty {
            background: #fafafa;
        }
        .day-number {
            font-weight: 600;
            color: #0A1929;
            font-size: 14px;
            margin-bottom: 5px;
        }
        .booking-indicator {
            background: #D4AF37;
            color: #0A1929;
            padding: 2px 5px;
            border-radius: 3px;
            font-size: 11px;
            margin-bottom: 2px;
            cursor: pointer;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            transition: all 0.2s ease;
        }
        .booking-indicator:hover {
            background: #c19b2e;
            transform: scale(1.02);
        }
        .booking-indicator.pending {
            background: #ffc107;
        }
        .booking-indicator.confirmed {
            background: #28a745;
            color: white;
        }
        .booking-indicator.checked-in {
            background: #17a2b8;
            color: white;
        }
        .calendar-day.blocked {
            background: #dc3545 !important;
            color: white !important;
            cursor: not-allowed;
            opacity: 0.7;
        }
        .calendar-day.blocked:hover {
            background: #c82333 !important;
        }
        .blocked-indicator {
            background: #dc3545;
            color: white;
            padding: 2px 5px;
            border-radius: 3px;
            font-size: 11px;
            margin-bottom: 2px;
            cursor: pointer;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            transition: all 0.2s ease;
        }
        .blocked-indicator:hover {
            background: #c82333;
            transform: scale(1.02);
        }
        .legend {
            display: flex;
            gap: 20px;
            flex-wrap: wrap;
            margin: 20px 0;
            padding: 15px;
            background: #f8f9fa;
            border-radius: 8px;
        }
        .legend-item {
            display: flex;
            align-items: center;
            gap: 5px;
        }
        .legend-color {
            width: 20px;
            height: 20px;
            border-radius: 3px;
        }
        .legend-color.pending {
            background: #ffc107;
        }
        .legend-color.confirmed {
            background: #28a745;
        }
        .legend-color.checked-in {
            background: #17a2b8;
        }
        .calendar-actions {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
        }
        .calendar-actions a {
            padding: 10px 20px;
            background: #6c757d;
            color: white;
            text-decoration: none;
            border-radius: 5px;
            font-weight: 600;
        }
        .calendar-actions a:hover {
            background: #5a6268;
        }
        @media (max-width: 1200px) {
            .calendar-day {
                min-height: 80px;
            }
        }
        @media (max-width: 768px) {
            .calendar-header {
                flex-direction: column;
                text-align: center;
            }
            .calendar-day {
                min-height: 60px;
                font-size: 12px;
            }
            .booking-indicator {
                font-size: 9px;
            }
        }
    </style>
</head>
<body>

    <?php require_once 'includes/admin-header.php'; ?>
    
    <div class="content">
        <h2 class="section-title">📅 Room Calendar</h2>
        
        <div class="calendar-actions mb-3">
            <a href="bookings.php">← Back to Bookings</a>
            <a href="dashboard.php">Dashboard</a>
        </div>
        
        <?php if (isset($error)): ?>
            <div class="alert alert-error">
                <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>
        
        <div class="calendar-container">
            <div class="calendar-header">
                <h2><?php echo $monthNames[$currentMonth] . ' ' . $currentYear; ?></h2>
                <div class="calendar-nav">
                    <a href="?year=<?php echo $prevYear; ?>&month=<?php echo $prevMonth; ?>">← Previous</a>
                    <span class="current">Current Month</span>
                    <a href="?year=<?php echo $nextYear; ?>&month=<?php echo $nextMonth; ?>">Next →</a>
                </div>
            </div>
            
            <div class="legend">
                <div class="legend-item">
                    <div class="legend-color pending"></div>
                    <span>Pending</span>
                </div>
                <div class="legend-item">
                    <div class="legend-color confirmed"></div>
                    <span>Confirmed</span>
                </div>
                <div class="legend-item">
                    <div class="legend-color checked-in"></div>
                    <span>Checked In</span>
                </div>
                <div class="legend-item">
                    <div class="legend-color blocked"></div>
                    <span>Blocked</span>
                </div>
            </div>
            
            <?php if (!empty($rooms)): ?>
                <div class="room-calendars">
                    <?php foreach ($rooms as $room): ?>
                        <div class="room-calendar">
                            <div class="room-header">
                                <h3><?php echo htmlspecialchars($room['name']); ?></h3>
                                <span class="room-price">
                                    <?php echo getSetting('currency_symbol', 'MWK') . ' ' . number_format($room['price_per_night'], 0); ?>/night
                                </span>
                            </div>
                            
                            <div class="calendar-grid">
                                <!-- Day headers -->
                                <div class="calendar-day-header">Sun</div>
                                <div class="calendar-day-header">Mon</div>
                                <div class="calendar-day-header">Tue</div>
                                <div class="calendar-day-header">Wed</div>
                                <div class="calendar-day-header">Thu</div>
                                <div class="calendar-day-header">Fri</div>
                                <div class="calendar-day-header">Sat</div>
                                
                                <!-- Empty days before first day of month -->
                                <?php for ($i = 0; $i < $firstDayOfWeek; $i++): ?>
                                    <div class="calendar-day empty"></div>
                                <?php endfor; ?>
                                
                                <!-- Days of the month -->
                                <?php for ($day = 1; $day <= $daysInMonth; $day++): ?>
                                    <?php 
                                        $dateKey = sprintf('%04d-%02d-%02d', $currentYear, $currentMonth, $day);
                                        $isToday = ($dateKey === $today);
                                        $dateForComparison = $dateKey;
                                    ?>
                                    <div class="calendar-day <?php echo $isToday ? 'today' : ''; ?>">
                                        <div class="day-number"><?php echo $day; ?></div>
                                         
                                        <?php
                                            // Check if this date is blocked for this room or all rooms
                                            $isBlocked = false;
                                            if (isset($blockedDatesByDate[$dateKey])) {
                                                foreach ($blockedDatesByDate[$dateKey] as $blocked) {
                                                    // Check if blocked for this specific room or all rooms
                                                    if ($blocked['room_id'] == $room['id'] || $blocked['room_id'] === null) {
                                                        $isBlocked = true;
                                                        $blockType = htmlspecialchars($blocked['block_type']);
                                                        $blockReason = htmlspecialchars($blocked['reason'] ?? 'No reason provided');
                                                        ?>
                                                        <div class="blocked-indicator"
                                                             title="Blocked: <?php echo $blockType; ?> - <?php echo $blockReason; ?>"
                                                             onclick="window.location.href='blocked-dates.php'">
                                                            <?php echo ucfirst($blockType); ?>
                                                        </div>
                                                        <?php
                                                    }
                                                }
                                            }
                                            
                                            // Show bookings if date is not blocked
                                            if (!$isBlocked && isset($bookingsByDate[$dateKey]) &&
                                                isset($bookingsByDate[$dateKey][$room['id']])) {
                                                $dayBookings = $bookingsByDate[$dateKey][$room['id']];
                                                foreach ($dayBookings as $booking) {
                                                    $statusClass = strtolower($booking['status']);
                                                    $guestName = htmlspecialchars($booking['guest_name']);
                                                    $ref = htmlspecialchars($booking['booking_reference']);
                                            ?>
                                                <div class="booking-indicator <?php echo $statusClass; ?>"
                                                     title="<?php echo "$guestName ($ref)"; ?>"
                                                     onclick="window.location.href='booking-details.php?id=<?php echo $booking['id']; ?>'">
                                                    <?php echo substr($guestName, 0, 15) . '...'; ?>
                                                </div>
                                            <?php
                                                }
                                            }
                                        ?>
                                    </div>
                                <?php endfor; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-inbox"></i>
                    <p>No rooms found.</p>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <script src="js/admin-components.js"></script>

    <?php require_once 'includes/admin-footer.php'; ?>