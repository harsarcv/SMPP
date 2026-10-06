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

if (!isset($_FILES['photo'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'No file uploaded']);
    exit;
}

try {
    $file = $_FILES['photo'];
    $user_id = $_SESSION['user_id'];
    $type = $_POST['type'] ?? 'unknown';
    
    // Validasi file
    $allowed = ['jpg', 'jpeg', 'png'];
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    
    if (!in_array($ext, $allowed)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid file type']);
        exit;
    }
    
    if ($file['size'] > 5 * 1024 * 1024) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'File too large']);
        exit;
    }
    
    // Upload directory
    $uploadDir = __DIR__ . '/../../public/uploads/temp/';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0775, true);
    }
    
    // Generate filename
    $filename = "temp_{$type}_{$user_id}_" . time() . '_' . mt_rand(1000, 9999) . '.' . $ext;
    $destination = $uploadDir . $filename;
    
    // Move uploaded file
    if (move_uploaded_file($file['tmp_name'], $destination)) {
        echo json_encode([
            'success' => true,
            'filename' => $filename,
            'url' => '/public/uploads/temp/' . $filename
        ]);
    } else {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Failed to save file']);
    }
    
} catch (Exception $e) {
    error_log("Photo Upload API Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Internal server error']);
}
?>
