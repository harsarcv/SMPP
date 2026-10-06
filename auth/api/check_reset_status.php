<?php
// API endpoint to check password reset status
header('Content-Type: application/json');

require_once '../../config/database.php';

$token = trim($_GET['token'] ?? '');

if ($token === '') {
    echo json_encode(['status' => 'invalid', 'error' => 'Token required']);
    exit;
}

try {
    $pdo = Database::getInstance()->getConnection();
    
    $stmt = $pdo->prepare("SELECT status FROM password_resets WHERE request_token = ? LIMIT 1");
    $stmt->execute([$token]);
    $request = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$request) {
        echo json_encode(['status' => 'not_found']);
    } else {
        echo json_encode(['status' => $request['status']]);
    }
} catch (Throwable $e) {
    echo json_encode(['status' => 'error', 'error' => $e->getMessage()]);
}
