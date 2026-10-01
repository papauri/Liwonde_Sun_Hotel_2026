<?php

/**
 * 002 — add `bookings.cancellation_retained_amount` (and `bookings.credit_balance` when
 * missing on this installation). Additive only.
 *
 * Cancelling a booking no longer rewrites its original totals: the room charge kept
 * after cancellation (first night under 'keep_first_night', 0/NULL when the bill is
 * voided) is stored here and billed by recalculateBookingFinancials() for cancelled
 * bookings. credit_balance holds money owed back to the guest.
 */

return [
    'name' => 'add_bookings_cancellation_retained_amount',

    'check' => function (PDO $pdo): bool {
        $a = $pdo->query("SHOW COLUMNS FROM bookings LIKE 'cancellation_retained_amount'")->rowCount() > 0;
        $b = $pdo->query("SHOW COLUMNS FROM bookings LIKE 'credit_balance'")->rowCount() > 0;
        return $a && $b;
    },

    'up' => function (PDO $pdo): void {
        if ($pdo->query("SHOW COLUMNS FROM bookings LIKE 'credit_balance'")->rowCount() === 0) {
            $pdo->exec("ALTER TABLE bookings ADD COLUMN credit_balance DECIMAL(10,2) NOT NULL DEFAULT 0.00 COMMENT 'Amount owed back to the guest (overpayment / shortened stay). Settled via refund or credit note.'");
        }
        if ($pdo->query("SHOW COLUMNS FROM bookings LIKE 'cancellation_retained_amount'")->rowCount() === 0) {
            $pdo->exec("ALTER TABLE bookings ADD COLUMN cancellation_retained_amount DECIMAL(12,2) NULL DEFAULT NULL COMMENT 'Room charge retained on cancellation (NULL/0 = bill voided). Used as the room bill when status = cancelled.'");
        }
    },
];
