<?php
// Load security configuration first
require_once 'config/security.php';

require_once 'config/database.php';
require_once 'includes/reviews-display.php';
require_once 'includes/video-display.php';
require_once 'includes/section-headers.php';

// Send security headers
sendSecurityHeaders();

// Helper: resolve image URL (supports relative and absolute URLs)
function resolveImageUrl($path) {
    if (!$path) return '';
    $trimmed = trim($path);
    if (stripos($trimmed, 'http://') === 0 || stripos($trimmed, 'https://') === 0) {
        return $trimmed; // external URL
    }
    return $trimmed; // relative path as-is
}

// Fetch site settings (cached)
$hero_title = getSetting('hero_title');
$hero_subtitle = getSetting('hero_subtitle');
$site_name = getSetting('site_name');
$site_logo = getSetting('site_logo');
$currency_symbol = getSetting('currency_symbol');
$currency_code = getSetting('currency_code');

// Fetch cached data for performance
$policies = getCachedPolicies();
$hero_slides = getCachedHeroSlides();
$featured_rooms = getCachedRooms(['is_featured' => true, 'limit' => 6]);
$facilities = getCachedFacilities(['is_featured' => true, 'limit' => 6]);
$gallery_images = getCachedGalleryImages();
$testimonials = getCachedTestimonials(3);

// Fetch cached About Us content
$about_data = getCachedAboutUs();
$about_content = $about_data['content'];
$about_features = $about_data['features'];
$about_stats = $about_data['stats'];

// Fetch hotel-wide reviews (with caching)
$hotel_reviews = [];
$review_averages = [];
try {
    // Try to get from cache first
    $reviews_cache = getCache('hotel_reviews_6', null);
    
    if ($reviews_cache !== null) {
        $hotel_reviews = $reviews_cache['reviews'];
        $review_averages = $reviews_cache['averages'];
    } else {
        // Fetch from database if not cached
        $reviews_data = fetchReviews(null, 'approved', 6, 0);
        
        if (isset($reviews_data['data'])) {
            $hotel_reviews = $reviews_data['data']['reviews'] ?? [];
            $review_averages = $reviews_data['data']['averages'] ?? [];
        } else {
            $hotel_reviews = $reviews_data['reviews'] ?? [];
            $review_averages = $reviews_data['averages'] ?? [];
        }
        
        // Cache for 30 minutes
        setCache('hotel_reviews_6', [
            'reviews' => $hotel_reviews,
            'averages' => $review_averages
        ], 1800);
    }
} catch (Exception $e) {
    error_log("Error fetching hotel reviews: " . $e->getMessage());
    $hotel_reviews = [];
    $review_averages = [];
}

// Fetch contact settings (cached)
$contact_settings = getSettingsByGroup('contact');
$contact = [];
foreach ($contact_settings as $setting) {
    $contact[$setting['setting_key']] = $setting['setting_value'];
}

// Fetch social media links (cached)
$social_settings = getSettingsByGroup('social');
$social = [];
foreach ($social_settings as $setting) {
    $social[$setting['setting_key']] = $setting['setting_value'];
}

// Fetch footer links (cached)
$footer_links_raw = getCache('footer_links', null);
if ($footer_links_raw === null) {
    try {
        $stmt = $pdo->query("
            SELECT column_name, link_text, link_url 
            FROM footer_links 
            WHERE is_active = 1 
            ORDER BY column_name, display_order
        ");
        $footer_links_raw = $stmt->fetchAll();
        setCache('footer_links', $footer_links_raw, 3600);
    } catch (PDOException $e) {
        $footer_links_raw = [];
    }
}

// Group footer links by column
$footer_links = [];
foreach ($footer_links_raw as $link) {
    $footer_links[$link['column_name']][] = $link;
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
    <title><?php echo htmlspecialchars($site_name); ?> - Luxury Hotel | Premium Accommodation</title>
    <meta name="description" content="<?php echo htmlspecialchars($hero_subtitle); ?>. Book your stay at our premier luxury hotel featuring world-class dining, spa, and breathtaking views.">
    <meta name="keywords" content="<?php echo htmlspecialchars(getSetting('default_keywords', 'luxury hotel, premium accommodation, resort')); ?>">
    <meta name="author" content="<?php echo htmlspecialchars($site_name); ?>">
    <meta name="robots" content="index, follow">
    <link rel="canonical" href="https://<?php echo $_SERVER['HTTP_HOST']; ?>/">
    
    <!-- Open Graph / Facebook -->
    <meta property="og:type" content="website">
    <meta property="og:url" content="https://<?php echo $_SERVER['HTTP_HOST']; ?>/">
    <meta property="og:title" content="<?php echo htmlspecialchars($site_name); ?> - Luxury Hotel">
    <meta property="og:description" content="<?php echo htmlspecialchars($hero_subtitle); ?>">
    <meta property="og:image" content="https://<?php echo $_SERVER['HTTP_HOST']; ?>/images/hero/slide1.jpg">
    
    <!-- Twitter -->
    <meta property="twitter:card" content="summary_large_image">
    <meta property="twitter:url" content="https://<?php echo $_SERVER['HTTP_HOST']; ?>/">
    <meta property="twitter:title" content="<?php echo htmlspecialchars($site_name); ?> - Luxury Hotel in Malawi">
    <meta property="twitter:description" content="<?php echo htmlspecialchars($hero_subtitle); ?>">
    <meta property="twitter:image" content="https://<?php echo $_SERVER['HTTP_HOST']; ?>/images/hero/slide1.jpg">
    
    <!-- Preload Critical Resources -->
    <link rel="preload" href="css/style.css" as="style">
    <?php if (!empty($hero_slides[0]['image_path'])): ?>
    <link rel="preload" as="image" href="<?php echo htmlspecialchars($hero_slides[0]['image_path']); ?>" fetchpriority="high">
    <?php endif; ?>
    
    <!-- Session Handler -->
    <script src="js/session-handler.js" defer></script>
    
    <!-- Modal Component -->
    <script src="js/modal.js" defer></script>
    
    <!-- JavaScript -->
    <script src="js/main.js" defer></script>
    
    <!-- Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;500;600;700&family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet" media="print" onload="this.media='all'">
    
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" media="print" onload="this.media='all'">
    
    <!-- Custom CSS -->
    <link rel="stylesheet" href="css/theme-dynamic.php">
    <link rel="stylesheet" href="css/header.css">
    <link rel="stylesheet" href="css/style.css">
    <link rel="stylesheet" href="css/footer.css">
    
    <!-- Structured Data - Local Business -->
    <script type="application/ld+json">
    {
      "@context": "https://schema.org",
      "@type": "Hotel",
      "name": "<?php echo htmlspecialchars($site_name); ?>",
      "image": "https://<?php echo $_SERVER['HTTP_HOST']; ?>/images/hero/slide1.jpg",
      "description": "<?php echo htmlspecialchars($hero_subtitle); ?>",
      "address": {
        "@type": "PostalAddress",
        "streetAddress": "<?php echo htmlspecialchars($contact['address_line1']); ?>",
        "addressLocality": "<?php echo htmlspecialchars($contact['address_line2'] ?? ''); ?>",
        "addressRegion": "<?php echo htmlspecialchars($contact['address_region'] ?? ''); ?>",
        "addressCountry": "<?php echo htmlspecialchars($contact['address_country'] ?? ''); ?>"
      },
      "telephone": "<?php echo htmlspecialchars($contact['phone_main']); ?>",
      "email": "<?php echo htmlspecialchars($contact['email_main']); ?>",
      "url": "https://<?php echo $_SERVER['HTTP_HOST']; ?>/",
      "starRating": {
        "@type": "Rating",
        "ratingValue": "5"
      },
      "priceRange": "$$$"
    }
    </script>
</head>
<body>
    <?php include 'includes/loader.php'; ?>
    
    <!-- Header & Navigation - Supreme Premium -->
    <?php include 'includes/header.php'; ?>

    <main>
    <!-- Hero Section: Luxury Carousel -->
    <section class="hero" id="home">
        <div class="hero-carousel">
            <?php foreach ($hero_slides as $index => $slide): ?>
            <div class="hero-slide <?php echo $index === 0 ? 'active' : ''; ?>" data-index="<?php echo $index; ?>">
                <?php if (!empty($slide['video_path'])): ?>
                    <!-- Display video if available -->
                    <div class="hero-slide-video">
                        <?php echo renderVideoEmbed($slide['video_path'], $slide['video_type'], [
                            'autoplay' => true,
                            'muted' => true,
                            'controls' => false,
                            'loop' => true,
                            'class' => 'hero-slide-video-embed',
                            'style' => 'position: absolute; top: 0; left: 0; width: 100%; height: 100%; object-fit: cover;'
                        ]); ?>
                    </div>
                <?php else: ?>
                    <!-- Display image background if no video -->
                    <?php if ($index === 0): ?>
                    <div class="hero-slide-image" style="background-image: url('<?php echo htmlspecialchars($slide['image_path']); ?>'); position: absolute; top: 0; left: 0; width: 100%; height: 100%; background-size: cover; background-position: center;"></div>
                    <?php else: ?>
                    <div class="hero-slide-image" data-bg="<?php echo htmlspecialchars($slide['image_path']); ?>" style="position: absolute; top: 0; left: 0; width: 100%; height: 100%; background-size: cover; background-position: center;"></div>
                    <?php endif; ?>
                <?php endif; ?>
                <div class="hero-overlay"></div>
                <div class="hero-content fade-in-up">
                    <span class="hero-subtitle"><?php echo htmlspecialchars($slide['subtitle']); ?></span>
                    <h1 class="hero-title"><?php echo htmlspecialchars($slide['title']); ?></h1>
                    <p class="hero-description"><?php echo htmlspecialchars($slide['description']); ?></p>
                    
                    <div class="hero-cta">
                        <?php if (!empty($slide['primary_cta_text'])): ?>
                            <a href="<?php echo htmlspecialchars($slide['primary_cta_link']); ?>" class="btn btn-primary"><?php echo htmlspecialchars($slide['primary_cta_text']); ?></a>
                        <?php endif; ?>
                        <?php if (!empty($slide['secondary_cta_text'])): ?>
                            <a href="<?php echo htmlspecialchars($slide['secondary_cta_link']); ?>" class="btn btn-outline"><?php echo htmlspecialchars($slide['secondary_cta_text']); ?></a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <div class="hero-controls">
            <button class="hero-nav hero-prev" aria-label="Previous slide"><i class="fas fa-bed"></i></button>
            <div class="hero-indicators">
                <?php foreach ($hero_slides as $index => $slide): ?>
                    <button class="hero-indicator <?php echo $index === 0 ? 'active' : ''; ?>" data-index="<?php echo $index; ?>" data-hotel="<?php echo htmlspecialchars($site_name); ?>" aria-label="Go to slide <?php echo $index + 1; ?>"></button>
                <?php endforeach; ?>
            </div>
            <button class="hero-nav hero-next" aria-label="Next slide"><i class="fas fa-utensils"></i></button>
            </div>
    
            <!-- Time and Weather Widget - Desktop -->
        <div class="hero-widget hero-widget-desktop">
            <div class="widget-time">
                <span class="time-display" id="heroTime">--:--</span>
                <span class="time-period" id="heroAmpm">AM</span>
            </div>
            <div class="widget-separator"></div>
            <div class="widget-weather">
                <i class="fas fa-sun"></i>
                <span class="temp-display" id="heroTemp">--°C</span>
            </div>
        </div>

        <!-- Time and Weather Widget - Mobile -->
        <div class="hero-widget-mobile">
            <span class="mobile-time" id="heroTimeMobile">--:--</span>
            <span class="mobile-period" id="heroAmpmMobile">AM</span>
            <span class="mobile-separator">|</span>
            <i class="fas fa-sun mobile-icon"></i>
            <span class="mobile-temp" id="heroTempMobile">--°C</span>
        </div>
    </section>

    <!-- About Us Section - Luxury Experience -->
    <section class="about-section" id="about">
        <div class="container about-container">
            <div class="about-grid">
                <div class="about-content">
                    <?php if (!empty($about_content)): ?>
                        <span class="about-eyebrow"><?php echo htmlspecialchars($about_content['subtitle'] ?? 'Our Story'); ?></span>
                        <h2 class="about-title"><?php echo htmlspecialchars($about_content['title'] ?? 'Experience Luxury Redefined'); ?></h2>
                        <p class="about-description">
                            <?php echo htmlspecialchars($about_content['content'] ?? 'Nestled in the heart of Malawi, ' . htmlspecialchars($site_name) . ' offers an unparalleled luxury experience where timeless elegance meets modern comfort. For over two decades, we\'ve been creating unforgettable memories for discerning travelers from around the world.'); ?>
                        </p>
                    <?php else: ?>
                        <span class="about-eyebrow">Our Story</span>
                        <h2 class="about-title">Experience Luxury Redefined</h2>
                        <p class="about-description">
                            Nestled in the heart of Malawi, <?php echo htmlspecialchars($site_name); ?> offers an unparalleled luxury experience
                            where timeless elegance meets modern comfort. For over two decades, we've been creating
                            unforgettable memories for discerning travelers from around the world.
                        </p>
                    <?php endif; ?>
                    
                    <?php if (!empty($about_features)): ?>
                    <div class="about-features">
                        <?php foreach ($about_features as $feature): ?>
                        <div class="about-feature">
                            <?php if (!empty($feature['icon_class'])): ?>
                            <div class="feature-icon">
                                <i class="<?php echo htmlspecialchars($feature['icon_class']); ?>"></i>
                            </div>
                            <?php endif; ?>
                            <div class="feature-content">
                                <?php if (!empty($feature['title'])): ?>
                                <h4><?php echo htmlspecialchars($feature['title']); ?></h4>
                                <?php endif; ?>
                                <?php if (!empty($feature['content'])): ?>
                                <p><?php echo htmlspecialchars($feature['content']); ?></p>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php else: ?>
                    <div class="about-features">
                        <div class="about-feature">
                            <div class="feature-icon">
                                <i class="fas fa-award"></i>
                            </div>
                            <div class="feature-content">
                                <h4>Award-Winning Service</h4>
                                <p>Consistently recognized for exceptional hospitality and guest satisfaction</p>
                            </div>
                        </div>
                        <div class="about-feature">
                            <div class="feature-icon">
                                <i class="fas fa-leaf"></i>
                            </div>
                            <div class="feature-content">
                                <h4>Sustainable Luxury</h4>
                                <p>Committed to eco-friendly practices while maintaining premium standards</p>
                            </div>
                        </div>
                        <div class="about-feature">
                            <div class="feature-icon">
                                <i class="fas fa-heart"></i>
                            </div>
                            <div class="feature-content">
                                <h4>Personalized Care</h4>
                                <p>Tailored experiences designed around your unique preferences and needs</p>
                            </div>
                        </div>
                        <div class="about-feature">
                            <div class="feature-icon">
                                <i class="fas fa-star"></i>
                            </div>
                            <div class="feature-content">
                                <h4>5-Star Excellence</h4>
                                <p>Maintaining the highest standards of quality, comfort, and attention to detail</p>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <?php if (!empty($about_stats)): ?>
                    <div class="about-stats">
                        <?php foreach ($about_stats as $stat): ?>
                        <div class="stat-item">
                            <?php if (!empty($stat['stat_number'])): ?>
                            <span class="stat-number"><?php echo htmlspecialchars($stat['stat_number']); ?></span>
                            <?php endif; ?>
                            <?php if (!empty($stat['stat_label'])): ?>
                            <span class="stat-label"><?php echo htmlspecialchars($stat['stat_label']); ?></span>
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php else: ?>
                    <div class="about-stats">
                        <div class="stat-item">
                            <span class="stat-number">25+</span>
                            <span class="stat-label">Years Excellence</span>
                        </div>
                        <div class="stat-item">
                            <span class="stat-number">98%</span>
                            <span class="stat-label">Guest Satisfaction</span>
                        </div>
                        <div class="stat-item">
                            <span class="stat-number">50+</span>
                            <span class="stat-label">Awards Won</span>
                        </div>
                        <div class="stat-item">
                            <span class="stat-number">10k+</span>
                            <span class="stat-label">Happy Guests</span>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <div class="about-cta">
                        <a href="#rooms" class="btn btn-primary">Explore Our Rooms</a>
                        <a href="#contact" class="btn btn-outline">Contact Us</a>
                    </div>
                </div>
                
                <div class="about-image">
                    <?php if (!empty($about_content['image_url'])): ?>
                    <img src="<?php echo htmlspecialchars(resolveImageUrl($about_content['image_url'])); ?>" alt="<?php echo htmlspecialchars($site_name); ?> - Luxury Exterior" loading="lazy">
                    <?php else: ?>
                    <img src="images/hotel_gallery/Outside2.png" alt="<?php echo htmlspecialchars($site_name); ?> - Luxury Exterior" loading="lazy">
                    <?php endif; ?>
                </div>
                    </div>
            </div>
        </div>
    </section>

    <!-- Featured Rooms Section -->
    <section class="section" id="rooms" style="background: #FBF8F3;">
        <div class="container">
            <?php renderSectionHeader('home_rooms', 'index', [
                'label' => 'Accommodations',
                'title' => 'Luxurious Rooms & Suites',
                'description' => 'Experience unmatched comfort in our meticulously designed rooms and suites'
            ]); ?>
            
            <div class="rooms-grid" id="roomsGrid" data-room-count="<?php echo count($featured_rooms); ?>">
                <?php foreach ($featured_rooms as $room): 
                    $amenities = explode(',', $room['amenities']);
                    $amenities_display = array_slice($amenities, 0, 3);
                ?>
                <a href="room.php?room=<?php echo urlencode($room['slug']); ?>" class="room-card fade-in-up room-card-link">
                    <div class="room-image">
                        <img src="<?php echo htmlspecialchars(resolveImageUrl($room['image_url'])); ?>" alt="<?php echo htmlspecialchars($room['name']); ?> - Luxury accommodation at <?php echo htmlspecialchars($site_name); ?>" loading="lazy">
                        <?php if ($room['badge']): ?>
                        <div class="room-badge"><?php echo htmlspecialchars($room['badge']); ?></div>
                        <?php endif; ?>
                    </div>
                    
                    <div class="room-content">
                        <div class="room-header">
                            <h3 class="room-name"><?php echo htmlspecialchars($room['name']); ?></h3>
                            <div class="room-price">
                                <div class="price-amount"><?php echo htmlspecialchars($currency_symbol); ?><?php echo number_format($room['price_per_night'], 0); ?></div>
                                <div class="price-period">per night</div>
                            </div>
                        </div>
                        
                        <p class="room-description"><?php echo htmlspecialchars($room['short_description']); ?></p>
                        
                        <div class="room-amenities">
                            <?php foreach ($amenities_display as $amenity): ?>
                            <span class="amenity-tag"><?php echo htmlspecialchars(trim($amenity)); ?></span>
                            <?php endforeach; ?>
                        </div>
                        
                        <?php 
                        $available = $room['rooms_available'] ?? 0;
                        $total = $room['total_rooms'] ?? 0;
                        if ($total > 0):
                            $availability_status = $available == 0 ? 'sold-out' : ($available <= 2 ? 'limited' : '');
                        ?>
                        <div class="room-availability <?php echo $availability_status; ?>">
                            <?php if ($available == 0): ?>
                                <i class="fas fa-times-circle"></i> Sold Out
                            <?php elseif ($available <= 2): ?>
                                <i class="fas fa-exclamation-triangle"></i> Only <?php echo $available; ?> left
                            <?php else: ?>
                                <i class="fas fa-check-circle"></i> <?php echo $available; ?> rooms available
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
                        
                        <div class="btn btn-primary room-cta">View & Book</div>
                    </div>
                </a>
                <?php endforeach; ?>
            </div>
        </div>
    </section>

    <!-- Facilities Section -->
    <section class="section" id="facilities">
        <div class="container">
            <?php renderSectionHeader('home_facilities', 'index', [
                'label' => 'Amenities',
                'title' => 'World-Class Facilities',
                'description' => 'Indulge in our premium facilities designed for your ultimate comfort'
            ]); ?>
            
            <div class="facilities-grid">
                <?php foreach ($facilities as $facility): ?>
                <?php if (!empty($facility['page_url'])): ?>
                    <a href="<?php echo htmlspecialchars($facility['page_url']); ?>" class="facility-card facility-card-link">
                        <div class="facility-icon">
                            <i class="<?php echo htmlspecialchars($facility['icon_class']); ?>"></i>
                        </div>
                        <h3 class="facility-name"><?php echo htmlspecialchars($facility['name']); ?></h3>
                        <p class="facility-description"><?php echo htmlspecialchars($facility['short_description']); ?></p>
                        <span class="facility-link-arrow"><i class="fas fa-arrow-right"></i></span>
                    </a>
                <?php else: ?>
                    <div class="facility-card">
                        <div class="facility-icon">
                            <i class="<?php echo htmlspecialchars($facility['icon_class']); ?>"></i>
                        </div>
                        <h3 class="facility-name"><?php echo htmlspecialchars($facility['name']); ?></h3>
                        <p class="facility-description"><?php echo htmlspecialchars($facility['short_description']); ?></p>
                    </div>
                <?php endif; ?>
                <?php endforeach; ?>
            </div>
        </div>
    </section>

    <!-- Upcoming Events Section -->
    <?php 
    $upcoming_events_page = 'index';
    include 'includes/upcoming-events.php'; 
    ?>

    <!-- Hotel Gallery Carousel Section -->
    <?php include 'includes/hotel-gallery.php'; ?>

    <!-- Hotel Reviews Section -->
    <?php include 'includes/reviews-section.php'; ?>

    <!-- Testimonials Section -->
    <section class="section" id="testimonials">
        <div class="container">
            <?php renderSectionHeader('home_testimonials', 'index', [
                'label' => 'Reviews',
                'title' => 'What Our Guests Say',
                'description' => 'Hear from those who have experienced our exceptional hospitality'
            ]); ?>
            
            <div class="testimonials-grid">
                <?php foreach ($testimonials as $testimonial): ?>
                <div class="testimonial-card">
                    <div class="testimonial-quote">"</div>
                    <p class="testimonial-text"><?php echo htmlspecialchars($testimonial['testimonial_text']); ?></p>
                    
                    <div class="testimonial-author">
                        <div class="author-info">
                            <div class="author-name"><?php echo htmlspecialchars($testimonial['guest_name']); ?></div>
                            <div class="author-location"><?php echo htmlspecialchars($testimonial['guest_location']); ?></div>
                            <div class="testimonial-rating">
                                <?php for ($i = 0; $i < $testimonial['rating']; $i++): ?>
                                <i class="fas fa-star"></i>
                                <?php endfor; ?>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </section>

    </main>
    
    <!-- Footer -->
    <?php include 'includes/footer.php'; ?>

    <!-- Scroll to Top Button -->
    <?php include 'includes/scroll-to-top.php'; ?>
</body>
</html>
