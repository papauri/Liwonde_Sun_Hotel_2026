<?php
require_once 'admin-init.php';
require_once '../includes/alert.php';

$message = '';
$error = '';

// Restrict this page to super admin only
if (($user['role'] ?? '') !== 'admin') {
    header('Location: dashboard.php?error=access_denied');
    exit;
}

$allowed_tables = ['bookings', 'gym_inquiries', 'conference_inquiries'];

function isAllowedBackupTable($table, $allowed_tables) {
    return in_array($table, $allowed_tables, true);
}

function appendBackupMetadata(array $existingMeta, array $updates) {
    return array_merge($existingMeta, $updates);
}

function getBackupRecordById($backup_id) {
    global $pdo;

    $stmt = $pdo->prepare("SELECT * FROM deleted_records_backup WHERE id = ? LIMIT 1");
    $stmt->execute([$backup_id]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

function recordExistsInSourceTable($table, $source_id) {
    global $pdo;

    if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) {
        return false;
    }

    $safe_table = str_replace('`', '``', $table);
    $stmt = $pdo->prepare("SELECT id FROM `{$safe_table}` WHERE id = ? LIMIT 1");
    $stmt->execute([$source_id]);
    return (bool)$stmt->fetch(PDO::FETCH_ASSOC);
}

function loadAdminUsersMap(array $user_ids) {
    global $pdo;

    $user_ids = array_values(array_unique(array_filter(array_map('intval', $user_ids), function ($id) {
        return $id > 0;
    })));

    if (empty($user_ids)) {
        return [];
    }

    $placeholders = implode(', ', array_fill(0, count($user_ids), '?'));
    $stmt = $pdo->prepare("SELECT id, full_name, username, role FROM admin_users WHERE id IN ({$placeholders})");
    $stmt->execute($user_ids);

    $users = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $users[(int)$row['id']] = $row;
    }

    return $users;
}

function formatAdminActor($user_id, array $admin_users_map) {
    $user_id = (int)$user_id;
    if ($user_id <= 0) {
        return 'System';
    }

    if (!isset($admin_users_map[$user_id])) {
        return 'Unknown user (#' . $user_id . ')';
    }

    $admin = $admin_users_map[$user_id];
    $name = trim((string)($admin['full_name'] ?? ''));
    $username = trim((string)($admin['username'] ?? ''));
    $role = trim((string)($admin['role'] ?? ''));

    $label = $name !== '' ? $name : $username;
    if ($label === '') {
        $label = 'User #' . $user_id;
    }

    if ($role !== '') {
        $label .= ' (' . $role . ')';
    }

    if ($username !== '' && $name !== '' && strcasecmp($username, $name) !== 0) {
        $label .= ' @' . $username;
    }

    return $label;
}

function buildAuditSummary(array $metadata, array $admin_users_map) {
    $summary = [];

    if (!empty($metadata['reason'])) {
        $summary['Reason'] = (string)$metadata['reason'];
    }

    if (!empty($metadata['booking_reference'])) {
        $summary['Booking Ref'] = (string)$metadata['booking_reference'];
    }

    if (!empty($metadata['inquiry_reference'])) {
        $summary['Inquiry Ref'] = (string)$metadata['inquiry_reference'];
    }

    if (!empty($metadata['preferred_date'])) {
        $summary['Preferred Date'] = (string)$metadata['preferred_date'];
    }

    if (!empty($metadata['deleted_from'])) {
        $summary['Deleted From'] = (string)$metadata['deleted_from'];
    }

    if (!empty($metadata['restore_page'])) {
        $summary['Restore Page'] = (string)$metadata['restore_page'];
    }

    if (!empty($metadata['redelete_page'])) {
        $summary['Re-delete Page'] = (string)$metadata['redelete_page'];
    }

    if (!empty($metadata['related_backup_id'])) {
        $summary['Related Backup'] = '#' . (string)$metadata['related_backup_id'];
    }

    if (!empty($metadata['restored_at'])) {
        $summary['Restored At'] = (string)$metadata['restored_at'];
    }

    if (!empty($metadata['restored_by'])) {
        $summary['Restored By'] = formatAdminActor($metadata['restored_by'], $admin_users_map);
    }

    if (!empty($metadata['redeleted_at'])) {
        $summary['Re-deleted At'] = (string)$metadata['redeleted_at'];
    }

    if (!empty($metadata['redeleted_by'])) {
        $summary['Re-deleted By'] = formatAdminActor($metadata['redeleted_by'], $admin_users_map);
    }

    return $summary;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $action = $_POST['action'] ?? '';

        if ($action === 'restore_backup') {
            $backup_id = (int)($_POST['backup_id'] ?? 0);
            if ($backup_id <= 0) {
                throw new Exception('Invalid backup record selected.');
            }

            $backup = getBackupRecordById($backup_id);

            if (!$backup) {
                throw new Exception('Backup record not found.');
            }

            $source_table = $backup['source_table'] ?? '';
            if (!isAllowedBackupTable($source_table, $allowed_tables)) {
                throw new Exception('Restore is only allowed for booking-related records.');
            }

            $row_data = json_decode($backup['row_data'] ?? '', true);
            if (!is_array($row_data) || empty($row_data)) {
                throw new Exception('Backup payload is invalid or empty.');
            }

            if (!array_key_exists('id', $row_data)) {
                throw new Exception('Backup row has no primary key id.');
            }

            $source_id = (string)$row_data['id'];

            $pdo->beginTransaction();

            $safe_table = str_replace('`', '``', $source_table);
            if (recordExistsInSourceTable($source_table, $source_id)) {
                throw new Exception('A record with this id already exists in ' . $source_table . '. Restore cancelled.');
            }

            $columns = array_keys($row_data);
            if (empty($columns)) {
                throw new Exception('No columns found in backup row.');
            }

            $quoted_columns = array_map(function ($col) {
                if (!preg_match('/^[A-Za-z0-9_]+$/', (string)$col)) {
                    throw new Exception('Unsafe column name in backup payload.');
                }
                return '`' . $col . '`';
            }, $columns);

            $placeholders = implode(', ', array_fill(0, count($columns), '?'));
            $insert_sql = "INSERT INTO `{$safe_table}` (" . implode(', ', $quoted_columns) . ") VALUES ({$placeholders})";
            $insert_stmt = $pdo->prepare($insert_sql);
            $insert_stmt->execute(array_values($row_data));

            $meta = json_decode($backup['metadata'] ?? '', true);
            if (!is_array($meta)) {
                $meta = [];
            }
            $meta = appendBackupMetadata($meta, [
                'restored_at' => date('Y-m-d H:i:s'),
                'restored_by' => $user['id'] ?? null,
                'restore_page' => 'admin/deleted-records-backup.php'
            ]);

            $meta_stmt = $pdo->prepare("UPDATE deleted_records_backup SET metadata = ? WHERE id = ?");
            $meta_stmt->execute([json_encode($meta), $backup_id]);

            $pdo->commit();
            $message = 'Backup restored successfully to ' . htmlspecialchars($source_table) . ' (ID: ' . htmlspecialchars($source_id) . ').';
        } elseif ($action === 'redelete_live') {
            $backup_id = (int)($_POST['backup_id'] ?? 0);
            if ($backup_id <= 0) {
                throw new Exception('Invalid backup record selected.');
            }

            $backup = getBackupRecordById($backup_id);
            if (!$backup) {
                throw new Exception('Backup record not found.');
            }

            $source_table = $backup['source_table'] ?? '';
            if (!isAllowedBackupTable($source_table, $allowed_tables)) {
                throw new Exception('Re-delete is only allowed for booking-related records.');
            }

            $row_data = json_decode($backup['row_data'] ?? '', true);
            if (!is_array($row_data) || !array_key_exists('id', $row_data)) {
                throw new Exception('Backup payload is invalid or empty.');
            }

            $source_id = (string)$row_data['id'];
            if (!recordExistsInSourceTable($source_table, $source_id)) {
                throw new Exception('No live record exists to re-delete.');
            }

            $pdo->beginTransaction();

            $backup_ok = backupRecordBeforeDelete($source_table, $source_id, 'id', [
                'reason' => 'manual_redelete_from_backup_page',
                'related_backup_id' => $backup_id,
                'deleted_by' => $user['id'] ?? null,
                'deleted_from' => 'admin/deleted-records-backup.php'
            ]);

            if (!$backup_ok) {
                throw new Exception('Unable to create a fresh backup snapshot before re-delete.');
            }

            $safe_table = str_replace('`', '``', $source_table);
            $delete_stmt = $pdo->prepare("DELETE FROM `{$safe_table}` WHERE id = ?");
            $delete_stmt->execute([$source_id]);

            $meta = json_decode($backup['metadata'] ?? '', true);
            if (!is_array($meta)) {
                $meta = [];
            }
            $meta = appendBackupMetadata($meta, [
                'redeleted_at' => date('Y-m-d H:i:s'),
                'redeleted_by' => $user['id'] ?? null,
                'redelete_page' => 'admin/deleted-records-backup.php'
            ]);

            $meta_stmt = $pdo->prepare("UPDATE deleted_records_backup SET metadata = ? WHERE id = ?");
            $meta_stmt->execute([json_encode($meta), $backup_id]);

            $pdo->commit();
            $message = 'Live record deleted again from ' . htmlspecialchars($source_table) . ' (ID: ' . htmlspecialchars($source_id) . ').';
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $error = 'Error: ' . $e->getMessage();
    }
}

try {
    ensureDeletedRecordsBackupTable();

    $search = trim($_GET['search'] ?? '');
    $table_filter = trim($_GET['table'] ?? '');
    $page = max(1, (int)($_GET['page'] ?? 1));
    $per_page = 50;

    $params = [];
    $where = [];

    if ($search !== '') {
        $where[] = '(source_id LIKE ? OR source_table LIKE ? OR row_data LIKE ? OR metadata LIKE ?)';
        $q = '%' . $search . '%';
        $params[] = $q;
        $params[] = $q;
        $params[] = $q;
        $params[] = $q;
    }

    if ($table_filter !== '' && in_array($table_filter, $allowed_tables, true)) {
        $where[] = 'source_table = ?';
        $params[] = $table_filter;
    } else {
        $table_filter = '';
    }

    $where_sql = '';
    if (!empty($where)) {
        $where_sql = ' WHERE ' . implode(' AND ', $where);
    }

    if (isset($_GET['export']) && $_GET['export'] === 'csv') {
        $export_sql = 'SELECT * FROM deleted_records_backup' . $where_sql . ' ORDER BY deleted_at DESC';
        $export_stmt = $pdo->prepare($export_sql);
        $export_stmt->execute($params);
        $export_rows = $export_stmt->fetchAll(PDO::FETCH_ASSOC);

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="deleted-records-backup-' . date('Ymd-His') . '.csv"');

        $output = fopen('php://output', 'w');
        fputcsv($output, ['id', 'source_table', 'source_id', 'deleted_by', 'deleted_at', 'metadata', 'row_data']);
        foreach ($export_rows as $row) {
            fputcsv($output, [
                $row['id'] ?? '',
                $row['source_table'] ?? '',
                $row['source_id'] ?? '',
                $row['deleted_by'] ?? '',
                $row['deleted_at'] ?? '',
                $row['metadata'] ?? '',
                $row['row_data'] ?? ''
            ]);
        }
        fclose($output);
        exit;
    }

    $count_sql = 'SELECT COUNT(*) FROM deleted_records_backup' . $where_sql;
    $count_stmt = $pdo->prepare($count_sql);
    $count_stmt->execute($params);
    $total_records = (int)$count_stmt->fetchColumn();
    $total_pages = max(1, (int)ceil($total_records / $per_page));
    if ($page > $total_pages) {
        $page = $total_pages;
    }
    $offset = ($page - 1) * $per_page;

    $sql = 'SELECT * FROM deleted_records_backup' . $where_sql . ' ORDER BY deleted_at DESC LIMIT ' . (int)$per_page . ' OFFSET ' . (int)$offset;

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $backups = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $admin_user_ids = [];
    foreach ($backups as $backup_row) {
        if (!empty($backup_row['deleted_by'])) {
            $admin_user_ids[] = (int)$backup_row['deleted_by'];
        }

        $meta_row = json_decode($backup_row['metadata'] ?? '', true);
        if (is_array($meta_row)) {
            foreach (['restored_by', 'redeleted_by'] as $actor_key) {
                if (!empty($meta_row[$actor_key])) {
                    $admin_user_ids[] = (int)$meta_row[$actor_key];
                }
            }
        }
    }

    $admin_users_map = loadAdminUsersMap($admin_user_ids);
} catch (Throwable $e) {
    $backups = [];
    $admin_users_map = [];
    $error = 'Error loading backups: ' . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Deleted Records Backup - Admin Panel</title>
    <link rel="icon" type="image/png" href="../images/logo/favicon.png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../css/style.css">
    <link rel="stylesheet" href="../css/theme-dynamic.php">
    <link rel="stylesheet" href="css/admin-styles.css">
    <link rel="stylesheet" href="css/admin-components.css">
    <style>
        .actions-row {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            align-items: end;
            margin-bottom: 16px;
        }

        .actions-row .field {
            min-width: 220px;
            flex: 1;
        }

        .actions-row label {
            display: block;
            margin-bottom: 6px;
            font-weight: 600;
            color: var(--navy);
            font-size: 13px;
        }

        .actions-row input,
        .actions-row select {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid #dce1ea;
            border-radius: 8px;
            font-size: 14px;
        }

        .table-container {
            overflow-x: auto;
        }

        .table td {
            vertical-align: top;
            font-size: 13px;
        }

        .meta-preview,
        .row-preview {
            max-width: 420px;
            max-height: 140px;
            overflow: auto;
            background: #f7f8fa;
            border: 1px solid #e6e9ef;
            border-radius: 8px;
            padding: 8px;
            font-size: 12px;
            line-height: 1.4;
            white-space: pre-wrap;
            word-break: break-word;
        }

        .badge-pill {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 999px;
            font-size: 11px;
            background: #eef2ff;
            color: #243b6b;
            font-weight: 600;
        }

        .warning-note {
            border-left: 4px solid #d4a017;
            background: #fff9e9;
            color: #5a4611;
            padding: 12px 14px;
            border-radius: 8px;
            margin-bottom: 14px;
            font-size: 13px;
        }

        .toolbar-actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 12px;
        }

        .pagination {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            align-items: center;
            margin-top: 16px;
        }

        .pagination a,
        .pagination span {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 38px;
            height: 38px;
            padding: 0 12px;
            border-radius: 8px;
            text-decoration: none;
            border: 1px solid #dce1ea;
            background: #fff;
            color: var(--navy);
            font-size: 13px;
        }

        .pagination .active {
            background: var(--gold);
            color: var(--deep-navy);
            border-color: var(--gold);
            font-weight: 700;
        }

        .status-note {
            display: inline-block;
            margin-top: 6px;
            font-size: 11px;
            color: #556070;
        }

        .audit-summary {
            display: grid;
            gap: 6px;
            min-width: 240px;
        }

        .audit-item {
            background: #f7f8fa;
            border: 1px solid #e6e9ef;
            border-radius: 8px;
            padding: 8px 10px;
        }

        .audit-label {
            display: block;
            font-size: 10px;
            font-weight: 700;
            letter-spacing: 0.04em;
            text-transform: uppercase;
            color: #556070;
            margin-bottom: 3px;
        }

        .audit-value {
            display: block;
            font-size: 12px;
            color: var(--navy);
            word-break: break-word;
            line-height: 1.4;
        }

        .audit-empty {
            color: #7b8794;
            font-style: italic;
            font-size: 12px;
        }
    </style>
</head>
<body>
    <?php require_once 'includes/admin-header.php'; ?>

    <div class="content">
        <?php if ($message): ?>
            <?php showAlert($message, 'success'); ?>
        <?php endif; ?>
        <?php if ($error): ?>
            <?php showAlert($error, 'error'); ?>
        <?php endif; ?>

        <div class="card">
            <h2><i class="fas fa-database"></i> Deleted Records Backup</h2>
            <p class="warning-note">
                Booking-related deletes are archived here before removal. Restoring will insert the original row back into its source table if that ID does not already exist.
            </p>

            <div class="toolbar-actions">
                <div>
                    <strong><?php echo number_format($total_records ?? 0); ?></strong> backup record(s) found
                </div>
                <div>
                    <a href="deleted-records-backup.php?<?php echo htmlspecialchars(http_build_query(array_filter([
                        'search' => $search ?? '',
                        'table' => $table_filter ?? '',
                        'export' => 'csv'
                    ], function ($value) {
                        return $value !== '' && $value !== null;
                    }))); ?>" class="btn btn-secondary">
                        <i class="fas fa-file-csv"></i> Export CSV
                    </a>
                </div>
            </div>

            <form method="GET" class="actions-row">
                <div class="field">
                    <label for="search">Search Backup Records</label>
                    <input id="search" type="text" name="search" value="<?php echo htmlspecialchars($search ?? ''); ?>" placeholder="Reference, id, table, metadata...">
                </div>
                <div class="field" style="max-width:260px;">
                    <label for="table">Table Filter</label>
                    <select id="table" name="table">
                        <option value="">All booking-related tables</option>
                        <?php foreach ($allowed_tables as $tbl): ?>
                            <option value="<?php echo htmlspecialchars($tbl); ?>" <?php echo (($table_filter ?? '') === $tbl) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($tbl); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-search"></i> Filter</button>
                    <a href="deleted-records-backup.php" class="btn btn-secondary"><i class="fas fa-rotate-left"></i> Reset</a>
                </div>
            </form>

            <div class="table-container">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Deleted At</th>
                            <th>Source</th>
                            <th>Deleted By</th>
                            <th>Metadata</th>
                            <th>Row Snapshot</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($backups)): ?>
                            <tr>
                                <td colspan="6" class="empty-state">
                                    <i class="fas fa-inbox"></i>
                                    <p>No backup records found.</p>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($backups as $backup): ?>
                                <?php
                                $source_table = $backup['source_table'] ?? '';
                                $source_id = $backup['source_id'] ?? '';
                                $meta = $backup['metadata'] ?? '';
                                $row_payload = $backup['row_data'] ?? '';
                                $decoded_meta = json_decode($meta, true);
                                $restored_at = is_array($decoded_meta) ? ($decoded_meta['restored_at'] ?? null) : null;
                                $restored_by = is_array($decoded_meta) ? ($decoded_meta['restored_by'] ?? null) : null;
                                $redeleted_at = is_array($decoded_meta) ? ($decoded_meta['redeleted_at'] ?? null) : null;
                                $redeleted_by = is_array($decoded_meta) ? ($decoded_meta['redeleted_by'] ?? null) : null;
                                $live_exists = isAllowedBackupTable($source_table, $allowed_tables)
                                    ? recordExistsInSourceTable($source_table, $source_id)
                                    : false;
                                $deleted_by_label = formatAdminActor($backup['deleted_by'] ?? 0, $admin_users_map ?? []);
                                $restored_by_label = !empty($restored_by) ? formatAdminActor($restored_by, $admin_users_map ?? []) : null;
                                $redeleted_by_label = !empty($redeleted_by) ? formatAdminActor($redeleted_by, $admin_users_map ?? []) : null;
                                $audit_summary = is_array($decoded_meta) ? buildAuditSummary($decoded_meta, $admin_users_map ?? []) : [];
                                ?>
                                <tr>
                                    <td><?php echo htmlspecialchars(date('M d, Y H:i', strtotime($backup['deleted_at']))); ?></td>
                                    <td>
                                        <span class="badge-pill"><?php echo htmlspecialchars($source_table); ?></span><br>
                                        <small>ID: <?php echo htmlspecialchars($source_id); ?></small>
                                        <?php if ($live_exists): ?>
                                            <div class="status-note">Live row exists</div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php echo htmlspecialchars($deleted_by_label); ?>
                                    </td>
                                    <td>
                                        <?php if (!empty($audit_summary)): ?>
                                            <div class="audit-summary">
                                                <?php foreach ($audit_summary as $audit_label => $audit_value): ?>
                                                    <div class="audit-item">
                                                        <span class="audit-label"><?php echo htmlspecialchars($audit_label); ?></span>
                                                        <span class="audit-value"><?php echo htmlspecialchars((string)$audit_value); ?></span>
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php else: ?>
                                            <div class="audit-empty">No audit metadata recorded.</div>
                                        <?php endif; ?>
                                    </td>
                                    <td><div class="row-preview"><?php echo htmlspecialchars($row_payload); ?></div></td>
                                    <td>
                                        <?php if (in_array($source_table, $allowed_tables, true)): ?>
                                            <form method="POST" onsubmit="return confirm('Restore this record back into ' + <?php echo json_encode($source_table); ?> + '?');">
                                                <input type="hidden" name="action" value="restore_backup">
                                                <input type="hidden" name="backup_id" value="<?php echo (int)$backup['id']; ?>">
                                                <button type="submit" class="btn btn-success btn-sm">
                                                    <i class="fas fa-undo"></i> Restore
                                                </button>
                                            </form>
                                            <?php if ($live_exists): ?>
                                                <form method="POST" style="margin-top:8px;" onsubmit="return confirm('Delete the current live record again from ' + <?php echo json_encode($source_table); ?> + '? A fresh backup snapshot will be created first.');">
                                                    <input type="hidden" name="action" value="redelete_live">
                                                    <input type="hidden" name="backup_id" value="<?php echo (int)$backup['id']; ?>">
                                                    <button type="submit" class="btn btn-danger btn-sm">
                                                        <i class="fas fa-trash"></i> Re-delete Live
                                                    </button>
                                                </form>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <em>Not restorable</em>
                                        <?php endif; ?>

                                        <?php if (!empty($restored_at)): ?>
                                            <div style="margin-top:6px;"><small>Restored: <?php echo htmlspecialchars($restored_at); ?></small></div>
                                            <?php if ($restored_by_label): ?>
                                                <div style="margin-top:4px;"><small>By: <?php echo htmlspecialchars($restored_by_label); ?></small></div>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                        <?php if (!empty($redeleted_at)): ?>
                                            <div style="margin-top:4px;"><small>Re-deleted: <?php echo htmlspecialchars($redeleted_at); ?></small></div>
                                            <?php if ($redeleted_by_label): ?>
                                                <div style="margin-top:4px;"><small>By: <?php echo htmlspecialchars($redeleted_by_label); ?></small></div>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <?php if (($total_pages ?? 1) > 1): ?>
                <?php
                $base_query = [
                    'search' => $search ?? '',
                    'table' => $table_filter ?? ''
                ];
                ?>
                <div class="pagination">
                    <?php if (($page ?? 1) > 1): ?>
                        <a href="deleted-records-backup.php?<?php echo htmlspecialchars(http_build_query($base_query + ['page' => $page - 1])); ?>">&laquo; Prev</a>
                    <?php endif; ?>

                    <?php for ($page_number = max(1, $page - 2); $page_number <= min($total_pages, $page + 2); $page_number++): ?>
                        <?php if ($page_number === (int)$page): ?>
                            <span class="active"><?php echo $page_number; ?></span>
                        <?php else: ?>
                            <a href="deleted-records-backup.php?<?php echo htmlspecialchars(http_build_query($base_query + ['page' => $page_number])); ?>"><?php echo $page_number; ?></a>
                        <?php endif; ?>
                    <?php endfor; ?>

                    <?php if (($page ?? 1) < $total_pages): ?>
                        <a href="deleted-records-backup.php?<?php echo htmlspecialchars(http_build_query($base_query + ['page' => $page + 1])); ?>">Next &raquo;</a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <?php require_once 'includes/admin-footer.php'; ?>
</body>
</html>
