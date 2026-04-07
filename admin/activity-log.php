<?php
/**
 * Activity Log Viewer
 * Unified timeline for admin and employee activity.
 */

require_once __DIR__ . '/admin-init.php';

$log_type = $_GET['log_type'] ?? 'all';
$action_query = trim($_GET['action'] ?? '');
$actor_query = '';
$actor_user_id = 0;
$employee_id = 0;
$date_from = trim($_GET['date_from'] ?? '');
$date_to = trim($_GET['date_to'] ?? '');
$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 150;

$valid_types = ['all', 'admin', 'employee'];
if (!in_array($log_type, $valid_types, true)) {
    $log_type = 'all';
}
$has_date_from = $date_from !== '';
$has_date_to = $date_to !== '';

if ($has_date_from && !strtotime($date_from)) {
    $date_from = '';
    $has_date_from = false;
}
if ($has_date_to && !strtotime($date_to)) {
    $date_to = '';
    $has_date_to = false;
}
if ($limit < 25 || $limit > 500) {
    $limit = 150;
}

$employee_options = [];
$action_options = [];
$actor_options = [];
$rows = [];
$summary = [
    'admin_events' => 0,
    'employee_events' => 0,
    'failed_logins' => 0,
    'cancellations' => 0,
];
$has_admin_log_table = false;
$has_employee_log_table = false;
$employee_has_actor_user_id = false;
$employee_has_admin_user_id = false;
$employee_has_employee_id = false;
$employee_has_source = false;
$employee_actor_expr = 'NULL';
$employee_actor_expr_unaliased = 'NULL';
$employee_id_expr = 'NULL';
$employee_join_clause = 'LEFT JOIN employees emp ON 1=0';
$employee_source_select = 'NULL';
$error = null;

try {
    $has_admin_log_table = (bool)$pdo->query("SHOW TABLES LIKE 'admin_activity_log'")->fetchColumn();
    $has_employee_log_table = (bool)$pdo->query("SHOW TABLES LIKE 'employee_activity_log'")->fetchColumn();

    if ($has_employee_log_table) {
        $employee_columns_stmt = $pdo->query('SHOW COLUMNS FROM employee_activity_log');
        foreach ($employee_columns_stmt->fetchAll(PDO::FETCH_ASSOC) as $col) {
            $field = strtolower((string)($col['Field'] ?? ''));
            if ($field === 'actor_user_id') {
                $employee_has_actor_user_id = true;
            }
            if ($field === 'admin_user_id') {
                $employee_has_admin_user_id = true;
            }
            if ($field === 'employee_id') {
                $employee_has_employee_id = true;
            }
            if ($field === 'source') {
                $employee_has_source = true;
            }
        }

        if ($employee_has_actor_user_id && $employee_has_admin_user_id) {
            $employee_actor_expr = 'COALESCE(e.actor_user_id, e.admin_user_id)';
            $employee_actor_expr_unaliased = 'COALESCE(actor_user_id, admin_user_id)';
        } elseif ($employee_has_actor_user_id) {
            $employee_actor_expr = 'e.actor_user_id';
            $employee_actor_expr_unaliased = 'actor_user_id';
        } elseif ($employee_has_admin_user_id) {
            $employee_actor_expr = 'e.admin_user_id';
            $employee_actor_expr_unaliased = 'admin_user_id';
        }

        if ($employee_has_employee_id) {
            $employee_id_expr = 'e.employee_id';
            $employee_join_clause = 'LEFT JOIN employees emp ON emp.id = e.employee_id';
        }

        $employee_source_select = $employee_has_source ? 'e.source' : 'NULL';
    }

    $employee_options_stmt = $pdo->query("SELECT id, full_name FROM employees ORDER BY full_name ASC");
    $employee_options = $employee_options_stmt->fetchAll(PDO::FETCH_ASSOC);

    $date_from_ts = $has_date_from ? ($date_from . ' 00:00:00') : null;
    $date_to_ts = $has_date_to ? ($date_to . ' 23:59:59') : null;

    $admin_base_where = [];
    $admin_base_params = [];
    $employee_base_where = [];
    $employee_base_params = [];

    if ($has_date_from) {
        $admin_base_where[] = 'created_at >= ?';
        $admin_base_params[] = $date_from_ts;
        $employee_base_where[] = 'created_at >= ?';
        $employee_base_params[] = $date_from_ts;
    }
    if ($has_date_to) {
        $admin_base_where[] = 'created_at <= ?';
        $admin_base_params[] = $date_to_ts;
        $employee_base_where[] = 'created_at <= ?';
        $employee_base_params[] = $date_to_ts;
    }
    if ($employee_id > 0) {
        $employee_base_where[] = $employee_has_employee_id ? 'employee_id = ?' : '0=1';
        $employee_base_params[] = $employee_id;
    }

    // Build dropdown options from current time/type/employee scope.
    if (($log_type === 'all' || $log_type === 'admin') && $has_admin_log_table) {
        $sql = "SELECT DISTINCT action FROM admin_activity_log";
        $sql .= empty($admin_base_where) ? '' : ' WHERE ' . implode(' AND ', $admin_base_where);
        $sql .= ' ORDER BY action ASC LIMIT 300';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($admin_base_params);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $action) {
            $action = trim((string)$action);
            if ($action !== '') {
                $action_options[$action] = true;
            }
        }

        $sql = "SELECT DISTINCT COALESCE(au.full_name, a.username, CONCAT('User #', a.user_id)) AS actor_name
                FROM admin_activity_log a
                LEFT JOIN admin_users au ON au.id = a.user_id";
        $sql .= empty($admin_base_where) ? '' : ' WHERE ' . implode(' AND ', array_map(function ($cond) {
            return 'a.' . $cond;
        }, $admin_base_where));
        $sql .= ' ORDER BY actor_name ASC LIMIT 300';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($admin_base_params);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $actorName) {
            $actorName = trim((string)$actorName);
            if ($actorName !== '') {
                $actor_options[$actorName] = true;
            }
        }
    }

    if (($log_type === 'all' || $log_type === 'employee') && $has_employee_log_table) {
        $sql = "SELECT DISTINCT action FROM employee_activity_log";
        $sql .= empty($employee_base_where) ? '' : ' WHERE ' . implode(' AND ', $employee_base_where);
        $sql .= ' ORDER BY action ASC LIMIT 300';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($employee_base_params);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $action) {
            $action = trim((string)$action);
            if ($action !== '') {
                $action_options[$action] = true;
            }
        }

        $sql = "SELECT DISTINCT COALESCE(au.full_name, au.username, CONCAT('User #', {$employee_actor_expr})) AS actor_name
                FROM employee_activity_log e
            LEFT JOIN admin_users au ON au.id = {$employee_actor_expr}";
        $sql .= empty($employee_base_where) ? '' : ' WHERE ' . implode(' AND ', array_map(function ($cond) {
            return 'e.' . $cond;
        }, $employee_base_where));
        $sql .= ' ORDER BY actor_name ASC LIMIT 300';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($employee_base_params);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $actorName) {
            $actorName = trim((string)$actorName);
            if ($actorName !== '') {
                $actor_options[$actorName] = true;
            }
        }
    }

    // Keep selected values visible even when no matches exist in scope.
    if ($action_query !== '') {
        $action_options[$action_query] = true;
    }
    if ($actor_query !== '') {
        $actor_options[$actor_query] = true;
    }

    $summary_where_admin = [];
    $summary_admin_params = [];
    $summary_where_employee = [];
    $summary_employee_params = [];

    if ($has_date_from) {
        $summary_where_admin[] = 'created_at >= ?';
        $summary_admin_params[] = $date_from_ts;
        $summary_where_employee[] = 'created_at >= ?';
        $summary_employee_params[] = $date_from_ts;
    }
    if ($has_date_to) {
        $summary_where_admin[] = 'created_at <= ?';
        $summary_admin_params[] = $date_to_ts;
        $summary_where_employee[] = 'created_at <= ?';
        $summary_employee_params[] = $date_to_ts;
    }
    if ($action_query !== '') {
        $summary_where_admin[] = 'action = ?';
        $summary_admin_params[] = $action_query;
        $summary_where_employee[] = 'action = ?';
        $summary_employee_params[] = $action_query;
    }
    if ($actor_query !== '') {
        if ($actor_user_id > 0) {
            $summary_where_admin[] = 'user_id = ?';
            $summary_admin_params[] = $actor_user_id;
            $summary_where_employee[] = $employee_actor_expr_unaliased !== 'NULL'
                ? ($employee_actor_expr_unaliased . ' = ?')
                : '0=1';
            $summary_employee_params[] = $actor_user_id;
        } else {
            $summary_where_admin[] = '(username = ? OR user_id IN (SELECT id FROM admin_users WHERE full_name = ? OR username = ?))';
            $summary_admin_params[] = $actor_query;
            $summary_admin_params[] = $actor_query;
            $summary_admin_params[] = $actor_query;
            $summary_where_employee[] = $employee_actor_expr_unaliased !== 'NULL'
                ? ('(' . $employee_actor_expr_unaliased . ' IN (SELECT id FROM admin_users WHERE full_name = ? OR username = ?))')
                : '0=1';
            $summary_employee_params[] = $actor_query;
            $summary_employee_params[] = $actor_query;
        }
    }
    if ($employee_id > 0) {
        $summary_where_employee[] = $employee_has_employee_id ? 'employee_id = ?' : '0=1';
        $summary_employee_params[] = $employee_id;
    }

    if (($log_type === 'all' || $log_type === 'admin') && $has_admin_log_table) {
        $sql = 'SELECT COUNT(*) FROM admin_activity_log WHERE ' . (empty($summary_where_admin) ? '1=1' : implode(' AND ', $summary_where_admin));
        $stmt = $pdo->prepare($sql);
        $stmt->execute($summary_admin_params);
        $summary['admin_events'] = (int)$stmt->fetchColumn();

        $sql = 'SELECT COUNT(*) FROM admin_activity_log WHERE ' .
            (empty($summary_where_admin) ? '1=1' : implode(' AND ', $summary_where_admin)) .
            " AND action IN ('login_failed', 'login_blocked')";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($summary_admin_params);
        $summary['failed_logins'] = (int)$stmt->fetchColumn();
    }

    if (($log_type === 'all' || $log_type === 'employee') && $has_employee_log_table) {
        $sql = 'SELECT COUNT(*) FROM employee_activity_log WHERE ' . (empty($summary_where_employee) ? '1=1' : implode(' AND ', $summary_where_employee));
        $stmt = $pdo->prepare($sql);
        $stmt->execute($summary_employee_params);
        $summary['employee_events'] = (int)$stmt->fetchColumn();

        $sql = 'SELECT COUNT(*) FROM employee_activity_log WHERE ' .
            (empty($summary_where_employee) ? '1=1' : implode(' AND ', $summary_where_employee)) .
            " AND action LIKE 'booking_cancel%'";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($summary_employee_params);
        $summary['cancellations'] = (int)$stmt->fetchColumn();
    }

    if ($log_type === 'admin') {
        $summary['employee_events'] = 0;
        $summary['cancellations'] = 0;
    } elseif ($log_type === 'employee') {
        $summary['admin_events'] = 0;
        $summary['failed_logins'] = 0;
    }

    ksort($action_options, SORT_NATURAL | SORT_FLAG_CASE);
    ksort($actor_options, SORT_NATURAL | SORT_FLAG_CASE);

    $sql_parts = [];
    $params = [];

    if (($log_type === 'all' || $log_type === 'admin') && $has_admin_log_table) {
        $admin_where = [];
        $admin_params = [];

        if ($has_date_from) {
            $admin_where[] = "a.created_at >= ?";
            $admin_params[] = $date_from_ts;
        }
        if ($has_date_to) {
            $admin_where[] = "a.created_at <= ?";
            $admin_params[] = $date_to_ts;
        }

        if ($action_query !== '') {
            $admin_where[] = "a.action = ?";
            $admin_params[] = $action_query;
        }

        if ($actor_query !== '') {
            if ($actor_user_id > 0) {
                $admin_where[] = 'a.user_id = ?';
                $admin_params[] = $actor_user_id;
            } else {
                $admin_where[] = '(a.username = ? OR au.full_name = ? OR au.username = ?)';
                $admin_params[] = $actor_query;
                $admin_params[] = $actor_query;
                $admin_params[] = $actor_query;
            }
        }

        $sql_parts[] = "
            SELECT
                CAST('admin' AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci AS log_type,
                a.id,
                a.created_at,
                CONVERT(a.action USING utf8mb4) COLLATE utf8mb4_unicode_ci AS action,
                CONVERT(a.details USING utf8mb4) COLLATE utf8mb4_unicode_ci AS details,
                CONVERT(a.ip_address USING utf8mb4) COLLATE utf8mb4_unicode_ci AS ip_address,
                COALESCE(
                    CONVERT(au.full_name USING utf8mb4) COLLATE utf8mb4_unicode_ci,
                    CONVERT(a.username USING utf8mb4) COLLATE utf8mb4_unicode_ci,
                    CONVERT(CONCAT('User #', a.user_id) USING utf8mb4) COLLATE utf8mb4_unicode_ci
                ) AS actor_name,
                COALESCE(
                    CONVERT(au.role USING utf8mb4) COLLATE utf8mb4_unicode_ci,
                    CAST('unknown' AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci
                ) AS actor_role,
                CAST(NULL AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci AS employee_name,
                CAST(NULL AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci AS source
            FROM admin_activity_log a
            LEFT JOIN admin_users au ON au.id = a.user_id
            WHERE " . (empty($admin_where) ? '1=1' : implode(' AND ', $admin_where));
        $params = array_merge($params, $admin_params);
    }

    if (($log_type === 'all' || $log_type === 'employee') && $has_employee_log_table) {
        $emp_where = [];
        $emp_params = [];

        if ($has_date_from) {
            $emp_where[] = "e.created_at >= ?";
            $emp_params[] = $date_from_ts;
        }
        if ($has_date_to) {
            $emp_where[] = "e.created_at <= ?";
            $emp_params[] = $date_to_ts;
        }

        if ($action_query !== '') {
            $emp_where[] = "e.action = ?";
            $emp_params[] = $action_query;
        }

        if ($actor_query !== '') {
            if ($actor_user_id > 0) {
                $emp_where[] = $employee_actor_expr !== 'NULL'
                    ? ($employee_actor_expr . ' = ?')
                    : '0=1';
                $emp_params[] = $actor_user_id;
            } else {
                $emp_where[] = '(au.full_name = ? OR au.username = ?)';
                $emp_params[] = $actor_query;
                $emp_params[] = $actor_query;
            }
        }

        if ($employee_id > 0) {
            $emp_where[] = $employee_has_employee_id ? "e.employee_id = ?" : '0=1';
            $emp_params[] = $employee_id;
        }

        $sql_parts[] = "
            SELECT
                CAST('employee' AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci AS log_type,
                e.id,
                e.created_at,
                CONVERT(e.action USING utf8mb4) COLLATE utf8mb4_unicode_ci AS action,
                CONVERT(e.details USING utf8mb4) COLLATE utf8mb4_unicode_ci AS details,
                CONVERT(e.ip_address USING utf8mb4) COLLATE utf8mb4_unicode_ci AS ip_address,
                COALESCE(
                    CONVERT(au.full_name USING utf8mb4) COLLATE utf8mb4_unicode_ci,
                    CONVERT(au.username USING utf8mb4) COLLATE utf8mb4_unicode_ci,
                    CONVERT(CONCAT('User #', {$employee_actor_expr}) USING utf8mb4) COLLATE utf8mb4_unicode_ci
                ) AS actor_name,
                COALESCE(
                    CONVERT(au.role USING utf8mb4) COLLATE utf8mb4_unicode_ci,
                    CAST('unknown' AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci
                ) AS actor_role,
                COALESCE(
                    CONVERT(emp.full_name USING utf8mb4) COLLATE utf8mb4_unicode_ci,
                    CONVERT(CONCAT('Employee #', {$employee_id_expr}) USING utf8mb4) COLLATE utf8mb4_unicode_ci
                ) AS employee_name,
                CONVERT({$employee_source_select} USING utf8mb4) COLLATE utf8mb4_unicode_ci AS source
            FROM employee_activity_log e
            LEFT JOIN admin_users au ON au.id = {$employee_actor_expr}
            {$employee_join_clause}
            WHERE " . (empty($emp_where) ? '1=1' : implode(' AND ', $emp_where));
        $params = array_merge($params, $emp_params);
    }

    if (!empty($sql_parts)) {
        $sql = implode("\nUNION ALL\n", $sql_parts) . "\nORDER BY created_at DESC, id DESC\nLIMIT " . (int)$limit;
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // If default view is empty unexpectedly, retry with a strict unfiltered fallback.
    $is_default_view = (
        $log_type === 'all' &&
        $action_query === '' &&
        $actor_query === '' &&
        $employee_id === 0 &&
        !$has_date_from &&
        !$has_date_to
    );

    if ($is_default_view && empty($rows)) {
        $fallback_parts = [];

        if ($has_admin_log_table) {
            $fallback_parts[] = "
                SELECT
                    CAST('admin' AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci AS log_type,
                    a.id,
                    a.created_at,
                    CONVERT(a.action USING utf8mb4) COLLATE utf8mb4_unicode_ci AS action,
                    CONVERT(a.details USING utf8mb4) COLLATE utf8mb4_unicode_ci AS details,
                    CONVERT(a.ip_address USING utf8mb4) COLLATE utf8mb4_unicode_ci AS ip_address,
                    COALESCE(
                        CONVERT(au.full_name USING utf8mb4) COLLATE utf8mb4_unicode_ci,
                        CONVERT(a.username USING utf8mb4) COLLATE utf8mb4_unicode_ci,
                        CONVERT(CONCAT('User #', a.user_id) USING utf8mb4) COLLATE utf8mb4_unicode_ci
                    ) AS actor_name,
                    COALESCE(
                        CONVERT(au.role USING utf8mb4) COLLATE utf8mb4_unicode_ci,
                        CAST('unknown' AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci
                    ) AS actor_role,
                    CAST(NULL AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci AS employee_name,
                    CAST(NULL AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci AS source
                FROM admin_activity_log a
                LEFT JOIN admin_users au ON au.id = a.user_id
            ";
        }

        if ($has_employee_log_table) {
            $fallback_parts[] = "
                SELECT
                    CAST('employee' AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci AS log_type,
                    e.id,
                    e.created_at,
                    CONVERT(e.action USING utf8mb4) COLLATE utf8mb4_unicode_ci AS action,
                    CONVERT(e.details USING utf8mb4) COLLATE utf8mb4_unicode_ci AS details,
                    CONVERT(e.ip_address USING utf8mb4) COLLATE utf8mb4_unicode_ci AS ip_address,
                    COALESCE(
                        CONVERT(au.full_name USING utf8mb4) COLLATE utf8mb4_unicode_ci,
                        CONVERT(au.username USING utf8mb4) COLLATE utf8mb4_unicode_ci,
                        CONVERT(CONCAT('User #', {$employee_actor_expr}) USING utf8mb4) COLLATE utf8mb4_unicode_ci
                    ) AS actor_name,
                    COALESCE(
                        CONVERT(au.role USING utf8mb4) COLLATE utf8mb4_unicode_ci,
                        CAST('unknown' AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci
                    ) AS actor_role,
                    COALESCE(
                        CONVERT(emp.full_name USING utf8mb4) COLLATE utf8mb4_unicode_ci,
                        CONVERT(CONCAT('Employee #', {$employee_id_expr}) USING utf8mb4) COLLATE utf8mb4_unicode_ci
                    ) AS employee_name,
                    CONVERT({$employee_source_select} USING utf8mb4) COLLATE utf8mb4_unicode_ci AS source
                FROM employee_activity_log e
                LEFT JOIN admin_users au ON au.id = {$employee_actor_expr}
                {$employee_join_clause}
            ";
        }

        if (!empty($fallback_parts)) {
            $fallback_sql = implode("\nUNION ALL\n", $fallback_parts) . "\nORDER BY created_at DESC, id DESC\nLIMIT " . (int)$limit;
            $stmt = $pdo->prepare($fallback_sql);
            $stmt->execute();
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    }
} catch (PDOException $e) {
    $error = 'Unable to load activity logs right now. Please try again shortly.';
    error_log('Activity log page error: ' . $e->getMessage());
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Activity Logs - Admin</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;500;600;700&family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../css/style.css">
    <link rel="stylesheet" href="../css/theme-dynamic.php">
    <link rel="stylesheet" href="css/admin-styles.css">
    <link rel="stylesheet" href="css/admin-components.css">
</head>
<body>
<div class="admin-layout">
    <?php require_once __DIR__ . '/includes/admin-header.php'; ?>

    <main class="admin-main">
        <div class="admin-content">
            <div class="admin-page-header">
                <h2><i class="fas fa-history"></i> Activity Logs</h2>
                <p>Unified timeline for admin and employee activity events.</p>
            </div>

    <?php if ($error): ?>
        <div class="alert alert-error">
            <?php echo htmlspecialchars($error); ?>
        </div>
    <?php endif; ?>

    <div class="stats-grid">
        <div class="stat-card compact">
            <div class="stat-label">Admin Events</div>
            <div class="stat-value"><?php echo number_format((int)$summary['admin_events']); ?></div>
        </div>
        <div class="stat-card compact">
            <div class="stat-label">Employee Events</div>
            <div class="stat-value"><?php echo number_format((int)$summary['employee_events']); ?></div>
        </div>
        <div class="stat-card compact warning">
            <div class="stat-label">Failed/Blocked Logins</div>
            <div class="stat-value"><?php echo number_format((int)$summary['failed_logins']); ?></div>
        </div>
        <div class="stat-card compact accent">
            <div class="stat-label">Cancellation Events</div>
            <div class="stat-value"><?php echo number_format((int)$summary['cancellations']); ?></div>
        </div>
    </div>

    <div class="card">
        <form method="GET" class="filter-grid">
            <div class="form-group">
                <label for="log_type">Log Type</label>
                <select id="log_type" name="log_type">
                    <option value="all" <?php echo $log_type === 'all' ? 'selected' : ''; ?>>All</option>
                    <option value="admin" <?php echo $log_type === 'admin' ? 'selected' : ''; ?>>Admin Only</option>
                    <option value="employee" <?php echo $log_type === 'employee' ? 'selected' : ''; ?>>Employee Only</option>
                </select>
            </div>
            <div class="form-group">
                <label for="action">Action</label>
                <select id="action" name="action">
                    <option value="">All actions</option>
                    <?php foreach (array_keys($action_options) as $actionOpt): ?>
                        <option value="<?php echo htmlspecialchars($actionOpt); ?>" <?php echo $action_query === $actionOpt ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($actionOpt); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label for="date_from">From</label>
                <input type="date" id="date_from" name="date_from" value="<?php echo htmlspecialchars($date_from); ?>">
            </div>
            <div class="form-group">
                <label for="date_to">To</label>
                <input type="date" id="date_to" name="date_to" value="<?php echo htmlspecialchars($date_to); ?>">
            </div>
            <div class="form-group">
                <label for="limit">Rows</label>
                <select id="limit" name="limit">
                    <option value="100" <?php echo $limit === 100 ? 'selected' : ''; ?>>100</option>
                    <option value="150" <?php echo $limit === 150 ? 'selected' : ''; ?>>150</option>
                    <option value="250" <?php echo $limit === 250 ? 'selected' : ''; ?>>250</option>
                    <option value="500" <?php echo $limit === 500 ? 'selected' : ''; ?>>500</option>
                </select>
            </div>
            <div class="form-group actions">
                <button type="submit" class="btn btn-primary"><i class="fas fa-filter"></i> Apply</button>
                <a href="activity-log.php" class="btn btn-secondary">Reset</a>
            </div>
        </form>
    </div>

    <div class="card">
        <div class="table-responsive">
            <table class="table log-table">
                <thead>
                    <tr>
                        <th>Time</th>
                        <th>Type</th>
                        <th>Action</th>
                        <th>Actor</th>
                        <th>Employee</th>
                        <th>Details</th>
                        <th>Source/IP</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($rows)): ?>
                        <tr>
                            <td colspan="7" class="empty-state">No activity found for the selected filters.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($rows as $row): ?>
                            <tr>
                                <td><?php echo htmlspecialchars(date('Y-m-d H:i:s', strtotime($row['created_at']))); ?></td>
                                <td>
                                    <span class="pill <?php echo $row['log_type'] === 'employee' ? 'employee' : 'admin'; ?>">
                                        <?php echo htmlspecialchars(ucfirst($row['log_type'])); ?>
                                    </span>
                                </td>
                                <td><code><?php echo htmlspecialchars($row['action']); ?></code></td>
                                <td>
                                    <?php echo htmlspecialchars($row['actor_name'] ?: 'Unknown'); ?>
                                    <div class="muted"><?php echo htmlspecialchars($row['actor_role'] ?: ''); ?></div>
                                </td>
                                <td><?php echo htmlspecialchars($row['employee_name'] ?: '-'); ?></td>
                                <td><?php echo htmlspecialchars($row['details'] ?: '-'); ?></td>
                                <td>
                                    <?php echo htmlspecialchars($row['source'] ?: '-'); ?>
                                    <div class="muted"><?php echo htmlspecialchars($row['ip_address'] ?: '-'); ?></div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
        </div>
    </main>
</div>

<style>
.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    gap: 14px;
    margin-bottom: 20px;
}

.stat-card.compact {
    border-left-width: 5px;
    margin-bottom: 0;
    padding: 16px;
}

.stat-card.compact.warning {
    border-left-color: #e67e22;
}

.stat-card.compact.accent {
    border-left-color: #9b59b6;
}

.stat-label {
    font-size: 12px;
    font-weight: 600;
    color: #6c757d;
    text-transform: uppercase;
    letter-spacing: 0.04em;
}

.stat-value {
    font-size: 30px;
    font-weight: 700;
    color: #0f172a;
    margin-top: 6px;
}

.filter-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    gap: 12px;
    align-items: end;
}

.form-group {
    display: flex;
    flex-direction: column;
}

.form-group label {
    margin-bottom: 6px;
    font-size: 12px;
    font-weight: 600;
    color: #475569;
}

.form-group input,
.form-group select {
    height: 42px;
    border: 1px solid #d1d5db;
    border-radius: 8px;
    padding: 8px 10px;
    font-size: 14px;
    width: 100%;
}

.form-group.actions {
    flex-direction: row;
    align-items: center;
    gap: 10px;
}

.log-table td {
    vertical-align: top;
    font-size: 13px;
}

.pill {
    display: inline-block;
    padding: 4px 8px;
    border-radius: 999px;
    font-size: 11px;
    font-weight: 700;
    letter-spacing: 0.04em;
    text-transform: uppercase;
}

.pill.admin {
    background: #dbeafe;
    color: #1e40af;
}

.pill.employee {
    background: #dcfce7;
    color: #166534;
}

.muted {
    font-size: 12px;
    color: #64748b;
    margin-top: 2px;
}

.empty-state {
    text-align: center;
    color: #64748b;
    padding: 30px 16px;
}

@media (max-width: 768px) {
    .filter-grid {
        grid-template-columns: 1fr;
    }

    .form-group.actions {
        flex-direction: column;
        align-items: stretch;
    }
}
</style>

<?php require_once __DIR__ . '/includes/admin-footer.php'; ?>
</body>
</html>
