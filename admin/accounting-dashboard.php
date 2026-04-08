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

    // Recent payments for the selected period (last 20)
    $recentStmt = $pdo->prepare("
        SELECT
            p.*,
            CASE
                WHEN p.booking_type = 'room' THEN CONCAT(b.guest_name, ' (', b.booking_reference, ')')
                WHEN p.booking_type = 'conference' THEN CONCAT(ci.company_name, ' (', ci.inquiry_reference, ')')
                ELSE 'Unknown'
            END as booking_description,
            CASE
                WHEN p.booking_type = 'room' THEN b.booking_reference
                WHEN p.booking_type = 'conference' THEN ci.inquiry_reference
                ELSE NULL
            END as source_reference,
            CASE
                WHEN p.booking_type = 'room' THEN b.guest_name
                WHEN p.booking_type = 'conference' THEN ci.contact_person
                ELSE NULL
            END as customer_name,
            CASE
                WHEN p.booking_type = 'room' THEN b.guest_email
                WHEN p.booking_type = 'conference' THEN ci.email
                ELSE NULL
            END as contact_email,
            CASE
                WHEN p.booking_type = 'room' THEN b.guest_phone
                WHEN p.booking_type = 'conference' THEN ci.phone
                ELSE NULL
            END as contact_phone,
            COALESCE(au.full_name, au.username, p.processed_by, 'System') as recorded_by_name,
            COALESCE(p.payment_reference_number, p.transaction_id) as external_reference,
            COALESCE(p.payment_date, DATE(p.created_at)) as effective_payment_date
        FROM payments p
        LEFT JOIN bookings b ON p.booking_type = 'room' AND p.booking_id = b.id
        LEFT JOIN conference_inquiries ci ON p.booking_type = 'conference' AND p.booking_id = ci.id
        LEFT JOIN admin_users au ON p.recorded_by = au.id
        WHERE p.deleted_at IS NULL
          AND COALESCE(p.payment_date, DATE(p.created_at)) BETWEEN ? AND ?
        ORDER BY COALESCE(p.payment_date, DATE(p.created_at)) DESC, p.created_at DESC
        LIMIT 20
    ");
    $recentStmt->execute([$startDate, $endDate]);
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
            flex-wrap: wrap;
        }

        .date-filter label {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 14px;
            color: var(--navy);
            flex-wrap: wrap;
        }
        
        .date-filter input {
            padding: 8px 12px;
            border: 1px solid #ddd;
            border-radius: var(--radius);
            font-family: inherit;
            min-width: 150px;
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
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 16px;
            margin-bottom: 32px;
            align-items: stretch;
        }
        
        .stat-card {
            position: relative;
            overflow: hidden;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            min-height: 150px;
            padding: 18px 20px;
            background: #fff;
            color: #1f2937;
            border: 1px solid #e5e7eb;
            border-radius: var(--radius-lg);
            box-shadow: 0 2px 10px rgba(15, 23, 42, 0.06);
        }
        
        .stat-card.primary,
        .stat-card.success,
        .stat-card.warning,
        .stat-card.danger,
        .stat-card.info,
        .stat-card.gold {
            background: #fff;
            color: #1f2937;
            box-shadow: 0 2px 10px rgba(15, 23, 42, 0.06);
        }
        
        .stat-label {
            font-size: 14px;
            color: #6b7280;
            margin-bottom: 10px;
            text-transform: uppercase;
            letter-spacing: 0.08em;
        }
        
        .stat-value {
            font-size: clamp(22px, 2vw, 28px);
            font-weight: 700;
            color: var(--navy);
            margin-bottom: 10px;
            line-height: 1.1;
            overflow-wrap: anywhere;
            word-break: break-word;
            white-space: normal;
        }
        
        .stat-sub {
            font-size: 13px;
            color: #6b7280;
            line-height: 1.45;
        }

        .recent-payments-table {
            min-width: 1180px;
        }

        .recent-payments-table td {
            vertical-align: top;
        }

        .payment-summary-cell strong {
            display: block;
            color: var(--navy);
        }

        .payment-summary-cell small,
        .payment-meta small,
        .audit-meta small,
        .booking-meta small {
            color: #6b7280;
            font-size: 11px;
            line-height: 1.45;
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

        .table-container {
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
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

        .levy-settings-form {
            margin-top: 10px;
            display: grid;
            gap: 8px;
            max-width: 380px;
        }

        .levy-settings-form .checkbox-label {
            display: flex;
            align-items: center;
            gap: 8px;
            font-weight: 500;
            color: var(--navy);
        }

        .levy-settings-form .field-label {
            font-weight: 500;
            color: var(--navy);
        }

        .levy-settings-form small {
            color: #6b7280;
        }

        @media (max-width: 1024px) {
            .accounting-header {
                flex-direction: column;
                align-items: stretch;
            }

            .date-filter {
                width: 100%;
            }

            .stats-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }

            .section-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 640px) {
            .content {
                padding-left: 12px;
                padding-right: 12px;
            }

            .date-filter {
                padding: 14px;
                gap: 10px;
            }

            .date-filter label,
            .date-filter input,
            .date-filter button {
                width: 100%;
            }

            .date-filter a {
                width: 100%;
                margin-left: 0 !important;
            }

            .quick-actions {
                flex-direction: column;
            }

            .quick-actions a {
                width: 100%;
                justify-content: center;
            }

            .vat-info {
                padding: 14px;
            }

            .levy-settings-form {
                max-width: none;
            }

            .levy-settings-form input[type="number"],
            .levy-settings-form button {
                width: 100% !important;
            }

            .stats-grid {
                grid-template-columns: 1fr;
            }

            .stat-card {
                min-height: 0;
                padding: 16px;
            }

            .stat-value {
                font-size: 22px;
            }

            .section-card {
                padding: 16px;
            }

            .payment-method-item,
            .outstanding-item {
                align-items: flex-start;
                gap: 10px;
                flex-direction: column;
            }

            .method-stats {
                text-align: left;
            }

            .recent-payments-table {
                min-width: 980px;
            }
        }

        @media (max-width: 480px) {
            .table-container .table {
                font-size: 11px;
                min-width: 680px;
            }

            .recent-payments-table {
                min-width: 860px;
            }

            .table-container .table th,
            .table-container .table td {
                padding: 6px 8px;
                white-space: nowrap;
            }

            .table-container .table td:last-child,
            .table-container .table th:last-child {
                position: sticky;
                right: 0;
                background: #fff;
                z-index: 2;
                box-shadow: -8px 0 10px -8px rgba(15, 23, 42, 0.35);
            }

            .table-container .table thead th:last-child {
                background: #f8f9fa;
                z-index: 3;
            }

            .table-container .table td:last-child .btn,
            .table-container .table td:last-child a {
                font-size: 11px;
                padding: 5px 8px;
                display: inline-flex;
                margin-top: 4px;
                width: 100%;
                justify-content: center;
            }
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

            <form method="POST" class="levy-settings-form">
                <?php echo getCsrfField(); ?>
                <input type="hidden" name="save_levy_settings" value="1">
                <label class="checkbox-label">
                    <input type="checkbox" name="tourist_levy_enabled" value="1" <?php echo $levyEnabled ? 'checked' : ''; ?>>
                    Enable tourist levy
                </label>
                <label class="field-label">Levy Rate (%)</label>
                <input type="number" step="0.01" min="0" max="100" name="tourist_levy_rate" value="<?php echo htmlspecialchars(number_format((float)$levyRate, 2, '.', '')); ?>" style="padding:8px 10px;border:1px solid #ddd;border-radius:6px;">
                <small>This levy is optional per guest and can be toggled on each booking details page.</small>
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
            <table class="table recent-payments-table">
                <thead>
                    <tr>
                        <th>Reference</th>
                        <th>Booking</th>
                        <th>Classification</th>
                        <th>Payment Date</th>
                        <th>Amounts</th>
                        <th>Status</th>
                        <th>Audit</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($recentPayments)): ?>
                        <?php foreach ($recentPayments as $payment): ?>
                            <tr>
                                <td>
                                    <strong><?php echo htmlspecialchars($payment['payment_reference']); ?></strong>
                                    <?php if (!empty($payment['receipt_number'])): ?>
                                        <br><small><i class="fas fa-receipt"></i> Receipt: <?php echo htmlspecialchars($payment['receipt_number']); ?></small>
                                    <?php endif; ?>
                                    <?php if (!empty($payment['invoice_number'])): ?>
                                        <br><small><i class="fas fa-file-invoice"></i> Invoice: <?php echo htmlspecialchars($payment['invoice_number']); ?></small>
                                    <?php endif; ?>
                                </td>
                                <td class="booking-meta">
                                    <strong><?php echo htmlspecialchars($payment['booking_description']); ?></strong>
                                    <?php if (!empty($payment['customer_name']) && $payment['customer_name'] !== $payment['booking_description']): ?>
                                        <br><small><i class="fas fa-user"></i> <?php echo htmlspecialchars($payment['customer_name']); ?></small>
                                    <?php endif; ?>
                                    <?php if (!empty($payment['contact_email'])): ?>
                                        <br><small><i class="fas fa-envelope"></i> <?php echo htmlspecialchars($payment['contact_email']); ?></small>
                                    <?php endif; ?>
                                    <?php if (!empty($payment['contact_phone'])): ?>
                                        <br><small><i class="fas fa-phone"></i> <?php echo htmlspecialchars($payment['contact_phone']); ?></small>
                                    <?php endif; ?>
                                </td>
                                <td class="payment-meta">
                                    <span class="badge badge-<?php echo $payment['booking_type']; ?>">
                                        <?php echo ucfirst($payment['booking_type']); ?>
                                    </span>
                                    <?php if (!empty($payment['payment_type'])): ?>
                                        <br><small><i class="fas fa-tags"></i> <?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $payment['payment_type']))); ?></small>
                                    <?php endif; ?>
                                    <br><small><i class="fas fa-wallet"></i> <?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $payment['payment_method'] ?? 'n/a'))); ?></small>
                                    <?php if (!empty($payment['external_reference'])): ?>
                                        <br><small><i class="fas fa-link"></i> <?php echo htmlspecialchars($payment['external_reference']); ?></small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php echo date('M j, Y', strtotime($payment['effective_payment_date'])); ?>
                                    <br><small style="color: #666; font-size: 11px;">
                                        <i class="fas fa-clock"></i> <?php echo date('H:i', strtotime($payment['created_at'])); ?>
                                    </small>
                                </td>
                                <td class="payment-summary-cell">
                                    <strong><?php echo $currency_symbol; ?><?php echo number_format((float)$payment['total_amount'], 0); ?></strong>
                                    <small>Subtotal: <?php echo $currency_symbol; ?><?php echo number_format((float)$payment['payment_amount'], 0); ?></small>
                                    <?php if ($payment['vat_amount'] > 0): ?>
                                        <br><small>VAT: <?php echo $currency_symbol; ?><?php echo number_format((float)$payment['vat_amount'], 0); ?></small>
                                    <?php endif; ?>
                                    <?php if (!empty($payment['source_reference'])): ?>
                                        <br><small>Booking Ref: <?php echo htmlspecialchars($payment['source_reference']); ?></small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="badge badge-<?php echo $payment['payment_status']; ?>">
                                        <?php echo ucfirst(str_replace('_', ' ', $payment['payment_status'])); ?>
                                    </span>
                                    <?php if (!empty($payment['status']) && $payment['status'] !== $payment['payment_status']): ?>
                                        <br><small><i class="fas fa-info-circle"></i> Record: <?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $payment['status']))); ?></small>
                                    <?php endif; ?>
                                </td>
                                <td class="audit-meta">
                                    <small><i class="fas fa-user-shield"></i> Recorded by: <?php echo htmlspecialchars($payment['recorded_by_name'] ?? 'System'); ?></small>
                                    <br><small><i class="fas fa-clock"></i> Created: <?php echo date('M j, H:i', strtotime($payment['created_at'])); ?></small>
                                    <?php if ($payment['updated_at'] && $payment['updated_at'] != $payment['created_at']): ?>
                                        <br><small><i class="fas fa-edit"></i> Updated: <?php echo date('M j, H:i', strtotime($payment['updated_at'])); ?></small>
                                    <?php endif; ?>
                                    <?php if (!empty($payment['processed_by'])): ?>
                                        <br><small><i class="fas fa-user-check"></i> Processed: <?php echo htmlspecialchars($payment['processed_by']); ?></small>
                                    <?php endif; ?>
                                    <?php if (!empty($payment['notes'])): ?>
                                        <br><small><i class="fas fa-note-sticky"></i> <?php echo htmlspecialchars(strlen(trim((string)$payment['notes'])) > 80 ? substr(trim((string)$payment['notes']), 0, 77) . '...' : trim((string)$payment['notes'])); ?></small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <a href="payment-details.php?id=<?php echo $payment['id']; ?>" class="btn btn-primary btn-sm">
                                        <i class="fas fa-eye"></i> View
                                    </a>
                                    <?php if ($payment['payment_status'] !== 'completed'): ?>
                                        <br><a href="payment-add.php?edit=<?php echo $payment['id']; ?>" class="btn btn-secondary btn-sm" style="margin-top: 8px; display: inline-flex;">
                                            <i class="fas fa-edit"></i> Edit
                                        </a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="8" class="empty-state">
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
