<?php
// Include admin initialization (PHP-only, no HTML output)
require_once 'admin-init.php';

// Include modal and alert helpers
require_once '../includes/modal.php';
require_once '../includes/alert.php';
require_once '../includes/video-display.php';
require_once 'video-upload-handler.php';

// Note: $user and $current_page are already set in admin-init.php
$message = '';
$error = '';

// Auto-ensure show_in_upcoming column exists
try {
    $col_check = $pdo->query("SHOW COLUMNS FROM events LIKE 'show_in_upcoming'");
    if ($col_check->rowCount() === 0) {
        $pdo->exec("ALTER TABLE events ADD COLUMN show_in_upcoming TINYINT(1) NOT NULL DEFAULT 0 AFTER is_featured");
        $pdo->exec("UPDATE events SET show_in_upcoming = 1 WHERE is_featured = 1");
    }
} catch (\Throwable $e) {
    error_log("Auto-migration show_in_upcoming: " . $e->getMessage());
}

// Ensure upcoming_events settings exist in site_settings
try {
    $settings_check = $pdo->prepare("SELECT COUNT(*) FROM site_settings WHERE setting_key = ?");
    $settings_check->execute(['upcoming_events_enabled']);
    if ($settings_check->fetchColumn() == 0) {
        $pdo->exec("INSERT INTO site_settings (setting_key, setting_value, setting_group) VALUES 
            ('upcoming_events_enabled', '1', 'upcoming_events'),
            ('upcoming_events_pages', '[\"index\"]', 'upcoming_events'),
            ('upcoming_events_max_display', '4', 'upcoming_events')");
    }
} catch (\Throwable $e) {
    error_log("Auto-migration upcoming_events settings: " . $e->getMessage());
}

// Helper: upload event image (same pattern as gallery/conference)
function uploadEventImage($fileInput)
{
    if (!$fileInput || !isset($fileInput['tmp_name']) || $fileInput['error'] !== UPLOAD_ERR_OK) {
        return null;
    }

    $uploadDir = __DIR__ . '/../images/events/';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }

    $ext = strtolower(pathinfo($fileInput['name'], PATHINFO_EXTENSION)) ?: 'jpg';
    $allowed = ['jpg', 'jpeg', 'png', 'webp'];
    if (!in_array($ext, $allowed, true)) {
        return null;
    }

    $filename = 'event_' . time() . '_' . random_int(1000, 9999) . '.' . $ext;
    $destination = $uploadDir . $filename;

    if (move_uploaded_file($fileInput['tmp_name'], $destination)) {
        return 'images/events/' . $filename;
    }

    return null;
}

// Helper: get user-friendly upload error message
function getUploadErrorMessage($errorCode)
{
    switch ($errorCode) {
        case UPLOAD_ERR_INI_SIZE:
        case UPLOAD_ERR_FORM_SIZE:
            return 'File is too large. Please resize or compress the image and try again.';
        case UPLOAD_ERR_PARTIAL:
            return 'File was only partially uploaded. Please try again.';
        case UPLOAD_ERR_NO_TMP_DIR:
            return 'Server error: No temporary folder. Please contact the administrator.';
        case UPLOAD_ERR_CANT_WRITE:
            return 'Server error: Failed to write file to disk.';
        case UPLOAD_ERR_EXTENSION:
            return 'Upload was blocked by a server extension.';
        default:
            return 'Unknown upload error. Please try again.';
    }
}

// Handle event actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $action = $_POST['action'] ?? '';

        if ($action === 'add') {
            // Handle image upload
            $imagePath = null;
            if (isset($_FILES['image']) && $_FILES['image']['error'] !== UPLOAD_ERR_NO_FILE) {
                if ($_FILES['image']['error'] !== UPLOAD_ERR_OK) {
                    $error = getUploadErrorMessage($_FILES['image']['error']);
                } else {
                    $imagePath = uploadEventImage($_FILES['image']);
                    if (!$imagePath) {
                        $error = 'Invalid image type. Only JPG, PNG, and WebP files are allowed.';
                    }
                }
            }
            
            // Check for video URL first, then file upload
            $videoUrl = processVideoUrl($_POST['video_url'] ?? '');
            if ($videoUrl) {
                $videoPath = $videoUrl['path'];
                $videoType = $videoUrl['type'];
            } else {
                $videoUpload = uploadVideo($_FILES['video'] ?? null, 'events');
                $videoPath = $videoUpload['path'] ?? null;
                $videoType = $videoUpload['type'] ?? null;
            }

            $stmt = $pdo->prepare("
                INSERT INTO events (title, description, event_date, start_time, end_time, location, ticket_price, capacity, is_featured, show_in_upcoming, is_active, display_order, image_path, video_path, video_type)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $_POST['title'],
                $_POST['description'],
                $_POST['event_date'],
                $_POST['start_time'],
                $_POST['end_time'],
                $_POST['location'],
                $_POST['ticket_price'] ?? 0,
                $_POST['capacity'],
                isset($_POST['is_featured']) ? 1 : 0,
                isset($_POST['show_in_upcoming']) ? 1 : 0,
                isset($_POST['is_active']) ? 1 : 0,
                $_POST['display_order'] ?? 0,
                $imagePath,
                $videoPath,
                $videoType
            ]);
            if (!$error) {
                $message = 'Event added successfully!';
            }

        } elseif ($action === 'update') {
            // Handle image upload
            $imagePath = null;
            if (isset($_FILES['image']) && $_FILES['image']['error'] !== UPLOAD_ERR_NO_FILE) {
                if ($_FILES['image']['error'] !== UPLOAD_ERR_OK) {
                    $error = getUploadErrorMessage($_FILES['image']['error']);
                } else {
                    $imagePath = uploadEventImage($_FILES['image']);
                    if (!$imagePath) {
                        $error = 'Invalid image type. Only JPG, PNG, and WebP files are allowed.';
                    }
                }
            }
            
            // Check for video URL first, then file upload
            $videoUrl = processVideoUrl($_POST['video_url'] ?? '');
            if ($videoUrl) {
                $videoPath = $videoUrl['path'];
                $videoType = $videoUrl['type'];
            } else {
                $videoUpload = uploadVideo($_FILES['video'] ?? null, 'events');
                $videoPath = $videoUpload['path'] ?? null;
                $videoType = $videoUpload['type'] ?? null;
            }

            // Build the update query dynamically based on what's being updated
            $updateFields = [
                'title = ?', 'description = ?', 'event_date = ?', 'start_time = ?', 'end_time = ?',
                'location = ?', 'ticket_price = ?', 'capacity = ?', 'is_featured = ?', 'show_in_upcoming = ?', 'is_active = ?', 'display_order = ?'
            ];
            $updateValues = [
                $_POST['title'],
                $_POST['description'] ?? '',
                $_POST['event_date'],
                $_POST['start_time'],
                $_POST['end_time'],
                $_POST['location'],
                $_POST['ticket_price'] ?? 0,
                $_POST['capacity'],
                isset($_POST['is_featured']) ? 1 : 0,
                isset($_POST['show_in_upcoming']) ? 1 : 0,
                isset($_POST['is_active']) ? 1 : 0,
                $_POST['display_order'] ?? 0
            ];

            if ($imagePath) {
                $updateFields[] = 'image_path = ?';
                $updateValues[] = $imagePath;
            }

            if ($videoPath) {
                $updateFields[] = 'video_path = ?';
                $updateValues[] = $videoPath;
                $updateFields[] = 'video_type = ?';
                $updateValues[] = $videoType;
            } elseif (!empty($_POST['remove_video'])) {
                // User explicitly wants to remove the video
                $updateFields[] = 'video_path = ?';
                $updateValues[] = null;
                $updateFields[] = 'video_type = ?';
                $updateValues[] = null;
            }

            $updateValues[] = $_POST['id'];

            $sql = "UPDATE events SET " . implode(', ', $updateFields) . " WHERE id = ?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($updateValues);
            if (!$error) {
                $message = 'Event updated successfully!';
            }

        } elseif ($action === 'delete') {
            $stmt = $pdo->prepare("DELETE FROM events WHERE id = ?");
            $stmt->execute([$_POST['id']]);
            $message = 'Event deleted successfully!';

        } elseif ($action === 'toggle_active') {
            $stmt = $pdo->prepare("UPDATE events SET is_active = NOT is_active WHERE id = ?");
            $stmt->execute([$_POST['id']]);
            $message = 'Event status updated!';

        } elseif ($action === 'toggle_featured') {
            $stmt = $pdo->prepare("UPDATE events SET is_featured = NOT is_featured WHERE id = ?");
            $stmt->execute([$_POST['id']]);
            $message = 'Featured status updated!';

        } elseif ($action === 'toggle_upcoming') {
            $stmt = $pdo->prepare("UPDATE events SET show_in_upcoming = NOT show_in_upcoming WHERE id = ?");
            $stmt->execute([$_POST['id']]);
            $message = 'Upcoming display status updated!';

        } elseif ($action === 'save_upcoming_settings') {
            // Save upcoming events section settings
            $ue_enabled = isset($_POST['ue_enabled']) ? '1' : '0';
            $ue_max = max(1, min(8, intval($_POST['ue_max_display'] ?? 4)));
            $ue_pages = $_POST['ue_pages'] ?? [];
            $ue_pages_json = json_encode(array_values($ue_pages));
            
            $stmtSetting = $pdo->prepare("INSERT INTO site_settings (setting_key, setting_value, setting_group) VALUES (?, ?, 'upcoming_events') ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
            $stmtSetting->execute(['upcoming_events_enabled', $ue_enabled]);
            $stmtSetting->execute(['upcoming_events_max_display', (string)$ue_max]);
            $stmtSetting->execute(['upcoming_events_pages', $ue_pages_json]);
            
            // Clear cached settings (in-memory + file cache)
            global $_SITE_SETTINGS;
            unset($_SITE_SETTINGS['upcoming_events_enabled'], $_SITE_SETTINGS['upcoming_events_max_display'], $_SITE_SETTINGS['upcoming_events_pages']);
            if (function_exists('clearCacheByPattern')) {
                clearCacheByPattern('setting_upcoming_events*');
            }
            
            $message = 'Upcoming events section settings saved!';

        } elseif ($action === 'update_image') {
            if (isset($_FILES['image']) && $_FILES['image']['error'] !== UPLOAD_ERR_NO_FILE) {
                if ($_FILES['image']['error'] !== UPLOAD_ERR_OK) {
                    $error = getUploadErrorMessage($_FILES['image']['error']);
                } else {
                    $imagePath = uploadEventImage($_FILES['image']);
                    if ($imagePath) {
                        $stmt = $pdo->prepare("UPDATE events SET image_path = ? WHERE id = ?");
                        $stmt->execute([$imagePath, $_POST['id']]);
                        $message = 'Event image updated!';
                    } else {
                        $error = 'Invalid image type. Only JPG, PNG, and WebP files are allowed.';
                    }
                }
            } else {
                $error = 'Please select an image to upload.';
            }
        }

    } catch (PDOException $e) {
        $error = 'Error: ' . $e->getMessage();
    }
}

// Fetch all events
try {
    $stmt = $pdo->query("
        SELECT * FROM events 
        ORDER BY event_date ASC, display_order ASC
    ");
    $events = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Mark expired events
    $today = date('Y-m-d');
    foreach ($events as &$event) {
        $event['is_expired'] = ($event['event_date'] < $today);
    }
    unset($event);
    
} catch (PDOException $e) {
    $error = 'Error fetching events: ' . $e->getMessage();
    $events = [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Events Management - Admin Panel</title>
    
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;500;600;700&family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../css/style.css">
    <link rel="stylesheet" href="../css/theme-dynamic.php">
    <link rel="stylesheet" href="css/admin-styles.css">
    <link rel="stylesheet" href="css/admin-components.css">
    
    <style>
        /* Events management specific styles - Card Grid Layout (matching gallery) */
        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            flex-wrap: wrap;
            gap: 12px;
        }
        .page-title {
            font-size: 28px;
            font-weight: 600;
            color: #2c3e50;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .btn-add {
            background: var(--gold);
            color: var(--deep-navy);
            padding: 12px 24px;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            box-shadow: 0 2px 8px rgba(212, 175, 55, 0.3);
        }
        .btn-add:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(212, 175, 55, 0.4);
        }
        
        /* Events Grid Layout (matching gallery) */
        .events-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(340px, 1fr));
            gap: 24px;
            padding: 0;
        }
        
        .event-card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            overflow: hidden;
            transition: all 0.3s ease;
            position: relative;
        }
        
        .event-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 8px 24px rgba(0,0,0,0.15);
        }
        
        .event-card.expired {
            opacity: 0.65;
            background: #fff8f0;
        }
        
        .event-card-image {
            width: 100%;
            aspect-ratio: 16 / 10;
            object-fit: cover;
            background: linear-gradient(135deg, var(--gold) 0%, #c19b2e 100%);
        }
        
        .no-image-placeholder {
            width: 100%;
            aspect-ratio: 16 / 10;
            background: linear-gradient(135deg, #e0e0e0 0%, #bdbdbd 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            color: #757575;
            font-size: 48px;
        }
        
        .event-card-body {
            padding: 20px;
        }
        
        .event-card-title {
            font-size: 18px;
            font-weight: 600;
            color: #2c3e50;
            margin-bottom: 8px;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }
        
        .event-card.expired .event-card-title {
            text-decoration: line-through;
            color: #999;
        }
        
        .event-card-desc {
            font-size: 13px;
            color: #666;
            margin-bottom: 12px;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
            line-height: 1.5;
        }
        
        .event-card-info {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
            font-size: 12px;
            margin-bottom: 12px;
            background: #f8f9fa;
            padding: 12px;
            border-radius: 8px;
        }
        
        .event-card-info-item {
            display: flex;
            align-items: flex-start;
            gap: 8px;
            color: #495057;
        }
        
        .event-card-info-item i {
            color: var(--gold);
            width: 16px;
            margin-top: 2px;
        }
        
        .event-card-info-full {
            grid-column: 1 / -1;
        }
        
        .event-card-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
            margin-bottom: 16px;
            padding-bottom: 16px;
            border-bottom: 1px solid #e0e0e0;
        }
        
        .event-badge {
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            white-space: nowrap;
        }
        
        .badge-active {
            background: #d4edda;
            color: #155724;
        }
        
        .badge-inactive {
            background: #f8d7da;
            color: #721c24;
        }
        
        .badge-featured {
            background: #fff3cd;
            color: #856404;
        }
        
        .badge-upcoming {
            background: #e8f5e9;
            color: #2e7d32;
        }
        
        .badge-expired {
            background: #dc3545;
            color: white;
            animation: pulse 2s infinite;
        }
        
        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.7; }
        }
        
        .badge-free {
            background: #d1ecf1;
            color: #0c5460;
        }
        
        .badge-price {
            background: #e7f3ff;
            color: #004085;
        }
        
        .badge-video {
            background: #fce4ec;
            color: #880e4f;
        }
        
        .event-card-actions {
            display: flex;
            gap: 6px;
            flex-wrap: wrap;
        }
        
        .btn-action {
            flex: 1;
            min-width: 70px;
            padding: 8px 12px;
            font-size: 12px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            transition: all 0.2s ease;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 4px;
            font-weight: 500;
        }
        
        .btn-action:hover {
            transform: translateY(-1px);
            box-shadow: 0 2px 8px rgba(0,0,0,0.15);
        }
        
        .btn-edit {
            background: #3498db;
            color: white;
        }
        .btn-edit:hover {
            background: #2980b9;
        }
        
        .btn-toggle {
            background: #f39c12;
            color: white;
        }
        .btn-toggle:hover {
            background: #e67e22;
        }
        
        .btn-featured {
            background: var(--gold);
            color: var(--deep-navy);
        }
        .btn-featured:hover {
            background: #c19b2e;
        }
        
        .btn-upcoming {
            background: #4caf50;
            color: white;
        }
        .btn-upcoming:hover {
            background: #388e3c;
        }
        
        .btn-delete {
            background: #e74c3c;
            color: white;
        }
        .btn-delete:hover {
            background: #c0392b;
        }
        
        .btn-save {
            background: #27ae60;
            color: white;
        }
        .btn-save:hover {
            background: #229954;
        }
        
        .btn-cancel {
            background: #95a5a6;
            color: white;
        }
        .btn-cancel:hover {
            background: #7f8c8d;
        }
        
        /* Modal styles */
        .image-upload-wrapper {
            position: relative;
        }
        .current-image-preview {
            width: 100%;
            max-width: 300px;
            border-radius: 12px;
            margin-bottom: 16px;
            box-shadow: 0 4px 16px rgba(0,0,0,0.1);
        }
        .image-upload-area {
            border: 2px dashed #ddd;
            border-radius: 12px;
            padding: 24px;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s ease;
            background: #f8f9fa;
        }
        .image-upload-area:hover {
            border-color: var(--gold);
            background: #fff;
        }
        .image-upload-area i {
            font-size: 48px;
            color: var(--gold);
            margin-bottom: 12px;
            display: block;
        }
        .checkbox-group {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .checkbox-group input {
            width: auto;
        }
        
        @media (max-width: 768px) {
            .events-grid {
                grid-template-columns: 1fr;
            }
            .page-header {
                flex-direction: column;
                gap: 16px;
                align-items: flex-start;
            }
            .btn-add {
                width: 100%;
            }
        }
    </style>
</head>
<body>
    <?php require_once 'includes/admin-header.php'; ?>

    <div class="content">
        <div class="page-header">
            <div>
                <h2 class="page-title"><i class="fas fa-calendar-alt"></i> Events Management</h2>
                <p style="color:#666; margin-top:4px;"><?php echo count($events); ?> event<?php echo count($events) !== 1 ? 's' : ''; ?> total</p>
            </div>
            <button class="btn-add" onclick="openAddModal()">
                <i class="fas fa-plus"></i> Add New Event
            </button>
        </div>

        <?php if ($message): ?>
            <?php showAlert($message, 'success'); ?>
        <?php endif; ?>

        <?php if ($error): ?>
            <?php showAlert($error, 'error'); ?>
        <?php endif; ?>

        <!-- Upcoming Events Section Settings -->
        <?php
        $ue_enabled = getSetting('upcoming_events_enabled', '1');
        $ue_max_display = getSetting('upcoming_events_max_display', '4');
        $ue_pages_json = getSetting('upcoming_events_pages', '["index"]');
        $ue_pages = json_decode($ue_pages_json, true) ?: ['index'];
        
        // Available pages where the section can be shown
        $available_pages = [
            'index' => 'Homepage',
            'rooms-showcase' => 'Rooms Showcase',
            'restaurant' => 'Restaurant',
            'conference' => 'Conference',
            'gym' => 'Gym'
        ];
        ?>
        <div style="background: linear-gradient(135deg, #e8f5e9, #f1f8e9); border: 1px solid #c8e6c9; border-radius: 12px; padding: 20px 24px; margin-bottom: 24px;">
            <div style="display: flex; align-items: center; justify-content: space-between; cursor: pointer;" onclick="document.getElementById('ueSettingsBody').style.display = document.getElementById('ueSettingsBody').style.display === 'none' ? 'block' : 'none'; this.querySelector('.ue-chevron').classList.toggle('fa-chevron-down'); this.querySelector('.ue-chevron').classList.toggle('fa-chevron-up');">
                <div style="display: flex; align-items: center; gap: 10px;">
                    <i class="fas fa-bullhorn" style="color: #2e7d32; font-size: 18px;"></i>
                    <span style="font-weight: 600; color: #1b5e20; font-size: 15px;">Upcoming Events Section Settings</span>
                    <span style="font-size: 12px; padding: 2px 10px; border-radius: 10px; background: <?php echo $ue_enabled === '1' ? '#4caf50' : '#999'; ?>; color: white;"><?php echo $ue_enabled === '1' ? 'Enabled' : 'Disabled'; ?></span>
                </div>
                <i class="fas fa-chevron-down ue-chevron" style="color: #666; font-size: 13px;"></i>
            </div>
            <div id="ueSettingsBody" style="display: none; margin-top: 16px;">
                <form method="POST" style="display: flex; flex-direction: column; gap: 14px;">
                    <input type="hidden" name="action" value="save_upcoming_settings">
                    
                    <div style="display: flex; align-items: center; gap: 8px;">
                        <input type="checkbox" name="ue_enabled" id="ueEnabled" <?php echo $ue_enabled === '1' ? 'checked' : ''; ?> style="width: auto;">
                        <label for="ueEnabled" style="font-weight: 500; font-size: 14px; color: #333;">Enable Upcoming Events section on public pages</label>
                    </div>
                    
                    <div style="display: flex; flex-wrap: wrap; gap: 16px; align-items: flex-end;">
                        <div style="flex: 1; min-width: 180px;">
                            <label style="font-size: 13px; font-weight: 500; color: #555; display: block; margin-bottom: 4px;">Max Events to Display</label>
                            <select name="ue_max_display" style="width: 100%; padding: 8px 12px; border: 1px solid #ccc; border-radius: 8px; font-size: 14px; background: white;">
                                <?php for ($i = 1; $i <= 8; $i++): ?>
                                <option value="<?php echo $i; ?>" <?php echo $ue_max_display == $i ? 'selected' : ''; ?>><?php echo $i; ?> event<?php echo $i > 1 ? 's' : ''; ?></option>
                                <?php endfor; ?>
                            </select>
                        </div>
                    </div>
                    
                    <div>
                        <label style="font-size: 13px; font-weight: 500; color: #555; display: block; margin-bottom: 6px;">Show on Pages</label>
                        <div style="display: flex; flex-wrap: wrap; gap: 8px 16px;">
                            <?php foreach ($available_pages as $page_key => $page_label): ?>
                            <label style="display: flex; align-items: center; gap: 6px; font-size: 13px; color: #444; cursor: pointer;">
                                <input type="checkbox" name="ue_pages[]" value="<?php echo $page_key; ?>" <?php echo in_array($page_key, $ue_pages) ? 'checked' : ''; ?> style="width: auto;">
                                <?php echo $page_label; ?>
                            </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    
                    <div>
                        <button type="submit" style="background: #2e7d32; color: white; border: none; padding: 10px 24px; border-radius: 8px; font-size: 14px; font-weight: 500; cursor: pointer; transition: background 0.2s;" onmouseover="this.style.background='#1b5e20'" onmouseout="this.style.background='#2e7d32'">
                            <i class="fas fa-save"></i> Save Settings
                        </button>
                    </div>
                </form>
                <p style="margin-top: 10px; font-size: 12px; color: #666;">
                    <i class="fas fa-info-circle"></i> Use the <strong>"Upcoming"</strong> button on each event card below to choose which events appear in this section. Only active, future-dated events marked for the upcoming section will display.
                </p>
            </div>
        </div>

        <div class="events-section">
            <?php if (!empty($events)): ?>
                <div class="events-grid">
                    <?php foreach ($events as $event): ?>
                    <div class="event-card <?php echo $event['is_expired'] ? 'expired' : ''; ?>">
                        <?php 
                            // Prioritize video over image
                            $hasVideo = !empty($event['video_path']);
                            $imgSrc = $event['image_path'] ?? '';
                            if ($imgSrc && !preg_match('#^https?://#i', $imgSrc)) {
                                $imgSrc = '../' . $imgSrc;
                            }
                        ?>
                        
                        <?php if ($hasVideo): ?>
                            <div style="width: 100%; aspect-ratio: 16/10; overflow: hidden; background: #000;">
                                <?php echo renderVideoEmbed($event['video_path'], $event['video_type'], ['autoplay' => false, 'muted' => false, 'style' => 'width: 100%; height: 100%; object-fit: cover;']); ?>
                            </div>
                        <?php elseif ($imgSrc): ?>
                            <img src="<?php echo htmlspecialchars($imgSrc); ?>" 
                                 alt="<?php echo htmlspecialchars($event['title']); ?>" 
                                 class="event-card-image"
                                 onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                            <div class="no-image-placeholder" style="display:none;"><i class="fas fa-calendar-alt"></i></div>
                        <?php else: ?>
                            <div class="no-image-placeholder"><i class="fas fa-calendar-alt"></i></div>
                        <?php endif; ?>
                        
                        <div class="event-card-body">
                            <div class="event-card-title"><?php echo htmlspecialchars($event['title']); ?></div>
                            <?php if (!empty($event['description'])): ?>
                                <div class="event-card-desc"><?php echo htmlspecialchars(substr($event['description'], 0, 100)); ?></div>
                            <?php endif; ?>
                            
                            <div class="event-card-info">
                                <div class="event-card-info-item">
                                    <i class="fas fa-calendar"></i>
                                    <span><?php echo date('M d, Y', strtotime($event['event_date'])); ?></span>
                                </div>
                                <?php if ($event['start_time']): ?>
                                <div class="event-card-info-item">
                                    <i class="fas fa-clock"></i>
                                    <span><?php echo date('H:i', strtotime($event['start_time'])); ?> - <?php echo date('H:i', strtotime($event['end_time'])); ?></span>
                                </div>
                                <?php endif; ?>
                                <?php if ($event['location']): ?>
                                <div class="event-card-info-item event-card-info-full">
                                    <i class="fas fa-map-marker-alt"></i>
                                    <span><?php echo htmlspecialchars($event['location']); ?></span>
                                </div>
                                <?php endif; ?>
                                <?php if ($event['capacity']): ?>
                                <div class="event-card-info-item">
                                    <i class="fas fa-users"></i>
                                    <span><?php echo $event['capacity']; ?> seats</span>
                                </div>
                                <?php endif; ?>
                            </div>
                            
                            <div class="event-card-meta">
                                <?php if ($event['is_expired']): ?>
                                    <span class="event-badge badge-expired"><i class="fas fa-calendar-times"></i> Expired</span>
                                <?php endif; ?>
                                <?php if ($event['is_active']): ?>
                                    <span class="event-badge badge-active"><i class="fas fa-check-circle"></i> Active</span>
                                <?php else: ?>
                                    <span class="event-badge badge-inactive"><i class="fas fa-times-circle"></i> Inactive</span>
                                <?php endif; ?>
                                <?php if ($event['is_featured']): ?>
                                    <span class="event-badge badge-featured"><i class="fas fa-star"></i> Featured</span>
                                <?php endif; ?>
                                <?php if (!empty($event['show_in_upcoming'])): ?>
                                    <span class="event-badge badge-upcoming"><i class="fas fa-bullhorn"></i> Upcoming Section</span>
                                <?php endif; ?>
                                <?php if ($event['ticket_price'] == 0): ?>
                                    <span class="event-badge badge-free"><i class="fas fa-ticket-alt"></i> Free</span>
                                <?php else: ?>
                                    <span class="event-badge badge-price"><i class="fas fa-tag"></i> <?php echo htmlspecialchars(getSetting('currency_symbol')); ?><?php echo number_format($event['ticket_price'], 0); ?></span>
                                <?php endif; ?>
                                <?php if (!empty($event['video_path'])): ?>
                                    <span class="event-badge badge-video"><i class="fas fa-video"></i> Video</span>
                                <?php endif; ?>
                                <span style="font-size:11px; color:#999;">Order: <?php echo $event['display_order']; ?></span>
                            </div>
                            
                            <div class="event-card-actions">
                                <button class="btn-action btn-edit" type="button" onclick='openEditModal(<?php echo htmlspecialchars(json_encode($event), ENT_QUOTES, "UTF-8"); ?>)'>
                                    <i class="fas fa-edit"></i> Edit
                                </button>
                                <button class="btn-action btn-toggle" type="button" onclick="toggleActive(<?php echo $event['id']; ?>)">
                                    <i class="fas fa-power-off"></i> Toggle
                                </button>
                                <button class="btn-action btn-featured" type="button" onclick="toggleFeatured(<?php echo $event['id']; ?>)">
                                    <i class="fas fa-star"></i> Featured
                                </button>
                                <button class="btn-action btn-upcoming" type="button" onclick="toggleUpcoming(<?php echo $event['id']; ?>)">
                                    <i class="fas fa-bullhorn"></i> Upcoming
                                </button>
                                <button class="btn-action btn-delete" type="button" onclick="if(confirm('Delete this event?')) deleteEvent(<?php echo $event['id']; ?>)">
                                    <i class="fas fa-trash-alt"></i> Delete
                                </button>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div style="text-align: center; padding: 60px 20px; color: #999;">
                    <i class="fas fa-calendar-times" style="font-size: 64px; margin-bottom: 16px; color: #ddd;"></i>
                    <p>No events found. Click "Add New Event" to create your first event.</p>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Add Event Modal -->
    <?php
    renderModal('eventModal', 'Add New Event', '
        <form method="POST" action="events-management.php" id="eventForm" enctype="multipart/form-data">
            <input type="hidden" name="action" id="formAction" value="add">
            <input type="hidden" name="id" id="eventId">
            
            <div class="form-group">
                <label>Event Title *</label>
                <input type="text" name="title" id="eventTitle" required>
            </div>
            
            <div class="form-group">
                <label>Description *</label>
                <textarea name="description" id="eventDescription" required></textarea>
            </div>
            
            <div class="form-group">
                <label>Event Date *</label>
                <input type="date" name="event_date" id="eventDate" required>
            </div>
            
            <div class="form-group">
                <label>Start Time</label>
                <input type="time" name="start_time" id="eventStartTime">
            </div>
            
            <div class="form-group">
                <label>End Time</label>
                <input type="time" name="end_time" id="eventEndTime">
            </div>
            
            <div class="form-group">
                <label>Location</label>
                <input type="text" name="location" id="eventLocation" placeholder="e.g., Grand Conference Hall">
            </div>
            
            <div class="form-group">
                <label>Ticket Price (' . htmlspecialchars(getSetting('currency_symbol')) . ')</label>
                <input type="number" name="ticket_price" id="eventPrice" step="0.01" value="0">
                <small style="color: #666;">Enter 0 for free events</small>
            </div>
            
            <div class="form-group">
                <label>Capacity</label>
                <input type="number" name="capacity" id="eventCapacity">
            </div>
            
            <div class="form-group">
                <label>Display Order</label>
                <input type="number" name="display_order" id="eventOrder" value="0">
            </div>

            <div class="form-group">
                <label>Event Image</label>
                <div class="image-upload-wrapper">
                    <div class="image-upload-area" onclick="document.getElementById(\'eventImage\').click()">
                        <i class="fas fa-cloud-upload-alt"></i>
                        <div style="font-weight: 600; margin-bottom: 4px;">Click to Upload Event Image</div>
                        <div style="font-size: 12px; color: #999;">Recommended: 1200x800px (JPG, PNG, WebP)</div>
                    </div>
                    <input type="file" name="image" id="eventImage" accept="image/jpeg,image/png,image/jpg,image/webp" style="display: none;" onchange="previewModalImage(this)">
                    <div id="imagePreviewContainer" style="margin-top: 16px; display: none;">
                        <img id="imagePreview" class="current-image-preview" alt="Preview">
                        <div style="font-size: 12px; color: #666; margin-top: 8px;">
                            <i class="fas fa-check-circle" style="color: #28a745;"></i>
                            <span id="imageFileName"></span>
                        </div>
                    </div>
                </div>
            </div>

            <div class="form-group">
                <label>Event Video (Optional)</label>
                
                <!-- Video URL Input -->
                <div style="margin-bottom: 12px;">
                    <label style="font-size: 13px; font-weight: 500; display: block; margin-bottom: 4px;">
                        <i class="fas fa-link"></i> Video URL (YouTube, Vimeo, or direct link)
                    </label>
                    <input type="url" name="video_url" id="eventVideoUrl" 
                           placeholder="https://www.youtube.com/watch?v=... or https://vimeo.com/..."
                           style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 6px; font-size: 14px;">
                    <small style="color: #888;">Paste a YouTube, Vimeo, Dailymotion, or direct video URL</small>
                </div>
                
                <div style="text-align: center; color: #999; font-size: 12px; margin-bottom: 12px;">— OR upload a file —</div>
                
                <input type="hidden" name="remove_video" id="removeVideoFlag" value="0">
                <div id="removeVideoSection" style="display: none; margin-bottom: 12px;">
                    <div style="background: #fff3cd; padding: 10px 14px; border-radius: 8px; border: 1px solid #ffc107; display: flex; align-items: center; justify-content: space-between;">
                        <span style="font-size: 13px; color: #856404;"><i class="fas fa-video"></i> <span id="currentVideoLabel">Current video attached</span></span>
                        <button type="button" onclick="removeEventVideo()" style="background: #dc3545; color: white; border: none; padding: 6px 14px; border-radius: 5px; cursor: pointer; font-size: 12px; font-weight: 600;">
                            <i class="fas fa-trash-alt"></i> Remove Video
                        </button>
                    </div>
                </div>
                
                <div class="video-upload-wrapper">
                    <div class="image-upload-area" onclick="document.getElementById(\'eventVideo\').click()" style="border-color: #9b59b6;">
                        <i class="fas fa-video" style="color: #9b59b6;"></i>
                        <div style="font-weight: 600; margin-bottom: 4px;">Click to Upload Event Video</div>
                        <div style="font-size: 12px; color: #999;">MP4, WebM, OGG (Max 100MB)</div>
                    </div>
                    <input type="file" name="video" id="eventVideo" accept="video/*" style="display: none;" onchange="previewModalVideo(this)">
                    <div id="videoPreviewContainer" style="margin-top: 16px; display: none;">
                        <div style="background: #f3e5f5; padding: 12px; border-radius: 8px; border: 1px solid #9b59b6;">
                            <i class="fas fa-check-circle" style="color: #9b59b6;"></i>
                            <span id="videoFileName" style="font-size: 14px; color: #4a148c;"></span>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="form-group checkbox-group">
                <input type="checkbox" name="is_featured" id="eventFeatured">
                <label for="eventFeatured">Feature this event</label>
            </div>
            
            <div class="form-group checkbox-group">
                <input type="checkbox" name="show_in_upcoming" id="eventUpcoming">
                <label for="eventUpcoming">Show in Upcoming Events section (homepage)</label>
            </div>
            
            <div class="form-group checkbox-group">
                <input type="checkbox" name="is_active" id="eventActive" checked>
                <label for="eventActive">Active (visible on website)</label>
            </div>
            
            <div style="display: flex; gap: 10px; justify-content: flex-end; padding-top: 20px; border-top: 1px solid #eee; margin-top: 20px;">
                <button type="button" class="btn-action btn-cancel" onclick="Modal.close(\'eventModal\')">
                    <i class="fas fa-times"></i> Cancel
                </button>
                <button type="submit" class="btn-action btn-save">
                    <i class="fas fa-save"></i> Save
                </button>
            </div>
        </form>
    ', [
        'size' => 'lg'
    ]);
    ?>

    <script src="js/admin-components.js"></script>
    <script>
        function openAddModal() {
            document.getElementById('formAction').value = 'add';
            document.getElementById('eventForm').reset();
            document.getElementById('eventActive').checked = true;
            document.getElementById('imagePreviewContainer').style.display = 'none';
            document.getElementById('videoPreviewContainer').style.display = 'none';
            document.getElementById('removeVideoSection').style.display = 'none';
            document.getElementById('removeVideoFlag').value = '0';
            document.getElementById('eventVideoUrl').value = '';
            
            // Update the modal header
            const modalHeader = document.querySelector('#eventModal .modal-header');
            if (modalHeader) {
                const titleElement = modalHeader.querySelector('span');
                if (titleElement) {
                    titleElement.textContent = 'Add New Event';
                } else {
                    modalHeader.innerHTML = '<span>Add New Event</span><span class="modal-close" data-modal-close>&times;</span>';
                }
            }
            Modal.open('eventModal');
        }

        function removeEventVideo() {
            // Set the hidden flag to tell backend to clear video
            document.getElementById('removeVideoFlag').value = '1';
            // Clear the video URL input
            document.getElementById('eventVideoUrl').value = '';
            // Hide video preview
            document.getElementById('videoPreviewContainer').style.display = 'none';
            // Hide the remove section and show confirmation
            document.getElementById('removeVideoSection').innerHTML = 
                '<div style="background: #f8d7da; padding: 10px 14px; border-radius: 8px; border: 1px solid #f5c6cb;">' +
                '<span style="font-size: 13px; color: #721c24;"><i class="fas fa-check-circle"></i> Video will be removed when you save.</span>' +
                '</div>';
        }

        function openEditModal(event) {
            document.getElementById('formAction').value = 'update';
            document.getElementById('eventId').value = event.id;
            document.getElementById('eventTitle').value = event.title || '';
            document.getElementById('eventDescription').value = event.description || '';
            document.getElementById('eventDate').value = event.event_date || '';
            document.getElementById('eventStartTime').value = event.start_time || '';
            document.getElementById('eventEndTime').value = event.end_time || '';
            document.getElementById('eventLocation').value = event.location || '';
            document.getElementById('eventPrice').value = event.ticket_price || 0;
            document.getElementById('eventCapacity').value = event.capacity || '';
            document.getElementById('eventOrder').value = event.display_order || 0;
            document.getElementById('eventFeatured').checked = event.is_featured == 1;
            document.getElementById('eventUpcoming').checked = event.show_in_upcoming == 1;
            document.getElementById('eventActive').checked = event.is_active == 1;
            
            // Show existing image if available
            if (event.image_path) {
                const imgSrc = event.image_path.startsWith('http') ? event.image_path : '../' + event.image_path;
                document.getElementById('imagePreview').src = imgSrc;
                document.getElementById('imageFileName').textContent = 'Current: ' + (event.image_path.split('/').pop() || 'image');
                document.getElementById('imagePreviewContainer').style.display = 'block';
            } else {
                document.getElementById('imagePreviewContainer').style.display = 'none';
            }
            
            // Show existing video URL if it's an external URL
            if (event.video_path) {
                const isUrl = event.video_path.startsWith('http://') || event.video_path.startsWith('https://');
                if (isUrl) {
                    document.getElementById('eventVideoUrl').value = event.video_path;
                } else {
                    // It's an uploaded file
                    document.getElementById('videoFileName').textContent = 'Current: ' + (event.video_path.split('/').pop() || 'video file');
                    document.getElementById('videoPreviewContainer').style.display = 'block';
                }
                // Show remove video section
                const videoName = event.video_path.split('/').pop() || 'video';
                document.getElementById('currentVideoLabel').textContent = 'Current: ' + videoName;
                document.getElementById('removeVideoSection').style.display = 'block';
                document.getElementById('removeVideoFlag').value = '0';
            } else {
                document.getElementById('eventVideoUrl').value = '';
                document.getElementById('videoPreviewContainer').style.display = 'none';
                document.getElementById('removeVideoSection').style.display = 'none';
                document.getElementById('removeVideoFlag').value = '0';
            }
            
            // Update the modal header
            const modalHeader = document.querySelector('#eventModal .modal-header');
            if (modalHeader) {
                const titleElement = modalHeader.querySelector('span');
                if (titleElement) {
                    titleElement.textContent = 'Edit Event';
                } else {
                    modalHeader.innerHTML = '<span>Edit Event</span><span class="modal-close" data-modal-close>&times;</span>';
                }
            }
            
            Modal.open('eventModal');
        }

        function deleteEvent(id) {
            const formData = new FormData();
            formData.append('action', 'delete');
            formData.append('id', id);

            fetch(window.location.href, {
                method: 'POST',
                body: formData
            })
            .then(response => {
                if (response.ok) {
                    window.location.reload();
                } else {
                    if (typeof Alert !== 'undefined') {
                        Alert.show('Error deleting event', 'error');
                    } else {
                        alert('Error deleting event');
                    }
                }
            })
            .catch(error => {
                console.error('Error:', error);
                if (typeof Alert !== 'undefined') {
                    Alert.show('Error deleting event', 'error');
                } else {
                    alert('Error deleting event');
                }
            });
        }

        function toggleActive(id) {
            const formData = new FormData();
            formData.append('action', 'toggle_active');
            formData.append('id', id);

            fetch(window.location.href, {
                method: 'POST',
                body: formData
            })
            .then(response => {
                if (response.ok) {
                    window.location.reload();
                } else {
                    if (typeof Alert !== 'undefined') {
                        Alert.show('Error toggling status', 'error');
                    } else {
                        alert('Error toggling status');
                    }
                }
            })
            .catch(error => {
                console.error('Error:', error);
                if (typeof Alert !== 'undefined') {
                    Alert.show('Error toggling status', 'error');
                } else {
                    alert('Error toggling status');
                }
            });
        }

        function toggleFeatured(id) {
            const formData = new FormData();
            formData.append('action', 'toggle_featured');
            formData.append('id', id);

            fetch(window.location.href, {
                method: 'POST',
                body: formData
            })
            .then(response => {
                if (response.ok) {
                    window.location.reload();
                } else {
                    if (typeof Alert !== 'undefined') {
                        Alert.show('Error toggling featured status', 'error');
                    } else {
                        alert('Error toggling featured status');
                    }
                }
            })
            .catch(error => {
                console.error('Error:', error);
                if (typeof Alert !== 'undefined') {
                    Alert.show('Error toggling featured status', 'error');
                } else {
                    alert('Error toggling featured status');
                }
            });
        }

        function toggleUpcoming(id) {
            const formData = new FormData();
            formData.append('action', 'toggle_upcoming');
            formData.append('id', id);

            fetch(window.location.href, {
                method: 'POST',
                body: formData
            })
            .then(response => {
                if (response.ok) {
                    window.location.reload();
                } else {
                    if (typeof Alert !== 'undefined') {
                        Alert.show('Error toggling upcoming status', 'error');
                    } else {
                        alert('Error toggling upcoming status');
                    }
                }
            })
            .catch(error => {
                console.error('Error:', error);
                if (typeof Alert !== 'undefined') {
                    Alert.show('Error toggling upcoming status', 'error');
                } else {
                    alert('Error toggling upcoming status');
                }
            });
        }

        function previewModalImage(input) {
            if (input.files && input.files[0]) {
                const reader = new FileReader();

                reader.onload = function(e) {
                    document.getElementById('imagePreview').src = e.target.result;
                    document.getElementById('imageFileName').textContent = input.files[0].name;
                    document.getElementById('imagePreviewContainer').style.display = 'block';
                };

                reader.readAsDataURL(input.files[0]);
            }
        }

        function previewModalVideo(input) {
            if (input.files && input.files[0]) {
                const file = input.files[0];
                document.getElementById('videoFileName').textContent = file.name + ' (' + formatBytes(file.size) + ')';
                document.getElementById('videoPreviewContainer').style.display = 'block';
            }
        }

        function formatBytes(bytes) {
            const units = ['B', 'KB', 'MB', 'GB'];
            let size = bytes;
            let unitIndex = 0;
            
            while (size >= 1024 && unitIndex < units.length - 1) {
                size /= 1024;
                unitIndex++;
            }
            
            return size.toFixed(2) + ' ' + units[unitIndex];
        }
    </script>

    <?php require_once 'includes/admin-footer.php'; ?>
