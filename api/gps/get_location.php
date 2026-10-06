<?php
session_start();
require_once '../../config/database.php';
require_once '../../helpers/session.php';

// Hanya untuk admin/supervisor
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'supervisor'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Access denied']);
    exit;
}

try {
    $pdo = Database::getInstance()->getConnection();
    
    $user_id = isset($_GET['user_id']) ? (int)$_GET['user_id'] : null;
    
    if ($user_id) {
        // Ambil lokasi user tertentu
        $stmt = $pdo->prepare("
            SELECT 
                u.id,
                u.name,
                u.nip,
                u.status,
                u.latitude,
                u.longitude,
                u.last_location_update,
                TIMESTAMPDIFF(SECOND, u.last_location_update, NOW()) as seconds_ago,
                CASE 
                    WHEN u.last_location_update > NOW() - INTERVAL 5 MINUTE THEN 'online'
                    WHEN u.last_location_update > NOW() - INTERVAL 30 MINUTE THEN 'recent'
                    ELSE 'offline'
                END as online_status
            FROM users u
            WHERE u.id = :user_id
            AND u.role = 'petugas'
        ");
        
        $stmt->execute([':user_id' => $user_id]);
        $location = $stmt->fetch();
        
        if (!$location) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'User not found']);
            exit;
        }
        
        if (!empty($location['latitude']) && !empty($location['longitude'])) {
            $location['latitude'] = (float)$location['latitude'];
            $location['longitude'] = (float)$location['longitude'];
        }
        
        echo json_encode([
            'success' => true,
            'location' => $location
        ]);
        
    } else {
        // Ambil semua petugas dengan lokasi terkini
        // Logic filter berdasarkan role
        $role = $_SESSION['role'] ?? '';
        $currentUserId = $_SESSION['user_id'] ?? 0;
        
        $sql = "
            SELECT 
                u.id,
                u.name,
                u.nip,
                u.role,
                u.status,
                u.latitude,
                u.longitude,
                u.last_location_update,
                TIMESTAMPDIFF(SECOND, u.last_location_update, NOW()) as seconds_ago,
                CASE 
                    WHEN u.last_location_update > NOW() - INTERVAL 5 MINUTE THEN 'online'
                    WHEN u.last_location_update > NOW() - INTERVAL 30 MINUTE THEN 'recent'
                    ELSE 'offline'
                END as online_status,
                -- Zone for petugas from schedules, or for supervisor maybe null or default
                (SELECT z.name FROM schedules s JOIN zones z ON s.zone_id = z.id 
                 WHERE s.user_id = u.id AND s.work_date = CURRENT_DATE LIMIT 1) as current_zone
            FROM users u
            WHERE u.status = 'online'
            AND u.latitude IS NOT NULL AND u.longitude IS NOT NULL
        ";
        
        $params = [];
        $role = $_SESSION['role'] ?? '';
        
        if ($role === 'supervisor') {
             // Supervisor only sees Petugas
             $sql .= " AND u.role = 'petugas' ";
             
             // Ambil group_id supervisor
             $supStmt = $pdo->prepare("SELECT group_id FROM users WHERE id = ?");
             $supStmt->execute([$currentUserId]);
             $sup = $supStmt->fetch();
             $groupId = $sup['group_id'] ?? 0;
             
             $sql .= " AND (u.group_id = :group_id OR u.supervisor_id = :sup_id)";
             $params[':group_id'] = $groupId;
             $params[':sup_id'] = $currentUserId;
        } else {
             // Admin sees EVERYONE (Petugas + Supervisor)
             $sql .= " AND u.role IN ('petugas', 'supervisor') ";
        }
        
        $sql .= " ORDER BY u.last_location_update DESC";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        
        $locations = $stmt->fetchAll();
        
        // Group berdasarkan status online
        $online = [];
        $recent = [];
        $offline = [];
        
        foreach ($locations as &$loc) {
            if (!empty($loc['latitude']) && !empty($loc['longitude'])) {
                $loc['latitude'] = (float)$loc['latitude'];
                $loc['longitude'] = (float)$loc['longitude'];
            }
            
            if ($loc['online_status'] === 'online') {
                $online[] = $loc;
            } elseif ($loc['online_status'] === 'recent') {
                $recent[] = $loc;
            } else {
                $offline[] = $loc;
            }
        }
        
        echo json_encode([
            'success' => true,
            'summary' => [
                'total' => count($locations),
                'online' => count($online),
                'recent' => count($recent),
                'offline' => count($offline)
            ],
            'locations' => [
                'online' => $online,
                'recent' => $recent,
                'offline' => $offline
            ]
        ]);
    }
    
} catch (Exception $e) {
    error_log("Get Location Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Internal server error']);
}
?>
