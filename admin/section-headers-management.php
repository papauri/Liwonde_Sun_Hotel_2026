<?php

/**
 * Section Headers Management
 * Admin interface for managing dynamic section headers
 */

require_once 'admin-init.php';
/** @var string $csrf_token */
require_once '../includes/alert.php';
require_once '../includes/section-headers.php';

$user = [
    'id' => $_SESSION['admin_user_id'],
    'username' => $_SESSION['admin_username'],
    'role' => $_SESSION['admin_role'],
    'full_name' => $_SESSION['admin_full_name']
];

if (!hasPermission((int)$user['id'], 'section_headers') && !in_array($user['role'] ?? '', ['admin', 'manager'], true)) {
    rhDenyAndRedirectHome((int)$_SESSION['admin_user_id'], (string)($_SESSION['admin_role'] ?? ''), basename($_SERVER['PHP_SELF']));
    exit;
}

$message = '';
$error = '';
$success = false;

/**
 * The loading-screen table is content-only and has no DDL anywhere else in the
 * repo, so create (and seed) it on first visit — same approach Page Management
 * takes for site_pages. Returns true when this call created the table.
 */
function ensurePageLoadersTable(PDO $pdo): bool
{
    $exists = $pdo->query("SHOW TABLES LIKE 'page_loaders'");
    if ($exists && $exists->fetch()) {
        return false;
    }

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS page_loaders (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            page_slug VARCHAR(50) NOT NULL COMMENT 'Page filename without .php, e.g. rooms-gallery',
            subtext VARCHAR(255) DEFAULT NULL COMMENT 'Line shown under the site name while the page loads',
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            display_order INT NOT NULL DEFAULT 0,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY page_slug (page_slug)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $seed = [
        ['index',        'Where Comfort Meets Value',        1],
        ['rooms-gallery','Preparing your room collection',   2],
        ['room',         'Preparing your room',              3],
        ['restaurant',   'Setting the table',                4],
        ['gym',          'Warming up',                       5],
        ['conference',   'Preparing the boardroom',          6],
        ['events',       'Gathering what\'s on',             7],
        ['booking',      'Opening the reservation desk',     8],
    ];
    $ins = $pdo->prepare("
        INSERT INTO page_loaders (page_slug, subtext, is_active, display_order)
        VALUES (?, ?, 1, ?)
        ON DUPLICATE KEY UPDATE page_slug = page_slug
    ");
    foreach ($seed as $row) {
        $ins->execute($row);
    }

    return true;
}

$page_loaders_available = true;
try {
    if (ensurePageLoadersTable($pdo)) {
        rh_log_event('section_headers', 'info', 'page_loaders table created and seeded');
    }
} catch (PDOException $e) {
    $page_loaders_available = false;
    rh_log_event('section_headers', 'error', 'Failed to initialize page_loaders table', ['error' => $e->getMessage()]);
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        header('Location: ' . basename($_SERVER['PHP_SELF']));
        exit;
    }
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'update_header') {
            $section_key = $_POST['section_key'] ?? '';
            $page = $_POST['page'] ?? '';
            $section_label = $_POST['section_label'] ?? '';
            $section_subtitle = $_POST['section_subtitle'] ?? '';
            $section_title = $_POST['section_title'] ?? '';
            $section_description = $_POST['section_description'] ?? '';
            $is_active = isset($_POST['is_active']) ? 1 : 0;

            if (empty($section_key) || empty($page)) {
                throw new Exception('Section key and page are required.');
            }

            $stmt = $pdo->prepare("
                UPDATE section_headers
                SET section_label = ?,
                    section_subtitle = ?,
                    section_title = ?,
                    section_description = ?,
                    is_active = ?,
                    updated_at = NOW()
                WHERE section_key = ? AND page = ?
            ");

            $stmt->execute([
                $section_label,
                $section_subtitle,
                $section_title,
                $section_description,
                $is_active,
                $section_key,
                $page
            ]);

            // Clear cache
            require_once __DIR__ . '/../config/cache.php';
            clearCache();

            $message = 'Section header updated successfully!';
            $success = true;
        } elseif ($action === 'toggle_active') {
            $section_key = $_POST['section_key'] ?? '';
            $page = $_POST['page'] ?? '';

            $stmt = $pdo->prepare("
                UPDATE section_headers
                SET is_active = NOT is_active,
                    updated_at = NOW()
                WHERE section_key = ? AND page = ?
            ");

            $stmt->execute([$section_key, $page]);

            // Clear cache
            require_once __DIR__ . '/../config/cache.php';
            clearCache();

            $message = 'Section header status updated!';
            $success = true;
        } elseif ($action === 'update_hero') {
            $hero_id          = (int)($_POST['hero_id'] ?? 0);
            $hero_title       = trim($_POST['hero_title'] ?? '');
            $hero_subtitle    = trim($_POST['hero_subtitle'] ?? '');
            $hero_description = trim($_POST['hero_description'] ?? '');
            $primary_cta_text = trim($_POST['primary_cta_text'] ?? '');
            $primary_cta_link = trim($_POST['primary_cta_link'] ?? '');
            $is_active        = isset($_POST['hero_is_active']) ? 1 : 0;

            if ($hero_id <= 0 || empty($hero_title)) {
                throw new Exception('Hero ID and title are required.');
            }

            $stmt = $pdo->prepare("
                UPDATE page_heroes
                SET hero_title         = ?,
                    hero_subtitle      = ?,
                    hero_description   = ?,
                    primary_cta_text   = ?,
                    primary_cta_link   = ?,
                    is_active          = ?,
                    updated_at         = NOW()
                WHERE id = ?
            ");
            $stmt->execute([
                $hero_title,
                $hero_subtitle ?: null,
                $hero_description ?: null,
                $primary_cta_text ?: null,
                $primary_cta_link ?: null,
                $is_active,
                $hero_id,
            ]);

            require_once __DIR__ . '/../config/cache.php';
            clearCache();

            $message = 'Page hero text updated successfully!';
            $success = true;
        } elseif ($action === 'revert_defaults') {
            // Delete all current section headers
            $pdo->exec("DELETE FROM section_headers");

            // Re-insert all default section headers
            $defaults = [
                // Homepage sections
                ['home_rooms', 'index', 'Accommodations', 'Where Comfort Meets Luxury', 'Luxurious Rooms & Suites', 'Experience unmatched comfort in our meticulously designed rooms and suites', 1],
                ['home_facilities', 'index', 'Amenities', NULL, 'World-Class Facilities', 'Indulge in our premium facilities designed for your ultimate comfort', 2],
                ['home_testimonials', 'index', 'Reviews', NULL, 'What Our Guests Say', 'Hear from those who have experienced our exceptional hospitality', 3],
                ['booking_widget', 'index', 'Reserve', NULL, 'Begin Your Stay', 'Select your dates and preferences for a seamless luxury booking experience.', 4],
                // Hotel Gallery
                ['hotel_gallery', 'index', 'Visual Journey', 'Discover Our Story', 'Explore Our Hotel', 'Immerse yourself in the beauty and luxury of our hotel', 5],
                // Reviews (global)
                ['hotel_reviews', 'global', 'Guest Impressions', NULL, 'Stories from Our Guests', 'Hear from those who have experienced our exceptional hospitality', 1],
                // Restaurant
                ['restaurant_gallery', 'restaurant', 'Visual Journey', NULL, 'Our Dining Spaces', 'From elegant interiors to breathtaking views, every detail creates the perfect ambiance', 1],
                ['restaurant_menu', 'restaurant', 'Culinary Delights', 'A Symphony of Flavors', 'Our Menu', 'Discover our carefully curated selection of dishes and beverages', 2],
                // Gym
                ['gym_wellness', 'gym', 'Your Wellness Journey', 'Transform Your Life', 'Start Your Fitness Journey', 'Transform your body and mind with our state-of-the-art facilities', 1],
                ['gym_facilities', 'gym', 'What We Offer', NULL, 'Comprehensive Fitness Facilities', 'Everything you need for a complete wellness experience', 2],
                ['gym_classes', 'gym', 'Stay Active', NULL, 'Group Fitness Classes', 'Join our expert-led classes designed for all fitness levels', 3],
                ['gym_training', 'gym', 'One-on-One Coaching', NULL, 'Personal Training Programs', 'Achieve your fitness goals faster with personalized guidance from our certified trainers', 4],
                ['gym_packages', 'gym', 'Exclusive Offers', NULL, 'Wellness Packages', 'Comprehensive packages designed for optimal health and relaxation', 5],
                // Rooms showcase
                ['rooms_collection', 'rooms-showcase', 'Stay Collection', NULL, 'Pick Your Perfect Space', 'Suites and rooms crafted for business, romance, and family stays with direct booking flows', 1],
                // Conference
                ['conference_overview', 'conference', 'Our Meeting Spaces', NULL, 'Professional Conference Facilities', 'State-of-the-art venues for your business meetings and events', 1],
                // Events
                ['events_overview', 'events', 'Upcoming Events', NULL, 'Special Events & Occasions', 'Join us for memorable celebrations and special gatherings', 1],
                // Upcoming Events (homepage section)
                ['upcoming_events', 'index', "What's Happening", NULL, 'Upcoming Events', "Don't miss out on our carefully curated experiences and celebrations", 6]
            ];

            $stmt = $pdo->prepare("
                INSERT INTO section_headers
                (section_key, page, section_label, section_subtitle, section_title, section_description, display_order)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");

            foreach ($defaults as $default) {
                $stmt->execute($default);
            }

            // Clear cache
            require_once __DIR__ . '/../config/cache.php';
            clearCache();

            $message = 'All section headers have been reset to factory defaults!';
            $success = true;
        } elseif ($action === 'create_hero') {
            $page_slug_new  = trim(preg_replace('/[^a-z0-9\-]/', '', strtolower($_POST['new_page_slug'] ?? '')));
            $page_url_new   = trim($_POST['new_page_url'] ?? '');
            $hero_title_new = trim($_POST['new_hero_title'] ?? '');

            if (empty($page_slug_new) || empty($hero_title_new)) {
                throw new Exception('Page slug and hero title are required.');
            }

            // Check slug not already taken
            $chk = $pdo->prepare("SELECT id FROM page_heroes WHERE page_slug = ? LIMIT 1");
            $chk->execute([$page_slug_new]);
            if ($chk->fetchColumn()) {
                throw new Exception("A hero for slug '{$page_slug_new}' already exists. Edit it from the list below.");
            }

            $ins = $pdo->prepare("
                INSERT INTO page_heroes (page_slug, page_url, hero_title, hero_subtitle, hero_description, is_active, display_order)
                VALUES (?, ?, ?, NULL, NULL, 1, 99)
            ");
            $ins->execute([$page_slug_new, $page_url_new ?: '/' . $page_slug_new . '.php', $hero_title_new]);

            require_once __DIR__ . '/../config/cache.php';
            clearCache();

            $message = "Hero entry created for '{$page_slug_new}'. You can now edit it below.";
            $success = true;
        } elseif ($action === 'update_hero_chips') {
            // Booking trust chips shown under the home page hero (includes/hero.php).
            // Stored as site_settings so the locked page_heroes schema stays untouched.
            for ($slot = 1; $slot <= 3; $slot++) {
                $chipText = trim($_POST["hero_chip_{$slot}_text"] ?? '');
                $chipIcon = trim($_POST["hero_chip_{$slot}_icon"] ?? '');

                if ($chipIcon !== '' && !preg_match('/^[a-z0-9\- ]+$/i', $chipIcon)) {
                    throw new Exception("Badge {$slot}: icon must be a Font Awesome class such as fa-bolt.");
                }
                if (mb_strlen($chipText) > 40) {
                    throw new Exception("Badge {$slot}: keep the label under 40 characters so it fits on mobile.");
                }

                updateSetting("hero_chip_{$slot}_text", $chipText);
                updateSetting("hero_chip_{$slot}_icon", $chipIcon);
            }

            require_once __DIR__ . '/../config/cache.php';
            clearCache();

            $message = 'Home hero badges updated!';
            $success = true;
        } elseif ($action === 'update_loader') {
            $loader_id      = (int)($_POST['loader_id'] ?? 0);
            $loader_subtext = trim($_POST['loader_subtext'] ?? '');
            $loader_active  = isset($_POST['loader_is_active']) ? 1 : 0;

            if ($loader_id <= 0) {
                throw new Exception('Invalid loading screen.');
            }
            if (mb_strlen($loader_subtext) > 255) {
                throw new Exception('Loading message must be 255 characters or fewer.');
            }

            $stmt = $pdo->prepare("
                UPDATE page_loaders
                SET subtext = ?, is_active = ?, updated_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$loader_subtext ?: null, $loader_active, $loader_id]);

            require_once __DIR__ . '/../config/cache.php';
            clearCache();

            $message = 'Loading screen updated!';
            $success = true;
        } elseif ($action === 'create_loader') {
            $loader_slug_new    = trim(preg_replace('/[^a-z0-9\-]/', '', strtolower($_POST['new_loader_slug'] ?? '')));
            $loader_subtext_new = trim($_POST['new_loader_subtext'] ?? '');

            if ($loader_slug_new === '') {
                throw new Exception('Page slug is required.');
            }
            if (mb_strlen($loader_subtext_new) > 255) {
                throw new Exception('Loading message must be 255 characters or fewer.');
            }

            $chk = $pdo->prepare("SELECT id FROM page_loaders WHERE page_slug = ? LIMIT 1");
            $chk->execute([$loader_slug_new]);
            if ($chk->fetchColumn()) {
                throw new Exception("A loading screen for '{$loader_slug_new}' already exists. Edit it in the list below.");
            }

            $maxOrder = (int)$pdo->query("SELECT COALESCE(MAX(display_order), 0) FROM page_loaders")->fetchColumn();
            $ins = $pdo->prepare("
                INSERT INTO page_loaders (page_slug, subtext, is_active, display_order)
                VALUES (?, ?, 1, ?)
            ");
            $ins->execute([$loader_slug_new, $loader_subtext_new ?: null, $maxOrder + 1]);

            require_once __DIR__ . '/../config/cache.php';
            clearCache();

            $message = "Loading screen created for '{$loader_slug_new}'.";
            $success = true;
        } elseif ($action === 'reset_single') {
            $section_key = $_POST['section_key'] ?? '';
            $page = $_POST['page'] ?? '';

            if (empty($section_key) || empty($page)) {
                throw new Exception('Section key and page are required.');
            }

            // Define all defaults
            $all_defaults = [
                ['home_rooms', 'index', 'Accommodations', 'Where Comfort Meets Luxury', 'Luxurious Rooms & Suites', 'Experience unmatched comfort in our meticulously designed rooms and suites', 1],
                ['home_facilities', 'index', 'Amenities', NULL, 'World-Class Facilities', 'Indulge in our premium facilities designed for your ultimate comfort', 2],
                ['home_testimonials', 'index', 'Reviews', NULL, 'What Our Guests Say', 'Hear from those who have experienced our exceptional hospitality', 3],
                ['booking_widget', 'index', 'Reserve', NULL, 'Begin Your Stay', 'Select your dates and preferences for a seamless luxury booking experience.', 4],
                ['hotel_gallery', 'index', 'Visual Journey', 'Discover Our Story', 'Explore Our Hotel', 'Immerse yourself in the beauty and luxury of our hotel', 5],
                ['hotel_reviews', 'global', 'Guest Impressions', NULL, 'Stories from Our Guests', 'Hear from those who have experienced our exceptional hospitality', 1],
                ['restaurant_gallery', 'restaurant', 'Visual Journey', NULL, 'Our Dining Spaces', 'From elegant interiors to breathtaking views, every detail creates the perfect ambiance', 1],
                ['restaurant_menu', 'restaurant', 'Culinary Delights', 'A Symphony of Flavors', 'Our Menu', 'Discover our carefully curated selection of dishes and beverages', 2],
                ['gym_wellness', 'gym', 'Your Wellness Journey', 'Transform Your Life', 'Start Your Fitness Journey', 'Transform your body and mind with our state-of-the-art facilities', 1],
                ['gym_facilities', 'gym', 'What We Offer', NULL, 'Comprehensive Fitness Facilities', 'Everything you need for a complete wellness experience', 2],
                ['gym_classes', 'gym', 'Stay Active', NULL, 'Group Fitness Classes', 'Join our expert-led classes designed for all fitness levels', 3],
                ['gym_training', 'gym', 'One-on-One Coaching', NULL, 'Personal Training Programs', 'Achieve your fitness goals faster with personalized guidance from our certified trainers', 4],
                ['gym_packages', 'gym', 'Exclusive Offers', NULL, 'Wellness Packages', 'Comprehensive packages designed for optimal health and relaxation', 5],
                ['rooms_collection', 'rooms-showcase', 'Stay Collection', NULL, 'Pick Your Perfect Space', 'Suites and rooms crafted for business, romance, and family stays with direct booking flows', 1],
                ['conference_overview', 'conference', 'Our Meeting Spaces', NULL, 'Professional Conference Facilities', 'State-of-the-art venues for your business meetings and events', 1],
                ['events_overview', 'events', 'Upcoming Events', NULL, 'Special Events & Occasions', 'Join us for memorable celebrations and special gatherings', 1],
                // Upcoming Events (homepage section)
                ['upcoming_events', 'index', "What's Happening", NULL, 'Upcoming Events', "Don't miss out on our carefully curated experiences and celebrations", 6]
            ];

            // Find the matching default
            $default = null;
            foreach ($all_defaults as $d) {
                if ($d[0] === $section_key && $d[1] === $page) {
                    $default = $d;
                    break;
                }
            }

            if (!$default) {
                throw new Exception('No default found for this section.');
            }

            // Update the section to default values
            $stmt = $pdo->prepare("
                UPDATE section_headers
                SET section_label = ?,
                    section_subtitle = ?,
                    section_title = ?,
                    section_description = ?,
                    display_order = ?,
                    is_active = 1,
                    updated_at = NOW()
                WHERE section_key = ? AND page = ?
            ");

            $stmt->execute([
                $default[2], // section_label
                $default[3], // section_subtitle
                $default[4], // section_title
                $default[5], // section_description
                $default[6], // display_order
                $section_key,
                $page
            ]);

            // Clear cache
            require_once __DIR__ . '/../config/cache.php';
            clearCache();

            $message = 'Section header reset to default successfully!';
            $success = true;
        }
    } catch (Exception $e) {
        $error = 'Error: ' . $e->getMessage();
    }

    // PRG — preserve flash message through redirect to avoid form re-submit on refresh
    if (in_array($action, ['update_loader', 'create_loader'], true)) {
        $tab = '#tab-loaders';
    } elseif (in_array($action, ['update_hero', 'create_hero', 'update_hero_chips'], true)) {
        $tab = '#tab-heroes';
    } else {
        $tab = '#tab-sections';
    }
    $flash_key = $success ? 'flash_success' : 'flash_error';
    $_SESSION[$flash_key] = $success ? $message : $error;
    header('Location: section-headers-management.php' . $tab);
    exit;
}

// Recover flash message from session
if (!empty($_SESSION['flash_success'])) {
    $message = $_SESSION['flash_success'];
    $success = true;
    unset($_SESSION['flash_success']);
}
if (!empty($_SESSION['flash_error'])) {
    $error = $_SESSION['flash_error'];
    unset($_SESSION['flash_error']);
}

// Get filter parameters
$filter_page = $_GET['page_filter'] ?? 'all';

// Fetch all section headers
try {
    if ($filter_page === 'all') {
        $stmt = $pdo->query("
            SELECT * FROM section_headers
            ORDER BY page, display_order, section_title
        ");
    } else {
        $stmt = $pdo->prepare("
            SELECT * FROM section_headers
            WHERE page = ?
            ORDER BY display_order, section_title
        ");
        $stmt->execute([$filter_page]);
    }
    $section_headers = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get unique pages for filter
    $pages_stmt = $pdo->query("SELECT DISTINCT page FROM section_headers ORDER BY page");
    $pages = $pages_stmt->fetchAll(PDO::FETCH_COLUMN);
} catch (PDOException $e) {
    $error = 'Error loading section headers: ' . $e->getMessage();
    $section_headers = [];
    $pages = [];
}

// Fetch all page heroes for the hero text tab
try {
    $page_heroes_rows = $pdo->query("
        SELECT id, page_slug, page_url, hero_title, hero_subtitle,
               hero_description, primary_cta_text, primary_cta_link,
               is_active
        FROM page_heroes
        ORDER BY page_slug ASC
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $page_heroes_rows = [];
}

// Fetch loading screens (page_loaders) for the loaders tab
$page_loaders_rows = [];
if ($page_loaders_available) {
    try {
        $page_loaders_rows = $pdo->query("
            SELECT id, page_slug, subtext, is_active
            FROM page_loaders
            ORDER BY display_order ASC, page_slug ASC
        ")->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $page_loaders_rows = [];
    }
}

// Home hero badges (site_settings) — defaults mirror includes/hero.php
$hero_chip_defaults = [
    1 => ['icon' => 'fa-bolt',          'text' => 'Instant confirmation'],
    2 => ['icon' => 'fa-shield-halved', 'text' => 'Secure booking'],
    3 => ['icon' => 'fa-tag',           'text' => 'Best rate direct'],
];
$hero_chips = [];
foreach ($hero_chip_defaults as $slot => $chip_default) {
    $hero_chips[$slot] = [
        'text' => (string) getSetting("hero_chip_{$slot}_text", $chip_default['text']),
        'icon' => (string) getSetting("hero_chip_{$slot}_icon", $chip_default['icon']),
    ];
}

$current_page = 'section-headers-management.php';
$page_title = 'Section Headers Management';
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= htmlspecialchars($csrf_token, ENT_QUOTES) ?>">
    <script>
        (function() {
            var _t = '<?= htmlspecialchars($csrf_token, ENT_QUOTES) ?>';
            var _f = window.fetch;
            window.fetch = function(u, o) {
                if (o && o.body instanceof FormData && !o.body.has('csrf_token')) o.body.append('csrf_token', _t);
                return _f.apply(this, arguments);
            };
        })();
    </script>
    <title><?php echo htmlspecialchars($page_title); ?> - Admin Panel</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;0,500;0,600;1,300;1,400;1,500&family=Jost:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/admin-styles.css?v=<?php echo @filemtime(__DIR__ . '/css/admin-styles.css'); ?>">
    <link rel="stylesheet" href="css/admin-components.css?v=<?php echo @filemtime(__DIR__ . '/css/admin-components.css'); ?>">
    <link rel="stylesheet" href="css/section-headers.css?v=<?php echo @filemtime(__DIR__ . '/css/section-headers.css'); ?>">
</head>

<body>
    <?php require_once 'includes/admin-header.php'; ?>

    <div class="content sh-page">
        <header class="rh-page-head">
            <div class="rh-page-head__main">
                <div class="rh-page-head__title">
                    <h1>Section Headers Management</h1>
                </div>
                <p class="rh-page-head__meta">Manage live section headers and page hero text used across the frontend.</p>
            </div>
        </header>

        <?php if ($message): ?>
            <div class="alert alert-<?php echo $success ? 'success' : 'info'; ?>">
                <i class="fas fa-<?php echo $success ? 'check-circle' : 'info-circle'; ?>"></i>
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="alert alert-error">
                <i class="fas fa-exclamation-triangle"></i>
                <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>

        <!-- Tab Navigation -->
        <div class="sh-tabs">
            <button type="button" class="sh-tab sh-tab--active" data-tab="heroes">
                <i class="fas fa-image"></i> Page Hero Text
            </button>
            <button type="button" class="sh-tab" data-tab="sections">
                <i class="fas fa-heading"></i> Section Headers
            </button>
            <button type="button" class="sh-tab" data-tab="loaders">
                <i class="fas fa-spinner"></i> Loading Screens
            </button>
        </div>

        <!-- ===== TAB: PAGE HEROES ===== -->
        <div id="tab-heroes" class="sh-tab-panel">
            <?php $heroFilter = $_GET['hero_filter'] ?? ''; ?>
            <div class="sh-toolbar">
                <p class="sh-toolbar__note">Edit the hero banner text (title, subtitle, description, buttons) shown at the top of each page. Images and videos are managed in <a href="media-management.php">Media Portal</a>.</p>
                <div class="sh-toolbar__row">
                    <label for="heroJump">Jump to page:</label>
                    <select id="heroJump" class="sh-input" onchange="window.location.href='section-headers-management.php?hero_filter=' + this.value + '#tab-heroes'">
                        <option value="">All Pages (<?= count($page_heroes_rows) ?>)</option>
                        <?php foreach ($page_heroes_rows as $_ph): ?>
                            <option value="<?= htmlspecialchars($_ph['page_slug']) ?>"
                                <?= $heroFilter === $_ph['page_slug'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($_ph['page_slug']) ?><?= !$_ph['is_active'] ? ' (inactive)' : '' ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?php if ($heroFilter !== ''): ?>
                        <a class="rh-mini-link" href="section-headers-management.php#tab-heroes">
                            <i class="fas fa-times"></i> Show all
                        </a>
                    <?php endif; ?>
                </div>
            </div>


            <!-- Home hero badges -->
            <section class="rh-panel">
                <div class="rh-panel__head">
                    <h2 class="rh-panel__title">Home hero badges</h2>
                    <div class="rh-panel__actions">
                        <span class="sh-meta">
                            <?php
                            $chip_preview = array_values(array_filter(array_map(static fn($c) => trim($c['text']), $hero_chips), static fn($t) => $t !== ''));
                            echo $chip_preview ? htmlspecialchars(implode('  ·  ', $chip_preview)) : 'No badges shown';
                            ?>
                        </span>
                        <button type="button" class="sh-btn sh-btn--primary" onclick="toggleEdit('hero-chips-form')">
                            <i class="fas fa-certificate"></i> Home Hero Badges
                        </button>
                    </div>
                </div>
                <form method="POST" id="edit_hero-chips-form" class="sh-form sh-edit-form">
                    <input type="hidden" name="action" value="update_hero_chips">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token, ENT_QUOTES) ?>">
                    <p class="sh-toolbar__note">
                        The three small badges under the home page hero. They advertise the booking flow, so they appear on the home hero only.
                        Clear a label to remove that badge. Icons use <a href="https://fontawesome.com/search?o=r&m=free" target="_blank" rel="noopener">Font Awesome</a> class names, e.g. <code>fa-bolt</code>.
                    </p>
                    <?php foreach ($hero_chips as $slot => $chip): ?>
                    <div class="sh-form__grid">
                        <div class="sh-field">
                            <label>Badge <?= (int)$slot ?> icon</label>
                            <input type="text" name="hero_chip_<?= (int)$slot ?>_icon" value="<?= htmlspecialchars($chip['icon']) ?>"
                                placeholder="fa-bolt" pattern="[A-Za-z0-9\- ]*" title="Font Awesome class, e.g. fa-bolt" class="sh-input">
                        </div>
                        <div class="sh-field">
                            <label>Badge <?= (int)$slot ?> label</label>
                            <input type="text" name="hero_chip_<?= (int)$slot ?>_text" value="<?= htmlspecialchars($chip['text']) ?>"
                                maxlength="40" placeholder="e.g. Instant confirmation" class="sh-input">
                        </div>
                    </div>
                    <?php endforeach; ?>
                    <div class="sh-form__foot">
                        <button type="submit" class="sh-btn sh-btn--primary">
                            <i class="fas fa-save"></i> Save Badges
                        </button>
                        <button type="button" class="sh-btn sh-btn--ghost" onclick="toggleEdit('hero-chips-form')">
                            Cancel
                        </button>
                    </div>
                </form>
            </section>
            <!-- Add new hero entry -->
            <section class="rh-panel">
                <div class="rh-panel__head">
                    <h2 class="rh-panel__title">Add hero for new page</h2>
                    <div class="rh-panel__actions">
                        <button type="button" class="sh-btn sh-btn--primary" onclick="toggleEdit('new-hero-form')">
                            <i class="fas fa-plus"></i> Add Hero for New Page
                        </button>
                    </div>
                </div>
                <form method="POST" id="edit_new-hero-form" class="sh-form sh-edit-form">
                    <input type="hidden" name="action" value="create_hero">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token, ENT_QUOTES) ?>">
                    <div class="sh-form__grid">
                        <div class="sh-field">
                            <label>
                                Page Slug <span class="sh-req">*</span>
                                <span class="sh-hint"> — e.g. <code>booking</code>, <code>privacy-policy</code></span>
                            </label>
                            <input type="text" name="new_page_slug" required placeholder="booking"
                                pattern="[a-z0-9\-]+" title="Lowercase letters, numbers and hyphens only" class="sh-input">
                        </div>
                        <div class="sh-field">
                            <label>
                                Page URL <span class="sh-hint"> — e.g. /booking.php</span>
                            </label>
                            <input type="text" name="new_page_url" placeholder="/booking.php" class="sh-input">
                        </div>
                    </div>
                    <div class="sh-field">
                        <label>
                            Hero Title <span class="sh-req">*</span>
                        </label>
                        <input type="text" name="new_hero_title" required placeholder="e.g. Book Your Stay" class="sh-input">
                    </div>
                    <div class="sh-form__foot">
                        <button type="submit" class="sh-btn sh-btn--primary">
                            <i class="fas fa-save"></i> Create Hero Entry
                        </button>
                        <button type="button" class="sh-btn sh-btn--ghost" onclick="toggleEdit('new-hero-form')">
                            Cancel
                        </button>
                    </div>
                </form>
            </section>

            <?php foreach ($page_heroes_rows as $ph): ?>
                <?php if ($heroFilter !== '' && $ph['page_slug'] !== $heroFilter) continue; ?>
                <?php $hid = 'hero_' . (int)$ph['id']; ?>
                <section class="rh-panel">
                    <div class="rh-panel__head">
                        <h2 class="rh-panel__title"><?php echo htmlspecialchars($ph['page_slug']); ?></h2>
                        <div class="rh-panel__actions">
                            <span class="rh-pill rh-pill--muted">Hero</span>
                            <?php if (!$ph['is_active']): ?>
                                <span class="rh-pill rh-pill--alert">Inactive</span>
                            <?php endif; ?>
                            <span class="sh-meta"><?php echo htmlspecialchars($ph['page_url'] ?: ('/' . $ph['page_slug'] . '.php')); ?></span>
                        </div>
                    </div>

                    <!-- Edit Form (always visible — no toggle needed for hero pages) -->
                    <form method="POST" id="edit_<?php echo $hid; ?>" class="sh-form">
                        <input type="hidden" name="action" value="update_hero">
                        <input type="hidden" name="hero_id" value="<?php echo (int)$ph['id']; ?>">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">

                        <div class="sh-field">
                            <label>
                                Hero Title <span class="sh-req">*</span>
                                <span class="sh-hint"> — main H1 heading</span>
                            </label>
                            <input type="text" name="hero_title" required
                                value="<?php echo htmlspecialchars($ph['hero_title']); ?>" class="sh-input">
                        </div>
                        <div class="sh-field">
                            <label>
                                Hero Subtitle
                                <span class="sh-hint"> — italic line below title</span>
                            </label>
                            <input type="text" name="hero_subtitle"
                                value="<?php echo htmlspecialchars($ph['hero_subtitle'] ?? ''); ?>" class="sh-input">
                        </div>
                        <div class="sh-field">
                            <label>
                                Description
                                <span class="sh-hint"> — optional paragraph below subtitle</span>
                            </label>
                            <textarea name="hero_description" rows="3" class="sh-input"><?php echo htmlspecialchars($ph['hero_description'] ?? ''); ?></textarea>
                        </div>
                        <div class="sh-form__grid">
                            <div class="sh-field">
                                <label>Primary Button Text</label>
                                <input type="text" name="primary_cta_text"
                                    value="<?php echo htmlspecialchars($ph['primary_cta_text'] ?? ''); ?>"
                                    placeholder="e.g., Book Now" class="sh-input">
                            </div>
                            <div class="sh-field">
                                <label>Primary Button Link</label>
                                <input type="text" name="primary_cta_link"
                                    value="<?php echo htmlspecialchars($ph['primary_cta_link'] ?? ''); ?>"
                                    placeholder="e.g., booking.php" class="sh-input">
                            </div>
                        </div>
                        <label class="sh-check">
                            <input type="checkbox" name="hero_is_active" value="1"
                                <?php echo $ph['is_active'] ? 'checked' : ''; ?>>
                            <span>Active (hero visible on page)</span>
                        </label>

                        <div class="sh-form__foot">
                            <button type="submit" class="sh-btn sh-btn--primary">
                                <i class="fas fa-save"></i> Save Hero Text
                            </button>
                        </div>
                    </form>
                </section>
            <?php endforeach; ?>
        </div><!-- /tab-heroes -->

        <!-- ===== TAB: SECTION HEADERS ===== -->
        <div id="tab-sections" class="sh-tab-panel" style="display:none;">

            <!-- Page Filter -->
            <div class="sh-toolbar">
                <div class="sh-toolbar__row">
                    <label for="pageFilter">Filter by Page:</label>
                    <select id="pageFilter" class="sh-input" onchange="window.location.href='section-headers-management.php?page_filter=' + this.value + '#tab-sections'">
                        <option value="all" <?php echo $filter_page === 'all' ? 'selected' : ''; ?>>All Pages</option>
                        <?php foreach ($pages as $page): ?>
                            <option value="<?php echo htmlspecialchars($page); ?>"
                                <?php echo $filter_page === $page ? 'selected' : ''; ?>>
                                <?php echo ucfirst(htmlspecialchars($page)); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <span class="sh-meta sh-toolbar__count">
                        <i class="fas fa-info-circle"></i>
                        <strong><?php echo count($section_headers); ?></strong> section(s) found
                    </span>
                </div>
            </div>

            <?php if (empty($section_headers)): ?>
                <section class="rh-panel">
                    <p class="rh-empty">No section headers found.</p>
                </section>
            <?php else: ?>
                <?php foreach ($section_headers as $header): ?>
                    <?php $sh_edit_id = htmlspecialchars($header['section_key']) . '_' . htmlspecialchars($header['page']); ?>
                    <section class="rh-panel header-card">
                        <div class="rh-panel__head">
                            <h2 class="rh-panel__title">
                                <?php echo htmlspecialchars($header['section_key']); ?>
                                <span class="rh-pill rh-pill--muted"><?php echo htmlspecialchars($header['page']); ?></span>
                                <?php if ($header['is_active']): ?>
                                    <span class="rh-pill rh-pill--ok">Active</span>
                                <?php else: ?>
                                    <span class="rh-pill rh-pill--alert">Inactive</span>
                                <?php endif; ?>
                            </h2>
                            <div class="rh-panel__actions">
                                <span class="sh-meta">Order <?php echo $header['display_order']; ?></span>
                                <button type="button" onclick="toggleEdit('<?php echo $sh_edit_id; ?>')"
                                    class="sh-btn sh-btn--primary">
                                    <i class="fas fa-edit"></i> Edit
                                </button>

                                <form method="post" onsubmit="return confirm('Reset this section to factory default?')">
                                    <input type="hidden" name="action" value="reset_single">
                                    <input type="hidden" name="section_key" value="<?php echo htmlspecialchars($header['section_key']); ?>">
                                    <input type="hidden" name="page" value="<?php echo htmlspecialchars($header['page']); ?>">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token, ENT_QUOTES) ?>">
                                    <button type="submit" class="sh-btn sh-btn--ghost">
                                        <i class="fas fa-undo"></i> Reset
                                    </button>
                                </form>
                            </div>
                        </div>

                        <!-- Preview -->
                        <div class="sh-section-preview">
                            <?php if (!empty($header['section_label'])): ?>
                                <span class="sh-preview__label"><?php echo htmlspecialchars($header['section_label']); ?></span>
                            <?php endif; ?>

                            <?php if (!empty($header['section_subtitle'])): ?>
                                <p class="sh-preview__subtitle"><?php echo htmlspecialchars($header['section_subtitle']); ?></p>
                            <?php endif; ?>

                            <h3 class="sh-preview__title"><?php echo htmlspecialchars($header['section_title']); ?></h3>

                            <?php if (!empty($header['section_description'])): ?>
                                <p class="sh-preview__desc"><?php echo htmlspecialchars($header['section_description']); ?></p>
                            <?php endif; ?>
                        </div>

                        <!-- Edit Form (Hidden by default) -->
                        <form method="POST" id="edit_<?php echo $sh_edit_id; ?>" class="sh-form sh-edit-form">
                            <input type="hidden" name="action" value="update_header">
                            <input type="hidden" name="section_key" value="<?php echo htmlspecialchars($header['section_key']); ?>">
                            <input type="hidden" name="page" value="<?php echo htmlspecialchars($header['page']); ?>">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token, ENT_QUOTES) ?>">

                            <div class="sh-field">
                                <label>
                                    Section Label <span class="sh-hint">(Small uppercase tag)</span>
                                </label>
                                <input type="text" name="section_label"
                                    value="<?php echo htmlspecialchars($header['section_label'] ?? ''); ?>"
                                    placeholder="e.g., ACCOMMODATIONS" class="sh-input">
                            </div>

                            <div class="sh-field">
                                <label>
                                    Section Subtitle <span class="sh-hint">(Italic descriptive text)</span>
                                </label>
                                <input type="text" name="section_subtitle"
                                    value="<?php echo htmlspecialchars($header['section_subtitle'] ?? ''); ?>"
                                    placeholder="e.g., Where Comfort Meets Luxury" class="sh-input">
                            </div>

                            <div class="sh-field">
                                <label>
                                    Section Title <span class="sh-req">*</span>
                                </label>
                                <input type="text" name="section_title"
                                    value="<?php echo htmlspecialchars($header['section_title']); ?>"
                                    required
                                    placeholder="e.g., Luxurious Rooms & Suites" class="sh-input">
                            </div>

                            <div class="sh-field">
                                <label>Section Description</label>
                                <textarea name="section_description" rows="3"
                                    placeholder="e.g., Experience unmatched comfort in our meticulously designed rooms and suites"
                                    class="sh-input"><?php echo htmlspecialchars($header['section_description'] ?? ''); ?></textarea>
                            </div>

                            <label class="sh-check">
                                <input type="checkbox" name="is_active" value="1"
                                    <?php echo $header['is_active'] ? 'checked' : ''; ?>>
                                <span>Active (visible on website)</span>
                            </label>

                            <div class="sh-form__foot">
                                <button type="submit" class="sh-btn sh-btn--primary">
                                    <i class="fas fa-save"></i> Save Changes
                                </button>
                                <button type="button" onclick="toggleEdit('<?php echo $sh_edit_id; ?>')"
                                    class="sh-btn sh-btn--ghost">
                                    <i class="fas fa-times"></i> Cancel
                                </button>
                            </div>
                        </form>
                    </section>
                <?php endforeach; ?>
            <?php endif; ?>

            <!-- Danger zone: Revert all section headers -->
            <section class="rh-panel sh-danger">
                <div class="rh-panel__head">
                    <h2 class="rh-panel__title"><i class="fas fa-exclamation-triangle"></i> Danger Zone</h2>
                </div>
                <div class="rh-panel__body">
                    <p class="sh-danger__text">This will delete <strong>all custom section header edits</strong> and restore the factory-default text for every section. This cannot be undone.</p>
                    <form method="post" onsubmit="return confirm('Reset ALL section headers to factory defaults?\n\nThis will permanently delete all custom changes.')">
                        <input type="hidden" name="action" value="revert_defaults">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token, ENT_QUOTES) ?>">
                        <button type="submit" class="sh-btn sh-btn--danger">
                            <i class="fas fa-undo-alt"></i> Revert All Section Headers to Defaults
                        </button>
                    </form>
                </div>
            </section>

        </div><!-- /tab-sections -->


        <!-- ===== TAB: LOADING SCREENS ===== -->
        <div id="tab-loaders" class="sh-tab-panel" style="display:none;">
            <div class="sh-toolbar">
                <p class="sh-toolbar__note">
                    The loading screen shows the site name between the two halves of your tagline, with a short message underneath that changes per page.
                    The tagline and site name come from <a href="footer-management.php?tab=settings">Site Identity</a>; the per-page message is edited here.
                </p>
            </div>

            <?php if (!$page_loaders_available): ?>
                <div class="alert alert-error">
                    <i class="fas fa-exclamation-triangle"></i>
                    The <code>page_loaders</code> table could not be created or read. Loading screens fall back to no message.
                </div>
            <?php else: ?>

            <!-- Add new loading screen -->
            <section class="rh-panel">
                <div class="rh-panel__head">
                    <h2 class="rh-panel__title">Add loading screen</h2>
                    <div class="rh-panel__actions">
                        <button type="button" class="sh-btn sh-btn--primary" onclick="toggleEdit('new-loader-form')">
                            <i class="fas fa-plus"></i> Add Loading Screen
                        </button>
                    </div>
                </div>
                <form method="POST" id="edit_new-loader-form" class="sh-form sh-edit-form">
                    <input type="hidden" name="action" value="create_loader">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token, ENT_QUOTES) ?>">
                    <div class="sh-form__grid">
                        <div class="sh-field">
                            <label>
                                Page Slug <span class="sh-req">*</span>
                                <span class="sh-hint"> — filename without <code>.php</code></span>
                            </label>
                            <input type="text" name="new_loader_slug" required placeholder="guest-services"
                                pattern="[a-z0-9\-]+" title="Lowercase letters, numbers and hyphens only" class="sh-input">
                        </div>
                        <div class="sh-field">
                            <label>Loading Message</label>
                            <input type="text" name="new_loader_subtext" maxlength="255" placeholder="e.g. Preparing your stay" class="sh-input">
                        </div>
                    </div>
                    <div class="sh-form__foot">
                        <button type="submit" class="sh-btn sh-btn--primary">
                            <i class="fas fa-save"></i> Create Loading Screen
                        </button>
                        <button type="button" class="sh-btn sh-btn--ghost" onclick="toggleEdit('new-loader-form')">
                            Cancel
                        </button>
                    </div>
                </form>
            </section>

            <?php if (empty($page_loaders_rows)): ?>
                <section class="rh-panel">
                    <p class="rh-empty">No loading screens yet. Add one above.</p>
                </section>
            <?php else: ?>
                <?php foreach ($page_loaders_rows as $pl): ?>
                <section class="rh-panel">
                    <div class="rh-panel__head">
                        <h2 class="rh-panel__title"><?php echo htmlspecialchars($pl['page_slug']); ?></h2>
                        <div class="rh-panel__actions">
                            <span class="rh-pill rh-pill--muted">Loader</span>
                            <?php if (!$pl['is_active']): ?>
                                <span class="rh-pill rh-pill--alert">Hidden</span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <form method="POST" class="sh-form">
                        <input type="hidden" name="action" value="update_loader">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token, ENT_QUOTES) ?>">
                        <input type="hidden" name="loader_id" value="<?php echo (int)$pl['id']; ?>">
                        <div class="sh-field">
                            <label>Loading Message</label>
                            <input type="text" name="loader_subtext" maxlength="255"
                                value="<?php echo htmlspecialchars($pl['subtext'] ?? ''); ?>"
                                placeholder="Shown under the site name while this page loads" class="sh-input">
                        </div>
                        <label class="sh-check">
                            <input type="checkbox" name="loader_is_active" value="1" <?php echo $pl['is_active'] ? 'checked' : ''; ?>>
                            <span>Show this message</span>
                        </label>
                        <div class="sh-form__foot">
                            <button type="submit" class="sh-btn sh-btn--primary">
                                <i class="fas fa-save"></i> Save
                            </button>
                        </div>
                    </form>
                </section>
                <?php endforeach; ?>
            <?php endif; ?>
            <?php endif; ?>
        </div><!-- /tab-loaders -->
        <!-- Style Guide -->
        <section class="rh-panel sh-style-guide">
            <div class="rh-panel__head">
                <h2 class="rh-panel__title">Section header style guide</h2>
            </div>
            <table class="rh-kv"><tbody>
                <tr>
                    <th scope="row">Section Label</th>
                    <td>Small category tag above the title. Gold, uppercase, bold, 14px. Example: "ACCOMMODATIONS"</td>
                </tr>
                <tr>
                    <th scope="row">Section Subtitle</th>
                    <td>Elegant descriptive text between label and title. Gray, italic, serif, 18px. Example: "Where Comfort Meets Luxury"</td>
                </tr>
                <tr>
                    <th scope="row">Section Title</th>
                    <td>Main heading (H2) for the section. Navy, bold, serif, 36px. Example: "Luxurious Rooms &amp; Suites"</td>
                </tr>
                <tr>
                    <th scope="row">Section Description</th>
                    <td>Supporting text below the title. Gray, regular, 16px. Example: "Experience unmatched comfort..."</td>
                </tr>
            </tbody></table>
        </section>

    </div><!-- /.content -->

    <script>
        function toggleEdit(id) {
            const form = document.getElementById('edit_' + id);
            if (!form) return;
            const nowVisible = form.style.display !== 'none' && form.style.display !== '';
            form.style.display = nowVisible ? 'none' : 'block';
            if (!nowVisible) {
                setTimeout(function() {
                    form.scrollIntoView({
                        behavior: 'smooth',
                        block: 'nearest'
                    });
                }, 50);
            }
        }

        // Tab switching
        document.querySelectorAll('.sh-tab').forEach(function(btn) {
            btn.addEventListener('click', function() {
                var target = this.dataset.tab;
                // Some in-panel buttons reuse the .sh-tab look without being tabs;
                // without this guard they would hide every panel.
                if (!target) return;
                document.querySelectorAll('.sh-tab').forEach(function(t) {
                    t.classList.toggle('sh-tab--active', t.dataset.tab === target);
                });
                document.querySelectorAll('.sh-tab-panel').forEach(function(p) {
                    p.style.display = p.id === 'tab-' + target ? 'block' : 'none';
                });
                // Preserve active tab in URL hash so page load can restore it
                history.replaceState(null, '', '#tab-' + target);
            });
        });

        // Restore tab from URL hash on load
        (function() {
            var hash = location.hash.replace('#', '');
            if (hash === 'tab-sections' || hash === 'tab-loaders') {
                var btn = document.querySelector('[data-tab="' + hash.replace('tab-', '') + '"]');
                if (btn) btn.click();
            } else {
                // heroes is default — clean the hash out of the URL bar
                if (hash) history.replaceState(null, '', location.pathname + location.search);
            }
        }());
    </script>

    <?php require_once 'includes/admin-footer.php'; ?>
</body>

</html>

