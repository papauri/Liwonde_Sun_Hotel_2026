<?php
require 'config/database.php';
$result = $pdo->query('DESCRIBE restaurant_inquiries');
$columns = $result->fetchAll(PDO::FETCH_ASSOC);
echo 'Restaurant Inquiries Table Structure:' . PHP_EOL;
foreach ($columns as $col) {
    echo $col['Field'] . ' | ' . $col['Type'] . PHP_EOL;
}
echo PHP_EOL . 'Table created successfully!';
?>
