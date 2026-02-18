<?php
/**
 * Hotel Gallery Section - Reusable Include
 * Displays a carousel of hotel gallery images with navigation controls
 * 
 * Required Variables:
 * - $gallery_images: Array of gallery image data with keys: image_url, title, description, category
 * - $site_name: Site name for context (optional)
 */

// If gallery_images is not available, try to fetch it
if (!isset($gallery_images) || empty($gallery_images)) {
    try {
        require_once __DIR__ . '/../config/database.php';
        
        // Check if getCachedGalleryImages function exists
        if (function_exists('getCachedGalleryImages')) {
            $gallery_images = getCachedGalleryImages();
        } else {
            // Fallback to direct query
            $stmt = $pdo->query("
                SELECT image_url, title, description, category, video_path, video_type 
                FROM hotel_gallery 
                WHERE is_active = 1 
                ORDER BY display_order ASC
            ");
            $gallery_images = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch (Exception $e) {
        $gallery_images = [];
        error_log("Error fetching gallery images: " . $e->getMessage());
    }
}

// Helper function to resolve image URLs
if (!function_exists('resolveImageUrl')) {
    function resolveImageUrl($path) {
        if (!$path) return '';
        $trimmed = trim($path);
        if (stripos($trimmed, 'http://') === 0 || stripos($trimmed, 'https://') === 0) {
            return $trimmed;
        }
        return $trimmed;
    }
}
?>

<!-- Hotel Gallery Carousel Section -->
<?php if (!empty($gallery_images)): ?>
<section class="section hotel-gallery-section" id="gallery">
    <div class="container">
        <?php 
        // Get section header from database
        $gallery_description = isset($site_name) 
            ? 'Immerse yourself in the beauty and luxury of ' . htmlspecialchars($site_name) 
            : 'Immerse yourself in the beauty and luxury of our hotel';
        
        renderSectionHeader('hotel_gallery', 'index', [
            'label' => 'Visual Journey',
            'title' => 'Explore Our Hotel',
            'description' => $gallery_description
        ]); 
        ?>
        
        <div class="gallery-carousel-wrapper">
            <button class="gallery-nav-btn gallery-nav-prev" aria-label="Previous">
                <i class="fas fa-chevron-left"></i>
            </button>
            
            <div class="gallery-carousel-container">
                <div class="gallery-carousel-track">
                    <?php 
// Include video display helper
require_once __DIR__ . '/video-display.php';

foreach ($gallery_images as $index => $image): ?>
                    <div class="gallery-carousel-item" data-index="<?php echo $index; ?>">
                        <div class="gallery-item-inner">
                            <?php if (!empty($image['video_path'])): ?>
                                <!-- Display video if available -->
                                <div class="gallery-video">
                                    <?php echo renderVideoEmbed($image['video_path'], $image['video_type'], [
                                        'autoplay' => true,
                                        'muted' => true,
                                        'controls' => false,
                                        'loop' => true,
                                        'class' => 'gallery-video-embed',
                                        'style' => 'position: absolute; top: 0; left: 0; width: 100%; height: 100%; object-fit: cover;'
                                    ]); ?>
                                </div>
                            <?php else: ?>
                                <!-- Display image if no video -->
                                <img src="<?php echo htmlspecialchars(resolveImageUrl($image['image_url'])); ?>" 
                                     alt="<?php echo htmlspecialchars($image['title']); ?>" 
                                     loading="lazy">
                            <?php endif; ?>
                            <div class="gallery-item-overlay">
                                <div class="gallery-item-content">
                                    <h4><?php echo htmlspecialchars($image['title']); ?></h4>
                                    <?php if (!empty($image['description'])): ?>
                                    <p><?php echo htmlspecialchars($image['description']); ?></p>
                                    <?php endif; ?>
                                    <?php if (!empty($image['category'])): ?>
                                    <span class="gallery-category-badge">
                                        <i class="fas fa-tag"></i> <?php echo htmlspecialchars($image['category']); ?>
                                    </span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            
            <button class="gallery-nav-btn gallery-nav-next" aria-label="Next">
                <i class="fas fa-chevron-right"></i>
            </button>
        </div>
        
        <div class="gallery-dots">
            <?php foreach ($gallery_images as $index => $image): ?>
            <button class="gallery-dot <?php echo $index === 0 ? 'active' : ''; ?>" 
                    data-index="<?php echo $index; ?>" 
                    aria-label="Go to image <?php echo $index + 1; ?>"></button>
            <?php endforeach; ?>
        </div>
    </div>
</section>
<?php endif; ?>