<?php
session_start();
require_once '../config/database.php';
require_once '../helpers/session.php';
require_once '../helpers/AIService.php';

if (!function_exists('safeText')) {
    function safeText($text) {
        return htmlspecialchars($text ?? '', ENT_QUOTES, 'UTF-8');
    }
}

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

// Jika sudah login, langsung redirect
if (isset($_SESSION['user_id'], $_SESSION['role'])) {
    header('Location: ' . redirect_by_role($_SESSION['role']));
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim((string)($_POST['username'] ?? ''));
    $password = (string)($_POST['password'] ?? '');

    if ($username === '' || $password === '') {
        $error = 'Username dan Password wajib diisi.';
    } else {
        try {
            $pdo = Database::getInstance()->getConnection();

            $stmt = $pdo->prepare("
                SELECT id, nip, name, username, password_hash, role, status
                FROM users
                WHERE username = ? OR name = ?
                LIMIT 1
            ");
            $stmt->execute([$username, $username]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$user) {
                $error = 'Username tidak ditemukan.';
            } else {
                $isValid = password_verify($password, $user['password_hash']);
                if (!$isValid && preg_match('/^[0-9a-f]{64}$/i', (string)$user['password_hash'])) {
                    if (hash('sha256', $password) === $user['password_hash']) {
                        $isValid = true;
                        $newHash = password_hash($password, PASSWORD_DEFAULT);
                        $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ?")->execute([$newHash, $user['id']]);
                    }
                }
                
                if (!$isValid) {
                    $error = 'Password salah.';
                } else {
                    $role = (string)$user['role'];
                    $status = (string)$user['status'];

                    // 4-Status System with backward compatibility:
                    // Allow: 'aktif' (old), 'online', 'offline' (new)
                    // Block: 'nonaktif', 'cuti'
                    $allowedStatuses = ['aktif', 'online', 'offline'];
                    if (!in_array($status, $allowedStatuses)) {
                        $error = ($status === 'cuti') 
                            ? 'Akun Anda sedang cuti. Hubungi Admin.' 
                            : 'Akun Anda tidak aktif. Hubungi Admin.';
                    } elseif ($role === 'petugas') {
                    // Untuk PETUGAS: gunakan AI untuk validasi KPI
                        $kpiStmt = $pdo->prepare("
                            SELECT score_total FROM kpi_scores
                            WHERE user_id = ? ORDER BY calculated_at DESC LIMIT 1
                        ");
                        $kpiStmt->execute([$user['id']]);
                        $kpi = $kpiStmt->fetch(PDO::FETCH_ASSOC);
                        
                        // Default: izinkan login jika belum ada data KPI
                        $canLogin = true;
                        $aiMessage = '';
                        
                        if ($kpi) {
                            // Gunakan AI untuk validasi KPI
                            $aiService = new AIService();
                            $aiResult = $aiService->validateKPIForLogin((float)$kpi['score_total']);
                            
                            if ($aiResult !== null) {
                                // AI merespon - gunakan keputusan AI
                                $canLogin = $aiResult['can_login'] ?? true;
                                $aiMessage = $aiResult['message'] ?? '';
                            } else {
                                // AI tidak tersedia - fallback ke pengecekan manual
                                if ((float)$kpi['score_total'] < 70) {
                                    $canLogin = false;
                                    $aiMessage = 'KPI di bawah 70 (Skor: ' . $kpi['score_total'] . ')';
                                }
                            }
                        }
                        
                        // Jika AI memutuskan BLOKIR
                        if (!$canLogin) {
                            // Nonaktifkan akun
                            $pdo->prepare("UPDATE users SET status = 'nonaktif' WHERE id = ?")->execute([$user['id']]);
                            $error = 'Akun dinonaktifkan: ' . $aiMessage . '. Hubungi Supervisor/Admin untuk aktivasi ulang.';
                        } else {
                            // KPI OK atau belum ada data KPI, izinkan login
                            $up = $pdo->prepare("UPDATE users SET last_login = NOW() WHERE id = ?");
                            $up->execute([$user['id']]);

                            // Cek reset password aktif
                            $resetStmt = $pdo->prepare("
                                SELECT id FROM password_resets
                                WHERE user_id = ? AND status = 'approved'
                                ORDER BY created_at DESC LIMIT 1
                            ");
                            $resetStmt->execute([$user['id']]);
                            $reset = $resetStmt->fetch(PDO::FETCH_ASSOC);

                            session_regenerate_id(true);
                            $_SESSION['user_id'] = (int)$user['id'];
                            $_SESSION['name']    = $user['name'];
                            $_SESSION['nip']     = $user['nip'];
                            $_SESSION['role']    = $role;
                            $_SESSION['status']  = $status;

                            if ($reset) {
                                $_SESSION['force_change_password'] = true;
                                $_SESSION['reset_id'] = (int)$reset['id'];
                                header('Location: confirm-password.php');
                                exit;
                            }

                            issue_session_token((int)$user['id']);
                            header('Location: ' . redirect_by_role($role));
                            exit;
                        }
                    } else {
                        // Admin/Supervisor dengan status aktif
                        $up = $pdo->prepare("UPDATE users SET last_login = NOW() WHERE id = ?");
                        $up->execute([$user['id']]);

                        session_regenerate_id(true);
                        $_SESSION['user_id'] = (int)$user['id'];
                        $_SESSION['name']    = $user['name'];
                        $_SESSION['nip']     = $user['nip'];
                        $_SESSION['role']    = $role;
                        $_SESSION['status']  = $status;

                        issue_session_token((int)$user['id']);
                        header('Location: ' . redirect_by_role($role));
                        exit;
                    }
                }
            }
        } catch (Throwable $e) {
            $error = 'Terjadi kesalahan pada server. Silakan coba lagi.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Login | SMPP</title>
  <link rel="stylesheet" href="css/style.css" />
  <link href="https://fonts.googleapis.com/css2?family=Karla:wght@400;500;700&display=swap" rel="stylesheet">
</head>
<body>

  <div class="login-container">
    <div class="login-header">
      <img src="../assets/icon/icon-kelurahan.png" alt="Logo Kelurahan" class="logo-img">
      <h1 class="app-title">SMPP</h1>
      <p class="app-subtitle">Sistem Monitoring Petugas PPSU</p>
    </div>

    <div class="login-body">
      <h2><span>Selamat</span> Datang !</h2>
      <p>Silahkan login terlebih dahulu.</p>

      <?php if ($error !== ''): ?>
        <div class="error" style="color:#b00020;margin-bottom:10px;background:#ffebee;padding:12px;border-radius:8px;border:1px solid #ffcdd2;">
          <?php echo safeText($error); ?>
        </div>
      <?php endif; ?>

      <form id="loginForm" method="post" action="">
        <input type="text" id="username" name="username" placeholder="Username" required />
        <input type="password" id="password" name="password" placeholder="Password" required />

        <div class="forgot-password">
          <a href="lupa-password.php">Lupa Password ?</a>
        </div>

        <button type="submit" class="login-btn">Login</button>
      </form>
    </div>

    <div class="login-footer">
      <p class="version">v1.00</p>
      <p class="credits">
        Dikelola oleh Kelurahan Sunter Jaya dan Tim <br>
        Mahasiswa Gunadarma
      </p>
    </div>
  </div>
</body>
</html>
