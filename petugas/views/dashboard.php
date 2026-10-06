<?php
// Mulai session
session_start();

require_once '../../config/database.php';

// ======================
// Helper fungsi umum
// ======================
function safeText($text) {
    return htmlspecialchars($text ?? '', ENT_QUOTES, 'UTF-8');
}

function fmtTime($time) {
    if (empty($time)) return '';
    return date('H.i', strtotime($time));
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

// ======================
// 1. Cek autentikasi dasar (session + token)
// ======================
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'petugas') {
    header('Location: /auth/login.php');
    exit;
}

// Handler: ubah status kerja via AJAX (terima POST/GET, robust)
if (
    ($_SERVER['REQUEST_METHOD'] === 'POST' || $_SERVER['REQUEST_METHOD'] === 'GET') &&
    (($_POST['action'] ?? $_GET['action'] ?? '') === 'work_status')
) {
    header('Content-Type: application/json');
    error_log('[work_status] request masuk: method=' . $_SERVER['REQUEST_METHOD']);
    $csrf = $_POST['csrf_token'] ?? $_GET['csrf_token'] ?? '';
    if (!$csrf || $csrf !== ($_SESSION['csrf_token'] ?? '')) {
        error_log('[work_status] CSRF tidak valid: token_post=' . ($csrf ?: 'null') . ' token_sess=' . ($_SESSION['csrf_token'] ?? 'null'));
        echo json_encode(['success' => false, 'message' => 'CSRF tidak valid']);
        exit;
    }
    $status = $_POST['status'] ?? $_GET['status'] ?? '';
    // 4-Status System: Allow 'start' and 'stop' actions
    $workAction = $_POST['status'] ?? '';
    // Backward compatibility: convert old params
    if ($workAction === 'aktif') $workAction = 'start';
    if ($workAction === 'nonaktif') $workAction = 'stop';
    
    if (!in_array($workAction, ['start','stop'], true)) {
        error_log('[work_status] Status tidak valid: ' . ($status ?? 'unknown'));
        echo json_encode(['success' => false, 'message' => 'Aksi tidak valid']);
        exit;
    }
    try {
        require_once '../../petugas/controllers/PetugasController.php';
        $controller = new PetugasController();
        $result = $controller->updateWorkStatus((int)($_SESSION['user_id'] ?? 0), $workAction);
        if (!empty($result['success'])) {
            error_log('[work_status] Berhasil update status ke: ' . ($result['status'] ?? 'unknown'));
            echo json_encode(['success' => true, 'data' => ['status' => $result['status']]]);
        } else {
            error_log('[work_status] Gagal update: ' . ($result['error'] ?? 'unknown'));
            echo json_encode(['success' => false, 'message' => $result['error'] ?? 'Gagal memperbarui status']);
        }
    } catch (Throwable $e) {
        error_log('[work_status] Exception: ' . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Gagal memperbarui status']);
    }
    exit;
}

$userId = (int)($_SESSION['user_id'] ?? 0);

// ======================
// 2. Cek role wajib PETUGAS
// ======================
if (($_SESSION['role'] ?? '') !== 'petugas') {
    $r = safeText($_SESSION['role'] ?? '');
    header('Location: /' . $r . '/views/dashboard.php');
    exit();
}

// ======================
// 3. Ambil data profil + KPI + progres harian dari database
// ======================
try {
    require_once '../../petugas/controllers/PetugasController.php';
    $controller = new PetugasController();

    $profileRes = $controller->getProfileData($userId);
    if (empty($profileRes['success'])) {
        session_destroy();
        header('Location: ../auth/login.php?error=user_not_found');
        exit();
    }

    $userRow = $profileRes['user'] ?? [];
    $kpiRow = $profileRes['kpi'] ?? [];
    $statsRow = $profileRes['stats'] ?? [];

    $att      = (int)($kpiRow['presence_rate']     ?? 0);
    $comp     = (int)($kpiRow['completion_rate']   ?? 0);
    $timeOk   = (int)($kpiRow['punctuality_rate']  ?? 0);
    $scoreVal = (int)($kpiRow['score_total']       ?? 0);

    $totalTasks     = (int)($statsRow['total_tugas']       ?? 0);
    $completedTasks = (int)($statsRow['laporan_hari_ini']  ?? 0);

    $jadwalRes = $controller->getJadwalHariIni($userId);
    $items = !empty($jadwalRes['success']) ? ($jadwalRes['jadwal'] ?? []) : [];

} catch (Throwable $e) {
    // Kalau ada error DB, jangan lempar ke user; tampilkan minimal state
    $userRow = $userRow ?? [
        'name'       => $_SESSION['username'] ?? 'Petugas',
        'nip'        => $_SESSION['nip'] ?? '',
        'status'     => 'nonaktif',
        'photo_path' => 'default_profile.png',
    ];
    $att = $comp = $timeOk = $scoreVal = 0;
    $totalTasks = $completedTasks = 0;
    $items = [];
}

// ======================
// 4. Data untuk tampilan
// ======================

$pName     = $userRow['name']  ?? ($_SESSION['username'] ?? 'Petugas');
$pNip      = $userRow['nip']   ?? ($_SESSION['nip'] ?? '');
$pStatus   = $userRow['status']?? 'nonaktif';
$photoPath = $userRow['photo_path'] ?? 'default_profile.png';

// Status online / status kerja (4-Status System)
$isOnline = ($pStatus === 'online');
$canWork = in_array($pStatus, ['online', 'offline']);

// Date label (Bahasa Indonesia)
$dayNames = ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'];
$hariIdx  = (int)date('w');
$tanggal  = date('d/m/Y');
$dateLabel = $dayNames[$hariIdx] . ', ' . $tanggal;

// Untuk popup detail, pakai item pertama jika ada
$firstItem = $items[0] ?? null;
$popupLokasi = $firstItem['zone_name']      ?? '';
$popupDesc   = $firstItem['description']    ?? '';
$popupStart  = isset($firstItem['start_time']) ? fmtTime($firstItem['start_time']) : '08.00';
$popupEnd    = isset($firstItem['end_time'])   ? fmtTime($firstItem['end_time'])   : '10.00';
$popupWaktu  = $dateLabel . ', ' . $popupStart . '–' . $popupEnd;

// Simpan progres ke dalam array profile untuk JS jika nanti mau dipakai
$profileSummary = [
    'total_tasks'     => $totalTasks,
    'completed_tasks' => $completedTasks,
    'presence_rate'   => $att,
    'completion_rate' => $comp,
    'punctuality_rate'=> $timeOk,
    'kpi_score'       => $scoreVal,
];

?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Dashboard Petugas | SMPP</title>
  <link rel="stylesheet" href="../css/dashboard.css" />
  <link href="https://fonts.googleapis.com/css2?family=Karla:wght@400;500;700;800;900&display=swap" rel="stylesheet">
  <?php if (empty($_SESSION['csrf_token'])) { $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); } ?>
  <meta name="csrf-token" content="<?php echo $_SESSION['csrf_token']; ?>">
</head>
<body>

  <!-- Popup Chrome Recommendation -->
  <div id="popup-chrome" class="popup-overlay" style="display: none;">
    <div class="popup-box">
      <p class="popup-text">
        Direkomendasikan menggunakan browser <b>Google Chrome</b><br>
        agar tampilan dan fitur berjalan lebih baik.
      </p>
      <button id="btnChrome" class="popup-btn">Mengerti</button>
    </div>
  </div>

  <!-- Popup Aktivasi -->
  <div id="popup-aktivasi" class="popup-overlay" style="display: none;">
    <div class="popup-box">
      <p class="popup-text">
        Harap Tekan Tombol <b>"Start"</b><br>
        Dibawah saat memulai pekerjaan untuk<br>
        mengaktifkan status bekerja
      </p>
      <button id="btnKonfirmasi" class="popup-btn">Konfirmasi</button>
    </div>
  </div>

  <div id="popup-notifikasi" class="popup-overlay" style="display: none;">
    <div class="popup-box">
      <h2 id="notif-title">Notifikasi</h2>
      <p id="notif-message"></p>
      <button id="btnNotifClose" class="popup-btn">Tutup</button>
    </div>
  </div>

  <!-- Popup Stop Konfirmasi -->
  <div id="popup-stop" class="popup-overlay" style="display: none;">
    <div class="popup-box">
      <p class="popup-text">
        Apakah Anda yakin ingin<br>
        <b>Menyelesaikan Pekerjaan</b> hari ini?
      </p>
      <div style="display:flex; gap:10px; justify-content:center; margin-top:20px;">
        <button id="btnStopCancel" class="popup-btn" style="background:#e2e8f0; color:#475569;">Batal</button>
        <button id="btnStopConfirm" class="popup-btn" style="background:#ef4444; color:white;">Ya, Selesai</button>
      </div>
    </div>
  </div>

  <!-- Popup Detail -->
  <div id="popup-detail" class="popup-detail" style="display: none;">
    <div class="popup-box-detail">
      <h2>TUGAS PERTAMA</h2>

      <div class="popup-section">
        <h3>Lokasi</h3>
        <p id="lokasi-detail"><?php echo safeText($popupLokasi); ?></p>
        <h3 style="margin-top:10px;">Tanggal & Waktu</h3>
        <p id="waktu-detail"><?php echo safeText($popupWaktu); ?></p>
      </div>

      <div class="popup-section">
        <h3>Detail Pekerjaan</h3>
        <p id="pekerjaan-detail"><?php echo safeText($popupDesc); ?></p>
      </div>

      <div class="popup-btns">
        <button class="btn-konfirmasi" id="closeDetailPopup">Konfirmasi</button>
      </div>
    </div>
  </div>

  <!-- DASHBOARD -->
  <div class="dashboard">

    <!-- HEADER -->
    <header class="dash-header">
      <div class="header-top" onclick="location.href='profile.php'" role="button" tabindex="0">
        <div class="user-info">
          <h3 class="user-name"><?php echo safeText($pName); ?></h3>
          <p class="user-nip"><?php echo safeText($pNip); ?></p>
        </div>
        <div class="avatar-icon">
          <img src="../../assets/icon/icon-avatar.png" alt="Profile" style="width:100%; height:100%; object-fit:cover;">
        </div>
      </div>
    </header>

    <!-- JUDUL SMPP -->
    <section class="smpp-section">
      <div class="smpp-content">
        <h1 class="dash-title">SMPP</h1>
        <p class="dash-subtitle">Sistem Monitoring Petugas PPSU</p>
      </div>
    </section>

    <!-- MENU -->
    <section class="menu-section">
      <div class="menu-item" onclick="location.href='laporan.php'" role="button" tabindex="0">
        <div class="menu-card">
          <img src="../../assets/icon/icon-laporan.png" alt="Laporan">
        </div>
        <p class="menu-label">Laporan</p>
      </div>
      <div class="menu-item" onclick="location.href='riwayat.php'" role="button" tabindex="0">
        <div class="menu-card">
          <img src="../../assets/icon/icon-riwayat.png" alt="Riwayat">
        </div>
        <p class="menu-label">Riwayat</p>
      </div>
    </section>

    <!-- JADWAL HARI INI -->
    <section class="jadwal-section">
      <div class="section-header">
        <h3>Jadwal Penugasan Hari Ini</h3>
        <span class="date-badge"><?php echo date('d M Y'); ?></span>
      </div>

      <div class="jadwal-list">
        <?php if (!empty($items)): ?>
          <?php 
          // Group schedules by time + zone (same RW, same time = grouped)
          $groupedSchedules = [];
          foreach ($items as $item) {
              $key = $item['rw_code'] . '_' . $item['start_time'] . '_' . $item['end_time'];
              if (!isset($groupedSchedules[$key])) {
                  $groupedSchedules[$key] = [
                      'zone_name' => $item['zone_name'],
                      'rw_code' => $item['rw_code'],
                      'start_time' => $item['start_time'],
                      'end_time' => $item['end_time'],
                      'jobs' => []
                  ];
              }
              $groupedSchedules[$key]['jobs'][] = [
                  'id' => $item['id'],
                  'description' => $item['description'] ?? 'Tugas umum',
                  'sudah_dilaporkan' => $item['sudah_dilaporkan'] ?? 0
              ];
          }
          ?>
          
          <?php foreach ($groupedSchedules as $groupKey => $group): ?>
            <div class="jadwal-card">
              <div class="card-header">
                <div class="zone-info">
                  <h4><?php echo htmlspecialchars($group['zone_name']); ?></h4>
                  <span class="rw-badge">RW <?php echo htmlspecialchars($group['rw_code']); ?></span>
                </div>
                <div class="time-badge">
                  <i class="fas fa-clock"></i>
                  <?php echo $group['start_time']; ?> - <?php echo $group['end_time']; ?>
                </div>
              </div>
              
              <?php if (count($group['jobs']) > 1): ?>
                <!-- Multiple jobs - show dropdown -->
                <div class="job-selection">
                  <label style="font-weight: 600; color: #0a4225; display: block; margin-bottom: 6px;">
                    <i class="fas fa-tasks"></i> Pilih Pekerjaan:
                  </label>
                  <select class="job-dropdown" id="job-select-<?php echo htmlspecialchars($groupKey); ?>" 
                          onchange="updateJobSelection('<?php echo htmlspecialchars($groupKey); ?>')">
                    <?php foreach ($group['jobs'] as $idx => $job): ?>
                      <option value="<?php echo $job['id']; ?>" 
                              data-reported="<?php echo $job['sudah_dilaporkan']; ?>">
                        <?php echo htmlspecialchars($job['description'] ?: 'Tugas ' . ($idx + 1)); ?>
                        <?php echo ($job['sudah_dilaporkan'] > 0) ? ' ✓ Selesai' : ''; ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                  <p class="job-count" style="font-size: 12px; color: #666; margin-top: 4px;">
                    <?php echo count($group['jobs']); ?> pekerjaan tersedia
                  </p>
                </div>
                
                <?php 
                // Check if all jobs are reported
                $allReported = true;
                $firstUnreportedId = null;
                foreach ($group['jobs'] as $job) {
                    if (($job['sudah_dilaporkan'] ?? 0) == 0) {
                        $allReported = false;
                        if ($firstUnreportedId === null) {
                            $firstUnreportedId = $job['id'];
                        }
                    }
                }
                ?>
                
                <?php if ($allReported): ?>
                  <div class="status-done">
                    <i class="fas fa-check-circle"></i> Semua Pekerjaan Selesai
                  </div>
                <?php else: ?>
                  <div class="card-actions">
                    <a href="laporan.php?schedule_id=<?php echo $firstUnreportedId ?? $group['jobs'][0]['id']; ?>" 
                       class="btn-action" id="btn-<?php echo htmlspecialchars($groupKey); ?>">
                      <i class="fas fa-camera"></i>
                      Buat Laporan
                    </a>
                  </div>
                <?php endif; ?>
                
              <?php else: ?>
                <!-- Single job - show directly -->
                <p class="description"><?php echo htmlspecialchars($group['jobs'][0]['description'] ?? 'Tugas umum'); ?></p>
                
                <?php if (($group['jobs'][0]['sudah_dilaporkan'] ?? 0) > 0): ?>
                  <div class="status-done">
                    <i class="fas fa-check-circle"></i> Sudah Dilaporkan
                  </div>
                <?php else: ?>
                  <div class="card-actions">
                    <a href="laporan.php?schedule_id=<?php echo $group['jobs'][0]['id']; ?>" class="btn-action">
                      <i class="fas fa-camera"></i>
                      Buat Laporan
                    </a>
                  </div>
                <?php endif; ?>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
        <?php else: ?>
            <div class="empty-state">
              <p>Belum ada jadwal penugasan untuk hari ini.</p>
              
              <?php 
                // Cek jadwal mendatang jika hari ini kosong
              $nextSchedule = $controller->getJadwalMendatang($userId);
              if ($nextSchedule['success'] && !empty($nextSchedule['jadwal'])): 
                  $next = $nextSchedule['jadwal'];
                  $nextDate = date('d M Y', strtotime($next['work_date']));
            ?>
              <div style="margin-top: 15px; padding: 15px; background: #e3f2fd; border-radius: 8px; border: 1px solid #90caf9; text-align: left;">
                  <strong style="color: #1976d2;"><i class="fas fa-calendar-alt"></i> Jadwal Berikutnya:</strong>
                  <div style="margin-top: 8px; font-size: 0.95em;">
                      <div style="margin-bottom: 4px;"><strong>Tanggal:</strong> <?php echo $nextDate; ?></div>
                      <div style="margin-bottom: 4px;"><strong>Lokasi:</strong> <?php echo htmlspecialchars($next['zone_name']); ?></div>
                      <div><strong>Waktu:</strong> <?php echo $next['start_time'] . ' - ' . $next['end_time']; ?></div>
                  </div>
              </div>
            <?php endif; ?>
          </div>
        <?php endif; ?>
      </div>
    </section>

    <!-- FOOTER -->
    <footer class="footer-btns">
      <div class="footer-wrapper">
        <form id="startForm" method="post" action="dashboard.php" style="display:inline-block;">
            <input type="hidden" name="action" value="work_status" />
            <input type="hidden" name="status" value="<?php echo $isOnline ? 'stop' : 'start'; ?>" />
            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token'] ?? ''; ?>" />
            <button type="submit" class="circle-btn start-btn"
                    title="<?php echo $isOnline ? 'Stop (Offline)' : 'Start (Online)'; ?>"
                    <?php if (!$canWork) echo 'disabled style="opacity:0.5;cursor:not-allowed;"'; ?>
                    id="powerButton">
              <img src="../../assets/icon/icon-power.png"
                     alt="Power" class="btn-icon" id="powerIcon">
            </button>
          </form>
        <button class="circle-btn map-btn" onclick="showMap()">
          <img src="../../assets/icon/icon-maps.png" alt="Maps" class="btn-icon">
        </button>
      </div>
    </footer>
  </div>

  <script src="../../public/js/dashboard-integration.js"></script>
  <script>
    const dashboard = window.dashboard || new DashboardIntegration();
    
    // Status kerja dari PHP
    const isActive = <?php echo $isActive ? 'true' : 'false'; ?>;
    
    if (isActive) {
      dashboard.startLocationTracking();
    } else {
      const act = document.getElementById('popup-aktivasi');
      if (act) act.style.display = 'flex';
    }

    // Data jadwal ke JS (untuk popup detail)
    const scheduleItems = <?php echo json_encode($items, JSON_UNESCAPED_UNICODE); ?>;

    // Handle detail buttons
    document.querySelectorAll('.detail-btn').forEach(btn => {
      btn.addEventListener('click', function() {
        const index = parseInt(this.getAttribute('data-index'), 10);
        showDetailPopup(index);
      });
    });

    // Popup Aktivasi
    document.getElementById('btnKonfirmasi').addEventListener('click', function() {
      document.getElementById('popup-aktivasi').style.display = 'none';
      localStorage.setItem('popup_aktivasi_closed', 'true');
    });

    // Popup Chrome
    const isChrome = /Chrome/.test(navigator.userAgent) && /Google Inc/.test(navigator.vendor);
    if (!isChrome && !localStorage.getItem('popup_chrome_closed')) {
        document.getElementById('popup-chrome').style.display = 'flex';
    }
    document.getElementById('btnChrome').addEventListener('click', function() {
        document.getElementById('popup-chrome').style.display = 'none';
        localStorage.setItem('popup_chrome_closed', 'true');
    });

    // Popup Detail
    document.getElementById('closeDetailPopup').addEventListener('click', function() {
      document.getElementById('popup-detail').style.display = 'none';
    });

    function showDetailPopup(index) {
      const item = scheduleItems[index];
      if (!item) return;

      const dayLabel = <?php echo json_encode($dateLabel, JSON_UNESCAPED_UNICODE); ?>;
      const start = item.start_time ? item.start_time.toString().substring(0,5).replace(':','.') : '';
      const end   = item.end_time   ? item.end_time.toString().substring(0,5).replace(':','.') : '';

      document.getElementById('lokasi-detail').textContent  = item.zone_name || ('Lokasi ' + (index + 1));
      document.getElementById('pekerjaan-detail').textContent = item.description || '';
      document.getElementById('waktu-detail').textContent  = dayLabel + (start && end ? (', ' + start + '–' + end) : '');

      document.getElementById('popup-detail').style.display = 'flex';
    }

    // Form Start/Stop Interception
    document.getElementById('startForm').addEventListener('submit', function(e) {
      e.preventDefault();
      const nextStatus = this.querySelector('input[name="status"]').value;
      
      // Jika akan Stop (Selesai kerja), munculkan popup konfirmasi dulu
      if (nextStatus === 'stop') {
        document.getElementById('popup-stop').style.display = 'flex';
      } else {
        // Start (Langsung)
        processStatusUpdate(nextStatus);
      }
    });

    // Handle Stop Popup Buttons
    document.getElementById('btnStopCancel').addEventListener('click', function() {
        document.getElementById('popup-stop').style.display = 'none';
    });

    document.getElementById('btnStopConfirm').addEventListener('click', function() {
        document.getElementById('popup-stop').style.display = 'none';
        processStatusUpdate('stop');
    });

    async function processStatusUpdate(statusValue) {
      const body = new URLSearchParams();
      body.set('action', 'work_status');
      body.set('status', statusValue);
      // Ambil CSRF dari meta tag karena lebih aman
      body.set('csrf_token', document.querySelector('meta[name="csrf-token"]').content || '');
      
      try {
        const response = await fetch('/petugas/views/dashboard.php', {
          method: 'POST',
          headers: { 
            'Accept': 'application/json',
            'Content-Type': 'application/x-www-form-urlencoded'
          },
          body: body,
          credentials: 'same-origin'
        });
        
        const ct = response.headers.get('content-type') || '';
        let result;
        try {
          result = await response.json();
        } catch (err) {
          console.error('Gagal parse JSON');
          alert('Respons tidak valid dari server');
          return;
        }
        
        const newStatus = (result.data && result.data.status) ? result.data.status : (result.status || null);
        
        if (newStatus) {
            updateUIForStatus(newStatus);
        } else {
          alert(result.message || 'Gagal mengubah status');
        }
      } catch (error) {
        console.error('Error:', error);
        alert('Gagal mengubah status');
      }
    }

    function updateUIForStatus(newStatus) {
      const powerIcon  = document.getElementById('powerIcon');
      const powerButton= document.getElementById('powerButton');
      
      if (newStatus === 'online') {
        powerIcon.src = '../../assets/icon/icon-power.png';
        powerButton.title = 'Ubah status ke offline';
        // document.getElementById('popup-aktivasi').style.display = 'flex'; // Disable double notification
        if (dashboard) dashboard.startLocationTracking();
        if (window.dashboard && window.dashboard.showNotification) {
             window.dashboard.showNotification('Status kerja ONLINE', 'success');
        }
      } else {
        powerIcon.src = '../../assets/icon/icon-power.png';
        powerButton.title = 'Ubah status ke online';
        if (dashboard) dashboard.stopLocationTracking();
      }
      
      // Update status hidden input untuk klik berikutnya
      const input = document.querySelector('input[name="status"]');
      if (input) {
          input.value = (newStatus === 'online') ? 'stop' : 'start';
      }
    }

    function showMap() {
      // TODO: integrasi ke halaman map petugas
      // window.location.href = 'map.php';
      alert('Fitur map akan ditampilkan di sini');
    }

    // Function to update job selection and button URL
    function updateJobSelection(groupKey) {
      const select = document.getElementById('job-select-' + groupKey);
      const btn = document.getElementById('btn-' + groupKey);
      if (select && btn) {
        const selectedOption = select.options[select.selectedIndex];
        const scheduleId = selectedOption.value;
        const isReported = selectedOption.getAttribute('data-reported') === '1';
        
        if (isReported) {
          btn.style.opacity = '0.5';
          btn.style.pointerEvents = 'none';
          btn.innerHTML = '<i class="fas fa-check"></i> Sudah Dilaporkan';
        } else {
          btn.style.opacity = '1';
          btn.style.pointerEvents = 'auto';
          btn.innerHTML = '<i class="fas fa-camera"></i> Buat Laporan';
          btn.href = 'laporan.php?schedule_id=' + scheduleId;
        }
      }
    }

    // Background Geolocation Tracking (Update location every 10s)
    function startBackgroundTracking() {
        if (!navigator.geolocation) {
            console.log("Geolocation/GPS tidak didukung di browser ini.");
            return;
        }

        const updateLocation = () => {
            navigator.geolocation.getCurrentPosition(
                (position) => {
                    const payload = {
                        latitude: position.coords.latitude,
                        longitude: position.coords.longitude,
                        accuracy: position.coords.accuracy,
                        speed: position.coords.speed,
                        heading: position.coords.heading,
                        timestamp: new Date().toISOString()
                    };

                    fetch('../../api/gps/update_location.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                        },
                        body: JSON.stringify(payload)
                    })
                    .then(r => r.json())
                    .then(data => {
                        console.log('Location updated:', data);
                    })
                    .catch(e => console.error('Failed to update location:', e));
                },
                (error) => {
                    console.error('Error getting location:', error.message);
                },
                {
                    enableHighAccuracy: true,
                    timeout: 5000,
                    maximumAge: 0
                }
            );
        };

        // Run immediately then interval
        updateLocation();
        setInterval(updateLocation, 10000); // 10 seconds
    }

    // Inisialisasi dashboard setelah DOM siap
    document.addEventListener('DOMContentLoaded', function() {
        if (!window.dashboard) {
            window.dashboard = new DashboardIntegration();
        }
        
        // Start Tracking
        startBackgroundTracking();
        
        // Untuk debugging, coba tampilkan test notification
        setTimeout(() => {
            console.log('Dashboard initialized, testing notification...');
        }, 1000);

        <?php
        if (!empty($_SESSION['low_kpi_warning'])) {
            $msg = $_SESSION['low_kpi_warning_message'] ?? 'KPI anda di bawah Rata - rata tolong hubungi ke pihak yang bersangkutan';
            unset($_SESSION['low_kpi_warning'], $_SESSION['low_kpi_warning_message']);
            echo "if (window.dashboard) { window.dashboard.showNotification('".addslashes($msg)."', 'error'); }";
        }
        ?>
    });
  </script>

  <script src="../js/live-tracking.js"></script>
</body>
</html>
