<?php
/**
 * Employee Helper
 * Ensures employee infrastructure exists and can be reused in admin pages.
 */

if (!function_exists('ensureEmployeeInfrastructure')) {
    function ensureEmployeeInfrastructure(PDO $pdo): void
    {
        static $ready = false;
        if ($ready) return;
        $ready = true;

        $pdo->exec("CREATE TABLE IF NOT EXISTS `employees` (
            `id` int NOT NULL AUTO_INCREMENT,
            `full_name` varchar(180) NOT NULL,
            `email` varchar(255) DEFAULT NULL,
            `phone` varchar(50) DEFAULT NULL,
            `position_title` varchar(120) NOT NULL,
            `department` varchar(120) DEFAULT NULL,
            `privileges` text DEFAULT NULL COMMENT 'JSON array or text privileges',
            `notes` text DEFAULT NULL,
            `is_active` tinyint(1) NOT NULL DEFAULT 1,
            `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_employees_active` (`is_active`),
            KEY `idx_employees_position` (`position_title`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // Add optional employee assignment support on maintenance tasks.
        try {
            $pdo->exec("ALTER TABLE `room_maintenance_tasks`
                ADD COLUMN IF NOT EXISTS `employee_id` int DEFAULT NULL
                AFTER `assigned_to`");
        } catch (PDOException $e) {
            // Safe fallback if DB version doesn't support IF NOT EXISTS.
            try {
                $pdo->exec("ALTER TABLE `room_maintenance_tasks` ADD COLUMN `employee_id` int DEFAULT NULL AFTER `assigned_to`");
            } catch (PDOException $ignored) {
            }
        }

        try {
            $pdo->exec("ALTER TABLE `room_maintenance_tasks`
                ADD INDEX `idx_maint_employee_id` (`employee_id`)");
        } catch (PDOException $e) {
            // Index likely already exists.
        }
    }
}
