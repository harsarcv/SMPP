<?php
session_start();
require_once '../../config/database.php';
require_once '../../helpers/session.php';
require_once '../../supervisor/controllers/SupervisorController.php';
enforce_session(['supervisor']);

// ===============================
// Hardening Session & Role
// ===============================
require_login();               
require_role('supervisor');
require_active_user();

// Helper
if (!function_exists('safeText')) {
    function safeText($text) {
        return htmlspecialchars($text ?? '', ENT_QUOTES, 'UTF-8');
    }
}
function avatarInitials($name) {
    $name = trim((string)$name);
    if ($name === '') return 'U';
    $parts = explode(' ', $name);
    $initials = '';
    foreach ($parts as $part) {
        if (!empty($part)) {
            $initials .= strtoupper(substr($part, 0, 1));
            if (strlen($initials) >= 2) break;
        }
    }
    return $initials ?: 'U';
}

// Data supervisor
$supName = $_SESSION['name'] ?? 'Supervisor';
$supNip  = $_SESSION['nip']  ?? 'SUPV001';

// Default
$userCount = 0;
$users     = [];
$filterType = $_GET['filter'] ?? 'status';
$filterValue = $_GET['value'] ?? 'online';
$filterLabel = '';

// Validasi filter type
$validFilters = ['status', 'name', 'nip', 'role'];
if (!in_array($filterType, $validFilters)) {
    $filterType = 'status';
}

// Set filter label
$filterLabels = [
    'status' => 'Status',
    'name' => 'Nama',
    'nip' => 'NIP',
    'role' => 'Peran'
];
$filterLabel = $filterLabels[$filterType] ?? 'Status';

try {
    $controller = new SupervisorController();
    
    // Prepare filter parameters
    $filterParams = [];
    
    // Mapping filter
    if ($filterType === 'status' && !empty($filterValue) && $filterValue !== 'all') {
        $filterParams['status'] = $filterValue;
    } elseif ($filterType === 'name') {
        $filterParams['sort_dir'] = in_array(strtolower($filterValue), ['asc', 'desc']) ? strtolower($filterValue) : 'asc';
    } elseif ($filterType === 'nip' && !empty($filterValue)) {
        $filterParams['nip'] = $filterValue;
    } elseif ($filterType === 'role' && !empty($filterValue) && $filterValue !== 'all') {
        $filterParams['role'] = $filterValue;
    }
    
    // FETCH DATA
    $listRes = $controller->getPetugasListDynamic($filterParams, $_SESSION['user_id']);
    $users = !empty($listRes['success']) ? ($listRes['petugas'] ?? []) : [];
    $userCount = count($users);
    
} catch (Throwable $e) {
    $userCount = 0;
    $users     = [];
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Data Petugas - Supervisor</title>
    <!-- Use Admin CSS for consistent Table UI -->
    <link rel="stylesheet" href="../../admin/css/data_petugas.css">
    <link rel="stylesheet" href="../css/dashboard.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        /* CSS Overrides to match User's Logic with Good UI */
        .header { display:flex; justify-content:space-between; align-items:center; margin-bottom:20px; }
        .profile-box { display:flex; gap:10px; align-items:center; }
        .profile-icon { width:40px; height:40px; background:#3b82f6; color:white; border-radius:50%; display:flex; align-items:center; justify-content:center; font-weight:bold; }
        .filter-card { background:white; padding:20px; border-radius:12px; box-shadow:0 1px 3px rgba(0,0,0,0.05); }
        .filter-row { display:flex; gap:10px; align-items:center; margin-bottom:20px; }
        .result-labels { margin-bottom:15px; color:#64748b; font-size:14px; }
        .group-badge { background: #dcfce7; color: #166534; padding: 2px 8px; border-radius: 12px; font-size: 11px; font-weight: 600; }
        .empty-table { padding:40px; text-align:center; color:#94a3b8; }
        
        /* Ensure table uses Admin styles */
        .data-table { width:100%; border-collapse:collapse; }
        .data-table th { background:#f8fafc; padding:12px 16px; text-align:left; color:#475569; font-weight:600; border-bottom:1px solid #e2e8f0; }
        .data-table td { padding:12px 16px; border-bottom:1px solid #f1f5f9; color:#1e293b; }
        
        /* Badges */
        .badge { padding:4px 10px; border-radius:20px; font-size:12px; font-weight:600; display:inline-block; }
        .badge-online { background:#dcfce7; color:#166534; }
        .badge-offline { background:#e2e8f0; color:#475569; }
        .badge-nonaktif { background:#fee2e2; color:#991b1b; }
        .badge-cuti { background:#fef9c3; color:#92400e; }
        .badge-cuti { background:#ffedd5; color:#c2410c; }
        .badge-online { background:#dcfce7; color:#166534; display:inline-flex; align-items:center; gap:4px; }
        .badge-online::before { content:''; width:6px; height:6px; background:#166534; border-radius:50%; }
        .badge-offline { background:#f1f5f9; color:#64748b; }
    </style>
</head>
<body>
<div class="layout" style="display:flex; min-height:100vh;">
    <!-- SIDEBAR -->
    <?php require_once '../../supervisor/components/sidebar.php'; ?>

    <!-- MAIN -->
    <main class="content" style="flex:1; padding:24px; background:#f8fafc;">
        <!-- HEADER -->
        <header class="header">
            <div>
                <h2 style="margin:0; color:#1e293b;">Data Petugas</h2>
                <p style="margin:4px 0 0 0; color:#64748b; font-size:14px;">Monitor anggota grup Anda</p>
            </div>
            <div class="profile-box">
                <div class="profile-info" style="text-align:right;">
                    <div class="name" style="font-weight:600; color:#1e293b;"><?php echo safeText($supName); ?></div>
                    <div class="id" style="font-size:12px; color:#64748b;"><?php echo safeText($supNip); ?></div>
                </div>
                <div class="profile-icon">S</div>
            </div>
        </header>

        <!-- FILTER BOX -->
        <section class="filter-card">
            <h4 style="margin:0 0 16px 0; color:#334155;">Filter Data</h4>
            <form method="GET" action="data_petugas.php" id="filterForm" class="filter-row">
                <select id="filterType" name="filter" onchange="this.form.submit()" style="padding:10px; border-radius:8px; border:1px solid #cbd5e1;">
                    <option value="status" <?php echo $filterType === 'status' ? 'selected' : ''; ?>>Status</option>
                    <option value="name" <?php echo $filterType === 'name' ? 'selected' : ''; ?>>Nama (Sort)</option>
                </select>

                <?php if ($filterType === 'status'): ?>
                <select id="filterValue" name="value" onchange="this.form.submit()" style="padding:10px; border-radius:8px; border:1px solid #cbd5e1;">
                    <option value="online" <?php echo $filterValue === 'online' ? 'selected' : ''; ?>>🟢 Online</option>
                    <option value="offline" <?php echo $filterValue === 'offline' ? 'selected' : ''; ?>>⚫ Offline</option>
                    <option value="cuti" <?php echo $filterValue === 'cuti' ? 'selected' : ''; ?>>🟡 Cuti</option>
                    <option value="nonaktif" <?php echo $filterValue === 'nonaktif' ? 'selected' : ''; ?>>🔴 Nonaktif</option>
                    <option value="all" <?php echo $filterValue === 'all' ? 'selected' : ''; ?>>Semua</option>
                </select>
                <?php endif; ?>
            </form>

            <div class="result-labels">
                <span class="label-hasil">Menampilkan: <strong><?php echo $userCount; ?></strong> petugas</span>
            </div>

            <!-- RESULT TABLE -->
            <div class="result-box">
                <?php if (!empty($users)): ?>
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>No</th>
                                <th>Nama Petugas</th>
                                <th>Group</th>
                                <th>NIP</th>
                                <th>Kontak</th>
                                <th>Status</th>
                                <th>Online</th>
                                <th>Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $counter = 1;
                            foreach ($users as $u): 
                                $isOnline = !empty($u['online']);
                                $userStatus = $u['status'] ?? 'nonaktif';
                                $groupName = $u['group_name'] ?? '';
                                
                                // Phone Format
                                $phone = safeText((string)($u['phone'] ?? ''));
                                if (!empty($phone) && strlen($phone) >= 10) {
                                    $phoneDisplay = substr($phone, 0, 4) . ' ' . substr($phone, 4, 4) . ' ' . substr($phone, 8);
                                } else {
                                    $phoneDisplay = $phone ?: '-';
                                }
                            ?>
                                <tr>
                                    <td><?php echo $counter; ?></td>
                                    <td>
                                        <div style="display:flex; align-items:center; gap:12px;">
                                            <div style="width:36px; height:36px; background:#f1f5f9; border-radius:50%; display:flex; align-items:center; justify-content:center; color:#64748b; font-weight:600; font-size:13px;">
                                                <?= avatarInitials($u['name'] ?? '') ?>
                                            </div>
                                            <div style="font-weight:600; color:#1e293b;">
                                                <?php echo safeText($u['name'] ?? ''); ?>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <?php if (!empty($groupName)): ?>
                                            <span class="group-badge"><?php echo safeText($groupName); ?></span>
                                        <?php else: ?>
                                            <span style="color: #94a3b8;">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo safeText((string)($u['nip'] ?? '')); ?></td>
                                    <td><?php echo $phoneDisplay; ?></td>
                                    <td>
                                        <span class="badge badge-<?= strtolower($userStatus) ?>">
                                            <?php echo ucfirst($userStatus); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="badge <?= $isOnline ? 'badge-online' : 'badge-offline' ?>">
                                            <?= $isOnline ? 'Online' : 'Offline' ?>
                                        </span>
                                    </td>
                                    <td>
                                        <a href="detail_petugas.php?id=<?= $u['id'] ?>" title="Monitoring" 
                                           style="display:inline-flex; align-items:center; justify-content:center; width:32px; height:32px; background:#eff6ff; color:#3b82f6; border-radius:8px; text-decoration:none;">
                                            <i class="fas fa-desktop"></i>
                                        </a>
                                    </td>
                                </tr>
                            <?php 
                                $counter++;
                            endforeach; 
                            ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <div class="empty-table">
                        <i class="fas fa-users-slash" style="font-size:32px; margin-bottom:10px; display:block;"></i>
                        Tidak ada data petugas ditemukan.
                    </div>
                <?php endif; ?>
            </div>
        </section>
    </main>
</div>

<!-- Live Tracking (Optional, if desired) -->
<script src="../../petugas/js/live-tracking.js"></script>

</body>
</html>
