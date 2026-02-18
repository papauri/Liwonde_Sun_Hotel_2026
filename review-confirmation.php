<?php
/**
 * Review Submission Confirmation
 * Hotel Website - Professional Confirmation Page
 */

// Start session
session_start();

// Get review details from session if available
$review_details = $_SESSION['review_details'] ?? null;
unset($_SESSION['review_details']);

// Get site name
$site_name = 'Hotel Website';
if (file_exists(__DIR__ . '/config/database.php')) {
    require_once __DIR__ . '/config/database.php';
    $site_name = getSetting('site_name', 'Hotel Website');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Review Submitted | <?php echo htmlspecialchars($site_name); ?></title>
    <meta name="description" content="Thank you for sharing your experience at <?php echo htmlspecialchars($site_name); ?>.">
    
    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;500;600;700&family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet" media="print" onload="this.media='all'">
    
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" media="print" onload="this.media='all'">
    
    <!-- CSS -->
    <link rel="stylesheet" href="css/theme-dynamic.php">
    <link rel="stylesheet" href="css/header.css">
    <link rel="stylesheet" href="css/style.css">
    <link rel="stylesheet" href="css/footer.css">
    
    <style>
        :root {
            /* Page-specific variables not in theme */
            --gold-light: #f4d03f;
            --gold-dark: #b8962e;
            --black: #1a1a1a;
        }
        
        body {
            font-family: var(--font-sans);
            background: linear-gradient(135deg, var(--black) 0%, var(--dark-gray) 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        
        .confirmation-container {
            max-width: 600px;
            width: 100%;
            background: var(--white);
            border-radius: 20px;
            padding: 60px 40px;
            text-align: center;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            position: relative;
            overflow: hidden;
        }
        
        .confirmation-container::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 6px;
            background: linear-gradient(90deg, var(--gold-dark), var(--gold), var(--gold-light), var(--gold), var(--gold-dark));
        }
        
        .success-icon {
            width: 100px;
            height: 100px;
            margin: 0 auto 30px;
            background: linear-gradient(135deg, var(--gold) 0%, var(--gold-light) 100%);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            animation: scaleIn 0.5s ease-out;
        }
        
        .success-icon i {
            font-size: 50px;
            color: var(--white);
            animation: checkmark 0.5s ease-out 0.2s both;
        }
        
        @keyframes scaleIn {
            0% {
                transform: scale(0);
                opacity: 0;
            }
            50% {
                transform: scale(1.1);
            }
            100% {
                transform: scale(1);
                opacity: 1;
            }
        }
        
        @keyframes checkmark {
            0% {
                transform: scale(0) rotate(-45deg);
                opacity: 0;
            }
            100% {
                transform: scale(1) rotate(0deg);
                opacity: 1;
            }
        }
        
        .confirmation-title {
            font-family: 'Playfair Display', serif;
            font-size: 2rem;
            font-weight: 600;
            color: var(--black);
            margin-bottom: 15px;
        }
        
        .confirmation-message {
            font-size: 1.05rem;
            color: #666;
            line-height: 1.7;
            margin-bottom: 30px;
        }
        
        .confirmation-details {
            background: var(--light-gray);
            border-radius: 12px;
            padding: 25px;
            margin-bottom: 30px;
            text-align: left;
        }
        
        .confirmation-details p {
            font-size: 0.95rem;
            color: #555;
            margin-bottom: 10px;
            display: flex;
            align-items: center;
        }
        
        .confirmation-details p:last-child {
            margin-bottom: 0;
        }
        
        .confirmation-details i {
            color: var(--gold);
            margin-right: 12px;
            width: 20px;
            text-align: center;
        }
        
        .confirmation-actions {
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
            justify-content: center;
        }
        
        .btn {
            padding: 14px 32px;
            border-radius: 50px;
            font-size: 0.95rem;
            font-weight: 600;
            text-decoration: none;
            transition: all 0.3s ease;
            cursor: pointer;
            border: none;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, var(--gold) 0%, var(--gold-light) 100%);
            color: var(--white);
            box-shadow: 0 4px 15px rgba(212, 175, 55, 0.3);
        }
        
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(212, 175, 55, 0.4);
        }
        
        .btn-secondary {
            background: transparent;
            color: var(--black);
            border: 2px solid var(--black);
        }
        
        .btn-secondary:hover {
            background: var(--black);
            color: var(--white);
        }
        
        .footer-note {
            margin-top: 30px;
            font-size: 0.85rem;
            color: #999;
        }
        
        /* Responsive */
        @media (max-width: 640px) {
            .confirmation-container {
                padding: 40px 25px;
            }
            
            .confirmation-title {
                font-size: 1.6rem;
            }
            
            .confirmation-message {
                font-size: 1rem;
            }
            
            .confirmation-actions {
                flex-direction: column;
            }
            
            .btn {
                width: 100%;
            }
        }
    </style>
</head>
<body>
    <div class="confirmation-container">
        <div class="success-icon">
            <i class="fas fa-check"></i>
        </div>
        
        <h1 class="confirmation-title">Thank You!</h1>
        
        <p class="confirmation-message">
            Your review has been successfully submitted and is pending moderation. 
            We appreciate you taking the time to share your experience.
        </p>
        
        <div class="confirmation-details">
            <p>
                <i class="fas fa-clock"></i>
                Your review will be visible within 24-48 hours
            </p>
            <p>
                <i class="fas fa-shield-alt"></i>
                All reviews are verified before publication
            </p>
            <p>
                <i class="fas fa-heart"></i>
                Your feedback helps us improve our services
            </p>
        </div>
        
        <div class="confirmation-actions">
            <a href="index.php" class="btn btn-primary">
                <i class="fas fa-home"></i> Return Home
            </a>
            <a href="rooms-gallery.php" class="btn btn-secondary">
                <i class="fas fa-bed"></i> View Rooms
            </a>
        </div>
        
        <p class="footer-note">
            <?php echo htmlspecialchars($site_name); ?> &copy; <?php echo date('Y'); ?>
        </p>
    </div>
</body>
</html>
