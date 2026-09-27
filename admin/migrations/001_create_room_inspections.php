<?php
/**
 * 001 — Create `room_inspections`.
 *
 * Why this exists
 * ---------------
 * `createRoomInspection()` in includes/room-management.php creates this table
 * lazily, in app code, the first time a room is moved to `inspection` status.
 * That is exactly the self-migrating behaviour the locked-schema rail forbids,
 * and it could never fire until the `updateRoomStatus()` deadlock was fixed on
 * 2026-08-15 — so the table has never actually been created.
 *
 * It also would not have worked. The lazy DDL declares:
 *
 *     individual_room_id INT NOT NULL,
 *     FOREIGN KEY (individual_room_id) REFERENCES individual_rooms(id)
 *
 * but `individual_rooms.id` is `INT UNSIGNED`. MySQL 8 rejects a foreign key
 * between incompatible column types (errno 3780), so the CREATE would have
 * thrown, been swallowed by the catch block in createRoomInspection(), and
 * surfaced to staff only as "inspection could not be created".
 *
 * This migration creates the table with the correct unsigned types and the
 * column set the surrounding code actually reads/writes.
 */

declare(strict_types=1);

return [
    'name' => 'create_room_inspections',

    'up' => function (PDO $pdo): string {
        $exists = (int)$pdo->query(
            "SELECT COUNT(*) FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'room_inspections'"
        )->fetchColumn() > 0;

        if ($exists) {
            return 'already present, no change';
        }

        $pdo->exec("
            CREATE TABLE room_inspections (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                individual_room_id INT UNSIGNED NOT NULL,
                status ENUM('pending','passed','failed') NOT NULL DEFAULT 'pending',
                inspector_id INT UNSIGNED DEFAULT NULL,
                checklist JSON DEFAULT NULL,
                notes TEXT NULL,
                inspected_at DATETIME DEFAULT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_room_inspections_room (individual_room_id),
                KEY idx_room_inspections_status (status),
                CONSTRAINT fk_room_inspections_room
                    FOREIGN KEY (individual_room_id) REFERENCES individual_rooms(id)
                    ON DELETE CASCADE,
                CONSTRAINT fk_room_inspections_inspector
                    FOREIGN KEY (inspector_id) REFERENCES admin_users(id)
                    ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        return 'created room_inspections (INT UNSIGNED FKs to individual_rooms, admin_users)';
    },
];
