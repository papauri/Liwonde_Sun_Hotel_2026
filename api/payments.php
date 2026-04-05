<?php
/**
 * Payments API Endpoint
 *
 * Handles payment operations for room bookings and conference inquiries
 *
 * Endpoints:
 * - GET /api/payments - List all payments (with filters)
 * - POST /api/payments - Create a new payment
 * - GET /api/payments/{id} - Get payment details
 * - PUT /api/payments/{id} - Update payment
 * - DELETE /api/payments/{id} - Delete payment (soft delete)
 *
 * Permissions:
 * - payments.view - View payments
 * - payments.create - Create payments
 * - payments.edit - Edit payments
 * - payments.delete - Delete payments
 *
 * SECURITY: This file must only be accessed through api/index.php
 * Direct access is blocked to prevent authentication bypass
 */

// Prevent direct access - must be accessed through api/index.php router
if (!defined('API_ACCESS_ALLOWED') || !isset($auth) || !isset($client)) {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'error' => 'Direct access to this endpoint is not allowed',
        'code' => 403,
        'message' => 'Please use the API router at /api/payments'
    ]);
    exit;
}

// Get request method and path
$method = $_SERVER['REQUEST_METHOD'];
$requestUri = $_SERVER['REQUEST_URI'] ?? '';
$path = parse_url($requestUri, PHP_URL_PATH) ?? '';

// Extract payment ID from path if present
$paymentId = isset($_GET['id']) ? (int)$_GET['id'] : null;

if (preg_match('#/api/(?:index\.php/)?payments(?:/|\.php/)?(\d+)$#i', $path, $matches)) {
    $paymentId = (int)$matches[1];
}

if (!is_int($paymentId) || $paymentId <= 0) {
    $paymentId = null;
}

try {
    switch ($method) {
        case 'GET':
            if ($paymentId) {
                if (!$auth->checkPermission($client, 'payments.view')) {
                    ApiResponse::error('Permission denied: payments.view', 403);
                }
                getPayment($pdo, $paymentId);
            } else {
                if (!$auth->checkPermission($client, 'payments.view')) {
                    ApiResponse::error('Permission denied: payments.view', 403);
                }
                listPayments($pdo);
            }
            break;

        case 'POST':
            if (!$auth->checkPermission($client, 'payments.create')) {
                ApiResponse::error('Permission denied: payments.create', 403);
            }
            createPayment($pdo);
            break;

        case 'PUT':
            if (!$paymentId) {
                ApiResponse::error('Payment ID is required for update', 400);
            }
            if (!$auth->checkPermission($client, 'payments.edit')) {
                ApiResponse::error('Permission denied: payments.edit', 403);
            }
            updatePayment($pdo, $paymentId);
            break;

        case 'DELETE':
            if (!$paymentId) {
                ApiResponse::error('Payment ID is required for deletion', 400);
            }
            if (!$auth->checkPermission($client, 'payments.delete')) {
                ApiResponse::error('Permission denied: payments.delete', 403);
            }
            deletePayment($pdo, $paymentId);
            break;

        default:
            ApiResponse::error('Method not allowed', 405);
    }
} catch (PDOException $e) {
    error_log("Payments API Database Error: " . $e->getMessage());
    ApiResponse::error('Database error occurred', 500);
} catch (Exception $e) {
    error_log("Payments API Error: " . $e->getMessage());
    ApiResponse::error('Failed to process request: ' . $e->getMessage(), 500);
}

function getPaymentTableColumns(PDO $pdo) {
    static $columns = null;

    if ($columns !== null) {
        return $columns;
    }

    $columns = [];

    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM payments");
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $column) {
            $columns[$column['Field']] = true;
        }
    } catch (Throwable $e) {
        error_log('Unable to inspect payments columns: ' . $e->getMessage());
    }

    return $columns;
}

function normalizeApiPaymentStatus($status) {
    $status = strtolower(trim((string)$status));
    $map = ['paid' => 'completed'];

    if (isset($map[$status])) {
        $status = $map[$status];
    }

    $allowed = ['pending', 'completed', 'failed', 'refunded', 'partially_refunded', 'cancelled'];
    return in_array($status, $allowed, true) ? $status : 'pending';
}

function normalizeLegacyPaymentStatus($status) {
    switch ($status) {
        case 'completed':
            return 'completed';
        case 'refunded':
        case 'partially_refunded':
            return 'refunded';
        case 'failed':
        case 'cancelled':
            return 'failed';
        default:
            return 'pending';
    }
}

function inferApiPaymentType($paymentStatus, $paymentAmount, $subtotalDueBeforePayment, $subtotalPaidBeforePayment, $allowManualPayment) {
    if ($paymentStatus === 'refunded' || $paymentStatus === 'partially_refunded' || $paymentAmount < 0) {
        return 'refund';
    }

    if ($paymentStatus !== 'completed') {
        return $subtotalPaidBeforePayment <= 0.01 ? 'deposit' : 'partial_payment';
    }

    if ($allowManualPayment && $paymentAmount > ($subtotalDueBeforePayment + 0.01)) {
        return 'adjustment';
    }

    if ($paymentAmount >= max(0, $subtotalDueBeforePayment - 0.01)) {
        return 'full_payment';
    }

    return $subtotalPaidBeforePayment <= 0.01 ? 'deposit' : 'partial_payment';
}

function generateUniquePaymentReference(PDO $pdo) {
    do {
        $paymentReference = 'PAY' . date('Ym') . strtoupper(substr(uniqid(), -6));
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM payments WHERE payment_reference = ?");
        $stmt->execute([$paymentReference]);
    } while ((int)$stmt->fetchColumn() > 0);

    return $paymentReference;
}

function generateUniqueReceiptNumber(PDO $pdo) {
    do {
        $receiptNumber = 'RCP' . date('Y') . str_pad((string)rand(1, 99999), 5, '0', STR_PAD_LEFT);
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM payments WHERE receipt_number = ?");
        $stmt->execute([$receiptNumber]);
    } while ((int)$stmt->fetchColumn() > 0);

    return $receiptNumber;
}

function getRowPaymentStatus(array $payment) {
    $status = trim((string)($payment['payment_status'] ?? ''));
    if ($status === '') {
        $status = trim((string)($payment['status'] ?? 'pending'));
    }
    return normalizeApiPaymentStatus($status);
}

function getRowTransactionReference(array $payment) {
    $candidates = [
        $payment['transaction_reference'] ?? null,
        $payment['payment_reference_number'] ?? null,
        $payment['transaction_id'] ?? null
    ];

    foreach ($candidates as $candidate) {
        $candidate = trim((string)$candidate);
        if ($candidate !== '') {
            return $candidate;
        }
    }

    return null;
}

function fetchBookingDetailsForPayment(PDO $pdo, $bookingType, $bookingId) {
    if ($bookingType === 'room') {
        $stmt = $pdo->prepare("
            SELECT 
                b.id,
                b.booking_reference,
                b.guest_name,
                b.guest_email,
                b.total_amount,
                b.total_with_vat,
                b.amount_paid,
                b.amount_due,
                b.vat_rate,
                COALESCE((
                    SELECT SUM(COALESCE(p.payment_amount, 0))
                    FROM payments p
                    WHERE p.booking_type = 'room'
                    AND p.booking_id = b.id
                    AND p.payment_status = 'completed'
                    AND p.deleted_at IS NULL
                ), 0) as subtotal_paid,
                GREATEST(0, COALESCE(b.total_amount, 0) - COALESCE((
                    SELECT SUM(COALESCE(p.payment_amount, 0))
                    FROM payments p
                    WHERE p.booking_type = 'room'
                    AND p.booking_id = b.id
                    AND p.payment_status = 'completed'
                    AND p.deleted_at IS NULL
                ), 0)) as subtotal_due
            FROM bookings b
            WHERE b.id = ?
        ");
        $stmt->execute([$bookingId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    if ($bookingType === 'conference') {
        $stmt = $pdo->prepare("
            SELECT 
                ci.id,
                ci.inquiry_reference as enquiry_reference,
                ci.company_name as organization_name,
                ci.contact_person as contact_name,
                ci.email as contact_email,
                ci.total_amount,
                ci.total_with_vat,
                ci.amount_paid,
                ci.amount_due,
                ci.vat_rate,
                ci.deposit_required,
                ci.deposit_paid,
                COALESCE((
                    SELECT SUM(COALESCE(p.payment_amount, 0))
                    FROM payments p
                    WHERE p.booking_type = 'conference'
                    AND p.booking_id = ci.id
                    AND p.payment_status = 'completed'
                    AND p.deleted_at IS NULL
                ), 0) as subtotal_paid,
                GREATEST(0, COALESCE(ci.total_amount, 0) - COALESCE((
                    SELECT SUM(COALESCE(p.payment_amount, 0))
                    FROM payments p
                    WHERE p.booking_type = 'conference'
                    AND p.booking_id = ci.id
                    AND p.payment_status = 'completed'
                    AND p.deleted_at IS NULL
                ), 0)) as subtotal_due
            FROM conference_inquiries ci
            WHERE ci.id = ?
        ");
        $stmt->execute([$bookingId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    return null;
}

function resolveBookingForPayment(PDO $pdo, $bookingType, $bookingId, $bookingLookup = '') {
    $bookingId = (int)$bookingId;
    if ($bookingId > 0) {
        return fetchBookingDetailsForPayment($pdo, $bookingType, $bookingId);
    }

    $bookingLookup = trim((string)$bookingLookup);
    if ($bookingLookup === '') {
        return null;
    }

    if ($bookingType === 'room') {
        if (ctype_digit($bookingLookup)) {
            return fetchBookingDetailsForPayment($pdo, 'room', (int)$bookingLookup);
        }

        $stmt = $pdo->prepare("SELECT id FROM bookings WHERE UPPER(booking_reference) = UPPER(?) LIMIT 1");
        $stmt->execute([$bookingLookup]);
        $resolvedId = (int)$stmt->fetchColumn();
        return $resolvedId > 0 ? fetchBookingDetailsForPayment($pdo, 'room', $resolvedId) : null;
    }

    if ($bookingType === 'conference') {
        if (ctype_digit($bookingLookup)) {
            return fetchBookingDetailsForPayment($pdo, 'conference', (int)$bookingLookup);
        }

        $stmt = $pdo->prepare("SELECT id FROM conference_inquiries WHERE UPPER(inquiry_reference) = UPPER(?) LIMIT 1");
        $stmt->execute([$bookingLookup]);
        $resolvedId = (int)$stmt->fetchColumn();
        return $resolvedId > 0 ? fetchBookingDetailsForPayment($pdo, 'conference', $resolvedId) : null;
    }

    return null;
}

function parseApiRoomFolioCharges(PDO $pdo, array $input) {
    $rows = $input['folio_charges'] ?? [];
    if (!is_array($rows) || empty($rows)) {
        return [];
    }

    $charges = [];
    $foodStmt = $pdo->prepare("SELECT id, category, item_name, price, is_available FROM food_menu WHERE id = ? LIMIT 1");
    $drinkStmt = $pdo->prepare("SELECT id, category, item_name, price, is_available FROM drink_menu WHERE id = ? LIMIT 1");

    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }

        $kind = strtolower(trim((string)($row['kind'] ?? '')));
        if ($kind === '') {
            continue;
        }

        $quantity = max(1, (int)($row['quantity'] ?? 1));

        if ($kind === 'food' || $kind === 'drink') {
            $itemId = (int)($row['item_id'] ?? 0);
            if ($itemId <= 0) {
                throw new Exception('Select a valid menu item for each food or drink folio charge.');
            }

            $stmt = $kind === 'food' ? $foodStmt : $drinkStmt;
            $stmt->execute([$itemId]);
            $item = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$item || (int)$item['is_available'] !== 1) {
                throw new Exception('One of the selected menu items is no longer available.');
            }

            $charges[] = [
                'charge_type' => 'menu',
                'description' => ucfirst($kind) . ': ' . $item['item_name'] . ' x' . $quantity . ' (' . $item['category'] . ')',
                'amount' => round((float)$item['price'] * $quantity, 2)
            ];
            continue;
        }

        if ($kind === 'custom') {
            $description = trim((string)($row['description'] ?? ''));
            $unitPrice = round((float)($row['unit_price'] ?? 0), 2);
            if ($description === '') {
                throw new Exception('Custom folio charges need a description.');
            }
            if ($unitPrice <= 0) {
                throw new Exception('Custom folio charges must have a unit price greater than zero.');
            }

            $charges[] = [
                'charge_type' => 'other',
                'description' => $description . ' x' . $quantity,
                'amount' => round($unitPrice * $quantity, 2)
            ];
        }
    }

    return $charges;
}

function insertRoomFolioCharges(PDO $pdo, $bookingId, array $charges) {
    if (empty($charges)) {
        return 0.0;
    }

    ensureBookingAdditionalChargesTable();

    $stmt = $pdo->prepare("INSERT INTO booking_additional_charges (booking_id, charge_type, description, amount, created_by) VALUES (?, ?, ?, ?, ?)");
    $total = 0.0;
    foreach ($charges as $charge) {
        $amount = round((float)$charge['amount'], 2);
        $stmt->execute([(int)$bookingId, $charge['charge_type'], $charge['description'], $amount, null]);
        $total += $amount;
    }

    return round($total, 2);
}

function buildPaymentWriteData(array $paymentColumns, array $payload) {
    $paymentStatus = $payload['payment_status'];
    $legacyStatus = normalizeLegacyPaymentStatus($paymentStatus);
    $hasDetectedColumns = !empty($paymentColumns);

    $baseData = [
        'payment_reference' => $payload['payment_reference'],
        'booking_type' => $payload['booking_type'],
        'booking_id' => $payload['booking_id'],
        'booking_reference' => $payload['booking_reference'],
        'payment_date' => $payload['payment_date'],
        'payment_amount' => $payload['payment_amount'],
        'vat_rate' => $payload['vat_rate'],
        'vat_amount' => $payload['vat_amount'],
        'total_amount' => $payload['total_amount'],
        'payment_method' => $payload['payment_method'],
        'payment_status' => $paymentStatus,
        'notes' => $payload['notes'],
        'cc_emails' => $payload['cc_emails'],
        'receipt_number' => $payload['receipt_number'],
        'processed_by' => $payload['processed_by']
    ];

    $data = [];
    foreach ($baseData as $field => $value) {
        if (!$hasDetectedColumns || isset($paymentColumns[$field])) {
            $data[$field] = $value;
        }
    }

    if (!empty($payload['payment_type'])) {
        if (!$hasDetectedColumns || isset($paymentColumns['payment_type'])) {
            $data['payment_type'] = $payload['payment_type'];
        }
    }

    if (isset($paymentColumns['conference_id'])) {
        $data['conference_id'] = $payload['booking_type'] === 'conference' ? $payload['booking_id'] : null;
    }

    if (!$hasDetectedColumns || isset($paymentColumns['invoice_generated'])) {
        $data['invoice_generated'] = $paymentStatus === 'completed' ? 1 : 0;
    }

    if (!$hasDetectedColumns || isset($paymentColumns['amount'])) {
        $data['amount'] = $payload['payment_amount'];
    }

    if (!$hasDetectedColumns || isset($paymentColumns['status'])) {
        $data['status'] = $legacyStatus;
    }

    if (array_key_exists('transaction_reference', $payload)) {
        $transactionReference = $payload['transaction_reference'];
        if (isset($paymentColumns['transaction_reference'])) {
            $data['transaction_reference'] = $transactionReference;
        }
        if (isset($paymentColumns['payment_reference_number'])) {
            $data['payment_reference_number'] = $transactionReference;
        }
        if (isset($paymentColumns['transaction_id'])) {
            $data['transaction_id'] = $transactionReference;
        }
    }

    return $data;
}

function insertPaymentRecord(PDO $pdo, array $paymentColumns, array $payload) {
    $data = buildPaymentWriteData($paymentColumns, $payload);
    $fields = array_keys($data);
    $placeholders = implode(', ', array_fill(0, count($fields), '?'));
    $sql = "INSERT INTO payments (" . implode(', ', $fields) . ") VALUES (" . $placeholders . ")";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(array_values($data));

    return (int)$pdo->lastInsertId();
}

function updatePaymentRecord(PDO $pdo, array $paymentColumns, $paymentId, array $payload) {
    $data = buildPaymentWriteData($paymentColumns, $payload);
    unset($data['payment_reference'], $data['booking_type'], $data['booking_id'], $data['booking_reference'], $data['conference_id']);

    $assignments = [];
    foreach (array_keys($data) as $field) {
        $assignments[] = $field . ' = ?';
    }

    $sql = "UPDATE payments SET " . implode(', ', $assignments) . ", updated_at = NOW() WHERE id = ?";
    $stmt = $pdo->prepare($sql);
    $params = array_values($data);
    $params[] = $paymentId;
    $stmt->execute($params);
}

/**
 * List all payments with optional filters
 */
function listPayments($pdo) {
    // Get query parameters for filtering
    $bookingType = isset($_GET['booking_type']) ? trim($_GET['booking_type']) : null;
    $bookingId = isset($_GET['booking_id']) ? (int)$_GET['booking_id'] : null;
    $status = isset($_GET['status']) ? trim($_GET['status']) : null;
    $paymentMethod = isset($_GET['payment_method']) ? trim($_GET['payment_method']) : null;
    $startDate = isset($_GET['start_date']) ? trim($_GET['start_date']) : null;
    $endDate = isset($_GET['end_date']) ? trim($_GET['end_date']) : null;
    $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
    $limit = isset($_GET['limit']) ? min(100, max(1, (int)$_GET['limit'])) : 50;
    $offset = ($page - 1) * $limit;
    
    $fromSql = "
        FROM payments p
        LEFT JOIN bookings b ON p.booking_type = 'room' AND p.booking_id = b.id
        LEFT JOIN conference_inquiries ci ON p.booking_type = 'conference' AND p.booking_id = ci.id
        WHERE p.deleted_at IS NULL
    ";

    $params = [];

    if ($bookingType) {
        $fromSql .= " AND p.booking_type = ?";
        $params[] = $bookingType;
    }

    if ($bookingId) {
        $fromSql .= " AND p.booking_id = ?";
        $params[] = $bookingId;
    }

    if ($status) {
        $fromSql .= " AND COALESCE(NULLIF(p.payment_status, ''), p.status, 'pending') = ?";
        $params[] = normalizeApiPaymentStatus($status);
    }

    if ($paymentMethod) {
        $fromSql .= " AND p.payment_method = ?";
        $params[] = $paymentMethod;
    }

    if ($startDate) {
        $fromSql .= " AND p.payment_date >= ?";
        $params[] = $startDate;
    }

    if ($endDate) {
        $fromSql .= " AND p.payment_date <= ?";
        $params[] = $endDate;
    }

    // Build query
    $sql = "
        SELECT 
            p.*,
            CASE 
                WHEN p.booking_type = 'room' THEN CONCAT('Room Booking - ', b.guest_name)
                WHEN p.booking_type = 'conference' THEN CONCAT('Conference - ', ci.company_name)
                ELSE p.booking_type
            END as booking_description,
            CASE 
                WHEN p.booking_type = 'room' THEN b.booking_reference
                WHEN p.booking_type = 'conference' THEN ci.inquiry_reference
                ELSE NULL
            END as booking_reference,
            CASE 
                WHEN p.booking_type = 'room' THEN b.guest_email
                WHEN p.booking_type = 'conference' THEN ci.email
                ELSE NULL
            END as contact_email
    " . $fromSql;
    
    // Get total count
    $countSql = "SELECT COUNT(*) as total " . $fromSql;
    $countStmt = $pdo->prepare($countSql);
    $countStmt->execute($params);
    $total = $countStmt->fetch(PDO::FETCH_ASSOC)['total'];
    
    // Add ordering and pagination
    $sql .= " ORDER BY p.payment_date DESC, p.created_at DESC";
    $sql .= " LIMIT ? OFFSET ?";
    $params[] = $limit;
    $params[] = $offset;
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $payments = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Calculate summary statistics
    $summarySql = "
        SELECT 
            COUNT(*) as total_payments,
            SUM(CASE WHEN COALESCE(NULLIF(payment_status, ''), status, 'pending') = 'completed' THEN total_amount ELSE 0 END) as total_collected,
            SUM(CASE WHEN COALESCE(NULLIF(payment_status, ''), status, 'pending') = 'pending' THEN total_amount ELSE 0 END) as total_pending,
            SUM(CASE WHEN COALESCE(NULLIF(payment_status, ''), status, 'pending') IN ('refunded', 'partially_refunded') THEN total_amount ELSE 0 END) as total_refunded,
            SUM(vat_amount) as total_vat_collected
        FROM payments
        WHERE deleted_at IS NULL
    ";
    
    $summaryParams = [];
    if ($bookingType) {
        $summarySql .= " AND booking_type = ?";
        $summaryParams[] = $bookingType;
    }
    if ($bookingId) {
        $summarySql .= " AND booking_id = ?";
        $summaryParams[] = $bookingId;
    }
    if ($startDate) {
        $summarySql .= " AND payment_date >= ?";
        $summaryParams[] = $startDate;
    }
    if ($endDate) {
        $summarySql .= " AND payment_date <= ?";
        $summaryParams[] = $endDate;
    }
    
    $summaryStmt = $pdo->prepare($summarySql);
    $summaryStmt->execute($summaryParams);
    $summary = $summaryStmt->fetch(PDO::FETCH_ASSOC);
    
    $response = [
        'payments' => array_map(function($payment) {
            $resolvedStatus = getRowPaymentStatus($payment);
            return [
                'id' => (int)$payment['id'],
                'payment_reference' => $payment['payment_reference'],
                'booking_type' => $payment['booking_type'],
                'booking_id' => (int)$payment['booking_id'],
                'booking_description' => $payment['booking_description'],
                'booking_reference' => $payment['booking_reference'],
                'contact_email' => $payment['contact_email'],
                'payment_date' => $payment['payment_date'],
                'amount' => [
                    'subtotal' => (float)$payment['payment_amount'],
                    'vat_rate' => (float)$payment['vat_rate'],
                    'vat_amount' => (float)$payment['vat_amount'],
                    'total' => (float)$payment['total_amount']
                ],
                'payment_method' => $payment['payment_method'],
                'status' => $resolvedStatus,
                'transaction_reference' => getRowTransactionReference($payment),
                'notes' => $payment['notes'],
                'created_at' => $payment['created_at'],
                'updated_at' => $payment['updated_at']
            ];
        }, $payments),
        'pagination' => [
            'page' => $page,
            'limit' => $limit,
            'total' => (int)$total,
            'pages' => ceil($total / $limit)
        ],
        'summary' => [
            'total_payments' => (int)$summary['total_payments'],
            'total_collected' => (float)$summary['total_collected'],
            'total_pending' => (float)$summary['total_pending'],
            'total_refunded' => (float)$summary['total_refunded'],
            'total_vat_collected' => (float)$summary['total_vat_collected'],
            'currency' => getSetting('currency_symbol')
        ]
    ];
    
    ApiResponse::success($response, 'Payments retrieved successfully');
}

/**
 * Get single payment details
 */
function getPayment($pdo, $paymentId) {
    $stmt = $pdo->prepare("\n        SELECT \n            p.*,\n            CASE \n                WHEN p.booking_type = 'room' THEN CONCAT('Room Booking - ', b.guest_name)\n                WHEN p.booking_type = 'conference' THEN CONCAT('Conference - ', ci.company_name)\n                ELSE p.booking_type\n            END as booking_description,\n            CASE \n                WHEN p.booking_type = 'room' THEN b.booking_reference\n                WHEN p.booking_type = 'conference' THEN ci.inquiry_reference\n                ELSE NULL\n            END as booking_reference\n        FROM payments p\n        LEFT JOIN bookings b ON p.booking_type = 'room' AND p.booking_id = b.id\n        LEFT JOIN conference_inquiries ci ON p.booking_type = 'conference' AND p.booking_id = ci.id\n        WHERE p.id = ? AND p.deleted_at IS NULL\n    ");
    $stmt->execute([$paymentId]);
    $payment = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$payment) {
        ApiResponse::error('Payment not found', 404);
    }

    $bookingDetails = null;

    if ($payment['booking_type'] === 'room') {
        $bookingStmt = $pdo->prepare("\n            SELECT b.*, r.name as room_name\n            FROM bookings b\n            LEFT JOIN rooms r ON b.room_id = r.id\n            WHERE b.id = ?\n        ");
        $bookingStmt->execute([$payment['booking_id']]);
        $booking = $bookingStmt->fetch(PDO::FETCH_ASSOC);

        if ($booking) {
            $bookingDetails = [
                'type' => 'room',
                'id' => (int)$booking['id'],
                'reference' => $booking['booking_reference'],
                'guest' => [
                    'name' => $booking['guest_name'],
                    'email' => $booking['guest_email'],
                    'phone' => $booking['guest_phone']
                ],
                'room' => [
                    'type' => $booking['room_name'] ?? ($booking['occupancy_type'] ?? null),
                    'check_in' => $booking['check_in_date'],
                    'check_out' => $booking['check_out_date'],
                    'nights' => (int)$booking['number_of_nights']
                ],
                'amounts' => [
                    'total_amount' => (float)$booking['total_amount'],
                    'amount_paid' => (float)$booking['amount_paid'],
                    'amount_due' => (float)$booking['amount_due'],
                    'vat_rate' => (float)$booking['vat_rate'],
                    'vat_amount' => (float)$booking['vat_amount'],
                    'total_with_vat' => (float)$booking['total_with_vat']
                ],
                'status' => $booking['status']
            ];
        }
    } elseif ($payment['booking_type'] === 'conference') {
        $confStmt = $pdo->prepare("\n            SELECT * FROM conference_inquiries WHERE id = ?\n        ");
        $confStmt->execute([$payment['booking_id']]);
        $enquiry = $confStmt->fetch(PDO::FETCH_ASSOC);

        if ($enquiry) {
            $bookingDetails = [
                'type' => 'conference',
                'id' => (int)$enquiry['id'],
                'reference' => $enquiry['inquiry_reference'],
                'organization' => [
                    'name' => $enquiry['company_name'],
                    'contact_person' => $enquiry['contact_person'],
                    'email' => $enquiry['email'],
                    'phone' => $enquiry['phone']
                ],
                'event' => [
                    'type' => $enquiry['event_type'],
                    'start_date' => $enquiry['event_date'],
                    'end_date' => $enquiry['event_date'],
                    'expected_attendees' => (int)$enquiry['number_of_attendees']
                ],
                'amounts' => [
                    'total_amount' => (float)$enquiry['total_amount'],
                    'amount_paid' => (float)$enquiry['amount_paid'],
                    'amount_due' => (float)$enquiry['amount_due'],
                    'vat_rate' => (float)$enquiry['vat_rate'],
                    'vat_amount' => (float)$enquiry['vat_amount'],
                    'total_with_vat' => (float)$enquiry['total_with_vat'],
                    'deposit_required' => (float)$enquiry['deposit_required'],
                    'deposit_amount' => (float)$enquiry['deposit_amount'],
                    'deposit_paid' => (float)$enquiry['deposit_paid']
                ],
                'status' => $enquiry['status']
            ];
        }
    }

    $resolvedStatus = getRowPaymentStatus($payment);

    $response = [
        'payment' => [
            'id' => (int)$payment['id'],
            'payment_reference' => $payment['payment_reference'],
            'booking_type' => $payment['booking_type'],
            'booking_id' => (int)$payment['booking_id'],
            'booking_details' => $bookingDetails,
            'payment_date' => $payment['payment_date'],
            'amount' => [
                'subtotal' => (float)$payment['payment_amount'],
                'vat_rate' => (float)$payment['vat_rate'],
                'vat_amount' => (float)$payment['vat_amount'],
                'total' => (float)$payment['total_amount']
            ],
            'payment_method' => $payment['payment_method'],
            'status' => $resolvedStatus,
            'transaction_reference' => getRowTransactionReference($payment),
            'receipt_number' => $payment['receipt_number'],
            'processed_by' => $payment['processed_by'],
            'notes' => $payment['notes'],
            'created_at' => $payment['created_at'],
            'updated_at' => $payment['updated_at']
        ]
    ];

    ApiResponse::success($response, 'Payment details retrieved successfully');
}

/**
 * Create a new payment
 */
function createPayment($pdo) {
    // Get request body
    $rawInput = file_get_contents('php://input');
    $input = json_decode($rawInput, true);
    
    if (!$input) {
        ApiResponse::error('Invalid JSON request body', 400);
    }
    
    // Validate required fields
    $requiredFields = [
        'booking_type', 'payment_amount',
        'payment_method', 'payment_status'
    ];
    
    $missingFields = [];
    foreach ($requiredFields as $field) {
        if (!isset($input[$field]) || $input[$field] === '') {
            $missingFields[$field] = ucfirst(str_replace('_', ' ', $field)) . ' is required';
        }
    }
    
    if (!empty($missingFields)) {
        ApiResponse::validationError($missingFields);
    }
    
    // Validate booking type
    if (!in_array($input['booking_type'], ['room', 'conference'], true)) {
        ApiResponse::validationError(['booking_type' => 'Must be either "room" or "conference"']);
    }
    
    // Validate payment method
    $validMethods = ['cash', 'bank_transfer', 'credit_card', 'debit_card', 'mobile_money', 'cheque', 'other'];
    if (!in_array($input['payment_method'], $validMethods, true)) {
        ApiResponse::validationError(['payment_method' => 'Invalid payment method']);
    }

    $paymentStatus = normalizeApiPaymentStatus($input['payment_status']);
    $paymentAmount = round((float)$input['payment_amount'], 2);
    if ($paymentAmount <= 0) {
        ApiResponse::validationError(['payment_amount' => 'Payment amount must be greater than zero']);
    }

    $bookingId = (int)($input['booking_id'] ?? 0);
    $bookingLookup = trim((string)($input['booking_lookup'] ?? ''));
    if ($bookingId <= 0 && $bookingLookup === '') {
        ApiResponse::validationError(['booking_id' => 'Provide booking_id or booking_lookup']);
    }

    $allowManualPayment = !empty($input['allow_manual_payment']);
    $bookingDetails = resolveBookingForPayment($pdo, $input['booking_type'], $bookingId, $bookingLookup);

    if (!$bookingDetails) {
        ApiResponse::error($input['booking_type'] === 'room' ? 'Room booking not found' : 'Conference enquiry not found', 404);
    }

    $bookingId = (int)$bookingDetails['id'];

    if ($input['booking_type'] === 'room' && isset($input['folio_charges'])) {
        ensureBookingAdditionalChargesTable();
    }
    
    // Start transaction
    $pdo->beginTransaction();
    
    try {
        $folioCharges = [];
        if ($input['booking_type'] === 'room' && isset($input['folio_charges'])) {
            $folioCharges = parseApiRoomFolioCharges($pdo, $input);
        }

        if (!empty($folioCharges)) {
            insertRoomFolioCharges($pdo, $bookingId, $folioCharges);
            recalculateRoomBookingFinancials($bookingId);
            $bookingDetails = fetchBookingDetailsForPayment($pdo, $input['booking_type'], $bookingId);
        }

        $subtotalDueBeforePayment = (float)($bookingDetails['subtotal_due'] ?? 0);
        $subtotalPaidBeforePayment = (float)($bookingDetails['subtotal_paid'] ?? 0);

        if ($paymentStatus === 'completed' && !$allowManualPayment && $paymentAmount > ($subtotalDueBeforePayment + 0.01)) {
            throw new Exception('This payment exceeds the booking subtotal due. Set allow_manual_payment=true for intentional credits or adjustments.');
        }

        $vatEnabled = getSetting('vat_enabled') === '1';
        $defaultVatRate = $vatEnabled ? (float)getSetting('vat_rate') : 0;
        $paymentVatRate = isset($input['vat_rate'])
            ? (float)$input['vat_rate']
            : (isset($bookingDetails['vat_rate']) ? (float)$bookingDetails['vat_rate'] : $defaultVatRate);
        $paymentVatAmount = round($paymentAmount * ($paymentVatRate / 100), 2);
        $totalAmount = round($paymentAmount + $paymentVatAmount, 2);
        $paymentRef = generateUniquePaymentReference($pdo);
        $receiptNumber = $paymentStatus === 'completed' ? generateUniqueReceiptNumber($pdo) : null;
        $paymentColumns = getPaymentTableColumns($pdo);
        $bookingReference = $input['booking_type'] === 'room' ? $bookingDetails['booking_reference'] : $bookingDetails['enquiry_reference'];
        $transactionReference = isset($input['transaction_reference']) ? trim((string)$input['transaction_reference']) : null;

        $paymentId = insertPaymentRecord($pdo, $paymentColumns, [
            'payment_reference' => $paymentRef,
            'booking_type' => $input['booking_type'],
            'booking_id' => $bookingId,
            'booking_reference' => $bookingReference,
            'payment_date' => isset($input['payment_date']) ? $input['payment_date'] : date('Y-m-d'),
            'payment_amount' => $paymentAmount,
            'vat_rate' => $paymentVatRate,
            'vat_amount' => $paymentVatAmount,
            'total_amount' => $totalAmount,
            'payment_method' => $input['payment_method'],
            'payment_type' => inferApiPaymentType($paymentStatus, $paymentAmount, $subtotalDueBeforePayment, $subtotalPaidBeforePayment, $allowManualPayment),
            'payment_status' => $paymentStatus,
            'transaction_reference' => $transactionReference !== '' ? $transactionReference : null,
            'receipt_number' => $receiptNumber,
            'cc_emails' => isset($input['cc_emails']) ? trim((string)$input['cc_emails']) : null,
            'processed_by' => isset($input['processed_by']) ? trim((string)$input['processed_by']) : null,
            'notes' => isset($input['notes']) ? trim((string)$input['notes']) : null
        ]);
        
        // Update booking payment totals
        if ($input['booking_type'] === 'room') {
            updateRoomBookingPayments($pdo, $bookingId);
        } else {
            updateConferenceEnquiryPayments($pdo, $bookingId);
        }
        
        $pdo->commit();
        
        // Fetch created payment
        $fetchStmt = $pdo->prepare("SELECT * FROM payments WHERE id = ?");
        $fetchStmt->execute([$paymentId]);
        $payment = $fetchStmt->fetch(PDO::FETCH_ASSOC);
        
        $response = [
            'payment' => [
                'id' => (int)$payment['id'],
                'payment_reference' => $payment['payment_reference'],
                'receipt_number' => $payment['receipt_number'],
                'booking_type' => $payment['booking_type'],
                'booking_id' => (int)$payment['booking_id'],
                'amount' => [
                    'subtotal' => (float)$payment['payment_amount'],
                    'vat_rate' => (float)$payment['vat_rate'],
                    'vat_amount' => (float)$payment['vat_amount'],
                    'total' => (float)$payment['total_amount']
                ],
                'payment_method' => $payment['payment_method'],
                'status' => getRowPaymentStatus($payment),
                'transaction_reference' => getRowTransactionReference($payment),
                'created_at' => $payment['created_at']
            ]
        ];
        
        ApiResponse::success($response, 'Payment created successfully', 201);
        
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/**
 * Update an existing payment
 */
function updatePayment($pdo, $paymentId) {
    // Check if payment exists
    $checkStmt = $pdo->prepare("SELECT * FROM payments WHERE id = ? AND deleted_at IS NULL");
    $checkStmt->execute([$paymentId]);
    $existingPayment = $checkStmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$existingPayment) {
        ApiResponse::error('Payment not found. It may have been deleted or does not exist.', 404);
    }
    
    // Get request body
    $rawInput = file_get_contents('php://input');
    $input = json_decode($rawInput, true);
    
    if (!$input) {
        ApiResponse::error('Invalid JSON request body', 400);
    }
    
    $allowedFields = [
        'payment_date', 'payment_amount', 'vat_rate', 'payment_method',
        'payment_status', 'transaction_reference', 'notes', 'processed_by', 'allow_manual_payment'
    ];

    $hasAnySupportedField = false;
    foreach ($allowedFields as $field) {
        if (array_key_exists($field, $input)) {
            $hasAnySupportedField = true;
            break;
        }
    }

    if (!$hasAnySupportedField) {
        ApiResponse::error('No valid fields to update', 400);
    }

    $paymentAmount = array_key_exists('payment_amount', $input)
        ? round((float)$input['payment_amount'], 2)
        : round((float)$existingPayment['payment_amount'], 2);
    if ($paymentAmount <= 0) {
        ApiResponse::validationError(['payment_amount' => 'Payment amount must be greater than zero']);
    }

    $existingStatus = getRowPaymentStatus($existingPayment);
    $paymentStatus = array_key_exists('payment_status', $input)
        ? normalizeApiPaymentStatus($input['payment_status'])
        : $existingStatus;
    $allowManualPayment = !empty($input['allow_manual_payment']);

    $bookingDetails = fetchBookingDetailsForPayment($pdo, $existingPayment['booking_type'], (int)$existingPayment['booking_id']);
    if (!$bookingDetails) {
        ApiResponse::error('Associated booking could not be found for this payment.', 404);
    }

    $subtotalDueBeforePayment = (float)($bookingDetails['subtotal_due'] ?? 0);
    $subtotalPaidBeforePayment = (float)($bookingDetails['subtotal_paid'] ?? 0);

    if ($existingStatus === 'completed') {
        $subtotalDueBeforePayment += (float)$existingPayment['payment_amount'];
        $subtotalPaidBeforePayment = max(0, $subtotalPaidBeforePayment - (float)$existingPayment['payment_amount']);
    }

    if ($paymentStatus === 'completed' && !$allowManualPayment && $paymentAmount > ($subtotalDueBeforePayment + 0.01)) {
        ApiResponse::error('This payment exceeds the booking subtotal due. Set allow_manual_payment=true for intentional credits or adjustments.', 400);
    }

    $vatEnabled = getSetting('vat_enabled') === '1';
    $defaultVatRate = $vatEnabled ? (float)getSetting('vat_rate') : 0;
    $existingVatRate = isset($existingPayment['vat_rate']) ? (float)$existingPayment['vat_rate'] : $defaultVatRate;
    $paymentVatRate = array_key_exists('vat_rate', $input)
        ? (float)$input['vat_rate']
        : (isset($bookingDetails['vat_rate']) ? (float)$bookingDetails['vat_rate'] : $existingVatRate);
    $paymentVatAmount = round($paymentAmount * ($paymentVatRate / 100), 2);
    $totalAmount = round($paymentAmount + $paymentVatAmount, 2);

    $receiptNumber = $existingPayment['receipt_number'] ?? null;
    if ($paymentStatus === 'completed' && $existingStatus !== 'completed' && empty($receiptNumber)) {
        $receiptNumber = generateUniqueReceiptNumber($pdo);
    }

    $bookingReference = $existingPayment['booking_type'] === 'room'
        ? ($bookingDetails['booking_reference'] ?? null)
        : ($bookingDetails['enquiry_reference'] ?? null);

    $transactionReference = getRowTransactionReference($existingPayment);
    if (array_key_exists('transaction_reference', $input)) {
        $transactionReference = trim((string)$input['transaction_reference']);
    }

    if ($existingPayment['booking_type'] === 'room') {
        ensureBookingAdditionalChargesTable();
    }
    
    // Start transaction
    $pdo->beginTransaction();
    
    try {
        $paymentColumns = getPaymentTableColumns($pdo);
        updatePaymentRecord($pdo, $paymentColumns, $paymentId, [
            'payment_reference' => $existingPayment['payment_reference'],
            'booking_type' => $existingPayment['booking_type'],
            'booking_id' => (int)$existingPayment['booking_id'],
            'booking_reference' => $bookingReference,
            'payment_date' => array_key_exists('payment_date', $input) ? $input['payment_date'] : $existingPayment['payment_date'],
            'payment_amount' => $paymentAmount,
            'vat_rate' => $paymentVatRate,
            'vat_amount' => $paymentVatAmount,
            'total_amount' => $totalAmount,
            'payment_method' => array_key_exists('payment_method', $input) ? $input['payment_method'] : $existingPayment['payment_method'],
            'payment_type' => inferApiPaymentType($paymentStatus, $paymentAmount, $subtotalDueBeforePayment, $subtotalPaidBeforePayment, $allowManualPayment),
            'payment_status' => $paymentStatus,
            'transaction_reference' => $transactionReference !== '' ? $transactionReference : null,
            'receipt_number' => $receiptNumber,
            'cc_emails' => $existingPayment['cc_emails'] ?? null,
            'processed_by' => array_key_exists('processed_by', $input)
                ? trim((string)$input['processed_by'])
                : ($existingPayment['processed_by'] ?? null),
            'notes' => array_key_exists('notes', $input)
                ? trim((string)$input['notes'])
                : ($existingPayment['notes'] ?? null)
        ]);
        
        // Update booking payment totals
        if ($existingPayment['booking_type'] === 'room') {
            updateRoomBookingPayments($pdo, $existingPayment['booking_id']);
        } else {
            updateConferenceEnquiryPayments($pdo, $existingPayment['booking_id']);
        }
        
        $pdo->commit();
        
        // Fetch updated payment
        $fetchStmt = $pdo->prepare("SELECT * FROM payments WHERE id = ?");
        $fetchStmt->execute([$paymentId]);
        $payment = $fetchStmt->fetch(PDO::FETCH_ASSOC);
        
        $response = [
            'payment' => [
                'id' => (int)$payment['id'],
                'payment_reference' => $payment['payment_reference'],
                'receipt_number' => $payment['receipt_number'],
                'amount' => [
                    'subtotal' => (float)$payment['payment_amount'],
                    'vat_rate' => (float)$payment['vat_rate'],
                    'vat_amount' => (float)$payment['vat_amount'],
                    'total' => (float)$payment['total_amount']
                ],
                'payment_method' => $payment['payment_method'],
                'status' => getRowPaymentStatus($payment),
                'transaction_reference' => getRowTransactionReference($payment),
                'updated_at' => $payment['updated_at']
            ]
        ];
        
        ApiResponse::success($response, 'Payment updated successfully');
        
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/**
 * Soft delete a payment
 */
function deletePayment($pdo, $paymentId) {
    // Check if payment exists
    $checkStmt = $pdo->prepare("SELECT * FROM payments WHERE id = ? AND deleted_at IS NULL");
    $checkStmt->execute([$paymentId]);
    $payment = $checkStmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$payment) {
        ApiResponse::error('Payment not found. It may have been deleted or does not exist.', 404);
    }

    if ($payment['booking_type'] === 'room') {
        ensureBookingAdditionalChargesTable();
    }
    
    // Start transaction
    $pdo->beginTransaction();
    
    try {
        // Soft delete
        $stmt = $pdo->prepare("UPDATE payments SET deleted_at = NOW() WHERE id = ?");
        $stmt->execute([$paymentId]);
        
        // Update booking payment totals
        if ($payment['booking_type'] === 'room') {
            updateRoomBookingPayments($pdo, $payment['booking_id']);
        } else {
            updateConferenceEnquiryPayments($pdo, $payment['booking_id']);
        }
        
        $pdo->commit();
        
        ApiResponse::success(['id' => $paymentId], 'Payment deleted successfully');
        
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/**
 * Update room booking payment totals
 */
function updateRoomBookingPayments($pdo, $bookingId) {
    recalculateRoomBookingFinancials((int)$bookingId);
}

/**
 * Update conference enquiry payment totals
 */
function updateConferenceEnquiryPayments($pdo, $enquiryId) {
    // Get enquiry total
    $enquiryStmt = $pdo->prepare("SELECT total_amount, deposit_required FROM conference_inquiries WHERE id = ?");
    $enquiryStmt->execute([$enquiryId]);
    $enquiry = $enquiryStmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$enquiry) {
        return;
    }
    
    $subtotalAmount = (float)$enquiry['total_amount'];
    $depositRequired = (float)$enquiry['deposit_required'];
    
    // Calculate paid amounts
    $paidStmt = $pdo->prepare("
        SELECT 
            SUM(CASE WHEN COALESCE(NULLIF(payment_status, ''), status, 'pending') = 'completed' THEN COALESCE(payment_amount, 0) ELSE 0 END) as subtotal_paid,
            SUM(CASE WHEN COALESCE(NULLIF(payment_status, ''), status, 'pending') = 'completed' THEN COALESCE(total_amount, 0) ELSE 0 END) as total_paid
        FROM payments
        WHERE booking_type = 'conference' 
        AND booking_id = ? 
        AND deleted_at IS NULL
    ");
    $paidStmt->execute([$enquiryId]);
    $paid = $paidStmt->fetch(PDO::FETCH_ASSOC);
    
    $subtotalPaid = (float)($paid['subtotal_paid'] ?? 0);
    $amountPaid = (float)($paid['total_paid'] ?? 0);
    
    // Calculate deposit paid
    $depositPaid = min($subtotalPaid, $depositRequired);
    
    // Get last payment date
    $lastPaymentStmt = $pdo->prepare("
        SELECT MAX(payment_date) as last_payment_date
        FROM payments
        WHERE booking_type = 'conference' 
        AND booking_id = ? 
        AND COALESCE(NULLIF(payment_status, ''), status, 'pending') = 'completed'
        AND deleted_at IS NULL
    ");
    $lastPaymentStmt->execute([$enquiryId]);
    $lastPayment = $lastPaymentStmt->fetch(PDO::FETCH_ASSOC);
    
    // Calculate VAT rate from settings
    $vatEnabled = getSetting('vat_enabled') === '1';
    $vatRate = $vatEnabled ? (float)getSetting('vat_rate') : 0;
    $vatAmount = round($subtotalAmount * ($vatRate / 100), 2);
    $totalWithVat = round($subtotalAmount + $vatAmount, 2);
    $amountDue = max(0, round($totalWithVat - $amountPaid, 2));
    
    // Update enquiry
    $updateStmt = $pdo->prepare("
        UPDATE conference_inquiries 
        SET amount_paid = ?, 
            amount_due = ?,
            vat_rate = ?,
            vat_amount = ?,
            total_with_vat = ?,
            deposit_paid = ?,
            last_payment_date = ?
        WHERE id = ?
    ");
    $updateStmt->execute([
        $amountPaid,
        $amountDue,
        $vatRate,
        $vatAmount,
        $totalWithVat,
        $depositPaid,
        $lastPayment['last_payment_date'],
        $enquiryId
    ]);
}
