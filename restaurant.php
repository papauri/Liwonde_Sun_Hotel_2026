<?php
require_once 'config/database.php';
require_once 'config/base-url.php';
require_once 'includes/page-guard.php';
require_once 'includes/section-headers.php';

// AJAX Endpoint - Handle menu data requests
if (isset($_GET['ajax']) && $_GET['ajax'] === 'menu') {
    header('Content-Type: application/json');

    $menu_type = isset($_GET['menu_type']) ? strtolower($_GET['menu_type']) : 'food';
    $currency_symbol = getSetting('currency_symbol');
    $currency_code = getSetting('currency_code');

    $response = [
        'success' => false,
        'menu_type' => $menu_type,
        'categories' => [],
        'currency' => [
            'symbol' => $currency_symbol,
            'code' => $currency_code
        ]
    ];

    try {
        if ($menu_type === 'food') {
            // Simple approach: just use food_menu table
            $stmt = $pdo->query("SELECT * FROM food_menu WHERE is_available = 1 ORDER BY category ASC, display_order ASC, id ASC");
            $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Group by category name (category column contains the name)
            foreach ($items as $item) {
                $category = $item['category'];
                $slug = strtolower(str_replace(' ', '-', $category));

                if (!isset($response['categories'][$slug])) {
                    $response['categories'][$slug] = [
                        'name' => $category,
                        'slug' => $slug,
                        'items' => []
                    ];
                }
                $response['categories'][$slug]['items'][] = [
                    'id' => $item['id'],
                    'name' => $item['item_name'],
                    'description' => $item['description'] ?? '',
                    'price' => (float)$item['price'],
                    'is_featured' => (bool)$item['is_featured'],
                    'is_vegetarian' => (bool)$item['is_vegetarian'],
                    'is_vegan' => (bool)$item['is_vegan'],
                    'allergens' => $item['allergens'] ?? ''
                ];
            }
            $response['success'] = true;

        } elseif ($menu_type === 'coffee') {
            $stmt = $pdo->query("SELECT * FROM drink_menu WHERE category = 'Coffee' ORDER BY display_order ASC, id ASC");
            $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $response['categories']['Coffee'] = [
                'name' => 'Coffee',
                'slug' => 'coffee',
                'items' => []
            ];

            foreach ($items as $item) {
                $response['categories']['Coffee']['items'][] = [
                    'id' => $item['id'],
                    'name' => $item['item_name'],
                    'description' => $item['description'] ?? '',
                    'price' => (float)$item['price'],
                    'tags' => !empty($item['tags']) ? array_map('trim', explode(',', $item['tags'])) : []
                ];
            }
            $response['success'] = true;

        } elseif ($menu_type === 'bar') {
            $stmt = $pdo->query("SELECT * FROM drink_menu WHERE category != 'Coffee' ORDER BY category, display_order ASC, id ASC");
            $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Group by category
            foreach ($items as $item) {
                $category = $item['category'];
                if (!isset($response['categories'][$category])) {
                    $response['categories'][$category] = [
                        'name' => $category,
                        'slug' => strtolower(str_replace(' ', '-', $category)),
                        'items' => []
                    ];
                }
                $response['categories'][$category]['items'][] = [
                    'id' => $item['id'],
                    'name' => $item['item_name'],
                    'description' => $item['description'] ?? '',
                    'price' => (float)$item['price'],
                    'tags' => !empty($item['tags']) ? array_map('trim', explode(',', $item['tags'])) : []
                ];
            }
            $response['success'] = true;
        }
    } catch (PDOException $e) {
        error_log("Error fetching menu: " . $e->getMessage());
        $response['error'] = 'Failed to load menu data';
    }

    echo json_encode($response);
    exit;
}

// Fetch site settings
$site_name = getSetting('site_name');
$site_logo = getSetting('site_logo');
$currency_symbol = getSetting('currency_symbol');
$currency_code = getSetting('currency_code');
// Dynamic menu page (pulls live data from DB)
$site_url = rtrim((string)getSetting('site_url', ''), '/');
if (!empty($site_url)) {
    $menu_page_url = $site_url . '/menu-pdf.php';
} else {
    $menu_host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $menu_protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $menu_page_url = $menu_protocol . '://' . $menu_host . siteUrl('menu-pdf.php');
}
$menu_qr_image = 'https://api.qrserver.com/v1/create-qr-code/?size=520x520&data=' . urlencode($menu_page_url) . '&margin=0';

// Fetch restaurant gallery
$gallery_images = [];
try {
    $stmt = $pdo->query("SELECT * FROM restaurant_gallery WHERE is_active = 1 ORDER BY display_order ASC, id ASC");
    $gallery_images = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error fetching gallery: " . $e->getMessage());
}


// Fetch policies for footer modals
$policies = [];
try {
    $policyStmt = $pdo->query("SELECT slug, title, summary, content FROM policies WHERE is_active = 1 ORDER BY display_order ASC, id ASC");
    $policies = $policyStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error fetching policies: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0, user-scalable=yes, viewport-fit=cover">
    <meta name="theme-color" content="#0A1929">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="format-detection" content="telephone=yes">
    <title>Fine Dining Restaurant - <?php echo htmlspecialchars($site_name); ?> | Gourmet Cuisine</title>
    <meta name="description" content="Experience exquisite fine dining at <?php echo htmlspecialchars($site_name); ?>. Fresh local cuisine, international dishes, craft cocktails, and premium bar service in an elegant setting.">
    <meta name="keywords" content="<?php echo htmlspecialchars(getSetting('default_keywords', 'fine dining, gourmet restaurant, international cuisine, luxury restaurant')); ?>">
    <meta name="robots" content="index, follow">
    <link rel="canonical" href="https://<?php echo $_SERVER['HTTP_HOST']; ?>/restaurant.php">
    
    <!-- Open Graph / Facebook -->
    <meta property="og:type" content="restaurant">
    <meta property="og:url" content="https://<?php echo $_SERVER['HTTP_HOST']; ?>/restaurant.php">
    <meta property="og:title" content="Fine Dining Restaurant - <?php echo htmlspecialchars($site_name); ?>">
    <meta property="og:description" content="Experience exquisite fine dining with fresh local cuisine, international dishes, and premium bar service.">
    <meta property="og:image" content="https://<?php echo $_SERVER['HTTP_HOST']; ?>/images/restaurant/hero.jpg">
    
    <!-- Twitter -->
    <meta property="twitter:card" content="summary_large_image">
    <meta property="twitter:url" content="https://<?php echo $_SERVER['HTTP_HOST']; ?>/restaurant.php">
    <meta property="twitter:title" content="Fine Dining Restaurant - <?php echo htmlspecialchars($site_name); ?>">
    <meta property="twitter:description" content="Experience exquisite fine dining with fresh local cuisine, international dishes, and premium bar service.">
    <meta property="twitter:image" content="https://<?php echo $_SERVER['HTTP_HOST']; ?>/images/restaurant/hero.jpg">
    
    <!-- Preload Critical Resources -->
    <link rel="preload" href="css/style.css" as="style">
    <link rel="preload" href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;500;600;700&family=Poppins:wght@300;400;500;600;700&display=swap" as="style">
    
    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;500;600;700&family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet" media="print" onload="this.media='all'">
    <noscript><link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;500;600;700&family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet"></noscript>
    
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" media="print" onload="this.media='all'">
    <noscript><link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css"></noscript>
    
    <!-- Custom CSS -->
    <link rel="stylesheet" href="css/theme-dynamic.php">
    <link rel="stylesheet" href="css/header.css">
    <link rel="stylesheet" href="css/style.css">
    <link rel="stylesheet" href="css/footer.css">
    
    <!-- Structured Data - Restaurant Schema -->
    <script type="application/ld+json">
    {
      "@context": "https://schema.org",
      "@type": "Restaurant",
      "name": "<?php echo htmlspecialchars($site_name); ?> Restaurant",
      "image": "https://<?php echo $_SERVER['HTTP_HOST']; ?>/images/restaurant/hero.jpg",
      "description": "Fine dining restaurant offering fresh local cuisine, international dishes, and premium bar service",
      "servesCuisine": ["International", "African", "Continental"],
      "priceRange": "$$$",
      "url": "https://<?php echo $_SERVER['HTTP_HOST']; ?>/restaurant.php"
    }
    </script>
    
    <style>
        /* Japandi Style Menu */
        :root {
            --japandi-bg: #f8f6f3;
            --japandi-card-bg: #ffffff;
            --japandi-text-primary: #2d2d2d;
            --japandi-text-secondary: #6b6b6b;
            --japandi-accent: #8b7355;
            --japandi-border: #e8e4df;
            --japandi-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
            --japandi-shadow-hover: 0 8px 24px rgba(0, 0, 0, 0.08);
        }
        
        /* Loading Spinner */
        .menu-loading {
            display: none;
            text-align: center;
            padding: 60px 20px;
        }
        .menu-loading.active {
            display: block;
        }
        .menu-loading-spinner {
            width: 50px;
            height: 50px;
            border: 3px solid rgba(139, 115, 85, 0.1);
            border-top-color: var(--japandi-accent);
            border-radius: 50%;
            animation: spin 1s linear infinite;
            margin: 0 auto 20px;
        }
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
        .menu-loading-text {
            color: var(--japandi-text-secondary);
            font-size: 1.1rem;
        }
        
        /* Menu Container */
        .menu-container {
            position: relative;
        }
        
        /* Restaurant Hero Actions */
        .restaurant-hero-actions {
            display: flex;
            justify-content: center;
            gap: 15px;
            margin-bottom: 40px;
            flex-wrap: wrap;
        }
        .restaurant-hero-actions .btn {
            min-width: 180px;
        }
        
        /* Menu Type Tabs - Premium Segmented Control */
        .menu-type-tabs {
            display: inline-flex;
            justify-content: center;
            margin: 0 auto 44px;
            background: rgba(139, 115, 85, 0.06);
            border: 1px solid var(--japandi-border);
            border-radius: 60px;
            padding: 5px;
            position: relative;
            width: auto;
        }
        /* centre the inline-flex pill within its parent */
        .menu-type-tabs-wrap {
            text-align: center;
            margin-bottom: 44px;
        }
        .menu-type-tabs-wrap .menu-type-tabs {
            margin-bottom: 0;
        }

        .menu-type-tab {
            background: transparent;
            border: none;
            color: var(--japandi-text-secondary);
            padding: 13px 30px;
            border-radius: 50px;
            cursor: pointer;
            font-family: 'Poppins', sans-serif;
            font-size: 0.88rem;
            font-weight: 500;
            letter-spacing: 0.3px;
            transition: all 0.35s cubic-bezier(.4,0,.2,1);
            display: inline-flex;
            align-items: center;
            gap: 9px;
            position: relative;
            z-index: 1;
            white-space: nowrap;
        }
        .menu-type-tab i {
            font-size: 0.92rem;
            transition: transform 0.3s ease;
        }
        .menu-type-tab:hover {
            color: var(--japandi-text-primary);
        }
        .menu-type-tab:hover i {
            transform: scale(1.12);
        }
        .menu-type-tab.active {
            background: var(--japandi-accent);
            color: #ffffff;
            box-shadow: 0 4px 14px rgba(139, 115, 85, 0.32);
        }
        .menu-type-tab.active i {
            transform: scale(1.1);
        }

        @media (max-width: 520px) {
            .menu-type-tabs {
                border-radius: 16px;
                padding: 4px;
                width: calc(100% - 8px);
            }
            .menu-type-tab {
                padding: 11px 16px;
                font-size: 0.82rem;
                flex: 1;
                justify-content: center;
                border-radius: 12px;
            }
        }
        
        /* Category Tabs - Japandi Style */
        .menu-tabs {
            display: flex;
            justify-content: center;
            gap: 6px;
            margin-bottom: 40px;
            flex-wrap: wrap;
        }
        .menu-tab {
            background: transparent;
            border: none;
            color: var(--japandi-text-secondary);
            padding: 12px 24px;
            cursor: pointer;
            font-family: 'Poppins', sans-serif;
            font-size: 0.85rem;
            font-weight: 400;
            letter-spacing: 1px;
            text-transform: uppercase;
            transition: all 0.3s ease;
            position: relative;
        }
        .menu-tab:hover {
            color: var(--japandi-text-primary);
        }
        .menu-tab.active {
            color: var(--japandi-text-primary);
            font-weight: 600;
        }
        .menu-tab.active::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 50%;
            transform: translateX(-50%);
            width: 40px;
            height: 2px;
            background: var(--japandi-accent);
        }
        
        /* Menu Categories */
        .menu-categories-wrapper {
            min-height: 400px;
        }
        .menu-category {
            display: none;
            animation: fadeIn 0.5s ease;
        }
        .menu-category.active {
            display: block;
        }
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        /* Menu Items Grid - Japandi Style */
        .menu-items-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(380px, 1fr));
            gap: 32px;
        }
        @media (max-width: 768px) {
            .menu-items-grid {
                grid-template-columns: 1fr;
                gap: 24px;
            }
        }
        
        /* Menu Item - Japandi Style */
        .menu-item {
            background: var(--japandi-card-bg);
            border: 1px solid var(--japandi-border);
            border-radius: 8px;
            padding: 32px;
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
        }
        .menu-item:hover {
            border-color: var(--japandi-accent);
            box-shadow: var(--japandi-shadow-hover);
            transform: translateY(-4px);
        }
        .menu-item.featured {
            border-color: var(--japandi-accent);
            background: linear-gradient(135deg, #ffffff 0%, #faf8f5 100%);
        }
        
        /* Featured Badge - Japandi Style */
        .featured-badge {
            position: static;
            background: var(--japandi-accent);
            color: #ffffff;
            padding: 6px 12px;
            border-radius: 2px;
            font-size: 0.7rem;
            font-weight: 600;
            letter-spacing: 1px;
            text-transform: uppercase;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            white-space: nowrap;
        }

        .menu-item-title {
            flex: 1;
            min-width: 0;
            display: flex;
            flex-direction: column;
            gap: 10px;
        }
        
        /* Menu Item Header - Japandi Style */
        .menu-item-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 16px;
            gap: 20px;
            padding-bottom: 16px;
            border-bottom: 1px solid var(--japandi-border);
        }
        .menu-item-name {
            font-family: 'Playfair Display', serif;
            font-size: 1.35rem;
            font-weight: 500;
            color: var(--japandi-text-primary);
            margin: 0;
            line-height: 1.3;
        }
        .menu-item-price {
            font-family: 'Poppins', sans-serif;
            font-size: 1.15rem;
            font-weight: 600;
            color: var(--japandi-accent);
            white-space: nowrap;
        }
        
        /* Menu Item Description - Japandi Style */
        .menu-item-description {
            color: var(--japandi-text-secondary);
            font-size: 0.95rem;
            line-height: 1.8;
            margin: 0 0 20px 0;
            font-weight: 300;
        }
        
        /* Menu Item Tags - Japandi Style */
        .menu-item-tags {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
        }
        .tag {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 12px;
            border-radius: 2px;
            font-size: 0.7rem;
            font-weight: 500;
            letter-spacing: 0.5px;
            text-transform: uppercase;
        }
        .tag-vegetarian {
            background: rgba(76, 175, 80, 0.1);
            color: #5a8f5e;
            border: 1px solid rgba(76, 175, 80, 0.2);
        }
        .tag-vegan {
            background: rgba(139, 195, 74, 0.1);
            color: #7a9f4a;
            border: 1px solid rgba(139, 195, 74, 0.2);
        }
        .tag-allergen {
            background: rgba(244, 67, 54, 0.08);
            color: #c62828;
            border: 1px solid rgba(244, 67, 54, 0.15);
        }
        .tag-drink {
            background: rgba(139, 115, 85, 0.1);
            color: var(--japandi-accent);
            border: 1px solid rgba(139, 115, 85, 0.2);
        }
        
        /* Menu CTA */
        .menu-cta {
            display: flex;
            justify-content: center;
            gap: 15px;
            margin-top: 50px;
            flex-wrap: wrap;
        }
        
        /* Empty State - Japandi Style */
        .menu-empty-state {
            text-align: center;
            padding: 80px 20px;
            color: var(--japandi-text-secondary);
        }
        .menu-empty-state i {
            font-size: 3rem;
            color: var(--japandi-accent);
            opacity: 0.3;
            margin-bottom: 24px;
        }
        .menu-empty-state h3 {
            font-family: 'Playfair Display', serif;
            font-size: 1.5rem;
            color: var(--japandi-text-primary);
            margin-bottom: 12px;
            font-weight: 500;
        }

        /* QR Menu Panel - deep black/minimal lines */
        .qr-menu-panel {
            max-width: 980px;
            margin: 0 auto 44px;
            background: linear-gradient(135deg, #0f0f0f 0%, #141414 50%, #0f0f0f 100%);
            border: 1px solid #1f1f1f;
            border-radius: 18px;
            padding: 38px;
            display: grid;
            grid-template-columns: minmax(0, 1fr) 230px;
            gap: 30px;
            box-shadow: 0 24px 50px rgba(0, 0, 0, 0.45);
        }
        .qr-menu-brand {
            display: flex;
            flex-direction: column;
            gap: 14px;
            color: #f3f3f0;
        }
        .qr-menu-mark {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            padding: 6px 16px;
            border-radius: 999px;
            background: rgba(255, 255, 255, 0.08);
            color: #fefefe;
            font-size: 0.75rem;
            letter-spacing: 0.6px;
            text-transform: uppercase;
            font-weight: 600;
            border: 1px solid rgba(255, 255, 255, 0.12);
        }
        .qr-menu-title {
            font-family: 'Playfair Display', serif;
            font-size: 1.85rem;
            margin: 0;
            color: #ffffff;
        }
        .qr-menu-desc {
            margin: 0;
            color: #c7c7c7;
            line-height: 1.8;
            font-size: 1.02rem;
            max-width: 620px;
        }
        .qr-menu-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            margin-top: 6px;
        }
        .qr-menu-actions .btn {
            min-width: 180px;
            justify-content: center;
            border-radius: 10px;
            background: #f5e6d3;
            color: #0f0f0f;
            border: 1px solid #f5e6d3;
        }
        .qr-menu-actions .btn:hover {
            background: #e8d7c0;
            border-color: #e8d7c0;
        }
        .qr-menu-actions .btn-outline {
            background: transparent;
            color: #f5e6d3;
            border: 1px solid rgba(245, 230, 211, 0.7);
        }
        .qr-menu-actions .btn-outline:hover {
            background: rgba(245, 230, 211, 0.1);
            color: #ffffff;
            border-color: #f5e6d3;
        }
        .qr-menu-meta {
            display: flex;
            align-items: center;
            gap: 12px;
            color: #a8a8a8;
            font-size: 0.95rem;
            margin-top: 2px;
            letter-spacing: 0.2px;
        }
        .qr-menu-qr {
            justify-self: end;
            text-align: center;
        }
        .qr-menu-qr img {
            width: 210px;
            height: 210px;
            border-radius: 14px;
            border: 1px solid rgba(245, 230, 211, 0.25);
            background: #0a0a0a;
            padding: 14px;
            box-shadow: 0 14px 34px rgba(0, 0, 0, 0.35);
        }
        .qr-menu-url {
            margin-top: 14px;
            font-size: 0.86rem;
            color: #d8d8d8;
            word-break: break-all;
            letter-spacing: 0.3px;
        }
        @media (max-width: 900px) {
            .qr-menu-panel {
                grid-template-columns: 1fr;
                padding: 28px;
            }
            .qr-menu-qr {
                justify-self: start;
            }
            .qr-menu-qr img {
                width: 190px;
                height: 190px;
            }
        }
    </style>
</head>
<body>
    <?php include 'includes/loader.php'; ?>
    
    <!-- Loading Animation -->
    <div class="page-loader">
        <div class="loader-content">
            <div class="luxury-spinner"></div>
            <p class="loader-text">Preparing Your Culinary Experience</p>
        </div>
    </div>
    
    <?php include 'includes/header.php'; ?>

    <main>
    <!-- Mobile Menu Overlay -->
    <div class="mobile-menu-overlay" role="presentation"></div>

    <!-- Hero Section -->
    <?php include 'includes/hero.php'; ?>

    <!-- Restaurant Gallery Grid -->
    <section class="restaurant-gallery section-padding">
        <div class="container">
            <?php renderSectionHeader('restaurant_gallery', 'restaurant', [
                'label' => 'Visual Journey',
                'title' => 'Our Dining Spaces',
                'description' => 'From elegant interiors to breathtaking views, every detail creates the perfect ambiance'
            ], 'text-center'); ?>

            <div class="gallery-grid">
                <?php if (!empty($gallery_images)): ?>
                    <?php foreach ($gallery_images as $index => $image): ?>
                        <div class="gallery-item" data-aos="fade-up" data-aos-delay="<?php echo $index * 100; ?>">
                            <img src="<?php echo htmlspecialchars($image['image_path']); ?>" alt="<?php echo htmlspecialchars($image['caption']); ?>" loading="lazy">
                            <div class="gallery-overlay">
                                <p class="gallery-caption"><?php echo htmlspecialchars($image['caption']); ?></p>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <!-- Fallback images if database is empty -->
                    <div class="gallery-item"><img src="images/restaurant/dining-area-1.jpg" alt="Elegant Dining Area" loading="lazy"><div class="gallery-overlay"><p class="gallery-caption">Elegant Dining Area</p></div></div>
                    <div class="gallery-item"><img src="images/restaurant/dining-area-2.jpg" alt="Intimate Indoor Seating" loading="lazy"><div class="gallery-overlay"><p class="gallery-caption">Intimate Indoor Seating</p></div></div>
                    <div class="gallery-item"><img src="images/restaurant/bar-area.jpg" alt="Premium Bar" loading="lazy"><div class="gallery-overlay"><p class="gallery-caption">Premium Bar</p></div></div>
                    <div class="gallery-item"><img src="images/restaurant/food-platter.jpg" alt="Fresh Seafood" loading="lazy"><div class="gallery-overlay"><p class="gallery-caption">Fresh Seafood</p></div></div>
                    <div class="gallery-item"><img src="images/restaurant/fine-dining.jpg" alt="Fine Dining Experience" loading="lazy"><div class="gallery-overlay"><p class="gallery-caption">Fine Dining Experience</p></div></div>
                    <div class="gallery-item"><img src="images/restaurant/outdoor-terrace.jpg" alt="Alfresco Terrace" loading="lazy"><div class="gallery-overlay"><p class="gallery-caption">Alfresco Terrace</p></div></div>
                <?php endif; ?>
            </div>
        </div>
    </section>

    <!-- Menu Section -->
    <section class="restaurant-menu section-padding" style="background: var(--japandi-bg);">
        <div class="container">
            <?php renderSectionHeader('restaurant_menu', 'restaurant', [
                'label' => 'Culinary Delights',
                'title' => 'Our Menu',
                'description' => 'Discover our carefully curated selection of dishes and beverages'
            ], 'text-center'); ?>

            <!-- Menu Container -->
            <div class="menu-container">
                <!-- Restaurant Hero Actions (moved here) -->
                <div class="restaurant-hero-actions">
                    <a href="#book" class="btn btn-primary"><i class="fas fa-utensils"></i> Reserve a Table</a>
                    <a href="#contact" class="btn btn-outline"><i class="fas fa-phone"></i> Call Restaurant</a>
                </div>

                <!-- QR Menu Panel -->
                <div class="qr-menu-panel" data-aos="fade-up">
                    <div class="qr-menu-brand">
                        <span class="qr-menu-mark"><i class="fas fa-qrcode"></i> Scan &amp; Dine</span>
                        <h3 class="qr-menu-title">Digital Menu</h3>
                        <p class="qr-menu-desc">Browse our full menu on your phone. Scan the QR code or tap below to view dishes, prices, and save a copy as PDF.</p>
                        <div class="qr-menu-actions">
                            <a class="btn" href="<?php echo htmlspecialchars($menu_page_url); ?>" target="_blank" rel="noopener"><i class="fas fa-external-link-alt"></i> View Menu</a>
                            <a class="btn btn-outline" href="<?php echo htmlspecialchars($menu_page_url); ?>" target="_blank" rel="noopener"><i class="fas fa-file-pdf"></i> Save as PDF</a>
                        </div>
                        <div class="qr-menu-meta">
                            <i class="fas fa-sync-alt" style="font-size:0.8rem;"></i>
                            <span>Always up to date</span>
                            <span aria-hidden="true">·</span>
                            <span>Live from our kitchen</span>
                        </div>
                    </div>
                    <div class="qr-menu-qr">
                        <img src="<?php echo $menu_qr_image; ?>" alt="QR code to view the restaurant menu" loading="lazy">
                    </div>
                </div>

                <!-- Menu Type Tabs -->
                <div class="menu-type-tabs-wrap">
                <div class="menu-type-tabs">
                    <button type="button" class="menu-type-tab active" data-type="food">
                        <i class="fas fa-utensils"></i> Food Menu
                    </button>
                    <button type="button" class="menu-type-tab" data-type="coffee">
                        <i class="fas fa-coffee"></i> Coffee
                    </button>
                    <button type="button" class="menu-type-tab" data-type="bar">
                        <i class="fas fa-glass-martini-alt"></i> Bar & Drinks
                    </button>
                </div>
                </div>

                <!-- Loading State -->
                <div class="menu-loading" id="menuLoading">
                    <div class="menu-loading-spinner"></div>
                    <p class="menu-loading-text">Loading menu...</p>
                </div>

                <!-- Menu Categories Wrapper -->
                <div class="menu-categories-wrapper" id="menuCategoriesWrapper">
                    <!-- Category Tabs -->
                    <div class="menu-tabs" id="menuTabs"></div>
                    
                    <!-- Menu Content -->
                    <div id="menuContent"></div>
                </div>
            </div>
        </div>
    </section>

    <!-- Experience Section -->
    <section class="restaurant-experience section-padding">
        <div class="container">
            <div class="experience-grid">
                <div class="experience-item" data-aos="fade-up">
                    <div class="experience-icon"><i class="fas fa-utensils"></i></div>
                    <h3>Fine Dining</h3>
                    <p>Experience culinary artistry with our carefully crafted menu featuring local Malawian flavors and international cuisine</p>
                </div>
                <div class="experience-item" data-aos="fade-up" data-aos-delay="100">
                    <div class="experience-icon"><i class="fas fa-cocktail"></i></div>
                    <h3>Premium Bar</h3>
                    <p>Enjoy handcrafted cocktails, fine wines, and premium spirits in our elegant bar lounge</p>
                </div>
                <div class="experience-item" data-aos="fade-up" data-aos-delay="200">
                    <div class="experience-icon"><i class="fas fa-fish"></i></div>
                    <h3>Fresh Local Ingredients</h3>
                    <p>We source the freshest chambo from Lake Malawi and seasonal produce from local farms</p>
                </div>
                <div class="experience-item" data-aos="fade-up" data-aos-delay="300">
                    <div class="experience-icon"><i class="fas fa-sun"></i></div>
                    <h3>Alfresco Dining</h3>
                    <p>Dine under the stars on our terrace with breathtaking views of the surrounding landscape</p>
                </div>
            </div>
        </div>
    </section>

    </main>
    <?php include 'includes/footer.php'; ?>

    <!-- Scripts -->
    <script src="js/modal.js"></script>
    <script src="js/main.js"></script>
    <script>
        // Currency settings (from PHP)
        const currencySymbol = '<?php echo $currency_symbol; ?>';
        const currencyCode = '<?php echo $currency_code; ?>';
        
        // Current menu state
        let currentMenuType = 'food';
        let currentCategory = null;
        let menuData = null;
        
        // DOM Elements
        const menuTypeTabs = document.querySelectorAll('.menu-type-tab');
        const menuTabs = document.getElementById('menuTabs');
        const menuContent = document.getElementById('menuContent');
        const menuLoading = document.getElementById('menuLoading');
        const menuCategoriesWrapper = document.getElementById('menuCategoriesWrapper');
        
        // Fetch menu data via AJAX
        async function fetchMenuData(menuType) {
            showLoading();
            
            try {
                const response = await fetch(`?ajax=menu&menu_type=${menuType}`);
                const data = await response.json();
                
                if (data.success) {
                    menuData = data;
                    renderMenu(data);
                } else {
                    showError(data.error || 'Failed to load menu');
                }
            } catch (error) {
                console.error('Error fetching menu:', error);
                showError('An error occurred while loading the menu');
            } finally {
                hideLoading();
            }
        }
        
        // Show loading state
        function showLoading() {
            menuLoading.classList.add('active');
            menuCategoriesWrapper.style.opacity = '0.5';
        }
        
        // Hide loading state
        function hideLoading() {
            menuLoading.classList.remove('active');
            menuCategoriesWrapper.style.opacity = '1';
        }
        
        // Show error state
        function showError(message) {
            menuContent.innerHTML = `
                <div class="menu-empty-state">
                    <i class="fas fa-exclamation-circle"></i>
                    <h3>Unable to Load Menu</h3>
                    <p>${message}</p>
                    <button class="btn btn-primary" onclick="fetchMenuData('${currentMenuType}')" style="margin-top: 20px;">
                        <i class="fas fa-redo"></i> Try Again
                    </button>
                </div>
            `;
            menuTabs.innerHTML = '';
        }
        
        // Render menu
        function renderMenu(data) {
            const categories = Object.values(data.categories);
            
            if (categories.length === 0) {
                menuTabs.innerHTML = '';
                menuContent.innerHTML = `
                    <div class="menu-empty-state">
                        <i class="fas fa-utensils"></i>
                        <h3>No Items Available</h3>
                        <p>Menu items for this category are coming soon. Please contact our restaurant for current offerings.</p>
                    </div>
                `;
                return;
            }
            
            // Render category tabs
            menuTabs.innerHTML = categories.map((cat, index) => `
                <button type="button" class="menu-tab ${index === 0 ? 'active' : ''}" data-category="${cat.slug}">
                    ${cat.name}
                </button>
            `).join('');
            
            // Render menu content
            menuContent.innerHTML = categories.map((cat, index) => `
                <div class="menu-category ${index === 0 ? 'active' : ''}" data-category="${cat.slug}">
                    <div class="menu-items-grid">
                        ${cat.items.map(item => renderMenuItem(item, data.menu_type)).join('')}
                    </div>
                </div>
            `).join('');
            
            // Set current category to first one
            currentCategory = categories[0].slug;
            
            // Add event listeners to category tabs
            menuTabs.querySelectorAll('.menu-tab').forEach(tab => {
                tab.addEventListener('click', function() {
                    const category = this.getAttribute('data-category');
                    switchCategory(category);
                });
            });
        }
        
        // Render single menu item
        function renderMenuItem(item, menuType) {
            if (menuType === 'food') {
                return `
                    <div class="menu-item ${item.is_featured ? 'featured' : ''}">
                        <div class="menu-item-header">
                            <div class="menu-item-title">
                                <h3 class="menu-item-name">${escapeHtml(item.name)}</h3>
                                ${item.is_featured ? '<span class="featured-badge"><i class="fas fa-star"></i> Chef\'s Special</span>' : ''}
                            </div>
                            <span class="menu-item-price">${currencySymbol}${item.price.toFixed(2)}</span>
                        </div>
                        ${item.description ? `<p class="menu-item-description">${escapeHtml(item.description)}</p>` : ''}
                        <div class="menu-item-tags">
                            ${item.is_vegetarian ? '<span class="tag tag-vegetarian"><i class="fas fa-leaf"></i> Vegetarian</span>' : ''}
                            ${item.is_vegan ? '<span class="tag tag-vegan"><i class="fas fa-seedling"></i> Vegan</span>' : ''}
                            ${item.allergens ? `<span class="tag tag-allergen"><i class="fas fa-exclamation-triangle"></i> ${escapeHtml(item.allergens)}</span>` : ''}
                        </div>
                    </div>
                `;
            }

            return `
                <div class="menu-item">
                    <div class="menu-item-header">
                        <h3 class="menu-item-name">${escapeHtml(item.name)}</h3>
                        <span class="menu-item-price">${currencySymbol}${item.price.toFixed(2)}</span>
                    </div>
                    ${item.description ? `<p class="menu-item-description">${escapeHtml(item.description)}</p>` : ''}
                    ${item.tags && item.tags.length > 0 ? `
                        <div class="menu-item-tags">
                            ${item.tags.map(tag => `<span class="tag tag-drink"><i class="fas fa-tag"></i> ${escapeHtml(tag)}</span>`).join('')}
                        </div>
                    ` : ''}
                </div>
            `;
        }
        
        // Switch category
        function switchCategory(category) {
            currentCategory = category;
            
            // Update active tab
            menuTabs.querySelectorAll('.menu-tab').forEach(tab => {
                tab.classList.toggle('active', tab.getAttribute('data-category') === category);
            });
            
            // Update active category
            menuContent.querySelectorAll('.menu-category').forEach(cat => {
                cat.classList.toggle('active', cat.getAttribute('data-category') === category);
            });
        }
        
        // Switch menu type
        function switchMenuType(menuType) {
            if (currentMenuType === menuType) return;
            
            currentMenuType = menuType;
            currentCategory = null;
            
            // Update active type tab
            menuTypeTabs.forEach(tab => {
                tab.classList.toggle('active', tab.getAttribute('data-type') === menuType);
            });
            
            // Fetch new menu data
            fetchMenuData(menuType);
        }
        
        // Escape HTML to prevent XSS
        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
        
        // Initialize menu type tabs
        menuTypeTabs.forEach(tab => {
            tab.addEventListener('click', function() {
                const menuType = this.getAttribute('data-type');
                switchMenuType(menuType);
            });
        });
        
        // Load default menu on page load
        document.addEventListener('DOMContentLoaded', function() {
            fetchMenuData('food');
        });
        
        // Page loader
        window.addEventListener('load', function() {
            const pageLoader = document.querySelector('.page-loader');
            if (pageLoader) {
                pageLoader.classList.add('fade-out');
                setTimeout(() => {
                    pageLoader.style.display = 'none';
                }, 500);
            }
        });
    </script>

    <?php include 'includes/scroll-to-top.php'; ?>
</body>
</html>
