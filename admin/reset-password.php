<?php
/**
 * Reset Password Page
 * Allows users to set a new password using a valid reset token
 */

session_start();

// If already logged in, redirect to dashboard
if (isset($_SESSION['admin_user_id'])) {
    header('Location: dashboard.php');
    exit;
}

require_once '../config/database.php';

$error_message = '';
$token = $_GET['token'] ?? $_POST['token'] ?? '';
$valid_token = false;
$user_data = null;

// Ensure password_resets table exists
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
    // Table may already exist - that's fine
}

// Validate token
if (!empty($token)) {
    $hashed_token = hash('sha256', $token);
    
    try {
        $stmt = $pdo->prepare("
            SELECT pr.*, au.username, au.full_name, au.email 
            FROM password_resets pr 
            JOIN admin_users au ON pr.user_id = au.id 
            WHERE pr.token = ? AND pr.expires_at > NOW() AND pr.used_at IS NULL 
            ORDER BY pr.created_at DESC 
            LIMIT 1
        ");
        $stmt->execute([$hashed_token]);
        $reset = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($reset) {
            $valid_token = true;
            $user_data = $reset;
        } else {
            $error_message = 'This reset link is invalid or has expired. Please request a new one.';
        }
    } catch (PDOException $e) {
        error_log("Token validation error: " . $e->getMessage());
        $error_message = 'An error occurred. Please try again.';
    }
} else {
    $error_message = 'No reset token provided.';
}

// Handle password reset
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $valid_token) {
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    
    if (empty($password)) {
        $error_message = 'Please enter a new password.';
    } elseif (strlen($password) < 8) {
        $error_message = 'Password must be at least 8 characters long.';
    } elseif ($password !== $confirm_password) {
        $error_message = 'Passwords do not match.';
    } else {
        try {
            // Update the password
            $password_hash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("UPDATE admin_users SET password_hash = ?, updated_at = NOW() WHERE id = ?");
            $stmt->execute([$password_hash, $user_data['user_id']]);
            
            // Mark token as used
            $hashed_token = hash('sha256', $token);
            $stmt = $pdo->prepare("UPDATE password_resets SET used_at = NOW() WHERE token = ?");
            $stmt->execute([$hashed_token]);
            
            // Invalidate all other reset tokens for this user
            $stmt = $pdo->prepare("UPDATE password_resets SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL");
            $stmt->execute([$user_data['user_id']]);
            
            // Reset failed login attempts
            $pdo->prepare("UPDATE admin_users SET failed_login_attempts = 0 WHERE id = ?")->execute([$user_data['user_id']]);
            
            // Log the password reset
            $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
            $ua = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500);
            try {
                $log_stmt = $pdo->prepare("INSERT INTO admin_activity_log (user_id, username, action, details, ip_address, user_agent) VALUES (?, ?, 'password_reset', 'Password reset via email token', ?, ?)");
                $log_stmt->execute([$user_data['user_id'], $user_data['username'], $ip, $ua]);
            } catch (PDOException $le) {
                // Don't block reset if logging fails
            }
            
            header('Location: login.php?reset=success');
            exit;
        } catch (PDOException $e) {
            error_log("Password reset error: " . $e->getMessage());
            $error_message = 'Failed to reset password. Please try again.';
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
    <title>Reset Password | <?php echo htmlspecialchars($site_name); ?></title>
    
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;500;600;700&family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <style>
        :root { --gold: #D4AF37; --navy: #0A1929; --deep-navy: #050D14; }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Poppins', sans-serif;
            background: linear-gradient(135deg, var(--deep-navy) 0%, var(--navy) 50%, #1a2f45 100%);
            min-height: 100vh; display: flex; align-items: center; justify-content: center;
            padding: 20px; position: relative; overflow: hidden;
        }
        body::before {
            content: ''; position: absolute; top: -50%; left: -50%; width: 200%; height: 200%;
            background: radial-gradient(ellipse at 30% 20%, rgba(212, 175, 55, 0.06) 0%, transparent 50%),
                        radial-gradient(ellipse at 70% 80%, rgba(212, 175, 55, 0.04) 0%, transparent 50%);
            animation: bgFloat 15s ease-in-out infinite;
        }
        @keyframes bgFloat { 0%, 100% { transform: translate(0, 0); } 50% { transform: translate(-2%, -1%); } }
        .login-container { width: 100%; max-width: 440px; position: relative; z-index: 1; }
        .login-card {
            background: white; border-radius: 24px; padding: 48px 40px;
            box-shadow: 0 25px 80px rgba(0, 0, 0, 0.35), 0 0 0 1px rgba(212, 175, 55, 0.1);
        }
        .login-header { text-align: center; margin-bottom: 36px; }
        .login-header .logo {
            width: 80px; height: 80px;
            background: linear-gradient(135deg, var(--gold) 0%, #c49b2e 100%);
            border-radius: 50%; display: flex; align-items: center; justify-content: center;
            margin: 0 auto 20px; box-shadow: 0 8px 30px rgba(212, 175, 55, 0.35);
        }
        .login-header .logo i { font-size: 36px; color: var(--deep-navy); }
        .login-header h1 { font-family: 'Playfair Display', serif; font-size: 24px; color: var(--navy); margin-bottom: 8px; }
        .login-header p { color: #888; font-size: 13px; line-height: 1.5; }
        .alert-danger {
            background: #fff0f0; border-left: 4px solid #dc3545; color: #721c24;
            padding: 12px 16px; border-radius: 10px; margin-bottom: 24px; font-size: 13px;
            display: flex; align-items: center; gap: 8px;
        }
        .alert-danger::before { content: '\f071'; font-family: 'Font Awesome 6 Free'; font-weight: 900; color: #dc3545; }
        .form-group { margin-bottom: 20px; }
        .form-group label { display: block; font-weight: 600; color: var(--navy); margin-bottom: 8px; font-size: 13px; letter-spacing: 0.3px; }
        .input-wrapper { position: relative; }
        .input-wrapper i.field-icon {
            position: absolute; left: 16px; top: 50%; transform: translateY(-50%);
            color: #aaa; font-size: 15px; z-index: 2; pointer-events: none; transition: color 0.3s;
        }
        .input-wrapper:focus-within i.field-icon { color: var(--gold); }
        .form-control {
            width: 100%; padding: 14px 46px 14px 46px;
            border: 2px solid #e8e8e8; border-radius: 12px; font-size: 14px;
            transition: all 0.3s; font-family: 'Poppins', sans-serif; background: #fafafa; color: var(--navy);
        }
        .form-control::placeholder { color: #bbb; font-weight: 300; }
        .form-control:focus { outline: none; border-color: var(--gold); box-shadow: 0 0 0 4px rgba(212, 175, 55, 0.1); background: #fff; }
        .form-control:hover { border-color: #ccc; }
        .password-toggle {
            position: absolute; right: 16px; top: 50%; transform: translateY(-50%);
            background: none; border: none; cursor: pointer; color: #aaa; font-size: 15px; padding: 4px; z-index: 2; transition: color 0.3s;
        }
        .password-toggle:hover { color: var(--gold); }
        .password-strength { height: 4px; border-radius: 2px; margin-top: 8px; background: #eee; overflow: hidden; }
        .password-strength-bar { height: 100%; border-radius: 2px; transition: width 0.3s, background 0.3s; width: 0; }
        .password-hint { font-size: 11px; color: #999; margin-top: 6px; }
        .btn-login {
            width: 100%; padding: 15px;
            background: linear-gradient(135deg, var(--gold) 0%, #c49b2e 100%);
            color: var(--deep-navy); border: none; border-radius: 12px; font-size: 15px; font-weight: 700;
            cursor: pointer; transition: all 0.3s; text-transform: uppercase; letter-spacing: 1.5px; margin-top: 8px;
        }
        .btn-login:hover { transform: translateY(-2px); box-shadow: 0 8px 30px rgba(212, 175, 55, 0.4); }
        .login-footer { margin-top: 28px; text-align: center; padding-top: 20px; border-top: 1px solid #f0f0f0; }
        .login-footer a { color: #888; text-decoration: none; font-size: 13px; font-weight: 500; transition: color 0.3s; }
        .login-footer a:hover { color: var(--gold); }
        .login-footer a i { margin-right: 4px; }
        .user-badge {
            display: inline-flex; align-items: center; gap: 6px; background: #f0f4ff; padding: 6px 14px;
            border-radius: 20px; font-size: 13px; color: var(--navy); font-weight: 500; margin-top: 10px;
        }
        .user-badge i { color: var(--gold); }
        @media (max-width: 480px) { .login-card { padding: 36px 24px; border-radius: 20px; } }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="login-card">
            <div class="login-header">
                <div class="logo">
                    <i class="fas fa-shield-alt"></i>
                </div>
                <h1>New Password</h1>
                <?php if ($valid_token && $user_data): ?>
                    <p>Create a new password for your account</p>
                    <div class="user-badge">
                        <i class="fas fa-user"></i> <?php echo htmlspecialchars($user_data['username']); ?>
                    </div>
                <?php else: ?>
                    <p>Reset link verification</p>
                <?php endif; ?>
            </div>

            <?php if ($error_message): ?>
                <div class="alert-danger">
                    <?php echo htmlspecialchars($error_message); ?>
                </div>
            <?php endif; ?>

            <?php if ($valid_token): ?>
                <form method="POST">
                    <input type="hidden" name="token" value="<?php echo htmlspecialchars($token); ?>">
                    
                    <div class="form-group">
                        <label for="password">New Password</label>
                        <div class="input-wrapper">
                            <i class="fas fa-lock field-icon"></i>
                            <input type="password" id="password" name="password" class="form-control" 
                                   placeholder="Enter new password" required minlength="8"
                                   oninput="checkStrength(this.value)">
                            <button type="button" class="password-toggle" onclick="togglePassword('password', 'toggleIcon1')">
                                <i class="fas fa-eye" id="toggleIcon1"></i>
                            </button>
                        </div>
                        <div class="password-strength"><div class="password-strength-bar" id="strengthBar"></div></div>
                        <div class="password-hint" id="strengthText">Minimum 8 characters</div>
                    </div>

                    <div class="form-group">
                        <label for="confirm_password">Confirm Password</label>
                        <div class="input-wrapper">
                            <i class="fas fa-lock field-icon"></i>
                            <input type="password" id="confirm_password" name="confirm_password" class="form-control" 
                                   placeholder="Confirm new password" required minlength="8">
                            <button type="button" class="password-toggle" onclick="togglePassword('confirm_password', 'toggleIcon2')">
                                <i class="fas fa-eye" id="toggleIcon2"></i>
                            </button>
                        </div>
                    </div>

                    <button type="submit" class="btn-login">
                        <i class="fas fa-check"></i> Reset Password
                    </button>
                </form>
            <?php endif; ?>

            <div class="login-footer">
                <a href="login.php">
                    <i class="fas fa-arrow-left"></i> Back to Login
                </a>
            </div>
        </div>
    </div>
    
    <script>
    function togglePassword(fieldId, iconId) {
        const input = document.getElementById(fieldId);
        const icon = document.getElementById(iconId);
        if (input.type === 'password') {
            input.type = 'text';
            icon.classList.replace('fa-eye', 'fa-eye-slash');
        } else {
            input.type = 'password';
            icon.classList.replace('fa-eye-slash', 'fa-eye');
        }
    }
    
    function checkStrength(password) {
        const bar = document.getElementById('strengthBar');
        const text = document.getElementById('strengthText');
        let score = 0;
        
        if (password.length >= 8) score++;
        if (password.length >= 12) score++;
        if (/[A-Z]/.test(password)) score++;
        if (/[0-9]/.test(password)) score++;
        if (/[^A-Za-z0-9]/.test(password)) score++;
        
        const levels = [
            { width: '0%', color: '#eee', label: 'Minimum 8 characters' },
            { width: '20%', color: '#dc3545', label: 'Weak' },
            { width: '40%', color: '#fd7e14', label: 'Fair' },
            { width: '60%', color: '#ffc107', label: 'Good' },
            { width: '80%', color: '#28a745', label: 'Strong' },
            { width: '100%', color: '#20c997', label: 'Very strong' }
        ];
        
        bar.style.width = levels[score].width;
        bar.style.background = levels[score].color;
        text.textContent = levels[score].label;
    }
    </script>
</body>
</html>
