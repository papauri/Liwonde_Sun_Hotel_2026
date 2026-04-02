<?php
// Include admin initialization (PHP-only, no HTML output)
require_once 'admin-init.php';

$user = [
    'id' => $_SESSION['admin_user_id'],
    'username' => $_SESSION['admin_username'],
    'role' => $_SESSION['admin_role'],
    'full_name' => $_SESSION['admin_full_name']
];
$site_name = getSetting('site_name');
$currency_symbol = getSetting('currency_symbol', 'K');
$today = date('Y-m-d');
$thisMonth = date('Y-m');
$thisYear = date('Y');
$message = '';
$error = '';
$vatEnabled = false;
$vatRate = 0;
$vatNumber = '';
$levyEnabled = false;
$levyRate = 0;
$menuChargesTotal = 0;
$otherChargesTotal = 0;
$levyCollectedTotal = 0;
$chargesSummary = [];
$levyComplianceDetails = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_levy_settings'])) {
    try {
        if (!isset($_POST['csrf_token']) || !validateCsrfToken($_POST['csrf_token'])) {
            throw new Exception('Invalid security token. Please refresh and try again.');
        }

        $levy_enabled = isset($_POST['tourist_levy_enabled']) ? '1' : '0';
        $levy_rate = (float)($_POST['tourist_levy_rate'] ?? 0);

        if ($levy_rate < 0 || $levy_rate > 100) {
            throw new Exception('Tourist levy rate must be between 0 and 100%.');
        }

        updateSetting('tourist_levy_enabled', $levy_enabled);
        updateSetting('tourist_levy_rate', number_format($levy_rate, 2, '.', ''));

        $message = 'Tourist levy settings updated successfully.';
    } catch (Throwable $e) {
        $error = 'Levy settings update failed: ' . $e->getMessage();
    }
}

// Handle CSV export for levy report
if (isset($_GET['export_levy']) && $_GET['export_levy'] === '1') {
    $exportStartDate = isset($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-01');
    $exportEndDate = isset($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-t');

    $exportStmt = $pdo->prepare("
        SELECT
            b.booking_reference,
            b.guest_name,
            b.check_in_date,
            b.check_out_date,
            bac.amount as levy_amount,
            bac.description as levy_description,
            bac.created_at as levy_applied_date,
            SUM(CASE WHEN p.payment_status = 'completed' THEN p.total_amount ELSE 0 END) as total_paid
        FROM bookings b
        INNER JOIN booking_additional_charges bac ON b.id = bac.booking_id
        LEFT JOIN payments p ON p.booking_type = 'room' AND p.booking_id = b.id
        WHERE bac.charge_type = 'levy'
        AND bac.is_active = 1
        AND bac.created_at BETWEEN ? AND ?
        GROUP BY b.id, bac.id
        ORDER BY b.check_in_date DESC
    ");
    $exportStmt->execute([$exportStartDate, $exportEndDate]);
    $exportData = $exportStmt->fetchAll(PDO::FETCH_ASSOC);

    // Create CSV output
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=levy-compliance-' . date('Y-m-d') . '.csv');
    
    $output = fopen('php://output', 'w');
    
    // Add header row
    fputcsv($output, ['Booking Reference', 'Guest Name', 'Check-In Date', 'Check-Out Date', 'Levy Amount', 'Levy Description', 'Levy Applied Date', 'Total Paid']);
    
    // Add data rows
    foreach ($exportData as $row) {
        fputcsv($output, [
            $row['booking_reference'],
            $row['guest_name'],
            date('Y-m-d', strtotime($row['check_in_date'])),
            date('Y-m-d', strtotime($row['check_out_date'])),
            number_format($row['levy_amount'], 2, '.', ''),
            $row['levy_description'],
            date('Y-m-d H:i', strtotime($row['levy_applied_date'])),
            number_format($row['total_paid'] ?? 0, 2, '.', '')
        ]);
    }
    
    fclose($output);
    exit;
}

// Ensure required tables exist
ensureBookingAdditionalChargesTable();

// Get date filters - support "all" for no date filtering
$showAll = isset($_GET['show_all']) && $_GET['show_all'] === '1';
$startDate = isset($_GET['start_date']) ? $_GET['start_date'] : ($showAll ? '2000-01-01' : date('Y-m-01'));
$endDate = isset($_GET['end_date']) ? $_GET['end_date'] : ($showAll ? '2099-12-31' : date('Y-m-t'));

// Debug: Check if required tables exist
$tables_exist = [
    'payments' => false,
    'bookings' => false,
    'conference_inquiries' => false,
    'booking_additional_charges' => false
];

try {
    foreach (array_keys($tables_exist) as $table) {
        $result = $pdo->query("SHOW TABLES LIKE '$table'")->rowCount();
        $tables_exist[$table] = ($result > 0);
    }
} catch (PDOException $e) {
    error_log("Table check error: " . $e->getMessage());
}

// Fetch accounting statistics
try {
    // Overall financial summary
    $financialStmt = $pdo->prepare("
        SELECT
            COUNT(*) as total_payments,
            COALESCE(SUM(CASE WHEN payment_status = 'completed' THEN COALESCE(total_amount, 0) ELSE 0 END), 0) as total_collected,
            COALESCE(SUM(CASE WHEN payment_status = 'completed' THEN COALESCE(payment_amount, 0) ELSE 0 END), 0) as total_collected_excl_vat,
            COALESCE(SUM(CASE WHEN payment_status = 'completed' THEN COALESCE(vat_amount, 0) ELSE 0 END), 0) as total_vat_collected,
            COALESCE(SUM(CASE WHEN payment_status = 'pending' THEN COALESCE(total_amount, 0) ELSE 0 END), 0) as total_pending,
            COALESCE(SUM(CASE WHEN payment_status = 'refunded' THEN COALESCE(total_amount, 0) ELSE 0 END), 0) as total_refunded,
            COALESCE(SUM(CASE WHEN payment_status = 'partially_refunded' THEN COALESCE(total_amount, 0) ELSE 0 END), 0) as total_partially_refunded
        FROM payments
        WHERE COALESCE(payment_date, created_at) BETWEEN ? AND ?
    ");
    $financialStmt->execute([$startDate, $endDate]);
    $financialSummary = $financialStmt->fetch(PDO::FETCH_ASSOC);

    // Room bookings financial summary
    $roomStmt = $pdo->prepare("
        SELECT
            COUNT(DISTINCT p.booking_id) as total_bookings_with_payments,
            COALESCE(SUM(CASE WHEN p.payment_status = 'completed' THEN COALESCE(p.total_amount, 0) ELSE 0 END), 0) as room_collected,
            COALESCE(SUM(CASE WHEN p.payment_status = 'completed' THEN COALESCE(p.vat_amount, 0) ELSE 0 END), 0) as room_vat_collected,
            COALESCE(SUM(b.amount_due), 0) as total_room_outstanding
        FROM payments p
        LEFT JOIN bookings b ON p.booking_type = 'room' AND p.booking_id = b.id
        WHERE p.booking_type = 'room'
        AND COALESCE(p.payment_date, p.created_at) BETWEEN ? AND ?
    ");
    $roomStmt->execute([$startDate, $endDate]);
    $roomSummary = $roomStmt->fetch(PDO::FETCH_ASSOC);

    // Conference bookings financial summary
    $confStmt = $pdo->prepare("
        SELECT
            COUNT(DISTINCT p.booking_id) as total_conferences_with_payments,
            COALESCE(SUM(CASE WHEN p.payment_status = 'completed' THEN COALESCE(p.total_amount, 0) ELSE 0 END), 0) as conf_collected,
            COALESCE(SUM(CASE WHEN p.payment_status = 'completed' THEN COALESCE(p.vat_amount, 0) ELSE 0 END), 0) as conf_vat_collected,
            COALESCE(SUM(ci.amount_due), 0) as total_conf_outstanding
        FROM payments p
        LEFT JOIN conference_inquiries ci ON p.booking_type = 'conference' AND p.booking_id = ci.id
        WHERE p.booking_type = 'conference'
        AND COALESCE(p.payment_date, p.created_at) BETWEEN ? AND ?
    ");
    $confStmt->execute([$startDate, $endDate]);
    $confSummary = $confStmt->fetch(PDO::FETCH_ASSOC);

    // Payment method breakdown
    $methodStmt = $pdo->prepare("
        SELECT
            payment_method,
            COUNT(*) as count,
            COALESCE(SUM(CASE WHEN payment_status = 'completed' THEN COALESCE(total_amount, 0) ELSE 0 END), 0) as total
        FROM payments
        WHERE COALESCE(payment_date, created_at) BETWEEN ? AND ?
        GROUP BY payment_method
        ORDER BY total DESC
    ");
    $methodStmt->execute([$startDate, $endDate]);
    $paymentMethods = $methodStmt->fetchAll(PDO::FETCH_ASSOC);

    // Recent payments (last 20)
    $recentStmt = $pdo->prepare("
        SELECT
            p.*,
            CASE
                WHEN p.booking_type = 'room' THEN CONCAT(b.guest_name, ' (', b.booking_reference, ')')
                WHEN p.booking_type = 'conference' THEN CONCAT(ci.company_name, ' (', ci.inquiry_reference, ')')
                ELSE 'Unknown'
            END as booking_description
        FROM payments p
        LEFT JOIN bookings b ON p.booking_type = 'room' AND p.booking_id = b.id
        LEFT JOIN conference_inquiries ci ON p.booking_type = 'conference' AND p.booking_id = ci.id
        ORDER BY p.payment_date DESC, p.created_at DESC
        LIMIT 20
    ");
    $recentStmt->execute();
    $recentPayments = $recentStmt->fetchAll(PDO::FETCH_ASSOC);

    // Outstanding payments summary
    $outstandingStmt = $pdo->query("
        SELECT 
            'room' as type,
            COUNT(*) as count,
            SUM(amount_due) as total_outstanding
        FROM bookings
        WHERE amount_due > 0
        UNION ALL
        SELECT 
            'conference' as type,
            COUNT(*) as count,
            SUM(amount_due) as total_outstanding
        FROM conference_inquiries
        WHERE amount_due > 0
    ");
    $outstandingSummary = $outstandingStmt->fetchAll(PDO::FETCH_ASSOC);

    // Charges summary (menu, other, levy)
    $chargesStmt = $pdo->prepare("
        SELECT
            charge_type,
            COUNT(*) as count,
            COALESCE(SUM(amount), 0) as total
        FROM booking_additional_charges
        WHERE is_active = 1
        AND created_at BETWEEN ? AND ?
        GROUP BY charge_type
    ");
    $chargesStmt->execute([$startDate, $endDate]);
    $chargesSummary = $chargesStmt->fetchAll(PDO::FETCH_ASSOC);

    // Initialize charges totals
    $menuChargesTotal = 0;
    $otherChargesTotal = 0;
    $levyCollectedTotal = 0;
    foreach ($chargesSummary as $charge) {
        if ($charge['charge_type'] === 'menu') {
            $menuChargesTotal = $charge['total'];
        } elseif ($charge['charge_type'] === 'other') {
            $otherChargesTotal = $charge['total'];
        } elseif ($charge['charge_type'] === 'levy') {
            $levyCollectedTotal = $charge['total'];
        }
    }

    // Levy compliance report - bookings with levy applied
    $levyComplianceStmt = $pdo->prepare("
        SELECT
            b.id as booking_id,
            b.booking_reference,
            b.guest_name,
            b.check_in_date,
            b.check_out_date,
            bac.amount as levy_amount,
            SUM(CASE WHEN p.payment_status = 'completed' THEN p.total_amount ELSE 0 END) as total_paid
        FROM bookings b
        INNER JOIN booking_additional_charges bac ON b.id = bac.booking_id
        LEFT JOIN payments p ON p.booking_type = 'room' AND p.booking_id = b.id
        WHERE bac.charge_type = 'levy'
        AND bac.is_active = 1
        AND bac.created_at BETWEEN ? AND ?
        GROUP BY b.id, bac.id
        ORDER BY b.check_in_date DESC
    ");
    $levyComplianceStmt->execute([$startDate, $endDate]);
    $levyComplianceDetails = $levyComplianceStmt->fetchAll(PDO::FETCH_ASSOC);

    // VAT settings - more flexible check
    $vatEnabled = in_array(getSetting('vat_enabled'), ['1', 1, true, 'true', 'on'], true);
    $vatRate = getSetting('vat_rate');
    $vatNumber = getSetting('vat_number');

    $levyEnabled = in_array(getSetting('tourist_levy_enabled', '0'), ['1', 1, true, 'true', 'on'], true);
    $levyRate = (float)getSetting('tourist_levy_rate', 0);

} catch (PDOException $e) {
    error_log("Accounting Dashboard Error: " . $e->getMessage());
    error_log("SQL Error Code: " . $e->getCode());
    error_log("Table Status: " . json_encode($tables_exist));
    
    // Build helpful error message
    $missing_tables = array_filter($tables_exist, function($exists) { return !$exists; });
    if (!empty($missing_tables)) {
        $error = "Database tables missing: " . implode(', ', array_keys($missing_tables));
    } else {
        $error = "Database query failed: " . $e->getMessage();
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Accounting Dashboard | <?php echo htmlspecialchars($site_name); ?> Admin</title>
    
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;500;600;700&family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../css/style.css">
    <link rel="stylesheet" href="../css/theme-dynamic.php">
    <link rel="stylesheet" href="css/admin-styles.css">
    <link rel="stylesheet" href="css/admin-components.css">
    
    <style>
        .accounting-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 24px;
            flex-wrap: wrap;
            gap: 16px;
        }
        
        .date-filter {
            display: flex;
            gap: 12px;
            align-items: center;
            background: white;
            padding: 12px 20px;
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow);
        }
        
        .date-filter input {
            padding: 8px 12px;
            border: 1px solid #ddd;
            border-radius: var(--radius);
            font-family: inherit;
        }
        
        .date-filter button {
            padding: 8px 16px;
            background: var(--navy);
            color: white;
            border: none;
            border-radius: var(--radius);
            cursor: pointer;
            font-weight: 500;
        }
        
        .date-filter button:hover {
            background: var(--gold);
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 24px;
            margin-bottom: 32px;
        }
        
        .stat-card {
            position: relative;
            overflow: hidden;
        }
        
        .stat-card.primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            box-shadow: 0 8px 20px rgba(102, 126, 234, 0.3);
        }
        
        .stat-card.success {
            background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%);
            color: white;
            box-shadow: 0 8px 20px rgba(17, 153, 142, 0.3);
        }
        
        .stat-card.warning {
            background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
            color: white;
            box-shadow: 0 8px 20px rgba(245, 87, 108, 0.3);
        }
        
        .stat-card.danger {
            background: linear-gradient(135deg, #fa709a 0%, #fee140 100%);
            color: #333;
            box-shadow: 0 8px 20px rgba(250, 112, 154, 0.3);
        }
        
        .stat-card.info {
            background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
            color: white;
            box-shadow: 0 8px 20px rgba(79, 172, 254, 0.3);
        }
        
        .stat-card.gold {
            background: linear-gradient(135deg, var(--gold) 0%, #c49b2e 100%);
            color: var(--deep-navy);
            box-shadow: 0 8px 20px rgba(212, 175, 55, 0.4);
        }
        
        .stat-label {
            font-size: 14px;
            opacity: 0.9;
            margin-bottom: 8px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .stat-value {
            font-size: 32px;
            font-weight: 700;
            margin-bottom: 4px;
        }
        
        .stat-sub {
            font-size: 13px;
            opacity: 0.8;
        }
        
        .section-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(400px, 1fr));
            gap: 24px;
            margin-bottom: 32px;
        }
        
        .section-card {
            background: white;
            padding: 24px;
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow);
        }
        
        .section-card h3 {
            margin-bottom: 20px;
            color: var(--navy);
            font-size: 18px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .payment-method-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 12px 0;
            border-bottom: 1px solid #eee;
        }
        
        .payment-method-item:last-child {
            border-bottom: none;
        }
        
        .method-name {
            display: flex;
            align-items: center;
            gap: 10px;
            font-weight: 500;
        }
        
        .method-icon {
            width: 32px;
            height: 32px;
            background: var(--light-gray);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--navy);
        }
        
        .method-stats {
            text-align: right;
        }
        
        .method-count {
            font-size: 12px;
            color: #666;
        }
        
        .method-total {
            font-weight: 600;
            color: var(--navy);
        }
        
        .outstanding-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 12px 0;
            border-bottom: 1px solid #eee;
        }
        
        .outstanding-item:last-child {
            border-bottom: none;
        }
        
        .outstanding-type {
            font-weight: 500;
            color: var(--navy);
        }
        
        .outstanding-amount {
            font-weight: 600;
            color: #dc3545;
        }
        
        .badge-completed {
            background: #d4edda;
            color: #155724;
        }
        
        .badge-pending {
            background: #fff3cd;
            color: #856404;
        }
        
        .badge-refunded {
            background: #f8d7da;
            color: #721c24;
        }
        
        .badge-partially_refunded {
            background: #e2e3e5;
            color: #383d41;
        }
        
        .badge-failed {
            background: #f8d7da;
            color: #721c24;
        }
        
        .quick-actions {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
        }
        
        .quick-actions a {
            padding: 10px 20px;
            background: var(--navy);
            color: white;
            border-radius: var(--radius);
            text-decoration: none;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s ease;
        }
        
        .quick-actions a:hover {
            background: var(--gold);
            transform: translateY(-2px);
        }
        
        .quick-actions a.secondary {
            background: white;
            color: var(--navy);
            border: 2px solid var(--navy);
        }
        
        .quick-actions a.secondary:hover {
            background: var(--navy);
            color: white;
        }
        
        .vat-info {
            background: #f8f9fa;
            padding: 16px;
            border-radius: var(--radius);
            margin-bottom: 20px;
            border-left: 4px solid var(--navy);
        }
        
        .vat-info p {
            margin: 4px 0;
            font-size: 14px;
        }
        
        .vat-info strong {
            color: var(--navy);
        }
    </style>
</head>
<body>

    <?php require_once 'includes/admin-header.php'; ?>

    <div class="content">
        <div class="accounting-header">
            <div>
                <h2 class="section-title">Accounting Dashboard</h2>
                <p style="color: #666; margin-top: 4px;">Financial overview and payment tracking</p>
            </div>
            
            <form method="GET" class="date-filter">
                <label>
                    From: <input type="date" name="start_date" value="<?php echo htmlspecialchars($showAll ? '' : $startDate); ?>">
                </label>
                <label>
                    To: <input type="date" name="end_date" value="<?php echo htmlspecialchars($showAll ? '' : $endDate); ?>">
                </label>
                <button type="submit">
                    <i class="fas fa-filter"></i> Apply Filter
                </button>
                <a href="accounting-dashboard.php?show_all=1" style="color: var(--navy); text-decoration: none; font-size: 14px; margin-left: 10px;">
                    <i class="fas fa-calendar-alt"></i> Show All Time
                </a>
                <a href="accounting-dashboard.php" style="color: var(--navy); text-decoration: none; font-size: 14px; margin-left: 10px;">Reset</a>
            </form>
        </div>

        <?php if ($message): ?>
            <div style="background:#d4edda;color:#155724;padding:12px 16px;border-radius:8px;margin-bottom:14px;"><?php echo htmlspecialchars($message); ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div style="background:#f8d7da;color:#721c24;padding:12px 16px;border-radius:8px;margin-bottom:14px;"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <!-- Quick Actions -->
        <div class="quick-actions" style="margin-bottom: 24px;">
            <a href="payments.php">
                <i class="fas fa-list"></i> View All Payments
            </a>
            <a href="payment-add.php" class="secondary">
                <i class="fas fa-plus"></i> Record Payment
            </a>
            <a href="reports.php" class="secondary">
                <i class="fas fa-chart-bar"></i> Financial Reports
            </a>
            <a href="booking-settings.php#vat" class="secondary">
                <i class="fas fa-cog"></i> VAT Settings
            </a>
        </div>

        <!-- VAT Information -->
        <div class="vat-info">
            <p><strong>VAT Status:</strong> <?php echo $vatEnabled ? 'Enabled' : 'Disabled'; ?></p>
            <?php if ($vatEnabled): ?>
                <p><strong>VAT Rate:</strong> <?php echo htmlspecialchars($vatRate); ?>%</p>
                <?php if ($vatNumber): ?>
                    <p><strong>VAT Number:</strong> <?php echo htmlspecialchars($vatNumber); ?></p>
                <?php endif; ?>
            <?php endif; ?>
            <hr style="border:none;border-top:1px solid rgba(255,255,255,0.25); margin:10px 0;">
            <p><strong>Tourist Levy:</strong> <?php echo $levyEnabled ? 'Enabled' : 'Disabled'; ?></p>
            <p><strong>Levy Rate:</strong> <?php echo number_format((float)$levyRate, 2); ?>%</p>

            <form method="POST" style="margin-top:10px; display:grid; gap:8px; max-width:380px;">
                <input type="hidden" name="save_levy_settings" value="1">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                <label style="display:flex; align-items:center; gap:8px; font-weight:500; color:#fff;">
                    <input type="checkbox" name="tourist_levy_enabled" value="1" <?php echo $levyEnabled ? 'checked' : ''; ?>>
                    Enable tourist levy
                </label>
                <label style="font-weight:500; color:#fff;">Levy Rate (%)</label>
                <input type="number" step="0.01" min="0" max="100" name="tourist_levy_rate" value="<?php echo htmlspecialchars(number_format((float)$levyRate, 2, '.', '')); ?>" style="padding:8px 10px;border:1px solid #ddd;border-radius:6px;">
                <small style="opacity:0.9; color:#fff;">This levy is optional per guest and can be toggled on each booking details page.</small>
                <button type="submit" style="padding:10px 14px; border:none; border-radius:6px; background:#fff; color:var(--deep-navy); font-weight:600; cursor:pointer; width:fit-content;">Save Levy Settings</button>
            </form>
        </div>

        <!-- Financial Summary Stats -->
        <div class="stats-grid">
            <div class="stat-card primary">
                <div class="stat-label">Total Collected</div>
                <div class="stat-value"><?php echo $currency_symbol; ?><?php echo number_format($financialSummary['total_collected'] ?? 0, 0); ?></div>
                <div class="stat-sub">
                    <?php echo $financialSummary['total_payments'] ?? 0; ?> payments
                    <?php if ($vatEnabled && ($financialSummary['total_vat_collected'] ?? 0) > 0): ?>
                        <br>(incl. <?php echo $currency_symbol; ?><?php echo number_format($financialSummary['total_vat_collected'] ?? 0, 0); ?> VAT)
                    <?php endif; ?>
                </div>
            </div>

            <div class="stat-card warning">
                <div class="stat-label">Pending Payments</div>
                <div class="stat-value"><?php echo $currency_symbol; ?><?php echo number_format($financialSummary['total_pending'] ?? 0, 0); ?></div>
                <div class="stat-sub">Awaiting confirmation</div>
            </div>

            <div class="stat-card danger">
                <div class="stat-label">Refunded</div>
                <div class="stat-value"><?php echo $currency_symbol; ?><?php echo number_format(($financialSummary['total_refunded'] ?? 0) + ($financialSummary['total_partially_refunded'] ?? 0), 0); ?></div>
                <div class="stat-sub">Refunded amount</div>
            </div>

            <div class="stat-card success">
                <div class="stat-label">Room Revenue</div>
                <div class="stat-value"><?php echo $currency_symbol; ?><?php echo number_format($roomSummary['room_collected'] ?? 0, 0); ?></div>
                <div class="stat-sub">
                    <?php echo $roomSummary['total_bookings_with_payments'] ?? 0; ?> bookings with payments
                    <?php if ($vatEnabled && ($roomSummary['room_vat_collected'] ?? 0) > 0): ?>
                        <br>(incl. <?php echo $currency_symbol; ?><?php echo number_format($roomSummary['room_vat_collected'] ?? 0, 0); ?> VAT)
                    <?php endif; ?>
                </div>
            </div>

            <div class="stat-card info">
                <div class="stat-label">Conference Revenue</div>
                <div class="stat-value"><?php echo $currency_symbol; ?><?php echo number_format($confSummary['conf_collected'] ?? 0, 0); ?></div>
                <div class="stat-sub">
                    <?php echo $confSummary['total_conferences_with_payments'] ?? 0; ?> conferences with payments
                    <?php if ($vatEnabled && ($confSummary['conf_vat_collected'] ?? 0) > 0): ?>
                        <br>(incl. <?php echo $currency_symbol; ?><?php echo number_format($confSummary['conf_vat_collected'] ?? 0, 0); ?> VAT)
                    <?php endif; ?>
                </div>
            </div>

            <?php if ($menuChargesTotal > 0 || $otherChargesTotal > 0 || $levyCollectedTotal > 0): ?>
                <div class="stat-card success">
                    <div class="stat-label">Menu Charges</div>
                    <div class="stat-value"><?php echo $currency_symbol; ?><?php echo number_format($menuChargesTotal, 0); ?></div>
                    <div class="stat-sub">Additional menu charges collected</div>
                </div>

                <div class="stat-card gold">
                    <div class="stat-label">Other Charges</div>
                    <div class="stat-value"><?php echo $currency_symbol; ?><?php echo number_format($otherChargesTotal, 0); ?></div>
                    <div class="stat-sub">Service & miscellaneous charges</div>
                </div>

                <div class="stat-card primary">
                    <div class="stat-label">Levy Collected</div>
                    <div class="stat-value"><?php echo $currency_symbol; ?><?php echo number_format($levyCollectedTotal, 0); ?></div>
                    <div class="stat-sub">Tourist/hospitality levy</div>
                </div>
            <?php endif; ?>
        </div>

        <!-- Payment Methods & Outstanding -->
        <div class="section-grid">
            <div class="section-card">
                <h3>
                    <i class="fas fa-credit-card"></i> Payment Methods
                </h3>
                <?php if (!empty($paymentMethods)): ?>
                    <?php foreach ($paymentMethods as $method): ?>
                        <div class="payment-method-item">
                            <div class="method-name">
                                <div class="method-icon">
                                    <?php
                                    $icon = 'fa-money-bill';
                                    switch ($method['payment_method']) {
                                        case 'cash': $icon = 'fa-money-bill-wave'; break;
                                        case 'bank_transfer': $icon = 'fa-building-columns'; break;
                                        case 'credit_card': $icon = 'fa-credit-card'; break;
                                        case 'debit_card': $icon = 'fa-credit-card'; break;
                                        case 'mobile_money': $icon = 'fa-mobile-screen'; break;
                                        case 'cheque': $icon = 'fa-file-invoice-dollar'; break;
                                    }
                                    ?>
                                    <i class="fas <?php echo $icon; ?>"></i>
                                </div>
                                <?php echo ucfirst(str_replace('_', ' ', $method['payment_method'])); ?>
                            </div>
                            <div class="method-stats">
                                <div class="method-count"><?php echo $method['count']; ?> transactions</div>
                                <div class="method-total"><?php echo $currency_symbol; ?><?php echo number_format($method['total'], 0); ?></div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <p style="color: #999; text-align: center; padding: 20px;">No payment data for selected period</p>
                <?php endif; ?>
            </div>

            <div class="section-card">
                <h3>
                    <i class="fas fa-exclamation-triangle"></i> Outstanding Payments
                </h3>
                <?php if (!empty($outstandingSummary)): ?>
                    <?php foreach ($outstandingSummary as $outstanding): ?>
                        <?php if ($outstanding['total_outstanding'] > 0): ?>
                            <div class="outstanding-item">
                                <div class="outstanding-type">
                                    <?php echo ucfirst($outstanding['type']); ?> Bookings
                                </div>
                                <div>
                                    <div class="method-count"><?php echo $outstanding['count']; ?> outstanding</div>
                                    <div class="outstanding-amount"><?php echo $currency_symbol; ?><?php echo number_format($outstanding['total_outstanding'], 0); ?></div>
                                </div>
                            </div>
                        <?php endif; ?>
                    <?php endforeach; ?>
                <?php else: ?>
                    <p style="color: #28a745; text-align: center; padding: 20px;">
                        <i class="fas fa-check-circle"></i> All payments up to date!
                    </p>
                <?php endif; ?>
            </div>
        </div>

        <!-- Levy Compliance Report -->
        <?php if ($levyEnabled && !empty($levyComplianceDetails)): ?>
        <div style="margin-top: 32px;">
            <h3 class="section-title">
                <i class="fas fa-percent"></i> Levy Compliance Report
            </h3>
            <div class="table-container">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Booking Reference</th>
                            <th>Guest Name</th>
                            <th>Check-In</th>
                            <th>Check-Out</th>
                            <th>Levy Amount</th>
                            <th>Total Paid</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($levyComplianceDetails as $levy): ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($levy['booking_reference']); ?></strong></td>
                                <td><?php echo htmlspecialchars($levy['guest_name']); ?></td>
                                <td><?php echo date('M j, Y', strtotime($levy['check_in_date'])); ?></td>
                                <td><?php echo date('M j, Y', strtotime($levy['check_out_date'])); ?></td>
                                <td class="font-weight-bold"><?php echo $currency_symbol; ?><?php echo number_format($levy['levy_amount'], 2); ?></td>
                                <td><?php echo $currency_symbol; ?><?php echo number_format($levy['total_paid'] ?? 0, 0); ?></td>
                                <td>
                                    <a href="booking-details.php?id=<?php echo $levy['booking_id']; ?>" class="btn btn-primary btn-sm">
                                        <i class="fas fa-eye"></i> View
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div style="text-align: center; margin-top: 16px;">
                <form method="GET" style="display: inline;">
                    <input type="hidden" name="export_levy" value="1">
                    <input type="hidden" name="start_date" value="<?php echo htmlspecialchars($startDate); ?>">
                    <input type="hidden" name="end_date" value="<?php echo htmlspecialchars($endDate); ?>">
                    <button type="submit" style="padding: 8px 16px; background: var(--navy); color: white; border: none; border-radius: 6px; cursor: pointer; font-weight: 500;">
                        <i class="fas fa-download"></i> Export Levy Report (CSV)
                    </button>
                </form>
            </div>
        </div>
        <?php endif; ?>

        <!-- Recent Payments -->
        <h3 class="section-title">Recent Payments</h3>
        <div class="table-container">
            <table class="table">
                <thead>
                    <tr>
                        <th>Reference</th>
                        <th>Booking</th>
                        <th>Type</th>
                        <th>Payment Date</th>
                        <th>Amount</th>
                        <th>Method</th>
                        <th>Status</th>
                        <th>Created</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($recentPayments)): ?>
                        <?php foreach ($recentPayments as $payment): ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($payment['payment_reference']); ?></strong></td>
                                <td><?php echo htmlspecialchars($payment['booking_description']); ?></td>
                                <td>
                                    <span class="badge badge-<?php echo $payment['booking_type']; ?>">
                                        <?php echo ucfirst($payment['booking_type']); ?>
                                    </span>
                                </td>
                                <td>
                                    <?php echo date('M j, Y', strtotime($payment['payment_date'])); ?>
                                    <br><small style="color: #666; font-size: 11px;">
                                        <i class="fas fa-clock"></i> <?php echo date('H:i', strtotime($payment['payment_date'])); ?>
                                    </small>
                                </td>
                                <td>
                                    <?php echo $currency_symbol; ?><?php echo number_format($payment['total_amount'], 0); ?>
                                    <?php if ($payment['vat_amount'] > 0): ?>
                                        <small style="color: #666;">(incl. VAT)</small>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo ucfirst(str_replace('_', ' ', $payment['payment_method'])); ?></td>
                                <td>
                                    <span class="badge badge-<?php echo $payment['payment_status']; ?>">
                                        <?php echo ucfirst(str_replace('_', ' ', $payment['payment_status'])); ?>
                                    </span>
                                </td>
                                <td>
                                    <small style="color: #666; font-size: 11px;">
                                        <i class="fas fa-clock"></i> <?php echo date('M j, H:i', strtotime($payment['created_at'])); ?>
                                    </small>
                                    <?php if ($payment['updated_at'] && $payment['updated_at'] != $payment['created_at']): ?>
                                        <br><small style="color: #999; font-size: 10px;">
                                            <i class="fas fa-edit"></i> <?php echo date('M j, H:i', strtotime($payment['updated_at'])); ?>
                                        </small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <a href="payment-details.php?id=<?php echo $payment['id']; ?>" class="btn btn-primary btn-sm">
                                        <i class="fas fa-eye"></i> View
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="9" class="empty-state">
                                <i class="fas fa-inbox"></i>
                                <p>No payments recorded yet</p>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <?php if (count($recentPayments) >= 20): ?>
            <div style="text-align: center; margin-top: 20px;">
                <a href="payments.php" class="btn btn-primary">
                    View All Payments <i class="fas fa-arrow-right"></i>
                </a>
            </div>
        <?php endif; ?>

    <?php require_once 'includes/admin-footer.php'; ?>
