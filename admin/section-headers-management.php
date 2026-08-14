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
    header('Location: dashboard.php?error=access_denied');
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

    <div class="content">
        <div class="page-header">
            <h2 class="page-title">
                <i class="fas fa-heading"></i> Section Headers Management
            </h2>
            <p class="text-muted">Manage live section headers and page hero text used across the frontend.</p>
        </div>

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
        <div class="sh-tabs" style="display:flex;gap:0;border-bottom:2px solid var(--gold);margin-bottom:24px;">
            <button type="button" class="sh-tab sh-tab--active" data-tab="heroes"
                style="padding:12px 28px;font-size:14px;font-weight:700;border:none;background:var(--gold);color:var(--admin-text,#1f2a37);cursor:pointer;border-radius:6px 6px 0 0;letter-spacing:.04em;">
                <i class="fas fa-image"></i> Page Hero Text
            </button>
            <button type="button" class="sh-tab" data-tab="sections"
                style="padding:12px 28px;font-size:14px;font-weight:700;border:none;background:#f0ede8;color:var(--navy);cursor:pointer;border-radius:6px 6px 0 0;letter-spacing:.04em;margin-left:4px;">
                <i class="fas fa-heading"></i> Section Headers
            </button>
            <button type="button" class="sh-tab" data-tab="loaders"
                style="padding:12px 28px;font-size:14px;font-weight:700;border:none;background:#f0ede8;color:var(--navy);cursor:pointer;border-radius:6px 6px 0 0;letter-spacing:.04em;margin-left:4px;">
                <i class="fas fa-spinner"></i> Loading Screens
            </button>
        </div>

        <!-- ===== TAB: PAGE HEROES ===== -->
        <div id="tab-heroes" class="sh-tab-panel">
            <p class="text-muted" style="margin-bottom:16px;">Edit the hero banner text (title, subtitle, description, buttons) shown at the top of each page. Images and videos are managed in <a href="media-management.php" style="color:var(--gold);">Media Portal</a>.</p>

            <!-- Quick-jump page filter for heroes -->
            <?php $heroFilter = $_GET['hero_filter'] ?? ''; ?>
            <div class="filter-bar" style="margin-bottom:20px;">
                <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
                    <label style="font-weight:600;color:var(--navy);">Jump to page:</label>
                    <select onchange="window.location.href='section-headers-management.php?hero_filter=' + this.value + '#tab-heroes'"
                        style="padding:8px 14px;border:2px solid #ddd;border-radius:6px;font-size:14px;">
                        <option value="">All Pages (<?= count($page_heroes_rows) ?>)</option>
                        <?php foreach ($page_heroes_rows as $_ph): ?>
                            <option value="<?= htmlspecialchars($_ph['page_slug']) ?>"
                                <?= $heroFilter === $_ph['page_slug'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($_ph['page_slug']) ?><?= !$_ph['is_active'] ? ' (inactive)' : '' ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?php if ($heroFilter !== ''): ?>
                        <a href="section-headers-management.php#tab-heroes" style="color:var(--gold);font-size:13px;text-decoration:none;">
                            <i class="fas fa-times"></i> Show all
                        </a>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Home hero badges -->
            <div class="card" style="margin-bottom:20px;border-left:4px solid var(--navy);">
                <button type="button" onclick="toggleEdit('hero-chips-form')"
                    style="background:var(--navy);color:white;border:none;padding:9px 18px;border-radius:6px;cursor:pointer;font-size:14px;font-weight:700;display:inline-flex;align-items:center;gap:8px;margin-bottom:0;">
                    <i class="fas fa-certificate"></i> Home Hero Badges
                </button>
                <span style="margin-left:10px;color:#777;font-size:13px;">
                    <?php
                    $chip_preview = array_values(array_filter(array_map(static fn($c) => trim($c['text']), $hero_chips), static fn($t) => $t !== ''));
                    echo $chip_preview ? htmlspecialchars(implode('  ·  ', $chip_preview)) : 'No badges shown';
                    ?>
                </span>
                <form method="POST" id="edit_hero-chips-form" style="display:none;margin-top:16px;">
                    <input type="hidden" name="action" value="update_hero_chips">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token, ENT_QUOTES) ?>">
                    <p class="text-muted" style="margin:0 0 14px;font-size:13px;">
                        The three small badges under the home page hero. They advertise the booking flow, so they appear on the home hero only.
                        Clear a label to remove that badge. Icons use <a href="https://fontawesome.com/search?o=r&m=free" target="_blank" rel="noopener" style="color:var(--gold);">Font Awesome</a> class names, e.g. <code>fa-bolt</code>.
                    </p>
                    <?php foreach ($hero_chips as $slot => $chip): ?>
                    <div style="display:grid;grid-template-columns:200px 1fr;gap:12px;margin-bottom:12px;">
                        <div>
                            <label style="display:block;font-weight:700;margin-bottom:6px;color:var(--navy);">Badge <?= (int)$slot ?> icon</label>
                            <input type="text" name="hero_chip_<?= (int)$slot ?>_icon" value="<?= htmlspecialchars($chip['icon']) ?>"
                                placeholder="fa-bolt" pattern="[A-Za-z0-9\- ]*" title="Font Awesome class, e.g. fa-bolt"
                                style="width:100%;padding:10px;border:2px solid #ddd;border-radius:6px;font-size:14px;box-sizing:border-box;">
                        </div>
                        <div>
                            <label style="display:block;font-weight:700;margin-bottom:6px;color:var(--navy);">Badge <?= (int)$slot ?> label</label>
                            <input type="text" name="hero_chip_<?= (int)$slot ?>_text" value="<?= htmlspecialchars($chip['text']) ?>"
                                maxlength="40" placeholder="e.g. Instant confirmation"
                                style="width:100%;padding:10px;border:2px solid #ddd;border-radius:6px;font-size:14px;box-sizing:border-box;">
                        </div>
                    </div>
                    <?php endforeach; ?>
                    <div style="display:flex;gap:10px;">
                        <button type="submit" style="background:var(--gold);color:white;border:none;padding:10px 20px;border-radius:6px;cursor:pointer;font-size:14px;font-weight:700;">
                            <i class="fas fa-save"></i> Save Badges
                        </button>
                        <button type="button" onclick="toggleEdit('hero-chips-form')"
                            style="background:#6c757d;color:white;border:none;padding:10px 20px;border-radius:6px;cursor:pointer;font-size:14px;">
                            Cancel
                        </button>
                    </div>
                </form>
            </div>

            <!-- Add new hero entry -->
            <div class="card" style="margin-bottom:20px;border-left:4px solid var(--gold);">
                <button type="button" onclick="toggleEdit('new-hero-form')"
                    style="background:var(--gold);color:white;border:none;padding:9px 18px;border-radius:6px;cursor:pointer;font-size:14px;font-weight:700;display:inline-flex;align-items:center;gap:8px;margin-bottom:0;">
                    <i class="fas fa-plus"></i> Add Hero for New Page
                </button>
                <form method="POST" id="edit_new-hero-form" style="display:none;margin-top:16px;">
                    <input type="hidden" name="action" value="create_hero">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token, ENT_QUOTES) ?>">
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px;">
                        <div>
                            <label style="display:block;font-weight:700;margin-bottom:6px;color:var(--navy);">
                                Page Slug <span style="color:#dc3545;">*</span>
                                <span style="color:#999;font-weight:400;font-size:12px;"> — e.g. <code>booking</code>, <code>privacy-policy</code></span>
                            </label>
                            <input type="text" name="new_page_slug" required placeholder="booking"
                                pattern="[a-z0-9\-]+" title="Lowercase letters, numbers and hyphens only"
                                style="width:100%;padding:10px;border:2px solid #ddd;border-radius:6px;font-size:14px;box-sizing:border-box;">
                        </div>
                        <div>
                            <label style="display:block;font-weight:700;margin-bottom:6px;color:var(--navy);">
                                Page URL <span style="color:#999;font-weight:400;font-size:12px;"> — e.g. /booking.php</span>
                            </label>
                            <input type="text" name="new_page_url" placeholder="/booking.php"
                                style="width:100%;padding:10px;border:2px solid #ddd;border-radius:6px;font-size:14px;box-sizing:border-box;">
                        </div>
                    </div>
                    <div style="margin-bottom:12px;">
                        <label style="display:block;font-weight:700;margin-bottom:6px;color:var(--navy);">
                            Hero Title <span style="color:#dc3545;">*</span>
                        </label>
                        <input type="text" name="new_hero_title" required placeholder="e.g. Book Your Stay"
                            style="width:100%;padding:10px;border:2px solid #ddd;border-radius:6px;font-size:14px;box-sizing:border-box;">
                    </div>
                    <div style="display:flex;gap:10px;">
                        <button type="submit" style="background:var(--gold);color:white;border:none;padding:10px 20px;border-radius:6px;cursor:pointer;font-size:14px;font-weight:700;">
                            <i class="fas fa-save"></i> Create Hero Entry
                        </button>
                        <button type="button" onclick="toggleEdit('new-hero-form')"
                            style="background:#6c757d;color:white;border:none;padding:10px 20px;border-radius:6px;cursor:pointer;font-size:14px;">
                            Cancel
                        </button>
                    </div>
                </form>
            </div>

            <div style="display:grid;gap:20px;">
                <?php foreach ($page_heroes_rows as $ph): ?>
                    <?php if ($heroFilter !== '' && $ph['page_slug'] !== $heroFilter) continue; ?>
                    <?php $hid = 'hero_' . (int)$ph['id']; ?>
                    <div style="background:white;border-radius:8px;padding:24px;box-shadow:0 2px 4px rgba(0,0,0,.1);border-left:4px solid <?php echo $ph['is_active'] ? 'var(--gold)' : '#ccc'; ?>;">
                        <div class="sh-hero-card-header">
                            <div>
                                <div style="display:flex;align-items:center;gap:8px;margin-bottom:4px;">
                                    <h3 style="margin:0;color:var(--navy);font-size:17px;"><?php echo htmlspecialchars($ph['page_slug']); ?></h3>
                                    <span style="padding:3px 10px;background:var(--navy);color:white;border-radius:10px;font-size:11px;font-weight:700;text-transform:uppercase;">HERO</span>
                                    <?php if (!$ph['is_active']): ?>
                                        <span style="padding:3px 10px;background:#dc3545;color:white;border-radius:10px;font-size:11px;font-weight:700;">INACTIVE</span>
                                    <?php endif; ?>
                                </div>
                                <p style="margin:0;color:#888;font-size:13px;"><?php echo htmlspecialchars($ph['page_url'] ?: ('/' . $ph['page_slug'] . '.php')); ?></p>
                            </div>
                        </div>

                        <!-- Edit Form (always visible — no toggle needed for hero pages) -->
                        <form method="POST" id="edit_<?php echo $hid; ?>"
                            style="padding:20px;background:#fff;border:2px solid var(--gold);border-radius:6px;">
                            <input type="hidden" name="action" value="update_hero">
                            <input type="hidden" name="hero_id" value="<?php echo (int)$ph['id']; ?>">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">

                            <div style="display:grid;gap:16px;">
                                <div>
                                    <label style="display:block;font-weight:700;margin-bottom:6px;color:var(--navy);">
                                        Hero Title <span style="color:#dc3545;">*</span>
                                        <span style="color:#999;font-weight:400;font-size:12px;"> — main H1 heading</span>
                                    </label>
                                    <input type="text" name="hero_title" required
                                        value="<?php echo htmlspecialchars($ph['hero_title']); ?>"
                                        style="width:100%;padding:10px;border:2px solid #ddd;border-radius:6px;font-size:14px;box-sizing:border-box;">
                                </div>
                                <div>
                                    <label style="display:block;font-weight:700;margin-bottom:6px;color:var(--navy);">
                                        Hero Subtitle
                                        <span style="color:#999;font-weight:400;font-size:12px;"> — italic line below title</span>
                                    </label>
                                    <input type="text" name="hero_subtitle"
                                        value="<?php echo htmlspecialchars($ph['hero_subtitle'] ?? ''); ?>"
                                        style="width:100%;padding:10px;border:2px solid #ddd;border-radius:6px;font-size:14px;box-sizing:border-box;">
                                </div>
                                <div>
                                    <label style="display:block;font-weight:700;margin-bottom:6px;color:var(--navy);">
                                        Description
                                        <span style="color:#999;font-weight:400;font-size:12px;"> — optional paragraph below subtitle</span>
                                    </label>
                                    <textarea name="hero_description" rows="3"
                                        style="width:100%;padding:10px;border:2px solid #ddd;border-radius:6px;font-size:14px;resize:vertical;box-sizing:border-box;"><?php echo htmlspecialchars($ph['hero_description'] ?? ''); ?></textarea>
                                </div>
                                <div class="sh-cta-grid">
                                    <div>
                                        <label style="display:block;font-weight:700;margin-bottom:6px;color:var(--navy);">Primary Button Text</label>
                                        <input type="text" name="primary_cta_text"
                                            value="<?php echo htmlspecialchars($ph['primary_cta_text'] ?? ''); ?>"
                                            placeholder="e.g., Book Now"
                                            style="width:100%;padding:10px;border:2px solid #ddd;border-radius:6px;font-size:14px;box-sizing:border-box;">
                                    </div>
                                    <div>
                                        <label style="display:block;font-weight:700;margin-bottom:6px;color:var(--navy);">Primary Button Link</label>
                                        <input type="text" name="primary_cta_link"
                                            value="<?php echo htmlspecialchars($ph['primary_cta_link'] ?? ''); ?>"
                                            placeholder="e.g., booking.php"
                                            style="width:100%;padding:10px;border:2px solid #ddd;border-radius:6px;font-size:14px;box-sizing:border-box;">
                                    </div>
                                </div>
                                <div>
                                    <label style="display:flex;align-items:center;gap:8px;cursor:pointer;">
                                        <input type="checkbox" name="hero_is_active" value="1"
                                            <?php echo $ph['is_active'] ? 'checked' : ''; ?>
                                            style="width:18px;height:18px;">
                                        <span style="font-weight:700;color:var(--navy);">Active (hero visible on page)</span>
                                    </label>
                                </div>
                            </div>

                            <div style="display:flex;gap:12px;margin-top:20px;padding-top:20px;border-top:1px solid #ddd;">
                                <button type="submit"
                                    style="background:var(--gold);color:white;border:none;padding:10px 20px;border-radius:6px;cursor:pointer;font-size:14px;font-weight:700;">
                                    <i class="fas fa-save"></i> Save Hero Text
                                </button>
                            </div>
                        </form>
                    </div>
                <?php endforeach; ?>
            </div>
        </div><!-- /tab-heroes -->

        <!-- ===== TAB: SECTION HEADERS ===== -->
        <div id="tab-sections" class="sh-tab-panel" style="display:none;">

            <!-- Page Filter -->
            <div class="filter-bar">
                <div style="display: flex; align-items: center; gap: 12px; flex-wrap: wrap;">
                    <label style="font-weight: 600; color: var(--navy);">Filter by Page:</label>
                    <select id="pageFilter" onchange="window.location.href='section-headers-management.php?page_filter=' + this.value + '#tab-sections'"
                        style="padding: 8px 16px; border: 2px solid #ddd; border-radius: 6px; font-size: 14px;">
                        <option value="all" <?php echo $filter_page === 'all' ? 'selected' : ''; ?>>All Pages</option>
                        <?php foreach ($pages as $page): ?>
                            <option value="<?php echo htmlspecialchars($page); ?>"
                                <?php echo $filter_page === $page ? 'selected' : ''; ?>>
                                <?php echo ucfirst(htmlspecialchars($page)); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <div style="margin-left: auto; color: #666; font-size: 14px;">
                        <i class="fas fa-info-circle"></i>
                        <strong><?php echo count($section_headers); ?></strong> section(s) found
                    </div>
                </div>
            </div>

            <!-- Section Headers Grid -->
            <div class="headers-grid" style="display: grid; gap: 20px;">
                <?php if (empty($section_headers)): ?>
                    <div style="text-align: center; padding: 60px 20px; background: white; border-radius: 8px;">
                        <i class="fas fa-inbox" style="font-size: 48px; color: #ccc; margin-bottom: 16px;"></i>
                        <p style="color: #666; font-size: 16px;">No section headers found.</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($section_headers as $header): ?>
                        <div class="header-card" style="background: white; border-radius: 8px; padding: 24px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); border-left: 4px solid <?php echo $header['is_active'] ? 'var(--gold)' : '#ccc'; ?>;">
                            <div class="sh-card-header">
                                <div>
                                    <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 8px;">
                                        <h3 style="margin: 0; color: var(--navy); font-size: 18px;">
                                            <?php echo htmlspecialchars($header['section_key']); ?>
                                        </h3>
                                        <span style="padding: 4px 12px; background: var(--navy); color: white; border-radius: 12px; font-size: 11px; text-transform: uppercase; font-weight: 600;">
                                            <?php echo htmlspecialchars($header['page']); ?>
                                        </span>
                                    </div>
                                    <div style="color: #666; font-size: 13px;">
                                        Display Order: <?php echo $header['display_order']; ?> |
                                        Status: <?php echo $header['is_active'] ? '<span style="color: #28a745;">Active</span>' : '<span style="color: #dc3545;">Inactive</span>'; ?>
                                    </div>
                                </div>

                                <div class="sh-card-actions">
                                    <button onclick="toggleEdit('<?php echo htmlspecialchars($header['section_key']); ?>_<?php echo htmlspecialchars($header['page']); ?>')"
                                        class="btn-small" style="background: var(--gold); color: white; border: none; padding: 8px 16px; border-radius: 6px; cursor: pointer; font-size: 14px;">
                                        <i class="fas fa-edit"></i> Edit
                                    </button>

                                    <form method="post" style="display: inline;" onsubmit="return confirm('Reset this section to factory default?')">
                                        <input type="hidden" name="action" value="reset_single">
                                        <input type="hidden" name="section_key" value="<?php echo htmlspecialchars($header['section_key']); ?>">
                                        <input type="hidden" name="page" value="<?php echo htmlspecialchars($header['page']); ?>">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token, ENT_QUOTES) ?>">
                                        <button type="submit" class="btn-small" style="background: #6c757d; color: white; border: none; padding: 8px 16px; border-radius: 6px; cursor: pointer; font-size: 14px;">
                                            <i class="fas fa-undo"></i> Reset
                                        </button>
                                    </form>
                                </div>
                            </div>

                            <!-- Preview -->
                            <div class="sh-section-preview" style="padding: 20px; background: #f8f9fa; border-radius: 6px; margin-bottom: 20px;">
                                <div style="text-align: center;">
                                    <?php if (!empty($header['section_label'])): ?>
                                        <span style="display: inline-block; color: var(--gold); font-size: 14px; font-weight: 600; text-transform: uppercase; letter-spacing: 2px; margin-bottom: 12px;">
                                            <?php echo htmlspecialchars($header['section_label']); ?>
                                        </span>
                                    <?php endif; ?>

                                    <?php if (!empty($header['section_subtitle'])): ?>
                                        <p style="font-family: 'Cormorant Garamond', Georgia, serif; font-style: italic; color: #7a7a7a; font-size: 18px; margin-bottom: 10px;">
                                            <?php echo htmlspecialchars($header['section_subtitle']); ?>
                                        </p>
                                    <?php endif; ?>

                                    <h2 style="font-family: 'Cormorant Garamond', Georgia, serif; font-size: 32px; font-weight: 700; color: var(--navy); margin-bottom: 16px;">
                                        <?php echo htmlspecialchars($header['section_title']); ?>
                                    </h2>

                                    <?php if (!empty($header['section_description'])): ?>
                                        <p style="color: #666; font-size: 16px; max-width: 600px; margin: 0 auto;">
                                            <?php echo htmlspecialchars($header['section_description']); ?>
                                        </p>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <!-- Edit Form (Hidden by default) -->
                            <form method="POST" id="edit_<?php echo htmlspecialchars($header['section_key']); ?>_<?php echo htmlspecialchars($header['page']); ?>"
                                style="display: none; padding: 20px; background: #fff; border: 2px solid var(--gold); border-radius: 6px;">
                                <input type="hidden" name="action" value="update_header">
                                <input type="hidden" name="section_key" value="<?php echo htmlspecialchars($header['section_key']); ?>">
                                <input type="hidden" name="page" value="<?php echo htmlspecialchars($header['page']); ?>">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token, ENT_QUOTES) ?>">

                                <div style="display: grid; gap: 16px;">
                                    <div>
                                        <label style="display: block; font-weight: 600; margin-bottom: 6px; color: var(--navy);">
                                            Section Label <span style="color: #999; font-weight: 400; font-size: 13px;">(Small uppercase tag)</span>
                                        </label>
                                        <input type="text" name="section_label"
                                            value="<?php echo htmlspecialchars($header['section_label'] ?? ''); ?>"
                                            placeholder="e.g., ACCOMMODATIONS"
                                            style="width: 100%; padding: 10px; border: 2px solid #ddd; border-radius: 6px; font-size: 14px;">
                                    </div>

                                    <div>
                                        <label style="display: block; font-weight: 600; margin-bottom: 6px; color: var(--navy);">
                                            Section Subtitle <span style="color: #999; font-weight: 400; font-size: 13px;">(Italic descriptive text)</span>
                                        </label>
                                        <input type="text" name="section_subtitle"
                                            value="<?php echo htmlspecialchars($header['section_subtitle'] ?? ''); ?>"
                                            placeholder="e.g., Where Comfort Meets Luxury"
                                            style="width: 100%; padding: 10px; border: 2px solid #ddd; border-radius: 6px; font-size: 14px;">
                                    </div>

                                    <div>
                                        <label style="display: block; font-weight: 600; margin-bottom: 6px; color: var(--navy);">
                                            Section Title <span style="color: #dc3545;">*</span>
                                        </label>
                                        <input type="text" name="section_title"
                                            value="<?php echo htmlspecialchars($header['section_title']); ?>"
                                            required
                                            placeholder="e.g., Luxurious Rooms & Suites"
                                            style="width: 100%; padding: 10px; border: 2px solid #ddd; border-radius: 6px; font-size: 14px;">
                                    </div>

                                    <div>
                                        <label style="display: block; font-weight: 600; margin-bottom: 6px; color: var(--navy);">
                                            Section Description
                                        </label>
                                        <textarea name="section_description" rows="3"
                                            placeholder="e.g., Experience unmatched comfort in our meticulously designed rooms and suites"
                                            style="width: 100%; padding: 10px; border: 2px solid #ddd; border-radius: 6px; font-size: 14px; resize: vertical;"><?php echo htmlspecialchars($header['section_description'] ?? ''); ?></textarea>
                                    </div>

                                    <div>
                                        <label style="display: flex; align-items: center; gap: 8px; cursor: pointer;">
                                            <input type="checkbox" name="is_active" value="1"
                                                <?php echo $header['is_active'] ? 'checked' : ''; ?>
                                                style="width: 18px; height: 18px;">
                                            <span style="font-weight: 600; color: var(--navy);">Active (visible on website)</span>
                                        </label>
                                    </div>
                                </div>

                                <div style="display: flex; gap: 12px; margin-top: 20px; padding-top: 20px; border-top: 1px solid #ddd;">
                                    <button type="submit" class="btn-primary" style="background: var(--gold); color: white; border: none; padding: 10px 20px; border-radius: 6px; cursor: pointer; font-size: 14px; font-weight: 600;">
                                        <i class="fas fa-save"></i> Save Changes
                                    </button>
                                    <button type="button" onclick="toggleEdit('<?php echo htmlspecialchars($header['section_key']); ?>_<?php echo htmlspecialchars($header['page']); ?>')"
                                        class="btn-secondary" style="background: #6c757d; color: white; border: none; padding: 10px 20px; border-radius: 6px; cursor: pointer; font-size: 14px;">
                                        <i class="fas fa-times"></i> Cancel
                                    </button>
                                </div>
                            </form>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <!-- Danger zone: Revert all section headers -->
            <div class="card" style="margin-top:32px;border-left:4px solid #dc3545;background:#fff9f9;">
                <h3 style="margin:0 0 8px;color:#dc3545;font-size:16px;"><i class="fas fa-exclamation-triangle"></i> Danger Zone</h3>
                <p style="margin:0 0 16px;color:#666;font-size:14px;">This will delete <strong>all custom section header edits</strong> and restore the factory-default text for every section. This cannot be undone.</p>
                <form method="post" onsubmit="return confirm('Reset ALL section headers to factory defaults?\n\nThis will permanently delete all custom changes.')">
                    <input type="hidden" name="action" value="revert_defaults">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token, ENT_QUOTES) ?>">
                    <button type="submit" style="background:#dc3545;color:white;border:none;padding:10px 20px;border-radius:6px;cursor:pointer;font-size:14px;font-weight:700;display:inline-flex;align-items:center;gap:8px;">
                        <i class="fas fa-undo-alt"></i> Revert All Section Headers to Defaults
                    </button>
                </form>
            </div>

        </div><!-- /tab-sections -->

        <!-- ===== TAB: LOADING SCREENS ===== -->
        <div id="tab-loaders" class="sh-tab-panel" style="display:none;">
            <p class="text-muted" style="margin-bottom:16px;">
                The loading screen shows the site name between the two halves of your tagline, with a short message underneath that changes per page.
                The tagline and site name come from <a href="footer-management.php?tab=settings" style="color:var(--gold);">Site Identity</a>; the per-page message is edited here.
            </p>

            <?php if (!$page_loaders_available): ?>
                <div class="alert alert-error">
                    <i class="fas fa-exclamation-triangle"></i>
                    The <code>page_loaders</code> table could not be created or read. Loading screens fall back to no message.
                </div>
            <?php else: ?>

            <!-- Add new loading screen -->
            <div class="card" style="margin-bottom:20px;border-left:4px solid var(--gold);">
                <button type="button" onclick="toggleEdit('new-loader-form')"
                    style="background:var(--gold);color:white;border:none;padding:9px 18px;border-radius:6px;cursor:pointer;font-size:14px;font-weight:700;display:inline-flex;align-items:center;gap:8px;margin-bottom:0;">
                    <i class="fas fa-plus"></i> Add Loading Screen
                </button>
                <form method="POST" id="edit_new-loader-form" style="display:none;margin-top:16px;">
                    <input type="hidden" name="action" value="create_loader">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token, ENT_QUOTES) ?>">
                    <div style="display:grid;grid-template-columns:1fr 2fr;gap:12px;margin-bottom:12px;">
                        <div>
                            <label style="display:block;font-weight:700;margin-bottom:6px;color:var(--navy);">
                                Page Slug <span style="color:#dc3545;">*</span>
                                <span style="color:#999;font-weight:400;font-size:12px;"> — filename without <code>.php</code></span>
                            </label>
                            <input type="text" name="new_loader_slug" required placeholder="guest-services"
                                pattern="[a-z0-9\-]+" title="Lowercase letters, numbers and hyphens only"
                                style="width:100%;padding:10px;border:2px solid #ddd;border-radius:6px;font-size:14px;box-sizing:border-box;">
                        </div>
                        <div>
                            <label style="display:block;font-weight:700;margin-bottom:6px;color:var(--navy);">Loading Message</label>
                            <input type="text" name="new_loader_subtext" maxlength="255" placeholder="e.g. Preparing your stay"
                                style="width:100%;padding:10px;border:2px solid #ddd;border-radius:6px;font-size:14px;box-sizing:border-box;">
                        </div>
                    </div>
                    <div style="display:flex;gap:10px;">
                        <button type="submit" style="background:var(--gold);color:white;border:none;padding:10px 20px;border-radius:6px;cursor:pointer;font-size:14px;font-weight:700;">
                            <i class="fas fa-save"></i> Create Loading Screen
                        </button>
                        <button type="button" onclick="toggleEdit('new-loader-form')"
                            style="background:#6c757d;color:white;border:none;padding:10px 20px;border-radius:6px;cursor:pointer;font-size:14px;">
                            Cancel
                        </button>
                    </div>
                </form>
            </div>

            <?php if (empty($page_loaders_rows)): ?>
                <div class="card"><p class="text-muted" style="margin:0;">No loading screens yet. Add one above.</p></div>
            <?php else: ?>
            <div style="display:grid;gap:16px;">
                <?php foreach ($page_loaders_rows as $pl): ?>
                <div style="background:white;border-radius:8px;padding:20px;box-shadow:0 2px 4px rgba(0,0,0,.1);border-left:4px solid <?php echo $pl['is_active'] ? 'var(--gold)' : '#ccc'; ?>;">
                    <form method="POST">
                        <input type="hidden" name="action" value="update_loader">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token, ENT_QUOTES) ?>">
                        <input type="hidden" name="loader_id" value="<?php echo (int)$pl['id']; ?>">
                        <div style="display:flex;align-items:center;gap:10px;margin-bottom:12px;">
                            <h3 style="margin:0;color:var(--navy);font-size:16px;"><?php echo htmlspecialchars($pl['page_slug']); ?></h3>
                            <?php if (!$pl['is_active']): ?>
                                <span style="padding:3px 10px;background:#ccc;color:#444;border-radius:10px;font-size:11px;font-weight:700;text-transform:uppercase;">Hidden</span>
                            <?php endif; ?>
                        </div>
                        <label style="display:block;font-weight:700;margin-bottom:6px;color:var(--navy);">Loading Message</label>
                        <input type="text" name="loader_subtext" maxlength="255"
                            value="<?php echo htmlspecialchars($pl['subtext'] ?? ''); ?>"
                            placeholder="Shown under the site name while this page loads"
                            style="width:100%;padding:10px;border:2px solid #ddd;border-radius:6px;font-size:14px;box-sizing:border-box;margin-bottom:12px;">
                        <div style="display:flex;align-items:center;gap:16px;flex-wrap:wrap;">
                            <label style="display:flex;align-items:center;gap:8px;font-size:14px;color:var(--navy);">
                                <input type="checkbox" name="loader_is_active" value="1" <?php echo $pl['is_active'] ? 'checked' : ''; ?>>
                                Show this message
                            </label>
                            <button type="submit" style="background:var(--gold);color:white;border:none;padding:9px 18px;border-radius:6px;cursor:pointer;font-size:14px;font-weight:700;">
                                <i class="fas fa-save"></i> Save
                            </button>
                        </div>
                    </form>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
            <?php endif; ?>
        </div><!-- /tab-loaders -->

        <!-- Style Guide -->
        <div class="card sh-style-guide">
            <h2 class="page-title sh-style-guide__heading"><i class="fas fa-info-circle"></i> Section Header Style Guide</h2>
            <div class="sh-style-guide__grid">
                <div class="sh-style-guide__item">
                    <h4 class="sh-style-guide__term">Section Label</h4>
                    <p class="sh-style-guide__desc">
                        Small category tag above the title.<br>
                        <strong>Style:</strong> Gold, uppercase, bold, 14px<br>
                        <strong>Example:</strong> "ACCOMMODATIONS"
                    </p>
                </div>
                <div class="sh-style-guide__item">
                    <h4 class="sh-style-guide__term">Section Subtitle</h4>
                    <p class="sh-style-guide__desc">
                        Elegant descriptive text between label and title.<br>
                        <strong>Style:</strong> Gray, italic, serif, 18px<br>
                        <strong>Example:</strong> "Where Comfort Meets Luxury"
                    </p>
                </div>
                <div class="sh-style-guide__item">
                    <h4 class="sh-style-guide__term">Section Title</h4>
                    <p class="sh-style-guide__desc">
                        Main heading (H2) for the section.<br>
                        <strong>Style:</strong> Navy, bold, serif, 36px<br>
                        <strong>Example:</strong> "Luxurious Rooms &amp; Suites"
                    </p>
                </div>
                <div class="sh-style-guide__item">
                    <h4 class="sh-style-guide__term">Section Description</h4>
                    <p class="sh-style-guide__desc">
                        Supporting text below the title.<br>
                        <strong>Style:</strong> Gray, regular, 16px<br>
                        <strong>Example:</strong> "Experience unmatched comfort..."
                    </p>
                </div>
            </div>
        </div>

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
                    var isActive = t.dataset.tab === target;
                    t.style.background = isActive ? 'var(--gold)' : '#f0ede8';
                    t.style.color = isActive ? 'var(--admin-text,#1f2a37)' : 'var(--navy)';
                    t.classList.toggle('sh-tab--active', isActive);
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

