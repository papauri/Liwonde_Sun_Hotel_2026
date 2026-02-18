<?php
/**
 * Forgot Password Page
 * Sends a password reset link via email to the admin user
 */

session_start();

// If already logged in, redirect to dashboard
if (isset($_SESSION['admin_user_id'])) {
    header('Location: dashboard.php');
    exit;
}

require_once '../config/database.php';

$error_message = '';
$success_message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    
    if (empty($email)) {
        $error_message = 'Please enter your email address.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error_message = 'Please enter a valid email address.';
    } else {
        // Ensure password_resets table exists before doing anything
        try {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS password_resets (
                    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    user_id INT UNSIGNED NOT NULL,
                    token VARCHAR(255) NOT NULL,
                    expires_at DATETIME NOT NULL,
                    used_at DATETIME DEFAULT NULL,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_token (token),
                    INDEX idx_user_id (user_id),
                    CONSTRAINT fk_password_resets_user FOREIGN KEY (user_id) REFERENCES admin_users(id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
        } catch (PDOException $e) {
            // Table likely already exists
        }
        
        try {
            // Look up user by email
            $stmt = $pdo->prepare("SELECT id, username, email, full_name FROM admin_users WHERE email = ? AND is_active = 1");
            $stmt->execute([$email]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($user) {
                // Generate a secure token
                $token = bin2hex(random_bytes(32));
                $expires = date('Y-m-d H:i:s', strtotime('+1 hour'));
                
                // Store token in database
                $stmt = $pdo->prepare("
                    INSERT INTO password_resets (user_id, token, expires_at, created_at)
                    VALUES (?, ?, ?, NOW())
                ");
                $stmt->execute([$user['id'], hash('sha256', $token), $expires]);
                
                // Build reset URL
                $site_url = getSetting('site_url', '');
                if (empty($site_url)) {
                    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
                    $site_url = $protocol . '://' . $_SERVER['HTTP_HOST'];
                }
                $reset_url = rtrim($site_url, '/') . '/admin/reset-password.php?token=' . $token;
                
                // Send reset email
                require_once '../config/email.php';
                
                $site_name = getSetting('site_name', 'Hotel Admin');
                
                $htmlBody = '
                <!DOCTYPE html>
                <html>
                <head><meta charset="UTF-8"></head>
                <body style="margin: 0; padding: 0; background: #f5f5f5; font-family: Arial, sans-serif;">
                    <div style="max-width: 600px; margin: 40px auto; background: white; border-radius: 12px; overflow: hidden; box-shadow: 0 4px 20px rgba(0,0,0,0.1);">
                        <div style="background: linear-gradient(135deg, #0A1929 0%, #1a2f45 100%); padding: 40px 30px; text-align: center;">
                            <h1 style="color: #D4AF37; font-size: 24px; margin: 0 0 8px;">Password Reset</h1>
                            <p style="color: rgba(255,255,255,0.7); font-size: 14px; margin: 0;">' . htmlspecialchars($site_name) . '</p>
                        </div>
                        <div style="padding: 40px 30px;">
                            <p style="color: #333; font-size: 15px; line-height: 1.6;">Hi <strong>' . htmlspecialchars($user['full_name']) . '</strong>,</p>
                            <p style="color: #555; font-size: 14px; line-height: 1.6;">We received a request to reset the password for your admin account (<strong>' . htmlspecialchars($user['username']) . '</strong>).</p>
                            <p style="color: #555; font-size: 14px; line-height: 1.6;">Click the button below to create a new password. This link expires in <strong>1 hour</strong>.</p>
                            <div style="text-align: center; margin: 32px 0;">
                                <a href="' . htmlspecialchars($reset_url) . '" style="display: inline-block; background: linear-gradient(135deg, #D4AF37 0%, #c49b2e 100%); color: #050D14; padding: 14px 40px; border-radius: 10px; text-decoration: none; font-weight: 700; font-size: 15px; letter-spacing: 0.5px;">
                                    Reset Password
                                </a>
                            </div>
                            <p style="color: #999; font-size: 12px; line-height: 1.6;">If you didn\'t request this, please ignore this email. Your password will remain unchanged.</p>
                            <hr style="border: none; border-top: 1px solid #eee; margin: 24px 0;">
                            <p style="color: #bbb; font-size: 11px; text-align: center;">This is an automated email from ' . htmlspecialchars($site_name) . ' Admin Panel</p>
                        </div>
                    </div>
                </body>
                </html>';
                
                $result = sendEmail(
                    $user['email'],
                    $user['full_name'],
                    'Password Reset - ' . $site_name . ' Admin',
                    $htmlBody
                );
                
                if ($result['success']) {
                    header('Location: login.php?reset=sent');
                    exit;
                } else {
                    error_log("Password reset email failed: " . $result['message']);
                    // Still show success to prevent email enumeration
                    header('Location: login.php?reset=sent');
                    exit;
                }
            } else {
                // Don't reveal if email exists - always show success
                header('Location: login.php?reset=sent');
                exit;
            }
        } catch (PDOException $e) {
            error_log("Password reset error: " . $e->getMessage());
            $error_message = 'An error occurred. Please try again.';
        }
    }
}

$site_name = getSetting('site_name');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password | <?php echo htmlspecialchars($site_name); ?></title>
    
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;500;600;700&family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <style>
        :root {
            --gold: #D4AF37;
            --navy: #0A1929;
            --deep-navy: #050D14;
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Poppins', sans-serif;
            background: linear-gradient(135deg, var(--deep-navy) 0%, var(--navy) 50%, #1a2f45 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            position: relative;
            overflow: hidden;
        }
        body::before {
            content: '';
            position: absolute;
            top: -50%; left: -50%; width: 200%; height: 200%;
            background: radial-gradient(ellipse at 30% 20%, rgba(212, 175, 55, 0.06) 0%, transparent 50%),
                        radial-gradient(ellipse at 70% 80%, rgba(212, 175, 55, 0.04) 0%, transparent 50%);
            animation: bgFloat 15s ease-in-out infinite;
        }
        @keyframes bgFloat { 0%, 100% { transform: translate(0, 0); } 50% { transform: translate(-2%, -1%); } }
        .login-container { width: 100%; max-width: 440px; position: relative; z-index: 1; }
        .login-card {
            background: white;
            border-radius: 24px;
            padding: 48px 40px;
            box-shadow: 0 25px 80px rgba(0, 0, 0, 0.35), 0 0 0 1px rgba(212, 175, 55, 0.1);
        }
        .login-header { text-align: center; margin-bottom: 36px; }
        .login-header .logo {
            width: 80px; height: 80px;
            background: linear-gradient(135deg, var(--gold) 0%, #c49b2e 100%);
            border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            margin: 0 auto 20px;
            box-shadow: 0 8px 30px rgba(212, 175, 55, 0.35);
        }
        .login-header .logo i { font-size: 36px; color: var(--deep-navy); }
        .login-header h1 { font-family: 'Playfair Display', serif; font-size: 24px; color: var(--navy); margin-bottom: 10px; }
        .login-header p { color: #888; font-size: 13px; line-height: 1.5; }
        .alert-danger {
            background: #fff0f0; border-left: 4px solid #dc3545; color: #721c24;
            padding: 12px 16px; border-radius: 10px; margin-bottom: 24px; font-size: 13px;
        }
        .form-group { margin-bottom: 20px; }
        .form-group label { display: block; font-weight: 600; color: var(--navy); margin-bottom: 8px; font-size: 13px; letter-spacing: 0.3px; }
        .input-wrapper { position: relative; }
        .input-wrapper i {
            position: absolute; left: 16px; top: 50%; transform: translateY(-50%);
            color: #aaa; font-size: 15px; z-index: 2; pointer-events: none; transition: color 0.3s;
        }
        .input-wrapper:focus-within i { color: var(--gold); }
        .form-control {
            width: 100%; padding: 14px 16px 14px 46px;
            border: 2px solid #e8e8e8; border-radius: 12px; font-size: 14px;
            transition: all 0.3s; font-family: 'Poppins', sans-serif; background: #fafafa; color: var(--navy);
        }
        .form-control::placeholder { color: #bbb; font-weight: 300; }
        .form-control:focus { outline: none; border-color: var(--gold); box-shadow: 0 0 0 4px rgba(212, 175, 55, 0.1); background: #fff; }
        .form-control:hover { border-color: #ccc; }
        .btn-login {
            width: 100%; padding: 15px;
            background: linear-gradient(135deg, var(--gold) 0%, #c49b2e 100%);
            color: var(--deep-navy); border: none; border-radius: 12px; font-size: 15px; font-weight: 700;
            cursor: pointer; transition: all 0.3s; text-transform: uppercase; letter-spacing: 1.5px; margin-top: 8px;
        }
        .btn-login:hover { transform: translateY(-2px); box-shadow: 0 8px 30px rgba(212, 175, 55, 0.4); }
        .login-footer {
            margin-top: 28px; text-align: center; padding-top: 20px; border-top: 1px solid #f0f0f0;
        }
        .login-footer a { color: #888; text-decoration: none; font-size: 13px; font-weight: 500; transition: color 0.3s; }
        .login-footer a:hover { color: var(--gold); }
        .login-footer a i { margin-right: 4px; }
        @media (max-width: 480px) { .login-card { padding: 36px 24px; border-radius: 20px; } }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="login-card">
            <div class="login-header">
                <div class="logo">
                    <i class="fas fa-key"></i>
                </div>
                <h1>Reset Password</h1>
                <p>Enter the email address associated with your admin account and we'll send you a reset link.</p>
            </div>

            <?php if ($error_message): ?>
                <div class="alert-danger">
                    <?php echo htmlspecialchars($error_message); ?>
                </div>
            <?php endif; ?>

            <form method="POST">
                <div class="form-group">
                    <label for="email">Email Address</label>
                    <div class="input-wrapper">
                        <i class="fas fa-envelope"></i>
                        <input type="email" id="email" name="email" class="form-control" 
                               placeholder="Enter your email" required autofocus
                               value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>">
                    </div>
                </div>

                <button type="submit" class="btn-login">
                    <i class="fas fa-paper-plane"></i> Send Reset Link
                </button>
            </form>

            <div class="login-footer">
                <a href="login.php">
                    <i class="fas fa-arrow-left"></i> Back to Login
                </a>
            </div>
        </div>
    </div>
</body>
</html>
