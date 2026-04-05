<?php
/**
 * Activity Log Viewer
 * Unified timeline for admin and employee activity.
 */

require_once __DIR__ . '/admin-init.php';

$log_type = $_GET['log_type'] ?? 'all';
$action_query = trim($_GET['action'] ?? '');
$actor_query = trim($_GET['actor'] ?? '');
$employee_id = isset($_GET['employee_id']) ? (int)$_GET['employee_id'] : 0;
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
$rows = [];
$summary = [
    'admin_events' => 0,
    'employee_events' => 0,
    'failed_logins' => 0,
    'cancellations' => 0,
];
$error = null;

try {
    $employee_options_stmt = $pdo->query("SELECT id, full_name FROM employees ORDER BY full_name ASC");
    $employee_options = $employee_options_stmt->fetchAll(PDO::FETCH_ASSOC);

    $date_from_ts = $has_date_from ? ($date_from . ' 00:00:00') : null;
    $date_to_ts = $has_date_to ? ($date_to . ' 23:59:59') : null;

    $summary_where_admin = [];
    $summary_where_employee = [];
    $summary_params = [];

    if ($has_date_from) {
        $summary_where_admin[] = 'created_at >= ?';
        $summary_where_employee[] = 'created_at >= ?';
        $summary_params[] = $date_from_ts;
    }
    if ($has_date_to) {
        $summary_where_admin[] = 'created_at <= ?';
        $summary_where_employee[] = 'created_at <= ?';
        $summary_params[] = $date_to_ts;
    }

    $admin_where_sql = empty($summary_where_admin) ? '1=1' : implode(' AND ', $summary_where_admin);
    $employee_where_sql = empty($summary_where_employee) ? '1=1' : implode(' AND ', $summary_where_employee);

    $summary_sql = "SELECT
        (SELECT COUNT(*) FROM admin_activity_log WHERE {$admin_where_sql}) AS admin_events,
        (SELECT COUNT(*) FROM employee_activity_log WHERE {$employee_where_sql}) AS employee_events,
        (SELECT COUNT(*) FROM admin_activity_log WHERE {$admin_where_sql} AND action IN ('login_failed', 'login_blocked')) AS failed_logins,
        (SELECT COUNT(*) FROM employee_activity_log WHERE {$employee_where_sql} AND action LIKE 'booking_cancel%') AS cancellations
    ";
    $summary_stmt = $pdo->prepare($summary_sql);
    // Reuse params for each subquery occurrence order.
    $summary_exec_params = [];
    if (!empty($summary_params)) {
        $summary_exec_params = array_merge($summary_exec_params, $summary_params, $summary_params, $summary_params, $summary_params);
    }
    $summary_stmt->execute($summary_exec_params);
    $summary_data = $summary_stmt->fetch(PDO::FETCH_ASSOC);
    if ($summary_data) {
        $summary = array_merge($summary, array_map('intval', $summary_data));
    }

    $sql_parts = [];
    $params = [];

    if ($log_type === 'all' || $log_type === 'admin') {
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
            $admin_where[] = "a.action LIKE ?";
            $admin_params[] = '%' . $action_query . '%';
        }

        if ($actor_query !== '') {
            $admin_where[] = "(a.username LIKE ? OR au.full_name LIKE ?)";
            $admin_params[] = '%' . $actor_query . '%';
            $admin_params[] = '%' . $actor_query . '%';
        }

        $sql_parts[] = "
            SELECT
                'admin' AS log_type,
                a.id,
                a.created_at,
                a.action,
                a.details,
                a.ip_address,
                COALESCE(au.full_name, a.username, CONCAT('User #', a.user_id)) AS actor_name,
                COALESCE(au.role, 'unknown') AS actor_role,
                NULL AS employee_name,
                NULL AS source
            FROM admin_activity_log a
            LEFT JOIN admin_users au ON au.id = a.user_id
            WHERE " . (empty($admin_where) ? '1=1' : implode(' AND ', $admin_where));
        $params = array_merge($params, $admin_params);
    }

    if ($log_type === 'all' || $log_type === 'employee') {
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
            $emp_where[] = "e.action LIKE ?";
            $emp_params[] = '%' . $action_query . '%';
        }

        if ($actor_query !== '') {
            $emp_where[] = "(au.full_name LIKE ? OR au.username LIKE ? OR emp.full_name LIKE ?)";
            $emp_params[] = '%' . $actor_query . '%';
            $emp_params[] = '%' . $actor_query . '%';
            $emp_params[] = '%' . $actor_query . '%';
        }

        if ($employee_id > 0) {
            $emp_where[] = "e.employee_id = ?";
            $emp_params[] = $employee_id;
        }

        $sql_parts[] = "
            SELECT
                'employee' AS log_type,
                e.id,
                e.created_at,
                e.action,
                e.details,
                e.ip_address,
                COALESCE(au.full_name, au.username, CONCAT('User #', e.actor_user_id)) AS actor_name,
                COALESCE(au.role, 'unknown') AS actor_role,
                COALESCE(emp.full_name, CONCAT('Employee #', e.employee_id)) AS employee_name,
                e.source
            FROM employee_activity_log e
            LEFT JOIN admin_users au ON au.id = e.actor_user_id
            LEFT JOIN employees emp ON emp.id = e.employee_id
            WHERE " . (empty($emp_where) ? '1=1' : implode(' AND ', $emp_where));
        $params = array_merge($params, $emp_params);
    }

    if (!empty($sql_parts)) {
        $sql = implode("\nUNION ALL\n", $sql_parts) . "\nORDER BY created_at DESC, id DESC\nLIMIT " . (int)$limit;
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
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
                <label for="action">Action Contains</label>
                <input type="text" id="action" name="action" value="<?php echo htmlspecialchars($action_query); ?>" placeholder="login, employee_, booking_cancel">
            </div>
            <div class="form-group">
                <label for="actor">Actor Name</label>
                <input type="text" id="actor" name="actor" value="<?php echo htmlspecialchars($actor_query); ?>" placeholder="Admin name or username">
            </div>
            <div class="form-group">
                <label for="employee_id">Employee</label>
                <select id="employee_id" name="employee_id">
                    <option value="0">All employees</option>
                    <?php foreach ($employee_options as $emp): ?>
                        <option value="<?php echo (int)$emp['id']; ?>" <?php echo $employee_id === (int)$emp['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($emp['full_name']); ?>
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
