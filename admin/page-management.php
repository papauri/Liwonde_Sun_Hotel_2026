<?php
/**
 * Page Management
 * Enable, disable, and reorder public website pages via the admin panel.
 * Pages can NOT be deleted here — only directly in the database.
 */

require_once 'admin-init.php';
require_once '../includes/alert.php';

$message = '';
$error   = '';

// ─── Ensure site_pages table exists ───────────────────────────────────
try {
    $pdo->query("SELECT 1 FROM site_pages LIMIT 1");
} catch (PDOException $e) {
    // Auto-create the table + seed data
    $sql = file_get_contents(__DIR__ . '/../Database/migrations/create_site_pages_table.sql');
    if ($sql) {
        // Execute each statement separately
        $statements = array_filter(array_map('trim', explode(';', $sql)));
        foreach ($statements as $stmt) {
            if (!empty($stmt) && stripos($stmt, '--') !== 0) {
                try { $pdo->exec($stmt); } catch (PDOException $ex) { /* ignore duplicates */ }
            }
        }
        $message = 'Page management table created and seeded with default pages.';
    }
}

// ─── Handle POST actions ──────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF check
    if (!isset($_POST['csrf_token']) || !validateCsrfToken($_POST['csrf_token'])) {
        $error = 'Invalid security token. Please try again.';
    } else {
        $action = $_POST['action'] ?? '';

        try {
            switch ($action) {

                // Toggle is_enabled
                case 'toggle_enabled':
                    $id = (int)($_POST['page_id'] ?? 0);
                    $stmt = $pdo->prepare("UPDATE site_pages SET is_enabled = NOT is_enabled WHERE id = ?");
                    $stmt->execute([$id]);
                    $message = 'Page status updated.';
                    break;

                // Toggle show_in_nav
                case 'toggle_nav':
                    $id = (int)($_POST['page_id'] ?? 0);
                    $stmt = $pdo->prepare("UPDATE site_pages SET show_in_nav = NOT show_in_nav WHERE id = ?");
                    $stmt->execute([$id]);
                    $message = 'Navigation visibility updated.';
                    break;

                // Save nav order (from sortable list)
                case 'save_order':
                    $order = json_decode($_POST['page_order'] ?? '[]', true);
                    if (is_array($order)) {
                        $stmt = $pdo->prepare("UPDATE site_pages SET nav_position = ? WHERE id = ?");
                        foreach ($order as $pos => $id) {
                            $stmt->execute([($pos + 1) * 10, (int)$id]);
                        }
                        $message = 'Navigation order saved.';
                    }
                    break;

                // Add new page
                case 'add_page':
                    $page_key  = preg_replace('/[^a-z0-9_-]/', '', strtolower(trim($_POST['page_key'] ?? '')));
                    $title     = trim($_POST['title'] ?? '');
                    $file_path = trim($_POST['file_path'] ?? '');
                    $icon      = trim($_POST['icon'] ?? 'fa-file');
                    $desc      = trim($_POST['description'] ?? '');

                    if (!$page_key || !$title || !$file_path) {
                        $error = 'Page key, title, and file path are required.';
                    } else {
                        // Check for duplicate key
                        $chk = $pdo->prepare("SELECT COUNT(*) FROM site_pages WHERE page_key = ?");
                        $chk->execute([$page_key]);
                        if ($chk->fetchColumn() > 0) {
                            $error = 'A page with that key already exists.';
                        } else {
                            $maxPos = $pdo->query("SELECT COALESCE(MAX(nav_position), 0) FROM site_pages")->fetchColumn();
                            $stmt = $pdo->prepare("
                                INSERT INTO site_pages (page_key, title, file_path, icon, nav_position, show_in_nav, is_enabled, description)
                                VALUES (?, ?, ?, ?, ?, 1, 1, ?)
                            ");
                            $stmt->execute([$page_key, $title, $file_path, $icon, $maxPos + 10, $desc]);
                            $message = "Page \"$title\" added successfully.";
                        }
                    }
                    break;

                // Edit page details
                case 'edit_page':
                    $id        = (int)($_POST['page_id'] ?? 0);
                    $title     = trim($_POST['title'] ?? '');
                    $file_path = trim($_POST['file_path'] ?? '');
                    $icon      = trim($_POST['icon'] ?? 'fa-file');
                    $desc      = trim($_POST['description'] ?? '');

                    if (!$title || !$file_path) {
                        $error = 'Title and file path are required.';
                    } else {
                        $stmt = $pdo->prepare("
                            UPDATE site_pages SET title = ?, file_path = ?, icon = ?, description = ? WHERE id = ?
                        ");
                        $stmt->execute([$title, $file_path, $icon, $desc, $id]);
                        $message = "Page \"$title\" updated.";
                    }
                    break;
            }

            // Clear page cache so nav picks up changes immediately
            if ($message && file_exists(__DIR__ . '/../config/cache.php')) {
                require_once __DIR__ . '/../config/cache.php';
                if (function_exists('clearCache')) { clearCache(); }
            }

        } catch (PDOException $ex) {
            $error = 'Database error: ' . $ex->getMessage();
            error_log("Page management error: " . $ex->getMessage());
        }
    }
}

// ─── Fetch all pages ──────────────────────────────────────────────────
try {
    $pages = $pdo->query("SELECT * FROM site_pages ORDER BY nav_position ASC, title ASC")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $pages = [];
    $error = 'Could not load pages. The site_pages table may not exist yet.';
}

// Regenerate CSRF token
$csrf_token = generateCsrfToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Page Management - <?php echo htmlspecialchars($site_name); ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../css/style.css">
    <link rel="stylesheet" href="../css/theme-dynamic.php">
    <link rel="stylesheet" href="css/admin-styles.css">
    <link rel="stylesheet" href="css/admin-components.css">

    <style>
        /* ── Page Management Styles ─────────────── */
        .pm-section {
            background: white;
            border-radius: 12px;
            padding: 24px;
            margin-bottom: 24px;
            box-shadow: 0 2px 8px rgba(0,0,0,.08);
        }
        .pm-section h2 {
            font-size: 20px;
            font-weight: 600;
            color: var(--navy);
            margin-bottom: 20px;
            padding-bottom: 12px;
            border-bottom: 2px solid var(--gold);
        }

        /* Table */
        .pm-table { width: 100%; border-collapse: collapse; }
        .pm-table th {
            text-align: left;
            padding: 12px 14px;
            background: var(--navy);
            color: #fff;
            font-size: 13px;
            text-transform: uppercase;
            letter-spacing: .5px;
        }
        .pm-table th:first-child { border-radius: 8px 0 0 0; }
        .pm-table th:last-child  { border-radius: 0 8px 0 0; }
        .pm-table td {
            padding: 14px;
            border-bottom: 1px solid #eee;
            vertical-align: middle;
            font-size: 14px;
        }
        .pm-table tr:hover td { background: #fafafa; }
        .pm-table .drag-handle {
            cursor: grab;
            color: #bbb;
            font-size: 16px;
        }
        .pm-table .drag-handle:active { cursor: grabbing; }

        /* Status badges */
        .badge {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }
        .badge-enabled  { background: #d4edda; color: #155724; }
        .badge-disabled { background: #f8d7da; color: #721c24; }
        .badge-nav-yes  { background: #cce5ff; color: #004085; }
        .badge-nav-no   { background: #e2e3e5; color: #383d41; }

        /* Toggle buttons */
        .btn-toggle {
            border: none;
            padding: 6px 14px;
            border-radius: 6px;
            cursor: pointer;
            font-size: 13px;
            font-weight: 500;
            transition: all .2s;
        }
        .btn-toggle:hover { transform: translateY(-1px); }
        .btn-enable  { background: #28a745; color: #fff; }
        .btn-disable { background: #dc3545; color: #fff; }
        .btn-nav-toggle { background: var(--gold, #D4AF37); color: var(--navy, #0A1929); }
        .btn-edit { background: #17a2b8; color: #fff; }
        .btn-save-order {
            background: var(--gold, #D4AF37);
            color: var(--navy, #0A1929);
            border: none;
            padding: 10px 22px;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            font-size: 14px;
            transition: all .3s;
        }
        .btn-save-order:hover { transform: translateY(-2px); }

        /* Action group */
        .action-group {
            display: flex;
            gap: 6px;
            flex-wrap: wrap;
        }

        /* Icon preview */
        .page-icon-preview {
            width: 32px;
            height: 32px;
            border-radius: 8px;
            background: var(--navy, #0A1929);
            color: var(--gold, #D4AF37);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 14px;
            flex-shrink: 0;
        }
        .page-info {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .page-info .page-title { font-weight: 600; color: var(--navy); }
        .page-info .page-file  { font-size: 12px; color: #888; font-family: 'Courier New', monospace; }

        /* Add-page form */
        .add-page-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 16px;
            margin-bottom: 20px;
        }
        .add-page-grid label { display: block; font-weight: 600; color: var(--navy); margin-bottom: 6px; font-size: 13px; }
        .add-page-grid input,
        .add-page-grid textarea {
            width: 100%;
            padding: 10px 12px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            font-size: 14px;
            transition: border-color .2s;
            box-sizing: border-box;
        }
        .add-page-grid input:focus,
        .add-page-grid textarea:focus { border-color: var(--gold); outline: none; }

        /* Security note */
        .security-note {
            display: flex;
            align-items: start;
            gap: 12px;
            padding: 16px 20px;
            background: #fff3cd;
            border-left: 4px solid #ffc107;
            border-radius: 0 8px 8px 0;
            margin-bottom: 24px;
            font-size: 14px;
            color: #856404;
        }
        .security-note i { margin-top: 2px; }

        /* Edit modal */
        .edit-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,.5);
            z-index: 2000;
            align-items: center;
            justify-content: center;
        }
        .edit-overlay.active { display: flex; }
        .edit-modal {
            background: #fff;
            border-radius: 16px;
            padding: 32px;
            width: 90%;
            max-width: 520px;
            box-shadow: 0 20px 60px rgba(0,0,0,.3);
            position: relative;
        }
        .edit-modal h3 {
            margin: 0 0 20px;
            color: var(--navy);
            font-size: 20px;
        }
        .edit-modal .form-group { margin-bottom: 16px; }
        .edit-modal label { display: block; font-weight: 600; margin-bottom: 6px; font-size: 13px; color: var(--navy); }
        .edit-modal input,
        .edit-modal textarea {
            width: 100%;
            padding: 10px 12px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            font-size: 14px;
            box-sizing: border-box;
        }
        .edit-modal input:focus,
        .edit-modal textarea:focus { border-color: var(--gold); outline: none; }
        .edit-modal .modal-actions { display: flex; gap: 10px; justify-content: flex-end; margin-top: 20px; }
        .btn-cancel { background: #6c757d; color: #fff; border: none; padding: 10px 20px; border-radius: 8px; cursor: pointer; font-weight: 600; }
        .btn-submit { background: var(--gold); color: var(--navy); border: none; padding: 10px 20px; border-radius: 8px; cursor: pointer; font-weight: 600; }

        /* Responsive */
        @media (max-width: 768px) {
            .pm-table thead { display: none; }
            .pm-table, .pm-table tbody, .pm-table tr, .pm-table td { display: block; width: 100%; }
            .pm-table tr { border: 1px solid #eee; border-radius: 10px; margin-bottom: 12px; padding: 12px; }
            .pm-table td { padding: 6px 0; border: none; }
            .pm-table td::before {
                content: attr(data-label);
                font-weight: 600;
                font-size: 11px;
                text-transform: uppercase;
                color: #888;
                display: block;
                margin-bottom: 4px;
            }
        }
    </style>
</head>
<body>
    <?php require_once 'includes/admin-header.php'; ?>

    <div class="content">
        <div class="page-header">
            <h2 class="page-title">
                <i class="fas fa-file-alt"></i> Page Management
            </h2>
            <p class="text-muted">Enable, disable, and reorder public website pages</p>
        </div>

        <?php if ($message): ?>
            <?php showAlert($message, 'success'); ?>
        <?php endif; ?>
        <?php if ($error): ?>
            <?php showAlert($error, 'error'); ?>
        <?php endif; ?>

        <!-- Security Note -->
        <div class="security-note">
            <i class="fas fa-shield-alt"></i>
            <div>
                <strong>Security Notice:</strong>
                Pages cannot be deleted from this panel. To permanently remove a page, delete the row directly from the <code>site_pages</code> database table. This protects against accidental removal.
            </div>
        </div>

        <!-- Pages Table -->
        <div class="pm-section">
            <h2><i class="fas fa-list-ul"></i> Website Pages</h2>

            <?php if (empty($pages)): ?>
                <p style="color:#666; text-align:center; padding:30px 0;">No pages found. Add your first page below.</p>
            <?php else: ?>

            <form method="POST" id="orderForm">
                <input type="hidden" name="action" value="save_order">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                <input type="hidden" name="page_order" id="pageOrderInput">
            </form>

            <div style="overflow-x: auto;">
            <table class="pm-table" id="pagesTable">
                <thead>
                    <tr>
                        <th style="width:40px"></th>
                        <th>Page</th>
                        <th>Status</th>
                        <th>Navigation</th>
                        <th>Order</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody id="pagesTbody">
                    <?php foreach ($pages as $page): ?>
                    <tr data-id="<?php echo $page['id']; ?>">
                        <td data-label="">
                            <span class="drag-handle" title="Drag to reorder"><i class="fas fa-grip-vertical"></i></span>
                        </td>
                        <td data-label="Page">
                            <div class="page-info">
                                <span class="page-icon-preview"><i class="fas <?php echo htmlspecialchars($page['icon']); ?>"></i></span>
                                <div>
                                    <div class="page-title"><?php echo htmlspecialchars($page['title']); ?></div>
                                    <div class="page-file"><?php echo htmlspecialchars($page['file_path']); ?></div>
                                    <?php if ($page['description']): ?>
                                        <div style="font-size:12px;color:#888;margin-top:2px;"><?php echo htmlspecialchars($page['description']); ?></div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </td>
                        <td data-label="Status">
                            <?php if ($page['is_enabled']): ?>
                                <span class="badge badge-enabled"><i class="fas fa-check-circle"></i> Enabled</span>
                            <?php else: ?>
                                <span class="badge badge-disabled"><i class="fas fa-times-circle"></i> Disabled</span>
                            <?php endif; ?>
                        </td>
                        <td data-label="Navigation">
                            <?php if ($page['show_in_nav']): ?>
                                <span class="badge badge-nav-yes"><i class="fas fa-eye"></i> Visible</span>
                            <?php else: ?>
                                <span class="badge badge-nav-no"><i class="fas fa-eye-slash"></i> Hidden</span>
                            <?php endif; ?>
                        </td>
                        <td data-label="Order">
                            <?php echo (int)$page['nav_position']; ?>
                        </td>
                        <td data-label="Actions">
                            <div class="action-group">
                                <!-- Toggle Enable/Disable -->
                                <form method="POST" style="display:inline;">
                                    <input type="hidden" name="action" value="toggle_enabled">
                                    <input type="hidden" name="page_id" value="<?php echo $page['id']; ?>">
                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                    <?php if ($page['is_enabled']): ?>
                                        <button type="submit" class="btn-toggle btn-disable" title="Disable this page" onclick="return confirm('Disable this page? It will become inaccessible to visitors.')">
                                            <i class="fas fa-power-off"></i>
                                        </button>
                                    <?php else: ?>
                                        <button type="submit" class="btn-toggle btn-enable" title="Enable this page">
                                            <i class="fas fa-power-off"></i>
                                        </button>
                                    <?php endif; ?>
                                </form>

                                <!-- Toggle Nav Visibility -->
                                <form method="POST" style="display:inline;">
                                    <input type="hidden" name="action" value="toggle_nav">
                                    <input type="hidden" name="page_id" value="<?php echo $page['id']; ?>">
                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                    <button type="submit" class="btn-toggle btn-nav-toggle" title="<?php echo $page['show_in_nav'] ? 'Hide from navigation' : 'Show in navigation'; ?>">
                                        <i class="fas <?php echo $page['show_in_nav'] ? 'fa-eye-slash' : 'fa-eye'; ?>"></i>
                                    </button>
                                </form>

                                <!-- Edit -->
                                <button type="button" class="btn-toggle btn-edit" title="Edit page details"
                                    onclick="openEditModal(<?php echo htmlspecialchars(json_encode($page)); ?>)">
                                    <i class="fas fa-pencil-alt"></i>
                                </button>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            </div>

            <div style="margin-top: 16px; display: flex; gap: 12px; align-items: center;">
                <button type="button" class="btn-save-order" onclick="saveOrder()">
                    <i class="fas fa-sort-amount-down"></i> Save Order
                </button>
                <span style="font-size: 13px; color: #888;">Drag rows to reorder, then click Save Order</span>
            </div>

            <?php endif; ?>
        </div>

        <!-- Add New Page -->
        <div class="pm-section">
            <h2><i class="fas fa-plus-circle"></i> Add New Page</h2>

            <form method="POST">
                <input type="hidden" name="action" value="add_page">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">

                <div class="add-page-grid">
                    <div>
                        <label for="page_key">Page Key (slug) <span style="color:#dc3545">*</span></label>
                        <input type="text" id="page_key" name="page_key" placeholder="e.g. spa" required
                               pattern="[a-z0-9_-]+" title="Lowercase letters, numbers, hyphens, and underscores only">
                    </div>
                    <div>
                        <label for="add_title">Nav Title <span style="color:#dc3545">*</span></label>
                        <input type="text" id="add_title" name="title" placeholder="e.g. Spa & Wellness" required>
                    </div>
                    <div>
                        <label for="add_file_path">File Path <span style="color:#dc3545">*</span></label>
                        <input type="text" id="add_file_path" name="file_path" placeholder="e.g. spa.php" required>
                    </div>
                    <div>
                        <label for="add_icon">Icon (Font Awesome)</label>
                        <input type="text" id="add_icon" name="icon" placeholder="e.g. fa-spa" value="fa-file">
                    </div>
                    <div style="grid-column: 1 / -1;">
                        <label for="add_description">Description</label>
                        <input type="text" id="add_description" name="description" placeholder="Short description for admin reference">
                    </div>
                </div>

                <button type="submit" class="btn-submit">
                    <i class="fas fa-plus"></i> Add Page
                </button>
            </form>
        </div>
    </div>

    <!-- Edit Modal -->
    <div class="edit-overlay" id="editOverlay">
        <div class="edit-modal">
            <h3><i class="fas fa-pencil-alt"></i> Edit Page</h3>
            <form method="POST" id="editForm">
                <input type="hidden" name="action" value="edit_page">
                <input type="hidden" name="page_id" id="edit_page_id">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">

                <div class="form-group">
                    <label>Page Key</label>
                    <input type="text" id="edit_page_key" disabled style="background:#f0f0f0; cursor:not-allowed;">
                </div>
                <div class="form-group">
                    <label for="edit_title">Nav Title <span style="color:#dc3545">*</span></label>
                    <input type="text" id="edit_title" name="title" required>
                </div>
                <div class="form-group">
                    <label for="edit_file_path">File Path <span style="color:#dc3545">*</span></label>
                    <input type="text" id="edit_file_path" name="file_path" required>
                </div>
                <div class="form-group">
                    <label for="edit_icon">Icon (Font Awesome)</label>
                    <input type="text" id="edit_icon" name="icon">
                </div>
                <div class="form-group">
                    <label for="edit_description">Description</label>
                    <textarea id="edit_description" name="description" rows="2"></textarea>
                </div>

                <div class="modal-actions">
                    <button type="button" class="btn-cancel" onclick="closeEditModal()">Cancel</button>
                    <button type="submit" class="btn-submit"><i class="fas fa-save"></i> Save Changes</button>
                </div>
            </form>
        </div>
    </div>

    <script>
    // ── Edit Modal ─────────────────────────────────────────
    function openEditModal(page) {
        document.getElementById('edit_page_id').value   = page.id;
        document.getElementById('edit_page_key').value   = page.page_key;
        document.getElementById('edit_title').value      = page.title;
        document.getElementById('edit_file_path').value  = page.file_path;
        document.getElementById('edit_icon').value       = page.icon || 'fa-file';
        document.getElementById('edit_description').value = page.description || '';
        document.getElementById('editOverlay').classList.add('active');
    }
    function closeEditModal() {
        document.getElementById('editOverlay').classList.remove('active');
    }
    // Close on overlay click
    document.getElementById('editOverlay').addEventListener('click', function(e) {
        if (e.target === this) closeEditModal();
    });
    // Close on Escape
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') closeEditModal();
    });

    // ── Drag & Drop Reorder ────────────────────────────────
    (function() {
        var tbody = document.getElementById('pagesTbody');
        if (!tbody) return;

        var dragging = null;

        tbody.querySelectorAll('.drag-handle').forEach(function(handle) {
            var row = handle.closest('tr');
            row.setAttribute('draggable', 'true');

            row.addEventListener('dragstart', function(e) {
                dragging = this;
                this.style.opacity = '0.4';
                e.dataTransfer.effectAllowed = 'move';
            });

            row.addEventListener('dragend', function() {
                this.style.opacity = '1';
                dragging = null;
                tbody.querySelectorAll('tr').forEach(function(r) { r.style.borderTop = ''; });
            });

            row.addEventListener('dragover', function(e) {
                e.preventDefault();
                e.dataTransfer.dropEffect = 'move';
                if (dragging && dragging !== this) {
                    this.style.borderTop = '3px solid var(--gold, #D4AF37)';
                }
            });

            row.addEventListener('dragleave', function() {
                this.style.borderTop = '';
            });

            row.addEventListener('drop', function(e) {
                e.preventDefault();
                this.style.borderTop = '';
                if (dragging && dragging !== this) {
                    tbody.insertBefore(dragging, this);
                }
            });
        });
    })();

    function saveOrder() {
        var rows = document.querySelectorAll('#pagesTbody tr');
        var order = [];
        rows.forEach(function(row) { order.push(row.dataset.id); });
        document.getElementById('pageOrderInput').value = JSON.stringify(order);
        document.getElementById('orderForm').submit();
    }
    </script>
</body>
</html>
