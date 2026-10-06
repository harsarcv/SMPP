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

$brandSub = "Kelurahan Sunter Jaya";
$admName = $_SESSION['name'] ?? 'Admin';
$admNip = $_SESSION['nip'] ?? 'ADMIN001';

$pdo = Database::getInstance()->getConnection();

// FILTER
$filter = $_GET['filter'] ?? 'terbaru';
$selectedDate = $_GET['date'] ?? date('Y-m-d');

$orderSql = "ORDER BY s.created_at DESC";
$orderLabel = "Terbaru";
if ($filter === 'nama') {
    $orderSql = "ORDER BY u.name ASC";
    $orderLabel = "Nama";
}
if ($filter === 'rw') {
    $orderSql = "ORDER BY z.rw_code ASC, u.name ASC";
    $orderLabel = "RW";
}
if ($filter === 'tanggal') {
    $orderSql = "ORDER BY s.work_date DESC, s.start_time ASC";
    $orderLabel = "Tanggal";
}
if ($filter === 'shift') {
    $orderSql = "ORDER BY s.start_time ASC";
    $orderLabel = "Shift";
}

// Ambil jadwal dengan status laporan
try {
    $stmt = $pdo->prepare("
        SELECT
            s.id,
            u.id AS user_id,
            u.name AS petugas_name,
            u.nip,
            u.role,
            u.status AS user_status,
            u.last_location_update,
            s.description AS deskripsi_tugas,
            DATE_FORMAT(s.work_date, '%d %b %Y') AS tanggal,
            DATE_FORMAT(s.work_date, '%Y-%m-%d') AS tanggal_raw,
            DAYNAME(s.work_date) AS hari,
            CONCAT(DATE_FORMAT(s.shift_start, '%H:%i'), ' - ', DATE_FORMAT(s.shift_end, '%H:%i')) AS shift,
            s.shift_start as start_time,
            s.shift_end as end_time,
            z.rw_code,
            z.name as zona_name,
            r.id as report_id,
            r.status as report_status,
            CASE 
                WHEN r.status = 'approved' THEN 'approved'
                WHEN r.status = 'rejected' THEN 'rejected'
                WHEN r.status = 'pending' THEN 'pending'
                WHEN s.work_date < CURRENT_DATE THEN 'expired'
                WHEN s.work_date = CURRENT_DATE AND s.shift_end < CURRENT_TIME THEN 'expired'
                WHEN s.work_date = CURRENT_DATE AND s.shift_start <= CURRENT_TIME AND s.shift_end >= CURRENT_TIME THEN 'active'
                ELSE 'upcoming'
            END as task_status,
            CASE
                WHEN r.id IS NOT NULL THEN 'sudah_lapor'
                WHEN u.status = 'online' AND s.work_date = CURRENT_DATE AND s.shift_start <= CURRENT_TIME AND s.shift_end >= CURRENT_TIME THEN 'sedang_mengerjakan'
                WHEN s.work_date = CURRENT_DATE AND s.shift_start <= CURRENT_TIME THEN 'belum_lapor'
                ELSE 'belum_mulai'
            END as work_status
        FROM schedules s
        JOIN users u ON u.id = s.user_id
        JOIN zones z ON z.id = s.zone_id
        LEFT JOIN reports r ON r.schedule_id = s.id
        WHERE s.work_date = :selected_date
        $orderSql
    ");
    $stmt->execute([':selected_date' => $selectedDate]);
    $allData = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die('<div style="margin: 20px; padding: 20px; background: #ffebee; border: 1px solid #f44336; color: #b71c1c; font-family: sans-serif; border-radius: 8px;">
        <h3 style="margin-top: 0;"><i class="fas fa-exclamation-triangle"></i> Terjadi Kesalahan Database</h3>
        <p>' . htmlspecialchars($e->getMessage()) . '</p>
        <p>Silakan hubungi administrator atau coba muat ulang halaman.</p>
    </div>');
}

// Pagination
$itemsPerPage = 10;
$totalItems = count($allData);
$totalPages = ceil($totalItems / $itemsPerPage);
$currentPage = isset($_GET['page']) ? max(1, min($totalPages, (int)$_GET['page'])) : 1;
$offset = ($currentPage - 1) * $itemsPerPage;
$data = array_slice($allData, $offset, $itemsPerPage);

$todayCount = $totalItems;

function safeText($t){ return htmlspecialchars($t??'', ENT_QUOTES, 'UTF-8'); }

// Helper untuk menentukan status badge (task_status based on report)
function getStatusBadge($status) {
    switch ($status) {
        case 'approved': 
            return '<span class="status-badge-new status-approved" style="background:#22c55e;color:white;">
                        <i class="fas fa-check-circle"></i> Diterima
                    </span>';
        case 'rejected': 
            return '<span class="status-badge-new status-rejected" style="background:#ef4444;color:white;">
                        <i class="fas fa-times-circle"></i> Ditolak
                    </span>';
        case 'pending': 
            return '<span class="status-badge-new status-pending" style="background:#f59e0b;color:white;">
                        <i class="fas fa-hourglass-half"></i> Menunggu Validasi
                    </span>';
        case 'active': 
            return '<span class="status-badge-new status-active">
                        <i class="fas fa-play-circle"></i> Sedang Berjalan
                    </span>';
        case 'upcoming': 
            return '<span class="status-badge-new status-upcoming">
                        <i class="fas fa-clock"></i> Akan Datang
                    </span>';
        case 'expired': 
            return '<span class="status-badge-new status-expired" style="background:#94a3b8;color:white;">
                        <i class="fas fa-exclamation-circle"></i> Belum Lapor
                    </span>';
        default: return '';
    }
}

// Helper untuk role badge
function getRoleBadge($role) {
    if ($role === 'supervisor') {
        return '<span style="background:#8b5cf6;color:white;padding:3px 8px;border-radius:4px;font-size:11px;font-weight:600;">
                    <i class="fas fa-user-tie"></i> Supervisor
                </span>';
    } else {
        return '<span style="background:#0ea5e9;color:white;padding:3px 8px;border-radius:4px;font-size:11px;font-weight:600;">
                    <i class="fas fa-user-hard-hat"></i> Petugas
                </span>';
    }
}

// Helper untuk work status badge (sedang mengerjakan / belum)
function getWorkStatusBadge($workStatus, $userStatus = 'offline') {
    switch ($workStatus) {
        case 'sedang_mengerjakan':
            return '<span style="background:#22c55e;color:white;padding:4px 10px;border-radius:6px;font-size:11px;font-weight:600;display:inline-flex;align-items:center;gap:4px;">
                        <span style="width:8px;height:8px;background:#fff;border-radius:50%;animation:pulse 1.5s infinite;"></span>
                        Sedang Mengerjakan
                    </span>';
        case 'sudah_lapor':
            return '<span style="background:#3b82f6;color:white;padding:4px 10px;border-radius:6px;font-size:11px;font-weight:600;">
                        <i class="fas fa-check"></i> Sudah Lapor
                    </span>';
        case 'belum_lapor':
            return '<span style="background:#f59e0b;color:white;padding:4px 10px;border-radius:6px;font-size:11px;font-weight:600;">
                        <i class="fas fa-exclamation-triangle"></i> Belum Lapor
                    </span>';
        case 'belum_mulai':
        default:
            return '<span style="background:#94a3b8;color:white;padding:4px 10px;border-radius:6px;font-size:11px;font-weight:600;">
                        <i class="fas fa-clock"></i> Belum Mulai
                    </span>';
    }
}

// Helper untuk format hari
function formatHari($hari) {
    $hari = trim($hari);
    $hariMap = [
        'Monday' => 'Senin',
        'Tuesday' => 'Selasa',
        'Wednesday' => 'Rabu',
        'Thursday' => 'Kamis',
        'Friday' => 'Jumat',
        'Saturday' => 'Sabtu',
        'Sunday' => 'Minggu'
    ];
    return $hariMap[$hari] ?? $hari;
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Jadwal Petugas - Admin</title>
    <link rel="stylesheet" href="../css/dashboard.css">
    <link rel="stylesheet" href="../css/jadwal.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        /* Header dengan gaya konsisten */
        .header-actions-new {
            display: flex;
            align-items: center;
            gap: 20px;
        }
        
        .date-display-new {
            background: linear-gradient(135deg, #0a93b8 0%, #08738d 100%);
            color: white;
            padding: 10px 20px;
            border-radius: 10px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 10px;
            box-shadow: 0 4px 12px rgba(10, 147, 184, 0.3);
        }
        
        /* Filter dengan gaya konsisten */
        .filter-section {
            background: #ffffff;
            border-radius: 16px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 4px 12px rgba(10, 147, 184, 0.1);
            border: 1px solid #e1f5fe;
        }
        
        .filter-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-top: 15px;
        }
        
        .filter-group {
            margin-bottom: 0;
        }
        
        .filter-group label {
            font-weight: 600;
            color: #0b4050;
            margin-bottom: 8px;
            display: block;
            font-size: 14px;
        }
        
        .filter-select {
            width: 100%;
            padding: 12px 16px;
            border: 2px solid #d1ecf1;
            border-radius: 10px;
            font-size: 14px;
            background: #ffffff;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .filter-select:focus {
            outline: none;
            border-color: #0a93b8;
            box-shadow: 0 0 0 3px rgba(10, 147, 184, 0.2);
        }
        
        /* Info Cards */
        .info-cards {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 12px;
            margin: 20px 0;
        }
        
        .info-card {
            background: linear-gradient(135deg, #ffffff 0%, #f8fdff 100%);
            border: 1px solid #e1f5fe;
            border-radius: 12px;
            padding: 15px;
            text-align: center;
            box-shadow: 0 4px 12px rgba(10, 147, 184, 0.1);
            transition: all 0.3s ease;
        }
        
        .info-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 20px rgba(10, 147, 184, 0.2);
        }
        
        .info-card i {
            font-size: 24px;
            color: #0a93b8;
            margin-bottom: 8px;
        }
        
        .info-card-value {
            font-size: 24px;
            font-weight: 700;
            color: #0a4c66;
            margin: 5px 0;
        }
        
        .info-card-label {
            font-size: 12px;
            color: #666;
            font-weight: 600;
        }

        /* Table Styles */
        .jadwal-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 14px;
        }
        
        .jadwal-table thead th {
            background: linear-gradient(135deg, #0a93b8 0%, #08738d 100%);
            color: white;
            padding: 12px 10px;
            text-align: left;
            font-weight: 600;
            font-size: 13px;
            white-space: nowrap;
        }
        
        .jadwal-table tbody td {
            padding: 10px 8px;
            border-bottom: 1px solid #e5e7eb;
            vertical-align: middle;
        }
        
        .jadwal-table tbody tr:hover {
            background: #f8fafc;
        }

        /* Petugas Column */
        .petugas-info {
            display: flex;
            flex-direction: column;
            gap: 4px;
        }
        
        .petugas-name {
            font-weight: 600;
            color: #1e293b;
            font-size: 14px;
        }
        
        .petugas-nip {
            font-size: 12px;
            color: #64748b;
        }

        /* Status Badges */
        .status-badge-new {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 5px 10px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
            white-space: nowrap;
        }
        
        .status-active {
            background: #0ea5e9;
            color: white;
        }
        
        .status-upcoming {
            background: #6366f1;
            color: white;
        }

        /* Deskripsi Column */
        .deskripsi-tugas {
            font-size: 13px;
            color: #475569;
            max-width: 180px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        /* Zona Column */
        .zona-display {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }
        
        .rw-badge-new {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            background: #0a93b8;
            color: white;
            padding: 4px 10px;
            border-radius: 15px;
            font-size: 12px;
            font-weight: 600;
            width: fit-content;
        }
        
        .zona-name-display {
            font-size: 12px;
            color: #64748b;
        }

        /* Action Buttons */
        .action-buttons-new {
            display: flex;
            gap: 6px;
            margin-top: 6px;
        }
        
        .action-btn-new {
            padding: 5px 10px;
            border-radius: 6px;
            font-size: 11px;
            font-weight: 600;
            text-decoration: none;
            cursor: pointer;
            border: none;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            transition: all 0.2s;
        }
        
        .action-edit-new {
            background: #3b82f6;
            color: white;
        }
        
        .action-edit-new:hover {
            background: #2563eb;
        }
        
        .action-delete-new {
            background: #ef4444;
            color: white;
        }
        
        .action-delete-new:hover {
            background: #dc2626;
        }

        /* Date/Shift Display */
        .date-info, .shift-display {
            display: flex;
            flex-direction: column;
            gap: 3px;
        }
        
        .date-display, .shift-time {
            font-weight: 600;
            color: #0a93b8;
            font-size: 13px;
        }
        
        .day-display, .shift-hours {
            font-size: 11px;
            color: #64748b;
        }
    </style>
</head>

<body>
<div class="app">

<!-- SIDEBAR -->
<?php $activePage = 'jadwal_penugasan'; ?>
<?php require_once '../../admin/components/sidebar.php'; ?>

<!-- MAIN -->
<main class="main">

<header class="header">
    <h1 class="page-title">Jadwal Penugasan</h1>
    <div class="header-actions-new">
        <div class="date-display-new">
            <i class="fas fa-calendar-alt"></i>
            <?php echo date('d F Y'); ?>
        </div>
    </div>
</header>

<div class="title-center">JADWAL PENUGASAN PETUGAS</div>

<!-- Info Cards -->
<div class="info-cards">
    <div class="info-card">
        <i class="fas fa-users"></i>
        <div class="info-card-value"><?php echo $todayCount; ?></div>
        <div class="info-card-label">Total Jadwal</div>
    </div>
    
    <?php
    // Hitung statistik based on task_status
    $activeCount = 0;
    $upcomingCount = 0;
    $pendingCount = 0;
    $approvedCount = 0;
    
    foreach ($data as $row) {
        switch ($row['task_status']) {
            case 'active': $activeCount++; break;
            case 'upcoming': $upcomingCount++; break;
            case 'pending': $pendingCount++; break;
            case 'approved': $approvedCount++; break;
        }
    }
    ?>
    
    <div class="info-card">
        <i class="fas fa-play-circle"></i>
        <div class="info-card-value"><?php echo $activeCount; ?></div>
        <div class="info-card-label">Sedang Berjalan</div>
    </div>
    
    <div class="info-card">
        <i class="fas fa-clock"></i>
        <div class="info-card-value"><?php echo $upcomingCount; ?></div>
        <div class="info-card-label">Akan Datang</div>
    </div>
    
    <div class="info-card">
        <i class="fas fa-hourglass-half" style="color:#f59e0b;"></i>
        <div class="info-card-value"><?php echo $pendingCount; ?></div>
        <div class="info-card-label">Menunggu Validasi</div>
    </div>
    
    <div class="info-card">
        <i class="fas fa-check-circle" style="color:#22c55e;"></i>
        <div class="info-card-value"><?php echo $approvedCount; ?></div>
        <div class="info-card-label">Diterima</div>
    </div>
</div>

<!-- Filter Section -->
<section class="filter-section">
    <h4 class="section-title" style="margin-bottom: 15px;">
        <i class="fas fa-filter"></i> Filter Jadwal
    </h4>
    
    <div class="filter-grid">
        <div class="filter-group">
            <label for="filterSelect">Urutkan Berdasarkan</label>
            <select id="filterSelect" class="filter-select" onchange="applyFilter()">
                <option value="terbaru" <?= $filter==='terbaru'?'selected':'' ?>>Terbaru Ditambahkan</option>
                <option value="nama" <?= $filter==='nama'?'selected':'' ?>>Nama Petugas</option>
                <option value="rw" <?= $filter==='rw'?'selected':'' ?>>Zona (RW)</option>
                <option value="tanggal" <?= $filter==='tanggal'?'selected':'' ?>>Tanggal</option>
                <option value="shift" <?= $filter==='shift'?'selected':'' ?>>Waktu Shift</option>
            </select>
        </div>
        
        <div class="filter-group">
            <label for="dateRange">Rentang Tanggal</label>
            <input type="date" id="dateRange" class="filter-select" 
                   value="<?php echo htmlspecialchars($selectedDate); ?>"
                   onchange="filterByDate()">
        </div>
    </div>
    
    <div class="label-row" style="margin-top: 15px;">
        <span class="label-title"><?php echo $todayCount; ?> Jadwal Ditemukan</span>
        <span class="label-urut">
            <i class="fas fa-sort-amount-down"></i>
            Diurutkan berdasarkan: <?php echo $orderLabel; ?>
        </span>
    </div>
</section>

<!-- Result Box dengan Tabel -->
<div class="result-box">
    <?php if ($todayCount === 0): ?>
        <div class="table-empty-new">
            <i class="fas fa-calendar-times"></i>
            <div class="empty-title">Belum Ada Jadwal</div>
            <div class="empty-subtitle">Tambahkan jadwal baru untuk memulai penugasan</div>
            <a href="buat_jadwal.php" class="action-btn-new action-edit-new" style="text-decoration: none;">
                <i class="fas fa-plus-circle"></i> Buat Jadwal Baru
            </a>
        </div>
    <?php else: ?>
        <style>
            @keyframes pulse {
                0%, 100% { opacity: 1; }
                50% { opacity: 0.4; }
            }
        </style>
        <table class="jadwal-table">
            <thead>
                <tr>
                    <th><i class="fas fa-user-circle"></i> Petugas</th>
                    <th><i class="fas fa-calendar-day"></i> Tanggal</th>
                    <th><i class="fas fa-business-time"></i> Shift</th>
                    <th><i class="fas fa-file-alt"></i> Deskripsi Tugas</th>
                    <th><i class="fas fa-tasks"></i> Status Kerja</th>
                    <th><i class="fas fa-info-circle"></i> Status Laporan</th>
                    <th><i class="fas fa-map-marked-alt"></i> Zona & Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($data as $row): 
                    $statusBadge = getStatusBadge($row['task_status']);
                    $roleBadge = getRoleBadge($row['role']);
                    $workStatusBadge = getWorkStatusBadge($row['work_status'], $row['user_status']);
                    $formattedHari = formatHari($row['hari']);
                ?>
                    <tr>
                        <td>
                            <div class="petugas-info">
                                <div class="petugas-name">
                                    <?= safeText($row['petugas_name']) ?>
                                </div>
                                <div class="petugas-nip">
                                    <i class="fas fa-id-card"></i> <?= safeText($row['nip']) ?>
                                </div>
                                <?= $roleBadge ?>
                            </div>
                        </td>
                        <td>
                            <div class="date-info">
                                <div class="date-display">
                                    <?= safeText($row['tanggal']) ?>
                                </div>
                                <div class="day-display">
                                    <i class="fas fa-calendar-week"></i> <?= $formattedHari ?>
                                </div>
                            </div>
                        </td>
                        <td>
                            <div class="shift-display">
                                <div class="shift-time">
                                    <i class="fas fa-clock"></i> <?= safeText($row['shift']) ?>
                                </div>
                            </div>
                        </td>
                        <td>
                            <div class="deskripsi-tugas" style="max-width: 200px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;" title="<?= safeText($row['deskripsi_tugas']) ?>">
                                <?= !empty($row['deskripsi_tugas']) ? safeText($row['deskripsi_tugas']) : '<span style="color:#94a3b8;font-style:italic;">Tidak ada deskripsi</span>' ?>
                            </div>
                        </td>
                        <td>
                            <?= $workStatusBadge ?>
                        </td>
                        <td>
                            <?= $statusBadge ?>
                        </td>
                        <td>
                            <div class="zona-display">
                                <div class="rw-badge-new">
                                    <i class="fas fa-map-pin"></i> RW <?= safeText($row['rw_code']) ?>
                                </div>
                                <div class="zona-name-display">
                                    <?= safeText($row['zona_name']) ?>
                                </div>
                                <div class="action-buttons-new">
                                    <a href="edit_jadwal.php?id=<?= $row['id'] ?>" 
                                       class="action-btn-new action-edit-new">
                                        <i class="fas fa-edit"></i> Edit
                                    </a>
                                    <button class="action-btn-new action-delete-new" 
                                            onclick="confirmDelete(<?= $row['id'] ?>, '<?= safeText($row['petugas_name']) ?>')">
                                        <i class="fas fa-trash-alt"></i> Hapus
                                    </button>
                                </div>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        
        <!-- Pagination Controls -->
        <?php if ($totalPages > 1): ?>
        <div class="pagination-controls" style="display: flex; justify-content: center; align-items: center; gap: 8px; margin-top: 20px; flex-wrap: wrap;">
            <?php if ($currentPage > 1): ?>
                <a href="?filter=<?= $filter ?>&page=1" class="page-btn" style="padding: 8px 12px; background: #e2e8f0; border-radius: 6px; text-decoration: none; color: #475569; font-size: 13px;">
                    <i class="fas fa-angle-double-left"></i>
                </a>
                <a href="?filter=<?= $filter ?>&page=<?= $currentPage - 1 ?>" class="page-btn" style="padding: 8px 12px; background: #e2e8f0; border-radius: 6px; text-decoration: none; color: #475569; font-size: 13px;">
                    <i class="fas fa-angle-left"></i> Prev
                </a>
            <?php endif; ?>
            
            <?php 
            $startPage = max(1, $currentPage - 2);
            $endPage = min($totalPages, $currentPage + 2);
            for ($p = $startPage; $p <= $endPage; $p++): 
            ?>
                <a href="?filter=<?= $filter ?>&page=<?= $p ?>" 
                   class="page-btn <?= $p === $currentPage ? 'active' : '' ?>" 
                   style="padding: 8px 14px; background: <?= $p === $currentPage ? '#0a93b8' : '#e2e8f0' ?>; color: <?= $p === $currentPage ? 'white' : '#475569' ?>; border-radius: 6px; text-decoration: none; font-weight: <?= $p === $currentPage ? '600' : '400' ?>; font-size: 13px;">
                    <?= $p ?>
                </a>
            <?php endfor; ?>
            
            <?php if ($currentPage < $totalPages): ?>
                <a href="?filter=<?= $filter ?>&page=<?= $currentPage + 1 ?>" class="page-btn" style="padding: 8px 12px; background: #e2e8f0; border-radius: 6px; text-decoration: none; color: #475569; font-size: 13px;">
                    Next <i class="fas fa-angle-right"></i>
                </a>
                <a href="?filter=<?= $filter ?>&page=<?= $totalPages ?>" class="page-btn" style="padding: 8px 12px; background: #e2e8f0; border-radius: 6px; text-decoration: none; color: #475569; font-size: 13px;">
                    <i class="fas fa-angle-double-right"></i>
                </a>
            <?php endif; ?>
            
            <span style="margin-left: 15px; color: #64748b; font-size: 13px;">
                Halaman <?= $currentPage ?> dari <?= $totalPages ?> (<?= $totalItems ?> jadwal)
            </span>
        </div>
        <?php endif; ?>
        
        <a href="buat_jadwal.php" class="zoom-count-new">
            <i class="fas fa-plus"></i> Tambah Baru
        </a>
    <?php endif; ?>
</div>

<!-- Action Buttons -->
<div class="btn-row" style="margin-top: 20px;">
    <button class="btn-blue" onclick="location.href='buat_jadwal.php'">
        <i class="fas fa-plus-circle"></i> TAMBAH JADWAL BARU
    </button>
    <button class="btn-blue" onclick="printJadwal()">
        <i class="fas fa-print"></i> CETAK JADWAL
    </button>
    <button class="btn-blue" onclick="exportToExcel()">
        <i class="fas fa-file-excel"></i> EXPORT EXCEL
    </button>
</div>

</main>
</div>

<script>
// Fungsi untuk filter
function applyFilter() {
    const filterValue = document.getElementById('filterSelect').value;
    const date = document.getElementById('dateRange').value;
    window.location.href = 'jadwal_penugasan.php?filter=' + filterValue + '&date=' + date;
}

// Fungsi untuk filter by date
function filterByDate() {
    const date = document.getElementById('dateRange').value;
    const filter = document.getElementById('filterSelect').value;
    window.location.href = 'jadwal_penugasan.php?filter=' + filter + '&date=' + date;
}

// Fungsi konfirmasi hapus
function confirmDelete(id, nama) {
    if (confirm(`Apakah Anda yakin ingin menghapus jadwal untuk ${nama}?`)) {
        deleteJadwal(id);
    }
}

// Fungsi hapus jadwal
async function deleteJadwal(id) {
    try {
        const response = await fetch('delete_jadwal.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: `id=${id}&action=delete`
        });
        
        const result = await response.json();
        
        if (result.success) {
            alert('Jadwal berhasil dihapus');
            location.reload();
        } else {
            alert('Gagal menghapus jadwal: ' + result.message);
        }
    } catch (error) {
        console.error('Error:', error);
        alert('Terjadi kesalahan saat menghapus jadwal');
    }
}

// Fungsi print
function printJadwal() {
    window.print();
}

// Fungsi export to Excel
function exportToExcel() {
    // Implementasi export ke Excel
    alert('Fitur export ke Excel akan segera tersedia!');
}

// Auto refresh setiap 30 detik untuk update status
setTimeout(() => {
    location.reload();
}, 30000);
</script>

</body>
</html>
