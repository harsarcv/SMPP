<?php
session_start();
require_once '../../config/database.php';
require_once '../../helpers/session.php';
enforce_session(['admin']);

$pdo = Database::getInstance()->getConnection();

$admName = $_SESSION['name'] ?? 'Admin';
$admNip  = $_SESSION['nip'] ?? 'ADMIN001';

$success = false;
$error = '';
$message = '';

// Get all supervisor password reset requests
$stmt = $pdo->prepare("
    SELECT pr.*, u.name, u.nip, u.username
    FROM password_resets pr
    JOIN users u ON pr.user_id = u.id
    WHERE pr.status = 'pending' AND u.role = 'supervisor'
    ORDER BY pr.created_at DESC
");
$stmt->execute();
$requests = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get all supervisors
$stmt = $pdo->query("
    SELECT id, name, nip, username, status
    FROM users 
    WHERE role = 'supervisor'
    ORDER BY name ASC
");
$supervisors = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Handle password reset action
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'reset_password') {
        $supervisorId = (int)($_POST['supervisor_id'] ?? 0);
        $newPassword = $_POST['new_password'] ?? '';
        
        if ($supervisorId > 0 && strlen($newPassword) >= 6) {
            $hash = password_hash($newPassword, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("
                UPDATE users 
                SET password_hash = :hash, updated_at = NOW()
                WHERE id = :id AND role = 'supervisor'
            ");
            $stmt->execute([':hash' => $hash, ':id' => $supervisorId]);
            
            if ($stmt->rowCount() > 0) {
                // Mark any pending reset requests as completed
                $pdo->prepare("
                    UPDATE password_resets 
                    SET status = 'completed', completed_at = NOW(), completed_by = :admin
                    WHERE user_id = :uid AND status = 'pending'
                ")->execute([':admin' => $_SESSION['user_id'], ':uid' => $supervisorId]);
                
                $success = true;
                $message = 'Password supervisor berhasil direset!';
            } else {
                $error = 'Gagal mereset password. Supervisor tidak ditemukan.';
            }
        } else {
            $error = 'Password minimal 6 karakter.';
        }
    } elseif ($action === 'approve_request') {
        $requestId = (int)($_POST['request_id'] ?? 0);
        $newPassword = $_POST['new_password'] ?? '';
        
        // Get user_id from request
        $stmt = $pdo->prepare("SELECT user_id FROM password_resets WHERE id = ?");
        $stmt->execute([$requestId]);
        $req = $stmt->fetch();
        
        if ($req && strlen($newPassword) >= 6) {
            $hash = password_hash($newPassword, PASSWORD_DEFAULT);
            $pdo->prepare("UPDATE users SET password_hash = :hash WHERE id = :id")
                ->execute([':hash' => $hash, ':id' => $req['user_id']]);
            
            $pdo->prepare("
                UPDATE password_resets 
                SET status = 'completed', completed_at = NOW(), completed_by = :admin
                WHERE id = :rid
            ")->execute([':admin' => $_SESSION['user_id'], ':rid' => $requestId]);
            
            $success = true;
            $message = 'Password berhasil direset!';
        }
    } elseif ($action === 'reject_request') {
        $requestId = (int)($_POST['request_id'] ?? 0);
        $pdo->prepare("UPDATE password_resets SET status = 'rejected' WHERE id = ?")->execute([$requestId]);
        $message = 'Permintaan ditolak.';
    }
    
    // Refresh requests
    $stmt = $pdo->prepare("
        SELECT pr.*, u.name, u.nip, u.username
        FROM password_resets pr
        JOIN users u ON pr.user_id = u.id
        WHERE pr.status = 'pending' AND u.role = 'supervisor'
        ORDER BY pr.created_at DESC
    ");
    $stmt->execute();
    $requests = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function safe($txt) { return htmlspecialchars($txt ?? '', ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password Supervisor - Admin</title>
    <link rel="stylesheet" href="../css/dashboard.css">
    <link rel="stylesheet" href="../css/jadwal.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        .card { background: white; border-radius: 12px; padding: 20px; margin-bottom: 20px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); }
        .card-title { font-size: 16px; font-weight: 600; margin-bottom: 15px; color: #333; }
        .request-item { background: #f8f9fa; padding: 15px; border-radius: 8px; margin-bottom: 10px; display: flex; justify-content: space-between; align-items: center; }
        .request-info h4 { margin: 0 0 5px 0; }
        .request-info p { margin: 0; font-size: 13px; color: #666; }
        .btn-sm { padding: 6px 12px; font-size: 12px; border: none; border-radius: 4px; cursor: pointer; }
        .btn-success { background: #28a745; color: white; }
        .btn-danger { background: #dc3545; color: white; }
        .alert { padding: 12px 16px; border-radius: 6px; margin-bottom: 15px; }
        .alert-success { background: #d4edda; color: #155724; }
        .alert-error { background: #f8d7da; color: #721c24; }
        .form-inline { display: flex; gap: 10px; align-items: center; }
        .form-inline input { padding: 8px 12px; border: 1px solid #ddd; border-radius: 4px; }
        .form-inline select { padding: 8px 12px; border: 1px solid #ddd; border-radius: 4px; min-width: 200px; }
    </style>
</head>
<body>
<div class="app">
    
    <?php $activePage = 'reset_password_supervisor'; ?>
    <?php require_once '../../admin/components/sidebar.php'; ?>

    <main class="main">
        <header class="header">
            <div class="header-left">
                <h1 class="page-title">Reset Password Supervisor</h1>
            </div>
            <div class="header-right">
                <div class="profile">
                    <div class="profile-text">
                        <div class="profile-name"><?= safe($admName) ?></div>
                        <div class="profile-id"><?= safe($admNip) ?></div>
                    </div>
                </div>
            </div>
        </header>

        <div class="title-center">KELOLA PASSWORD SUPERVISOR</div>

        <?php if ($success): ?>
            <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?= safe($message) ?></div>
        <?php elseif ($error): ?>
            <div class="alert alert-error"><i class="fas fa-exclamation-circle"></i> <?= safe($error) ?></div>
        <?php endif; ?>

        <!-- Pending Requests -->
        <?php if (!empty($requests)): ?>
        <div class="card">
            <div class="card-title"><i class="fas fa-bell"></i> Permintaan Reset Password</div>
            <?php foreach ($requests as $req): ?>
            <div class="request-item">
                <div class="request-info">
                    <h4><?= safe($req['name']) ?></h4>
                    <p>NIP: <?= safe($req['nip']) ?> | Username: <?= safe($req['username']) ?></p>
                    <p style="font-size: 11px; color: #999;">Diajukan: <?= date('d M Y H:i', strtotime($req['created_at'])) ?></p>
                </div>
                <form method="post" class="form-inline">
                    <input type="hidden" name="request_id" value="<?= $req['id'] ?>">
                    <input type="password" name="new_password" placeholder="Password Baru" required minlength="6">
                    <button type="submit" name="action" value="approve_request" class="btn-sm btn-success">
                        <i class="fas fa-check"></i> Setujui
                    </button>
                    <button type="submit" name="action" value="reject_request" class="btn-sm btn-danger">
                        <i class="fas fa-times"></i> Tolak
                    </button>
                </form>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <!-- Manual Reset -->
        <div class="card">
            <div class="card-title"><i class="fas fa-key"></i> Reset Password Manual</div>
            <form method="post" class="form-inline">
                <select name="supervisor_id" required>
                    <option value="">-- Pilih Supervisor --</option>
                    <?php foreach ($supervisors as $sv): ?>
                        <option value="<?= $sv['id'] ?>"><?= safe($sv['name']) ?> (<?= safe($sv['username']) ?>)</option>
                    <?php endforeach; ?>
                </select>
                <input type="password" name="new_password" placeholder="Password Baru" required minlength="6">
                <button type="submit" name="action" value="reset_password" class="btn-sm btn-success">
                    <i class="fas fa-sync"></i> Reset Password
                </button>
            </form>
        </div>

        <!-- Supervisor List -->
        <div class="card">
            <div class="card-title"><i class="fas fa-users"></i> Daftar Supervisor</div>
            <table class="sv-table" style="width: 100%;">
                <thead>
                    <tr>
                        <th>Nama</th>
                        <th>NIP</th>
                        <th>Username</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($supervisors as $sv): ?>
                    <tr>
                        <td><?= safe($sv['name']) ?></td>
                        <td><?= safe($sv['nip']) ?></td>
                        <td><?= safe($sv['username']) ?></td>
                        <td>
                            <span class="status-badge <?= $sv['status'] === 'aktif' ? 'badge-success' : 'badge-pending' ?>">
                                <?= ucfirst($sv['status']) ?>
                            </span>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

    </main>
</div>
</body>
</html>
