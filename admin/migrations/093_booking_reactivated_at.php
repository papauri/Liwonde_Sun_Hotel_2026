<?php

/**
 * 093 - reactivate an expired booking (owner 2026-11-29: "re-activating an expired automatic booking if the room and
 * dates are available" + "get re-priced at today's rate").
 *
 *   bookings.reactivated_at   when staff last put this expired booking back to pending (NULL = never)
 *
 * The pending-expiry clock (_expireStalePendingBookings, config/database.php) runs from the later of created_at and
 * reactivated_at, so a reactivated booking gets a fresh hold window. created_at is never rewritten.
 *
 * Additive only: the column is added only when missing, no rows are touched (default NULL), re-running is safe.
 * Until this runs the Reactivate button is hidden, the action answers "Run migration 093 first." and the expiry
 * sweep keeps using created_at exactly as before.
 */

$rhReactivatedHas = static function (PDO $pdo): bool {
    $st = $pdo->prepare(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bookings' AND COLUMN_NAME = 'reactivated_at'"
    );
    $st->execute();
    return (int)$st->fetchColumn() > 0;
};

return [
    'name' => 'booking_reactivated_at',

    'check' => function (PDO $pdo) use ($rhReactivatedHas): bool {
        return $rhReactivatedHas($pdo);
    },

    'up' => function (PDO $pdo) use ($rhReactivatedHas): void {
        if ($rhReactivatedHas($pdo)) {
            return;
        }
        $pdo->exec("ALTER TABLE `bookings` ADD COLUMN `reactivated_at` DATETIME NULL DEFAULT NULL COMMENT 'When staff last reactivated this expired booking (restarts the pending hold clock)'");
        echo "    bookings.reactivated_at: added\n";
    },
];
