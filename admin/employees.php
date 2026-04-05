<?php
require_once 'admin-init.php';
require_once '../includes/alert.php';
require_once '../config/email.php';
require_once '../includes/activity-logger.php';

$message = '';
$error = '';

// Ensure employee title catalog exists
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS employee_titles (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        title_name VARCHAR(120) NOT NULL UNIQUE,
        is_active TINYINT(1) DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $titleCount = (int)$pdo->query("SELECT COUNT(*) FROM employee_titles")->fetchColumn();
    if ($titleCount === 0) {
        $defaultTitles = [
            'Front Desk Officer',
            'Receptionist',
            'Reservations Agent',
            'Housekeeper',
            'Maintenance Technician',
            'Kitchen Staff',
            'Security Officer',
            'Guest Relations Officer',
            'Supervisor',
            'Manager',
        ];

        $insTitle = $pdo->prepare("INSERT INTO employee_titles (title_name, is_active) VALUES (?, 1)");
        foreach ($defaultTitles as $t) {
            $insTitle->execute([$t]);
        }
    }
} catch (PDOException $e) {
    // Keep page functional even if catalog setup fails
}

function buildEmployeeLoginUsername(PDO $pdo, string $email, string $fullName): string
{
    $base = '';

    if ($email !== '' && strpos($email, '@') !== false) {
        $base = strtolower((string)substr($email, 0, strpos($email, '@')));
    }

    if ($base === '') {
        $base = strtolower(preg_replace('/[^a-z0-9]+/', '.', trim($fullName)));
    }

    $base = trim($base, '.');
    if ($base === '') {
        $base = 'employee';
    }

    $candidate = $base;
    $suffix = 1;
    $check = $pdo->prepare("SELECT COUNT(*) FROM admin_users WHERE username = ?");

    while (true) {
        $check->execute([$candidate]);
        if ((int)$check->fetchColumn() === 0) {
            return $candidate;
        }
        $candidate = $base . $suffix;
        $suffix++;
    }
}

function syncEmployeeLoginAccount(PDO $pdo, array $employee, string $role, int $isActive): array
{
    $email = trim((string)($employee['email'] ?? ''));
    $fullName = trim((string)($employee['full_name'] ?? 'Employee'));
    $siteName = getSetting('site_name', 'Hotel Admin');

    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'message' => 'A valid employee email is required to create a login account.'];
    }

    $find = $pdo->prepare("SELECT id FROM admin_users WHERE email = ? LIMIT 1");
    $find->execute([$email]);
    $existingId = (int)($find->fetchColumn() ?: 0);

    if ($existingId > 0) {
        $upd = $pdo->prepare("UPDATE admin_users SET full_name = ?, role = ?, is_active = ? WHERE id = ?");
        $upd->execute([$fullName, $role, $isActive, $existingId]);
        return ['ok' => true, 'created' => false, 'message' => 'Existing login account synchronized.'];
    }

    $username = buildEmployeeLoginUsername($pdo, $email, $fullName);
    $tempPassword = bin2hex(random_bytes(4)) . 'Aa!';
    $hash = password_hash($tempPassword, PASSWORD_DEFAULT);

    $ins = $pdo->prepare("INSERT INTO admin_users (username, email, password_hash, full_name, role, is_active) VALUES (?, ?, ?, ?, ?, ?)");
    $ins->execute([$username, $email, $hash, $fullName, $role, $isActive]);

    $emailNote = 'Login account created. Temporary password generated.';
    if (function_exists('sendEmail')) {
        $subject = 'Your Staff Login Details - ' . $siteName;
        $html = '
            <div style="font-family: Arial, sans-serif; max-width:600px; margin:0 auto;">
                <h2 style="color:#0A1929;">Staff Login Account Created</h2>
                <p>Hello ' . htmlspecialchars($fullName, ENT_QUOTES, 'UTF-8') . ',</p>
                <p>A staff login account was created for you.</p>
                <div style="background:#f8f9fa; border:1px solid #e9ecef; border-radius:8px; padding:16px; margin:16px 0;">
                    <p style="margin:0 0 8px;"><strong>Username:</strong> ' . htmlspecialchars($username, ENT_QUOTES, 'UTF-8') . '</p>
                    <p style="margin:0 0 8px;"><strong>Temporary Password:</strong> ' . htmlspecialchars($tempPassword, ENT_QUOTES, 'UTF-8') . '</p>
                    <p style="margin:0;"><strong>Role:</strong> ' . htmlspecialchars($role, ENT_QUOTES, 'UTF-8') . '</p>
                </div>
                <p>Please log in and change your password immediately.</p>
            </div>';

        $mailResult = sendEmail($email, $fullName, $subject, $html);
        if (!($mailResult['success'] ?? false)) {
            $emailNote = 'Login account created, but email delivery failed: ' . ($mailResult['message'] ?? 'Unknown error');
        }
    }

    return ['ok' => true, 'created' => true, 'message' => $emailNote];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $csrf_token) {
        $error = 'Invalid security token. Please try again.';
    } else {
        $action = $_POST['action'] ?? '';
        $sanitize = fn($v, $max = 255) => mb_substr(strip_tags(trim((string)$v)), 0, $max);

        try {
            if ($action === 'add_employee_title') {
                $titleName = $sanitize($_POST['title_name'] ?? '', 120);
                if ($titleName === '') {
                    throw new Exception('Title name is required.');
                }

                $checkTitle = $pdo->prepare("SELECT COUNT(*) FROM employee_titles WHERE title_name = ?");
                $checkTitle->execute([$titleName]);
                if ((int)$checkTitle->fetchColumn() > 0) {
                    throw new Exception('That title already exists.');
                }

                $insTitle = $pdo->prepare("INSERT INTO employee_titles (title_name, is_active) VALUES (?, 1)");
                $insTitle->execute([$titleName]);
                $message = 'Employee title added successfully.';
            } elseif ($action === 'delete_employee_title') {
                $titleId = (int)($_POST['title_id'] ?? 0);
                if ($titleId <= 0) {
                    throw new Exception('Invalid title.');
                }

                $delTitle = $pdo->prepare("DELETE FROM employee_titles WHERE id = ?");
                $delTitle->execute([$titleId]);
                $message = 'Employee title removed successfully.';
            } elseif ($action === 'create_employee' || $action === 'edit_employee') {
                $fullName = $sanitize($_POST['full_name'] ?? '', 180);
                $email = $sanitize($_POST['email'] ?? '', 255);
                $phone = $sanitize($_POST['phone'] ?? '', 50);
                $position = $sanitize($_POST['position_title'] ?? '', 120);
                $department = $sanitize($_POST['department'] ?? '', 120);
                $createLogin = isset($_POST['create_login']) ? 1 : 0;
                $loginRole = $_POST['login_role'] ?? 'receptionist';
                
                // Handle multi-select privileges
                $priv_array = $_POST['privileges'] ?? [];
                if (is_array($priv_array)) {
                    $privileges = implode(', ', array_map(function($p) use ($sanitize) {
                        return $sanitize($p, 100);
                    }, array_filter($priv_array)));
                } else {
                    $privileges = $sanitize($priv_array, 3000);
                }
                
                $notes = $sanitize($_POST['notes'] ?? '', 2000);
                $isActive = isset($_POST['is_active']) ? 1 : 0;

                if ($fullName === '') throw new Exception('Employee name is required.');
                if ($position === '') throw new Exception('Position is required.');
                if ($createLogin && !in_array($loginRole, ['admin', 'manager', 'receptionist'], true)) {
                    throw new Exception('Invalid login role selected.');
                }
                if ($createLogin && ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL))) {
                    throw new Exception('A valid employee email is required when creating a login account.');
                }

                if ($action === 'create_employee') {
                    $stmt = $pdo->prepare("INSERT INTO employees
                        (full_name, email, phone, position_title, department, privileges, notes, is_active)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                    $stmt->execute([
                        $fullName,
                        $email ?: null,
                        $phone ?: null,
                        $position,
                        $department ?: null,
                        $privileges ?: null,
                        $notes ?: null,
                        $isActive,
                    ]);
                    if ($createLogin) {
                        $sync = syncEmployeeLoginAccount($pdo, [
                            'full_name' => $fullName,
                            'email' => $email,
                        ], $loginRole, $isActive);

                        if (!($sync['ok'] ?? false)) {
                            throw new Exception('Employee added, but login setup failed: ' . ($sync['message'] ?? 'Unknown error'));
                        }

                        $message = 'Employee added successfully. ' . ($sync['message'] ?? '');
                    } else {
                        $message = 'Employee added successfully.';
                    }

                    try {
                        $createdEmployeeId = (int)$pdo->lastInsertId();
                        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
                        $ua = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500);
                        logAdminActivity($pdo, (int)$user['id'], $user['username'] ?? null, 'employee_created', 'Created employee record: ' . $fullName, $ip, $ua);
                        logEmployeeActivity($pdo, $createdEmployeeId, null, (int)$user['id'], 'employee_record_created', 'Employee record created by admin', 'employees_page', $ip, $ua);
                    } catch (Throwable $e) {
                        // Do not block operation if logging fails.
                    }
                } else {
                    $employeeId = (int)($_POST['employee_id'] ?? 0);
                    if ($employeeId <= 0) throw new Exception('Invalid employee ID.');

                    $stmt = $pdo->prepare("UPDATE employees SET
                        full_name = ?,
                        email = ?,
                        phone = ?,
                        position_title = ?,
                        department = ?,
                        privileges = ?,
                        notes = ?,
                        is_active = ?
                        WHERE id = ?");
                    $stmt->execute([
                        $fullName,
                        $email ?: null,
                        $phone ?: null,
                        $position,
                        $department ?: null,
                        $privileges ?: null,
                        $notes ?: null,
                        $isActive,
                        $employeeId,
                    ]);
                    if ($createLogin) {
                        $sync = syncEmployeeLoginAccount($pdo, [
                            'full_name' => $fullName,
                            'email' => $email,
                        ], $loginRole, $isActive);

                        if (!($sync['ok'] ?? false)) {
                            throw new Exception('Employee updated, but login sync failed: ' . ($sync['message'] ?? 'Unknown error'));
                        }

                        $message = 'Employee updated successfully. ' . ($sync['message'] ?? '');
                    } else {
                        $message = 'Employee updated successfully.';
                    }

                    try {
                        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
                        $ua = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500);
                        logAdminActivity($pdo, (int)$user['id'], $user['username'] ?? null, 'employee_updated', 'Updated employee record: ' . $fullName . ' (ID: ' . $employeeId . ')', $ip, $ua);
                        logEmployeeActivity($pdo, $employeeId, null, (int)$user['id'], 'employee_record_updated', 'Employee record updated by admin', 'employees_page', $ip, $ua);
                    } catch (Throwable $e) {
                        // Do not block operation if logging fails.
                    }
                }
            } elseif ($action === 'delete_employee') {
                $employeeId = (int)($_POST['employee_id'] ?? 0);
                if ($employeeId <= 0) throw new Exception('Invalid employee ID.');

                $employeeName = '';
                try {
                    $empRow = $pdo->prepare("SELECT full_name FROM employees WHERE id = ? LIMIT 1");
                    $empRow->execute([$employeeId]);
                    $employeeName = (string)($empRow->fetchColumn() ?: 'Unknown employee');
                } catch (Throwable $e) {
                    $employeeName = 'Unknown employee';
                }

                $stmt = $pdo->prepare("DELETE FROM employees WHERE id = ?");
                $stmt->execute([$employeeId]);
                $message = 'Employee deleted successfully.';

                try {
                    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
                    $ua = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500);
                    logAdminActivity($pdo, (int)$user['id'], $user['username'] ?? null, 'employee_deleted', 'Deleted employee record: ' . $employeeName . ' (ID: ' . $employeeId . ')', $ip, $ua);
                    logEmployeeActivity($pdo, $employeeId, null, (int)$user['id'], 'employee_record_deleted', 'Employee record deleted by admin', 'employees_page', $ip, $ua);
                } catch (Throwable $e) {
                    // Do not block operation if logging fails.
                }
            }
        } catch (PDOException $e) {
            $error = 'Database error: ' . $e->getMessage();
        } catch (Exception $e) {
            $error = $e->getMessage();
        }
    }
}

$editId = isset($_GET['edit']) ? (int)$_GET['edit'] : 0;
$editEmployee = null;
if ($editId > 0) {
    try {
        $stmt = $pdo->prepare("SELECT e.*, au.id AS login_user_id, au.role AS login_role
            FROM employees e
            LEFT JOIN admin_users au ON au.email = e.email
            WHERE e.id = ?");
        $stmt->execute([$editId]);
        $editEmployee = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (PDOException $e) {
        $editEmployee = null;
    }
}

$employees = [];
try {
    $employees = $pdo->query("SELECT e.*, au.id AS login_user_id, au.role AS login_role
        FROM employees e
        LEFT JOIN admin_users au ON au.email = e.email
        ORDER BY e.is_active DESC, e.full_name ASC")
        ->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $error = $error ?: 'Failed to load employees: ' . $e->getMessage();
}

$employee_titles = [];
try {
    $employee_titles = $pdo->query("SELECT id, title_name FROM employee_titles WHERE is_active = 1 ORDER BY title_name ASC")
        ->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $employee_titles = [];
}

$siteName = function_exists('getSetting') ? getSetting('site_name', 'Hotel') : 'Hotel';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Employees - Admin</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;500;600;700&family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../css/style.css">
    <link rel="stylesheet" href="../css/theme-dynamic.php">
    <link rel="stylesheet" href="css/admin-styles.css">
    <link rel="stylesheet" href="css/admin-components.css">

    <style>
        .emp-layout {
            display: grid;
            grid-template-columns: minmax(300px, 380px) 1fr;
            gap: 20px;
            align-items: start;
        }
        @media (max-width: 1000px) {
            .emp-layout { grid-template-columns: 1fr; }
        }

        .panel {
            background: #fff;
            border-radius: 12px;
            box-shadow: 0 2px 12px rgba(0,0,0,.08);
            padding: 18px;
        }

        .panel h3 {
            margin: 0 0 14px;
            color: #1f2937;
            font-size: 17px;
        }

        .form-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 10px;
        }

        .form-group label {
            font-size: 12px;
            font-weight: 600;
            color: #374151;
            margin-bottom: 4px;
        }

        .form-group input,
        .form-group select,
        .form-group textarea {
            border: 1px solid #d1d5db;
            border-radius: 7px;
            padding: 8px 10px;
            font-size: 13px;
            font-family: inherit;
        }

        .form-group textarea {
            resize: vertical;
            min-height: 80px;
        }

        .checkbox-row {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 13px;
            color: #374151;
        }

        .btn-submit {
            background: #2563eb;
            color: #fff;
            border: none;
            border-radius: 8px;
            padding: 10px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
        }

        .btn-cancel {
            background: #f3f4f6;
            color: #374151;
            border: none;
            border-radius: 8px;
            padding: 9px;
            font-size: 13px;
            font-weight: 600;
            text-decoration: none;
            text-align: center;
            display: block;
        }

        .table-wrap { overflow-x: auto; }
        .emp-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
        }
        .emp-table th,
        .emp-table td {
            border-bottom: 1px solid #e5e7eb;
            padding: 9px 8px;
            text-align: left;
            vertical-align: top;
        }
        .emp-table th { background: #f9fafb; color: #374151; }

        .badge {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 999px;
            font-size: 11px;
            font-weight: 600;
        }
        .badge.active { background: #dcfce7; color: #15803d; }
        .badge.inactive { background: #f3f4f6; color: #6b7280; }

        .action-group { display: flex; gap: 4px; flex-wrap: wrap; }
        .btn-inline {
            border: none;
            border-radius: 6px;
            padding: 5px 9px;
            cursor: pointer;
            font-size: 11px;
            font-weight: 600;
            text-decoration: none;
            display: inline-block;
        }
        .btn-edit { background: #e0f2fe; color: #0369a1; }
        .btn-delete { background: #fee2e2; color: #b91c1c; }
    </style>
</head>
<body>
<div class="admin-layout">
    <?php require_once 'includes/admin-header.php'; ?>

    <main class="admin-main">
        <div class="admin-content">

            <div class="admin-page-header">
                <h2><i class="fas fa-user-tie"></i> Employees</h2>
                <p>Manage operational employees, positions, and duties for assignment and scheduling.</p>
            </div>

            <div class="panel" style="margin-bottom:16px;">
                <h3><i class="fas fa-list"></i> Employee Titles Catalog</h3>
                <form method="POST" style="display:flex; gap:8px; align-items:center; flex-wrap:wrap; margin-bottom:10px;">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                    <input type="hidden" name="action" value="add_employee_title">
                    <input type="text" name="title_name" placeholder="Add new title (e.g. Concierge)" required style="min-width:260px; padding:8px 10px; border:1px solid #d1d5db; border-radius:8px;">
                    <button type="submit" class="btn-inline btn-edit"><i class="fas fa-plus"></i> Add Title</button>
                </form>
                <div style="display:flex; gap:8px; flex-wrap:wrap;">
                    <?php if (empty($employee_titles)): ?>
                        <span style="color:#6b7280; font-size:12px;">No titles yet.</span>
                    <?php else: ?>
                        <?php foreach ($employee_titles as $t): ?>
                            <form method="POST" style="display:inline-flex; align-items:center; gap:6px; margin:0;">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                <input type="hidden" name="action" value="delete_employee_title">
                                <input type="hidden" name="title_id" value="<?php echo (int)$t['id']; ?>">
                                <span style="background:#f3f4f6; color:#374151; border:1px solid #e5e7eb; border-radius:999px; padding:6px 10px; font-size:12px;">
                                    <?php echo htmlspecialchars($t['title_name']); ?>
                                </span>
                                <button type="submit" class="btn-inline btn-delete" title="Delete title" onclick="return confirm('Delete this title from catalog?');"><i class="fas fa-times"></i></button>
                            </form>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <?php if ($message): ?><?php showAlert($message, 'success'); ?><?php endif; ?>
            <?php if ($error): ?><?php showAlert($error, 'error'); ?><?php endif; ?>

            <div class="emp-layout">
                <div class="panel">
                    <h3>
                        <?php if ($editEmployee): ?>
                            <i class="fas fa-edit"></i> Edit Employee
                        <?php else: ?>
                            <i class="fas fa-plus-circle"></i> New Employee
                        <?php endif; ?>
                    </h3>

                    <form method="POST" action="employees.php<?php echo $editEmployee ? '?edit=' . (int)$editEmployee['id'] : ''; ?>">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                        <?php if ($editEmployee): ?>
                            <input type="hidden" name="action" value="edit_employee">
                            <input type="hidden" name="employee_id" value="<?php echo (int)$editEmployee['id']; ?>">
                        <?php else: ?>
                            <input type="hidden" name="action" value="create_employee">
                        <?php endif; ?>

                        <div class="form-grid">
                            <div class="form-group">
                                <label>Full Name *</label>
                                <input type="text" name="full_name" maxlength="180" required
                                    value="<?php echo htmlspecialchars($editEmployee['full_name'] ?? ''); ?>">
                            </div>

                            <div class="form-group">
                                <label>Position *</label>
                                <select name="position_title" required>
                                    <option value="">Select title</option>
                                    <?php foreach ($employee_titles as $title): ?>
                                        <option value="<?php echo htmlspecialchars($title['title_name']); ?>" <?php echo (($editEmployee['position_title'] ?? '') === $title['title_name']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($title['title_name']); ?></option>
                                    <?php endforeach; ?>
                                    <?php if (!empty($editEmployee['position_title']) && !in_array($editEmployee['position_title'], array_column($employee_titles, 'title_name'), true)): ?>
                                        <option value="<?php echo htmlspecialchars($editEmployee['position_title']); ?>" selected><?php echo htmlspecialchars($editEmployee['position_title']); ?> (legacy)</option>
                                    <?php endif; ?>
                                </select>
                            </div>

                            <div class="form-group">
                                <label>Department</label>
                                <input type="text" name="department" maxlength="120"
                                    placeholder="e.g. Maintenance, Housekeeping"
                                    value="<?php echo htmlspecialchars($editEmployee['department'] ?? ''); ?>">
                            </div>

                            <div class="form-group">
                                <label>Email</label>
                                <input type="email" name="email" maxlength="255"
                                    value="<?php echo htmlspecialchars($editEmployee['email'] ?? ''); ?>">
                            </div>

                            <div class="form-group" style="background:#f8fafc; border:1px solid #e5e7eb; border-radius:10px; padding:12px;">
                                <label style="display:flex; align-items:center; gap:8px; margin-bottom:10px;">
                                    <input type="checkbox" name="create_login" value="1" style="width:auto;" <?php echo !empty($editEmployee['login_user_id']) ? 'checked' : ''; ?>>
                                    Create or sync system login account
                                </label>
                                <label>Login Role</label>
                                <select name="login_role" style="width:100%; min-height:auto;">
                                    <option value="receptionist" <?php echo (($editEmployee['login_role'] ?? '') === 'receptionist' || (($editEmployee['login_role'] ?? '') === '' && stripos((string)($editEmployee['position_title'] ?? ''), 'reception') !== false)) ? 'selected' : ''; ?>>Receptionist</option>
                                    <option value="manager" <?php echo (($editEmployee['login_role'] ?? '') === 'manager' || (($editEmployee['login_role'] ?? '') === '' && stripos((string)($editEmployee['position_title'] ?? ''), 'manager') !== false)) ? 'selected' : ''; ?>>Manager</option>
                                    <option value="admin" <?php echo (($editEmployee['login_role'] ?? '') === 'admin' || (($editEmployee['login_role'] ?? '') === '' && stripos((string)($editEmployee['position_title'] ?? ''), 'admin') !== false)) ? 'selected' : ''; ?>>Admin</option>
                                </select>
                                <small style="color: #666; display: block; margin-top: 6px;">Requires a valid email. If account does not exist, a temporary password is emailed.</small>
                            </div>

                            <div class="form-group">
                                <label>Phone</label>
                                <input type="text" name="phone" maxlength="50"
                                    value="<?php echo htmlspecialchars($editEmployee['phone'] ?? ''); ?>">
                            </div>

                            <div class="form-group">
                                <label>Operational Duties</label>
                                <select name="privileges" multiple style="min-height: 120px;">
                                    <option value="Front Desk" <?php echo (strpos((string)($editEmployee['privileges'] ?? ''), 'Front Desk') !== false ? 'selected' : ''); ?>>Front Desk</option>
                                    <option value="Check In-Out" <?php echo (strpos((string)($editEmployee['privileges'] ?? ''), 'Check In-Out') !== false ? 'selected' : ''); ?>>Check In-Out</option>
                                    <option value="Reservations" <?php echo (strpos((string)($editEmployee['privileges'] ?? ''), 'Reservations') !== false ? 'selected' : ''); ?>>Reservations</option>
                                    <option value="Cash Handling" <?php echo (strpos((string)($editEmployee['privileges'] ?? ''), 'Cash Handling') !== false ? 'selected' : ''); ?>>Cash Handling</option>
                                    <option value="Housekeeping" <?php echo (strpos((string)($editEmployee['privileges'] ?? ''), 'Housekeeping') !== false ? 'selected' : ''); ?>>Housekeeping</option>
                                    <option value="Maintenance" <?php echo (strpos((string)($editEmployee['privileges'] ?? ''), 'Maintenance') !== false ? 'selected' : ''); ?>>Maintenance</option>
                                    <option value="Kitchen Service" <?php echo (strpos((string)($editEmployee['privileges'] ?? ''), 'Kitchen Service') !== false ? 'selected' : ''); ?>>Kitchen Service</option>
                                    <option value="Security" <?php echo (strpos((string)($editEmployee['privileges'] ?? ''), 'Security') !== false ? 'selected' : ''); ?>>Security</option>
                                    <option value="Inventory" <?php echo (strpos((string)($editEmployee['privileges'] ?? ''), 'Inventory') !== false ? 'selected' : ''); ?>>Inventory</option>
                                    <option value="Guest Relations" <?php echo (strpos((string)($editEmployee['privileges'] ?? ''), 'Guest Relations') !== false ? 'selected' : ''); ?>>Guest Relations</option>
                                </select>
                                <small style="color: #666; display: block; margin-top: 4px;">Hold Ctrl/Cmd to select multiple. These are duties, not system login permissions.</small>
                            </div>

                            <div class="form-group">
                                <label>Notes</label>
                                <textarea name="notes"><?php echo htmlspecialchars($editEmployee['notes'] ?? ''); ?></textarea>
                            </div>

                            <label class="checkbox-row">
                                <input type="checkbox" name="is_active" value="1"
                                    <?php echo isset($editEmployee) ? ((int)($editEmployee['is_active'] ?? 1) === 1 ? 'checked' : '') : 'checked'; ?>>
                                Active employee
                            </label>

                            <button type="submit" class="btn-submit">
                                <?php echo $editEmployee ? 'Update Employee' : 'Add Employee'; ?>
                            </button>

                            <?php if ($editEmployee): ?>
                            <a href="employees.php" class="btn-cancel">Cancel Edit</a>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>

                <div class="panel">
                    <h3><i class="fas fa-list"></i> Employee Directory</h3>
                    <div class="table-wrap">
                        <table class="emp-table">
                            <thead>
                                <tr>
                                    <th>Name</th>
                                    <th>Position</th>
                                    <th>Duties</th>
                                    <th>Login</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($employees)): ?>
                                    <tr>
                                        <td colspan="6" style="text-align:center;color:#9ca3af;padding:28px;">No employees found.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($employees as $emp): ?>
                                    <tr>
                                        <td>
                                            <strong><?php echo htmlspecialchars($emp['full_name']); ?></strong>
                                            <?php if (!empty($emp['department'])): ?>
                                            <br><small style="color:#9ca3af"><?php echo htmlspecialchars($emp['department']); ?></small>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo htmlspecialchars($emp['position_title']); ?></td>
                                        <td style="max-width:280px;color:#4b5563;">
                                            <?php echo htmlspecialchars(mb_substr((string)($emp['privileges'] ?? ''), 0, 90)); ?>
                                            <?php if (mb_strlen((string)($emp['privileges'] ?? '')) > 90): ?>…<?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if (!empty($emp['login_user_id'])): ?>
                                                <span class="badge active">Yes (<?php echo htmlspecialchars((string)($emp['login_role'] ?? 'user')); ?>)</span>
                                            <?php else: ?>
                                                <span class="badge inactive">No</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="badge <?php echo (int)$emp['is_active'] === 1 ? 'active' : 'inactive'; ?>">
                                                <?php echo (int)$emp['is_active'] === 1 ? 'Active' : 'Inactive'; ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div class="action-group">
                                                <a href="maintenance.php?prefill_employee=<?php echo (int)$emp['id']; ?>" class="btn-inline btn-edit" title="Create maintenance task">
                                                    <i class="fas fa-tools"></i>
                                                </a>
                                                <a href="employees.php?edit=<?php echo (int)$emp['id']; ?>" class="btn-inline btn-edit">
                                                    <i class="fas fa-pen"></i>
                                                </a>
                                                <form method="POST" style="display:inline" onsubmit="return confirm('Delete this employee?')">
                                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                                    <input type="hidden" name="action" value="delete_employee">
                                                    <input type="hidden" name="employee_id" value="<?php echo (int)$emp['id']; ?>">
                                                    <button class="btn-inline btn-delete" title="Delete"><i class="fas fa-trash"></i></button>
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

        </div>
    </main>
</div>
</body>
</html>
