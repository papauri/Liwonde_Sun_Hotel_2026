<?php
/**
 * Hotel Reviews Section
 * Displays guest reviews with rating summary and individual review cards
 * 
 * This file expects the following variables to be available:
 * - $hotel_reviews: Array of review records
 * - $review_averages: Array of average rating values
 * - $site_name: Site name for admin response attribution
 */

// Load section headers helper
require_once __DIR__ . '/section-headers.php';
?>

<!-- Hotel Reviews Section -->
<section class="section hotel-reviews-section" id="reviews">
    <div class="container">
        <?php renderSectionHeader('hotel_reviews', 'global', [
            'label' => 'Guest Reviews',
            'title' => 'What Our Guests Say',
            'description' => 'Read authentic reviews from guests who have experienced our hospitality'
        ]); ?>
        
        <?php if (!empty($hotel_reviews)): ?>
        <!-- Reviews List -->
        <div class="reviews-grid">
            <?php foreach ($hotel_reviews as $review): ?>
            <div class="hotel-review-card">
                <div class="review-header">
                    <div class="reviewer-avatar">
                        <i class="fas fa-user"></i>
                    </div>
                    <div class="reviewer-info">
                        <h4 class="reviewer-name"><?php echo htmlspecialchars($review['guest_name']); ?></h4>
                        <div class="review-rating">
                            <?php for ($i = 0; $i < $review['rating']; $i++): ?>
                            <i class="fas fa-star"></i>
                            <?php endfor; ?>
                            <?php for ($i = $review['rating']; $i < 5; $i++): ?>
                            <i class="far fa-star"></i>
                            <?php endfor; ?>
                        </div>
                    </div>
                </div>
                
                <div class="review-body">
                    <p class="review-comment"><?php echo htmlspecialchars($review['comment']); ?></p>
                </div>
                
                <div class="review-meta">
                    <span class="review-date">
                        <i class="far fa-calendar-alt"></i>
                        <?php echo date('F j, Y', strtotime($review['created_at'])); ?>
                    </span>
                </div>
                
                <?php if (!empty($review['latest_response'])): ?>
                <div class="review-admin-response">
                    <div class="admin-response-header">
                        <i class="fas fa-reply"></i>
                        <span>Response from <?php echo htmlspecialchars($site_name); ?></span>
                    </div>
                    <p class="admin-response-text"><?php echo htmlspecialchars($review['latest_response']); ?></p>
                    <?php if (!empty($review['latest_response_date'])): ?>
                    <span class="admin-response-date"><?php echo date('F j, Y', strtotime($review['latest_response_date'])); ?></span>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
        
        <!-- Reviews Actions -->
        <div class="reviews-actions">
            <a href="submit-review.php" class="btn-write-review">
                <i class="fas fa-pen-fancy"></i>
                <span>Write a Review</span>
                <i class="fas fa-arrow-right btn-arrow"></i>
            </a>
        </div>
        <?php else: ?>
        <!-- No Reviews Message -->
        <div class="no-reviews-message">
            <i class="fas fa-star"></i>
            <p>No reviews yet. Be the first to share your experience!</p>
            <a href="submit-review.php" class="btn btn-primary">Write a Review</a>
        </div>
        <?php endif; ?>
    </div>
</section>
