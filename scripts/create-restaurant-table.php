<?php
require 'config/database.php';

try {
    $sql = file_get_contents('Database/migrations/001_create_restaurant_inquiries_table.sql');
    $pdo->exec($sql);
    echo 'restaurant_inquiries table created successfully!' . PHP_EOL;
} catch (PDOException $e) {
    if (strpos($e->getMessage(), 'already exists') !== false) {
        echo 'Table already exists, checking structure...' . PHP_EOL;
        $result = $pdo->query('DESCRIBE restaurant_inquiries');
        $columns = $result->fetchAll(PDO::FETCH_ASSOC);
        echo 'Columns found: ' . count($columns) . PHP_EOL;
        foreach ($columns as $col) {
            echo '  - ' . $col['Field'] . ' (' . $col['Type'] . ')' . PHP_EOL;
        }
    } else {
        echo 'Error: ' . $e->getMessage() . PHP_EOL;
    }
}
?>
