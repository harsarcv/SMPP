<?php
session_start();
require_once '../../config/database.php';
require_once '../../helpers/session.php';
enforce_session(['supervisor']);

$brandSub = "Kelurahan Sunter Jaya";
$admName  = $_SESSION['name'] ?? 'Supervisor';
$admNip   = $_SESSION['nip'] ?? 'SPV001';
$supervisorId = $_SESSION['user_id'] ?? 0;

$pdo = Database::getInstance()->getConnection();
$filter = $_GET['filter'] ?? 'nama';

// =============== GET SUPERVISOR'S GROUP ===============
$supervisorGroupId = null;
try {
    $grpStmt = $pdo->prepare("SELECT id FROM `groups` WHERE supervisor_id = :sup_id LIMIT 1");
    $grpStmt->execute([':sup_id' => $supervisorId]);
    $grpResult = $grpStmt->fetch(PDO::FETCH_ASSOC);
    if ($grpResult) {
        $supervisorGroupId = $grpResult['id'];
    }
} catch (Exception $e) {
    // Supervisor has no group
}

$orderSql = "ORDER BY u.name ASC";
if ($filter === 'rw')      $orderSql = "ORDER BY z.rw_code ASC, u.name ASC";
if ($filter === 'tanggal') $orderSql = "ORDER BY s.work_date DESC, s.start_time ASC";
if ($filter === 'shift')   $orderSql = "ORDER BY s.start_time ASC";

// Build group filter condition
$groupCondition = "";
$groupParams = [];
if ($supervisorGroupId) {
    $groupCondition = " AND u.group_id = :group_id ";
    $groupParams[':group_id'] = $supervisorGroupId;
} else {
    $groupCondition = " AND 1 = 0 "; // No group, show nothing
}

try {
    $stmt = $pdo->prepare("
        SELECT
            s.id,
            u.name AS petugas_name,
            u.nip,
            DATE_FORMAT(s.work_date, '%d %b %Y') AS tanggal,
            DAYNAME(s.work_date) AS hari,
            CONCAT(DATE_FORMAT(s.shift_start, '%H:%i'), ' - ', DATE_FORMAT(s.shift_end, '%H:%i')) AS shift,
            s.shift_start as start_time,
            s.shift_end as end_time,
            z.rw_code,
            z.name as zona_name,
            CASE 
                WHEN s.work_date < CURRENT_DATE THEN 'completed'
                WHEN s.work_date = CURRENT_DATE AND s.shift_end < CURRENT_TIME THEN 'completed'
                WHEN s.work_date = CURRENT_DATE AND s.shift_start <= CURRENT_TIME AND s.shift_end >= CURRENT_TIME THEN 'active'
                ELSE 'upcoming'
            END as status
        FROM schedules s
        JOIN users u ON u.id = s.user_id
        JOIN zones z ON z.id = s.zone_id
        WHERE u.role = 'petugas' 
          AND s.work_date >= DATE_SUB(CURRENT_DATE, INTERVAL 1 DAY)
          $groupCondition
        $orderSql
    ");
    $stmt->execute($groupParams);
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $data = [];
}

$todayCount = count($data);

function safeText($t){ return htmlspecialchars($t??'', ENT_QUOTES, 'UTF-8'); }

function formatHari($hari) {
    $hariMap = ['Monday'=>'Senin','Tuesday'=>'Selasa','Wednesday'=>'Rabu','Thursday'=>'Kamis','Friday'=>'Jumat','Saturday'=>'Sabtu','Sunday'=>'Minggu'];
    return $hariMap[trim($hari)] ?? $hari;
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Jadwal Petugas - Supervisor</title>
    <link rel="stylesheet" href="../css/dashboard.css">
    <link rel="stylesheet" href="../css/jadwal_penugasan.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
</head>
<body>
<div class="layout">

<?php $activePage = 'jadwal_penugasan'; ?>
<?php require_once '../../supervisor/components/sidebar.php'; ?>

<main class="content">

<header class="header">
    <h1 class="page-title">Monitoring Jadwal</h1>
    <div class="header-right">
        <div class="date-badge">
            <i class="fas fa-calendar-alt"></i> <?php echo date('d F Y'); ?>
        </div>
    </div>
</header>

<div class="title-center">MONITORING PENUGASAN</div>

<!-- Statistics Cards -->
<div class="stats-grid">
    <div class="stat-card total">
        <div class="stat-icon"><i class="fas fa-users"></i></div>
        <div class="stat-info">
            <h3><?php echo $todayCount; ?></h3>
            <p>Total Jadwal</p>
        </div>
    </div>
    <?php
    $active = 0; $upcoming = 0; $completed = 0;
    foreach ($data as $row) {
        if($row['status'] == 'active') $active++;
        if($row['status'] == 'upcoming') $upcoming++;
        if($row['status'] == 'completed') $completed++;
    }
    ?>
    <div class="stat-card active">
        <div class="stat-icon"><i class="fas fa-running"></i></div>
        <div class="stat-info">
            <h3><?php echo $active; ?></h3>
            <p>Sedang Bertugas</p>
        </div>
    </div>
    <div class="stat-card upcoming">
        <div class="stat-icon"><i class="fas fa-clock"></i></div>
        <div class="stat-info">
            <h3><?php echo $upcoming; ?></h3>
            <p>Shift Berikutnya</p>
        </div>
    </div>
</div>

<!-- Main Content -->
<section class="schedule-section">
    <div class="toolbar">
        <h4 class="toolbar-title"><i class="fas fa-list-alt"></i> Daftar Penugasan</h4>
        <div class="toolbar-actions">
            <select id="filterSelect" class="filter-select" onchange="applyFilter()">
                <option value="nama" <?= $filter==='nama'?'selected':'' ?>>Urutkan: Nama</option>
                <option value="rw" <?= $filter==='rw'?'selected':'' ?>>Urutkan: RW/Zona</option>
                <option value="shift" <?= $filter==='shift'?'selected':'' ?>>Urutkan: Shift</option>
            </select>
            <button class="btn-refresh" onclick="location.reload()"><i class="fas fa-sync"></i></button>
        </div>
    </div>
    
    <div class="table-responsive">
        <?php if ($todayCount === 0): ?>
            <div class="empty-view">
                <i class="fas fa-calendar-day"></i>
                <p>Tidak ada jadwal penugasan aktif hari ini.</p>
            </div>
        <?php else: ?>
            <table class="sv-jadwal-table">
                <thead>
                    <tr>
                        <th>Petugas</th>
                        <th>Tanggal / Hari</th>
                        <th>Jam Shift</th>
                        <th>Zona Tugas</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($data as $row): 
                        $st = $row['status'];
                        $stClass = $st === 'active' ? 'st-active' : ($st === 'completed' ? 'st-done' : 'st-wait');
                        $stText = $st === 'active' ? 'Aktif' : ($st === 'completed' ? 'Selesai' : 'Menunggu');
                    ?>
                        <tr>
                            <td>
                                <div class="fw-bold"><?= safeText($row['petugas_name']) ?></div>
                                <div class="fw-light small text-muted"><?= safeText($row['nip']) ?></div>
                            </td>
                            <td>
                                <div><?= safeText($row['tanggal']) ?></div>
                                <div class="small-badge"><?= formatHari($row['hari']) ?></div>
                            </td>
                            <td>
                                <div class="shift-box">
                                    <i class="far fa-clock"></i> <?= safeText($row['shift']) ?>
                                </div>
                            </td>
                            <td>
                                <div class="zone-box">
                                    <strong>RW <?= safeText($row['rw_code']) ?></strong> - <?= safeText($row['zona_name']) ?>
                                </div>
                            </td>
                            <td>
                                <span class="status-pill <?= $stClass ?>"><?= $stText ?></span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</section>

</main>
</div>

<script>
function applyFilter() {
    const v = document.getElementById('filterSelect').value;
    window.location.href = '?filter=' + v;
}
// Auto-refresh stats
setTimeout(() => location.reload(), 60000);
</script>
</body>
</html>
