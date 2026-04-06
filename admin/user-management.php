<?php
// Include admin initialization (PHP-only, no HTML output)
require_once 'admin-init.php';
require_once '../config/email.php';
require_once '../includes/activity-logger.php';
// permissions.php already loaded by admin-init.php

// Only admin role can access user management
if ($user['role'] !== 'admin') {
    header('Location: dashboard.php?error=access_denied');
    exit;
}

$site_name = getSetting('site_name');
$success_msg = '';
$error_msg = '';

$logAdminEvent = function (string $action, string $details) use ($pdo, $user): void {
    try {
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $ua = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500);
        logAdminActivity($pdo, (int)$user['id'], $user['username'] ?? null, $action, $details, $ip, $ua);
    } catch (Throwable $e) {
        // Never block business actions if logging fails.
    }
};

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

// Ensure user_permissions table exists
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS user_permissions (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id INT UNSIGNED NOT NULL,
        permission_key VARCHAR(50) NOT NULL,
        is_granted TINYINT(1) DEFAULT 1,
        granted_by INT UNSIGNED NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY unique_user_perm (user_id, permission_key),
        FOREIGN KEY (user_id) REFERENCES admin_users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (PDOException $e) {
    // Table likely exists
}

// Ensure employee_titles catalog exists
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
    // Keep page usable even if catalog setup fails
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Verify CSRF token
    if (!isset($_POST['csrf_token']) || !validateCsrfToken($_POST['csrf_token'])) {
        $error_msg = 'Invalid security token. Please try again.';
    } else {
        $action = $_POST['action'] ?? '';
        
        // ---- ADD NEW USER ----
        if ($action === 'add_user') {
            $username = trim($_POST['username'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $full_name = trim($_POST['full_name'] ?? '');
            $role = $_POST['role'] ?? 'receptionist';
            $password = $_POST['password'] ?? '';
            
            if (empty($username) || empty($email) || empty($full_name) || empty($password)) {
                $error_msg = 'All fields are required.';
            } elseif (strlen($password) < 8) {
                $error_msg = 'Password must be at least 8 characters.';
            } elseif (!in_array($role, ['admin', 'manager', 'receptionist'])) {
                $error_msg = 'Invalid role selected.';
            } else {
                // Check for duplicate username/email
                $check = $pdo->prepare("SELECT COUNT(*) FROM admin_users WHERE username = ? OR email = ?");
                $check->execute([$username, $email]);
                if ($check->fetchColumn() > 0) {
                    $error_msg = 'Username or email already exists.';
                } else {
                    $hash = password_hash($password, PASSWORD_DEFAULT);
                    $stmt = $pdo->prepare("INSERT INTO admin_users (username, email, password_hash, full_name, role) VALUES (?, ?, ?, ?, ?)");
                    $stmt->execute([$username, $email, $hash, $full_name, $role]);
                    $success_msg = "User '{$full_name}' created successfully.";

                    $createdUserId = (int)$pdo->lastInsertId();
                    $logAdminEvent(
                        'user_created',
                        "Created user: {$full_name} ({$username}, role: {$role}, id: {$createdUserId})"
                    );
                }
            }
        }
        
        // ---- UPDATE USER ----
        elseif ($action === 'update_user') {
            $uid = (int)($_POST['user_id'] ?? 0);
            $full_name = trim($_POST['full_name'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $role = $_POST['role'] ?? 'receptionist';
            $is_active = isset($_POST['is_active']) ? 1 : 0;
            $new_password = $_POST['new_password'] ?? '';
            
            if ($uid <= 0 || empty($full_name) || empty($email)) {
                $error_msg = 'Full name and email are required.';
            } elseif (!in_array($role, ['admin', 'manager', 'receptionist'])) {
                $error_msg = 'Invalid role selected.';
            } else {
                // Check email uniqueness (excluding current user)
                $check = $pdo->prepare("SELECT COUNT(*) FROM admin_users WHERE email = ? AND id != ?");
                $check->execute([$email, $uid]);
                if ($check->fetchColumn() > 0) {
                    $error_msg = 'Email already in use by another user.';
                } else {
                    if (!empty($new_password)) {
                        if (strlen($new_password) < 8) {
                            $error_msg = 'Password must be at least 8 characters.';
                        } else {
                            $hash = password_hash($new_password, PASSWORD_DEFAULT);
                            $stmt = $pdo->prepare("UPDATE admin_users SET full_name = ?, email = ?, role = ?, is_active = ?, password_hash = ? WHERE id = ?");
                            $stmt->execute([$full_name, $email, $role, $is_active, $hash, $uid]);
                            $success_msg = "User updated successfully (including password).";

                            $logAdminEvent(
                                'user_updated',
                                "Updated user ID {$uid}: {$full_name} (role: {$role}, active: {$is_active}, password_changed: yes)"
                            );
                        }
                    } else {
                        $stmt = $pdo->prepare("UPDATE admin_users SET full_name = ?, email = ?, role = ?, is_active = ? WHERE id = ?");
                        $stmt->execute([$full_name, $email, $role, $is_active, $uid]);
                        $success_msg = "User updated successfully.";

                        $logAdminEvent(
                            'user_updated',
                            "Updated user ID {$uid}: {$full_name} (role: {$role}, active: {$is_active}, password_changed: no)"
                        );
                    }
                }
            }
        }
        
        // ---- SAVE PERMISSIONS ----
        elseif ($action === 'save_permissions') {
            $uid = (int)($_POST['user_id'] ?? 0);
            if ($uid <= 0) {
                $error_msg = 'Invalid user.';
            } else {
                // Ensure not editing admin's permissions
                $check_role = $pdo->prepare("SELECT role, full_name, username FROM admin_users WHERE id = ?");
                $check_role->execute([$uid]);
                $target_user = $check_role->fetch(PDO::FETCH_ASSOC);
                $target_role = $target_user['role'] ?? null;
                
                if ($target_role === 'admin') {
                    $error_msg = 'Cannot modify admin permissions.';
                } else {
                    $all_perms = getAllPermissions();
                    $granted = $_POST['permissions'] ?? [];
                    $perms_to_set = [];
                    
                    foreach ($all_perms as $key => $info) {
                        if ($key === 'user_management') continue; // Admin-only
                        $perms_to_set[$key] = in_array($key, $granted);
                    }
                    
                    if (setUserPermissions($uid, $perms_to_set, $user['id'])) {
                        $success_msg = "Permissions updated successfully.";

                        $grantedCount = 0;
                        foreach ($perms_to_set as $isGranted) {
                            if ($isGranted) {
                                $grantedCount++;
                            }
                        }
                        $targetName = trim((string)($target_user['full_name'] ?? $target_user['username'] ?? "User #{$uid}"));
                        $logAdminEvent(
                            'user_permissions_updated',
                            "Updated permissions for {$targetName} (ID: {$uid}, granted: {$grantedCount}, total: " . count($perms_to_set) . ")"
                        );
                    } else {
                        $error_msg = "Failed to update permissions.";
                    }
                }
            }
        }
        
        // ---- DELETE USER ----
        elseif ($action === 'delete_user') {
            $uid = (int)($_POST['user_id'] ?? 0);
            if ($uid <= 0) {
                $error_msg = 'Invalid user.';
            } elseif ($uid === (int)$user['id']) {
                $error_msg = 'You cannot delete your own account.';
            } else {
                // Don't allow deleting the last admin
                $admin_count = $pdo->query("SELECT COUNT(*) FROM admin_users WHERE role = 'admin' AND is_active = 1")->fetchColumn();
                $check_role = $pdo->prepare("SELECT role, full_name, username FROM admin_users WHERE id = ?");
                $check_role->execute([$uid]);
                $target_user = $check_role->fetch(PDO::FETCH_ASSOC);
                $target_role = $target_user['role'] ?? null;
                
                if ($target_role === 'admin' && $admin_count <= 1) {
                    $error_msg = 'Cannot delete the last admin user.';
                } else {
                    $stmt = $pdo->prepare("DELETE FROM admin_users WHERE id = ?");
                    $stmt->execute([$uid]);
                    $success_msg = "User deleted successfully.";

                    $targetName = trim((string)($target_user['full_name'] ?? $target_user['username'] ?? "User #{$uid}"));
                    $targetRole = (string)($target_user['role'] ?? 'unknown');
                    $logAdminEvent(
                        'user_deleted',
                        "Deleted user: {$targetName} (ID: {$uid}, role: {$targetRole})"
                    );
                }
            }
        }

        // ---- ADD EMPLOYEE TITLE ----
        elseif ($action === 'add_employee_title') {
            $title_name = trim($_POST['title_name'] ?? '');
            if ($title_name === '') {
                $error_msg = 'Title name is required.';
            } else {
                $checkTitle = $pdo->prepare("SELECT COUNT(*) FROM employee_titles WHERE title_name = ?");
                $checkTitle->execute([$title_name]);
                if ((int)$checkTitle->fetchColumn() > 0) {
                    $error_msg = 'That title already exists.';
                } else {
                    $insTitle = $pdo->prepare("INSERT INTO employee_titles (title_name, is_active) VALUES (?, 1)");
                    $insTitle->execute([$title_name]);
                    $success_msg = 'Employee title added successfully.';

                    $logAdminEvent('employee_title_added', "Added employee title: {$title_name}");
                }
            }
        }

        // ---- DELETE EMPLOYEE TITLE ----
        elseif ($action === 'delete_employee_title') {
            $title_id = (int)($_POST['title_id'] ?? 0);
            if ($title_id <= 0) {
                $error_msg = 'Invalid title.';
            } else {
                $title_name = '';
                try {
                    $titleStmt = $pdo->prepare("SELECT title_name FROM employee_titles WHERE id = ? LIMIT 1");
                    $titleStmt->execute([$title_id]);
                    $title_name = (string)($titleStmt->fetchColumn() ?: 'Unknown title');
                } catch (Throwable $e) {
                    $title_name = 'Unknown title';
                }

                $delTitle = $pdo->prepare("DELETE FROM employee_titles WHERE id = ?");
                $delTitle->execute([$title_id]);
                $success_msg = 'Employee title removed successfully.';

                $logAdminEvent('employee_title_deleted', "Deleted employee title: {$title_name} (ID: {$title_id})");
            }
        }

        // ---- ADD EMPLOYEE ----
        elseif ($action === 'add_employee') {
            $full_name = trim($_POST['emp_full_name'] ?? '');
            $position_title = trim($_POST['emp_position_title'] ?? '');
            $department = trim($_POST['emp_department'] ?? '');
            $email = trim($_POST['emp_email'] ?? '');
            $phone = trim($_POST['emp_phone'] ?? '');
            $create_login = isset($_POST['emp_create_login']) ? 1 : 0;
            $login_role = $_POST['emp_login_role'] ?? 'receptionist';
            
            // Handle multi-select privileges
            $priv_array = $_POST['emp_privileges'] ?? [];
            if (is_array($priv_array)) {
                $privileges = implode(', ', array_filter($priv_array));
            } else {
                $privileges = trim($priv_array);
            }
            
            $notes = trim($_POST['emp_notes'] ?? '');
            $is_active = isset($_POST['emp_is_active']) ? 1 : 0;

            if ($full_name === '' || $position_title === '') {
                $error_msg = 'Employee full name and position are required.';
            } elseif ($create_login && !in_array($login_role, ['admin', 'manager', 'receptionist'], true)) {
                $error_msg = 'Invalid login role selected.';
            } elseif ($create_login && ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL))) {
                $error_msg = 'A valid employee email is required when creating a login account.';
            } else {
                $stmt = $pdo->prepare("INSERT INTO employees
                    (full_name, email, phone, position_title, department, privileges, notes, is_active)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([
                    $full_name,
                    $email ?: null,
                    $phone ?: null,
                    $position_title,
                    $department ?: null,
                    $privileges ?: null,
                    $notes ?: null,
                    $is_active,
                ]);

                if ($create_login) {
                    $sync = syncEmployeeLoginAccount($pdo, [
                        'full_name' => $full_name,
                        'email' => $email,
                    ], $login_role, $is_active);

                    if (!($sync['ok'] ?? false)) {
                        $error_msg = "Employee '{$full_name}' added, but login setup failed: " . ($sync['message'] ?? 'Unknown error');
                    } else {
                        $success_msg = "Employee '{$full_name}' added successfully. " . ($sync['message'] ?? '');
                    }
                } else {
                    $success_msg = "Employee '{$full_name}' added successfully.";
                }

                try {
                    $createdEmployeeId = (int)$pdo->lastInsertId();
                    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
                    $ua = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500);
                    logAdminActivity($pdo, (int)$user['id'], $user['username'] ?? null, 'employee_created', 'Created employee record: ' . $full_name, $ip, $ua);
                    logEmployeeActivity($pdo, $createdEmployeeId, null, (int)$user['id'], 'employee_record_created', 'Employee record created by admin', 'user_management', $ip, $ua);
                } catch (Throwable $e) {
                    // Do not block flow if logging fails.
                }
            }
        }

        // ---- UPDATE EMPLOYEE ----
        elseif ($action === 'update_employee') {
            $eid = (int)($_POST['employee_id'] ?? 0);
            $full_name = trim($_POST['emp_full_name'] ?? '');
            $position_title = trim($_POST['emp_position_title'] ?? '');
            $department = trim($_POST['emp_department'] ?? '');
            $email = trim($_POST['emp_email'] ?? '');
            $phone = trim($_POST['emp_phone'] ?? '');
            $create_login = isset($_POST['emp_create_login']) ? 1 : 0;
            $login_role = $_POST['emp_login_role'] ?? 'receptionist';
            
            // Handle multi-select privileges
            $priv_array = $_POST['emp_privileges'] ?? [];
            if (is_array($priv_array)) {
                $privileges = implode(', ', array_filter($priv_array));
            } else {
                $privileges = trim($priv_array);
            }
            
            $notes = trim($_POST['emp_notes'] ?? '');
            $is_active = isset($_POST['emp_is_active']) ? 1 : 0;

            if ($eid <= 0 || $full_name === '' || $position_title === '') {
                $error_msg = 'Invalid employee data.';
            } elseif ($create_login && !in_array($login_role, ['admin', 'manager', 'receptionist'], true)) {
                $error_msg = 'Invalid login role selected.';
            } elseif ($create_login && ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL))) {
                $error_msg = 'A valid employee email is required when creating a login account.';
            } else {
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
                    $full_name,
                    $email ?: null,
                    $phone ?: null,
                    $position_title,
                    $department ?: null,
                    $privileges ?: null,
                    $notes ?: null,
                    $is_active,
                    $eid,
                ]);

                if ($create_login) {
                    $sync = syncEmployeeLoginAccount($pdo, [
                        'full_name' => $full_name,
                        'email' => $email,
                    ], $login_role, $is_active);

                    if (!($sync['ok'] ?? false)) {
                        $error_msg = 'Employee updated, but login sync failed: ' . ($sync['message'] ?? 'Unknown error');
                    } else {
                        $success_msg = 'Employee updated successfully. ' . ($sync['message'] ?? '');
                    }
                } else {
                    $success_msg = 'Employee updated successfully.';
                }

                try {
                    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
                    $ua = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500);
                    logAdminActivity($pdo, (int)$user['id'], $user['username'] ?? null, 'employee_updated', 'Updated employee record: ' . $full_name . ' (ID: ' . $eid . ')', $ip, $ua);
                    logEmployeeActivity($pdo, $eid, null, (int)$user['id'], 'employee_record_updated', 'Employee record updated by admin', 'user_management', $ip, $ua);
                } catch (Throwable $e) {
                    // Do not block flow if logging fails.
                }
            }
        }

        // ---- DELETE EMPLOYEE ----
        elseif ($action === 'delete_employee') {
            $eid = (int)($_POST['employee_id'] ?? 0);
            if ($eid <= 0) {
                $error_msg = 'Invalid employee.';
            } else {
                $employeeName = '';
                try {
                    $empRow = $pdo->prepare("SELECT full_name FROM employees WHERE id = ? LIMIT 1");
                    $empRow->execute([$eid]);
                    $employeeName = (string)($empRow->fetchColumn() ?: 'Unknown employee');
                } catch (Throwable $e) {
                    $employeeName = 'Unknown employee';
                }

                $stmt = $pdo->prepare("DELETE FROM employees WHERE id = ?");
                $stmt->execute([$eid]);
                $success_msg = 'Employee deleted successfully.';

                try {
                    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
                    $ua = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500);
                    logAdminActivity($pdo, (int)$user['id'], $user['username'] ?? null, 'employee_deleted', 'Deleted employee record: ' . $employeeName . ' (ID: ' . $eid . ')', $ip, $ua);
                    logEmployeeActivity($pdo, $eid, null, (int)$user['id'], 'employee_record_deleted', 'Employee record deleted by admin', 'user_management', $ip, $ua);
                } catch (Throwable $e) {
                    // Do not block flow if logging fails.
                }
            }
        }
    }
}

// Fetch all users
$users_stmt = $pdo->query("SELECT * FROM admin_users ORDER BY role ASC, full_name ASC");
$all_users = $users_stmt->fetchAll(PDO::FETCH_ASSOC);

$employees = [];
try {
    $employees = $pdo->query("SELECT e.*, au.id AS login_user_id, au.role AS login_role
        FROM employees e
        LEFT JOIN admin_users au ON au.email = e.email
        ORDER BY e.is_active DESC, e.full_name ASC")
        ->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // employees table is auto-created in admin-init; keep this safe
    $employees = [];
}

$employee_titles = [];
try {
    $employee_titles = $pdo->query("SELECT id, title_name FROM employee_titles WHERE is_active = 1 ORDER BY title_name ASC")
        ->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $employee_titles = [];
}

// If editing a specific user's permissions
$editing_user_id = isset($_GET['permissions']) ? (int)$_GET['permissions'] : 0;
$editing_user = null;
$editing_permissions = [];
if ($editing_user_id > 0) {
    foreach ($all_users as $u) {
        if ($u['id'] == $editing_user_id) {
            $editing_user = $u;
            break;
        }
    }
    if ($editing_user && $editing_user['role'] !== 'admin') {
        $editing_permissions = getUserPermissions($editing_user_id);
    }
}

$all_permissions = getAllPermissions();
$permission_categories = [];
foreach ($all_permissions as $key => $info) {
    if ($key === 'user_management') continue; // Admin-only, not configurable
    $permission_categories[$info['category']][$key] = $info;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Management | <?php echo htmlspecialchars($site_name); ?> Admin</title>
    
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;500;600;700&family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../css/style.css">
    <link rel="stylesheet" href="../css/theme-dynamic.php">
    <link rel="stylesheet" href="css/admin-styles.css">
    <link rel="stylesheet" href="css/admin-components.css">
    
    <style>
        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 24px;
            flex-wrap: wrap;
            gap: 16px;
        }
        .page-header h2 {
            font-family: 'Playfair Display', serif;
            color: var(--deep-navy, #05090F);
            margin: 0;
        }
        
        /* Users Table */
        .users-table {
            width: 100%;
            border-collapse: collapse;
            background: white;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 2px 12px rgba(0,0,0,0.08);
        }
        .users-table thead {
            background: linear-gradient(135deg, var(--deep-navy, #05090F) 0%, var(--navy, #0A1929) 100%);
            color: white;
        }
        .users-table th {
            padding: 14px 18px;
            text-align: left;
            font-weight: 600;
            font-size: 13px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .users-table td {
            padding: 14px 18px;
            border-bottom: 1px solid #f0f0f0;
            font-size: 14px;
        }
        .users-table tbody tr:hover {
            background: #f8f9fa;
        }
        .users-table tbody tr:last-child td {
            border-bottom: none;
        }
        
        /* Role Badge */
        .role-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .role-badge.admin {
            background: linear-gradient(135deg, #c9a44a 0%, #dbb963 100%);
            color: #1a1a1a;
        }
        .role-badge.manager {
            background: linear-gradient(135deg, #3498db 0%, #5dade2 100%);
            color: white;
        }
        .role-badge.receptionist {
            background: linear-gradient(135deg, #27ae60 0%, #2ecc71 100%);
            color: white;
        }
        
        /* Status Badge */
        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 500;
        }
        .status-badge.active {
            background: #e8f5e9;
            color: #2e7d32;
        }
        .status-badge.inactive {
            background: #fbe9e7;
            color: #c62828;
        }
        .status-badge i {
            font-size: 8px;
        }
        
        /* Action buttons */
        .btn-sm {
            padding: 6px 14px;
            border: none;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 500;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            transition: all 0.2s ease;
        }
        .btn-edit {
            background: #e3f2fd;
            color: #1565c0;
        }
        .btn-edit:hover {
            background: #bbdefb;
        }
        .btn-permissions {
            background: #fff3e0;
            color: #e65100;
        }
        .btn-permissions:hover {
            background: #ffe0b2;
        }
        .btn-delete {
            background: #fbe9e7;
            color: #c62828;
        }
        .btn-delete:hover {
            background: #ffccbc;
        }
        .btn-add {
            background: var(--gold, #c9a44a);
            color: white;
            padding: 10px 22px;
            border: none;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.2s ease;
        }
        .btn-add:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(201, 164, 74, 0.3);
        }
        .btn-save-perms {
            background: var(--gold, #c9a44a);
            color: white;
            padding: 12px 28px;
            border: none;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.2s ease;
        }
        .btn-save-perms:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(201, 164, 74, 0.3);
        }
        .btn-cancel {
            background: #f5f5f5;
            color: #555;
            padding: 12px 28px;
            border: 1px solid #ddd;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 500;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.2s ease;
        }
        .btn-cancel:hover {
            background: #eee;
        }
        
        /* Alert messages */
        .alert {
            padding: 14px 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 14px;
        }
        .alert-success {
            background: #e8f5e9;
            color: #2e7d32;
            border: 1px solid #c8e6c9;
        }
        .alert-error {
            background: #fbe9e7;
            color: #c62828;
            border: 1px solid #ffccbc;
        }
        
        /* User Cards (for table actions) */
        .actions-cell {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }
        
        /* Modal */
        .modal-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0,0,0,0.5);
            z-index: 2000;
            justify-content: center;
            align-items: flex-start;
            padding: 40px 20px;
            overflow-y: auto;
        }
        .modal-overlay.active {
            display: flex;
        }
        .modal-wrapper {
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            z-index: 2001;
            display: block;
            height: auto;
            min-height: 0;
            background: white;
            border-radius: 16px;
            padding: 0;
            max-width: 560px;
            width: calc(100% - 40px);
            max-height: calc(100vh - 80px);
            overflow-y: auto;
            box-shadow: 0 20px 60px rgba(0,0,0,0.2);
            margin: 0;
            opacity: 0;
            visibility: hidden;
            pointer-events: none;
            box-sizing: border-box;
        }
        .modal-wrapper.active {
            opacity: 1;
            visibility: visible;
            pointer-events: auto;
            transform: translate(-50%, -50%);
        }
        .modal-wrapper.modal-md {
            max-width: 560px;
        }
        .modal-wrapper > form {
            margin: 0;
            background: #fff;
            border-radius: inherit;
            overflow: hidden;
        }
        .modal {
            background: white;
            border-radius: 16px;
            padding: 0;
            max-width: 560px;
            width: 100%;
            max-height: 90vh;
            overflow-y: auto;
            box-shadow: 0 20px 60px rgba(0,0,0,0.2);
        }
        .modal-header {
            padding: 20px 28px;
            border-bottom: 1px solid #eee;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .modal-header h3 {
            margin: 0;
            font-family: 'Playfair Display', serif;
            color: var(--deep-navy, #05090F);
            font-size: 20px;
        }
        .modal-close {
            background: none;
            border: none;
            font-size: 20px;
            color: #999;
            cursor: pointer;
            padding: 4px 8px;
            border-radius: 6px;
            transition: all 0.2s;
        }
        .modal-close:hover {
            background: #f5f5f5;
            color: #333;
        }
        .modal-body {
            padding: 24px 28px;
        }
        .modal-footer {
            padding: 16px 28px;
            border-top: 1px solid #eee;
            display: flex;
            justify-content: flex-end;
            gap: 12px;
        }
        
        /* Form in modal */
        .form-row {
            margin-bottom: 18px;
        }
        .form-row label {
            display: block;
            font-weight: 600;
            font-size: 13px;
            color: #333;
            margin-bottom: 6px;
        }
        .form-row input,
        .form-row select,
        .form-row textarea {
            width: 100%;
            padding: 10px 14px;
            border: 1px solid #ddd;
            border-radius: 8px;
            font-size: 14px;
            font-family: 'Poppins', sans-serif;
            transition: border-color 0.2s;
            box-sizing: border-box;
        }
        .form-row input:focus,
        .form-row select:focus,
        .form-row textarea:focus {
            outline: none;
            border-color: var(--gold, #c9a44a);
            box-shadow: 0 0 0 3px rgba(201, 164, 74, 0.1);
        }
        .form-row .hint {
            font-size: 12px;
            color: #888;
            margin-top: 4px;
        }
        
        /* Permissions Panel */
        .permissions-panel {
            background: white;
            border-radius: 16px;
            box-shadow: 0 2px 12px rgba(0,0,0,0.08);
            overflow: hidden;
            margin-top: 24px;
        }
        .permissions-header {
            padding: 20px 28px;
            background: linear-gradient(135deg, var(--deep-navy, #05090F) 0%, var(--navy, #0A1929) 100%);
            color: white;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .permissions-header h3 {
            margin: 0;
            font-family: 'Playfair Display', serif;
            font-size: 18px;
        }
        .permissions-header .perm-user-info {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .permissions-header .perm-user-name {
            font-weight: 600;
        }
        .permissions-body {
            padding: 28px;
        }
        
        /* Permission Category */
        .perm-category {
            margin-bottom: 28px;
        }
        .perm-category:last-child {
            margin-bottom: 0;
        }
        .perm-category-title {
            font-family: 'Playfair Display', serif;
            font-size: 16px;
            color: var(--deep-navy, #05090F);
            margin: 0 0 14px 0;
            padding-bottom: 8px;
            border-bottom: 2px solid var(--gold, #c9a44a);
            display: inline-block;
        }
        .perm-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
            gap: 12px;
        }
        .perm-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 16px;
            background: #f8f9fa;
            border-radius: 10px;
            border: 1px solid #eee;
            transition: all 0.2s ease;
            cursor: pointer;
        }
        .perm-item:hover {
            border-color: var(--gold, #c9a44a);
            background: #fff9ed;
        }
        .perm-item.checked {
            background: #fff9ed;
            border-color: var(--gold, #c9a44a);
        }
        .perm-item input[type="checkbox"] {
            width: 18px;
            height: 18px;
            accent-color: var(--gold, #c9a44a);
            cursor: pointer;
            flex-shrink: 0;
        }
        .perm-item .perm-info {
            flex: 1;
        }
        .perm-item .perm-label {
            font-weight: 600;
            font-size: 13px;
            color: #333;
        }
        .perm-item .perm-desc {
            font-size: 11px;
            color: #888;
            margin-top: 2px;
        }
        .perm-item .perm-icon {
            width: 36px;
            height: 36px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: white;
            color: var(--gold, #c9a44a);
            font-size: 14px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.08);
            flex-shrink: 0;
        }
        
        /* Quick actions */
        .quick-actions {
            display: flex;
            gap: 12px;
            margin-bottom: 20px;
        }
        .btn-select-all,
        .btn-select-none,
        .btn-select-defaults {
            padding: 6px 14px;
            border: 1px solid #ddd;
            border-radius: 6px;
            background: white;
            font-size: 12px;
            cursor: pointer;
            transition: all 0.2s;
            font-family: 'Poppins', sans-serif;
        }
        .btn-select-all:hover { background: #e8f5e9; border-color: #4caf50; color: #2e7d32; }
        .btn-select-none:hover { background: #fbe9e7; border-color: #ef5350; color: #c62828; }
        .btn-select-defaults:hover { background: #e3f2fd; border-color: #42a5f5; color: #1565c0; }
        
        .perm-actions {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 24px;
            padding-top: 20px;
            border-top: 1px solid #eee;
        }
        
        /* Access denied notice */
        .access-denied {
            background: #fff3e0;
            border: 1px solid #ffe0b2;
            border-radius: 8px;
            padding: 14px 20px;
            margin-bottom: 20px;
            color: #e65100;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        /* Last login */
        .last-login {
            font-size: 12px;
            color: #888;
        }
        
        /* Responsive */
        @media (max-width: 768px) {
            .page-header {
                flex-direction: column;
                align-items: flex-start;
            }
            .users-table thead {
                display: none;
            }
            .users-table, 
            .users-table tbody, 
            .users-table tr, 
            .users-table td {
                display: block;
            }
            .users-table tr {
                margin-bottom: 16px;
                border-radius: 12px;
                overflow: hidden;
                box-shadow: 0 2px 8px rgba(0,0,0,0.06);
            }
            .users-table td {
                padding: 10px 16px;
                text-align: right;
                position: relative;
                padding-left: 45%;
            }
            .users-table td::before {
                content: attr(data-label);
                position: absolute;
                left: 16px;
                font-weight: 600;
                font-size: 12px;
                text-transform: uppercase;
                color: #888;
            }
            .actions-cell {
                justify-content: flex-end;
                width: 100%;
            }

            .actions-cell .btn-sm,
            .actions-cell a.btn-sm {
                width: 100%;
                justify-content: center;
            }

            input[name="title_name"] {
                min-width: 0 !important;
                width: 100%;
            }
            .perm-grid {
                grid-template-columns: 1fr;
            }
            .permissions-header {
                flex-direction: column;
                gap: 12px;
                text-align: center;
            }
            .quick-actions {
                flex-wrap: wrap;
            }
            .perm-actions {
                flex-direction: column;
                gap: 12px;
            }
            .modal-overlay {
                padding: 20px 12px;
            }
            .modal-wrapper,
            .modal-wrapper.active {
                top: 20px;
                left: 50%;
                transform: translateX(-50%);
                width: calc(100% - 24px);
                max-height: calc(100vh - 40px);
            }
        }

        @media (max-width: 480px) {
            .users-table td {
                padding-left: 42%;
            }

            .users-table td::before {
                left: 12px;
                max-width: 40%;
            }
        }
    </style>
</head>
<body>

<?php require_once 'includes/admin-header.php'; ?>

<main class="admin-content" style="padding: 32px; max-width: 1400px; margin: 0 auto; flex: 1;">
    
    <?php if (isset($_GET['error']) && $_GET['error'] === 'access_denied'): ?>
    <div class="access-denied">
        <i class="fas fa-exclamation-triangle"></i>
        You do not have permission to access that page.
    </div>
    <?php endif; ?>
    
    <?php if ($success_msg): ?>
    <div class="alert alert-success">
        <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($success_msg); ?>
    </div>
    <?php endif; ?>
    
    <?php if ($error_msg): ?>
    <div class="alert alert-error">
        <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error_msg); ?>
    </div>
    <?php endif; ?>
    
    <!-- USERS LIST -->
    <div class="page-header">
        <h2><i class="fas fa-users-cog"></i> User Management</h2>
        <div style="display:flex; gap:10px; flex-wrap:wrap;">
            <button class="btn-add" onclick="Modal.open('addEmployeeModal')">
                <i class="fas fa-user-tie"></i> Add Employee
            </button>
            <button class="btn-add" onclick="Modal.open('addUserModal')">
                <i class="fas fa-user-plus"></i> Add New User
            </button>
        </div>
    </div>
    <p style="margin: 0 0 18px; color:#5f6b7a; font-size:13px; line-height:1.5;">
        <strong>Users</strong> are login accounts for the admin system with roles/permissions.
        <strong>Employees</strong> are staff records used for operations, scheduling, and assignment.
        A login account can optionally be created and linked to an employee by email.
    </p>
    
    <div style="overflow-x:auto;">
        <table class="users-table">
            <thead>
                <tr>
                    <th>User</th>
                    <th>Username</th>
                    <th>Email</th>
                    <th>Role</th>
                    <th>Status</th>
                    <th>Last Login</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($all_users as $u): ?>
                <tr>
                    <td data-label="User">
                        <strong><?php echo htmlspecialchars($u['full_name']); ?></strong>
                    </td>
                    <td data-label="Username"><?php echo htmlspecialchars($u['username']); ?></td>
                    <td data-label="Email"><?php echo htmlspecialchars($u['email']); ?></td>
                    <td data-label="Role">
                        <span class="role-badge <?php echo $u['role']; ?>">
                            <?php echo ucfirst($u['role']); ?>
                        </span>
                    </td>
                    <td data-label="Status">
                        <span class="status-badge <?php echo $u['is_active'] ? 'active' : 'inactive'; ?>">
                            <i class="fas fa-circle"></i>
                            <?php echo $u['is_active'] ? 'Active' : 'Inactive'; ?>
                        </span>
                    </td>
                    <td data-label="Last Login">
                        <span class="last-login">
                            <?php echo $u['last_login'] ? date('M j, Y g:ia', strtotime($u['last_login'])) : 'Never'; ?>
                        </span>
                    </td>
                    <td data-label="Actions">
                        <div class="actions-cell">
                            <button type="button" class="btn-sm btn-edit js-edit-user" data-user="<?php echo htmlspecialchars(json_encode($u), ENT_QUOTES, 'UTF-8'); ?>">
                                <i class="fas fa-edit"></i> Edit
                            </button>
                            <?php if ($u['role'] !== 'admin'): ?>
                            <a href="?permissions=<?php echo $u['id']; ?>" class="btn-sm btn-permissions">
                                <i class="fas fa-shield-alt"></i> Permissions
                            </a>
                            <?php endif; ?>
                            <?php if ($u['id'] != $user['id']): ?>
                            <button type="button" class="btn-sm btn-delete" onclick="confirmDelete(<?php echo $u['id']; ?>, '<?php echo htmlspecialchars($u['full_name'], ENT_QUOTES); ?>')">
                                <i class="fas fa-trash"></i>
                            </button>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- EMPLOYEES LIST -->
    <div class="page-header" style="margin-top:28px;">
        <h2><i class="fas fa-user-tie"></i> Employee Management</h2>
        <p style="margin: 8px 0 0; color:#666; font-size:13px;">
            Employee duties are for operational assignment and scheduling. System login access is controlled by user roles and permissions above.
        </p>
    </div>

    <div style="background:#fff; border:1px solid #e5e7eb; border-radius:12px; padding:14px 16px; margin: 12px 0 18px;">
        <div style="display:flex; justify-content:space-between; align-items:center; gap:12px; flex-wrap:wrap; margin-bottom:10px;">
            <strong style="color:#1f2937;"><i class="fas fa-list"></i> Employee Titles Catalog</strong>
            <form method="POST" style="display:flex; gap:8px; align-items:center; flex-wrap:wrap; margin:0;">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                <input type="hidden" name="action" value="add_employee_title">
                <input type="text" name="title_name" placeholder="Add new title (e.g. Concierge)" required style="min-width:260px; padding:8px 10px; border:1px solid #d1d5db; border-radius:8px;">
                <button type="submit" class="btn-sm btn-edit"><i class="fas fa-plus"></i> Add Title</button>
            </form>
        </div>
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
                        <button type="submit" class="btn-sm btn-delete" title="Delete title" onclick="return confirm('Delete this title from catalog?');"><i class="fas fa-times"></i></button>
                    </form>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <div style="overflow-x:auto;">
        <table class="users-table">
            <thead>
                <tr>
                    <th>Employee</th>
                    <th>Position</th>
                    <th>Department</th>
                    <th>Duties</th>
                    <th>Login</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($employees)): ?>
                <tr>
                    <td colspan="7" style="text-align:center; color:#888;">No employees found.</td>
                </tr>
                <?php else: ?>
                <?php foreach ($employees as $emp): ?>
                <tr>
                    <td data-label="Employee">
                        <strong><?php echo htmlspecialchars($emp['full_name']); ?></strong>
                        <?php if (!empty($emp['email'])): ?>
                            <br><small style="color:#888;"><?php echo htmlspecialchars($emp['email']); ?></small>
                        <?php endif; ?>
                    </td>
                    <td data-label="Position"><?php echo htmlspecialchars($emp['position_title']); ?></td>
                    <td data-label="Department"><?php echo htmlspecialchars($emp['department'] ?: '—'); ?></td>
                    <td data-label="Duties">
                        <?php
                            $priv = (string)($emp['privileges'] ?? '');
                            $shortPriv = mb_strlen($priv) > 80 ? mb_substr($priv, 0, 80) . '...' : $priv;
                            echo htmlspecialchars($shortPriv ?: '—');
                        ?>
                    </td>
                    <td data-label="Login">
                        <?php if (!empty($emp['login_user_id'])): ?>
                            <span class="status-badge active">
                                <i class="fas fa-circle"></i>
                                <?php echo htmlspecialchars(ucfirst((string)($emp['login_role'] ?? 'user'))); ?>
                            </span>
                        <?php else: ?>
                            <span class="status-badge inactive">
                                <i class="fas fa-circle"></i>
                                None
                            </span>
                        <?php endif; ?>
                    </td>
                    <td data-label="Status">
                        <span class="status-badge <?php echo $emp['is_active'] ? 'active' : 'inactive'; ?>">
                            <i class="fas fa-circle"></i>
                            <?php echo $emp['is_active'] ? 'Active' : 'Inactive'; ?>
                        </span>
                    </td>
                    <td data-label="Actions">
                        <div class="actions-cell">
                            <a href="maintenance.php?prefill_employee=<?php echo (int)$emp['id']; ?>" class="btn-sm btn-edit" title="Create maintenance task for this employee">
                                <i class="fas fa-tools"></i> Task
                            </a>
                            <button type="button" class="btn-sm btn-edit js-edit-employee" data-employee="<?php echo htmlspecialchars(json_encode($emp), ENT_QUOTES, 'UTF-8'); ?>">
                                <i class="fas fa-edit"></i> Edit
                            </button>
                            <button type="button" class="btn-sm btn-delete" onclick="confirmEmployeeDelete(<?php echo (int)$emp['id']; ?>, '<?php echo htmlspecialchars($emp['full_name'], ENT_QUOTES); ?>')">
                                <i class="fas fa-trash"></i>
                            </button>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    
    <!-- PERMISSIONS EDITOR -->
    <?php if ($editing_user && $editing_user['role'] !== 'admin'): ?>
    <form method="POST" id="permissionsForm">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
        <input type="hidden" name="action" value="save_permissions">
        <input type="hidden" name="user_id" value="<?php echo $editing_user['id']; ?>">
        
        <div class="permissions-panel">
            <div class="permissions-header">
                <h3><i class="fas fa-shield-alt"></i> Edit Permissions</h3>
                <div class="perm-user-info">
                    <span class="perm-user-name"><?php echo htmlspecialchars($editing_user['full_name']); ?></span>
                    <span class="role-badge <?php echo $editing_user['role']; ?>"><?php echo ucfirst($editing_user['role']); ?></span>
                </div>
            </div>
            <div class="permissions-body">
                
                <div class="quick-actions">
                    <button type="button" class="btn-select-all" onclick="selectAllPerms(true)">
                        <i class="fas fa-check-double"></i> Select All
                    </button>
                    <button type="button" class="btn-select-none" onclick="selectAllPerms(false)">
                        <i class="fas fa-times"></i> Deselect All
                    </button>
                    <button type="button" class="btn-select-defaults" onclick="selectDefaults()">
                        <i class="fas fa-undo"></i> Reset to Role Defaults
                    </button>
                </div>
                
                <?php foreach ($permission_categories as $cat_name => $cat_perms): ?>
                <div class="perm-category">
                    <h4 class="perm-category-title"><i class="fas fa-folder"></i> <?php echo htmlspecialchars($cat_name); ?></h4>
                    <div class="perm-grid">
                        <?php foreach ($cat_perms as $perm_key => $perm_info): ?>
                        <?php 
                            $is_checked = isset($editing_permissions[$perm_key]) && $editing_permissions[$perm_key];
                        ?>
                        <label class="perm-item <?php echo $is_checked ? 'checked' : ''; ?>" id="perm-label-<?php echo $perm_key; ?>">
                            <div class="perm-icon">
                                <i class="fas <?php echo htmlspecialchars($perm_info['icon']); ?>"></i>
                            </div>
                            <input type="checkbox" 
                                   name="permissions[]" 
                                   value="<?php echo htmlspecialchars($perm_key); ?>"
                                   <?php echo $is_checked ? 'checked' : ''; ?>
                                   onchange="togglePermItem(this)"
                                   data-default="<?php echo in_array($perm_key, getDefaultPermissionsForRole($editing_user['role'])) ? '1' : '0'; ?>">
                            <div class="perm-info">
                                <div class="perm-label"><?php echo htmlspecialchars($perm_info['label']); ?></div>
                                <div class="perm-desc"><?php echo htmlspecialchars($perm_info['description']); ?></div>
                            </div>
                        </label>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endforeach; ?>
                
                <div class="perm-actions">
                    <a href="user-management.php" class="btn-cancel">
                        <i class="fas fa-arrow-left"></i> Back to Users
                    </a>
                    <button type="submit" class="btn-save-perms">
                        <i class="fas fa-save"></i> Save Permissions
                    </button>
                </div>
            </div>
        </div>
    </form>
    <?php elseif ($editing_user && $editing_user['role'] === 'admin'): ?>
    <div class="permissions-panel" style="margin-top: 24px;">
        <div class="permissions-body" style="text-align: center; padding: 40px;">
            <i class="fas fa-crown" style="font-size: 48px; color: var(--gold, #c9a44a); margin-bottom: 16px;"></i>
            <h3 style="margin: 0 0 8px;">Admin Role</h3>
            <p style="color: #888; margin: 0;">Admin users have full access to all features. Their permissions cannot be restricted.</p>
            <a href="user-management.php" class="btn-cancel" style="margin-top: 20px;">
                <i class="fas fa-arrow-left"></i> Back to Users
            </a>
        </div>
    </div>
    <?php endif; ?>
    
</main>

<!-- ADD USER MODAL -->
<div class="modal-overlay" id="addUserModal-overlay" data-modal-overlay></div>
<div class="modal-wrapper modal-md" id="addUserModal" data-modal>
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
            <input type="hidden" name="action" value="add_user">
            
            <div class="modal-header">
                <h3><i class="fas fa-user-plus"></i> Add New User</h3>
                <button type="button" class="modal-close" data-modal-close>&times;</button>
            </div>
            <div class="modal-body">
                <div class="form-row">
                    <label for="add-fullname">Full Name</label>
                    <input type="text" id="add-fullname" name="full_name" required placeholder="e.g. Jane Banda">
                </div>
                <div class="form-row">
                    <label for="add-username">Username</label>
                    <input type="text" id="add-username" name="username" required placeholder="e.g. jane.b" pattern="[a-zA-Z0-9._-]+" title="Letters, numbers, dots, dashes, underscores only">
                </div>
                <div class="form-row">
                    <label for="add-email">Email</label>
                    <input type="email" id="add-email" name="email" required placeholder="e.g. jane@example.com">
                </div>
                <div class="form-row">
                    <label for="add-role">Role</label>
                    <select id="add-role" name="role">
                        <option value="receptionist">Receptionist</option>
                        <option value="manager">Manager</option>
                        <option value="admin">Administrator</option>
                    </select>
                    <div class="hint">Role determines default permissions. You can customize later.</div>
                </div>
                <div class="form-row">
                    <label for="add-password">Password</label>
                    <input type="password" id="add-password" name="password" required minlength="8" placeholder="Minimum 8 characters">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-cancel" data-modal-close>Cancel</button>
                <button type="submit" class="btn-save-perms">
                    <i class="fas fa-user-plus"></i> Create User
                </button>
            </div>
        </form>
    </div>
</div>

<!-- EDIT USER MODAL -->
<div class="modal-overlay" id="editUserModal-overlay" data-modal-overlay></div>
<div class="modal-wrapper modal-md" id="editUserModal" data-modal>
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
            <input type="hidden" name="action" value="update_user">
            <input type="hidden" name="user_id" id="edit-user-id">
            
            <div class="modal-header">
                <h3><i class="fas fa-user-edit"></i> Edit User</h3>
                <button type="button" class="modal-close" data-modal-close>&times;</button>
            </div>
            <div class="modal-body">
                <div class="form-row">
                    <label for="edit-fullname">Full Name</label>
                    <input type="text" id="edit-fullname" name="full_name" required>
                </div>
                <div class="form-row">
                    <label for="edit-email">Email</label>
                    <input type="email" id="edit-email" name="email" required>
                </div>
                <div class="form-row">
                    <label for="edit-role">Role</label>
                    <select id="edit-role" name="role">
                        <option value="receptionist">Receptionist</option>
                        <option value="manager">Manager</option>
                        <option value="admin">Administrator</option>
                    </select>
                </div>
                <div class="form-row">
                    <label>
                        <input type="checkbox" name="is_active" id="edit-active" value="1" style="width:auto; margin-right: 6px;">
                        Active Account
                    </label>
                </div>
                <div class="form-row">
                    <label for="edit-password">New Password <span style="font-weight:400; color:#888;">(leave blank to keep current)</span></label>
                    <input type="password" id="edit-password" name="new_password" minlength="8" placeholder="Leave blank to keep unchanged">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-cancel" data-modal-close>Cancel</button>
                <button type="submit" class="btn-save-perms">
                    <i class="fas fa-save"></i> Save Changes
                </button>
            </div>
        </form>
    </div>
</div>

<!-- DELETE FORM (hidden) -->
<form method="POST" id="deleteForm" style="display:none;">
    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
    <input type="hidden" name="action" value="delete_user">
    <input type="hidden" name="user_id" id="delete-user-id">
</form>

<!-- ADD EMPLOYEE MODAL -->
<div class="modal-overlay" id="addEmployeeModal-overlay" data-modal-overlay></div>
<div class="modal-wrapper modal-md" id="addEmployeeModal" data-modal>
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
            <input type="hidden" name="action" value="add_employee">

            <div class="modal-header">
                <h3><i class="fas fa-user-tie"></i> Add Employee</h3>
                <button type="button" class="modal-close" data-modal-close>&times;</button>
            </div>
            <div class="modal-body">
                <div class="form-row">
                    <label for="add-emp-name">Full Name</label>
                    <input type="text" id="add-emp-name" name="emp_full_name" required>
                </div>
                <div class="form-row">
                    <label for="add-emp-position">Position</label>
                    <select id="add-emp-position" name="emp_position_title" required>
                        <option value="">Select title</option>
                        <?php foreach ($employee_titles as $title): ?>
                            <option value="<?php echo htmlspecialchars($title['title_name']); ?>"><?php echo htmlspecialchars($title['title_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-row">
                    <label for="add-emp-dept">Department</label>
                    <input type="text" id="add-emp-dept" name="emp_department" placeholder="e.g. Front Office, Administration">
                </div>
                <div class="form-row">
                    <label for="add-emp-email">Email</label>
                    <input type="email" id="add-emp-email" name="emp_email">
                </div>
                <div class="form-row" style="background:#f8fafc; border:1px solid #e5e7eb; border-radius:10px; padding:12px;">
                    <label style="display:flex; align-items:center; gap:8px; margin:0 0 10px;">
                        <input type="checkbox" id="add-emp-create-login" name="emp_create_login" value="1" style="width:auto; margin:0;">
                        Create system login account for this employee
                    </label>
                    <label for="add-emp-login-role" style="margin-bottom:6px;">Login Role</label>
                    <select id="add-emp-login-role" name="emp_login_role">
                        <option value="receptionist" selected>Receptionist</option>
                        <option value="manager">Manager</option>
                        <option value="admin">Admin</option>
                    </select>
                    <small style="color:#666; display:block; margin-top:6px;">Requires a valid email. A temporary password will be emailed when the account is first created.</small>
                </div>
                <div class="form-row">
                    <label for="add-emp-phone">Phone</label>
                    <input type="text" id="add-emp-phone" name="emp_phone">
                </div>
                <div class="form-row">
                    <label for="add-emp-priv">Operational Duties</label>
                    <select id="add-emp-priv" name="emp_privileges" multiple style="min-height: 120px;">
                        <option value="Front Desk">Front Desk</option>
                        <option value="Check In-Out">Check In-Out</option>
                        <option value="Reservations">Reservations</option>
                        <option value="Cash Handling">Cash Handling</option>
                        <option value="Housekeeping">Housekeeping</option>
                        <option value="Maintenance">Maintenance</option>
                        <option value="Kitchen Service">Kitchen Service</option>
                        <option value="Security">Security</option>
                        <option value="Inventory">Inventory</option>
                        <option value="Guest Relations">Guest Relations</option>
                    </select>
                    <small style="color: #666; display: block; margin-top: 4px;">Hold Ctrl/Cmd to select multiple. These are duties, not system login permissions.</small>
                </div>
                <div class="form-row">
                    <label for="add-emp-notes">Notes</label>
                    <input type="text" id="add-emp-notes" name="emp_notes">
                </div>
                <div class="form-row">
                    <label>
                        <input type="checkbox" name="emp_is_active" value="1" checked style="width:auto; margin-right: 6px;">
                        Active Employee
                    </label>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-cancel" data-modal-close>Cancel</button>
                <button type="submit" class="btn-save-perms">
                    <i class="fas fa-save"></i> Save Employee
                </button>
            </div>
        </form>
    </div>
</div>

<!-- EDIT EMPLOYEE MODAL -->
<div class="modal-overlay" id="editEmployeeModal-overlay" data-modal-overlay></div>
<div class="modal-wrapper modal-md" id="editEmployeeModal" data-modal>
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
            <input type="hidden" name="action" value="update_employee">
            <input type="hidden" name="employee_id" id="edit-emp-id">

            <div class="modal-header">
                <h3><i class="fas fa-user-edit"></i> Edit Employee</h3>
                <button type="button" class="modal-close" data-modal-close>&times;</button>
            </div>
            <div class="modal-body">
                <div class="form-row">
                    <label for="edit-emp-name">Full Name</label>
                    <input type="text" id="edit-emp-name" name="emp_full_name" required>
                </div>
                <div class="form-row">
                    <label for="edit-emp-position">Position</label>
                    <select id="edit-emp-position" name="emp_position_title" required>
                        <option value="">Select title</option>
                        <?php foreach ($employee_titles as $title): ?>
                            <option value="<?php echo htmlspecialchars($title['title_name']); ?>"><?php echo htmlspecialchars($title['title_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-row">
                    <label for="edit-emp-dept">Department</label>
                    <input type="text" id="edit-emp-dept" name="emp_department">
                </div>
                <div class="form-row">
                    <label for="edit-emp-email">Email</label>
                    <input type="email" id="edit-emp-email" name="emp_email" required>
                </div>
                <div class="form-row" style="background:#f8fafc; border:1px solid #e5e7eb; border-radius:10px; padding:12px;">
                    <label style="display:flex; align-items:center; gap:8px; margin:0 0 10px;">
                        <input type="checkbox" id="edit-emp-create-login" name="emp_create_login" value="1" style="width:auto; margin:0;">
                        Create or sync system login account
                    </label>
                    <label for="edit-emp-login-role" style="margin-bottom:6px;">Login Role</label>
                    <select id="edit-emp-login-role" name="emp_login_role">
                        <option value="receptionist">Receptionist</option>
                        <option value="manager">Manager</option>
                        <option value="admin">Admin</option>
                    </select>
                    <small style="color:#666; display:block; margin-top:6px;">If an account exists for this email, it will be synchronized. If not, a new login will be created.</small>
                </div>
                <div class="form-row">
                    <label for="edit-emp-phone">Phone</label>
                    <input type="text" id="edit-emp-phone" name="emp_phone">
                </div>
                <div class="form-row">
                    <label for="edit-emp-priv">Operational Duties</label>
                    <select id="edit-emp-priv" name="emp_privileges" multiple style="min-height: 120px;">
                        <option value="Front Desk">Front Desk</option>
                        <option value="Check In-Out">Check In-Out</option>
                        <option value="Reservations">Reservations</option>
                        <option value="Cash Handling">Cash Handling</option>
                        <option value="Housekeeping">Housekeeping</option>
                        <option value="Maintenance">Maintenance</option>
                        <option value="Kitchen Service">Kitchen Service</option>
                        <option value="Security">Security</option>
                        <option value="Inventory">Inventory</option>
                        <option value="Guest Relations">Guest Relations</option>
                    </select>
                    <small style="color: #666; display: block; margin-top: 4px;">Hold Ctrl/Cmd to select multiple. These are duties, not system login permissions.</small>
                </div>
                <div class="form-row">
                    <label for="edit-emp-notes">Notes</label>
                    <textarea id="edit-emp-notes" name="emp_notes" style="resize: vertical;"></textarea>
                </div>
                <div class="form-row">
                    <label>
                        <input type="checkbox" name="emp_is_active" id="edit-emp-active" value="1" style="width:auto; margin-right: 6px;">
                        Active Employee
                    </label>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-cancel" data-modal-close>Cancel</button>
                <button type="submit" class="btn-save-perms">
                    <i class="fas fa-save"></i> Save Changes
                </button>
            </div>
        </form>
    </div>
</div>

<!-- DELETE EMPLOYEE FORM (hidden) -->
<form method="POST" id="deleteEmployeeForm" style="display:none;">
    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
    <input type="hidden" name="action" value="delete_employee">
    <input type="hidden" name="employee_id" id="delete-employee-id">
</form>

<script src="js/admin-components.js"></script>
<script>
function openEditModal(user) {
    document.getElementById('edit-user-id').value = user.id;
    document.getElementById('edit-fullname').value = user.full_name;
    document.getElementById('edit-email').value = user.email;
    document.getElementById('edit-role').value = user.role;
    document.getElementById('edit-active').checked = user.is_active == 1;
    document.getElementById('edit-password').value = '';
    Modal.open('editUserModal');
}

// Bind edit buttons (data-user JSON)
document.querySelectorAll('.js-edit-user').forEach(btn => {
    btn.addEventListener('click', function() {
        const raw = this.getAttribute('data-user');
        if (!raw) return;
        try {
            const user = JSON.parse(raw);
            openEditModal(user);
        } catch (e) {
            console.error('Failed to parse user data for edit modal.', e);
        }
    });
});

function confirmDelete(userId, userName) {
    if (confirm('Are you sure you want to delete user "' + userName + '"?\n\nThis action cannot be undone and will remove all their permissions.')) {
        document.getElementById('delete-user-id').value = userId;
        document.getElementById('deleteForm').submit();
    }
}

function openEmployeeEditModal(emp) {
    document.getElementById('edit-emp-id').value = emp.id;
    document.getElementById('edit-emp-name').value = emp.full_name || '';
    const positionSelect = document.getElementById('edit-emp-position');
    const targetPosition = emp.position_title || '';
    if (positionSelect && targetPosition) {
        const exists = Array.from(positionSelect.options).some(opt => opt.value === targetPosition);
        if (!exists) {
            const legacyOpt = document.createElement('option');
            legacyOpt.value = targetPosition;
            legacyOpt.textContent = targetPosition + ' (legacy)';
            positionSelect.appendChild(legacyOpt);
        }
        positionSelect.value = targetPosition;
    }
    document.getElementById('edit-emp-dept').value = emp.department || '';
    document.getElementById('edit-emp-email').value = emp.email || '';
    document.getElementById('edit-emp-phone').value = emp.phone || '';
    
    // Set multi-select privilege options
    const privSelect = document.getElementById('edit-emp-priv');
    const privArray = (emp.privileges || '').split(',').map(p => p.trim()).filter(p => p);
    Array.from(privSelect.options).forEach(opt => {
        opt.selected = privArray.includes(opt.value);
    });

    const hasLogin = !!emp.login_user_id;
    const loginToggle = document.getElementById('edit-emp-create-login');
    const loginRole = document.getElementById('edit-emp-login-role');
    if (loginToggle) {
        loginToggle.checked = hasLogin;
    }
    if (loginRole) {
        loginRole.value = emp.login_role || 'receptionist';
    }
    setLoginRoleState('edit-emp-create-login', 'edit-emp-login-role');
    
    document.getElementById('edit-emp-notes').value = emp.notes || '';
    document.getElementById('edit-emp-active').checked = emp.is_active == 1;
    Modal.open('editEmployeeModal');
}

document.querySelectorAll('.js-edit-employee').forEach(btn => {
    btn.addEventListener('click', function() {
        const raw = this.getAttribute('data-employee');
        if (!raw) return;
        try {
            const emp = JSON.parse(raw);
            openEmployeeEditModal(emp);
        } catch (e) {
            console.error('Failed to parse employee data for edit modal.', e);
        }
    });
});

function confirmEmployeeDelete(employeeId, employeeName) {
    if (confirm('Delete employee "' + employeeName + '"?\n\nThis action cannot be undone.')) {
        document.getElementById('delete-employee-id').value = employeeId;
        document.getElementById('deleteEmployeeForm').submit();
    }
}

function togglePermItem(checkbox) {
    const label = checkbox.closest('.perm-item');
    if (checkbox.checked) {
        label.classList.add('checked');
    } else {
        label.classList.remove('checked');
    }
}

function selectAllPerms(checked) {
    document.querySelectorAll('#permissionsForm input[type="checkbox"]').forEach(cb => {
        cb.checked = checked;
        togglePermItem(cb);
    });
}

function selectDefaults() {
    document.querySelectorAll('#permissionsForm input[type="checkbox"]').forEach(cb => {
        cb.checked = cb.dataset.default === '1';
        togglePermItem(cb);
    });
}

function setLoginRoleState(toggleId, selectId) {
    const toggle = document.getElementById(toggleId);
    const select = document.getElementById(selectId);
    if (!toggle || !select) return;
    select.disabled = !toggle.checked;
    select.style.opacity = toggle.checked ? '1' : '0.6';
}

const addLoginToggle = document.getElementById('add-emp-create-login');
if (addLoginToggle) {
    addLoginToggle.addEventListener('change', function () {
        setLoginRoleState('add-emp-create-login', 'add-emp-login-role');
    });
}

const editLoginToggle = document.getElementById('edit-emp-create-login');
if (editLoginToggle) {
    editLoginToggle.addEventListener('change', function () {
        setLoginRoleState('edit-emp-create-login', 'edit-emp-login-role');
    });
}

setLoginRoleState('add-emp-create-login', 'add-emp-login-role');
setLoginRoleState('edit-emp-create-login', 'edit-emp-login-role');

// Modal system is handled by admin-components.js
</script>

</body>
</html>
