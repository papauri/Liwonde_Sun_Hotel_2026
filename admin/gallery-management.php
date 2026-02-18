<?php
/**
 * Gallery Management - Admin Panel
 * Manage hotel gallery images and videos (hotel_gallery table)
 */

require_once 'admin-init.php';
require_once '../includes/alert.php';
require_once 'video-upload-handler.php';

// Note: $user and $current_page are already set in admin-init.php
$message = '';
$error = '';

// Helper: upload gallery image
function uploadGalleryImage($fileInput) {
    if (!$fileInput || !isset($fileInput['tmp_name']) || $fileInput['error'] !== UPLOAD_ERR_OK) {
        return null;
    }
    $uploadDir = __DIR__ . '/../images/hotel_gallery/';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }
    $ext = pathinfo($fileInput['name'], PATHINFO_EXTENSION) ?: 'jpg';
    $filename = 'gallery_' . time() . '_' . random_int(1000, 9999) . '.' . strtolower($ext);
    $relativePath = 'images/hotel_gallery/' . $filename;
    $destination = $uploadDir . $filename;
    if (move_uploaded_file($fileInput['tmp_name'], $destination)) {
        return $relativePath;
    }
    return null;
}

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $action = $_POST['action'] ?? '';

        if ($action === 'add') {
            $imagePath = uploadGalleryImage($_FILES['image'] ?? null);
            $imageUrl = $imagePath ?: ($_POST['image_url_external'] ?? '');
            
            if (empty($imageUrl)) {
                $error = 'Please provide an image (upload or URL).';
            } else {
                // Handle video
                $videoUrl = processVideoUrl($_POST['video_url'] ?? '');
                $videoPath = $videoUrl['path'] ?? null;
                $videoType = $videoUrl['type'] ?? null;
                
                if (!$videoPath) {
                    $videoUpload = uploadVideo($_FILES['video'] ?? null, 'gallery');
                    $videoPath = $videoUpload['path'] ?? null;
                    $videoType = $videoUpload['type'] ?? null;
                }

                $stmt = $pdo->prepare("
                    INSERT INTO hotel_gallery (title, description, image_url, video_path, video_type, category, is_active, display_order)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $_POST['title'],
                    $_POST['description'] ?? '',
                    $imageUrl,
                    $videoPath,
                    $videoType,
                    $_POST['category'] ?? 'general',
                    isset($_POST['is_active']) ? 1 : 1,
                    $_POST['display_order'] ?? 0
                ]);
                $message = 'Gallery item added successfully!';
            }

        } elseif ($action === 'update') {
            $imagePath = uploadGalleryImage($_FILES['image'] ?? null);
            
            // Handle video
            $videoUrl = processVideoUrl($_POST['video_url'] ?? '');
            $videoPath = $videoUrl['path'] ?? null;
            $videoType = $videoUrl['type'] ?? null;
            
            if (!$videoPath) {
                $videoUpload = uploadVideo($_FILES['video'] ?? null, 'gallery');
                $videoPath = $videoUpload['path'] ?? null;
                $videoType = $videoUpload['type'] ?? null;
            }

            $updateFields = ['title = ?', 'description = ?', 'category = ?', 'display_order = ?'];
            $updateValues = [
                $_POST['title'],
                $_POST['description'] ?? '',
                $_POST['category'] ?? 'general',
                $_POST['display_order'] ?? 0
            ];

            if ($imagePath) {
                $updateFields[] = 'image_url = ?';
                $updateValues[] = $imagePath;
            } elseif (!empty($_POST['image_url_external'])) {
                $updateFields[] = 'image_url = ?';
                $updateValues[] = $_POST['image_url_external'];
            }

            if ($videoPath) {
                $updateFields[] = 'video_path = ?';
                $updateValues[] = $videoPath;
                $updateFields[] = 'video_type = ?';
                $updateValues[] = $videoType;
            }

            // Handle remove video
            if (isset($_POST['remove_video']) && $_POST['remove_video'] == '1') {
                $updateFields[] = 'video_path = ?';
                $updateValues[] = null;
                $updateFields[] = 'video_type = ?';
                $updateValues[] = null;
            }

            $updateValues[] = $_POST['id'];

            $sql = "UPDATE hotel_gallery SET " . implode(', ', $updateFields) . " WHERE id = ?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($updateValues);
            $message = 'Gallery item updated successfully!';

        } elseif ($action === 'delete') {
            // Get image path before deleting
            $stmt = $pdo->prepare("SELECT image_url, video_path FROM hotel_gallery WHERE id = ?");
            $stmt->execute([$_POST['id']]);
            $item = $stmt->fetch(PDO::FETCH_ASSOC);
            
            $stmt = $pdo->prepare("DELETE FROM hotel_gallery WHERE id = ?");
            $stmt->execute([$_POST['id']]);
            
            // Delete local files
            if ($item) {
                if ($item['image_url'] && !preg_match('#^https?://#i', $item['image_url'])) {
                    $path = '../' . $item['image_url'];
                    if (file_exists($path)) @unlink($path);
                }
                if ($item['video_path'] && !preg_match('#^https?://#i', $item['video_path'])) {
                    $path = '../' . $item['video_path'];
                    if (file_exists($path)) @unlink($path);
                }
            }
            $message = 'Gallery item deleted successfully!';

        } elseif ($action === 'toggle_active') {
            $stmt = $pdo->prepare("UPDATE hotel_gallery SET is_active = NOT is_active WHERE id = ?");
            $stmt->execute([$_POST['id']]);
            $message = 'Gallery item status updated!';
        }

    } catch (PDOException $e) {
        $error = 'Error: ' . $e->getMessage();
    }
}

// Fetch all gallery items
try {
    $stmt = $pdo->query("SELECT * FROM hotel_gallery ORDER BY display_order ASC, created_at DESC");
    $gallery_items = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $error = 'Error fetching gallery: ' . $e->getMessage();
    $gallery_items = [];
}

// Get unique categories
$categories = array_unique(array_filter(array_column($gallery_items, 'category')));
sort($categories);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gallery Management - Admin Panel</title>
    
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;500;600;700&family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../css/style.css">
    <link rel="stylesheet" href="../css/theme-dynamic.php">
    <link rel="stylesheet" href="css/admin-styles.css">
    <link rel="stylesheet" href="css/admin-components.css">
    
    <style>
        .gallery-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 20px;
            margin-top: 20px;
        }
        .gallery-card {
            background: white;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            transition: transform 0.3s, box-shadow 0.3s;
        }
        .gallery-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 8px 24px rgba(0,0,0,0.15);
        }
        .gallery-card-image {
            width: 100%;
            height: 200px;
            object-fit: cover;
            display: block;
        }
        .gallery-card-body {
            padding: 16px;
        }
        .gallery-card-title {
            font-weight: 600;
            font-size: 16px;
            color: var(--navy);
            margin-bottom: 4px;
        }
        .gallery-card-desc {
            font-size: 13px;
            color: #666;
            margin-bottom: 8px;
        }
        .gallery-card-meta {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 8px;
            margin-bottom: 12px;
        }
        .gallery-badge {
            padding: 3px 10px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
        }
        .badge-category {
            background: #e3f2fd;
            color: #1565c0;
        }
        .badge-active {
            background: #e8f5e9;
            color: #2e7d32;
        }
        .badge-inactive {
            background: #fbe9e7;
            color: #c62828;
        }
        .badge-video {
            background: #f3e5f5;
            color: #7b1fa2;
        }
        .gallery-card-actions {
            display: flex;
            gap: 6px;
            flex-wrap: wrap;
        }
        .gallery-card-actions .btn-action {
            padding: 6px 12px;
            border: none;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 600;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            transition: all 0.2s;
        }
        .btn-edit { background: #17a2b8; color: white; }
        .btn-edit:hover { background: #138496; }
        .btn-toggle-active { background: #ffc107; color: #212529; }
        .btn-toggle-active:hover { background: #e0a800; }
        .btn-delete { background: #dc3545; color: white; }
        .btn-delete:hover { background: #c82333; }
        .btn-add-gallery {
            background: var(--gold, #D4AF37);
            color: var(--deep-navy, #05090F);
            padding: 12px 24px;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-size: 14px;
        }
        .btn-add-gallery:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(212,175,55,0.3);
        }
        .filter-bar {
            display: flex;
            gap: 12px;
            margin-bottom: 20px;
            flex-wrap: wrap;
            align-items: center;
        }
        .filter-btn {
            padding: 8px 16px;
            border: 2px solid #e0e0e0;
            border-radius: 20px;
            background: white;
            cursor: pointer;
            font-size: 13px;
            font-weight: 500;
            transition: all 0.2s;
        }
        .filter-btn:hover, .filter-btn.active {
            border-color: var(--gold);
            background: rgba(212,175,55,0.1);
            color: var(--navy);
        }
        /* Modal */
        .modal-overlay {
            display: none;
            position: fixed;
            top: 0; left: 0;
            width: 100%; height: 100%;
            background: rgba(0,0,0,0.5);
            z-index: 9999;
            justify-content: center;
            align-items: center;
            padding: 20px;
            opacity: 1;
            visibility: visible;
            pointer-events: auto;
        }
        .modal-content {
            background: white;
            border-radius: 12px;
            padding: 32px;
            max-width: 700px;
            width: 100%;
            max-height: 90vh;
            overflow-y: auto;
        }
        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 24px;
        }
        .modal-header h3 {
            margin: 0;
            color: var(--navy, #0A1929);
        }
        .modal-close {
            background: none;
            border: none;
            font-size: 24px;
            cursor: pointer;
            color: #666;
        }
        .form-group {
            margin-bottom: 16px;
        }
        .form-group label {
            display: block;
            font-weight: 600;
            margin-bottom: 4px;
            font-size: 14px;
        }
        .form-group input,
        .form-group textarea,
        .form-group select {
            width: 100%;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 6px;
            font-size: 14px;
        }
        .form-group textarea {
            resize: vertical;
            min-height: 60px;
        }
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
        }
        .form-actions {
            display: flex;
            gap: 12px;
            justify-content: flex-end;
            margin-top: 24px;
        }
        .no-image-placeholder {
            width: 100%;
            height: 200px;
            background: #f0f0f0;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #999;
            font-size: 48px;
        }
        @media (max-width: 768px) {
            .gallery-grid {
                grid-template-columns: 1fr;
            }
            .form-row {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <?php require_once 'includes/admin-header.php'; ?>
    
    <div class="content">
        <div class="page-header" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px;">
            <div>
                <h2 class="page-title"><i class="fas fa-images"></i> Gallery Management</h2>
                <p style="color:#666; margin-top:4px;"><?php echo count($gallery_items); ?> items in gallery</p>
            </div>
            <button class="btn-add-gallery" type="button" onclick="openAddModal()">
                <i class="fas fa-plus"></i> Add Gallery Item
            </button>
        </div>
        
        <?php if ($message): ?>
            <?php showAlert($message, 'success'); ?>
        <?php endif; ?>
        <?php if ($error): ?>
            <?php showAlert($error, 'error'); ?>
        <?php endif; ?>
        
        <!-- Category Filter -->
        <div class="filter-bar">
            <button class="filter-btn active" type="button" onclick="filterGallery('all', this)">All</button>
            <?php foreach ($categories as $cat): ?>
                <button class="filter-btn" type="button" onclick="filterGallery('<?php echo htmlspecialchars($cat); ?>', this)">
                    <?php echo htmlspecialchars(ucfirst($cat)); ?>
                </button>
            <?php endforeach; ?>
        </div>
        
        <!-- Gallery Grid -->
        <?php if (!empty($gallery_items)): ?>
        <div class="gallery-grid">
            <?php foreach ($gallery_items as $item): ?>
            <div class="gallery-card" data-category="<?php echo htmlspecialchars($item['category']); ?>">
                <?php 
                    $imgSrc = $item['image_url'];
                    if (!preg_match('#^https?://#i', $imgSrc)) {
                        $imgSrc = '../' . $imgSrc;
                    }
                ?>
                <img src="<?php echo htmlspecialchars($imgSrc); ?>" 
                     alt="<?php echo htmlspecialchars($item['title']); ?>" 
                     class="gallery-card-image"
                     onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                <div class="no-image-placeholder" style="display:none;"><i class="fas fa-image"></i></div>
                
                <div class="gallery-card-body">
                    <div class="gallery-card-title"><?php echo htmlspecialchars($item['title']); ?></div>
                    <div class="gallery-card-desc"><?php echo htmlspecialchars(substr($item['description'] ?? '', 0, 80)); ?></div>
                    
                    <div class="gallery-card-meta">
                        <span class="gallery-badge badge-category"><?php echo htmlspecialchars(ucfirst($item['category'] ?? 'general')); ?></span>
                        <?php if ($item['is_active']): ?>
                            <span class="gallery-badge badge-active"><i class="fas fa-check"></i> Active</span>
                        <?php else: ?>
                            <span class="gallery-badge badge-inactive"><i class="fas fa-times"></i> Inactive</span>
                        <?php endif; ?>
                        <?php if (!empty($item['video_path'])): ?>
                            <span class="gallery-badge badge-video"><i class="fas fa-video"></i> Video</span>
                        <?php endif; ?>
                        <span style="font-size:11px; color:#999;">Order: <?php echo $item['display_order']; ?></span>
                    </div>
                    
                    <div class="gallery-card-actions">
                        <button class="btn-action btn-edit" type="button" onclick='openEditModal(<?php echo htmlspecialchars(json_encode($item), ENT_QUOTES, "UTF-8"); ?>)'>
                            <i class="fas fa-edit"></i> Edit
                        </button>
                        <button class="btn-action btn-toggle-active" type="button" onclick="toggleActive(<?php echo $item['id']; ?>)">
                            <i class="fas fa-power-off"></i> Toggle
                        </button>
                        <button class="btn-action btn-delete" type="button" onclick="if(confirm('Delete this gallery item?')) deleteItem(<?php echo $item['id']; ?>)">
                            <i class="fas fa-trash-alt"></i> Delete
                        </button>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php else: ?>
        <div style="text-align:center; padding:60px; color:#999;">
            <i class="fas fa-images" style="font-size:64px; margin-bottom:16px; color:#ddd; display:block;"></i>
            <p>No gallery items found. Click "Add Gallery Item" to get started.</p>
        </div>
        <?php endif; ?>
    </div>

    <!-- Add/Edit Modal -->
    <div class="modal-overlay" id="galleryModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 id="modalTitle"><i class="fas fa-plus-circle"></i> Add Gallery Item</h3>
                <button class="modal-close" type="button" onclick="closeModal()">&times;</button>
            </div>
            <form method="POST" enctype="multipart/form-data" id="galleryForm">
                <input type="hidden" name="action" id="formAction" value="add">
                <input type="hidden" name="id" id="formId" value="">
                
                <div class="form-group">
                    <label>Title *</label>
                    <input type="text" name="title" id="formTitle" required>
                </div>
                
                <div class="form-group">
                    <label>Description</label>
                    <textarea name="description" id="formDescription" rows="2"></textarea>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label>Category</label>
                        <select name="category" id="formCategory">
                            <option value="general">General</option>
                            <option value="exterior">Exterior</option>
                            <option value="interior">Interior</option>
                            <option value="rooms">Rooms</option>
                            <option value="facilities">Facilities</option>
                            <option value="dining">Dining</option>
                            <option value="events">Events</option>
                            <option value="spa">Spa & Wellness</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Display Order</label>
                        <input type="number" name="display_order" id="formOrder" value="0" min="0">
                    </div>
                </div>
                
                <!-- Image -->
                <div class="form-group">
                    <label><i class="fas fa-image"></i> Image</label>
                    <div id="currentImagePreview" style="display:none; margin-bottom:8px;">
                        <img id="previewImg" src="" style="max-width:200px; max-height:120px; border-radius:6px; border:1px solid #ddd;">
                    </div>
                    <input type="file" name="image" accept="image/jpeg,image/png,image/webp,image/jpg">
                    <div style="margin-top:8px;">
                        <label style="font-size:12px; font-weight:normal;">Or paste an image URL:</label>
                        <input type="url" name="image_url_external" id="formImageUrlExternal" placeholder="https://images.unsplash.com/...">
                    </div>
                </div>
                
                <!-- Video -->
                <div class="form-group">
                    <label><i class="fas fa-video"></i> Video (Optional)</label>
                    <div id="currentVideoInfo" style="display:none; margin-bottom:8px; background:#f0f7ff; padding:8px 12px; border-radius:6px; font-size:13px;">
                        <i class="fas fa-video" style="color:var(--gold);"></i> <span id="currentVideoText"></span>
                        <label style="display:inline-flex; align-items:center; gap:4px; margin-left:12px; font-weight:normal; cursor:pointer;">
                            <input type="checkbox" name="remove_video" value="1"> Remove video
                        </label>
                    </div>
                    <input type="url" name="video_url" id="formVideoUrl" placeholder="https://www.youtube.com/watch?v=... or https://vimeo.com/...">
                    <small style="color:#888;">YouTube, Vimeo, Dailymotion, or direct video URL</small>
                    <div style="text-align:center; color:#999; font-size:11px; margin:8px 0;">— OR upload —</div>
                    <input type="file" name="video" accept="video/*">
                </div>
                
                <div class="form-actions">
                    <button type="button" onclick="closeModal()" style="padding:10px 24px; border:1px solid #ddd; border-radius:6px; background:white; cursor:pointer;">Cancel</button>
                    <button type="submit" id="formSubmitBtn" style="padding:10px 24px; border:none; border-radius:6px; background:var(--gold, #D4AF37); color:var(--deep-navy, #05090F); font-weight:600; cursor:pointer;">
                        <i class="fas fa-save"></i> Save
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
    function openAddModal() {
        document.getElementById('modalTitle').innerHTML = '<i class="fas fa-plus-circle"></i> Add Gallery Item';
        document.getElementById('formAction').value = 'add';
        document.getElementById('formId').value = '';
        document.getElementById('formTitle').value = '';
        document.getElementById('formDescription').value = '';
        document.getElementById('formCategory').value = 'general';
        document.getElementById('formOrder').value = '0';
        document.getElementById('formImageUrlExternal').value = '';
        document.getElementById('formVideoUrl').value = '';
        document.getElementById('currentImagePreview').style.display = 'none';
        document.getElementById('currentVideoInfo').style.display = 'none';
        document.getElementById('galleryModal').style.display = 'flex';
    }
    
    function openEditModal(item) {
        document.getElementById('modalTitle').innerHTML = '<i class="fas fa-edit"></i> Edit Gallery Item';
        document.getElementById('formAction').value = 'update';
        document.getElementById('formId').value = item.id;
        document.getElementById('formTitle').value = item.title;
        document.getElementById('formDescription').value = item.description || '';
        document.getElementById('formCategory').value = item.category || 'general';
        document.getElementById('formOrder').value = item.display_order || 0;
        
        // Show current image if exists
        if (item.image_url) {
            const imgSrc = item.image_url.match(/^https?:\/\//) ? item.image_url : '../' + item.image_url;
            document.getElementById('previewImg').src = imgSrc;
            document.getElementById('currentImagePreview').style.display = 'block';
            document.getElementById('formImageUrlExternal').value = item.image_url.match(/^https?:\/\//) ? item.image_url : '';
        } else {
            document.getElementById('currentImagePreview').style.display = 'none';
        }
        
        // Show current video info
        if (item.video_path) {
            document.getElementById('currentVideoText').textContent = item.video_path.substring(0, 60) + (item.video_path.length > 60 ? '...' : '') + ' (' + (item.video_type || 'unknown') + ')';
            document.getElementById('currentVideoInfo').style.display = 'block';
            if (item.video_path.match(/^https?:\/\//)) {
                document.getElementById('formVideoUrl').value = item.video_path;
            }
        } else {
            document.getElementById('currentVideoInfo').style.display = 'none';
        }
        
        document.getElementById('galleryModal').style.display = 'flex';
    }
    
    function closeModal() {
        document.getElementById('galleryModal').style.display = 'none';
    }
    
    // Close on outside click
    document.getElementById('galleryModal').addEventListener('click', function(e) {
        if (e.target === this) closeModal();
    });
    
    function toggleActive(id) {
        const formData = new FormData();
        formData.append('action', 'toggle_active');
        formData.append('id', id);
        fetch(window.location.href, {
            method: 'POST',
            body: formData,
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
            .then(r => { if(r.ok) window.location.reload(); else alert('Error'); })
            .catch(() => alert('Error'));
    }
    
    function deleteItem(id) {
        const formData = new FormData();
        formData.append('action', 'delete');
        formData.append('id', id);
        fetch(window.location.href, {
            method: 'POST',
            body: formData,
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
            .then(r => { if(r.ok) window.location.reload(); else alert('Error'); })
            .catch(() => alert('Error'));
    }
    
    function filterGallery(category, btn) {
        document.querySelectorAll('.filter-btn').forEach(b => b.classList.remove('active'));
        btn.classList.add('active');
        
        document.querySelectorAll('.gallery-card').forEach(card => {
            if (category === 'all' || card.dataset.category === category) {
                card.style.display = '';
            } else {
                card.style.display = 'none';
            }
        });
    }
    </script>
    
    <?php require_once 'includes/admin-footer.php'; ?>
