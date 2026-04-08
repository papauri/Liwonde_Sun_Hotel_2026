<?php
/**
 * Maintenance & Room Service Helper
 * Manages task table creation, availability blocking, and schedule utilities.
 *
 * Include path (from admin pages): require_once '../includes/maintenance-helper.php';
 * Include path (from root pages):  require_once 'includes/maintenance-helper.php';
 */

if (!function_exists('ensureMaintenanceInfrastructure')) {
    function ensureMaintenanceInfrastructure(PDO $pdo): void
    {
        static $ready = false;
        if ($ready) return;
        $ready = true;

        $pdo->exec("CREATE TABLE IF NOT EXISTS `room_maintenance_tasks` (
            `id`                  int          NOT NULL AUTO_INCREMENT,
            `room_id`             int          DEFAULT NULL,
            `room_unit_id`        int          DEFAULT NULL,
            `task_type`           enum('maintenance','deep_cleaning','room_service','inspection','renovation','pest_control','other')
                                               NOT NULL DEFAULT 'maintenance',
            `title`               varchar(200) NOT NULL,
            `description`         text         DEFAULT NULL,
            `priority`            enum('low','medium','high','urgent') NOT NULL DEFAULT 'medium',
            `assigned_to`         int          DEFAULT NULL,
            `employee_id`         int          DEFAULT NULL,
            `assigned_to_name`    varchar(150) DEFAULT NULL,
            `scheduled_start`     datetime     NOT NULL,
            `scheduled_end`       datetime     NOT NULL,
            `actual_start`        datetime     DEFAULT NULL,
            `actual_end`          datetime     DEFAULT NULL,
            `status`              enum('pending','in_progress','completed','cancelled') NOT NULL DEFAULT 'pending',
            `blocks_availability` tinyint(1)   NOT NULL DEFAULT 1,
            `recurrence_group_id` varchar(36)  DEFAULT NULL,
            `notes`               text         DEFAULT NULL,
            `created_by`          int          DEFAULT NULL,
            `created_at`          timestamp    NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at`          timestamp    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_maint_room_id`     (`room_id`),
            KEY `idx_maint_room_unit`   (`room_unit_id`),
            KEY `idx_maint_status`      (`status`),
            KEY `idx_maint_scheduled`   (`scheduled_start`),
            KEY `idx_maint_assigned_to` (`assigned_to`),
            KEY `idx_maint_employee_id` (`employee_id`),
            KEY `idx_maint_recurrence`  (`recurrence_group_id`(8))
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // Add assigned_to_name column to existing tables that predate this column
        try {
            $pdo->exec("ALTER TABLE `room_maintenance_tasks`
                ADD COLUMN IF NOT EXISTS `room_unit_id` int DEFAULT NULL
                AFTER `room_id`");
        } catch (\PDOException $e) {
            $needsAdd = function_exists('hasTableColumn') ? !hasTableColumn('room_maintenance_tasks', 'room_unit_id') : true;
            if ($needsAdd) {
                try {
                    $pdo->exec("ALTER TABLE `room_maintenance_tasks` ADD COLUMN `room_unit_id` int DEFAULT NULL AFTER `room_id`");
                } catch (\PDOException $inner) {
                    // Ignore duplicate-column style failures.
                }
            }
        }

        try {
            $pdo->exec("ALTER TABLE `room_maintenance_tasks`
                ADD INDEX `idx_maint_room_unit` (`room_unit_id`)");
        } catch (\PDOException $e) {
            // Index already exists.
        }

        try {
            $pdo->exec("ALTER TABLE `room_maintenance_tasks`
                ADD COLUMN IF NOT EXISTS `assigned_to_name` varchar(150) DEFAULT NULL
                AFTER `assigned_to`");
        } catch (\PDOException $e) {
            // Column already exists or DB doesn't support IF NOT EXISTS — safe to ignore
        }

        try {
            $pdo->exec("ALTER TABLE `room_maintenance_tasks`
                ADD COLUMN IF NOT EXISTS `employee_id` int DEFAULT NULL
                AFTER `assigned_to`");
        } catch (\PDOException $e) {
            // Column already exists or DB doesn't support IF NOT EXISTS — safe to ignore
        }

        try {
            $pdo->exec("ALTER TABLE `room_maintenance_tasks`
                ADD INDEX `idx_maint_employee_id` (`employee_id`)");
        } catch (\PDOException $e) {
            // Index already exists.
        }
    }
}

/**
 * Sync room_blocked_dates for a maintenance task.
 * - Removes all existing blocked rows tied to this task.
 * - Re-inserts one row per day for the scheduled range when the task
 *   is active (blocks_availability=1, status is pending or in_progress).
 */
if (!function_exists('syncMaintenanceBlockedDates')) {
    function syncMaintenanceBlockedDates(
        PDO    $pdo,
        int    $taskId,
        ?int   $roomId,
        ?int   $roomUnitId,
        string $scheduledStart,
        string $scheduledEnd,
        bool   $blocksAvailability,
        string $status
    ): void {
        // Always remove old blocks for this task first
        $pdo->prepare("DELETE FROM room_blocked_dates WHERE reason = ?")
            ->execute(["maintenance_task:{$taskId}"]);

        // Only re-insert when task is active
        if (!$blocksAvailability || in_array($status, ['completed', 'cancelled'], true)) {
            return;
        }

        $startDate = new DateTime((new DateTime($scheduledStart))->format('Y-m-d'));
        $endDt     = new DateTime($scheduledEnd);
        $endDate   = new DateTime($endDt->format('Y-m-d'));

        // If end time is exactly midnight, don't block that day
        if ($endDt->format('H:i:s') === '00:00:00') {
            $endDate->modify('-1 day');
        }

        if ($startDate > $endDate) {
            return;
        }

        $stmt = $pdo->prepare(
              "INSERT INTO room_blocked_dates (room_id, room_unit_id, block_date, block_type, reason)
               VALUES (?, ?, ?, 'maintenance', ?)"
        );

        $current = clone $startDate;
        while ($current <= $endDate) {
            $stmt->execute([$roomId, $roomUnitId, $current->format('Y-m-d'), "maintenance_task:{$taskId}"]);
            $current->modify('+1 day');
        }
    }
}

/**
 * Remove all room_blocked_dates rows that belong to a specific maintenance task.
 */
if (!function_exists('removeMaintenanceBlockedDates')) {
    function removeMaintenanceBlockedDates(PDO $pdo, int $taskId): void
    {
        $pdo->prepare("DELETE FROM room_blocked_dates WHERE reason = ?")
            ->execute(["maintenance_task:{$taskId}"]);
    }
}

/**
 * Return summary counts for the maintenance dashboard cards.
 */
if (!function_exists('getMaintenanceSummary')) {
    function getMaintenanceSummary(PDO $pdo): array
    {
        $defaults = ['today' => 0, 'overdue' => 0, 'upcoming' => 0, 'in_progress' => 0, 'pending' => 0];
        try {
            $today   = date('Y-m-d');
            $weekEnd = date('Y-m-d', strtotime('+7 days'));

            $stmt = $pdo->query("
                SELECT
                    SUM(DATE(scheduled_start) = '{$today}'
                        AND status IN ('pending','in_progress'))              AS today_count,
                    SUM(scheduled_end < NOW()
                        AND status IN ('pending','in_progress'))              AS overdue_count,
                    SUM(DATE(scheduled_start) > '{$today}'
                        AND DATE(scheduled_start) <= '{$weekEnd}'
                        AND status = 'pending')                              AS upcoming_count,
                    SUM(status = 'in_progress')                              AS in_progress_count,
                    SUM(status = 'pending')                                  AS pending_count
                FROM room_maintenance_tasks
            ");
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                return [
                    'today'       => (int)($row['today_count']      ?? 0),
                    'overdue'     => (int)($row['overdue_count']     ?? 0),
                    'upcoming'    => (int)($row['upcoming_count']    ?? 0),
                    'in_progress' => (int)($row['in_progress_count'] ?? 0),
                    'pending'     => (int)($row['pending_count']     ?? 0),
                ];
            }
        } catch (PDOException $e) {
            // Table may not be ready yet
        }
        return $defaults;
    }
}

/**
 * Generate a RFC-4122 v4 UUID for recurring task group IDs.
 */
if (!function_exists('generateMaintenanceGroupId')) {
    function generateMaintenanceGroupId(): string
    {
        $bytes    = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}

/**
 * Sanitize a maintenance input string.
 */
if (!function_exists('maintenanceSanitize')) {
    function maintenanceSanitize($value, int $maxLength = 200): string
    {
        return mb_substr(trim(strip_tags((string)$value)), 0, $maxLength);
    }
}