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
    $schedule_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($schedule_id <= 0) {
    die("ID jadwal tidak valid");
}

// ====================
// Ambil Data Jadwal
// ====================
$stmt = $pdo->prepare("
    SELECT s.*, u.name, u.nip
    FROM schedules s
    JOIN users u ON s.user_id = u.id
    WHERE s.id = :id
");
$stmt->execute([':id' => $schedule_id]);
$sched = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$sched) die("Jadwal tidak ditemukan.");

// ====================
// Ambil daftar zona
// ====================
    $stmt = $pdo->query("SELECT id, rw_code, name FROM zones ORDER BY rw_code ASC");
    $zones = $stmt->fetchAll(PDO::FETCH_ASSOC);

$success = false;
$errorMsg = null;

// ====================
// PROSES UPDATE/HAPUS
// ====================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($_POST['action'] === 'save') {
        $zone_id    = (int)$_POST['zone_id'];
        $work_date  = $_POST['work_date'];
        $start_time = $_POST['start_time'];
        $end_time   = $_POST['end_time'];
        $desc       = trim($_POST['description']);

        if ($zone_id > 0 && $work_date && $start_time && $end_time) {
            $result = $scheduleController->updateSchedule($schedule_id, [
                'user_id'    => (int)$sched['user_id'],
                'zone_id'    => $zone_id,
                'work_date'  => $work_date,
                'start_time' => $start_time,
                'end_time'   => $end_time,
                'shift_type' => $sched['shift_type'] ?? 'custom',
                'day_type'   => $sched['day_type'] ?? null,
                'description'=> $desc,
            ]);
            if (!empty($result['success'])) {
                $success = true;
            } else {
                $errorMsg = $result['errors']['schedule'] ?? $result['errors']['zone'] ?? $result['errors']['system'] ?? 'Gagal menyimpan jadwal';
            }
        } else {
            $errorMsg = "Semua data wajib diisi.";
        }

    } elseif ($_POST['action'] === 'delete') {
        $pdo->prepare("DELETE FROM schedules WHERE id=:sid")
            ->execute([':sid' => $schedule_id]);

        header("Location: jadwal_penugasan.php?msg=deleted");
        exit;
    }
}

// Reload setelah update
$stmt = $pdo->prepare("
    SELECT s.*, u.name, u.nip
    FROM schedules s
    JOIN users u ON s.user_id = u.id
    WHERE s.id = :id
");
$stmt->execute([':id' => $schedule_id]);
$sched = $stmt->fetch(PDO::FETCH_ASSOC);

// Helper
function safeText($txt) { return htmlspecialchars($txt ?? '', ENT_QUOTES, 'UTF-8'); }
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Edit Jadwal - Admin</title>

    <link rel="stylesheet" href="../css/dashboard.css">
    <link rel="stylesheet" href="../css/jadwal.css">
</head>

<body>
<div class="app">

<aside class="sidebar">
    <div class="brand">
        <div class="brand-title">Admin</div>
        <div class="brand-sub">Kelurahan Sunter Jaya</div>
    </div>

    <nav class="nav">
        <a href="dashboard.php" class="nav-item">Dashboard</a>
        <div class="nav-section">AKUN</div>
        <a href="../../auth/logout.php" class="nav-item">Logout</a>
    </nav>
</aside>

<main class="main">

<header class="header">
    <h1 class="page-title">Edit Jadwal</h1>
</header>

<div class="title-center">FORM EDIT JADWAL</div>

<?php if ($success): ?>
    <div class="alert-success">Jadwal berhasil diperbarui!</div>
<?php elseif ($errorMsg): ?>
    <div class="alert-error"><?= safeText($errorMsg) ?></div>
<?php endif; ?>

<section class="form-box">
<form method="post">

    <label>Nama Petugas</label>
    <input type="text" disabled value="<?= safeText($sched['name']) ?>">

    <label>NIP</label>
    <input type="text" disabled value="<?= safeText($sched['nip']) ?>">

    <label>Zona (RW)</label>
    <select name="zone_id" required>
        <?php foreach ($zones as $z): ?>
            <option value="<?= $z['id'] ?>" <?= $sched['zone_id']==$z['id']?'selected':'' ?>>
                <?= safeText($z['rw_code'].' - '.$z['name']) ?>
            </option>
        <?php endforeach ?>
    </select>

    <label>Tanggal Kerja</label>
    <input type="date" name="work_date" required value="<?= safeText($sched['work_date']) ?>">

    <label>Waktu Mulai</label>
    <input type="time" name="start_time" required value="<?= safeText(substr($sched['start_time'],0,5)) ?>">

    <label>Waktu Selesai</label>
    <input type="time" name="end_time" required value="<?= safeText(substr($sched['end_time'],0,5)) ?>">

    <label>Deskripsi Tugas</label>
    <textarea name="description"><?= safeText($sched['description']) ?></textarea>

    <div class="btn-row-2">
        <button class="btn-delete" type="submit" name="action" value="delete">HAPUS</button>
        <button class="btn-submit" type="submit" name="action" value="save">SIMPAN PERUBAHAN</button>
    </div>

</form>
</section>

</main>

</div>
</body>
</html>
