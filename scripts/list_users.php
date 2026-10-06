<?php
require_once __DIR__ . '/../config/database.php';
$pdo = Database::getInstance()->getConnection();
$stmt = $pdo->query("SELECT id, name, role FROM users WHERE role='petugas' LIMIT 10");
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "<h3>List Petugas</h3>";
echo "<table border='1' cellpadding='5' cellspacing='0'>";
echo "<tr><th>ID</th><th>Name</th><th>Role</th></tr>";
foreach($rows as $r) {
    echo "<tr>";
    echo "<td>{$r['id']}</td>";
    echo "<td>{$r['name']}</td>";
    echo "<td>{$r['role']}</td>";
    echo "</tr>";
}
echo "</table>";
echo "<p>Use ID above in simulate_movement.php?id=YOUR_ID</p>";
