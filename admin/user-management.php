<?php
// Include admin initialization (PHP-only, no HTML output)
require_once 'admin-init.php';
// permissions.php already loaded by admin-init.php

// Only admin role can access user management
if ($user['role'] !== 'admin') {
    header('Location: dashboard.php?error=access_denied');
    exit;
}

$site_name = getSetting('site_name');
$success_msg = '';
$error_msg = '';

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
                        }
                    } else {
                        $stmt = $pdo->prepare("UPDATE admin_users SET full_name = ?, email = ?, role = ?, is_active = ? WHERE id = ?");
                        $stmt->execute([$full_name, $email, $role, $is_active, $uid]);
                        $success_msg = "User updated successfully.";
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
                $check_role = $pdo->prepare("SELECT role FROM admin_users WHERE id = ?");
                $check_role->execute([$uid]);
                $target_role = $check_role->fetchColumn();
                
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
                $check_role = $pdo->prepare("SELECT role FROM admin_users WHERE id = ?");
                $check_role->execute([$uid]);
                $target_role = $check_role->fetchColumn();
                
                if ($target_role === 'admin' && $admin_count <= 1) {
                    $error_msg = 'Cannot delete the last admin user.';
                } else {
                    $stmt = $pdo->prepare("DELETE FROM admin_users WHERE id = ?");
                    $stmt->execute([$uid]);
                    $success_msg = "User deleted successfully.";
                }
            }
        }
    }
}

// Fetch all users
$users_stmt = $pdo->query("SELECT * FROM admin_users ORDER BY role ASC, full_name ASC");
$all_users = $users_stmt->fetchAll(PDO::FETCH_ASSOC);

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
            align-items: center;
            padding: 20px;
        }
        .modal-overlay.active {
            display: flex;
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
        .form-row select {
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
        .form-row select:focus {
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
        <button class="btn-add" onclick="Modal.open('addUserModal')">
            <i class="fas fa-user-plus"></i> Add New User
        </button>
    </div>
    
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

// Modal system is handled by admin-components.js
</script>

</body>
</html>
