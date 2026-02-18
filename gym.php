<?php
require_once 'config/database.php';
require_once 'includes/page-guard.php';
require_once 'config/email.php';
require_once 'includes/validation.php';
require_once 'includes/modal.php';
require_once 'includes/image-proxy-helper.php';
require_once 'includes/section-headers.php';

// Start session for any session-based functionality
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Fetch site settings
$site_name = getSetting('site_name');
$site_logo = getSetting('site_logo');
$email_main = getSetting('email_main');


// Fetch gym content
$gymContent = [
    'wellness_title' => '',
    'wellness_description' => '',
    'wellness_image_path' => '',
    'badge_text' => '',
    'personal_training_image_path' => ''
];

try {
    $stmt = $pdo->query("SELECT * FROM gym_content WHERE is_active = 1 ORDER BY id DESC LIMIT 1");
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        $gymContent = array_merge($gymContent, $row);
    }
} catch (PDOException $e) {
    error_log("Error fetching gym content: " . $e->getMessage());
}

// Fetch gym features
$gymFeatures = [];
try {
    $stmt = $pdo->query("SELECT icon_class, title, description FROM gym_features WHERE is_active = 1 ORDER BY display_order ASC, id ASC");
    $gymFeatures = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error fetching gym features: " . $e->getMessage());
}

// Fetch gym facilities (grid)
$gymFacilities = [];
try {
    $stmt = $pdo->query("SELECT icon_class, title, description FROM gym_facilities WHERE is_active = 1 ORDER BY display_order ASC, id ASC");
    $gymFacilities = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error fetching gym facilities: " . $e->getMessage());
}

// Fetch classes
$gymClasses = [];
try {
    $stmt = $pdo->query("SELECT title, description, day_label, time_label, level_label FROM gym_classes WHERE is_active = 1 ORDER BY display_order ASC, id ASC");
    $gymClasses = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error fetching gym classes: " . $e->getMessage());
}

// Fetch packages
$gymPackages = [];
try {
    $stmt = $pdo->query("SELECT name, icon_class, includes_text, duration_label, price, currency_code, is_featured FROM gym_packages WHERE is_active = 1 ORDER BY display_order ASC, id ASC");
    $gymPackages = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error fetching gym packages: " . $e->getMessage());
}

// Handle booking form submission
$bookingSuccess = false;
$bookingError = '';
$bookingReference = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['gym_booking_form'])) {
    // Initialize validation errors array
    $validation_errors = [];
    $sanitized_data = [];
    
    // Validate full_name
    $name_validation = validateName($_POST['full_name'] ?? '', 2, true);
    if (!$name_validation['valid']) {
        $validation_errors['full_name'] = $name_validation['error'];
    } else {
        $sanitized_data['full_name'] = sanitizeString($name_validation['value'], 100);
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
    
    // Validate preferred_date and preferred_time together
    $datetime_validation = validateDateTime(
        $_POST['preferred_date'] ?? '',
        $_POST['preferred_time'] ?? '',
        false,  // Don't allow past dates
        $booking_buffer  // Use configurable buffer
    );
    
    if (!$datetime_validation['valid']) {
        $validation_errors['preferred_date'] = $datetime_validation['error'];
    } else {
        $sanitized_data['preferred_date'] = $datetime_validation['datetime']->format('Y-m-d');
        $sanitized_data['preferred_time'] = $datetime_validation['datetime']->format('H:i');
    }
    
    // Validate package_choice
    $package_validation = validateText($_POST['package_choice'] ?? '', 1, 100, true);
    if (!$package_validation['valid']) {
        $validation_errors['package_choice'] = $package_validation['error'];
    } else {
        $sanitized_data['package_choice'] = sanitizeString($package_validation['value'], 100);
    }
    
    // Validate goals (optional)
    $goals_validation = validateText($_POST['goals'] ?? '', 0, 1000, false);
    if (!$goals_validation['valid']) {
        $validation_errors['goals'] = $goals_validation['error'];
    } else {
        $sanitized_data['goals'] = sanitizeString($goals_validation['value'], 1000);
    }
    
    // Validate guests (optional)
    $guests_validation = validateNumber($_POST['guests'] ?? '', 1, 10, false);
    if (!$guests_validation['valid']) {
        $validation_errors['guests'] = $guests_validation['error'];
    } else {
        $sanitized_data['guests'] = $guests_validation['value'] ?? 1;
    }
    
    // Validate consent checkbox
    $consent = isset($_POST['consent']);
    if (!$consent) {
        $validation_errors['consent'] = 'You must accept consent to proceed.';
    }
    
    // Check for validation errors
    if (!empty($validation_errors)) {
        $error_messages = [];
        foreach ($validation_errors as $field => $message) {
            $error_messages[] = ucfirst(str_replace('_', ' ', $field)) . ': ' . $message;
        }
        $bookingError = implode('; ', $error_messages);
    } else {
        // Prepare booking data for email functions
        $booking_data = [
            'name' => $sanitized_data['full_name'],
            'email' => $sanitized_data['email'],
            'phone' => $sanitized_data['phone'],
            'preferred_date' => $sanitized_data['preferred_date'],
            'preferred_time' => $sanitized_data['preferred_time'],
            'package_choice' => $sanitized_data['package_choice'],
            'guests' => $sanitized_data['guests'] ?? 1,
            'goals' => $sanitized_data['goals'] ?? ''
        ];
        
        // Log booking data for diagnostics
        error_log("Gym booking data prepared: " . print_r($booking_data, true));
        
        // Set success and generate reference after validation passes
        $bookingSuccess = true;
        $bookingReference = 'GYM-' . strtoupper(substr(uniqid(), -8));
        
        // Save inquiry to database
        try {
            $stmt = $pdo->prepare("
                INSERT INTO gym_inquiries (
                    reference_number, name, email, phone, membership_type,
                    preferred_date, preferred_time, guests, message, consent, status
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'new')
            ");
            $stmt->execute([
                $bookingReference,
                $sanitized_data['full_name'],
                $sanitized_data['email'],
                $sanitized_data['phone'],
                $sanitized_data['package_choice'],
                $sanitized_data['preferred_date'],
                $sanitized_data['preferred_time'],
                $sanitized_data['guests'] ?? 1,
                $sanitized_data['goals'] ?? '',
                $consent ? 1 : 0
            ]);
            error_log("Gym inquiry saved to database with reference: " . $bookingReference);
        } catch (PDOException $e) {
            error_log("Failed to save gym inquiry to database: " . $e->getMessage());
            // Continue with email sending even if database save fails
        }
        
        // Send confirmation email to customer
        $customer_result = sendGymBookingEmail($booking_data);
        if (!$customer_result['success']) {
            error_log("Failed to send gym booking confirmation email: " . $customer_result['message']);
        } else {
            error_log("Gym customer email sent successfully to: " . $sanitized_data['email']);
        }
        
        // Send notification email to admin
        $admin_result = sendGymAdminNotificationEmail($booking_data);
        if (!$admin_result['success']) {
            error_log("Failed to send gym admin notification: " . $admin_result['message']);
        } else {
            error_log("Gym admin notification sent successfully");
        }
        
        error_log("Gym booking submitted successfully from: " . $sanitized_data['email'] . " with reference: " . $bookingReference);
    }
}

// Fetch policies for footer modals
$policies = [];
try {
    $policyStmt = $pdo->query("SELECT slug, title, summary, content FROM policies WHERE is_active = 1 ORDER BY display_order ASC, id ASC");
    $policies = $policyStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error fetching policies: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0, user-scalable=yes, viewport-fit=cover">
    <meta name="theme-color" content="#0A1929">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="format-detection" content="telephone=yes">
    <title>Fitness & Wellness Center - <?php echo htmlspecialchars($site_name); ?> | Gym, Spa & Yoga</title>
    <meta name="description" content="State-of-the-art fitness center and wellness facilities at <?php echo htmlspecialchars($site_name); ?>. Modern gym equipment, spa services, yoga classes, personal training, and holistic wellness programs.">
    <meta name="keywords" content="fitness center malawi, hotel gym, spa malawi, yoga classes, wellness center, personal training, health club">
    <meta name="robots" content="index, follow">
    <link rel="canonical" href="https://<?php echo $_SERVER['HTTP_HOST']; ?>/gym.php">
    
    <!-- Open Graph / Facebook -->
    <meta property="og:type" content="website">
    <meta property="og:url" content="https://<?php echo $_SERVER['HTTP_HOST']; ?>/gym.php">
    <meta property="og:title" content="Fitness & Wellness Center - <?php echo htmlspecialchars($site_name); ?>">
    <meta property="og:description" content="State-of-the-art fitness center with modern equipment, spa, yoga, and personal training services.">
    <meta property="og:image" content="https://<?php echo $_SERVER['HTTP_HOST']; ?>/images/gym/hero.jpg">
    
    <!-- Twitter -->
    <meta property="twitter:card" content="summary_large_image">
    <meta property="twitter:url" content="https://<?php echo $_SERVER['HTTP_HOST']; ?>/gym.php">
    <meta property="twitter:title" content="Fitness & Wellness Center - <?php echo htmlspecialchars($site_name); ?>">
    <meta property="twitter:description" content="State-of-the-art fitness center with modern equipment, spa, yoga, and personal training services.">
    <meta property="twitter:image" content="https://<?php echo $_SERVER['HTTP_HOST']; ?>/images/gym/hero.jpg">
    
    <!-- Preload Critical Resources -->
    <link rel="preload" href="css/style.css" as="style">
    <link rel="preload" href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;500;600;700&family=Poppins:wght@300;400;500;600;700&display=swap" as="style">
    
    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;500;600;700&family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet" media="print" onload="this.media='all'">
    <noscript><link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;500;600;700&family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet"></noscript>
    
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" media="print" onload="this.media='all'">
    <noscript><link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css"></noscript>
    
    <!-- Custom CSS -->
    <link rel="stylesheet" href="css/theme-dynamic.php">
    <link rel="stylesheet" href="css/header.css">
    <link rel="stylesheet" href="css/style.css">
    <link rel="stylesheet" href="css/footer.css">
    
    <!-- Structured Data - Sports Activity Location Schema -->
    <script type="application/ld+json">
    {
      "@context": "https://schema.org",
      "@type": "SportsActivityLocation",
      "name": "<?php echo htmlspecialchars($site_name); ?> Fitness & Wellness Center",
      "image": "https://<?php echo $_SERVER['HTTP_HOST']; ?>/images/gym/hero.jpg",
      "description": "State-of-the-art fitness center with modern equipment, spa, yoga, and personal training services",
      "url": "https://<?php echo $_SERVER['HTTP_HOST']; ?>/gym.php"
    }
    </script>
</head>
<body>
    <?php include 'includes/loader.php'; ?>
    
    <!-- Loading Animation -->
    <div class="page-loader">
        <div class="loader-content">
            <div class="luxury-spinner"></div>
            <p class="loader-text">Preparing Your Wellness Journey</p>
        </div>
    </div>

    <?php
    // Prepare modal content based on form submission result
    $gymModalContent = '';
    
    if ($bookingSuccess) {
        $gymModalContent = '<div style="text-align: center; padding: 20px;">
            <div style="margin-bottom: 20px;">
                <i class="fas fa-check-circle" style="font-size: 64px; color: #28a745;"></i>
            </div>
            <h2 style="color: #28a745; margin: 0 0 15px 0; font-size: 28px;">Booking Request Submitted Successfully!</h2>
            <p style="color: #666; margin: 0 0 20px 0; font-size: 16px;">Thank you for your gym booking request. Our team will contact you within 24 hours to confirm your booking.</p>
            <div style="background: #f8f9fa; padding: 20px; border-radius: 12px; margin: 20px 0; border: 2px solid #28a745;">
                <p style="color: #666; margin: 0 0 10px 0; font-size: 14px;">Your Reference Number:</p>
                <p style="color: #28a745; margin: 0; font-size: 24px; font-weight: bold; letter-spacing: 1px;">' . htmlspecialchars($bookingReference) . '</p>
            </div>
            <p style="color: #666; margin: 20px 0 0 0; font-size: 14px; line-height: 1.6;">
                <i class="fas fa-envelope" style="color: #28a745;"></i> A confirmation email has been sent to your email address.<br>
                <i class="fas fa-info-circle" style="color: #28a745;"></i> Please save this reference number for your records.
            </p>
        </div>';
    } elseif (!empty($bookingError)) {
        $gymModalContent = '<div style="text-align: center; padding: 20px;">
            <div style="margin-bottom: 20px;">
                <i class="fas fa-exclamation-triangle" style="font-size: 64px; color: #dc3545;"></i>
            </div>
            <h2 style="color: #dc3545; margin: 0 0 15px 0; font-size: 28px;">Booking Request Failed</h2>
            <p style="color: #666; margin: 0 0 20px 0; font-size: 16px;">' . htmlspecialchars($bookingError) . '</p>
            <p style="color: #666; margin: 20px 0 0 0; font-size: 14px;">
                <i class="fas fa-phone" style="color: #dc3545;"></i> Please try again or contact our front desk directly for assistance.
            </p>
        </div>';
    }
    
    // Render modal if there's content to show
    if (!empty($gymModalContent)) {
        renderModal('gymBookingResult', '', $gymModalContent, [
            'size' => 'md',
            'show_close' => true,
            'close_on_overlay' => true,
            'close_on_escape' => true
        ]);
    }
    ?>
    
    <?php include 'includes/header.php'; ?>
    
    <!-- Mobile Menu Overlay -->
    <div class="mobile-menu-overlay" role="presentation"></div>

    <!-- Hero Section -->
    <?php include 'includes/hero.php'; ?>

    <!-- Wellness Overview -->
    <section class="wellness-intro section-padding">
        <div class="container">
            <?php renderSectionHeader('gym_wellness', 'gym', [
                'label' => 'Your Wellness Journey',
                'title' => $gymContent['wellness_title'] ?? 'Wellness & Fitness',
                'description' => $gymContent['wellness_description'] ?? 'Experience comprehensive wellness facilities'
            ]); ?>
            <div class="wellness-content-grid">
                <div class="wellness-text" data-aos="fade-right">
                    
                    <div class="wellness-features">
                        <?php if (!empty($gymFeatures)): ?>
                            <?php foreach ($gymFeatures as $feature): ?>
                                <div class="feature-item">
                                    <i class="<?php echo htmlspecialchars($feature['icon_class']); ?>"></i>
                                    <div>
                                        <h4><?php echo htmlspecialchars($feature['title']); ?></h4>
                                        <p><?php echo htmlspecialchars($feature['description']); ?></p>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="feature-item">
                                <i class="fas fa-dumbbell"></i>
                                <div>
                                    <h4>Modern Equipment</h4>
                                    <p>Latest cardio machines, free weights, and resistance training equipment</p>
                                </div>
                            </div>
                            <div class="feature-item">
                                <i class="fas fa-user-md"></i>
                                <div>
                                    <h4>Personal Training</h4>
                                    <p>Certified trainers available for one-on-one sessions and customized programs</p>
                                </div>
                            </div>
                            <div class="feature-item">
                                <i class="fas fa-spa"></i>
                                <div>
                                    <h4>Spa & Recovery</h4>
                                    <p>Massage therapy, sauna, and steam rooms for post-workout relaxation</p>
                                </div>
                            </div>
                            <div class="feature-item">
                                <i class="fas fa-clock"></i>
                                <div>
                                    <h4>Flexible Hours</h4>
                                    <p>Open daily from 5:30 AM to 10:00 PM for your convenience</p>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <div class="wellness-image" data-aos="fade-left">
                    <img src="<?php echo htmlspecialchars(proxyImageUrl($gymContent['wellness_image_path'])); ?>" alt="Modern Fitness Center" loading="lazy">
                    <div class="image-badge">
                        <i class="fas fa-trophy"></i>
                        <span><?php echo htmlspecialchars($gymContent['badge_text']); ?></span>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Gym Facilities -->
    <section class="gym-facilities section-padding bg-dark">
        <div class="container">
            <?php renderSectionHeader('gym_facilities', 'gym', [
                'label' => 'What We Offer',
                'title' => 'Comprehensive Fitness Facilities',
                'description' => 'Everything you need for a complete wellness experience'
            ], 'text-center'); ?>

            <div class="facilities-grid">
                <?php if (!empty($gymFacilities)): ?>
                    <?php foreach ($gymFacilities as $index => $facility): ?>
                        <div class="facility-card" data-aos="fade-up" data-aos-delay="<?php echo $index * 100; ?>">
                            <div class="facility-icon"><i class="<?php echo htmlspecialchars($facility['icon_class']); ?>"></i></div>
                            <h3><?php echo htmlspecialchars($facility['title']); ?></h3>
                            <p><?php echo htmlspecialchars($facility['description']); ?></p>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="facility-card" data-aos="fade-up">
                        <div class="facility-icon"><i class="fas fa-running"></i></div>
                        <h3>Cardio Zone</h3>
                        <p>Treadmills, ellipticals, stationary bikes, and rowing machines with entertainment screens and heart rate monitoring</p>
                    </div>
                    <div class="facility-card" data-aos="fade-up" data-aos-delay="100">
                        <div class="facility-icon"><i class="fas fa-dumbbell"></i></div>
                        <h3>Strength Training</h3>
                        <p>Complete range of free weights, barbells, resistance machines, and functional training equipment</p>
                    </div>
                    <div class="facility-card" data-aos="fade-up" data-aos-delay="200">
                        <div class="facility-icon"><i class="fas fa-child"></i></div>
                        <h3>Yoga & Pilates Studio</h3>
                        <p>Dedicated studio space for yoga, pilates, and meditation with daily group classes</p>
                    </div>
                    <div class="facility-card" data-aos="fade-up" data-aos-delay="300">
                        <div class="facility-icon"><i class="fas fa-swimming-pool"></i></div>
                        <h3>Lap Pool</h3>
                        <p>25-meter heated pool perfect for swimming workouts and aqua aerobics sessions</p>
                    </div>
                    <div class="facility-card" data-aos="fade-up" data-aos-delay="400">
                        <div class="facility-icon"><i class="fas fa-hot-tub"></i></div>
                        <h3>Spa & Sauna</h3>
                        <p>Traditional sauna, steam room, and jacuzzi for relaxation and muscle recovery</p>
                    </div>
                    <div class="facility-card" data-aos="fade-up" data-aos-delay="500">
                        <div class="facility-icon"><i class="fas fa-apple-alt"></i></div>
                        <h3>Nutrition Bar</h3>
                        <p>Fresh smoothies, protein shakes, and healthy snacks to fuel your workout</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </section>

    <!-- Class Schedule -->
    <section class="class-schedule section-padding">
        <div class="container">
            <?php renderSectionHeader('gym_classes', 'gym', [
                'label' => 'Stay Active',
                'title' => 'Group Fitness Classes',
                'description' => 'Join our expert-led classes designed for all fitness levels'
            ], 'text-center'); ?>

            <div class="schedule-grid">
                <?php if (!empty($gymClasses)): ?>
                    <?php foreach ($gymClasses as $idx => $class): ?>
                        <div class="class-card" data-aos="zoom-in" data-aos-delay="<?php echo $idx * 100; ?>">
                            <div class="class-time">
                                <span class="day"><?php echo htmlspecialchars($class['day_label']); ?></span>
                                <span class="time"><?php echo htmlspecialchars($class['time_label']); ?></span>
                            </div>
                            <h3><?php echo htmlspecialchars($class['title']); ?></h3>
                            <p><?php echo htmlspecialchars($class['description']); ?></p>
                            <span class="class-level"><?php echo htmlspecialchars($class['level_label']); ?></span>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="class-card" data-aos="zoom-in">
                        <div class="class-time">
                            <span class="day">Monday - Friday</span>
                            <span class="time">6:30 AM</span>
                        </div>
                        <h3>Morning Yoga Flow</h3>
                        <p>Start your day with energizing yoga sequences</p>
                        <span class="class-level">All Levels</span>
                    </div>
                    <div class="class-card" data-aos="zoom-in" data-aos-delay="100">
                        <div class="class-time">
                            <span class="day">Tuesday & Thursday</span>
                            <span class="time">7:00 AM</span>
                        </div>
                        <h3>HIIT Bootcamp</h3>
                        <p>High-intensity interval training for maximum results</p>
                        <span class="class-level">Intermediate</span>
                    </div>
                    <div class="class-card" data-aos="zoom-in" data-aos-delay="200">
                        <div class="class-time">
                            <span class="day">Wednesday & Saturday</span>
                            <span class="time">8:00 AM</span>
                        </div>
                        <h3>Pilates Core</h3>
                        <p>Strengthen your core with controlled movements</p>
                        <span class="class-level">All Levels</span>
                    </div>
                    <div class="class-card" data-aos="zoom-in" data-aos-delay="300">
                        <div class="class-time">
                            <span class="day">Daily</span>
                            <span class="time">6:00 PM</span>
                        </div>
                        <h3>Evening Meditation</h3>
                        <p>Wind down with guided meditation and breathing</p>
                        <span class="class-level">All Levels</span>
                    </div>
                <?php endif; ?>
            </div>

            <div class="schedule-cta text-center">
                <button class="btn btn-primary" data-open-booking><i class="fas fa-calendar-plus"></i> Book a Class</button>
                <a href="#" onclick="alert('Full schedule download will be available soon. Please contact us for the complete class schedule.'); return false;" class="btn btn-outline"><i class="fas fa-download"></i> Download Full Schedule</a>
            </div>
        </div>
    </section>

    <!-- Personal Training -->
    <section class="personal-training section-padding bg-dark">
        <div class="container">
            <div class="training-content-grid">
                <div class="training-image" data-aos="fade-right">
                    <img src="<?php echo htmlspecialchars(proxyImageUrl($gymContent['personal_training_image_path'])); ?>" alt="Personal Training" loading="lazy">
                </div>
                
                <div class="training-text" data-aos="fade-left">
                    <?php renderSectionHeader('gym_training', 'gym', [
                        'label' => 'One-on-One Coaching',
                        'title' => 'Personal Training Programs',
                        'description' => 'Achieve your fitness goals faster with personalized guidance from our certified trainers.'
                    ]); ?>
                    
                    <ul class="training-benefits">
                        <li><i class="fas fa-check-circle"></i> Customized workout plans tailored to your goals</li>
                        <li><i class="fas fa-check-circle"></i> Nutritional guidance and meal planning</li>
                        <li><i class="fas fa-check-circle"></i> Progress tracking and regular assessments</li>
                        <li><i class="fas fa-check-circle"></i> Flexible scheduling to fit your stay</li>
                        <li><i class="fas fa-check-circle"></i> Expert form correction and injury prevention</li>
                    </ul>
                    
                    <div class="training-cta">
                        <button class="btn btn-primary" data-open-booking><i class="fas fa-user-plus"></i> Book a Session</button>
                        <p class="training-note">First consultation is complimentary for hotel guests</p>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Wellness Packages -->
    <section class="wellness-packages section-padding">
        <div class="container">
            <?php renderSectionHeader('gym_packages', 'gym', [
                'label' => 'Exclusive Offers',
                'title' => 'Wellness Packages',
                'description' => 'Comprehensive packages designed for optimal health and relaxation'
            ], 'text-center'); ?>

            <div class="packages-grid">
                <?php if (!empty($gymPackages)): ?>
                    <?php foreach ($gymPackages as $idx => $package): ?>
                        <?php $includes = array_filter(array_map('trim', preg_split('/\r\n|\n|\r/', $package['includes_text'] ?? ''))); ?>
                        <div class="package-card <?php echo !empty($package['is_featured']) ? 'featured' : ''; ?>" data-aos="flip-left" data-aos-delay="<?php echo $idx * 100; ?>">
                            <?php if (!empty($package['is_featured'])): ?><span class="popular-badge">Most Popular</span><?php endif; ?>
                            <div class="package-icon"><i class="<?php echo htmlspecialchars($package['icon_class']); ?>"></i></div>
                            <h3><?php echo htmlspecialchars($package['name']); ?></h3>
                            <ul class="package-includes">
                                <?php if (!empty($includes)): ?>
                                    <?php foreach ($includes as $include): ?>
                                        <li><?php echo htmlspecialchars($include); ?></li>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </ul>
                            <?php if (!empty($package['duration_label'])): ?>
                                <div class="package-duration"><?php echo htmlspecialchars($package['duration_label']); ?></div>
                            <?php endif; ?>
                            <div class="package-price">
                                <span class="price-currency"><?php echo htmlspecialchars($package['currency_code']); ?></span>
                                <span class="price-amount"><?php echo number_format($package['price'] ?? 0, 0); ?></span>
                            </div>
                            <button class="btn <?php echo !empty($package['is_featured']) ? 'btn-primary' : 'btn-outline'; ?>" type="button" data-open-booking>Book Package</button>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="package-card" data-aos="flip-left">
                        <div class="package-icon"><i class="fas fa-leaf"></i></div>
                        <h3>Rejuvenation Retreat</h3>
                        <ul class="package-includes">
                            <li>3 personal training sessions</li>
                            <li>Daily yoga classes</li>
                            <li>2 spa massages</li>
                            <li>Nutrition consultation</li>
                            <li>Complimentary smoothie bar access</li>
                        </ul>
                        <div class="package-duration">5 Days</div>
                        <div class="package-price">
                            <span class="price-currency"><?php echo htmlspecialchars(getSetting('currency_code')); ?></span>
                            <span class="price-amount">45,000</span>
                        </div>
                        <button class="btn btn-outline" type="button" data-open-booking>Book Package</button>
                    </div>
                    <div class="package-card featured" data-aos="flip-left" data-aos-delay="100">
                        <span class="popular-badge">Most Popular</span>
                        <div class="package-icon"><i class="fas fa-star"></i></div>
                        <h3>Ultimate Wellness</h3>
                        <ul class="package-includes">
                            <li>5 personal training sessions</li>
                            <li>Unlimited group classes</li>
                            <li>4 spa treatments</li>
                            <li>Full nutrition program</li>
                            <li>Fitness assessment & tracking</li>
                            <li>Complimentary wellness amenities</li>
                        </ul>
                        <div class="package-duration">7 Days</div>
                        <div class="package-price">
                            <span class="price-currency"><?php echo htmlspecialchars(getSetting('currency_code')); ?></span>
                            <span class="price-amount">85,000</span>
                        </div>
                        <button class="btn btn-primary" type="button" data-open-booking>Book Package</button>
                    </div>
                    <div class="package-card" data-aos="flip-left" data-aos-delay="200">
                        <div class="package-icon"><i class="fas fa-dumbbell"></i></div>
                        <h3>Fitness Kickstart</h3>
                        <ul class="package-includes">
                            <li>2 personal training sessions</li>
                            <li>Group class pass (5 classes)</li>
                            <li>1 spa massage</li>
                            <li>Fitness assessment</li>
                            <li>Workout plan to take home</li>
                        </ul>
                        <div class="package-duration">3 Days</div>
                        <div class="package-price">
                            <span class="price-currency"><?php echo htmlspecialchars(getSetting('currency_code')); ?></span>
                            <span class="price-amount">28,000</span>
                        </div>
                        <button class="btn btn-outline" type="button" data-open-booking>Book Package</button>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </section>

    <!-- Booking Modal -->
    <div class="booking-modal" id="bookingModal" data-booking-modal>
        <div class="booking-modal__overlay" data-close-booking></div>
        <div class="booking-modal__content">
            <button class="booking-modal__close" aria-label="Close booking form" data-close-booking>&times;</button>
            <div class="booking-modal__header">
                <span class="booking-pill">Gym Booking</span>
                <h3>Request a Session</h3>
                <p>Complete form and our team will confirm your booking via email.</p>
            </div>
            <form method="POST" class="booking-form" novalidate>
                <input type="hidden" name="gym_booking_form" value="1">
                <div class="form-grid">
                    <div class="form-group">
                        <label for="full_name">Full Name *</label>
                        <input type="text" id="full_name" name="full_name" required>
                    </div>
                    <div class="form-group">
                        <label for="email">Email *</label>
                        <input type="email" id="email" name="email" required>
                    </div>
                    <div class="form-group">
                        <label for="phone">Phone *</label>
                        <input type="tel" id="phone" name="phone" required>
                    </div>
                    <div class="form-group">
                        <label for="guests">Guests</label>
                        <input type="number" id="guests" name="guests" min="1" max="10" placeholder="1">
                    </div>
                    <div class="form-group">
                        <label for="preferred_date">Preferred Date *</label>
                        <input type="date" id="preferred_date" name="preferred_date" min="<?php echo date('Y-m-d'); ?>" required>
                        <small class="field-error" id="preferred_date_error" style="color: #dc3545; display: none;"></small>
                    </div>
                    <div class="form-group">
                        <label for="preferred_time">Preferred Time *</label>
                        <input type="time" id="preferred_time" name="preferred_time" required>
                        <small class="field-error" id="preferred_time_error" style="color: #dc3545; display: none;"></small>
                    </div>
                    <div class="form-group full">
                        <label for="package_choice">Select Package *</label>
                        <select id="package_choice" name="package_choice" required>
                            <option value="">Choose a package</option>
                            <?php foreach ($gymPackages as $pkg): ?>
                                <option value="<?php echo htmlspecialchars($pkg['name']); ?>">
                                    <?php echo htmlspecialchars($pkg['name']); ?> (<?php echo htmlspecialchars($pkg['currency_code']); ?> <?php echo number_format($pkg['price'], 0); ?>)
                                </option>
                            <?php endforeach; ?>
                            <?php if (empty($gymPackages)): ?>
                                <option value="Custom Request">Custom Request</option>
                            <?php endif; ?>
                        </select>
                    </div>
                    <div class="form-group full">
                        <label for="goals">Fitness Goals / Notes</label>
                        <textarea id="goals" name="goals" rows="4" placeholder="Tell us what you want to achieve or any special requests"></textarea>
                    </div>
                    <div class="form-consent full">
                        <label class="checkbox">
                            <input type="checkbox" name="consent" required>
                            <span>I agree to be contacted about this booking request.</span>
                        </label>
                    </div>
                </div>
                <button type="submit" class="btn btn-primary full-width" id="gymSubmitBtn" disabled>Send Booking Request</button>
                <p class="privacy-note">We send bookings to: <?php echo htmlspecialchars($email_main); ?>. We respect your privacy.</p>
            </form>
        </div>
    </div>

    <?php include 'includes/footer.php'; ?>

    <!-- Scripts -->
    <script src="js/modal.js"></script>
    <script src="js/main.js"></script>
    <script>
        // Page loader
        window.addEventListener('load', function() {
            const loader = document.querySelector('.page-loader');
            if (loader) {
                loader.classList.add('fade-out');
                setTimeout(() => { loader.style.display = 'none'; }, 500);
            }
            
            // Open result modal if present (after form submission)
            const resultModal = document.getElementById('gymBookingResult');
            if (resultModal) {
                // Small delay to ensure page is fully loaded
                setTimeout(function() {
                    resultModal.classList.add('active');
                    document.body.style.overflow = 'hidden';
                }, 600);
            }
        });
        
        // Modal close functionality for result modal
        const resultModal = document.getElementById('gymBookingResult');
        const resultModalOverlay = document.getElementById('gymBookingResult-overlay');
        
        function closeResultModal() {
            if (resultModal) {
                resultModal.classList.remove('active');
                document.body.style.overflow = '';
            }
        }
        
        if (resultModalOverlay) {
            resultModalOverlay.addEventListener('click', closeResultModal);
        }
        
        document.addEventListener('keyup', (e) => {
            if (e.key === 'Escape' && resultModal && resultModal.classList.contains('active')) {
                closeResultModal();
            }
        });
        
        const resultCloseButtons = document.querySelectorAll('#gymBookingResult [data-modal-close]');
        resultCloseButtons.forEach(btn => btn.addEventListener('click', closeResultModal));

        // Booking modal - custom implementation for booking-modal structure
        const bookingModal = document.querySelector('[data-booking-modal]');
        const openButtons = document.querySelectorAll('[data-open-booking]');
        const closeButtons = document.querySelectorAll('[data-close-booking]');

        function openModal() {
            if (bookingModal) {
                bookingModal.classList.add('active');
                document.body.style.overflow = 'hidden';
            }
        }

        function closeModal() {
            if (bookingModal) {
                bookingModal.classList.remove('active');
                document.body.style.overflow = '';
            }
        }

        openButtons.forEach(btn => btn.addEventListener('click', openModal));
        closeButtons.forEach(btn => btn.addEventListener('click', closeModal));
        document.addEventListener('keyup', (e) => {
            if (e.key === 'Escape') closeModal();
        });

        // Consent checkbox validation - grey out submit button until consent is checked
        const consentCheckbox = document.querySelector('input[name="consent"]');
        const gymSubmitBtn = document.getElementById('gymSubmitBtn');
        
        if (consentCheckbox && gymSubmitBtn) {
            // Initialize button state
            gymSubmitBtn.disabled = !consentCheckbox.checked;
            gymSubmitBtn.style.opacity = consentCheckbox.checked ? '1' : '0.6';
            gymSubmitBtn.style.cursor = consentCheckbox.checked ? 'pointer' : 'not-allowed';
            
            // Handle checkbox change
            consentCheckbox.addEventListener('change', function() {
                gymSubmitBtn.disabled = !this.checked;
                gymSubmitBtn.style.opacity = this.checked ? '1' : '0.6';
                gymSubmitBtn.style.cursor = this.checked ? 'pointer' : 'not-allowed';
            });
        }

        // Form submission handling - grey out submit button
        const gymForm = document.querySelector('.booking-form');
        
        if (gymForm && gymSubmitBtn) {
            gymForm.addEventListener('submit', function() {
                // Disable and grey out the submit button
                gymSubmitBtn.disabled = true;
                gymSubmitBtn.style.opacity = '0.6';
                gymSubmitBtn.style.cursor = 'not-allowed';
                gymSubmitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Submitting...';
            });
        }

        // Date/Time validation - ensure selected time is not in the past for today's date
        const preferredDate = document.getElementById('preferred_date');
        const preferredTime = document.getElementById('preferred_time');
        const dateError = document.getElementById('preferred_date_error');
        const timeError = document.getElementById('preferred_time_error');
        
        // Booking buffer in minutes (default 60, should match PHP setting)
        const bookingBufferMinutes = 60;
        
        function validateDateTime() {
            if (!preferredDate.value || !preferredTime.value) {
                dateError.style.display = 'none';
                timeError.style.display = 'none';
                return true;
            }
            
            const selectedDate = new Date(preferredDate.value + 'T' + preferredTime.value);
            const now = new Date();
            const minAllowed = new Date(now.getTime() + bookingBufferMinutes * 60000);
            
            // Clear previous errors
            dateError.style.display = 'none';
            timeError.style.display = 'none';
            
            if (selectedDate < minAllowed) {
                // Check if it's a past date
                const today = new Date();
                today.setHours(0, 0, 0, 0);
                const selectedDayOnly = new Date(preferredDate.value);
                selectedDayOnly.setHours(0, 0, 0, 0);
                
                if (selectedDayOnly < today) {
                    dateError.textContent = 'Date cannot be in the past';
                    dateError.style.display = 'block';
                    preferredDate.style.borderColor = '#dc3545';
                } else {
                    // It's today but time is too soon
                    const bufferHours = Math.floor(bookingBufferMinutes / 60);
                    const bufferMins = bookingBufferMinutes % 60;
                    let timeMsg = 'For today, please select a time at least ';
                    if (bufferHours > 0 && bufferMins > 0) {
                        timeMsg += bufferHours + ' hour(s) and ' + bufferMins + ' minutes from now';
                    } else if (bufferHours > 0) {
                        timeMsg += bufferHours + ' hour(s) from now';
                    } else {
                        timeMsg += bookingBufferMinutes + ' minutes from now';
                    }
                    timeError.textContent = timeMsg;
                    timeError.style.display = 'block';
                    preferredTime.style.borderColor = '#dc3545';
                }
                return false;
            }
            
            // Valid - reset borders
            preferredDate.style.borderColor = '';
            preferredTime.style.borderColor = '';
            return true;
        }
        
        // Add event listeners for real-time validation
        if (preferredDate && preferredTime) {
            preferredDate.addEventListener('change', validateDateTime);
            preferredTime.addEventListener('change', validateDateTime);
            
            // Also validate on form submission
            if (gymForm) {
                gymForm.addEventListener('submit', function(e) {
                    if (!validateDateTime()) {
                        e.preventDefault();
                        return false;
                    }
                });
            }
        }
    </script>

    <?php include 'includes/scroll-to-top.php'; ?>
</body>
</html>
