<?php
/**
 * Section Headers Management
 * Admin interface for managing dynamic section headers
 */

require_once 'admin-init.php';
require_once '../includes/alert.php';
require_once '../includes/section-headers.php';

$user = [
    'id' => $_SESSION['admin_user_id'],
    'username' => $_SESSION['admin_username'],
    'role' => $_SESSION['admin_role'],
    'full_name' => $_SESSION['admin_full_name']
];

$message = '';
$error = '';
$success = false;

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
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
            
        } elseif ($action === 'revert_defaults') {
            // Delete all current section headers
            $pdo->exec("DELETE FROM section_headers");
            
            // Re-insert all default section headers
            $defaults = [
                // Homepage sections
                ['home_rooms', 'index', 'Accommodations', 'Where Comfort Meets Luxury', 'Luxurious Rooms & Suites', 'Experience unmatched comfort in our meticulously designed rooms and suites', 1],
                ['home_facilities', 'index', 'Amenities', NULL, 'World-Class Facilities', 'Indulge in our premium facilities designed for your ultimate comfort', 2],
                ['home_testimonials', 'index', 'Reviews', NULL, 'What Our Guests Say', 'Hear from those who have experienced our exceptional hospitality', 3],
                // Hotel Gallery
                ['hotel_gallery', 'index', 'Visual Journey', 'Discover Our Story', 'Explore Our Hotel', 'Immerse yourself in the beauty and luxury of our hotel', 4],
                // Reviews (global)
                ['hotel_reviews', 'global', 'Guest Reviews', NULL, 'What Our Guests Say', 'Read authentic reviews from guests who have experienced our hospitality', 1],
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
                ['conference_overview', 'conference', 'Professional Events', 'Where Business Meets Excellence', 'Conference & Meeting Facilities', 'State-of-the-art venues for your business needs', 1],
                // Events
                ['events_overview', 'events', 'Celebrations & Gatherings', NULL, 'Upcoming Events', 'Discover our curated experiences and special occasions', 1],
                // Upcoming Events (homepage section)
                ['upcoming_events', 'index', "What's Happening", NULL, 'Upcoming Events', "Don't miss out on our carefully curated experiences and celebrations", 5]
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
                ['hotel_gallery', 'index', 'Visual Journey', 'Discover Our Story', 'Explore Our Hotel', 'Immerse yourself in the beauty and luxury of our hotel', 4],
                ['hotel_reviews', 'global', 'Guest Reviews', NULL, 'What Our Guests Say', 'Read authentic reviews from guests who have experienced our hospitality', 1],
                ['restaurant_gallery', 'restaurant', 'Visual Journey', NULL, 'Our Dining Spaces', 'From elegant interiors to breathtaking views, every detail creates the perfect ambiance', 1],
                ['restaurant_menu', 'restaurant', 'Culinary Delights', 'A Symphony of Flavors', 'Our Menu', 'Discover our carefully curated selection of dishes and beverages', 2],
                ['gym_wellness', 'gym', 'Your Wellness Journey', 'Transform Your Life', 'Start Your Fitness Journey', 'Transform your body and mind with our state-of-the-art facilities', 1],
                ['gym_facilities', 'gym', 'What We Offer', NULL, 'Comprehensive Fitness Facilities', 'Everything you need for a complete wellness experience', 2],
                ['gym_classes', 'gym', 'Stay Active', NULL, 'Group Fitness Classes', 'Join our expert-led classes designed for all fitness levels', 3],
                ['gym_training', 'gym', 'One-on-One Coaching', NULL, 'Personal Training Programs', 'Achieve your fitness goals faster with personalized guidance from our certified trainers', 4],
                ['gym_packages', 'gym', 'Exclusive Offers', NULL, 'Wellness Packages', 'Comprehensive packages designed for optimal health and relaxation', 5],
                ['rooms_collection', 'rooms-showcase', 'Stay Collection', NULL, 'Pick Your Perfect Space', 'Suites and rooms crafted for business, romance, and family stays with direct booking flows', 1],
                ['conference_overview', 'conference', 'Professional Events', 'Where Business Meets Excellence', 'Conference & Meeting Facilities', 'State-of-the-art venues for your business needs', 1],
                ['events_overview', 'events', 'Celebrations & Gatherings', NULL, 'Upcoming Events', 'Discover our curated experiences and special occasions', 1],
                // Upcoming Events (homepage section)
                ['upcoming_events', 'index', "What's Happening", NULL, 'Upcoming Events', "Don't miss out on our carefully curated experiences and celebrations", 5]
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

$current_page = 'section-headers';
$page_title = 'Section Headers Management';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($page_title); ?> - Admin Panel</title>
    
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;500;600;700&family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../css/style.css">
    <link rel="stylesheet" href="../css/theme-dynamic.php">
    <link rel="stylesheet" href="css/admin-styles.css">
    <link rel="stylesheet" href="css/admin-components.css">
    
    <style>
        .btn-small {
            transition: all 0.3s ease;
        }

        .btn-small:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(212, 175, 55, 0.3);
        }

        .header-card {
            transition: all 0.3s ease;
        }

        .header-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 8px 16px rgba(0, 0, 0, 0.15) !important;
        }

        .btn-primary:hover {
            transform: scale(1.02);
            box-shadow: 0 4px 12px rgba(212, 175, 55, 0.4);
        }

        .btn-secondary:hover {
            background: #5a6268 !important;
        }
        
        .filter-bar {
            margin-bottom: 24px;
            padding: 16px;
            background: white;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        
        .headers-grid {
            display: grid;
            gap: 20px;
        }
        
        .header-card {
            background: white;
            border-radius: 8px;
            padding: 24px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        
        .header-preview {
            padding: 20px;
            background: #f8f9fa;
            border-radius: 6px;
            margin-bottom: 20px;
        }
        
        .alert {
            padding: 16px 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 12px;
        }
        
        .alert-success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        
        .alert-info {
            background: #d1ecf1;
            color: #0c5460;
            border: 1px solid #bee5eb;
        }
        
        .alert-error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
    </style>
</head>
<body>
    <?php require_once 'includes/admin-header.php'; ?>
    
    <div class="content">
        <div class="page-header">
            <div style="display: flex; justify-content: space-between; align-items: start;">
                <div>
                    <h2 class="page-title">
                        <i class="fas fa-heading"></i> Section Headers Management
                    </h2>
                    <p class="text-muted">Manage dynamic section headers across all pages</p>
                </div>
                <form method="post" style="margin-top: 8px;" onsubmit="return confirm('Are you sure you want to reset ALL section headers to factory defaults? This will DELETE all custom changes!')">
                    <input type="hidden" name="action" value="revert_defaults">
                    <button type="submit" class="btn-secondary" style="padding: 10px 20px; background: #dc3545; color: white; border: none; border-radius: 6px; cursor: pointer; font-weight: 600; display: flex; align-items: center; gap: 8px;">
                        <i class="fas fa-undo-alt"></i> Revert All to Defaults
                    </button>
                </form>
            </div>
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

        <!-- Page Filter -->
        <div class="filter-bar">
            <div style="display: flex; align-items: center; gap: 12px; flex-wrap: wrap;">
                <label style="font-weight: 600; color: var(--navy);">Filter by Page:</label>
                <select id="pageFilter" onchange="window.location.href='section-headers-management.php?page_filter=' + this.value" 
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
                    <div style="display: flex; justify-content: space-between; align-items: start; margin-bottom: 20px;">
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
                        
                        <div style="display: flex; gap: 8px;">
                            <button onclick="toggleEdit('<?php echo htmlspecialchars($header['section_key']); ?>_<?php echo htmlspecialchars($header['page']); ?>')" 
                                    class="btn-small" style="background: var(--gold); color: white; border: none; padding: 8px 16px; border-radius: 6px; cursor: pointer; font-size: 14px;">
                                <i class="fas fa-edit"></i> Edit
                            </button>
                            
                            <form method="post" style="display: inline;" onsubmit="return confirm('Reset this section to factory default?')">
                                <input type="hidden" name="action" value="reset_single">
                                <input type="hidden" name="section_key" value="<?php echo htmlspecialchars($header['section_key']); ?>">
                                <input type="hidden" name="page" value="<?php echo htmlspecialchars($header['page']); ?>">
                                <button type="submit" class="btn-small" style="background: #6c757d; color: white; border: none; padding: 8px 16px; border-radius: 6px; cursor: pointer; font-size: 14px;">
                                    <i class="fas fa-undo"></i> Reset
                                </button>
                            </form>
                        </div>
                    </div>
                    
                    <!-- Preview -->
                    <div class="header-preview" style="padding: 20px; background: #f8f9fa; border-radius: 6px; margin-bottom: 20px;">
                        <div style="text-align: center;">
                            <?php if (!empty($header['section_label'])): ?>
                                <span style="display: inline-block; color: var(--gold); font-size: 14px; font-weight: 600; text-transform: uppercase; letter-spacing: 2px; margin-bottom: 12px;">
                                    <?php echo htmlspecialchars($header['section_label']); ?>
                                </span>
                            <?php endif; ?>
                            
                            <?php if (!empty($header['section_subtitle'])): ?>
                                <p style="font-family: 'Playfair Display', serif; font-style: italic; color: #7a7a7a; font-size: 18px; margin-bottom: 10px;">
                                    <?php echo htmlspecialchars($header['section_subtitle']); ?>
                                </p>
                            <?php endif; ?>
                            
                            <h2 style="font-family: 'Playfair Display', serif; font-size: 32px; font-weight: 700; color: var(--navy); margin-bottom: 16px;">
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
    
    <!-- Style Guide -->
    <div style="margin-top: 40px; padding: 24px; background: white; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
        <h2 style="color: var(--navy); margin-bottom: 16px;"><i class="fas fa-info-circle"></i> Section Header Style Guide</h2>
        
        <div style="display: grid; gap: 20px; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));">
            <div>
                <h4 style="color: var(--gold); margin-bottom: 8px;">Section Label</h4>
                <p style="color: #666; font-size: 14px; line-height: 1.6;">
                    Small category tag above the title.<br>
                    <strong>Style:</strong> Gold, uppercase, bold, 14px<br>
                    <strong>Example:</strong> "ACCOMMODATIONS"
                </p>
            </div>
            
            <div>
                <h4 style="color: var(--gold); margin-bottom: 8px;">Section Subtitle</h4>
                <p style="color: #666; font-size: 14px; line-height: 1.6;">
                    Elegant descriptive text between label and title.<br>
                    <strong>Style:</strong> Gray, italic, serif, 18px<br>
                    <strong>Example:</strong> "Where Comfort Meets Luxury"
                </p>
            </div>
            
            <div>
                <h4 style="color: var(--gold); margin-bottom: 8px;">Section Title</h4>
                <p style="color: #666; font-size: 14px; line-height: 1.6;">
                    Main heading (H2) for the section.<br>
                    <strong>Style:</strong> Navy, bold, serif, 36px<br>
                    <strong>Example:</strong> "Luxurious Rooms & Suites"
                </p>
            </div>
            
            <div>
                <h4 style="color: var(--gold); margin-bottom: 8px;">Section Description</h4>
                <p style="color: #666; font-size: 14px; line-height: 1.6;">
                    Supporting text below the title.<br>
                    <strong>Style:</strong> Gray, regular, 16px<br>
                    <strong>Example:</strong> "Experience unmatched comfort..."
                </p>
            </div>
        </div>
    </div>
</div>

<script>
function toggleEdit(id) {
    const form = document.getElementById('edit_' + id);
    if (form) {
        form.style.display = form.style.display === 'none' ? 'block' : 'none';
    }
}
</script>

<?php require_once 'includes/admin-footer.php'; ?>
</body>
</html>