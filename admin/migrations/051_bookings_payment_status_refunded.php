<?php

/**
 * 051 - bookings.payment_status accepts 'refunded' (additive).
 *
 * Cancelling a paid booking with a full refund sets payment_status = 'refunded'; if the column is an
 * ENUM without that value the write stores '' (or errors in strict mode). Values are only ADDED.
 */

return [
    'name' => 'bookings_payment_status_refunded',

    'check' => function (PDO $pdo): bool {
        $st = $pdo->prepare("SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bookings' AND COLUMN_NAME = 'payment_status'");
        $st->execute();
        $type = (string)$st->fetchColumn();
        return $type === '' || stripos($type, 'enum(') !== 0 || strpos($type, "'refunded'") !== false;
    },

    'up' => function (PDO $pdo): void {
        $st = $pdo->prepare("SELECT COLUMN_TYPE, COLUMN_DEFAULT, IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bookings' AND COLUMN_NAME = 'payment_status'");
        $st->execute();
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row || stripos((string)$row['COLUMN_TYPE'], 'enum(') !== 0) {
            return;
        }
        preg_match_all("/'((?:[^']|'')*)'/", $row['COLUMN_TYPE'], $m);
        $values = $m[1] ?? [];
        if (!in_array('refunded', $values, true)) {
            $values[] = 'refunded';
        }
        $list = implode(',', array_map(static function (string $v): string {
            return "'" . $v . "'";
        }, $values));
        $null = $row['IS_NULLABLE'] === 'YES' ? 'NULL' : 'NOT NULL';
        $def = $row['COLUMN_DEFAULT'] !== null ? ' DEFAULT ' . $pdo->quote($row['COLUMN_DEFAULT']) : ($row['IS_NULLABLE'] === 'YES' ? ' DEFAULT NULL' : '');
        $pdo->exec("ALTER TABLE bookings MODIFY COLUMN payment_status ENUM($list) $null$def");
    },
];
