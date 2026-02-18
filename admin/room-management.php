<?php
/**
 * Room Management - Admin Panel
 * Card-based layout with modal editing and drag-and-drop ordering
 */
require_once 'admin-init.php';

$is_ajax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
if (!$is_ajax) {
    require_once '../includes/alert.php';
}
require_once 'video-upload-handler.php';

// Helpers
function ini_bytes($val) {
    $val = trim((string)$val);
    if ($val === '') return 0;
    $last = strtolower($val[strlen($val) - 1]);
    $num = (int)$val;
    switch ($last) {
        case 'g': $num *= 1024;
        case 'm': $num *= 1024;
        case 'k': $num *= 1024;
    }
    return $num;
}
function is_ajax_request() {
    return !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
}

// Note: $user and $current_page are already set in admin-init.php
$message = '';
$error = '';

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $action = $_POST['action'] ?? '';

        if ($action === 'update') {
            $rooms_available = (int)($_POST['rooms_available'] ?? 0);
            $total_rooms = (int)($_POST['total_rooms'] ?? 0);
            
            if ($rooms_available > $total_rooms) {
                $error = 'Availability cannot exceed total rooms.';
                if (is_ajax_request()) {
                    header('Content-Type: application/json');
                    echo json_encode(['success' => false, 'message' => $error]);
                    exit;
                }
            } else {
                $description = !empty($_POST['description']) ? $_POST['description'] : ($_POST['short_description'] ?? '');
                
                $stmt = $pdo->prepare("
                    UPDATE rooms
                    SET name = ?, description = ?, short_description = ?, price_per_night = ?,
                        price_single_occupancy = ?, price_double_occupancy = ?, price_triple_occupancy = ?,
                        size_sqm = ?, max_guests = ?, rooms_available = ?, total_rooms = ?,
                        bed_type = ?, amenities = ?, is_featured = ?, is_active = ?, display_order = ?
                    WHERE id = ?
                ");
                $stmt->execute([
                    $_POST['name'],
                    $description,
                    $_POST['short_description'] ?? '',
                    $_POST['price_per_night'],
                    $_POST['price_single_occupancy'] ?? null,
                    $_POST['price_double_occupancy'] ?? null,
                    $_POST['price_triple_occupancy'] ?? null,
                    $_POST['size_sqm'] ?? 0,
                    $_POST['max_guests'] ?? 2,
                    $rooms_available,
                    $total_rooms,
                    $_POST['bed_type'] ?? 'Double',
                    $_POST['amenities'] ?? '',
                    isset($_POST['is_featured']) ? 1 : 0,
                    isset($_POST['is_active']) ? 1 : 0,
                    $_POST['display_order'] ?? 0,
                    $_POST['id']
                ]);
                
                require_once __DIR__ . '/../config/cache.php';
                clearRoomCache();
                $message = 'Room updated successfully!';
                
                if (is_ajax_request()) {
                    header('Content-Type: application/json');
                    echo json_encode(['success' => true, 'message' => $message]);
                    exit;
                }
            }

        } elseif ($action === 'toggle_active') {
            $stmt = $pdo->prepare("UPDATE rooms SET is_active = NOT is_active WHERE id = ?");
            $stmt->execute([$_POST['id']]);
            require_once __DIR__ . '/../config/cache.php';
            clearRoomCache();
            $message = 'Room status updated!';
            if (is_ajax_request()) {
                header('Content-Type: application/json');
                echo json_encode(['success' => true]);
                exit;
            }

        } elseif ($action === 'toggle_featured') {
            $stmt = $pdo->prepare("UPDATE rooms SET is_featured = NOT is_featured WHERE id = ?");
            $stmt->execute([$_POST['id']]);
            require_once __DIR__ . '/../config/cache.php';
            clearRoomCache();
            $message = 'Featured status updated!';
            if (is_ajax_request()) {
                header('Content-Type: application/json');
                echo json_encode(['success' => true]);
                exit;
            }

        } elseif ($action === 'upload_image') {
            if (isset($_FILES['room_image']) && $_FILES['room_image']['error'] === 0) {
                $room_id = $_POST['room_id'];
                $allowed = ['jpg', 'jpeg', 'png', 'webp'];
                $filename = $_FILES['room_image']['name'];
                $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
                $size = (int)($_FILES['room_image']['size'] ?? 0);
                
                if ($size > 20 * 1024 * 1024) {
                    $error = 'File too large. Max size is 20MB.';
                    if (is_ajax_request()) {
                        header('Content-Type: application/json');
                        echo json_encode(['success' => false, 'message' => $error]);
                        exit;
                    }
                }
                
                $mime_ok = true;
                if (function_exists('finfo_open')) {
                    $f = finfo_open(FILEINFO_MIME_TYPE);
                    if ($f) {
                        $mime = finfo_file($f, $_FILES['room_image']['tmp_name']);
                        finfo_close($f);
                        $mime_ok = in_array($mime, ['image/jpeg','image/png','image/webp'], true);
                    }
                }

                if (in_array($ext, $allowed) && $mime_ok) {
                    $upload_dir = '../images/rooms/';
                    if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
                    
                    $new_filename = 'room_' . $room_id . '_featured_' . time() . '.' . $ext;
                    $upload_path = $upload_dir . $new_filename;
                    
                    if (move_uploaded_file($_FILES['room_image']['tmp_name'], $upload_path)) {
                        $image_url = 'images/rooms/' . $new_filename;
                        
                        $oldStmt = $pdo->prepare("SELECT image_url FROM rooms WHERE id = ?");
                        $oldStmt->execute([$room_id]);
                        $old_image = $oldStmt->fetchColumn();
                        
                        $stmt = $pdo->prepare("UPDATE rooms SET image_url = ? WHERE id = ?");
                        $stmt->execute([$image_url, $room_id]);
                        $message = 'Room image uploaded successfully!';
                        
                        if ($old_image && !preg_match('#^https?://#i', $old_image)) {
                            $old_path = '../' . $old_image;
                            if (file_exists($old_path)) @unlink($old_path);
                        }
                        
                        require_once __DIR__ . '/../config/cache.php';
                        clearRoomCache();
                        
                        if (is_ajax_request()) {
                            header('Content-Type: application/json');
                            echo json_encode(['success' => true, 'image_url' => $image_url]);
                            exit;
                        }
                    } else {
                        $error = 'Failed to upload image.';
                        if (is_ajax_request()) {
                            header('Content-Type: application/json');
                            echo json_encode(['success' => false, 'message' => $error]);
                            exit;
                        }
                    }
                } else {
                    $error = 'Invalid file type. Only JPG, PNG, and WEBP allowed.';
                    if (is_ajax_request()) {
                        header('Content-Type: application/json');
                        echo json_encode(['success' => false, 'message' => $error]);
                        exit;
                    }
                }
            } else {
                $contentLen = isset($_SERVER['CONTENT_LENGTH']) ? (int)$_SERVER['CONTENT_LENGTH'] : 0;
                $postMax = ini_bytes(ini_get('post_max_size'));
                if ($contentLen > 0 && $postMax > 0 && $contentLen >= $postMax) {
                    $error = 'Upload too large. Server POST limit is ' . ini_get('post_max_size') . '.';
                } else {
                    $err = $_FILES['room_image']['error'] ?? 0;
                    if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) $error = 'File too large.';
                    elseif ($err === UPLOAD_ERR_NO_FILE) $error = 'No image selected.';
                    else $error = 'Upload error (code ' . $err . ').';
                }
                if (is_ajax_request()) {
                    header('Content-Type: application/json');
                    echo json_encode(['success' => false, 'message' => $error]);
                    exit;
                }
            }

        } elseif ($action === 'add_room') {
            $videoUrl = processVideoUrl($_POST['video_url'] ?? '');
            if ($videoUrl) {
                $videoPath = $videoUrl['path'];
                $videoType = $videoUrl['type'];
            } else {
                $videoUpload = uploadVideo($_FILES['video'] ?? null, 'rooms');
                $videoPath = $videoUpload['path'] ?? null;
                $videoType = $videoUpload['type'] ?? null;
            }
            
            $stmt = $pdo->prepare("
                INSERT INTO rooms (name, description, short_description, price_per_night,
                    price_single_occupancy, price_double_occupancy, price_triple_occupancy,
                    size_sqm, max_guests, rooms_available, total_rooms,
                    bed_type, amenities, is_featured, is_active, display_order, video_path, video_type)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $_POST['name'],
                $_POST['description'] ?? $_POST['short_description'] ?? '',
                $_POST['short_description'] ?? '',
                $_POST['price_per_night'] ?? 0,
                $_POST['price_single_occupancy'] ?? null,
                $_POST['price_double_occupancy'] ?? null,
                $_POST['price_triple_occupancy'] ?? null,
                $_POST['size_sqm'] ?? 0,
                $_POST['max_guests'] ?? 2,
                $_POST['rooms_available'] ?? 1,
                $_POST['total_rooms'] ?? 1,
                $_POST['bed_type'] ?? 'Double',
                $_POST['amenities'] ?? '',
                isset($_POST['is_featured']) ? 1 : 0,
                1,
                $_POST['display_order'] ?? 0,
                $videoPath,
                $videoType
            ]);
            
            require_once __DIR__ . '/../config/cache.php';
            clearRoomCache();
            $message = 'New room added successfully!';

        } elseif ($action === 'update_video') {
            $room_id = $_POST['room_id'] ?? $_POST['id'];
            $videoUrl = processVideoUrl($_POST['video_url'] ?? '');
            if ($videoUrl) {
                $videoPath = $videoUrl['path'];
                $videoType = $videoUrl['type'];
            } else {
                $videoUpload = uploadVideo($_FILES['video'] ?? null, 'rooms');
                $videoPath = $videoUpload['path'] ?? null;
                $videoType = $videoUpload['type'] ?? null;
            }
            
            if ($videoPath) {
                $stmt = $pdo->prepare("UPDATE rooms SET video_path = ?, video_type = ? WHERE id = ?");
                $stmt->execute([$videoPath, $videoType, $room_id]);
                require_once __DIR__ . '/../config/cache.php';
                clearRoomCache();
                $message = 'Room video updated successfully!';
            } elseif (isset($_POST['remove_video']) && $_POST['remove_video'] == '1') {
                $stmt = $pdo->prepare("UPDATE rooms SET video_path = NULL, video_type = NULL WHERE id = ?");
                $stmt->execute([$room_id]);
                require_once __DIR__ . '/../config/cache.php';
                clearRoomCache();
                $message = 'Room video removed successfully!';
            } else {
                $error = 'No video URL or file provided.';
            }

        } elseif ($action === 'delete_room') {
            $room_id = $_POST['id'];
            
            $stmt = $pdo->prepare("SELECT image_url FROM rooms WHERE id = ?");
            $stmt->execute([$room_id]);
            $old_image = $stmt->fetchColumn();
            
            // Delete room gallery images
            try {
                $stmt = $pdo->prepare("SELECT image_url FROM room_images WHERE room_id = ?");
                $stmt->execute([$room_id]);
                $gallery_images = $stmt->fetchAll(PDO::FETCH_COLUMN);
                foreach ($gallery_images as $img) {
                    if ($img && !preg_match('#^https?://#i', $img)) {
                        $path = '../' . $img;
                        if (file_exists($path)) @unlink($path);
                    }
                }
                $stmt = $pdo->prepare("DELETE FROM room_images WHERE room_id = ?");
                $stmt->execute([$room_id]);
            } catch (PDOException $e) {
                // room_images table may not exist
            }
            
            $stmt = $pdo->prepare("DELETE FROM rooms WHERE id = ?");
            $stmt->execute([$room_id]);
            
            if ($old_image && !preg_match('#^https?://#i', $old_image)) {
                $path = '../' . $old_image;
                if (file_exists($path)) @unlink($path);
            }
            
            require_once __DIR__ . '/../config/cache.php';
            clearRoomCache();
            $message = 'Room deleted successfully!';
            if (is_ajax_request()) {
                header('Content-Type: application/json');
                echo json_encode(['success' => true]);
                exit;
            }

        } elseif ($action === 'update_order') {
            // Drag-and-drop reorder
            $order = json_decode($_POST['order'] ?? '[]', true);
            if (is_array($order)) {
                $stmt = $pdo->prepare("UPDATE rooms SET display_order = ? WHERE id = ?");
                foreach ($order as $idx => $id) {
                    $stmt->execute([$idx, (int)$id]);
                }
                require_once __DIR__ . '/../config/cache.php';
                clearRoomCache();
                if (is_ajax_request()) {
                    header('Content-Type: application/json');
                    echo json_encode(['success' => true, 'message' => 'Order updated']);
                    exit;
                }
                $message = 'Room order updated!';
            }
        }

    } catch (PDOException $e) {
        $error = 'Error: ' . $e->getMessage();
        if (is_ajax_request()) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => $error]);
            exit;
        }
    }
}

// Fetch all rooms
try {
    $stmt = $pdo->query("SELECT * FROM rooms ORDER BY display_order ASC, name ASC");
    $rooms = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $error = 'Error fetching rooms: ' . $e->getMessage();
    $rooms = [];
}

$currency = htmlspecialchars(getSetting('currency_symbol', 'MWK'));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Room Management - Admin Panel</title>
    
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;500;600;700&family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../css/style.css">
    <link rel="stylesheet" href="../css/theme-dynamic.php">
    <link rel="stylesheet" href="css/admin-styles.css">
    <link rel="stylesheet" href="css/admin-components.css">
    
    <style>
        /* Card Grid Layout - matches gallery-management.php */
        .room-cards-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
            gap: 20px;
            padding: 0;
        }
        .room-card {
            background: white;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 2px 12px rgba(0,0,0,0.08);
            transition: all 0.3s ease;
            border: 2px solid transparent;
            cursor: grab;
            position: relative;
        }
        .room-card:hover {
            box-shadow: 0 6px 24px rgba(0,0,0,0.12);
            transform: translateY(-2px);
        }
        .room-card.dragging {
            opacity: 0.5;
            transform: scale(0.98);
            border-color: var(--gold, #D4AF37);
        }
        .room-card.drag-over {
            border-color: var(--gold, #D4AF37);
            box-shadow: 0 0 0 3px rgba(212, 175, 55, 0.3);
        }
        .room-card-image {
            width: 100%;
            height: 200px;
            object-fit: cover;
            display: block;
            background: #f0f0f0;
        }
        .no-image-placeholder {
            width: 100%;
            height: 200px;
            background: linear-gradient(135deg, #f5f5f5 0%, #e8e8e8 100%);
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            color: #bbb;
            font-size: 48px;
        }
        .no-image-placeholder span {
            font-size: 13px;
            margin-top: 8px;
            font-weight: 500;
        }
        .room-card-body {
            padding: 16px;
        }
        .room-card-title {
            font-size: 17px;
            font-weight: 700;
            color: var(--deep-navy, #0A1929);
            margin-bottom: 6px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .room-card-desc {
            font-size: 13px;
            color: #666;
            margin-bottom: 12px;
            line-height: 1.4;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }
        .room-card-price {
            font-size: 20px;
            font-weight: 700;
            color: var(--gold, #D4AF37);
            margin-bottom: 10px;
        }
        .room-card-price small {
            font-size: 12px;
            font-weight: 400;
            color: #999;
        }
        .room-card-details {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 6px;
            margin-bottom: 12px;
            font-size: 12px;
            color: #555;
        }
        .room-card-details .detail-item {
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .room-card-details .detail-item i {
            color: var(--gold, #D4AF37);
            width: 14px;
            text-align: center;
        }
        .room-card-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
            margin-bottom: 14px;
        }
        .room-badge {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 3px 10px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
        }
        .badge-active { background: #d4edda; color: #155724; }
        .badge-inactive { background: #f8d7da; color: #721c24; }
        .badge-featured { background: #fff3cd; color: #856404; }
        .badge-video { background: #d1ecf1; color: #0c5460; }
        .room-card-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
            border-top: 1px solid #eee;
            padding-top: 12px;
        }
        .btn-action {
            padding: 6px 12px;
            border: none;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s ease;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            white-space: nowrap;
        }
        .btn-action:hover { transform: translateY(-1px); }
        .btn-edit { background: #17a2b8; color: white; }
        .btn-edit:hover { background: #138496; box-shadow: 0 2px 6px rgba(23,162,184,0.3); }
        .btn-toggle-active { background: #ffc107; color: #212529; }
        .btn-toggle-active:hover { background: #e0a800; }
        .btn-toggle-featured { background: var(--gold, #D4AF37); color: var(--deep-navy, #0A1929); }
        .btn-toggle-featured:hover { background: #c19b2e; }
        .btn-image { background: #6f42c1; color: white; }
        .btn-image:hover { background: #5a32a3; }
        .btn-pictures { background: #20c997; color: white; }
        .btn-pictures:hover { background: #1aaf85; }
        .btn-delete { background: #dc3545; color: white; }
        .btn-delete:hover { background: #c82333; }
        .btn-video { background: #007bff; color: white; }
        .btn-video:hover { background: #0069d9; }

        /* Drag handle */
        .drag-handle {
            position: absolute;
            top: 10px;
            left: 10px;
            background: rgba(0,0,0,0.5);
            color: white;
            width: 32px;
            height: 32px;
            border-radius: 6px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: grab;
            z-index: 2;
            opacity: 0;
            transition: opacity 0.2s;
        }
        .room-card:hover .drag-handle { opacity: 1; }

        /* Order badge on image */
        .order-badge {
            position: absolute;
            top: 10px;
            right: 10px;
            background: rgba(0,0,0,0.6);
            color: white;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
            z-index: 2;
        }

        /* Modal styles */
        .modal-overlay {
            display: none;
            position: fixed;
            top: 0; left: 0; width: 100%; height: 100%;
            background: rgba(0,0,0,0.5);
            z-index: 9999;
            justify-content: center;
            align-items: flex-start;
            padding: 40px 20px;
            overflow-y: auto;
            opacity: 1;
            visibility: visible;
            pointer-events: auto;
        }
        .modal-content {
            background: white;
            border-radius: 12px;
            width: 100%;
            max-width: 750px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            animation: modalIn 0.2s ease-out;
        }
        @keyframes modalIn {
            from { opacity: 0; transform: translateY(-20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 20px 24px;
            border-bottom: 1px solid #eee;
        }
        .modal-header h3 {
            margin: 0;
            font-size: 18px;
            color: var(--deep-navy, #0A1929);
        }
        .modal-close {
            background: none;
            border: none;
            font-size: 28px;
            cursor: pointer;
            color: #999;
            line-height: 1;
        }
        .modal-close:hover { color: #333; }
        .modal-body {
            padding: 24px;
            max-height: 70vh;
            overflow-y: auto;
        }
        .form-group {
            margin-bottom: 16px;
        }
        .form-group label {
            display: block;
            font-weight: 600;
            font-size: 13px;
            margin-bottom: 6px;
            color: #333;
        }
        .form-group input,
        .form-group textarea,
        .form-group select {
            width: 100%;
            padding: 10px 14px;
            border: 1px solid #ddd;
            border-radius: 6px;
            font-size: 14px;
            font-family: inherit;
            transition: border-color 0.2s;
            box-sizing: border-box;
        }
        .form-group input:focus,
        .form-group textarea:focus,
        .form-group select:focus {
            border-color: var(--gold, #D4AF37);
            outline: none;
            box-shadow: 0 0 0 3px rgba(212,175,55,0.15);
        }
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
        }
        .form-row-3 {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr;
            gap: 16px;
        }
        .form-actions {
            display: flex;
            gap: 12px;
            justify-content: flex-end;
            padding: 16px 24px;
            border-top: 1px solid #eee;
        }
        .form-section {
            margin-bottom: 20px;
            padding-bottom: 16px;
            border-bottom: 1px solid #f0f0f0;
        }
        .form-section-title {
            font-size: 14px;
            font-weight: 700;
            color: var(--deep-navy, #0A1929);
            margin-bottom: 14px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .form-section-title i { color: var(--gold, #D4AF37); }

        /* Upload area */
        .upload-area {
            border: 2px dashed #ddd;
            border-radius: 8px;
            padding: 30px 20px;
            text-align: center;
            background: #f8f9fa;
            cursor: pointer;
            transition: all 0.3s;
        }
        .upload-area:hover { border-color: var(--gold, #D4AF37); background: #fff; }
        .upload-area.dragover { border-color: var(--gold); background: #fffbf0; }
        .upload-area i { font-size: 36px; color: #ddd; margin-bottom: 8px; }

        /* Page header */
        .page-header-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 12px;
            margin-bottom: 20px;
        }
        .page-header-row h2 { margin: 0; }

        /* Current image in modal */
        .current-image {
            width: 100%;
            border-radius: 8px;
            margin-bottom: 16px;
        }

        /* Checkbox styling */
        .checkbox-row {
            display: flex;
            gap: 20px;
            flex-wrap: wrap;
        }
        .checkbox-row label {
            display: flex;
            align-items: center;
            gap: 8px;
            cursor: pointer;
            font-weight: 500;
        }

        @media (max-width: 768px) {
            .room-cards-grid {
                grid-template-columns: 1fr;
            }
            .form-row, .form-row-3 {
                grid-template-columns: 1fr;
            }
            .modal-content {
                margin: 10px;
            }
        }
    </style>
</head>
<body>
    <?php require_once 'includes/admin-header.php'; ?>

    <div class="content">
        <div class="page-header-row">
            <h2 class="page-title"><i class="fas fa-bed"></i> Manage Hotel Rooms</h2>
            <div style="display:flex; gap:10px; align-items:center;">
                <span style="font-size:12px; color:#999;"><i class="fas fa-arrows-alt"></i> Drag cards to reorder</span>
                <button class="btn-action" type="button" style="background:var(--gold,#D4AF37); color:var(--deep-navy,#05090F); padding:12px 24px; font-size:14px; border-radius:8px;" onclick="openAddModal()">
                    <i class="fas fa-plus"></i> Add New Room
                </button>
            </div>
        </div>

        <?php if ($message): ?>
            <?php showAlert($message, 'success'); ?>
        <?php endif; ?>
        <?php if ($error): ?>
            <?php showAlert($error, 'error'); ?>
        <?php endif; ?>

        <?php if (!empty($rooms)): ?>
        <div class="room-cards-grid" id="roomsGrid">
            <?php foreach ($rooms as $room): ?>
            <div class="room-card" data-id="<?php echo $room['id']; ?>" draggable="true">
                <div class="drag-handle"><i class="fas fa-grip-vertical"></i></div>
                <span class="order-badge">#<?php echo $room['display_order']; ?></span>

                <?php if (!empty($room['image_url'])): ?>
                    <?php $imgSrc = preg_match('#^https?://#i', $room['image_url']) ? $room['image_url'] : '../' . $room['image_url']; ?>
                    <img src="<?php echo htmlspecialchars($imgSrc); ?>" 
                         alt="<?php echo htmlspecialchars($room['name']); ?>" 
                         class="room-card-image"
                         onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                    <div class="no-image-placeholder" style="display:none;"><i class="fas fa-bed"></i><span>No Image</span></div>
                <?php else: ?>
                    <div class="no-image-placeholder"><i class="fas fa-bed"></i><span>No Image</span></div>
                <?php endif; ?>

                <div class="room-card-body">
                    <div class="room-card-title">
                        <?php echo htmlspecialchars($room['name']); ?>
                    </div>
                    <div class="room-card-desc"><?php echo htmlspecialchars($room['short_description'] ?? $room['description'] ?? ''); ?></div>
                    
                    <div class="room-card-price">
                        <?php echo $currency; ?> <?php echo number_format($room['price_per_night'], 0); ?>
                        <small>/ night</small>
                    </div>

                    <div class="room-card-details">
                        <div class="detail-item"><i class="fas fa-expand-arrows-alt"></i> <?php echo $room['size_sqm'] ?? 0; ?> sqm</div>
                        <div class="detail-item"><i class="fas fa-users"></i> Max <?php echo $room['max_guests'] ?? 2; ?> guests</div>
                        <div class="detail-item"><i class="fas fa-bed"></i> <?php echo htmlspecialchars($room['bed_type'] ?? 'N/A'); ?></div>
                        <div class="detail-item">
                            <i class="fas fa-door-open"></i> 
                            <?php 
                            $avail = $room['rooms_available'] ?? 0;
                            $total = $room['total_rooms'] ?? 0;
                            echo $avail . '/' . $total . ' avail';
                            ?>
                        </div>
                    </div>

                    <?php if ($room['price_single_occupancy'] || $room['price_double_occupancy'] || $room['price_triple_occupancy']): ?>
                    <div style="font-size:11px; color:#888; margin-bottom:10px;">
                        <?php if ($room['price_single_occupancy']): ?>Single: <?php echo $currency; ?> <?php echo number_format($room['price_single_occupancy'], 0); ?> <?php endif; ?>
                        <?php if ($room['price_double_occupancy']): ?>| Double: <?php echo $currency; ?> <?php echo number_format($room['price_double_occupancy'], 0); ?> <?php endif; ?>
                        <?php if ($room['price_triple_occupancy']): ?>| Triple: <?php echo $currency; ?> <?php echo number_format($room['price_triple_occupancy'], 0); ?> <?php endif; ?>
                    </div>
                    <?php endif; ?>

                    <div class="room-card-meta">
                        <?php if ($room['is_active']): ?>
                            <span class="room-badge badge-active"><i class="fas fa-check"></i> Active</span>
                        <?php else: ?>
                            <span class="room-badge badge-inactive"><i class="fas fa-times"></i> Inactive</span>
                        <?php endif; ?>
                        <?php if ($room['is_featured']): ?>
                            <span class="room-badge badge-featured"><i class="fas fa-star"></i> Featured</span>
                        <?php endif; ?>
                        <?php if (!empty($room['video_path'])): ?>
                            <span class="room-badge badge-video"><i class="fas fa-video"></i> Video</span>
                        <?php endif; ?>
                    </div>

                    <div class="room-card-actions">
                        <button class="btn-action btn-edit" type="button" onclick='openEditModal(<?php echo htmlspecialchars(json_encode($room), ENT_QUOTES, "UTF-8"); ?>)'>
                            <i class="fas fa-edit"></i> Edit
                        </button>
                        <button class="btn-action btn-image" type="button" onclick="openImageModal(<?php echo $room['id']; ?>, '<?php echo htmlspecialchars($room['name'], ENT_QUOTES); ?>', '<?php echo htmlspecialchars($room['image_url'] ?? '', ENT_QUOTES); ?>')">
                            <i class="fas fa-image"></i>
                        </button>
                        <button class="btn-action btn-pictures" type="button" onclick="openPicturesModal(<?php echo $room['id']; ?>, '<?php echo htmlspecialchars($room['name'], ENT_QUOTES); ?>')">
                            <i class="fas fa-images"></i>
                        </button>
                        <button class="btn-action btn-video" type="button" onclick='openVideoModal(<?php echo $room["id"]; ?>, <?php echo htmlspecialchars(json_encode($room["name"]), ENT_QUOTES, "UTF-8"); ?>, <?php echo htmlspecialchars(json_encode($room["video_path"] ?? ""), ENT_QUOTES, "UTF-8"); ?>, <?php echo htmlspecialchars(json_encode($room["video_type"] ?? ""), ENT_QUOTES, "UTF-8"); ?>)'>
                            <i class="fas fa-video"></i>
                        </button>
                        <button class="btn-action btn-toggle-active" type="button" onclick="toggleActive(<?php echo $room['id']; ?>)" title="Toggle Active">
                            <i class="fas fa-power-off"></i>
                        </button>
                        <button class="btn-action btn-toggle-featured" type="button" onclick="toggleFeatured(<?php echo $room['id']; ?>)" title="Toggle Featured">
                            <i class="fas fa-star"></i>
                        </button>
                        <button class="btn-action btn-delete" type="button" onclick="if(confirm('Delete this room permanently?')) deleteRoom(<?php echo $room['id']; ?>)" title="Delete Room">
                            <i class="fas fa-trash-alt"></i>
                        </button>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php else: ?>
        <div style="text-align:center; padding:60px; color:#999;">
            <i class="fas fa-bed" style="font-size:64px; margin-bottom:16px; color:#ddd; display:block;"></i>
            <p>No rooms found. Click "Add New Room" to get started.</p>
        </div>
        <?php endif; ?>

    <!-- Edit Room Modal -->
    <div class="modal-overlay" id="editModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 id="editModalTitle"><i class="fas fa-edit"></i> Edit Room</h3>
                <button class="modal-close" type="button" onclick="closeEditModal()">&times;</button>
            </div>
            <form method="POST" id="editForm">
                <input type="hidden" name="action" id="editAction" value="update">
                <input type="hidden" name="id" id="editId">
                
                <div class="modal-body">
                    <div class="form-section">
                        <div class="form-section-title"><i class="fas fa-info-circle"></i> Room Information</div>
                        <div class="form-group">
                            <label>Room Name *</label>
                            <input type="text" name="name" id="editName" required>
                        </div>
                        <div class="form-group">
                            <label>Short Description</label>
                            <textarea name="short_description" id="editShortDesc" rows="2"></textarea>
                        </div>
                        <div class="form-group">
                            <label>Full Description</label>
                            <textarea name="description" id="editDescription" rows="3"></textarea>
                        </div>
                    </div>

                    <div class="form-section">
                        <div class="form-section-title"><i class="fas fa-dollar-sign"></i> Pricing</div>
                        <div class="form-row">
                            <div class="form-group">
                                <label>Price Per Night *</label>
                                <input type="number" name="price_per_night" id="editPrice" step="0.01" required>
                            </div>
                            <div class="form-group">
                                <label>Single Occupancy Price</label>
                                <input type="number" name="price_single_occupancy" id="editPriceSingle" step="0.01" placeholder="Optional">
                            </div>
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label>Double Occupancy Price</label>
                                <input type="number" name="price_double_occupancy" id="editPriceDouble" step="0.01" placeholder="Optional">
                            </div>
                            <div class="form-group">
                                <label>Triple Occupancy Price</label>
                                <input type="number" name="price_triple_occupancy" id="editPriceTriple" step="0.01" placeholder="Optional">
                            </div>
                        </div>
                    </div>

                    <div class="form-section">
                        <div class="form-section-title"><i class="fas fa-cog"></i> Room Details</div>
                        <div class="form-row-3">
                            <div class="form-group">
                                <label>Size (sqm)</label>
                                <input type="number" name="size_sqm" id="editSize" min="0">
                            </div>
                            <div class="form-group">
                                <label>Max Guests</label>
                                <input type="number" name="max_guests" id="editGuests" min="1" value="2">
                            </div>
                            <div class="form-group">
                                <label>Bed Type</label>
                                <input type="text" name="bed_type" id="editBedType" placeholder="e.g. King Bed">
                            </div>
                        </div>
                        <div class="form-row-3">
                            <div class="form-group">
                                <label>Rooms Available</label>
                                <input type="number" name="rooms_available" id="editAvailable" min="0">
                            </div>
                            <div class="form-group">
                                <label>Total Rooms</label>
                                <input type="number" name="total_rooms" id="editTotal" min="1">
                            </div>
                            <div class="form-group">
                                <label>Display Order</label>
                                <input type="number" name="display_order" id="editOrder" min="0" value="0">
                            </div>
                        </div>
                        <div class="form-group">
                            <label>Amenities (comma-separated)</label>
                            <textarea name="amenities" id="editAmenities" rows="2" placeholder="WiFi, Air Conditioning, TV, Mini Bar"></textarea>
                        </div>
                    </div>

                    <div class="form-section" style="border-bottom:none;">
                        <div class="form-section-title"><i class="fas fa-toggle-on"></i> Status</div>
                        <div class="checkbox-row">
                            <label>
                                <input type="checkbox" name="is_active" id="editIsActive" value="1"> Active
                            </label>
                            <label>
                                <input type="checkbox" name="is_featured" id="editIsFeatured" value="1"> Featured
                            </label>
                        </div>
                    </div>
                </div>

                <div class="form-actions">
                    <button type="button" onclick="closeEditModal()" style="padding:10px 24px; border:1px solid #ddd; border-radius:6px; background:white; cursor:pointer;">Cancel</button>
                    <button type="submit" style="padding:10px 24px; border:none; border-radius:6px; background:var(--gold,#D4AF37); color:var(--deep-navy,#05090F); font-weight:600; cursor:pointer;">
                        <i class="fas fa-save"></i> Save Changes
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Add Room Modal -->
    <div class="modal-overlay" id="addModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-plus-circle"></i> Add New Room</h3>
                <button class="modal-close" type="button" onclick="closeAddModal()">&times;</button>
            </div>
            <form method="POST" enctype="multipart/form-data" id="addForm">
                <input type="hidden" name="action" value="add_room">
                
                <div class="modal-body">
                    <div class="form-section">
                        <div class="form-section-title"><i class="fas fa-info-circle"></i> Room Information</div>
                        <div class="form-group">
                            <label>Room Name *</label>
                            <input type="text" name="name" required>
                        </div>
                        <div class="form-group">
                            <label>Short Description</label>
                            <textarea name="short_description" rows="2"></textarea>
                        </div>
                        <div class="form-group">
                            <label>Full Description</label>
                            <textarea name="description" rows="3"></textarea>
                        </div>
                    </div>

                    <div class="form-section">
                        <div class="form-section-title"><i class="fas fa-dollar-sign"></i> Pricing</div>
                        <div class="form-row">
                            <div class="form-group">
                                <label>Price Per Night *</label>
                                <input type="number" name="price_per_night" step="0.01" required>
                            </div>
                            <div class="form-group">
                                <label>Single Occupancy Price</label>
                                <input type="number" name="price_single_occupancy" step="0.01" placeholder="Optional">
                            </div>
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label>Double Occupancy Price</label>
                                <input type="number" name="price_double_occupancy" step="0.01" placeholder="Optional">
                            </div>
                            <div class="form-group">
                                <label>Triple Occupancy Price</label>
                                <input type="number" name="price_triple_occupancy" step="0.01" placeholder="Optional">
                            </div>
                        </div>
                    </div>

                    <div class="form-section">
                        <div class="form-section-title"><i class="fas fa-cog"></i> Room Details</div>
                        <div class="form-row-3">
                            <div class="form-group">
                                <label>Size (sqm)</label>
                                <input type="number" name="size_sqm">
                            </div>
                            <div class="form-group">
                                <label>Max Guests</label>
                                <input type="number" name="max_guests" value="2">
                            </div>
                            <div class="form-group">
                                <label>Bed Type</label>
                                <input type="text" name="bed_type" value="Double">
                            </div>
                        </div>
                        <div class="form-row-3">
                            <div class="form-group">
                                <label>Rooms Available</label>
                                <input type="number" name="rooms_available" value="1">
                            </div>
                            <div class="form-group">
                                <label>Total Rooms</label>
                                <input type="number" name="total_rooms" value="1">
                            </div>
                            <div class="form-group">
                                <label>Display Order</label>
                                <input type="number" name="display_order" value="0">
                            </div>
                        </div>
                        <div class="form-group">
                            <label>Amenities (comma-separated)</label>
                            <textarea name="amenities" rows="2" placeholder="WiFi, Air Conditioning, TV, Mini Bar"></textarea>
                        </div>
                    </div>

                    <div class="form-section">
                        <div class="form-section-title"><i class="fas fa-video"></i> Video (Optional)</div>
                        <div class="form-group">
                            <label>Video URL</label>
                            <input type="url" name="video_url" placeholder="https://www.youtube.com/watch?v=... or https://vimeo.com/...">
                            <small style="color:#888;">YouTube, Vimeo, Dailymotion, or direct video URL</small>
                        </div>
                    </div>

                    <div class="form-section" style="border-bottom:none;">
                        <div class="form-section-title"><i class="fas fa-toggle-on"></i> Status</div>
                        <div class="checkbox-row">
                            <label>
                                <input type="checkbox" name="is_featured" value="1"> Featured Room
                            </label>
                        </div>
                    </div>
                </div>

                <div class="form-actions">
                    <button type="button" onclick="closeAddModal()" style="padding:10px 24px; border:1px solid #ddd; border-radius:6px; background:white; cursor:pointer;">Cancel</button>
                    <button type="submit" style="padding:10px 24px; border:none; border-radius:6px; background:var(--gold,#D4AF37); color:var(--deep-navy,#05090F); font-weight:600; cursor:pointer;">
                        <i class="fas fa-plus"></i> Add Room
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Image Upload Modal -->
    <div class="modal-overlay" id="imageModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 id="imageModalTitle"><i class="fas fa-image"></i> Room Image</h3>
                <button class="modal-close" type="button" onclick="closeImageModal()">&times;</button>
            </div>
            <div class="modal-body">
                <div id="currentImageContainer" style="display:none; margin-bottom:20px;">
                    <h4 style="margin-bottom:12px; color:#666;">Current Image</h4>
                    <img id="currentImage" class="current-image" alt="Current room image">
                </div>
                <h4 style="margin:0 0 12px; color:#666;">Upload New Image</h4>
                <form method="POST" enctype="multipart/form-data" id="imageUploadForm">
                    <input type="hidden" name="action" value="upload_image">
                    <input type="hidden" name="room_id" id="uploadRoomId">
                    
                    <div class="upload-area" onclick="document.getElementById('roomImageInput').click()">
                        <i class="fas fa-cloud-upload-alt"></i>
                        <p style="margin:8px 0; color:#666;">Click or drag an image here</p>
                        <small style="color:#999;">JPG, PNG, WEBP (Max 20MB)</small>
                    </div>
                    <div class="form-group" style="margin-top:12px;">
                        <input type="file" name="room_image" id="roomImageInput" accept="image/jpeg,image/png,image/webp" required>
                    </div>
                </form>
            </div>
            <div class="form-actions">
                <button type="button" onclick="closeImageModal()" style="padding:10px 24px; border:1px solid #ddd; border-radius:6px; background:white; cursor:pointer;">Close</button>
            </div>
        </div>
    </div>

    <!-- Video Modal -->
    <div class="modal-overlay" id="videoModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 id="videoModalTitle"><i class="fas fa-video"></i> Room Video</h3>
                <button class="modal-close" type="button" onclick="closeVideoModal()">&times;</button>
            </div>
            <form method="POST" enctype="multipart/form-data" id="videoForm">
                <input type="hidden" name="action" value="update_video">
                <input type="hidden" name="room_id" id="videoRoomId">
                <div class="modal-body">
                    <div id="currentVideoInfo" style="display:none; margin-bottom:16px; background:#f0f7ff; padding:12px; border-radius:6px;">
                        <i class="fas fa-video" style="color:var(--gold);"></i> <span id="currentVideoText"></span>
                        <label style="display:inline-flex; align-items:center; gap:4px; margin-left:12px; cursor:pointer;">
                            <input type="checkbox" name="remove_video" value="1"> Remove video
                        </label>
                    </div>
                    <div class="form-group">
                        <label>Video URL</label>
                        <input type="url" name="video_url" id="videoUrlInput" placeholder="https://www.youtube.com/watch?v=... or https://vimeo.com/...">
                        <small style="color:#888;">YouTube, Vimeo, Dailymotion, or direct video URL</small>
                    </div>
                    <div style="text-align:center; color:#999; font-size:12px; margin:12px 0;">— OR upload a video file —</div>
                    <div class="form-group">
                        <input type="file" name="video" accept="video/*">
                    </div>
                </div>
                <div class="form-actions">
                    <button type="button" onclick="closeVideoModal()" style="padding:10px 24px; border:1px solid #ddd; border-radius:6px; background:white; cursor:pointer;">Cancel</button>
                    <button type="submit" style="padding:10px 24px; border:none; border-radius:6px; background:#007bff; color:white; font-weight:600; cursor:pointer;">
                        <i class="fas fa-save"></i> Save Video
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Pictures Modal -->
    <div class="modal-overlay" id="picturesModal">
        <div class="modal-content" style="max-width:900px;">
            <div class="modal-header">
                <h3 id="picturesModalTitle"><i class="fas fa-images"></i> Room Pictures</h3>
                <button class="modal-close" type="button" onclick="closePicturesModal()">&times;</button>
            </div>
            <div class="modal-body">
                <form method="POST" enctype="multipart/form-data" id="pictureUploadForm" style="margin-bottom:20px; padding-bottom:16px; border-bottom:1px solid #eee;">
                    <input type="hidden" name="action" value="upload_picture">
                    <input type="hidden" name="room_id" id="pictureRoomId">
                    <div class="form-row">
                        <div class="form-group">
                            <label>Image File *</label>
                            <input type="file" name="image" id="pictureFileInput" accept="image/jpeg,image/png,image/webp" required>
                        </div>
                        <div class="form-group">
                            <label>Title</label>
                            <input type="text" name="picture_title" placeholder="Picture title">
                        </div>
                    </div>
                    <div style="display:flex; gap:12px; align-items:center; flex-wrap:wrap;">
                        <div class="form-group" style="flex:1; margin-bottom:0;">
                            <input type="text" name="picture_description" placeholder="Brief description">
                        </div>
                        <label style="display:flex; align-items:center; gap:6px; cursor:pointer; font-size:13px; white-space:nowrap;">
                            <input type="checkbox" name="set_featured"> Featured
                        </label>
                        <button type="submit" class="btn-action" style="background:var(--gold,#D4AF37); color:var(--deep-navy); padding:8px 16px;">
                            <i class="fas fa-upload"></i> Upload
                        </button>
                    </div>
                </form>
                <div id="picturesGrid" style="display:grid; grid-template-columns:repeat(auto-fill, minmax(150px, 1fr)); gap:12px;">
                    <div style="text-align:center; padding:40px; color:#999; grid-column:1/-1;">
                        <i class="fas fa-images"></i> Select a room to view pictures
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
    // ===== EDIT MODAL =====
    function openEditModal(room) {
        document.getElementById('editModalTitle').innerHTML = '<i class="fas fa-edit"></i> Edit: ' + escapeHtml(room.name);
        document.getElementById('editAction').value = 'update';
        document.getElementById('editId').value = room.id;
        document.getElementById('editName').value = room.name || '';
        document.getElementById('editShortDesc').value = room.short_description || '';
        document.getElementById('editDescription').value = room.description || '';
        document.getElementById('editPrice').value = room.price_per_night || '';
        document.getElementById('editPriceSingle').value = room.price_single_occupancy || '';
        document.getElementById('editPriceDouble').value = room.price_double_occupancy || '';
        document.getElementById('editPriceTriple').value = room.price_triple_occupancy || '';
        document.getElementById('editSize').value = room.size_sqm || '';
        document.getElementById('editGuests').value = room.max_guests || 2;
        document.getElementById('editBedType').value = room.bed_type || '';
        document.getElementById('editAvailable').value = room.rooms_available || 0;
        document.getElementById('editTotal').value = room.total_rooms || 0;
        document.getElementById('editOrder').value = room.display_order || 0;
        document.getElementById('editAmenities').value = room.amenities || '';
        document.getElementById('editIsActive').checked = room.is_active == 1;
        document.getElementById('editIsFeatured').checked = room.is_featured == 1;
        document.getElementById('editModal').style.display = 'flex';
    }

    function closeEditModal() {
        document.getElementById('editModal').style.display = 'none';
    }

    // ===== ADD MODAL =====
    function openAddModal() {
        document.getElementById('addModal').style.display = 'flex';
    }
    function closeAddModal() {
        document.getElementById('addModal').style.display = 'none';
    }

    // ===== IMAGE MODAL =====
    function openImageModal(roomId, roomName, currentImageUrl) {
        document.getElementById('imageModalTitle').innerHTML = '<i class="fas fa-image"></i> ' + escapeHtml(roomName);
        document.getElementById('uploadRoomId').value = roomId;
        
        if (currentImageUrl) {
            const src = /^https?:\/\//i.test(currentImageUrl) ? currentImageUrl : ('../' + currentImageUrl);
            document.getElementById('currentImage').src = src;
            document.getElementById('currentImageContainer').style.display = 'block';
        } else {
            document.getElementById('currentImageContainer').style.display = 'none';
        }
        document.getElementById('imageModal').style.display = 'flex';
    }
    function closeImageModal() {
        document.getElementById('imageModal').style.display = 'none';
        document.getElementById('imageUploadForm').reset();
    }

    // Auto-upload on file select
    document.getElementById('roomImageInput').addEventListener('change', function() {
        const form = document.getElementById('imageUploadForm');
        const formData = new FormData(form);
        fetch(window.location.href, {
            method: 'POST',
            body: formData,
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(r => r.json().catch(() => null))
        .then(data => {
            if (data && data.success) {
                const src = /^https?:\/\//i.test(data.image_url) ? data.image_url : ('../' + data.image_url);
                document.getElementById('currentImage').src = src + '?t=' + Date.now();
                document.getElementById('currentImageContainer').style.display = 'block';
                // Update card image
                const card = document.querySelector('.room-card[data-id="' + document.getElementById('uploadRoomId').value + '"]');
                if (card) {
                    let img = card.querySelector('.room-card-image');
                    if (img) {
                        img.src = src + '?t=' + Date.now();
                        img.style.display = '';
                        const placeholder = card.querySelector('.no-image-placeholder');
                        if (placeholder) placeholder.style.display = 'none';
                    }
                }
                form.reset();
            } else {
                alert(data && data.message ? data.message : 'Error uploading image');
            }
        })
        .catch(() => alert('Error uploading image'));
    });

    // ===== VIDEO MODAL =====
    function openVideoModal(roomId, roomName, videoPath, videoType) {
        document.getElementById('videoModalTitle').innerHTML = '<i class="fas fa-video"></i> ' + escapeHtml(roomName);
        document.getElementById('videoRoomId').value = roomId;
        document.getElementById('videoUrlInput').value = '';
        
        if (videoPath) {
            const text = videoPath.substring(0, 60) + (videoPath.length > 60 ? '...' : '') + ' (' + (videoType || 'unknown') + ')';
            document.getElementById('currentVideoText').textContent = text;
            document.getElementById('currentVideoInfo').style.display = 'block';
            if (/^https?:\/\//i.test(videoPath)) {
                document.getElementById('videoUrlInput').value = videoPath;
            }
        } else {
            document.getElementById('currentVideoInfo').style.display = 'none';
        }
        document.getElementById('videoModal').style.display = 'flex';
    }
    function closeVideoModal() {
        document.getElementById('videoModal').style.display = 'none';
        document.getElementById('videoForm').reset();
    }

    // ===== PICTURES MODAL =====
    function openPicturesModal(roomId, roomName) {
        document.getElementById('picturesModalTitle').innerHTML = '<i class="fas fa-images"></i> ' + escapeHtml(roomName) + ' - Pictures';
        document.getElementById('pictureRoomId').value = roomId;
        document.getElementById('picturesModal').style.display = 'flex';
        loadRoomPictures(roomId);
    }
    function closePicturesModal() {
        document.getElementById('picturesModal').style.display = 'none';
        document.getElementById('pictureUploadForm').reset();
    }

    function loadRoomPictures(roomId) {
        const grid = document.getElementById('picturesGrid');
        grid.innerHTML = '<div style="text-align:center; padding:40px; color:#999; grid-column:1/-1;"><i class="fas fa-spinner fa-spin"></i> Loading...</div>';
        
        fetch('api/room-pictures.php?room_id=' + roomId)
            .then(r => r.json())
            .then(data => {
                if (data.success && data.data && data.data.gallery) {
                    renderPictures(data.data.gallery);
                } else {
                    grid.innerHTML = '<div style="text-align:center; padding:40px; color:#999; grid-column:1/-1;"><i class="fas fa-images"></i> No pictures yet</div>';
                }
            })
            .catch(() => {
                grid.innerHTML = '<div style="text-align:center; padding:40px; color:#dc3545; grid-column:1/-1;">Error loading pictures</div>';
            });
    }

    function renderPictures(pictures) {
        const grid = document.getElementById('picturesGrid');
        if (!pictures || pictures.length === 0) {
            grid.innerHTML = '<div style="text-align:center; padding:40px; color:#999; grid-column:1/-1;"><i class="fas fa-images"></i> No pictures yet</div>';
            return;
        }
        grid.innerHTML = pictures.map(function(p) {
            var src = /^https?:\/\//i.test(p.image_url) ? p.image_url : ('../' + p.image_url);
            var featured = p.is_featured ? '<span style="position:absolute; top:4px; left:4px; background:var(--gold); color:white; padding:2px 6px; border-radius:4px; font-size:10px;"><i class="fas fa-star"></i></span>' : '';
            return '<div style="position:relative; border-radius:8px; overflow:hidden; border:1px solid #eee;">' +
                '<img src="' + src + '" alt="' + escapeHtml(p.title || '') + '" style="width:100%; height:120px; object-fit:cover;">' +
                featured +
                '<div style="padding:6px; font-size:11px;">' +
                '<div style="font-weight:600;">' + escapeHtml(p.title || 'Untitled') + '</div>' +
                '<div style="display:flex; gap:4px; margin-top:4px;">' +
                (!p.is_featured ? '<button class="btn-action" style="background:var(--gold); color:white; padding:2px 6px; font-size:10px;" onclick="setFeaturedPicture(' + p.id + ')"><i class="fas fa-star"></i></button>' : '') +
                '<button class="btn-action btn-delete" style="padding:2px 6px; font-size:10px;" onclick="deletePicture(' + p.id + ')"><i class="fas fa-trash"></i></button>' +
                '</div></div></div>';
        }).join('');
    }

    function setFeaturedPicture(pictureId) {
        if (!confirm('Set as featured image?')) return;
        var roomId = document.getElementById('pictureRoomId').value;
        fetch('api/room-pictures.php?action=set_featured', {
            method: 'PUT',
            headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
            body: JSON.stringify({ room_id: roomId, picture_id: pictureId })
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.success) {
                loadRoomPictures(roomId);
                setTimeout(function() { window.location.reload(); }, 1000);
            } else { alert('Error: ' + data.message); }
        })
        .catch(function() { alert('Error'); });
    }

    function deletePicture(pictureId) {
        if (!confirm('Delete this picture?')) return;
        var roomId = document.getElementById('pictureRoomId').value;
        fetch('api/room-pictures.php?picture_id=' + pictureId, {
            method: 'DELETE',
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.success) loadRoomPictures(roomId);
            else alert('Error: ' + data.message);
        })
        .catch(function() { alert('Error'); });
    }

    // Handle picture upload
    document.getElementById('pictureUploadForm').addEventListener('submit', function(e) {
        e.preventDefault();
        var self = this;
        fetch('api/room-pictures.php', {
            method: 'POST',
            body: new FormData(self),
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.success) {
                self.reset();
                loadRoomPictures(document.getElementById('pictureRoomId').value);
            } else { alert(data.message || 'Error'); }
        })
        .catch(function() { alert('Error uploading'); });
    });

    // ===== TOGGLE & DELETE =====
    function toggleActive(id) {
        var fd = new FormData();
        fd.append('action', 'toggle_active');
        fd.append('id', id);
        fetch(window.location.href, {
            method: 'POST',
            body: fd,
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
            .then(function(r) { if (r.ok) window.location.reload(); else alert('Error'); })
            .catch(function() { alert('Error'); });
    }

    function toggleFeatured(id) {
        var fd = new FormData();
        fd.append('action', 'toggle_featured');
        fd.append('id', id);
        fetch(window.location.href, {
            method: 'POST',
            body: fd,
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
            .then(function(r) { if (r.ok) window.location.reload(); else alert('Error'); })
            .catch(function() { alert('Error'); });
    }

    function deleteRoom(id) {
        var fd = new FormData();
        fd.append('action', 'delete_room');
        fd.append('id', id);
        fetch(window.location.href, {
            method: 'POST',
            body: fd,
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
            .then(function(r) { if (r.ok) window.location.reload(); else alert('Error'); })
            .catch(function() { alert('Error'); });
    }

    // ===== DRAG AND DROP =====
    var dragSrcEl = null;
    var grid = document.getElementById('roomsGrid');

    if (grid) {
        grid.addEventListener('dragstart', function(e) {
            if (!e.target.closest('.drag-handle')) {
                e.preventDefault();
                return;
            }
            var card = e.target.closest('.room-card');
            if (!card) return;
            dragSrcEl = card;
            card.classList.add('dragging');
            e.dataTransfer.effectAllowed = 'move';
            e.dataTransfer.setData('text/plain', card.dataset.id);
        });

        grid.addEventListener('dragover', function(e) {
            e.preventDefault();
            e.dataTransfer.dropEffect = 'move';
            var card = e.target.closest('.room-card');
            if (card && card !== dragSrcEl) {
                grid.querySelectorAll('.room-card').forEach(function(c) { c.classList.remove('drag-over'); });
                card.classList.add('drag-over');
            }
        });

        grid.addEventListener('dragleave', function(e) {
            var card = e.target.closest('.room-card');
            if (card) card.classList.remove('drag-over');
        });

        grid.addEventListener('drop', function(e) {
            e.preventDefault();
            var targetCard = e.target.closest('.room-card');
            if (!targetCard || !dragSrcEl || targetCard === dragSrcEl) return;
            
            var cards = Array.from(grid.querySelectorAll('.room-card'));
            var srcIdx = cards.indexOf(dragSrcEl);
            var targetIdx = cards.indexOf(targetCard);
            
            if (srcIdx < targetIdx) {
                targetCard.parentNode.insertBefore(dragSrcEl, targetCard.nextSibling);
            } else {
                targetCard.parentNode.insertBefore(dragSrcEl, targetCard);
            }
            
            saveOrder();
        });

        grid.addEventListener('dragend', function() {
            grid.querySelectorAll('.room-card').forEach(function(c) {
                c.classList.remove('dragging', 'drag-over');
            });
            dragSrcEl = null;
        });
    }

    function saveOrder() {
        var cards = document.querySelectorAll('.room-card');
        var order = Array.from(cards).map(function(c) { return c.dataset.id; });
        
        var fd = new FormData();
        fd.append('action', 'update_order');
        fd.append('order', JSON.stringify(order));
        
        fetch(window.location.href, {
            method: 'POST',
            body: fd,
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.success) {
                cards.forEach(function(card, idx) {
                    var badge = card.querySelector('.order-badge');
                    if (badge) badge.textContent = '#' + idx;
                });
            }
        })
        .catch(function(err) { console.error('Error saving order:', err); });
    }

    // ===== CLOSE MODALS ON OUTSIDE CLICK =====
    document.querySelectorAll('.modal-overlay').forEach(function(modal) {
        modal.addEventListener('click', function(e) {
            if (e.target === this) {
                this.style.display = 'none';
            }
        });
    });

    // Upload area drag & drop for images
    document.querySelectorAll('.upload-area').forEach(function(area) {
        area.addEventListener('dragover', function(e) { e.preventDefault(); e.stopPropagation(); area.classList.add('dragover'); });
        area.addEventListener('dragleave', function() { area.classList.remove('dragover'); });
        area.addEventListener('drop', function(e) {
            e.preventDefault();
            e.stopPropagation();
            area.classList.remove('dragover');
            var form = area.closest('form');
            var input = form ? form.querySelector('input[type="file"]') : null;
            if (input && e.dataTransfer.files.length) input.files = e.dataTransfer.files;
        });
    });

    // Helper
    function escapeHtml(str) {
        if (!str) return '';
        return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;');
    }
    </script>

    <?php require_once 'includes/admin-footer.php'; ?>
