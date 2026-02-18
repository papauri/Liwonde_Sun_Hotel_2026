<?php
/**
 * Enhanced Cache Management System
 * Easy cache control with toggles, scheduling, and bulk operations
 */

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 0); // Don't display to user, log instead
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../logs/php-errors.log');

require_once 'admin-init.php';

// Set a custom error handler to prevent blank screens
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    error_log("Cache Management Error: [$errno] $errstr in $errfile:$errline");
    return true; // Prevent PHP error handler
});

// Set exception handler
set_exception_handler(function($exception) {
    error_log("Cache Management Exception: " . $exception->getMessage());
    echo "<div style='padding: 20px; background: #f8d7da; border: 1px solid #f5c6cb; border-radius: 4px; margin: 20px;'>";
    echo "<strong>Error:</strong> An unexpected error occurred. Please check the error log.";
    echo "</div>";
});

$user = [
    'id' => $_SESSION['admin_user_id'],
    'username' => $_SESSION['admin_username'],
    'role' => $_SESSION['admin_role'],
    'full_name' => $_SESSION['admin_full_name']
];

$message = '';
$error = '';
$success = false;

// Include alert.php for showAlert function
require_once __DIR__ . '/../includes/alert.php';

// Handle success message from redirect
if (isset($_GET['msg'])) {
    $message = htmlspecialchars($_GET['msg']);
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    try {
        switch ($action) {
            case 'toggle_cache':
                $cache_type = $_POST['cache_type'] ?? '';
                $enabled = isset($_POST['enabled']) ? (int)$_POST['enabled'] : 0;
                
                // Update or insert cache setting
                $stmt = $pdo->prepare("
                    INSERT INTO site_settings (setting_key, setting_value, updated_at)
                    VALUES (?, ?, NOW())
                    ON DUPLICATE KEY UPDATE setting_value = ?, updated_at = NOW()
                ");
                $stmt->execute(["cache_{$cache_type}_enabled", $enabled, $enabled]);
                
                $message = "Cache '{$cache_type}' " . ($enabled ? 'enabled' : 'disabled') . " successfully!";
                $success = true;
                
                // Redirect to force fresh read of database
                header("Location: cache-management.php?msg=" . urlencode($message));
                exit;
                break;
                
            case 'clear_cache':
                $cache_types = $_POST['cache_types'] ?? [];
                
                if (empty($cache_types)) {
                    $error = 'Please select at least one cache type to clear.';
                } else {
                    require_once __DIR__ . '/../config/cache.php';
                    $cleared = 0;
                    $files_cleared = 0;
                    
                    foreach ($cache_types as $type) {
                        switch ($type) {
                            case 'all':
                                $before = count(glob(CACHE_DIR . '/*.cache'));
                                $image_before = count(glob(IMAGE_CACHE_DIR . '/*.jpg'));
                                clearCache();
                                $files_cleared += $before + $image_before;
                                $cleared++;
                                break;
                            case 'email':
                                $before = count(glob(CACHE_DIR . '/email_*.cache'));
                                clearEmailCache();
                                $files_cleared += $before;
                                $cleared++;
                                break;
                            case 'settings':
                                $before = count(glob(CACHE_DIR . '/setting_*.cache'));
                                clearSettingsCache();
                                $files_cleared += $before;
                                $cleared++;
                                break;
                            case 'rooms':
                                $before = count(glob(CACHE_DIR . '/rooms_*.cache')) 
                                        + count(glob(CACHE_DIR . '/room_*.cache'))
                                        + count(glob(CACHE_DIR . '/facilities_*.cache'))
                                        + count(glob(CACHE_DIR . '/gallery_*.cache'))
                                        + count(glob(CACHE_DIR . '/hero_*.cache'));
                                $image_before = count(glob(IMAGE_CACHE_DIR . '/*.jpg'));
                                clearRoomCache();
                                $files_cleared += $before + $image_before;
                                $cleared++;
                                break;
                            case 'tables':
                                $before = count(glob(CACHE_DIR . '/table_*.cache'));
                                clearCacheByPattern('table_*');
                                $files_cleared += $before;
                                $cleared++;
                                break;
                            case 'images':
                                $before = count(glob(IMAGE_CACHE_DIR . '/*.jpg'));
                                clearImageCache();
                                $files_cleared += $before;
                                $cleared++;
                                break;
                        }
                    }
                    
                    $message = "Successfully cleared {$files_cleared} cache files in {$cleared} cache type(s)!";
                    $success = true;
                }
                break;
                
            case 'set_schedule':
                $enabled = isset($_POST['schedule_enabled']) ? 1 : 0;
                $interval = $_POST['schedule_interval'] ?? 'daily';
                $time = $_POST['schedule_time'] ?? '00:00';
                $custom_seconds = isset($_POST['custom_seconds']) ? (int)$_POST['custom_seconds'] : 60;
                
                // Validate custom seconds (minimum 10 seconds, maximum 86400 seconds/24 hours)
                if ($custom_seconds < 10) $custom_seconds = 10;
                if ($custom_seconds > 86400) $custom_seconds = 86400;
                
                // Update schedule settings
                $stmt = $pdo->prepare("
                    INSERT INTO site_settings (setting_key, setting_value, updated_at)
                    VALUES (?, ?, NOW())
                    ON DUPLICATE KEY UPDATE setting_value = ?, updated_at = NOW()
                ");
                $stmt->execute(['cache_schedule_enabled', $enabled, $enabled]);
                $stmt->execute(['cache_schedule_interval', $interval, $interval]);
                $stmt->execute(['cache_schedule_time', $time, $time]);
                $stmt->execute(['cache_custom_seconds', $custom_seconds, $custom_seconds]);
                
                $message = "Cache clearing schedule " . ($enabled ? 'enabled' : 'disabled') . "!";
                $success = true;
                break;
                
            case 'set_global_cache':
                $enabled = isset($_POST['global_cache_enabled']) ? 1 : 0;
                
                $stmt = $pdo->prepare("
                    INSERT INTO site_settings (setting_key, setting_value, updated_at)
                    VALUES ('cache_global_enabled', ?, NOW())
                    ON DUPLICATE KEY UPDATE setting_value = ?, updated_at = NOW()
                ");
                $stmt->execute([$enabled, $enabled]);
                
                $message = "Global caching " . ($enabled ? 'enabled' : 'disabled') . "!";
                $success = true;
                break;
        }
    } catch (PDOException $e) {
        $error = 'Database error: ' . $e->getMessage();
    }
}

// Get current cache settings
$cache_settings = [];
try {
    $stmt = $pdo->query("
        SELECT setting_key, setting_value 
        FROM site_settings 
        WHERE setting_key LIKE 'cache_%' OR setting_key LIKE '%_cache_%'
    ");
    $settings = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($settings as $setting) {
        $cache_settings[$setting['setting_key']] = $setting['setting_value'];
    }
} catch (PDOException $e) {
    $error = 'Error fetching cache settings: ' . $e->getMessage();
}

// Get cache statistics (with error handling)
require_once __DIR__ . '/../config/cache.php';

try {
    $stats = getCacheStats();
} catch (Exception $e) {
    error_log("Cache stats error: " . $e->getMessage());
    // Default stats if cache is disabled or error occurs
    $stats = [
        'total_files' => 0,
        'active_files' => 0,
        'expired_files' => 0,
        'total_size' => 0,
        'total_size_formatted' => '0 B',
        'oldest_file' => null,
        'newest_file' => null,
        'caches' => []
    ];
}

try {
    $caches = listCache();
} catch (Exception $e) {
    error_log("Cache list error: " . $e->getMessage());
    // Empty cache list if error occurs
    $caches = [];
}

// Helper function to safely count cache files by pattern
function countCacheByPattern($caches, $patterns) {
    try {
        $count = 0;
        foreach ($patterns as $pattern) {
            $regex = '/^' . str_replace('*', '.*', $pattern) . '$/';
            foreach ($caches as $cache) {
                if (preg_match($regex, $cache['key'])) {
                    $count++;
                }
            }
        }
        return $count;
    } catch (Exception $e) {
        return 0;
    }
}

// Define cache types - based on actual cache patterns in the system
$cache_types = [
    'email' => [
        'name' => 'Email Settings',
        'icon' => 'fa-envelope',
        'description' => 'Email configuration and SMTP settings',
        'patterns' => ['email_*']
    ],
    'settings' => [
        'name' => 'Site Settings',
        'icon' => 'fa-cog',
        'description' => 'General site settings and configuration',
        'patterns' => ['setting_*']
    ],
    'rooms' => [
        'name' => 'Rooms & Images',
        'icon' => 'fa-bed',
        'description' => 'Room data, prices, facilities, and image cache',
        'patterns' => ['rooms_*', 'room_*', 'facilities_*', 'gallery_*', 'hero_*']
    ],
    'tables' => [
        'name' => 'Database Tables',
        'icon' => 'fa-database',
        'description' => 'Cached database table data',
        'patterns' => ['table_*']
    ],
    'images' => [
        'name' => 'Image Cache',
        'icon' => 'fa-image',
        'description' => 'Cached processed images',
        'patterns' => ['image_*']
    ]
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cache Management - Admin Panel</title>
    
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;500;600;700&family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../css/style.css">
    <link rel="stylesheet" href="../css/theme-dynamic.php">
    <link rel="stylesheet" href="css/admin-styles.css">
    <link rel="stylesheet" href="css/admin-components.css">
    
    <style>
        .cache-overview {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .cache-stat-card {
            background: white;
            border-radius: 12px;
            padding: 24px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
            display: flex;
            align-items: center;
            gap: 20px;
        }
        
        .cache-stat-icon {
            width: 60px;
            height: 60px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
        }
        
        .cache-stat-icon.primary {
            background: rgba(212, 175, 55, 0.1);
            color: var(--gold);
        }
        
        .cache-stat-icon.success {
            background: rgba(40, 167, 69, 0.1);
            color: #28a745;
        }
        
        .cache-stat-icon.warning {
            background: rgba(255, 193, 7, 0.1);
            color: #ffc107;
        }
        
        .cache-stat-icon.danger {
            background: rgba(220, 53, 69, 0.1);
            color: #dc3545;
        }
        
        .cache-stat-info h3 {
            font-size: 28px;
            font-weight: 700;
            color: var(--navy);
            margin-bottom: 4px;
        }
        
        .cache-stat-info p {
            font-size: 14px;
            color: #666;
            margin: 0;
        }
        
        .cache-section {
            background: white;
            border-radius: 12px;
            padding: 24px;
            margin-bottom: 24px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
        }
        
        .cache-section h2 {
            font-size: 20px;
            font-weight: 600;
            color: var(--navy);
            margin-bottom: 20px;
            padding-bottom: 12px;
            border-bottom: 2px solid var(--gold);
        }
        
        .cache-toggle-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 16px;
        }
        
        .cache-toggle-item {
            border: 2px solid #e0e0e0;
            border-radius: 10px;
            padding: 16px;
            transition: all 0.3s;
        }
        
        .cache-toggle-item:hover {
            border-color: var(--gold);
        }
        
        .cache-toggle-item.active {
            border-color: #28a745;
            background: rgba(40, 167, 69, 0.05);
        }
        
        .cache-toggle-item.inactive {
            border-color: #dc3545;
            background: rgba(220, 53, 69, 0.05);
        }
        
        .cache-toggle-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 8px;
        }
        
        .cache-toggle-name {
            font-weight: 600;
            color: var(--navy);
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .cache-toggle-status {
            font-size: 12px;
            font-weight: 600;
            padding: 4px 8px;
            border-radius: 4px;
        }
        
        .cache-toggle-status.enabled {
            background: #28a745;
            color: white;
        }
        
        .cache-toggle-status.disabled {
            background: #dc3545;
            color: white;
        }
        
        .cache-toggle-desc {
            font-size: 13px;
            color: #666;
            margin-bottom: 12px;
        }
        
        .cache-toggle-btn {
            width: 100%;
            padding: 8px 16px;
            border: none;
            border-radius: 6px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
        }
        
        .cache-toggle-btn.enable {
            background: #28a745;
            color: white;
        }
        
        .cache-toggle-btn.enable:hover {
            background: #218838;
        }
        
        .cache-toggle-btn.disable {
            background: #dc3545;
            color: white;
        }
        
        .cache-toggle-btn.disable:hover {
            background: #c82333;
        }
        
        .bulk-clear-form {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 16px;
            margin-bottom: 20px;
        }
        
        .cache-checkbox-item {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 12px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.3s;
        }
        
        .cache-checkbox-item:hover {
            border-color: var(--gold);
        }
        
        .cache-checkbox-item input[type="checkbox"] {
            width: 20px;
            height: 20px;
            cursor: pointer;
        }
        
        .cache-checkbox-item label {
            cursor: pointer;
            font-weight: 500;
            color: var(--navy);
            flex: 1;
        }
        
        .btn-primary {
            background: var(--gold);
            color: var(--deep-navy);
        }
        
        .btn-primary:hover {
            background: #d4af37;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(212, 175, 55, 0.3);
        }
        
        .schedule-form {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            align-items: end;
        }
        
        .form-group {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }
        
        .form-group label {
            font-weight: 600;
            color: var(--navy);
            font-size: 14px;
        }
        
        .form-group select,
        .form-group input[type="time"] {
            padding: 10px 14px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            font-size: 14px;
            transition: all 0.3s;
        }
        
        .form-group select:focus,
        .form-group input:focus {
            outline: none;
            border-color: var(--gold);
        }
        
        .switch-container {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        
        .switch {
            position: relative;
            display: inline-block;
            width: 60px;
            height: 30px;
        }
        
        .switch input {
            opacity: 0;
            width: 0;
            height: 0;
        }
        
        .slider {
            position: absolute;
            cursor: pointer;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: #ccc;
            transition: .4s;
            border-radius: 30px;
        }
        
        .slider:before {
            position: absolute;
            content: "";
            height: 24px;
            width: 24px;
            left: 3px;
            bottom: 3px;
            background-color: white;
            transition: .4s;
            border-radius: 50%;
        }
        
        input:checked + .slider {
            background-color: var(--gold);
        }
        
        input:checked + .slider:before {
            transform: translateX(30px);
        }
        
        .cache-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }
        
        .cache-table th,
        .cache-table td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #e0e0e0;
        }
        
        .cache-table th {
            background: #f8f9fa;
            font-weight: 600;
            color: var(--navy);
            font-size: 13px;
            text-transform: uppercase;
        }
        
        .cache-table tr:hover {
            background: #f8f9fa;
        }
    </style>
</head>
<body>
    <?php require_once 'includes/admin-header.php'; ?>
    
    <div class="content">
        <div class="page-header">
            <h2 class="page-title">
                <i class="fas fa-bolt"></i> Cache Management
            </h2>
            <p class="text-muted">Control website caching for optimal performance</p>
        </div>
        
        <?php if ($message): ?>
            <?php showAlert($message, 'success'); ?>
        <?php endif; ?>
        
        <?php if ($error): ?>
            <?php showAlert($error, 'error'); ?>
        <?php endif; ?>
        
        <!-- Cache Statistics Overview -->
        <div class="cache-overview">
            <div class="cache-stat-card">
                <div class="cache-stat-icon primary">
                    <i class="fas fa-file-alt"></i>
                </div>
                <div class="cache-stat-info">
                    <h3><?php echo $stats['total_files']; ?></h3>
                    <p>Total Cache Files</p>
                </div>
            </div>
            
            <div class="cache-stat-card">
                <div class="cache-stat-icon success">
                    <i class="fas fa-check-circle"></i>
                </div>
                <div class="cache-stat-info">
                    <h3><?php echo $stats['active_files']; ?></h3>
                    <p>Active Caches</p>
                </div>
            </div>
            
            <div class="cache-stat-card">
                <div class="cache-stat-icon warning">
                    <i class="fas fa-clock"></i>
                </div>
                <div class="cache-stat-info">
                    <h3><?php echo $stats['expired_files']; ?></h3>
                    <p>Expired Caches</p>
                </div>
            </div>
            
            <div class="cache-stat-card">
                <div class="cache-stat-icon danger">
                    <i class="fas fa-hdd"></i>
                </div>
                <div class="cache-stat-info">
                    <h3><?php echo $stats['total_size_formatted']; ?></h3>
                    <p>Total Size</p>
                </div>
            </div>
        </div>
        
        <!-- Global Cache Control -->
        <div class="cache-section">
            <h2><i class="fas fa-power-off"></i> Global Cache Control</h2>
            <form method="POST" style="display: flex; align-items: center; gap: 20px;">
                <input type="hidden" name="action" value="set_global_cache">
                
                <div class="switch-container">
                    <label class="switch">
                        <input type="checkbox" name="global_cache_enabled" 
                               <?php echo isset($cache_settings['cache_global_enabled']) && $cache_settings['cache_global_enabled'] ? 'checked' : ''; ?>>
                        <span class="slider"></span>
                    </label>
                    <span style="font-weight: 600; color: var(--navy);">
                        Enable All Caching
                    </span>
                </div>
                
                <button type="submit" class="btn-action btn-save">
                    <i class="fas fa-save"></i> Save Setting
                </button>
            </form>
        </div>
        
        <!-- Individual Cache Toggles -->
        <div class="cache-section">
            <h2><i class="fas fa-toggle-on"></i> Individual Cache Controls</h2>
            <div class="cache-toggle-grid">
                <?php foreach ($cache_types as $type => $info): ?>
                <?php 
                $enabled = isset($cache_settings["cache_{$type}_enabled"]) 
                    ? $cache_settings["cache_{$type}_enabled"] 
                    : 1; // Default enabled
                ?>
                <div class="cache-toggle-item <?php echo $enabled ? 'active' : 'inactive'; ?>">
                    <div class="cache-toggle-header">
                        <div class="cache-toggle-name">
                            <i class="fas <?php echo $info['icon']; ?>"></i>
                            <?php echo $info['name']; ?>
                        </div>
                        <span class="cache-toggle-status <?php echo $enabled ? 'enabled' : 'disabled'; ?>">
                            <?php echo $enabled ? 'ON' : 'OFF'; ?>
                        </span>
                    </div>
                    <p class="cache-toggle-desc"><?php echo $info['description']; ?></p>
                    <form method="POST">
                        <input type="hidden" name="action" value="toggle_cache">
                        <input type="hidden" name="cache_type" value="<?php echo $type; ?>">
                        <input type="hidden" name="enabled" value="<?php echo $enabled ? 0 : 1; ?>">
                        <button type="submit" class="cache-toggle-btn <?php echo $enabled ? 'disable' : 'enable'; ?>">
                            <i class="fas fa-power-off"></i>
                            <?php echo $enabled ? 'Disable' : 'Enable'; ?>
                        </button>
                    </form>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        
        <!-- Bulk Cache Clearing -->
        <div class="cache-section">
            <h2><i class="fas fa-eraser"></i> Bulk Cache Clearing</h2>
            <form method="POST">
                <input type="hidden" name="action" value="clear_cache">
                
                <div class="bulk-clear-form">
                    <label class="cache-checkbox-item">
                        <input type="checkbox" name="cache_types[]" value="email">
                        <span><i class="fas fa-envelope"></i> Email Settings (<?php echo count(array_filter($caches, function($c) { return strpos($c['key'], 'email_') === 0; })); ?> files)</span>
                    </label>
                    
                    <label class="cache-checkbox-item">
                        <input type="checkbox" name="cache_types[]" value="settings">
                        <span><i class="fas fa-cog"></i> Site Settings (<?php echo count(array_filter($caches, function($c) { return strpos($c['key'], 'setting_') === 0; })); ?> files)</span>
                    </label>
                    
                    <label class="cache-checkbox-item" style="border-color: var(--gold); background: rgba(212, 175, 55, 0.05);">
                        <input type="checkbox" name="cache_types[]" value="rooms">
                        <span><i class="fas fa-bed"></i> <strong>Rooms & Prices</strong> (<?php 
                            $room_count = count(array_filter($caches, function($c) { 
                                return strpos($c['key'], 'rooms_') === 0 || strpos($c['key'], 'room_') === 0 || 
                                       strpos($c['key'], 'facilities_') === 0 || strpos($c['key'], 'gallery_') === 0 || 
                                       strpos($c['key'], 'hero_') === 0; 
                            }));
                            echo $room_count; ?> files)</span>
                    </label>
                    
                    <label class="cache-checkbox-item" style="border-color: #17a2b8; background: rgba(23, 162, 184, 0.05);">
                        <input type="checkbox" name="cache_types[]" value="images">
                        <span><i class="fas fa-image"></i> <strong>Image Cache</strong> (<?php 
                            $image_count = is_dir(IMAGE_CACHE_DIR) ? count(glob(IMAGE_CACHE_DIR . '/*.jpg')) : 0;
                            echo $image_count; ?> images)</span>
                    </label>
                    
                    <label class="cache-checkbox-item">
                        <input type="checkbox" name="cache_types[]" value="tables">
                        <span><i class="fas fa-database"></i> Database Tables (<?php echo count(array_filter($caches, function($c) { return strpos($c['key'], 'table_') === 0; })); ?> files)</span>
                    </label>
                    
                    <label class="cache-checkbox-item" style="border-color: #dc3545; background: rgba(220, 53, 69, 0.05);">
                        <input type="checkbox" name="cache_types[]" value="all">
                        <span><i class="fas fa-trash"></i> <strong>ALL CACHES + IMAGES</strong></span>
                    </label>
                </div>
                
                <button type="submit" class="btn-action btn-delete" 
                        onclick="return confirm('Are you sure you want to clear the selected caches?');">
                    <i class="fas fa-eraser"></i> Clear Selected Caches
                </button>
            </form>
        </div>
        
        <!-- Scheduled Cache Clearing -->
        <div class="cache-section">
            <h2><i class="fas fa-clock"></i> Scheduled Cache Clearing</h2>
            <form method="POST">
                <input type="hidden" name="action" value="set_schedule">
                
                <div class="schedule-form">
                    <div class="switch-container">
                        <label class="switch">
                            <input type="checkbox" name="schedule_enabled" 
                                   <?php echo isset($cache_settings['cache_schedule_enabled']) && $cache_settings['cache_schedule_enabled'] ? 'checked' : ''; ?>>
                            <span class="slider"></span>
                        </label>
                        <span style="font-weight: 600; color: var(--navy);">
                            Enable Auto-Clear
                        </span>
                    </div>
                    
                    <div class="form-group">
                        <label>Clear Frequency</label>
                        <select name="schedule_interval" id="schedule_interval" onchange="toggleCustomInterval()">
                            <option value="30sec" 
                                    <?php echo (isset($cache_settings['cache_schedule_interval']) && $cache_settings['cache_schedule_interval'] == '30sec') ? 'selected' : ''; ?>>
                                Every 30 Seconds
                            </option>
                            <option value="1min" 
                                    <?php echo (isset($cache_settings['cache_schedule_interval']) && $cache_settings['cache_schedule_interval'] == '1min') ? 'selected' : ''; ?>>
                                Every 1 Minute
                            </option>
                            <option value="5min" 
                                    <?php echo (isset($cache_settings['cache_schedule_interval']) && $cache_settings['cache_schedule_interval'] == '5min') ? 'selected' : ''; ?>>
                                Every 5 Minutes
                            </option>
                            <option value="15min" 
                                    <?php echo (isset($cache_settings['cache_schedule_interval']) && $cache_settings['cache_schedule_interval'] == '15min') ? 'selected' : ''; ?>>
                                Every 15 Minutes
                            </option>
                            <option value="30min" 
                                    <?php echo (isset($cache_settings['cache_schedule_interval']) && $cache_settings['cache_schedule_interval'] == '30min') ? 'selected' : ''; ?>>
                                Every 30 Minutes
                            </option>
                            <option value="hourly" 
                                    <?php echo (isset($cache_settings['cache_schedule_interval']) && $cache_settings['cache_schedule_interval'] == 'hourly') ? 'selected' : ''; ?>>
                                Every Hour
                            </option>
                            <option value="6hours" 
                                    <?php echo (isset($cache_settings['cache_schedule_interval']) && $cache_settings['cache_schedule_interval'] == '6hours') ? 'selected' : ''; ?>>
                                Every 6 Hours
                            </option>
                            <option value="12hours" 
                                    <?php echo (isset($cache_settings['cache_schedule_interval']) && $cache_settings['cache_schedule_interval'] == '12hours') ? 'selected' : ''; ?>>
                                Every 12 Hours
                            </option>
                            <option value="daily" 
                                    <?php echo (!isset($cache_settings['cache_schedule_interval']) || $cache_settings['cache_schedule_interval'] == 'daily') ? 'selected' : ''; ?>>
                                Daily
                            </option>
                            <option value="weekly" 
                                    <?php echo (isset($cache_settings['cache_schedule_interval']) && $cache_settings['cache_schedule_interval'] == 'weekly') ? 'selected' : ''; ?>>
                                Weekly
                            </option>
                            <option value="custom" 
                                    <?php echo (isset($cache_settings['cache_schedule_interval']) && $cache_settings['cache_schedule_interval'] == 'custom') ? 'selected' : ''; ?>>
                                Custom Interval
                            </option>
                        </select>
                    </div>
                    
                    <div class="form-group" id="custom_interval_group" style="display: none;">
                        <label>Custom Interval (seconds)</label>
                        <input type="number" name="custom_seconds" id="custom_seconds"
                               value="<?php echo $cache_settings['cache_custom_seconds'] ?? '60'; ?>"
                               min="10" max="86400" step="1">
                        <small style="color: #666; display: block; margin-top: 5px;">
                            Min: 10 seconds (0.17 mins) | Max: 86400 seconds (24 hours)
                        </small>
                    </div>
                    
                    <div class="form-group">
                        <label>Clear At Time</label>
                        <input type="time" name="schedule_time" 
                               value="<?php echo $cache_settings['cache_schedule_time'] ?? '00:00'; ?>"
                               min="00:00" max="23:59">
                    </div>
                    
                    <button type="submit" class="btn-action btn-save">
                        <i class="fas fa-save"></i> Save Schedule
                    </button>
                </div>
            </form>
            
            <div style="margin-top: 20px; padding: 16px; background: #fff3cd; border-left: 4px solid #ffc107; border-radius: 4px;">
                <i class="fas fa-info-circle"></i>
                <strong>Note:</strong> Scheduled cache clearing requires a cron job (Linux/Mac) or Task Scheduler (Windows) to be set up.
                <br><br>
                <strong>Cron Setup (Linux/Mac):</strong><br>
                For intervals &lt; 1 minute: <code>* * * * * php scripts/scheduled-cache-clear.php</code> (runs every minute)<br>
                For other intervals: Script will check if it should run based on your settings.<br>
                <br>
                <strong>Windows Task Scheduler:</strong><br>
                Set trigger to run every 1 minute for best accuracy with short intervals.
            </div>
            
            <script>
            function toggleCustomInterval() {
                const interval = document.getElementById('schedule_interval').value;
                const customGroup = document.getElementById('custom_interval_group');
                const timeGroup = document.querySelector('input[name="schedule_time"]').closest('.form-group');
                
                if (interval === 'custom') {
                    customGroup.style.display = 'block';
                    timeGroup.style.display = 'none';
                } else if (['30sec', '1min', '5min', '15min', '30min', 'hourly'].includes(interval)) {
                    customGroup.style.display = 'none';
                    timeGroup.style.display = 'none';
                } else {
                    customGroup.style.display = 'none';
                    timeGroup.style.display = 'block';
                }
            }
            
            // Run on page load
            document.addEventListener('DOMContentLoaded', toggleCustomInterval);
            </script>
        </div>
        
        <!-- Cache Files List -->
        <?php if (!empty($caches)): ?>
        <div class="cache-section">
            <h2><i class="fas fa-list"></i> Current Cache Files (<?php echo count($caches); ?>)</h2>
            <div style="overflow-x: auto;">
                <table class="cache-table">
                    <thead>
                        <tr>
                            <th>File Name</th>
                            <th>Cache Key</th>
                            <th>Size</th>
                            <th>Created</th>
                            <th>Expires</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($caches as $cache): ?>
                        <tr>
                            <td><code><?php echo htmlspecialchars($cache['file']); ?></code></td>
                            <td><?php echo htmlspecialchars($cache['key']); ?></td>
                            <td><?php echo $cache['size_formatted']; ?></td>
                            <td><?php echo $cache['created_formatted']; ?></td>
                            <td><?php echo $cache['expires_formatted']; ?></td>
                            <td>
                                <span class="badge <?php echo $cache['expired'] ? 'badge-expired' : 'badge-active'; ?>">
                                    <?php echo $cache['expired'] ? 'Expired' : 'Active'; ?>
                                </span>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>
    </div>
    
    <?php require_once 'includes/admin-footer.php'; ?>
