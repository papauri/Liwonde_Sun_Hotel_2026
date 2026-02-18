<?php
// Include admin initialization (PHP-only, no HTML output)
require_once 'admin-init.php';
require_once '../includes/alert.php';

$user = [
    'id' => $_SESSION['admin_user_id'],
    'username' => $_SESSION['admin_username'],
    'role' => $_SESSION['admin_role'],
    'full_name' => $_SESSION['admin_full_name']
];
$message = '';
$error = '';
$current_page = basename($_SERVER['PHP_SELF']);
$current_tab = $_GET['tab'] ?? 'food';

// Handle menu item actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $action = $_POST['action'] ?? '';
        $menu_type = $_POST['menu_type'] ?? 'food';

        if ($action === 'add') {
            if ($menu_type === 'food') {
                // Add new food item - auto-increment display_order if not specified
                $category = $_POST['category'];
                $display_order = isset($_POST['display_order']) && $_POST['display_order'] !== '' ? (int)$_POST['display_order'] : null;
                
                if ($display_order === null) {
                    // Get next available display_order for this category
                    $stmt = $pdo->prepare("SELECT COALESCE(MAX(display_order), 0) + 1 as next_order FROM food_menu WHERE category = ?");
                    $stmt->execute([$category]);
                    $result = $stmt->fetch(PDO::FETCH_ASSOC);
                    $display_order = $result['next_order'];
                }
                
                $stmt = $pdo->prepare("
                    INSERT INTO food_menu (item_name, description, price, category, is_available, display_order)
                    VALUES (?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $_POST['name'],
                    $_POST['description'],
                    $_POST['price'],
                    $category,
                    isset($_POST['is_available']) ? 1 : 0,
                    $display_order
                ]);
            } else {
                // Add new drink item - auto-increment item_order if not specified
                $category = $_POST['category'];
                $item_order = isset($_POST['item_order']) && $_POST['item_order'] !== '' ? (int)$_POST['item_order'] : null;
                
                if ($item_order === null) {
                    // Get next available item_order for this category
                    $stmt = $pdo->prepare("SELECT COALESCE(MAX(display_order), 0) + 1 as next_order FROM drink_menu WHERE category = ?");
                    $stmt->execute([$category]);
                    $result = $stmt->fetch(PDO::FETCH_ASSOC);
                    $item_order = $result['next_order'];
                }
                
                $stmt = $pdo->prepare("
                    INSERT INTO drink_menu (item_name, description, price, category, is_available, display_order, tags)
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $_POST['name'],
                    $_POST['description'],
                    $_POST['price'],
                    $category,
                    isset($_POST['is_available']) ? 1 : 0,
                    $item_order,
                    $_POST['tags'] ?? ''
                ]);
            }
            $message = 'Menu item added successfully!';

        } elseif ($action === 'update') {
            if ($menu_type === 'food') {
                // Update existing food item
                $stmt = $pdo->prepare("
                    UPDATE food_menu
                    SET item_name = ?, description = ?, price = ?, category = ?, is_available = ?, display_order = ?
                    WHERE id = ?
                ");
                $stmt->execute([
                    $_POST['name'],
                    $_POST['description'],
                    $_POST['price'],
                    $_POST['category'],
                    $_POST['is_available'] ?? 1,
                    $_POST['display_order'] ?? 0,
                    $_POST['id']
                ]);
            } else {
                // Update existing drink item
                $stmt = $pdo->prepare("
                    UPDATE drink_menu
                    SET item_name = ?, description = ?, price = ?, category = ?, is_available = ?, display_order = ?, tags = ?
                    WHERE id = ?
                ");
                $stmt->execute([
                    $_POST['name'],
                    $_POST['description'],
                    $_POST['price'],
                    $_POST['category'],
                    $_POST['is_available'] ?? 1,
                    $_POST['display_order'] ?? 0,
                    $_POST['tags'] ?? '',
                    $_POST['id']
                ]);
            }
            $message = 'Menu item updated successfully!';

        } elseif ($action === 'delete') {
            $table = $_POST['menu_type'] === 'food' ? 'food_menu' : 'drink_menu';
            $stmt = $pdo->prepare("DELETE FROM $table WHERE id = ?");
            $stmt->execute([$_POST['id']]);
            $message = 'Menu item deleted successfully!';

        } elseif ($action === 'toggle_availability') {
            $table = $_POST['menu_type'] === 'food' ? 'food_menu' : 'drink_menu';
            $field = 'is_available';
            $stmt = $pdo->prepare("UPDATE $table SET $field = NOT $field WHERE id = ?");
            $stmt->execute([$_POST['id']]);
            $message = 'Menu item availability updated!';
        }

    } catch (PDOException $e) {
        $error = 'Error: ' . $e->getMessage();
    }
}

// Fetch all menu items grouped by category
try {
    // Simple approach: just use food_menu table
    $stmt = $pdo->query("
        SELECT * FROM food_menu
        ORDER BY category ASC, display_order ASC, item_name ASC
    ");
    $food_items = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Fetch drink items from drink_menu
    $stmt = $pdo->query("
        SELECT * FROM drink_menu
        ORDER BY category, display_order ASC, item_name ASC
    ");
    $drink_items = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Group food items by category
    $grouped_food = [];
    $food_categories = [];
    foreach ($food_items as $item) {
        $grouped_food[$item['category']][] = $item;
        if (!in_array($item['category'], $food_categories)) {
            $food_categories[] = $item['category'];
        }
    }
    sort($food_categories);

    // Group drink items by category
    $grouped_drinks = [];
    $drink_categories = [];
    foreach ($drink_items as $item) {
        $grouped_drinks[$item['category']][] = $item;
        if (!in_array($item['category'], $drink_categories)) {
            $drink_categories[] = $item['category'];
        }
    }
    sort($drink_categories);

} catch (PDOException $e) {
    $error = 'Error fetching menu items: ' . $e->getMessage();
    $food_categories = [];
    $grouped_food = [];
    $grouped_drinks = [];
    $drink_categories = [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Menu Management - Admin Panel</title>
    
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;500;600;700&family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../css/style.css">
    <link rel="stylesheet" href="../css/theme-dynamic.php">
    <link rel="stylesheet" href="css/admin-styles.css">
    <link rel="stylesheet" href="css/admin-components.css">
    
    <style>
        /* Menu management specific styles - unique to this page */
        .btn-add {
            background: var(--gold);
            color: var(--deep-navy);
            padding: 12px 24px;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s ease;
        }
        .btn-add:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(212, 175, 55, 0.3);
        }
        .menu-type-tabs {
            display: flex;
            gap: 16px;
            margin-bottom: 24px;
            border-bottom: 2px solid #e0e0e0;
            padding-bottom: 0;
        }
        .menu-type-tab {
            padding: 12px 24px;
            background: transparent;
            border: none;
            border-bottom: 3px solid transparent;
            font-size: 16px;
            font-weight: 600;
            color: #666;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        .menu-type-tab:hover {
            color: var(--gold);
        }
        .menu-type-tab.active {
            color: var(--gold);
            border-bottom-color: var(--gold);
        }
        .menu-type-tab i {
            margin-right: 8px;
        }
        .category-section {
            background: white;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 24px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
            overflow-x: auto;
        }
        .category-header {
            font-size: 20px;
            font-weight: 700;
            color: var(--navy);
            margin-bottom: 16px;
            padding-bottom: 12px;
            border-bottom: 2px solid var(--gold);
        }
        .menu-table {
            width: 100%;
            min-width: 1400px;
            border-collapse: collapse;
            border: 1px solid #d0d7de;
        }
        .menu-table th {
            background: #f6f8fa;
            padding: 12px 14px;
            text-align: left;
            font-size: 12px;
            font-weight: 600;
            color: #24292f;
            text-transform: uppercase;
            border: 1px solid #d0d7de;
            border-bottom: 2px solid #d0d7de;
            white-space: nowrap;
        }
        .menu-table td {
            padding: 0;
            border: 1px solid #d0d7de;
            vertical-align: middle;
            background: white;
        }
        .menu-table tbody tr {
            transition: background 0.2s ease;
        }
        .menu-table tbody tr:hover {
            background: #f6f8fa;
        }
        .menu-table tbody tr.edit-mode {
            background: #fff8c7;
        }
        .menu-table tbody tr.edit-mode td {
            background: #fff8c7;
        }
        .menu-table input,
        .menu-table textarea,
        .menu-table select {
            width: 100%;
            height: 100%;
            min-height: 50px;
            padding: 10px 14px;
            border: none;
            border-radius: 0;
            font-size: 14px;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', 'Helvetica Neue', Arial, sans-serif;
            background: transparent;
            transition: background 0.2s ease;
        }
        .menu-table input:focus,
        .menu-table textarea:focus,
        .menu-table select:focus {
            outline: none;
            background: #fff8c7;
            box-shadow: inset 0 0 0 2px var(--gold);
        }
        .menu-table textarea {
            resize: none;
            min-height: 80px;
            line-height: 1.5;
        }
        .menu-table select {
            cursor: pointer;
            appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%23666' d='M6 8L1 3h10z'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 8px center;
            padding-right: 28px;
        }
        tr.editing {
            background: rgba(212, 175, 55, 0.05);
            box-shadow: 0 0 0 2px rgba(212, 175, 55, 0.2);
        }
        tr.editing input,
        tr.editing textarea,
        tr.editing select {
            border-color: var(--gold);
            background: white;
        }
        .cell-view {
            display: block;
            padding: 12px 14px;
            min-height: 50px;
        }
        .cell-view.hidden {
            display: none;
        }
        .cell-edit {
            display: none;
        }
        .cell-edit.active {
            display: block;
        }
        .cell-edit.active input,
        .cell-edit.active textarea,
        .cell-edit.active select {
            display: block;
        }
        .actions-cell {
            white-space: nowrap;
            min-width: 280px;
            padding: 8px 12px !important;
        }
        .action-buttons {
            display: flex;
            gap: 6px;
            flex-wrap: wrap;
            align-items: center;
        }
        .badge-available {
            background: #28a745;
            color: white;
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
        }
        .badge-unavailable {
            background: #dc3545;
            color: white;
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
        }
        .btn-action {
            padding: 6px 14px;
            border: none;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            white-space: nowrap;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        .btn-action i {
            font-size: 11px;
        }
        .btn-action:hover {
            transform: translateY(-1px);
            box-shadow: 0 2px 6px rgba(0, 0, 0, 0.2);
        }
        .btn-edit {
            background: #17a2b8;
            color: white;
        }
        .btn-edit:hover {
            background: #138496;
            box-shadow: 0 2px 6px rgba(23, 162, 184, 0.3);
        }
        .btn-save {
            background: #28a745;
            color: white;
        }
        .btn-save:hover {
            background: #218838;
            box-shadow: 0 2px 6px rgba(40, 167, 69, 0.3);
        }
        .btn-cancel {
            background: #6c757d;
            color: white;
        }
        .btn-cancel:hover {
            background: #5a6268;
            box-shadow: 0 2px 6px rgba(108, 117, 125, 0.3);
        }
        .btn-delete {
            background: #dc3545;
            color: white;
        }
        .btn-delete:hover {
            background: #c82333;
            box-shadow: 0 2px 6px rgba(220, 53, 69, 0.3);
        }
        .btn-toggle {
            background: #ffc107;
            color: #212529;
        }
        .btn-toggle:hover {
            background: #e0a800;
            box-shadow: 0 2px 6px rgba(255, 193, 7, 0.3);
        }
        .btn-toggle.active {
            background: #28a745;
            color: white;
        }
        .btn-toggle.active:hover {
            background: #218838;
            box-shadow: 0 2px 6px rgba(40, 167, 69, 0.3);
        }
        .edit-mode {
            background: #fff3cd !important;
        }
        .empty-state {
            text-align: center;
            padding: 40px;
            color: #999;
        }
        .empty-state i {
            font-size: 48px;
            margin-bottom: 16px;
            color: #ddd;
        }
        .tab-content {
            display: none;
        }
        .tab-content.active {
            display: block;
        }
        @media (max-width: 768px) {
            .content {
                padding: 16px;
            }
            .page-header {
                flex-direction: column;
                gap: 16px;
                align-items: flex-start;
            }
            .btn-add {
                width: 100%;
                justify-content: center;
            }
            .menu-table {
                font-size: 12px;
            }
            .menu-table th,
            .menu-table td {
                padding: 0;
            }
            .cell-view {
                padding: 8px 10px;
                min-height: 40px;
            }
            .menu-table th {
                font-size: 11px;
            }
            .menu-table input,
            .menu-table textarea,
            .menu-table select {
                padding: 8px 10px;
                font-size: 13px;
            }
            .action-buttons {
                flex-direction: column;
                gap: 4px;
            }
            .btn-action {
                padding: 6px 12px;
                font-size: 11px;
                width: 100%;
                text-align: center;
                justify-content: center;
            }
            .category-section {
                padding: 12px;
                overflow-x: auto;
            }
            .menu-table {
                min-width: 1200px;
            }
            .category-header {
                font-size: 16px;
                margin-bottom: 12px;
            }
            .menu-type-tabs {
                flex-wrap: wrap;
            }
            .menu-type-tab {
                padding: 10px 16px;
                font-size: 14px;
            }
        }
        @media (max-width: 480px) {
            .content {
                padding: 12px;
            }
            .menu-table {
                font-size: 11px;
            }
            .menu-table th,
            .menu-table td {
                padding: 0;
            }
            .cell-view {
                padding: 6px 8px;
                min-height: 36px;
            }
            .menu-table th {
                font-size: 10px;
            }
            .btn-action {
                padding: 5px 10px;
                font-size: 10px;
            }
        }
        /* Modal form controls specific to menu management */
        .form-group input,
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 6px;
            font-size: 14px;
        }
        .form-group textarea {
            resize: vertical;
            min-height: 80px;
        }
        .checkbox-group {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .checkbox-group input {
            width: auto;
        }
        .modal-actions {
            display: flex;
            gap: 12px;
            justify-content: flex-end;
            margin-top: 24px;
        }
    </style>
</head>
<body>
    <?php require_once 'includes/admin-header.php'; ?>
    
    <div class="content">
        <div class="page-header">
            <h2 class="page-title">Menu Management</h2>
        </div>
        
        <?php if ($message): ?>
            <?php showAlert($message, 'success'); ?>
        <?php endif; ?>

        <?php if ($error): ?>
            <?php showAlert($error, 'error'); ?>
        <?php endif; ?>
        
        <!-- Menu Type Tabs -->
        <div class="menu-type-tabs">
            <button class="menu-type-tab <?php echo $current_tab === 'food' ? 'active' : ''; ?>" onclick="switchTab('food')">
                <i class="fas fa-utensils"></i> Food Menu
            </button>
            <button class="menu-type-tab <?php echo $current_tab === 'drinks' ? 'active' : ''; ?>" onclick="switchTab('drinks')">
                <i class="fas fa-glass-martini-alt"></i> Drinks Menu
            </button>
        </div>
        
        <!-- Food Menu Tab Content -->
        <div class="tab-content <?php echo $current_tab === 'food' ? 'active' : ''; ?>" id="food-tab">
            <div class="page-header">
                <h3 class="page-title">Food Items</h3>
                <button class="btn-add" onclick="openAddModal('food')">
                    <i class="fas fa-plus"></i> Add Food Item
                </button>
            </div>
            
            <?php foreach ($food_categories as $category): ?>
                <div class="category-section">
                    <h3 class="category-header" style="display: flex; justify-content: space-between; align-items: center;">
                        <span>
                            <i class="fas fa-<?php 
                                echo $category === 'Breakfast' ? 'coffee' : 
                                    ($category === 'Lunch' ? 'hamburger' : 
                                    ($category === 'Dinner' ? 'drumstick-bite' : 'utensils')); 
                            ?>"></i>
                            <?php echo $category; ?>
                            <?php if (isset($grouped_food[$category])): ?>
                                <span style="font-size: 14px; font-weight: normal; color: #666;">
                                    (<?php echo count($grouped_food[$category]); ?> items)
                                </span>
                            <?php endif; ?>
                        </span>
                        <button class="btn-add" onclick="openAddModal('food', '<?php echo htmlspecialchars($category); ?>')" style="font-size: 12px; padding: 8px 16px;">
                            <i class="fas fa-plus"></i> Add Item
                        </button>
                    </h3>
                    
                    <?php if (isset($grouped_food[$category]) && !empty($grouped_food[$category])): ?>
                        <div class="table-responsive">
                            <table class="menu-table">
                            <thead>
                                <tr>
                                    <th style="width: 100px;">Order</th>
                                    <th style="width: 250px;">Item Name</th>
                                    <th style="width: 350px;">Description</th>
                                    <th style="width: 150px;">Price (<?php echo htmlspecialchars(getSetting('currency_symbol')); ?>)</th>
                                    <th style="width: 150px;">Status</th>
                                    <th style="width: 300px;">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($grouped_food[$category] as $item): ?>
                                    <tr id="food-row-<?php echo $item['id']; ?>" data-category="<?php echo htmlspecialchars($item['category']); ?>">
                                        <td>
                                            <input type="number" value="<?php echo $item['display_order']; ?>" data-field="display_order">
                                        </td>
                                        <td>
                                            <input type="text" value="<?php echo htmlspecialchars($item['item_name']); ?>" data-field="name">
                                        </td>
                                        <td>
                                            <textarea data-field="description"><?php echo htmlspecialchars($item['description'] ?? ''); ?></textarea>
                                        </td>
                                        <td>
                                            <input type="number" value="<?php echo $item['price']; ?>" step="0.01" data-field="price">
                                        </td>
                                        <td>
                                            <select data-field="is_available">
                                                <option value="1" <?php echo $item['is_available'] ? 'selected' : ''; ?>>Available</option>
                                                <option value="0" <?php echo !$item['is_available'] ? 'selected' : ''; ?>>Unavailable</option>
                                            </select>
                                        </td>
                                        <td class="actions-cell">
                                            <div class="action-buttons">
                                                <button class="btn-action btn-save"
                                                        onclick="saveRow(<?php echo $item['id']; ?>, '<?php echo htmlspecialchars($item['category']); ?>', 'food')"
                                                        title="Save Changes">
                                                    <i class="fas fa-save"></i> Save
                                                </button>
                                                <button class="btn-action btn-toggle <?php echo $item['is_available'] ? 'active' : ''; ?>"
                                                        onclick="quickToggle(<?php echo $item['id']; ?>, 'food')"
                                                        title="<?php echo $item['is_available'] ? 'Mark as Unavailable' : 'Mark as Available'; ?>">
                                                    <i class="fas fa-toggle-<?php echo $item['is_available'] ? 'on' : 'off'; ?>"></i> Toggle
                                                </button>
                                                <button class="btn-action btn-delete"
                                                        onclick="if(confirm('Delete this menu item?')) deleteRow(<?php echo $item['id']; ?>, 'food')"
                                                        title="Delete Item">
                                                    <i class="fas fa-trash-alt"></i> Delete
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="fas fa-inbox"></i>
                            <p>No items in this category yet. Click "Add Food Item" to get started.</p>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
        
        <!-- Drinks Menu Tab Content -->
        <div class="tab-content <?php echo $current_tab === 'drinks' ? 'active' : ''; ?>" id="drinks-tab">
            <div class="page-header">
                <h3 class="page-title">Drinks Items</h3>
                <button class="btn-add" onclick="openAddModal('drinks')">
                    <i class="fas fa-plus"></i> Add Drink Item
                </button>
            </div>
            
            <?php foreach ($drink_categories as $category): ?>
                <div class="category-section">
                    <h3 class="category-header" style="display: flex; justify-content: space-between; align-items: center;">
                        <span>
                            <i class="fas fa-<?php 
                                echo $category === 'Coffee' ? 'coffee' : 
                                    ($category === 'Wine' ? 'wine-bottle' : 
                                    ($category === 'Cocktails' ? 'glass-martini-alt' : 
                                    ($category === 'Beer' ? 'beer' : 'glass-martini-alt'))); 
                            ?>"></i>
                            <?php echo $category; ?>
                            <?php if (isset($grouped_drinks[$category])): ?>
                                <span style="font-size: 14px; font-weight: normal; color: #666;">
                                    (<?php echo count($grouped_drinks[$category]); ?> items)
                                </span>
                            <?php endif; ?>
                        </span>
                        <button class="btn-add" onclick="openAddModal('drinks', '<?php echo htmlspecialchars($category); ?>')" style="font-size: 12px; padding: 8px 16px;">
                            <i class="fas fa-plus"></i> Add Item
                        </button>
                    </h3>
                    
                    <?php if (isset($grouped_drinks[$category]) && !empty($grouped_drinks[$category])): ?>
                        <div class="table-responsive">
                            <table class="menu-table">
                            <thead>
                                <tr>
                                    <th style="width: 100px;">Order</th>
                                    <th style="width: 250px;">Item Name</th>
                                    <th style="width: 300px;">Description</th>
                                    <th style="width: 150px;">Price (<?php echo htmlspecialchars(getSetting('currency_symbol')); ?>)</th>
                                    <th style="width: 150px;">Tags</th>
                                    <th style="width: 150px;">Status</th>
                                    <th style="width: 300px;">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($grouped_drinks[$category] as $item): ?>
                                    <tr id="drink-row-<?php echo $item['id']; ?>" data-category="<?php echo htmlspecialchars($item['category']); ?>">
                                        <td>
                                            <input type="number" value="<?php echo $item['display_order']; ?>" data-field="display_order">
                                        </td>
                                        <td>
                                            <input type="text" value="<?php echo htmlspecialchars($item['item_name']); ?>" data-field="name">
                                        </td>
                                        <td>
                                            <textarea data-field="description"><?php echo htmlspecialchars($item['description'] ?? ''); ?></textarea>
                                        </td>
                                        <td>
                                            <input type="number" value="<?php echo $item['price']; ?>" step="0.01" data-field="price">
                                        </td>
                                        <td>
                                            <input type="text" value="<?php echo htmlspecialchars($item['tags'] ?? ''); ?>" data-field="tags" placeholder="e.g., Hot, Cold, Premium">
                                        </td>
                                        <td>
                                            <select data-field="is_available">
                                                <option value="1" <?php echo $item['is_available'] ? 'selected' : ''; ?>>Available</option>
                                                <option value="0" <?php echo !$item['is_available'] ? 'selected' : ''; ?>>Unavailable</option>
                                            </select>
                                        </td>
                                        <td class="actions-cell">
                                            <div class="action-buttons">
                                                <button class="btn-action btn-save"
                                                        onclick="saveRow(<?php echo $item['id']; ?>, '<?php echo htmlspecialchars($item['category']); ?>', 'drinks')"
                                                        title="Save Changes">
                                                    <i class="fas fa-save"></i> Save
                                                </button>
                                                <button class="btn-action btn-toggle <?php echo $item['is_available'] ? 'active' : ''; ?>"
                                                        onclick="quickToggle(<?php echo $item['id']; ?>, 'drinks')"
                                                        title="<?php echo $item['is_available'] ? 'Mark as Unavailable' : 'Mark as Available'; ?>">
                                                    <i class="fas fa-toggle-<?php echo $item['is_available'] ? 'on' : 'off'; ?>"></i> Toggle
                                                </button>
                                                <button class="btn-action btn-delete"
                                                        onclick="if(confirm('Delete this menu item?')) deleteRow(<?php echo $item['id']; ?>, 'drinks')"
                                                        title="Delete Item">
                                                    <i class="fas fa-trash-alt"></i> Delete
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="fas fa-inbox"></i>
                            <p>No items in this category yet. Click "Add Drink Item" to get started.</p>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
    
    <!-- Add Menu Item Modal -->
    <div class="modal" id="addMenuModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.7); z-index: 10000; align-items: center; justify-content: center;">
        <div style="background: white; border-radius: 12px; padding: 32px; max-width: 600px; width: 90%; max-height: 90vh; overflow-y: auto;">
            <div style="font-size: 24px; font-weight: 700; color: var(--navy); margin-bottom: 24px; display: flex; justify-content: space-between; align-items: center;">
                <span id="modal-title">Add New Menu Item</span>
                <span onclick="closeAddModal()" style="cursor: pointer; font-size: 28px; color: #999;">&times;</span>
            </div>
            
            <form method="POST">
                <input type="hidden" name="action" value="add">
                <input type="hidden" name="menu_type" id="menu_type" value="food">
                
                <div style="margin-bottom: 20px;">
                    <label style="display: block; margin-bottom: 8px; font-weight: 600;">Category *</label>
                    <select name="category" id="add_category" required style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 6px;">
                        <option value="">Select Category</option>
                    </select>
                </div>
                
                <div style="margin-bottom: 20px;">
                    <label style="display: block; margin-bottom: 8px; font-weight: 600;">Item Name *</label>
                    <input type="text" name="name" id="add_name" required style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 6px;">
                </div>
                
                <div style="margin-bottom: 20px;">
                    <label style="display: block; margin-bottom: 8px; font-weight: 600;">Description *</label>
                    <textarea name="description" id="add_description" rows="3" required style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 6px;"></textarea>
                </div>
                
                <div style="margin-bottom: 20px;">
                    <label style="display: block; margin-bottom: 8px; font-weight: 600;">Price (<?php echo htmlspecialchars(getSetting('currency_symbol')); ?>) *</label>
                    <input type="number" name="price" id="add_price" step="0.01" required style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 6px;">
                </div>
                
                <div style="margin-bottom: 20px;">
                    <label style="display: block; margin-bottom: 8px; font-weight: 600;">Display Order (leave blank for auto)</label>
                    <input type="number" name="display_order" id="add_order" placeholder="Auto" style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 6px;">
                </div>
                
                <!-- Tags field for drinks only -->
                <div style="margin-bottom: 20px;" id="tags-field-container" style="display: none;">
                    <label style="display: block; margin-bottom: 8px; font-weight: 600;">Tags (comma-separated)</label>
                    <input type="text" name="tags" id="add_tags" placeholder="e.g., Hot, Cold, Premium" style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 6px;">
                </div>
                
                <div style="margin-bottom: 20px;">
                    <label style="display: flex; align-items: center; gap: 8px; cursor: pointer;">
                        <input type="checkbox" name="is_available" id="add_active" checked style="width: auto;">
                        <span style="font-weight: 600;" id="availability-label">Available (visible on menu)</span>
                    </label>
                </div>
                
                <div style="display: flex; gap: 12px; justify-content: flex-end;">
                    <button type="button" onclick="closeAddModal()" style="background: #6c757d; color: white; border: none; padding: 10px 20px; border-radius: 6px; cursor: pointer;">
                        <i class="fas fa-times"></i> Cancel
                    </button>
                    <button type="submit" style="background: var(--gold); color: var(--deep-navy); border: none; padding: 10px 20px; border-radius: 6px; cursor: pointer; font-weight: 600;">
                        <i class="fas fa-save"></i> Add Item
                    </button>
                </div>
            </form>
        </div>
    </div>
    
    
    <script>
        function switchTab(tab) {
            // Update URL without reloading
            const url = new URL(window.location);
            url.searchParams.set('tab', tab);
            window.history.pushState({}, '', url);
            
            // Update tab buttons
            document.querySelectorAll('.menu-type-tab').forEach(t => t.classList.remove('active'));
            document.querySelector(`.menu-type-tab[onclick="switchTab('${tab}')"]`).classList.add('active');
            
            // Update tab content
            document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
            document.getElementById(`${tab}-tab`).classList.add('active');
        }
        
        function openAddModal(menuType, category = null) {
            const modal = document.getElementById('addMenuModal');
            const menuTypeInput = document.getElementById('menu_type');
            const categorySelect = document.getElementById('add_category');
            const tagsContainer = document.getElementById('tags-field-container');
            const modalTitle = document.getElementById('modal-title');
            const availabilityLabel = document.getElementById('availability-label');
            
            // Set menu type
            menuTypeInput.value = menuType;
            
            // Update modal title
            modalTitle.textContent = menuType === 'food' ? 'Add New Food Item' : 'Add New Drink Item';
            availabilityLabel.textContent = menuType === 'food' ? 'Available (visible on menu)' : 'Active (visible on menu)';
            
            // Show/hide tags field based on menu type
            tagsContainer.style.display = menuType === 'drinks' ? 'block' : 'none';
            
            // Populate categories based on menu type
            categorySelect.innerHTML = '<option value="">Select Category</option>';
            const categories = menuType === 'food' ? <?php echo json_encode($food_categories); ?> : <?php echo json_encode($drink_categories); ?>;
            categories.forEach(cat => {
                const option = document.createElement('option');
                option.value = cat;
                option.textContent = cat;
                categorySelect.appendChild(option);
            });
            
            // Pre-select category if provided
            if (category) {
                categorySelect.value = category;
            }
            
            modal.style.display = 'flex';
        }
        
        function closeAddModal() {
            document.getElementById('addMenuModal').style.display = 'none';
        }
        
        // Close modal when clicking outside
        document.getElementById('addMenuModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeAddModal();
            }
        });
        
        function saveRow(id, category, menuType) {
            const row = document.getElementById(`${menuType}-row-${id}`);
            const formData = new FormData();
            
            formData.append('action', 'update');
            formData.append('id', id);
            formData.append('category', category);
            formData.append('menu_type', menuType);
            
            if (menuType === 'food') {
                formData.append('display_order', row.querySelector('[data-field="display_order"]').value);
                formData.append('name', row.querySelector('[data-field="name"]').value);
                formData.append('description', row.querySelector('[data-field="description"]').value);
                formData.append('price', row.querySelector('[data-field="price"]').value);
                formData.append('is_available', row.querySelector('[data-field="is_available"]').value);
            } else {
                formData.append('display_order', row.querySelector('[data-field="display_order"]').value);
                formData.append('name', row.querySelector('[data-field="name"]').value);
                formData.append('description', row.querySelector('[data-field="description"]').value);
                formData.append('price', row.querySelector('[data-field="price"]').value);
                formData.append('tags', row.querySelector('[data-field="tags"]').value);
                formData.append('is_available', row.querySelector('[data-field="is_available"]').value);
            }
            
            fetch(window.location.href, {
                method: 'POST',
                body: formData
            })
            .then(response => {
                if (response.ok) {
                    window.location.reload();
                } else {
                    Alert.show('Error saving item', 'error');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                Alert.show('Error saving item', 'error');
            });
        }
        
        // Quick toggle availability
        function quickToggle(id, menuType) {
            const formData = new FormData();
            formData.append('action', 'toggle_availability');
            formData.append('id', id);
            formData.append('menu_type', menuType);
            
            fetch(window.location.href, {
                method: 'POST',
                body: formData
            })
            .then(response => {
                if (response.ok) {
                    window.location.reload();
                } else {
                    Alert.show('Error toggling availability', 'error');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                Alert.show('Error toggling availability', 'error');
            });
        }
        
        function deleteRow(id, menuType) {
            const formData = new FormData();
            formData.append('action', 'delete');
            formData.append('id', id);
            formData.append('menu_type', menuType);
            
            fetch(window.location.href, {
                method: 'POST',
                body: formData
            })
            .then(response => {
                if (response.ok) {
                    window.location.reload();
                } else {
                    Alert.show('Error deleting item', 'error');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                Alert.show('Error deleting item', 'error');
            });
        }
    </script>

    <?php require_once 'includes/admin-footer.php'; ?>
