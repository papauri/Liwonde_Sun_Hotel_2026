<?php
require_once 'config/database.php';
require_once 'includes/reviews-display.php';
require_once 'includes/video-display.php';
require_once 'includes/image-proxy-helper.php';

$room_slug = isset($_GET['room']) ? trim($_GET['room']) : null;
if (!$room_slug) {
    header('Location: /');
    exit;
}

$site_name = getSetting('site_name');
$site_tagline = getSetting('site_tagline');
$site_logo = getSetting('site_logo');
$currency_symbol = getSetting('currency_symbol');
$email_reservations = getSetting('email_reservations');
$phone_main = getSetting('phone_main');

function resolveImageUrl($path) {
    if (!$path) return '';
    $trimmed = trim($path);
    if (stripos($trimmed, 'http://') === 0 || stripos($trimmed, 'https://') === 0) {
        return $trimmed;
    }
    // No prefix needed since file is now in root directory
    return $trimmed;
}

$policies = [];
try {
    $policyStmt = $pdo->query("SELECT slug, title, summary, content FROM policies WHERE is_active = 1 ORDER BY display_order ASC, id ASC");
    $policies = $policyStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $policies = [];
}

$contact_settings = getSettingsByGroup('contact');
$contact = [];
foreach ($contact_settings as $setting) {
    $contact[$setting['setting_key']] = $setting['setting_value'];
}

$social_settings = getSettingsByGroup('social');
$social = [];
foreach ($social_settings as $setting) {
    $social[$setting['setting_key']] = $setting['setting_value'];
}

$footer_links_raw = [];
try {
    $footerStmt = $pdo->query("SELECT column_name, link_text, link_url FROM footer_links WHERE is_active = 1 ORDER BY column_name, display_order");
    $footer_links_raw = $footerStmt->fetchAll();
} catch (PDOException $e) {
    $footer_links_raw = [];
}

$footer_links = [];
foreach ($footer_links_raw as $link) {
    $footer_links[$link['column_name']][] = $link;
}

$room = null;
$room_images = [];
try {
    $stmt = $pdo->prepare("SELECT * FROM rooms WHERE slug = ? AND is_active = 1");
    $stmt->execute([$room_slug]);
    $room = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$room) {
        header('Location: /');
        exit;
    }

    $galleryStmt = $pdo->prepare("SELECT id, title, description, image_url FROM gallery WHERE room_id = ? AND is_active = 1 AND image_url IS NOT NULL AND image_url != '' ORDER BY display_order ASC, id ASC");
    $galleryStmt->execute([$room['id']]);
    $room_images = $galleryStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('Error fetching room details: ' . $e->getMessage());
}

$room_images = array_values(array_filter($room_images, function($img) {
    return !empty($img['image_url']) && trim($img['image_url']) !== '';
}));

if (empty($room_images) && !empty($room['image_url'])) {
    $room_images[] = [
        'id' => 0,
        'title' => $room['name'],
        'description' => '',
        'image_url' => $room['image_url']
    ];
}

$hero_image = proxyImageUrl(resolveImageUrl($room_images[0]['image_url'] ?? $room['image_url']));
$amenities = array_filter(array_map('trim', explode(',', $room['amenities'] ?? '')));

// Build SEO data for room page
$seo_data = [
    'title' => $room['name'],
    'description' => $room['short_description'] ?? $site_tagline,
    'image' => $hero_image,
    'type' => 'hotel',
    'tags' => 'luxury room, ' . $room['name'] . ', ' . implode(', ', array_slice($amenities, 0, 5)),
    'breadcrumbs' => [
        ['name' => 'Home', 'url' => $base_url . '/'],
        ['name' => 'Rooms', 'url' => $base_url . '/rooms-gallery.php'],
        ['name' => $room['name'], 'url' => $base_url . '/room.php?room=' . urlencode($room['slug'])]
    ],
    'structured_data' => [
        '@context' => 'https://schema.org',
        '@type' => 'HotelRoom',
        'name' => $room['name'],
        'description' => $room['short_description'] ?? $site_tagline,
        'image' => $base_url . $hero_image,
        'numberOfBeds' => 1,
        'bed' => [
            '@type' => 'BedType',
            'name' => $room['bed_type']
        ],
        'amenityFeature' => array_map(function($amenity) {
            return [
                '@type' => 'LocationFeatureSpecification',
                'name' => $amenity,
                'value' => true
            ];
        }, array_slice($amenities, 0, 10)),
        'occupancy' => [
            '@type' => 'QuantitativeValue',
            'maxValue' => $room['max_guests'] ?? 2
        ],
        'floorSize' => [
            '@type' => 'QuantitativeValue',
            'value' => $room['size_sqm'] ?? 40,
            'unitCode' => 'MTK'
        ],
        'offers' => [
            '@type' => 'Offer',
            'price' => $room['price_per_night'],
            'priceCurrency' => 'MWK',
            'availability' => $room['rooms_available'] > 0 ? 'https://schema.org/InStock' : 'https://schema.org/SoldOut',
            'url' => $base_url . '/booking.php?room_id=' . $room['id']
        ],
        'containedInPlace' => [
            '@type' => 'Hotel',
            'name' => $site_name,
            'address' => [
                '@type' => 'PostalAddress',
                'addressLocality' => $contact['address_line2'] ?? '',
                'addressCountry' => $contact['address_country'] ?? ''
            ]
        ]
    ]
];

// Fetch room reviews for structured data
try {
    $reviews_stmt = $pdo->prepare("
        SELECT rating, comment, guest_name, created_at 
        FROM reviews 
        WHERE room_id = ? AND status = 'approved' 
        LIMIT 5
    ");
    $reviews_stmt->execute([$room['id']]);
    $room_reviews = $reviews_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (!empty($room_reviews)) {
        $seo_data['structured_data']['aggregateRating'] = [
            '@type' => 'AggregateRating',
            'ratingValue' => array_sum(array_column($room_reviews, 'rating')) / count($room_reviews),
            'reviewCount' => count($room_reviews),
            'bestRating' => 5,
            'worstRating' => 1
        ];
    }
} catch (PDOException $e) {
    // Ignore review errors
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0, user-scalable=yes, viewport-fit=cover">
    
    <!-- SEO Meta Tags -->
    <?php require_once 'includes/seo-meta.php'; ?>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&family=Playfair+Display:wght@600;700&display=swap" rel="stylesheet" media="print" onload="this.media='all'">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" media="print" onload="this.media='all'">
    <link rel="stylesheet" href="css/theme-dynamic.php">
    <link rel="stylesheet" href="css/header.css">
    <link rel="stylesheet" href="css/style.css">
    <link rel="stylesheet" href="css/footer.css">
</head>
<body class="rooms-page">
    <?php include 'includes/loader.php'; ?>
    
    <?php include 'includes/header.php'; ?>
    
    <!-- Mobile Menu Overlay -->
    <div class="mobile-menu-overlay" role="presentation"></div>

    <section class="rooms-hero">
        <?php if (!empty($room['video_path'])): ?>
            <!-- Display video if available -->
            <div class="rooms-hero__video">
                <?php echo renderVideoEmbed($room['video_path'], $room['video_type'], [
                    'autoplay' => true,
                    'muted' => true,
                    'controls' => false,
                    'loop' => true,
                    'class' => 'rooms-hero-video-embed',
                    'style' => 'position: absolute; top: 0; left: 0; width: 100%; height: 100%; object-fit: cover;'
                ]); ?>
            </div>
        <?php else: ?>
            <!-- Display image background if no video -->
            <div class="rooms-hero__image" style="background-image: linear-gradient(135deg, rgba(5, 9, 15, 0.76), rgba(10, 25, 41, 0.75)), url('<?php echo htmlspecialchars($hero_image); ?>');"></div>
        <?php endif; ?>
        
        <div class="rooms-hero__overlay"></div>
        <div class="container">
            <div class="rooms-hero__grid">
                <div class="rooms-hero__content">
                    <?php if (!empty($room['badge'])): ?>
                    <div class="pill"><?php echo htmlspecialchars($room['badge']); ?></div>
                    <?php endif; ?>
                    <h1><?php echo htmlspecialchars($room['name']); ?></h1>
                    <p><?php echo htmlspecialchars($room['short_description'] ?? $site_tagline); ?></p>
                </div>
            </div>
        </div>
    </section>

    <section class="section room-detail-below" style="padding-top: 30px; padding-bottom: 40px;">
        <div class="container">

            <?php 
            // Check if room has a video
            $room_has_video = !empty($room['video_path']);
            $has_gallery_content = !empty($room_images) || $room_has_video;
            
            if ($has_gallery_content): 
            ?>
            <h3 style="font-size: 24px; color: var(--navy); margin: 40px 0 24px 0; font-weight: 700;">Room Gallery</h3>
            <div class="room-gallery-grid">
                <?php 
                // Display room video first if available
                if ($room_has_video): 
                ?>
                <div class="gallery-item gallery-item-video">
                    <?php echo renderVideoEmbed($room['video_path'], $room['video_type'], [
                        'autoplay' => false,
                        'muted' => false,
                        'controls' => true,
                        'loop' => false,
                        'class' => 'room-gallery-video',
                        'style' => 'width: 100%; height: 100%; object-fit: cover; border-radius: 8px;'
                    ]); ?>
                    <div class="gallery-item-label">Room Video Tour</div>
                </div>
                <?php endif; ?>
                
                <?php foreach ($room_images as $img): ?>
                <div class="gallery-item">
                    <img src="<?php echo htmlspecialchars(proxyImageUrl(resolveImageUrl($img['image_url']))); ?>" alt="<?php echo htmlspecialchars($img['title']); ?>">
                    <div class="gallery-item-label"><?php echo htmlspecialchars($img['title']); ?></div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <!-- Room Details Section - Moved below gallery -->
            <aside class="room-detail-info room-detail-info--horizontal" aria-label="Room details">
                <div class="room-detail-header">
                    <h2 class="room-detail-title">About the Room</h2>
                    <a class="btn btn-primary btn-booking" href="booking.php?room_id=<?php echo $room['id']; ?>">
                        <i class="fas fa-calendar-check"></i> Book Now
                    </a>
                </div>
                <p class="room-detail-description"><?php echo htmlspecialchars($room['description'] ?? $room['short_description']); ?></p>
                <div class="room-detail-specs">
                    <div class="spec-item"><i class="fas fa-users"></i><div class="spec-label">Guests</div><div class="spec-value">Up to <?php echo htmlspecialchars($room['max_guests'] ?? 2); ?></div></div>
                    <div class="spec-item"><i class="fas fa-ruler-combined"></i><div class="spec-label">Floor Space</div><div class="spec-value"><?php echo htmlspecialchars($room['size_sqm']); ?> sqm</div></div>
                    <div class="spec-item"><i class="fas fa-bed"></i><div class="spec-label">Bed Type</div><div class="spec-value"><?php echo htmlspecialchars($room['bed_type']); ?></div></div>
                    <div class="spec-item"><i class="fas fa-tag"></i><div class="spec-label">Nightly Rate</div><div class="spec-value"><?php echo htmlspecialchars($currency_symbol); ?><?php echo number_format($room['price_per_night'], 0); ?></div></div>
                    <?php
                    $available = $room['rooms_available'] ?? 0;
                    $total = $room['total_rooms'] ?? 0;
                    if ($total > 0):
                        $availability_class = $available == 0 ? 'unavailable' : ($available <= 2 ? 'low' : 'good');
                    ?>
                    <div class="spec-item availability-<?php echo $availability_class; ?>">
                        <i class="fas fa-door-open"></i>
                        <div class="spec-label">Availability</div>
                        <div class="spec-value"><?php echo $available; ?>/<?php echo $total; ?> rooms</div>
                    </div>
                    <?php endif; ?>
                </div>
                <?php if (!empty($amenities)): ?>
                <div class="amenities-list">
                    <h3 class="amenities-list__title">Room Amenities</h3>
                    <div class="amenities-list__chips">
                        <?php foreach ($amenities as $amenity): ?>
                        <span class="amenities-chip"><i class="fas fa-check"></i><?php echo htmlspecialchars($amenity); ?></span>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>
            </aside>
        </div>
    </section>

    <section class="booking-cta" id="book">
        <div class="container booking-cta__grid">
            <div class="booking-cta__content">
                <div class="pill">Direct Booking</div>
                <h2>Ready to reserve your stay?</h2>
                <p>Pick your preferred suite and we will secure it instantly. Share your dates and guest count and our team will confirm right away.</p>
                <div class="booking-cta__actions">
                    <a class="btn btn-primary" href="tel:<?php echo htmlspecialchars(preg_replace('/[^0-9+]/', '', $phone_main)); ?>"><i class="fas fa-phone"></i> Call Reservations</a>
                    <a class="btn btn-outline" href="mailto:<?php echo htmlspecialchars($email_reservations); ?>?subject=Room%20Reservation"><i class="fas fa-envelope"></i> Email Booking</a>
                </div>
            </div>
            <div class="booking-cta__card">
                <div class="booking-cta__row"><span>Selected Room</span><strong><?php echo htmlspecialchars($room['name']); ?></strong></div>
                <div class="booking-cta__row"><span>Nightly Rate</span><strong><?php echo htmlspecialchars($currency_symbol); ?><?php echo number_format($room['price_per_night'], 0); ?></strong></div>
                <div class="booking-cta__row"><span>Capacity</span><strong><?php echo htmlspecialchars($room['max_guests'] ?? 2); ?> guests</strong></div>
                <div class="booking-cta__row"><span>Floor Space</span><strong><?php echo htmlspecialchars($room['size_sqm'] ?? 40); ?> sqm</strong></div>
                <a class="btn btn-primary" href="booking.php?room_id=<?php echo $room['id']; ?>">Proceed to Booking</a>
            </div>
        </div>
    </section>

    <!-- Reviews Section -->
    <section class="reviews-section" id="reviews" data-room-id="<?php echo $room['id']; ?>">
        <div class="container">
            <div class="reviews-section__header">
                <h2 class="reviews-section__title">Guest Reviews</h2>
                <a class="btn-write-review" href="submit-review.php?room_id=<?php echo $room['id']; ?>">
                    <i class="fas fa-pen-fancy"></i>
                    <span>Write a Review</span>
                    <i class="fas fa-arrow-right btn-arrow"></i>
                </a>
            </div>

            <!-- Rating Summary -->
            <div class="rating-summary" id="ratingSummary">
                <div class="rating-summary__loading">
                    <i class="fas fa-spinner fa-spin"></i> Loading reviews...
                </div>
            </div>

            <!-- Filter Options -->
            <div class="reviews-filter" id="reviewsFilter" style="display: none;">
                <div class="reviews-filter__label">Sort by:</div>
                <div class="reviews-filter__options">
                    <button class="reviews-filter__btn reviews-filter__btn--active" data-sort="newest">
                        <i class="fas fa-clock"></i> Newest First
                    </button>
                    <button class="reviews-filter__btn" data-sort="highest">
                        <i class="fas fa-star"></i> Highest Rated
                    </button>
                    <button class="reviews-filter__btn" data-sort="lowest">
                        <i class="fas fa-star-half-alt"></i> Lowest Rated
                    </button>
                </div>
            </div>

            <!-- Reviews List -->
            <div class="reviews-list" id="reviewsList">
                <div class="reviews-list__loading">
                    <i class="fas fa-spinner fa-spin"></i> Loading reviews...
                </div>
            </div>

            <!-- Pagination -->
            <div class="reviews-pagination" id="reviewsPagination" style="display: none;">
                <button class="reviews-pagination__btn reviews-pagination__btn--prev" disabled>
                    <i class="fas fa-chevron-left"></i> Previous
                </button>
                <div class="reviews-pagination__info">
                    Page <span id="currentPage">1</span> of <span id="totalPages">1</span>
                </div>
                <button class="reviews-pagination__btn reviews-pagination__btn--next" disabled>
                    Next <i class="fas fa-chevron-right"></i>
                </button>
            </div>

            <!-- Empty State -->
            <div class="reviews-empty" id="reviewsEmpty" style="display: none;">
                <i class="fas fa-comment-slash"></i>
                <h3>No Reviews Yet</h3>
                <p>Be the first to share your experience!</p>
            </div>
        </div>
    </section>

    <!-- Footer -->
    <?php include 'includes/footer.php'; ?>

    <script src="js/modal.js"></script>
    <script src="js/main.js"></script>
    <script>
    // Reviews functionality
    (function() {
        const reviewsSection = document.getElementById('reviews');
        if (!reviewsSection) return;

        const roomId = reviewsSection.dataset.roomId;
        const ratingSummary = document.getElementById('ratingSummary');
        const reviewsList = document.getElementById('reviewsList');
        const reviewsFilter = document.getElementById('reviewsFilter');
        const reviewsPagination = document.getElementById('reviewsPagination');
        const reviewsEmpty = document.getElementById('reviewsEmpty');
        const currentPageSpan = document.getElementById('currentPage');
        const totalPagesSpan = document.getElementById('totalPages');
        const prevBtn = document.querySelector('.reviews-pagination__btn--prev');
        const nextBtn = document.querySelector('.reviews-pagination__btn--next');
        const filterBtns = document.querySelectorAll('.reviews-filter__btn');

        let currentPage = 1;
        let totalPages = 1;
        let currentSort = 'newest';
        let reviewsPerPage = 5;
        let allReviews = [];

        // Fetch reviews from API
        async function fetchReviews() {
            try {
                const response = await fetch(`admin/api/reviews.php?room_id=${roomId}&status=approved`);
                const data = await response.json();
                
                if (data.success) {
                    allReviews = data.reviews || [];
                    displayRatingSummary(data.averages || {}, data.total_count || 0);
                    sortAndDisplayReviews();
                } else {
                    showError('Failed to load reviews');
                }
            } catch (error) {
                console.error('Error fetching reviews:', error);
                showError('Failed to load reviews');
            }
        }

        // Display rating summary
        function displayRatingSummary(averages, totalCount) {
            if (totalCount === 0) {
                ratingSummary.innerHTML = `
                    <div class="rating-summary__empty">
                        <i class="fas fa-star"></i>
                        <span>No reviews yet</span>
                    </div>
                `;
                reviewsEmpty.style.display = 'block';
                reviewsList.style.display = 'none';
                reviewsFilter.style.display = 'none';
                return;
            }

            const avgRating = averages.avg_rating || 0;
            const starsHtml = generateStars(avgRating);

            ratingSummary.innerHTML = `
                <div class="rating-summary__main">
                    <div class="rating-summary__score">
                        <span class="rating-summary__number">${avgRating.toFixed(1)}</span>
                        <div class="rating-summary__stars">${starsHtml}</div>
                    </div>
                    <div class="rating-summary__count">
                        <strong>${totalCount}</strong> review${totalCount !== 1 ? 's' : ''}
                    </div>
                </div>
                <div class="rating-summary__categories">
                    ${averages.avg_service ? `<div class="rating-summary__category"><span>Service</span><div class="rating-summary__bar"><div class="rating-summary__bar-fill" style="width: ${(averages.avg_service / 5) * 100}%"></div></div><span>${averages.avg_service.toFixed(1)}</span></div>` : ''}
                    ${averages.avg_cleanliness ? `<div class="rating-summary__category"><span>Cleanliness</span><div class="rating-summary__bar"><div class="rating-summary__bar-fill" style="width: ${(averages.avg_cleanliness / 5) * 100}%"></div></div><span>${averages.avg_cleanliness.toFixed(1)}</span></div>` : ''}
                    ${averages.avg_location ? `<div class="rating-summary__category"><span>Location</span><div class="rating-summary__bar"><div class="rating-summary__bar-fill" style="width: ${(averages.avg_location / 5) * 100}%"></div></div><span>${averages.avg_location.toFixed(1)}</span></div>` : ''}
                    ${averages.avg_value ? `<div class="rating-summary__category"><span>Value</span><div class="rating-summary__bar"><div class="rating-summary__bar-fill" style="width: ${(averages.avg_value / 5) * 100}%"></div></div><span>${averages.avg_value.toFixed(1)}</span></div>` : ''}
                </div>
            `;

            reviewsEmpty.style.display = 'none';
            reviewsList.style.display = 'block';
            reviewsFilter.style.display = 'flex';
        }

        // Generate star HTML
        function generateStars(rating) {
            let html = '';
            const fullStars = Math.floor(rating);
            const hasHalfStar = (rating - fullStars) >= 0.5;
            const emptyStars = 5 - fullStars - (hasHalfStar ? 1 : 0);

            for (let i = 0; i < fullStars; i++) {
                html += '<i class="fas fa-star"></i>';
            }
            if (hasHalfStar) {
                html += '<i class="fas fa-star-half-alt"></i>';
            }
            for (let i = 0; i < emptyStars; i++) {
                html += '<i class="far fa-star"></i>';
            }
            return html;
        }

        // Sort and display reviews
        function sortAndDisplayReviews() {
            let sortedReviews = [...allReviews];

            switch (currentSort) {
                case 'newest':
                    sortedReviews.sort((a, b) => new Date(b.created_at) - new Date(a.created_at));
                    break;
                case 'highest':
                    sortedReviews.sort((a, b) => (b.rating || 0) - (a.rating || 0));
                    break;
                case 'lowest':
                    sortedReviews.sort((a, b) => (a.rating || 0) - (b.rating || 0));
                    break;
            }

            totalPages = Math.ceil(sortedReviews.length / reviewsPerPage);
            if (currentPage > totalPages) currentPage = 1;

            displayReviews(sortedReviews);
            updatePagination();
        }

        // Display reviews
        function displayReviews(reviews) {
            const startIndex = (currentPage - 1) * reviewsPerPage;
            const endIndex = startIndex + reviewsPerPage;
            const pageReviews = reviews.slice(startIndex, endIndex);

            if (pageReviews.length === 0) {
                reviewsList.innerHTML = `
                    <div class="reviews-list__empty">
                        <i class="fas fa-comment-slash"></i>
                        <p>No reviews found</p>
                    </div>
                `;
                return;
            }

            reviewsList.innerHTML = pageReviews.map(review => {
                const starsHtml = generateStars(review.rating || 0);
                const date = new Date(review.created_at).toLocaleDateString('en-US', {
                    year: 'numeric',
                    month: 'long',
                    day: 'numeric'
                });

                let categoriesHtml = '';
                if (review.service_rating || review.cleanliness_rating || review.location_rating || review.value_rating) {
                    categoriesHtml = `
                        <div class="review-card__categories">
                            ${review.service_rating ? `<span><i class="fas fa-concierge-bell"></i> Service: ${review.service_rating}</span>` : ''}
                            ${review.cleanliness_rating ? `<span><i class="fas fa-broom"></i> Cleanliness: ${review.cleanliness_rating}</span>` : ''}
                            ${review.location_rating ? `<span><i class="fas fa-map-marker-alt"></i> Location: ${review.location_rating}</span>` : ''}
                            ${review.value_rating ? `<span><i class="fas fa-tag"></i> Value: ${review.value_rating}</span>` : ''}
                        </div>
                    `;
                }

                let adminResponseHtml = '';
                if (review.latest_response) {
                    const responseDate = review.latest_response_date ? new Date(review.latest_response_date).toLocaleDateString('en-US', {
                        year: 'numeric',
                        month: 'long',
                        day: 'numeric'
                    }) : '';
                    adminResponseHtml = `
                        <div class="review-card__admin-response">
                            <div class="review-card__admin-header">
                                <i class="fas fa-reply"></i>
                                <span>Response from <?php echo htmlspecialchars($site_name); ?></span>
                                ${responseDate ? `<span class="review-card__admin-date">${responseDate}</span>` : ''}
                            </div>
                            <p>${escapeHtml(review.latest_response)}</p>
                        </div>
                    `;
                }

                return `
                    <div class="review-card">
                        <div class="review-card__header">
                            <div class="review-card__author">
                                <div class="review-card__avatar">
                                    <i class="fas fa-user"></i>
                                </div>
                                <div class="review-card__author-info">
                                    <div class="review-card__name">${escapeHtml(review.guest_name || 'Anonymous')}</div>
                                    <div class="review-card__date">${date}</div>
                                </div>
                            </div>
                            <div class="review-card__rating">
                                ${starsHtml}
                            </div>
                        </div>
                        ${review.title ? `<h4 class="review-card__title">${escapeHtml(review.title)}</h4>` : ''}
                        <p class="review-card__comment">${escapeHtml(review.comment)}</p>
                        ${categoriesHtml}
                        ${adminResponseHtml}
                    </div>
                `;
            }).join('');
        }

        // Update pagination
        function updatePagination() {
            currentPageSpan.textContent = currentPage;
            totalPagesSpan.textContent = totalPages;
            prevBtn.disabled = currentPage === 1;
            nextBtn.disabled = currentPage === totalPages;

            if (totalPages > 1) {
                reviewsPagination.style.display = 'flex';
            } else {
                reviewsPagination.style.display = 'none';
            }
        }

        // Escape HTML
        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        // Show error
        function showError(message) {
            ratingSummary.innerHTML = `
                <div class="rating-summary__error">
                    <i class="fas fa-exclamation-circle"></i>
                    <span>${message}</span>
                </div>
            `;
            reviewsList.innerHTML = `
                <div class="reviews-list__error">
                    <i class="fas fa-exclamation-circle"></i>
                    <p>${message}</p>
                </div>
            `;
        }

        // Event listeners
        filterBtns.forEach(btn => {
            btn.addEventListener('click', () => {
                filterBtns.forEach(b => b.classList.remove('reviews-filter__btn--active'));
                btn.classList.add('reviews-filter__btn--active');
                currentSort = btn.dataset.sort;
                currentPage = 1;
                sortAndDisplayReviews();
            });
        });

        prevBtn.addEventListener('click', () => {
            if (currentPage > 1) {
                currentPage--;
                sortAndDisplayReviews();
                reviewsSection.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
        });

        nextBtn.addEventListener('click', () => {
            if (currentPage < totalPages) {
                currentPage++;
                sortAndDisplayReviews();
                reviewsSection.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
        });

        // Initialize
        fetchReviews();
    })();
    </script>

    <?php include 'includes/scroll-to-top.php'; ?>
</body>
</html>
