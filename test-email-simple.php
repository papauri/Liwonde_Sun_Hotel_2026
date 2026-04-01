<?php
/**
 * Simple Email Test Script
 * Bypasses all complex logic to test email sending directly
 */

require_once 'config/database.php';

echo "========================================\n";
echo "Simple Email Test\n";
echo "========================================\n\n";

try {
    // 1. Read SMTP password directly from database
    echo "Step 1: Reading SMTP settings from database...\n";
    echo str_repeat("-", 50) . "\n";
    
    $stmt = $pdo->query("SELECT setting_key, setting_value, is_encrypted FROM email_settings WHERE setting_key IN ('smtp_host', 'smtp_port', 'smtp_username', 'smtp_password', 'smtp_secure', 'email_from_email')");
    $settings = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $settings[$row['setting_key']] = [
            'value' => $row['setting_value'],
            'encrypted' => $row['is_encrypted']
        ];
    }
    
    foreach ($settings as $key => $setting) {
        $value = $setting['value'];
        $encrypted = $setting['encrypted'] ? 'Yes' : 'No';
        
        if ($key === 'smtp_password') {
            $masked = str_repeat('*', strlen($value));
            echo "  $key: $masked (Encrypted: $encrypted, Length: " . strlen($value) . ")\n";
        } else {
            echo "  $key: $value (Encrypted: $encrypted)\n";
        }
    }
    
    // 2. Check PHPMailer
    echo "\nStep 2: Checking PHPMailer...\n";
    echo str_repeat("-", 50) . "\n";
    
    if (!file_exists(__DIR__ . '/vendor/phpmailer/phpmailer/src/PHPMailer.php')) {
        echo "  ✗ PHPMailer NOT found\n";
        echo "  Path: " . __DIR__ . '/vendor/phpmailer/phpmailer/src/PHPMailer.php' . "\n";
        exit(1);
    }
    
    echo "  ✓ PHPMailer found\n";
    echo "  Path: " . __DIR__ . '/vendor/phpmailer/phpmailer/src/PHPMailer.php' . "\n";
    
    // 3. Test email sending
    echo "\nStep 3: Testing email sending...\n";
    echo str_repeat("-", 50) . "\n";
    
    // Load PHPMailer
    require __DIR__ . '/vendor/autoload.php';
    
    $mail = new PHPMailer(true);
    
    // Configure SMTP
    $mail->isSMTP();
    $mail->Host = $settings['smtp_host']['value'];
    $mail->SMTPAuth = true;
    $mail->Username = $settings['smtp_username']['value'];
    $mail->Password = $settings['smtp_password']['value'];
    $mail->SMTPSecure = $settings['smtp_secure']['value'];
    $mail->Port = (int)$settings['smtp_port']['value'];
    $mail->Timeout = 30;
    
    // Set from/to
    $mail->setFrom($settings['email_from_email']['value'], 'Liwonde Sun Hotel');
    $mail->addAddress('johnpaulchirwa@gmail.com', 'Test Recipient');
    
    // Email content
    $mail->isHTML(true);
    $mail->Subject = 'Email System Test - ' . date('Y-m-d H:i:s');
    $mail->Body = '
        <h1 style="color: #0A1929; text-align: center;">Email System Test</h1>
        <div style="background: #f8f9fa; padding: 20px; border-radius: 10px; max-width: 600px; margin: 0 auto;">
            <p style="color: #333;">This is a test email from the Liwonde Sun Hotel website email system.</p>
            
            <div style="background: #d4edda; padding: 15px; border-left: 4px solid #0d6efd; border-radius: 5px; margin: 20px 0;">
                <h3 style="color: #0d6efd; margin-top: 0;">SMTP Configuration</h3>
                <p style="color: #155724; margin: 0;">Host: ' . $settings['smtp_host']['value'] . '</p>
                <p style="color: #155724; margin: 0;">Port: ' . $settings['smtp_port']['value'] . '</p>
                <p style="color: #155724; margin: 0;">Secure: ' . $settings['smtp_secure']['value'] . '</p>
                <p style="color: #155724; margin: 0;">Username: ' . $settings['smtp_username']['value'] . '</p>
                <p style="color: #155724; margin: 0;">Password: ' . str_repeat('*', strlen($settings['smtp_password']['value'])) . '</p>
            </div>
            
            <div style="background: #fff3cd; padding: 15px; border-left: 4px solid #ffc107; border-radius: 5px; margin: 20px 0;">
                <h3 style="color: #856404; margin-top: 0;">Timestamp</h3>
                <p style="color: #333; margin: 0;">' . date('Y-m-d H:i:s T') . '</p>
            </div>
            
            <div style="background: #d1e7dd; padding: 15px; border-left: 4px solid #28a745; border-radius: 5px; margin: 20px 0;">
                <h3 style="color: #1e7e34; margin-top: 0;">Test Result</h3>
                <p style="color: #333; margin: 0;">If you receive this email, the email system is working correctly!</p>
            </div>
        </div>
        
        <p style="text-align: center; color: #666; font-size: 14px; margin-top: 30px;">
            <em>Liwonde Sun Hotel</em><br>
            <a href="https://promanaged-it.com/hotelsmw">https://promanaged-it.com/hotelsmw</a>
        </p>
    ';
    
    $mail->AltBody = 'This is a test email from the Liwonde Sun Hotel website email system.';
    
    // Enable debug output
    $mail->SMTPDebug = true;
    
    // Try to send
    $success = false;
    $error = '';
    
    try {
        $mail->send();
        $success = true;
        echo "  ✓ Email sent successfully\n";
    } catch (Exception $e) {
        $error = $e->getMessage();
        echo "  ✗ Email failed: $error\n";
        echo "  Error details: " . $e->getError() . "\n";
    }
    
    echo "\n";
    echo str_repeat("=", 50) . "\n";
    echo "TEST RESULT\n";
    echo str_repeat("=", 50) . "\n";
    
    if ($success) {
        echo "✓ SUCCESS: Email was sent successfully\n";
        echo "\nThe email system is working correctly!\n";
        echo "\nNext steps:\n";
        echo "1. Gym and conference forms should now work\n";
        echo "2. Test by submitting a gym booking\n";
        echo "3. Test by submitting a conference enquiry\n";
    } else {
        echo "✗ FAILED: Email could not be sent\n";
        echo "\nError: $error\n";
        echo "\nTroubleshooting steps:\n";
        echo "1. Check SMTP password is correct\n";
        echo "2. Check SMTP server is accessible from this server\n";
        echo "3. Check firewall settings\n";
        echo "4. Check email account has sufficient sending quota\n";
    }
    
    echo "\n";
    echo str_repeat("=", 50) . "\n";
    
} catch (Exception $e) {
    echo "\n";
    echo "╔════════════════════════════════════════╗\n";
    echo "║   FATAL ERROR                                    ║\n";
    echo "╚════════════════════════════════════════════════╝\n";
    echo "\n";
    echo "Error: " . $e->getMessage() . "\n";
    echo "File: " . $e->getFile() . "\n";
    echo "Line: " . $e->getLine() . "\n";
    echo "\n";
    exit(1);
}