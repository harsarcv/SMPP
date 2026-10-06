<?php
session_start();
require_once '../../config/database.php';
require_once '../../helpers/session.php';
enforce_session(['admin']);

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: ' . get_base_url() . '/auth/login.php');
    exit;
}

$pdo = Database::getInstance()->getConnection();
$user_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$message = '';

// HANDLE RESET KPI (Admin only)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'reset_kpi') {
    $resetUserId = (int)($_POST['user_id'] ?? 0);
    if ($resetUserId > 0) {
        // Reset KPI to 70 (minimum passing score) and reactivate user
        $pdo->prepare("UPDATE kpi_scores SET score_total = 70, presence_rate = 70, completion_rate = 70, punctuality_rate = 70, calculated_at = NOW() WHERE user_id = ?")->execute([$resetUserId]);
        $pdo->prepare("UPDATE users SET status = 'aktif' WHERE id = ?")->execute([$resetUserId]);
        $message = 'KPI berhasil direset dan akun diaktifkan kembali!';
    }
}

// Helper
function safeText($text) { return htmlspecialchars($text ?? '', ENT_QUOTES, 'UTF-8'); }
function getInitials($name) {
    $parts = explode(' ', trim($name));
    $initials = '';
    foreach ($parts as $p) { if ($p) $initials .= strtoupper($p[0]); if (strlen($initials) >= 2) break; }
    return $initials ?: 'P';
}
function getScoreClass($score) {
    if ($score >= 80) return 'high';
    if ($score >= 60) return 'mid';
    if ($score > 0) return 'low';
    return 'none';
}
function getColorClass($score) {
    if ($score >= 80) return 'green';
    if ($score >= 60) return 'yellow';
    return 'red';
}

// Single user detail
$petugas = null;
$kpi = null;

if ($user_id > 0) {
    $stmt = $pdo->prepare("SELECT id, name, nip, status FROM users WHERE id=:id");
    $stmt->execute([':id' => $user_id]);
    $petugas = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($petugas) {
        $stmt = $pdo->prepare("SELECT * FROM kpi_scores WHERE user_id = :uid ORDER BY calculated_at DESC LIMIT 1");
        $stmt->execute([':uid' => $user_id]);
        $kpi = $stmt->fetch(PDO::FETCH_ASSOC);
    }
}

// All users list
$all_petugas = [];
$kpi_map = [];
$stats = ['total' => 0, 'high' => 0, 'mid' => 0, 'low' => 0];

if ($user_id === 0) {
    $stmt = $pdo->query("SELECT id, name, nip, status FROM users WHERE role='petugas' ORDER BY name ASC");
    $all_petugas = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $stats['total'] = count($all_petugas);

    $stmt = $pdo->query("SELECT * FROM kpi_scores ORDER BY calculated_at DESC");
    $all_kpis_raw = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($all_kpis_raw as $k) {
        if (!isset($kpi_map[$k['user_id']])) {
            $kpi_map[$k['user_id']] = $k;
            $score = (float)$k['score_total'];
            if ($score >= 80) $stats['high']++;
            elseif ($score >= 60) $stats['mid']++;
            elseif ($score > 0) $stats['low']++;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Monitoring KPI Petugas</title>
    <link rel="stylesheet" href="../css/dashboard.css">
    <link rel="stylesheet" href="../css/kpi.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <style>body { font-family: 'Inter', sans-serif; }</style>
</head>
<body>

<div class="app">

<?php $activePage = 'detail_kpi'; ?>
<?php require_once '../../admin/components/sidebar.php'; ?>

<main class="main">

<?php if ($user_id > 0 && $petugas): ?>
    <!-- ==================== -->
    <!-- SINGLE DETAIL VIEW   -->
    <!-- ==================== -->
    
    <a href="detail_kpi.php" class="btn-back">← Kembali ke Daftar</a>
    
    <?php if ($message): ?>
        <div style="background: #d1fae5; color: #065f46; padding: 15px; border-radius: 10px; margin-bottom: 20px; text-align: center; font-weight: 600;">
            ✓ <?= safeText($message) ?>
        </div>
    <?php endif; ?>
    
    <div class="detail-container">
        <div class="detail-card">
            <div class="detail-header">
                <div class="detail-avatar"><?= safeText(getInitials($petugas['name'])) ?></div>
                <h2 class="detail-name"><?= safeText($petugas['name']) ?></h2>
                <p class="detail-nip">NIP: <?= safeText($petugas['nip']) ?></p>
                <span class="detail-status <?= $petugas['status'] ?>"><?= strtoupper($petugas['status']) ?></span>
            </div>
            
            <div class="detail-body">
                <?php if (!$kpi): ?>
                    <div class="no-data">
                        <div class="icon">📊</div>
                        <div class="title">Belum Ada Data KPI</div>
                        <div class="desc">Petugas ini belum memiliki data KPI yang tercatat</div>
                    </div>
                <?php else: ?>
                    <?php 
                        // Calculate weighted contributions
                        $attRaw = (float)$kpi['presence_rate'];
                        $compRaw = (float)$kpi['completion_rate'];
                        $timeOkRaw = (float)$kpi['punctuality_rate'];
                        
                        $attWeighted = round($attRaw * 0.65, 1);
                        $compWeighted = round($compRaw * 0.25, 1);
                        $timeOkWeighted = round($timeOkRaw * 0.10, 1);
                        
                        // Max weights
                        $maxAtt = 65; $maxComp = 25; $maxTimeOk = 10;
                    ?>
                    <div class="kpi-metrics-grid" style="display: flex; gap: 20px; justify-content: center; flex-wrap: wrap;">
                        <div class="kpi-metric" style="text-align: center; padding: 20px; background: #f8fafc; border-radius: 12px; min-width: 120px;">
                            <?php $pct = $maxAtt > 0 ? ($attWeighted / $maxAtt) * 100 : 0; $color = $pct >= 80 ? '#22c55e' : ($pct >= 60 ? '#eab308' : '#ef4444'); ?>
                            <div class="value" style="font-size: 32px; font-weight: 800; color: <?= $color ?>;"><?= $attWeighted ?>%</div>
                            <div style="font-size: 11px; color: #888;">dari <?= $maxAtt ?>%</div>
                            <div class="label" style="font-weight: 600; color: #333; margin-top: 5px;">Kehadiran</div>
                        </div>
                        <div class="kpi-metric" style="text-align: center; padding: 20px; background: #f8fafc; border-radius: 12px; min-width: 120px;">
                            <?php $pct = $maxComp > 0 ? ($compWeighted / $maxComp) * 100 : 0; $color = $pct >= 80 ? '#22c55e' : ($pct >= 60 ? '#eab308' : '#ef4444'); ?>
                            <div class="value" style="font-size: 32px; font-weight: 800; color: <?= $color ?>;"><?= $compWeighted ?>%</div>
                            <div style="font-size: 11px; color: #888;">dari <?= $maxComp ?>%</div>
                            <div class="label" style="font-weight: 600; color: #333; margin-top: 5px;">Penyelesaian</div>
                        </div>
                        <div class="kpi-metric" style="text-align: center; padding: 20px; background: #f8fafc; border-radius: 12px; min-width: 120px;">
                            <?php $pct = $maxTimeOk > 0 ? ($timeOkWeighted / $maxTimeOk) * 100 : 0; $color = $pct >= 80 ? '#22c55e' : ($pct >= 60 ? '#eab308' : '#ef4444'); ?>
                            <div class="value" style="font-size: 32px; font-weight: 800; color: <?= $color ?>;"><?= $timeOkWeighted ?>%</div>
                            <div style="font-size: 11px; color: #888;">dari <?= $maxTimeOk ?>%</div>
                            <div class="label" style="font-weight: 600; color: #333; margin-top: 5px;">Ketepatan</div>
                        </div>
                    </div>
                    
                    <div class="total-score-box">
                        <div class="title">SKOR TOTAL KPI</div>
                        <div class="score"><?= (float)$kpi['score_total'] ?></div>
                        <div class="status">
                            <?php if ($kpi['score_total'] >= 80): ?>
                                ✓ Performa Sangat Baik
                            <?php elseif ($kpi['score_total'] >= 60): ?>
                                ⚠ Performa Cukup
                            <?php else: ?>
                                ✗ Perlu Peningkatan
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <p style="text-align: center; color: #94a3b8; font-size: 12px; margin-top: 20px;">
                        Terakhir diperbarui: <?= date('d M Y H:i', strtotime($kpi['calculated_at'])) ?>
                    </p>
                    
                    <?php if ((float)$kpi['score_total'] < 70): ?>
                    <!-- Reset KPI Button (Admin Only) -->
                    <div style="margin-top: 25px; padding-top: 20px; border-top: 1px solid #e2e8f0; text-align: center;">
                        <p style="color: #ef4444; font-size: 14px; margin-bottom: 15px;">
                            ⚠️ <strong>Peringatan:</strong> KPI di bawah 70. Akun petugas ini <strong>tidak dapat login</strong>.
                        </p>
                        <form method="POST" action="" onsubmit="return confirm('Yakin ingin mereset KPI dan mengaktifkan kembali akun petugas ini?');">
                            <input type="hidden" name="action" value="reset_kpi">
                            <input type="hidden" name="user_id" value="<?= $petugas['id'] ?>">
                            <button type="submit" style="
                                background: linear-gradient(135deg, #10b981, #059669);
                                color: white;
                                border: none;
                                padding: 14px 28px;
                                border-radius: 10px;
                                font-size: 14px;
                                font-weight: 700;
                                cursor: pointer;
                                box-shadow: 0 4px 15px rgba(16,185,129,0.3);
                                transition: all 0.2s;
                            ">
                                🔄 Reset KPI & Aktifkan Akun
                            </button>
                        </form>
                    </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

<?php elseif ($user_id > 0 && !$petugas): ?>
    
    <div class="no-data" style="margin-top: 50px;">
        <div class="icon">🚫</div>
        <div class="title">Petugas Tidak Ditemukan</div>
        <a href="detail_kpi.php" class="btn-view" style="margin-top: 15px;">Kembali ke Daftar</a>
    </div>

<?php else: ?>
    <!-- ==================== -->
    <!-- TABLE VIEW           -->
    <!-- ==================== -->
    
    <div class="kpi-page-header">
        <h2>📊 Monitoring Kinerja Petugas</h2>
        <p>Pantau performa dan KPI seluruh petugas PPSU</p>
    </div>

    <!-- Stats Cards -->
    <div class="kpi-stats-row">
        <div class="kpi-stat-card">
            <div class="icon blue">👥</div>
            <div class="value"><?= $stats['total'] ?></div>
            <div class="label">Total Petugas</div>
        </div>
        <div class="kpi-stat-card">
            <div class="icon green">🌟</div>
            <div class="value"><?= $stats['high'] ?></div>
            <div class="label">Performa Tinggi (≥80)</div>
        </div>
        <div class="kpi-stat-card">
            <div class="icon yellow">⚡</div>
            <div class="value"><?= $stats['mid'] ?></div>
            <div class="label">Performa Sedang (60-79)</div>
        </div>
        <div class="kpi-stat-card">
            <div class="icon red">⚠️</div>
            <div class="value"><?= $stats['low'] ?></div>
            <div class="label">Perlu Perhatian (&lt;60)</div>
        </div>
    </div>

    <!-- Toolbar -->
    <div class="kpi-toolbar">
        <div class="search-box">
            <input type="text" id="kpiSearch" placeholder="Cari nama atau NIP petugas..." onkeyup="filterKPI()">
        </div>
    </div>

    <!-- Table -->
    <div class="table-container">
        <table class="kpi-table" id="kpiTable">
            <thead>
                <tr>
                    <th>No</th>
                    <th>Petugas</th>
                    <th>Kehadiran</th>
                    <th>Penyelesaian</th>
                    <th>Ketepatan</th>
                    <th>Skor Total</th>
                    <th>Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php $no = 1; foreach ($all_petugas as $p): ?>
                    <?php 
                    $k = $kpi_map[$p['id']] ?? null; 
                    $score = $k ? (float)$k['score_total'] : 0;
                    $scoreClass = getScoreClass($score);
                    ?>
                    <tr>
                        <td><?= $no++ ?></td>
                        <td>
                            <div class="user-info">
                                <div class="user-avatar"><?= getInitials($p['name']) ?></div>
                                <div>
                                    <div class="user-name"><?= safeText($p['name']) ?></div>
                                    <div class="user-nip"><?= safeText($p['nip']) ?></div>
                                </div>
                            </div>
                        </td>
                        <td>
                            <?php if ($k): 
                                $attW = round((float)$k['presence_rate'] * 0.65, 1); 
                                $pct = ($attW / 65) * 100;
                                $color = $pct >= 80 ? '#22c55e' : ($pct >= 60 ? '#eab308' : '#ef4444');
                            ?>
                                <span style="font-weight: 700; color: <?= $color ?>;"><?= $attW ?>%</span>
                                <div style="font-size: 10px; color: #aaa;">/65%</div>
                            <?php else: ?>
                                <span style="color: #94a3b8;">-</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($k): 
                                $compW = round((float)$k['completion_rate'] * 0.25, 1);
                                $pct = ($compW / 25) * 100;
                                $color = $pct >= 80 ? '#22c55e' : ($pct >= 60 ? '#eab308' : '#ef4444');
                            ?>
                                <span style="font-weight: 700; color: <?= $color ?>;"><?= $compW ?>%</span>
                                <div style="font-size: 10px; color: #aaa;">/25%</div>
                            <?php else: ?>
                                <span style="color: #94a3b8;">-</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($k): 
                                $timeW = round((float)$k['punctuality_rate'] * 0.10, 1);
                                $pct = ($timeW / 10) * 100;
                                $color = $pct >= 80 ? '#22c55e' : ($pct >= 60 ? '#eab308' : '#ef4444');
                            ?>
                                <span style="font-weight: 700; color: <?= $color ?>;"><?= $timeW ?>%</span>
                                <div style="font-size: 10px; color: #aaa;">/10%</div>
                            <?php else: ?>
                                <span style="color: #94a3b8;">-</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="score-badge <?= $scoreClass ?>">
                                <?= $k ? $score : '-' ?>
                            </span>
                        </td>
                        <td>
                            <a href="?id=<?= $p['id'] ?>" class="btn-view">👁 Detail</a>
                        </td>
                    </tr>
                <?php endforeach ?>
            </tbody>
        </table>
    </div>
    
    <script>
    function filterKPI() {
        let input = document.getElementById('kpiSearch');
        let filter = input.value.toUpperCase();
        let table = document.getElementById('kpiTable');
        let tr = table.getElementsByTagName("tr");

        for (let i = 1; i < tr.length; i++) {
            let td = tr[i].getElementsByTagName("td")[1];
            if (td) {
                let txtValue = td.textContent || td.innerText;
                tr[i].style.display = txtValue.toUpperCase().indexOf(filter) > -1 ? "" : "none";
            }       
        }
    }
    </script>

<?php endif ?>

</main>
</div>
</body>
</html>
