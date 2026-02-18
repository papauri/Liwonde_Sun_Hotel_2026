<?php
// Hotel Website - Rooms Gallery (Modern Cards)
require_once 'config/database.php';
require_once 'includes/page-guard.php';
require_once 'includes/reviews-display.php';

$site_name = getSetting('site_name');
$site_logo = getSetting('site_logo');
$site_tagline = getSetting('site_tagline');
$currency_symbol = getSetting('currency_symbol');
$email_reservations = getSetting('email_reservations');
$phone_main = getSetting('phone_main');


// Fetch all active rooms
$rooms = [];
try {
    $roomStmt = $pdo->query("SELECT * FROM rooms WHERE is_active = 1 ORDER BY is_featured DESC, display_order ASC, id ASC");
    $rooms = $roomStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $rooms = [];
}

// Policies for modals
$policies = [];
try {
    $policyStmt = $pdo->query("SELECT slug, title, summary, content FROM policies WHERE is_active = 1 ORDER BY display_order ASC, id ASC");
    $policies = $policyStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $policies = [];
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0, user-scalable=yes, viewport-fit=cover">
    <meta name="theme-color" content="#060a17">
    <title><?php echo htmlspecialchars($site_name); ?> | Rooms Gallery</title>
    <meta name="description" content="Explore all rooms and suites at <?php echo htmlspecialchars($site_name); ?>. Browse, compare, and open any room for full details.">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&family=Playfair+Display:wght@600;700&display=swap" rel="stylesheet" media="print" onload="this.media='all'">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" media="print" onload="this.media='all'">
    <link rel="stylesheet" href="css/theme-dynamic.php">
    <link rel="stylesheet" href="css/header.css">
    <link rel="stylesheet" href="css/style.css">
    <link rel="stylesheet" href="css/footer.css">
</head>

<body class="rooms-gallery-page">
    <?php include 'includes/loader.php'; ?>
    <?php include 'includes/header.php'; ?>

    <!-- Hero Section -->
    <?php include 'includes/hero.php'; ?>

    <main>
        <section class="section" id="collection">
            <div class="container">
                <?php if (!empty($rooms)): ?>
                    <div class="rooms-grid modern-grid fancy-3d-grid" data-room-count="<?php echo (int)count($rooms); ?>">
                        <?php foreach ($rooms as $room):
                            $amenities_raw = $room['amenities'] ?? '';
                            $amenities = array_filter(array_map('trim', explode(',', $amenities_raw)));
                            $amenities = array_slice($amenities, 0, 4);

                            $max_guests = $room['max_guests'] ?? 2;
                            $size_sqm = $room['size_sqm'];
                            $bed_type = $room['bed_type'];
                        ?>
                            <article class="room-tile fancy-3d-card" tabindex="0" data-room-id="<?php echo (int)$room['id']; ?>" data-room-slug="<?php echo htmlspecialchars($room['slug']); ?>">
                                <div class="room-tile__3d-bg"></div>

                                <a class="room-tile__image" href="room.php?room=<?php echo urlencode($room['slug']); ?>" aria-label="Open details for <?php echo htmlspecialchars($room['name']); ?>">
                                    <img src="<?php echo htmlspecialchars($room['image_url']); ?>" alt="<?php echo htmlspecialchars($room['name']); ?>" loading="lazy">
                                    <?php if (!empty($room['badge'])): ?>
                                        <span class="room-tile__badge"><?php echo htmlspecialchars($room['badge']); ?></span>
                                    <?php endif; ?>

                                    <span class="room-tile__price-badge" aria-label="Price per night">
                                        <span class="amount"><?php echo htmlspecialchars($currency_symbol); ?><?php echo number_format((float)($room['price_per_night'] ?? 0), 0); ?></span>
                                        <small>per night</small>
                                    </span>
                                </a>

                                <div class="room-tile__body">
                                    <div class="room-tile__header">
                                        <div>
                                            <h3><?php echo htmlspecialchars($room['name']); ?></h3>
                                            <p><?php echo htmlspecialchars($room['short_description']); ?></p>
                                        </div>
                                    </div>

                                    <!-- Compact Rating Display -->
                                    <div class="room-tile__rating" data-room-id="<?php echo (int)$room['id']; ?>">
                                        <div class="compact-rating compact-rating--loading">
                                            <i class="fas fa-spinner fa-spin"></i>
                                        </div>
                                    </div>

                                    <div class="room-tile__meta">
                                        <span><i class="fas fa-user-friends"></i> <?php echo htmlspecialchars($max_guests); ?> guests</span>
                                        <span><i class="fas fa-ruler-combined"></i> <?php echo htmlspecialchars($size_sqm); ?> sqm</span>
                                        <span><i class="fas fa-bed"></i> <?php echo htmlspecialchars($bed_type); ?></span>
                                    </div>

                                    <?php if (!empty($amenities)): ?>
                                        <div class="room-tile__amenities">
                                            <?php foreach ($amenities as $amenity): ?>
                                                <span class="pill-small"><?php echo htmlspecialchars($amenity); ?></span>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>

                                    <div class="room-tile__actions">
                                        <a class="btn btn-primary" href="room.php?room=<?php echo urlencode($room['slug']); ?>#book">View & Book</a>
                                    </div>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="gallery-empty" style="margin-top: 50px;">
                        <h2>Rooms are preparing for launch</h2>
                        <p>Our suites are being curated. Please check back soon or reach out to our reservations team for availability.</p>
                        <div style="margin-top: 18px;">
                            <a class="btn btn-primary" href="mailto:<?php echo htmlspecialchars($email_reservations); ?>?subject=Room%20Availability">Email Reservations</a>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </section>
    </main>

    <?php include 'includes/footer.php'; ?>

    <script src="js/modal.js"></script>
    <script src="js/main.js"></script>
    <script>
        // Optional 3D card tilt effect (desktop only + respects reduced motion)
        (function() {
            const prefersReducedMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
            const canHover = window.matchMedia && window.matchMedia('(hover: hover)').matches;
            const finePointer = window.matchMedia && window.matchMedia('(pointer: fine)').matches;

            if (prefersReducedMotion || !canHover || !finePointer) return;

            document.querySelectorAll('.fancy-3d-card').forEach(card => {
                card.addEventListener('mousemove', function(e) {
                    const rect = card.getBoundingClientRect();
                    const x = e.clientX - rect.left;
                    const y = e.clientY - rect.top;
                    const centerX = rect.width / 2;
                    const centerY = rect.height / 2;
                    const rotateX = ((y - centerY) / centerY) * 10;
                    const rotateY = ((x - centerX) / centerX) * 10;
                    card.style.transform = `perspective(900px) rotateX(${-rotateX}deg) rotateY(${rotateY}deg) scale(1.04)`;
                });

                card.addEventListener('mouseleave', function() {
                    card.style.transform = '';
                });
            });
        })();

        // Fetch and display ALL room ratings in a single request (optimized)
        (function() {
            const ratingContainers = document.querySelectorAll('.room-tile__rating');
            
            if (ratingContainers.length === 0) return;
            
            // Single batch API call instead of N+1 individual requests
            fetch('admin/api/all-room-ratings.php')
                .then(response => response.json())
                .then(result => {
                    if (result.success && result.data) {
                        const ratings = result.data;
                        
                        ratingContainers.forEach(container => {
                            const roomId = parseInt(container.dataset.roomId);
                            const ratingData = ratings[roomId];
                            
                            if (ratingData && ratingData.review_count > 0) {
                                const avgRating = ratingData.avg_rating;
                                const totalCount = ratingData.review_count;
                                
                                let starsHtml = '';
                                const fullStars = Math.floor(avgRating);
                                const hasHalfStar = (avgRating - fullStars) >= 0.5;
                                const emptyStars = 5 - fullStars - (hasHalfStar ? 1 : 0);

                                for (let i = 0; i < fullStars; i++) {
                                    starsHtml += '<i class="fas fa-star"></i>';
                                }
                                if (hasHalfStar) {
                                    starsHtml += '<i class="fas fa-star-half-alt"></i>';
                                }
                                for (let i = 0; i < emptyStars; i++) {
                                    starsHtml += '<i class="far fa-star"></i>';
                                }

                                container.innerHTML = `
                                    <div class="compact-rating">
                                        <div class="compact-rating__stars">${starsHtml}</div>
                                        <div class="compact-rating__info">
                                            <span class="compact-rating__score">${avgRating.toFixed(1)}</span>
                                            <span class="compact-rating__count">(${totalCount})</span>
                                        </div>
                                    </div>
                                `;
                            } else {
                                container.innerHTML = `
                                    <div class="compact-rating compact-rating--no-reviews">
                                        <i class="far fa-star"></i>
                                        <span>No reviews</span>
                                    </div>
                                `;
                            }
                        });
                    } else {
                        // Fallback for error case
                        ratingContainers.forEach(container => {
                            container.innerHTML = `
                                <div class="compact-rating compact-rating--no-reviews">
                                    <i class="far fa-star"></i>
                                    <span>No reviews</span>
                                </div>
                            `;
                        });
                    }
                })
                .catch(error => {
                    console.error('Error fetching room ratings:', error);
                    ratingContainers.forEach(container => {
                        container.innerHTML = `
                            <div class="compact-rating compact-rating--no-reviews">
                                <i class="far fa-star"></i>
                                <span>No reviews</span>
                            </div>
                        `;
                    });
                });
        })();
    </script>

    <?php include 'includes/scroll-to-top.php'; ?>
</body>
</html>
