<?php
session_start();
require_once '../../config/database.php';
require_once '../../helpers/session.php';

// Hanya untuk petugas dan supervisor
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['petugas', 'supervisor'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Access denied']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

// Accept both JSON and form data
if (isset($_SERVER['CONTENT_TYPE']) && strpos($_SERVER['CONTENT_TYPE'], 'application/json') !== false) {
    $data = json_decode(file_get_contents('php://input'), true);
} else {
    $data = $_POST;
}

// Validasi input
if (!isset($data['latitude']) || !isset($data['longitude'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing latitude or longitude']);
    exit;
}

try {
    $pdo = Database::getInstance()->getConnection();
    
    // Pastikan tabel history ada sebelum operasi lain
    createLocationHistoryTable($pdo);
    
    $user_id = $_SESSION['user_id'];
    $latitude = (float)$data['latitude'];
    $longitude = (float)$data['longitude'];
    $accuracy = isset($data['accuracy']) ? (float)$data['accuracy'] : 0;
    $altitude = isset($data['altitude']) ? (float)$data['altitude'] : null;
    $heading = isset($data['heading']) ? (float)$data['heading'] : null;
    $speed = isset($data['speed']) ? (float)$data['speed'] : null;
    $timestamp = isset($data['timestamp']) ? $data['timestamp'] : date('Y-m-d H:i:s');
    
    // Validasi koordinat
    if ($latitude < -90 || $latitude > 90 || $longitude < -180 || $longitude > 180) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid coordinates']);
        exit;
    }
    
    // Cek apakah user sedang online (4-status system)
    $userStmt = $pdo->prepare("SELECT status FROM users WHERE id = :user_id");
    $userStmt->execute([':user_id' => $user_id]);
    $user = $userStmt->fetch();
    
    if (!$user || $user['status'] !== 'online') {
        $statusMsg = match($user['status'] ?? 'unknown') {
            'offline' => 'Anda sedang offline. Tekan tombol Start untuk memulai.',
            'nonaktif' => 'Akun Anda nonaktif. Hubungi Admin.',
            'cuti' => 'Anda sedang cuti.',
            default => 'Status tidak valid.'
        };
        echo json_encode([
            'success' => false, 
            'error' => $statusMsg,
            'user_status' => $user['status'] ?? 'unknown'
        ]);
        exit;
    }
    
    // Update lokasi user
    $updateStmt = $pdo->prepare("
        UPDATE users 
        SET latitude = :lat,
            longitude = :lng,
            last_location_update = NOW(),
            updated_at = NOW()
        WHERE id = :user_id
    ");
    
    $updateStmt->execute([
        ':lat' => $latitude,
        ':lng' => $longitude,
        ':user_id' => $user_id
    ]);
    
    // Cek apakah ada laporan aktif untuk hari ini
    $reportStmt = $pdo->prepare("
        SELECT id FROM reports 
        WHERE user_id = :user_id 
        AND work_date = CURRENT_DATE
        AND status = 'pending'
        ORDER BY created_at DESC
        LIMIT 1
    ");
    
    $reportStmt->execute([':user_id' => $user_id]);
    $active_report = $reportStmt->fetch();
    
    $route_saved = false;
    
    if ($active_report) {
        // Simpan route point
        $routeStmt = $pdo->prepare("
            INSERT INTO report_route_points 
            (report_id, seq, point_type, latitude, longitude, recorded_at)
            VALUES (
                :report_id,
                COALESCE((SELECT MAX(seq) FROM report_route_points WHERE report_id = :report_id2), 0) + 1,
                'other',
                :lat,
                :lng,
                NOW()
            )
        ");
        
        $routeStmt->execute([
            ':report_id' => $active_report['id'],
            ':report_id2' => $active_report['id'],
            ':lat' => $latitude,
            ':lng' => $longitude
        ]);
        
        $route_saved = true;
    }
    
    // Ambil zona terdekat (hanya untuk info, tidak untuk validasi)
    $zoneStmt = $pdo->prepare("
        SELECT 
            s.id as schedule_id,
            z.id as zone_id,
            z.name as zone_name
        FROM schedules s
        JOIN zones z ON s.zone_id = z.id
        WHERE s.user_id = :user_id
        AND s.work_date = CURRENT_DATE
        AND s.status = 'active'
        LIMIT 1
    ");
    
    $zoneStmt->execute([
        ':user_id' => $user_id
    ]);
    
    $assigned_zone = $zoneStmt->fetch(PDO::FETCH_ASSOC);
    
    // DEBUG: Count schedules for today (safe query)
    $debugCount = 0;
    $debugDate = '';
    try {
        $countStmt = $pdo->prepare("
            SELECT COUNT(*) as total, CURRENT_DATE as today
            FROM schedules 
            WHERE user_id = :user_id 
            AND work_date = CURRENT_DATE
        ");
        $countStmt->execute([':user_id' => $user_id]);
        $countResult = $countStmt->fetch(PDO::FETCH_ASSOC);
        $debugCount = $countResult['total'] ?? 0;
        $debugDate = $countResult['today'] ?? '';
    } catch (Exception $e) {
        error_log("Debug count error: " . $e->getMessage());
    }
    
    $zone_info = null;

    if ($assigned_zone) {
        $zone_info = [
            'zone_id' => $assigned_zone['zone_id'],
            'zone_name' => $assigned_zone['zone_name'],
            'schedule_id' => $assigned_zone['schedule_id']
        ];
    }
    
    // Simpan history lokasi (opsional, untuk analytics)
    $historyStmt = $pdo->prepare("
        INSERT INTO location_history 
        (user_id, latitude, longitude, accuracy, altitude, heading, speed, recorded_at)
        VALUES (:user_id, :lat, :lng, :accuracy, :altitude, :heading, :speed, NOW())
    ");
    
    $historyStmt->execute([
        ':user_id' => $user_id,
        ':lat' => $latitude,
        ':lng' => $longitude,
        ':accuracy' => $accuracy,
        ':altitude' => $altitude,
        ':heading' => $heading,
        ':speed' => $speed
    ]);
    
    $response = [
        'success' => true,
        'timestamp' => date('Y-m-d H:i:s'),
        'location' => [
            'latitude' => $latitude,
            'longitude' => $longitude,
            'accuracy' => $accuracy
        ],
        'route_saved' => $route_saved,
        'active_report' => $active_report ? $active_report['id'] : null,
        'nearest_zone' => $zone_info,
        'battery_info' => isset($data['battery']) ? $data['battery'] : null,
        'device_info' => isset($data['device']) ? $data['device'] : null,
        'debug_info' => [
            'user_id' => $user_id,
            'schedule_count_today' => $debugCount,
            'server_date' => $debugDate
        ]
    ];
    
    if ($accuracy > 100) {
        $response['warning'] = 'Akurasi GPS rendah (' . round($accuracy) . 'm). Pastikan sinyal GPS baik.';
    }
    
    header('Content-Type: application/json');
    $json_output = json_encode($response);

    if ($json_output === false) {
        error_log('json_encode error: ' . json_last_error_msg());
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Internal server error: Failed to encode JSON response.']);
    } else {
        echo $json_output;
    }
    
} catch (Throwable $e) {
    error_log("GPS Update Location Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Error: ' . $e->getMessage()]);
}

/**
 * Buat tabel history lokasi jika belum ada
 */
function createLocationHistoryTable($pdo) {
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS location_history (
                id BIGINT PRIMARY KEY AUTO_INCREMENT,
                user_id BIGINT NOT NULL,
                latitude DOUBLE NOT NULL,
                longitude DOUBLE NOT NULL,
                accuracy DOUBLE,
                altitude DOUBLE,
                heading DOUBLE,
                speed DOUBLE,
                recorded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_location_history_user (user_id),
                INDEX idx_location_history_recorded (recorded_at),
                CONSTRAINT fk_location_history_user FOREIGN KEY (user_id) REFERENCES users(id)
            ) ENGINE=InnoDB;
        ");
        
    } catch (Throwable $e) {
        // Table mungkin sudah ada, atau ada error lain.
        // Log error untuk debugging di masa depan.
        error_log("Error creating location_history table: " . $e->getMessage());
    }
}
?>
