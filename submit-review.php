<?php
/**
 * Review Submission Form with Enhanced Security
 * Hotel Website - Guest Review Submission
*
 * Features:
 * - CSRF protection
 * - Secure session management
 * - Input validation
 */

// Start session first
session_start();

// Load security configuration first
require_once 'config/security.php';

// Include database configuration
require_once 'config/database.php';

// Include alert system
require_once 'includes/alert.php';

// Include validation library
require_once 'includes/validation.php';

// Send security headers
sendSecurityHeaders();

// Initialize variables
$rooms = [];
$selected_room_id = isset($_GET['room_id']) ? (int)$_GET['room_id'] : 0;
$room_name = '';

// Fetch available rooms for dropdown
try {
    $stmt = $pdo->query("SELECT id, name FROM rooms WHERE is_active = 1 ORDER BY name ASC");
    $rooms = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get room name if room_id is provided
    if ($selected_room_id > 0) {
        $room_stmt = $pdo->prepare("SELECT name FROM rooms WHERE id = ?");
        $room_stmt->execute([$selected_room_id]);
        $room_data = $room_stmt->fetch(PDO::FETCH_ASSOC);
        if ($room_data) {
            $room_name = $room_data['name'];
        }
    }
} catch (PDOException $e) {
    // Log error but continue
    error_log("Error fetching rooms: " . $e->getMessage());
}

// Handle form submission via POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validate CSRF token
    requireCsrfValidation();
    
    // Initialize validation errors array
    $validation_errors = [];
    $sanitized_data = [];
    
    // Validate guest_name
    $name_validation = validateName($_POST['guest_name'] ?? '', 2, true);
    if (!$name_validation['valid']) {
        $validation_errors['guest_name'] = $name_validation['error'];
    } else {
        $sanitized_data['guest_name'] = sanitizeString($name_validation['value'], 100);
    }
    
    // Validate guest_email
    $guest_email_input = trim($_POST['guest_email'] ?? '');
    $email_validation = validateEmail($guest_email_input);
    if (!$email_validation['valid']) {
        $validation_errors['guest_email'] = $email_validation['error'];
    } else {
        $sanitized_data['guest_email'] = sanitizeString($guest_email_input, 254);
    }
    
    // Validate overall_rating
    $rating_validation = validateRating($_POST['overall_rating'] ?? '', true);
    if (!$rating_validation['valid']) {
        $validation_errors['overall_rating'] = $rating_validation['error'];
    } else {
        $sanitized_data['overall_rating'] = $rating_validation['value'];
    }
    
    // Validate review_title
    $title_validation = validateText($_POST['review_title'] ?? '', 5, 200, true);
    if (!$title_validation['valid']) {
        $validation_errors['review_title'] = $title_validation['error'];
    } else {
        $sanitized_data['review_title'] = sanitizeString($title_validation['value'], 200);
    }
    
    // Validate review_comment
    $comment_validation = validateText($_POST['review_comment'] ?? '', 20, 2000, true);
    if (!$comment_validation['valid']) {
        $validation_errors['review_comment'] = $comment_validation['error'];
    } else {
        $sanitized_data['review_comment'] = sanitizeString($comment_validation['value'], 2000);
    }
    
    // Validate review_type (optional)
    $allowed_review_types = ['', 'room', 'restaurant', 'spa', 'conference', 'gym', 'service'];
    $type_validation = validateSelectOption($_POST['review_type'] ?? '', $allowed_review_types, false);
    if (!$type_validation['valid']) {
        $validation_errors['review_type'] = $type_validation['error'];
    } else {
        $sanitized_data['review_type'] = $type_validation['value'] ?: 'general';
    }
    
    // Validate room_id (optional, only if review_type is 'room')
    $room_id = null;
    if ($sanitized_data['review_type'] === 'room' && !empty($_POST['room_id'])) {
        $room_validation = validateRoomId($_POST['room_id']);
        if (!$room_validation['valid']) {
            $validation_errors['room_id'] = $room_validation['error'];
        } else {
            $room_id = $room_validation['room']['id'];
        }
    }
    
    // Validate optional ratings
    $sanitized_data['service_rating'] = null;
    if (!empty($_POST['service_rating'])) {
        $service_validation = validateRating($_POST['service_rating'], false);
        if (!$service_validation['valid']) {
            $validation_errors['service_rating'] = $service_validation['error'];
        } else {
            $sanitized_data['service_rating'] = $service_validation['value'];
        }
    }
    
    $sanitized_data['cleanliness_rating'] = null;
    if (!empty($_POST['cleanliness_rating'])) {
        $cleanliness_validation = validateRating($_POST['cleanliness_rating'], false);
        if (!$cleanliness_validation['valid']) {
            $validation_errors['cleanliness_rating'] = $cleanliness_validation['error'];
        } else {
            $sanitized_data['cleanliness_rating'] = $cleanliness_validation['value'];
        }
    }
    
    $sanitized_data['location_rating'] = null;
    if (!empty($_POST['location_rating'])) {
        $location_validation = validateRating($_POST['location_rating'], false);
        if (!$location_validation['valid']) {
            $validation_errors['location_rating'] = $location_validation['error'];
        } else {
            $sanitized_data['location_rating'] = $location_validation['value'];
        }
    }
    
    $sanitized_data['value_rating'] = null;
    if (!empty($_POST['value_rating'])) {
        $value_validation = validateRating($_POST['value_rating'], false);
        if (!$value_validation['valid']) {
            $validation_errors['value_rating'] = $value_validation['error'];
        } else {
            $sanitized_data['value_rating'] = $value_validation['value'];
        }
    }
    
    // Check for validation errors
    if (!empty($validation_errors)) {
        $errors = [];
        foreach ($validation_errors as $field => $message) {
            $errors[] = ucfirst(str_replace('_', ' ', $field)) . ': ' . $message;
        }
    } else {
        $errors = [];
    }
    
    if (empty($errors)) {
        // Insert review directly into database
        try {
            $stmt = $pdo->prepare("
                INSERT INTO reviews (
                    guest_name,
                    guest_email,
                    rating,
                    title,
                    comment,
                    review_type,
                    room_id,
                    service_rating,
                    cleanliness_rating,
                    location_rating,
                    value_rating,
                    status,
                    created_at
                ) VALUES (
                    ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', NOW()
                )
            ");
            
            $stmt->execute([
                $sanitized_data['guest_name'],
                $sanitized_data['guest_email'],
                $sanitized_data['overall_rating'],
                $sanitized_data['review_title'],
                $sanitized_data['review_comment'],
                $sanitized_data['review_type'],
                $room_id,
                $sanitized_data['service_rating'],
                $sanitized_data['cleanliness_rating'],
                $sanitized_data['location_rating'],
                $sanitized_data['value_rating']
            ]);
            
            // Store review details for confirmation page
            $_SESSION['review_details'] = [
                'guest_name' => $sanitized_data['guest_name'],
                'review_title' => $sanitized_data['review_title'],
                'overall_rating' => $sanitized_data['overall_rating'],
                'room_id' => $room_id
            ];
            
            // Redirect to confirmation page
            header('Location: review-confirmation.php');
            exit;
            
        } catch (PDOException $e) {
            error_log("Error inserting review: " . $e->getMessage());
            $_SESSION['alert'] = [
                'type' => 'error',
                'message' => 'An error occurred while submitting your review. Please try again.'
            ];
        }
    } else {
        // Set error messages
        $_SESSION['alert'] = [
            'type' => 'error',
            'message' => implode(' ', $errors)
        ];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Submit a Review | <?php echo htmlspecialchars(getSetting('site_name', 'Hotel Website')); ?></title>
    <meta name="description" content="Share your experience at <?php echo htmlspecialchars(getSetting('site_name', 'Hotel Website')); ?>. Submit your review and help us improve our services.">
    
    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;500;600;700&family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet" media="print" onload="this.media='all'">
    
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" media="print" onload="this.media='all'">
    
    <!-- Main CSS -->
    <link rel="stylesheet" href="css/theme-dynamic.php">
    <link rel="stylesheet" href="css/header.css">
    <link rel="stylesheet" href="css/style.css">
    <link rel="stylesheet" href="css/footer.css">
    
    <!-- Custom CSS for Review Form -->
    <style>
        /* Review Form Page Styles */
        .review-page {
            background: linear-gradient(135deg, var(--cream) 0%, #F5F1E8 100%);
            min-height: 100vh;
            padding-top: 100px;
            padding-bottom: 60px;
        }
        
        .review-hero {
            text-align: center;
            padding: 40px 20px;
            background: linear-gradient(135deg, var(--navy) 0%, var(--deep-navy) 100%);
            color: var(--white);
            margin-bottom: 40px;
            position: relative;
            overflow: hidden;
        }
        
        .review-hero::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, var(--gold) 0%, #FFD700 50%, var(--dark-gold) 100%);
        }
        
        .review-hero h1 {
            font-family: var(--font-serif);
            font-size: 36px;
            margin-bottom: 16px;
            color: var(--gold);
        }
        
        .review-hero p {
            font-size: 16px;
            opacity: 0.9;
            max-width: 600px;
            margin: 0 auto;
        }
        
        .review-form-container {
            max-width: 800px;
            margin: 0 auto;
            padding: 0 20px;
        }
        
        .review-form-card {
            background: var(--white);
            border-radius: 20px;
            padding: 40px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.1);
            border: 1px solid rgba(212, 175, 55, 0.15);
            position: relative;
        }
        
        .review-form-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, var(--gold) 0%, #FFD700 50%, var(--dark-gold) 100%);
            border-radius: 20px 20px 0 0;
        }
        
        .form-section-title {
            font-family: var(--font-serif);
            font-size: 22px;
            color: var(--navy);
            margin-bottom: 24px;
            padding-bottom: 12px;
            border-bottom: 2px solid rgba(212, 175, 55, 0.2);
            display: flex;
            align-items: center;
            gap: 12px;
        }
        
        .form-section-title i {
            color: var(--gold);
            font-size: 20px;
        }
        
        .form-row {
            display: grid;
            grid-template-columns: 1fr;
            gap: 20px;
            margin-bottom: 30px;
        }
        
        @media (min-width: 768px) {
            .form-row.two-columns {
                grid-template-columns: 1fr 1fr;
            }
        }
        
        .form-group {
            margin-bottom: 24px;
        }
        
        .form-group label {
            display: block;
            font-weight: 600;
            color: var(--navy);
            margin-bottom: 8px;
            font-size: 14px;
            letter-spacing: 0.5px;
        }
        
        .form-group label .required {
            color: #dc3545;
            margin-left: 4px;
        }
        
        .form-group label .optional {
            color: #999;
            font-weight: 400;
            font-size: 12px;
            margin-left: 4px;
        }
        
        .form-group input[type="text"],
        .form-group input[type="email"],
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 14px 16px;
            border: 2px solid #e0e5eb;
            border-radius: 12px;
            font-size: 15px;
            font-family: var(--font-sans);
            background: #f9fbff;
            transition: all 0.3s ease;
        }
        
        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: var(--gold);
            background: #fff;
            box-shadow: 0 0 0 4px rgba(212, 175, 55, 0.1);
        }
        
        .form-group textarea {
            resize: vertical;
            min-height: 120px;
        }
        
        .form-hint {
            font-size: 12px;
            color: #888;
            margin-top: 6px;
        }
        
        /* Star Rating Selector */
        .star-rating {
            display: flex;
            gap: 8px;
            align-items: center;
        }
        
        .star-rating .star {
            font-size: 32px;
            color: #ddd;
            cursor: pointer;
            transition: all 0.2s ease;
            position: relative;
        }
        
        .star-rating .star:hover {
            transform: scale(1.15);
        }
        
        .star-rating .star.active,
        .star-rating .star.hover {
            color: var(--gold);
        }
        
        .star-rating .star i {
            display: block;
        }
        
        .star-rating .star.filled i::before {
            content: '\f005';
        }
        
        .star-rating .star.empty i::before {
            content: '\f005';
        }
        
        .star-rating-label {
            margin-left: 12px;
            font-size: 14px;
            color: #666;
            font-weight: 500;
        }
        
        .rating-group {
            display: flex;
            align-items: center;
            gap: 20px;
            flex-wrap: wrap;
        }
        
        .rating-group .star-rating {
            flex-shrink: 0;
        }
        
        /* Optional Ratings Section */
        .optional-ratings {
            background: linear-gradient(135deg, rgba(212, 175, 55, 0.05) 0%, rgba(212, 175, 55, 0.02) 100%);
            border-radius: 16px;
            padding: 24px;
            margin-top: 30px;
            border: 1px solid rgba(212, 175, 55, 0.15);
        }
        
        .optional-ratings-title {
            font-size: 14px;
            font-weight: 600;
            color: var(--navy);
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .optional-ratings-title i {
            color: var(--gold);
        }
        
        .optional-rating-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 16px 0;
            border-bottom: 1px solid rgba(212, 175, 55, 0.1);
        }
        
        .optional-rating-item:last-child {
            border-bottom: none;
            padding-bottom: 0;
        }
        
        .optional-rating-item:first-child {
            padding-top: 0;
        }
        
        .optional-rating-label {
            font-size: 14px;
            color: var(--navy);
            font-weight: 500;
            flex: 1;
        }
        
        /* Submit Button */
        .submit-btn {
            width: 100%;
            padding: 16px 32px;
            background: linear-gradient(135deg, var(--gold) 0%, #E5D047 50%, var(--dark-gold) 100%);
            color: var(--navy);
            border: none;
            border-radius: 12px;
            font-size: 16px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 1.5px;
            cursor: pointer;
            transition: all 0.3s ease;
            box-shadow: 0 8px 24px rgba(212, 175, 55, 0.4), inset 0 1px 0 rgba(255, 255, 255, 0.3);
            position: relative;
            overflow: hidden;
        }
        
        .submit-btn:hover {
            transform: translateY(-3px);
            box-shadow: 0 12px 32px rgba(212, 175, 55, 0.5), inset 0 1px 0 rgba(255, 255, 255, 0.4);
        }
        
        .submit-btn:active {
            transform: translateY(-1px);
        }
        
        .submit-btn:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none;
        }
        
        .submit-btn .btn-text {
            position: relative;
            z-index: 1;
        }
        
        .submit-btn .loading-spinner {
            display: none;
            width: 20px;
            height: 20px;
            border: 2px solid rgba(10, 25, 41, 0.3);
            border-top-color: var(--navy);
            border-radius: 50%;
            animation: spin 0.8s linear infinite;
            margin-right: 10px;
        }
        
        .submit-btn.loading .loading-spinner {
            display: inline-block;
        }
        
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
        
        /* Room Selection Badge */
        .room-selection-badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 8px 16px;
            background: rgba(212, 175, 55, 0.15);
            border: 1px solid rgba(212, 175, 55, 0.3);
            border-radius: 20px;
            color: var(--navy);
            font-size: 13px;
            font-weight: 600;
            margin-bottom: 20px;
        }
        
        .room-selection-badge i {
            color: var(--gold);
        }
        
        /* Form Validation Styles */
        .form-group.error input,
        .form-group.error select,
        .form-group.error textarea {
            border-color: #dc3545;
            background: #fff5f5;
        }
        
        .form-group.error .form-hint {
            color: #dc3545;
        }
        
        .form-group.success input,
        .form-group.success select,
        .form-group.success textarea {
            border-color: #28a745;
            background: #f0fff4;
        }
        
        /* Responsive Styles */
        @media (max-width: 768px) {
            .review-page {
                padding-top: 80px;
                padding-bottom: 40px;
            }
            
            .review-hero h1 {
                font-size: 28px;
            }
            
            .review-hero p {
                font-size: 14px;
            }
            
            .review-form-card {
                padding: 24px;
            }
            
            .form-section-title {
                font-size: 18px;
            }
            
            .star-rating .star {
                font-size: 28px;
            }
            
            .optional-rating-item {
                flex-direction: column;
                align-items: flex-start;
                gap: 12px;
            }
        }
        
        /* Accessibility */
        .star-rating .star:focus {
            outline: 2px solid var(--gold);
            outline-offset: 2px;
        }
        
        .star-rating .star[aria-pressed="true"] {
            color: var(--gold);
        }
    </style>
</head>
<body>
    <?php include 'includes/header.php'; ?>
    
    <main class="review-page">
        <!-- Hero Section -->
        <section class="review-hero">
            <div class="container">
                <h1>Share Your Experience</h1>
                <p>Your feedback helps us improve and provide exceptional service to all our guests.</p>
            </div>
        </section>
        
        <!-- Review Form -->
        <section class="review-form-container">
            <div class="review-form-card">
                <?php if (isset($_SESSION['alert'])): ?>
                    <?php showSessionAlert(); ?>
                <?php endif; ?>
                
                <?php if ($room_name): ?>
                    <div class="room-selection-badge">
                        <i class="fas fa-bed"></i>
                        <span>Reviewing: <?php echo htmlspecialchars($room_name); ?></span>
                    </div>
                <?php endif; ?>
                
                <form id="reviewForm" method="POST" action="submit-review.php<?php echo $selected_room_id > 0 ? '?room_id=' . $selected_room_id : ''; ?>" novalidate>
                    <?php echo getCsrfField(); ?>
                    <!-- Personal Information -->
                    <div class="form-section-title">
                        <i class="fas fa-user"></i>
                        <span>Your Information</span>
                    </div>
                    
                    <div class="form-row two-columns">
                        <div class="form-group">
                            <label for="guest_name">
                                Guest Name <span class="required">*</span>
                            </label>
                            <input 
                                type="text" 
                                id="guest_name" 
                                name="guest_name" 
                                placeholder="Enter your full name"
                                required
                                aria-required="true"
                                minlength="2"
                            >
                            <p class="form-hint">Your name will be displayed with your review</p>
                        </div>
                        
                        <div class="form-group">
                            <label for="guest_email">
                                Email Address <span class="required">*</span>
                            </label>
                            <input 
                                type="email" 
                                id="guest_email" 
                                name="guest_email" 
                                placeholder="your@email.com"
                                required
                                aria-required="true"
                            >
                            <p class="form-hint">We'll never share your email with anyone</p>
                        </div>
                    </div>
                    
                    <!-- What are you reviewing? -->
                    <div class="form-group">
                        <label for="review_type">
                            What are you reviewing? <span class="optional">(Optional)</span>
                        </label>
                        <select id="review_type" name="review_type">
                            <option value="">General Hotel Experience</option>
                            <option value="room" <?php echo $selected_room_id > 0 ? 'selected' : ''; ?>>Specific Room</option>
                            <option value="restaurant">Restaurant & Dining</option>
                            <option value="spa">Spa & Wellness</option>
                            <option value="conference">Conference & Events</option>
                            <option value="gym">Fitness Center</option>
                            <option value="service">Staff & Service</option>
                        </select>
                        <p class="form-hint">Select the specific area you're reviewing, or leave blank for a general hotel review</p>
                    </div>
                    
                    <!-- Room Selection (shown only when "Specific Room" is selected) -->
                    <div class="form-group" id="roomSelectionGroup" style="display: <?php echo $selected_room_id > 0 ? 'block' : 'none'; ?>;">
                        <label for="room_id">
                            Which room did you stay in? <span class="optional">(Optional)</span>
                        </label>
                        <select id="room_id" name="room_id">
                            <option value="">Select a room type...</option>
                            <?php foreach ($rooms as $room): ?>
                                <option value="<?php echo $room['id']; ?>" <?php echo $selected_room_id === (int)$room['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($room['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <p class="form-hint">Help us understand which room you stayed in</p>
                    </div>
                    
                    <!-- Overall Rating -->
                    <div class="form-section-title" style="margin-top: 30px;">
                        <i class="fas fa-star"></i>
                        <span>Overall Rating <span class="required">*</span></span>
                    </div>
                    
                    <div class="form-group">
                        <div class="rating-group">
                            <div class="star-rating" id="overallRating" data-rating="0">
                                <span class="star" data-value="1" role="button" tabindex="0" aria-label="1 star" aria-pressed="false"><i class="fas fa-star"></i></span>
                                <span class="star" data-value="2" role="button" tabindex="0" aria-label="2 stars" aria-pressed="false"><i class="fas fa-star"></i></span>
                                <span class="star" data-value="3" role="button" tabindex="0" aria-label="3 stars" aria-pressed="false"><i class="fas fa-star"></i></span>
                                <span class="star" data-value="4" role="button" tabindex="0" aria-label="4 stars" aria-pressed="false"><i class="fas fa-star"></i></span>
                                <span class="star" data-value="5" role="button" tabindex="0" aria-label="5 stars" aria-pressed="false"><i class="fas fa-star"></i></span>
                            </div>
                            <span class="star-rating-label" id="overallRatingLabel">Select a rating</span>
                        </div>
                        <input type="hidden" id="overall_rating" name="overall_rating" value="0" required>
                        <p class="form-hint">How would you rate your overall experience?</p>
                    </div>
                    
                    <!-- Review Content -->
                    <div class="form-section-title" style="margin-top: 30px;">
                        <i class="fas fa-comment-alt"></i>
                        <span>Your Review</span>
                    </div>
                    
                    <div class="form-group">
                        <label for="review_title">
                            Review Title <span class="required">*</span>
                        </label>
                        <input 
                            type="text" 
                            id="review_title" 
                            name="review_title" 
                            placeholder="Summarize your experience in a few words"
                            required
                            aria-required="true"
                            minlength="5"
                        >
                        <p class="form-hint">A catchy title for your review (min. 5 characters)</p>
                    </div>
                    
                    <div class="form-group">
                        <label for="review_comment">
                            Review Comment <span class="required">*</span>
                        </label>
                        <textarea 
                            id="review_comment" 
                            name="review_comment" 
                            placeholder="Tell us about your stay, what you loved, and how we can improve..."
                            required
                            aria-required="true"
                            minlength="20"
                        ></textarea>
                        <p class="form-hint">Share your detailed experience (min. 20 characters)</p>
                    </div>
                    
                    <!-- Optional Detailed Ratings -->
                    <div class="optional-ratings">
                        <div class="optional-ratings-title">
                            <i class="fas fa-chart-bar"></i>
                            <span>Detailed Ratings <span class="optional">(Optional)</span></span>
                        </div>
                        
                        <div class="optional-rating-item">
                            <span class="optional-rating-label">Service</span>
                            <div class="star-rating" id="serviceRating" data-rating="0">
                                <span class="star" data-value="1" role="button" tabindex="0" aria-label="1 star" aria-pressed="false"><i class="fas fa-star"></i></span>
                                <span class="star" data-value="2" role="button" tabindex="0" aria-label="2 stars" aria-pressed="false"><i class="fas fa-star"></i></span>
                                <span class="star" data-value="3" role="button" tabindex="0" aria-label="3 stars" aria-pressed="false"><i class="fas fa-star"></i></span>
                                <span class="star" data-value="4" role="button" tabindex="0" aria-label="4 stars" aria-pressed="false"><i class="fas fa-star"></i></span>
                                <span class="star" data-value="5" role="button" tabindex="0" aria-label="5 stars" aria-pressed="false"><i class="fas fa-star"></i></span>
                            </div>
                            <input type="hidden" id="service_rating" name="service_rating" value="">
                        </div>
                        
                        <div class="optional-rating-item">
                            <span class="optional-rating-label">Cleanliness</span>
                            <div class="star-rating" id="cleanlinessRating" data-rating="0">
                                <span class="star" data-value="1" role="button" tabindex="0" aria-label="1 star" aria-pressed="false"><i class="fas fa-star"></i></span>
                                <span class="star" data-value="2" role="button" tabindex="0" aria-label="2 stars" aria-pressed="false"><i class="fas fa-star"></i></span>
                                <span class="star" data-value="3" role="button" tabindex="0" aria-label="3 stars" aria-pressed="false"><i class="fas fa-star"></i></span>
                                <span class="star" data-value="4" role="button" tabindex="0" aria-label="4 stars" aria-pressed="false"><i class="fas fa-star"></i></span>
                                <span class="star" data-value="5" role="button" tabindex="0" aria-label="5 stars" aria-pressed="false"><i class="fas fa-star"></i></span>
                            </div>
                            <input type="hidden" id="cleanliness_rating" name="cleanliness_rating" value="">
                        </div>
                        
                        <div class="optional-rating-item">
                            <span class="optional-rating-label">Location</span>
                            <div class="star-rating" id="locationRating" data-rating="0">
                                <span class="star" data-value="1" role="button" tabindex="0" aria-label="1 star" aria-pressed="false"><i class="fas fa-star"></i></span>
                                <span class="star" data-value="2" role="button" tabindex="0" aria-label="2 stars" aria-pressed="false"><i class="fas fa-star"></i></span>
                                <span class="star" data-value="3" role="button" tabindex="0" aria-label="3 stars" aria-pressed="false"><i class="fas fa-star"></i></span>
                                <span class="star" data-value="4" role="button" tabindex="0" aria-label="4 stars" aria-pressed="false"><i class="fas fa-star"></i></span>
                                <span class="star" data-value="5" role="button" tabindex="0" aria-label="5 stars" aria-pressed="false"><i class="fas fa-star"></i></span>
                            </div>
                            <input type="hidden" id="location_rating" name="location_rating" value="">
                        </div>
                        
                        <div class="optional-rating-item">
                            <span class="optional-rating-label">Value for Money</span>
                            <div class="star-rating" id="valueRating" data-rating="0">
                                <span class="star" data-value="1" role="button" tabindex="0" aria-label="1 star" aria-pressed="false"><i class="fas fa-star"></i></span>
                                <span class="star" data-value="2" role="button" tabindex="0" aria-label="2 stars" aria-pressed="false"><i class="fas fa-star"></i></span>
                                <span class="star" data-value="3" role="button" tabindex="0" aria-label="3 stars" aria-pressed="false"><i class="fas fa-star"></i></span>
                                <span class="star" data-value="4" role="button" tabindex="0" aria-label="4 stars" aria-pressed="false"><i class="fas fa-star"></i></span>
                                <span class="star" data-value="5" role="button" tabindex="0" aria-label="5 stars" aria-pressed="false"><i class="fas fa-star"></i></span>
                            </div>
                            <input type="hidden" id="value_rating" name="value_rating" value="">
                        </div>
                    </div>
                    
                    <!-- Submit Button -->
                    <div style="margin-top: 30px;">
                        <button type="submit" class="submit-btn" id="submitBtn">
                            <span class="loading-spinner"></span>
                            <span class="btn-text">Submit Review</span>
                        </button>
                    </div>
                    
                    <!-- Privacy Note -->
                    <p style="text-align: center; margin-top: 20px; font-size: 13px; color: #888;">
                        <i class="fas fa-shield-alt"></i>
                        Your review will be published after moderation. We reserve the right to edit or remove inappropriate content.
                    </p>
                </form>
            </div>
        </section>
    </main>
    
    <?php include 'includes/footer.php'; ?>
    <script src="js/modal.js"></script>
    <script src="js/main.js"></script>
    
    <!-- JavaScript for Star Rating and Form Validation -->
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Star Rating Functionality
            const ratingLabels = {
                1: 'Poor',
                2: 'Fair',
                3: 'Good',
                4: 'Very Good',
                5: 'Excellent'
            };
            
            function initStarRating(containerId, inputId, isRequired = false) {
                const container = document.getElementById(containerId);
                const input = document.getElementById(inputId);
                const stars = container.querySelectorAll('.star');
                const labelElement = document.getElementById(containerId + 'Label');
                
                if (!container || !input) return;
                
                // Handle star click
                stars.forEach(star => {
                    star.addEventListener('click', function() {
                        const value = parseInt(this.dataset.value);
                        setRating(value);
                    });
                    
                    // Handle star hover
                    star.addEventListener('mouseenter', function() {
                        const value = parseInt(this.dataset.value);
                        highlightStars(value);
                        if (labelElement) {
                            labelElement.textContent = ratingLabels[value];
                        }
                    });
                    
                    // Handle keyboard navigation
                    star.addEventListener('keydown', function(e) {
                        if (e.key === 'Enter' || e.key === ' ') {
                            e.preventDefault();
                            const value = parseInt(this.dataset.value);
                            setRating(value);
                        }
                    });
                });
                
                // Reset on mouse leave
                container.addEventListener('mouseleave', function() {
                    const currentRating = parseInt(container.dataset.rating);
                    highlightStars(currentRating);
                    if (labelElement) {
                        if (currentRating > 0) {
                            labelElement.textContent = ratingLabels[currentRating];
                        } else {
                            labelElement.textContent = 'Select a rating';
                        }
                    }
                });
                
                function setRating(value) {
                    container.dataset.rating = value;
                    input.value = value;
                    highlightStars(value);
                    
                    // Update ARIA attributes
                    stars.forEach(star => {
                        const starValue = parseInt(star.dataset.value);
                        star.setAttribute('aria-pressed', starValue <= value ? 'true' : 'false');
                    });
                    
                    if (labelElement) {
                        labelElement.textContent = ratingLabels[value];
                    }
                    
                    // Trigger validation
                    validateField(input);
                }
                
                function highlightStars(value) {
                    stars.forEach(star => {
                        const starValue = parseInt(star.dataset.value);
                        if (starValue <= value) {
                            star.classList.add('active');
                            star.classList.add('filled');
                            star.classList.remove('empty');
                        } else {
                            star.classList.remove('active');
                            star.classList.remove('filled');
                            star.classList.add('empty');
                        }
                    });
                }
            }
            
            // Initialize all star ratings
            initStarRating('overallRating', 'overall_rating', true);
            initStarRating('serviceRating', 'service_rating', false);
            initStarRating('cleanlinessRating', 'cleanliness_rating', false);
            initStarRating('locationRating', 'location_rating', false);
            initStarRating('valueRating', 'value_rating', false);
            
            // Handle review type selection
            const reviewTypeSelect = document.getElementById('review_type');
            const roomSelectionGroup = document.getElementById('roomSelectionGroup');
            
            if (reviewTypeSelect && roomSelectionGroup) {
                reviewTypeSelect.addEventListener('change', function() {
                    if (this.value === 'room') {
                        roomSelectionGroup.style.display = 'block';
                    } else {
                        roomSelectionGroup.style.display = 'none';
                        document.getElementById('room_id').value = '';
                    }
                });
            }
            
            // Form Validation
            const form = document.getElementById('reviewForm');
            const submitBtn = document.getElementById('submitBtn');
            
            function validateField(field) {
                const formGroup = field.closest('.form-group');
                let isValid = true;
                
                if (field.hasAttribute('required') && !field.value.trim()) {
                    isValid = false;
                }
                
                if (field.type === 'email' && field.value.trim()) {
                    const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
                    if (!emailRegex.test(field.value.trim())) {
                        isValid = false;
                    }
                }
                
                if (field.getAttribute('minlength') && field.value.trim().length < parseInt(field.getAttribute('minlength'))) {
                    isValid = false;
                }
                
                // Special validation for overall rating
                if (field.id === 'overall_rating' && parseInt(field.value) < 1) {
                    isValid = false;
                }
                
                if (formGroup) {
                    if (isValid) {
                        formGroup.classList.remove('error');
                        formGroup.classList.add('success');
                    } else {
                        formGroup.classList.remove('success');
                        formGroup.classList.add('error');
                    }
                }
                
                return isValid;
            }
            
            // Real-time validation
            const requiredFields = form.querySelectorAll('input[required], textarea[required], select[required]');
            requiredFields.forEach(field => {
                field.addEventListener('blur', function() {
                    validateField(this);
                });
                
                field.addEventListener('input', function() {
                    if (this.closest('.form-group').classList.contains('error')) {
                        validateField(this);
                    }
                });
            });
            
            // Form submission
            form.addEventListener('submit', function(e) {
                e.preventDefault();
                
                // Validate all fields
                let isFormValid = true;
                requiredFields.forEach(field => {
                    if (!validateField(field)) {
                        isFormValid = false;
                    }
                });
                
                // Validate overall rating
                const overallRating = document.getElementById('overall_rating');
                if (parseInt(overallRating.value) < 1) {
                    isFormValid = false;
                    overallRating.closest('.form-group').classList.add('error');
                }
                
                if (!isFormValid) {
                    // Scroll to first error
                    const firstError = form.querySelector('.form-group.error');
                    if (firstError) {
                        firstError.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    }
                    return;
                }
                
                // Show loading state
                submitBtn.classList.add('loading');
                submitBtn.disabled = true;
                
                // Prepare form data
                const formData = new FormData(form);
                
                // Submit via AJAX
                fetch(form.action, {
                    method: 'POST',
                    body: formData
                })
                .then(response => {
                    if (response.redirected) {
                        window.location.href = response.url;
                        return;
                    }
                    return response.text();
                })
                .then(html => {
                    // Check if response contains success message
                    if (html.includes('alert-success') || html.includes('Thank you for your review')) {
                        // Parse the response to find redirect URL
                        const parser = new DOMParser();
                        const doc = parser.parseFromString(html, 'text/html');
                        
                        // Show success message
                        const alertDiv = document.createElement('div');
                        alertDiv.className = 'alert alert-success';
                        alertDiv.style.cssText = 'background: #1f8f5f; color: white; padding: 16px; border-radius: 8px; margin-bottom: 20px; text-align: center;';
                        alertDiv.innerHTML = '<i class="fas fa-check-circle"></i> Thank you for your review! Your feedback has been submitted successfully.';
                        
                        form.insertBefore(alertDiv, form.firstChild);
                        
                        // Redirect after 2 seconds
                        setTimeout(() => {
                            const roomId = document.getElementById('room_id').value;
                            if (roomId) {
                                window.location.href = 'room.php?id=' + roomId;
                            } else {
                                window.location.href = 'index.php';
                            }
                        }, 2000);
                    } else {
                        // Show error message
                        const alertDiv = document.createElement('div');
                        alertDiv.className = 'alert alert-error';
                        alertDiv.style.cssText = 'background: #c0392b; color: white; padding: 16px; border-radius: 8px; margin-bottom: 20px; text-align: center;';
                        alertDiv.innerHTML = '<i class="fas fa-exclamation-circle"></i> An error occurred while submitting your review. Please try again.';
                        
                        form.insertBefore(alertDiv, form.firstChild);
                        
                        // Reset button
                        submitBtn.classList.remove('loading');
                        submitBtn.disabled = false;
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    
                    // Show error message
                    const alertDiv = document.createElement('div');
                    alertDiv.className = 'alert alert-error';
                    alertDiv.style.cssText = 'background: #c0392b; color: white; padding: 16px; border-radius: 8px; margin-bottom: 20px; text-align: center;';
                    alertDiv.innerHTML = '<i class="fas fa-exclamation-circle"></i> An error occurred while submitting your review. Please try again.';
                    
                    form.insertBefore(alertDiv, form.firstChild);
                    
                    // Reset button
                    submitBtn.classList.remove('loading');
                    submitBtn.disabled = false;
                });
            });
        });
    </script>
</body>
</html>
