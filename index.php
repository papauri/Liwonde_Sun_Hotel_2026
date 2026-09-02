<?php
require_once 'config/database.php';
require_once 'config/base-url.php';
require_once 'includes/reviews-display.php';
require_once 'includes/video-display.php';
require_once 'includes/section-headers.php';

// Helper: resolve image URL (supports relative and absolute URLs)
function resolveImageUrl(?string $path, ?int $timestamp = null)
{
    if (!$path) return '';
    $trimmed = trim($path);
    if (stripos($trimmed, 'http://') === 0 || stripos($trimmed, 'https://') === 0) {
        $url = $trimmed; // external URL
        // Add cache-busting parameter for external URLs if timestamp provided
        if ($timestamp !== null) {
            $separator = (strpos($url, '?') !== false) ? '&' : '?';
            $url .= $separator . 'v=' . $timestamp;
        }
        return $url;
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
    <?php
    $seo_data = [
        'title' => $site_name . ' | Luxury Hotel & Premium Accommodation',
        'description' => $hero_subtitle . '. Book your stay at our premier luxury hotel featuring world-class dining, spa, and breathtaking views.',
        'image' => '/images/hotel_gallery/Front.jpeg',
        'type' => 'website'
    ];
    require_once 'includes/seo-meta.php';
    ?>

    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="format-detection" content="telephone=yes">


    <!-- Performance: Resource Hints -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="preconnect" href="https://cdnjs.cloudflare.com">
    <link rel="dns-prefetch" href="https://api.qrserver.com">

    <!-- Fonts: Optimized with font-display swap -->
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;0,500;0,600;1,300;1,400;1,500&family=Jost:wght@300;400;500;600&display=swap" rel="stylesheet">

    <!-- Font Awesome: Defer non-critical icons --><noscript></noscript>

    <!-- SPA navigation script removed (consolidated transitions are loaded globally via footer include) -->

    <!-- Defer non-critical JS for faster initial load -->
    <script src="js/session-handler.js" defer></script>
    <script src="js/main.js" defer></script>

    <!-- Premium Animations: Load after main content -->
    <script src="js/enhancements.js" defer></script>
    <script src="js/spring-physics.js" defer></script>
    <script src="js/intersection-observer.js" defer></script>
    <script src="js/parallax-cards.js" defer></script>
    <script src="js/bellhop-sections.js" defer></script>

    <!-- Scroll Reveal Animation System - Unified scroll-triggered animations -->
    <script src="js/scroll-reveal.js" defer></script>

    <!-- Optional: Cursor effect (purely decorative) -->
    <script src="js/cursor-follower.js" defer onload="if(!window.matchMedia('(prefers-reduced-motion: reduce)').matches) this.media='all'"></script>

    <!-- Critical CSS: Prevent FOUC and optimize initial render -->
    <link rel="stylesheet" href="css/base/critical.css">

    <!-- Main CSS - Loads all stylesheets in correct order -->
    <link rel="stylesheet" href="css/main.css">

    <!-- Structured Data - Local Business -->
    <script type="application/ld+json">
        {
            "@context": "https://schema.org",
            "@type": "Hotel",
            "name": "<?php echo htmlspecialchars($site_name); ?>",
            "image": "https://<?php echo $_SERVER['HTTP_HOST']; ?>/images/hotel_gallery/Front.jpeg",
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
                "ratingValue": "<?php echo htmlspecialchars(getSetting('hotel_star_rating', '5')); ?>"
            },
            "priceRange": "<?php echo htmlspecialchars(getSetting('price_range_indicator', '$$$')); ?>"
        }
    </script>
</head>

<body class="home-page">
    <?php include 'includes/loader.php'; ?>

    <!-- Header & Navigation - Supreme Premium -->
    <?php include 'includes/header.php'; ?>

    <main class="landing-main" id="main-content">
        <!-- Hero Section - Uses shared hero component for uniformity -->
        <?php include 'includes/hero.php'; ?>

        <!-- Booking Section - Standalone booking widget -->
        <?php if (function_exists('isBookingEnabled') && isBookingEnabled()): ?>
            <?php include 'includes/booking-widget.php'; ?>
        <?php endif; ?>


        <div class="scroll-container landing-scroll-container" id="landing-scroll-container">
            <div class="main-content landing-shell" id="landing-shell">
                <!-- Passalacqua Section18 Style About Section -->
                <section class="editorial-about landing-section" id="about" data-lazy-reveal>
                    <div class="editorial-about-container" id="editorial-about-container">
                        <div class="editorial-about-grid">
                            <div class="editorial-about-image">
                                <?php if (!empty($about_content['image_url'])): ?>
                                    <?php
                                    $imageUrl = resolveImageUrl($about_content['image_url'], !empty($about_content['updated_at']) ? strtotime($about_content['updated_at']) : null);
                                    ?>
                                    <img src="<?php echo htmlspecialchars($imageUrl); ?>" alt="<?php echo htmlspecialchars($site_name); ?> - Luxury Exterior" width="1200" height="1500" loading="lazy" decoding="async">
                                <?php endif; ?>
                            </div>
                            <div class="editorial-about-content">
                                <div>
                                    <span class="editorial-about-eyebrow"><?php echo htmlspecialchars($about_content['subtitle'] ?? 'Our Story'); ?></span>
                                    <h2 class="editorial-about-title"><?php echo htmlspecialchars($about_content['title'] ?? 'Experience Luxury Redefined'); ?></h2>
                                    <div class="editorial-about-divider"></div>
                                    <p class="editorial-about-description">
                                        <?php echo htmlspecialchars($about_content['content'] ?? htmlspecialchars($site_name) . ' offers an unparalleled luxury experience where timeless elegance meets modern comfort. Creating unforgettable memories for discerning travelers from around the world.'); ?>
                                    </p>
                                    <div class="editorial-about-features">
                                        <?php foreach (($about_features ?? []) as $feature): ?>
                                            <div class="editorial-about-feature">
                                                <?php if (!empty($feature['icon_class'])): ?>
                                                    <i class="<?php echo htmlspecialchars($feature['icon_class']); ?>"></i>
                                                <?php endif; ?>
                                                <span class="feature-title"><?php echo htmlspecialchars($feature['title']); ?></span>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                    <div class="editorial-about-stats">
                                        <?php foreach (($about_stats ?? []) as $stat): ?>
                                            <div class="editorial-about-stat">
                                                <span class="stat-number"><?php echo htmlspecialchars($stat['stat_number']); ?></span>
                                                <span class="stat-label"><?php echo htmlspecialchars($stat['stat_label']); ?></span>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                    <div class="editorial-about-cta">
                                        <a href="#rooms" class="btn btn-primary">Explore Our Rooms</a>
                                        <a href="contact-us.php" class="btn btn-outline btn-outline-on-light">Contact Us</a>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </section>


                <!-- Rooms — horizontal showcase rail (Bellhop-inspired editorial treatment) -->
                <section class="editorial-rooms-section bellhop-section landing-section" id="rooms" data-lazy-reveal>
                    <div id="editorial-rooms-section-content" class="bellhop-rooms">
                        <div class="bellhop-section__head">
                            <?php renderSectionHeader('home_rooms', 'index', [
                                'label' => 'Accommodations',
                                'title' => 'Rooms & Suites',
                                'description' => 'Each room is composed for rest — warm textures, generous light, and the slow rhythm of the Shire never far from the window.'
                            ], 'editorial-header section-header--editorial bellhop-header'); ?>
                            <div class="bellhop-rail__nav" data-bh-rail-nav hidden>
                                <button type="button" class="bellhop-rail__arrow" data-bh-rail-prev aria-label="Show previous rooms">
                                    <i class="fas fa-arrow-left" aria-hidden="true"></i>
                                </button>
                                <button type="button" class="bellhop-rail__arrow" data-bh-rail-next aria-label="Show next rooms">
                                    <i class="fas fa-arrow-right" aria-hidden="true"></i>
                                </button>
                            </div>
                        </div>

                        <div class="bellhop-rail" data-bh-rail>
                            <div class="bellhop-rail__track" data-bh-rail-track tabindex="0" role="group"
                                 aria-label="Featured rooms — scroll sideways to browse">
                                <?php
                                $roomIndex = 0;
                                foreach ($featured_rooms as $room):
                                    $roomUrl = "room.php?room=" . urlencode($room['slug']);
                                    $imageUrl = htmlspecialchars(resolveImageUrl($room['image_url']));
                                    $roomName = htmlspecialchars($room['name']);
                                    $roomPrice = (float)($room['price_per_night'] ?? 0);
                                    $roomSize = (int)($room['size_sqm'] ?? 0);
                                    $roomGuests = (int)($room['max_guests'] ?? 0);
                                    $roomAmenitiesRaw = trim((string)($room['amenities'] ?? ''));
                                    $roomAmenities = array_values(array_filter(array_map('trim', preg_split('/[,\n]+/', $roomAmenitiesRaw ?: ''))));
                                    $roomAmenities = array_slice($roomAmenities, 0, 3);
                                    $roomSummary = trim((string)($room['short_description'] ?? '')) ?: trim((string)($room['description'] ?? ''));
                                    $roomIndex++;
                                ?>
                                    <article class="bellhop-room-card" data-bh-anim="fade-up">
                                        <div class="bellhop-room-card__media">
                                            <?php if ($imageUrl !== ''): ?>
                                                <img src="<?php echo $imageUrl; ?>"
                                                     alt="<?php echo $roomName; ?> at <?php echo htmlspecialchars($site_name); ?>"
                                                     width="1200" height="900"
                                                     loading="lazy"
                                                     decoding="async">
                                            <?php else: ?>
                                                <!-- No photo on record: the frame stays, rather than rendering an
                                                     empty <img src=""> that browsers report as a broken image. -->
                                                <span class="bellhop-room-card__media-empty" aria-hidden="true"></span>
                                            <?php endif; ?>
                                            <span class="bellhop-room-card__index" aria-hidden="true"><?php echo str_pad((string)$roomIndex, 2, '0', STR_PAD_LEFT); ?></span>
                                        </div>

                                        <div class="bellhop-room-card__bar">
                                            <h3 class="bellhop-room-card__title"><?php echo $roomName; ?></h3>
                                            <p class="bellhop-room-card__price">
                                                <?php echo htmlspecialchars($currency_symbol) . ' ' . number_format($roomPrice, 0); ?>
                                                <span>/ night</span>
                                            </p>
                                        </div>

                                        <div class="bellhop-room-card__foot">
                                            <?php if (!empty($roomSummary)): ?>
                                                <p class="bellhop-room-card__summary"><?php echo htmlspecialchars($roomSummary); ?></p>
                                            <?php endif; ?>

                                            <ul class="bellhop-room-card__specs">
                                                <?php if ($roomSize > 0): ?>
                                                    <li><?php echo $roomSize; ?> sqm</li>
                                                <?php endif; ?>
                                                <?php if ($roomGuests > 0): ?>
                                                    <li><?php echo $roomGuests; ?> guests</li>
                                                <?php endif; ?>
                                                <?php foreach ($roomAmenities as $amenity): ?>
                                                    <li><?php echo htmlspecialchars($amenity); ?></li>
                                                <?php endforeach; ?>
                                            </ul>

                                            <span class="bellhop-room-card__cue" aria-hidden="true">
                                                View room <i class="fas fa-arrow-right"></i>
                                            </span>
                                        </div>

                                        <!-- One overlay link for the whole card: keeps the tap target large and
                                             avoids three competing links to the same room for screen readers. -->
                                        <a class="bellhop-room-card__link"
                                           href="<?php echo $roomUrl; ?>"
                                           data-anchor="#<?php echo htmlspecialchars($room['slug']); ?>">
                                            <span class="sr-only">View <?php echo $roomName; ?></span>
                                        </a>
                                    </article>
                                <?php endforeach; ?>

                                <?php if ($roomIndex > 0): ?>
                                    <div class="bellhop-rail__action">
                                        <a href="rooms-gallery.php" class="bellhop-circle">
                                            <span>View all rooms</span>
                                        </a>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="bellhop-rail__bar" aria-hidden="true"><span data-bh-rail-progress></span></div>
                    </div>
                </section>


                <!-- Passalacqua-Inspired Facilities Section: Editorial, Borderless, Large Icons -->
                <section class="editorial-facilities-section landing-section" id="facilities" data-lazy-reveal>
                    <div class="container">
                        <?php renderSectionHeader('home_facilities', 'index', [
                            'label' => 'Amenities',
                            'title' => 'Space To Unwind',
                            'description' => 'Pool, table and terrace — every corner of the hotel is turned toward the light and the water, and made to be lingered in.'
                        ], 'editorial-header section-header--editorial'); ?>
                        <div class="editorial-facilities-grid landing-grid landing-grid--three" id="editorial-facilities-grid">
                            <?php foreach ($facilities as $facility): ?>
                                <div class="editorial-facility-card">
                                    <div class="editorial-facility-icon">
                                        <i class="<?php echo htmlspecialchars($facility['icon_class']); ?>"></i>
                                    </div>
                                    <div class="editorial-facility-content">
                                        <h3 class="editorial-facility-name"><?php echo htmlspecialchars($facility['name']); ?></h3>
                                        <div class="editorial-facility-divider"></div>
                                        <p class="editorial-facility-description"><?php echo htmlspecialchars($facility['short_description']); ?></p>
                                        <?php if (!empty($facility['page_url'])): ?>
                                            <a href="<?php echo htmlspecialchars($facility['page_url']); ?>" class="editorial-facility-link"><i class="fas fa-arrow-right"></i></a>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </section>

                <!-- Hotel Gallery Carousel Section -->
                <?php include 'includes/hotel-gallery.php'; ?>

                <!-- Upcoming Events Section (must follow editorial gallery) -->
                <?php
                $upcoming_events_page = 'index';
                include 'includes/upcoming-events.php';
                ?>

                <!-- Reviews Section — belongs to the Website & CMS module -->
                <?php if (!function_exists('moduleEnabled') || moduleEnabled('website_cms')) { include 'includes/reviews-section.php'; } ?>


                <!-- Passalacqua-Inspired Testimonials Section: Editorial, Borderless, Large Serif Quotes -->
                <?php
                // Reads the `testimonials` table via getCachedTestimonials(). NOTE: no admin
                // page writes to that table — nothing in the codebase INSERTs, UPDATEs or
                // DELETEs from it — so whatever it holds cannot be edited from the back office.
                // See .claude/BUILD_PLAN.md (2026-09-02) for the open owner decision on
                // consolidating this with the admin-managed `reviews` table.
                //
                // Owner rule (2026-09-02): render nothing rather than an empty shell.
                if (!empty($testimonials)):
                ?>
                <section class="editorial-testimonials-section landing-section" id="testimonials" data-lazy-reveal>
                    <div class="container">
                        <div class="editorial-header-wrapper">
                            <?php renderSectionHeader('home_testimonials', 'index', [
                                'label' => 'Guest Book',
                                'title' => 'In Their Words',
                                'description' => 'A few notes from the guests who have stayed, dined and slowed down with us beside the river.'
                            ], 'editorial-header section-header--editorial'); ?>
                        </div>
                        <div class="editorial-testimonials-grid landing-grid landing-grid--three" id="editorial-testimonials-grid">
                            <?php foreach ($testimonials as $testimonial): ?>
                                <div class="editorial-testimonial-card">
                                    <div class="editorial-testimonial-quote">“</div>
                                    <p class="editorial-testimonial-text"><?php echo htmlspecialchars($testimonial['testimonial_text']); ?></p>
                                    <div class="editorial-testimonial-divider"></div>
                                    <div class="editorial-testimonial-author">
                                        <span class="editorial-testimonial-author-name"><?php echo htmlspecialchars($testimonial['guest_name']); ?></span>
                                        <?php if (!empty($testimonial['guest_location'])): ?>
                                            <span class="editorial-testimonial-author-location"><?php echo htmlspecialchars($testimonial['guest_location']); ?></span>
                                        <?php endif; ?>
                                        <span class="editorial-testimonial-rating">
                                            <?php for ($i = 0; $i < $testimonial['rating']; $i++): ?>
                                                <i class="fas fa-star"></i>
                                            <?php endfor; ?>
                                        </span>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </section>
                <?php endif; ?>

            </div>
        </div>

        <!-- Font Loading Detection -->
        <script>
            (function() {
                // Initialize animations for Section07 style
                const animatedElements = document.querySelectorAll('[data-animation]');
                if (animatedElements.length > 0 && 'IntersectionObserver' in window) {
                    const observer = new IntersectionObserver((entries) => {
                        entries.forEach(entry => {
                            if (entry.isIntersecting) {
                                const el = entry.target;
                                const delay = el.getAttribute('data-animation-delay') || '0s';
                                el.style.transitionDelay = delay;
                                el.classList.add('a1');
                                observer.unobserve(el);
                            }
                        });
                    }, {
                        threshold: 0.15,
                        rootMargin: '0px 0px -50px 0px'
                    });

                    animatedElements.forEach(el => observer.observe(el));
                }

                // Detect when custom fonts are loaded
                if ('fonts' in document) {
                    document.fonts.ready.then(function() {
                        document.body.classList.add('fonts-loaded');
                    });
                } else {
                    // Fallback for browsers without Font Loading API
                    window.addEventListener('load', function() {
                        document.body.classList.add('fonts-loaded');
                    });
                }
            })();
        </script>

    </main>
    <?php include 'includes/footer.php'; ?>
</body>

</html>
