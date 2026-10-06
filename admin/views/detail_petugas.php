<?php
session_start();
require_once '../../config/database.php';
require_once '../../helpers/session.php';
enforce_session(['admin']);

$userId = (int)($_GET['id'] ?? 0);
if (!$userId) {
    header('Location: data_petugas.php');
    exit;
}

$pdo = Database::getInstance()->getConnection();

// ========================
// 1. FETCH USER DATA
// ========================
$userStmt = $pdo->prepare("
    SELECT 
        u.*,
        g.name AS group_name,
        sup.name AS supervisor_name
    FROM users u
    LEFT JOIN `groups` g ON u.group_id = g.id
    LEFT JOIN users sup ON u.supervisor_id = sup.id
    WHERE u.id = ?
");
$userStmt->execute([$userId]);
$user = $userStmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    header('Location: data_petugas.php?error=not_found');
    exit;
}

// ========================
// 2. FETCH KPI DATA
// ========================
$kpiStmt = $pdo->prepare("
    SELECT presence_rate, completion_rate, punctuality_rate, score_total, effective_date, calculated_at
    FROM kpi_scores 
    WHERE user_id = ? 
    ORDER BY effective_date DESC 
    LIMIT 1
");
$kpiStmt->execute([$userId]);
$kpi = $kpiStmt->fetch(PDO::FETCH_ASSOC);

// Calculate weighted KPI values
$kpiWeighted = [
    'attendance' => $kpi ? round((float)$kpi['presence_rate'] * 0.65, 1) : 0,
    'completion' => $kpi ? round((float)$kpi['completion_rate'] * 0.25, 1) : 0,
    'punctuality' => $kpi ? round((float)$kpi['punctuality_rate'] * 0.10, 1) : 0,
    'total' => $kpi ? (float)$kpi['score_total'] : 0
];

// ========================
// 3. FETCH TODAY'S SCHEDULES WITH WORK STATUS
// ========================
$today = date('Y-m-d');
$scheduleStmt = $pdo->prepare("
    SELECT s.*, z.name AS zone_name, z.rw_code, r.id AS report_id, r.status AS report_status,
        CASE
            WHEN r.id IS NOT NULL THEN 'sudah_lapor'
            WHEN :user_status = 'online' AND s.shift_start <= CURRENT_TIME AND s.shift_end >= CURRENT_TIME THEN 'sedang_mengerjakan'
            WHEN s.shift_start <= CURRENT_TIME THEN 'belum_lapor'
            ELSE 'belum_mulai'
        END as work_status
    FROM schedules s
    LEFT JOIN zones z ON s.zone_id = z.id
    LEFT JOIN reports r ON r.schedule_id = s.id
    WHERE s.user_id = :user_id AND s.work_date = :work_date
    ORDER BY s.shift_start ASC
");
$scheduleStmt->execute([':user_id' => $userId, ':work_date' => $today, ':user_status' => $user['status'] ?? 'offline']);
$todaySchedules = $scheduleStmt->fetchAll(PDO::FETCH_ASSOC);

// ========================
// 4. FETCH RECENT REPORTS (Last 7 days)
// ========================
$reportStmt = $pdo->prepare("
    SELECT r.*, z.name AS zone_name, z.rw_code,
           (SELECT COUNT(*) FROM report_photos WHERE report_id = r.id) AS photo_count
    FROM reports r
    LEFT JOIN zones z ON r.zone_id = z.id
    WHERE r.user_id = ? AND r.work_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
    ORDER BY r.created_at DESC
    LIMIT 10
");
$reportStmt->execute([$userId]);
$recentReports = $reportStmt->fetchAll(PDO::FETCH_ASSOC);

// ========================
// 5. FETCH STATS (30 days)
// ========================
$statsStmt = $pdo->prepare("
    SELECT 
        COUNT(*) AS total_reports,
        SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) AS approved_reports,
        SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) AS rejected_reports,
        SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) AS pending_reports
    FROM reports
    WHERE user_id = ? AND work_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
");
$statsStmt->execute([$userId]);
$stats = $statsStmt->fetch(PDO::FETCH_ASSOC);

// Helper functions
function safeText($v) { return htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8'); }
function formatPhone($p) { return $p ? '+62 ' . substr($p, 0, 3) . '-' . substr($p, 3, 4) . '-' . substr($p, 7) : '-'; }
function getStatusColor($status) {
    switch ($status) {
        case 'online': return '#22c55e';   // Green - sedang bekerja
        case 'aktif': return '#22c55e';    // Green - backward compatibility
        case 'offline': return '#64748b';  // Gray - tidak bekerja
        case 'nonaktif': return '#ef4444'; // Red - dinonaktifkan
        case 'cuti': return '#f59e0b';     // Yellow - cuti
        default: return '#6b7280';
    }
}

function getStatusLabel($status) {
    switch ($status) {
        case 'online': return '🟢 Online';
        case 'aktif': return '✅ Aktif';
        case 'offline': return '⚫ Offline';
        case 'nonaktif': return '🔴 Nonaktif';
        case 'cuti': return '🟡 Cuti';
        default: return ucfirst($status);
    }
}
function getReportStatusBadge($status) {
    switch ($status) {
        case 'approved': return '<span style="background:#d1fae5;color:#059669;padding:4px 10px;border-radius:12px;font-size:11px;font-weight:600;">✓ Approved</span>';
        case 'rejected': return '<span style="background:#fee2e2;color:#dc2626;padding:4px 10px;border-radius:12px;font-size:11px;font-weight:600;">✗ Rejected</span>';
        default: return '<span style="background:#fef3c7;color:#d97706;padding:4px 10px;border-radius:12px;font-size:11px;font-weight:600;">⏳ Pending</span>';
    }
}

function getWorkStatusBadge($workStatus) {
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
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Detail Petugas - <?php echo safeText($user['name']); ?></title>
    <link rel="stylesheet" href="../css/dashboard.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        * { font-family: 'Inter', sans-serif; box-sizing: border-box; }
        body { background: #f0f4f8; margin: 0; padding: 0; }
        
        .detail-container { padding: 24px; max-width: 1400px; margin: 0 auto; }
        
        /* Header */
        .page-header { 
            display: flex; 
            align-items: center; 
            justify-content: space-between; 
            margin-bottom: 24px;
            background: linear-gradient(135deg, #1e40af, #3b82f6);
            padding: 20px 28px;
            border-radius: 16px;
            color: white;
        }
        .page-header h1 { margin: 0; font-size: 22px; font-weight: 700; }
        .back-btn {
            display: flex; align-items: center; gap: 8px;
            background: rgba(255,255,255,0.2); color: white;
            padding: 10px 18px; border-radius: 10px;
            text-decoration: none; font-weight: 600; font-size: 14px;
            transition: all 0.2s;
        }
        .back-btn:hover { background: rgba(255,255,255,0.3); }
        
        /* Grid Layout */
        .main-grid { display: grid; grid-template-columns: 350px 1fr; gap: 24px; }
        @media (max-width: 1024px) { .main-grid { grid-template-columns: 1fr; } }
        
        /* Left Column - Profile */
        .profile-card {
            background: white; border-radius: 20px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.06);
            overflow: hidden;
        }
        .profile-header {
            background: linear-gradient(135deg, #1e3a5f, #2563eb);
            padding: 30px; text-align: center;
        }
        .avatar-lg {
            width: 100px; height: 100px;
            border-radius: 50%; border: 4px solid rgba(255,255,255,0.3);
            object-fit: cover; margin-bottom: 16px;
        }
        .profile-name { color: white; font-size: 20px; font-weight: 700; margin: 0; }
        .profile-role { color: rgba(255,255,255,0.8); font-size: 13px; margin-top: 4px; }
        .status-badge-lg {
            display: inline-block; margin-top: 12px;
            padding: 6px 16px; border-radius: 20px;
            font-size: 12px; font-weight: 700; text-transform: uppercase;
        }
        
        .profile-body { padding: 24px; }
        .info-row {
            display: flex; justify-content: space-between;
            padding: 12px 0; border-bottom: 1px solid #f1f5f9;
        }
        .info-row:last-child { border-bottom: none; }
        .info-label { color: #64748b; font-size: 13px; font-weight: 500; }
        .info-value { color: #1e293b; font-size: 14px; font-weight: 600; text-align: right; }
        
        /* GPS Card */
        .gps-card {
            background: #f8fafc; border-radius: 12px;
            padding: 16px; margin-top: 16px;
        }
        .gps-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 8px; }
        .gps-title { font-weight: 600; color: #334155; font-size: 14px; }
        .live-badge { background: #ef4444; color: white; padding: 3px 8px; border-radius: 10px; font-size: 10px; animation: pulse 2s infinite; }
        @keyframes pulse { 0%, 100% { opacity: 1; } 50% { opacity: 0.5; } }
        .gps-coords { font-family: monospace; font-size: 12px; color: #64748b; margin-bottom: 10px; }
        .gps-btn {
            display: block; width: 100%; text-align: center;
            background: #3b82f6; color: white;
            padding: 10px; border-radius: 8px;
            text-decoration: none; font-weight: 600; font-size: 13px;
        }
        .gps-btn:hover { background: #2563eb; }
        .gps-time { font-size: 11px; color: #94a3b8; margin-top: 8px; text-align: center; }
        
        /* Right Column */
        .section-card {
            background: white; border-radius: 16px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.06);
            padding: 24px; margin-bottom: 20px;
        }
        .section-title {
            font-size: 16px; font-weight: 700; color: #1e293b;
            margin: 0 0 20px 0; display: flex; align-items: center; gap: 10px;
        }
        .section-title i { color: #3b82f6; }
        
        /* KPI Grid */
        .kpi-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 16px; }
        @media (max-width: 768px) { .kpi-grid { grid-template-columns: repeat(2, 1fr); } }
        .kpi-item {
            background: linear-gradient(135deg, #f0f9ff, #e0f2fe);
            border-radius: 14px; padding: 20px; text-align: center;
        }
        .kpi-value { font-size: 28px; font-weight: 800; color: #0369a1; margin-bottom: 4px; }
        .kpi-max { font-size: 11px; color: #64748b; margin-bottom: 6px; }
        .kpi-label { font-size: 12px; font-weight: 600; color: #334155; }
        .kpi-item.total { background: linear-gradient(135deg, #22c55e, #16a34a); }
        .kpi-item.total .kpi-value, .kpi-item.total .kpi-label { color: white; }
        .kpi-item.total .kpi-max { color: rgba(255,255,255,0.8); }
        
        /* Stats Grid */
        .stats-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 12px; }
        @media (max-width: 768px) { .stats-grid { grid-template-columns: repeat(2, 1fr); } }
        .stat-box { background: #f8fafc; border-radius: 12px; padding: 16px; text-align: center; }
        .stat-num { font-size: 24px; font-weight: 800; }
        .stat-label { font-size: 11px; color: #64748b; margin-top: 4px; }
        .stat-box.total .stat-num { color: #3b82f6; }
        .stat-box.approved .stat-num { color: #22c55e; }
        .stat-box.rejected .stat-num { color: #ef4444; }
        .stat-box.pending .stat-num { color: #f59e0b; }
        
        /* Schedule List */
        .schedule-list { display: flex; flex-direction: column; gap: 12px; }
        .schedule-item {
            display: flex; align-items: center; gap: 16px;
            background: #f8fafc; border-radius: 12px; padding: 16px;
            border-left: 4px solid #3b82f6;
        }
        .schedule-time { font-weight: 700; color: #1e293b; min-width: 120px; }
        .schedule-zone { flex: 1; }
        .schedule-zone-name { font-weight: 600; color: #334155; }
        .schedule-rw { font-size: 12px; color: #64748b; }
        .schedule-status { font-size: 12px; padding: 4px 10px; border-radius: 10px; font-weight: 600; }
        .schedule-status.active { background: #dcfce7; color: #16a34a; }
        .schedule-status.completed { background: #e0f2fe; color: #0369a1; }
        
        /* Report Table */
        .report-table { width: 100%; border-collapse: collapse; }
        .report-table th, .report-table td { padding: 12px 16px; text-align: left; }
        .report-table th { background: #f1f5f9; font-size: 12px; font-weight: 600; color: #64748b; text-transform: uppercase; }
        .report-table tr:hover { background: #f8fafc; }
        .report-table td { border-bottom: 1px solid #f1f5f9; font-size: 13px; }
        
        /* Empty State */
        .empty-state { text-align: center; padding: 40px; color: #94a3b8; }
        .empty-state i { font-size: 40px; margin-bottom: 12px; }
        
        /* Action Buttons */
        .action-row { display: flex; gap: 12px; margin-top: 20px; flex-wrap: wrap; }
        .btn-action {
            display: flex; align-items: center; gap: 8px;
            padding: 12px 20px; border-radius: 10px;
            text-decoration: none; font-weight: 600; font-size: 13px;
            transition: all 0.2s;
        }
        .btn-primary { background: #3b82f6; color: white; }
        .btn-primary:hover { background: #2563eb; }
        .btn-secondary { background: #e2e8f0; color: #475569; }
        .btn-secondary:hover { background: #cbd5e1; }
        .btn-danger { background: #fef2f2; color: #dc2626; }
        .btn-danger:hover { background: #fee2e2; }
    </style>
</head>
<body>

<div class="app">
    <?php $activePage = 'data_petugas'; require_once '../../admin/components/sidebar.php'; ?>

    <main class="main">
        <div class="detail-container">
            
            <!-- Page Header -->
            <div class="page-header">
                <div>
                    <h1><i class="fas fa-user-circle"></i> Detail Petugas</h1>
                    <div style="font-size:13px; opacity:0.85; margin-top:4px;">Informasi lengkap tentang <?php echo safeText($user['name']); ?></div>
                </div>
                <a href="data_petugas.php" class="back-btn"><i class="fas fa-arrow-left"></i> Kembali</a>
            </div>

            <div class="main-grid">
                <!-- Left Column: Profile -->
                <div class="left-column">
                    <div class="profile-card">
                        <div class="profile-header">
                            <?php 
                                $photoPath = $user['photo_path'] && $user['photo_path'] !== '/default-avatar.png' 
                                    ? '../../public/uploads/' . $user['photo_path'] 
                                    : '../../assets/icon/icon-avatar.png';
                            ?>
                            <img src="<?php echo $photoPath; ?>" alt="Avatar" class="avatar-lg" onerror="this.src='../../assets/icon/icon-avatar.png'">
                            <h2 class="profile-name"><?php echo safeText($user['name']); ?></h2>
                            <div class="profile-role"><?php echo safeText(ucfirst($user['role'])); ?></div>
                            <div class="status-badge-lg" style="background:<?php echo getStatusColor($user['status']); ?>; color:white;">
                                <?php echo getStatusLabel($user['status']); ?>
                            </div>
                        </div>
                        
                        <div class="profile-body">
                            <div class="info-row">
                                <span class="info-label">NIP</span>
                                <span class="info-value"><?php echo safeText($user['nip'] ?? '-'); ?></span>
                            </div>
                            <div class="info-row">
                                <span class="info-label">Username</span>
                                <span class="info-value"><?php echo safeText($user['username']); ?></span>
                            </div>
                            <div class="info-row">
                                <span class="info-label">Telepon</span>
                                <span class="info-value"><?php echo safeText($user['phone'] ?? '-'); ?></span>
                            </div>
                            <div class="info-row">
                                <span class="info-label">Group</span>
                                <span class="info-value"><?php echo safeText($user['group_name'] ?? '-'); ?></span>
                            </div>
                            <div class="info-row">
                                <span class="info-label">Supervisor</span>
                                <span class="info-value"><?php echo safeText($user['supervisor_name'] ?? '-'); ?></span>
                            </div>
                            <div class="info-row">
                                <span class="info-label">Login Terakhir</span>
                                <span class="info-value"><?php echo $user['last_login'] ? date('d/m/Y H:i', strtotime($user['last_login'])) : '-'; ?></span>
                            </div>
                            <div class="info-row">
                                <span class="info-label">Terdaftar</span>
                                <span class="info-value"><?php echo date('d M Y', strtotime($user['created_at'])); ?></span>
                            </div>
                            
                            <!-- GPS Location -->
                            <div class="gps-card">
                                <div class="gps-header">
                                    <span class="gps-title"><i class="fas fa-map-marker-alt"></i> Lokasi GPS</span>
                                    <span id="gps-badge-container">
                                    <?php 
                                        $lastUpdate = $user['last_location_update'] ? strtotime($user['last_location_update']) : 0;
                                        $timeDiff = time() - $lastUpdate;
                                        $isLive = $timeDiff < 600; // 10 minutes
                                    ?>
                                    <?php if ($user['latitude'] && $user['longitude'] && $isLive): ?>
                                        <span class="live-badge">LIVE</span>
                                    <?php elseif ($user['latitude'] && $user['longitude']): ?>
                                        <span class="live-badge" style="background:#64748b; animation:none;">OFFLINE</span>
                                    <?php endif; ?>
                                    </span>
                                </div>
                                <div id="gps-data-container">
                                <?php if ($user['latitude'] && $user['longitude']): ?>
                                    <div class="gps-coords" id="gps-coords"><?php echo $user['latitude']; ?>, <?php echo $user['longitude']; ?></div>
                                    <a href="https://www.google.com/maps?q=<?php echo $user['latitude']; ?>,<?php echo $user['longitude']; ?>" target="_blank" class="gps-btn" id="gps-link">
                                        <i class="fas fa-external-link-alt"></i> Buka Google Maps
                                    </a>
                                    <!-- Removed Update Time as requested -->
                                <?php else: ?>
                                    <div style="color:#94a3b8; font-size:13px; text-align:center;">Belum ada data lokasi</div>
                                <?php endif; ?>
                                </div>
                            </div>
                            
                            <script>
                            document.addEventListener('DOMContentLoaded', function() {
                                const userId = <?php echo $userId; ?>;
                                const gpsCoords = document.getElementById('gps-coords');
                                const gpsLink = document.getElementById('gps-link');
                                const gpsTime = document.getElementById('gps-time');
                                const badgeContainer = document.getElementById('gps-badge-container');
                                const dataContainer = document.getElementById('gps-data-container');

                                function updateLocation() {
                                    fetch('../../api/gps/get_location.php?user_id=' + userId)
                                    .then(response => response.json())
                                    .then(data => {
                                        if (data.success && data.location && data.location.latitude) {
                                            const loc = data.location;
                                            const lat = parseFloat(loc.latitude).toFixed(8);
                                            const lng = parseFloat(loc.longitude).toFixed(8);
                                            const lastUpdate = new Date(loc.last_location_update);
                                            const now = new Date();
                                            const diffSeconds = (now - lastUpdate) / 1000;
                                            
                                            // Format timestamp
                                            const dateStr = lastUpdate.getDate().toString().padStart(2, '0') + '/' + 
                                                          (lastUpdate.getMonth()+1).toString().padStart(2, '0') + '/' + 
                                                          lastUpdate.getFullYear() + ' ' + 
                                                          lastUpdate.getHours().toString().padStart(2, '0') + ':' + 
                                                          lastUpdate.getMinutes().toString().padStart(2, '0');
                                            
                                            // Update Coords & Time
                                            if (gpsCoords) gpsCoords.innerText = `${lat}, ${lng}`;
                                            if (gpsLink) gpsLink.href = `https://www.google.com/maps?q=${lat},${lng}`;
                                            // if (gpsTime) gpsTime.innerText = `Update: ${dateStr}`;
                                            
                                            // Update Badge
                                            if (diffSeconds < 600) { // 10 mins
                                                badgeContainer.innerHTML = '<span class="live-badge">LIVE</span>';
                                            } else {
                                                badgeContainer.innerHTML = '<span class="live-badge" style="background:#64748b; animation:none;">OFFLINE</span>';
                                            }
                                        }
                                    })
                                    .catch(err => console.error('Error polling location:', err));
                                }

                                // Poll every 5 seconds
                                setInterval(updateLocation, 5000);
                            });
                            </script>
                            
                            <!-- Action Buttons -->
                            <div class="action-row">
                                <a href="edit_petugas.php?id=<?php echo $userId; ?>" class="btn-action btn-primary">
                                    <i class="fas fa-edit"></i> Edit
                                </a>
                                <a href="laporan_petugas.php?user_id=<?php echo $userId; ?>" class="btn-action btn-secondary">
                                    <i class="fas fa-file-alt"></i> Laporan
                                </a>
                                <a href="detail_kpi.php?id=<?php echo $userId; ?>" class="btn-action btn-secondary">
                                    <i class="fas fa-chart-line"></i> KPI Detail
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Right Column: Details -->
                <div class="right-column">
                    
                    <!-- KPI Section -->
                    <div class="section-card">
                        <h3 class="section-title"><i class="fas fa-chart-pie"></i> Skor KPI (Bobot)</h3>
                        <div class="kpi-grid">
                            <div class="kpi-item">
                                <div class="kpi-value"><?php echo $kpiWeighted['attendance']; ?>%</div>
                                <div class="kpi-max">Maks: 65%</div>
                                <div class="kpi-label">Kehadiran</div>
                            </div>
                            <div class="kpi-item">
                                <div class="kpi-value"><?php echo $kpiWeighted['completion']; ?>%</div>
                                <div class="kpi-max">Maks: 25%</div>
                                <div class="kpi-label">Penyelesaian</div>
                            </div>
                            <div class="kpi-item">
                                <div class="kpi-value"><?php echo $kpiWeighted['punctuality']; ?>%</div>
                                <div class="kpi-max">Maks: 10%</div>
                                <div class="kpi-label">Ketepatan</div>
                            </div>
                            <div class="kpi-item total">
                                <div class="kpi-value"><?php echo $kpiWeighted['total']; ?>%</div>
                                <div class="kpi-max">Maks: 100%</div>
                                <div class="kpi-label">Total Skor</div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Stats Section -->
                    <div class="section-card">
                        <h3 class="section-title"><i class="fas fa-clipboard-list"></i> Statistik Laporan (30 Hari)</h3>
                        <div class="stats-grid">
                            <div class="stat-box total">
                                <div class="stat-num"><?php echo $stats['total_reports'] ?? 0; ?></div>
                                <div class="stat-label">Total Laporan</div>
                            </div>
                            <div class="stat-box approved">
                                <div class="stat-num"><?php echo $stats['approved_reports'] ?? 0; ?></div>
                                <div class="stat-label">Disetujui</div>
                            </div>
                            <div class="stat-box pending">
                                <div class="stat-num"><?php echo $stats['pending_reports'] ?? 0; ?></div>
                                <div class="stat-label">Pending</div>
                            </div>
                            <div class="stat-box rejected">
                                <div class="stat-num"><?php echo $stats['rejected_reports'] ?? 0; ?></div>
                                <div class="stat-label">Ditolak</div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Today's Schedule -->
                    <div class="section-card">
                        <h3 class="section-title"><i class="fas fa-calendar-day"></i> Jadwal Hari Ini (<?php echo date('d M Y'); ?>)</h3>
                        <?php if (empty($todaySchedules)): ?>
                            <div class="empty-state">
                                <i class="fas fa-calendar-times"></i>
                                <p>Tidak ada jadwal hari ini</p>
                            </div>
                        <?php else: ?>
                            <style>
                                @keyframes pulse {
                                    0%, 100% { opacity: 1; }
                                    50% { opacity: 0.4; }
                                }
                            </style>
                            <div class="schedule-list">
                                <?php foreach ($todaySchedules as $sch): ?>
                                    <div class="schedule-item" style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 8px;">
                                        <div style="display: flex; align-items: center; gap: 15px; flex: 1;">
                                            <div class="schedule-time">
                                                <?php echo date('H:i', strtotime($sch['shift_start'])); ?> - <?php echo date('H:i', strtotime($sch['shift_end'])); ?>
                                            </div>
                                            <div class="schedule-zone">
                                                <div class="schedule-zone-name"><?php echo safeText($sch['zone_name']); ?></div>
                                                <div class="schedule-rw">RW <?php echo safeText($sch['rw_code']); ?></div>
                                            </div>
                                        </div>
                                        <?= getWorkStatusBadge($sch['work_status']) ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Recent Reports -->
                    <div class="section-card">
                        <h3 class="section-title"><i class="fas fa-history"></i> Laporan Terbaru (7 Hari)</h3>
                        <?php if (empty($recentReports)): ?>
                            <div class="empty-state">
                                <i class="fas fa-folder-open"></i>
                                <p>Belum ada laporan dalam 7 hari terakhir</p>
                            </div>
                        <?php else: ?>
                            <div style="overflow-x: auto;">
                                <table class="report-table">
                                    <thead>
                                        <tr>
                                            <th>Tanggal</th>
                                            <th>Waktu</th>
                                            <th>Lokasi</th>
                                            <th>Foto</th>
                                            <th>Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($recentReports as $rep): ?>
                                            <tr>
                                                <td><?php echo date('d/m/Y', strtotime($rep['work_date'])); ?></td>
                                                <td><?php echo ucfirst($rep['session']); ?></td>
                                                <td>
                                                    <div style="font-weight:600;"><?php echo safeText($rep['zone_name']); ?></div>
                                                    <div style="font-size:11px;color:#64748b;">RW <?php echo safeText($rep['rw_code']); ?></div>
                                                </td>
                                                <td><?php echo $rep['photo_count']; ?> foto</td>
                                                <td><?php echo getReportStatusBadge($rep['status']); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            <div style="text-align:center; margin-top:16px;">
                                <a href="laporan_petugas.php?user_id=<?php echo $userId; ?>" style="color:#3b82f6; font-weight:600; font-size:13px;">
                                    Lihat Semua Laporan <i class="fas fa-arrow-right"></i>
                                </a>
                            </div>
                        <?php endif; ?>
                    </div>
                    
                </div>
            </div>
            
        </div>
    </main>
</div>

</body>
</html>
