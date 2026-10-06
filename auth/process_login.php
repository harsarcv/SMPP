<?php
session_start();
require_once '../config/database.php';
require_once '../helpers/session.php';

function redirect_by_role(string $role): string {
    $base = function_exists('get_base_url') ? get_base_url() : '';
    switch ($role) {
        case 'admin':
            return $base . '/admin/views/dashboard.php';
        case 'supervisor':
            return $base . '/supervisor/views/dashboard.php';
        case 'petugas':
            return $base . '/petugas/views/dashboard.php';
        default:
            return $base . '/auth/login.php';
    }
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: login.php');
    exit;
}

$username = trim((string)($_POST['username'] ?? ''));
$password = (string)($_POST['password'] ?? '');

if ($username === '' || $password === '') {
    header('Location: login.php?error=invalid_credentials');
    exit;
}

try {
    $pdo = Database::getInstance()->getConnection();

    $stmt = $pdo->prepare(
        "SELECT id, nip, name, username, password_hash, role, status FROM users WHERE username = ? LIMIT 1"
    );
    $stmt->execute([$username]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        header('Location: login.php?error=user_not_found');
        exit;
    }
    if (!password_verify($password, $user['password_hash'])) {
        header('Location: login.php?error=wrong_password');
        exit;
    }
    // 4-Status System with backward compatibility:
    // Allow: 'aktif' (old), 'online', 'offline' (new)
    // Block: 'nonaktif', 'cuti'
    $allowedLoginStatuses = ['aktif', 'online', 'offline'];
    if (!in_array($user['status'], $allowedLoginStatuses)) {
        $errorType = ($user['status'] === 'cuti') ? 'account_on_leave' : 'account_inactive';
        header("Location: login.php?error={$errorType}");
        exit;
    }

    $up = $pdo->prepare("UPDATE users SET last_login = NOW(), updated_at = NOW() WHERE id = ?");
    $up->execute([$user['id']]);

    $resetStmt = $pdo->prepare(
        "SELECT id FROM password_resets WHERE user_id = ? AND status = 'approved' ORDER BY created_at DESC LIMIT 1"
    );
    $resetStmt->execute([$user['id']]);
    $reset = $resetStmt->fetch(PDO::FETCH_ASSOC);

    session_regenerate_id(true);

    $_SESSION['user_id'] = (int)$user['id'];
    $_SESSION['name']    = $user['name'];
    $_SESSION['nip']     = $user['nip'];
    $_SESSION['role']    = $user['role'];
    $_SESSION['last_activity'] = time();

    if ($reset) {
        $_SESSION['force_change_password'] = true;
        $_SESSION['reset_id'] = (int)$reset['id'];
        header('Location: confirm-password.php');
        exit;
    }

    issue_session_token((int)$user['id']);

    try {
        $log = $pdo->prepare("INSERT INTO activity_logs (user_id, activity, ip_address) VALUES (:user_id, :activity, :ip)");
        $log->execute([
            ':user_id' => (int)$user['id'],
            ':activity' => 'Login ke sistem',
            ':ip' => $_SERVER['REMOTE_ADDR'] ?? ''
        ]);
    } catch (Throwable $e) {}

    header('Location: ' . redirect_by_role($user['role']));
    exit;

} catch (Throwable $e) {
    header('Location: login.php?error=system_error');
    exit;
}
?>
