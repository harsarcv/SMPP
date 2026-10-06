<?php
require_once '../../config/database.php';
try {
    $pdo = Database::getInstance()->getConnection();
    $stmt = $pdo->query("DESCRIBE schedules");
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo "<pre>";
    print_r($columns);
    echo "</pre>";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
