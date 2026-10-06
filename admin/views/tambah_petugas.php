<?php
session_start();
require_once '../../config/database.php';
require_once '../../helpers/session.php';
enforce_session(['admin']);

// Auth admin only
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../../auth/login.php');
    exit;
}

$brandSub = "Kelurahan Sunter Jaya";
$admName  = $_SESSION['name'] ?? 'Admin';
$admNip   = $_SESSION['nip'] ?? 'ADMIN001';

if (!function_exists('safeText')) {
    function safeText($t) {
        return htmlspecialchars($t ?? '', ENT_QUOTES, 'UTF-8');
    }
}

$msg = '';
$success = false;

$old = [
    'name'    => '',
    'nip'     => '',
    'phone'   => '',
    'username'=> '',
    'role'    => 'petugas'
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $pdo = Database::getInstance()->getConnection();

    $name  = trim($_POST['name']    ?? '');
    $nip   = trim($_POST['nip']     ?? '');
    $phone = trim($_POST['phone']   ?? '');
    $usern = trim($_POST['username']?? '');
    $pass  = (string)($_POST['password'] ?? '');
    $role  = trim($_POST['role']    ?? 'petugas');

    $validRoles = ['admin', 'supervisor', 'petugas'];
    if (!in_array($role, $validRoles)) {
        $role = 'petugas';
    }

    $old['name']    = $name;
    $old['nip']     = $nip;
    $old['phone']   = $phone;
    $old['username']= $usern;
    $old['role']    = $role;

    if ($name === '' || $nip === '' || $usern === '' || $pass === '') {
        $msg = 'Nama, NIP, Username, dan Password wajib diisi.';
    } elseif (!ctype_digit($nip)) {
        $msg = 'NIP harus berupa angka saja.';
    } elseif ($phone !== '' && !ctype_digit($phone)) {
        $msg = 'No. Handphone harus berupa angka saja.';
    } else {
        try {
            $check = $pdo->prepare("SELECT COUNT(*) FROM users WHERE username = :u OR nip = :n");
            $check->execute([':u' => $usern, ':n' => $nip]);
            if ($check->fetchColumn() > 0) {
                $msg = 'Username atau NIP sudah digunakan.';
            }
        } catch (Exception $e) {
            $msg = 'Gagal memeriksa data pengguna: ' . $e->getMessage();
        }
    }

    $photoPath = null;
    if ($msg === '') {
        if (!isset($_FILES['photo']) || $_FILES['photo']['error'] === UPLOAD_ERR_NO_FILE) {
            $photoPath = '/uploads/users/default.png';
        } elseif ($_FILES['photo']['error'] !== UPLOAD_ERR_OK) {
            $msg = 'Terjadi kesalahan saat upload foto.';
        } else {
            $file = $_FILES['photo'];
            $ext  = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            $allowed = ['jpg', 'jpeg', 'png'];

            if (!in_array($ext, $allowed, true)) {
                $msg = 'Format foto harus JPG atau PNG.';
            } elseif ($file['size'] > 2 * 1024 * 1024) {
                $msg = 'Ukuran foto maksimal 2MB.';
            } else {
                $userDir = __DIR__ . '/../../public/uploads/users';
                if (!is_dir($userDir)) @mkdir($userDir, 0775, true);
                
                $filename = 'user_' . time() . '_' . rand(1000, 9999) . '.' . $ext;
                $targetPath = $userDir . '/' . $filename;
                
                if (move_uploaded_file($file['tmp_name'], $targetPath)) {
                    $photoPath = '/uploads/users/' . $filename;
                } else {
                    $msg = 'Gagal menyimpan file foto.';
                }
            }
        }
    }

    if ($msg === '' && $photoPath !== null) {
        try {
            $hash = password_hash($pass, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("
                INSERT INTO users (name, nip, phone, username, password_hash, photo_path, role, status, created_at)
                VALUES (:name, :nip, :phone, :usr, :pwd, :photo, :role, 'offline', CURRENT_TIMESTAMP)
            ");
            $stmt->execute([
                ':name'  => $name,
                ':nip'   => $nip,
                ':phone' => $phone,
                ':usr'   => $usern,
                ':pwd'   => $hash,
                ':photo' => $photoPath,
                ':role'  => $role
            ]);
            header('Location: data_petugas.php?add_success=1');
            exit;
        } catch (Exception $e) {
            $msg = 'Gagal menambah user: ' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tambah User - Admin</title>
    <link rel="stylesheet" href="../css/dashboard.css">
    <link rel="stylesheet" href="../css/user_form.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
</head>
<body>

<div class="app">
    <?php $activePage = 'tambah_petugas'; ?>
    <?php require_once '../../admin/components/sidebar.php'; ?>

    <main class="main">
        <header class="header">
            <div class="header-left">
                <h1 class="page-title">Tambah User Baru</h1>
            </div>
        </header>

        <div class="form-container">
            <div class="form-card">
                <div class="form-header">
                    <div class="form-icon">
                        <i class="fas fa-user-plus" style="font-size: 28px; color: white;"></i>
                    </div>
                    <h2>Tambah User Baru</h2>
                    <p>Isi form di bawah untuk menambahkan user baru ke sistem</p>
                </div>

                <?php if ($msg !== ''): ?>
                    <div class="alert alert-error">
                        <i class="fas fa-exclamation-circle alert-icon"></i>
                        <?php echo safeText($msg); ?>
                    </div>
                <?php endif; ?>

                <form method="post" action="" enctype="multipart/form-data">
                    <div class="form-group">
                        <label>Pilih Role / Peran <span class="required">*</span></label>
                        <div class="option-cards">
                            <label class="option-card <?php echo $old['role'] === 'petugas' ? 'selected' : ''; ?>">
                                <input type="radio" name="role" value="petugas" <?php echo $old['role'] === 'petugas' ? 'checked' : ''; ?> onchange="updateRoleSelection()">
                                <div class="icon"><i class="fas fa-hard-hat"></i></div>
                                <div class="title">Petugas</div>
                            </label>
                            <label class="option-card <?php echo $old['role'] === 'supervisor' ? 'selected' : ''; ?>">
                                <input type="radio" name="role" value="supervisor" <?php echo $old['role'] === 'supervisor' ? 'checked' : ''; ?> onchange="updateRoleSelection()">
                                <div class="icon"><i class="fas fa-user-tie"></i></div>
                                <div class="title">Supervisor</div>
                            </label>
                            <label class="option-card <?php echo $old['role'] === 'admin' ? 'selected' : ''; ?>">
                                <input type="radio" name="role" value="admin" <?php echo $old['role'] === 'admin' ? 'checked' : ''; ?> onchange="updateRoleSelection()">
                                <div class="icon"><i class="fas fa-user-shield"></i></div>
                                <div class="title">Admin</div>
                            </label>
                        </div>
                    </div>

                    <div class="form-grid">
                        <div class="form-group">
                            <label>Nama Lengkap <span class="required">*</span></label>
                            <input type="text" name="name" class="form-input" placeholder="Masukkan nama lengkap"
                                   value="<?php echo safeText($old['name']); ?>" required>
                        </div>

                        <div class="form-group">
                            <label>NIP / No. Identitas <span class="required">*</span></label>
                            <input type="number" name="nip" class="form-input" placeholder="Masukkan NIP"
                                   value="<?php echo safeText($old['nip']); ?>" required>
                        </div>

                        <div class="form-group">
                            <label>Username <span class="required">*</span></label>
                            <input type="text" name="username" class="form-input" placeholder="Masukkan username"
                                   value="<?php echo safeText($old['username']); ?>" required>
                        </div>

                        <div class="form-group">
                            <label>Password <span class="required">*</span></label>
                            <input type="password" name="password" class="form-input" placeholder="Masukkan password" required>
                        </div>

                        <div class="form-group full-width">
                            <label>No. Handphone</label>
                            <input type="number" name="phone" class="form-input" placeholder="Contoh: 081234567890"
                                   value="<?php echo safeText($old['phone']); ?>"
                                   oninput="if(this.value.length > 15) this.value = this.value.slice(0, 15);">
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Foto Profil (Opsional)</label>
                        <div class="file-upload">
                            <div class="upload-icon">
                                <i class="fas fa-cloud-upload-alt"></i>
                            </div>
                            <div class="upload-text">
                                <strong>Klik untuk upload</strong> atau drag & drop<br>
                                <small>JPG, PNG (Maks. 2MB)</small>
                            </div>
                            <input type="file" name="photo" accept=".jpg,.jpeg,.png">
                        </div>
                    </div>

                    <div class="btn-row center">
                        <a href="data_petugas.php" class="btn btn-secondary">
                            <i class="fas fa-arrow-left"></i> Kembali
                        </a>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save"></i> Simpan User
                        </button>
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
