<?php
// Production error handling - log errors, don't display
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

require_once 'config/database.php';
require_once 'includes/page-guard.php';
require_once 'includes/image-proxy-helper.php';
require_once 'includes/section-headers.php';


// Fetch all events (both upcoming and expired)
try {
    $stmt = $pdo->prepare("
        SELECT * FROM events
        WHERE is_active = 1
        ORDER BY event_date DESC, start_time DESC
    ");
    $stmt->execute();
    $all_events = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Separate into upcoming and expired
    $upcoming_events = [];
    $expired_events = [];
    $today = date('Y-m-d');
    
    foreach ($all_events as $event) {
        if ($event['event_date'] >= $today) {
            $upcoming_events[] = $event;
        } else {
            $expired_events[] = $event;
        }
    }
    
    // Sort upcoming events ascending
    usort($upcoming_events, function($a, $b) {
        return strtotime($a['event_date']) - strtotime($b['event_date']);
    });
    
} catch (PDOException $e) {
    $upcoming_events = [];
    $expired_events = [];
    error_log("Events fetch error: " . $e->getMessage());
}

// Include video display helper for renderVideoEmbed function
require_once 'includes/video-display.php';

$currency_symbol = getSetting('currency_symbol');
$site_name = getSetting('site_name');
$site_logo = getSetting('site_logo');
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
    <title>Upcoming Events - <?php echo htmlspecialchars($site_name); ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" media="print" onload="this.media='all'">
    <link rel="stylesheet" href="css/theme-dynamic.php">
    <link rel="stylesheet" href="css/header.css">
    <link rel="stylesheet" href="css/style.css">
    <link rel="stylesheet" href="css/footer.css">
    <style>
        .events-section {
            padding: 80px 0;
            background: var(--cream);
        }

        .events-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
            gap: 32px;
            align-items: stretch;
        }

        .event-card {
            background: white;
            border-radius: 16px;
            overflow: hidden;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
            transition: all 0.4s cubic-bezier(0.34, 1.56, 0.64, 1);
            position: relative;
            display: flex;
            flex-direction: column;
        }

        .event-card.featured {
            border: 2px solid var(--gold);
        }

        .event-card:hover {
            transform: translateY(-12px);
            box-shadow: 0 16px 48px rgba(0, 0, 0, 0.2);
        }

        .event-image-container {
            position: relative;
            width: 100%;
            aspect-ratio: 16 / 10;
            overflow: hidden;
        }

        .event-image {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: block;
            transition: transform 0.4s ease;
        }

        .event-card:hover .event-image {
            transform: scale(1.1);
        }

        /* Video styling to match images */
        .event-image-container video {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        /* Fix video wrapper to fit within event-image-container */
        .event-image-container .video-wrapper {
            position: absolute !important;
            top: 0 !important;
            left: 0 !important;
            width: 100% !important;
            height: 100% !important;
            padding-bottom: 0 !important;
            overflow: hidden;
        }

        .event-image-container .video-wrapper iframe {
            position: absolute !important;
            top: 0 !important;
            left: 0 !important;
            width: 100% !important;
            height: 100% !important;
            object-fit: cover;
        }

        .event-date-badge {
            position: absolute;
            top: 16px;
            right: 16px;
            background: linear-gradient(135deg, var(--gold) 0%, #c49b2e 100%);
            color: var(--deep-navy);
            padding: 12px 16px;
            border-radius: 12px;
            text-align: center;
            font-weight: 700;
            box-shadow: 0 4px 16px rgba(212, 175, 55, 0.4);
        }

        .event-date-day {
            font-size: 24px;
            line-height: 1;
            display: block;
        }

        .event-date-month {
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        /* Override global .featured-badge so the event chip stays compact in the top-left */
        .event-card .featured-badge {
            position: absolute;
            top: 16px;
            left: 16px;
            right: auto;
            background: var(--gold);
            color: var(--deep-navy);
            padding: 6px 14px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 1px;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            width: auto;
            max-width: none;
        }

        .event-content {
            padding: 28px;
            display: flex;
            flex-direction: column;
            flex: 1;
        }

        .event-title {
            font-size: 24px;
            font-family: var(--font-serif);
            color: var(--navy);
            margin: 0 0 12px 0;
            line-height: 1.3;
        }

        .event-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 16px;
            margin-bottom: 16px;
            color: #666;
            font-size: 14px;
        }

        .event-meta-item {
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .event-meta-item i {
            color: var(--gold);
        }

        .event-description {
            color: #666;
            line-height: 1.7;
            margin-bottom: 16px;
            flex: 1;
        }

        .event-footer {
            display: flex;
            justify-content: flex-start;
            align-items: center;
            gap: 12px;
            padding-top: 20px;
            border-top: 1px solid #eee;
            margin-top: auto;
            flex-shrink: 0;
        }

        .event-price {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            padding: 10px 16px;
            border-radius: 12px;
            background: linear-gradient(135deg, rgba(212, 175, 55, 0.15), rgba(212, 175, 55, 0.05));
            color: var(--navy);
            font-weight: 700;
            font-size: 18px;
            border: 1px solid rgba(212, 175, 55, 0.35);
            box-shadow: 0 6px 20px rgba(212, 175, 55, 0.15);
        }

        .event-price .price-label {
            background: var(--gold);
            color: var(--deep-navy);
            padding: 6px 10px;
            border-radius: 999px;
            font-size: 12px;
            letter-spacing: 0.5px;
            text-transform: uppercase;
            box-shadow: 0 4px 12px rgba(212, 175, 55, 0.3);
        }

        .event-price .price-value {
            font-size: 22px;
            letter-spacing: 0.3px;
        }

        .event-price.free {
            background: #e8f7ee;
            border-color: #a3e2bb;
            color: #1e7a3c;
            box-shadow: 0 6px 18px rgba(30, 122, 60, 0.15);
        }

        .event-price.free .price-label {
            background: #2ecc71;
            color: white;
            box-shadow: 0 4px 12px rgba(46, 204, 113, 0.25);
        }

        .no-events {
            text-align: center;
            padding: 60px 20px;
            color: #999;
        }

        .no-events i {
            font-size: 64px;
            margin-bottom: 20px;
            color: #ddd;
        }

        .no-events h3 {
            font-size: 28px;
            font-family: var(--font-serif);
            color: var(--navy);
            margin-bottom: 12px;
        }

        @media (max-width: 768px) {

            .events-section {
                padding: 50px 0;
            }

            .events-grid {
                grid-template-columns: 1fr;
                gap: 20px;
            }

            .event-card {
                margin-bottom: 0;
            }

            .event-image-container {
                aspect-ratio: 16 / 10;
            }

            .event-date-badge {
                padding: 8px 12px;
            }

            .event-date-day {
                font-size: 20px;
            }

            .event-date-month {
                font-size: 10px;
            }

            .event-content {
                padding: 20px 16px;
                min-height: auto;
            }

            .event-title {
                font-size: 20px;
                margin-bottom: 10px;
            }

            .event-meta {
                gap: 12px;
                font-size: 13px;
            }

            .event-description {
                font-size: 14px;
                margin-bottom: 16px;
            }

            .event-footer {
                flex-direction: column;
                gap: 12px;
                align-items: flex-start;
                padding-top: 16px;
            }

            .event-price {
                width: 100%;
                justify-content: center;
                padding: 10px 14px;
            }

            .event-price .price-value {
                font-size: 18px;
            }

            .event-price .price-label {
                font-size: 11px;
                padding: 5px 8px;
            }

            .no-events {
                padding: 40px 20px;
            }

            .no-events i {
                font-size: 48px;
                margin-bottom: 16px;
            }

            .no-events h3 {
                font-size: 22px;
            }

            .no-events p {
                font-size: 14px;
            }
        }

        @media (max-width: 480px) {

            .event-image-container {
                aspect-ratio: 16 / 9;
            }

            .event-title {
                font-size: 18px;
            }

            .event-meta {
                flex-direction: column;
                gap: 8px;
            }
        }

        /* Expired Events Styling */
        .expired-events-section {
            margin-top: 60px;
            padding-top: 40px;
            border-top: 2px solid #e0e0e0;
        }

        .expired-section-title {
            font-size: 32px;
            font-family: var(--font-serif);
            color: var(--navy);
            margin-bottom: 12px;
            text-align: center;
        }

        .expired-section-subtitle {
            text-align: center;
            color: #666;
            margin-bottom: 32px;
            font-size: 16px;
        }

        .event-card.expired {
            position: relative;
            overflow: hidden;
        }

        .event-card.expired::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.1);
            backdrop-filter: blur(3px);
            z-index: 1;
            pointer-events: none;
        }

        .event-card.expired .event-image-container,
        .event-card.expired .event-content,
        .event-card.expired .event-footer {
            filter: grayscale(100%) blur(0.5px);
            opacity: 0.6;
            transition: all 0.3s ease;
        }

        .event-card.expired:hover {
            transform: translateY(-8px);
        }

        .event-card.expired:hover .event-image-container,
        .event-card.expired:hover .event-content,
        .event-card.expired:hover .event-footer {
            filter: grayscale(80%) blur(0);
            opacity: 0.8;
        }

        .expired-badge-overlay {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%) rotate(-15deg);
            z-index: 10;
            pointer-events: none;
        }

        .expired-badge {
            background: linear-gradient(135deg, #dc3545 0%, #c82333 100%);
            color: white;
            padding: 12px 24px;
            border-radius: 8px;
            font-size: 18px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 2px;
            box-shadow: 0 8px 32px rgba(220, 53, 69, 0.5);
            border: 3px solid rgba(255, 255, 255, 0.8);
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .expired-badge i {
            font-size: 22px;
        }

        .event-card.expired .event-date-badge {
            background: linear-gradient(135deg, #6c757d 0%, #5a6268 100%);
            box-shadow: 0 4px 16px rgba(108, 117, 125, 0.4);
        }

        .event-card.expired .featured-badge {
            display: none;
        }
    </style>
</head>
<body>
    <?php include 'includes/loader.php'; ?>
    
    <?php include 'includes/header.php'; ?>
    
    <!-- Mobile Menu Overlay -->
    <div class="mobile-menu-overlay" role="presentation"></div>

    <!-- Hero Section -->
    <?php include 'includes/hero.php'; ?>

    <!-- Events Section -->
    <section class="events-section">
        <div class="container">
            <?php renderSectionHeader('events_overview', 'events', [
                'label' => 'Upcoming Events',
                'title' => 'Special Events & Occasions',
                'description' => 'Join us for memorable celebrations and special gatherings'
            ], 'text-center'); ?>
            <?php if (empty($upcoming_events)): ?>
                <div class="no-events">
                    <i class="fas fa-calendar-times"></i>
                    <h3>No Upcoming Events</h3>
                    <p>Check back soon for exciting events and special occasions!</p>
                </div>
            <?php else: ?>
                <div class="events-grid">
                    <?php foreach ($upcoming_events as $event): ?>
                        <?php
                        $event_date = new DateTime($event['event_date']);
                        $day = $event_date->format('d');
                        $month = $event_date->format('M');
                        $formatted_date = $event_date->format('F j, Y');
                        $start_time = !empty($event['start_time']) ? date('g:i A', strtotime($event['start_time'])) : '';
                        $end_time = !empty($event['end_time']) ? date('g:i A', strtotime($event['end_time'])) : '';
                        ?>
                        <div class="event-card <?php echo $event['is_featured'] ? 'featured' : ''; ?>">
                            <div class="event-image-container">
                                <?php if (!empty($event['video_path'])): ?>
                                    <!-- Display video if available -->
                                    <?php echo renderVideoEmbed($event['video_path'], $event['video_type'], [
                                        'autoplay' => true,
                                        'muted' => true,
                                        'controls' => true,
                                        'loop' => true,
                                        'class' => 'event-image',
                                        'style' => 'width: 100%; height: 100%; object-fit: cover; display: block;'
                                    ]); ?>
                                <?php else: ?>
                                    <!-- Display image if no video -->
                                    <?php
                                    // Use event image if exists, otherwise fallback to hero image
                                    $event_image = !empty($event['image_path']) && file_exists($event['image_path'])
                                        ? $event['image_path']
                                        : 'images/hero/slide2.jpg';
                                    // Apply proxy for external images (Facebook, etc.)
                                    $event_image = proxyImageUrl($event_image);
                                    ?>
                                    <img src="<?php echo htmlspecialchars($event_image); ?>"
                                         alt="<?php echo htmlspecialchars($event['title']); ?>"
                                         class="event-image"
                                         loading="lazy"
                                         width="600" height="375"
                                         onerror="this.src='images/hero/slide2.jpg'">
                                <?php endif; ?>
                                
                                <div class="event-date-badge">
                                    <span class="event-date-day"><?php echo $day; ?></span>
                                    <span class="event-date-month"><?php echo $month; ?></span>
                                </div>

                                <?php if ($event['is_featured']): ?>
                                    <div class="featured-badge">
                                        <i class="fas fa-star"></i> Featured
                                    </div>
                                <?php endif; ?>
                            </div>

                            <div class="event-content">
                                <h3 class="event-title"><?php echo htmlspecialchars($event['title']); ?></h3>

                                <div class="event-meta">
                                    <div class="event-meta-item">
                                        <i class="fas fa-calendar-alt"></i>
                                        <span><?php echo $formatted_date; ?></span>
                                    </div>
                                    <?php if ($start_time && $end_time): ?>
                                        <div class="event-meta-item">
                                            <i class="fas fa-clock"></i>
                                            <span><?php echo $start_time . ' - ' . $end_time; ?></span>
                                        </div>
                                    <?php endif; ?>
                                    <?php if (!empty($event['location'])): ?>
                                        <div class="event-meta-item">
                                            <i class="fas fa-map-marker-alt"></i>
                                            <span><?php echo htmlspecialchars($event['location']); ?></span>
                                        </div>
                                    <?php endif; ?>
                                    <?php if ($event['capacity']): ?>
                                        <div class="event-meta-item">
                                            <i class="fas fa-users"></i>
                                            <span>Limited to <?php echo $event['capacity']; ?> guests</span>
                                        </div>
                                    <?php endif; ?>
                                </div>

                                <p class="event-description"><?php echo htmlspecialchars($event['description']); ?></p>

                                <div class="event-footer">
                                    <div class="event-price <?php echo $event['ticket_price'] == 0 ? 'free' : ''; ?>">
                                        <?php if ($event['ticket_price'] == 0): ?>
                                            <span class="price-label">Free</span>
                                            <span class="price-value">Event</span>
                                        <?php else: ?>
                                            <span class="price-label">From</span>
                                            <span class="price-value"><?php echo $currency_symbol . number_format($event['ticket_price'], 0); ?></span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            
            <!-- Expired Events Section -->
            <?php if (!empty($expired_events)): ?>
                <div class="expired-events-section">
                    <h2 class="expired-section-title">Past Events</h2>
                    <p class="expired-section-subtitle">Events that have already taken place</p>
                    
                    <div class="events-grid">
                        <?php foreach ($expired_events as $event): ?>
                            <?php
                            $event_date = new DateTime($event['event_date']);
                            $day = $event_date->format('d');
                            $month = $event_date->format('M');
                            $formatted_date = $event_date->format('F j, Y');
                            $start_time = !empty($event['start_time']) ? date('g:i A', strtotime($event['start_time'])) : '';
                            $end_time = !empty($event['end_time']) ? date('g:i A', strtotime($event['end_time'])) : '';
                            ?>
                            <div class="event-card expired">
                                <div class="expired-badge-overlay">
                                    <div class="expired-badge">
                                        <i class="fas fa-calendar-times"></i>
                                        <span>Expired</span>
                                    </div>
                                </div>
                                
                                <div class="event-image-container">
                                    <?php if (!empty($event['video_path'])): ?>
                                        <?php echo renderVideoEmbed($event['video_path'], $event['video_type'], [
                                            'autoplay' => false,
                                            'muted' => true,
                                            'controls' => false,
                                            'loop' => false,
                                            'class' => 'event-image',
                                            'style' => 'width: 100%; height: 100%; object-fit: cover; display: block;'
                                        ]); ?>
                                    <?php else: ?>
                                        <?php
                                        $event_image = !empty($event['image_path']) && file_exists($event['image_path'])
                                            ? $event['image_path']
                                            : 'images/hero/slide2.jpg';
                                        $event_image = proxyImageUrl($event_image);
                                        ?>
                                        <img src="<?php echo htmlspecialchars($event_image); ?>"
                                             alt="<?php echo htmlspecialchars($event['title']); ?>"
                                             class="event-image"
                                             loading="lazy"
                                             width="600" height="375"
                                             onerror="this.src='images/hero/slide2.jpg'">
                                    <?php endif; ?>
                                    
                                    <div class="event-date-badge">
                                        <span class="event-date-day"><?php echo $day; ?></span>
                                        <span class="event-date-month"><?php echo $month; ?></span>
                                    </div>
                                </div>

                                <div class="event-content">
                                    <h3 class="event-title"><?php echo htmlspecialchars($event['title']); ?></h3>

                                    <div class="event-meta">
                                        <div class="event-meta-item">
                                            <i class="fas fa-calendar-alt"></i>
                                            <span><?php echo $formatted_date; ?></span>
                                        </div>
                                        <?php if ($start_time && $end_time): ?>
                                            <div class="event-meta-item">
                                                <i class="fas fa-clock"></i>
                                                <span><?php echo $start_time . ' - ' . $end_time; ?></span>
                                            </div>
                                        <?php endif; ?>
                                        <?php if (!empty($event['location'])): ?>
                                            <div class="event-meta-item">
                                                <i class="fas fa-map-marker-alt"></i>
                                                <span><?php echo htmlspecialchars($event['location']); ?></span>
                                            </div>
                                        <?php endif; ?>
                                    </div>

                                    <p class="event-description"><?php echo htmlspecialchars($event['description']); ?></p>

                                    <div class="event-footer">
                                        <div class="event-price <?php echo $event['ticket_price'] == 0 ? 'free' : ''; ?>">
                                            <?php if ($event['ticket_price'] == 0): ?>
                                                <span class="price-label">Free</span>
                                                <span class="price-value">Event</span>
                                            <?php else: ?>
                                                <span class="price-label">Was</span>
                                                <span class="price-value"><?php echo $currency_symbol . number_format($event['ticket_price'], 0); ?></span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </section>

    <!-- Footer -->
    <?php include 'includes/footer.php'; ?>

    <script src="js/modal.js"></script>
    <script src="js/main.js"></script>

    <?php include 'includes/scroll-to-top.php'; ?>
</body>
</html>
