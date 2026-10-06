<?php
session_start();
require_once '../../config/database.php';
require_once '../../helpers/session.php';
enforce_session(['supervisor']);

$pdo = Database::getInstance()->getConnection();

$supId = $_SESSION['user_id'];
$supName = $_SESSION['name'] ?? 'Supervisor';
$supNip = $_SESSION['nip'] ?? 'SPV001';

$success = false;
$error = '';
$message = '';

// Get supervisor's group
$stmt = $pdo->prepare("SELECT * FROM `groups` WHERE supervisor_id = :sid LIMIT 1");
$stmt->execute([':sid' => $supId]);
$group = $stmt->fetch(PDO::FETCH_ASSOC);

// Get petugas in the group
$groupMembers = [];
if ($group) {
    $stmt = $pdo->prepare("
        SELECT id, nip, name, username, status, phone, photo_path
        FROM users 
        WHERE group_id = :gid AND role = 'petugas'
        ORDER BY name ASC
    ");
    $stmt->execute([':gid' => $group['id']]);
    $groupMembers = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Get available petugas (not in any group)
$stmt = $pdo->query("
    SELECT id, nip, name, username, status
    FROM users 
    WHERE role = 'petugas' AND (group_id IS NULL OR group_id = 0)
    ORDER BY name ASC
");
$availablePetugas = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    // Create group if doesn't exist
    if ($action === 'create_group') {
        $groupName = trim($_POST['group_name'] ?? '');
        if (!empty($groupName) && !$group) {
            $stmt = $pdo->prepare("INSERT INTO `groups` (name, supervisor_id, description) VALUES (:name, :sid, :desc)");
            $stmt->execute([
                ':name' => $groupName,
                ':sid' => $supId,
                ':desc' => 'Group ' . $supName
            ]);
            $success = true;
            $message = 'Group berhasil dibuat!';
            
            // Refresh group
            $stmt = $pdo->prepare("SELECT * FROM `groups` WHERE supervisor_id = :sid LIMIT 1");
            $stmt->execute([':sid' => $supId]);
            $group = $stmt->fetch(PDO::FETCH_ASSOC);
        }
    }
    
    // Add petugas to group
    elseif ($action === 'add_member') {
        $petugasId = (int)($_POST['petugas_id'] ?? 0);
        if ($petugasId > 0 && $group) {
            $stmt = $pdo->prepare("
                UPDATE users 
                SET group_id = :gid, supervisor_id = :sid, updated_at = NOW()
                WHERE id = :pid AND role = 'petugas'
            ");
            $stmt->execute([':gid' => $group['id'], ':sid' => $supId, ':pid' => $petugasId]);
            
            if ($stmt->rowCount() > 0) {
                $success = true;
                $message = 'Petugas berhasil ditambahkan ke group!';
            }
        }
    }
    
    // Remove petugas from group
    elseif ($action === 'remove_member') {
        $petugasId = (int)($_POST['petugas_id'] ?? 0);
        if ($petugasId > 0) {
            $stmt = $pdo->prepare("
                UPDATE users 
                SET group_id = NULL, supervisor_id = NULL, updated_at = NOW()
                WHERE id = :pid AND group_id = :gid
            ");
            $stmt->execute([':pid' => $petugasId, ':gid' => $group['id']]);
            $success = true;
            $message = 'Petugas dihapus dari group.';
        }
    }
    
    // Reset password for group member
    elseif ($action === 'reset_password') {
        $petugasId = (int)($_POST['petugas_id'] ?? 0);
        $newPassword = $_POST['new_password'] ?? '';
        
        // Verify petugas is in supervisor's group
        $stmt = $pdo->prepare("SELECT id FROM users WHERE id = :pid AND group_id = :gid");
        $stmt->execute([':pid' => $petugasId, ':gid' => $group['id']]);
        
        if ($stmt->fetch() && strlen($newPassword) >= 6) {
            $hash = password_hash($newPassword, PASSWORD_DEFAULT);
            $pdo->prepare("UPDATE users SET password_hash = :hash WHERE id = :id")
                ->execute([':hash' => $hash, ':id' => $petugasId]);
            $success = true;
            $message = 'Password petugas berhasil direset!';
        } else {
            $error = 'Password minimal 6 karakter atau petugas tidak dalam group Anda.';
        }
    }
    
    // Refresh data
    if ($group) {
        $stmt = $pdo->prepare("
            SELECT id, nip, name, username, status, phone, photo_path
            FROM users 
            WHERE group_id = :gid AND role = 'petugas'
            ORDER BY name ASC
        ");
        $stmt->execute([':gid' => $group['id']]);
        $groupMembers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    $stmt = $pdo->query("
        SELECT id, nip, name, username, status
        FROM users 
        WHERE role = 'petugas' AND (group_id IS NULL OR group_id = 0)
        ORDER BY name ASC
    ");
    $availablePetugas = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function safe($txt) { return htmlspecialchars($txt ?? '', ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kelola Group - Supervisor</title>
    <link rel="stylesheet" href="../css/dashboard.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        .content { padding: 20px; }
        .card { background: white; border-radius: 12px; padding: 20px; margin-bottom: 20px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); }
        .card-title { font-size: 18px; font-weight: 600; margin-bottom: 15px; color: #333; display: flex; align-items: center; gap: 10px; }
        .member-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 15px; }
        .member-card { background: #f8f9fa; padding: 15px; border-radius: 8px; display: flex; align-items: center; gap: 12px; }
        .member-avatar { width: 50px; height: 50px; border-radius: 50%; background: #007bff; color: white; display: flex; align-items: center; justify-content: center; font-weight: bold; overflow: hidden; }
        .member-avatar img { width: 100%; height: 100%; object-fit: cover; }
        .member-info { flex: 1; }
        .member-name { font-weight: 600; color: #333; }
        .member-nip { font-size: 12px; color: #666; }
        .member-status { font-size: 11px; padding: 2px 8px; border-radius: 10px; display: inline-block; margin-top: 4px; }
        .status-online { background: #dcfce7; color: #166534; }
        .status-offline { background: #e2e8f0; color: #475569; }
        .status-nonaktif { background: #fee2e2; color: #991b1b; }
        .status-cuti { background: #fef9c3; color: #92400e; }
        .member-actions { display: flex; gap: 5px; flex-direction: column; }
        .btn-icon { width: 30px; height: 30px; border: none; border-radius: 4px; cursor: pointer; font-size: 12px; }
        .btn-danger { background: #dc3545; color: white; }
        .btn-warning { background: #ffc107; color: #333; }
        .btn-primary { background: #007bff; color: white; border: none; padding: 8px 15px; border-radius: 4px; cursor: pointer; }
        .alert { padding: 12px 16px; border-radius: 6px; margin-bottom: 15px; }
        .alert-success { background: #d4edda; color: #155724; }
        .alert-error { background: #f8d7da; color: #721c24; }
        .form-row { display: flex; gap: 10px; align-items: center; margin-bottom: 15px; }
        .form-row select, .form-row input { padding: 8px 12px; border: 1px solid #ddd; border-radius: 4px; }
        .form-row select { min-width: 250px; }
        .empty-state { text-align: center; padding: 30px; color: #666; }
        .group-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
        .group-name { font-size: 24px; font-weight: 700; color: #007bff; }
        .group-count { font-size: 14px; color: #666; }
        
        /* Modal for password reset */
        .modal { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); display: none; align-items: center; justify-content: center; z-index: 9999; }
        .modal-box { background: white; padding: 25px; border-radius: 12px; max-width: 400px; width: 90%; }
        .modal-title { font-size: 18px; font-weight: 600; margin-bottom: 15px; }
        .modal-input { width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 4px; margin-bottom: 15px; }
        .modal-actions { display: flex; gap: 10px; justify-content: flex-end; }
    </style>
</head>
<body>
<div class="layout">
    
  <?php require_once '../../supervisor/components/sidebar.php'; ?>


    <main class="content">
        <header class="header">
            <h2>Kelola Group</h2>
            <div class="profile-box">
                <div class="profile-icon">S</div>
                <div class="profile-info">
                    <div class="name"><?= safe($supName) ?></div>
                    <div class="id"><?= safe($supNip) ?></div>
                </div>
            </div>
        </header>

        <?php if ($success): ?>
            <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?= safe($message) ?></div>
        <?php elseif ($error): ?>
            <div class="alert alert-error"><i class="fas fa-exclamation-circle"></i> <?= safe($error) ?></div>
        <?php endif; ?>

        <?php if (!$group): ?>
        <!-- Create Group Form -->
        <div class="card">
            <div class="card-title"><i class="fas fa-users-cog"></i> Buat Group Baru</div>
            <p style="color: #666; margin-bottom: 15px;">Anda belum memiliki group. Buat group untuk mulai menambahkan anggota petugas.</p>
            <form method="post" class="form-row">
                <input type="text" name="group_name" placeholder="Nama Group (contoh: Group RW 01)" required style="flex: 1;">
                <button type="submit" name="action" value="create_group" class="btn-primary">
                    <i class="fas fa-plus"></i> Buat Group
                </button>
            </form>
        </div>
        <?php else: ?>
        
        <!-- Group Info -->
        <div class="group-header">
            <div>
                <div class="group-name"><i class="fas fa-users"></i> <?= safe($group['name']) ?></div>
                <div class="group-count"><?= count($groupMembers) ?> anggota petugas</div>
            </div>
        </div>

        <!-- Add Member -->
        <div class="card">
            <div class="card-title"><i class="fas fa-user-plus"></i> Tambah Anggota</div>
            <?php if (!empty($availablePetugas)): ?>
            <form method="post" class="form-row">
                <select name="petugas_id" required>
                    <option value="">-- Pilih Petugas --</option>
                    <?php foreach ($availablePetugas as $p): ?>
                        <option value="<?= $p['id'] ?>"><?= safe($p['name']) ?> (<?= safe($p['nip']) ?>) - <?= ucfirst($p['status']) ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" name="action" value="add_member" class="btn-primary">
                    <i class="fas fa-plus"></i> Tambahkan
                </button>
            </form>
            <?php else: ?>
            <p style="color: #999;">Semua petugas sudah masuk ke dalam group.</p>
            <?php endif; ?>
        </div>

        <!-- Group Members -->
        <div class="card">
            <div class="card-title"><i class="fas fa-users"></i> Anggota Group</div>
            <?php if (!empty($groupMembers)): ?>
            <div class="member-grid">
                <?php foreach ($groupMembers as $m): ?>
                <div class="member-card">
                    <div class="member-avatar">
                        <?php if (!empty($m['photo_path']) && $m['photo_path'] !== '/default-avatar.png'): ?>
                            <img src="<?= safe($m['photo_path']) ?>" alt="Avatar">
                        <?php else: ?>
                            <?= strtoupper(substr($m['name'], 0, 1)) ?>
                        <?php endif; ?>
                    </div>
                    <div class="member-info">
                        <div class="member-name"><?= safe($m['name']) ?></div>
                        <div class="member-nip">NIP: <?= safe($m['nip']) ?></div>
                        <?php 
                            $stLabel = match($m['status']) {
                                'online' => '🟢 Online',
                                'offline' => '⚫ Offline',
                                'nonaktif' => '🔴 Nonaktif',
                                'cuti' => '🟡 Cuti',
                                default => ucfirst($m['status'])
                            };
                        ?>
                        <span class="member-status status-<?= $m['status'] ?>"><?= $stLabel ?></span>
                    </div>
                    <div class="member-actions">
                        <button onclick="openResetModal(<?= $m['id'] ?>, '<?= safe($m['name']) ?>')" class="btn-icon btn-warning" title="Reset Password">
                            <i class="fas fa-key"></i>
                        </button>
                        <form method="post" style="display:inline;" onsubmit="return confirm('Hapus dari group?')">
                            <input type="hidden" name="petugas_id" value="<?= $m['id'] ?>">
                            <button type="submit" name="action" value="remove_member" class="btn-icon btn-danger" title="Hapus dari Group">
                                <i class="fas fa-times"></i>
                            </button>
                        </form>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php else: ?>
            <div class="empty-state">
                <i class="fas fa-user-slash" style="font-size: 40px; color: #ccc; margin-bottom: 10px;"></i>
                <p>Belum ada anggota dalam group. Tambahkan petugas di atas.</p>
            </div>
            <?php endif; ?>
        </div>
        
        <?php endif; ?>
    </main>
</div>

<!-- Password Reset Modal -->
<div id="resetModal" class="modal">
    <div class="modal-box">
        <div class="modal-title"><i class="fas fa-key"></i> Reset Password</div>
        <p id="resetModalName" style="margin-bottom: 15px;"></p>
        <form method="post">
            <input type="hidden" name="petugas_id" id="resetPetugasId">
            <input type="password" name="new_password" class="modal-input" placeholder="Password Baru (min 6 karakter)" required minlength="6">
            <div class="modal-actions">
                <button type="button" onclick="closeResetModal()" style="padding: 8px 15px; border: 1px solid #ddd; background: white; border-radius: 4px; cursor: pointer;">Batal</button>
                <button type="submit" name="action" value="reset_password" class="btn-primary">Reset Password</button>
            </div>
        </form>
    </div>
</div>

<script>
function openResetModal(id, name) {
    document.getElementById('resetPetugasId').value = id;
    document.getElementById('resetModalName').textContent = 'Reset password untuk: ' + name;
    document.getElementById('resetModal').style.display = 'flex';
}
function closeResetModal() {
    document.getElementById('resetModal').style.display = 'none';
}
document.getElementById('resetModal').addEventListener('click', function(e) {
    if (e.target === this) closeResetModal();
});
</script>
</body>
</html>
