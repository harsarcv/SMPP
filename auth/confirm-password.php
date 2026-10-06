<?php
session_start();
require_once '../config/database.php';

if (!function_exists('safeText')) {
    function safeText($text) {
        return htmlspecialchars($text ?? '', ENT_QUOTES, 'UTF-8');
    }
}

$error   = '';
$success = '';
$token   = trim((string)($_GET['token'] ?? ''));

if ($token === '') {
    header('Location: login.php');
    exit;
}

try {
    $pdo = Database::getInstance()->getConnection();
} catch (Throwable $e) {
    $pdo = null;
    $error = 'Gagal koneksi ke database.';
}

// Validate token
$userId = null;
$userName = '';

if ($pdo && $token !== '') {
    $stmt = $pdo->prepare("
        SELECT pr.user_id, pr.status, pr.expires_at, u.name
        FROM password_resets pr
        JOIN users u ON u.id = pr.user_id
        WHERE pr.request_token = ? LIMIT 1
    ");
    $stmt->execute([$token]);
    $request = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$request) {
        $error = 'Token tidak valid.';
    } elseif ($request['status'] !== 'approved') {
        $error = 'Permintaan belum disetujui atau sudah digunakan.';
    } elseif (strtotime($request['expires_at']) < time()) {
        // Token expired - mark as expired and show message with link to request new one
        $pdo->prepare("UPDATE password_resets SET status = 'expired' WHERE request_token = ?")->execute([$token]);
        $error = 'Token sudah kadaluarsa. Silakan <a href="lupa-password.php" style="color:#28a745;font-weight:bold;">buat permintaan baru</a>.';
    } else {
        $userId = (int)$request['user_id'];
        $userName = $request['name'];
    }
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $pdo && $userId) {
    $newPass = trim((string)($_POST['password'] ?? ''));
    $confirmPass = trim((string)($_POST['confirm_password'] ?? ''));

    if ($newPass === '' || $confirmPass === '') {
        $error = 'Password baru wajib diisi.';
    } elseif (strlen($newPass) < 6) {
        $error = 'Password minimal 6 karakter.';
    } elseif ($newPass !== $confirmPass) {
        $error = 'Konfirmasi password tidak cocok.';
    } else {
        try {
            $pdo->beginTransaction();
            $hash = password_hash($newPass, PASSWORD_DEFAULT);
            $pdo->prepare("UPDATE users SET password_hash = ?, updated_at = NOW() WHERE id = ?")->execute([$hash, $userId]);
            $pdo->prepare("UPDATE password_resets SET status = 'expired' WHERE request_token = ?")->execute([$token]);
            $pdo->commit();
            $success = 'Password berhasil diubah!';
            $userId = null;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $error = 'Gagal menyimpan password.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Buat Password Baru | SMPP</title>
  <link rel="stylesheet" href="css/style.css" />
  <link href="https://fonts.googleapis.com/css2?family=Karla:wght@400;500;700;800;900&display=swap" rel="stylesheet">
  <style>
    .success-box { background:#d4edda; color:#155724; padding:20px; border-radius:12px; text-align:center; margin-bottom:15px; }
    .btn-primary { display:block; text-align:center; padding:14px; margin-top:10px; background:#28a745; color:white; border-radius:25px; text-decoration:none; font-weight:700; }
    .btn-secondary { display:block; text-align:center; padding:12px; margin-top:10px; background:#f0f0f0; color:#333; border-radius:25px; text-decoration:none; font-weight:600; }
  </style>
</head>
<body>

<div class="login-container">
  <div class="login-header">
    <img src="../assets/icon/icon-kelurahan.png" alt="Logo" class="logo-img">
    <h1 class="app-title">SMPP</h1>
    <p class="app-subtitle">Buat Password Baru</p>
  </div>

  <div class="login-body">
    <h2>Konfirmasi Password</h2>

    <?php if ($error !== ''): ?>
      <div class="error" style="color:#b00020;margin-bottom:10px;"><?php echo $error; ?></div>
      <a href="login.php" class="btn-secondary">Kembali ke Login</a>
    <?php elseif ($success !== ''): ?>
      <div class="success-box">
        <h3>✓ Berhasil!</h3>
        <p><?php echo safeText($success); ?></p>
      </div>
      <a href="login.php" class="btn-primary">Login Sekarang</a>
    <?php elseif ($userId): ?>
      <p style="margin-bottom:15px;">Halo <strong><?php echo safeText($userName); ?></strong>, silakan buat password baru.</p>
      <form method="post" action="">
        <p class="label-title">Password Baru</p>
        <input type="password" name="password" placeholder="Minimal 6 karakter" required />
        <p class="label-title" style="margin-top:15px;">Konfirmasi Password</p>
        <input type="password" name="confirm_password" placeholder="Ketik ulang password" required />
        <button type="submit" class="login-btn lp-request-btn">Simpan Password</button>
      </form>
    <?php endif; ?>
  </div>

  <div class="login-footer">
    <p class="version">v1.00</p>
  </div>
</div>

</body>
</html>
