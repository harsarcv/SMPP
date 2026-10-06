<?php
session_start();
require_once '../config/database.php';

if (!function_exists('safeText')) {
    function safeText($text) {
        return htmlspecialchars($text ?? '', ENT_QUOTES, 'UTF-8');
    }
}

$error   = '';
$waiting = false;
$token   = '';
$userName = '';
$userNip = '';

try {
    $pdo = Database::getInstance()->getConnection();
} catch (Throwable $e) {
    $pdo = null;
    $error = 'Gagal koneksi ke database.';
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $pdo) {
    $nama = trim((string)($_POST['nama'] ?? ''));
    $nip  = trim((string)($_POST['nip'] ?? ''));

    if ($nama === '' || $nip === '') {
        $error = 'Nama dan NIP wajib diisi.';
    } elseif (!ctype_digit($nip)) {
        $error = 'NIP harus berupa angka saja.';
    } else {
        try {
            // Find user
            $stmt = $pdo->prepare("SELECT id, name, nip, status FROM users WHERE name = ? AND nip = ? LIMIT 1");
            $stmt->execute([$nama, $nip]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$user) {
                $error = 'Data Nama dan NIP tidak ditemukan.';
            } elseif ($user['status'] !== 'aktif') {
                $error = 'Akun Anda tidak aktif. Hubungi Admin.';
            } else {
                $userId = (int)$user['id'];
                $userName = $nama;
                $userNip = $nip;

                // Check existing request (include expires_at)
                $checkStmt = $pdo->prepare("
                    SELECT id, request_token, status, expires_at FROM password_resets 
                    WHERE user_id = ? AND status IN ('pending', 'approved')
                    ORDER BY created_at DESC LIMIT 1
                ");
                $checkStmt->execute([$userId]);
                $existingRequest = $checkStmt->fetch(PDO::FETCH_ASSOC);

                $needsNewToken = false;

                if ($existingRequest) {
                    // Check if token is expired
                    if (strtotime($existingRequest['expires_at']) < time()) {
                        // Token expired - mark as expired and create new one
                        $pdo->prepare("UPDATE password_resets SET status = 'expired' WHERE id = ?")->execute([$existingRequest['id']]);
                        $needsNewToken = true;
                    } elseif ($existingRequest['status'] === 'approved') {
                        // Already approved - redirect to confirm password
                        header('Location: confirm-password.php?token=' . urlencode($existingRequest['request_token']));
                        exit;
                    } else {
                        // Still pending and not expired
                        $waiting = true;
                        $token = $existingRequest['request_token'];
                    }
                } else {
                    $needsNewToken = true;
                }

                // Create new token if needed
                if ($needsNewToken) {
                    $requestToken = bin2hex(random_bytes(32));
                    $resetIns = $pdo->prepare("
                        INSERT INTO password_resets (user_id, request_token, source, status, requested_by, expires_at, created_at)
                        VALUES (:uid, :token, 'petugas', 'pending', :req_by, DATE_ADD(NOW(), INTERVAL 24 HOUR), NOW())
                    ");
                    $resetIns->execute([':uid' => $userId, ':token' => $requestToken, ':req_by' => $userId]);
                    $waiting = true;
                    $token = $requestToken;
                }
            }
        } catch (Throwable $e) {
            $error = 'Terjadi kesalahan server.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Lupa Password | SMPP</title>
  <link rel="stylesheet" href="css/style.css" />
  <link href="https://fonts.googleapis.com/css2?family=Karla:wght@400;500;700;800;900&display=swap" rel="stylesheet">
  <style>
    .btn-secondary { display:block; text-align:center; padding:12px; margin-top:10px; background:#f0f0f0; color:#333; border-radius:25px; text-decoration:none; font-weight:600; }
    .waiting-box { background:#fff3cd; color:#856404; padding:25px; border-radius:12px; text-align:center; margin-bottom:15px; }
    .spinner { width:50px; height:50px; border:5px solid #f3f3f3; border-top:5px solid #28a745; border-radius:50%; animation:spin 1s linear infinite; margin:20px auto; }
    @keyframes spin { 0%{transform:rotate(0deg);} 100%{transform:rotate(360deg);} }
  </style>
</head>
<body>

<div class="login-container">
  <div class="login-header">
    <img src="../assets/icon/icon-kelurahan.png" alt="Logo" class="logo-img">
    <h1 class="app-title">SMPP</h1>
    <p class="app-subtitle">Sistem Monitoring Petugas PPSU</p>
  </div>

  <div class="login-body">
    <h2>Lupa Password</h2>

    <?php if ($error !== ''): ?>
      <div class="error" style="color:#b00020;margin-bottom:10px;"><?php echo safeText($error); ?></div>
    <?php endif; ?>

    <?php if ($waiting): ?>
      <div class="waiting-box">
        <div class="spinner"></div>
        <h3>Menunggu Persetujuan Supervisor</h3>
        <p>Permintaan reset password Anda sedang diproses.</p>
        <p style="font-size:12px; margin-top:15px;">Anda akan otomatis diarahkan ketika Supervisor mengambil keputusan...</p>
      </div>
      <a href="login.php" class="btn-secondary">Kembali ke Login</a>
      
      <script>
        // Poll setiap 3 detik untuk cek status
        var token = "<?php echo safeText($token); ?>";
        setInterval(function() {
          fetch('api/check_reset_status.php?token=' + encodeURIComponent(token))
            .then(r => r.json())
            .then(data => {
              if (data.status === 'approved') {
                window.location.href = 'confirm-password.php?token=' + encodeURIComponent(token);
              } else if (data.status === 'rejected') {
                alert('Permintaan Anda telah ditolak oleh Supervisor.');
                window.location.href = 'login.php';
              }
              // jika masih pending, tetap menunggu
            })
            .catch(err => console.log('Poll error:', err));
        }, 3000);
      </script>
    <?php else: ?>
      <form method="post" action="">
        <p class="label-title">Nama Lengkap</p>
        <input type="text" name="nama" placeholder="Nama Lengkap" required />
        <p class="label-title" style="margin-top:15px;">NIP</p>
        <input type="number" name="nip" placeholder="Nomor Induk Petugas (angka)" required />
        <button type="submit" class="login-btn lp-request-btn">Kirim Permintaan</button>
      </form>
      <a href="login.php" class="btn-secondary">Kembali ke Login</a>
    <?php endif; ?>
  </div>

  <div class="login-footer">
    <p class="version">v1.00</p>
  </div>
</div>

</body>
</html>
