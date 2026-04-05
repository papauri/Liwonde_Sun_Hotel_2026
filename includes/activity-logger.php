<?php

if (!function_exists('ensureActivityLogInfrastructure')) {
    function ensureActivityLogInfrastructure(PDO $pdo): void
    {
        static $ready = false;
        if ($ready) {
            return;
        }

        $pdo->exec("CREATE TABLE IF NOT EXISTS admin_activity_log (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NULL,
            username VARCHAR(100) NULL,
            action VARCHAR(50) NOT NULL,
            details TEXT NULL,
            ip_address VARCHAR(45) NULL,
            user_agent VARCHAR(500) NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_user_id (user_id),
            INDEX idx_action (action),
            INDEX idx_created_at (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $pdo->exec("CREATE TABLE IF NOT EXISTS employee_activity_log (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            employee_id INT UNSIGNED NULL,
            admin_user_id INT UNSIGNED NULL,
            actor_user_id INT UNSIGNED NULL,
            action VARCHAR(60) NOT NULL,
            details TEXT NULL,
            source VARCHAR(60) NULL,
            ip_address VARCHAR(45) NULL,
            user_agent VARCHAR(500) NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_employee_id (employee_id),
            INDEX idx_admin_user_id (admin_user_id),
            INDEX idx_actor_user_id (actor_user_id),
            INDEX idx_action (action),
            INDEX idx_created_at (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $ready = true;
    }
}

if (!function_exists('resolveEmployeeForAdminUser')) {
    function resolveEmployeeForAdminUser(PDO $pdo, int $adminUserId): ?array
    {
        if ($adminUserId <= 0) {
            return null;
        }

        $stmt = $pdo->prepare("SELECT e.id AS employee_id, au.id AS admin_user_id
            FROM admin_users au
            LEFT JOIN employees e ON e.email = au.email
            WHERE au.id = ?
            LIMIT 1");
        $stmt->execute([$adminUserId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return null;
        }

        return [
            'employee_id' => isset($row['employee_id']) ? (int)$row['employee_id'] : 0,
            'admin_user_id' => (int)$row['admin_user_id'],
        ];
    }
}

if (!function_exists('logAdminActivity')) {
    function logAdminActivity(PDO $pdo, ?int $userId, ?string $username, string $action, string $details = '', ?string $ipAddress = null, ?string $userAgent = null): void
    {
        ensureActivityLogInfrastructure($pdo);

        $stmt = $pdo->prepare("INSERT INTO admin_activity_log
            (user_id, username, action, details, ip_address, user_agent)
            VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $userId && $userId > 0 ? $userId : null,
            $username,
            $action,
            $details !== '' ? $details : null,
            $ipAddress,
            $userAgent ? mb_substr($userAgent, 0, 500) : null,
        ]);
    }
}

if (!function_exists('logEmployeeActivity')) {
    function logEmployeeActivity(PDO $pdo, ?int $employeeId, ?int $adminUserId, ?int $actorUserId, string $action, string $details = '', ?string $source = null, ?string $ipAddress = null, ?string $userAgent = null): void
    {
        ensureActivityLogInfrastructure($pdo);

        $stmt = $pdo->prepare("INSERT INTO employee_activity_log
            (employee_id, admin_user_id, actor_user_id, action, details, source, ip_address, user_agent)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $employeeId && $employeeId > 0 ? $employeeId : null,
            $adminUserId && $adminUserId > 0 ? $adminUserId : null,
            $actorUserId && $actorUserId > 0 ? $actorUserId : null,
            $action,
            $details !== '' ? $details : null,
            $source,
            $ipAddress,
            $userAgent ? mb_substr($userAgent, 0, 500) : null,
        ]);
    }
}
