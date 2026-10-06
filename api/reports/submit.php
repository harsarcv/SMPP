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

// Check if it's multipart form data
if (empty($_FILES) || !isset($_POST['schedule_id'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing required data']);
    exit;
}

try {
    $user_id = $_SESSION['user_id'];
    $schedule_id = (int)$_POST['schedule_id'];
    
    $data = [
        'schedule_id' => $schedule_id
    ];
    
    $files = [
        'photo_before' => $_FILES['photo_before'] ?? null,
        'photo_after' => $_FILES['photo_after'] ?? null
    ];
    
    require_once '../../petugas/controllers/PetugasController.php';
    $controller = new PetugasController();
    $result = $controller->submitLaporan($user_id, $data, $files);
    
    echo json_encode($result);
    
} catch (Exception $e) {
    error_log("Report Submit API Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Internal server error']);
}
?>