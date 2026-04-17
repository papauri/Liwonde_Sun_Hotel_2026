<?php
// Include admin initialization (PHP-only, no HTML output)
require_once 'admin-init.php';

$user = [
    'id' => $_SESSION['admin_user_id'],
    'username' => $_SESSION['admin_username'],
    'role' => $_SESSION['admin_role'],
    'full_name' => $_SESSION['admin_full_name']
];
$message = '';
$error = '';
$test_email_feedback = null;

/**
 * Read the max advance booking days directly from the database.
 * This avoids stale cache values when changes are made outside this page.
 */
function getLiveMaxAdvanceBookingDays($default = 30) {
    global $pdo;

    try {
        $stmt = $pdo->prepare("SELECT setting_value FROM site_settings WHERE setting_key = 'max_advance_booking_days' LIMIT 1");
        $stmt->execute();
        $value = $stmt->fetchColumn();

        if ($value === false || $value === null || $value === '') {
            return (int)$default;
        }

        return (int)$value;
    } catch (Exception $e) {
        error_log('Error loading live max_advance_booking_days: ' . $e->getMessage());
        return (int)$default;
    }
}

/**
 * Check whether an SMTP password is stored in the database.
 * This intentionally checks raw stored value (encrypted or plain),
 * not the visible form input.
 */
function hasStoredSmtpPassword() {
    global $pdo;

    try {
        $stmt = $pdo->prepare("SELECT setting_value FROM email_settings WHERE setting_key = 'smtp_password' LIMIT 1");
        $stmt->execute();
        $stored = $stmt->fetchColumn();

        if ($stored === false || $stored === null) {
            return false;
        }

        return trim((string)$stored) !== '';
    } catch (Exception $e) {
        error_log('Error checking stored smtp_password: ' . $e->getMessage());
        return false;
    }
}

/**
 * Check if smtp password can be read in plaintext by runtime.
 * If encrypted value exists but decrypt fails, this returns false.
 */
function hasUsableSmtpPassword() {
    $decrypted = (string)getEmailSetting('smtp_password', '');
    return trim($decrypted) !== '';
}

/**
 * Ensure department recipient settings exist in email_settings.
 * Falls back to legacy site settings and then global admin email.
 */
function seedDepartmentEmailSettings() {
    $fallback_admin = trim((string)getEmailSetting('email_admin_email', ''));
    if (!filter_var($fallback_admin, FILTER_VALIDATE_EMAIL)) {
        $fallback_admin = trim((string)getSetting('email_main', ''));
    }

    $map = [
        'booking_admin_email' => trim((string)getSetting('admin_notification_email', '')),
        'conference_admin_email' => trim((string)getSetting('conference_email', '')),
        'gym_admin_email' => trim((string)getSetting('gym_email', '')),
        'restaurant_admin_email' => trim((string)getSetting('email_restaurant', getSetting('restaurant_email', ''))),
    ];

    foreach ($map as $key => $legacy_value) {
        $current = trim((string)getEmailSetting($key, ''));
        if (filter_var($current, FILTER_VALIDATE_EMAIL)) {
            continue;
        }

        $seed = filter_var($legacy_value, FILTER_VALIDATE_EMAIL) ? $legacy_value : $fallback_admin;
        if (filter_var($seed, FILTER_VALIDATE_EMAIL)) {
            updateEmailSetting($key, $seed, '', false);
        }
    }
}

seedDepartmentEmailSettings();

// Handle setting updates
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!isset($_POST['csrf_token']) || !validateCsrfToken($_POST['csrf_token'])) {
            throw new Exception('Invalid security token. Please refresh and try again.');
        }

        if (isset($_POST['contact_settings'])) {
            // Hotel contact information settings
            $contact_fields = [
                'site_name'          => ['label' => 'Hotel Name',            'max' => 150],
                'site_tagline'       => ['label' => 'Tagline',               'max' => 255, 'optional' => true],
                'site_url'           => ['label' => 'Website URL',           'max' => 255, 'optional' => true],
                'phone_main'         => ['label' => 'Main Phone Number',     'max' => 30,  'optional' => true],
                'phone_secondary'    => ['label' => 'Secondary Phone',       'max' => 30,  'optional' => true],
                'email_main'         => ['label' => 'General Email',         'max' => 254, 'email' => true, 'optional' => true],
                'email_reservations' => ['label' => 'Reservations Email',    'max' => 254, 'email' => true, 'optional' => true],
                'email_info'         => ['label' => 'Info Email',            'max' => 254, 'email' => true, 'optional' => true],
                'address_line1'      => ['label' => 'Address Line 1',        'max' => 255, 'optional' => true],
                'address_line2'      => ['label' => 'Address Line 2',        'max' => 255, 'optional' => true],
                'address_city'       => ['label' => 'City',                  'max' => 100, 'optional' => true],
                'address_country'    => ['label' => 'Country',               'max' => 100, 'optional' => true],
            ];

            foreach ($contact_fields as $key => $meta) {
                $value = trim($_POST[$key] ?? '');
                if (empty($value) && empty($meta['optional'])) {
                    throw new Exception($meta['label'] . ' is required.');
                }
                if (!empty($value) && strlen($value) > $meta['max']) {
                    throw new Exception($meta['label'] . ' must not exceed ' . $meta['max'] . ' characters.');
                }
                if (!empty($value) && !empty($meta['email']) && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
                    throw new Exception($meta['label'] . ' must be a valid email address.');
                }
                // Sanitize phone numbers to strip dangerous characters
                if (!empty($value) && str_contains($key, 'phone')) {
                    $value = preg_replace('/[^0-9+\-\s\(\)]/', '', $value);
                }
                if (!updateSetting($key, $value)) {
                    throw new Exception('Failed to save ' . $meta['label'] . '.');
                }
                // Clear file cache for this setting
                deleteCache("setting_{$key}");
            }

            $message = 'Hotel contact information updated successfully!';
        } elseif (isset($_POST['max_advance_booking_days'])) {
            // Booking settings form
            $max_advance_days = (int)($_POST['max_advance_booking_days'] ?? 30);
            
            // Validate input
            if ($max_advance_days < 1) {
                throw new Exception('Maximum advance booking days must be at least 1');
            }
            
            if ($max_advance_days > 365) {
                throw new Exception('Maximum advance booking days cannot exceed 365 (one year)');
            }
            
            // Upsert the setting so it always exists in the live database.
            if (!updateSetting('max_advance_booking_days', (string)$max_advance_days)) {
                throw new Exception('Failed to update max advance booking days in database.');
            }
            
            // Clear the setting cache (both in-memory and file cache)
            global $_SITE_SETTINGS;
            if (isset($_SITE_SETTINGS['max_advance_booking_days'])) {
                unset($_SITE_SETTINGS['max_advance_booking_days']);
            }
            // Clear the file cache
            deleteCache("setting_max_advance_booking_days");

            // Verify persisted value from live DB.
            $saved_value = getLiveMaxAdvanceBookingDays(30);
            if ($saved_value !== $max_advance_days) {
                throw new Exception('Database update verification failed. Please retry.');
            }
            
            $message = "Maximum advance booking days updated to {$max_advance_days} days successfully!";
            
        } elseif (isset($_POST['restaurant_settings'])) {
            // Restaurant settings form
            $restaurant_settings = [
                'restaurant_min_advance_days' => (int)($_POST['restaurant_min_advance_days'] ?? 1),
                'restaurant_breakfast_start' => trim($_POST['restaurant_breakfast_start'] ?? '06:00'),
                'restaurant_breakfast_end' => trim($_POST['restaurant_breakfast_end'] ?? '10:00'),
                'restaurant_lunch_start' => trim($_POST['restaurant_lunch_start'] ?? '12:00'),
                'restaurant_lunch_end' => trim($_POST['restaurant_lunch_end'] ?? '15:00'),
                'restaurant_dinner_start' => trim($_POST['restaurant_dinner_start'] ?? '18:00'),
                'restaurant_dinner_end' => trim($_POST['restaurant_dinner_end'] ?? '22:00'),
            ];
            
            // Validate minimum advance days
            if ($restaurant_settings['restaurant_min_advance_days'] < 0) {
                throw new Exception('Minimum advance days cannot be negative');
            }
            if ($restaurant_settings['restaurant_min_advance_days'] > 365) {
                throw new Exception('Minimum advance days cannot exceed 365');
            }
            
            // Validate all time formats and time ranges
            $time_fields = [
                'breakfast' => ['start' => $restaurant_settings['restaurant_breakfast_start'], 'end' => $restaurant_settings['restaurant_breakfast_end']],
                'lunch' => ['start' => $restaurant_settings['restaurant_lunch_start'], 'end' => $restaurant_settings['restaurant_lunch_end']],
                'dinner' => ['start' => $restaurant_settings['restaurant_dinner_start'], 'end' => $restaurant_settings['restaurant_dinner_end']],
            ];
            
            foreach ($time_fields as $meal => $times) {
                // Validate time format (HH:MM)
                if (!preg_match('/^\d{2}:\d{2}$/', $times['start'])) {
                    throw new Exception(ucfirst($meal) . ' start time must be in HH:MM format');
                }
                if (!preg_match('/^\d{2}:\d{2}$/', $times['end'])) {
                    throw new Exception(ucfirst($meal) . ' end time must be in HH:MM format');
                }
                
                // Validate that times are valid
                $start_parts = explode(':', $times['start']);
                $end_parts = explode(':', $times['end']);
                if ($start_parts[0] > 23 || $start_parts[1] > 59 || $end_parts[0] > 23 || $end_parts[1] > 59) {
                    throw new Exception(ucfirst($meal) . ' times are invalid');
                }
                
                // Validate that end time is after start time
                if ($times['end'] <= $times['start']) {
                    throw new Exception(ucfirst($meal) . ' end time must be after start time');
                }
            }
            
            // Update all restaurant settings
            foreach ($restaurant_settings as $key => $value) {
                if (!updateSetting($key, (string)$value)) {
                    throw new Exception('Failed to update restaurant setting: ' . $key);
                }
            }
            
            $message = "Restaurant operating hours updated successfully!";
            
        } elseif (isset($_POST['email_settings'])) {
            // Email settings form
            $smtp_password_input = trim($_POST['smtp_password'] ?? '');
            $email_settings = [
                'smtp_host' => $_POST['smtp_host'] ?? '',
                'smtp_port' => $_POST['smtp_port'] ?? '',
                'smtp_username' => $_POST['smtp_username'] ?? '',
                'smtp_secure' => $_POST['smtp_secure'] ?? '',
                'email_from_name' => $_POST['email_from_name'] ?? '',
                'email_from_email' => $_POST['email_from_email'] ?? '',
                'email_admin_email' => $_POST['email_admin_email'] ?? '',
                'booking_admin_email' => trim($_POST['booking_admin_email'] ?? ''),
                'conference_admin_email' => trim($_POST['conference_admin_email'] ?? ''),
                'gym_admin_email' => trim($_POST['gym_admin_email'] ?? ''),
                'restaurant_admin_email' => trim($_POST['restaurant_admin_email'] ?? ''),
                'email_bcc_admin' => isset($_POST['email_bcc_admin']) ? '1' : '0',
                'email_development_mode' => isset($_POST['email_development_mode']) ? '1' : '0',
                'email_log_enabled' => isset($_POST['email_log_enabled']) ? '1' : '0',
                'email_preview_enabled' => isset($_POST['email_preview_enabled']) ? '1' : '0',
            ];

            $smtp_secure = strtolower(trim((string)$email_settings['smtp_secure']));
            if (!in_array($smtp_secure, ['ssl', 'tls', ''], true)) {
                throw new Exception('SMTP security must be SSL, TLS, or None.');
            }
            $email_settings['smtp_secure'] = $smtp_secure;
            
            // Validate required fields
            $required_fields = ['smtp_host', 'smtp_port', 'smtp_username', 'email_from_name', 'email_from_email'];
            foreach ($required_fields as $field) {
                if (empty($email_settings[$field])) {
                    throw new Exception(ucfirst(str_replace('_', ' ', $field)) . ' is required');
                }
            }
            
            // Validate port
            if (!is_numeric($email_settings['smtp_port']) || $email_settings['smtp_port'] < 1 || $email_settings['smtp_port'] > 65535) {
                throw new Exception('SMTP port must be a valid port number (1-65535)');
            }
            
            // Validate emails
            if (!filter_var($email_settings['email_from_email'], FILTER_VALIDATE_EMAIL)) {
                throw new Exception('From email address is invalid');
            }
            
            if (!empty($email_settings['email_admin_email']) && !filter_var($email_settings['email_admin_email'], FILTER_VALIDATE_EMAIL)) {
                throw new Exception('Admin email address is invalid');
            }

            $department_labels = [
                'booking_admin_email' => 'Booking admin email address is invalid',
                'conference_admin_email' => 'Conference admin email address is invalid',
                'gym_admin_email' => 'Gym admin email address is invalid',
                'restaurant_admin_email' => 'Restaurant admin email address is invalid',
            ];
            foreach ($department_labels as $field => $error_message) {
                if ($email_settings[$field] !== '' && !filter_var($email_settings[$field], FILTER_VALIDATE_EMAIL)) {
                    throw new Exception($error_message);
                }
            }
            
            // Update email settings in database
            foreach ($email_settings as $key => $value) {
                updateEmailSetting($key, $value, '', false);
            }

            // Preserve existing SMTP password unless a new one was provided.
            if ($smtp_password_input !== '') {
                updateEmailSetting('smtp_password', $smtp_password_input, '', true);
            }
            
            $message = "Email settings updated successfully!";
            if ($smtp_password_input === '') {
                $message .= " Existing SMTP password was kept.";
            }
        } elseif (isset($_POST['send_test_email'])) {
            $test_to = trim($_POST['test_email_to'] ?? '');
            if (!filter_var($test_to, FILTER_VALIDATE_EMAIL)) {
                $test_email_feedback = [
                    'type' => 'error',
                    'message' => 'Enter a valid email address for test email.',
                    'details' => []
                ];
                throw new Exception('Enter a valid email address for test email.');
            }

            // Pre-flight validation: report config issues before attempting SMTP send.
            $preflight_errors = [];
            $smtp_host = trim((string)getEmailSetting('smtp_host', ''));
            $smtp_port = trim((string)getEmailSetting('smtp_port', ''));
            $smtp_username = trim((string)getEmailSetting('smtp_username', ''));
            $smtp_password_configured = hasStoredSmtpPassword();
            $smtp_password_usable = hasUsableSmtpPassword();
            $smtp_secure = strtolower(trim((string)getEmailSetting('smtp_secure', '')));
            $email_from_email = trim((string)getEmailSetting('email_from_email', ''));

            if ($smtp_host === '') {
                $preflight_errors[] = 'SMTP host is missing';
            }

            if ($smtp_port === '' || !ctype_digit($smtp_port) || (int)$smtp_port < 1 || (int)$smtp_port > 65535) {
                $preflight_errors[] = 'SMTP port is invalid';
            }

            if ($smtp_username === '') {
                $preflight_errors[] = 'SMTP username is missing';
            }

            if (!$smtp_password_configured) {
                $preflight_errors[] = 'SMTP password is missing';
            } elseif (!$smtp_password_usable) {
                $preflight_errors[] = 'SMTP password is stored but unreadable. Re-enter SMTP password and save Email Settings.';
            }

            if (!in_array($smtp_secure, ['ssl', 'tls', ''], true)) {
                $preflight_errors[] = 'SMTP security must be SSL, TLS, or None';
            }

            if ($email_from_email !== '' && !filter_var($email_from_email, FILTER_VALIDATE_EMAIL)) {
                $preflight_errors[] = 'From email address is invalid';
            }

            if (!empty($preflight_errors)) {
                $test_email_feedback = [
                    'type' => 'error',
                    'message' => 'Test blocked by configuration errors.',
                    'details' => $preflight_errors
                ];
                throw new Exception('Test blocked by configuration errors: ' . implode('; ', $preflight_errors));
            }

            require_once '../config/email.php';
            $result = sendEmail(
                $test_to,
                'Test Recipient',
                'SMTP Test Email - ' . getSetting('site_name', 'Hotel Website'),
                '<h2>SMTP Test Successful</h2><p>This is a test email from Booking Settings.</p><p>Timestamp: ' . date('Y-m-d H:i:s') . '</p>'
            );

            if (!$result['success']) {
                $test_email_feedback = [
                    'type' => 'error',
                    'message' => 'Test email failed.',
                    'details' => [($result['message'] ?? 'Unknown error')]
                ];
                throw new Exception('Test email failed: ' . ($result['message'] ?? 'Unknown error'));
            }

            $test_email_feedback = [
                'type' => 'success',
                'message' => 'Test email processed successfully.',
                'details' => [($result['message'] ?? 'Success')]
            ];

            $message = 'Test email processed successfully. ' . ($result['message'] ?? '');
            if (!empty($result['preview_url'])) {
                $message .= ' Preview: ' . $result['preview_url'];
                $test_email_feedback['details'][] = 'Preview: ' . $result['preview_url'];
            }
        }
        
    } catch (Exception $e) {
        $error = 'Error: ' . $e->getMessage();
        if (isset($_POST['send_test_email']) && $test_email_feedback === null) {
            $test_email_feedback = [
                'type' => 'error',
                'message' => 'Unable to complete test email.',
                'details' => [$e->getMessage()]
            ];
        }
    }
}

// Get current setting directly from DB so UI always reflects live value.
$current_max_days = getLiveMaxAdvanceBookingDays(30);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Booking Settings - Admin Panel</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;500;600;700&family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../css/style.css">
    <link rel="stylesheet" href="../css/theme-dynamic.php">
    <link rel="stylesheet" href="css/admin-styles.css">
    <link rel="stylesheet" href="css/admin-components.css">
    <style>
        /* Settings page specific styles */
        .content {
            max-width: 900px;
        }
        .settings-card {
            background: white;
            border-radius: 12px;
            padding: 30px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
            margin-bottom: 30px;
        }
        .settings-card h2 {
            font-family: 'Playfair Display', serif;
            font-size: 22px;
            color: #0A1929;
            margin-top: 0;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 2px solid #e0e0e0;
        }
        .form-group {
            margin-bottom: 25px;
        }
        .form-group label {
            display: block;
            font-weight: 600;
            color: #0A1929;
            margin-bottom: 8px;
            font-size: 14px;
        }
        .form-control {
            width: 100%;
            max-width: 400px;
            padding: 12px 16px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            font-size: 16px;
            transition: all 0.3s ease;
        }
        .form-control:focus {
            outline: none;
            border-color: #D4AF37;
            box-shadow: 0 0 0 3px rgba(212, 175, 55, 0.1);
        }
        .help-text {
            color: #666;
            font-size: 13px;
            margin-top: 8px;
            line-height: 1.5;
        }
        .help-text i {
            color: #D4AF37;
            margin-right: 5px;
        }
        .current-value {
            background: linear-gradient(135deg, #0A1929 0%, #1a2a3a 100%);
            color: white;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 25px;
            display: flex;
            align-items: center;
            gap: 15px;
        }
        .current-value i {
            font-size: 32px;
            color: #D4AF37;
        }
        .current-value-info h3 {
            margin: 0 0 5px 0;
            font-size: 14px;
            opacity: 0.8;
        }
        .current-value-info .value {
            font-size: 32px;
            font-weight: 700;
            color: #D4AF37;
        }
        .btn-submit {
            padding: 12px 30px;
            background: linear-gradient(135deg, #D4AF37 0%, #c49b2e 100%);
            color: #0A1929;
            border: none;
            border-radius: 8px;
            font-size: 15px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        .btn-submit:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(212, 175, 55, 0.4);
        }
        .back-link {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            color: #0A1929;
            text-decoration: none;
            font-weight: 500;
            margin-bottom: 15px;
            transition: color 0.3s ease;
        }
        .back-link:hover {
            color: #D4AF37;
        }
        .info-box {
            background: #e3f2fd;
            border-left: 4px solid #2196f3;
            padding: 15px;
            border-radius: 4px;
            margin-top: 20px;
        }
        .info-box h4 {
            margin: 0 0 10px 0;
            color: #1565c0;
            font-size: 15px;
        }
        .info-box ul {
            margin: 0;
            padding-left: 20px;
            color: #1976d2;
            font-size: 13px;
            line-height: 1.8;
        }
        .field-error {
            border-color: #dc3545 !important;
            box-shadow: 0 0 0 3px rgba(220, 53, 69, 0.15) !important;
            background-color: #fff7f8;
        }
        .test-progress-card {
            margin-top: 14px;
            padding: 14px;
            border: 1px solid #e0e0e0;
            border-radius: 8px;
            background: #fafafa;
            display: none;
        }
        .test-progress-header {
            font-weight: 600;
            color: #0A1929;
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .test-progress-bar-wrap {
            width: 100%;
            height: 10px;
            background: #ececec;
            border-radius: 999px;
            overflow: hidden;
            margin-bottom: 12px;
        }
        .test-progress-bar {
            height: 100%;
            width: 0%;
            background: linear-gradient(90deg, #D4AF37 0%, #0A1929 100%);
            transition: width 0.25s ease;
        }
        .test-checklist {
            list-style: none;
            margin: 0;
            padding: 0;
            display: grid;
            gap: 7px;
        }
        .test-check-item {
            display: flex;
            align-items: center;
            gap: 8px;
            color: #666;
            font-size: 13px;
        }
        .test-check-item .status {
            width: 18px;
            text-align: center;
        }
        .test-check-item.pass {
            color: #198754;
        }
        .test-check-item.fail {
            color: #dc3545;
        }
        .test-check-item.running {
            color: #0A1929;
        }
        @media (max-width: 768px) {
            .content {
                padding: 0 15px 30px 15px;
            }
            .settings-card {
                padding: 20px;
            }
        }
    </style>
</head>
<body>
    <?php require_once 'includes/admin-header.php'; ?>

    <div class="content">
        <a href="dashboard.php" class="back-link">
            <i class="fas fa-arrow-left"></i> Back to Dashboard
        </a>

        <div class="page-header">
            <h1 class="page-title">
                <i class="fas fa-cog" style="color: #D4AF37; margin-right: 10px;"></i>
                Booking Settings
            </h1>
        </div>

        <?php if ($message): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i>
                <div><?php echo $message; ?></div>
            </div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="alert alert-error">
                <i class="fas fa-exclamation-circle"></i>
                <div><?php echo $error; ?></div>
            </div>
        <?php endif; ?>

        <div class="settings-card">
            <h2><i class="fas fa-calendar-alt" style="color: #D4AF37;"></i> Advance Booking Configuration</h2>

            <div class="current-value">
                <i class="fas fa-clock"></i>
                <div class="current-value-info">
                    <h3>Current Setting</h3>
                    <div class="value"><?php echo $current_max_days; ?> Days</div>
                </div>
            </div>

            <form method="POST" action="booking-settings.php">
                <?php echo getCsrfField(); ?>
<div class="form-group">
                    <label for="max_advance_booking_days">Maximum Advance Booking Days</label>
                    <input type="number" 
                           id="max_advance_booking_days" 
                           name="max_advance_booking_days" 
                           class="form-control" 
                           value="<?php echo $current_max_days; ?>" 
                           min="1" 
                           max="365" 
                           required>
                    <p class="help-text">
                        <i class="fas fa-info-circle"></i>
                        Guests can only make bookings up to this many days in advance. 
                        Default is 30 days (one month). Minimum is 1 day, maximum is 365 days.
                    </p>
                </div>

                <button type="submit" class="btn-submit">
                    <i class="fas fa-save"></i> Save Changes
                </button>
            </form>

            <div class="info-box">
                <h4><i class="fas fa-lightbulb"></i> How This Affects Your Website</h4>
                <ul>
                    <li><strong>Booking Form:</strong> Date pickers will only allow dates within this limit</li>
                    <li><strong>Validation:</strong> Server-side validation will reject bookings beyond this date</li>
                    <li><strong>User Experience:</strong> Users will see a clear message about the booking window</li>
                    <li><strong>Flexibility:</strong> Change this value anytime to adjust your booking policy</li>
                </ul>
            </div>
        </div>

        <div class="settings-card">
            <h2><i class="fas fa-utensils" style="color: #D4AF37;"></i> Restaurant Operating Hours</h2>

            <div class="current-value">
                <p>Configure when the restaurant accepts reservations and the operating hours for each service period.</p>
            </div>

            <form method="POST" action="booking-settings.php">
                <?php echo getCsrfField(); ?>
<div class="form-group">
                    <label for="restaurant_min_advance_days">Minimum Advance Days for Reservations</label>
                    <input type="number" 
                           id="restaurant_min_advance_days" 
                           name="restaurant_min_advance_days" 
                           class="form-control" 
                           value="<?php echo htmlspecialchars(getSetting('restaurant_min_advance_days', '1')); ?>" 
                           min="0" 
                           max="365" 
                           required>
                    <p class="help-text">
                        <i class="fas fa-info-circle"></i>
                        Minimum days in advance customers must book (e.g., 1 = no same-day reservations, 0 = allow same-day)
                    </p>
                </div>

                <h4 style="margin-top: 25px; margin-bottom: 15px; color: #0A1929; border-bottom: 1px solid #e0e0e0; padding-bottom: 10px;">
                    <i class="fas fa-sunrise"></i> Breakfast Service
                </h4>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-bottom: 20px;">
                    <div class="form-group">
                        <label for="restaurant_breakfast_start">Breakfast Start Time</label>
                        <input type="time" 
                               id="restaurant_breakfast_start" 
                               name="restaurant_breakfast_start" 
                               class="form-control" 
                               value="<?php echo htmlspecialchars(getSetting('restaurant_breakfast_start', '06:00')); ?>" 
                               required>
                    </div>
                    <div class="form-group">
                        <label for="restaurant_breakfast_end">Breakfast End Time</label>
                        <input type="time" 
                               id="restaurant_breakfast_end" 
                               name="restaurant_breakfast_end" 
                               class="form-control" 
                               value="<?php echo htmlspecialchars(getSetting('restaurant_breakfast_end', '10:00')); ?>" 
                               required>
                    </div>
                </div>

                <h4 style="margin-top: 25px; margin-bottom: 15px; color: #0A1929; border-bottom: 1px solid #e0e0e0; padding-bottom: 10px;">
                    <i class="fas fa-sun"></i> Lunch Service
                </h4>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-bottom: 20px;">
                    <div class="form-group">
                        <label for="restaurant_lunch_start">Lunch Start Time</label>
                        <input type="time" 
                               id="restaurant_lunch_start" 
                               name="restaurant_lunch_start" 
                               class="form-control" 
                               value="<?php echo htmlspecialchars(getSetting('restaurant_lunch_start', '12:00')); ?>" 
                               required>
                    </div>
                    <div class="form-group">
                        <label for="restaurant_lunch_end">Lunch End Time</label>
                        <input type="time" 
                               id="restaurant_lunch_end" 
                               name="restaurant_lunch_end" 
                               class="form-control" 
                               value="<?php echo htmlspecialchars(getSetting('restaurant_lunch_end', '15:00')); ?>" 
                               required>
                    </div>
                </div>

                <h4 style="margin-top: 25px; margin-bottom: 15px; color: #0A1929; border-bottom: 1px solid #e0e0e0; padding-bottom: 10px;">
                    <i class="fas fa-moon"></i> Dinner Service
                </h4>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-bottom: 20px;">
                    <div class="form-group">
                        <label for="restaurant_dinner_start">Dinner Start Time</label>
                        <input type="time" 
                               id="restaurant_dinner_start" 
                               name="restaurant_dinner_start" 
                               class="form-control" 
                               value="<?php echo htmlspecialchars(getSetting('restaurant_dinner_start', '18:00')); ?>" 
                               required>
                    </div>
                    <div class="form-group">
                        <label for="restaurant_dinner_end">Dinner End Time</label>
                        <input type="time" 
                               id="restaurant_dinner_end" 
                               name="restaurant_dinner_end" 
                               class="form-control" 
                               value="<?php echo htmlspecialchars(getSetting('restaurant_dinner_end', '22:00')); ?>" 
                               required>
                    </div>
                </div>

                <button type="submit" name="restaurant_settings" value="1" class="btn-submit">
                    <i class="fas fa-save"></i> Save Restaurant Hours
                </button>
            </form>

            <div class="info-box">
                <h4><i class="fas fa-lightbulb"></i> How This Works</h4>
                <ul>
                    <li><strong>Minimum Advance Days:</strong> Set to 1 to block same-day reservations, or 0 to allow them</li>
                    <li><strong>Operating Hours:</strong> Reservations can only be made within these time windows</li>
                    <li><strong>Validation:</strong> The reservation form will validate against these hours</li>
                    <li><strong>Time Format:</strong> Use 24-hour format (e.g., 18:00 for 6 PM)</li>
                    <li><strong>Flexibility:</strong> Customers can still book any service (breakfast, lunch, or dinner)</li>
                </ul>
            </div>

            
            <?php
            // Get current email settings
            $email_settings = getAllEmailSettings();
            $current_settings = [];
            foreach ($email_settings as $key => $setting) {
                $current_settings[$key] = $setting['value'];
            }
            $smtp_password_configured = hasStoredSmtpPassword() && hasUsableSmtpPassword();
            ?>
            
            <form method="POST" action="booking-settings.php">
                <?php echo getCsrfField(); ?>
<input type="hidden" name="email_settings" value="1">
                
                <h3 id="email-settings" style="color: #0A1929; margin-top: 25px; margin-bottom: 15px; padding-bottom: 10px; border-bottom: 1px solid #e0e0e0;">
                    <i class="fas fa-server"></i> SMTP Server Settings
                </h3>
                
                <div class="form-group">
                    <label for="smtp_host">SMTP Host *</label>
                    <input type="text" 
                           id="smtp_host" 
                           name="smtp_host" 
                           class="form-control" 
                           value="<?php echo htmlspecialchars($current_settings['smtp_host'] ?? ''); ?>" 
                           required>
                    <p class="help-text">
                        <i class="fas fa-info-circle"></i>
                        Your SMTP server hostname (e.g., mail.yourdomain.com, smtp.gmail.com)
                    </p>
                </div>
                
                <div class="form-group">
                    <label for="smtp_port">SMTP Port *</label>
                    <input type="number" 
                           id="smtp_port" 
                           name="smtp_port" 
                           class="form-control" 
                           value="<?php echo htmlspecialchars($current_settings['smtp_port'] ?? ''); ?>" 
                           min="1" 
                           max="65535" 
                           required>
                    <p class="help-text">
                        <i class="fas fa-info-circle"></i>
                        Common ports: 465 (SSL), 587 (TLS), 25 (Standard)
                    </p>
                </div>
                
                <div class="form-group">
                    <label for="smtp_username">SMTP Username *</label>
                    <input type="text" 
                           id="smtp_username" 
                           name="smtp_username" 
                           class="form-control" 
                           value="<?php echo htmlspecialchars($current_settings['smtp_username'] ?? ''); ?>" 
                           required>
                    <p class="help-text">
                        <i class="fas fa-info-circle"></i>
                        Usually your full email address
                    </p>
                </div>
                
                <div class="form-group">
                    <label for="smtp_password">SMTP Password</label>
                    <input type="password" 
                           id="smtp_password" 
                           name="smtp_password" 
                           class="form-control" 
                           value="" 
                           placeholder="Leave blank to keep current password">
                    <p class="help-text">
                        <i class="fas fa-info-circle"></i>
                        Your email account password. Only enter if you want to change it.
                    </p>
                </div>
                
                <div class="form-group">
                    <label for="smtp_secure">SMTP Security</label>
                    <select id="smtp_secure" name="smtp_secure" class="form-control">
                        <option value="ssl" <?php echo ($current_settings['smtp_secure'] ?? 'ssl') === 'ssl' ? 'selected' : ''; ?>>SSL</option>
                        <option value="tls" <?php echo ($current_settings['smtp_secure'] ?? 'ssl') === 'tls' ? 'selected' : ''; ?>>TLS</option>
                        <option value="" <?php echo empty($current_settings['smtp_secure'] ?? '') ? 'selected' : ''; ?>>None</option>
                    </select>
                    <p class="help-text">
                        <i class="fas fa-info-circle"></i>
                        Security protocol for SMTP connection
                    </p>
                </div>
                
                <h3 style="color: #0A1929; margin-top: 30px; margin-bottom: 15px; padding-bottom: 10px; border-bottom: 1px solid #e0e0e0;">
                    <i class="fas fa-user"></i> Email Identity
                </h3>
                
                <div class="form-group">
                    <label for="email_from_name">From Name *</label>
                    <input type="text" 
                           id="email_from_name" 
                           name="email_from_name" 
                           class="form-control" 
                           value="<?php echo htmlspecialchars($current_settings['email_from_name'] ?? ''); ?>" 
                           required>
                    <p class="help-text">
                        <i class="fas fa-info-circle"></i>
                        Name that appears as the sender of emails
                    </p>
                </div>
                
                <div class="form-group">
                    <label for="email_from_email">From Email *</label>
                    <input type="email" 
                           id="email_from_email" 
                           name="email_from_email" 
                           class="form-control" 
                           value="<?php echo htmlspecialchars($current_settings['email_from_email'] ?? ''); ?>" 
                           required>
                    <p class="help-text">
                        <i class="fas fa-info-circle"></i>
                        Email address that appears as the sender
                    </p>
                </div>
                
                <div class="form-group">
                    <label for="email_admin_email">Admin Notification Email</label>
                    <input type="email" 
                           id="email_admin_email" 
                           name="email_admin_email" 
                           class="form-control" 
                           value="<?php echo htmlspecialchars($current_settings['email_admin_email'] ?? ''); ?>">
                    <p class="help-text">
                        <i class="fas fa-info-circle"></i>
                        Email address to receive booking notifications (optional)
                    </p>
                </div>

                <h3 style="color: #0A1929; margin-top: 30px; margin-bottom: 15px; padding-bottom: 10px; border-bottom: 1px solid #e0e0e0;">
                    <i class="fas fa-users-cog"></i> Department Notification Emails
                </h3>

                <div class="form-group">
                    <label for="booking_admin_email">Bookings Admin Email</label>
                    <input type="email"
                           id="booking_admin_email"
                           name="booking_admin_email"
                           class="form-control"
                           value="<?php echo htmlspecialchars($current_settings['booking_admin_email'] ?? getSetting('admin_notification_email', $current_settings['email_admin_email'] ?? '')); ?>">
                    <p class="help-text">
                        <i class="fas fa-info-circle"></i>
                        Receives room booking notifications from the website.
                    </p>
                </div>

                <div class="form-group">
                    <label for="conference_admin_email">Conference Admin Email</label>
                    <input type="email"
                           id="conference_admin_email"
                           name="conference_admin_email"
                           class="form-control"
                           value="<?php echo htmlspecialchars($current_settings['conference_admin_email'] ?? getSetting('conference_email', $current_settings['email_admin_email'] ?? '')); ?>">
                    <p class="help-text">
                        <i class="fas fa-info-circle"></i>
                        Receives conference enquiry notifications.
                    </p>
                </div>

                <div class="form-group">
                    <label for="gym_admin_email">Gym Admin Email</label>
                    <input type="email"
                           id="gym_admin_email"
                           name="gym_admin_email"
                           class="form-control"
                           value="<?php echo htmlspecialchars($current_settings['gym_admin_email'] ?? getSetting('gym_email', $current_settings['email_admin_email'] ?? '')); ?>">
                    <p class="help-text">
                        <i class="fas fa-info-circle"></i>
                        Receives gym booking request notifications.
                    </p>
                </div>

                <div class="form-group">
                    <label for="restaurant_admin_email">Restaurant Admin Email</label>
                    <input type="email"
                           id="restaurant_admin_email"
                           name="restaurant_admin_email"
                           class="form-control"
                              value="<?php echo htmlspecialchars($current_settings['restaurant_admin_email'] ?? getSetting('email_restaurant', $current_settings['email_admin_email'] ?? '')); ?>">
                    <p class="help-text">
                        <i class="fas fa-info-circle"></i>
                        Reserved for restaurant enquiry/booking notifications.
                    </p>
                </div>
                
                <h3 style="color: #0A1929; margin-top: 30px; margin-bottom: 15px; padding-bottom: 10px; border-bottom: 1px solid #e0e0e0;">
                    <i class="fas fa-sliders-h"></i> Email Settings
                </h3>
                
                <div class="form-group">
                    <label style="display: flex; align-items: center; gap: 10px;">
                        <input type="checkbox" 
                               id="email_bcc_admin" 
                               name="email_bcc_admin" 
                               value="1" 
                               <?php echo ($current_settings['email_bcc_admin'] ?? '1') === '1' ? 'checked' : ''; ?>>
                        <span>BCC Admin on all emails</span>
                    </label>
                    <p class="help-text">
                        <i class="fas fa-info-circle"></i>
                        Send a blind carbon copy of all emails to the admin email address
                    </p>
                </div>
                
                <div class="form-group">
                    <label style="display: flex; align-items: center; gap: 10px;">
                        <input type="checkbox" 
                               id="email_development_mode" 
                               name="email_development_mode" 
                               value="1" 
                               <?php echo ($current_settings['email_development_mode'] ?? '1') === '1' ? 'checked' : ''; ?>>
                        <span>Development Mode (Preview Only)</span>
                    </label>
                    <p class="help-text">
                        <i class="fas fa-info-circle"></i>
                        When checked, emails will be saved as preview files instead of being sent. 
                        Useful for testing on localhost.
                    </p>
                </div>
                
                <div class="form-group">
                    <label style="display: flex; align-items: center; gap: 10px;">
                        <input type="checkbox" 
                               id="email_log_enabled" 
                               name="email_log_enabled" 
                               value="1" 
                               <?php echo ($current_settings['email_log_enabled'] ?? '1') === '1' ? 'checked' : ''; ?>>
                        <span>Enable Email Logging</span>
                    </label>
                    <p class="help-text">
                        <i class="fas fa-info-circle"></i>
                        Log all email activity to logs/email-log.txt
                    </p>
                </div>
                
                <div class="form-group">
                    <label style="display: flex; align-items: center; gap: 10px;">
                        <input type="checkbox" 
                               id="email_preview_enabled" 
                               name="email_preview_enabled" 
                               value="1" 
                               <?php echo ($current_settings['email_preview_enabled'] ?? '1') === '1' ? 'checked' : ''; ?>>
                        <span>Enable Email Previews</span>
                    </label>
                    <p class="help-text">
                        <i class="fas fa-info-circle"></i>
                        Save HTML previews of emails in logs/email-previews/ folder
                    </p>
                </div>
                
                <button type="submit" class="btn-submit">
                    <i class="fas fa-save"></i> Save Email Settings
                </button>
            </form>

            <form method="POST" action="booking-settings.php#email-test-feedback" id="testEmailForm" style="margin-top: 18px; border-top: 1px solid #e0e0e0; padding-top: 18px;" data-smtp-password-configured="<?php echo $smtp_password_configured ? '1' : '0'; ?>">
                <?php echo getCsrfField(); ?>
                <input type="hidden" name="send_test_email" value="1">
                <div class="form-group">
                    <label for="test_email_to">Send Test Email To</label>
                    <input type="email"
                           id="test_email_to"
                           name="test_email_to"
                           class="form-control"
                           value="<?php echo htmlspecialchars($_POST['test_email_to'] ?? ($current_settings['email_admin_email'] ?? $current_settings['email_from_email'] ?? '')); ?>"
                           required>
                    <p class="help-text">
                        <i class="fas fa-vial"></i>
                        Sends a live SMTP test using the current settings (or preview file in development mode).
                    </p>
                </div>

                <button type="submit" class="btn-submit">
                    <i class="fas fa-paper-plane"></i> Send Test Email
                </button>
            </form>

            <div class="test-progress-card" id="testProgressCard" aria-live="polite">
                <div class="test-progress-header">
                    <i class="fas fa-tasks"></i>
                    <span>Email Test Pre-Flight Checks</span>
                </div>
                <div class="test-progress-bar-wrap">
                    <div class="test-progress-bar" id="testProgressBar"></div>
                </div>
                <ul class="test-checklist" id="testChecklist">
                    <li class="test-check-item" data-check="smtp_host"><span class="status"><i class="far fa-circle"></i></span>SMTP host is set</li>
                    <li class="test-check-item" data-check="smtp_port"><span class="status"><i class="far fa-circle"></i></span>SMTP port is valid</li>
                    <li class="test-check-item" data-check="smtp_username"><span class="status"><i class="far fa-circle"></i></span>SMTP username is set</li>
                    <li class="test-check-item" data-check="smtp_password"><span class="status"><i class="far fa-circle"></i></span>SMTP password is configured</li>
                    <li class="test-check-item" data-check="smtp_secure"><span class="status"><i class="far fa-circle"></i></span>SMTP security is valid (SSL/TLS/None)</li>
                    <li class="test-check-item" data-check="email_from_email"><span class="status"><i class="far fa-circle"></i></span>From email is valid</li>
                    <li class="test-check-item" data-check="test_email_to"><span class="status"><i class="far fa-circle"></i></span>Test recipient email is valid</li>
                </ul>
            </div>

            <div id="email-test-feedback" style="margin-top: 16px;">
                <?php if ($test_email_feedback): ?>
                    <div class="alert <?php echo $test_email_feedback['type'] === 'success' ? 'alert-success' : 'alert-error'; ?>" style="margin-top: 0;">
                        <i class="fas <?php echo $test_email_feedback['type'] === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle'; ?>"></i>
                        <div>
                            <strong><?php echo htmlspecialchars($test_email_feedback['message']); ?></strong>
                            <?php if (!empty($test_email_feedback['details'])): ?>
                                <ul style="margin: 8px 0 0 18px; padding: 0;">
                                    <?php foreach ($test_email_feedback['details'] as $detail): ?>
                                        <li><?php echo htmlspecialchars($detail); ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
            
            <div class="info-box" style="margin-top: 30px;">
                <h4><i class="fas fa-lightbulb"></i> Email Configuration Tips</h4>
                <ul>
                    <li><strong>Testing:</strong> Use Development Mode to test emails without actually sending them</li>
                    <li><strong>Security:</strong> Passwords are encrypted in the database for security</li>
                    <li><strong>Logs:</strong> Check logs/email-log.txt for email activity history</li>
                    <li><strong>Preview:</strong> View email previews in logs/email-previews/ folder</li>
                    <li><strong>Backup:</strong> Your previous email settings were backed up during migration</li>
                </ul>
            </div>
        </div>
        
        <!-- Hotel Contact Information Card -->
        <div class="settings-card" id="contact-settings">
            <h2><i class="fas fa-address-card" style="color: #D4AF37;"></i> Hotel Contact Information</h2>
            <p style="color:#555; margin-top:-10px; margin-bottom:20px;">
                These details appear on your website — phone links, email links, footer, and booking buttons.
            </p>

            <form method="POST" action="booking-settings.php#contact-settings">
                <?php echo getCsrfField(); ?>
                <input type="hidden" name="contact_settings" value="1">

                <h4 style="margin-bottom:15px; color:#0A1929; border-bottom:1px solid #e0e0e0; padding-bottom:8px;">
                    <i class="fas fa-hotel"></i> Hotel Identity
                </h4>
                <div style="display:grid; grid-template-columns:1fr 1fr; gap:15px; margin-bottom:20px;">
                    <div class="form-group">
                        <label for="site_name">Hotel Name <span style="color:#dc3545;">*</span></label>
                        <input type="text" id="site_name" name="site_name" class="form-control"
                               value="<?php echo htmlspecialchars(getSetting('site_name', '')); ?>"
                               placeholder="e.g. Liwonde Sun Hotel" maxlength="150" required>
                    </div>
                    <div class="form-group">
                        <label for="site_tagline">Tagline</label>
                        <input type="text" id="site_tagline" name="site_tagline" class="form-control"
                               value="<?php echo htmlspecialchars(getSetting('site_tagline', '')); ?>"
                               placeholder="e.g. Where Comfort Meets Nature" maxlength="255">
                    </div>
                </div>
                <div class="form-group" style="margin-bottom:20px;">
                    <label for="site_url">Website URL</label>
                    <input type="text" id="site_url" name="site_url" class="form-control"
                           value="<?php echo htmlspecialchars(getSetting('site_url', '')); ?>"
                           placeholder="https://www.example.com" maxlength="255">
                </div>

                <h4 style="margin-top:25px; margin-bottom:15px; color:#0A1929; border-bottom:1px solid #e0e0e0; padding-bottom:8px;">
                    <i class="fas fa-phone"></i> Phone Numbers
                </h4>
                <div style="display:grid; grid-template-columns:1fr 1fr; gap:15px; margin-bottom:20px;">
                    <div class="form-group">
                        <label for="phone_main">Main Phone Number</label>
                        <input type="tel" id="phone_main" name="phone_main" class="form-control"
                               value="<?php echo htmlspecialchars(getSetting('phone_main', '')); ?>"
                               placeholder="e.g. +265 999 123 456" maxlength="30">
                        <p class="help-text"><i class="fas fa-info-circle"></i> Used for the "Call Reservations" button on the website.</p>
                    </div>
                    <div class="form-group">
                        <label for="phone_secondary">Secondary Phone</label>
                        <input type="tel" id="phone_secondary" name="phone_secondary" class="form-control"
                               value="<?php echo htmlspecialchars(getSetting('phone_secondary', '')); ?>"
                               placeholder="e.g. +265 888 654 321" maxlength="30">
                    </div>
                </div>

                <h4 style="margin-top:25px; margin-bottom:15px; color:#0A1929; border-bottom:1px solid #e0e0e0; padding-bottom:8px;">
                    <i class="fas fa-envelope"></i> Email Addresses
                </h4>
                <div style="display:grid; grid-template-columns:1fr 1fr; gap:15px; margin-bottom:20px;">
                    <div class="form-group">
                        <label for="email_main">General Enquiries Email</label>
                        <input type="email" id="email_main" name="email_main" class="form-control"
                               value="<?php echo htmlspecialchars(getSetting('email_main', '')); ?>"
                               placeholder="info@example.com" maxlength="254">
                    </div>
                    <div class="form-group">
                        <label for="email_reservations">Reservations Email</label>
                        <input type="email" id="email_reservations" name="email_reservations" class="form-control"
                               value="<?php echo htmlspecialchars(getSetting('email_reservations', '')); ?>"
                               placeholder="reservations@example.com" maxlength="254">
                        <p class="help-text"><i class="fas fa-info-circle"></i> Used for the "Email Booking" button on room pages.</p>
                    </div>
                    <div class="form-group">
                        <label for="email_info">Info / General Email</label>
                        <input type="email" id="email_info" name="email_info" class="form-control"
                               value="<?php echo htmlspecialchars(getSetting('email_info', '')); ?>"
                               placeholder="info@example.com" maxlength="254">
                    </div>
                </div>

                <h4 style="margin-top:25px; margin-bottom:15px; color:#0A1929; border-bottom:1px solid #e0e0e0; padding-bottom:8px;">
                    <i class="fas fa-map-marker-alt"></i> Address
                </h4>
                <div style="display:grid; grid-template-columns:1fr 1fr; gap:15px; margin-bottom:20px;">
                    <div class="form-group">
                        <label for="address_line1">Address Line 1</label>
                        <input type="text" id="address_line1" name="address_line1" class="form-control"
                               value="<?php echo htmlspecialchars(getSetting('address_line1', '')); ?>"
                               placeholder="e.g. P.O. Box 123" maxlength="255">
                    </div>
                    <div class="form-group">
                        <label for="address_line2">Address Line 2</label>
                        <input type="text" id="address_line2" name="address_line2" class="form-control"
                               value="<?php echo htmlspecialchars(getSetting('address_line2', '')); ?>"
                               placeholder="e.g. Liwonde Road" maxlength="255">
                    </div>
                    <div class="form-group">
                        <label for="address_city">City / Town</label>
                        <input type="text" id="address_city" name="address_city" class="form-control"
                               value="<?php echo htmlspecialchars(getSetting('address_city', '')); ?>"
                               placeholder="e.g. Liwonde" maxlength="100">
                    </div>
                    <div class="form-group">
                        <label for="address_country">Country</label>
                        <input type="text" id="address_country" name="address_country" class="form-control"
                               value="<?php echo htmlspecialchars(getSetting('address_country', '')); ?>"
                               placeholder="e.g. Malawi" maxlength="100">
                    </div>
                </div>

                <button type="submit" class="btn-submit">
                    <i class="fas fa-save"></i> Save Contact Information
                </button>
            </form>

            <div class="info-box" style="margin-top:25px;">
                <h4><i class="fas fa-lightbulb"></i> How This Affects Your Website</h4>
                <ul>
                    <li><strong>Call Reservations button:</strong> Uses Main Phone Number — the button only appears when a number is set.</li>
                    <li><strong>Email Booking button:</strong> Uses Reservations Email on room and booking pages.</li>
                    <li><strong>Footer &amp; Contact pages:</strong> Display all contact details set here.</li>
                    <li><strong>Changes are live immediately</strong> — no cache clearing needed.</li>
                </ul>
            </div>
        </div>

        <div class="info-box" style="background: #fff3cd; border-left-color: #ffc107;">
            <h4><i class="fas fa-exclamation-triangle"></i> Important Security Note</h4>
            <p style="color: #856404; margin: 0;">
                <strong>All email settings are now stored in the database.</strong> No more hardcoded passwords in files. 
                Your SMTP password is encrypted for security. You can update it anytime in this admin panel.
            </p>
        </div>

    <script>
    (function () {
        const form = document.getElementById('testEmailForm');
        if (!form) return;

        const submitBtn = form.querySelector('button[type="submit"]');
        const progressCard = document.getElementById('testProgressCard');
        const progressBar = document.getElementById('testProgressBar');
        const checklist = document.getElementById('testChecklist');
        let allowSubmit = false;

        const checkDefs = [
            { key: 'smtp_host', field: 'smtp_host', test: () => document.getElementById('smtp_host')?.value.trim() !== '' },
            { key: 'smtp_port', field: 'smtp_port', test: () => {
                const val = document.getElementById('smtp_port')?.value.trim() || '';
                return /^\d+$/.test(val) && Number(val) >= 1 && Number(val) <= 65535;
            }},
            { key: 'smtp_username', field: 'smtp_username', test: () => document.getElementById('smtp_username')?.value.trim() !== '' },
            { key: 'smtp_password', field: 'smtp_password', test: () => form.dataset.smtpPasswordConfigured === '1' },
            { key: 'smtp_secure', field: 'smtp_secure', test: () => {
                const val = (document.getElementById('smtp_secure')?.value || '').toLowerCase();
                return ['ssl', 'tls', ''].includes(val);
            }},
            { key: 'email_from_email', field: 'email_from_email', test: () => {
                const val = document.getElementById('email_from_email')?.value.trim() || '';
                return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(val);
            }},
            { key: 'test_email_to', field: 'test_email_to', test: () => {
                const val = document.getElementById('test_email_to')?.value.trim() || '';
                return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(val);
            }}
        ];

        function setFieldError(fieldId, isError) {
            const field = document.getElementById(fieldId);
            if (!field) return;
            field.classList.toggle('field-error', !!isError);
        }

        function resetChecklist() {
            progressBar.style.width = '0%';
            checkDefs.forEach(def => {
                setFieldError(def.field, false);
                const item = checklist.querySelector(`[data-check="${def.key}"]`);
                if (!item) return;
                item.classList.remove('pass', 'fail', 'running');
                item.querySelector('.status').innerHTML = '<i class="far fa-circle"></i>';
            });
        }

        function wait(ms) {
            return new Promise(resolve => setTimeout(resolve, ms));
        }

        async function runChecks() {
            progressCard.style.display = 'block';
            resetChecklist();

            let passCount = 0;
            for (let i = 0; i < checkDefs.length; i++) {
                const def = checkDefs[i];
                const item = checklist.querySelector(`[data-check="${def.key}"]`);
                if (!item) continue;

                item.classList.add('running');
                item.querySelector('.status').innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
                await wait(150);

                const ok = def.test();
                item.classList.remove('running');

                if (ok) {
                    passCount += 1;
                    item.classList.add('pass');
                    item.querySelector('.status').innerHTML = '<i class="fas fa-check-circle"></i>';
                    setFieldError(def.field, false);
                } else {
                    item.classList.add('fail');
                    item.querySelector('.status').innerHTML = '<i class="fas fa-times-circle"></i>';
                    setFieldError(def.field, true);
                }

                progressBar.style.width = `${Math.round(((i + 1) / checkDefs.length) * 100)}%`;
            }

            return passCount === checkDefs.length;
        }

        form.addEventListener('submit', async function (e) {
            if (allowSubmit) return;

            e.preventDefault();
            if (submitBtn) {
                submitBtn.disabled = true;
                submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Running Checks...';
            }

            const allPassed = await runChecks();
            if (!allPassed) {
                if (submitBtn) {
                    submitBtn.disabled = false;
                    submitBtn.innerHTML = '<i class="fas fa-paper-plane"></i> Send Test Email';
                }
                return;
            }

            if (submitBtn) {
                submitBtn.innerHTML = '<i class="fas fa-circle-notch fa-spin"></i> Sending Test Email...';
            }

            allowSubmit = true;
            form.submit();
        });
    })();
    </script>

    <?php require_once 'includes/admin-footer.php'; ?>