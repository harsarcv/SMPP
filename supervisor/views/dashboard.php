<?php
session_start();
require_once '../../config/database.php';
require_once '../../helpers/session.php';
require_once '../../supervisor/controllers/SupervisorController.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'supervisor') {
    header('Location: /auth/login.php');
    exit;
}

// Helper jika belum ada
if (!function_exists('safeText')) {
    function safeText($text) {
        return htmlspecialchars($text ?? '', ENT_QUOTES, 'UTF-8');
    }
}

require_login();
require_role('supervisor');
require_active_user();

$brandSub = 'Kelurahan Sunter Jaya';

// Data supervisor dari session (sudah diisi saat login)
$supName = $_SESSION['name'] ?? 'Supervisor';
$supNip  = $_SESSION['nip']  ?? 'SUPV001';

// Default nilai jika DB error
$stats = [
    'total_users'    => 0,
    'today_reports'  => 0,
    'active_users'   => 0,
];
$resets       = [];
$exportTotal  = 0;

// Label tanggal untuk kartu Lupa Password
$dateLabel = date('d/m/Y');

try {
    $controller = new SupervisorController();
    $statsRes = $controller->getDashboardStats((int)($_SESSION['user_id'] ?? 0));
    if (!empty($statsRes['success'])) {
        // Map ke kunci tampilan yang sudah ada
        $stats['total_users']   = (int)($statsRes['stats']['total_petugas'] ?? 0);
        $stats['today_reports'] = (int)($statsRes['stats']['today_reports'] ?? 0);
        $stats['active_users']  = (int)($statsRes['stats']['active_petugas'] ?? 0);
        $exportTotal            = (int)($statsRes['stats']['today_reports'] ?? 0);
    }

    $resetRes = $controller->getResetRequests();
    $rows = !empty($resetRes['success']) ? ($resetRes['requests'] ?? []) : [];

    foreach ($rows as $r) {
        // Label status untuk ditampilkan di popup
        $st = (string)$r['status'];
        if ($st === 'pending') {
            $statusLabel = 'Menunggu persetujuan supervisor';
        } elseif ($st === 'approved') {
            $statusLabel = 'Sudah di-reset dan dikirim ke petugas';
        } elseif ($st === 'expired') {
            $statusLabel = 'Reset password sudah kadaluarsa';
        } else {
            $statusLabel = ucfirst($st);
        }

        $resets[] = [
            'id'           => (int)$r['id'],
            'name'         => (string)($r['petugas_name'] ?? $r['name'] ?? ''),
            'nip'          => (string)($r['petugas_nip'] ?? $r['nip'] ?? ''),
            'status'       => $st,
            'status_label' => $statusLabel,
            'created_at'   => $r['created_at'],
        ];
    }

} catch (Throwable $e) {
    // Jika error DB, biarkan $stats dan $resets default
}

?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Dashboard Supervisor - Sunter Jaya</title>

    <!-- CSS -->
    <link rel="stylesheet" href="../css/dashboard.css" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
</head>

<body>

<div class="layout">

    <!-- POPUP RESET PASSWORD -->
    <div class="popup-overlay" id="popupReset" style="display:none;">
        <div class="popup-box popup-animate">
            <h3 class="popup-title">Nama Petugas/Username</h3>
            <div class="popup-value" id="resetName"></div>

            <h3 class="popup-title">Nomor Induk Petugas</h3>
            <div class="popup-value" id="resetNip"></div>

            <h3 class="popup-title">Status Permintaan</h3>
            <div class="popup-value" id="resetPass"></div>

            <input type="hidden" id="resetId" value="">
            <input type="hidden" id="resetStatus" value="">

            <div id="resetBtnContainer" style="margin-top:15px; display:none;">
                <button class="popup-button" id="btnApproveReset" style="background:#28a745; margin-bottom:8px;">Setujui Permintaan</button>
                <button class="popup-button" id="btnRejectReset" style="background:#dc3545; margin-bottom:8px;">Tolak Permintaan</button>
            </div>
            <button class="popup-button" onclick="closePopup('popupReset')">Tutup</button>
        </div>
    </div>

    <!-- POPUP EXPORT -->
    <div class="popup-overlay" id="popupExport" style="display:none;">
        <div class="popup-box popup-animate">

            <h3 class="popup-title-large">Export File</h3>

            <select class="popup-select" id="rangeSelect">
                <option value="">Rentang Waktu</option>
                <option value="today">Hari Ini</option>
                <option value="week">Mingguan</option>
                <option value="month">Bulanan</option>
            </select>

            <div class="popup-result">
                <div class="zoom-btn"><?php echo $exportTotal > 0 ? safeText((string)$exportTotal) : '🔍'; ?></div>
                <div style="margin-top:6px;font-size:12px;color:#444;">
                    Total laporan 30 hari terakhir
                </div>
            </div>

            <select class="popup-select" id="formatSelect">
                <option value="">Pilih Format</option>
                <option value="pdf">PDF</option>
                <option value="excel">Excel</option>
            </select>
            <button class="popup-button" onclick="handleExport()">Konfirmasi</button>
        </div>
    </div>

    <!-- SIDEBAR -->
  <?php require_once '../../supervisor/components/sidebar.php'; ?>

    <!-- MAIN CONTENT -->
    <main class="content">

        <!-- HEADER -->
        <header class="header">
            <div class="header-left">
                <h2>Dashboard</h2>
            </div>

            <div class="header-right">
                <div class="profile-box">
                    <div class="profile-icon">S</div>
                    <div class="profile-info">
                        <div class="name"><?php echo safeText($supName); ?></div>
                        <div class="id"><?php echo safeText($supNip); ?></div>
                    </div>
                </div>
            </div>
        </header>

        <!-- STATISTIK -->
        <section class="stats">
            <div class="stat-card">
                <div class="num"><?php echo (int)$stats['total_users']; ?></div>
                <div class="label">Total Petugas</div>
            </div>

            <div class="stat-card">
                <div class="num"><?php echo (int)$stats['today_reports']; ?></div>
                <div class="label">Laporan Hari Ini</div>
            </div>

            <div class="stat-card">
                <div class="num"><?php echo (int)$stats['active_users']; ?></div>
                <div class="label">Petugas Aktif</div>
            </div>
        </section>

        <!-- LUPA PASSWORD -->
        <section class="card forgot-box">
            <div class="forgot-header">
                <h3>Lupa Password</h3>
                <span class="date"><?php echo safeText($dateLabel); ?></span>
            </div>

            <div class="forgot-list">
                <?php if (!empty($resets)) { 
                    $limit = 5;
                    $count = 0;
                    $i=1; 
                    foreach ($resets as $row) {
                        if ($count >= $limit) break;
                        $count++;
                        
                    $name  = (string)($row['name'] ?? '');
                    $nip   = (string)($row['nip'] ?? '');
                    $label = (string)($row['status_label'] ?? '');
                ?>
                <div class="forgot-item">
                    <div class="fi-left">
                        <div class="idx"><?php echo $i; ?></div>
                        <div class="name"><?php echo safeText($name); ?></div>
                    </div>
                    <button class="arrow-btn"
                            onclick="openResetFromList('<?php echo safeText($name); ?>','<?php echo safeText($nip); ?>','<?php echo safeText($label); ?>', <?php echo (int)$row['id']; ?>, '<?php echo safeText($row['status']); ?>')">
                        →
                    </button>
                </div>
                <?php $i++; } } else { ?>
                <div class="forgot-item">
                    <div class="fi-left">
                        <div class="idx">1</div>
                        <div class="name">Tidak ada permintaan reset</div>
                    </div>
                    <button class="arrow-btn"
                            onclick="openResetFromList('Tidak ada data','-','Belum ada permintaan reset dalam 7 hari terakhir')">
                        →
                    </button>
                </div>
                <?php } ?>
            </div>

            <div class="more" onclick="location.href='lupa_password_supervisor.php'">Selengkapnya</div>
        </section>

        <!-- SHORTCUT MENU -->
        <section class="actions">

            <!-- Data Petugas -->
            <div class="action-card" onclick="location.href='data_petugas.php'">
                <div class="icon-circle">
                    <i class="fas fa-id-card"></i>
                </div>
                <div class="title">Data Petugas</div>
            </div>

            <!-- Laporan Petugas -->
            <div class="action-card" onclick="location.href='laporan_petugas.php'">
                <div class="icon-circle">
                    <i class="fas fa-file-alt"></i>
                </div>
                <div class="title">Laporan Petugas</div>
            </div>

            <!-- Jadwal Penugasan -->
            <div class="action-card" onclick="location.href='jadwal_penugasan.php'">
                <div class="icon-circle">
                    <i class="fas fa-calendar-alt"></i>
                </div>
                <div class="title">Jadwal Penugasan</div>
            </div>

            <!-- Ekspor Laporan -->
            <div class="action-card" onclick="openPopup('popupExport')">
                <div class="icon-circle">
                    <i class="fas fa-file-export"></i>
                </div>
                <div class="title">Ekspor Laporan</div>
            </div>

        </section>

    </main>
</div>

<script>
function openPopup(id) {
    var el = document.getElementById(id);
    if (el) el.style.display = 'flex';
}

function closePopup(id) {
    var el = document.getElementById(id);
    if (el) el.style.display = 'none';
}

function openResetFromList(name, nip, info, id, status) {
    document.getElementById('resetName').textContent = name || '-';
    document.getElementById('resetNip').textContent  = nip  || '-';
    document.getElementById('resetPass').textContent = info || '-';
    document.getElementById('resetId').value = id || '';
    document.getElementById('resetStatus').value = status || '';
    
    // Show approve button only for pending requests
    var btnContainer = document.getElementById('resetBtnContainer');
    if (status === 'pending' && id) {
        btnContainer.style.display = 'block';
    } else {
        btnContainer.style.display = 'none';
    }
    
    openPopup('popupReset');
}

// Handle approve reset
document.getElementById('btnApproveReset').addEventListener('click', function() {
    var id = document.getElementById('resetId').value;
    if (!id) return;
    
    var btn = this;
    btn.disabled = true;
    btn.textContent = 'Memproses...';
    
    fetch('../actions/approve_reset.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ id: parseInt(id) })
    })
    .then(r => r.json())
    .then(data => {
        btn.disabled = false;
        btn.textContent = 'Setujui Permintaan';
        
        if (data.success) {
            alert('Permintaan berhasil disetujui! Petugas dapat membuat password baru.');
            closePopup('popupReset');
            location.reload();
        } else {
            alert('Gagal: ' + (data.error || 'Terjadi kesalahan'));
        }
    })
    .catch(err => {
        btn.disabled = false;
        btn.textContent = 'Setujui Permintaan';
        alert('Error: ' + err);
    });
});

// Handle reject reset
document.getElementById('btnRejectReset').addEventListener('click', function() {
    var id = document.getElementById('resetId').value;
    if (!id) return;
    
    if (!confirm('Apakah Anda yakin ingin menolak permintaan ini?')) return;
    
    var btn = this;
    btn.disabled = true;
    btn.textContent = 'Memproses...';
    
    fetch('../actions/reject_reset.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ id: parseInt(id) })
    })
    .then(r => r.json())
    .then(data => {
        btn.disabled = false;
        btn.textContent = 'Tolak Permintaan';
        
        if (data.success) {
            alert('Permintaan berhasil ditolak.');
            closePopup('popupReset');
            location.reload();
        } else {
            alert('Gagal: ' + (data.error || 'Terjadi kesalahan'));
        }
    })
    .catch(err => {
        btn.disabled = false;
        btn.textContent = 'Tolak Permintaan';
        alert('Error: ' + err);
    });
});

// Handler export
function handleExport() {
    const range  = document.getElementById('rangeSelect').value;
    const format = document.getElementById('formatSelect').value;

    if (!range || !format) {
        alert('Pilih rentang waktu dan format export terlebih dahulu');
        return;
    }

    const url = 'export_laporan.php?range=' + encodeURIComponent(range) +
                '&format=' + encodeURIComponent(format);
    window.location.href = url;
}
</script>

<script src="../../petugas/js/live-tracking.js"></script>
</body>
</html>
