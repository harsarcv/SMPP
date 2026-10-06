<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
session_start();
require_once __DIR__ . '/../config/database.php';

try {
    $pdo = Database::getInstance()->getConnection();
    
    // Optional scope: hanya zona yang ditugaskan hari ini untuk petugas
    $assignedOnly = isset($_GET['assigned']) && ($_GET['assigned'] === '1' || strtolower($_GET['assigned']) === 'true');
    $role = $_SESSION['role'] ?? null;
    $userId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
    
    if ($assignedOnly && $role === 'petugas' && $userId > 0) {
        $sql = "
            SELECT 
                z.id,
                z.zone_code,
                z.rw_code,
                z.name,
                z.address,
                NULL AS geometry,
                z.created_at,
                z.updated_at
            FROM zones z
            JOIN schedules s ON s.zone_id = z.id
            WHERE s.user_id = :uid
              AND s.work_date = CURRENT_DATE
              AND s.status = 'active'
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':uid' => $userId]);
    } else {
        // Default: semua zona
        $sql = "
            SELECT 
                id,
                zone_code,
                rw_code,
                name,
                address,
                NULL AS geometry,
                created_at,
                updated_at
            FROM zones
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute();
    }
    
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Format ke GeoJSON
    $features = [];
    foreach ($results as $row) {
        $geometry = is_string($row['geometry']) ? json_decode($row['geometry'], true) : $row['geometry'];
        
        $features[] = [
            'type' => 'Feature',
            'geometry' => $geometry,
            'properties' => [
                'id' => $row['id'],
                'zone_code' => $row['zone_code'],
                'rw_code' => $row['rw_code'],
                'name' => $row['name'],
                'address' => $row['address'],
                'created_at' => $row['created_at'],
                'updated_at' => $row['updated_at']
            ]
        ];
    }
    
    echo json_encode([
        'type' => 'FeatureCollection',
        'features' => $features
    ]);
    
} catch(PDOException $e) {
    echo json_encode(['error' => $e->getMessage()]);
}
?>
