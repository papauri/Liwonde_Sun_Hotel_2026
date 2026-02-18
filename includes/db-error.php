<?php
try {
    require_once __DIR__ . '/../config/database.php';
    $siteName = getSetting('site_name');
} catch (Exception $e) {
    // DB is down - site name will be empty
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0, user-scalable=yes, viewport-fit=cover">
    <title>Database Connection Error | <?php echo htmlspecialchars($siteName); ?></title>
    <link rel="stylesheet" href="/css/style.css">
    <style>
        body {
            background: linear-gradient(135deg, #e9ecef 0%, #f7f9fa 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: 'Poppins', 'Segoe UI', Arial, sans-serif;
            margin: 0;
        }
        .db-error-container {
            background: white;
            border-radius: 24px;
            box-shadow: 0 8px 40px rgba(10,25,41,0.10), 0 1.5px 0 #ffe082;
            padding: 48px 36px 36px 36px;
            max-width: 420px;
            width: 100%;
            text-align: center;
            position: relative;
        }
        .sleeping-bear {
            width: 120px;
            margin: 0 auto 18px auto;
            display: block;
        }
        .zzz {
            font-size: 32px;
            color: #b0b8c1;
            font-family: 'Playfair Display', serif;
            margin-bottom: 8px;
            letter-spacing: 2px;
        }
        .db-error-title {
            font-size: 1.6rem;
            font-family: 'Playfair Display', serif;
            color: var(--navy, #0A1929);
            font-weight: 700;
            margin-bottom: 10px;
        }
        .db-error-msg {
            color: #b71c1c;
            background: #fff3f3;
            border-radius: 8px;
            padding: 10px 0;
            font-size: 1.05rem;
            margin-bottom: 18px;
            font-family: 'Poppins', sans-serif;
        }
        .db-error-list {
            text-align: left;
            margin: 0 auto 0 auto;
            max-width: 340px;
            color: #444;
            font-size: 1rem;
            padding-left: 0;
        }
        .db-error-list li {
            margin-bottom: 8px;
            padding-left: 0.5em;
        }
        .db-error-footer {
            margin-top: 30px;
            color: #aaa;
            font-size: 0.95rem;
        }
    </style>
</head>
<body>
    <div class="db-error-container">
        <svg class="sleeping-bear" viewBox="0 0 120 80" fill="none" xmlns="http://www.w3.org/2000/svg">
            <ellipse cx="60" cy="70" rx="38" ry="10" fill="#e0e0e0"/>
            <ellipse cx="60" cy="48" rx="38" ry="28" fill="#b0b8c1"/>
            <ellipse cx="40" cy="38" rx="10" ry="8" fill="#a7a7a7"/>
            <ellipse cx="80" cy="38" rx="10" ry="8" fill="#a7a7a7"/>
            <ellipse cx="60" cy="60" rx="16" ry="10" fill="#fff"/>
            <ellipse cx="60" cy="62" rx="8" ry="4" fill="#b0b8c1"/>
            <ellipse cx="48" cy="30" rx="3" ry="2" fill="#fff"/>
            <ellipse cx="72" cy="30" rx="3" ry="2" fill="#fff"/>
            <ellipse cx="60" cy="54" rx="2" ry="1.2" fill="#444"/>
            <ellipse cx="52" cy="54" rx="1.2" ry="0.7" fill="#444"/>
            <ellipse cx="68" cy="54" rx="1.2" ry="0.7" fill="#444"/>
        </svg>
        <div class="zzz">Zzz...</div>
        <div class="db-error-title">The site is down.<br>Please contact the administrators.</div>
    </div>
    <script>
        // Print the real error to the console for admins
        <?php if (isset($errorMsg)): ?>
        console.error('Database Connection Error: <?php echo addslashes($errorMsg); ?>');
        <?php endif; ?>
    </script>
    </div>
</body>
</html>
