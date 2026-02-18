<?php
/**
 * Dynamic Restaurant Menu - Print-Ready HTML
 * 
 * Dark, minimalist design inspired by modern tasting-card menus.
 * Pulls live data from food_menu and drink_menu tables.
 * Optimized for print-to-PDF and direct browser viewing.
 */
require_once 'config/database.php';

// All settings from DB – zero hardcoded values
$currency_symbol = getSetting('currency_symbol') ?: 'MWK';
$currency_code   = getSetting('currency_code')   ?: 'MWK';
$site_name       = getSetting('site_name')        ?: 'Hotel';
$site_tagline    = getSetting('restaurant_tagline', 'Fresh. Local. Inspired.');
$site_phone      = getSetting('phone', '');
$site_email      = getSetting('email', '');

// ── Fetch food menu ──
$food_categories = [];
try {
    $stmt = $pdo->query("SELECT * FROM food_menu WHERE is_available = 1 ORDER BY category ASC, display_order ASC, id ASC");
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($items as $item) {
        $cat = $item['category'];
        if (!isset($food_categories[$cat])) $food_categories[$cat] = [];
        $food_categories[$cat][] = $item;
    }
} catch (PDOException $e) {
    error_log("Menu PDF - food error: " . $e->getMessage());
}

// ── Fetch drink menu ──
$drink_categories = [];
try {
    $stmt = $pdo->query("SELECT * FROM drink_menu WHERE is_available = 1 ORDER BY category ASC, display_order ASC, id ASC");
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($items as $item) {
        $cat = $item['category'];
        if (!isset($drink_categories[$cat])) $drink_categories[$cat] = [];
        $drink_categories[$cat][] = $item;
    }
} catch (PDOException $e) {
    error_log("Menu PDF - drink error: " . $e->getMessage());
}

// Format price
function fmtPrice($price, $symbol) {
    return $symbol . ' ' . number_format((float)$price, 0, '.', ',');
}

// Preferred category order for food
$food_order = [
    'Breakfast', 'Starter', 'Chicken Corner', 'Meat Corner', 'Fish Corner',
    'Pasta Corner', 'Burger Corner', 'Pizza Corner', 'Snack Corner',
    'Indian Corner', $site_name . ' Specialities', 'Extras', 'Desserts'
];

// Preferred category order for drinks
$drink_order = [
    'Coffee', 'Non-Alcoholic', 'Cocktails', 'Desserts',
    'Beer', 'Wine', 'Whisky', 'Brandy', 'Gin', 'Vodka',
    'Rum', 'Tequila', 'Liqueur', 'Tobacco'
];

// Sort helper
function sortedCategories($categories, $order) {
    $sorted = [];
    foreach ($order as $key) {
        if (isset($categories[$key])) $sorted[$key] = $categories[$key];
    }
    // append any remaining
    foreach ($categories as $key => $val) {
        if (!isset($sorted[$key])) $sorted[$key] = $val;
    }
    return $sorted;
}

$food_categories = sortedCategories($food_categories, $food_order);
$drink_categories = sortedCategories($drink_categories, $drink_order);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo htmlspecialchars($site_name); ?> — Menu</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;0,500;0,600;1,300;1,400&family=Montserrat:wght@300;400;500;600&display=swap" rel="stylesheet">
<style>
/* ── Reset ── */
*, *::before, *::after { margin: 0; padding: 0; box-sizing: border-box; }

/* ── Page ── */
html { font-size: 14px; }
body {
    font-family: 'Montserrat', sans-serif;
    background: #0c0c0c;
    color: #e8e4df;
    line-height: 1.6;
    -webkit-font-smoothing: antialiased;
}

/* ── Print Settings ── */
@page {
    size: A4;
    margin: 18mm 20mm 18mm 20mm;
}
@media print {
    body { background: #0c0c0c !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    .no-print { display: none !important; }
    .page-break { page-break-before: always; }
    .menu-page { padding: 0; max-width: 100%; }
}

/* ── Container ── */
.menu-page {
    max-width: 800px;
    margin: 0 auto;
    padding: 40px 32px;
}

/* ── Cover / Header ── */
.menu-cover {
    text-align: center;
    padding: 60px 20px 48px;
    border-bottom: 1px solid rgba(232, 228, 223, 0.12);
    margin-bottom: 44px;
}
.menu-cover .hotel-name {
    font-family: 'Cormorant Garamond', serif;
    font-size: 2.6rem;
    font-weight: 300;
    letter-spacing: 6px;
    text-transform: uppercase;
    color: #f5e6d3;
    margin-bottom: 8px;
}
.menu-cover .menu-label {
    font-family: 'Cormorant Garamond', serif;
    font-size: 1.2rem;
    font-weight: 300;
    letter-spacing: 10px;
    text-transform: uppercase;
    color: rgba(245, 230, 211, 0.5);
    margin-bottom: 24px;
}
.menu-cover .divider {
    width: 60px;
    height: 1px;
    background: rgba(245, 230, 211, 0.3);
    margin: 0 auto 16px;
}
.menu-cover .tagline {
    font-family: 'Cormorant Garamond', serif;
    font-style: italic;
    font-size: 1rem;
    color: rgba(245, 230, 211, 0.45);
    letter-spacing: 1px;
}

/* ── Section Divider ── */
.section-divider {
    text-align: center;
    padding: 36px 0 28px;
    border-top: 1px solid rgba(232, 228, 223, 0.08);
}
.section-divider h2 {
    font-family: 'Cormorant Garamond', serif;
    font-size: 1.8rem;
    font-weight: 400;
    letter-spacing: 5px;
    text-transform: uppercase;
    color: #f5e6d3;
}
.section-divider .sub {
    font-size: 0.72rem;
    letter-spacing: 3px;
    text-transform: uppercase;
    color: rgba(245, 230, 211, 0.35);
    margin-top: 6px;
}

/* ── Category ── */
.menu-category {
    margin-bottom: 34px;
}
.category-title {
    font-family: 'Cormorant Garamond', serif;
    font-size: 1.35rem;
    font-weight: 500;
    letter-spacing: 3px;
    text-transform: uppercase;
    color: #f5e6d3;
    padding-bottom: 8px;
    border-bottom: 1px solid rgba(245, 230, 211, 0.12);
    margin-bottom: 14px;
}

/* ── Item Row ── */
.menu-item {
    display: flex;
    align-items: baseline;
    gap: 8px;
    padding: 5px 0;
}
.item-name {
    font-family: 'Montserrat', sans-serif;
    font-weight: 500;
    font-size: 0.88rem;
    color: #e8e4df;
    white-space: nowrap;
    flex-shrink: 0;
}
.item-dots {
    flex: 1;
    border-bottom: 1px dotted rgba(245, 230, 211, 0.18);
    min-width: 30px;
    margin-bottom: 4px;
}
.item-price {
    font-family: 'Montserrat', sans-serif;
    font-weight: 400;
    font-size: 0.85rem;
    color: #f5e6d3;
    white-space: nowrap;
    flex-shrink: 0;
}
.item-desc {
    font-family: 'Cormorant Garamond', serif;
    font-style: italic;
    font-size: 0.82rem;
    color: rgba(232, 228, 223, 0.45);
    line-height: 1.5;
    padding: 0 0 2px 0;
}

/* ── Footer ── */
.menu-footer {
    text-align: center;
    padding: 40px 20px 20px;
    border-top: 1px solid rgba(232, 228, 223, 0.08);
    margin-top: 30px;
}
.menu-footer p {
    font-family: 'Cormorant Garamond', serif;
    font-style: italic;
    font-size: 0.85rem;
    color: rgba(245, 230, 211, 0.35);
    letter-spacing: 1px;
    line-height: 1.8;
}
.menu-footer .currency-note {
    font-family: 'Montserrat', sans-serif;
    font-style: normal;
    font-size: 0.7rem;
    letter-spacing: 2px;
    text-transform: uppercase;
    color: rgba(245, 230, 211, 0.25);
    margin-top: 10px;
}

/* ── Print Button ── */
.print-bar {
    position: fixed;
    bottom: 0;
    left: 0;
    right: 0;
    background: rgba(12, 12, 12, 0.95);
    backdrop-filter: blur(10px);
    border-top: 1px solid rgba(245, 230, 211, 0.1);
    display: flex;
    justify-content: center;
    gap: 14px;
    padding: 14px 20px;
    z-index: 100;
}
.print-bar button,
.print-bar a {
    font-family: 'Montserrat', sans-serif;
    font-size: 0.8rem;
    font-weight: 500;
    letter-spacing: 1.5px;
    text-transform: uppercase;
    padding: 12px 32px;
    border-radius: 8px;
    cursor: pointer;
    text-decoration: none;
    transition: all 0.2s;
}
.print-bar .btn-print {
    background: #f5e6d3;
    color: #0c0c0c;
    border: 1px solid #f5e6d3;
}
.print-bar .btn-print:hover {
    background: #e8d7c0;
}
.print-bar .btn-back {
    background: transparent;
    color: #f5e6d3;
    border: 1px solid rgba(245, 230, 211, 0.3);
}
.print-bar .btn-back:hover {
    border-color: #f5e6d3;
}

/* ── Two-column layout for compact categories ── */
.two-col {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 0 40px;
}
@media (max-width: 600px) {
    .two-col { grid-template-columns: 1fr; }
    .menu-cover .hotel-name { font-size: 1.8rem; letter-spacing: 4px; }
    .menu-page { padding: 20px 16px; }
}
</style>
</head>
<body>

<!-- Print / Back Bar -->
<div class="print-bar no-print">
    <button class="btn-print" onclick="window.print()">Save as PDF</button>
    <a class="btn-back" href="restaurant.php">Back to Restaurant</a>
</div>

<div class="menu-page">

    <!-- ═══ COVER ═══ -->
    <div class="menu-cover">
        <div class="hotel-name"><?php echo htmlspecialchars($site_name); ?></div>
        <div class="menu-label">Menu</div>
        <div class="divider"></div>
        <div class="tagline"><?php echo htmlspecialchars($site_tagline); ?></div>
    </div>

    <!-- ═══ FOOD MENU ═══ -->
    <div class="section-divider">
        <h2>Dining</h2>
        <div class="sub">From our kitchen to your table</div>
    </div>

    <?php foreach ($food_categories as $category => $items): ?>
    <div class="menu-category">
        <div class="category-title"><?php echo htmlspecialchars($category); ?></div>
        <?php foreach ($items as $item): ?>
            <?php if (!empty($item['description']) && strlen($item['description']) > 5): ?>
                <div class="item-desc"><?php echo htmlspecialchars($item['description']); ?></div>
            <?php endif; ?>
            <div class="menu-item">
                <span class="item-name"><?php echo htmlspecialchars($item['item_name']); ?></span>
                <span class="item-dots"></span>
                <span class="item-price"><?php echo fmtPrice($item['price'], $currency_symbol); ?></span>
            </div>
        <?php endforeach; ?>
    </div>
    <?php endforeach; ?>

    <!-- Page break before drinks -->
    <div class="page-break"></div>

    <!-- ═══ DRINKS MENU ═══ -->
    <div class="section-divider">
        <h2>Beverages</h2>
        <div class="sub">Crafted pours &amp; refreshments</div>
    </div>

    <?php foreach ($drink_categories as $category => $items): ?>
    <div class="menu-category">
        <div class="category-title"><?php echo htmlspecialchars($category); ?></div>
        <?php foreach ($items as $item): ?>
            <div class="menu-item">
                <span class="item-name"><?php echo htmlspecialchars($item['item_name']); ?></span>
                <span class="item-dots"></span>
                <span class="item-price"><?php echo fmtPrice($item['price'], $currency_symbol); ?></span>
            </div>
        <?php endforeach; ?>
    </div>
    <?php endforeach; ?>

    <!-- ═══ FOOTER ═══ -->
    <div class="menu-footer">
        <p>All prices are inclusive of applicable taxes.<br>
        Menu items are subject to availability.<br>
        Please inform your server of any dietary requirements or allergies.</p>
        <div class="currency-note">All prices in <?php echo htmlspecialchars($currency_code); ?></div>
        <?php if (!empty($site_phone) || !empty($site_email)): ?>
        <div class="currency-note" style="margin-top:6px;">
            <?php if (!empty($site_phone)): ?><?php echo htmlspecialchars($site_phone); ?><?php endif; ?>
            <?php if (!empty($site_phone) && !empty($site_email)): ?> &middot; <?php endif; ?>
            <?php if (!empty($site_email)): ?><?php echo htmlspecialchars($site_email); ?><?php endif; ?>
        </div>
        <?php endif; ?>
    </div>

</div>

</body>
</html>
