<?php
/**
 * Header Component
 * Liwonde Sun Hotel 2026
 * Modern centered navbar with glass-morphism - Inspired by Markopolo.ai
 */

// Load base URL helper
require_once __DIR__ . '/../config/base-url.php';
if (!function_exists('isBookingEnabled')) {
    require_once __DIR__ . '/booking-functions.php';
}

// Ensure $site_name and $site_logo are available regardless of the including page
/** @var string $site_name */
$site_name = isset($site_name) ? (string) $site_name : (function_exists('getSetting') ? (string) getSetting('site_name', 'Hotel') : 'Hotel');
/** @var string $site_logo */
$site_logo = isset($site_logo) ? (string) $site_logo : (function_exists('getSetting') ? (string) getSetting('site_logo', '') : '');

// Header logo kicker/tagline source
$header_logo_kicker = '';

if (isset($site_tagline) && is_string($site_tagline)) {
    $header_logo_kicker = trim($site_tagline);
}

if ($header_logo_kicker === '' && function_exists('getSetting')) {
    $header_logo_kicker = trim((string) getSetting('site_tagline', ''));
}

if ($header_logo_kicker === '') {
    $header_logo_kicker = isset($site_name) ? trim((string) $site_name) : '';
}
?>
<!-- Skip to content link for accessibility -->
<a href="#main-content" class="skip-to-content">Skip to main content</a>

<header class="lsh-header" role="banner">
    <div class="lsh-header__inner">
        <?php
        // Determine current page for active nav highlighting
        $current_file = basename($_SERVER['PHP_SELF']);

        // Function to check if nav link is active
        function is_nav_active(string $link_file): bool {
            global $current_file;
            $link_base = basename($link_file);

            if ($current_file === $link_base) {
                return true;
            }

            // Special case: room.php highlights "Rooms" nav
            if ($current_file === 'room.php' && $link_base === 'rooms-gallery.php') {
                return true;
            }

            return false;
        }

        // Load pages from site_pages table
        $_nav_pages = [];
        $_nav_booking = null;

        try {
            if (isset($pdo)) {
                $nav_stmt = $pdo->query("
                    SELECT page_key, title, file_path, icon
                    FROM site_pages
                    WHERE is_enabled = 1 AND show_in_nav = 1
                    ORDER BY nav_position ASC
                ");
                $all_nav = $nav_stmt->fetchAll(PDO::FETCH_ASSOC);

                foreach ($all_nav as $np) {
                    // Fix incorrect file_path values - remove 'api/' prefix if present
                    if (strpos($np['file_path'], 'api/') === 0) {
                        $np['file_path'] = substr($np['file_path'], 4);
                    }

                    if ($np['page_key'] === 'booking') {
                        $_nav_booking = $np;
                    } else {
                        $_nav_pages[] = $np;
                    }
                }
            }
        } catch (PDOException $e) {
            $_nav_pages = null;
        }

        // Fallback to hardcoded nav
        if (empty($_nav_pages) && $_nav_pages !== []) {
            $_nav_pages = [
                ['page_key' => 'home',       'title' => 'Home',       'file_path' => 'index.php',        'icon' => 'fa-home'],
                ['page_key' => 'rooms',      'title' => 'Rooms',      'file_path' => 'rooms-gallery.php','icon' => 'fa-bed'],
                ['page_key' => 'restaurant', 'title' => 'Restaurant', 'file_path' => 'restaurant.php',   'icon' => 'fa-utensils'],
                ['page_key' => 'gym',        'title' => 'Gym',        'file_path' => 'gym.php',          'icon' => 'fa-dumbbell'],
                ['page_key' => 'conference', 'title' => 'Conference', 'file_path' => 'conference.php',   'icon' => 'fa-briefcase'],
                ['page_key' => 'events',     'title' => 'Events',     'file_path' => 'events.php',       'icon' => 'fa-calendar-alt'],
            ];
            $_nav_booking = ['page_key' => 'booking', 'title' => 'Book Now', 'file_path' => 'booking.php', 'icon' => 'fa-calendar-check'];
        }

        // Apply feature toggles
        $bookingEnabled = function_exists('isBookingEnabled') ? isBookingEnabled() : true;
        $conferenceEnabled = function_exists('isConferenceEnabled') ? isConferenceEnabled() : true;
        $gymEnabled = function_exists('isGymEnabled') ? isGymEnabled() : true;
        $restaurantEnabled = function_exists('isRestaurantEnabled') ? isRestaurantEnabled() : true;
        $eventsEnabled = function_exists('isEventsEnabled') ? isEventsEnabled() : true;
        
        $_nav_pages = array_values(array_filter($_nav_pages, function ($navp) use ($bookingEnabled, $conferenceEnabled, $gymEnabled, $restaurantEnabled, $eventsEnabled) {
            $key = $navp['page_key'] ?? '';
            if ($key === 'rooms' && !$bookingEnabled) return false;
            if ($key === 'conference' && !$conferenceEnabled) return false;
            if ($key === 'gym' && !$gymEnabled) return false;
            if ($key === 'restaurant' && !$restaurantEnabled) return false;
            if ($key === 'events' && !$eventsEnabled) return false;
            return true;
        }));

        if (!$bookingEnabled) {
            $_nav_booking = null;
        }

        ?>

        <!-- Brand — left (Markopolo-style) -->
        <a href="<?php echo siteUrl('/'); ?>" class="lsh-header__brand" aria-label="<?php echo htmlspecialchars($site_name); ?> - Home">
            <span class="lsh-header__logo-wrap">
                <?php if (!empty($site_logo)): ?>
                <img src="<?php echo htmlspecialchars($site_logo); ?>" alt="" class="lsh-header__logo-img" loading="eager" decoding="async" fetchpriority="high" />
                <?php endif; ?>
                <span class="lsh-header__logo-copy">
                    <span class="lsh-header__logo-kicker"><?php echo htmlspecialchars($header_logo_kicker); ?></span>
                    <span class="lsh-header__logo-text"><?php echo htmlspecialchars($site_name); ?></span>
                </span>
            </span>
        </a>

        <!-- Centered Navigation -->
        <nav class="lsh-header__nav" role="navigation" aria-label="Main navigation">
            <ul class="lsh-header__menu">
                <?php foreach ($_nav_pages as $navp): ?>
                <li class="lsh-header__item">
                    <a href="<?php echo siteUrl($navp['file_path']); ?>"
                       class="lsh-header__link <?php echo is_nav_active($navp['file_path']) ? 'lsh-header__link--active' : ''; ?>">
                        <span class="lsh-header__link-text"><?php echo htmlspecialchars($navp['title']); ?></span>
                        <span class="lsh-header__link-indicator"></span>
                    </a>
                </li>
                <?php endforeach; ?>
            </ul>
        </nav>

        <!-- Actions — right -->
        <div class="lsh-header__actions">
            <?php if ($_nav_booking): ?>
            <a href="<?php echo siteUrl($_nav_booking['file_path']); ?>"
               class="lsh-header__cta <?php echo is_nav_active($_nav_booking['file_path']) ? 'lsh-header__cta--active' : ''; ?>">
                <span class="lsh-header__cta-bg"></span>
                <span class="lsh-header__cta-content">
                    <?php if (!empty($_nav_booking['icon'])): ?>
                    <i class="fas <?php echo htmlspecialchars($_nav_booking['icon']); ?>" aria-hidden="true"></i>
                    <?php endif; ?>
                    <span><?php echo htmlspecialchars($_nav_booking['title']); ?></span>
                </span>
            </a>

            <!-- Compact mobile-only Book Now button -->
            <a href="<?php echo siteUrl($_nav_booking['file_path']); ?>"
               class="lsh-header__cta-mini"
               aria-label="<?php echo htmlspecialchars($_nav_booking['title']); ?>">
                <i class="fas <?php echo !empty($_nav_booking['icon']) ? htmlspecialchars($_nav_booking['icon']) : 'fa-calendar-check'; ?>" aria-hidden="true"></i>
            </a>
            <?php endif; ?>

            <!-- Mobile Toggle -->
            <button class="lsh-header__toggle"
                    type="button"
                    aria-controls="lsh-mobile-menu"
                    aria-expanded="false"
                    aria-label="Toggle navigation menu"
                    data-mobile-toggle>
                <span class="lsh-header__toggle-box">
                    <span class="lsh-header__toggle-line"></span>
                    <span class="lsh-header__toggle-line"></span>
                    <span class="lsh-header__toggle-line"></span>
                </span>
            </button>
        </div>
    </div>
</header>

<?php
/* Mobile menu — full-screen overlay. Deliberately a sibling of <header> so it
   survives SPA content swaps. api/page-content.php strips everything from the
   opening tag below through the lsh-mobile:end marker out of SPA payloads, so
   it is never duplicated. Kept as a PHP comment: an HTML comment here would
   sit outside the stripped range and ship with every payload. */
?>
<div class="lsh-mobile" id="lsh-mobile-menu" aria-hidden="true">
    <div class="lsh-mobile__backdrop" data-mobile-close></div>

    <div class="lsh-mobile__panel">
        <div class="lsh-mobile__header">
            <a href="<?php echo siteUrl('/'); ?>" class="lsh-mobile__brand">
                <?php if (!empty($site_logo)): ?>
                <img src="<?php echo htmlspecialchars($site_logo); ?>" alt="" class="lsh-mobile__logo" />
                <?php endif; ?>
                <span class="lsh-mobile__logo-text"><?php echo htmlspecialchars($site_name); ?></span>
            </a>
                <button class="lsh-mobile__close" type="button" aria-label="Close menu" data-mobile-close>
                    <svg width="28" height="28" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <path d="M18 6L6 18M6 6L18 18" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                </button>
            </div>

            <nav class="lsh-mobile__nav" aria-label="Mobile navigation">
                <ul class="lsh-mobile__list">
                    <?php foreach ($_nav_pages as $index => $navp): ?>
                    <li class="lsh-mobile__item" style="--item-index: <?php echo $index; ?>">
                        <a href="<?php echo siteUrl($navp['file_path']); ?>"
                           class="lsh-mobile__link <?php echo is_nav_active($navp['file_path']) ? 'lsh-mobile__link--active' : ''; ?>">
                            <?php if (!empty($navp['icon'])): ?>
                            <i class="fas <?php echo htmlspecialchars($navp['icon']); ?> lsh-mobile__icon" aria-hidden="true"></i>
                            <?php endif; ?>
                            <span class="lsh-mobile__text"><?php echo htmlspecialchars($navp['title']); ?></span>
                            <svg class="lsh-mobile__arrow" width="20" height="20" viewBox="0 0 24 24" fill="none">
                                <path d="M9 18L15 12L9 6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                            </svg>
                        </a>
                    </li>
                    <?php endforeach; ?>
                </ul>

                <?php if ($_nav_booking): ?>
                <a href="<?php echo siteUrl($_nav_booking['file_path']); ?>"
                   class="lsh-mobile__cta <?php echo is_nav_active($_nav_booking['file_path']) ? 'lsh-mobile__cta--active' : ''; ?>">
                    <?php if (!empty($_nav_booking['icon'])): ?>
                    <i class="fas <?php echo htmlspecialchars($_nav_booking['icon']); ?>" aria-hidden="true"></i>
                    <?php endif; ?>
                    <span><?php echo htmlspecialchars($_nav_booking['title']); ?></span>
                </a>
                <?php endif; ?>
            </nav>

        <div class="lsh-mobile__footer">
            <p class="lsh-mobile__tagline"><?php echo htmlspecialchars($header_logo_kicker); ?></p>
        </div>
    </div>
</div>
<!-- lsh-mobile:end -->
