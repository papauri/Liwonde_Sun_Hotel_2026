<?php
require_once 'admin-init.php';
require_once '../includes/alert.php';

$message = '';
$error   = '';

// ── POST Handler ───────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $csrf_token) {
        $error = 'Invalid security token. Please try again.';
    } else {
        $action = $_POST['action'] ?? '';

        // Simple sanitize helper (inline, no external dep)
        $san = fn($v, $max = 255) => mb_substr(strip_tags(trim($v)), 0, $max);

        try {
            // ── Content ──────────────────────────────────────────────────────
            if ($action === 'update_content') {
                $id = (int)($_POST['content_id'] ?? 0);
                $fields = [
                    'hero_title'                => $san($_POST['hero_title']   ?? '', 200),
                    'hero_subtitle'             => $san($_POST['hero_subtitle'] ?? '', 200),
                    'hero_description'          => $san($_POST['hero_description'] ?? '', 2000),
                    'hero_image_path'           => $san($_POST['hero_image_path'] ?? '', 255),
                    'wellness_title'            => $san($_POST['wellness_title'] ?? '', 200),
                    'wellness_description'      => $san($_POST['wellness_description'] ?? '', 2000),
                    'wellness_image_path'       => $san($_POST['wellness_image_path'] ?? '', 255),
                    'badge_text'                => $san($_POST['badge_text'] ?? '', 120),
                    'personal_training_image_path' => $san($_POST['personal_training_image_path'] ?? '', 255),
                ];
                if ($id > 0) {
                    $sets = implode(', ', array_map(fn($k) => "`{$k}` = ?", array_keys($fields)));
                    $vals = array_values($fields);
                    $vals[] = $id;
                    $pdo->prepare("UPDATE gym_content SET {$sets} WHERE id = ?")->execute($vals);
                } else {
                    $cols = '`' . implode('`, `', array_keys($fields)) . '`';
                    $phs  = implode(', ', array_fill(0, count($fields), '?'));
                    $pdo->prepare("INSERT INTO gym_content ({$cols}) VALUES ({$phs})")->execute(array_values($fields));
                }
                $message = 'Page content updated.';

            // ── Packages ─────────────────────────────────────────────────────
            } elseif ($action === 'create_package' || $action === 'edit_package') {
                $name      = $san($_POST['name'] ?? '', 150);
                $icon      = $san($_POST['icon_class'] ?? '', 100);
                $includes  = $san($_POST['includes_text'] ?? '', 3000);
                $duration  = $san($_POST['duration_label'] ?? '', 50);
                $price     = max(0, (float)($_POST['price'] ?? 0));
                $currency  = $san($_POST['currency_code'] ?? 'MWK', 10);
                $ctaText   = $san($_POST['cta_text'] ?? 'Book Package', 120);
                $ctaLink   = $san($_POST['cta_link'] ?? '#book', 255);
                $featured  = isset($_POST['is_featured'])  ? 1 : 0;
                $active    = isset($_POST['is_active'])    ? 1 : 0;
                $order     = (int)($_POST['display_order'] ?? 0);

                if ($name === '') throw new Exception('Package name is required.');

                if ($action === 'create_package') {
                    $pdo->prepare("INSERT INTO gym_packages
                        (name, icon_class, includes_text, duration_label, price, currency_code,
                         cta_text, cta_link, is_featured, display_order, is_active)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
                        ->execute([$name, $icon, $includes ?: null, $duration ?: null,
                                   $price, $currency, $ctaText, $ctaLink, $featured, $order, $active]);
                    $message = 'Package created.';
                } else {
                    $id = (int)($_POST['item_id'] ?? 0);
                    if ($id <= 0) throw new Exception('Invalid package ID.');
                    $pdo->prepare("UPDATE gym_packages SET
                        name=?, icon_class=?, includes_text=?, duration_label=?, price=?,
                        currency_code=?, cta_text=?, cta_link=?, is_featured=?, display_order=?, is_active=?
                        WHERE id=?")
                        ->execute([$name, $icon, $includes ?: null, $duration ?: null,
                                   $price, $currency, $ctaText, $ctaLink, $featured, $order, $active, $id]);
                    $message = 'Package updated.';
                }

            } elseif ($action === 'delete_package') {
                $id = (int)($_POST['item_id'] ?? 0);
                if ($id <= 0) throw new Exception('Invalid ID.');
                $pdo->prepare("DELETE FROM gym_packages WHERE id=?")->execute([$id]);
                $message = 'Package deleted.';

            // ── Classes ──────────────────────────────────────────────────────
            } elseif ($action === 'create_class' || $action === 'edit_class') {
                $title  = $san($_POST['title'] ?? '', 150);
                $desc   = $san($_POST['description'] ?? '', 2000);
                $day    = $san($_POST['day_label'] ?? '', 120);
                $time   = $san($_POST['time_label'] ?? '', 50);
                $level  = $san($_POST['level_label'] ?? 'All Levels', 80);
                $order  = (int)($_POST['display_order'] ?? 0);
                $active = isset($_POST['is_active']) ? 1 : 0;

                if ($title === '') throw new Exception('Class title is required.');

                if ($action === 'create_class') {
                    $pdo->prepare("INSERT INTO gym_classes
                        (title, description, day_label, time_label, level_label, display_order, is_active)
                        VALUES (?, ?, ?, ?, ?, ?, ?)")
                        ->execute([$title, $desc ?: null, $day, $time, $level, $order, $active]);
                    $message = 'Class created.';
                } else {
                    $id = (int)($_POST['item_id'] ?? 0);
                    if ($id <= 0) throw new Exception('Invalid class ID.');
                    $pdo->prepare("UPDATE gym_classes SET
                        title=?, description=?, day_label=?, time_label=?, level_label=?,
                        display_order=?, is_active=? WHERE id=?")
                        ->execute([$title, $desc ?: null, $day, $time, $level, $order, $active, $id]);
                    $message = 'Class updated.';
                }

            } elseif ($action === 'delete_class') {
                $id = (int)($_POST['item_id'] ?? 0);
                if ($id <= 0) throw new Exception('Invalid ID.');
                $pdo->prepare("DELETE FROM gym_classes WHERE id=?")->execute([$id]);
                $message = 'Class deleted.';

            // ── Facilities ───────────────────────────────────────────────────
            } elseif ($action === 'create_facility' || $action === 'edit_facility') {
                $icon   = $san($_POST['icon_class'] ?? 'fas fa-check', 100);
                $title  = $san($_POST['title'] ?? '', 150);
                $desc   = $san($_POST['description'] ?? '', 2000);
                $order  = (int)($_POST['display_order'] ?? 0);
                $active = isset($_POST['is_active']) ? 1 : 0;

                if ($title === '') throw new Exception('Facility title is required.');

                if ($action === 'create_facility') {
                    $pdo->prepare("INSERT INTO gym_facilities
                        (icon_class, title, description, display_order, is_active)
                        VALUES (?, ?, ?, ?, ?)")
                        ->execute([$icon, $title, $desc ?: null, $order, $active]);
                    $message = 'Facility created.';
                } else {
                    $id = (int)($_POST['item_id'] ?? 0);
                    if ($id <= 0) throw new Exception('Invalid facility ID.');
                    $pdo->prepare("UPDATE gym_facilities SET
                        icon_class=?, title=?, description=?, display_order=?, is_active=?
                        WHERE id=?")
                        ->execute([$icon, $title, $desc ?: null, $order, $active, $id]);
                    $message = 'Facility updated.';
                }

            } elseif ($action === 'delete_facility') {
                $id = (int)($_POST['item_id'] ?? 0);
                if ($id <= 0) throw new Exception('Invalid ID.');
                $pdo->prepare("DELETE FROM gym_facilities WHERE id=?")->execute([$id]);
                $message = 'Facility deleted.';

            // ── Features ─────────────────────────────────────────────────────
            } elseif ($action === 'create_feature' || $action === 'edit_feature') {
                $icon   = $san($_POST['icon_class'] ?? 'fas fa-dumbbell', 100);
                $title  = $san($_POST['title'] ?? '', 150);
                $desc   = $san($_POST['description'] ?? '', 2000);
                $order  = (int)($_POST['display_order'] ?? 0);
                $active = isset($_POST['is_active']) ? 1 : 0;

                if ($title === '') throw new Exception('Feature title is required.');

                if ($action === 'create_feature') {
                    $pdo->prepare("INSERT INTO gym_features
                        (icon_class, title, description, display_order, is_active)
                        VALUES (?, ?, ?, ?, ?)")
                        ->execute([$icon, $title, $desc ?: null, $order, $active]);
                    $message = 'Feature created.';
                } else {
                    $id = (int)($_POST['item_id'] ?? 0);
                    if ($id <= 0) throw new Exception('Invalid feature ID.');
                    $pdo->prepare("UPDATE gym_features SET
                        icon_class=?, title=?, description=?, display_order=?, is_active=?
                        WHERE id=?")
                        ->execute([$icon, $title, $desc ?: null, $order, $active, $id]);
                    $message = 'Feature updated.';
                }

            } elseif ($action === 'delete_feature') {
                $id = (int)($_POST['item_id'] ?? 0);
                if ($id <= 0) throw new Exception('Invalid ID.');
                $pdo->prepare("DELETE FROM gym_features WHERE id=?")->execute([$id]);
                $message = 'Feature deleted.';
            }

        } catch (PDOException $e) {
            $error = 'Database error: ' . $e->getMessage();
        } catch (Exception $e) {
            $error = $e->getMessage();
        }
    }
}

// ── Data Fetches ───────────────────────────────────────────────────────────────
try {
    $gymContent   = $pdo->query("SELECT * FROM gym_content ORDER BY id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    $gymPackages  = $pdo->query("SELECT * FROM gym_packages ORDER BY display_order, id")->fetchAll(PDO::FETCH_ASSOC);
    $gymClasses   = $pdo->query("SELECT * FROM gym_classes ORDER BY display_order, id")->fetchAll(PDO::FETCH_ASSOC);
    $gymFacilities= $pdo->query("SELECT * FROM gym_facilities ORDER BY display_order, id")->fetchAll(PDO::FETCH_ASSOC);
    $gymFeatures  = $pdo->query("SELECT * FROM gym_features ORDER BY display_order, id")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $error = 'Failed to load gym data: ' . $e->getMessage();
    $gymContent = null; $gymPackages = $gymClasses = $gymFacilities = $gymFeatures = [];
}

// Edit state (which tab + item)
$editTab  = $_GET['tab']  ?? 'content';   // active tab
$editId   = isset($_GET['edit']) ? (int)$_GET['edit'] : 0;
$editType = $_GET['type'] ?? '';

$editItem = null;
if ($editId > 0) {
    $tableMap = ['package'=>'gym_packages','class'=>'gym_classes','facility'=>'gym_facilities','feature'=>'gym_features'];
    if (isset($tableMap[$editType])) {
        try {
            $s = $pdo->prepare("SELECT * FROM {$tableMap[$editType]} WHERE id=?");
            $s->execute([$editId]);
            $editItem = $s->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (PDOException $e) {}
    }
}

$siteName = function_exists('getSetting') ? getSetting('site_name', 'Hotel') : 'Hotel';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gym Management - Admin</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;500;600;700&family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../css/style.css">
    <link rel="stylesheet" href="../css/theme-dynamic.php">
    <link rel="stylesheet" href="css/admin-styles.css">
    <link rel="stylesheet" href="css/admin-components.css">

    <style>
        /* ── Tab Navigation ─────────────────────────────── */
        .gym-tabs {
            display: flex;
            gap: 4px;
            flex-wrap: wrap;
            border-bottom: 2px solid #e5e7eb;
            margin-bottom: 22px;
        }
        .gym-tab {
            padding: 10px 18px;
            border: none;
            background: none;
            font-size: 14px;
            font-weight: 600;
            color: #6b7280;
            cursor: pointer;
            border-bottom: 3px solid transparent;
            margin-bottom: -2px;
            border-radius: 6px 6px 0 0;
            transition: color .15s, border-color .15s;
            text-decoration: none;
            display: inline-block;
        }
        .gym-tab:hover { color: #1f2937; }
        .gym-tab.active { color: #2563eb; border-bottom-color: #2563eb; background: #eff6ff; }

        /* ── Two-column layout ───────────────────────────── */
        .gym-layout {
            display: grid;
            grid-template-columns: minmax(300px, 380px) 1fr;
            gap: 22px;
            align-items: start;
        }
        @media (max-width: 1024px) { .gym-layout { grid-template-columns: 1fr; } }

        /* ── Panel ───────────────────────────────────────── */
        .gym-panel {
            background: #fff;
            border-radius: 12px;
            box-shadow: 0 2px 12px rgba(0,0,0,.08);
            padding: 20px;
        }
        .gym-panel h3 { margin: 0 0 16px; font-size: 17px; color: #1f2937; }

        /* ── Form ────────────────────────────────────────── */
        .gform { display: flex; flex-direction: column; gap: 12px; }
        .gfg { display: flex; flex-direction: column; gap: 4px; }
        .gfg label { font-size: 12px; font-weight: 600; color: #374151; }
        .gfg input,
        .gfg select,
        .gfg textarea {
            border: 1px solid #d1d5db;
            border-radius: 7px;
            padding: 8px 10px;
            font-size: 13px;
            font-family: inherit;
        }
        .gfg textarea { resize: vertical; min-height: 80px; }
        .gfg-row { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
        .gfg-row-3 { display: grid; grid-template-columns: 1fr 1fr 80px; gap: 12px; }
        .gfg-cb { display: flex; align-items: center; gap: 8px; font-size: 13px; color: #374151; }
        .gfg-cb input[type=checkbox] { width: 16px; height: 16px; cursor: pointer; }
        .gfg-hint { font-size: 11px; color: #9ca3af; margin-top: 2px; }
        .btn-save {
            background: #2563eb; color: #fff;
            border: none; border-radius: 8px;
            padding: 10px; font-size: 14px; font-weight: 600;
            cursor: pointer; transition: background .15s;
        }
        .btn-save:hover { background: #1d4ed8; }
        .btn-cancel {
            background: #f3f4f6; color: #374151;
            border: none; border-radius: 8px;
            padding: 9px; font-size: 13px; font-weight: 600;
            cursor: pointer; text-align: center; text-decoration: none;
            display: block;
        }

        /* ── Item Table ──────────────────────────────────── */
        .gym-table { width: 100%; border-collapse: collapse; font-size: 13px; }
        .gym-table th,
        .gym-table td {
            border-bottom: 1px solid #e5e7eb;
            padding: 9px 8px;
            text-align: left;
            vertical-align: middle;
        }
        .gym-table th { font-weight: 700; color: #374151; background: #f9fafb; }
        .gym-table-wrap { overflow-x: auto; }

        /* ── Inline toggle badge ─────────────────────────── */
        .badge-active   { background: #dcfce7; color: #15803d; display: inline-block; padding: 2px 8px; border-radius: 999px; font-size: 11px; font-weight: 600; }
        .badge-inactive { background: #f3f4f6; color: #6b7280; display: inline-block; padding: 2px 8px; border-radius: 999px; font-size: 11px; font-weight: 600; }
        .badge-featured { background: #fef9c3; color: #854d0e; display: inline-block; padding: 2px 8px; border-radius: 999px; font-size: 11px; font-weight: 600; }

        /* ── Row actions ─────────────────────────────────── */
        .row-actions { display: flex; gap: 4px; flex-wrap: wrap; }
        .btn-sm {
            border: none; border-radius: 6px;
            padding: 5px 10px; font-size: 11px; font-weight: 600;
            cursor: pointer; white-space: nowrap; text-decoration: none;
            display: inline-block;
        }
        .btn-edit-sm  { background: #e0f2fe; color: #0369a1; }
        .btn-del-sm   { background: #fee2e2; color: #b91c1c; }

        /* ── Icon preview ────────────────────────────────── */
        .icon-preview { font-size: 18px; color: #6b7280; margin-right: 6px; vertical-align: middle; }

        /* ── Section divider ─────────────────────────────── */
        .section-note {
            font-size: 12px; color: #6b7280;
            border-left: 3px solid #e5e7eb;
            padding: 6px 10px;
            background: #f9fafb;
            border-radius: 0 6px 6px 0;
            margin-bottom: 14px;
        }

        /* ── Content tab full-width ──────────────────────── */
        .content-form { max-width: 640px; }

        /* ── Mobile ──────────────────────────────────────── */
        @media (max-width: 768px) {
            .gfg-row, .gfg-row-3 { grid-template-columns: 1fr; }
            .gym-tabs { gap: 2px; }
            .gym-tab { padding: 8px 12px; font-size: 13px; }
        }
    </style>
</head>
<body>
<div class="admin-layout">
    <?php require_once 'includes/admin-header.php'; ?>

    <main class="admin-main">
        <div class="admin-content">

            <!-- Page Header -->
            <div class="admin-page-header">
                <h2><i class="fas fa-dumbbell"></i> Gym Management</h2>
                <p>Edit gym packages, classes, facilities, features, and page content.</p>
            </div>

            <!-- Alerts -->
            <?php if ($message): ?><?php showAlert($message, 'success'); ?><?php endif; ?>
            <?php if ($error):   ?><?php showAlert($error,   'error');   ?><?php endif; ?>

            <!-- Tab Navigation -->
            <div class="gym-tabs">
                <?php
                $tabs = [
                    'content'    => ['icon' => 'fa-file-alt',    'label' => 'Page Content'],
                    'packages'   => ['icon' => 'fa-box-open',    'label' => 'Packages'],
                    'classes'    => ['icon' => 'fa-calendar-alt','label' => 'Classes'],
                    'facilities' => ['icon' => 'fa-building',    'label' => 'Facilities'],
                    'features'   => ['icon' => 'fa-star',        'label' => 'Features'],
                ];
                foreach ($tabs as $key => $tab): ?>
                <a href="gym-management.php?tab=<?php echo $key; ?>"
                   class="gym-tab <?php echo $editTab === $key ? 'active' : ''; ?>">
                    <i class="fas <?php echo $tab['icon']; ?>"></i> <?php echo $tab['label']; ?>
                </a>
                <?php endforeach; ?>
            </div>

            <!-- ══════════════════════════════════════════════════════════ -->
            <!-- TAB: Page Content                                          -->
            <!-- ══════════════════════════════════════════════════════════ -->
            <?php if ($editTab === 'content'): ?>
            <div class="content-form">
                <p class="section-note">
                    This content appears on the public <strong>gym.php</strong> page — hero section, wellness intro, and image paths.
                </p>
                <div class="gym-panel">
                    <h3><i class="fas fa-edit"></i> Edit Gym Page Content</h3>
                    <form method="POST" action="gym-management.php?tab=content" class="gform">
                        <?php echo getCsrfField(); ?>
                        <input type="hidden" name="action" value="update_content">
                        <input type="hidden" name="content_id" value="<?php echo (int)($gymContent['id'] ?? 0); ?>">

                        <div class="gfg">
                            <label>Hero Title</label>
                            <input type="text" name="hero_title" maxlength="200" required
                                   value="<?php echo htmlspecialchars($gymContent['hero_title'] ?? 'Fitness Center'); ?>">
                        </div>
                        <div class="gfg">
                            <label>Hero Subtitle <small style="font-weight:400;color:#9ca3af">(tag line)</small></label>
                            <input type="text" name="hero_subtitle" maxlength="200"
                                   value="<?php echo htmlspecialchars($gymContent['hero_subtitle'] ?? ''); ?>">
                        </div>
                        <div class="gfg">
                            <label>Hero Description</label>
                            <textarea name="hero_description" rows="3"><?php echo htmlspecialchars($gymContent['hero_description'] ?? ''); ?></textarea>
                        </div>
                        <div class="gfg">
                            <label>Hero Image Path</label>
                            <input type="text" name="hero_image_path" maxlength="255"
                                   placeholder="images/gym/hero-bg.jpg"
                                   value="<?php echo htmlspecialchars($gymContent['hero_image_path'] ?? ''); ?>">
                        </div>

                        <hr style="border:none;border-top:1px solid #e5e7eb;margin:4px 0;">

                        <div class="gfg">
                            <label>Wellness Section Title</label>
                            <input type="text" name="wellness_title" maxlength="200"
                                   value="<?php echo htmlspecialchars($gymContent['wellness_title'] ?? ''); ?>">
                        </div>
                        <div class="gfg">
                            <label>Wellness Description</label>
                            <textarea name="wellness_description" rows="3"><?php echo htmlspecialchars($gymContent['wellness_description'] ?? ''); ?></textarea>
                        </div>
                        <div class="gfg">
                            <label>Wellness Image Path</label>
                            <input type="text" name="wellness_image_path" maxlength="255"
                                   placeholder="images/gym/fitness-center.jpg"
                                   value="<?php echo htmlspecialchars($gymContent['wellness_image_path'] ?? ''); ?>">
                        </div>

                        <hr style="border:none;border-top:1px solid #e5e7eb;margin:4px 0;">

                        <div class="gfg">
                            <label>Badge Text <small style="font-weight:400;color:#9ca3af">(small highlight badge)</small></label>
                            <input type="text" name="badge_text" maxlength="120"
                                   value="<?php echo htmlspecialchars($gymContent['badge_text'] ?? ''); ?>">
                        </div>
                        <div class="gfg">
                            <label>Personal Training Image Path / URL</label>
                            <input type="text" name="personal_training_image_path" maxlength="255"
                                   placeholder="images/gym/personal-training.jpg"
                                   value="<?php echo htmlspecialchars($gymContent['personal_training_image_path'] ?? ''); ?>">
                        </div>

                        <button type="submit" class="btn-save"><i class="fas fa-save"></i> Save Content</button>
                    </form>
                </div>
            </div>
            <?php endif; ?>

            <!-- ══════════════════════════════════════════════════════════ -->
            <!-- TAB: Packages                                              -->
            <!-- ══════════════════════════════════════════════════════════ -->
            <?php if ($editTab === 'packages'): ?>
            <?php
            $editPkg = ($editType === 'package' && $editItem) ? $editItem : null;
            ?>
            <div class="gym-layout">

                <!-- Form -->
                <div class="gym-panel">
                    <h3>
                        <?php if ($editPkg): ?>
                        <i class="fas fa-edit"></i> Edit Package
                        <?php else: ?>
                        <i class="fas fa-plus-circle"></i> New Package
                        <?php endif; ?>
                    </h3>
                    <form method="POST" action="gym-management.php?tab=packages" class="gform">
                        <?php echo getCsrfField(); ?>
                        <input type="hidden" name="action" value="<?php echo $editPkg ? 'edit_package' : 'create_package'; ?>">
                        <?php if ($editPkg): ?>
                        <input type="hidden" name="item_id" value="<?php echo (int)$editPkg['id']; ?>">
                        <?php endif; ?>

                        <div class="gfg">
                            <label>Package Name *</label>
                            <input type="text" name="name" maxlength="150" required
                                   placeholder="e.g. Rejuvenation Retreat"
                                   value="<?php echo htmlspecialchars($editPkg['name'] ?? ''); ?>">
                        </div>

                        <div class="gfg-row">
                            <div class="gfg">
                                <label>Font Awesome Icon Class</label>
                                <input type="text" name="icon_class" maxlength="100"
                                       placeholder="fas fa-leaf"
                                       value="<?php echo htmlspecialchars($editPkg['icon_class'] ?? 'fas fa-leaf'); ?>">
                                <span class="gfg-hint">e.g. fas fa-star, fas fa-dumbbell, fas fa-leaf</span>
                            </div>
                            <div class="gfg">
                                <label>Duration Label</label>
                                <input type="text" name="duration_label" maxlength="50"
                                       placeholder="e.g. 7 Days"
                                       value="<?php echo htmlspecialchars($editPkg['duration_label'] ?? ''); ?>">
                            </div>
                        </div>

                        <div class="gfg">
                            <label>What's Included <small style="font-weight:400;color:#9ca3af">(one bullet per line)</small></label>
                            <textarea name="includes_text" rows="5"
                                      placeholder="3 personal training sessions&#10;Daily yoga classes&#10;1 spa massage"><?php echo htmlspecialchars($editPkg['includes_text'] ?? ''); ?></textarea>
                        </div>

                        <div class="gfg-row">
                            <div class="gfg">
                                <label>Price</label>
                                <input type="number" name="price" min="0" step="0.01"
                                       value="<?php echo number_format((float)($editPkg['price'] ?? 0), 2, '.', ''); ?>">
                            </div>
                            <div class="gfg">
                                <label>Currency</label>
                                <input type="text" name="currency_code" maxlength="10"
                                       value="<?php echo htmlspecialchars($editPkg['currency_code'] ?? 'MWK'); ?>">
                            </div>
                        </div>

                        <div class="gfg-row">
                            <div class="gfg">
                                <label>Button Text</label>
                                <input type="text" name="cta_text" maxlength="120"
                                       value="<?php echo htmlspecialchars($editPkg['cta_text'] ?? 'Book Package'); ?>">
                            </div>
                            <div class="gfg">
                                <label>Button Link</label>
                                <input type="text" name="cta_link" maxlength="255"
                                       value="<?php echo htmlspecialchars($editPkg['cta_link'] ?? '#book'); ?>">
                            </div>
                        </div>

                        <div class="gfg">
                            <label>Display Order</label>
                            <input type="number" name="display_order" min="0"
                                   value="<?php echo (int)($editPkg['display_order'] ?? 0); ?>">
                        </div>

                        <div style="display:flex;gap:16px;flex-wrap:wrap;">
                            <label class="gfg-cb">
                                <input type="checkbox" name="is_featured" value="1"
                                    <?php echo ($editPkg['is_featured'] ?? 0) ? 'checked' : ''; ?>>
                                Featured (highlighted)
                            </label>
                            <label class="gfg-cb">
                                <input type="checkbox" name="is_active" value="1"
                                    <?php echo isset($editPkg) ? (($editPkg['is_active'] ?? 1) ? 'checked' : '') : 'checked'; ?>>
                                Active (visible on site)
                            </label>
                        </div>

                        <button type="submit" class="btn-save">
                            <?php echo $editPkg ? 'Update Package' : 'Create Package'; ?>
                        </button>
                        <?php if ($editPkg): ?>
                        <a href="gym-management.php?tab=packages" class="btn-cancel">Cancel Edit</a>
                        <?php endif; ?>
                    </form>
                </div>

                <!-- List -->
                <div class="gym-panel">
                    <h3><i class="fas fa-list"></i> Packages (<?php echo count($gymPackages); ?>)</h3>
                    <div class="gym-table-wrap">
                        <table class="gym-table">
                            <thead>
                                <tr>
                                    <th>Name</th>
                                    <th>Price</th>
                                    <th>Duration</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php if (empty($gymPackages)): ?>
                                <tr><td colspan="5" style="text-align:center;color:#9ca3af;padding:24px;">No packages yet.</td></tr>
                            <?php else: ?>
                                <?php foreach ($gymPackages as $pkg): ?>
                                <tr>
                                    <td>
                                        <i class="<?php echo htmlspecialchars($pkg['icon_class']); ?> icon-preview"></i>
                                        <strong><?php echo htmlspecialchars($pkg['name']); ?></strong>
                                        <?php if ($pkg['is_featured']): ?>
                                        <span class="badge-featured">Featured</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo htmlspecialchars($pkg['currency_code']); ?> <?php echo number_format((float)$pkg['price'], 0); ?></td>
                                    <td><?php echo htmlspecialchars($pkg['duration_label'] ?? '—'); ?></td>
                                    <td><span class="badge-<?php echo $pkg['is_active'] ? 'active' : 'inactive'; ?>"><?php echo $pkg['is_active'] ? 'Active' : 'Inactive'; ?></span></td>
                                    <td>
                                        <div class="row-actions">
                                            <a href="gym-management.php?tab=packages&edit=<?php echo (int)$pkg['id']; ?>&type=package"
                                               class="btn-sm btn-edit-sm"><i class="fas fa-pen"></i> Edit</a>
                                                                                        <form method="POST" style="display:inline"
                                                                                                    onsubmit="return confirm('Delete this package?')">
                                                                                                <?php echo getCsrfField(); ?>
                                                <input type="hidden" name="action"  value="delete_package">
                                                <input type="hidden" name="item_id" value="<?php echo (int)$pkg['id']; ?>">
                                                <button class="btn-sm btn-del-sm"><i class="fas fa-trash"></i></button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

            </div>
            <?php endif; ?>

            <!-- ══════════════════════════════════════════════════════════ -->
            <!-- TAB: Classes                                               -->
            <!-- ══════════════════════════════════════════════════════════ -->
            <?php if ($editTab === 'classes'): ?>
            <?php $editCls = ($editType === 'class' && $editItem) ? $editItem : null; ?>
            <div class="gym-layout">

                <div class="gym-panel">
                    <h3><?php echo $editCls ? '<i class="fas fa-edit"></i> Edit Class' : '<i class="fas fa-plus-circle"></i> New Class'; ?></h3>
                    <form method="POST" action="gym-management.php?tab=classes" class="gform">
                        <?php echo getCsrfField(); ?>
                        <input type="hidden" name="action" value="<?php echo $editCls ? 'edit_class' : 'create_class'; ?>">
                        <?php if ($editCls): ?>
                        <input type="hidden" name="item_id" value="<?php echo (int)$editCls['id']; ?>">
                        <?php endif; ?>

                        <div class="gfg">
                            <label>Class Title *</label>
                            <input type="text" name="title" maxlength="150" required
                                   placeholder="e.g. Morning Yoga Flow"
                                   value="<?php echo htmlspecialchars($editCls['title'] ?? ''); ?>">
                        </div>
                        <div class="gfg">
                            <label>Description</label>
                            <textarea name="description" rows="3"><?php echo htmlspecialchars($editCls['description'] ?? ''); ?></textarea>
                        </div>
                        <div class="gfg">
                            <label>Day / Schedule</label>
                            <input type="text" name="day_label" maxlength="120"
                                   placeholder="e.g. Monday - Friday"
                                   value="<?php echo htmlspecialchars($editCls['day_label'] ?? ''); ?>">
                        </div>
                        <div class="gfg-row">
                            <div class="gfg">
                                <label>Time</label>
                                <input type="text" name="time_label" maxlength="50"
                                       placeholder="e.g. 6:30 AM"
                                       value="<?php echo htmlspecialchars($editCls['time_label'] ?? ''); ?>">
                            </div>
                            <div class="gfg">
                                <label>Level</label>
                                <input type="text" name="level_label" maxlength="80"
                                       placeholder="e.g. All Levels"
                                       value="<?php echo htmlspecialchars($editCls['level_label'] ?? 'All Levels'); ?>">
                            </div>
                        </div>
                        <div class="gfg">
                            <label>Display Order</label>
                            <input type="number" name="display_order" min="0"
                                   value="<?php echo (int)($editCls['display_order'] ?? 0); ?>">
                        </div>
                        <label class="gfg-cb">
                            <input type="checkbox" name="is_active" value="1"
                                <?php echo isset($editCls) ? (($editCls['is_active'] ?? 1) ? 'checked' : '') : 'checked'; ?>>
                            Active (visible on site)
                        </label>

                        <button type="submit" class="btn-save"><?php echo $editCls ? 'Update Class' : 'Create Class'; ?></button>
                        <?php if ($editCls): ?>
                        <a href="gym-management.php?tab=classes" class="btn-cancel">Cancel Edit</a>
                        <?php endif; ?>
                    </form>
                </div>

                <div class="gym-panel">
                    <h3><i class="fas fa-list"></i> Classes (<?php echo count($gymClasses); ?>)</h3>
                    <div class="gym-table-wrap">
                        <table class="gym-table">
                            <thead><tr><th>Title</th><th>Day</th><th>Time</th><th>Level</th><th>Status</th><th>Actions</th></tr></thead>
                            <tbody>
                            <?php if (empty($gymClasses)): ?>
                                <tr><td colspan="6" style="text-align:center;color:#9ca3af;padding:24px;">No classes yet.</td></tr>
                            <?php else: ?>
                                <?php foreach ($gymClasses as $cls): ?>
                                <tr>
                                    <td><strong><?php echo htmlspecialchars($cls['title']); ?></strong></td>
                                    <td><?php echo htmlspecialchars($cls['day_label']); ?></td>
                                    <td><?php echo htmlspecialchars($cls['time_label']); ?></td>
                                    <td><?php echo htmlspecialchars($cls['level_label']); ?></td>
                                    <td><span class="badge-<?php echo $cls['is_active'] ? 'active' : 'inactive'; ?>"><?php echo $cls['is_active'] ? 'Active' : 'Inactive'; ?></span></td>
                                    <td>
                                        <div class="row-actions">
                                            <a href="gym-management.php?tab=classes&edit=<?php echo (int)$cls['id']; ?>&type=class"
                                               class="btn-sm btn-edit-sm"><i class="fas fa-pen"></i> Edit</a>
                                            <form method="POST" style="display:inline" onsubmit="return confirm('Delete this class?')">
                                                <?php echo getCsrfField(); ?>
                                                <input type="hidden" name="action"  value="delete_class">
                                                <input type="hidden" name="item_id" value="<?php echo (int)$cls['id']; ?>">
                                                <button class="btn-sm btn-del-sm"><i class="fas fa-trash"></i></button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

            </div>
            <?php endif; ?>

            <!-- ══════════════════════════════════════════════════════════ -->
            <!-- TAB: Facilities                                            -->
            <!-- ══════════════════════════════════════════════════════════ -->
            <?php if ($editTab === 'facilities'): ?>
            <?php $editFac = ($editType === 'facility' && $editItem) ? $editItem : null; ?>
            <div class="gym-layout">

                <div class="gym-panel">
                    <h3><?php echo $editFac ? '<i class="fas fa-edit"></i> Edit Facility' : '<i class="fas fa-plus-circle"></i> New Facility'; ?></h3>
                    <form method="POST" action="gym-management.php?tab=facilities" class="gform">
                        <?php echo getCsrfField(); ?>
                        <input type="hidden" name="action" value="<?php echo $editFac ? 'edit_facility' : 'create_facility'; ?>">
                        <?php if ($editFac): ?>
                        <input type="hidden" name="item_id" value="<?php echo (int)$editFac['id']; ?>">
                        <?php endif; ?>

                        <div class="gfg">
                            <label>Icon Class <small style="font-weight:400;color:#9ca3af">(Font Awesome)</small></label>
                            <input type="text" name="icon_class" maxlength="100"
                                   placeholder="fas fa-running"
                                   value="<?php echo htmlspecialchars($editFac['icon_class'] ?? 'fas fa-check'); ?>">
                            <span class="gfg-hint">e.g. fas fa-dumbbell, fas fa-swimming-pool, fas fa-hot-tub</span>
                        </div>
                        <div class="gfg">
                            <label>Title *</label>
                            <input type="text" name="title" maxlength="150" required
                                   placeholder="e.g. Cardio Zone"
                                   value="<?php echo htmlspecialchars($editFac['title'] ?? ''); ?>">
                        </div>
                        <div class="gfg">
                            <label>Description</label>
                            <textarea name="description" rows="3"><?php echo htmlspecialchars($editFac['description'] ?? ''); ?></textarea>
                        </div>
                        <div class="gfg">
                            <label>Display Order</label>
                            <input type="number" name="display_order" min="0"
                                   value="<?php echo (int)($editFac['display_order'] ?? 0); ?>">
                        </div>
                        <label class="gfg-cb">
                            <input type="checkbox" name="is_active" value="1"
                                <?php echo isset($editFac) ? (($editFac['is_active'] ?? 1) ? 'checked' : '') : 'checked'; ?>>
                            Active (visible on site)
                        </label>

                        <button type="submit" class="btn-save"><?php echo $editFac ? 'Update Facility' : 'Create Facility'; ?></button>
                        <?php if ($editFac): ?>
                        <a href="gym-management.php?tab=facilities" class="btn-cancel">Cancel Edit</a>
                        <?php endif; ?>
                    </form>
                </div>

                <div class="gym-panel">
                    <h3><i class="fas fa-list"></i> Facilities (<?php echo count($gymFacilities); ?>)</h3>
                    <div class="gym-table-wrap">
                        <table class="gym-table">
                            <thead><tr><th>Facility</th><th>Description</th><th>Status</th><th>Actions</th></tr></thead>
                            <tbody>
                            <?php if (empty($gymFacilities)): ?>
                                <tr><td colspan="4" style="text-align:center;color:#9ca3af;padding:24px;">No facilities yet.</td></tr>
                            <?php else: ?>
                                <?php foreach ($gymFacilities as $fac): ?>
                                <tr>
                                    <td>
                                        <i class="<?php echo htmlspecialchars($fac['icon_class']); ?> icon-preview"></i>
                                        <strong><?php echo htmlspecialchars($fac['title']); ?></strong>
                                    </td>
                                    <td style="color:#6b7280;font-size:12px;"><?php echo htmlspecialchars(mb_substr($fac['description'] ?? '', 0, 80)) . (mb_strlen($fac['description'] ?? '') > 80 ? '…' : ''); ?></td>
                                    <td><span class="badge-<?php echo $fac['is_active'] ? 'active' : 'inactive'; ?>"><?php echo $fac['is_active'] ? 'Active' : 'Inactive'; ?></span></td>
                                    <td>
                                        <div class="row-actions">
                                            <a href="gym-management.php?tab=facilities&edit=<?php echo (int)$fac['id']; ?>&type=facility"
                                               class="btn-sm btn-edit-sm"><i class="fas fa-pen"></i> Edit</a>
                                            <form method="POST" style="display:inline" onsubmit="return confirm('Delete this facility?')">
                                                <?php echo getCsrfField(); ?>
                                                <input type="hidden" name="action"  value="delete_facility">
                                                <input type="hidden" name="item_id" value="<?php echo (int)$fac['id']; ?>">
                                                <button class="btn-sm btn-del-sm"><i class="fas fa-trash"></i></button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

            </div>
            <?php endif; ?>

            <!-- ══════════════════════════════════════════════════════════ -->
            <!-- TAB: Features                                              -->
            <!-- ══════════════════════════════════════════════════════════ -->
            <?php if ($editTab === 'features'): ?>
            <?php $editFeat = ($editType === 'feature' && $editItem) ? $editItem : null; ?>
            <div class="gym-layout">

                <div class="gym-panel">
                    <h3><?php echo $editFeat ? '<i class="fas fa-edit"></i> Edit Feature' : '<i class="fas fa-plus-circle"></i> New Feature'; ?></h3>
                    <form method="POST" action="gym-management.php?tab=features" class="gform">
                        <?php echo getCsrfField(); ?>
                        <input type="hidden" name="action" value="<?php echo $editFeat ? 'edit_feature' : 'create_feature'; ?>">
                        <?php if ($editFeat): ?>
                        <input type="hidden" name="item_id" value="<?php echo (int)$editFeat['id']; ?>">
                        <?php endif; ?>

                        <div class="gfg">
                            <label>Icon Class <small style="font-weight:400;color:#9ca3af">(Font Awesome)</small></label>
                            <input type="text" name="icon_class" maxlength="100"
                                   placeholder="fas fa-dumbbell"
                                   value="<?php echo htmlspecialchars($editFeat['icon_class'] ?? 'fas fa-dumbbell'); ?>">
                        </div>
                        <div class="gfg">
                            <label>Title *</label>
                            <input type="text" name="title" maxlength="150" required
                                   placeholder="e.g. Modern Equipment"
                                   value="<?php echo htmlspecialchars($editFeat['title'] ?? ''); ?>">
                        </div>
                        <div class="gfg">
                            <label>Description</label>
                            <textarea name="description" rows="3"><?php echo htmlspecialchars($editFeat['description'] ?? ''); ?></textarea>
                        </div>
                        <div class="gfg">
                            <label>Display Order</label>
                            <input type="number" name="display_order" min="0"
                                   value="<?php echo (int)($editFeat['display_order'] ?? 0); ?>">
                        </div>
                        <label class="gfg-cb">
                            <input type="checkbox" name="is_active" value="1"
                                <?php echo isset($editFeat) ? (($editFeat['is_active'] ?? 1) ? 'checked' : '') : 'checked'; ?>>
                            Active (visible on site)
                        </label>

                        <button type="submit" class="btn-save"><?php echo $editFeat ? 'Update Feature' : 'Create Feature'; ?></button>
                        <?php if ($editFeat): ?>
                        <a href="gym-management.php?tab=features" class="btn-cancel">Cancel Edit</a>
                        <?php endif; ?>
                    </form>
                </div>

                <div class="gym-panel">
                    <h3><i class="fas fa-list"></i> Features (<?php echo count($gymFeatures); ?>)</h3>
                    <div class="gym-table-wrap">
                        <table class="gym-table">
                            <thead><tr><th>Feature</th><th>Description</th><th>Status</th><th>Actions</th></tr></thead>
                            <tbody>
                            <?php if (empty($gymFeatures)): ?>
                                <tr><td colspan="4" style="text-align:center;color:#9ca3af;padding:24px;">No features yet.</td></tr>
                            <?php else: ?>
                                <?php foreach ($gymFeatures as $feat): ?>
                                <tr>
                                    <td>
                                        <i class="<?php echo htmlspecialchars($feat['icon_class']); ?> icon-preview"></i>
                                        <strong><?php echo htmlspecialchars($feat['title']); ?></strong>
                                    </td>
                                    <td style="color:#6b7280;font-size:12px;"><?php echo htmlspecialchars(mb_substr($feat['description'] ?? '', 0, 80)) . (mb_strlen($feat['description'] ?? '') > 80 ? '…' : ''); ?></td>
                                    <td><span class="badge-<?php echo $feat['is_active'] ? 'active' : 'inactive'; ?>"><?php echo $feat['is_active'] ? 'Active' : 'Inactive'; ?></span></td>
                                    <td>
                                        <div class="row-actions">
                                            <a href="gym-management.php?tab=features&edit=<?php echo (int)$feat['id']; ?>&type=feature"
                                               class="btn-sm btn-edit-sm"><i class="fas fa-pen"></i> Edit</a>
                                            <form method="POST" style="display:inline" onsubmit="return confirm('Delete this feature?')">
                                                <?php echo getCsrfField(); ?>
                                                <input type="hidden" name="action"  value="delete_feature">
                                                <input type="hidden" name="item_id" value="<?php echo (int)$feat['id']; ?>">
                                                <button class="btn-sm btn-del-sm"><i class="fas fa-trash"></i></button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

            </div>
            <?php endif; ?>

        </div>
    </main>
</div>
</body>
</html>
