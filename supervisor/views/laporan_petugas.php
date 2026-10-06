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

if (!function_exists('safeText')) {
    function safeText($text) {
        return htmlspecialchars($text ?? '', ENT_QUOTES, 'UTF-8');
    }
}

// =============== GET SUPERVISOR'S GROUP ===============
$supervisorGroupId = null;
$supervisorGroupName = null;
$debugInfo = [];
try {
    // Fetch group from groups table where supervisor_id matches current supervisor
    $grpStmt = $pdo->prepare("SELECT id, name FROM `groups` WHERE supervisor_id = :sup_id LIMIT 1");
    $grpStmt->execute([':sup_id' => $supervisorId]);
    $grpResult = $grpStmt->fetch(PDO::FETCH_ASSOC);
    if ($grpResult) {
        $supervisorGroupId = $grpResult['id'];
        $supervisorGroupName = $grpResult['name'];
        $debugInfo['supervisor_id'] = $supervisorId;
        $debugInfo['group_id'] = $supervisorGroupId;
        $debugInfo['group_name'] = $supervisorGroupName;
    }
} catch (Exception $e) {
    $debugInfo['error'] = $e->getMessage();
}

$periodDays = 3650; // Show data for last 10 years (effectively all for now)

// =============== PARAMETER FILTER ===============
$search = $_GET['q'] ?? '';          // filter nama/lokasi
$dateFilter = $_GET['date'] ?? '';   // filter tanggal spesifik
$sort = $_GET['sort'] ?? 'nama';     // nama | tanggal | lokasi
$filterUserId = $_GET['user_id'] ?? ''; // Filter specific user

// If user_id is provided, get name for search placeholder or info
if (!empty($filterUserId) && empty($search)) {
    $stmtName = $pdo->prepare("SELECT name FROM users WHERE id = ?");
    $stmtName->execute([$filterUserId]);
    $uName = $stmtName->fetchColumn();
    if ($uName) {
        $search = $uName; // Pre-fill search with name to make UI consistent
    }
}

// =============== QUERY RIWAYAT LAPORAN (READ ONLY) ===============
$reportRows = [];

try {
    $baseSql = "
        SELECT 
            r.id,
            r.user_id,
            u.name AS petugas,
            r.created_at,
            r.work_date,
            z.name AS lokasi,
            z.rw_code,
            r.description,
            r.status,
            r.latitude,
            r.longitude
        FROM reports r
        JOIN users u ON r.user_id = u.id 
        LEFT JOIN zones z ON r.zone_id = z.id
        WHERE r.created_at >= DATE_SUB(CURDATE(), INTERVAL {$periodDays} DAY)
    ";
    
    $params = [];

    // IMPORTANT: Filter by supervisor's group
    if ($supervisorGroupId) {
        $baseSql .= " AND u.group_id = :group_id ";
        $params[':group_id'] = $supervisorGroupId;
    } else {
        // Supervisor has no group, show nothing
        $baseSql .= " AND 1 = 0 ";
    }

    if (!empty($search)) {
        $baseSql .= " AND (u.name LIKE :search OR z.name LIKE :search) ";
        $params[':search'] = "%{$search}%";
    }

    if (!empty($dateFilter)) {
        $baseSql .= " AND r.work_date = :date ";
        $params[':date'] = $dateFilter;
    }

    if (!empty($filterUserId)) {
        $baseSql .= " AND r.user_id = :uid ";
        $params[':uid'] = $filterUserId;
    }

    if ($sort === 'tanggal') {
        $baseSql .= " ORDER BY r.created_at DESC, u.name ASC ";
    } elseif ($sort === 'lokasi') {
        $baseSql .= " ORDER BY z.rw_code ASC NULLS LAST, r.created_at DESC ";
    } else {
        $baseSql .= " ORDER BY u.name ASC, r.created_at DESC ";
    }

    $baseSql .= " LIMIT 500";

    $stmt = $pdo->prepare($baseSql);
    $stmt->execute($params);
    $reportRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // PHOTOS
    $reportIds = array_column($reportRows, 'id');
    $photosByReport = [];
    
    if (!empty($reportIds)) {
        $placeholders = implode(',', array_fill(0, count($reportIds), '?'));
        $sqlPhoto = "
            SELECT 
                rp.report_id,
                rp.file_path,
                rp.photo_type,
                rp.uploaded_at,
                u.name as petugas_name
            FROM report_photos rp
            JOIN reports r ON rp.report_id = r.id
            JOIN users u ON r.user_id = u.id
            WHERE rp.report_id IN ($placeholders)
            ORDER BY rp.uploaded_at DESC
        ";
        $ps = $pdo->prepare($sqlPhoto);
        $ps->execute($reportIds);
        $allPhotos = $ps->fetchAll(PDO::FETCH_ASSOC);

        foreach ($allPhotos as $ph) {
            $rid = $ph['report_id'];
            if (!isset($photosByReport[$rid])) {
                $photosByReport[$rid] = [];
            }
            $src = '../../public/uploads/' . ltrim($ph['file_path'], '/'); 
            $ph['full_path'] = $src;
            $photosByReport[$rid][] = $ph;
        }
    }

    // GPS LATEST (READ ONLY)
    $gpsLatest = [];
    $stmt = $pdo->query("
        SELECT 
            r.id,
            u.name AS petugas,
            r.created_at,
            z.rw_code,
            z.name AS zone_name,
            r.latitude,
            r.longitude
        FROM reports r
        JOIN users u ON r.user_id = u.id
        LEFT JOIN zones z ON r.zone_id = z.id
        WHERE r.work_date = CURRENT_DATE
        ORDER BY r.created_at DESC
        LIMIT 20
    ");
    $gpsLatest = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (Exception $e) {
    // Only log error
    $reportRows = [];
}
?>
<!doctype html>
<html lang="id">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title>Laporan Petugas - Supervisor</title>
  <link rel="stylesheet" href="../css/dashboard.css">
  <link rel="stylesheet" href="../css/laporan_petugas.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
  <style>
      /* Ensure layout is flex to put sidebar side-by-side */
      .layout {
          display: flex;
          height: 100vh;
      }
      .content {
          flex: 1;
          padding: 25px;
          overflow-y: auto;
          background-color: #f3f7f3; /* dashboard bg match */
      }
  </style>
</head>
<body>

<div class="layout">

  <!-- SIDEBAR -->
  <?php $activePage = 'laporan_petugas'; ?>
  <?php require_once '../../supervisor/components/sidebar.php'; ?>

  <!-- MAIN -->
  <main class="content">

<header class="header">
  <div class="header-left">
    <h1 class="page-title">Monitoring Laporan</h1>
  </div>
  <div class="header-right">
    <div class="profile">
      <div class="profile-text">
        <div class="profile-name"><?php echo safeText($admName); ?></div>
        <div class="profile-id"><?php echo safeText($admNip); ?></div>
      </div>
      <div class="avatar-sv">
        <i class="fas fa-user-tie"></i>
      </div>
    </div>
  </div>
</header>

<div class="title-center">MONITORING LAPORAN PETUGAS</div>

<section class="report-box">
  
  <!-- Debug Info: Show supervisor's group status -->
  <?php if (empty($supervisorGroupId)): ?>
    <div style="background: #fee2e2; border: 1px solid #ef4444; border-radius: 8px; padding: 15px; margin-bottom: 20px; color: #991b1b;">
      <strong><i class="fas fa-exclamation-triangle"></i> Peringatan:</strong> Akun supervisor Anda (ID: <?= $supervisorId ?>) <strong>belum terdaftar dalam grup manapun</strong>. 
      <br><br>
      Silakan minta Admin untuk menambahkan Anda ke dalam grup di menu <strong>Data Supervisor</strong> atau <strong>Kelola Group</strong>.
    </div>
  <?php else: ?>
    <div style="background: #dbeafe; border: 1px solid #3b82f6; border-radius: 8px; padding: 12px; margin-bottom: 15px; color: #1e40af; font-size: 14px;">
      <i class="fas fa-info-circle"></i> Menampilkan laporan untuk grup: <strong><?= safeText($supervisorGroupName) ?></strong> (Group ID: <?= $supervisorGroupId ?>)
    </div>
  <?php endif; ?>
  
  <div class="filter-container">
      <h4 class="section-title"><i class="fas fa-filter"></i> Filter Data</h4>
      <form method="get" id="filterForm" class="filter-row">
        <div class="filter-group">
          <label for="q">Pencarian</label>
          <input type="text" name="q" id="q" class="filter-input" placeholder="Nama / Lokasi" value="<?php echo safeText($search); ?>">
        </div>
        <div class="filter-group">
          <label for="date">Tanggal</label>
          <input type="date" name="date" id="date" class="filter-input" value="<?php echo safeText($dateFilter); ?>">
        </div>
        <div class="filter-group">
          <label for="lapFilter">Urutkan</label>
          <select name="sort" id="lapFilter" class="filter-select">
            <option value="nama" <?php echo $sort==='nama'?'selected':''; ?>>Nama</option>
            <option value="tanggal" <?php echo $sort==='tanggal'?'selected':''; ?>>Waktu</option>
            <option value="lokasi" <?php echo $sort==='lokasi'?'selected':''; ?>>Lokasi</option>
          </select>
        </div>
        <div class="filter-group action-group">
          <button type="submit" class="btn-apply"><i class="fas fa-search"></i> Cari</button>
          <?php if($search || $dateFilter): ?>
            <a href="laporan_petugas.php" class="btn-reset"><i class="fas fa-times"></i> Reset</a>
          <?php endif; ?>
        </div>
      </form>
  </div>

  <div class="label-row">
    <span class="label-title">Riwayat Laporan Masuk</span>
    <span class="label-urut">Total: <?php echo count($reportRows); ?></span>
  </div>

  <div class="result-box">
    <?php if (!empty($reportRows)): ?>
      <table class="sv-table">
        <thead>
          <tr>
            <th>Petugas</th>
            <th>Waktu & Tanggal</th>
            <th>Lokasi Tugas</th>
            <th>Koordinat</th>
            <th>Status</th>
            <th>Aksi</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($reportRows as $row): 
            $dt = new DateTime($row['created_at']);
            $lat = $row['latitude'] ? number_format((float)$row['latitude'], 6) : '-';
            $lng = $row['longitude'] ? number_format((float)$row['longitude'], 6) : '-';
            $rw  = $row['rw_code'] ? ('RW '.$row['rw_code']) : '';
            
            // Status Badge
            $statusClass = ($row['status'] === 'approved') ? 'badge-success' : 'badge-pending';
            $statusLabel = ($row['status'] === 'approved') ? 'Selesai' : 'Pending';
          ?>
            <tr>
              <td>
                <div class="row-main-text"><?php echo safeText($row['petugas']); ?></div>
              </td>
              <td>
                <div class="dt-date"><?php echo safeText($dt->format('d M Y')); ?></div>
                <div class="dt-time"><?php echo safeText($dt->format('H:i')); ?></div>
              </td>
              <td>
                <div class="loc-rw"><?php echo safeText($rw); ?></div>
                <div class="loc-name"><?php echo safeText($row['lokasi']); ?></div>
              </td>
              <td>
                <?php if($lat !== '-'): ?>
                <a href="https://www.google.com/maps?q=<?= $row['latitude'] ?>,<?= $row['longitude'] ?>" 
                   target="_blank" class="coord-tag" style="text-decoration:none;" title="Buka di Google Maps">
                   📍 <?= $lat ?>, <?= $lng ?>
                </a>
                <?php else: ?>
                <span class="coord-tag">-</span>
                <?php endif; ?>
              </td>
              <td>
                <span class="status-badge <?= $statusClass ?>"><?= $statusLabel ?></span>
              </td>
              <td>
                <?php 
                    $currPhotos = $photosByReport[$row['id']] ?? [];
                    $hasPhotos = !empty($currPhotos);
                    $jsonPhotos = htmlspecialchars(json_encode($currPhotos), ENT_QUOTES, 'UTF-8');
                ?>
                <button type="button" class="btn-view-detail <?php echo $hasPhotos ? '' : 'disabled'; ?>" 
                        onclick="openDetailModal('<?php echo safeText($row['petugas']); ?>', '<?php echo safeText($rw); ?>', <?php echo $jsonPhotos; ?>, '<?php echo $row['status']; ?>')"
                        <?php echo $hasPhotos ? '' : 'disabled'; ?>>
                    <i class="fas fa-eye"></i> Detail
                </button>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php else: ?>
      <div class="empty-state">
        <i class="fas fa-folder-open"></i>
        <p>Tidak ada data laporan ditemukan.</p>
      </div>
    <?php endif; ?>
  </div>



</section>
</main>
</div>

<!-- INFO MODAL -->
<div id="detailModal" class="modal-overlay">
  <div class="modal-wrapper">
    <div class="modal-header">
      <div>
          <div id="modalTitle" class="modal-title">Detail Laporan</div>
          <div id="modalStatus" class="modal-subtitle"></div>
      </div>
      <button class="modal-close" onclick="closeDetailModal()">&times;</button>
    </div>
    <div class="modal-body">
      <div id="modalPhotos" class="photo-grid"></div>
    </div>
  </div>
</div>

<script>
document.getElementById('lapFilter').addEventListener('change', function() {
    document.getElementById('filterForm').submit();
});

function openDetailModal(petugas, lokasi, photos, status) {
    const modal = document.getElementById('detailModal');
    const title = document.getElementById('modalTitle');
    const statusLabel = document.getElementById('modalStatus');
    const container = document.getElementById('modalPhotos');
    
    title.innerHTML = `${petugas} - ${lokasi}`;
    container.innerHTML = '';
    
    if (status === 'approved') {
        statusLabel.innerHTML = '<span class="badge-success">Selesai / Divalidasi</span>';
    } else {
        statusLabel.innerHTML = '<span class="badge-pending">Pending Check</span>';
    }
    
    if (!photos || photos.length === 0) {
        container.innerHTML = '<div class="no-photo">Tidak ada dokumentasi foto.</div>';
    } else {
        photos.forEach(ph => {
            const typeLabel = ph.photo_type ? ph.photo_type.toUpperCase() : 'FOTO';
            const imgPath = ph.full_path; 
            
            const card = document.createElement('div');
            card.className = 'photo-card';
            card.innerHTML = `
                <div class="img-wrapper">
                    <img src="${imgPath}" onclick="window.open('${imgPath}', '_blank')" onerror="this.src='../../assets/img/no-schedule.svg'">
                    <span class="photo-badge">${typeLabel}</span>
                </div>
                <div class="photo-meta">
                    ${new Date(ph.uploaded_at).toLocaleString('id-ID')}
                </div>
            `;
            container.appendChild(card);
        });
    }
    
    modal.style.display = 'flex';
}

function closeDetailModal() {
    document.getElementById('detailModal').style.display = 'none';
}
document.getElementById('detailModal').addEventListener('click', function(e) {
    if (e.target === this) closeDetailModal();
});
</script>
</body>
</html>
