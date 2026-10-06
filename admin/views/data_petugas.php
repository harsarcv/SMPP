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
$controller = new PetugasController();

// Filters (4-Status System: online, offline, nonaktif, cuti)
$filterKey = $_GET['filter'] ?? 'all';
$search = $_GET['search'] ?? '';
$roleFilter = $_GET['role'] ?? 'all';

$filter = [];
if ($filterKey === 'online') {
    $filter['status'] = 'online';
} elseif ($filterKey === 'offline') {
    $filter['status'] = 'offline';
} elseif ($filterKey === 'nonaktif') {
    $filter['status'] = 'nonaktif';
} elseif ($filterKey === 'cuti') {
    $filter['status'] = 'cuti';
}

if (!empty($search)) {
    $filter['search'] = $search;
}

if ($roleFilter !== 'all') {
    $filter['role'] = $roleFilter;
}

// Pagination - increased for 200+ users
$page = (int)($_GET['page'] ?? 1);
if ($page < 1) $page = 1;
$limit = (int)($_GET['limit'] ?? 20); // Default 20 per page
if ($limit < 10) $limit = 10;
if ($limit > 100) $limit = 100;

$result = $controller->getPetugasList($filter, $page, $limit);
$users = $result['success'] ? ($result['data'] ?? []) : [];
$pagination = $result['pagination'] ?? [];

// Get stats (4-Status System)
try {
    $statsQuery = $pdo->query("SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN status = 'online' THEN 1 ELSE 0 END) as online,
        SUM(CASE WHEN status = 'offline' THEN 1 ELSE 0 END) as offline,
        SUM(CASE WHEN status = 'nonaktif' THEN 1 ELSE 0 END) as nonaktif,
        SUM(CASE WHEN status = 'cuti' THEN 1 ELSE 0 END) as cuti
    FROM users WHERE role = 'petugas'");
    $stats = $statsQuery->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $stats = ['total' => 0, 'online' => 0, 'offline' => 0, 'nonaktif' => 0, 'cuti' => 0];
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

function safe($v) { return htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Data Petugas - Admin</title>
    <link rel="stylesheet" href="../css/dashboard.css">
    <link rel="stylesheet" href="../css/data_petugas.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
</head>
<body>
<div class="app">
    <?php $activePage = 'data_petugas'; ?>
    <?php require_once '../../admin/components/sidebar.php'; ?>

    <main class="main">
        <header class="header">
            <div class="header-left">
                <h1 class="page-title">Data Petugas PPSU</h1>
            </div>
        </header>

        <section class="data-box">
            <!-- Stats Bar (4-Status System) -->
            <div class="stats-bar">
                <div class="stat-item">
                    <div class="stat-icon blue"><i class="fas fa-users"></i></div>
                    <div class="stat-info">
                        <div class="stat-value"><?= $stats['total'] ?? 0 ?></div>
                        <div class="stat-label">Total</div>
                    </div>
                </div>
                <div class="stat-item">
                    <div class="stat-icon green"><i class="fas fa-circle"></i></div>
                    <div class="stat-info">
                        <div class="stat-value"><?= $stats['online'] ?? 0 ?></div>
                        <div class="stat-label">Online</div>
                    </div>
                </div>
                <div class="stat-item">
                    <div class="stat-icon gray"><i class="fas fa-circle"></i></div>
                    <div class="stat-info">
                        <div class="stat-value"><?= $stats['offline'] ?? 0 ?></div>
                        <div class="stat-label">Offline</div>
                    </div>
                </div>
                <div class="stat-item">
                    <div class="stat-icon orange"><i class="fas fa-pause-circle"></i></div>
                    <div class="stat-info">
                        <div class="stat-value"><?= $stats['cuti'] ?? 0 ?></div>
                        <div class="stat-label">Cuti</div>
                    </div>
                </div>
                <div class="stat-item">
                    <div class="stat-icon red"><i class="fas fa-times-circle"></i></div>
                    <div class="stat-info">
                        <div class="stat-value"><?= $stats['nonaktif'] ?? 0 ?></div>
                        <div class="stat-label">Nonaktif</div>
                    </div>
                </div>
            </div>

            <!-- Filter Row -->
            <form method="GET" class="filter-row">
                <select name="filter">
                    <option value="all">Semua Status</option>
                    <option value="online" <?= $filterKey === 'online' ? 'selected' : '' ?>>🟢 Online</option>
                    <option value="offline" <?= $filterKey === 'offline' ? 'selected' : '' ?>>⚫ Offline</option>
                    <option value="cuti" <?= $filterKey === 'cuti' ? 'selected' : '' ?>>🟡 Cuti</option>
                    <option value="nonaktif" <?= $filterKey === 'nonaktif' ? 'selected' : '' ?>>🔴 Nonaktif</option>
                </select>
                <input type="text" name="search" placeholder="Cari nama, NIP, atau username..." value="<?= safe($search) ?>">
                <button type="submit" class="btn-blue"><i class="fas fa-search"></i> Cari</button>
                <a href="tambah_petugas.php" class="btn-add"><i class="fas fa-plus"></i> Tambah User</a>
            </form>

            <!-- Data Table -->
            <?php if (empty($users)): ?>
                <div class="empty-state">
                    <div class="empty-icon"><i class="fas fa-user-slash"></i></div>
                    <h3>Data Tidak Ditemukan</h3>
                    <p>Tidak ada petugas yang sesuai dengan filter pencarian.</p>
                </div>
            <?php else: ?>
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>No</th>
                            <th>Nama Petugas</th>
                            <th>NIP</th>
                            <th>No. HP</th>
                            <th>Role</th>
                            <th>Status</th>
                            <th>Online</th>
                            <th>Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $startNum = (($pagination['page'] ?? 1) - 1) * $limit + 1;
                        foreach ($users as $i => $u): 
                            $status = $u['status'] ?? 'nonaktif';
                            $role = $u['role'] ?? 'petugas';
                            $online = !empty($u['online_status']) && strtolower($u['online_status']) === 'online';
                        ?>
                        <tr>
                            <td><?= $startNum + $i ?></td>
                            <td>
                                <div class="user-cell">
                                    <a href="detail_petugas.php?id=<?= $u['id'] ?>" style="text-decoration:none;">
                                        <div class="avatar"><?= avatarInitials($u['name'] ?? '') ?></div>
                                    </a>
                                    <div class="user-info">
                                        <a href="detail_petugas.php?id=<?= $u['id'] ?>" class="user-name" style="text-decoration:none; color:inherit;"><?= safe($u['name'] ?? '') ?></a>
                                        <div class="user-role"><?= safe($u['username'] ?? '') ?></div>
                                    </div>
                                </div>
                            </td>
                            <td><?= safe($u['nip'] ?? '-') ?></td>
                            <td><?= safe($u['phone'] ?? '-') ?></td>
                            <td><span class="role-badge role-<?= $role ?>"><?= ucfirst($role) ?></span></td>
                            <td><span class="badge badge-<?= $status ?>"><?= ucfirst($status) ?></span></td>
                            <td><span class="badge <?= $online ? 'badge-online' : 'badge-offline' ?>"><?= $online ? 'Online' : 'Offline' ?></span></td>
                            <td>
                                <div class="action-btns">
                                    <a href="detail_petugas.php?id=<?= $u['id'] ?>" class="btn-action btn-monitor" title="Monitoring">
                                        <i class="fas fa-desktop"></i>
                                    </a>
                                    <a href="edit_petugas.php?id=<?= $u['id'] ?>" class="btn-action btn-edit" title="Edit">
                                        <i class="fas fa-edit"></i>
                                    </a>
                                    <a href="laporan_petugas.php?user_id=<?= $u['id'] ?>" class="btn-action btn-report" title="Laporan">
                                        <i class="fas fa-file-alt"></i>
                                    </a>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <!-- Pagination -->
                <?php if (!empty($pagination) && $pagination['pages'] > 1): ?>
                <div class="pagination">
                    <?php 
                    $q = $_GET; 
                    unset($q['page']); 
                    $qs = http_build_query($q);
                    $link = "?" . ($qs ? $qs . '&' : '') . "page=";
                    $curr = $pagination['page'];
                    $total = $pagination['pages'];
                    
                    // Smart pagination - show max 7 page links
                    $start = max(1, $curr - 3);
                    $end = min($total, $curr + 3);
                    if ($end - $start < 6) {
                        if ($start == 1) $end = min($total, 7);
                        else $start = max(1, $total - 6);
                    }
                    ?>

                    <?php if ($curr > 1): ?>
                        <a href="<?= $link ?>1" class="page-link" title="First"><i class="fas fa-angle-double-left"></i></a>
                        <a href="<?= $link . ($curr - 1) ?>" class="page-link"><i class="fas fa-angle-left"></i></a>
                    <?php endif; ?>

                    <?php if ($start > 1): ?>
                        <span class="page-link" style="border:none;">...</span>
                    <?php endif; ?>

                    <?php for ($i = $start; $i <= $end; $i++): ?>
                        <a href="<?= $link . $i ?>" class="page-link <?= $i == $curr ? 'active' : '' ?>"><?= $i ?></a>
                    <?php endfor; ?>

                    <?php if ($end < $total): ?>
                        <span class="page-link" style="border:none;">...</span>
                    <?php endif; ?>

                    <?php if ($curr < $total): ?>
                        <a href="<?= $link . ($curr + 1) ?>" class="page-link"><i class="fas fa-angle-right"></i></a>
                        <a href="<?= $link . $total ?>" class="page-link" title="Last"><i class="fas fa-angle-double-right"></i></a>
                    <?php endif; ?>

                    <span class="page-info">Halaman <?= $curr ?> dari <?= $total ?> (<?= $pagination['total'] ?? 0 ?> data)</span>

                    <div class="per-page">
                        <label>Per halaman:</label>
                        <select onchange="window.location.href='?<?= $qs ? $qs . '&' : '' ?>limit='+this.value">
                            <option value="20" <?= $limit == 20 ? 'selected' : '' ?>>20</option>
                            <option value="50" <?= $limit == 50 ? 'selected' : '' ?>>50</option>
                            <option value="100" <?= $limit == 100 ? 'selected' : '' ?>>100</option>
                        </select>
                    </div>
                </div>
                <?php endif; ?>
            <?php endif; ?>
        </section>
    </main>
</div>
</body>
</html>
