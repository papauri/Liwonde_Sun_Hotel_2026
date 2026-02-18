<?php require_once __DIR__ . '/visitor-tracker.php'; ?>
<?php require_once 'modal.php'; ?>
<!-- Footer - Minimalist Professional 2026 -->
<footer class="minimalist-footer" id="contact">
    <div class="container">
        <div class="minimalist-footer-grid">
            <?php
            // Fetch policies from database
            $policies = [];
            try {
                $stmt = $pdo->query("SELECT slug, title, summary, content FROM policies WHERE is_active = 1 ORDER BY display_order ASC");
                $policies = $stmt->fetchAll(PDO::FETCH_ASSOC);
            } catch (PDOException $e) {
                // Fallback if table doesn't exist
            }

            // Fetch footer links from database
            $footer_links = [];
            try {
                // Determine current page for link selection
                $request_uri = $_SERVER['REQUEST_URI'];
                $path = parse_url($request_uri, PHP_URL_PATH);
                $current_page = basename($path, '.php');
                if (empty($current_page) || $current_page === '') {
                    $current_page = 'index';
                }
                $is_index_page = ($current_page === 'index');

                // Select appropriate URL based on page
                if ($is_index_page) {
                    // On index page, use link_url (hash only)
                    $stmt = $pdo->query("SELECT column_name, link_text, link_url FROM footer_links WHERE is_active = 1 ORDER BY column_name, display_order ASC");
                } else {
                    // On other pages, use secondary_link_url if available, otherwise link_url
                    $stmt = $pdo->query("SELECT column_name, link_text,
                        COALESCE(NULLIF(secondary_link_url, ''), link_url) as link_url
                        FROM footer_links WHERE is_active = 1 ORDER BY column_name, display_order ASC");
                }
                $all_links = $stmt->fetchAll(PDO::FETCH_ASSOC);
                foreach ($all_links as $link) {
                    $footer_links[$link['column_name']][] = $link;
                }
            } catch (PDOException $e) {
                // Fallback if table doesn't exist
            }

            // Fetch contact settings
            $contact = [
                'phone_main' => getSetting('phone_main'),
                'email_main' => getSetting('email_main'),
                'address_line1' => getSetting('address_line1'),
                'working_hours' => getSetting('working_hours')
            ];

            // Fetch social links
            $social = [
                'facebook_url' => getSetting('facebook_url'),
                'instagram_url' => getSetting('instagram_url'),
                'twitter_url' => getSetting('twitter_url'),
                'linkedin_url' => getSetting('linkedin_url')
            ];

            foreach ($footer_links as $column_name => $links):
            ?>
            <div class="minimalist-footer-column">
                <h4><?php echo htmlspecialchars($column_name); ?></h4>
                <ul class="minimalist-footer-links">
                    <?php foreach ($links as $link): ?>
                    <li><a href="<?php echo htmlspecialchars($link['link_url']); ?>"><?php echo htmlspecialchars($link['link_text']); ?></a></li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <?php endforeach; ?>

            <div class="minimalist-footer-column">
                <h4>Policies</h4>
                <ul class="minimalist-footer-links">
                    <?php if (!empty($policies)): ?>
                        <?php foreach ($policies as $policy): ?>
                            <li><a href="#" class="policy-link" data-policy="<?php echo htmlspecialchars($policy['slug']); ?>"><?php echo htmlspecialchars($policy['title']); ?></a></li>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <li><a href="#" class="policy-link" data-policy="booking-policy">Booking Policy</a></li>
                        <li><a href="#" class="policy-link" data-policy="cancellation-policy">Cancellation</a></li>
                        <li><a href="#" class="policy-link" data-policy="dining-policy">Dining Policy</a></li>
                        <li><a href="#" class="policy-link" data-policy="faqs">FAQs</a></li>
                    <?php endif; ?>
                </ul>
            </div>

            <div class="minimalist-footer-column">
                <h4>Contact Information</h4>
                <ul class="minimalist-contact-info">
                    <li>
                        <i class="fas fa-phone"></i>
                        <a href="tel:<?php echo htmlspecialchars(preg_replace('/[^0-9+]/', '', $contact['phone_main'])); ?>"><?php echo htmlspecialchars($contact['phone_main']); ?></a>
                    </li>
                    <li>
                        <i class="fas fa-envelope"></i>
                        <a href="mailto:<?php echo htmlspecialchars($contact['email_main']); ?>"><?php echo htmlspecialchars($contact['email_main']); ?></a>
                    </li>
                    <li>
                        <i class="fas fa-map-marker-alt"></i>
                        <a href="https://www.google.com/maps/search/<?php echo urlencode($contact['address_line1']); ?>" target="_blank"><?php echo htmlspecialchars($contact['address_line1']); ?></a>
                    </li>
                    <li>
                        <i class="fas fa-clock"></i>
                        <span><?php echo htmlspecialchars($contact['working_hours']); ?></span>
                    </li>
                </ul>
            </div>

            <div class="minimalist-footer-column">
                <h4>Connect With Us</h4>
                <div class="minimalist-social-links">
                    <?php if (!empty($social['facebook_url'])): ?>
                    <a href="<?php echo htmlspecialchars($social['facebook_url']); ?>" class="minimalist-social-icon" target="_blank" aria-label="Facebook" title="Follow us on Facebook">
                        <i class="fab fa-facebook-f"></i>
                    </a>
                    <?php endif; ?>

                    <?php if (!empty($social['instagram_url'])): ?>
                    <a href="<?php echo htmlspecialchars($social['instagram_url']); ?>" class="minimalist-social-icon" target="_blank" aria-label="Instagram" title="Follow us on Instagram">
                        <i class="fab fa-instagram"></i>
                    </a>
                    <?php endif; ?>

                    <?php if (!empty($social['twitter_url'])): ?>
                    <a href="<?php echo htmlspecialchars($social['twitter_url']); ?>" class="minimalist-social-icon" target="_blank" aria-label="Twitter" title="Follow us on Twitter">
                        <i class="fab fa-twitter"></i>
                    </a>
                    <?php endif; ?>

                    <?php if (!empty($social['linkedin_url'])): ?>
                    <a href="<?php echo htmlspecialchars($social['linkedin_url']); ?>" class="minimalist-social-icon" target="_blank" aria-label="LinkedIn" title="Connect with us on LinkedIn">
                        <i class="fab fa-linkedin-in"></i>
                    </a>
                    <?php endif; ?>
                </div>

                <h4 class="share-section-title">Share</h4>
                <div class="minimalist-share-buttons">
                    <button class="minimalist-share-btn" onclick="sharePage('facebook')" aria-label="Share on Facebook">
                        <i class="fab fa-facebook-f"></i>
                    </button>
                    <button class="minimalist-share-btn" onclick="sharePage('twitter')" aria-label="Share on Twitter">
                        <i class="fab fa-twitter"></i>
                    </button>
                    <button class="minimalist-share-btn" onclick="sharePage('whatsapp')" aria-label="Share on WhatsApp">
                        <i class="fab fa-whatsapp"></i>
                    </button>
                    <button class="minimalist-share-btn" onclick="sharePage('email')" aria-label="Share via Email">
                        <i class="fas fa-envelope"></i>
                    </button>
                </div>
            </div>
        </div>

        <div class="minimalist-footer-bottom">
            <div class="minimalist-footer-copyright">
                <p><?php echo htmlspecialchars(getSetting('footer_credits', '&copy; ' . date('Y') . ' ' . getSetting('site_name', 'Hotel Website') . '. Powered by ProManaged IT')); ?></p>
            </div>

            <div class="minimalist-footer-credits">
                <p><?php echo htmlspecialchars(getSetting('footer_design_credit', 'Designed with <i class="fas fa-heart"></i> for Luxury Excellence')); ?></p>
            </div>
        </div>
    </div>
</footer>

<?php
// Always render policy overlay and modals - either from database or fallback defaults
?>
<div class="policy-overlay" data-policy-overlay></div>
<div class="policy-modals">
    <?php if (!empty($policies)): ?>
        <?php foreach ($policies as $policy): ?>
            <?php
            $policyContent = '';
            if (!empty($policy['summary'])) {
                $policyContent = '<p class="policy-summary" style="margin-bottom: 16px; color: #666; font-style: italic;">' . htmlspecialchars($policy['summary']) . '</p>';
            }
            $policyContent .= '<p>' . nl2br(htmlspecialchars($policy['content'])) . '</p>';

            renderModal(
                'policy-' . htmlspecialchars($policy['slug']),
                htmlspecialchars($policy['title']),
                $policyContent,
                [
                    'size' => 'md',
                    'show_close' => true
                ]
            );
            ?>
        <?php endforeach; ?>
    <?php else: ?>
        <?php
        // Fallback default policies when database is empty
        $defaultPolicies = [
            [
                'slug' => 'booking-policy',
                'title' => 'Booking Policy',
                'content' => "To make a reservation, we require a valid credit card or advance payment equal to the first night's stay.\n\nCancellations must be made at least 48 hours prior to check-in for a full refund. Late cancellations or no-shows will be charged for the first night.\n\nCheck-in time is 2:00 PM and check-out time is 11:00 AM. Early check-in or late check-out may be available upon request and subject to availability.\n\nGuests must be at least 18 years old to book a room and check in.\n\nWe reserve the right to refuse service to anyone at our discretion."
            ],
            [
                'slug' => 'cancellation-policy',
                'title' => 'Cancellation Policy',
                'content' => "Free cancellation up to 48 hours before check-in.\n\nCancellations made within 48 hours of check-in will be charged for the first night.\n\nNo-shows will be charged for the full reservation.\n\nRefunds will be processed to the original payment method within 5-7 business days.\n\nFor group bookings (5+ rooms) or special events, different cancellation terms may apply. Please contact us directly for details.\n\nIn case of unforeseen circumstances or force majeure events, we may offer flexible cancellation options."
            ],
            [
                'slug' => 'dining-policy',
                'title' => 'Dining Policy',
                'content' => "Our on-site restaurant serves breakfast, lunch, and dinner daily.\n\nBreakfast hours: 6:30 AM - 10:00 AM\nLunch hours: 12:00 PM - 2:30 PM\nDinner hours: 6:30 PM - 10:00 PM\n\nReservations are recommended for dinner, especially on weekends.\n\nDress code: Smart casual for dinner. No beachwear or flip-flops in the dining room.\n\nWe accommodate dietary restrictions and food allergies. Please inform us when making your reservation.\n\nRoom service is available from 7:00 AM to 10:00 PM.\n\nAlcoholic beverages will only be served to guests aged 18 and above."
            ],
            [
                'slug' => 'faqs',
                'title' => 'Frequently Asked Questions',
                'content' => "<strong>Q: Is parking available?</strong><br>A: Yes, we offer complimentary secure parking for all guests.\n\n<strong>Q: Do you offer airport transfers?</strong><br>A: Yes, airport transfers can be arranged at an additional cost. Please contact us in advance.\n\n<strong>Q: Is WiFi available?</strong><br>A: Complimentary high-speed WiFi is available throughout the hotel.\n\n<strong>Q: Are pets allowed?</strong><br>A: Unfortunately, we do not allow pets except for registered service animals.\n\n<strong>Q: What payment methods do you accept?</strong><br>A: We accept cash, credit cards (Visa, MasterCard, American Express), and mobile money.\n\n<strong>Q: Do you have a gym or fitness center?</strong><br>A: Yes, our fully equipped fitness center is available 24/7 for all guests.\n\n<strong>Q: Can I host events at the hotel?</strong><br>A: Yes, we have conference and event facilities. Please contact our events team for more information.\n\n<strong>Q: Is the hotel wheelchair accessible?</strong><br>A: Yes, we have wheelchair-accessible rooms and facilities. Please specify your requirements when booking."
            ]
        ];

        foreach ($defaultPolicies as $policy) {
            renderModal(
                'policy-' . $policy['slug'],
                $policy['title'],
                '<p>' . nl2br(htmlspecialchars($policy['content'])) . '</p>',
                [
                    'size' => 'md',
                    'show_close' => true
                ]
            );
        }
        ?>
    <?php endif; ?>
</div>

<!-- Share Script -->
<script>
function sharePage(platform) {
    const shareData = {
        title: document.title || '<?php echo htmlspecialchars(getSetting('site_name', 'Hotel Website')); ?>',
        url: window.location.href,
        text: 'Experience hospitality at <?php echo htmlspecialchars(getSetting('site_name', 'Hotel Website')); ?>. Book your stay today!'
    };

    const shareUrls = {
        facebook: `https://www.facebook.com/sharer/sharer.php?u=${encodeURIComponent(shareData.url)}&t=${encodeURIComponent(shareData.title)}`,
        twitter: `https://twitter.com/intent/tweet?text=${encodeURIComponent(shareData.title)}&url=${encodeURIComponent(shareData.url)}`,
        whatsapp: `https://wa.me/?text=${encodeURIComponent(shareData.text + ' ' + shareData.url)}`
    };

    if (platform === 'email') {
        const subject = encodeURIComponent(shareData.title);
        const body = encodeURIComponent(shareData.text + '\n\n' + shareData.url);
        window.location.href = `mailto:?subject=${subject}&body=${body}`;
    } else {
        const shareUrl = shareUrls[platform];
        window.open(shareUrl, '_blank', 'width=600,height=400');
    }
}
</script>

<?php require_once __DIR__ . '/cookie-consent.php'; ?>