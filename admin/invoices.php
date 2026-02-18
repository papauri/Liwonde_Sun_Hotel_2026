<?php
// Include admin initialization (PHP-only, no HTML output)
require_once 'admin-init.php';

require_once '../includes/alert.php';
$message = '';
$error = '';

// Handle invoice actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    try {
        $action = $_POST['action'];
        $payment_id = (int)($_POST['payment_id'] ?? 0);
        
        if ($action === 'resend_invoice' && $payment_id > 0) {
            // Get payment details
            $stmt = $pdo->prepare("SELECT * FROM payments WHERE id = ?");
            $stmt->execute([$payment_id]);
            $payment = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$payment) {
                throw new Exception('Payment not found. It may have been deleted or does not exist.');
            }
            
            // Resend invoice email based on booking type
            require_once '../config/invoice.php';
            
            if ($payment['booking_type'] === 'room') {
                $result = sendPaymentInvoiceEmail($payment['booking_id']);
            } else {
                $result = sendConferenceInvoiceEmail($payment['booking_id']);
            }
            
            if ($result['success']) {
                $message = 'Invoice resent successfully!';
            } else {
                $error = 'Failed to resend invoice: ' . $result['message'];
            }
        }
        
        if ($action === 'regenerate_invoice' && $payment_id > 0) {
            // Get payment details
            $stmt = $pdo->prepare("SELECT * FROM payments WHERE id = ?");
            $stmt->execute([$payment_id]);
            $payment = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$payment) {
                throw new Exception('Payment not found. It may have been deleted or does not exist.');
            }
            
            // Regenerate invoice based on booking type
            require_once '../config/invoice.php';
            
            if ($payment['booking_type'] === 'room') {
                $result = generateInvoicePDF($payment['booking_id']);
            } else {
                $result = generateConferenceInvoicePDF($payment['booking_id']);
            }
            
            if ($result) {
                // Update payment record with new invoice path
                $update_stmt = $pdo->prepare("
                    UPDATE payments 
                    SET invoice_path = ?, invoice_number = ?, invoice_generated = 1
                    WHERE id = ?
                ");
                $update_stmt->execute([
                    $result['relative_path'],
                    $result['invoice_number'],
                    $payment_id
                ]);
                
                $message = 'Invoice regenerated successfully!';
            } else {
                $error = 'Failed to regenerate invoice';
            }
        }
        
    } catch (Exception $e) {
        $error = 'Error: ' . $e->getMessage();
    }
}

// Get filter parameters
$filter_type = $_GET['filter_type'] ?? 'all';
$filter_status = $_GET['filter_status'] ?? 'all';
$search = $_GET['search'] ?? '';

// Build query
$where_conditions = [];
$params = [];

if ($filter_type !== 'all') {
    $where_conditions[] = "p.booking_type = ?";
    $params[] = $filter_type;
}

if ($filter_status !== 'all') {
    $where_conditions[] = "p.payment_status = ?";
    $params[] = $filter_status;
}

if (!empty($search)) {
    $where_conditions[] = "(p.invoice_number LIKE ? OR p.payment_reference LIKE ? OR p.booking_reference LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

$where_clause = !empty($where_conditions) ? implode(' AND ', $where_conditions) : '';

// Fetch invoices with payment details
try {
    $sql = "
        SELECT p.*,
               CASE
                   WHEN p.booking_type = 'room' THEN CONCAT(b.guest_name, ' (', r.name, ')')
                   WHEN p.booking_type = 'conference' THEN CONCAT(ci.company_name, ' (', cr.name, ')')
                   ELSE 'Unknown'
               END as customer_name,
               CASE
                   WHEN p.booking_type = 'room' THEN b.guest_email
                   WHEN p.booking_type = 'conference' THEN ci.email
                   ELSE NULL
               END as customer_email
        FROM payments p
        LEFT JOIN bookings b ON p.booking_type = 'room' AND p.booking_id = b.id
        LEFT JOIN rooms r ON p.booking_type = 'room' AND b.room_id = r.id
        LEFT JOIN conference_inquiries ci ON p.booking_type = 'conference' AND p.booking_id = ci.id
        LEFT JOIN conference_rooms cr ON p.booking_type = 'conference' AND ci.conference_room_id = cr.id";
    
    if (!empty($where_clause)) {
        $sql .= " WHERE $where_clause";
    }
    
    $sql .= " ORDER BY p.payment_date DESC, p.id DESC";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $invoices = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get statistics
    $stats_stmt = $pdo->query("
        SELECT
            COUNT(*) as total_invoices,
            COUNT(CASE WHEN invoice_generated = 1 THEN 1 END) as invoices_generated,
            SUM(total_amount) as total_revenue
        FROM payments
    ");
    $stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    $error = 'Error fetching invoices: ' . $e->getMessage();
    $invoices = [];
    $stats = ['total_invoices' => 0, 'invoices_generated' => 0, 'total_revenue' => 0];
}

$site_name = getSetting('site_name');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Invoices - Admin Panel</title>
    
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;500;600;700&family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../css/style.css">
    <link rel="stylesheet" href="../css/theme-dynamic.php">
    <link rel="stylesheet" href="css/admin-styles.css">
    <link rel="stylesheet" href="css/admin-components.css">
    
    <style>
        .invoices-container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 20px;
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 32px;
        }
        
        .stat-card h3 {
            font-size: 14px;
            color: #666;
            margin-bottom: 8px;
            font-weight: 500;
        }
        
        .stat-card .number {
            font-size: 32px;
            font-weight: 700;
            color: var(--navy);
        }
        
        .filters-card {
            background: white;
            padding: 24px;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
            margin-bottom: 24px;
        }
        
        .filters-row {
            display: flex;
            gap: 16px;
            flex-wrap: wrap;
            align-items: end;
        }
        
        .filter-group {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }
        
        .filter-group label {
            font-size: 13px;
            font-weight: 600;
            color: var(--navy);
        }
        
        .filter-group select,
        .filter-group input {
            padding: 10px 14px;
            border: 1px solid #d0d7de;
            border-radius: 6px;
            font-size: 14px;
            min-width: 180px;
        }
        
        .btn-filter {
            background: var(--gold);
            color: var(--deep-navy);
            border: none;
            padding: 10px 20px;
            border-radius: 6px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s ease;
        }
        
        .btn-filter:hover {
            background: #c19b2e;
        }
        
        .invoices-table {
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
            overflow: hidden;
        }
        
        .table-responsive {
            overflow-x: auto;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
        }
        
        thead {
            background: #f6f8fa;
        }
        
        th {
            padding: 14px 16px;
            text-align: left;
            font-size: 12px;
            font-weight: 600;
            color: #666;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            border-bottom: 1px solid #d0d7de;
        }
        
        td {
            padding: 14px 16px;
            border-bottom: 1px solid #e8e8e8;
            vertical-align: middle;
        }
        
        tbody tr:hover {
            background: #f8f9fa;
        }
        
        .badge {
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            white-space: nowrap;
        }
        
        .badge-room {
            background: #e3f2fd;
            color: #0d6efd;
        }
        
        .badge-conference {
            background: #f3e5f5;
            color: #9c27b0;
        }
        
        .badge-generated {
            background: #d4edda;
            color: #155724;
        }
        
        .badge-completed {
            background: #d4edda;
            color: #155724;
        }
        
        .badge-failed {
            background: #f8d7da;
            color: #721c24;
        }
        
        .action-buttons {
            display: flex;
            gap: 8px;
        }
        
        .btn-action {
            padding: 6px 12px;
            border: none;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s ease;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        
        .btn-view {
            background: #0d6efd;
            color: white;
        }
        
        .btn-view:hover {
            background: #0b5ed7;
        }
        
        .btn-resend {
            background: #17a2b8;
            color: white;
        }
        
        .btn-resend:hover {
            background: #138496;
        }
        
        .btn-regenerate {
            background: #ffc107;
            color: #212529;
        }
        
        .btn-regenerate:hover {
            background: #e0a800;
        }
        
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: #999;
        }
        
        .empty-state i {
            font-size: 64px;
            margin-bottom: 16px;
            color: #ddd;
        }
        
        .empty-state p {
            font-size: 16px;
            margin: 0;
        }
        
        @media (max-width: 768px) {
            .invoices-container {
                padding: 10px;
            }
            
            .page-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 16px;
            }
            
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .filters-row {
                flex-direction: column;
                align-items: stretch;
            }
            
            .filter-group select,
            .filter-group input {
                min-width: 100%;
            }
        }
    </style>
</head>
<body>

    <?php require_once 'includes/admin-header.php'; ?>
    
    <div class="invoices-container">
        <div class="page-header">
            <h1 class="page-title">
                <i class="fas fa-file-invoice-dollar"></i> Invoice Management
            </h1>
        </div>

        <?php if ($message): ?>
            <?php showAlert($message, 'success'); ?>
        <?php endif; ?>

        <?php if ($error): ?>
            <?php showAlert($error, 'error'); ?>
        <?php endif; ?>

        <!-- Statistics -->
        <div class="stats-grid">
            <div class="stat-card">
                <h3>Total Payments</h3>
                <div class="number"><?php echo number_format($stats['total_invoices'] ?? 0); ?></div>
            </div>
            <div class="stat-card">
                <h3>Invoices Generated</h3>
                <div class="number"><?php echo number_format($stats['invoices_generated'] ?? 0); ?></div>
            </div>
            <div class="stat-card">
                <h3>Total Revenue</h3>
                <div class="number"><?php echo getSetting('currency_symbol', 'K'); ?> <?php echo number_format($stats['total_revenue'] ?? 0, 0); ?></div>
            </div>
        </div>

        <!-- Filters -->
        <div class="filters-card">
            <form method="GET" action="">
                <div class="filters-row">
                    <div class="filter-group">
                        <label>Booking Type</label>
                        <select name="filter_type">
                            <option value="all" <?php echo $filter_type === 'all' ? 'selected' : ''; ?>>All Types</option>
                            <option value="room" <?php echo $filter_type === 'room' ? 'selected' : ''; ?>>Room Bookings</option>
                            <option value="conference" <?php echo $filter_type === 'conference' ? 'selected' : ''; ?>>Conference Bookings</option>
                        </select>
                    </div>
                    <div class="filter-group">
                        <label>Payment Status</label>
                        <select name="filter_status">
                            <option value="all" <?php echo $filter_status === 'all' ? 'selected' : ''; ?>>All Statuses</option>
                            <option value="pending" <?php echo $filter_status === 'pending' ? 'selected' : ''; ?>>Pending</option>
                            <option value="pending" <?php echo $filter_status === 'pending' ? 'selected' : ''; ?>>Pending</option>
                            <option value="completed" <?php echo $filter_status === 'completed' ? 'selected' : ''; ?>>Completed</option>
                            <option value="failed" <?php echo $filter_status === 'failed' ? 'selected' : ''; ?>>Failed</option>
                            <option value="refunded" <?php echo $filter_status === 'refunded' ? 'selected' : ''; ?>>Refunded</option>
                            <option value="partially_refunded" <?php echo $filter_status === 'partially_refunded' ? 'selected' : ''; ?>>Partially Refunded</option>
                        </select>
                    </div>
                    <div class="filter-group">
                        <label>Search</label>
                        <input type="text" name="search" placeholder="Invoice #, Payment #, Booking Ref..." value="<?php echo htmlspecialchars($search); ?>">
                    </div>
                    <button type="submit" class="btn-filter">
                        <i class="fas fa-filter"></i> Apply Filters
                    </button>
                    <a href="invoices.php" class="btn-action" style="background: #6c757d; color: white;">
                        <i class="fas fa-times"></i> Clear
                    </a>
                </div>
            </form>
        </div>

        <!-- Invoices Table -->
        <div class="invoices-table">
            <?php if (!empty($invoices)): ?>
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th>Invoice #</th>
                                <th>Payment #</th>
                                <th>Booking Ref</th>
                                <th>Type</th>
                                <th>Customer</th>
                                <th>Date</th>
                                <th>Amount</th>
                                <th>Status</th>
                                <th>Invoice</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($invoices as $invoice): ?>
                                <tr>
                                    <td>
                                        <strong><?php echo htmlspecialchars($invoice['invoice_number'] ?? 'N/A'); ?></strong>
                                    </td>
                                    <td>
                                        <code><?php echo htmlspecialchars($invoice['payment_reference']); ?></code>
                                    </td>
                                    <td>
                                        <strong><?php echo htmlspecialchars($invoice['booking_reference']); ?></strong>
                                    </td>
                                    <td>
                                        <span class="badge badge-<?php echo $invoice['booking_type']; ?>">
                                            <?php echo ucfirst($invoice['booking_type']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php echo htmlspecialchars($invoice['customer_name'] ?? 'N/A'); ?>
                                        <?php if ($invoice['customer_email']): ?>
                                            <br><small style="color: #666;"><?php echo htmlspecialchars($invoice['customer_email']); ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php echo date('M j, Y', strtotime($invoice['payment_date'])); ?>
                                    </td>
                                    <td>
                                        <strong style="color: var(--gold); font-size: 16px;">
                                            <?php echo getSetting('currency_symbol', 'K'); ?> <?php echo number_format($invoice['total_amount'], 0); ?>
                                        </strong>
                                    </td>
                                    <td>
                                        <span class="badge badge-<?php echo $invoice['payment_status']; ?>">
                                            <?php echo ucfirst(str_replace('_', ' ', $invoice['payment_status'])); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if ($invoice['invoice_generated']): ?>
                                            <span class="badge badge-generated">
                                                <i class="fas fa-check"></i> Generated
                                            </span>
                                        <?php else: ?>
                                            <span class="badge badge-pending">
                                                <i class="fas fa-clock"></i> Pending
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="action-buttons">
                                            <?php if ($invoice['invoice_path'] && file_exists(__DIR__ . '/../' . $invoice['invoice_path'])): ?>
                                                <a href="../<?php echo htmlspecialchars($invoice['invoice_path']); ?>" 
                                                   target="_blank" 
                                                   class="btn-action btn-view"
                                                   title="View Invoice">
                                                    <i class="fas fa-eye"></i> View
                                                </a>
                                            <?php endif; ?>
                                            
                                            <?php if ($invoice['invoice_generated']): ?>
                                                <form method="POST" style="display: inline;">
                                                    <input type="hidden" name="action" value="resend_invoice">
                                                    <input type="hidden" name="payment_id" value="<?php echo $invoice['id']; ?>">
                                                    <button type="submit" class="btn-action btn-resend" onclick="return confirm('Resend invoice email?');">
                                                        <i class="fas fa-paper-plane"></i> Resend
                                                    </button>
                                                </form>
                                            <?php endif; ?>
                                            
                                            <form method="POST" style="display: inline;">
                                                <input type="hidden" name="action" value="regenerate_invoice">
                                                <input type="hidden" name="payment_id" value="<?php echo $invoice['id']; ?>">
                                                <button type="submit" class="btn-action btn-regenerate" onclick="return confirm('Regenerate invoice? This will create a new invoice number.');">
                                                    <i class="fas fa-sync"></i> Regenerate
                                                </button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-file-invoice"></i>
                    <p>No invoices found matching your criteria.</p>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
        // Auto-refresh filters when changed
        document.querySelectorAll('.filter-group select').forEach(select => {
            select.addEventListener('change', function() {
                this.closest('form').submit();
            });
        });
    </script>
    <script src="js/admin-components.js"></script>
    <script src="js/admin-mobile.js"></script>
</body>
</html>
