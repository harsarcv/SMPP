<?php
session_start();
require_once '../../config/database.php';
require_once '../../helpers/session.php';
enforce_session(['petugas']);

// Wajib login sebagai petugas & status aktif
require_login();
require_role('petugas');
require_active_user();

// Helper text aman (jaga-jaga kalau belum didefinisikan di helpers)
if (!function_exists('safeText')) {
    function safeText($text) {
        return htmlspecialchars($text ?? '', ENT_QUOTES, 'UTF-8');
    }
}

function avatarInitials($name) {
    $name = trim((string)$name);
    if ($name === '') return 'P';
    $parts = preg_split('/\s+/', $name);
    $first = $parts[0] ?? '';
    $last = $parts[count($parts) - 1] ?? '';
    $pick = $first;
    if ($last !== '' && $last !== $first) {
        $pick = $first . ' ' . $last;
    }
    $letters = preg_split('//u', $pick, -1, PREG_SPLIT_NO_EMPTY);
    $out = '';
    foreach ($letters as $ch) {
        if (preg_match('/[A-Za-z0-9]/u', $ch)) {
            $out .= $ch;
            if ((function_exists('mb_strlen') ? mb_strlen($out) : strlen($out)) >= 2) break;
        }
    }
    if ($out === '') {
        $out = function_exists('mb_substr') ? mb_substr($name, 0, 1) : substr($name, 0, 1);
    }
    return strtoupper($out);
}

try {
    $pdo = Database::getInstance()->getConnection();
    require_once '../../petugas/controllers/PetugasController.php';
    $petugasController = new PetugasController();

    $userId = (int)($_SESSION['user_id'] ?? 0);

    $profRes = $petugasController->getProfileData($userId);
    $u = !empty($profRes['success']) ? ($profRes['user'] ?? []) : [];
    $pName  = $u['name']  ?? ($_SESSION['username'] ?? 'Petugas');
    $pNip   = $u['nip']   ?? ($_SESSION['nip'] ?? '');
    $status = $u['status'] ?? 'nonaktif';

    // ---------------------------
    // FILTER RIWAYAT
    // ---------------------------
    // filter: today | date | location
    $filter       = $_GET['filter'] ?? 'today';
    $dateFilter   = $_GET['date'] ?? '';
    $locFilter    = $_GET['location'] ?? '';

    // Normalisasi nilai filter
    if (!in_array($filter, ['today','date','location'], true)) {
        $filter = 'today';
    }

    // Label indikator di UI
    $filterLabel = '-';
    if ($filter === 'today') {
        $filterLabel = 'Hari ini';
    } elseif ($filter === 'date') {
        $filterLabel = 'Tanggal';
    } elseif ($filter === 'location') {
        $filterLabel = 'Lokasi';
    }

    $filterArr = [];
    if ($filter === 'today') {
        $filterArr['date'] = date('Y-m-d');
    } elseif ($filter === 'date' && $dateFilter !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFilter)) {
        $filterArr['date'] = $dateFilter;
    } elseif ($filter === 'location' && $locFilter !== '') {
        $filterArr['location'] = $locFilter;
    }

    $histRes = $petugasController->getRiwayatLaporan($userId, $filterArr);
    $rows = !empty($histRes['success']) ? ($histRes['riwayat'] ?? []) : [];

    // Bentuk array laporan untuk template
    $reports = [];
    $basePhotoPath = '/public/uploads/';

    foreach ($rows as $r) {
        $tanggal = $r['work_date'] ? (new DateTime($r['work_date']))->format('d/m/Y') : '';
        // Sesi kerja pakai enum work_session_enum (pagi/siang/sore/malam)
        $sesi = $r['session'] ?? '';
        if ($sesi !== '') {
            // Format: Pagi, Siang, ...
            $sesi = ucfirst($sesi);
        }

        $beforePath = $r['before_photo_path'] ?? '';
        $afterPath  = $r['after_photo_path'] ?? '';

        $beforeUrl = $beforePath !== '' 
            ? $basePhotoPath . ltrim($beforePath, '/')
            : '../../assets/icon/icon-image.png';

        $afterUrl = $afterPath !== '' 
            ? $basePhotoPath . ltrim($afterPath, '/')
            : '../../assets/icon/icon-image.png';

        $reports[] = [
            'id'           => (int)$r['id'],
            'tanggal'      => $tanggal,
            'sesi'         => $sesi,
            'lokasi'       => $r['lokasi'] ?? '-',
            'before_url'   => $beforeUrl,
            'after_url'    => $afterUrl,
            'status'       => $r['status'] ?? 'pending',
            'gps_result'   => $r['gps_result'] ?? null,
        ];
    }

} catch (Throwable $e) {
    // Jika terjadi error, jangan bocorkan detail ke user
    $reports = [];
    $pName = $pName ?? 'Petugas';
    $pNip  = $pNip ?? '';
    $filterLabel = '-';
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Riwayat Laporan | SMPP</title>
  <link rel="stylesheet" href="../css/riwayat.css" />
  <link href="https://fonts.googleapis.com/css2?family=Karla:wght@400;500;700;800;900&display=swap" rel="stylesheet">
</head>
<body>

  <!-- HEADER -->
  <header class="dash-header">
    <div class="header-top" onclick="location.href='profile.php'" role="button" tabindex="0">
      <div class="user-info">
        <h3 class="user-name"><?php echo safeText($pName); ?></h3>
        <p class="user-nip"><?php echo safeText($pNip); ?></p>
      </div>
      <div class="avatar-icon">
        <!-- Ganti inisial dengan icon default sesuai request -->
        <img src="../../assets/icon/icon-avatar.png" alt="Profile" style="width:100%; height:100%; object-fit:cover; border-radius: 50%;">
      </div>
    </div>
  </header>

  <!-- RIWAYAT SECTION -->
  <div class="dashboard">
    <section class="judul">
      <div class="judul-content">
        <h2 class="form-title">RIWAYAT LAPORAN</h2>
      </div>
    </section>

    <section class="riwayat-section">
      <div class="filter-box">
        <h3>Filter</h3>

        <!-- Form GET untuk filter server-side -->
        <form method="get" id="filterForm" class="filter-form">
          <select class="filter-dropdown" id="filterSelect" name="filter">
            <option value="">Pilih Riwayat Berdasarkan</option>
            <option value="today"    <?php echo $filter==='today'    ? 'selected' : ''; ?>>Hari ini</option>
            <option value="date"     <?php echo $filter==='date'     ? 'selected' : ''; ?>>Tanggal</option>
            <option value="location" <?php echo $filter==='location' ? 'selected' : ''; ?>>Lokasi</option>
          </select>

          <!-- Input tanggal hanya tampil jika filter=date -->
          <div id="dateFilterWrapper" style="margin-top:8px; <?php echo $filter==='date' ? '' : 'display:none;'; ?>">
            <input type="date" name="date" value="<?php echo safeText($dateFilter); ?>" style="width:100%;padding:6px 10px;border-radius:8px;border:1px solid #ccc;">
          </div>

          <!-- Input lokasi hanya tampil jika filter=location -->
          <div id="locationFilterWrapper" style="margin-top:8px; <?php echo $filter==='location' ? '' : 'display:none;'; ?>">
            <input type="text" name="location" placeholder="Cari Lokasi" value="<?php echo safeText($locFilter); ?>" style="width:100%;padding:6px 10px;border-radius:8px;border:1px solid #ccc;">
          </div>

          <button type="submit" style="margin-top:8px;padding:6px 12px;border:none;border-radius:8px;background:#FF9470;color:#fff;font-weight:700;">
            Terapkan
          </button>
        </form>
      </div>

      <div class="hasil-box">
        <div class="hasil-header">
          <h3>Hasil Riwayat</h3>
          <span id="filterIndicator">Berdasarkan : <?php echo safeText($filterLabel); ?></span>
        </div>

        <!-- DAFTAR LAPORAN -->
        <div class="laporan-list" id="laporanList">
          <?php if (!empty($reports)): ?>
            <?php foreach ($reports as $index => $report): ?>
              <div class="laporan-item"
                   data-id="<?php echo (int)$report['id']; ?>"
                   data-before="<?php echo safeText($report['before_url']); ?>"
                   data-after="<?php echo safeText($report['after_url']); ?>"
                   data-status="<?php echo safeText($report['status']); ?>"
                   data-gps="<?php echo safeText((string)$report['gps_result']); ?>">
                <div class="laporan-info">
                  <div class="laporan-field">
                    <span class="field-label">Tanggal:</span>
                    <span class="field-value"><?php echo safeText($report['tanggal']); ?></span>
                  </div>
                  <div class="laporan-field">
                    <span class="field-label">Sesi Kerja:</span>
                    <span class="field-value"><?php echo safeText($report['sesi']); ?></span>
                  </div>
                  <div class="laporan-field">
                    <span class="field-label">Lokasi Penugasan:</span>
                    <span class="field-value"><?php echo safeText($report['lokasi']); ?></span>
                  </div>
                </div>
                <div class="laporan-arrow">›</div>
              </div>
            <?php endforeach; ?>
          <?php else: ?>
            <div class="no-data">Tidak ada data laporan untuk kriteria ini</div>
          <?php endif; ?>
        </div>

        <!-- DETAIL FOTO LAPORAN -->
        <div class="foto-detail" id="fotoDetail" style="display: none;">
          <div class="foto-header">
            <h4>Foto Laporan</h4>
            <button class="close-btn" id="closeDetail">×</button>
          </div>
          
          <div class="foto-comparison">
            <div class="foto-side">
              <h5>Before</h5>
              <div class="foto-container">
                <img src="../assets/icon/icon-photo.png" alt="Before" id="fotoBefore">
              </div>
            </div>
            <div class="foto-side">
              <h5>After</h5>
              <div class="foto-container">
                <img src="../assets/icon/icon-photo.png" alt="After" id="fotoAfter">
              </div>
            </div>
          </div>

          <div class="status-row-detail">
            <div class="badge" id="badgeStatus">Status: -</div>
            <div class="badge" id="badgeGps">GPS: -</div>
          </div>

          <div class="foto-info">
            <p>Riwayat Foto ini hanya tersedia untuk laporan 1 bulan terbaru</p>
          </div>
        </div>
      </div>
    </section>

    <!-- FOOTER -->
    <footer class="footer-btns">
      <div class="footer-wrapper">
        <button class="circle-btn home-btn" onclick="window.location.href='dashboard.php'">
          <img src="../../assets/icon/icon-home.png" alt="Home" class="btn-icon">
        </button>
        <button class="circle-btn map-btn" onclick="window.location.href='laporan.php'">
          <img src="../../assets/icon/icon-laporan.png" alt="Laporan" class="btn-icon">
        </button>
      </div>
    </footer>
  </div>

  <script>
    // Toggle input tambahan berdasarkan pilihan filter
    const filterSelect = document.getElementById('filterSelect');
    const dateWrapper = document.getElementById('dateFilterWrapper');
    const locWrapper  = document.getElementById('locationFilterWrapper');

    filterSelect.addEventListener('change', function () {
      const val = this.value;
      dateWrapper.style.display = (val === 'date') ? '' : 'none';
      locWrapper.style.display  = (val === 'location') ? '' : 'none';
    });

    // Klik item laporan -> tampilkan detail (foto + status + gps)
    document.querySelectorAll('.laporan-item').forEach(item => {
      item.addEventListener('click', function() {
        const before = this.getAttribute('data-before');
        const after  = this.getAttribute('data-after');
        const status = this.getAttribute('data-status') || '-';
        const gps    = this.getAttribute('data-gps') || '-';

        document.getElementById('fotoBefore').src = before || '../assets/icon/icon-photo.png';
        document.getElementById('fotoAfter').src  = after  || '../assets/icon/icon-photo.png';

        const badgeStatus = document.getElementById('badgeStatus');
        const badgeGps    = document.getElementById('badgeGps');

        badgeStatus.textContent = 'Status: ' + status;
        badgeGps.textContent    = 'GPS: ' + gps;

        // Warna badge GPS berdasarkan nilai
        badgeGps.classList.remove('gps-valid','gps-suspicious','gps-fake');
        if (gps === 'valid') {
          badgeGps.classList.add('gps-valid');
        } else if (gps === 'suspicious') {
          badgeGps.classList.add('gps-suspicious');
        } else if (gps === 'fake') {
          badgeGps.classList.add('gps-fake');
        }

        document.getElementById('laporanList').style.display = 'none';
        document.getElementById('fotoDetail').style.display  = 'block';
      });
    });

    // Tombol tutup detail
    document.getElementById('closeDetail').addEventListener('click', function() {
      document.getElementById('fotoDetail').style.display  = 'none';
      document.getElementById('laporanList').style.display = 'block';
    });
  </script>

  <script src="../js/live-tracking.js"></script>
</body>
</html>
