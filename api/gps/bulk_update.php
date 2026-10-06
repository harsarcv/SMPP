<?php
session_start();
require_once '../../config/database.php';
require_once '../../helpers/session.php';

// Hanya untuk petugas
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'petugas') {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Access denied']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

// Accept JSON data
$data = json_decode(file_get_contents('php://input'), true);

if (!isset($data['locations']) || !is_array($data['locations'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing locations array']);
    exit;
}

try {
    $pdo = Database::getInstance()->getConnection();
    $user_id = $_SESSION['user_id'];
    
    // Cek apakah user aktif
    $userStmt = $pdo->prepare("SELECT status FROM users WHERE id = :user_id");
    $userStmt->execute([':user_id' => $user_id]);
    $user = $userStmt->fetch();
    
    if (!$user || $user['status'] !== 'aktif') {
        echo json_encode([
            'success' => false, 
            'error' => 'Status tidak aktif',
            'user_status' => $user['status'] ?? 'unknown'
        ]);
        exit;
    }
    
    $success_count = 0;
    $errors = [];
    
    // Ambil laporan aktif untuk hari ini
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
    
    foreach ($data['locations'] as $index => $location) {
        if (!isset($location['latitude']) || !isset($location['longitude'])) {
            $errors[] = "Location {$index}: Missing coordinates";
            continue;
        }
        
        $latitude = (float)$location['latitude'];
        $longitude = (float)$location['longitude'];
        $timestamp = isset($location['timestamp']) ? $location['timestamp'] : date('Y-m-d H:i:s');
        
        // Validasi koordinat
        if ($latitude < -90 || $latitude > 90 || $longitude < -180 || $longitude > 180) {
            $errors[] = "Location {$index}: Invalid coordinates";
            continue;
        }
        
        try {
            // Update lokasi terakhir
            if ($index === count($data['locations']) - 1) {
                // Hanya update lokasi terakhir ke tabel users
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
            }
            
            // Simpan ke history
            $historyStmt = $pdo->prepare("
                INSERT INTO location_history 
                (user_id, latitude, longitude, accuracy, recorded_at)
                VALUES (:user_id, :lat, :lng, :accuracy, :recorded_at)
            ");
            
            $historyStmt->execute([
                ':user_id' => $user_id,
                ':lat' => $latitude,
                ':lng' => $longitude,
                ':accuracy' => $location['accuracy'] ?? 0,
                ':recorded_at' => $timestamp
            ]);
            
            // Simpan route point jika ada laporan aktif
            if ($active_report) {
                $routeStmt = $pdo->prepare("
                    INSERT INTO report_route_points 
                    (report_id, seq, point_type, latitude, longitude, recorded_at)
                    VALUES (
                        :report_id,
                        COALESCE((SELECT MAX(seq) FROM report_route_points WHERE report_id = :report_id2), 0) + 1,
                        'other',
                        :lat,
                        :lng,
                        :recorded_at
                    )
                ");
                
                $routeStmt->execute([
                    ':report_id' => $active_report['id'],
                    ':report_id2' => $active_report['id'],
                    ':lat' => $latitude,
                    ':lng' => $longitude,
                    ':recorded_at' => $timestamp
                ]);
            }
            
            $success_count++;
            
        } catch (Exception $e) {
            $errors[] = "Location {$index}: " . $e->getMessage();
        }
    }
    
    header('Content-Type: application/json');
    
    echo json_encode([
        'success' => true,
        'processed' => $success_count,
        'errors' => $errors,
        'report_id' => $active_report['id'] ?? null,
        'ai_analysis' => (function() use ($data) {
            try {
                // Siapkan payload AI jika data cukup
                if (!isset($data['locations']) || count($data['locations']) < 3) {
                    return null;
                }
                $points = [];
                foreach ($data['locations'] as $loc) {
                    if (isset($loc['latitude'], $loc['longitude'])) {
                        $points[] = [
                            'latitude' => (float)$loc['latitude'],
                            'longitude' => (float)$loc['longitude'],
                            'timestamp' => isset($loc['timestamp']) ? (string)$loc['timestamp'] : date('c')
                        ];
                    }
                }
                if (count($points) < 3) return null;
                $payload = json_encode(['locations' => $points]);
                return callAiService('/gps/analyze', $payload);
            } catch (\Throwable $e) { return null; }
        })()
    ]);
    
} catch (Exception $e) {
    header('Content-Type: application/json');
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
    exit;
}

/**
 * Panggil layanan AI FastAPI secara aman
 */
function callAiService($path, $jsonPayload, $timeout = 2.0)
{
    $base = 'http://127.0.0.1:8000';
    $url = $base . $path;
    
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonPayload);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        $res = curl_exec($ch);
        curl_close($ch);
        if ($res) {
            $decoded = json_decode($res, true);
            if (is_array($decoded)) return $decoded;
        }
        return null;
    }
    
    $ctx = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/json\r\n",
            'content' => $jsonPayload,
            'timeout' => $timeout,
        ]
    ]);
    $res = @file_get_contents($url, false, $ctx);
    if ($res) {
        $decoded = json_decode($res, true);
        if (is_array($decoded)) return $decoded;
    }
    return null;
}
