<?php
session_start();
require_once '../config/database.php';

if (!function_exists('safeText')) {
    function safeText($text) {
        return htmlspecialchars($text ?? '', ENT_QUOTES, 'UTF-8');
    }
}

$error   = '';
$status  = '';
$resetToken = '';

try {
    $pdo = Database::getInstance()->getConnection();
} catch (Throwable $e) {
    $pdo = null;
    $error = 'Gagal koneksi ke database.';
}

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
            $stmt = $pdo->prepare("
                SELECT id FROM users WHERE name = ? AND nip = ? LIMIT 1
            ");
            $stmt->execute([$nama, $nip]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$user) {
                $error = 'Data Nama dan NIP tidak ditemukan.';
            } else {
                $userId = (int)$user['id'];

                // Check latest password reset request
                $checkStmt = $pdo->prepare("
                    SELECT id, status, request_token, expires_at 
                    FROM password_resets 
                    WHERE user_id = ? 
                    ORDER BY created_at DESC 
                    LIMIT 1
                ");
                $checkStmt->execute([$userId]);
                $request = $checkStmt->fetch(PDO::FETCH_ASSOC);

                if (!$request) {
                    $status = 'not_found';
                } else {
                    $status = $request['status'];
                    if ($status === 'approved') {
                        $resetToken = $request['request_token'];
                    }
                }
            }
        } catch (Throwable $e) {
            $error = 'Terjadi kesalahan: ' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Cek Status Reset | SMPP</title>
  <link rel="stylesheet" href="css/style.css" />
  <link href="https://fonts.googleapis.com/css2?family=Karla:wght@400;500;700;800;900&display=swap" rel="stylesheet">
  <style>
    .status-box {
      padding: 20px;
      border-radius: 12px;
      margin: 15px 0;
      text-align: center;
    }
    .status-pending {
      background: #fff3cd;
      color: #856404;
      border: 1px solid #ffc107;
    }
    .status-approved {
      background: #d4edda;
      color: #155724;
      border: 1px solid #28a745;
    }
    .status-expired {
      background: #f8d7da;
      color: #721c24;
      border: 1px solid #dc3545;
    }
    .status-not-found {
      background: #e2e3e5;
      color: #383d41;
      border: 1px solid #adb5bd;
    }
    .btn-primary {
      display: block;
      text-align: center;
      padding: 14px;
      margin-top: 10px;
      background: #28a745;
      color: white;
      border-radius: 25px;
      text-decoration: none;
      font-weight: 700;
      font-size: 16px;
    }
    .btn-secondary {
      display: block;
      text-align: center;
      padding: 12px;
      margin-top: 10px;
      background: #f0f0f0;
      color: #333;
      border-radius: 25px;
      text-decoration: none;
      font-weight: 600;
    }
  </style>
</head>
<body>

  <div class="login-container">
    
    <div class="login-header">
      <img src="../assets/icon/icon-kelurahan.png" alt="Logo" class="logo-img">
      <h1 class="app-title">SMPP</h1>
      <p class="app-subtitle">Cek Status Permintaan Reset</p>
    </div>

    <div class="login-body">
      <h2>Cek Status</h2>

      <?php if ($error !== ''): ?>
        <div class="error" style="color:#b00020;margin-bottom:10px;">
          <?php echo safeText($error); ?>
        </div>
      <?php endif; ?>

      <?php if ($status === ''): ?>
        <!-- Form to check status -->
        <form method="post" action="">
          <p class="label-title">Nama Lengkap</p>
          <input type="text" name="nama" placeholder="Nama Lengkap" required />

          <p class="label-title" style="margin-top: 15px;">NIP</p>
          <input type="number" name="nip" placeholder="Nomor Induk Petugas (angka)" required />

          <button type="submit" class="login-btn lp-request-btn">Cek Status</button>
        </form>

      <?php elseif ($status === 'pending'): ?>
        <div class="status-box status-pending">
          <h3>⏳ Menunggu Persetujuan</h3>
          <p>Permintaan reset password Anda sedang diproses oleh Supervisor.</p>
          <p>Silakan cek kembali dalam beberapa saat.</p>
        </div>
        <a href="check-reset-status.php" class="btn-secondary">Refresh Status</a>

      <?php elseif ($status === 'approved'): ?>
        <div class="status-box status-approved">
          <h3>✓ Disetujui!</h3>
          <p>Permintaan Anda telah disetujui oleh Supervisor.</p>
          <p>Klik tombol di bawah untuk membuat password baru.</p>
        </div>
        <a href="reset-password.php?token=<?php echo urlencode($resetToken); ?>" class="btn-primary">
          Buat Password Baru
        </a>

      <?php elseif ($status === 'expired'): ?>
        <div class="status-box status-expired">
          <h3>✕ Kadaluarsa</h3>
          <p>Permintaan reset password Anda telah kadaluarsa.</p>
          <p>Silakan ajukan permintaan baru.</p>
        </div>
        <a href="lupa-password.php" class="btn-secondary">Ajukan Ulang</a>

      <?php else: ?>
        <div class="status-box status-not-found">
          <h3>Tidak Ditemukan</h3>
          <p>Tidak ada permintaan reset password untuk akun Anda.</p>
        </div>
        <a href="lupa-password.php" class="btn-secondary">Ajukan Permintaan</a>

      <?php endif; ?>

      <a href="login.php" class="btn-secondary" style="margin-top:20px;">
        Kembali ke Login
      </a>
    </div>

    <div class="login-footer">
      <p class="version">v1.00</p>
    </div>

  </div>

</body>
</html>
