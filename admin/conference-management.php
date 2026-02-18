<?php
// Include admin initialization (PHP-only, no HTML output)
require_once 'admin-init.php';

require_once '../config/email.php';
require_once '../config/invoice.php';
require_once '../includes/alert.php';

// Note: $user and $current_page are already set in admin-init.php
$message = '';
$error = '';

function uploadConferenceImage(array $fileInput): ?string
{
    if (empty($fileInput) || ($fileInput['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return null;
    }

    $uploadDir = __DIR__ . '/../images/conference/';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }

    $extension = strtolower(pathinfo($fileInput['name'], PATHINFO_EXTENSION)) ?: 'jpg';
    $allowed = ['jpg', 'jpeg', 'png', 'webp'];
    if (!in_array($extension, $allowed, true)) {
        return null;
    }

    $filename = 'conference_' . time() . '_' . random_int(1000, 9999) . '.' . $extension;
    $destination = $uploadDir . $filename;

    if (move_uploaded_file($fileInput['tmp_name'], $destination)) {
        return 'images/conference/' . $filename;
    }

    return null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $action = $_POST['action'] ?? '';
        $imagePath = uploadConferenceImage($_FILES['image'] ?? []);

        if ($action === 'add') {
            $stmt = $pdo->prepare("
                INSERT INTO conference_rooms (
                    name, description, capacity, size_sqm, daily_rate,
                    amenities, image_path, is_active, display_order
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $_POST['name'],
                $_POST['description'],
                $_POST['capacity'],
                $_POST['size_sqm'] ?: null,
                $_POST['daily_rate'],
                $_POST['amenities'] ?? '',
                $imagePath,
                isset($_POST['is_active']) ? 1 : 0,
                $_POST['display_order'] ?? 0
            ]);
            $message = 'Conference room added successfully!';
        }

        if ($action === 'update') {
            if ($imagePath) {
                $stmt = $pdo->prepare("
                    UPDATE conference_rooms
                    SET name = ?, description = ?, capacity = ?, size_sqm = ?, daily_rate = ?,
                        amenities = ?, image_path = ?, is_active = ?, display_order = ?
                    WHERE id = ?
                ");
                $stmt->execute([
                    $_POST['name'],
                    $_POST['description'],
                    $_POST['capacity'],
                    $_POST['size_sqm'] ?: null,
                    $_POST['daily_rate'],
                    $_POST['amenities'] ?? '',
                    $imagePath,
                    isset($_POST['is_active']) ? 1 : 0,
                    $_POST['display_order'] ?? 0,
                    $_POST['id']
                ]);
            } else {
                $stmt = $pdo->prepare("
                    UPDATE conference_rooms
                    SET name = ?, description = ?, capacity = ?, size_sqm = ?, daily_rate = ?,
                        amenities = ?, is_active = ?, display_order = ?
                    WHERE id = ?
                ");
                $stmt->execute([
                    $_POST['name'],
                    $_POST['description'],
                    $_POST['capacity'],
                    $_POST['size_sqm'] ?: null,
                    $_POST['daily_rate'],
                    $_POST['amenities'] ?? '',
                    isset($_POST['is_active']) ? 1 : 0,
                    $_POST['display_order'] ?? 0,
                    $_POST['id']
                ]);
            }
            $message = 'Conference room updated successfully!';
        }

        if ($action === 'delete') {
            $stmt = $pdo->prepare("DELETE FROM conference_rooms WHERE id = ?");
            $stmt->execute([$_POST['id']]);
            $message = 'Conference room deleted successfully!';
        }
    } catch (PDOException $e) {
        $error = 'Error: ' . $e->getMessage();
    }
}

try {
    $stmt = $pdo->query("SELECT * FROM conference_rooms ORDER BY display_order ASC, name ASC");
    $conference_rooms = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $conference_rooms = [];
    $error = 'Error fetching conference rooms: ' . $e->getMessage();
}

// Handle enquiry status updates
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['enquiry_action'])) {
    try {
        $enquiry_id = $_POST['enquiry_id'] ?? 0;
        $action = $_POST['enquiry_action'];
        
        // Fetch enquiry data for email functions
        $stmt = $pdo->prepare("SELECT * FROM conference_inquiries WHERE id = ?");
        $stmt->execute([$enquiry_id]);
        $enquiry = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($action === 'confirm') {
            $stmt = $pdo->prepare("UPDATE conference_inquiries SET status = 'confirmed' WHERE id = ?");
            $stmt->execute([$enquiry_id]);
            
            // Send confirmation email
            if ($enquiry) {
                $email_result = sendConferenceConfirmedEmail($enquiry);
                if ($email_result['success']) {
                    $message = 'Conference enquiry confirmed successfully! Confirmation email sent.';
                } else {
                    $message = 'Conference enquiry confirmed successfully! (Email not sent: ' . $email_result['message'] . ')';
                }
            } else {
                $message = 'Conference enquiry confirmed successfully!';
            }
        } elseif ($action === 'cancel') {
            $stmt = $pdo->prepare("UPDATE conference_inquiries SET status = 'cancelled' WHERE id = ?");
            $stmt->execute([$enquiry_id]);
            
            // Send cancellation email
            if ($enquiry) {
                $email_result = sendConferenceCancelledEmail($enquiry);
                
                // Log cancellation to database
                $email_sent = $email_result['success'];
                $email_status = $email_result['message'];
                logCancellationToDatabase(
                    $enquiry['id'],
                    $enquiry['inquiry_reference'],
                    'conference',
                    $enquiry['email'],
                    $user['id'],
                    'Cancelled by admin',
                    $email_sent,
                    $email_status
                );
                
                // Log cancellation to file
                logCancellationToFile(
                    $enquiry['inquiry_reference'],
                    'conference',
                    $enquiry['email'],
                    $user['full_name'] ?? $user['username'],
                    'Cancelled by admin',
                    $email_sent,
                    $email_status
                );
                
                if ($email_sent) {
                    $message = 'Conference enquiry cancelled successfully! Cancellation email sent.';
                } else {
                    $message = 'Conference enquiry cancelled successfully! (Email not sent: ' . $email_status . ')';
                }
            } else {
                $message = 'Conference enquiry cancelled successfully!';
            }
        } elseif ($action === 'complete') {
            $stmt = $pdo->prepare("UPDATE conference_inquiries SET status = 'completed' WHERE id = ?");
            $stmt->execute([$enquiry_id]);
            $message = 'Conference marked as completed!';
        } elseif ($action === 'send_invoice') {
            // Mark conference as paid and send invoice
            if ($enquiry) {
                try {
                    // Get VAT settings
                    $vatEnabled = getSetting('vat_enabled') === '1';
                    $vatRate = $vatEnabled ? (float)getSetting('vat_rate') : 0;
                    
                    // Calculate amounts
                    $totalAmount = (float)$enquiry['total_amount'];
                    $vatAmount = $vatEnabled ? ($totalAmount * ($vatRate / 100)) : 0;
                    $totalWithVat = $totalAmount + $vatAmount;
                    
                    // Generate payment reference
                    $payment_reference = 'PAY-' . date('Y') . '-' . str_pad($enquiry_id, 6, '0', STR_PAD_LEFT);
                    
                    // Insert into payments table
                    $insert_payment = $pdo->prepare("
                        INSERT INTO payments (
                            payment_reference, booking_type, booking_id, booking_reference,
                            payment_date, payment_amount, vat_rate, vat_amount, total_amount,
                            payment_method, payment_type, payment_status, invoice_generated,
                            status, recorded_by
                        ) VALUES (?, 'conference', ?, ?, CURDATE(), ?, ?, ?, ?, 'cash', 'full_payment', 'completed', 1, 'completed', ?)
                    ");
                    $insert_payment->execute([
                        $payment_reference,
                        $enquiry_id,
                        $enquiry['inquiry_reference'],
                        $totalAmount,
                        $vatRate,
                        $vatAmount,
                        $totalWithVat,
                        $user['id']
                    ]);
                    
                    // Update conference enquiry payment tracking columns
                    $update_amounts = $pdo->prepare("
                        UPDATE conference_inquiries
                        SET amount_paid = ?, amount_due = 0, vat_rate = ?, vat_amount = ?,
                            total_with_vat = ?, last_payment_date = CURDATE(), payment_status = 'full_paid'
                        WHERE id = ?
                    ");
                    $update_amounts->execute([$totalWithVat, $vatRate, $vatAmount, $totalWithVat, $enquiry_id]);
                    
                    // Send invoice email
                    $invoice_result = sendConferenceInvoiceEmail($enquiry_id);
                    if ($invoice_result['success']) {
                        $message = 'Payment recorded successfully! Invoice sent to ' . htmlspecialchars($enquiry['email']);
                    } else {
                        $message = 'Payment recorded successfully! (Invoice email failed: ' . $invoice_result['message'] . ')';
                    }
                } catch (PDOException $e) {
                    $error = 'Failed to record payment: ' . $e->getMessage();
                    error_log("Conference payment error: " . $e->getMessage());
                }
            } else {
                $error = 'Enquiry not found!';
            }
        } elseif ($action === 'update_amount') {
            $amount = $_POST['total_amount'] ?? 0;
            $stmt = $pdo->prepare("UPDATE conference_inquiries SET total_amount = ? WHERE id = ?");
            $stmt->execute([$amount, $enquiry_id]);
            $message = 'Total amount updated successfully!';
        } elseif ($action === 'update_notes') {
            $notes = $_POST['notes'] ?? '';
            $stmt = $pdo->prepare("UPDATE conference_inquiries SET notes = ? WHERE id = ?");
            $stmt->execute([$notes, $enquiry_id]);
            $message = 'Notes updated successfully!';
        }
    } catch (PDOException $e) {
        $error = 'Error updating enquiry: ' . $e->getMessage();
    } catch (Exception $e) {
        $error = 'Error: ' . $e->getMessage();
    }
}

// Fetch conference enquiries
try {
    $enquiries_stmt = $pdo->query("
        SELECT ci.*, cr.name as room_name
        FROM conference_inquiries ci
        LEFT JOIN conference_rooms cr ON ci.conference_room_id = cr.id
        ORDER BY ci.event_date DESC, ci.created_at DESC
    ");
    $conference_enquiries = $enquiries_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $conference_enquiries = [];
    $error = 'Error fetching conference enquiries: ' . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Conference Rooms - Admin Panel</title>
    
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;500;600;700&family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../css/style.css">
    <link rel="stylesheet" href="../css/theme-dynamic.php">
    <link rel="stylesheet" href="css/admin-styles.css">
    <link rel="stylesheet" href="css/admin-components.css">
    
    <style>
        /* Conference management specific styles */
        .card h2 {
            margin: 0 0 16px;
            color: var(--navy);
        }
        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 16px;
        }
        .checkbox-row {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-top: 12px;
        }
        .btn-info {
            background: #17a2b8;
            color: white;
        }
        .btn-info:hover {
            background: #138496;
        }
        .room-list {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(400px, 1fr));
            gap: 24px;
        }
        .room-item {
            background: white;
            border: 1px solid #e8e8e8;
            border-radius: 16px;
            padding: 0;
            display: flex;
            flex-direction: column;
            box-shadow: 0 2px 12px rgba(0, 0, 0, 0.06);
            transition: all 0.3s ease;
            overflow: hidden;
        }
        .room-item:hover {
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.12);
            transform: translateY(-2px);
            border-color: var(--gold);
        }
        .room-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 16px 20px;
            background: linear-gradient(135deg, #f8f9fa 0%, #ffffff 100%);
            border-bottom: 1px solid #e8e8e8;
        }
        .room-header h3 {
            margin: 0;
            color: var(--navy);
            font-size: 18px;
            font-weight: 700;
        }
        .badge-active {
            background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
            color: white;
            box-shadow: 0 2px 8px rgba(40, 167, 69, 0.3);
        }
        .badge-inactive {
            background: linear-gradient(135deg, #dc3545 0%, #c82333 100%);
            color: white;
            box-shadow: 0 2px 8px rgba(220, 53, 69, 0.3);
        }
        .room-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 16px;
            font-size: 13px;
            color: #666;
            padding: 12px 20px;
            background: #fafbfc;
            border-bottom: 1px solid #e8e8e8;
        }
        .room-meta span {
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .room-meta i {
            color: var(--gold);
            font-size: 14px;
        }
        .room-image-container {
            width: 100%;
            height: 180px;
            overflow: hidden;
            background: #f8f9fa;
            border-bottom: 1px solid #e8e8e8;
            position: relative;
        }

        .room-image-preview {
            width: 100%;
            height: 100%;
            object-fit: cover;
            transition: transform 0.3s ease;
        }

        .room-item:hover .room-image-preview {
            transform: scale(1.05);
        }

        .room-image-placeholder {
            width: 100%;
            height: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #bbb;
            font-size: 48px;
            background: linear-gradient(135deg, #f0f2f5 0%, #e4e6e9 100%);
        }

        .room-form-section {
            padding: 20px;
            flex: 1;
            display: flex;
            flex-direction: column;
            gap: 16px;
        }
        .room-form-section .form-grid {
            grid-template-columns: repeat(2, 1fr);
            gap: 12px;
        }
        .room-form-section label {
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #888;
        }
        .room-form-section input,
        .room-form-section textarea {
            padding: 8px 12px;
            font-size: 13px;
            border: 1px solid #e0e0e0;
            background: #fafbfc;
            transition: all 0.2s ease;
        }
        .room-form-section input:focus,
        .room-form-section textarea:focus {
            border-color: var(--gold);
            background: white;
            box-shadow: 0 0 0 3px rgba(212, 175, 55, 0.1);
        }
        .room-form-section textarea {
            min-height: 80px;
        }
        .room-actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            padding: 16px 20px;
            background: #fafbfc;
            border-top: 1px solid #e8e8e8;
        }
        .room-actions .btn {
            flex: 1;
            min-width: 120px;
            padding: 10px 16px;
            font-size: 13px;
            transition: all 0.2s ease;
        }
        .room-actions .btn:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(212, 175, 55, 0.3);
        }
        .room-actions .btn-secondary:hover {
            box-shadow: 0 4px 12px rgba(108, 117, 125, 0.3);
        }
        .room-form-section .checkbox-row {
            margin-top: 0;
            padding: 8px 0;
        }
        .room-form-section .checkbox-row input[type="checkbox"] {
            width: 18px;
            height: 18px;
            accent-color: var(--gold);
            cursor: pointer;
        }
        .room-form-section .checkbox-row label {
            font-size: 13px;
            text-transform: none;
            letter-spacing: normal;
            color: var(--navy);
            cursor: pointer;
        }
        @media (max-width: 768px) {
            .room-list {
                grid-template-columns: 1fr;
            }
            .room-form-section .form-grid {
                grid-template-columns: 1fr;
            }
            .room-actions {
                flex-direction: column;
            }
            .room-actions .btn {
                width: 100%;
            }
        }
    </style>
</head>
<body>

    <?php require_once 'includes/admin-header.php'; ?>
    
    <div class="content">
        <?php if ($message): ?>
            <?php showAlert($message, 'success'); ?>
        <?php endif; ?>
        <?php if ($error): ?>
            <?php showAlert($error, 'error'); ?>
        <?php endif; ?>

        <div class="card">
            <h2>Add New Conference Room</h2>
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="action" value="add">
                <div class="form-grid">
                    <div class="form-group">
                        <label>Name *</label>
                        <input type="text" name="name" required>
                    </div>
                    <div class="form-group">
                        <label>Capacity *</label>
                        <input type="number" name="capacity" min="1" required>
                    </div>
                    <div class="form-group">
                        <label>Size (sqm)</label>
                        <input type="number" step="0.01" name="size_sqm">
                    </div>
                    <div class="form-group">
                        <label>Full Day Rate *</label>
                        <input type="number" step="0.01" name="daily_rate" required>
                    </div>
                    <div class="form-group">
                        <label>Display Order</label>
                        <input type="number" name="display_order" value="0">
                    </div>
                </div>
                <div class="form-group">
                    <label>Description *</label>
                    <textarea name="description" required></textarea>
                </div>
                <div class="form-group">
                    <label>Amenities (comma separated)</label>
                    <textarea name="amenities"></textarea>
                </div>
                <div class="form-group">
                    <label>Featured Image</label>
                    <input type="file" name="image" accept="image/*">
                </div>
                <div class="checkbox-row">
                    <input type="checkbox" name="is_active" id="is_active_add" checked>
                    <label for="is_active_add">Active</label>
                </div>
                <button type="submit" class="btn">Add Conference Room</button>
            </form>
        </div>

        <div class="card">
            <h2>Conference Enquiries Management</h2>
            <div class="table-container">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Reference</th>
                            <th>Company</th>
                            <th>Contact</th>
                            <th>Event Date</th>
                            <th>Time</th>
                            <th>Room</th>
                            <th>Attendees</th>
                            <th>Status</th>
                            <th>Amount</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($conference_enquiries)): ?>
                        <tr>
                            <td colspan="10" class="empty-state">
                                <i class="fas fa-inbox"></i>
                                <p>No conference enquiries found</p>
                            </td>
                        </tr>
                        <?php else: ?>
                        <?php foreach ($conference_enquiries as $enquiry): ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($enquiry['inquiry_reference']); ?></strong></td>
                            <td><?php echo htmlspecialchars($enquiry['company_name']); ?></td>
                            <td>
                                <?php echo htmlspecialchars($enquiry['contact_person']); ?><br>
                                <small><?php echo htmlspecialchars($enquiry['email']); ?></small><br>
                                <small><?php echo htmlspecialchars($enquiry['phone']); ?></small>
                            </td>
                            <td><?php echo date('M j, Y', strtotime($enquiry['event_date'])); ?></td>
                            <td>
                                <?php echo date('H:i', strtotime($enquiry['start_time'])); ?> -
                                <?php echo date('H:i', strtotime($enquiry['end_time'])); ?>
                            </td>
                            <td><?php echo htmlspecialchars($enquiry['room_name'] ?? 'N/A'); ?></td>
                            <td><?php echo (int) $enquiry['number_of_attendees']; ?></td>
                            <td>
                                <span class="badge badge-<?php echo $enquiry['status']; ?>">
                                    <?php echo ucfirst($enquiry['status']); ?>
                                </span>
                            </td>
                            <td>
                                <?php if ($enquiry['total_amount']): ?>
                                    K <?php echo number_format($enquiry['total_amount'], 0); ?>
                                <?php else: ?>
                                    <em>Pending</em>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="quick-actions">
                                    <?php if ($enquiry['status'] === 'pending'): ?>
                                        <form method="POST" style="display:inline;">
                                            <input type="hidden" name="enquiry_action" value="confirm">
                                            <input type="hidden" name="enquiry_id" value="<?php echo $enquiry['id']; ?>">
                                            <button type="submit" class="btn btn-success btn-sm" onclick="return confirm('Confirm this conference enquiry?');">
                                                <i class="fas fa-check"></i> Confirm
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                    <?php if ($enquiry['status'] === 'confirmed'): ?>
                                        <form method="POST" style="display:inline;">
                                            <input type="hidden" name="enquiry_action" value="complete">
                                            <input type="hidden" name="enquiry_id" value="<?php echo $enquiry['id']; ?>">
                                            <button type="submit" class="btn btn-primary btn-sm" onclick="return confirm('Mark this conference as completed?');">
                                                <i class="fas fa-check-circle"></i> Complete
                                            </button>
                                        </form>
                                        <form method="POST" style="display:inline;">
                                            <input type="hidden" name="enquiry_action" value="send_invoice">
                                            <input type="hidden" name="enquiry_id" value="<?php echo $enquiry['id']; ?>">
                                            <button type="submit" class="btn btn-info btn-sm" onclick="return confirm('Generate and send invoice for this conference?');">
                                                <i class="fas fa-file-invoice-dollar"></i> Paid
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                    <?php if (in_array($enquiry['status'], ['pending', 'confirmed'])): ?>
                                        <form method="POST" style="display:inline;">
                                            <input type="hidden" name="enquiry_action" value="cancel">
                                            <input type="hidden" name="enquiry_id" value="<?php echo $enquiry['id']; ?>">
                                            <button type="submit" class="btn btn-secondary btn-sm" onclick="return confirm('Cancel this conference enquiry?');">
                                                <i class="fas fa-times"></i> Cancel
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                    <button type="button" class="btn btn-primary btn-sm" onclick="showEnquiryDetails(<?php echo htmlspecialchars(json_encode($enquiry)); ?>)">
                                        <i class="fas fa-eye"></i> Details
                                    </button>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="card">
            <h2>Manage Conference Rooms</h2>
            <div class="room-list">
                <?php foreach ($conference_rooms as $room): ?>
                    <div class="room-item">
                        <div class="room-header">
                            <h3><?php echo htmlspecialchars($room['name']); ?></h3>
                            <span class="badge <?php echo $room['is_active'] ? 'badge-active' : 'badge-inactive'; ?>">
                                <?php echo $room['is_active'] ? '<i class="fas fa-check-circle"></i> Active' : '<i class="fas fa-times-circle"></i> Inactive'; ?>
                            </span>
                        </div>
                        
                        <!-- Image Display -->
                        <div class="room-image-container">
                            <?php if (!empty($room['image_path'])): ?>
                                <img src="../<?php echo htmlspecialchars($room['image_path']); ?>"
                                     alt="<?php echo htmlspecialchars($room['name']); ?>"
                                     class="room-image-preview">
                            <?php else: ?>
                                <div class="room-image-placeholder">
                                    <i class="fas fa-image"></i>
                                </div>
                            <?php endif; ?>
                        </div>

                        <div class="room-meta">
                            <span><i class="fas fa-users"></i> <?php echo (int) $room['capacity']; ?> Guests</span>
                            <span><i class="fas fa-expand-arrows-alt"></i> <?php echo number_format($room['size_sqm'] ?? 0, 0); ?> sqm</span>
                            <span><i class="fas fa-calendar-day"></i> K <?php echo number_format($room['daily_rate'], 0); ?>/day</span>
                        </div>

                        <form method="POST" enctype="multipart/form-data">
                            <input type="hidden" name="action" value="update">
                            <input type="hidden" name="id" value="<?php echo (int) $room['id']; ?>">
                            <div class="room-form-section">
                                <div class="form-grid">
                                    <div class="form-group">
                                        <label>Name *</label>
                                        <input type="text" name="name" value="<?php echo htmlspecialchars($room['name']); ?>" required>
                                    </div>
                                    <div class="form-group">
                                        <label>Capacity *</label>
                                        <input type="number" name="capacity" min="1" value="<?php echo htmlspecialchars($room['capacity']); ?>" required>
                                    </div>
                                    <div class="form-group">
                                        <label>Size (sqm)</label>
                                        <input type="number" step="0.01" name="size_sqm" value="<?php echo htmlspecialchars($room['size_sqm']); ?>">
                                    </div>
                                    <div class="form-group">
                                        <label>Full Day Rate *</label>
                                        <input type="number" step="0.01" name="daily_rate" value="<?php echo htmlspecialchars($room['daily_rate']); ?>" required>
                                    </div>
                                    <div class="form-group">
                                        <label>Display Order</label>
                                        <input type="number" name="display_order" value="<?php echo htmlspecialchars($room['display_order']); ?>">
                                    </div>
                                </div>
                                <div class="form-group">
                                    <label>Description *</label>
                                    <textarea name="description" required><?php echo htmlspecialchars($room['description']); ?></textarea>
                                </div>
                                <div class="form-group">
                                    <label>Amenities (comma separated)</label>
                                    <textarea name="amenities"><?php echo htmlspecialchars($room['amenities']); ?></textarea>
                                </div>
                                <div class="form-group">
                                    <label>Replace Image</label>
                                    <input type="file" name="image" accept="image/*">
                                </div>
                                <div class="checkbox-row">
                                    <input type="checkbox" name="is_active" id="is_active_<?php echo (int) $room['id']; ?>" <?php echo $room['is_active'] ? 'checked' : ''; ?>>
                                    <label for="is_active_<?php echo (int) $room['id']; ?>">Active</label>
                                </div>
                            </div>
                            <div class="room-actions">
                                <button type="submit" class="btn"><i class="fas fa-save"></i> Save Changes</button>
                                <button type="submit" name="action" value="delete" class="btn btn-secondary" onclick="return confirm('Delete this conference room?');">
                                    <i class="fas fa-trash"></i> Delete
                                </button>
                            </div>
                        </form>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <!-- Enquiry Details Modal -->
    <div id="enquiryModal" class="modal" style="display:none;">
        <div class="modal-content" style="max-width: 700px;">
            <div class="modal-header">
                <h3>Conference Enquiry Details</h3>
                <span class="close" onclick="closeEnquiryModal()">&times;</span>
            </div>
            <div class="modal-body" id="enquiryModalBody">
                <!-- Content will be loaded dynamically -->
            </div>
        </div>
    </div>

    <script>
        function showEnquiryDetails(enquiry) {
            const modal = document.getElementById('enquiryModal');
            const body = document.getElementById('enquiryModalBody');
            
            body.innerHTML = `
                <div class="enquiry-details">
                    <div class="detail-row">
                        <strong>Reference:</strong>
                        <span>${enquiry.inquiry_reference}</span>
                    </div>
                    <div class="detail-row">
                        <strong>Company:</strong>
                        <span>${enquiry.company_name}</span>
                    </div>
                    <div class="detail-row">
                        <strong>Contact Person:</strong>
                        <span>${enquiry.contact_person}</span>
                    </div>
                    <div class="detail-row">
                        <strong>Email:</strong>
                        <span>${enquiry.email}</span>
                    </div>
                    <div class="detail-row">
                        <strong>Phone:</strong>
                        <span>${enquiry.phone}</span>
                    </div>
                    <div class="detail-row">
                        <strong>Event Date:</strong>
                        <span>${new Date(enquiry.event_date).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' })}</span>
                    </div>
                    <div class="detail-row">
                        <strong>Time:</strong>
                        <span>${new Date('1970-01-01T' + enquiry.start_time).toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit' })} -
                              ${new Date('1970-01-01T' + enquiry.end_time).toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit' })}</span>
                    </div>
                    <div class="detail-row">
                        <strong>Conference Room:</strong>
                        <span>${enquiry.room_name || 'N/A'}</span>
                    </div>
                    <div class="detail-row">
                        <strong>Number of Attendees:</strong>
                        <span>${enquiry.number_of_attendees}</span>
                    </div>
                    <div class="detail-row">
                        <strong>Event Type:</strong>
                        <span>${enquiry.event_type || 'N/A'}</span>
                    </div>
                    <div class="detail-row">
                        <strong>Status:</strong>
                        <span class="badge badge-${enquiry.status}">${enquiry.status.charAt(0).toUpperCase() + enquiry.status.slice(1)}</span>
                    </div>
                    <div class="detail-row">
                        <strong>Catering Required:</strong>
                        <span>${enquiry.catering_required ? 'Yes' : 'No'}</span>
                    </div>
                    <div class="detail-row">
                        <strong>AV Equipment:</strong>
                        <span>${enquiry.av_equipment || 'None'}</span>
                    </div>
                    <div class="detail-row">
                        <strong>Special Requirements:</strong>
                        <span>${enquiry.special_requirements || 'None'}</span>
                    </div>
                    <div class="detail-row">
                        <strong>Total Amount:</strong>
                        <span>${enquiry.total_amount ? 'K ' + Number(enquiry.total_amount).toLocaleString() : 'Pending'}</span>
                    </div>
                    <div class="detail-row">
                        <strong>Notes:</strong>
                        <span>${enquiry.notes || 'None'}</span>
                    </div>
                    
                    <div class="modal-actions">
                        <form method="POST" style="display:inline;">
                            <input type="hidden" name="enquiry_action" value="update_amount">
                            <input type="hidden" name="enquiry_id" value="${enquiry.id}">
                            <div class="form-group" style="margin-bottom: 10px;">
                                <label>Update Total Amount (<?php echo getSetting('currency_symbol', 'MWK'); ?>):</label>
                                <input type="number" name="total_amount" step="0.01" value="${enquiry.total_amount || ''}" style="width: 150px;">
                            </div>
                            <button type="submit" class="btn">Update Amount</button>
                        </form>
                        <form method="POST" style="display:inline;">
                            <input type="hidden" name="enquiry_action" value="update_notes">
                            <input type="hidden" name="enquiry_id" value="${enquiry.id}">
                            <div class="form-group" style="margin-bottom: 10px;">
                                <label>Update Notes:</label>
                                <textarea name="notes" rows="3" style="width: 100%; max-width: 400px;">${enquiry.notes || ''}</textarea>
                            </div>
                            <button type="submit" class="btn">Update Notes</button>
                        </form>
                    </div>
                </div>
            `;
            
            modal.style.display = 'block';
        }

        function closeEnquiryModal() {
            document.getElementById('enquiryModal').style.display = 'none';
        }

        // Close modal when clicking outside
        window.onclick = function(event) {
            const modal = document.getElementById('enquiryModal');
            if (event.target == modal) {
                closeEnquiryModal();
            }
        }
    </script>

    <style>
        .enquiry-details {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }
        
        .detail-row {
            display: flex;
            justify-content: space-between;
            padding: 8px 0;
            border-bottom: 1px solid #eee;
        }
        
        .detail-row strong {
            min-width: 180px;
            color: var(--navy);
        }
        
        .detail-row span {
            flex: 1;
            text-align: right;
        }
        
        .modal-actions {
            margin-top: 20px;
            padding-top: 20px;
            border-top: 2px solid #eee;
            display: flex;
            flex-direction: column;
            gap: 15px;
        }
        
        .modal-actions .form-group {
            display: flex;
            flex-direction: column;
            gap: 5px;
        }
        
        .modal-actions label {
            font-weight: 600;
            color: var(--navy);
        }
        
        .badge-completed {
            background: #cce5ff;
            color: #004085;
        }
    </style>
    <script src="js/admin-components.js"></script>

    <?php require_once 'includes/admin-footer.php'; ?>
