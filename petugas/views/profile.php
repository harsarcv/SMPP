<?php
session_start();
require_once '../../config/database.php';
require_once '../../helpers/session.php';
enforce_session(['petugas']);

// ======================================
// Proteksi Akses
// ======================================
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'petugas') {
    header('Location: /auth/login.php');
    exit;
}

$userId = (int)$_SESSION['user_id'];

// ======================================
// Helper
// ======================================
if (!function_exists('safeText')) {
    function safeText($text) {
        return htmlspecialchars($text ?? '', ENT_QUOTES, 'UTF-8');
    }
}

function percent($num, $den) {
    if ($den <= 0) return 0;
    $val = round(($num / $den) * 100);
    return ($val > 100) ? 100 : $val;
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

// ======================================
// Ambil Data User + Foto
// ======================================
require_once '../../petugas/controllers/PetugasController.php';
$controller = new PetugasController();

$res = $controller->getProfileData($userId);
if (empty($res['success'])) {
    session_destroy();
    header('Location: /auth/login.php');
    exit;
}

$u = $res['user'] ?? [];
$stats = $res['stats'] ?? [];
$kpiData = $res['kpi'] ?? [];

$pName   = $u['name'] ?? '';
$pNip    = $u['nip'] ?? '';
$pStatus = $u['status'] ?? 'offline';

// 4-Status System: online, offline, nonaktif, cuti
$isOnline = ($pStatus === 'online');
$isOffline = ($pStatus === 'offline');
$isNonaktif = ($pStatus === 'nonaktif');
$isCuti = ($pStatus === 'cuti');

// Helper for status display
function getStatusInfo($status) {
    switch ($status) {
        case 'online': return ['color' => 'green', 'label' => 'Online', 'glow' => true];
        case 'offline': return ['color' => 'gray', 'label' => 'Offline', 'glow' => false];
        case 'nonaktif': return ['color' => 'red', 'label' => 'Nonaktif', 'glow' => false];
        case 'cuti': return ['color' => 'yellow', 'label' => 'Cuti', 'glow' => false];
        default: return ['color' => 'gray', 'label' => ucfirst($status), 'glow' => false];
    }
}
$statusInfo = getStatusInfo($pStatus);

$photoPath = $u['photo_path'] ?: 'default_profile.png';

$totalTasks = (int)($stats['total_tugas'] ?? 0);
$completedTasks = (int)($stats['laporan_hari_ini'] ?? 0);

// ======================================
// KPI dari Database (data permanen)
// Weights: Kehadiran 65%, Penyelesaian 25%, Ketepatan 10%
// ======================================
$attRaw = (float)($kpiData['presence_rate'] ?? 0);
$compRaw = (float)($kpiData['completion_rate'] ?? 0);
$timeOkRaw = (float)($kpiData['punctuality_rate'] ?? 0);
$scoreVal = (float)($kpiData['score_total'] ?? 0);

// Calculate WEIGHTED contributions (what user sees, sums to 100%)
$att = round($attRaw * 0.65, 1);      // Max 65%
$comp = round($compRaw * 0.25, 1);    // Max 25%
$timeOk = round($timeOkRaw * 0.10, 1); // Max 10%

// Max weights for reference
$maxAtt = 65;
$maxComp = 25;
$maxTimeOk = 10;

?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Profil Petugas | SMPP</title>
  <link rel="stylesheet" href="../css/profile.css" />
  <link href="https://fonts.googleapis.com/css2?family=Karla:wght@400;500;700;800;900&display=swap" rel="stylesheet">
</head>
<body>

  <!-- HEADER -->
  <header class="dash-header">
    <div class="header-top">
      <h2 class="header-title">PROFILE PETUGAS PPSU</h2>
      <a href="../../auth/logout.php"><img src="../../assets/icon/icon-exit.png" class="logout-icon" alt="logout"></a>
    </div>
  </header>

  <!-- CONTENT -> PROFIL -->
  <div class="profile-page">

    <div class="profile-photo">
      <div class="photo-circle">
        <img src="../../assets/icon/icon-avatar.png" alt="Profile" style="width:100%; height:100%; object-fit:cover; border-radius: 50%;">
      </div>
    </div>

    <!-- BIODATA -->
    <div class="biodata-card">
      <h3 class="nama-title">Nama Petugas</h3>
      <p class="nama-value"><?php echo safeText($pName); ?></p>

      <h3 class="nip-title">Nomor Induk Petugas</h3>
      <p class="nip-value"><?php echo safeText($pNip); ?></p>

      <!-- STATUS (4-Status System) -->
      <div class="status-row">
        <div class="status-item">
          <span class="dot <?php echo $statusInfo['color'] . ($statusInfo['glow'] ? ' glow' : ''); ?>"></span>
          <?php echo $statusInfo['label']; ?>
        </div>
      </div>

      <!-- PROGRESS -->
      <p class="progress-title">Penyelesaian Tugas Harian</p>
      <div class="progress-wrapper">
        <input 
          type="range" 
          min="0" 
          max="<?php echo (int)$totalTasks; ?>" 
          value="<?php echo (int)min($completedTasks,$totalTasks); ?>" 
          disabled>

        <div class="progress-count">
          <?php echo safeText("$completedTasks/$totalTasks"); ?>
        </div>
      </div>
    </div>

    <!-- RATING -->
    <div class="rating-card">
      <div class="rating-title">Rating Petugas</div>

      <!-- KPI Individual Metrics (Weighted Values) -->
      <div class="kpi-metrics-row" style="display: flex; justify-content: space-around; margin: 20px 0; gap: 10px;">
        <div class="kpi-metric-item" style="text-align: center; flex: 1;">
          <?php 
            // Color based on % of max achieved
            $attPercent = $maxAtt > 0 ? ($att / $maxAtt) * 100 : 0;
            $attColor = $attPercent >= 80 ? '#22c55e' : ($attPercent >= 60 ? '#eab308' : '#ef4444');
          ?>
          <div style="font-size: 26px; font-weight: 800; color: <?php echo $attColor; ?>;">
            <?php echo $att; ?>%
          </div>
          <div style="font-size: 10px; color: #888; margin-top: 2px;">dari <?php echo $maxAtt; ?>%</div>
          <div style="font-size: 11px; color: #666; margin-top: 4px; font-weight: 600;">Kehadiran</div>
        </div>
        <div class="kpi-metric-item" style="text-align: center; flex: 1;">
          <?php 
            $compPercent = $maxComp > 0 ? ($comp / $maxComp) * 100 : 0;
            $compColor = $compPercent >= 80 ? '#22c55e' : ($compPercent >= 60 ? '#eab308' : '#ef4444');
          ?>
          <div style="font-size: 26px; font-weight: 800; color: <?php echo $compColor; ?>;">
            <?php echo $comp; ?>%
          </div>
          <div style="font-size: 10px; color: #888; margin-top: 2px;">dari <?php echo $maxComp; ?>%</div>
          <div style="font-size: 11px; color: #666; margin-top: 4px; font-weight: 600;">Penyelesaian</div>
        </div>
        <div class="kpi-metric-item" style="text-align: center; flex: 1;">
          <?php 
            $timeOkPercent = $maxTimeOk > 0 ? ($timeOk / $maxTimeOk) * 100 : 0;
            $timeOkColor = $timeOkPercent >= 80 ? '#22c55e' : ($timeOkPercent >= 60 ? '#eab308' : '#ef4444');
          ?>
          <div style="font-size: 26px; font-weight: 800; color: <?php echo $timeOkColor; ?>;">
            <?php echo $timeOk; ?>%
          </div>
          <div style="font-size: 10px; color: #888; margin-top: 2px;">dari <?php echo $maxTimeOk; ?>%</div>
          <div style="font-size: 11px; color: #666; margin-top: 4px; font-weight: 600;">Ketepatan</div>
        </div>
      </div>

      <!-- Total Score - Circular Progress Bar -->
      <p style="margin-top: 25px; text-align: center; font-weight: 600; color: #333;">Skor Total</p>
      <?php 
        $scoreColor = $scoreVal >= 80 ? '#22c55e' : ($scoreVal >= 60 ? '#eab308' : '#ef4444');
        $scoreDeg = ($scoreVal / 100) * 360;
      ?>
      <div class="circle-progress-container" style="display: flex; justify-content: center; margin: 15px 0;">
        <div class="circle-progress" style="
          position: relative;
          width: 120px;
          height: 120px;
          border-radius: 50%;
          background: conic-gradient(<?php echo $scoreColor; ?> <?php echo $scoreDeg; ?>deg, #e5e7eb <?php echo $scoreDeg; ?>deg);
          display: flex;
          align-items: center;
          justify-content: center;
          box-shadow: 0 4px 15px rgba(0,0,0,0.1);
        ">
          <div class="circle-inner" style="
            width: 90px;
            height: 90px;
            border-radius: 50%;
            background: white;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
          ">
            <span style="font-size: 26px; font-weight: 800; color: <?php echo $scoreColor; ?>;"><?php echo $scoreVal; ?>%</span>
            <span style="font-size: 10px; color: #666; margin-top: 2px;">
              <?php if ($scoreVal >= 80): ?>Sangat Baik<?php elseif ($scoreVal >= 60): ?>Cukup<?php else: ?>Perlu Ditingkatkan<?php endif; ?>
            </span>
          </div>
        </div>
      </div>
    </div>

  </div>

  <!-- FOOTER NAVIGATION -->
  <footer class="footer-btns">
    <div class="footer-wrapper">
      <a href="dashboard.php" class="circle-btn" title="Dashboard">
        <img src="../../assets/icon/icon-home.png" class="btn-icon" alt="Home">
      </a>
    </div>
  </footer>

  <script src="../js/live-tracking.js"></script>
</body>
</html>
