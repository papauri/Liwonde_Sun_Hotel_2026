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

// Handle setting updates
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Check which form was submitted
        if (isset($_POST['max_advance_booking_days'])) {
            // Booking settings form
            $max_advance_days = (int)($_POST['max_advance_booking_days'] ?? 30);
            
            // Validate input
            if ($max_advance_days < 1) {
                throw new Exception('Maximum advance booking days must be at least 1');
            }
            
            if ($max_advance_days > 365) {
                throw new Exception('Maximum advance booking days cannot exceed 365 (one year)');
            }
            
            // Update setting in database
            $stmt = $pdo->prepare("UPDATE site_settings SET setting_value = ?, updated_at = NOW() WHERE setting_key = 'max_advance_booking_days'");
            $stmt->execute([$max_advance_days]);
            
            // Clear the setting cache (both in-memory and file cache)
            global $_SITE_SETTINGS;
            if (isset($_SITE_SETTINGS['max_advance_booking_days'])) {
                unset($_SITE_SETTINGS['max_advance_booking_days']);
            }
            // Clear the file cache
            deleteCache("setting_max_advance_booking_days");
            
            $message = "Maximum advance booking days updated to {$max_advance_days} days successfully!";
            
        } elseif (isset($_POST['email_settings'])) {
            // Email settings form
            $email_settings = [
                'smtp_host' => $_POST['smtp_host'] ?? '',
                'smtp_port' => $_POST['smtp_port'] ?? '',
                'smtp_username' => $_POST['smtp_username'] ?? '',
                'smtp_password' => $_POST['smtp_password'] ?? '',
                'smtp_secure' => $_POST['smtp_secure'] ?? 'ssl',
                'email_from_name' => $_POST['email_from_name'] ?? '',
                'email_from_email' => $_POST['email_from_email'] ?? '',
                'email_admin_email' => $_POST['email_admin_email'] ?? '',
                'email_bcc_admin' => isset($_POST['email_bcc_admin']) ? '1' : '0',
                'email_development_mode' => isset($_POST['email_development_mode']) ? '1' : '0',
                'email_log_enabled' => isset($_POST['email_log_enabled']) ? '1' : '0',
                'email_preview_enabled' => isset($_POST['email_preview_enabled']) ? '1' : '0',
            ];
            
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
            
            // Update email settings in database
            foreach ($email_settings as $key => $value) {
                $is_encrypted = ($key === 'smtp_password' && !empty($value));
                updateEmailSetting($key, $value, '', $is_encrypted);
            }
            
            $message = "Email settings updated successfully!";
        }
        
    } catch (Exception $e) {
        $error = 'Error: ' . $e->getMessage();
    }
}

// Get current setting
$current_max_days = (int)getSetting('max_advance_booking_days', 30);
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
            <h2><i class="fas fa-envelope" style="color: #D4AF37;"></i> Email Configuration</h2>
            
            <?php
            // Get current email settings
            $email_settings = getAllEmailSettings();
            $current_settings = [];
            foreach ($email_settings as $key => $setting) {
                $current_settings[$key] = $setting['value'];
            }
            ?>
            
            <form method="POST" action="booking-settings.php">
                <input type="hidden" name="email_settings" value="1">
                
                <h3 style="color: #0A1929; margin-top: 25px; margin-bottom: 15px; padding-bottom: 10px; border-bottom: 1px solid #e0e0e0;">
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
        
        <div class="info-box" style="background: #fff3cd; border-left-color: #ffc107;">
            <h4><i class="fas fa-exclamation-triangle"></i> Important Security Note</h4>
            <p style="color: #856404; margin: 0;">
                <strong>All email settings are now stored in the database.</strong> No more hardcoded passwords in files. 
                Your SMTP password is encrypted for security. You can update it anytime in this admin panel.
            </p>
        </div>

    <?php require_once 'includes/admin-footer.php'; ?>
