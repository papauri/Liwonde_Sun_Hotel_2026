<?php
/**
 * Calendar-Based Room Management
 * Hotel Website - Admin Panel
 */

require_once 'admin-init.php';

$currentYear  = isset($_GET['year'])  ? intval($_GET['year'])  : date('Y');
$currentMonth = isset($_GET['month']) ? intval($_GET['month']) : date('m');

if ($currentMonth < 1)  { $currentMonth = 12; $currentYear--; }
elseif ($currentMonth > 12) { $currentMonth = 1;  $currentYear++; }

$prevMonth = $currentMonth - 1; $prevYear = $currentYear;
if ($prevMonth < 1)  { $prevMonth = 12; $prevYear--; }
$nextMonth = $currentMonth + 1; $nextYear = $currentYear;
if ($nextMonth > 12) { $nextMonth = 1;  $nextYear++; }

$currencySymbol = getSetting('currency_symbol', 'MWK');

// -- Rooms
try {
    $stmt = $pdo->query("
        SELECT id, name, slug, bed_type, max_guests, size_sqm,
               total_rooms, rooms_available,
               price_per_night, price_single_occupancy, price_double_occupancy
        FROM rooms WHERE is_active = 1 ORDER BY display_order ASC, name ASC
    ");
    $rooms = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $error = "Error fetching rooms: " . $e->getMessage();
    $rooms = [];
}

// -- Blocked dates
$blockedDatesByDate = [];
try {
    $startDate = sprintf('%04d-%02d-01', $currentYear, $currentMonth);
    $endDate   = date('Y-m-t', mktime(0,0,0,$currentMonth,1,$currentYear));

    $stmt = $pdo->prepare("
        SELECT rbd.*, r.name AS room_name
        FROM room_blocked_dates rbd
        LEFT JOIN rooms r ON rbd.room_id = r.id
        WHERE rbd.block_date >= :start_date AND rbd.block_date <= :end_date
        ORDER BY rbd.block_date ASC, rbd.room_id ASC
    ");
    $stmt->execute(['start_date' => $startDate, 'end_date' => $endDate]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $bd) {
        $blockedDatesByDate[$bd['block_date']][] = $bd;
    }
} catch (PDOException $e) {
    $error = "Error fetching blocked dates: " . $e->getMessage();
}

// -- Bookings
$bookingsByDate = [];
$allBookings    = [];
try {
    $startDate = sprintf('%04d-%02d-01', $currentYear, $currentMonth);
    $endDate   = date('Y-m-t', mktime(0,0,0,$currentMonth,1,$currentYear));

    $stmt = $pdo->prepare("
        SELECT
            b.id, b.booking_reference, b.room_id,
            b.guest_name, b.guest_email, b.guest_phone, b.guest_country,
            b.number_of_guests, b.check_in_date, b.check_out_date,
            b.number_of_nights, b.total_amount, b.amount_paid, b.amount_due,
            b.total_with_vat, b.vat_amount,
            b.special_requests, b.status, b.payment_status, b.occupancy_type,
            b.is_tentative, b.created_at,
            r.name  AS room_name,
            r.bed_type, r.max_guests AS room_capacity,
            r.size_sqm, r.total_rooms, r.rooms_available
        FROM bookings b
        INNER JOIN rooms r ON b.room_id = r.id
        WHERE b.status NOT IN ('cancelled','checked-out')
          AND b.check_in_date  <= :end_date
          AND b.check_out_date >= :start_date
        ORDER BY b.check_in_date ASC, r.name ASC
    ");
    $stmt->execute(['start_date' => $startDate, 'end_date' => $endDate]);
    $bookings = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($bookings as $bk) {
        $allBookings[$bk['id']] = $bk;
        $ci = new DateTime($bk['check_in_date']);
        $co = new DateTime($bk['check_out_date']);
        $d  = clone $ci;
        while ($d < $co) {
            $dk  = $d->format('Y-m-d');
            $rid = $bk['room_id'];
            $bookingsByDate[$dk][$rid][] = $bk;
            $d->modify('+1 day');
        }
    }
} catch (PDOException $e) {
    $error = "Error fetching bookings: " . $e->getMessage();
}

$daysInMonth    = date('t', mktime(0,0,0,$currentMonth,1,$currentYear));
$firstDayOfWeek = date('w', mktime(0,0,0,$currentMonth,1,$currentYear));
$today          = date('Y-m-d');

$monthNames = [
    1=>'January',2=>'February',3=>'March',4=>'April',
    5=>'May',6=>'June',7=>'July',8=>'August',
    9=>'September',10=>'October',11=>'November',12=>'December'
];

// Build JSON data for the modal
$bookingsJson = [];
foreach ($allBookings as $bk) {
    $bookingsJson[$bk['id']] = [
        'id'             => $bk['id'],
        'ref'            => $bk['booking_reference'],
        'guest_name'     => $bk['guest_name'],
        'guest_email'    => $bk['guest_email'],
        'guest_phone'    => $bk['guest_phone'],
        'guest_country'  => $bk['guest_country'] ?: '-',
        'room_name'      => $bk['room_name'],
        'bed_type'       => $bk['bed_type'] ?: '-',
        'room_capacity'  => $bk['room_capacity'],
        'size_sqm'       => $bk['size_sqm'] ?: '-',
        'check_in'       => date('D, d M Y', strtotime($bk['check_in_date'])),
        'check_out'      => date('D, d M Y', strtotime($bk['check_out_date'])),
        'nights'         => $bk['number_of_nights'],
        'guests'         => $bk['number_of_guests'],
        'occupancy_type' => ucfirst($bk['occupancy_type'] ?: 'double'),
        'total_amount'   => number_format($bk['total_amount'], 0),
        'total_with_vat' => number_format($bk['total_with_vat'], 0),
        'amount_paid'    => number_format($bk['amount_paid'], 0),
        'amount_due'     => number_format($bk['amount_due'], 0),
        'vat_amount'     => number_format($bk['vat_amount'], 0),
        'payment_status' => ucfirst($bk['payment_status']),
        'status'         => ucfirst(str_replace('-', ' ', $bk['status'])),
        'status_raw'     => $bk['status'],
        'special_requests'=> $bk['special_requests'] ?: '',
        'is_tentative'   => (bool)$bk['is_tentative'],
        'currency'       => $currencySymbol,
    ];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Room Calendar - Admin Panel</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;600;700&family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../css/style.css">
    <link rel="stylesheet" href="../css/theme-dynamic.php">
    <link rel="stylesheet" href="css/admin-styles.css">
    <link rel="stylesheet" href="css/admin-components.css">
    <style>
        .calendar-container {
            background: #fff; border-radius: 10px; padding: 22px;
            margin: 18px 0; box-shadow: 0 2px 8px rgba(0,0,0,.08);
        }
        .calendar-header {
            display: flex; justify-content: space-between; align-items: center;
            margin-bottom: 18px; flex-wrap: wrap; gap: 10px;
        }
        .calendar-header h2 {
            margin: 0; color: #0A1929;
            font-family: 'Playfair Display', serif; font-size: 22px;
        }
        .calendar-nav { display: flex; gap: 8px; align-items: center; }
        .calendar-nav a {
            padding: 8px 18px; background: #D4AF37; color: #0A1929;
            text-decoration: none; border-radius: 6px; font-weight: 600;
            font-size: 13px; transition: background .2s, transform .15s;
        }
        .calendar-nav a:hover { background: #c19b2e; transform: translateY(-1px); }
        .calendar-nav .current-label {
            padding: 8px 18px; background: #0A1929; color: #D4AF37;
            border-radius: 6px; font-weight: 600; font-size: 13px;
            text-decoration: none;
        }
        .legend {
            display: flex; flex-wrap: wrap; gap: 12px;
            margin-bottom: 18px; padding: 12px 16px;
            background: #f8f9fa; border-radius: 8px; font-size: 12px;
        }
        .legend-item { display: flex; align-items: center; gap: 6px; }
        .legend-dot { width: 14px; height: 14px; border-radius: 3px; flex-shrink: 0; }
        .legend-dot.pending    { background: #ffc107; }
        .legend-dot.tentative  { background: #fd7e14; }
        .legend-dot.confirmed  { background: #28a745; }
        .legend-dot.checked-in { background: #17a2b8; }
        .legend-dot.expired    { background: #6c757d; }
        .legend-dot.no-show    { background: #dc3545; }
        .legend-dot.blocked    { background: #c62828; }
        .room-calendars { display: flex; flex-direction: column; gap: 28px; }
        .room-calendar { border: 1px solid #dee2e6; border-radius: 10px; overflow: hidden; }
        .room-header {
            background: linear-gradient(135deg, #0A1929 0%, #1a3a52 100%);
            color: #fff; padding: 14px 18px;
            display: flex; justify-content: space-between;
            align-items: flex-start; gap: 12px; flex-wrap: wrap;
        }
        .room-header-left h3 {
            margin: 0 0 4px; font-size: 17px;
            font-family: 'Playfair Display', serif;
        }
        .room-meta {
            display: flex; flex-wrap: wrap; gap: 10px;
            font-size: 12px; color: rgba(255,255,255,.8);
        }
        .room-meta span { display: flex; align-items: center; gap: 4px; }
        .room-meta i { color: #D4AF37; font-size: 11px; }
        .room-header-right {
            display: flex; flex-direction: column; align-items: flex-end; gap: 5px;
        }
        .room-price-badge {
            background: #D4AF37; color: #0A1929;
            padding: 4px 14px; border-radius: 20px; font-weight: 700;
            font-size: 13px; white-space: nowrap;
        }
        .room-availability { font-size: 11px; color: rgba(255,255,255,.75); }
        .calendar-grid { display: grid; grid-template-columns: repeat(7, 1fr); }
        .calendar-day-header {
            background: #f1f3f5; padding: 8px 4px; text-align: center;
            font-weight: 600; border: 1px solid #dee2e6;
            color: #495057; font-size: 12px;
        }
        .calendar-day {
            min-height: 90px; border: 1px solid #dee2e6;
            padding: 5px 4px; background: #fff;
            vertical-align: top; box-sizing: border-box;
        }
        .calendar-day.today { background: #e8f4fd; }
        .calendar-day.today .day-number { color: #0d6efd; font-weight: 700; }
        .calendar-day.empty { background: #fafafa; }
        .calendar-day.weekend { background: #fdfaf3; }
        .day-number {
            font-weight: 600; color: #495057; font-size: 13px;
            margin-bottom: 4px; display: block;
        }
        .booking-chip {
            border-radius: 4px; padding: 3px 5px; margin-bottom: 2px;
            font-size: 10.5px; cursor: pointer; line-height: 1.3;
            border-left: 3px solid transparent;
            white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
            transition: filter .15s, transform .1s;
        }
        .booking-chip:hover { filter: brightness(.9); transform: translateY(-1px); }
        .booking-chip.status-pending    { background: #fff3cd; color: #856404; border-left-color: #ffc107; }
        .booking-chip.status-tentative  { background: #ffe5cc; color: #7c3a00; border-left-color: #fd7e14; }
        .booking-chip.status-confirmed  { background: #d1f0da; color: #155724; border-left-color: #28a745; }
        .booking-chip.status-checked-in { background: #d1ecf1; color: #0c5460; border-left-color: #17a2b8; }
        .booking-chip.status-expired    { background: #e2e3e5; color: #41464b; border-left-color: #6c757d; }
        .booking-chip.status-no-show    { background: #f8d7da; color: #721c24; border-left-color: #dc3545; }
        .chip-name  { font-weight: 600; display: block; overflow: hidden; text-overflow: ellipsis; }
        .chip-detail{ font-size: 9.5px; opacity: .8; }
        .blocked-chip {
            background: #f8d7da; color: #721c24;
            border-left: 3px solid #c62828; border-radius: 4px;
            padding: 3px 5px; font-size: 10.5px; margin-bottom: 2px;
            cursor: pointer; white-space: nowrap; overflow: hidden;
            text-overflow: ellipsis; transition: filter .15s;
        }
        .blocked-chip:hover { filter: brightness(.9); }
        .month-summary {
            display: flex; flex-wrap: wrap; gap: 10px;
            padding: 10px 14px; background: #f8f9fa;
            border-top: 1px solid #dee2e6; font-size: 12px;
        }
        .summary-pill {
            background: #fff; border: 1px solid #dee2e6;
            border-radius: 20px; padding: 3px 12px;
            display: flex; align-items: center; gap: 5px;
        }
        .summary-pill .dot { width: 8px; height: 8px; border-radius: 50%; }
        .month-jump-form {
            display: flex; align-items: center; gap: 6px;
        }
        .month-jump-form select {
            padding: 7px 10px; border: 1px solid #dee2e6; border-radius: 6px;
            font-size: 13px; background: #fff; color: #0A1929; cursor: pointer;
        }
        /* Modal */
        .bk-modal-overlay {
            display: none; position: fixed; inset: 0;
            background: rgba(0,0,0,.55); z-index: 9999;
            align-items: flex-start; justify-content: center; padding: 40px 16px;
            overflow-y: auto;
        }
        .bk-modal-overlay.open { display: flex; }
        .bk-modal {
            background: #fff; border-radius: 12px;
            width: 100%; max-width: 640px;
            max-height: calc(100vh - 80px); overflow-y: auto;
            box-shadow: 0 20px 60px rgba(0,0,0,.3);
            animation: modalIn .2s ease;
            margin: 0 auto;
        }
        @keyframes modalIn {
            from { opacity: 0; transform: scale(.95) translateY(-10px); }
            to   { opacity: 1; transform: scale(1) translateY(0); }
        }
        .bk-modal-header {
            background: linear-gradient(135deg, #0A1929 0%, #1a3a52 100%);
            color: #fff; padding: 16px 20px;
            display: flex; justify-content: space-between; align-items: flex-start;
            border-radius: 12px 12px 0 0;
        }
        .bk-modal-header h3 {
            margin: 0 0 4px;
            font-family: 'Playfair Display', serif; font-size: 17px;
        }
        .bk-modal-ref { font-size: 12px; color: #D4AF37; font-weight: 600; }
        .bk-modal-close {
            background: rgba(255,255,255,.15); border: none;
            color: #fff; cursor: pointer; border-radius: 50%;
            width: 30px; height: 30px; font-size: 16px; flex-shrink: 0;
            transition: background .2s;
        }
        .bk-modal-close:hover { background: rgba(255,255,255,.3); }
        .bk-modal-body { padding: 18px 20px; }
        .bk-modal-status {
            display: inline-flex; align-items: center; gap: 6px;
            padding: 4px 14px; border-radius: 20px;
            font-size: 12px; font-weight: 600; margin-bottom: 14px;
        }
        .bk-modal-status.status-pending    { background:#fff3cd; color:#856404; }
        .bk-modal-status.status-tentative  { background:#ffe5cc; color:#7c3a00; }
        .bk-modal-status.status-confirmed  { background:#d1f0da; color:#155724; }
        .bk-modal-status.status-checked-in { background:#d1ecf1; color:#0c5460; }
        .bk-modal-status.status-expired    { background:#e2e3e5; color:#41464b; }
        .bk-modal-status.status-no-show    { background:#f8d7da; color:#721c24; }
        .bk-section { padding: 10px 0; border-bottom: 1px solid #f0f0f0; }
        .bk-section:last-child { border-bottom: none; }
        .bk-section-title {
            font-size: 10px; font-weight: 700; text-transform: uppercase;
            letter-spacing: .08em; color: #999; margin-bottom: 8px;
        }
        .bk-row {
            display: flex; justify-content: space-between;
            align-items: flex-start; padding: 3px 0; gap: 8px;
        }
        .bk-label { font-size: 12px; color: #777; flex-shrink: 0; }
        .bk-value { font-size: 12px; color: #1a1a1a; font-weight: 600; text-align: right; }
        .bk-value.highlight { color: #28a745; }
        .bk-value.danger    { color: #dc3545; }
        .bk-value.warn      { color: #856404; }
        .bk-special {
            background: #f8f9fa; border-radius: 6px; padding: 8px 12px;
            font-size: 12px; color: #495057; margin-top: 10px;
            border-left: 3px solid #D4AF37;
        }
        .bk-special strong {
            display: block; font-size: 11px; color: #999;
            margin-bottom: 3px; text-transform: uppercase;
        }
        .bk-modal-actions {
            padding: 14px 20px; border-top: 1px solid #f0f0f0;
            display: flex; gap: 10px; flex-wrap: wrap;
        }
        .bk-btn {
            padding: 8px 18px; border-radius: 6px; font-weight: 600;
            font-size: 13px; text-decoration: none; cursor: pointer;
            border: none; transition: filter .15s, transform .1s;
            display: inline-flex; align-items: center; gap: 6px;
        }
        .bk-btn:hover { filter: brightness(.9); transform: translateY(-1px); }
        .bk-btn.primary   { background: #0A1929; color: #D4AF37; }
        .bk-btn.secondary { background: #D4AF37; color: #0A1929; }
        .bk-btn.muted     { background: #e9ecef; color: #495057; }
        @media (max-width: 768px) {
            .calendar-header { flex-direction: column; }
            .calendar-day { min-height: 70px; }
            .booking-chip, .blocked-chip { font-size: 9px; }
            .chip-detail { display: none; }
        }
        @media (max-width: 480px) {
            .calendar-day { min-height: 52px; padding: 3px 2px; }
            .day-number { font-size: 11px; }
        }
    </style>
</head>
<body>
    <?php require_once 'includes/admin-header.php'; ?>
    <div class="content">
        <h2 class="section-title"><i class="fas fa-calendar-alt" style="color:#D4AF37;"></i> Room Calendar</h2>
        <div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:16px;">
            <a href="bookings.php" class="btn btn-secondary btn-sm"><i class="fas fa-arrow-left"></i> Bookings</a>
            <a href="dashboard.php" class="btn btn-secondary btn-sm"><i class="fas fa-tachometer-alt"></i> Dashboard</a>
            <a href="blocked-dates.php" class="btn btn-secondary btn-sm"><i class="fas fa-ban"></i> Blocked Dates</a>
            <a href="create-booking.php" class="btn btn-primary btn-sm"><i class="fas fa-plus"></i> New Booking</a>
        </div>
        <?php if (isset($error)): ?>
            <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        <div class="calendar-container">
            <div class="calendar-header">
                <h2><?php echo $monthNames[$currentMonth] . ' ' . $currentYear; ?></h2>
                <div style="display:flex;flex-wrap:wrap;gap:10px;align-items:center;">
                    <form method="get" class="month-jump-form">
                        <select name="month" onchange="this.form.submit()">
                            <?php foreach ($monthNames as $mn => $ml): ?>
                                <option value="<?php echo $mn; ?>" <?php echo $mn === $currentMonth ? 'selected' : ''; ?>>
                                    <?php echo $ml; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <select name="year" onchange="this.form.submit()">
                            <?php for ($y = date('Y') - 2; $y <= date('Y') + 2; $y++): ?>
                                <option value="<?php echo $y; ?>" <?php echo $y === $currentYear ? 'selected' : ''; ?>>
                                    <?php echo $y; ?>
                                </option>
                            <?php endfor; ?>
                        </select>
                    </form>
                    <div class="calendar-nav">
                        <a href="?year=<?php echo $prevYear; ?>&month=<?php echo $prevMonth; ?>"><i class="fas fa-chevron-left"></i> Prev</a>
                        <a href="?year=<?php echo date('Y'); ?>&month=<?php echo date('n'); ?>" class="current-label">Today</a>
                        <a href="?year=<?php echo $nextYear; ?>&month=<?php echo $nextMonth; ?>">Next <i class="fas fa-chevron-right"></i></a>
                    </div>
                </div>
            </div>
            <div class="legend">
                <div class="legend-item"><div class="legend-dot pending"></div><span>Pending</span></div>
                <div class="legend-item"><div class="legend-dot tentative"></div><span>Tentative</span></div>
                <div class="legend-item"><div class="legend-dot confirmed"></div><span>Confirmed</span></div>
                <div class="legend-item"><div class="legend-dot checked-in"></div><span>Checked In</span></div>
                <div class="legend-item"><div class="legend-dot expired"></div><span>Expired</span></div>
                <div class="legend-item"><div class="legend-dot no-show"></div><span>No Show</span></div>
                <div class="legend-item"><div class="legend-dot blocked"></div><span>Blocked</span></div>
            </div>
            <?php if (!empty($rooms)): ?>
                <div class="room-calendars">
                    <?php foreach ($rooms as $room):
                        $roomBkCount = 0; $statusCount = [];
                        foreach ($allBookings as $bk) {
                            if ($bk['room_id'] == $room['id']) {
                                $roomBkCount++;
                                $st = $bk['status'];
                                $statusCount[$st] = ($statusCount[$st] ?? 0) + 1;
                            }
                        }
                    ?>
                        <div class="room-calendar">
                            <div class="room-header">
                                <div class="room-header-left">
                                    <h3><?php echo htmlspecialchars($room['name']); ?></h3>
                                    <div class="room-meta">
                                        <?php if ($room['bed_type']): ?>
                                            <span><i class="fas fa-bed"></i> <?php echo htmlspecialchars($room['bed_type']); ?></span>
                                        <?php endif; ?>
                                        <?php if ($room['max_guests']): ?>
                                            <span><i class="fas fa-users"></i> Up to <?php echo $room['max_guests']; ?> guests</span>
                                        <?php endif; ?>
                                        <?php if ($room['size_sqm']): ?>
                                            <span><i class="fas fa-ruler-combined"></i> <?php echo $room['size_sqm']; ?> m&sup2;</span>
                                        <?php endif; ?>
                                        <?php if ($room['total_rooms']): ?>
                                            <span><i class="fas fa-door-open"></i> <?php echo $room['total_rooms']; ?> rooms total</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="room-header-right">
                                    <span class="room-price-badge">
                                        <?php echo $currencySymbol . ' ' . number_format($room['price_per_night'], 0); ?>/night
                                    </span>
                                    <?php if ($room['rooms_available'] !== null): ?>
                                        <span class="room-availability">
                                            <i class="fas fa-check-circle" style="color:#4caf90;"></i>
                                            <?php echo $room['rooms_available']; ?> available
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="calendar-grid">
                                <div class="calendar-day-header">Sun</div>
                                <div class="calendar-day-header">Mon</div>
                                <div class="calendar-day-header">Tue</div>
                                <div class="calendar-day-header">Wed</div>
                                <div class="calendar-day-header">Thu</div>
                                <div class="calendar-day-header">Fri</div>
                                <div class="calendar-day-header">Sat</div>
                                <?php for ($i = 0; $i < $firstDayOfWeek; $i++): ?>
                                    <div class="calendar-day empty"></div>
                                <?php endfor; ?>
                                <?php for ($day = 1; $day <= $daysInMonth; $day++):
                                    $dateKey   = sprintf('%04d-%02d-%02d', $currentYear, $currentMonth, $day);
                                    $isToday   = ($dateKey === $today);
                                    $dow       = (int)date('w', strtotime($dateKey));
                                    $isWeekend = ($dow === 0 || $dow === 6);
                                    $cls = 'calendar-day';
                                    if ($isToday)                     $cls .= ' today';
                                    elseif ($isWeekend)               $cls .= ' weekend';
                                ?>
                                    <div class="<?php echo $cls; ?>">
                                        <span class="day-number"><?php echo $day; ?></span>
                                        <?php
                                        if (isset($blockedDatesByDate[$dateKey])) {
                                            foreach ($blockedDatesByDate[$dateKey] as $bd) {
                                                if ($bd['room_id'] == $room['id'] || $bd['room_id'] === null) {
                                                    $btLabel  = ucfirst(htmlspecialchars($bd['block_type'] ?? 'blocked'));
                                                    $btReason = htmlspecialchars($bd['reason'] ?? '');
                                                    $ttip = $btLabel . ($btReason ? ': ' . $btReason : '');
                                                    echo '<div class="blocked-chip" title="' . $ttip . '" onclick="window.location.href=\'blocked-dates.php\'">'
                                                       . '<i class="fas fa-ban" style="font-size:9px;"></i> ' . $btLabel . '</div>';
                                                }
                                            }
                                        }
                                        if (isset($bookingsByDate[$dateKey][$room['id']])) {
                                            foreach ($bookingsByDate[$dateKey][$room['id']] as $bk) {
                                                $st       = $bk['status'];
                                                $name     = htmlspecialchars($bk['guest_name']);
                                                $nameS    = mb_strlen($name) > 14 ? mb_substr($name, 0, 13) . '...' : $name;
                                                $ref      = htmlspecialchars($bk['booking_reference']);
                                                $nights   = $bk['number_of_nights'];
                                                $bkId     = (int)$bk['id'];
                                                $isCIDay  = ($bk['check_in_date'] === $dateKey);
                                                $ciIcon   = $isCIDay ? '<i class="fas fa-sign-in-alt" style="font-size:9px;margin-right:2px;"></i>' : '';
                                                $ttip = $name . ' | ' . $ref . ' | ' . $nights . ' night' . ($nights!=1?'s':'') . ' | ' . ucfirst(str_replace('-',' ',$st));
                                                echo '<div class="booking-chip status-' . $st . '" onclick="openBookingModal(' . $bkId . ')" title="' . $ttip . '">'
                                                   . '<span class="chip-name">' . $ciIcon . $nameS . '</span>'
                                                   . '<span class="chip-detail">' . $ref . ' &middot; ' . $nights . 'n</span>'
                                                   . '</div>';
                                            }
                                        }
                                        ?>
                                    </div>
                                <?php endfor; ?>
                            </div>
                            <?php if ($roomBkCount > 0): ?>
                                <div class="month-summary">
                                    <span style="font-weight:600;color:#495057;">
                                        <i class="fas fa-info-circle"></i> <?php echo $monthNames[$currentMonth]; ?> summary:
                                    </span>
                                    <span class="summary-pill">
                                        <span style="font-weight:700;color:#0A1929;"><?php echo $roomBkCount; ?></span>&nbsp;booking<?php echo $roomBkCount != 1 ? 's' : ''; ?>
                                    </span>
                                    <?php
                                    $dotColors = [
                                        'pending'=>'#ffc107','tentative'=>'#fd7e14','confirmed'=>'#28a745',
                                        'checked-in'=>'#17a2b8','expired'=>'#6c757d','no-show'=>'#dc3545'
                                    ];
                                    foreach ($statusCount as $st => $cnt): ?>
                                        <span class="summary-pill">
                                            <span class="dot" style="background:<?php echo $dotColors[$st] ?? '#999'; ?>;"></span>
                                            <?php echo $cnt . ' ' . str_replace('-',' ',$st); ?>
                                        </span>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="empty-state"><i class="fas fa-inbox"></i><p>No active rooms found.</p></div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Booking Detail Modal -->
    <div class="bk-modal-overlay" id="bk-modal-overlay" onclick="closeBkModalOnOverlay(event)">
        <div class="bk-modal" id="bk-modal" role="dialog" aria-modal="true" aria-labelledby="bk-modal-title">
            <div class="bk-modal-header">
                <div>
                    <h3 id="bk-modal-title">Booking Details</h3>
                    <span class="bk-modal-ref" id="bk-modal-ref"></span>
                </div>
                <button class="bk-modal-close" onclick="closeBkModal()" aria-label="Close">&times;</button>
            </div>
            <div class="bk-modal-body" id="bk-modal-body"></div>
            <div class="bk-modal-actions" id="bk-modal-actions"></div>
        </div>
    </div>

    <script>
    const BOOKINGS_DATA = <?php echo json_encode($bookingsJson, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE); ?>;

    function openBookingModal(id) {
        var b = BOOKINGS_DATA[id];
        if (!b) return;
        document.getElementById('bk-modal-title').textContent = b.guest_name;
        document.getElementById('bk-modal-ref').textContent   = 'Ref: ' + b.ref;
        var payClass = b.payment_status === 'Paid' ? 'highlight' : b.payment_status === 'Partial' ? 'warn' : 'danger';
        var amountDueRow = parseFloat(String(b.amount_due).replace(/,/g,'')) > 0
            ? '<div class="bk-row"><span class="bk-label">Amount Due</span><span class="bk-value danger">' + b.currency + ' ' + b.amount_due + '</span></div>' : '';
        var vatRow = parseFloat(String(b.vat_amount).replace(/,/g,'')) > 0
            ? '<div class="bk-row"><span class="bk-label">Total incl. VAT</span><span class="bk-value">' + b.currency + ' ' + b.total_with_vat + '</span></div>' : '';
        var specialReq = b.special_requests
            ? '<div class="bk-special"><strong>Special Requests</strong>' + escHtml(b.special_requests) + '</div>' : '';
        var tentativeNotice = b.is_tentative
            ? '<div style="background:#fff3cd;color:#856404;border-radius:6px;padding:6px 10px;font-size:12px;margin-bottom:10px;"><i class="fas fa-clock"></i> This is a <strong>tentative</strong> booking.</div>' : '';
        var sizeRow = b.size_sqm !== '-'
            ? '<div class="bk-row"><span class="bk-label">Size</span><span class="bk-value">' + b.size_sqm + ' m\u00B2</span></div>' : '';

        document.getElementById('bk-modal-body').innerHTML =
            tentativeNotice +
            '<div class="bk-modal-status status-' + b.status_raw + '"><i class="fas fa-circle" style="font-size:8px;"></i> ' + b.status + '</div>' +
            '<div class="bk-section"><div class="bk-section-title"><i class="fas fa-user"></i> Guest</div>' +
                '<div class="bk-row"><span class="bk-label">Name</span><span class="bk-value">' + escHtml(b.guest_name) + '</span></div>' +
                '<div class="bk-row"><span class="bk-label">Email</span><span class="bk-value"><a href="mailto:' + escHtml(b.guest_email) + '" style="color:#0A1929;">' + escHtml(b.guest_email) + '</a></span></div>' +
                '<div class="bk-row"><span class="bk-label">Phone</span><span class="bk-value"><a href="tel:' + escHtml(b.guest_phone) + '" style="color:#0A1929;">' + escHtml(b.guest_phone) + '</a></span></div>' +
                '<div class="bk-row"><span class="bk-label">Country</span><span class="bk-value">' + escHtml(b.guest_country) + '</span></div>' +
            '</div>' +
            '<div class="bk-section"><div class="bk-section-title"><i class="fas fa-bed"></i> Room</div>' +
                '<div class="bk-row"><span class="bk-label">Room</span><span class="bk-value">' + escHtml(b.room_name) + '</span></div>' +
                '<div class="bk-row"><span class="bk-label">Bed Type</span><span class="bk-value">' + escHtml(b.bed_type) + '</span></div>' +
                '<div class="bk-row"><span class="bk-label">Room Capacity</span><span class="bk-value">Up to ' + b.room_capacity + ' guests</span></div>' +
                sizeRow +
            '</div>' +
            '<div class="bk-section"><div class="bk-section-title"><i class="fas fa-calendar-check"></i> Stay</div>' +
                '<div class="bk-row"><span class="bk-label">Check-in</span><span class="bk-value">' + escHtml(b.check_in) + '</span></div>' +
                '<div class="bk-row"><span class="bk-label">Check-out</span><span class="bk-value">' + escHtml(b.check_out) + '</span></div>' +
                '<div class="bk-row"><span class="bk-label">Nights</span><span class="bk-value">' + b.nights + '</span></div>' +
                '<div class="bk-row"><span class="bk-label">Guests</span><span class="bk-value">' + b.guests + '</span></div>' +
                '<div class="bk-row"><span class="bk-label">Occupancy</span><span class="bk-value">' + escHtml(b.occupancy_type) + '</span></div>' +
            '</div>' +
            '<div class="bk-section"><div class="bk-section-title"><i class="fas fa-money-bill-wave"></i> Financials</div>' +
                '<div class="bk-row"><span class="bk-label">Total</span><span class="bk-value">' + b.currency + ' ' + b.total_amount + '</span></div>' +
                vatRow +
                '<div class="bk-row"><span class="bk-label">Amount Paid</span><span class="bk-value highlight">' + b.currency + ' ' + b.amount_paid + '</span></div>' +
                amountDueRow +
                '<div class="bk-row"><span class="bk-label">Payment Status</span><span class="bk-value ' + payClass + '">' + escHtml(b.payment_status) + '</span></div>' +
            '</div>' +
            specialReq;

        document.getElementById('bk-modal-actions').innerHTML =
            '<a href="booking-details.php?id=' + b.id + '" class="bk-btn primary"><i class="fas fa-eye"></i> Full Details</a>' +
            '<a href="edit-booking.php?id=' + b.id + '" class="bk-btn secondary"><i class="fas fa-edit"></i> Edit</a>' +
            '<button class="bk-btn muted" onclick="closeBkModal()"><i class="fas fa-times"></i> Close</button>';

        document.getElementById('bk-modal-overlay').classList.add('open');
        document.body.style.overflow = 'hidden';
    }

    function closeBkModal() {
        document.getElementById('bk-modal-overlay').classList.remove('open');
        document.body.style.overflow = '';
    }

    function closeBkModalOnOverlay(e) {
        if (e.target === document.getElementById('bk-modal-overlay')) closeBkModal();
    }

    function escHtml(str) {
        if (!str) return '';
        return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#039;');
    }

    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') closeBkModal();
    });
    </script>

    <script src="js/admin-components.js"></script>
    <?php require_once 'includes/admin-footer.php'; ?>
</body>
</html>