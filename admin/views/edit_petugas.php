<?php
session_start();
require_once '../../config/database.php';
require_once '../../helpers/session.php';
enforce_session(['admin']);

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../../auth/login.php');
    exit;
}

$pdo = Database::getInstance()->getConnection();
require_once '../../admin/controllers/PetugasController.php';
$petugasController = new PetugasController();

$pid = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($pid <= 0) {
    die("ID user tidak valid");
}

$stmt = $pdo->prepare("SELECT id, nip, name, username, phone, status, photo_path, role FROM users WHERE id = :id");
$stmt->execute([':id'=>$pid]);
$petugas = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$petugas) die("User tidak ditemukan");

// Get last zone
$stmtZone = $pdo->prepare("
    SELECT z.rw_code, z.name
    FROM schedules s
    JOIN zones z ON s.zone_id = z.id
    WHERE s.user_id = :uid
    ORDER BY s.work_date DESC LIMIT 1
");
$stmtZone->execute([':uid'=>$pid]);
$zona = $stmtZone->fetch(PDO::FETCH_ASSOC);

$success = false;
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $name = trim($_POST['name']);
        $nip = trim($_POST['nip']);
        $phone = trim($_POST['phone']);
        $username = trim($_POST['username']);
        $password = trim($_POST['password']);
        $role = trim($_POST['role'] ?? $petugas['role']);
        $status = trim($_POST['status'] ?? $petugas['status']);

        $validRoles = ['admin', 'supervisor', 'petugas'];
        if (!in_array($role, $validRoles)) $role = $petugas['role'];

        if (!ctype_digit($nip)) {
            $error = 'NIP harus berupa angka saja.';
        } elseif ($phone !== '' && !ctype_digit($phone)) {
            $error = 'No. HP harus berupa angka saja.';
        } else {
            $result = $petugasController->updatePetugas($pid, [
                'name'     => $name,
                'nip'      => $nip,
                'phone'    => $phone,
                'username' => $username,
                'password' => $password,
                'role'     => $role,
                'status'   => $status,
            ]);
            if (!empty($result['success'])) {
                $success = true;
                $stmt->execute([':id'=>$pid]);
                $petugas = $stmt->fetch(PDO::FETCH_ASSOC);
            } else {
                $error = $result['errors']['username'] ?? $result['errors']['password'] ?? $result['errors']['system'] ?? 'Gagal menyimpan data';
            }
        }

    } elseif ($action === 'delete') {
        $res = $petugasController->deactivatePetugas($pid);
        if (!empty($res['success'])) {
            header("Location: data_petugas.php?deactivated=1");
            exit;
        } else {
            $error = $res['errors']['general'] ?? 'Gagal menonaktifkan user';
        }

    } elseif ($action === 'hard_delete') {
        $res = $petugasController->deletePetugas($pid);
        if (!empty($res['success'])) {
            header("Location: data_petugas.php?deleted=1");
            exit;
        } else {
            $error = $res['errors']['general'] ?? 'Gagal menghapus user';
        }
    }
}

function safe($v){return htmlspecialchars($v??'',ENT_QUOTES,'UTF-8');}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit User - Admin</title>
    <link rel="stylesheet" href="../css/dashboard.css">
    <link rel="stylesheet" href="../css/user_form.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
</head>
<body>
<div class="app">
    <?php $activePage = 'data_petugas'; ?>
    <?php require_once '../../admin/components/sidebar.php'; ?>

    <main class="main">
        <header class="header">
            <div class="header-left">
                <h1 class="page-title">Edit User</h1>
            </div>
        </header>

        <div class="form-container">
            <div class="form-card">
                <div class="form-header">
                    <div class="form-icon">
                        <i class="fas fa-user-edit" style="font-size: 28px; color: white;"></i>
                    </div>
                    <h2>Edit Data User</h2>
                    <p>Perbarui informasi user di bawah ini</p>
                </div>

                <?php if ($success): ?>
                    <div class="alert alert-success">
                        <i class="fas fa-check-circle alert-icon"></i>
                        Data berhasil diperbarui!
                    </div>
                <?php endif; ?>

                <?php if ($error): ?>
                    <div class="alert alert-error">
                        <i class="fas fa-exclamation-circle alert-icon"></i>
                        <?php echo safe($error); ?>
                    </div>
                <?php endif; ?>

                <form method="post">
                    <!-- Role Selection -->
                    <div class="form-group">
                        <label>Role / Peran</label>
                        <div class="option-cards">
                            <label class="option-card <?php echo $petugas['role'] === 'petugas' ? 'selected' : ''; ?>">
                                <input type="radio" name="role" value="petugas" <?php echo $petugas['role'] === 'petugas' ? 'checked' : ''; ?> onchange="updateRoleSelection()">
                                <div class="icon"><i class="fas fa-hard-hat"></i></div>
                                <div class="title">Petugas</div>
                            </label>
                            <label class="option-card <?php echo $petugas['role'] === 'supervisor' ? 'selected' : ''; ?>">
                                <input type="radio" name="role" value="supervisor" <?php echo $petugas['role'] === 'supervisor' ? 'checked' : ''; ?> onchange="updateRoleSelection()">
                                <div class="icon"><i class="fas fa-user-tie"></i></div>
                                <div class="title">Supervisor</div>
                            </label>
                            <label class="option-card <?php echo $petugas['role'] === 'admin' ? 'selected' : ''; ?>">
                                <input type="radio" name="role" value="admin" <?php echo $petugas['role'] === 'admin' ? 'checked' : ''; ?> onchange="updateRoleSelection()">
                                <div class="icon"><i class="fas fa-user-shield"></i></div>
                                <div class="title">Admin</div>
                            </label>
                        </div>
                    </div>

                    <div class="form-grid">
                        <div class="form-group">
                            <label>Nama Lengkap <span class="required">*</span></label>
                            <input type="text" name="name" class="form-input" value="<?php echo safe($petugas['name']); ?>" required>
                        </div>

                        <div class="form-group">
                            <label>NIP <span class="required">*</span></label>
                            <input type="number" name="nip" class="form-input" value="<?php echo safe($petugas['nip']); ?>" required>
                        </div>

                        <div class="form-group">
                            <label>Username <span class="required">*</span></label>
                            <input type="text" name="username" class="form-input" value="<?php echo safe($petugas['username']); ?>" required>
                        </div>

                        <div class="form-group">
                            <label>No. Handphone</label>
                            <input type="number" name="phone" class="form-input" value="<?php echo safe($petugas['phone']); ?>"
                                   oninput="if(this.value.length > 15) this.value = this.value.slice(0, 15);">
                        </div>

                        <div class="form-group">
                            <label>Status</label>
                            <select name="status" class="form-select">
                                <!-- 4-Status System -->
                                <option value="online" <?php echo $petugas['status']==='online'?'selected':''; ?>>🟢 Online</option>
                                <option value="offline" <?php echo $petugas['status']==='offline'?'selected':''; ?>>⚫ Offline</option>
                                <option value="cuti" <?php echo $petugas['status']==='cuti'?'selected':''; ?>>🟡 Cuti</option>
                                <option value="nonaktif" <?php echo $petugas['status']==='nonaktif'?'selected':''; ?>>🔴 Nonaktif</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label>Password Baru (Opsional)</label>
                            <input type="password" name="password" class="form-input" placeholder="Kosongkan jika tidak diubah">
                        </div>
                    </div>

                    <?php if ($zona): ?>
                    <div class="info-box">
                        <h4><i class="fas fa-map-marker-alt"></i> Zona Bertugas Terakhir</h4>
                        <p><?php echo safe($zona['rw_code'] . ' - ' . $zona['name']); ?></p>
                    </div>
                    <?php endif; ?>

                    <input type="hidden" name="address" value="">
                    
                    <div class="btn-row space-between">
                        <div>
                            <button type="submit" name="action" value="delete" class="btn btn-warning" onclick="return confirm('Yakin ingin menonaktifkan user ini?')">
                                <i class="fas fa-user-slash"></i> Nonaktifkan
                            </button>
                            <button type="submit" name="action" value="hard_delete" class="btn btn-danger" onclick="return confirm('Yakin ingin menghapus permanen? Data tidak bisa dikembalikan!')">
                                <i class="fas fa-trash"></i> Hapus Permanen
                            </button>
                        </div>
                        <div>
                            <a href="data_petugas.php" class="btn btn-secondary">
                                <i class="fas fa-arrow-left"></i> Kembali
                            </a>
                            <button type="submit" name="action" value="save" class="btn btn-primary">
                                <i class="fas fa-save"></i> Simpan
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </main>
</div>

<script>
function updateRoleSelection() {
    document.querySelectorAll('.option-card').forEach(card => {
        const radio = card.querySelector('input[type="radio"]');
        if (radio.checked) {
            card.classList.add('selected');
        } else {
            card.classList.remove('selected');
        }
    });
}
</script>
</body>
</html>
