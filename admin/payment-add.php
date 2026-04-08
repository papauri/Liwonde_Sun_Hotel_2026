<?php
// Include admin initialization (PHP-only, no HTML output)
require_once 'admin-init.php';

require_once '../config/email.php';
require_once '../config/invoice.php';

$user = [
    'id' => $_SESSION['admin_user_id'],
    'username' => $_SESSION['admin_username'],
    'role' => $_SESSION['admin_role'],
    'full_name' => $_SESSION['admin_full_name']
];
$site_name = getSetting('site_name');
$currency_symbol = getSetting('currency_symbol');

// Get VAT settings
$vatEnabled = getSetting('vat_enabled') === '1';
$vatRate = $vatEnabled ? (float)getSetting('vat_rate') : 0;

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

function fetchAvailableMenuItems(PDO $pdo, $table) {
    if (!in_array($table, ['food_menu', 'drink_menu'], true)) {
        return [];
    }

    try {
        $stmt = $pdo->query("SELECT id, category, item_name, price FROM {$table} WHERE is_available = 1 ORDER BY category ASC, display_order ASC, item_name ASC");
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map(function ($item) use ($table) {
            return [
                'id' => (int)$item['id'],
                'category' => $item['category'],
                'item_name' => $item['item_name'],
                'price' => (float)$item['price'],
                'kind' => $table === 'food_menu' ? 'food' : 'drink'
            ];
        }, $items);
    } catch (Throwable $e) {
        error_log('Unable to load menu items from ' . $table . ': ' . $e->getMessage());
        return [];
    }
}

function getProcessedByName(array $user) {
    $fullName = trim((string)($user['full_name'] ?? ''));
    if ($fullName !== '') {
        return $fullName;
    }

    $username = trim((string)($user['username'] ?? ''));
    return $username !== '' ? $username : 'Admin';
}

function normalizeManualPaymentStatus($status) {
    $status = strtolower(trim((string)$status));
    $map = [
        'paid' => 'completed'
    ];

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

function inferManualPaymentType($paymentStatus, $paymentAmount, $subtotalDueBeforePayment, $subtotalPaidBeforePayment, $allowManualPayment) {
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
                b.check_in_date,
                b.check_out_date,
                r.name as room_name,
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
            LEFT JOIN rooms r ON b.room_id = r.id
            WHERE b.id = ?
        ");
        $stmt->execute([$bookingId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    if ($bookingType === 'conference') {
        $stmt = $pdo->prepare("
            SELECT 
                ci.id,
                ci.enquiry_reference,
                ci.organization_name,
                ci.contact_name,
                ci.contact_email,
                ci.total_amount,
                ci.total_with_vat,
                ci.amount_paid,
                ci.amount_due,
                ci.vat_rate,
                ci.start_date,
                ci.end_date,
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

        $stmt = $pdo->prepare("
            SELECT id
            FROM bookings
            WHERE UPPER(booking_reference) = UPPER(?)
            LIMIT 1
        ");
        $stmt->execute([$bookingLookup]);
        $resolvedId = (int)$stmt->fetchColumn();

        return $resolvedId > 0 ? fetchBookingDetailsForPayment($pdo, 'room', $resolvedId) : null;
    }

    if ($bookingType === 'conference') {
        if (ctype_digit($bookingLookup)) {
            return fetchBookingDetailsForPayment($pdo, 'conference', (int)$bookingLookup);
        }

        $stmt = $pdo->prepare("
            SELECT id
            FROM conference_inquiries
            WHERE UPPER(enquiry_reference) = UPPER(?)
            LIMIT 1
        ");
        $stmt->execute([$bookingLookup]);
        $resolvedId = (int)$stmt->fetchColumn();

        return $resolvedId > 0 ? fetchBookingDetailsForPayment($pdo, 'conference', $resolvedId) : null;
    }

    return null;
}

function getSubmittedChargeRows() {
    $kinds = $_POST['folio_charge_kind'] ?? [];
    $itemIds = $_POST['folio_charge_item_id'] ?? [];
    $descriptions = $_POST['folio_charge_description'] ?? [];
    $quantities = $_POST['folio_charge_quantity'] ?? [];
    $unitPrices = $_POST['folio_charge_unit_price'] ?? [];

    $count = max(count($kinds), count($itemIds), count($descriptions), count($quantities), count($unitPrices));
    $rows = [];

    for ($index = 0; $index < $count; $index++) {
        $rows[] = [
            'kind' => $kinds[$index] ?? '',
            'item_id' => $itemIds[$index] ?? '',
            'description' => $descriptions[$index] ?? '',
            'quantity' => $quantities[$index] ?? '1',
            'unit_price' => $unitPrices[$index] ?? ''
        ];
    }

    return !empty($rows) ? $rows : [[
        'kind' => '',
        'item_id' => '',
        'description' => '',
        'quantity' => '1',
        'unit_price' => ''
    ]];
}

function parseRoomFolioCharges(PDO $pdo) {
    $kinds = $_POST['folio_charge_kind'] ?? [];
    $itemIds = $_POST['folio_charge_item_id'] ?? [];
    $descriptions = $_POST['folio_charge_description'] ?? [];
    $quantities = $_POST['folio_charge_quantity'] ?? [];
    $unitPrices = $_POST['folio_charge_unit_price'] ?? [];
    $charges = [];

    $foodStmt = $pdo->prepare("SELECT id, category, item_name, price, is_available FROM food_menu WHERE id = ? LIMIT 1");
    $drinkStmt = $pdo->prepare("SELECT id, category, item_name, price, is_available FROM drink_menu WHERE id = ? LIMIT 1");

    $count = max(count($kinds), count($itemIds), count($descriptions), count($quantities), count($unitPrices));

    for ($index = 0; $index < $count; $index++) {
        $kind = trim((string)($kinds[$index] ?? ''));
        if ($kind === '') {
            continue;
        }

        $quantity = max(1, (int)($quantities[$index] ?? 1));

        if ($kind === 'food' || $kind === 'drink') {
            $itemId = (int)($itemIds[$index] ?? 0);
            if ($itemId <= 0) {
                throw new Exception('Select a valid menu item for each food or drink charge.');
            }

            $stmt = $kind === 'food' ? $foodStmt : $drinkStmt;
            $stmt->execute([$itemId]);
            $item = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$item || (int)$item['is_available'] !== 1) {
                throw new Exception('One of the selected menu items is no longer available.');
            }

            $unitPrice = round((float)$item['price'], 2);
            $charges[] = [
                'charge_type' => 'menu',
                'description' => ucfirst($kind) . ': ' . $item['item_name'] . ' x' . $quantity . ' (' . $item['category'] . ')',
                'amount' => round($unitPrice * $quantity, 2)
            ];
            continue;
        }

        if ($kind === 'custom') {
            $description = trim((string)($descriptions[$index] ?? ''));
            $unitPrice = round((float)($unitPrices[$index] ?? 0), 2);

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

function insertRoomFolioCharges(PDO $pdo, $bookingId, array $charges, $createdBy) {
    if (empty($charges)) {
        return 0.0;
    }

    ensureBookingAdditionalChargesTable();

    $stmt = $pdo->prepare("INSERT INTO booking_additional_charges (booking_id, charge_type, description, amount, created_by) VALUES (?, ?, ?, ?, ?)");
    $total = 0.0;

    foreach ($charges as $charge) {
        $amount = round((float)$charge['amount'], 2);
        $stmt->execute([$bookingId, $charge['charge_type'], $charge['description'], $amount, $createdBy]);
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

    if (!$hasDetectedColumns || isset($paymentColumns['recorded_by'])) {
        $data['recorded_by'] = $payload['recorded_by'];
    }

    if (!empty($payload['transaction_reference'])) {
        if (isset($paymentColumns['transaction_reference'])) {
            $data['transaction_reference'] = $payload['transaction_reference'];
        }
        if (isset($paymentColumns['payment_reference_number'])) {
            $data['payment_reference_number'] = $payload['transaction_reference'];
        }
        if (isset($paymentColumns['transaction_id'])) {
            $data['transaction_id'] = $payload['transaction_reference'];
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

$paymentColumns = getPaymentTableColumns($pdo);
$foodMenuItems = fetchAvailableMenuItems($pdo, 'food_menu');
$drinkMenuItems = fetchAvailableMenuItems($pdo, 'drink_menu');

// Check if editing existing payment
$editId = isset($_GET['edit']) ? (int)$_GET['edit'] : 0;
$payment = null;

if ($editId) {
    $stmt = $pdo->prepare("SELECT * FROM payments WHERE id = ? AND deleted_at IS NULL");
    $stmt->execute([$editId]);
    $payment = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$payment) {
        $_SESSION['alert'] = ['type' => 'info', 'message' => 'Payment not found. It may have been deleted or does not exist.'];
        header('Location: payments.php');
        exit;
    }
}

// Get booking type and ID from query params for new payment
$bookingType = isset($_GET['booking_type']) ? $_GET['booking_type'] : '';
$bookingId = isset($_GET['booking_id']) ? (int)$_GET['booking_id'] : 0;
$bookingLookupValue = '';

// Pre-fill from existing payment or query params
if ($payment) {
    $bookingType = $payment['booking_type'];
    $bookingId = $payment['booking_id'];
}

// Get booking details
$bookingDetails = null;
$outstandingAmount = 0;
$outstandingSubtotal = 0;

if ($bookingType && $bookingId) {
    $bookingDetails = fetchBookingDetailsForPayment($pdo, $bookingType, $bookingId);
    $outstandingAmount = (float)($bookingDetails['amount_due'] ?? 0);
    $outstandingSubtotal = (float)($bookingDetails['subtotal_due'] ?? 0);
}

if ($bookingDetails) {
    $bookingLookupValue = $bookingType === 'room'
        ? $bookingDetails['booking_reference']
        : $bookingDetails['enquiry_reference'];
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $bookingLookupValue = trim((string)($_POST['booking_lookup'] ?? ''));
}

$submittedChargeRows = getSubmittedChargeRows();

// Process form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $bookingType = $_POST['booking_type'] ?? '';
    $requestedBookingId = (int)($_POST['booking_id'] ?? 0);
    $bookingLookupValue = trim((string)($_POST['booking_lookup'] ?? ''));
    $paymentAmount = (float)($_POST['payment_amount'] ?? 0);
    $paymentDate = $_POST['payment_date'] ?? date('Y-m-d');
    $paymentMethod = $_POST['payment_method'] ?? '';
    $paymentStatus = normalizeManualPaymentStatus($_POST['payment_status'] ?? 'pending');
    $transactionReference = $_POST['transaction_reference'] ?? '';
    $notes = $_POST['notes'] ?? '';
    $ccEmails = $_POST['cc_emails'] ?? '';
    $allowManualPayment = isset($_POST['allow_manual_payment']);
    $processedBy = getProcessedByName($user);
    $bookingDetails = $editId
        ? fetchBookingDetailsForPayment($pdo, $bookingType, $bookingId)
        : resolveBookingForPayment($pdo, $bookingType, $requestedBookingId, $bookingLookupValue);

    if ($bookingDetails) {
        $bookingId = (int)$bookingDetails['id'];
        $outstandingAmount = (float)($bookingDetails['amount_due'] ?? 0);
        $outstandingSubtotal = (float)($bookingDetails['subtotal_due'] ?? 0);
        $bookingLookupValue = $bookingType === 'room'
            ? $bookingDetails['booking_reference']
            : $bookingDetails['enquiry_reference'];
    }
    
    // Validate
    if (!$bookingType || !$bookingDetails || $paymentAmount <= 0 || !$paymentMethod) {
        $_SESSION['alert'] = ['type' => 'error', 'message' => 'Select a valid booking and fill in all required payment fields.'];
    } else {
        try {
            $folioCharges = [];
            if (!$editId && $bookingType === 'room') {
                $folioCharges = parseRoomFolioCharges($pdo);
            }

            if ($editId) {
                $pdo->beginTransaction();
                $paymentVatRate = isset($bookingDetails['vat_rate']) ? (float)$bookingDetails['vat_rate'] : $vatRate;
                $paymentVatAmount = round($paymentAmount * ($paymentVatRate / 100), 2);
                $totalAmount = round($paymentAmount + $paymentVatAmount, 2);
                $receiptNumber = $payment['receipt_number'] ?? null;

                if ($paymentStatus === 'completed' && ($payment['payment_status'] ?? '') !== 'completed' && empty($receiptNumber)) {
                    $receiptNumber = generateUniqueReceiptNumber($pdo);
                }

                updatePaymentRecord($pdo, $paymentColumns, $editId, [
                    'payment_reference' => $payment['payment_reference'],
                    'booking_type' => $bookingType,
                    'booking_id' => $bookingId,
                    'booking_reference' => $bookingType === 'room' ? $bookingDetails['booking_reference'] : $bookingDetails['enquiry_reference'],
                    'payment_date' => $paymentDate,
                    'payment_amount' => round($paymentAmount, 2),
                    'vat_rate' => $paymentVatRate,
                    'vat_amount' => $paymentVatAmount,
                    'total_amount' => $totalAmount,
                    'payment_method' => $paymentMethod,
                    'payment_type' => inferManualPaymentType($paymentStatus, $paymentAmount, (float)($bookingDetails['subtotal_due'] ?? 0), (float)($bookingDetails['subtotal_paid'] ?? 0), $allowManualPayment),
                    'payment_status' => $paymentStatus,
                    'transaction_reference' => $transactionReference ?: null,
                    'receipt_number' => $receiptNumber,
                    'cc_emails' => $ccEmails ?: null,
                    'processed_by' => $processedBy,
                    'recorded_by' => (int)$user['id'],
                    'notes' => $notes ?: null
                ]);
                
                // Update booking totals
                if ($bookingType === 'room') {
                    updateRoomBookingPayments($pdo, $bookingId);
                } else {
                    updateConferenceEnquiryPayments($pdo, $bookingId);
                }
                
                $pdo->commit();
                
                $_SESSION['alert'] = ['type' => 'success', 'message' => 'Payment updated successfully'];
                header('Location: payment-details.php?id=' . $editId);
                exit;
                
            } else {
                $pdo->beginTransaction();

                if (!empty($folioCharges)) {
                    insertRoomFolioCharges($pdo, $bookingId, $folioCharges, (int)$user['id']);
                    recalculateRoomBookingFinancials($bookingId);
                    $bookingDetails = fetchBookingDetailsForPayment($pdo, $bookingType, $bookingId);
                }

                $subtotalDueBeforePayment = (float)($bookingDetails['subtotal_due'] ?? 0);
                $subtotalPaidBeforePayment = (float)($bookingDetails['subtotal_paid'] ?? 0);

                if ($paymentStatus === 'completed' && !$allowManualPayment && $paymentAmount > ($subtotalDueBeforePayment + 0.01)) {
                    throw new Exception('This payment exceeds the booking subtotal due. Tick "Allow manual credit / overpayment entry" if you intentionally need a credit or adjustment.');
                }

                $paymentVatRate = isset($bookingDetails['vat_rate']) ? (float)$bookingDetails['vat_rate'] : $vatRate;
                $paymentVatAmount = round($paymentAmount * ($paymentVatRate / 100), 2);
                $totalAmount = round($paymentAmount + $paymentVatAmount, 2);
                $paymentRef = generateUniquePaymentReference($pdo);
                $receiptNumber = $paymentStatus === 'completed' ? generateUniqueReceiptNumber($pdo) : null;
                
                // Get booking reference for the payment record
                $bookingReference = $bookingType === 'room'
                    ? $bookingDetails['booking_reference']
                    : $bookingDetails['enquiry_reference'];

                $newPaymentId = insertPaymentRecord($pdo, $paymentColumns, [
                    'payment_reference' => $paymentRef,
                    'booking_type' => $bookingType,
                    'booking_id' => $bookingId,
                    'booking_reference' => $bookingReference,
                    'payment_date' => $paymentDate,
                    'payment_amount' => round($paymentAmount, 2),
                    'vat_rate' => $paymentVatRate,
                    'vat_amount' => $paymentVatAmount,
                    'total_amount' => $totalAmount,
                    'payment_method' => $paymentMethod,
                    'payment_type' => inferManualPaymentType($paymentStatus, $paymentAmount, $subtotalDueBeforePayment, $subtotalPaidBeforePayment, $allowManualPayment),
                    'payment_status' => $paymentStatus,
                    'transaction_reference' => $transactionReference ?: null,
                    'receipt_number' => $receiptNumber,
                    'cc_emails' => $ccEmails ?: null,
                    'processed_by' => $processedBy,
                    'recorded_by' => (int)$user['id'],
                    'notes' => $notes ?: null
                ]);
                
                // Update booking totals
                if ($bookingType === 'room') {
                    updateRoomBookingPayments($pdo, $bookingId);
                } else {
                    updateConferenceEnquiryPayments($pdo, $bookingId);
                }
                
                $pdo->commit();
                
                // Send payment confirmation email for room bookings
                if ($bookingType === 'room' && $paymentStatus === 'completed') {
                    try {
                        // Merge default CC recipients with additional CCs from form
                        $defaultCcRecipients = getEmailSetting('invoice_recipients', '');
                        $smtpUsername = getEmailSetting('smtp_username', '');
                        
                        // Parse default recipients
                        $allCcRecipients = array_filter(array_map('trim', explode(',', $defaultCcRecipients)));
                        
                        // Add SMTP username to CC list
                        if (!empty($smtpUsername) && !in_array($smtpUsername, $allCcRecipients)) {
                            $allCcRecipients[] = $smtpUsername;
                        }
                        
                        // Add additional CCs from form
                        if (!empty($ccEmails)) {
                            $additionalCc = array_filter(array_map('trim', explode(',', $ccEmails)));
                            foreach ($additionalCc as $email) {
                                if (!in_array($email, $allCcRecipients)) {
                                    $allCcRecipients[] = $email;
                                }
                            }
                        }
                        
                        // Send payment invoice with CC recipients
                        $email_result = sendPaymentInvoiceEmailWithCC($bookingId, $allCcRecipients);
                        if (!$email_result['success']) {
                            error_log("Failed to send room payment invoice email: " . $email_result['message']);
                        } else {
                            $logMsg = "Room payment invoice email sent successfully";
                            if (isset($email_result['preview_url'])) {
                                $logMsg .= " - Preview: " . $email_result['preview_url'];
                            }
                            if (!empty($allCcRecipients)) {
                                $logMsg .= " - CC: " . implode(', ', $allCcRecipients);
                            }
                            error_log($logMsg);
                        }
                    } catch (Exception $e) {
                        error_log("Error sending room payment invoice email: " . $e->getMessage());
                    }
                }
                
                // Send invoice email for conference bookings
                if ($bookingType === 'conference' && $paymentStatus === 'completed') {
                    try {
                        // Merge default CC recipients with additional CCs from form
                        $defaultCcRecipients = getEmailSetting('invoice_recipients', '');
                        $smtpUsername = getEmailSetting('smtp_username', '');
                        
                        // Parse default recipients
                        $allCcRecipients = array_filter(array_map('trim', explode(',', $defaultCcRecipients)));
                        
                        // Add SMTP username to CC list
                        if (!empty($smtpUsername) && !in_array($smtpUsername, $allCcRecipients)) {
                            $allCcRecipients[] = $smtpUsername;
                        }
                        
                        // Add additional CCs from form
                        if (!empty($ccEmails)) {
                            $additionalCc = array_filter(array_map('trim', explode(',', $ccEmails)));
                            foreach ($additionalCc as $email) {
                                if (!in_array($email, $allCcRecipients)) {
                                    $allCcRecipients[] = $email;
                                }
                            }
                        }
                        
                        // Generate invoice and send with CC recipients
                        $email_result = sendConferenceInvoiceEmailWithCC($bookingId, $allCcRecipients);
                        if (!$email_result['success']) {
                            error_log("Failed to send conference invoice email: " . $email_result['message']);
                        } else {
                            $logMsg = "Conference invoice email sent successfully";
                            if (isset($email_result['preview_url'])) {
                                $logMsg .= " - Preview: " . $email_result['preview_url'];
                            }
                            if (!empty($allCcRecipients)) {
                                $logMsg .= " - CC: " . implode(', ', $allCcRecipients);
                            }
                            error_log($logMsg);
                        }
                    } catch (Exception $e) {
                        error_log("Error sending conference invoice email: " . $e->getMessage());
                    }
                }
                
                $_SESSION['alert'] = ['type' => 'success', 'message' => 'Payment recorded successfully'];
                header('Location: payment-details.php?id=' . $newPaymentId);
                exit;
            }
            
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $_SESSION['alert'] = ['type' => 'error', 'message' => 'Database error: ' . $e->getMessage()];
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $_SESSION['alert'] = ['type' => 'error', 'message' => 'Error: ' . $e->getMessage()];
        }
    }
}

// Helper functions
function updateRoomBookingPayments($pdo, $bookingId) {
    recalculateRoomBookingFinancials($bookingId);
}

function updateConferenceEnquiryPayments($pdo, $enquiryId) {
    $enquiryStmt = $pdo->prepare("SELECT total_amount, deposit_required FROM conference_inquiries WHERE id = ?");
    $enquiryStmt->execute([$enquiryId]);
    $enquiry = $enquiryStmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$enquiry) return;
    
    $subtotalAmount = (float)$enquiry['total_amount'];
    $depositRequired = (float)$enquiry['deposit_required'];
    
    $paidStmt = $pdo->prepare("
        SELECT 
            SUM(CASE WHEN payment_status = 'completed' THEN COALESCE(payment_amount, 0) ELSE 0 END) as subtotal_paid,
            SUM(CASE WHEN payment_status = 'completed' THEN COALESCE(total_amount, 0) ELSE 0 END) as total_paid
        FROM payments
        WHERE booking_type = 'conference' 
        AND booking_id = ? 
        AND deleted_at IS NULL
    ");
    $paidStmt->execute([$enquiryId]);
    $paid = $paidStmt->fetch(PDO::FETCH_ASSOC);
    
    $subtotalPaid = (float)($paid['subtotal_paid'] ?? 0);
    $amountPaid = (float)($paid['total_paid'] ?? 0);
    $depositPaid = min($subtotalPaid, $depositRequired);
    
    $lastPaymentStmt = $pdo->prepare("
        SELECT MAX(payment_date) as last_payment_date
        FROM payments
        WHERE booking_type = 'conference' 
        AND booking_id = ? 
        AND payment_status = 'completed'
        AND deleted_at IS NULL
    ");
    $lastPaymentStmt->execute([$enquiryId]);
    $lastPayment = $lastPaymentStmt->fetch(PDO::FETCH_ASSOC);
    
    $vatEnabled = getSetting('vat_enabled') === '1';
    $vatRate = $vatEnabled ? (float)getSetting('vat_rate') : 0;
    $vatAmount = round($subtotalAmount * ($vatRate / 100), 2);
    $totalWithVat = round($subtotalAmount + $vatAmount, 2);
    $amountDue = max(0, round($totalWithVat - $amountPaid, 2));
    
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

$formPaymentAmount = $_POST['payment_amount']
    ?? ($payment['payment_amount'] ?? ((!$editId && $outstandingSubtotal > 0) ? number_format($outstandingSubtotal, 2, '.', '') : ''));
$formPaymentDate = $_POST['payment_date'] ?? ($payment['payment_date'] ?? date('Y-m-d'));
$formPaymentMethod = $_POST['payment_method'] ?? ($payment['payment_method'] ?? '');
$formPaymentStatus = $_POST['payment_status'] ?? ($payment['payment_status'] ?? 'pending');
$formTransactionReference = $_POST['transaction_reference']
    ?? ($payment['transaction_reference'] ?? ($payment['payment_reference_number'] ?? ($payment['transaction_id'] ?? '')));
$formCcEmails = $_POST['cc_emails'] ?? ($payment['cc_emails'] ?? '');
$formNotes = $_POST['notes'] ?? ($payment['notes'] ?? '');
$formAllowManualPayment = isset($_POST['allow_manual_payment']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $editId ? 'Edit Payment' : 'Record Payment'; ?> | <?php echo htmlspecialchars($site_name); ?> Admin</title>
    
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;500;600;700&family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../css/style.css">
    <link rel="stylesheet" href="../css/theme-dynamic.php">
    <link rel="stylesheet" href="css/admin-styles.css">
    <link rel="stylesheet" href="css/admin-components.css">
    
    <style>
        .form-container {
            max-width: 800px;
            margin: 0 auto;
        }
        
        .form-section {
            background: white;
            padding: 24px;
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow);
            margin-bottom: 24px;
        }
        
        .form-section h3 {
            margin-bottom: 20px;
            color: var(--navy);
            font-size: 18px;
            font-weight: 600;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
            color: var(--navy);
        }
        
        .form-group label .required {
            color: #dc3545;
        }
        
        .form-group input,
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 12px;
            border: 1px solid #ddd;
            border-radius: var(--radius);
            font-family: inherit;
            font-size: 14px;
        }
        
        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: var(--navy);
            box-shadow: 0 0 0 3px rgba(13, 71, 161, 0.1);
        }
        
        .form-group input:disabled,
        .form-group select:disabled {
            background-color: #f5f5f5;
            color: #999;
            cursor: not-allowed;
        }
        
        .form-group textarea {
            min-height: 100px;
            resize: vertical;
        }
        
        .form-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
        }
        
        .booking-info {
            background: #f8f9fa;
            padding: 16px;
            border-radius: var(--radius);
            margin-bottom: 20px;
        }
        
        .booking-info.fully-paid {
            background: #d4edda;
            border: 1px solid #c3e6cb;
        }
        
        .booking-info h4 {
            margin-bottom: 12px;
            color: var(--navy);
        }
        
        .booking-info p {
            margin: 6px 0;
            font-size: 14px;
        }
        
        .booking-info strong {
            color: var(--navy);
        }
        
        .calculation-preview {
            background: #e7f3ff;
            padding: 16px;
            border-radius: var(--radius);
            margin-top: 16px;
        }
        
        .calculation-preview h4 {
            margin-bottom: 12px;
            color: var(--navy);
        }
        
        .calc-row {
            display: flex;
            justify-content: space-between;
            padding: 8px 0;
            border-bottom: 1px solid rgba(13, 71, 161, 0.1);
        }
        
        .calc-row:last-child {
            border-bottom: none;
        }
        
        .calc-row.total {
            font-weight: 700;
            font-size: 16px;
            color: var(--navy);
            padding-top: 12px;
        }
        
        .form-actions {
            display: flex;
            gap: 12px;
            justify-content: flex-end;
        }
        
        .help-text {
            font-size: 12px;
            color: #666;
            margin-top: 4px;
        }
        
        .booking-search-results {
            position: absolute;
            top: 100%;
            left: 0;
            right: 0;
            background: white;
            border: 1px solid #ddd;
            border-radius: var(--radius);
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            max-height: 300px;
            overflow-y: auto;
            z-index: 1000;
            margin-top: 4px;
        }
        
        .booking-search-item {
            padding: 12px 16px;
            border-bottom: 1px solid #eee;
            cursor: pointer;
            transition: background 0.2s;
        }
        
        .booking-search-item:hover {
            background: #f8f9fa;
        }
        
        .booking-search-item:last-child {
            border-bottom: none;
        }
        
        .booking-search-item strong {
            color: var(--navy);
            display: block;
            margin-bottom: 4px;
        }
        
        .booking-search-item small {
            color: #666;
            display: block;
        }
        
        .booking-search-no-results {
            padding: 16px;
            text-align: center;
            color: #666;
        }
        
        .booking-search-loading {
            padding: 16px;
            text-align: center;
            color: var(--navy);
        }
        
        /* Warning and alert styles */
        .warning-box {
            background: #fff3cd;
            border: 1px solid #ffc107;
            border-radius: var(--radius);
            padding: 16px;
            margin-bottom: 16px;
        }
        
        .warning-box h5 {
            color: #856404;
            margin: 0 0 8px 0;
            font-size: 14px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .warning-box p {
            color: #856404;
            margin: 0;
            font-size: 13px;
        }
        
        .fully-paid-badge {
            display: inline-block;
            background: #28a745;
            color: white;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            margin-left: 8px;
        }
        
        .checkbox-wrapper {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-top: 8px;
        }
        
        .checkbox-wrapper input[type="checkbox"] {
            width: auto;
            margin: 0;
        }
        
        .checkbox-wrapper label {
            margin: 0;
            font-weight: 400;
            font-size: 13px;
            cursor: pointer;
        }
    </style>
</head>
<body>

    <?php require_once 'includes/admin-header.php'; ?>

    <div class="content">
        <div class="form-container">
            <h2 class="section-title"><?php echo $editId ? 'Edit Payment' : 'Record New Payment'; ?></h2>
            
            <form method="POST">
                <?php echo getCsrfField(); ?>
                <div class="form-section">
                    <h3><i class="fas fa-calendar-check"></i> Booking Information</h3>

                    <?php if ($editId): ?>
                        <div class="booking-info">
                            <h4><?php echo ucfirst($bookingType); ?> Booking Details</h4>
                            <?php if ($bookingType === 'room'): ?>
                                <p><strong>Reference:</strong> <?php echo htmlspecialchars($bookingDetails['booking_reference']); ?></p>
                                <p><strong>Guest:</strong> <?php echo htmlspecialchars($bookingDetails['guest_name']); ?></p>
                                <p><strong>Email:</strong> <?php echo htmlspecialchars($bookingDetails['guest_email']); ?></p>
                                <p><strong>Room:</strong> <?php echo htmlspecialchars($bookingDetails['room_name']); ?></p>
                                <p><strong>Stay:</strong> <?php echo date('M j, Y', strtotime($bookingDetails['check_in_date'])); ?> - <?php echo date('M j, Y', strtotime($bookingDetails['check_out_date'])); ?></p>
                                <p><strong>Subtotal Due:</strong> <?php echo $currency_symbol; ?><?php echo number_format((float)$bookingDetails['subtotal_due'], 2); ?></p>
                                <p><strong>Total Due:</strong> <span style="color: <?php echo $bookingDetails['amount_due'] > 0 ? '#dc3545' : '#28a745'; ?>; font-weight: 600;"><?php echo $currency_symbol; ?><?php echo number_format((float)$bookingDetails['amount_due'], 2); ?></span></p>
                            <?php else: ?>
                                <p><strong>Reference:</strong> <?php echo htmlspecialchars($bookingDetails['enquiry_reference']); ?></p>
                                <p><strong>Organization:</strong> <?php echo htmlspecialchars($bookingDetails['organization_name']); ?></p>
                                <p><strong>Contact:</strong> <?php echo htmlspecialchars($bookingDetails['contact_name']); ?></p>
                                <p><strong>Email:</strong> <?php echo htmlspecialchars($bookingDetails['contact_email']); ?></p>
                                <p><strong>Event:</strong> <?php echo date('M j, Y', strtotime($bookingDetails['start_date'])); ?> - <?php echo date('M j, Y', strtotime($bookingDetails['end_date'])); ?></p>
                                <p><strong>Subtotal Due:</strong> <?php echo $currency_symbol; ?><?php echo number_format((float)$bookingDetails['subtotal_due'], 2); ?></p>
                                <p><strong>Total Due:</strong> <span style="color: <?php echo $bookingDetails['amount_due'] > 0 ? '#dc3545' : '#28a745'; ?>; font-weight: 600;"><?php echo $currency_symbol; ?><?php echo number_format((float)$bookingDetails['amount_due'], 2); ?></span></p>
                            <?php endif; ?>
                        </div>
                        <input type="hidden" name="booking_type" value="<?php echo htmlspecialchars($bookingType); ?>">
                        <input type="hidden" name="booking_id" id="booking_id" value="<?php echo $bookingId; ?>">
                        <input type="hidden" name="booking_lookup" value="<?php echo htmlspecialchars($bookingLookupValue); ?>">
                    <?php else: ?>
                        <div class="form-row">
                            <div class="form-group">
                                <label>Booking Type <span class="required">*</span></label>
                                <select name="booking_type" id="booking_type" required>
                                    <option value="">Select type...</option>
                                    <option value="room" <?php echo $bookingType === 'room' ? 'selected' : ''; ?>>Room Booking</option>
                                    <option value="conference" <?php echo $bookingType === 'conference' ? 'selected' : ''; ?>>Conference Booking</option>
                                </select>
                            </div>

                            <div class="form-group" style="position: relative;">
                                <label>Booking Reference / Search <span class="required">*</span></label>
                                <input type="hidden" name="booking_id" id="booking_id" value="<?php echo $bookingId; ?>">
                                <div style="display: flex; gap: 8px;">
                                    <input type="text" name="booking_lookup" id="booking_lookup" value="<?php echo htmlspecialchars($bookingLookupValue); ?>" required style="flex: 1;" placeholder="Type booking reference, guest, email, or internal ID">
                                    <button type="button" id="search_booking_btn" class="btn btn-secondary" style="padding: 12px 16px;">
                                        <i class="fas fa-search"></i>
                                    </button>
                                </div>
                                <div id="booking_search_results" class="booking-search-results" style="display: none;"></div>
                                <div class="help-text">Pick a result to fill the internal booking ID automatically. You can also type an exact booking reference and submit directly.</div>
                            </div>
                        </div>

                        <div id="dynamic_booking_info" class="booking-info <?php echo ($bookingDetails && $outstandingSubtotal <= 0) ? 'fully-paid' : ''; ?>" style="display: <?php echo $bookingDetails ? 'block' : 'none'; ?>;">
                            <h4 id="booking_info_title">
                                <?php if ($bookingDetails): ?>
                                    <?php echo $bookingType === 'room' ? 'Room Booking Details' : 'Conference Booking Details'; ?>
                                    <?php if ($outstandingSubtotal <= 0): ?>
                                        <span class="fully-paid-badge">FULLY PAID</span>
                                    <?php endif; ?>
                                <?php else: ?>
                                    Booking Details
                                <?php endif; ?>
                            </h4>
                            <div id="booking_info_content">
                                <?php if ($bookingDetails): ?>
                                    <?php if ($bookingType === 'room'): ?>
                                        <p><strong>Reference:</strong> <?php echo htmlspecialchars($bookingDetails['booking_reference']); ?></p>
                                        <p><strong>Guest:</strong> <?php echo htmlspecialchars($bookingDetails['guest_name']); ?></p>
                                        <p><strong>Email:</strong> <?php echo htmlspecialchars($bookingDetails['guest_email']); ?></p>
                                        <p><strong>Room:</strong> <?php echo htmlspecialchars($bookingDetails['room_name']); ?></p>
                                        <p><strong>Dates:</strong> <?php echo date('M j, Y', strtotime($bookingDetails['check_in_date'])); ?> - <?php echo date('M j, Y', strtotime($bookingDetails['check_out_date'])); ?></p>
                                        <p><strong>Subtotal Due:</strong> <?php echo $currency_symbol; ?><?php echo number_format((float)$bookingDetails['subtotal_due'], 2); ?></p>
                                        <p><strong>Total Due:</strong> <span style="color: <?php echo $bookingDetails['amount_due'] > 0 ? '#dc3545' : '#28a745'; ?>; font-weight: 600;"><?php echo $currency_symbol; ?><?php echo number_format((float)$bookingDetails['amount_due'], 2); ?></span></p>
                                    <?php else: ?>
                                        <p><strong>Reference:</strong> <?php echo htmlspecialchars($bookingDetails['enquiry_reference']); ?></p>
                                        <p><strong>Organization:</strong> <?php echo htmlspecialchars($bookingDetails['organization_name']); ?></p>
                                        <p><strong>Contact:</strong> <?php echo htmlspecialchars($bookingDetails['contact_name']); ?></p>
                                        <p><strong>Email:</strong> <?php echo htmlspecialchars($bookingDetails['contact_email']); ?></p>
                                        <p><strong>Dates:</strong> <?php echo date('M j, Y', strtotime($bookingDetails['start_date'])); ?> - <?php echo date('M j, Y', strtotime($bookingDetails['end_date'])); ?></p>
                                        <p><strong>Subtotal Due:</strong> <?php echo $currency_symbol; ?><?php echo number_format((float)$bookingDetails['subtotal_due'], 2); ?></p>
                                        <p><strong>Total Due:</strong> <span style="color: <?php echo $bookingDetails['amount_due'] > 0 ? '#dc3545' : '#28a745'; ?>; font-weight: 600;"><?php echo $currency_symbol; ?><?php echo number_format((float)$bookingDetails['amount_due'], 2); ?></span></p>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </div>
                            <button type="button" id="clear_booking_btn" class="btn btn-secondary" style="margin-top: 12px; padding: 8px 16px; font-size: 13px;">
                                <i class="fas fa-times"></i> Clear Selection
                            </button>
                        </div>
                    <?php endif; ?>
                </div>

                <?php if (!$editId): ?>
                    <div class="form-section" id="room_charge_section" style="display: <?php echo $bookingType === 'room' ? 'block' : 'none'; ?>;">
                        <h3><i class="fas fa-utensils"></i> Add Room Folio Charges</h3>
                        <div class="help-text">Food and drink selections are posted into the room folio first, then the payment is applied against the updated balance.</div>

                        <div id="folio_charge_rows">
                            <?php foreach ($submittedChargeRows as $row): ?>
                                <div class="charge-row">
                                    <div class="form-row">
                                        <div class="form-group">
                                            <label>Charge Type</label>
                                            <select name="folio_charge_kind[]" class="folio-charge-kind">
                                                <option value="">No extra charge</option>
                                                <option value="food" <?php echo $row['kind'] === 'food' ? 'selected' : ''; ?>>Food Menu Item</option>
                                                <option value="drink" <?php echo $row['kind'] === 'drink' ? 'selected' : ''; ?>>Drink Menu Item</option>
                                                <option value="custom" <?php echo $row['kind'] === 'custom' ? 'selected' : ''; ?>>Custom Charge</option>
                                            </select>
                                        </div>
                                        <div class="form-group">
                                            <label>Menu Item</label>
                                            <select name="folio_charge_item_id[]" class="folio-charge-item" data-selected="<?php echo htmlspecialchars((string)$row['item_id']); ?>">
                                                <option value="">Select item...</option>
                                            </select>
                                        </div>
                                    </div>

                                    <div class="form-row">
                                        <div class="form-group">
                                            <label>Description</label>
                                            <input type="text" name="folio_charge_description[]" class="folio-charge-description" value="<?php echo htmlspecialchars($row['description']); ?>" placeholder="Custom description if needed">
                                        </div>
                                        <div class="form-group">
                                            <label>Quantity</label>
                                            <input type="number" name="folio_charge_quantity[]" class="folio-charge-quantity" min="1" step="1" value="<?php echo htmlspecialchars($row['quantity']); ?>">
                                        </div>
                                        <div class="form-group">
                                            <label>Unit Price</label>
                                            <input type="number" name="folio_charge_unit_price[]" class="folio-charge-unit-price" min="0" step="0.01" value="<?php echo htmlspecialchars($row['unit_price']); ?>" placeholder="0.00">
                                        </div>
                                        <div class="form-group">
                                            <label>Line Total</label>
                                            <input type="text" class="folio-charge-line-total" value="<?php echo $currency_symbol; ?>0.00" readonly>
                                        </div>
                                    </div>

                                    <div class="charge-actions">
                                        <button type="button" class="btn btn-secondary remove-charge-row" style="padding: 8px 14px; font-size: 13px;">
                                            <i class="fas fa-trash"></i> Remove Row
                                        </button>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <div class="form-actions" style="justify-content: flex-start; margin-top: 12px;">
                            <button type="button" id="add_charge_row_btn" class="btn btn-secondary">
                                <i class="fas fa-plus"></i> Add Charge Row
                            </button>
                        </div>
                    </div>
                <?php endif; ?>

                <div class="form-section">
                    <h3><i class="fas fa-money-bill-wave"></i> Payment Details</h3>

                    <div class="form-row">
                        <div class="form-group">
                            <label>Payment Amount <span class="required">*</span></label>
                            <input type="number" name="payment_amount" id="payment_amount" step="0.01" min="0.01" value="<?php echo htmlspecialchars((string)$formPaymentAmount); ?>" required>
                            <div class="help-text">Enter the payment subtotal before VAT. Use the auto-fill option to match the current subtotal due plus any selected room charges.</div>
                            <div class="checkbox-wrapper" style="margin-top: 10px;">
                                <input type="checkbox" id="auto_fill_payment_amount" <?php echo !$editId ? 'checked' : ''; ?>>
                                <label for="auto_fill_payment_amount">Auto-fill payment amount from the current subtotal due and selected room charges</label>
                            </div>
                            <div class="checkbox-wrapper">
                                <input type="checkbox" id="allow_manual_payment" name="allow_manual_payment" <?php echo $formAllowManualPayment ? 'checked' : ''; ?>>
                                <label for="allow_manual_payment">Allow manual credit / overpayment entry</label>
                            </div>
                        </div>

                        <div class="form-group">
                            <label>Payment Date <span class="required">*</span></label>
                            <input type="date" name="payment_date" value="<?php echo htmlspecialchars($formPaymentDate); ?>" required>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label>Payment Method <span class="required">*</span></label>
                            <select name="payment_method" id="payment_method" required>
                                <option value="">Select method...</option>
                                <option value="cash" <?php echo $formPaymentMethod === 'cash' ? 'selected' : ''; ?>>Cash</option>
                                <option value="bank_transfer" <?php echo $formPaymentMethod === 'bank_transfer' ? 'selected' : ''; ?>>Bank Transfer</option>
                                <option value="credit_card" <?php echo $formPaymentMethod === 'credit_card' ? 'selected' : ''; ?>>Credit Card</option>
                                <option value="debit_card" <?php echo $formPaymentMethod === 'debit_card' ? 'selected' : ''; ?>>Debit Card</option>
                                <option value="mobile_money" <?php echo $formPaymentMethod === 'mobile_money' ? 'selected' : ''; ?>>Mobile Money</option>
                                <option value="cheque" <?php echo $formPaymentMethod === 'cheque' ? 'selected' : ''; ?>>Cheque</option>
                                <option value="other" <?php echo $formPaymentMethod === 'other' ? 'selected' : ''; ?>>Other</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label>Payment Status <span class="required">*</span></label>
                            <select name="payment_status" id="payment_status" required>
                                <option value="pending" <?php echo $formPaymentStatus === 'pending' ? 'selected' : ''; ?>>Pending</option>
                                <option value="completed" <?php echo $formPaymentStatus === 'completed' ? 'selected' : ''; ?>>Completed</option>
                                <option value="failed" <?php echo $formPaymentStatus === 'failed' ? 'selected' : ''; ?>>Failed</option>
                                <option value="refunded" <?php echo $formPaymentStatus === 'refunded' ? 'selected' : ''; ?>>Refunded</option>
                                <option value="partially_refunded" <?php echo $formPaymentStatus === 'partially_refunded' ? 'selected' : ''; ?>>Partially Refunded</option>
                                <option value="cancelled" <?php echo $formPaymentStatus === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Transaction Reference</label>
                        <input type="text" name="transaction_reference" value="<?php echo htmlspecialchars($formTransactionReference); ?>" placeholder="Bank reference, cheque number, mobile money code, etc.">
                    </div>

                    <div class="form-group">
                        <label>Additional CC Emails</label>
                        <input type="text" name="cc_emails" value="<?php echo htmlspecialchars($formCcEmails); ?>" placeholder="email1@example.com, email2@example.com">
                        <div class="help-text">Comma-separated email addresses to receive a copy of the payment receipt in addition to the default recipients.</div>
                    </div>

                    <div class="form-group">
                        <label>Notes</label>
                        <textarea name="notes" placeholder="Any additional notes about this payment..."><?php echo htmlspecialchars($formNotes); ?></textarea>
                    </div>

                    <div class="calculation-preview" id="calculation-preview">
                        <h4>Payment Calculation</h4>
                        <?php if (!$editId): ?>
                            <div class="calc-row">
                                <span>Selected Folio Charges:</span>
                                <span id="selected-charges-display"><?php echo $currency_symbol; ?>0.00</span>
                            </div>
                            <div class="calc-row">
                                <span>Subtotal Due Before This Payment:</span>
                                <span id="subtotal-due-display"><?php echo $currency_symbol; ?><?php echo number_format((float)$outstandingSubtotal, 2); ?></span>
                            </div>
                        <?php endif; ?>
                        <div class="calc-row">
                            <span>Payment Subtotal:</span>
                            <span id="subtotal-display"><?php echo $currency_symbol; ?>0.00</span>
                        </div>
                        <?php if ($vatEnabled): ?>
                            <div class="calc-row">
                                <span>VAT (<?php echo $vatRate; ?>%):</span>
                                <span id="vat-display"><?php echo $currency_symbol; ?>0.00</span>
                            </div>
                        <?php endif; ?>
                        <div class="calc-row total">
                            <span>Payment Total:</span>
                            <span id="total-display"><?php echo $currency_symbol; ?>0.00</span>
                        </div>
                    </div>
                </div>

                <div class="form-actions">
                    <a href="payments.php" class="btn btn-secondary">Cancel</a>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save"></i> <?php echo $editId ? 'Update Payment' : 'Record Payment'; ?>
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        const vatRate = <?php echo json_encode((float)$vatRate); ?>;
        const currencySymbol = <?php echo json_encode($currency_symbol); ?>;
        const vatEnabled = <?php echo $vatEnabled ? 'true' : 'false'; ?>;
        const isEditMode = <?php echo $editId ? 'true' : 'false'; ?>;
        const menuCatalog = {
            food: <?php echo json_encode($foodMenuItems); ?>,
            drink: <?php echo json_encode($drinkMenuItems); ?>
        };

        const paymentAmountInput = document.getElementById('payment_amount');
        const autoFillCheckbox = document.getElementById('auto_fill_payment_amount');
        const allowManualPaymentCheckbox = document.getElementById('allow_manual_payment');
        const subtotalDisplay = document.getElementById('subtotal-display');
        const vatDisplay = document.getElementById('vat-display');
        const totalDisplay = document.getElementById('total-display');
        const subtotalDueDisplay = document.getElementById('subtotal-due-display');
        const selectedChargesDisplay = document.getElementById('selected-charges-display');
        const paymentMethodSelect = document.getElementById('payment_method');
        const paymentStatusSelect = document.getElementById('payment_status');
        const roomChargeSection = document.getElementById('room_charge_section');
        let currentOutstandingSubtotal = <?php echo json_encode((float)$outstandingSubtotal); ?>;
        let searchTimeout = null;

        function formatMoney(amount) {
            return currencySymbol + Number(amount || 0).toLocaleString(undefined, {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2
            });
        }

        function getSelectedChargeSubtotal() {
            const rows = document.querySelectorAll('.charge-row');
            let total = 0;

            rows.forEach(row => {
                const kind = row.querySelector('.folio-charge-kind')?.value || '';
                if (!kind) {
                    return;
                }

                const quantity = parseFloat(row.querySelector('.folio-charge-quantity')?.value || '0') || 0;
                const unitPrice = parseFloat(row.querySelector('.folio-charge-unit-price')?.value || '0') || 0;
                total += quantity * unitPrice;
            });

            return total;
        }

        function updateCalculation() {
            const amount = parseFloat(paymentAmountInput?.value || '0') || 0;
            const vatAmount = vatEnabled ? amount * (vatRate / 100) : 0;
            const total = amount + vatAmount;

            if (selectedChargesDisplay) {
                selectedChargesDisplay.textContent = formatMoney(getSelectedChargeSubtotal());
            }
            if (subtotalDueDisplay) {
                subtotalDueDisplay.textContent = formatMoney(currentOutstandingSubtotal);
            }

            subtotalDisplay.textContent = formatMoney(amount);
            if (vatEnabled && vatDisplay) {
                vatDisplay.textContent = formatMoney(vatAmount);
            }
            totalDisplay.textContent = formatMoney(total);
        }

        function applySuggestedPaymentAmount() {
            if (!autoFillCheckbox || !autoFillCheckbox.checked || !paymentAmountInput || isEditMode) {
                updateCalculation();
                return;
            }

            const suggested = Math.max(0, currentOutstandingSubtotal) + getSelectedChargeSubtotal();
            paymentAmountInput.value = suggested > 0 ? suggested.toFixed(2) : '';
            updateCalculation();
        }

        function updateChargeRow(row) {
            const kindSelect = row.querySelector('.folio-charge-kind');
            const itemSelect = row.querySelector('.folio-charge-item');
            const descriptionInput = row.querySelector('.folio-charge-description');
            const quantityInput = row.querySelector('.folio-charge-quantity');
            const unitPriceInput = row.querySelector('.folio-charge-unit-price');
            const lineTotalInput = row.querySelector('.folio-charge-line-total');
            const selectedValue = itemSelect.dataset.selected || itemSelect.value;
            const kind = kindSelect.value;

            if (kind === 'food' || kind === 'drink') {
                const options = menuCatalog[kind] || [];
                itemSelect.innerHTML = '<option value="">Select item...</option>' + options.map(item => (
                    `<option value="${item.id}">${item.item_name} (${item.category}) - ${formatMoney(item.price)}</option>`
                )).join('');
                itemSelect.disabled = false;
                itemSelect.value = selectedValue;

                const selectedItem = options.find(item => String(item.id) === String(itemSelect.value));
                descriptionInput.readOnly = true;
                unitPriceInput.readOnly = true;
                if (selectedItem) {
                    descriptionInput.value = `${selectedItem.item_name} (${selectedItem.category})`;
                    unitPriceInput.value = Number(selectedItem.price).toFixed(2);
                } else {
                    descriptionInput.value = '';
                    unitPriceInput.value = '';
                }
            } else if (kind === 'custom') {
                itemSelect.innerHTML = '<option value="">Custom charge</option>';
                itemSelect.disabled = true;
                itemSelect.value = '';
                itemSelect.dataset.selected = '';
                descriptionInput.readOnly = false;
                unitPriceInput.readOnly = false;
            } else {
                itemSelect.innerHTML = '<option value="">Select item...</option>';
                itemSelect.disabled = true;
                itemSelect.value = '';
                itemSelect.dataset.selected = '';
                descriptionInput.readOnly = false;
                unitPriceInput.readOnly = false;
                if (!descriptionInput.matches(':focus')) {
                    descriptionInput.value = '';
                }
                if (!unitPriceInput.matches(':focus')) {
                    unitPriceInput.value = '';
                }
            }

            const quantity = parseFloat(quantityInput.value || '0') || 0;
            const unitPrice = parseFloat(unitPriceInput.value || '0') || 0;
            lineTotalInput.value = formatMoney(quantity * unitPrice);
        }

        function refreshChargeRows() {
            const rows = document.querySelectorAll('.charge-row');
            rows.forEach(row => {
                updateChargeRow(row);
                const removeButton = row.querySelector('.remove-charge-row');
                if (removeButton) {
                    removeButton.style.visibility = rows.length > 1 ? 'visible' : 'hidden';
                }
            });
            applySuggestedPaymentAmount();
        }

        function bindChargeRow(row) {
            row.querySelector('.folio-charge-kind')?.addEventListener('change', () => {
                row.querySelector('.folio-charge-item').dataset.selected = '';
                updateChargeRow(row);
                applySuggestedPaymentAmount();
            });

            row.querySelector('.folio-charge-item')?.addEventListener('change', event => {
                event.currentTarget.dataset.selected = event.currentTarget.value;
                updateChargeRow(row);
                applySuggestedPaymentAmount();
            });

            row.querySelector('.folio-charge-quantity')?.addEventListener('input', () => {
                updateChargeRow(row);
                applySuggestedPaymentAmount();
            });

            row.querySelector('.folio-charge-unit-price')?.addEventListener('input', () => {
                updateChargeRow(row);
                applySuggestedPaymentAmount();
            });

            row.querySelector('.remove-charge-row')?.addEventListener('click', () => {
                const rows = document.querySelectorAll('.charge-row');
                if (rows.length > 1) {
                    row.remove();
                    refreshChargeRows();
                }
            });
        }

        function createChargeRow() {
            const wrapper = document.createElement('div');
            wrapper.className = 'charge-row';
            wrapper.innerHTML = `
                <div class="form-row">
                    <div class="form-group">
                        <label>Charge Type</label>
                        <select name="folio_charge_kind[]" class="folio-charge-kind">
                            <option value="">No extra charge</option>
                            <option value="food">Food Menu Item</option>
                            <option value="drink">Drink Menu Item</option>
                            <option value="custom">Custom Charge</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Menu Item</label>
                        <select name="folio_charge_item_id[]" class="folio-charge-item" data-selected="" disabled>
                            <option value="">Select item...</option>
                        </select>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label>Description</label>
                        <input type="text" name="folio_charge_description[]" class="folio-charge-description" placeholder="Custom description if needed">
                    </div>
                    <div class="form-group">
                        <label>Quantity</label>
                        <input type="number" name="folio_charge_quantity[]" class="folio-charge-quantity" min="1" step="1" value="1">
                    </div>
                    <div class="form-group">
                        <label>Unit Price</label>
                        <input type="number" name="folio_charge_unit_price[]" class="folio-charge-unit-price" min="0" step="0.01" placeholder="0.00">
                    </div>
                    <div class="form-group">
                        <label>Line Total</label>
                        <input type="text" class="folio-charge-line-total" value="${formatMoney(0)}" readonly>
                    </div>
                </div>
                <div class="charge-actions">
                    <button type="button" class="btn btn-secondary remove-charge-row" style="padding: 8px 14px; font-size: 13px;">
                        <i class="fas fa-trash"></i> Remove Row
                    </button>
                </div>
            `;
            return wrapper;
        }

        function toggleRoomChargeSection() {
            if (!roomChargeSection) {
                return;
            }

            const bookingTypeSelect = document.getElementById('booking_type');
            const selectedType = bookingTypeSelect ? bookingTypeSelect.value : <?php echo json_encode($bookingType); ?>;
            roomChargeSection.style.display = selectedType === 'room' ? 'block' : 'none';
        }

        function renderBookingDetails(bookingType, booking) {
            const dynamicInfo = document.getElementById('dynamic_booking_info');
            const infoTitle = document.getElementById('booking_info_title');
            const infoContent = document.getElementById('booking_info_content');
            const bookingLookupInput = document.getElementById('booking_lookup');
            const bookingIdInput = document.getElementById('booking_id');

            if (!dynamicInfo || !infoTitle || !infoContent) {
                return;
            }

            currentOutstandingSubtotal = parseFloat(booking.subtotal_due || 0) || 0;
            const fullyPaid = currentOutstandingSubtotal <= 0;
            dynamicInfo.style.display = 'block';
            dynamicInfo.classList.toggle('fully-paid', fullyPaid);

            if (bookingIdInput) {
                bookingIdInput.value = booking.id;
            }

            if (bookingLookupInput) {
                bookingLookupInput.value = bookingType === 'room'
                    ? `${booking.booking_reference} - ${booking.guest_name}`
                    : `${booking.enquiry_reference} - ${booking.organization_name || booking.contact_name}`;
            }

            if (bookingType === 'room') {
                infoTitle.innerHTML = 'Room Booking Details' + (fullyPaid ? ' <span class="fully-paid-badge">FULLY PAID</span>' : '');
                infoContent.innerHTML = `
                    <p><strong>Reference:</strong> ${booking.booking_reference}</p>
                    <p><strong>Guest:</strong> ${booking.guest_name}</p>
                    <p><strong>Email:</strong> ${booking.guest_email || 'N/A'}</p>
                    <p><strong>Room:</strong> ${booking.room_name || 'N/A'}</p>
                    <p><strong>Dates:</strong> ${booking.check_in_date} - ${booking.check_out_date}</p>
                    <p><strong>Subtotal Due:</strong> ${formatMoney(booking.subtotal_due || 0)}</p>
                    <p><strong>Total Due:</strong> <span style="color: ${(booking.amount_due || 0) > 0 ? '#dc3545' : '#28a745'}; font-weight: 600;">${formatMoney(booking.amount_due || 0)}</span></p>
                    ${fullyPaid ? '<div class="warning-box" style="margin-top: 12px;"><h5><i class="fas fa-exclamation-triangle"></i> Fully Paid Booking</h5><p>This booking has no outstanding subtotal. Any new food, drink, or manual payment entry will create a fresh folio charge or a credit.</p></div>' : ''}
                `;
            } else {
                infoTitle.innerHTML = 'Conference Booking Details' + (fullyPaid ? ' <span class="fully-paid-badge">FULLY PAID</span>' : '');
                infoContent.innerHTML = `
                    <p><strong>Reference:</strong> ${booking.enquiry_reference}</p>
                    <p><strong>Organization:</strong> ${booking.organization_name || 'N/A'}</p>
                    <p><strong>Contact:</strong> ${booking.contact_name}</p>
                    <p><strong>Email:</strong> ${booking.contact_email || 'N/A'}</p>
                    <p><strong>Dates:</strong> ${booking.start_date} - ${booking.end_date}</p>
                    <p><strong>Subtotal Due:</strong> ${formatMoney(booking.subtotal_due || 0)}</p>
                    <p><strong>Total Due:</strong> <span style="color: ${(booking.amount_due || 0) > 0 ? '#dc3545' : '#28a745'}; font-weight: 600;">${formatMoney(booking.amount_due || 0)}</span></p>
                    ${fullyPaid ? '<div class="warning-box" style="margin-top: 12px;"><h5><i class="fas fa-exclamation-triangle"></i> Fully Paid Booking</h5><p>This booking has no outstanding subtotal. New payments here should normally only be used for approved credits or adjustments.</p></div>' : ''}
                `;
            }

            toggleRoomChargeSection();
            applySuggestedPaymentAmount();

            if (!paymentStatusSelect.value || paymentStatusSelect.value === 'pending') {
                paymentStatusSelect.value = 'completed';
            }

            if (paymentMethodSelect && paymentMethodSelect.value === '') {
                const lastPaymentMethod = localStorage.getItem('lastPaymentMethod');
                paymentMethodSelect.value = lastPaymentMethod || 'cash';
            }
        }

        function fetchBookingDetails(bookingType, bookingId) {
            const dynamicInfo = document.getElementById('dynamic_booking_info');
            const infoTitle = document.getElementById('booking_info_title');
            const infoContent = document.getElementById('booking_info_content');

            if (!bookingType || !bookingId || !dynamicInfo || !infoTitle || !infoContent) {
                return;
            }

            dynamicInfo.style.display = 'block';
            infoTitle.textContent = 'Loading...';
            infoContent.innerHTML = '<div style="text-align: center; padding: 20px;"><i class="fas fa-spinner fa-spin"></i> Loading booking details...</div>';

            fetch(`api/search-bookings.php?type=${bookingType}&q=${encodeURIComponent(bookingId)}`)
                .then(response => response.json())
                .then(data => {
                    if (!data.bookings || data.bookings.length === 0) {
                        infoTitle.textContent = 'Error';
                        infoContent.innerHTML = '<p style="color: #dc3545;">Booking not found. Please try another search.</p>';
                        return;
                    }

                    renderBookingDetails(bookingType, data.bookings[0]);
                })
                .catch(error => {
                    console.error('Error fetching booking details:', error);
                    infoTitle.textContent = 'Error';
                    infoContent.innerHTML = '<p style="color: #dc3545;">Failed to load booking details. Please try again.</p>';
                });
        }

        function displaySearchResults(data, isRecent = false) {
            const searchResults = document.getElementById('booking_search_results');
            const bookingTypeSelect = document.getElementById('booking_type');
            const bookingLookupInput = document.getElementById('booking_lookup');
            const bookingIdInput = document.getElementById('booking_id');

            if (!searchResults || !bookingTypeSelect) {
                return;
            }

            if (!data.bookings || data.bookings.length === 0) {
                searchResults.innerHTML = '<div class="booking-search-no-results">' + (isRecent ? 'No recent bookings found' : 'No bookings found matching your search') + '</div>';
                return;
            }

            let html = '';
            data.bookings.forEach(booking => {
                if (bookingTypeSelect.value === 'room') {
                    html += `
                        <div class="booking-search-item" data-id="${booking.id}">
                            <strong>${booking.booking_reference} - ${booking.guest_name}</strong>
                            <small>ID: ${booking.id} | Room: ${booking.room_name || 'N/A'} | ${booking.check_in_date} to ${booking.check_out_date}</small>
                            <small>Subtotal due: ${formatMoney(booking.subtotal_due || 0)} | Total due: ${formatMoney(booking.amount_due || 0)}</small>
                        </div>
                    `;
                } else {
                    html += `
                        <div class="booking-search-item" data-id="${booking.id}">
                            <strong>${booking.enquiry_reference} - ${booking.organization_name || booking.contact_name}</strong>
                            <small>ID: ${booking.id} | Event: ${booking.start_date} to ${booking.end_date}</small>
                            <small>Subtotal due: ${formatMoney(booking.subtotal_due || 0)} | Total due: ${formatMoney(booking.amount_due || 0)}</small>
                        </div>
                    `;
                }
            });

            searchResults.innerHTML = html;
            searchResults.querySelectorAll('.booking-search-item').forEach(item => {
                item.addEventListener('click', () => {
                    const bookingId = item.dataset.id;
                    if (bookingIdInput) {
                        bookingIdInput.value = bookingId;
                    }
                    if (bookingLookupInput) {
                        bookingLookupInput.dataset.selected = bookingId;
                    }
                    searchResults.style.display = 'none';
                    fetchBookingDetails(bookingTypeSelect.value, bookingId);
                });
            });
        }

        function loadRecentBookings(bookingType) {
            const searchResults = document.getElementById('booking_search_results');
            if (!searchResults) {
                return;
            }

            searchResults.innerHTML = '<div class="booking-search-loading"><i class="fas fa-spinner fa-spin"></i> Loading recent bookings...</div>';
            searchResults.style.display = 'block';

            fetch(`api/search-bookings.php?type=${bookingType}&recent=1`)
                .then(response => response.json())
                .then(data => displaySearchResults(data, true))
                .catch(error => {
                    console.error('Load recent error:', error);
                    searchResults.innerHTML = '<div class="booking-search-no-results">Error loading recent bookings</div>';
                });
        }

        function searchBookings() {
            const bookingTypeSelect = document.getElementById('booking_type');
            const bookingLookupInput = document.getElementById('booking_lookup');
            const searchResults = document.getElementById('booking_search_results');

            if (!bookingTypeSelect || !bookingLookupInput || !searchResults) {
                return;
            }

            const bookingType = bookingTypeSelect.value;
            const searchTerm = bookingLookupInput.value.trim();

            if (!bookingType) {
                searchResults.innerHTML = '<div class="booking-search-no-results">Please select a booking type first</div>';
                searchResults.style.display = 'block';
                return;
            }

            if (searchTerm.length < 1) {
                loadRecentBookings(bookingType);
                return;
            }

            searchResults.innerHTML = '<div class="booking-search-loading"><i class="fas fa-spinner fa-spin"></i> Searching...</div>';
            searchResults.style.display = 'block';

            if (searchTimeout) {
                clearTimeout(searchTimeout);
            }

            searchTimeout = setTimeout(() => {
                fetch(`api/search-bookings.php?type=${bookingType}&q=${encodeURIComponent(searchTerm)}`)
                    .then(response => response.json())
                    .then(data => displaySearchResults(data))
                    .catch(error => {
                        console.error('Search error:', error);
                        searchResults.innerHTML = '<div class="booking-search-no-results">Error searching bookings</div>';
                    });
            }, 300);
        }

        function clearSelectedBooking() {
            const bookingLookupInput = document.getElementById('booking_lookup');
            const bookingIdInput = document.getElementById('booking_id');
            const dynamicInfo = document.getElementById('dynamic_booking_info');
            const searchResults = document.getElementById('booking_search_results');

            currentOutstandingSubtotal = 0;
            if (bookingLookupInput) {
                bookingLookupInput.value = '';
                bookingLookupInput.dataset.selected = '';
            }
            if (bookingIdInput) {
                bookingIdInput.value = '';
            }
            if (dynamicInfo) {
                dynamicInfo.style.display = 'none';
                dynamicInfo.classList.remove('fully-paid');
            }
            if (searchResults) {
                searchResults.style.display = 'none';
            }

            applySuggestedPaymentAmount();
            updateCalculation();
        }

        paymentAmountInput?.addEventListener('input', updateCalculation);
        autoFillCheckbox?.addEventListener('change', applySuggestedPaymentAmount);
        paymentMethodSelect?.addEventListener('change', function () {
            if (this.value) {
                localStorage.setItem('lastPaymentMethod', this.value);
            }
        });

        document.querySelectorAll('.charge-row').forEach(row => bindChargeRow(row));
        refreshChargeRows();

        document.getElementById('add_charge_row_btn')?.addEventListener('click', () => {
            const rowsContainer = document.getElementById('folio_charge_rows');
            if (!rowsContainer) {
                return;
            }

            const row = createChargeRow();
            rowsContainer.appendChild(row);
            bindChargeRow(row);
            refreshChargeRows();
        });

        document.getElementById('search_booking_btn')?.addEventListener('click', searchBookings);
        document.getElementById('booking_lookup')?.addEventListener('focus', () => {
            const bookingTypeSelect = document.getElementById('booking_type');
            if (bookingTypeSelect?.value) {
                loadRecentBookings(bookingTypeSelect.value);
            }
        });
        document.getElementById('booking_lookup')?.addEventListener('input', function () {
            const bookingIdInput = document.getElementById('booking_id');
            const dynamicInfo = document.getElementById('dynamic_booking_info');

            if (bookingIdInput) {
                bookingIdInput.value = '';
            }
            if (dynamicInfo) {
                dynamicInfo.style.display = 'none';
                dynamicInfo.classList.remove('fully-paid');
            }

            currentOutstandingSubtotal = 0;
            toggleRoomChargeSection();

            if (this.value.trim().length > 0) {
                searchBookings();
            } else {
                applySuggestedPaymentAmount();
                updateCalculation();
            }
        });

        document.getElementById('booking_type')?.addEventListener('change', function () {
            clearSelectedBooking();
            toggleRoomChargeSection();
        });

        document.getElementById('clear_booking_btn')?.addEventListener('click', clearSelectedBooking);

        document.addEventListener('click', event => {
            const searchResults = document.getElementById('booking_search_results');
            if (!searchResults || event.target.closest('#booking_search_results') || event.target.closest('#booking_lookup') || event.target.closest('#search_booking_btn')) {
                return;
            }
            searchResults.style.display = 'none';
        });

        toggleRoomChargeSection();
        updateCalculation();
        <?php if (!$editId && $bookingDetails): ?>
        renderBookingDetails(<?php echo json_encode($bookingType); ?>, <?php echo json_encode($bookingDetails); ?>);
        <?php endif; ?>
    </script>
    <script src="js/admin-mobile.js"></script>

</body>