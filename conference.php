<?php
/**
 * Conference Booking Page with Enhanced Security
 * Features:
 * - CSRF protection
 * - Secure session management
 * - Input validation
 */

// Start session BEFORE loading security configuration (required for CSRF)
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Load security configuration first
require_once 'config/security.php';

require_once 'config/database.php';
require_once 'includes/page-guard.php';
require_once 'config/email.php';
require_once 'includes/modal.php';
require_once 'includes/validation.php';
require_once 'includes/section-headers.php';

// Send security headers
sendSecurityHeaders();


// Fetch policies for footer modals
$policies = [];
try {
    $policyStmt = $pdo->query("SELECT slug, title, summary, content FROM policies WHERE is_active = 1 ORDER BY display_order ASC, id ASC");
    $policies = $policyStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $policies = [];
}

// Fetch active conference rooms
try {
    $stmt = $pdo->prepare("SELECT * FROM conference_rooms WHERE is_active = 1 ORDER BY display_order ASC");
    $stmt->execute();
    $conference_rooms = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $conference_rooms = [];
    error_log("Conference rooms fetch error: " . $e->getMessage());
}

// Handle inquiry submission
$inquiry_success = false;
$inquiry_error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validate CSRF token
    requireCsrfValidation();
    
    try {
        // Initialize validation errors array
        $validation_errors = [];
        $sanitized_data = [];
        
        // Validate conference_room_id
        $room_validation = validateConferenceRoomId($_POST['conference_room_id'] ?? '');
        if (!$room_validation['valid']) {
            $validation_errors['conference_room_id'] = $room_validation['error'];
        } else {
            $sanitized_data['conference_room_id'] = $room_validation['room']['id'];
        }
        
        // Validate company_name
        $company_validation = validateText($_POST['company_name'] ?? '', 2, 200, true);
        if (!$company_validation['valid']) {
            $validation_errors['company_name'] = $company_validation['error'];
        } else {
            $sanitized_data['company_name'] = sanitizeString($company_validation['value'], 200);
        }
        
        // Validate contact_person
        $contact_validation = validateName($_POST['contact_person'] ?? '', 2, true);
        if (!$contact_validation['valid']) {
            $validation_errors['contact_person'] = $contact_validation['error'];
        } else {
            $sanitized_data['contact_person'] = sanitizeString($contact_validation['value'], 100);
        }
        
        // Validate email
        $email_validation = validateEmail($_POST['email'] ?? '');
        if (!$email_validation['valid']) {
            $validation_errors['email'] = $email_validation['error'];
        } else {
            // Use validated email directly - no need to sanitize as validation already ensures it's safe
            $sanitized_data['email'] = $_POST['email'];
        }
        
        // Validate phone
        $phone_validation = validatePhone($_POST['phone'] ?? '');
        if (!$phone_validation['valid']) {
            $validation_errors['phone'] = $phone_validation['error'];
        } else {
            $sanitized_data['phone'] = $phone_validation['sanitized'];
        }
        
        // Get booking time buffer from settings (default to 60 minutes if not set)
        $booking_buffer = (int)getSetting('booking_time_buffer_minutes', 60);
        
        // Validate event_date and start_time together
        $datetime_validation = validateDateTime(
            $_POST['event_date'] ?? '',
            $_POST['start_time'] ?? '',
            false,  // Don't allow past dates
            $booking_buffer  // Use configurable buffer
        );
        
        if (!$datetime_validation['valid']) {
            $validation_errors['event_date'] = $datetime_validation['error'];
        } else {
            $sanitized_data['event_date'] = $datetime_validation['datetime']->format('Y-m-d');
            $sanitized_data['start_time'] = $datetime_validation['datetime']->format('H:i');
        }
        
        // Validate end_time separately
        $end_time_validation = validateTime($_POST['end_time'] ?? '');
        if (!$end_time_validation['valid']) {
            $validation_errors['end_time'] = $end_time_validation['error'];
        } else {
            $sanitized_data['end_time'] = $end_time_validation['time'];
        }
        
        // Validate time range
        if (empty($validation_errors['start_time']) && empty($validation_errors['end_time'])) {
            $time_range_validation = validateTimeRange($sanitized_data['start_time'], $sanitized_data['end_time']);
            if (!$time_range_validation['valid']) {
                $validation_errors['time_range'] = $time_range_validation['error'];
            }
        }
        
        // Validate number_of_attendees
        $attendees_validation = validateNumber($_POST['number_of_attendees'] ?? '', 1, 500, true);
        if (!$attendees_validation['valid']) {
            $validation_errors['number_of_attendees'] = $attendees_validation['error'];
        } else {
            $sanitized_data['number_of_attendees'] = $attendees_validation['value'];
        }
        
        // Validate event_type (optional)
        $allowed_event_types = ['', 'Meeting', 'Conference', 'Workshop', 'Seminar', 'Training', 'Other'];
        $type_validation = validateSelectOption($_POST['event_type'] ?? '', $allowed_event_types, false);
        if (!$type_validation['valid']) {
            $validation_errors['event_type'] = $type_validation['error'];
        } else {
            $sanitized_data['event_type'] = $type_validation['value'];
        }
        
        // Validate special_requirements (optional)
        $requirements_validation = validateText($_POST['special_requirements'] ?? '', 0, 1000, false);
        if (!$requirements_validation['valid']) {
            $validation_errors['special_requirements'] = $requirements_validation['error'];
        } else {
            $sanitized_data['special_requirements'] = sanitizeString($requirements_validation['value'], 1000);
        }
        
        // Validate av_equipment (optional)
        $av_validation = validateText($_POST['av_equipment'] ?? '', 0, 500, false);
        if (!$av_validation['valid']) {
            $validation_errors['av_equipment'] = $av_validation['error'];
        } else {
            $sanitized_data['av_equipment'] = sanitizeString($av_validation['value'], 500);
        }
        
        // Validate catering_required (optional)
        $sanitized_data['catering_required'] = isset($_POST['catering_required']) ? 1 : 0;
        
        // Check for validation errors
        if (!empty($validation_errors)) {
            $error_messages = [];
            foreach ($validation_errors as $field => $message) {
                $error_messages[] = ucfirst(str_replace('_', ' ', $field)) . ': ' . $message;
            }
            throw new Exception(implode('; ', $error_messages));
        }
        
        // Prepare booking data for email functions
        $booking_data = [
            'conference_room_id' => $sanitized_data['conference_room_id'],
            'company_name' => $sanitized_data['company_name'],
            'contact_person' => $sanitized_data['contact_person'],
            'email' => $sanitized_data['email'],
            'phone' => $sanitized_data['phone'],
            'event_date' => $sanitized_data['event_date'],
            'start_time' => $sanitized_data['start_time'],
            'end_time' => $sanitized_data['end_time'],
            'number_of_attendees' => $sanitized_data['number_of_attendees'],
            'event_type' => $sanitized_data['event_type'],
            'special_requirements' => $sanitized_data['special_requirements'],
            'catering_required' => $sanitized_data['catering_required'],
            'av_equipment' => $sanitized_data['av_equipment']
        ];
        
        // Log booking data for diagnostics
        error_log("Conference enquiry data prepared: " . print_r($booking_data, true));
        
        $room_id = $sanitized_data['conference_room_id'];
        $company_name = $sanitized_data['company_name'];
        $contact_person = $sanitized_data['contact_person'];
        $email = $sanitized_data['email'];
        $phone = $sanitized_data['phone'];
        $event_date = $sanitized_data['event_date'];
        $start_time = $sanitized_data['start_time'];
        $end_time = $sanitized_data['end_time'];
        $attendees = $sanitized_data['number_of_attendees'];
        $event_type = $sanitized_data['event_type'];
        $special_requirements = $sanitized_data['special_requirements'];
        $catering = $sanitized_data['catering_required'];
        $av_equipment = $sanitized_data['av_equipment'];

        // Get room details for pricing
        $room_stmt = $pdo->prepare("SELECT * FROM conference_rooms WHERE id = ? AND is_active = 1");
        $room_stmt->execute([$room_id]);
        $room = $room_stmt->fetch(PDO::FETCH_ASSOC);

        if (!$room) {
            throw new Exception('Selected conference room is not available.');
        }

        // Use full day rate for pricing
        $total_amount = $room['daily_rate'];

        // Generate unique inquiry reference
        do {
            $inquiry_reference = 'CONF-' . date('Y') . '-' . str_pad(rand(1, 99999), 5, '0', STR_PAD_LEFT);
            $ref_check = $pdo->prepare("SELECT id FROM conference_inquiries WHERE inquiry_reference = ?");
            $ref_check->execute([$inquiry_reference]);
        } while ($ref_check->rowCount() > 0);

        // Insert inquiry
        $insert_stmt = $pdo->prepare("
            INSERT INTO conference_inquiries (
                inquiry_reference, conference_room_id, company_name, contact_person,
                email, phone, event_date, start_time, end_time, number_of_attendees,
                event_type, special_requirements, catering_required, av_equipment, total_amount
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        $insert_stmt->execute([
            $inquiry_reference, $room_id, $company_name, $contact_person,
            $email, $phone, $event_date, $start_time, $end_time, $attendees,
            $event_type, $special_requirements, $catering, $av_equipment, $total_amount
        ]);

        // Set success and generate reference after validation passes
        $inquiry_success = true;
        $success_reference = $inquiry_reference;

        // Prepare enquiry data for email functions
        $enquiry_data = [
            'id' => $pdo->lastInsertId(),
            'inquiry_reference' => $inquiry_reference,
            'conference_room_id' => $room_id,
            'company_name' => $company_name,
            'contact_person' => $contact_person,
            'email' => $email,
            'phone' => $phone,
            'event_date' => $event_date,
            'start_time' => $start_time,
            'end_time' => $end_time,
            'number_of_attendees' => $attendees,
            'event_type' => $event_type,
            'special_requirements' => $special_requirements,
            'catering_required' => $catering,
            'av_equipment' => $av_equipment,
            'total_amount' => $total_amount
        ];

        // Send confirmation email to customer
        $customer_result = sendConferenceEnquiryEmail($enquiry_data);
        if (!$customer_result['success']) {
            error_log("Failed to send conference enquiry confirmation email: " . $customer_result['message']);
        } else {
            error_log("Conference customer email sent successfully to: " . $sanitized_data['email']);
        }
        
        // Send notification email to admin
        $admin_result = sendConferenceAdminNotificationEmail($enquiry_data);
        if (!$admin_result['success']) {
            error_log("Failed to send conference admin notification: " . $admin_result['message']);
        } else {
            error_log("Conference admin notification sent successfully");
        }
        
        error_log("Conference enquiry submitted successfully from: " . $sanitized_data['email'] . " with reference: " . $inquiry_reference);

    } catch (Exception $e) {
        $inquiry_error = $e->getMessage();
    }
}

$currency_symbol = getSetting('currency_symbol');
$site_name = getSetting('site_name');
$site_logo = getSetting('site_logo');
$site_tagline = getSetting('site_tagline');

function resolveConferenceImage(?string $imagePath): string
{
    if (!empty($imagePath)) {
        $normalized = ltrim($imagePath, '/');
        $fullPath = __DIR__ . '/' . $normalized;
        if (file_exists($fullPath)) {
            return $normalized;
        }
    }

    return '';
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Conference Facilities - <?php echo htmlspecialchars($site_name); ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" media="print" onload="this.media='all'">
    <link rel="stylesheet" href="css/theme-dynamic.php">
    <link rel="stylesheet" href="css/header.css">
    <link rel="stylesheet" href="css/style.css">
    <link rel="stylesheet" href="css/footer.css">
    <style>
        .conference-rooms-section {
            padding: 90px 0;
            background: #f4f7fb;
        }

        .conference-rooms-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 32px;
            align-items: stretch;
        }

        .conference-room-card {
            background: white;
            border-radius: 16px;
            overflow: hidden;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
            transition: all 0.4s cubic-bezier(0.34, 1.56, 0.64, 1);
            position: relative;
            display: flex;
            flex-direction: column;
        }

        .conference-room-card.featured {
            border: 2px solid var(--gold);
        }

        .conference-room-card:hover {
            transform: translateY(-12px);
            box-shadow: 0 16px 48px rgba(0, 0, 0, 0.2);
        }

        .conference-room-image {
            position: relative;
            width: 100%;
            height: 260px;
            overflow: hidden;
        }

        .conference-room-image img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            transition: transform 0.4s ease;
        }

        .conference-room-card:hover .conference-room-image img {
            transform: scale(1.1);
        }

        .conference-room-content {
            padding: 28px;
            display: flex;
            flex-direction: column;
            flex: 1;
        }

        .conference-room-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 12px;
            gap: 16px;
        }

        .conference-room-title {
            font-size: 24px;
            font-family: var(--font-serif);
            color: var(--navy);
            margin: 0 0 12px 0;
            line-height: 1.3;
        }

        .conference-room-capacity {
            background: var(--gold);
            color: var(--deep-navy);
            padding: 8px 14px;
            border-radius: 12px;
            text-align: center;
            font-weight: 700;
            font-size: 13px;
            white-space: nowrap;
        }

        .conference-room-description {
            color: #666;
            line-height: 1.8;
            margin-bottom: 16px;
            flex: 1;
        }

        .conference-room-details {
            display: flex;
            flex-wrap: wrap;
            gap: 16px;
            margin-bottom: 16px;
            color: #666;
            font-size: 14px;
        }

        .detail-item {
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .detail-item i {
            color: var(--gold);
        }

        .amenities-list {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-bottom: 16px;
        }

        .amenity-tag {
            background: #f5f5f5;
            color: #555;
            padding: 6px 11px;
            border-radius: 6px;
            transition: all 0.25s ease;
            border: 1px solid rgba(0, 0, 0, 0.06);
            font-size: 11px;
            font-weight: 500;
        }

        .amenity-tag:hover {
            background: var(--navy);
            color: white;
            border-color: var(--navy);
        }

        .pricing-section {
            background: linear-gradient(135deg, rgba(212, 175, 55, 0.15), rgba(212, 175, 55, 0.05));
            color: var(--navy);
            padding: 16px;
            border-radius: 12px;
            border: 1px solid rgba(212, 175, 55, 0.35);
            box-shadow: 0 6px 20px rgba(212, 175, 55, 0.15);
        }

        .pricing-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 8px 0;
            border-bottom: 1px solid rgba(212, 175, 55, 0.15);
        }

        .pricing-row:last-child {
            border-bottom: none;
        }

        .pricing-label {
            font-size: 14px;
            color: #666;
            font-weight: 500;
        }

        .pricing-value {
            font-size: 20px;
            font-weight: 700;
            color: var(--navy);
        }

        .btn-inquire {
            background: linear-gradient(135deg, var(--gold) 0%, #c49b2e 100%);
            color: var(--deep-navy);
            padding: 16px 40px;
            border-radius: 8px;
            border: none;
            font-size: 16px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.3s ease;
            text-decoration: none;
            display: inline-block;
        }

        .btn-inquire:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 24px rgba(212, 175, 55, 0.4);
        }

        .inquiry-modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.8);
            z-index: 10000;
            overflow-y: auto;
            padding: 20px;
        }

        .inquiry-modal.active {
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .inquiry-form-container {
            background: white;
            border-radius: 16px;
            max-width: 800px;
            width: 100%;
            padding: 40px;
            position: relative;
        }

        .close-modal {
            position: absolute;
            top: 20px;
            right: 20px;
            font-size: 32px;
            color: #999;
            cursor: pointer;
            transition: color 0.3s;
        }

        .close-modal:hover {
            color: var(--navy);
        }

        .form-group {
            margin-bottom: 24px;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: var(--navy);
        }

        .form-group input,
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 12px 16px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            font-size: 15px;
            transition: border-color 0.3s;
        }

        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: var(--gold);
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }

        .checkbox-group {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .checkbox-group input[type="checkbox"] {
            width: auto;
        }

        .success-message {
            background: linear-gradient(135deg, #d4edda 0%, #c3e6cb 100%);
            color: #155724;
            padding: 20px;
            border-radius: 12px;
            margin-bottom: 20px;
            border: 1px solid #c3e6cb;
        }

        .error-message {
            background: linear-gradient(135deg, #f8d7da 0%, #f5c6cb 100%);
            color: #721c24;
            padding: 20px;
            border-radius: 12px;
            margin-bottom: 20px;
            border: 1px solid #f5c6cb;
        }

        .conference-empty {
            padding: 40px;
            background: white;
            border-radius: 16px;
            text-align: center;
            border: 1px solid rgba(15, 29, 46, 0.08);
            box-shadow: 0 12px 40px rgba(10, 20, 35, 0.08);
        }

        .conference-empty h2 {
            color: var(--navy);
            margin-bottom: 12px;
        }

        @media (max-width: 1024px) {
            .conference-rooms-grid {
                grid-template-columns: repeat(2, 1fr);
                gap: 24px;
            }
        }

        @media (max-width: 768px) {
            .conference-rooms-grid {
                grid-template-columns: 1fr;
                gap: 20px;
            }
        }

        @media (max-width: 768px) {
            .conference-rooms-grid {
                grid-template-columns: 1fr;
                gap: 20px;
            }

            .conference-room-card {
                margin-bottom: 0;
            }

            .conference-room-image {
                height: 200px;
            }

            .conference-room-content {
                padding: 20px 16px;
            }

            .conference-room-header {
                gap: 12px;
                margin-bottom: 16px;
            }

            .conference-room-title {
                font-size: 20px;
                margin-bottom: 10px;
            }

            .conference-room-capacity {
                padding: 8px 12px;
                font-size: 11px;
                width: fit-content;
            }

            .conference-room-description {
                font-size: 13px;
                margin-bottom: 16px;
            }

            .conference-room-details {
                gap: 12px;
                font-size: 13px;
                margin-bottom: 16px;
            }

            .amenities-list {
                gap: 8px;
                margin-bottom: 16px;
            }

            .amenity-tag {
                padding: 6px 10px;
                font-size: 11px;
            }

            .pricing-section {
                padding: 12px 12px;
                margin-bottom: 16px;
            }

            .pricing-row {
                padding: 8px 0;
            }

            .pricing-label {
                font-size: 13px;
            }

            .pricing-value {
                font-size: 18px;
            }

            .form-row {
                grid-template-columns: 1fr;
            }

            .inquiry-form-container {
                padding: 24px;
            }
        }

        @media (max-width: 480px) {

            .conference-room-image {
                height: 180px;
            }

            .conference-room-title {
                font-size: 18px;
            }

            .conference-room-header {
                flex-direction: column;
                gap: 8px;
            }

            .conference-room-details {
                flex-direction: column;
                gap: 8px;
            }
        }
    </style>
</head>
<body>
    <?php include 'includes/loader.php'; ?>
    <?php include 'includes/header.php'; ?>

    <!-- Hero Section -->
    <?php include 'includes/hero.php'; ?>

    <!-- Conference Rooms Section -->
    <section class="conference-rooms-section">
        <div class="container">
            <?php renderSectionHeader('conference_overview', 'conference', [
                'label' => 'Our Meeting Spaces',
                'title' => 'Professional Conference Facilities',
                'description' => 'State-of-the-art venues for your business meetings and events'
            ], 'text-center'); ?>
            <?php if (empty($conference_rooms)): ?>
                <div class="conference-empty">
                    <h2>No conference rooms available</h2>
                    <p>Our team is preparing the conference lineup. Please check back soon or contact us for tailored corporate options.</p>
                </div>
            <?php else: ?>
                <div class="conference-rooms-grid">
                    <?php foreach ($conference_rooms as $room): ?>
                        <?php
                        $amenities = !empty($room['amenities']) ? explode(',', $room['amenities']) : [];
                        $image_path = resolveConferenceImage($room['image_path'] ?? '');
                        ?>
                        <div class="conference-room-card">
                            <?php if (!empty($image_path)): ?>
                                <img src="<?php echo htmlspecialchars($image_path); ?>"
                                     alt="<?php echo htmlspecialchars($room['name']); ?>"
                                     class="conference-room-image">
                            <?php else: ?>
                                <div class="conference-room-image" style="background: linear-gradient(135deg, #e0e0e0 0%, #f5f5f5 100%); display: flex; align-items: center; justify-content: center; color: #999;">
                                    <i class="fas fa-image" style="font-size: 32px;"></i>
                                </div>
                            <?php endif; ?>
                            
                            <div class="conference-room-content">
                                <div class="conference-room-header">
                                    <h2 class="conference-room-title"><?php echo htmlspecialchars($room['name']); ?></h2>
                                    <span class="conference-room-capacity">
                                        <i class="fas fa-users"></i> Up to <?php echo $room['capacity']; ?> People
                                    </span>
                                </div>

                                <p class="conference-room-description"><?php echo htmlspecialchars($room['description']); ?></p>

                                <div class="conference-room-details">
                                    <div class="detail-item">
                                        <i class="fas fa-expand-arrows-alt"></i>
                                        <span><?php echo number_format($room['size_sqm'], 0); ?> sqm</span>
                                    </div>
                                    <div class="detail-item">
                                        <i class="fas fa-users"></i>
                                        <span>Capacity: <?php echo $room['capacity']; ?> people</span>
                                    </div>
                                    <div class="detail-item">
                                        <i class="fas fa-briefcase"></i>
                                        <span>Executive-ready service</span>
                                    </div>
                                </div>

                                <?php if (!empty($amenities)): ?>
                                <div class="amenities-list">
                                    <?php foreach ($amenities as $amenity): ?>
                                        <span class="amenity-tag">
                                            <i class="fas fa-check"></i> <?php echo trim(htmlspecialchars($amenity)); ?>
                                        </span>
                                    <?php endforeach; ?>
                                </div>
                                <?php endif; ?>

                                <div class="pricing-section">
                                    <div class="pricing-row">
                                        <span class="pricing-label">Full Day Rate</span>
                                        <span class="pricing-value"><?php echo $currency_symbol . number_format($room['daily_rate'], 0); ?>/day</span>
                                    </div>
                                </div>

                                <button class="btn-inquire" onclick="openInquiryModal(<?php echo $room['id']; ?>, '<?php echo htmlspecialchars($room['name']); ?>')">
                                    <i class="fas fa-envelope"></i> Send Inquiry
                                </button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </section>

    <!-- Inquiry Modal -->
    <?php
    $modalContent = '
        <form method="POST" action="" id="inquiryForm">
            ' . getCsrfField() . '
            <input type="hidden" name="conference_room_id" id="selectedRoomId">
            
            <div class="form-group">
                <label>Conference Room</label>
                <input type="text" id="selectedRoomName" disabled style="background: #f5f5f5;">
            </div>
 
            <div class="form-row">
                <div class="form-group">
                    <label>Company Name *</label>
                    <input type="text" name="company_name" required>
                </div>
                <div class="form-group">
                    <label>Contact Person *</label>
                    <input type="text" name="contact_person" required>
                </div>
            </div>
 
            <div class="form-row">
                <div class="form-group">
                    <label>Email *</label>
                    <input type="email" name="email" required>
                </div>
                <div class="form-group">
                    <label>Phone *</label>
                    <input type="tel" name="phone" required>
                </div>
            </div>
 
            <div class="form-group">
                <label>Event Date *</label>
                <input type="date" name="event_date" id="event_date" min="' . date('Y-m-d') . '" required>
                <small class="field-error" id="event_date_error" style="color: #dc3545; display: none;"></small>
            </div>
  
            <div class="form-row">
                <div class="form-group">
                    <label>Start Time *</label>
                    <input type="time" name="start_time" id="start_time" required>
                    <small class="field-error" id="start_time_error" style="color: #dc3545; display: none;"></small>
                </div>
                <div class="form-group">
                    <label>End Time *</label>
                    <input type="time" name="end_time" id="end_time" required>
                </div>
            </div>
 
            <div class="form-row">
                <div class="form-group">
                    <label>Number of Attendees *</label>
                    <input type="number" name="number_of_attendees" min="1" required>
                </div>
                <div class="form-group">
                    <label>Event Type</label>
                    <select name="event_type">
                        <option value="">Select type...</option>
                        <option value="Meeting">Meeting</option>
                        <option value="Conference">Conference</option>
                        <option value="Workshop">Workshop</option>
                        <option value="Seminar">Seminar</option>
                        <option value="Training">Training</option>
                        <option value="Other">Other</option>
                    </select>
                </div>
            </div>
 
            <div class="form-group">
                <label>AV Equipment Requirements</label>
                <input type="text" name="av_equipment" placeholder="e.g., Projector, Microphones, Sound System">
            </div>
 
            <div class="form-group checkbox-group">
                <input type="checkbox" name="catering_required" id="catering">
                <label for="catering" style="margin-bottom: 0;">Catering Required</label>
            </div>
  
            <div class="form-group">
                <label>Special Requirements</label>
                <textarea name="special_requirements" rows="4" placeholder="Any additional requests or requirements..."></textarea>
            </div>
            
            <div class="form-group checkbox-group">
                <input type="checkbox" name="consent" id="consentCheckbox" required>
                <label for="consentCheckbox" style="margin-bottom: 0;">I agree to be contacted about this enquiry request.</label>
            </div>
        </form>
    ';
    
    renderModal('inquiryModal', 'Conference Room Inquiry', $modalContent, [
        'size' => 'lg',
        'footer' => '
            <button type="submit" form="inquiryForm" id="conferenceSubmitBtn" class="btn-inquire" style="width: 100%;" disabled>
                <i class="fas fa-paper-plane"></i> Submit Inquiry
            </button>
        '
    ]);
    ?>

    <!-- Result Modal for Success/Error Messages -->
    <?php
    $resultModalContent = '';
    if ($inquiry_success) {
        $resultModalContent = '<div style="text-align: center; padding: 20px;">
            <i class="fas fa-check-circle" style="font-size: 64px; color: #28a745;"></i>
            <h2 style="color: var(--navy); margin: 20px 0 15px 0; font-size: 28px; font-weight: 700;">Conference Enquiry Submitted Successfully!</h2>
            <p style="color: #666; margin: 0 0 25px 0; font-size: 16px; line-height: 1.6;">Thank you for your conference enquiry. Our events team will review your request and contact you within 24 hours to confirm availability and finalize details.</p>
            <div style="background: linear-gradient(135deg, rgba(212, 175, 55, 0.15), rgba(212, 175, 55, 0.05)); padding: 20px 30px; border-radius: 12px; margin: 25px 0; border: 2px solid rgba(212, 175, 55, 0.35);">
                <p style="color: var(--navy); margin: 0; font-size: 14px; font-weight: 600;">Your Reference Number:</p>
                <p style="color: var(--navy); margin: 8px 0 0 0; font-size: 24px; font-weight: 700; letter-spacing: 1px;">' . htmlspecialchars($success_reference) . '</p>
            </div>
            <p style="color: #666; margin: 20px 0 0 0; font-size: 14px; line-height: 1.6;">
                <i class="fas fa-envelope" style="color: var(--gold);"></i> A confirmation email has been sent to your email address.<br>
                <i class="fas fa-info-circle" style="color: var(--gold);"></i> Please save this reference number for your records.
            </p>
        </div>';
    } elseif ($inquiry_error) {
        $resultModalContent = '<div style="text-align: center; padding: 20px;">
            <i class="fas fa-exclamation-triangle" style="font-size: 64px; color: #dc3545;"></i>
            <h2 style="color: var(--navy); margin: 20px 0 15px 0; font-size: 28px; font-weight: 700;">Enquiry Submission Failed</h2>
            <p style="color: #666; margin: 0 0 25px 0; font-size: 16px; line-height: 1.6;">' . htmlspecialchars($inquiry_error) . '</p>
            <p style="color: #666; margin: 20px 0 0 0; font-size: 14px;">
                <i class="fas fa-phone" style="color: var(--gold);"></i> Please try again or contact our events team directly for assistance.
            </p>
        </div>';
    }
    
    renderModal('conferenceBookingResult', '', $resultModalContent, ['size' => 'md']);
    ?>

    <?php include 'includes/footer.php'; ?>
    <script src="js/modal.js"></script>
    <script src="js/main.js"></script>
    <script>
        // Inquiry Modal - target by specific ID, not generic selector
        const inquiryModal = document.getElementById('inquiryModal');
        const inquiryModalOverlay = document.getElementById('inquiryModal-overlay');

        function openInquiryModal(roomId, roomName) {
            document.getElementById('selectedRoomId').value = roomId;
            document.getElementById('selectedRoomName').value = roomName;
            
            if (inquiryModal) {
                inquiryModal.classList.add('active');
                if (inquiryModalOverlay) inquiryModalOverlay.classList.add('active');
                document.body.style.overflow = 'hidden';
            }
        }

        function closeInquiryModal() {
            if (inquiryModal) {
                inquiryModal.classList.remove('active');
                if (inquiryModalOverlay) inquiryModalOverlay.classList.remove('active');
                document.body.style.overflow = '';
            }
        }

        // Close inquiry modal on overlay click
        if (inquiryModalOverlay) {
            inquiryModalOverlay.addEventListener('click', closeInquiryModal);
        }

        // Close inquiry modal close buttons (scoped to inquiry modal only)
        const inquiryCloseButtons = document.querySelectorAll('#inquiryModal [data-modal-close]');
        inquiryCloseButtons.forEach(btn => btn.addEventListener('click', closeInquiryModal));

        // Result Modal - close handling (scoped to result modal only)
        const resultModal = document.getElementById('conferenceBookingResult');
        const resultModalOverlay = document.getElementById('conferenceBookingResult-overlay');

        function closeResultModal() {
            if (resultModal) {
                resultModal.classList.remove('active');
                if (resultModalOverlay) resultModalOverlay.classList.remove('active');
                document.body.style.overflow = '';
            }
        }

        if (resultModalOverlay) {
            resultModalOverlay.addEventListener('click', closeResultModal);
        }

        const resultCloseButtons = document.querySelectorAll('#conferenceBookingResult [data-modal-close]');
        resultCloseButtons.forEach(btn => btn.addEventListener('click', closeResultModal));

        // Close any active modal on escape key
        document.addEventListener('keyup', (e) => {
            if (e.key === 'Escape') {
                if (resultModal && resultModal.classList.contains('active')) {
                    closeResultModal();
                } else if (inquiryModal && inquiryModal.classList.contains('active')) {
                    closeInquiryModal();
                }
            }
        });

        // Consent checkbox validation - grey out submit button until consent is checked
        const consentCheckbox = document.getElementById('consentCheckbox');
        const conferenceSubmitBtn = document.getElementById('conferenceSubmitBtn');
        
        if (consentCheckbox && conferenceSubmitBtn) {
            // Initialize button state
            conferenceSubmitBtn.disabled = !consentCheckbox.checked;
            conferenceSubmitBtn.style.opacity = consentCheckbox.checked ? '1' : '0.6';
            conferenceSubmitBtn.style.cursor = consentCheckbox.checked ? 'pointer' : 'not-allowed';
            
            // Handle checkbox change
            consentCheckbox.addEventListener('change', function() {
                conferenceSubmitBtn.disabled = !this.checked;
                conferenceSubmitBtn.style.opacity = this.checked ? '1' : '0.6';
                conferenceSubmitBtn.style.cursor = this.checked ? 'pointer' : 'not-allowed';
            });
        }
        
        // Form submission handling - grey out submit button
        const inquiryForm = document.getElementById('inquiryForm');
        
        if (inquiryForm && conferenceSubmitBtn) {
            inquiryForm.addEventListener('submit', function() {
                // Disable and grey out the submit button
                conferenceSubmitBtn.disabled = true;
                conferenceSubmitBtn.style.opacity = '0.6';
                conferenceSubmitBtn.style.cursor = 'not-allowed';
                conferenceSubmitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Submitting...';
            });
        }

        // Date/Time validation - ensure selected time is not in the past for today's date
        const eventDate = document.getElementById('event_date');
        const startTime = document.getElementById('start_time');
        const dateError = document.getElementById('event_date_error');
        const timeError = document.getElementById('start_time_error');
        
        // Booking buffer in minutes (default 60, should match PHP setting)
        const bookingBufferMinutes = 60;
        
        function validateConferenceDateTime() {
            if (!eventDate || !startTime) return true;
            if (!eventDate.value || !startTime.value) {
                if (dateError) dateError.style.display = 'none';
                if (timeError) timeError.style.display = 'none';
                return true;
            }
            
            const selectedDateTime = new Date(eventDate.value + 'T' + startTime.value);
            const now = new Date();
            const minAllowed = new Date(now.getTime() + bookingBufferMinutes * 60000);
            
            // Clear previous errors
            if (dateError) dateError.style.display = 'none';
            if (timeError) timeError.style.display = 'none';
            
            if (selectedDateTime < minAllowed) {
                // Check if it's a past date
                const today = new Date();
                today.setHours(0, 0, 0, 0);
                const selectedDayOnly = new Date(eventDate.value);
                selectedDayOnly.setHours(0, 0, 0, 0);
                
                if (selectedDayOnly < today) {
                    if (dateError) {
                        dateError.textContent = 'Event date cannot be in the past';
                        dateError.style.display = 'block';
                    }
                    if (eventDate) eventDate.style.borderColor = '#dc3545';
                } else {
                    // It's today but time is too soon
                    const bufferHours = Math.floor(bookingBufferMinutes / 60);
                    const bufferMins = bookingBufferMinutes % 60;
                    let timeMsg = 'For today, please select a start time at least ';
                    if (bufferHours > 0 && bufferMins > 0) {
                        timeMsg += bufferHours + ' hour(s) and ' + bufferMins + ' minutes from now';
                    } else if (bufferHours > 0) {
                        timeMsg += bufferHours + ' hour(s) from now';
                    } else {
                        timeMsg += bookingBufferMinutes + ' minutes from now';
                    }
                    if (timeError) {
                        timeError.textContent = timeMsg;
                        timeError.style.display = 'block';
                    }
                    if (startTime) startTime.style.borderColor = '#dc3545';
                }
                return false;
            }
            
            // Valid - reset borders
            if (eventDate) eventDate.style.borderColor = '';
            if (startTime) startTime.style.borderColor = '';
            return true;
        }
        
        // Add event listeners for real-time validation
        if (eventDate && startTime) {
            eventDate.addEventListener('change', validateConferenceDateTime);
            startTime.addEventListener('change', validateConferenceDateTime);
            
            // Also validate on form submission
            if (inquiryForm) {
                inquiryForm.addEventListener('submit', function(e) {
                    if (!validateConferenceDateTime()) {
                        e.preventDefault();
                        return false;
                    }
                });
            }
        }

        <?php if ($inquiry_success || $inquiry_error): ?>
            // Auto-open result modal on page load when there's a result
            window.addEventListener('load', function() {
                const resultModalEl = document.getElementById('conferenceBookingResult');
                const resultOverlay = document.getElementById('conferenceBookingResult-overlay');
                if (resultModalEl) {
                    setTimeout(function() {
                        resultModalEl.classList.add('active');
                        if (resultOverlay) resultOverlay.classList.add('active');
                        document.body.style.overflow = 'hidden';
                    }, 600);
                }
            });
        <?php endif; ?>
    </script>

    <?php include 'includes/scroll-to-top.php'; ?>
</body>
</html>
