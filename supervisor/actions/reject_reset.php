<?php
// Reject password reset request - SIMPLIFIED VERSION
error_reporting(0);
ini_set('display_errors', 0);
ob_start();

session_start();
header('Content-Type: application/json');
ob_clean();

try {
    require_once __DIR__ . '/../../config/database.php';
} catch (Throwable $e) {
    ob_clean();
    echo json_encode(['success' => false, 'error' => 'DB load failed']);
    exit;
}

// Check session
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'supervisor') {
    ob_clean();
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

// Check method
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ob_clean();
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

// Get input
$input = json_decode(file_get_contents('php://input'), true);
$requestId = (int)($input['id'] ?? 0);

if ($requestId <= 0) {
    ob_clean();
    echo json_encode(['success' => false, 'error' => 'Invalid ID']);
    exit;
}

try {
    $pdo = Database::getInstance()->getConnection();
    
    // Check if request exists and is pending
    $stmt = $pdo->prepare("SELECT id, user_id FROM password_resets WHERE id = ? AND status = 'pending' LIMIT 1");
    $stmt->execute([$requestId]);
    $request = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$request) {
        ob_clean();
        echo json_encode(['success' => false, 'error' => 'Permintaan tidak ditemukan atau sudah diproses']);
        exit;
    }
    
    // Update to rejected
    $updateStmt = $pdo->prepare("
        UPDATE password_resets 
        SET status = 'rejected', 
            approved_by = ?, 
            approved_at = NOW()
        WHERE id = ?
    ");
    $updateStmt->execute([$_SESSION['user_id'], $requestId]);
    
    // Get user name for response
    $userStmt = $pdo->prepare("SELECT name FROM users WHERE id = ?");
    $userStmt->execute([$request['user_id']]);
    $user = $userStmt->fetch(PDO::FETCH_ASSOC);
    
    ob_clean();
    echo json_encode([
        'success' => true,
        'message' => 'Permintaan ditolak',
        'name' => $user['name'] ?? 'User'
    ]);
    
} catch (Throwable $e) {
    ob_clean();
    echo json_encode(['success' => false, 'error' => 'Error: ' . $e->getMessage()]);
}
