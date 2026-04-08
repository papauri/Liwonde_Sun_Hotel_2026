<?php
require_once 'admin-init.php';

$message = '';
$error = '';

if (!ensureRoomPromotionInfrastructure()) {
    $error = 'Room promotion infrastructure could not be initialized.';
}

$rooms = [];
try {
    $rooms_stmt = $pdo->query("SELECT id, name FROM rooms WHERE is_active = 1 ORDER BY display_order ASC, id ASC");
    $rooms = $rooms_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $error = 'Failed to load rooms: ' . $e->getMessage();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $action = $_POST['action'] ?? '';

        if ($action === 'create' || $action === 'update') {
            $promo_id = (int)($_POST['id'] ?? 0);
            $room_id = (int)($_POST['room_id'] ?? 0);
            $title = trim((string)($_POST['title'] ?? ''));
            $promo_type = trim((string)($_POST['promo_type'] ?? 'percentage'));
            $promo_value = (float)($_POST['promo_value'] ?? 0);
            $start_date = trim((string)($_POST['start_date'] ?? ''));
            $end_date = trim((string)($_POST['end_date'] ?? ''));
            $description = trim((string)($_POST['description'] ?? ''));
            $is_active = isset($_POST['is_active']) ? 1 : 0;

            if ($room_id <= 0) {
                throw new Exception('Please select a room.');
            }
            if ($title === '') {
                throw new Exception('Promo title is required.');
            }
            if (!in_array($promo_type, ['percentage', 'fixed'], true)) {
                throw new Exception('Promo type is invalid.');
            }
            if ($promo_value <= 0) {
                throw new Exception('Promo value must be greater than zero.');
            }
            if ($promo_type === 'percentage' && $promo_value > 100) {
                throw new Exception('Percentage promo cannot be greater than 100.');
            }
            if ($start_date !== '' && $end_date !== '' && strtotime($start_date) > strtotime($end_date)) {
                throw new Exception('Start date cannot be later than end date.');
            }

            $start_date = $start_date !== '' ? $start_date : null;
            $end_date = $end_date !== '' ? $end_date : null;
            $description = $description !== '' ? $description : null;

            if ($action === 'create') {
                $stmt = $pdo->prepare("\n                    INSERT INTO room_promotions (\n                        room_id, title, promo_type, promo_value, start_date, end_date, description,\n                        is_active, created_by, updated_by\n                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)\n                ");
                $stmt->execute([
                    $room_id, $title, $promo_type, $promo_value, $start_date, $end_date, $description,
                    $is_active, $user['id'], $user['id']
                ]);
                $message = 'Room promo created successfully.';
            } else {
                if ($promo_id <= 0) {
                    throw new Exception('Invalid promo id.');
                }
                $stmt = $pdo->prepare("\n                    UPDATE room_promotions\n                    SET room_id = ?, title = ?, promo_type = ?, promo_value = ?,\n                        start_date = ?, end_date = ?, description = ?,\n                        is_active = ?, updated_by = ?, updated_at = NOW()\n                    WHERE id = ?\n                ");
                $stmt->execute([
                    $room_id, $title, $promo_type, $promo_value,
                    $start_date, $end_date, $description,
                    $is_active, $user['id'], $promo_id
                ]);
                $message = 'Room promo updated successfully.';
            }
        } elseif ($action === 'toggle') {
            $promo_id = (int)($_POST['id'] ?? 0);
            if ($promo_id <= 0) {
                throw new Exception('Invalid promo id.');
            }
            $stmt = $pdo->prepare("\n                UPDATE room_promotions\n                SET is_active = CASE WHEN is_active = 1 THEN 0 ELSE 1 END,\n                    updated_by = ?, updated_at = NOW()\n                WHERE id = ?\n            ");
            $stmt->execute([$user['id'], $promo_id]);
            $message = 'Promo status updated.';
        } elseif ($action === 'delete') {
            $promo_id = (int)($_POST['id'] ?? 0);
            if ($promo_id <= 0) {
                throw new Exception('Invalid promo id.');
            }
            $stmt = $pdo->prepare("DELETE FROM room_promotions WHERE id = ?");
            $stmt->execute([$promo_id]);
            $message = 'Promo deleted successfully.';
        }
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

$promos = [];
try {
    $stmt = $pdo->query("\n        SELECT rp.*, r.name AS room_name\n        FROM room_promotions rp\n        LEFT JOIN rooms r ON r.id = rp.room_id\n        ORDER BY rp.is_active DESC, rp.updated_at DESC, rp.id DESC\n    ");
    $promos = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $error = 'Failed to load room promos: ' . $e->getMessage();
}

$editing_promo = null;
$edit_id = isset($_GET['edit']) ? (int)$_GET['edit'] : 0;
if ($edit_id > 0) {
    foreach ($promos as $promo_row) {
        if ((int)$promo_row['id'] === $edit_id) {
            $editing_promo = $promo_row;
            break;
        }
    }
}

$site_name = getSetting('site_name');
$current_page = 'room-promotions.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Room Promotions | <?php echo htmlspecialchars($site_name); ?> Admin</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@600;700&family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../css/style.css">
    <link rel="stylesheet" href="../css/theme-dynamic.php">
    <link rel="stylesheet" href="css/admin-styles.css">
    <style>
        .promo-page { padding: 28px; background: #f8f9fa; min-height: 100vh; }
        .promo-grid { display: grid; grid-template-columns: 380px 1fr; gap: 22px; }
        .card { background: #fff; border-radius: 12px; box-shadow: 0 3px 12px rgba(0,0,0,0.08); padding: 20px; }
        .form-group { margin-bottom: 12px; }
        .form-group label { display: block; margin-bottom: 6px; font-weight: 600; color: #2d3d4f; }
        .form-group input, .form-group select, .form-group textarea {
            width: 100%; padding: 10px 12px; border: 1px solid #d8dee4; border-radius: 8px;
        }
        .btn { border: 0; border-radius: 8px; padding: 10px 14px; font-weight: 600; cursor: pointer; }
        .btn-primary { background: #1f7a4f; color: #fff; }
        .btn-muted { background: #e9ecef; color: #333; }
        .table { width: 100%; border-collapse: collapse; }
        .table th, .table td { padding: 10px; border-bottom: 1px solid #e9ecef; text-align: left; }
        .badge { padding: 4px 10px; border-radius: 999px; font-size: 11px; font-weight: 700; }
        .badge-on { background: #d4edda; color: #155724; }
        .badge-off { background: #f8d7da; color: #721c24; }
        .row-actions { display: flex; gap: 6px; flex-wrap: wrap; }
        .btn-small { padding: 6px 10px; font-size: 12px; }
        @media (max-width: 980px) { .promo-grid { grid-template-columns: 1fr; } }
    </style>
</head>
<body>
<?php require_once 'includes/admin-header.php'; ?>

<div class="promo-page">
    <h1 style="font-family:'Playfair Display',serif; color:#0a1f36; margin-bottom: 18px;"><i class="fas fa-tags"></i> Room Promotions</h1>

    <?php if ($message): ?>
        <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($message); ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="alert alert-error"><i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <div class="promo-grid">
        <div class="card">
            <h3 style="margin-top:0; color:#0a1f36;"><?php echo $editing_promo ? 'Edit Promo' : 'Create Promo'; ?></h3>
            <form method="POST">
                <?php echo getCsrfField(); ?>
                <input type="hidden" name="action" value="<?php echo $editing_promo ? 'update' : 'create'; ?>">
                <?php if ($editing_promo): ?>
                    <input type="hidden" name="id" value="<?php echo (int)$editing_promo['id']; ?>">
                <?php endif; ?>
                <div class="form-group">
                    <label>Room</label>
                    <select name="room_id" required>
                        <option value="">Select room...</option>
                        <?php foreach ($rooms as $room): ?>
                            <option value="<?php echo (int)$room['id']; ?>" <?php echo $editing_promo && (int)$editing_promo['room_id'] === (int)$room['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($room['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Promo Title</label>
                    <input type="text" name="title" maxlength="150" required placeholder="Weekend Saver 20% Off" value="<?php echo htmlspecialchars($editing_promo['title'] ?? ''); ?>">
                </div>
                <div class="form-group">
                    <label>Promo Type</label>
                    <select name="promo_type" required>
                        <option value="percentage" <?php echo ($editing_promo['promo_type'] ?? '') === 'percentage' ? 'selected' : ''; ?>>Percentage (%)</option>
                        <option value="fixed" <?php echo ($editing_promo['promo_type'] ?? '') === 'fixed' ? 'selected' : ''; ?>>Fixed Amount (MWK)</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Promo Value</label>
                    <input type="number" name="promo_value" min="0.01" step="0.01" required value="<?php echo htmlspecialchars(isset($editing_promo['promo_value']) ? (string)$editing_promo['promo_value'] : ''); ?>">
                </div>
                <div class="form-group">
                    <label>Start Date (optional)</label>
                    <input type="date" name="start_date" value="<?php echo htmlspecialchars($editing_promo['start_date'] ?? ''); ?>">
                </div>
                <div class="form-group">
                    <label>End Date (optional)</label>
                    <input type="date" name="end_date" value="<?php echo htmlspecialchars($editing_promo['end_date'] ?? ''); ?>">
                </div>
                <div class="form-group">
                    <label>Description (optional)</label>
                    <textarea name="description" rows="3" placeholder="Campaign notes or offer details"><?php echo htmlspecialchars($editing_promo['description'] ?? ''); ?></textarea>
                </div>
                <div class="form-group" style="display:flex; align-items:center; gap:8px;">
                    <input type="checkbox" name="is_active" id="is_active" <?php echo !isset($editing_promo['is_active']) || (int)$editing_promo['is_active'] === 1 ? 'checked' : ''; ?> style="width:auto;">
                    <label for="is_active" style="margin:0;">Active now</label>
                </div>
                <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> <?php echo $editing_promo ? 'Update Promo' : 'Create Promo'; ?></button>
                <?php if ($editing_promo): ?>
                    <a href="room-promotions.php" class="btn btn-muted" style="text-decoration:none; display:inline-block; margin-left:8px;">Cancel Edit</a>
                <?php endif; ?>
            </form>
        </div>

        <div class="card">
            <h3 style="margin-top:0; color:#0a1f36;">Existing Room Promos</h3>
            <?php if (empty($promos)): ?>
                <p style="color:#777;">No room promos yet.</p>
            <?php else: ?>
                <div style="overflow:auto;">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Title</th>
                                <th>Room</th>
                                <th>Type</th>
                                <th>Value</th>
                                <th>Dates</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($promos as $promo): ?>
                                <tr>
                                    <td>
                                        <strong><?php echo htmlspecialchars($promo['title']); ?></strong>
                                        <?php if (!empty($promo['description'])): ?>
                                            <br><small style="color:#666;"><?php echo htmlspecialchars($promo['description']); ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo htmlspecialchars($promo['room_name'] ?? 'Unknown'); ?></td>
                                    <td><?php echo $promo['promo_type'] === 'percentage' ? 'Percent' : 'Fixed'; ?></td>
                                    <td>
                                        <?php if ($promo['promo_type'] === 'percentage'): ?>
                                            <?php echo number_format((float)$promo['promo_value'], 2); ?>%
                                        <?php else: ?>
                                            MWK <?php echo number_format((float)$promo['promo_value'], 2); ?>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php echo $promo['start_date'] ? htmlspecialchars($promo['start_date']) : 'Any'; ?>
                                        -
                                        <?php echo $promo['end_date'] ? htmlspecialchars($promo['end_date']) : 'Any'; ?>
                                    </td>
                                    <td>
                                        <span class="badge <?php echo (int)$promo['is_active'] === 1 ? 'badge-on' : 'badge-off'; ?>">
                                            <?php echo (int)$promo['is_active'] === 1 ? 'Active' : 'Inactive'; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="row-actions">
                                            <a class="btn btn-muted btn-small" href="room-promotions.php?edit=<?php echo (int)$promo['id']; ?>">Edit</a>
                                            <form method="POST" style="display:inline;">
                                                <?php echo getCsrfField(); ?>
                                                <input type="hidden" name="action" value="toggle">
                                                <input type="hidden" name="id" value="<?php echo (int)$promo['id']; ?>">
                                                <button type="submit" class="btn btn-muted btn-small"><?php echo (int)$promo['is_active'] === 1 ? 'Disable' : 'Enable'; ?></button>
                                            </form>
                                            <form method="POST" style="display:inline;" onsubmit="return confirm('Delete this promo?');">
                                                <?php echo getCsrfField(); ?>
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="id" value="<?php echo (int)$promo['id']; ?>">
                                                <button type="submit" class="btn btn-small" style="background:#dc3545; color:#fff;">Delete</button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php require_once 'includes/admin-footer.php'; ?>
</body>
</html>