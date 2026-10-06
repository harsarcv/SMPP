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

$report_id = isset($_GET['report_id']) ? (int)$_GET['report_id'] : 0;
$user_id = isset($_GET['user_id']) ? (int)$_GET['user_id'] : 0;
$date = isset($_GET['date']) ? $_GET['date'] : date('Y-m-d');

try {
    $pdo = Database::getInstance()->getConnection();
    
    if ($report_id > 0) {
        // Ambil route untuk laporan tertentu
        $stmt = $pdo->prepare("
            SELECT 
                rp.id,
                rp.seq,
                rp.point_type,
                rp.location AS location_json,
                rp.recorded_at,
                DATE_FORMAT(rp.recorded_at, '%H:%i:%s') as time_formatted
            FROM report_route_points rp
            WHERE rp.report_id = :report_id
            ORDER BY rp.seq ASC
        ");
        
        $stmt->execute([':report_id' => $report_id]);
        $route_points_raw = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $route_points = [];
        foreach ($route_points_raw as $row) {
            $geo = is_string($row['location_json']) ? json_decode($row['location_json'], true) : $row['location_json'];
            $lng = null;
            $lat = null;
            if (is_array($geo) && isset($geo['type'], $geo['coordinates']) && $geo['type'] === 'Point') {
                $lng = (float)$geo['coordinates'][0];
                $lat = (float)$geo['coordinates'][1];
            }
            $route_points[] = [
                'id' => $row['id'],
                'seq' => $row['seq'],
                'point_type' => $row['point_type'],
                'longitude' => $lng,
                'latitude' => $lat,
                'recorded_at' => $row['recorded_at'],
                'time_formatted' => $row['time_formatted'],
            ];
        }
        
        // Ambil info laporan
        $reportStmt = $pdo->prepare("
            SELECT 
                r.id,
                u.name as petugas_name,
                z.name as zone_name,
                r.gps_result,
                r.created_at
            FROM reports r
            JOIN users u ON r.user_id = u.id
            JOIN zones z ON r.zone_id = z.id
            WHERE r.id = :report_id
        ");
        
        $reportStmt->execute([':report_id' => $report_id]);
        $report_info = $reportStmt->fetch();
        
        echo json_encode([
            'success' => true,
            'report_info' => $report_info,
            'route_points' => $route_points,
            'total_points' => count($route_points)
        ]);
        
    } elseif ($user_id > 0) {
        // Ambil route untuk user pada tanggal tertentu
        $stmt = $pdo->prepare("
            SELECT 
                rp.id,
                rp.report_id,
                rp.seq,
                rp.point_type,
                rp.latitude,
                rp.longitude,
                rp.recorded_at,
                DATE_FORMAT(rp.recorded_at, '%H:%i:%s') as time_formatted,
                z.name as zone_name
            FROM report_route_points rp
            JOIN reports r ON rp.report_id = r.id
            JOIN zones z ON r.zone_id = z.id
            WHERE r.user_id = :user_id
            AND r.work_date = :work_date
            ORDER BY rp.recorded_at ASC
        ");
        
        $stmt->execute([
            ':user_id' => $user_id,
            ':work_date' => $date
        ]);
        
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $route_points = [];
        foreach ($rows as $row) {
            $route_points[] = [
                'id' => $row['id'],
                'report_id' => $row['report_id'],
                'seq' => $row['seq'],
                'point_type' => $row['point_type'],
                'longitude' => (float)$row['longitude'],
                'latitude' => (float)$row['latitude'],
                'recorded_at' => $row['recorded_at'],
                'time_formatted' => $row['time_formatted'],
                'zone_name' => $row['zone_name'],
            ];
        }
        
        // Group by report
        $routes_by_report = [];
        foreach ($route_points as $point) {
            $report_id = $point['report_id'];
            if (!isset($routes_by_report[$report_id])) {
                $routes_by_report[$report_id] = [
                    'report_id' => $report_id,
                    'zone_name' => $point['zone_name'],
                    'points' => []
                ];
            }
            $routes_by_report[$report_id]['points'][] = $point;
        }
        
        echo json_encode([
            'success' => true,
            'date' => $date,
            'user_id' => $user_id,
            'routes_by_report' => array_values($routes_by_report),
            'total_points' => count($route_points)
        ]);
        
    } else {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Report ID or User ID required']);
    }
    
} catch (Exception $e) {
    error_log("Get Route Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Internal server error']);
}
?>
