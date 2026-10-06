<?php
session_start();
require_once '../../config/database.php';
require_once '../../helpers/session.php';
enforce_session(['admin']);

// Auth admin only
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: ' . get_base_url() . '/auth/login.php');
    exit;
}

$admName = $_SESSION['name'] ?? 'Admin';
$admNip  = $_SESSION['nip'] ?? 'ADMIN001';

$pdo = Database::getInstance()->getConnection();
require_once '../../admin/controllers/JadwalController.php';
$scheduleController = new ScheduleController();

// ===============================================
// Ambil daftar petugas untuk dropdown
// ===============================================
$stmt = $pdo->query("SELECT id, name, nip FROM users WHERE role = 'petugas' AND status IN ('online', 'offline')");
$petugas_list = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ===============================================
// Ambil daftar zona (RW) dari PostGIS
// ===============================================
$stmt = $pdo->query("SELECT id, rw_code, name FROM zones ORDER BY rw_code ASC");
$zones = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ===============================================
// Handle Submit Form
// ===============================================
$success = false;
$errorMsg = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = [
        'user_id'    => (int)($_POST['user_id'] ?? 0),
        'zone_id'    => (int)($_POST['zone_id'] ?? 0),
        'work_date'  => $_POST['work_date'] ?? null,
        'start_time' => $_POST['start_time'] ?? null,
        'end_time'   => $_POST['end_time'] ?? null,
        'shift_type' => $_POST['shift_type'] ?? 'custom',
        'day_type'   => null,
        'description'=> trim($_POST['description'] ?? ''),
        'created_by' => $_SESSION['user_id'] ?? null,
    ];

    $result = $scheduleController->createSchedule($data);
    if (!empty($result['success'])) {
        $success = true;
    } else {
        $errorMsg = ($result['errors']['schedule'] ?? $result['errors']['zone'] ?? $result['errors']['system'] ?? 'Gagal membuat jadwal');
    }
}

?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Buat Jadwal - Admin</title>

    <link rel="stylesheet" href="../css/dashboard.css">
    <link rel="stylesheet" href="../css/jadwal.css">
</head>

<body>
<div class="app">

    <?php $activePage = 'buat_jadwal'; ?>
    <?php require_once '../../admin/components/sidebar.php'; ?>
    
    <main class="main">

        <header class="header">
            <div class="header-left">
                <h1 class="page-title">Tambah Jadwal</h1>
            </div>

            <div class="header-right">
                <div class="profile">
                    <div class="profile-text">
                        <div class="profile-name"><?php echo safeText($admName); ?></div>
                        <div class="profile-id"><?php echo safeText($admNip); ?></div>
                    </div>
                    <div class="avatar"></div>
                </div>
            </div>
        </header>

        <div class="title-center">FORM JADWAL PETUGAS</div>

        <!-- Success/Error Popup will be shown via JS -->
        <?php if ($success): ?>
            <script>
                document.addEventListener('DOMContentLoaded', function() {
                    showPopup('success', 'Jadwal Berhasil Ditambahkan!', 'Jadwal petugas telah berhasil dibuat.');
                });
            </script>
        <?php elseif ($errorMsg): ?>
            <script>
                document.addEventListener('DOMContentLoaded', function() {
                    showPopup('error', 'Gagal Membuat Jadwal', '<?php echo addslashes($errorMsg); ?>');
                });
            </script>
        <?php endif; ?>

        <section class="form-box">
            <div class="form-title">BUAT JADWAL PENUGASAN BARU</div>
            
            <?php if (isset($_GET['success']) && $_GET['success'] == '1'): ?>
                <div class="success-message">
                    <i class="fas fa-check-circle"></i>
                    Jadwal berhasil dibuat!
                </div>
            <?php endif; ?>
            
            <form method="post" id="jadwalForm">
                <div class="form-grid">
                    <!-- Kolom Kiri -->
                    <div class="form-group">
                        <label for="user_id" class="required">Pilih Petugas</label>
                        <select name="user_id" id="user_id" required>
                            <option value="" disabled selected>-- Pilih Petugas --</option>
                            <?php foreach ($petugas_list as $p): ?>
                                <option value="<?= (int)$p['id'] ?>"
                                    <?= (isset($_POST['user_id']) && $_POST['user_id'] == $p['id']) ? 'selected' : '' ?>>
                                    <?= safeText($p['name'] . " (" . $p['nip'] . ")") ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="error-message">Silakan pilih petugas</div>
                    </div>
                    
                    <!-- Kolom Kanan -->
                    <div class="form-group">
                        <label for="zone_id" class="required">Pilih Zona (RW)</label>
                        <select name="zone_id" id="zone_id" required>
                            <option value="" disabled selected>-- Pilih RW --</option>
                            <?php foreach ($zones as $z): ?>
                                <option value="<?= (int)$z['id'] ?>"
                                    <?= (isset($_POST['zone_id']) && $_POST['zone_id'] == $z['id']) ? 'selected' : '' ?>>
                                    <?= safeText($z['rw_code'] . " - " . $z['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="error-message">Silakan pilih zona</div>
                    </div>
                    
                    <!-- Full Width Row -->
                    <div class="form-group full-width">
                        <label for="work_date" class="required">Tanggal Kerja</label>
                        <input type="date" 
                            name="work_date" 
                            id="work_date" 
                            required
                            min="<?= date('Y-m-d') ?>"
                            value="<?= $_POST['work_date'] ?? date('Y-m-d') ?>">
                        <div class="error-message">Silakan pilih tanggal yang valid</div>
                    </div>
                    
                    <!-- Waktu Mulai & Selesai -->
                    <div class="form-group">
                        <label for="start_time" class="required">Waktu Mulai</label>
                        <div class="input-with-icon">
                            <input type="time" 
                                name="start_time" 
                                id="start_time" 
                                required
                                value="<?= $_POST['start_time'] ?? '08:00' ?>">
                            <i class="fas fa-clock"></i>
                        </div>
                        <div class="error-message">Silakan pilih waktu mulai</div>
                    </div>
                    
                    <div class="form-group">
                        <label for="end_time" class="required">Waktu Selesai</label>
                        <div class="input-with-icon">
                            <input type="time" 
                                name="end_time" 
                                id="end_time" 
                                required
                                value="<?= $_POST['end_time'] ?? '16:00' ?>">
                            <i class="fas fa-clock"></i>
                        </div>
                        <div class="error-message">Silakan pilih waktu selesai</div>
                    </div>
                    
                    <!-- Shift Type Options -->
                    <div class="form-group full-width">
                        <label for="shift_type">Jenis Shift</label>
                        <div class="shift-options">
                            <label class="shift-option <?= ($_POST['shift_type'] ?? 'custom') == 'shift1' ? 'active' : '' ?>">
                                <input type="radio" name="shift_type" value="shift1" 
                                    <?= ($_POST['shift_type'] ?? '') == 'shift1' ? 'checked' : '' ?>>
                                Shift Pagi<br>
                                <small>06:00 - 14:00</small>
                            </label>
                            <label class="shift-option <?= ($_POST['shift_type'] ?? 'custom') == 'shift2' ? 'active' : '' ?>">
                                <input type="radio" name="shift_type" value="shift2"
                                    <?= ($_POST['shift_type'] ?? '') == 'shift2' ? 'checked' : '' ?>>
                                Shift Siang<br>
                                <small>15:00 - 22:00</small>
                            </label>
                            <label class="shift-option <?= ($_POST['shift_type'] ?? 'custom') == 'custom' ? 'active' : '' ?>">
                                <input type="radio" name="shift_type" value="custom" 
                                    <?= ($_POST['shift_type'] ?? 'custom') == 'custom' ? 'checked' : '' ?>>
                                Custom<br>
                                <small>Atur Waktu Sendiri</small>
                            </label>
                        </div>
                    </div>
                    
                    <!-- Deskripsi Tugas -->
                    <div class="form-group full-width">
                        <label for="description">Deskripsi Tugas</label>
                        <textarea name="description" 
                                id="description" 
                                placeholder="Isi detail tugas yang diberikan kepada petugas..."
                                rows="4"><?= $_POST['description'] ?? '' ?></textarea>
                    </div>
                </div>
                
                <button class="btn-submit" type="submit" id="submitBtn">
                    <span id="submitText">BUAT JADWAL</span>
                    <span id="loadingSpinner" class="loading-spinner" style="display: none;"></span>
                </button>
            </form>
        </section>

    </main>

</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const form = document.getElementById('jadwalForm');
    const submitBtn = document.getElementById('submitBtn');
    const submitText = document.getElementById('submitText');
    const loadingSpinner = document.getElementById('loadingSpinner');
    
    // Auto-set waktu berdasarkan shift type
    const shiftOptions = document.querySelectorAll('input[name="shift_type"]');
    const startTimeInput = document.getElementById('start_time');
    const endTimeInput = document.getElementById('end_time');
    
    shiftOptions.forEach(option => {
        option.addEventListener('change', function() {
            if (this.value === 'shift1') {
                startTimeInput.value = '06:00';
                endTimeInput.value = '14:00';
            } else if (this.value === 'shift2') {
                startTimeInput.value = '15:00';
                endTimeInput.value = '22:00';
            }
        });
    });
    
    // Toggle active class pada shift options
    document.querySelectorAll('.shift-option').forEach(option => {
        option.addEventListener('click', function() {
            document.querySelectorAll('.shift-option').forEach(opt => {
                opt.classList.remove('active');
            });
            this.classList.add('active');
        });
    });
    
    // Validasi waktu
    function validateTime() {
        const start = startTimeInput.value;
        const end = endTimeInput.value;
        
        if (start && end && start >= end) {
            alert('Waktu selesai harus setelah waktu mulai');
            return false;
        }
        return true;
    }
    
    // Validasi tanggal
    const workDateInput = document.getElementById('work_date');
    workDateInput.min = new Date().toISOString().split('T')[0];
    
    // Form submission handler
    form.addEventListener('submit', function(e) {
        if (!validateTime()) {
            e.preventDefault();
            return;
        }
        
        // Show loading state
        submitText.style.display = 'none';
        loadingSpinner.style.display = 'inline-block';
        submitBtn.disabled = true;
        
        // Allow form to submit
        return true;
    });
    
    // Real-time validation
    const inputs = form.querySelectorAll('input, select, textarea');
    inputs.forEach(input => {
        input.addEventListener('blur', function() {
            if (this.hasAttribute('required') && !this.value) {
                this.style.borderColor = '#ff4757';
            } else {
                this.style.borderColor = '#d1ecf1';
            }
        });
        
        input.addEventListener('input', function() {
            this.style.borderColor = this.validity.valid ? '#d1ecf1' : '#ff4757';
        });
    });
});
</script>

<!-- Popup Modal -->
<div id="popupModal" class="popup-overlay" style="display: none;">
    <div class="popup-box">
        <div class="popup-icon" id="popupIcon"></div>
        <h3 id="popupTitle"></h3>
        <p id="popupMessage"></p>
        <button onclick="closePopup()" class="btn-popup-close">OK</button>
    </div>
</div>

<style>
.popup-overlay {
    position: fixed;
    top: 0; left: 0;
    width: 100%; height: 100%;
    background: rgba(0,0,0,0.5);
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 9999;
}
.popup-box {
    background: white;
    padding: 30px 40px;
    border-radius: 12px;
    text-align: center;
    max-width: 400px;
    animation: popIn 0.3s ease;
}
@keyframes popIn {
    from { transform: scale(0.8); opacity: 0; }
    to { transform: scale(1); opacity: 1; }
}
.popup-icon {
    font-size: 60px;
    margin-bottom: 15px;
}
.popup-icon.success { color: #28a745; }
.popup-icon.error { color: #dc3545; }
.popup-box h3 {
    margin: 0 0 10px 0;
    color: #333;
}
.popup-box p {
    margin: 0 0 20px 0;
    color: #666;
}
.btn-popup-close {
    background: #007bff;
    color: white;
    border: none;
    padding: 10px 30px;
    border-radius: 6px;
    cursor: pointer;
    font-size: 14px;
}
.btn-popup-close:hover {
    background: #0056b3;
}
</style>

<script>
function showPopup(type, title, message) {
    document.getElementById('popupIcon').className = 'popup-icon ' + type;
    document.getElementById('popupIcon').innerHTML = type === 'success' ? '✓' : '✕';
    document.getElementById('popupTitle').textContent = title;
    document.getElementById('popupMessage').textContent = message;
    document.getElementById('popupModal').style.display = 'flex';
}
function closePopup() {
    document.getElementById('popupModal').style.display = 'none';
}
</script>

</body>
</html>
