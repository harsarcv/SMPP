<?php
session_start();

if (isset($_SESSION['user_id'])) {
    // Log logout activity
    require_once '../config/database.php';
    try {
        $pdo = Database::getInstance()->getConnection();
        $stmt = $pdo->prepare("
            INSERT INTO activity_logs (user_id, activity, ip_address) 
            VALUES (:user_id, 'Logout dari sistem', :ip)
        ");
        $stmt->execute([
            ':user_id' => $_SESSION['user_id'],
            ':ip' => $_SERVER['REMOTE_ADDR']
        ]);
    } catch (Exception $e) {
        error_log("Logout log error: " . $e->getMessage());
    }
}

// Hapus semua session
$_SESSION = array();

// Hapus cookie session
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

session_destroy();

// Redirect ke login
header('Location: login.php?success=logged_out');
exit;
?>