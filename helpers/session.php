<?php
if (!function_exists('get_base_url')) {
    function get_base_url(): string {
        if (isset($_SERVER['DOCUMENT_ROOT'])) {
            $docRoot = rtrim(str_replace('\\', '/', realpath($_SERVER['DOCUMENT_ROOT']) ?: $_SERVER['DOCUMENT_ROOT']), '/');
            $appRoot = rtrim(str_replace('\\', '/', realpath(dirname(__DIR__))), '/');
            if ($docRoot !== '' && strpos($appRoot, $docRoot) === 0) {
                $subDir = substr($appRoot, strlen($docRoot));
                return rtrim($subDir, '/');
            }
        }
        if (isset($_SERVER['REQUEST_URI']) && strpos($_SERVER['REQUEST_URI'], '/PPSU') === 0) {
            return '/PPSU';
        }
        if (isset($_SERVER['SCRIPT_NAME']) && strpos($_SERVER['SCRIPT_NAME'], '/PPSU') === 0) {
            return '/PPSU';
        }
        return '';
    }
}

function enforce_session($allowed_roles = []) {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    
    $base = get_base_url();

    // Cek apakah user sudah login
    if (!isset($_SESSION['user_id']) || !isset($_SESSION['role'])) {
        header('Location: ' . $base . '/auth/login.php');
        exit;
    }
    
    // Cek role jika ada spesifikasi
    if (!empty($allowed_roles) && !in_array($_SESSION['role'], $allowed_roles)) {
        // Redirect ke dashboard sesuai role
        if ($_SESSION['role'] === 'admin') {
            header('Location: ' . $base . '/admin/views/dashboard.php');
        } elseif ($_SESSION['role'] === 'supervisor') {
            header('Location: ' . $base . '/supervisor/views/dashboard.php');
        } elseif ($_SESSION['role'] === 'petugas') {
            header('Location: ' . $base . '/petugas/views/dashboard.php');
        } else {
            header('Location: ' . $base . '/auth/login.php');
        }
        exit;
    }
    
    // Perbarui session timeout (30 menit)
    if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > 1800)) {
        session_unset();
        session_destroy();
        header('Location: ' . $base . '/auth/login.php?timeout=1');
        exit;
    }
    $_SESSION['last_activity'] = time();
}

function require_login() {
    if (!isset($_SESSION['user_id'])) {
        header('Location: ' . get_base_url() . '/auth/login.php');
        exit;
    }
}

function require_role($role) {
    if (!isset($_SESSION['role']) || $_SESSION['role'] !== $role) {
        http_response_code(403);
        die("Access denied. Required role: " . $role);
    }
}

function require_active_user() {
    if (!isset($_SESSION['user_id'])) {
        return;
    }
    try {
        if (!class_exists('Database')) {
            require_once __DIR__ . '/../config/database.php';
        }
        $pdo = Database::getInstance()->getConnection();
        $stmt = $pdo->prepare("SELECT status FROM users WHERE id = :id");
        $stmt->execute([':id' => $_SESSION['user_id']]);
        $user = $stmt->fetch();
        
        // 4-Status System with backward compatibility:
        // Allow: 'aktif' (old), 'online', 'offline' (new)
        // Block: 'nonaktif', 'cuti'
        $allowedStatuses = ['aktif', 'online', 'offline'];
        if (!$user || !in_array($user['status'], $allowedStatuses)) {
            session_destroy();
            $errorType = ($user && $user['status'] === 'cuti') ? 'account_on_leave' : 'account_inactive';
            header("Location: " . get_base_url() . "/auth/login.php?error={$errorType}");
            exit;
        }
    } catch (Throwable $e) {
        error_log("Error checking user status: " . $e->getMessage());
    }
}

function generate_csrf_token() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function issue_session_token($user_id) {
    $_SESSION['session_token'] = bin2hex(random_bytes(32));
    $_SESSION['session_token_issued_at'] = time();
}

function validate_csrf_token($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

function sanitize_input($data) {
    $data = trim($data);
    $data = stripslashes($data);
    $data = htmlspecialchars($data);
    return $data;
}

if (!function_exists('safeText')) {
    function safeText($text) {
        return htmlspecialchars($text ?? '', ENT_QUOTES, 'UTF-8');
    }
}

function is_ajax_request() {
    return isset($_SERVER['HTTP_X_REQUESTED_WITH']) && 
           strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
}

function json_response($data, $status = 200) {
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

function check_permission($required_permission) {
    // Implementasi permission checking jika diperlukan
    return true;
}
