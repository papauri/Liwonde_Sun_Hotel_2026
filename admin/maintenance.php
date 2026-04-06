<?php
require_once 'admin-init.php';
require_once '../includes/maintenance-helper.php';
require_once '../includes/alert.php';

// Ensure table exists (auto-creates on first load)
ensureMaintenanceInfrastructure($pdo);

$message = '';
$error   = '';
$editTask = null;

// ── POST Handler ───────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $csrf_token) {
        $error = 'Invalid security token. Please try again.';
    } else {
        $action = $_POST['action'] ?? '';

        $validTypes    = ['maintenance','deep_cleaning','room_service','inspection','renovation','pest_control','other'];
        $validPriority = ['low','medium','high','urgent'];
        $validStatuses = ['pending','in_progress','completed','cancelled'];

        try {

            // ── Create Task ─────────────────────────────────────────────────
            if ($action === 'create_task') {
                $roomId         = ($_POST['room_id'] ?? '') !== '' ? (int)$_POST['room_id'] : null;
                $taskType       = $_POST['task_type']       ?? 'maintenance';
                $title          = maintenanceSanitize($_POST['title']        ?? '', 200);
                $description    = maintenanceSanitize($_POST['description']  ?? '', 2000);
                $priority       = $_POST['priority']        ?? 'medium';
                $assignedTo     = !empty($_POST['assigned_to']) ? (int)$_POST['assigned_to'] : null;
                $employeeId     = !empty($_POST['employee_id']) ? (int)$_POST['employee_id'] : null;
                $assignedToName = maintenanceSanitize($_POST['assigned_to_name'] ?? '', 150) ?: null;
                $scheduledStart = trim($_POST['scheduled_start'] ?? '');
                $scheduledEnd   = trim($_POST['scheduled_end']   ?? '');
                $blocksAvail    = isset($_POST['blocks_availability']) ? 1 : 0;
                $notes          = maintenanceSanitize($_POST['notes'] ?? '', 2000);
                $repeatType     = $_POST['repeat_type']     ?? 'none';
                $repeatInterval = max(1, (int)($_POST['repeat_interval'] ?? 1));
                $repeatUntil    = trim($_POST['repeat_until'] ?? '');

                if ($title === '')               throw new Exception('Task title is required.');
                if (!in_array($taskType,    $validTypes,    true)) throw new Exception('Invalid task type.');
                if (!in_array($priority,    $validPriority, true)) throw new Exception('Invalid priority.');
                if ($scheduledStart === '' || $scheduledEnd === '') throw new Exception('Scheduled start and end are required.');
                if (strtotime($scheduledEnd) <= strtotime($scheduledStart)) throw new Exception('End date/time must be after start.');

                // Build list of (start, end) pairs — 1 for single, N for recurring
                $occurrences = [['start' => $scheduledStart, 'end' => $scheduledEnd]];
                $recurrenceGroupId = null;

                if ($repeatType !== 'none' && $repeatUntil !== '') {
                    $recurrenceGroupId = generateMaintenanceGroupId();
                    $startDt   = new DateTime($scheduledStart);
                    $endDt     = new DateTime($scheduledEnd);
                    $duration  = $startDt->diff($endDt);
                    $untilDate = new DateTime($repeatUntil . ' 23:59:59');
                    $cap       = 52;
                    $cur       = clone $startDt;

                    while (count($occurrences) < $cap) {
                        switch ($repeatType) {
                            case 'daily':   $cur->modify("+{$repeatInterval} day");   break;
                            case 'weekly':  $cur->modify("+{$repeatInterval} week");  break;
                            case 'monthly': $cur->modify("+{$repeatInterval} month"); break;
                            default: break 2;
                        }
                        if ($cur > $untilDate) break;
                        $curEnd = clone $cur;
                        $curEnd->add($duration);
                        $occurrences[] = [
                            'start' => $cur->format('Y-m-d H:i:s'),
                            'end'   => $curEnd->format('Y-m-d H:i:s'),
                        ];
                    }
                }

                $ins = $pdo->prepare("INSERT INTO room_maintenance_tasks
                    (room_id, task_type, title, description, priority, assigned_to, employee_id, assigned_to_name,
                     scheduled_start, scheduled_end, status, blocks_availability,
                     recurrence_group_id, notes, created_by)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?, ?, ?, ?)");

                $createdPairs = [];
                foreach ($occurrences as $occ) {
                    $ins->execute([
                        $roomId, $taskType, $title, $description ?: null, $priority, $assignedTo, $employeeId, $assignedToName,
                        $occ['start'], $occ['end'], $blocksAvail, $recurrenceGroupId,
                        $notes ?: null, $user['id'],
                    ]);
                    $createdPairs[(int)$pdo->lastInsertId()] = $occ;
                }

                foreach ($createdPairs as $tid => $occ) {
                    syncMaintenanceBlockedDates($pdo, $tid, $roomId, $occ['start'], $occ['end'], (bool)$blocksAvail, 'pending');
                }

                $n = count($createdPairs);
                $message = $n === 1 ? 'Task created successfully.' : "{$n} recurring tasks created.";

            // ── Edit Task ───────────────────────────────────────────────────
            } elseif ($action === 'edit_task') {
                $taskId         = (int)($_POST['task_id'] ?? 0);
                $roomId         = ($_POST['room_id'] ?? '') !== '' ? (int)$_POST['room_id'] : null;
                $taskType       = $_POST['task_type']      ?? 'maintenance';
                $title          = maintenanceSanitize($_POST['title']        ?? '', 200);
                $description    = maintenanceSanitize($_POST['description']  ?? '', 2000);
                $priority       = $_POST['priority']       ?? 'medium';
                $assignedTo     = !empty($_POST['assigned_to']) ? (int)$_POST['assigned_to'] : null;
                $employeeId     = !empty($_POST['employee_id']) ? (int)$_POST['employee_id'] : null;
                $assignedToName = maintenanceSanitize($_POST['assigned_to_name'] ?? '', 150) ?: null;
                $scheduledStart = trim($_POST['scheduled_start'] ?? '');
                $scheduledEnd   = trim($_POST['scheduled_end']   ?? '');
                $blocksAvail    = isset($_POST['blocks_availability']) ? 1 : 0;
                $notes          = maintenanceSanitize($_POST['notes'] ?? '', 2000);

                if ($taskId <= 0)               throw new Exception('Invalid task ID.');
                if ($title === '')               throw new Exception('Task title is required.');
                if (!in_array($taskType,    $validTypes,    true)) throw new Exception('Invalid task type.');
                if (!in_array($priority,    $validPriority, true)) throw new Exception('Invalid priority.');
                if ($scheduledStart === '' || $scheduledEnd === '') throw new Exception('Scheduled start/end required.');
                if (strtotime($scheduledEnd) <= strtotime($scheduledStart)) throw new Exception('End must be after start.');

                $row = $pdo->prepare("SELECT status FROM room_maintenance_tasks WHERE id = ?");
                $row->execute([$taskId]);
                $currentStatus = $row->fetchColumn();
                if ($currentStatus === false) throw new Exception('Task not found.');

                $pdo->prepare("UPDATE room_maintenance_tasks SET
                    room_id=?, task_type=?, title=?, description=?,
                    priority=?, assigned_to=?, employee_id=?, assigned_to_name=?, scheduled_start=?, scheduled_end=?,
                    blocks_availability=?, notes=?
                    WHERE id=?")->execute([
                    $roomId, $taskType, $title, $description ?: null,
                    $priority, $assignedTo, $employeeId, $assignedToName, $scheduledStart, $scheduledEnd,
                    $blocksAvail, $notes ?: null, $taskId,
                ]);

                syncMaintenanceBlockedDates($pdo, $taskId, $roomId, $scheduledStart, $scheduledEnd, (bool)$blocksAvail, $currentStatus);
                $message = 'Task updated successfully.';

            // ── Update Status ───────────────────────────────────────────────
            } elseif ($action === 'update_status') {
                $taskId    = (int)($_POST['task_id'] ?? 0);
                $newStatus = $_POST['status'] ?? '';

                if ($taskId <= 0 || !in_array($newStatus, $validStatuses, true)) {
                    throw new Exception('Invalid status update.');
                }

                $row = $pdo->prepare("SELECT room_id, scheduled_start, scheduled_end, blocks_availability FROM room_maintenance_tasks WHERE id = ?");
                $row->execute([$taskId]);
                $task = $row->fetch(PDO::FETCH_ASSOC);
                if (!$task) throw new Exception('Task not found.');

                if ($newStatus === 'in_progress') {
                    $pdo->prepare("UPDATE room_maintenance_tasks SET status=?, actual_start=NOW() WHERE id=?")
                        ->execute([$newStatus, $taskId]);
                } elseif (in_array($newStatus, ['completed', 'cancelled'], true)) {
                    $pdo->prepare("UPDATE room_maintenance_tasks SET status=?, actual_end=NOW() WHERE id=?")
                        ->execute([$newStatus, $taskId]);
                } else {
                    $pdo->prepare("UPDATE room_maintenance_tasks SET status=? WHERE id=?")
                        ->execute([$newStatus, $taskId]);
                }

                syncMaintenanceBlockedDates(
                    $pdo, $taskId, (int)$task['room_id'],
                    $task['scheduled_start'], $task['scheduled_end'],
                    (bool)$task['blocks_availability'], $newStatus
                );

                $labels = ['pending'=>'Pending','in_progress'=>'In Progress','completed'=>'Completed','cancelled'=>'Cancelled'];
                $message = 'Task marked as ' . ($labels[$newStatus] ?? $newStatus) . '.';

            // ── Delete Task ─────────────────────────────────────────────────
            } elseif ($action === 'delete_task') {
                $taskId = (int)($_POST['task_id'] ?? 0);
                if ($taskId <= 0) throw new Exception('Invalid task ID.');

                removeMaintenanceBlockedDates($pdo, $taskId);
                $pdo->prepare("DELETE FROM room_maintenance_tasks WHERE id=?")->execute([$taskId]);
                $message = 'Task deleted.';
            }

        } catch (PDOException $e) {
            $error = 'Database error: ' . $e->getMessage();
        } catch (Exception $e) {
            $error = $e->getMessage();
        }
    }
}

// ── Edit Mode ──────────────────────────────────────────────────────────────────
$editId = isset($_GET['edit']) ? (int)$_GET['edit'] : 0;
if ($editId > 0 && $editTask === null) {
    try {
        $r = $pdo->prepare("SELECT * FROM room_maintenance_tasks WHERE id=?");
        $r->execute([$editId]);
        $editTask = $r->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (PDOException $e) {
        $editTask = null;
    }
}

// ── Filters ────────────────────────────────────────────────────────────────────
$filterStatus   = in_array($_GET['status']   ?? '', ['all','pending','in_progress','completed','cancelled']) ? ($_GET['status'] ?? 'all') : 'all';
$filterRoom     = isset($_GET['room_id'])    && ctype_digit((string)$_GET['room_id'])    ? (int)$_GET['room_id']    : 0;
$filterAssigned = isset($_GET['assigned_to'])&& ctype_digit((string)$_GET['assigned_to'])? (int)$_GET['assigned_to']: 0;
$filterEmployee = isset($_GET['employee_id'])&& ctype_digit((string)$_GET['employee_id'])? (int)$_GET['employee_id']: 0;
$prefillEmployee= isset($_GET['prefill_employee']) && ctype_digit((string)$_GET['prefill_employee']) ? (int)$_GET['prefill_employee'] : 0;
$filterDateFrom = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date_from'] ?? '') ? $_GET['date_from'] : '';
$filterDateTo   = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date_to']   ?? '') ? $_GET['date_to']   : '';

// ── Data Fetches ───────────────────────────────────────────────────────────────
$rooms = [];
try {
    $rooms = $pdo->query("SELECT id, name FROM rooms WHERE is_active=1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {}

$staffMembers = [];
try {
    $staffMembers = $pdo->query("SELECT id, full_name, role FROM admin_users ORDER BY full_name")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {}

$employees = [];
try {
    $employees = $pdo->query("SELECT id, full_name, position_title FROM employees WHERE is_active = 1 ORDER BY full_name")
        ->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {}

// Build dynamic WHERE
$taskWhere  = [];
$taskParams = [];

if ($filterStatus !== 'all') { $taskWhere[] = 't.status = ?';                  $taskParams[] = $filterStatus; }
if ($filterRoom > 0)         { $taskWhere[] = 't.room_id = ?';                 $taskParams[] = $filterRoom; }
if ($filterAssigned > 0)     { $taskWhere[] = 't.assigned_to = ?';             $taskParams[] = $filterAssigned; }
if ($filterEmployee > 0)     { $taskWhere[] = 't.employee_id = ?';             $taskParams[] = $filterEmployee; }
if ($filterDateFrom !== '')  { $taskWhere[] = 'DATE(t.scheduled_start) >= ?';  $taskParams[] = $filterDateFrom; }
if ($filterDateTo   !== '')  { $taskWhere[] = 'DATE(t.scheduled_start) <= ?';  $taskParams[] = $filterDateTo; }

$whereClause = $taskWhere ? 'WHERE ' . implode(' AND ', $taskWhere) : '';

$tasks = [];
try {
    $sql = "SELECT t.*,
        r.name        AS room_name,
        au.full_name  AS assignee_name,
        au.role       AS assignee_role,
        e.full_name   AS employee_name,
        e.position_title AS employee_position
    FROM room_maintenance_tasks t
    LEFT JOIN rooms r       ON r.id  = t.room_id
    LEFT JOIN admin_users au ON au.id = t.assigned_to
    LEFT JOIN employees e   ON e.id   = t.employee_id
    {$whereClause}
    ORDER BY
        FIELD(t.status,'in_progress','pending','completed','cancelled'),
        CASE t.priority WHEN 'urgent' THEN 1 WHEN 'high' THEN 2 WHEN 'medium' THEN 3 ELSE 4 END,
        t.scheduled_start ASC
    LIMIT 300";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($taskParams);
    $tasks = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $error = $error ?: 'Failed to load tasks: ' . $e->getMessage();
}

// Today's tasks for the schedule strip
$todayStr   = date('Y-m-d');
$todayTasks = array_filter($tasks, fn($t) =>
    date('Y-m-d', strtotime($t['scheduled_start'])) === $todayStr
    && in_array($t['status'], ['pending','in_progress'], true)
);

$summary  = getMaintenanceSummary($pdo);
$siteName = function_exists('getSetting') ? getSetting('site_name', 'Hotel') : 'Hotel';

// ── Label Maps ─────────────────────────────────────────────────────────────────
$typeLabels = [
    'maintenance'  => 'Maintenance',
    'deep_cleaning'=> 'Deep Clean',
    'room_service' => 'Room Service',
    'inspection'   => 'Inspection',
    'renovation'   => 'Renovation',
    'pest_control' => 'Pest Control',
    'other'        => 'Other',
];
$priorityLabels = ['low'=>'Low','medium'=>'Medium','high'=>'High','urgent'=>'Urgent'];
$statusLabels   = ['pending'=>'Pending','in_progress'=>'In Progress','completed'=>'Completed','cancelled'=>'Cancelled'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Maintenance &amp; Room Service - Admin</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;500;600;700&family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../css/style.css">
    <link rel="stylesheet" href="../css/theme-dynamic.php">
    <link rel="stylesheet" href="css/admin-styles.css">
    <link rel="stylesheet" href="css/admin-components.css">

    <style>
        /* ── Layout ────────────────────────────────────────── */
        .maint-layout {
            display: grid;
            grid-template-columns: minmax(0, 380px) minmax(0, 1fr);
            gap: 22px;
            margin-top: 18px;
            align-items: start;
        }
        .admin-content {
            width: 100%;
            max-width: 100%;
        }
        .maint-layout > * {
            min-width: 0;
        }
        @media (max-width: 1024px) {
            .maint-layout { grid-template-columns: 1fr; }
        }
        /* ── Mobile adjustments ─────────────────────────────── */
        @media (max-width: 768px) {
            .maint-stats {
                grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
                gap: 10px;
            }
            .panel { padding: 14px; }
            .mstat { padding: 10px 12px; }
            .mstat .mstat-val { font-size: 20px; }
            .filter-row { flex-direction: column; align-items: stretch; }
            .filter-row select,
            .filter-row input {
                width: 100%;
                flex: 1 1 auto;
            }
            .schedule-items { flex-direction: column; }
            .sched-card { min-width: unset; }
            .task-table th:nth-child(4),
            .task-table td:nth-child(4) { display: none; } /* hide Scheduled col on small screens */
            .form-grid { grid-template-columns: 1fr; }
            .form-grid .full { grid-column: 1; }
            .form-section-label { grid-column: 1; }
            .checkbox-row { grid-column: 1; }
            .btn-submit { grid-column: 1; }
            .btn-cancel-edit { grid-column: 1; }
            .recurrence-section { grid-column: 1; }
            .recurrence-row { grid-template-columns: 1fr; }
        }
        @media (max-width: 480px) {
            .maint-stats { grid-template-columns: 1fr 1fr; }
            .task-table th:nth-child(5),
            .task-table td:nth-child(5) { display: none; } /* hide Priority col on very small screens */
        }

        /* ── Stat Cards ────────────────────────────────────── */
        .maint-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 14px;
            margin-bottom: 18px;
        }
        .mstat {
            background: #fff;
            border-radius: 12px;
            padding: 14px 16px;
            box-shadow: 0 2px 12px rgba(0,0,0,.08);
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .mstat .mstat-icon {
            width: 40px; height: 40px;
            border-radius: 10px;
            display: flex; align-items: center; justify-content: center;
            font-size: 18px; flex-shrink: 0;
        }
        .mstat .mstat-val  { font-size: 24px; font-weight: 700; color: #111; }
        .mstat .mstat-lbl  { font-size: 11px; color: #6b7280; text-transform: uppercase; letter-spacing: .04em; }
        .mstat.today   .mstat-icon { background: #e0f2fe; color: #0369a1; }
        .mstat.overdue .mstat-icon { background: #fee2e2; color: #b91c1c; }
        .mstat.wip     .mstat-icon { background: #fef9c3; color: #854d0e; }
        .mstat.soon    .mstat-icon { background: #dcfce7; color: #15803d; }
        .mstat.pending .mstat-icon { background: #f3f4f6; color: #374151; }

        /* ── Today Schedule Strip ───────────────────────────── */
        .today-strip {
            background: #fff;
            border-radius: 12px;
            box-shadow: 0 2px 12px rgba(0,0,0,.08);
            padding: 14px 18px;
            margin-bottom: 18px;
        }
        .today-strip h4 { margin: 0 0 10px; font-size: 15px; color: #1f2937; }
        .schedule-items { display: flex; gap: 10px; flex-wrap: wrap; }
        .sched-card {
            border-radius: 9px;
            padding: 8px 12px;
            min-width: 180px;
            border-left: 3px solid #ccc;
            background: #f9fafb;
            font-size: 13px;
        }
        .sched-card.urgent   { border-color: #dc2626; }
        .sched-card.high     { border-color: #f97316; }
        .sched-card.medium   { border-color: #f59e0b; }
        .sched-card.low      { border-color: #22c55e; }
        .sched-card .sc-time  { font-size: 11px; color: #6b7280; }
        .sched-card .sc-title { font-weight: 600; color: #111827; }
        .sched-card .sc-room  { font-size: 11px; color: #6b7280; }

        /* ── Panel ──────────────────────────────────────────── */
        .panel {
            background: #fff;
            border-radius: 12px;
            box-shadow: 0 2px 12px rgba(0,0,0,.08);
            padding: 18px;
            min-width: 0;
        }
        .panel h3 { margin: 0 0 14px; font-size: 17px; color: #1f2937; }

        /* ── Form ───────────────────────────────────────────── */
        .form-grid { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1fr); gap: 10px; }
        .form-grid .full { grid-column: 1 / -1; }
        .form-grid > * { min-width: 0; }
        .form-group { display: flex; flex-direction: column; }
        .form-group label {
            font-size: 12px; font-weight: 600;
            color: #374151; margin-bottom: 4px;
        }
        .form-group input,
        .form-group select,
        .form-group textarea {
            border: 1px solid #d1d5db;
            border-radius: 7px;
            padding: 8px 10px;
            font-size: 13px;
            font-family: inherit;
            width: 100%;
            max-width: 100%;
            min-width: 0;
            box-sizing: border-box;
        }
        .form-group textarea { resize: vertical; min-height: 70px; }
        .form-section-label {
            grid-column: 1 / -1;
            font-size: 11px; font-weight: 700;
            text-transform: uppercase; letter-spacing: .06em;
            color: #9ca3af; padding-top: 6px;
        }
        .checkbox-row {
            grid-column: 1 / -1;
            display: flex; align-items: center; gap: 8px;
            font-size: 13px; color: #374151;
        }
        .checkbox-row input[type=checkbox] { width: 16px; height: 16px; cursor: pointer; }
        .btn-submit {
            grid-column: 1 / -1;
            background: #2563eb; color: #fff;
            border: none; border-radius: 8px;
            padding: 10px; font-size: 14px; font-weight: 600;
            cursor: pointer; transition: background .15s;
        }
        .btn-submit:hover { background: #1d4ed8; }
        .btn-cancel-edit {
            grid-column: 1 / -1;
            background: #f3f4f6; color: #374151;
            border: none; border-radius: 8px;
            padding: 9px; font-size: 13px; font-weight: 600;
            cursor: pointer; text-align: center; text-decoration: none;
            display: block;
        }
        .recurrence-section {
            grid-column: 1 / -1;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            padding: 10px 12px;
            background: #f9fafb;
        }
        .recurrence-section legend {
            font-size: 12px; font-weight: 600; color: #374151;
            padding: 0 6px;
        }
        .recurrence-row { display: grid; grid-template-columns: minmax(0, 1fr) 60px minmax(0, 1fr); gap: 8px; margin-top: 8px; }
        .recurrence-row > * { min-width: 0; }

        /* ── Filter Row ─────────────────────────────────────── */
        .filter-row {
            display: flex; flex-wrap: wrap; gap: 8px;
            align-items: flex-end; margin-bottom: 14px;
        }
        .filter-row > * { min-width: 0; }
        .filter-row select,
        .filter-row input {
            border: 1px solid #d1d5db;
            border-radius: 7px; padding: 7px 10px;
            font-size: 13px;
            flex: 1 1 160px;
            max-width: 100%;
            box-sizing: border-box;
        }
        .filter-row .btn-filter {
            background: #2563eb; color: #fff;
            border: none; border-radius: 7px;
            padding: 8px 14px; cursor: pointer;
            font-size: 13px; font-weight: 600;
            flex: 0 0 auto;
        }
        .filter-row .btn-reset {
            background: #f3f4f6; color: #374151;
            border: none; border-radius: 7px;
            padding: 8px 12px; cursor: pointer;
            font-size: 13px; font-weight: 600;
            text-decoration: none;
            flex: 0 0 auto;
        }

        /* ── Task Table ─────────────────────────────────────── */
        .task-table-wrap { overflow-x: auto; max-width: 100%; }
        .task-table {
            width: 100%; border-collapse: collapse;
            font-size: 13px;
            min-width: 980px;
        }
        .task-table th,
        .task-table td {
            border-bottom: 1px solid #e5e7eb;
            padding: 9px 8px;
            text-align: left;
            vertical-align: top;
        }
        .task-table th { font-weight: 700; color: #374151; background: #f9fafb; }

        /* ── Badges ─────────────────────────────────────────── */
        .badge {
            display: inline-block; padding: 2px 8px;
            border-radius: 999px; font-size: 11px; font-weight: 600;
        }
        .priority-urgent  { background: #fee2e2; color: #b91c1c; }
        .priority-high    { background: #ffedd5; color: #c2410c; }
        .priority-medium  { background: #fef9c3; color: #854d0e; }
        .priority-low     { background: #dcfce7; color: #15803d; }
        .status-pending      { background: #e0f2fe; color: #0369a1; }
        .status-in_progress  { background: #fef9c3; color: #854d0e; }
        .status-completed    { background: #dcfce7; color: #15803d; }
        .status-cancelled    { background: #f3f4f6; color: #6b7280; }
        .type-badge {
            background: #ede9fe; color: #5b21b6;
            display: inline-block; padding: 2px 7px;
            border-radius: 4px; font-size: 10px; font-weight: 600;
        }

        /* ── Actions ────────────────────────────────────────── */
        .btn-inline {
            border: none; border-radius: 6px;
            padding: 5px 9px; cursor: pointer;
            font-size: 11px; font-weight: 600;
            white-space: nowrap;
        }
        .btn-start    { background: #10b981; color: #fff; }
        .btn-complete { background: #3b82f6; color: #fff; }
        .btn-cancel   { background: #ef4444; color: #fff; }
        .btn-pending  { background: #6b7280; color: #fff; }
        .btn-edit     { background: #e0f2fe; color: #0369a1; }
        .btn-delete   { background: #fee2e2; color: #b91c1c; }
        .action-group { display: flex; flex-wrap: wrap; gap: 4px; }

        @media (max-width: 480px) {
            .task-table {
                min-width: 840px;
                font-size: 11px;
            }

            .task-table th,
            .task-table td {
                white-space: nowrap;
                padding: 6px 8px;
            }

            .task-table th:last-child,
            .task-table td:last-child {
                position: sticky;
                right: 0;
                background: #fff;
                z-index: 2;
                box-shadow: -8px 0 10px -8px rgba(15, 23, 42, 0.35);
            }

            .task-table thead th:last-child {
                background: #f9fafb;
                z-index: 3;
            }

            .action-group {
                width: 100%;
            }

            .action-group .btn-inline {
                width: 100%;
                text-align: center;
            }
        }

        @media (max-width: 360px) {
            .task-table {
                min-width: 760px;
            }
        }

        /* ── Date/Time display ───────────────────────────────── */
        .dt-range { font-size: 12px; white-space: nowrap; }
        .dt-range .dt-date { font-weight: 600; color: #1f2937; }
        .dt-range .dt-time { color: #6b7280; }
        .overdue-flag { color: #b91c1c; font-size: 10px; font-weight: 700; }
    </style>
</head>
<body>
<div class="admin-layout">
    <?php require_once 'includes/admin-header.php'; ?>

    <main class="admin-main">
        <div class="admin-content">

            <!-- Page Header -->
            <div class="admin-page-header">
                <h2><i class="fas fa-tools"></i> Maintenance &amp; Room Service</h2>
                <p>Schedule tasks, assign staff, and manage room availability blocks.</p>
            </div>

            <!-- Alerts -->
            <?php if ($message): ?><?php showAlert($message, 'success'); ?><?php endif; ?>
            <?php if ($error):   ?><?php showAlert($error,   'error');   ?><?php endif; ?>

            <!-- Summary Stats -->
            <div class="maint-stats">
                <div class="mstat today">
                    <div class="mstat-icon"><i class="fas fa-calendar-day"></i></div>
                    <div><div class="mstat-val"><?php echo $summary['today']; ?></div><div class="mstat-lbl">Today</div></div>
                </div>
                <div class="mstat overdue">
                    <div class="mstat-icon"><i class="fas fa-exclamation-triangle"></i></div>
                    <div><div class="mstat-val"><?php echo $summary['overdue']; ?></div><div class="mstat-lbl">Overdue</div></div>
                </div>
                <div class="mstat wip">
                    <div class="mstat-icon"><i class="fas fa-spinner"></i></div>
                    <div><div class="mstat-val"><?php echo $summary['in_progress']; ?></div><div class="mstat-lbl">In Progress</div></div>
                </div>
                <div class="mstat soon">
                    <div class="mstat-icon"><i class="fas fa-clock"></i></div>
                    <div><div class="mstat-val"><?php echo $summary['upcoming']; ?></div><div class="mstat-lbl">Next 7 Days</div></div>
                </div>
                <div class="mstat pending">
                    <div class="mstat-icon"><i class="fas fa-list"></i></div>
                    <div><div class="mstat-val"><?php echo $summary['pending']; ?></div><div class="mstat-lbl">Pending</div></div>
                </div>
            </div>

            <!-- Today's Schedule -->
            <?php if (!empty($todayTasks)): ?>
            <div class="today-strip">
                <h4><i class="fas fa-calendar-check"></i> Today's Schedule — <?php echo date('l, F j'); ?></h4>
                <div class="schedule-items">
                    <?php foreach ($todayTasks as $t): ?>
                    <div class="sched-card <?php echo htmlspecialchars($t['priority']); ?>">
                        <div class="sc-time">
                            <?php echo date('H:i', strtotime($t['scheduled_start'])); ?>
                            &ndash;
                            <?php echo date('H:i', strtotime($t['scheduled_end'])); ?>
                        </div>
                        <div class="sc-title"><?php echo htmlspecialchars($t['title']); ?></div>
                        <div class="sc-room">
                            <?php echo $t['room_name'] ? htmlspecialchars($t['room_name']) : 'Common Area'; ?>
                            <?php if ($t['assignee_name']): ?>
                            &mdash; <?php echo htmlspecialchars($t['assignee_name']); ?>
                            <?php elseif ($t['employee_name']): ?>
                            &mdash; <?php echo htmlspecialchars($t['employee_name']); ?>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- Main Layout: Form + Table -->
            <div class="maint-layout">

                <!-- Left: Create / Edit Form -->
                <div class="panel">
                    <h3>
                        <?php if ($editTask): ?>
                            <i class="fas fa-edit"></i> Edit Task
                        <?php else: ?>
                            <i class="fas fa-plus-circle"></i> New Task
                        <?php endif; ?>
                    </h3>

                    <form method="POST" action="maintenance.php">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                        <?php if ($editTask): ?>
                            <input type="hidden" name="action"  value="edit_task">
                            <input type="hidden" name="task_id" value="<?php echo (int)$editTask['id']; ?>">
                        <?php else: ?>
                            <input type="hidden" name="action" value="create_task">
                        <?php endif; ?>

                        <div class="form-grid">

                            <!-- Title (full width) -->
                            <div class="form-group full">
                                <label for="maint_title">Task Title *</label>
                                <input type="text" id="maint_title" name="title" maxlength="200" required
                                    placeholder="e.g. Fix bathroom tap, Deep clean Suite 4"
                                    value="<?php echo htmlspecialchars($editTask['title'] ?? ''); ?>">
                            </div>

                            <!-- Task Type -->
                            <div class="form-group">
                                <label for="maint_type">Task Type</label>
                                <select id="maint_type" name="task_type">
                                    <?php foreach ($typeLabels as $val => $lbl): ?>
                                    <option value="<?php echo $val; ?>" <?php echo ($editTask['task_type'] ?? 'maintenance') === $val ? 'selected' : ''; ?>>
                                        <?php echo $lbl; ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <!-- Priority -->
                            <div class="form-group">
                                <label for="maint_priority">Priority</label>
                                <select id="maint_priority" name="priority">
                                    <?php foreach ($priorityLabels as $val => $lbl): ?>
                                    <option value="<?php echo $val; ?>" <?php echo ($editTask['priority'] ?? 'medium') === $val ? 'selected' : ''; ?>>
                                        <?php echo $lbl; ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <!-- Room -->
                            <div class="form-group">
                                <label for="maint_room">Room / Area</label>
                                <select id="maint_room" name="room_id">
                                    <option value="">Common Area / Whole Hotel</option>
                                    <?php foreach ($rooms as $rm): ?>
                                    <option value="<?php echo (int)$rm['id']; ?>"
                                        <?php echo (isset($editTask['room_id']) && (int)$editTask['room_id'] === (int)$rm['id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($rm['name']); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <!-- Assigned To -->
                            <div class="form-group">
                                <label for="maint_assigned">Assigned To (Staff)</label>
                                <select id="maint_assigned" name="assigned_to">
                                    <option value="">— Unassigned —</option>
                                    <?php foreach ($staffMembers as $sm): ?>
                                    <option value="<?php echo (int)$sm['id']; ?>"
                                        <?php echo (isset($editTask['assigned_to']) && (int)$editTask['assigned_to'] === (int)$sm['id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($sm['full_name']); ?>
                                        (<?php echo htmlspecialchars($sm['role']); ?>)
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <!-- Optional employee assignee -->
                            <div class="form-group">
                                <label for="maint_employee">Assigned Employee (Optional)</label>
                                <select id="maint_employee" name="employee_id">
                                    <option value="">— No Employee —</option>
                                    <?php foreach ($employees as $emp): ?>
                                    <option value="<?php echo (int)$emp['id']; ?>"
                                        <?php echo ((isset($editTask['employee_id']) && (int)$editTask['employee_id'] === (int)$emp['id']) || (!$editTask && $prefillEmployee > 0 && $prefillEmployee === (int)$emp['id'])) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($emp['full_name']); ?>
                                        (<?php echo htmlspecialchars($emp['position_title']); ?>)
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                                <small style="font-size:11px;color:#6b7280;">Tip: assign from Employee Management using the "Create Task" action.</small>
                            </div>

                            <!-- Optional external assignee name -->
                            <div class="form-group">
                                <label for="maint_assigned_name">Or: Assignee Name <small style="font-weight:400;color:#9ca3af">(external / contractor)</small></label>
                                <input type="text" id="maint_assigned_name" name="assigned_to_name" maxlength="150"
                                    placeholder="e.g. John the Plumber, ABC Contractors"
                                    value="<?php echo htmlspecialchars($editTask['assigned_to_name'] ?? ''); ?>">
                            </div>

                            <!-- Schedule -->
                            <div class="form-section-label">Schedule</div>

                            <div class="form-group">
                                <label for="maint_start">Start Date &amp; Time *</label>
                                <input type="datetime-local" id="maint_start" name="scheduled_start" required
                                    value="<?php echo $editTask ? date('Y-m-d\TH:i', strtotime($editTask['scheduled_start'])) : ''; ?>">
                            </div>

                            <div class="form-group">
                                <label for="maint_end">End Date &amp; Time *</label>
                                <input type="datetime-local" id="maint_end" name="scheduled_end" required
                                    value="<?php echo $editTask ? date('Y-m-d\TH:i', strtotime($editTask['scheduled_end'])) : ''; ?>">
                            </div>

                            <!-- Blocks Availability -->
                            <div class="checkbox-row">
                                <input type="checkbox" id="maint_blocks" name="blocks_availability" value="1"
                                    <?php echo (!$editTask || $editTask['blocks_availability']) ? 'checked' : ''; ?>>
                                <label for="maint_blocks" style="font-size:13px;font-weight:400;">
                                    Block room from new bookings during this period
                                </label>
                            </div>

                            <!-- Recurring (only on create) -->
                            <?php if (!$editTask): ?>
                            <fieldset class="recurrence-section">
                                <legend>Repeat (optional)</legend>
                                <div class="form-group">
                                    <label for="repeat_type">Repeat</label>
                                    <select id="repeat_type" name="repeat_type" onchange="toggleRepeat(this.value)">
                                        <option value="none">No repeat</option>
                                        <option value="daily">Daily</option>
                                        <option value="weekly">Weekly</option>
                                        <option value="monthly">Monthly</option>
                                    </select>
                                </div>
                                <div id="repeat_details" style="display:none;" class="recurrence-row">
                                    <div class="form-group">
                                        <label>Every</label>
                                        <input type="number" name="repeat_interval" min="1" max="30" value="1" style="width:60px;">
                                        <span id="repeat_unit" style="align-self:flex-end;font-size:12px;color:#6b7280;padding-bottom:6px;">day(s)</span>
                                    </div>
                                    <div class="form-group">
                                        <label>Repeat until</label>
                                        <input type="date" name="repeat_until">
                                    </div>
                                </div>
                            </fieldset>
                            <?php endif; ?>

                            <!-- Description -->
                            <div class="form-group full">
                                <label for="maint_desc">Description</label>
                                <textarea id="maint_desc" name="description" placeholder="Describe what needs to be done..."><?php echo htmlspecialchars($editTask['description'] ?? ''); ?></textarea>
                            </div>

                            <!-- Notes -->
                            <div class="form-group full">
                                <label for="maint_notes">Internal Notes</label>
                                <textarea id="maint_notes" name="notes" placeholder="Internal notes for staff..."><?php echo htmlspecialchars($editTask['notes'] ?? ''); ?></textarea>
                            </div>

                            <button type="submit" class="btn-submit">
                                <?php echo $editTask ? 'Update Task' : 'Create Task'; ?>
                            </button>

                            <?php if ($editTask): ?>
                            <a href="maintenance.php" class="btn-cancel-edit">Cancel Edit</a>
                            <?php endif; ?>

                        </div>
                    </form>
                </div>

                <!-- Right: Task List -->
                <div class="panel">
                    <h3><i class="fas fa-list-ul"></i> Tasks</h3>

                    <!-- Filters -->
                    <form method="GET" action="maintenance.php">
                        <div class="filter-row">
                            <select name="status">
                                <option value="all"         <?php echo $filterStatus==='all'         ?'selected':''; ?>>All Statuses</option>
                                <option value="pending"     <?php echo $filterStatus==='pending'     ?'selected':''; ?>>Pending</option>
                                <option value="in_progress" <?php echo $filterStatus==='in_progress' ?'selected':''; ?>>In Progress</option>
                                <option value="completed"   <?php echo $filterStatus==='completed'   ?'selected':''; ?>>Completed</option>
                                <option value="cancelled"   <?php echo $filterStatus==='cancelled'   ?'selected':''; ?>>Cancelled</option>
                            </select>

                            <select name="room_id">
                                <option value="0">All Rooms</option>
                                <?php foreach ($rooms as $rm): ?>
                                <option value="<?php echo (int)$rm['id']; ?>" <?php echo $filterRoom===(int)$rm['id']?'selected':''; ?>>
                                    <?php echo htmlspecialchars($rm['name']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>

                            <select name="assigned_to">
                                <option value="0">All Staff</option>
                                <?php foreach ($staffMembers as $sm): ?>
                                <option value="<?php echo (int)$sm['id']; ?>" <?php echo $filterAssigned===(int)$sm['id']?'selected':''; ?>>
                                    <?php echo htmlspecialchars($sm['full_name']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>

                            <select name="employee_id">
                                <option value="0">All Employees</option>
                                <?php foreach ($employees as $emp): ?>
                                <option value="<?php echo (int)$emp['id']; ?>" <?php echo $filterEmployee===(int)$emp['id']?'selected':''; ?>>
                                    <?php echo htmlspecialchars($emp['full_name']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>

                            <input type="date" name="date_from" value="<?php echo htmlspecialchars($filterDateFrom); ?>" placeholder="From">
                            <input type="date" name="date_to"   value="<?php echo htmlspecialchars($filterDateTo); ?>"   placeholder="To">

                            <button type="submit" class="btn-filter"><i class="fas fa-filter"></i> Filter</button>
                            <a href="maintenance.php" class="btn-reset">Reset</a>
                        </div>
                    </form>

                    <!-- Table -->
                    <div class="task-table-wrap">
                        <table class="task-table">
                            <thead>
                                <tr>
                                    <th>Task</th>
                                    <th>Room</th>
                                    <th>Assigned</th>
                                    <th>Scheduled</th>
                                    <th>Priority</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php if (empty($tasks)): ?>
                                <tr><td colspan="7" style="text-align:center;color:#9ca3af;padding:28px;">No tasks found.</td></tr>
                            <?php else: ?>
                                <?php foreach ($tasks as $task):
                                    $isOverdue = in_array($task['status'],['pending','in_progress'],true)
                                                 && strtotime($task['scheduled_end']) < time();
                                ?>
                                <tr>
                                    <td>
                                        <strong><?php echo htmlspecialchars($task['title']); ?></strong><br>
                                        <span class="type-badge"><?php echo htmlspecialchars($typeLabels[$task['task_type']] ?? $task['task_type']); ?></span>
                                        <?php if ($task['recurrence_group_id']): ?>
                                            <span class="badge" style="background:#ede9fe;color:#7c3aed;font-size:10px;margin-left:3px;"><i class="fas fa-redo"></i> Recurring</span>
                                        <?php endif; ?>
                                        <?php if ($isOverdue): ?>
                                            <br><span class="overdue-flag"><i class="fas fa-exclamation-circle"></i> OVERDUE</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php echo $task['room_name'] ? htmlspecialchars($task['room_name']) : '<em style="color:#9ca3af">Common Area</em>'; ?>
                                        <?php if (!$task['blocks_availability']): ?>
                                            <br><span style="font-size:10px;color:#9ca3af">No block</span>
                                        <?php else: ?>
                                            <br><span style="font-size:10px;color:#dc2626"><i class="fas fa-ban"></i> Blocked</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($task['assignee_name']): ?>
                                            <?php echo htmlspecialchars($task['assignee_name']); ?><br>
                                            <small style="color:#9ca3af"><?php echo htmlspecialchars($task['assignee_role']); ?></small>
                                        <?php elseif (!empty($task['employee_name'])): ?>
                                            <?php echo htmlspecialchars($task['employee_name']); ?><br>
                                            <small style="color:#9ca3af"><?php echo htmlspecialchars($task['employee_position'] ?: 'Employee'); ?></small>
                                        <?php elseif (!empty($task['assigned_to_name'])): ?>
                                            <?php echo htmlspecialchars($task['assigned_to_name']); ?><br>
                                            <small style="color:#9ca3af">External</small>
                                        <?php else: ?>
                                            <em style="color:#9ca3af">Unassigned</em>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="dt-range">
                                            <div class="dt-date"><?php echo date('M j, Y', strtotime($task['scheduled_start'])); ?></div>
                                            <div class="dt-time"><?php echo date('H:i', strtotime($task['scheduled_start'])); ?> &ndash; <?php echo date('H:i', strtotime($task['scheduled_end'])); ?></div>
                                            <?php if (date('Y-m-d', strtotime($task['scheduled_start'])) !== date('Y-m-d', strtotime($task['scheduled_end']))): ?>
                                            <div class="dt-date" style="font-size:11px;">&rarr; <?php echo date('M j', strtotime($task['scheduled_end'])); ?></div>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="badge priority-<?php echo htmlspecialchars($task['priority']); ?>">
                                            <?php echo htmlspecialchars($priorityLabels[$task['priority']] ?? $task['priority']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="badge status-<?php echo htmlspecialchars($task['status']); ?>">
                                            <?php echo htmlspecialchars($statusLabels[$task['status']] ?? $task['status']); ?>
                                        </span>
                                        <?php if ($task['actual_start']): ?>
                                            <br><small style="color:#9ca3af">Started <?php echo date('H:i', strtotime($task['actual_start'])); ?></small>
                                        <?php endif; ?>
                                        <?php if ($task['actual_end']): ?>
                                            <br><small style="color:#9ca3af">Ended <?php echo date('H:i', strtotime($task['actual_end'])); ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="action-group">
                                            <?php if ($task['status'] === 'pending'): ?>
                                            <form method="POST" style="display:inline">
                                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                                <input type="hidden" name="action"    value="update_status">
                                                <input type="hidden" name="task_id"   value="<?php echo (int)$task['id']; ?>">
                                                <input type="hidden" name="status"    value="in_progress">
                                                <button class="btn-inline btn-start" title="Start task">
                                                    <i class="fas fa-play"></i> Start
                                                </button>
                                            </form>
                                            <?php endif; ?>

                                            <?php if ($task['status'] === 'in_progress'): ?>
                                            <form method="POST" style="display:inline">
                                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                                <input type="hidden" name="action"    value="update_status">
                                                <input type="hidden" name="task_id"   value="<?php echo (int)$task['id']; ?>">
                                                <input type="hidden" name="status"    value="completed">
                                                <button class="btn-inline btn-complete" title="Mark complete">
                                                    <i class="fas fa-check"></i> Complete
                                                </button>
                                            </form>
                                            <?php endif; ?>

                                            <?php if (in_array($task['status'],['pending','in_progress'],true)): ?>
                                            <form method="POST" style="display:inline">
                                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                                <input type="hidden" name="action"    value="update_status">
                                                <input type="hidden" name="task_id"   value="<?php echo (int)$task['id']; ?>">
                                                <input type="hidden" name="status"    value="cancelled">
                                                <button class="btn-inline btn-cancel" title="Cancel task">
                                                    <i class="fas fa-times"></i> Cancel
                                                </button>
                                            </form>
                                            <?php endif; ?>

                                            <?php if ($task['status'] === 'cancelled'): ?>
                                            <form method="POST" style="display:inline">
                                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                                <input type="hidden" name="action"    value="update_status">
                                                <input type="hidden" name="task_id"   value="<?php echo (int)$task['id']; ?>">
                                                <input type="hidden" name="status"    value="pending">
                                                <button class="btn-inline btn-pending" title="Re-open task">
                                                    <i class="fas fa-undo"></i> Re-open
                                                </button>
                                            </form>
                                            <?php endif; ?>

                                            <!-- Edit -->
                                            <a href="maintenance.php?edit=<?php echo (int)$task['id']; ?>" class="btn-inline btn-edit">
                                                <i class="fas fa-pen"></i>
                                            </a>

                                            <!-- Delete -->
                                            <form method="POST" style="display:inline"
                                                  onsubmit="return confirm('Delete this task? This will also unblock the room dates.')">
                                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                                <input type="hidden" name="action"    value="delete_task">
                                                <input type="hidden" name="task_id"   value="<?php echo (int)$task['id']; ?>">
                                                <button class="btn-inline btn-delete" title="Delete task">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div><!-- /right panel -->

            </div><!-- /maint-layout -->
        </div>
    </main>
</div>

<script>
function toggleRepeat(val) {
    var el = document.getElementById('repeat_details');
    var unit = document.getElementById('repeat_unit');
    if (!el) return;
    el.style.display = val === 'none' ? 'none' : 'grid';
    var map = {daily:'day(s)', weekly:'week(s)', monthly:'month(s)'};
    if (unit) unit.textContent = map[val] || '';
}

// Auto-set end time to 1 hour after start when creating
(function() {
    var startEl = document.getElementById('maint_start');
    var endEl   = document.getElementById('maint_end');
    if (!startEl || !endEl) return;
    startEl.addEventListener('change', function() {
        if (endEl.value === '' && this.value !== '') {
            var d = new Date(this.value);
            d.setHours(d.getHours() + 1);
            var pad = n => String(n).padStart(2,'0');
            endEl.value = d.getFullYear() + '-' + pad(d.getMonth()+1) + '-' + pad(d.getDate())
                        + 'T' + pad(d.getHours()) + ':' + pad(d.getMinutes());
        }
    });
})();
</script>
</body>
</html>
