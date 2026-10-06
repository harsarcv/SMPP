<?php
session_start();
require_once '../../config/database.php';
require_once '../../helpers/session.php';

// Auth admin only
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: ' . get_base_url() . '/auth/login.php');
    exit;
}

$safeText_defined = function_exists('safeText');
if (!$safeText_defined) {
    function safeText($text) {
        return htmlspecialchars($text ?? '', ENT_QUOTES, 'UTF-8');
    }
}

$DB_READY = true;
$brandSub = "Kelurahan Sunter Jaya";
$admName = $_SESSION['name'] ?? 'Admin';
$admNip = $_SESSION['nip'] ?? 'ADMIN001';

try {
    $pdo = Database::getInstance()->getConnection();

    // ============================================
    // STATISTIK DASHBOARD (data real dari DB)
    // ============================================

    // Total Petugas
    $stmt = $pdo->prepare("
        SELECT COUNT(*) 
        FROM users 
        WHERE role = 'petugas'
    ");
    $stmt->execute();
    $stats['total_users'] = (int)$stmt->fetchColumn();

    // Laporan Hari Ini (berdasarkan work_date)
    $stmt = $pdo->prepare("
        SELECT COUNT(*) 
        FROM reports 
        WHERE work_date = CURRENT_DATE
    ");
    $stmt->execute();
    $stats['today_reports'] = (int)$stmt->fetchColumn();



    // Laporan Selesai (Hari ini & Approved)
    $stmt = $pdo->prepare("
        SELECT COUNT(*) 
        FROM reports 
        WHERE work_date = CURRENT_DATE
          AND status = 'approved'
    ");
    $stmt->execute();
    $stats['completed_reports'] = (int)$stmt->fetchColumn();

    // ============================================
    // UPDATE TERBARU (LAPORAN + AKTIVITAS EXPORT)
    // ============================================

    $updates = [
        'reports' => [],
        'exports' => []
    ];

    // Laporan terbaru (10 laporan terakhir dalam 7 hari) + JOIN ke users & zones
    $stmt = $pdo->prepare("
        SELECT 
            r.id,
            r.created_at,
            r.status,
            u.name AS petugas_name,
            z.rw_code,
            z.name AS zone_name
        FROM reports r
        JOIN users u ON r.user_id = u.id
        JOIN zones z ON r.zone_id = z.id
        WHERE r.created_at >= CURRENT_DATE - INTERVAL 7 DAY
        ORDER BY r.created_at DESC
        LIMIT 10
    ");
    $stmt->execute();
    $updates['reports'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Aktivitas export/cetak terbaru di activity_logs + JOIN ke users (actor_name)
    $stmt = $pdo->prepare("
        SELECT 
            a.id,
            a.activity,
            a.created_at,
            u.name AS actor_name
        FROM activity_logs a
        LEFT JOIN users u ON a.user_id = u.id
        WHERE a.activity LIKE '%export%' 
           OR a.activity LIKE '%cetak%'
        ORDER BY a.created_at DESC
        LIMIT 5
    ");
    $stmt->execute();
    $updates['exports'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (Exception $e) {
    // Jika DB error, matikan flag dan set nilai default
    $DB_READY = false;
    $stats = [
        'total_users'   => 0,
        'today_reports' => 0,
        'completed_reports' => 0
    ];
    $updates = [
        'reports' => [],
        'exports' => []
    ];
}
?>
<!doctype html>
<html lang="id">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title>Admin Dashboard - Admin</title>

  <link rel="stylesheet" href="../css/dashboard.css">
</head>
<body>

<div class="app">

  <!-- SIDEBAR -->
  <?php $activePage = 'dashboard'; ?>
  <?php require_once '../../admin/components/sidebar.php'; ?>

  <!-- MAIN -->
  <main class="main">
    <!-- header -->
    <header class="header">
      <div class="header-left">
        <h1 class="page-title">Dashboard</h1>
      </div>

      <div class="header-right">
        <div class="profile">
          <div class="profile-text">
            <div class="profile-name"><?php echo safeText($admName); ?></div>
            <div class="profile-id"><?php echo safeText($admNip); ?></div>
          </div>
          <div class="avatar" aria-hidden="true">
            <svg viewBox="0 0 24 24" width="42" height="42" fill="none" xmlns="http://www.w3.org/2000/svg">
              <circle cx="12" cy="8" r="3.4" fill="#fff"/>
              <path d="M4 20c0-3.3 4.5-6 8-6s8 2.7 8 6" fill="#fff" opacity="0.9"/>
            </svg>
          </div>
        </div>
      </div>
    </header>

    <!-- stat row -->
    <section class="stats">
      <div class="stat-card">
        <div class="stat-num"><?php echo (int)$stats['total_users']; ?></div>
        <div class="stat-label">Total Petugas</div>
      </div>
      <div class="stat-card">
        <div class="stat-num"><?php echo (int)$stats['today_reports']; ?></div>
        <div class="stat-label">Laporan Hari Ini</div>
      </div>
      <div class="stat-card">
        <div class="stat-num"><?php echo (int)$stats['completed_reports']; ?></div>
        <div class="stat-label">Laporan Selesai</div>
      </div>
    </section>

    <!-- update terbaru -->
    <section class="updates">
      <div class="card updates-card">
        <div class="card-header">
          <div class="title">Update Terbaru</div>
          <div class="date"><?php echo safeText(date('d F Y')); ?></div>
        </div>

        <ol class="updates-list">
          <?php
            $num = 1;

            // Helper kecil untuk label status laporan
            function statusLabel($statusRaw) {
                $statusRaw = strtolower((string)$statusRaw);
                switch ($statusRaw) {
                    case 'approved':
                        return 'Disetujui';
                    case 'rejected':
                        return 'Ditolak';
                    case 'pending':
                    default:
                        return 'Menunggu Validasi';
                }
            }

            // 3 laporan terbaru
            foreach (array_slice($updates['reports'], 0, 3) as $r) {
                $petugas  = $r['petugas_name'] ?? 'Petugas';
                $rwCode   = $r['rw_code'] ?? '';
                $zoneName = $r['zone_name'] ?? '';
                $status   = $r['status'] ?? 'pending';

                $zonaText = '';
                if ($rwCode !== '') {
                    $zonaText = ' di RW ' . $rwCode;
                } elseif ($zoneName !== '') {
                    $zonaText = ' di ' . $zoneName;
                }

                $statusText = statusLabel($status);

                $txt = $petugas . ' mengirim laporan' . $zonaText . ' (' . $statusText . ')';

                echo '<li><span class="num">' . safeText((string)$num++) . '</span> ' . safeText($txt) . '</li>';
            }

            // 2 aktivitas export/cetak terbaru
            foreach (array_slice($updates['exports'], 0, 2) as $e) {
                $actor = $e['actor_name'] ?? 'Admin';
                $activityText = $e['activity'] ?? 'Export Data';
                $txt = $actor . ' - ' . $activityText;
                echo '<li><span class="num">' . safeText((string)$num++) . '</span> ' . safeText($txt) . '</li>';
            }

            if ($num === 1) {
                echo '<li><span class="num">-</span> Tidak ada update terbaru</li>';
            }
          ?>
        </ol>
      </div>
    </section>

    <!-- action grid -->
    <section class="actions-grid">
      <div class="card actions-card">
        <div class="grid">
          <button class="action" onclick="location.href='data_petugas.php'">
            <div class="action-icon">👥</div>
            <div class="action-title">DATA PETUGAS</div>
          </button>

          <button class="action" onclick="location.href='laporan_petugas.php'">
            <div class="action-icon">📄</div>
            <div class="action-title">LAPORAN HARIAN</div>
          </button>

          <button class="action" onclick="location.href='jadwal_penugasan.php'">
            <div class="action-icon">📆</div>
            <div class="action-title">JADWAL PETUGAS</div>
          </button>

          <button class="action" onclick="location.href='buat_jadwal.php'">
            <div class="action-icon">✏️</div>
            <div class="action-title">BUAT JADWAL</div>
          </button>

          <button class="action" onclick="location.href='tambah_petugas.php'">
            <div class="action-icon">➕</div>
            <div class="action-title">TAMBAH PETUGAS</div>
          </button>

          <button class="action" onclick="location.href='detail_kpi.php'">
            <div class="action-icon">📊</div>
            <div class="action-title">DETAIL KPI</div>
          </button>
        </div>
      </div>
    </section>

  </main>
</div>

<script>
  window.DB_READY = <?php echo $DB_READY ? 'true' : 'false'; ?>;
</script>
<script src="../../public/js/dashboard-integration.js"></script>
</body>
</html>
