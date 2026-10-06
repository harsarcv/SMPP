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

// Include helper for KPI update
require_once __DIR__ . '/../../petugas/controllers/PetugasController.php';

// HANDLE VALIDATION ACTION (APPROVE)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'validate_report') {
    $repId = $_POST['report_id'] ?? 0;
    if ($repId) {
        // Get user_id and photos from report before approving
        $getUser = $pdo->prepare("SELECT user_id, description FROM reports WHERE id = ?");
        $getUser->execute([$repId]);
        $reportData = $getUser->fetch();
        $taskDescription = $reportData['description'] ?? '';
        
        // Run AI validation on photos (if API key is configured)
        $aiScore = null;
        $aiResult = null;
        
        // Get photos for this report
        $getPhotos = $pdo->prepare("SELECT id, file_path FROM report_photos WHERE report_id = ?");
        $getPhotos->execute([$repId]);
        $photos = $getPhotos->fetchAll(PDO::FETCH_ASSOC);
        
        if (!empty($photos)) {
            require_once __DIR__ . '/../../helpers/GeminiAI.php';
            $gemini = new GeminiAI();
            
            // Check if API key is configured
            $envPath = dirname(dirname(__DIR__)) . '/.env';
            if (file_exists($envPath)) {
                $envContent = file_get_contents($envPath);
                if (preg_match('/GEMINI_API_KEY=(.+)/', $envContent, $matches)) {
                    $apiKey = trim($matches[1]);
                    if (!empty($apiKey)) {
                        $gemini->setApiKey($apiKey);
                        
                        // Analyze each photo
                        $allValid = true;
                        $totalConfidence = 0;
                        $photoResults = [];
                        
                        foreach ($photos as $photo) {
                            $photoPath = dirname(dirname(__DIR__)) . '/public/uploads/' . ltrim($photo['file_path'], '/');
                            if (file_exists($photoPath)) {
                                // Pass task description so AI validates photo against stated task
                                $result = $gemini->analyzeWorkPhoto($photoPath, $taskDescription);
                                $photoResults[] = $result;
                                
                                if ($result['success']) {
                                    // Update photo with AI description
                                    $pdo->prepare("UPDATE report_photos SET ai_description = :desc, ai_is_valid_work = :valid WHERE id = :id")
                                        ->execute([
                                            ':desc' => $result['description'] ?? '',
                                            ':valid' => $result['is_valid_work'] ? 1 : 0,
                                            ':id' => $photo['id']
                                        ]);
                                    
                                    if (!$result['is_valid_work']) {
                                        $allValid = false;
                                    }
                                    $totalConfidence += $result['confidence'] ?? 0;
                                }
                            }
                        }
                        
                        // Calculate overall AI score
                        $aiScore = count($photoResults) > 0 ? round($totalConfidence / count($photoResults), 2) : 0;
                        $aiResult = json_encode([
                            'all_valid' => $allValid,
                            'photo_count' => count($photoResults),
                            'average_confidence' => $aiScore,
                            'details' => $photoResults
                        ]);
                        
                        // Update report with AI results
                        $pdo->prepare("UPDATE reports SET ai_score = :score, ai_analysis_result = :result WHERE id = :id")
                            ->execute([':score' => $aiScore, ':result' => $aiResult, ':id' => $repId]);
                    }
                }
            }
        }
        
        $upd = $pdo->prepare("UPDATE reports SET status = 'approved', reviewed_by = :uid, reviewed_at = NOW() WHERE id = :rid");
        $upd->execute([':uid' => $_SESSION['user_id'], ':rid' => $repId]);
        
        // Update KPI after approval
        if ($reportData && $reportData['user_id']) {
            $petugasController = new PetugasController();
            $petugasController->updateKPI($reportData['user_id']);
        }
        
        header("Location: laporan_petugas.php?msg=validated");
        exit;
    }
}

// HANDLE RE-ANALYZE AI ACTION (without approving)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'reanalyze_ai') {
    $repId = $_POST['report_id'] ?? 0;
    if ($repId) {
        // Get report description and photos
        $getReport = $pdo->prepare("SELECT description FROM reports WHERE id = ?");
        $getReport->execute([$repId]);
        $reportData = $getReport->fetch();
        $taskDescription = $reportData['description'] ?? '';
        
        // Get photos for this report
        $getPhotos = $pdo->prepare("SELECT id, file_path FROM report_photos WHERE report_id = ?");
        $getPhotos->execute([$repId]);
        $photos = $getPhotos->fetchAll(PDO::FETCH_ASSOC);
        
        if (!empty($photos)) {
            require_once __DIR__ . '/../../helpers/GeminiAI.php';
            $gemini = new GeminiAI();
            
            // Load API key from .env
            $envPath = dirname(dirname(__DIR__)) . '/.env';
            if (file_exists($envPath)) {
                $envContent = file_get_contents($envPath);
                if (preg_match('/GEMINI_API_KEY=(.+)/', $envContent, $matches)) {
                    $apiKey = trim($matches[1]);
                    if (!empty($apiKey)) {
                        $gemini->setApiKey($apiKey);
                        
                        // Re-analyze each photo with task description
                        foreach ($photos as $photo) {
                            $photoPath = dirname(dirname(__DIR__)) . '/public/uploads/' . ltrim($photo['file_path'], '/');
                            if (file_exists($photoPath)) {
                                // Pass task description so AI validates photo against stated task
                                $result = $gemini->analyzeWorkPhoto($photoPath, $taskDescription);
                                
                                if ($result['success']) {
                                    // Update photo with new AI analysis
                                    $pdo->prepare("UPDATE report_photos SET ai_description = :desc, ai_is_valid_work = :valid WHERE id = :id")
                                        ->execute([
                                            ':desc' => $result['description'] ?? '',
                                            ':valid' => ($result['is_valid_work'] ?? false) ? 1 : 0,
                                            ':id' => $photo['id']
                                        ]);
                                }
                            }
                        }
                        header("Location: laporan_petugas.php?msg=reanalyzed");
                        exit;
                    }
                }
            }
        }
        header("Location: laporan_petugas.php?msg=ai_error");
        exit;
    }
}

// HANDLE REJECT ACTION
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'reject_report') {
    $repId = $_POST['report_id'] ?? 0;
    if ($repId) {
        // Update status only (reject_reason might not exist yet)
        $upd = $pdo->prepare("UPDATE reports SET status = 'rejected', reviewed_by = :uid, reviewed_at = NOW() WHERE id = :rid");
        $upd->execute([':uid' => $_SESSION['user_id'], ':rid' => $repId]);
        
        // Update KPI after rejection
        $getUser = $pdo->prepare("SELECT user_id FROM reports WHERE id = ?");
        $getUser->execute([$repId]);
        $reportData = $getUser->fetch();
        
        if ($reportData && $reportData['user_id']) {
            $petugasController = new PetugasController();
            $petugasController->updateKPI($reportData['user_id']);
        }
        
        header("Location: laporan_petugas.php?msg=rejected");
        exit;
    }
}

// ============== HANDLE EXPORT EXCEL ACTION ==============
if (isset($_GET['action']) && $_GET['action'] === 'export_excel') {
    $exportDate = $_GET['date'] ?? date('Y-m-d');
    
    // Fetch reports for export with AI data
    $exportSql = "
        SELECT 
            r.id AS report_id,
            u.name AS petugas,
            u.nip,
            g.name AS group_name,
            r.work_date,
            DATE_FORMAT(r.created_at, '%Y-%m-%d %H:%i') AS created_at,
            z.name AS lokasi,
            z.rw_code,
            r.description AS report_desc,
            s.description AS task_description,
            r.status,
            r.latitude,
            r.longitude,
            r.ai_score,
            r.ai_analysis_result
        FROM reports r
        JOIN users u ON r.user_id = u.id 
        LEFT JOIN zones z ON r.zone_id = z.id
        LEFT JOIN `groups` g ON u.group_id = g.id
        LEFT JOIN schedules s ON r.schedule_id = s.id
        WHERE r.work_date = :export_date
        ORDER BY r.created_at DESC
    ";
    
    $exportStmt = $pdo->prepare($exportSql);
    $exportStmt->execute([':export_date' => $exportDate]);
    $exportData = $exportStmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get photos for each report
    $photoStmt = $pdo->prepare("
        SELECT file_path, photo_type, ai_description, ai_is_valid_work 
        FROM report_photos 
        WHERE report_id = ?
        ORDER BY photo_type ASC
    ");
    
    // Base URL for photos
    $baseUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . 
               '://' . $_SERVER['HTTP_HOST'] . '/public/uploads/';
    
    // Set headers for Excel download (HTML format)
    $filename = "laporan_petugas_" . $exportDate . ".xls";
    header('Content-Type: application/vnd.ms-excel; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: max-age=0');
    
    // Output HTML table that Excel can read
    echo '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <style>
        table { border-collapse: collapse; width: 100%; }
        th { background-color: #0a93b8; color: white; padding: 10px; text-align: left; font-weight: bold; }
        td { border: 1px solid #ddd; padding: 8px; vertical-align: middle; }
        tr:nth-child(even) { background-color: #f9f9f9; }
        .status-approved { background-color: #dcfce7; color: #166534; padding: 4px 8px; }
        .status-pending { background-color: #fef3c7; color: #92400e; padding: 4px 8px; }
        .status-rejected { background-color: #fee2e2; color: #991b1b; padding: 4px 8px; }
        .ai-valid { color: #16a34a; font-weight: bold; }
        .ai-invalid { color: #dc2626; font-weight: bold; }
        a { color: #0066cc; }
    </style>
</head>
<body>
    <h2>Laporan Petugas - ' . htmlspecialchars($exportDate) . '</h2>
    <table>
        <thead>
            <tr>
                <th>No</th>
                <th>Nama Petugas</th>
                <th>NIP</th>
                <th>Grup</th>
                <th>Tanggal</th>
                <th>Waktu</th>
                <th>Lokasi</th>
                <th>RW</th>
                <th>Deskripsi Tugas</th>
                <th>Status</th>
                <th>Foto Sebelum</th>
                <th>Foto Sesudah</th>
                <th>AI Score</th>
                <th>AI Valid</th>
                <th>AI Deskripsi</th>
                <th>Koordinat</th>
            </tr>
        </thead>
        <tbody>';
    
    $no = 1;
    foreach ($exportData as $row) {
        // Get photos for this report
        $photoStmt->execute([$row['report_id']]);
        $photos = $photoStmt->fetchAll(PDO::FETCH_ASSOC);
        
        $photoBefore = '-';
        $photoAfter = '-';
        $aiValid = '-';
        $aiDescription = '-';
        
        foreach ($photos as $photo) {
            $photoUrl = $baseUrl . ltrim($photo['file_path'], '/');
            // Create clickable link instead of img tag
            $linkTag = '<a href="' . $photoUrl . '" target="_blank">📷 Lihat Foto</a>';
            
            if ($photo['photo_type'] === 'before') {
                $photoBefore = $linkTag;
            } else {
                $photoAfter = $linkTag;
            }
            
            if ($photo['ai_is_valid_work'] !== null) {
                $aiValid = $photo['ai_is_valid_work'] ? '✅ Valid' : '❌ Tidak Valid';
            }
            if (!empty($photo['ai_description'])) {
                $aiDescription = $photo['ai_description'];
            }
        }
        
        // Parse AI analysis result if exists
        $aiScore = $row['ai_score'] ?? '0';
        if (is_numeric($aiScore)) {
            $aiScore = number_format((float)$aiScore, 2) . '%';
        }
        if (!empty($row['ai_analysis_result'])) {
            $aiResult = json_decode($row['ai_analysis_result'], true);
            if ($aiResult && isset($aiResult['all_valid'])) {
                $aiValid = $aiResult['all_valid'] ? '✅ Valid' : '❌ Tidak Valid';
            }
        }
        
        // Status styling
        $statusClass = 'status-' . $row['status'];
        $aiValidClass = (strpos($aiValid, 'Valid') !== false && strpos($aiValid, 'Tidak') === false) ? 'ai-valid' : 'ai-invalid';
        
        echo '<tr>
            <td>' . $no++ . '</td>
            <td>' . htmlspecialchars($row['petugas']) . '</td>
            <td>' . htmlspecialchars($row['nip']) . '</td>
            <td>' . htmlspecialchars($row['group_name'] ?? '-') . '</td>
            <td>' . htmlspecialchars($row['work_date']) . '</td>
            <td>' . htmlspecialchars($row['created_at']) . '</td>
            <td>' . htmlspecialchars($row['lokasi'] ?? '-') . '</td>
            <td>' . htmlspecialchars($row['rw_code'] ?? '-') . '</td>
            <td>' . htmlspecialchars($row['task_description'] ?? $row['report_desc'] ?? '-') . '</td>
            <td><span class="' . $statusClass . '">' . ucfirst($row['status']) . '</span></td>
            <td>' . $photoBefore . '</td>
            <td>' . $photoAfter . '</td>
            <td>' . $aiScore . '</td>
            <td class="' . $aiValidClass . '">' . $aiValid . '</td>
            <td>' . htmlspecialchars(substr($aiDescription, 0, 150)) . '</td>
            <td>' . ($row['latitude'] && $row['longitude'] ? $row['latitude'] . ', ' . $row['longitude'] : '-') . '</td>
        </tr>';
    }
    
    echo '</tbody>
    </table>
    <p style="margin-top: 20px; color: #666; font-size: 12px;">
        Diekspor pada: ' . date('d-m-Y H:i:s') . ' | Total: ' . count($exportData) . ' laporan
    </p>
</body>
</html>';
    
    exit;
}

$brandSub = "Kelurahan Sunter Jaya";
$admName  = $_SESSION['name'] ?? 'Admin';
$admNip   = $_SESSION['nip'] ?? 'ADMIN001';

if (!function_exists('safeText')) {
    function safeText($text) {
        return htmlspecialchars($text ?? '', ENT_QUOTES, 'UTF-8');
    }
}

$periodDays = 30;

// =============== PARAMETER FILTER ===============
$search = $_GET['q'] ?? '';          // filter nama/lokasi
$dateFilter = $_GET['date'] ?? date('Y-m-d');   // default to today
$sort = $_GET['sort'] ?? 'nama';     // nama | tanggal | lokasi
$filterUserId = isset($_GET['user_id']) ? (int)$_GET['user_id'] : 0; // filter by specific user

// Get user name if filtering by user_id
$filterUserName = '';
$userNotFound = false;
if ($filterUserId > 0) {
    $stmtUser = $pdo->prepare("SELECT id, name FROM users WHERE id = ?");
    $stmtUser->execute([$filterUserId]);
    $userData = $stmtUser->fetch(PDO::FETCH_ASSOC);
    if ($userData) {
        $filterUserName = $userData['name'];
    } else {
        // User tidak ditemukan - set flag untuk menampilkan error
        $userNotFound = true;
    }
}

// Untuk label tampilan
$sortLabel = [
    'nama'    => 'Nama',
    'tanggal' => 'Tanggal Laporan',
    'lokasi'  => 'Lokasi/RW',
][$sort] ?? 'Nama';

// =============== QUERY RIWAYAT LAPORAN ===============
$reportRows = [];

try {
    $baseSql = "
        SELECT 
            r.id,
            r.user_id,
            u.name AS petugas,
            u.role,
            g.name AS group_name,
            r.created_at,
            r.work_date,
            z.name AS lokasi,
            z.rw_code,
            r.description,
            s.description AS schedule_desc,
            r.status,
            r.latitude,
            r.longitude
        FROM reports r
        JOIN users u ON r.user_id = u.id 
        LEFT JOIN zones z ON r.zone_id = z.id
        LEFT JOIN `groups` g ON u.group_id = g.id
        LEFT JOIN schedules s ON r.schedule_id = s.id
        WHERE r.created_at >= DATE_SUB(CURDATE(), INTERVAL {$periodDays} DAY)
    ";
    
    // Debug SQL if needed
    // echo "<pre>$baseSql</pre>";

    $params = [];

    // Filter by specific user_id (exact match)
    if ($filterUserId > 0) {
        $baseSql .= " AND r.user_id = :user_id ";
        $params[':user_id'] = $filterUserId;
    }
    // Filter by Name or Location (search)
    elseif (!empty($search)) {
        $baseSql .= " AND (u.name LIKE :search OR z.name LIKE :search) ";
        $params[':search'] = "%{$search}%";
    }

    // Filter by Specific Date
    if (!empty($dateFilter)) {
        $baseSql .= " AND r.work_date = :date ";
        $params[':date'] = $dateFilter;
    }

    // ORDER BY sesuai sort
    if ($sort === 'tanggal') {
        $baseSql .= " ORDER BY r.created_at DESC, u.name ASC ";
    } elseif ($sort === 'lokasi') {
        $baseSql .= " ORDER BY z.rw_code ASC NULLS LAST, r.created_at DESC ";
    } else {
        // default nama
        $baseSql .= " ORDER BY u.name ASC, r.created_at DESC ";
    }

    $baseSql .= " LIMIT 100";

    $stmt = $pdo->prepare($baseSql);
    $stmt->execute($params);
    $reportRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // =============== FOTO RIWAYAT (Hanya jika ada filter spesifik atau default) ===============
    // Kita ambil foto dari hasil search di atas saja untuk efisiensi, 
    // atau query ulang khusus foto jika user mau lihat galeri.
    // Di sini kita tampilkan foto dari laporan yang muncul di hasil search.
    
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
                rp.ai_description,
                rp.ai_is_valid_work,
                u.name as petugas_name
            FROM report_photos rp
            JOIN reports r ON rp.report_id = r.id
            JOIN users u ON r.user_id = u.id
            WHERE rp.report_id IN ($placeholders)
            ORDER BY rp.sequence_no ASC, rp.uploaded_at ASC
        ";
        $ps = $pdo->prepare($sqlPhoto);
        $ps->execute($reportIds);
        $allPhotos = $ps->fetchAll(PDO::FETCH_ASSOC);

        // Group photos by report_id
        foreach ($allPhotos as $ph) {
            $rid = $ph['report_id'];
            if (!isset($photosByReport[$rid])) {
                $photosByReport[$rid] = [];
            }
            // Fix path here so JS just receives valid URLs
            $src = '../../public/uploads/' . ltrim($ph['file_path'], '/'); 
            $ph['full_path'] = $src;
            $photosByReport[$rid][] = $ph;
        }
    }

    // =============== RINGKASAN GPS HARI INI ===============
    // Hanya hitung valid/fake untuk dashboard mini stats
    $gpsSummary = ['valid' => 0, 'suspicious' => 0, 'fake' => 0];
    // (Optional: Bisa diaktifkan jika perlu statistik)

    // =============== DAFTAR KOORDINAT TERBARU (HARI INI) ===============
    // Ini bagian yang diminta user untuk "membaca coordinat saja"
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
    $reportRows = [];
    $photos     = [];
    $gpsLatest  = [];
    echo "<div style='color:red; padding:20px; border:1px solid red; background:#fff0f0;'>
            <strong>Debug Error:</strong> " . htmlspecialchars($e->getMessage()) . "<br>
            <strong>Trace:</strong> <pre>" . $e->getTraceAsString() . "</pre>
          </div>";
    error_log("Error Laporan Petugas: " . $e->getMessage());
}
?>
<!doctype html>
<html lang="id">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title>Laporan Petugas - Admin</title>

  <!-- global header/sidebar CSS -->
  <link rel="stylesheet" href="../css/dashboard.css">

  <!-- page-specific CSS -->
  <link rel="stylesheet" href="../css/laporan.css">
  
  <!-- Custom CSS untuk perbaikan tampilan -->
  <style>
    .filter-row {
        gap: 15px;
        align-items: flex-end;
    }
    .filter-group {
        display: flex;
        flex-direction: column;
        gap: 5px;
    }
    .filter-group label {
        font-size: 13px;
        font-weight: 600;
        color: #555;
    }
    .filter-input, .filter-select {
        padding: 8px 12px;
        border: 1px solid #ddd;
        border-radius: 6px;
        font-size: 14px;
        min-width: 200px;
    }
    .btn-reset {
        background: #f0f0f0;
        color: #333;
        border: 1px solid #ccc;
        padding: 8px 15px;
        border-radius: 6px;
        text-decoration: none;
        font-size: 14px;
        display: inline-flex;
        align-items: center;
        gap: 5px;
        height: 38px; /* match input height approx */
    }
    .btn-reset:hover {
        background: #e0e0e0;
    }
    .coordinate-box {
        background: #f8f9fa;
        border: 1px solid #e9ecef;
        padding: 4px 8px;
        border-radius: 4px;
        font-family: monospace;
        font-size: 12px;
        color: #d63384;
        display: inline-block;
    }
    .btn-detail {
        background: #007bff;
        color: white;
        border: none;
        padding: 6px 12px;
        border-radius: 4px;
        cursor: pointer;
        font-size: 12px;
        display: inline-flex;
        align-items: center;
        gap: 5px;
        transition: background 0.2s;
    }
    .btn-detail:hover {
        background: #0056b3;
    }
    .btn-detail.disabled {
        background: #ccc;
        cursor: not-allowed;
        opacity: 0.7;
    }

    /* MODAL STYLES */
    .modal-overlay {
        position: fixed;
        top: 0; left: 0;
        width: 100%; height: 100%;
        background: rgba(0,0,0,0.6);
        z-index: 9999;
        display: none;
        align-items: center;
        justify-content: center;
        backdrop-filter: blur(2px);
    }
    .modal-wrapper {
        background: white;
        width: 90%;
        max-width: 800px;
        border-radius: 12px;
        overflow: hidden;
        box-shadow: 0 10px 25px rgba(0,0,0,0.2);
        animation: slideUp 0.3s ease-out;
    }
    @keyframes slideUp {
        from { transform: translateY(20px); opacity: 0; }
        to { transform: translateY(0); opacity: 1; }
    }
    .modal-header {
        background: #007bff;
        color: white;
        padding: 15px 20px;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }
    .modal-title-text {
        font-size: 16px;
        font-weight: 600;
    }
    .modal-close {
        background: none;
        border: none;
        color: white;
        font-size: 20px;
        cursor: pointer;
        opacity: 0.8;
    }
    .modal-close:hover { opacity: 1; }
    
    .modal-body {
        padding: 20px;
        max-height: 80vh;
        overflow-y: auto;
    }
    .photo-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
        gap: 20px;
    }
    .photo-card {
        border: 1px solid #eee;
        border-radius: 8px;
        overflow: hidden;
        background: #fdfdfd;
        text-align: center;
    }
    .photo-card img {
        width: 100%;
        height: 200px;
        object-fit: cover;
        display: block;
        cursor: zoom-in;
    }
    .photo-info {
        padding: 10px;
        font-size: 13px;
        color: #555;
        border-top: 1px solid #eee;
        background: #fff;
    }
    .photo-tag {
        display: inline-block;
        padding: 2px 8px;
        border-radius: 10px;
        background: #e3f2fd;
        color: #1565c0;
        font-size: 11px;
        font-weight: 600;
        margin-bottom: 4px;
    }
  </style>
</head>
<body>

<div class="app">

  <!-- SIDEBAR -->
  <?php $activePage = 'laporan_petugas'; ?>
  <?php require_once '../../admin/components/sidebar.php'; ?>

  <!-- MAIN -->
  <main class="main">

<!-- HEADER -->
<header class="header">
  <div class="header-left">
    <h1 class="page-title">Laporan Petugas</h1>
  </div>

  <div class="header-right">
    <div class="profile">
      <div class="profile-text">
        <div class="profile-name"><?php echo safeText($admName); ?></div>
        <div class="profile-id"><?php echo safeText($admNip); ?></div>
      </div>
      <div class="avatar">
        <svg viewBox="0 0 24 24" width="42" height="42" fill="none">
          <circle cx="12" cy="8" r="3.4" fill="#fff"/>
          <path d="M4 20c0-3.3 4.5-6 8-6s8 2.7 8 6" fill="#fff" opacity="0.9"/>
        </svg>
      </div>
    </div>
  </div>
</header>

<!-- PAGE TITLE -->
<div class="title-center">LAPORAN PETUGAS</div>

<?php if ($filterUserId > 0 && $filterUserName): ?>
<!-- Filter Indicator for Specific User -->
<div style="background: linear-gradient(135deg, #3b82f6, #1d4ed8); color: white; padding: 12px 20px; margin: 0 20px 20px; border-radius: 10px; display: flex; align-items: center; justify-content: space-between; box-shadow: 0 4px 12px rgba(59,130,246,0.3);">
    <div>
        <i class="fas fa-user-circle"></i> 
        <strong>Menampilkan laporan untuk:</strong> <?php echo htmlspecialchars($filterUserName); ?>
    </div>
    <a href="laporan_petugas.php" style="background: white; color: #1d4ed8; padding: 6px 12px; border-radius: 6px; text-decoration: none; font-weight: 600; font-size: 12px;">
        <i class="fas fa-times"></i> Lihat Semua
    </a>
</div>
<?php endif; ?>

<!-- CONTENT BOX -->
<section class="report-box">

<?php if ($userNotFound): ?>
  <!-- ERROR: User Not Found -->
  <div style="text-align: center; padding: 60px 20px;">
    <div style="font-size: 60px; color: #ef4444; margin-bottom: 20px;">
      <i class="fas fa-user-slash"></i>
    </div>
    <h2 style="color: #1e293b; margin-bottom: 10px;">User Tidak Ditemukan</h2>
    <p style="color: #64748b; margin-bottom: 25px;">
      User dengan ID <strong><?php echo $filterUserId; ?></strong> tidak ada di database.
    </p>
    <a href="laporan_petugas.php" class="btn-apply" style="text-decoration: none; padding: 12px 25px;">
      <i class="fas fa-arrow-left"></i> Kembali ke Daftar Laporan
    </a>
  </div>
</section>
</main>
</div>
</body>
</html>
<?php exit; ?>
<?php endif; ?>

  <h4 class="section-title"><i class="fas fa-filter"></i> Filter Laporan (30 Hari Terakhir)</h4>

  <!-- Filter Form yang diperbaiki -->
  <form method="get" id="filterForm" class="filter-row">
    
    <!-- Filter Nama -->
    <div class="filter-group">
      <label for="q">Cari Nama / Lokasi</label>
      <input type="text" name="q" id="q" class="filter-input"
             placeholder="Contoh: Budi atau RW 01"
             value="<?php echo safeText($search); ?>">
    </div>

    <!-- Filter Tanggal -->
    <div class="filter-group">
      <label for="date">Tanggal Laporan</label>
      <input type="date" name="date" id="date" class="filter-input"
             value="<?php echo safeText($dateFilter); ?>">
    </div>

    <!-- Sort -->
    <div class="filter-group">
      <label for="lapFilter">Urutkan</label>
      <select name="sort" id="lapFilter" class="filter-select">
        <option value="nama" <?php echo $sort==='nama'?'selected':''; ?>>Nama Petugas</option>
        <option value="tanggal" <?php echo $sort==='tanggal'?'selected':''; ?>>Tanggal Laporan</option>
        <option value="lokasi" <?php echo $sort==='lokasi'?'selected':''; ?>>Lokasi/RW</option>
      </select>
    </div>

    <div class="filter-group">
      <label style="visibility: hidden;">Aksi</label>
      <div style="display:flex; gap:10px;">
          <button type="submit" class="btn-apply">
            <i class="fas fa-search"></i> Cari
          </button>
          <?php if($search || $dateFilter): ?>
            <a href="laporan_petugas.php" class="btn-reset">
                <i class="fas fa-times"></i> Reset
            </a>
          <?php endif; ?>
      </div>
    </div>
  </form>

  <div class="label-row" style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
    <div>
      <span class="label-title">Hasil Riwayat Laporan</span>
      <span class="label-urut">
        <i class="fas fa-sort-amount-down"></i>
        Menampilkan <?php echo count($reportRows); ?> data
      </span>
    </div>
    <a href="?action=export_excel&date=<?php echo urlencode($dateFilter); ?>" 
       class="btn-export" 
       style="display: inline-flex; align-items: center; gap: 8px; padding: 10px 18px; background: linear-gradient(135deg, #22c55e 0%, #16a34a 100%); color: white; border-radius: 8px; text-decoration: none; font-weight: 600; font-size: 14px; box-shadow: 0 2px 8px rgba(34,197,94,0.3);">
      <i class="fas fa-file-excel"></i> Export Excel
    </a>
  </div>

  <!-- Tabel Riwayat Laporan -->
  <div class="result-box" style="padding: 0; height: auto; min-height: 300px;">
    <?php if (!empty($reportRows)): ?>
      <table class="laporan-table riwayat">
        <thead>
          <tr>
            <th><i class="fas fa-user"></i> Nama Petugas</th>
            <th><i class="fas fa-calendar-alt"></i> Tanggal</th>
            <th><i class="fas fa-map-marker-alt"></i> Lokasi/RW</th>
            <th><i class="fas fa-file-alt"></i> Deskripsi</th>
            <th><i class="fas fa-map-pin"></i> Koordinat</th>
            <th><i class="fas fa-eye"></i> Detail</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($reportRows as $row): 
            $dt = new DateTime($row['created_at']);
            
            // Prepare Photos Data for Modal (Used in Description Column & Detail Button)
            $currPhotos = $photosByReport[$row['id']] ?? [];
            $hasPhotos = !empty($currPhotos);
            $jsonPhotos = htmlspecialchars(json_encode($currPhotos), ENT_QUOTES, 'UTF-8');

            
            // Parse Lat/Long
            $lat = $row['latitude'] ? number_format((float)$row['latitude'], 6) : '-';
            $lng = $row['longitude'] ? number_format((float)$row['longitude'], 6) : '-';
            
            $loc = $row['lokasi'] ?? '-';
            $rw  = $row['rw_code'] ? ('RW '.$row['rw_code']) : '';
          ?>
            <tr>
              <td>
                <div style="font-weight:600; color:#333;">
                  <?php echo safeText($row['petugas']); ?>
                </div>
                <div style="font-size: 11px; color: #666; margin-top: 2px;">
                  <?php echo safeText(ucfirst($row['role'])); ?> 
                  <?php if(!empty($row['group_name'])) echo ' - ' . safeText($row['group_name']); ?>
                </div>
              </td>
              <td>
                <div class="tanggal-display">
                  <?php echo safeText($dt->format('d-m-Y')); ?>
                </div>
                <div style="font-size: 12px; color: #666;">
                  <?php echo safeText($dt->format('H:i')); ?>
                </div>
              </td>
              <td>
                <div class="lokasi-display">
                  <div class="lokasi-rw">
                    <?php echo safeText($rw); ?>
                  </div>
                  <div class="lokasi-name">
                    <?php echo safeText($loc); ?>
                  </div>
                </div>
              </td>
              <td>
                <?php $desc = $row['description'] ?: $row['schedule_desc'] ?: '-'; ?>
                <div style="font-size:13px; color:#0d6efd; max-width:200px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; cursor:pointer;" 
                     title="Klik untuk melihat detail report"
                     onclick="openDetailModal('<?php echo safeText($row['petugas']); ?>', '<?php echo safeText($rw); ?>', <?php echo $jsonPhotos; ?>, '<?php echo $row['status']; ?>', <?php echo $row['id']; ?>, <?php echo htmlspecialchars(json_encode($desc), ENT_QUOTES, 'UTF-8'); ?>)">
                    <span style="border-bottom: 1px dashed #0d6efd;"><?php echo safeText($desc); ?></span>
                </div>
              </td>
              <td>
                <?php if($lat !== '-'): ?>
                    <a href="https://www.google.com/maps?q=<?= $row['latitude'] ?>,<?= $row['longitude'] ?>" 
                       target="_blank" 
                       class="coordinate-box" 
                       style="text-decoration: none; cursor: pointer;"
                       title="Buka di Google Maps">
                        📍 <?= $lat ?>, <?= $lng ?>
                    </a>
                <?php else: ?>
                    <span style="color:#999;">-</span>
                <?php endif; ?>
              </td>
              <td>
                <!-- Detail Button with Data -->
                <button type="button" 
                        class="btn-detail <?php echo $hasPhotos ? '' : 'disabled'; ?>" 
                        data-report-id="<?php echo $row['id']; ?>"
                        onclick="openDetailModal('<?php echo safeText($row['petugas']); ?>', '<?php echo safeText($rw); ?>', <?php echo $jsonPhotos; ?>, '<?php echo $row['status']; ?>', <?php echo $row['id']; ?>, <?php echo htmlspecialchars(json_encode($desc), ENT_QUOTES, 'UTF-8'); ?>)"
                        <?php echo $hasPhotos ? '' : 'disabled'; ?>>
                    <i class="fas fa-eye"></i> Lihat
                </button>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php else: ?>
      <div class="table-empty-laporan">
        <i class="fas fa-search"></i>
        <p>Tidak ada laporan ditemukan sesuai filter (30 hari terakhir).</p>
      </div>
    <?php endif; ?>
  </div>

  <!-- Foto Gallery (Updated) -->
  <!-- Foto Gallery Section Removed (Moved to Popup) -->

  <!-- Action Buttons Removed as per request -->




</section>
</main>
</div>

<!-- Font Awesome Icons -->
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">

<script>
// Auto submit form saat select sort berubah
const lapFilterEl = document.getElementById('lapFilter');
if (lapFilterEl) {
    lapFilterEl.addEventListener('change', function() {
        document.getElementById('filterForm').submit();
    });
}

// MODAL LOGIC
// MODAL LOGIC
function openDetailModal(petugas, lokasi, photos, status, reportId, description) {
    const modal = document.getElementById('detailModal');
    const title = document.getElementById('modalTitle');
    const container = document.getElementById('modalPhotos');
    const btnValidate = document.getElementById('btnValidate');
    const btnReject = document.getElementById('btnReject');
    const inputReportId = document.getElementById('inputReportId');
    const inputReportIdReject = document.getElementById('inputReportIdReject');
    const inputReportIdReanalyze = document.getElementById('inputReportIdReanalyze');
    const statusLabel = document.getElementById('modalStatusLabel');
    const modalDesc = document.getElementById('modalDescription');
    
    title.innerHTML = `${petugas} - <span style="font-weight:400; font-size:14px;">${lokasi}</span>`;
    
    // Set Description
    if (modalDesc) {
        if(description) {
            modalDesc.innerHTML = `<div style="margin-bottom:15px; background:#f0f4f8; padding:10px; border-radius:8px; border-left:4px solid #007bff;">
                <strong style="color:#0056b3; display:block; margin-bottom:4px;">Deskripsi Pekerjaan:</strong>
                <span style="color:#333;">${description}</span>
            </div>`;
            modalDesc.style.display = 'block';
        } else {
            modalDesc.style.display = 'none';
        }
    }

    container.innerHTML = '';
    
    // Set Status Label
    if (status === 'approved') {
        statusLabel.innerHTML = '<span class="status-badge success"><i class="fas fa-check-circle"></i> Sudah Disetujui</span>';
        btnValidate.style.display = 'none'; 
        btnReject.style.display = 'none';
    } else if (status === 'rejected') {
        statusLabel.innerHTML = '<span class="status-badge rejected"><i class="fas fa-times-circle"></i> Ditolak</span>';
        btnValidate.style.display = 'inline-flex'; 
        btnReject.style.display = 'none';
    } else {
        statusLabel.innerHTML = '<span class="status-badge pending"><i class="fas fa-clock"></i> Pending</span>';
        btnValidate.style.display = 'inline-flex'; 
        btnReject.style.display = 'inline-flex';
    }

    // Set Report ID for ALL forms (approve, reject, reanalyze)
    inputReportId.value = reportId;
    inputReportIdReject.value = reportId;
    if (inputReportIdReanalyze) inputReportIdReanalyze.value = reportId;
    
    if (!photos || photos.length === 0) {
        container.innerHTML = '<div style="text-align:center; padding:30px; color:#777;">Tidak ada foto untuk laporan ini.</div>';
    } else {
        photos.forEach(ph => {
            const typeLabel = ph.photo_type ? ph.photo_type.toUpperCase() : 'FOTO';
            const imgPath = ph.full_path; 
            
            // AI validation status
            const aiDesc = ph.ai_description || '';
            const aiValid = ph.ai_is_valid_work;
            let aiStatusHtml = '';
            
            if (aiValid !== null && aiValid !== undefined) {
                if (aiValid == 1) {
                    aiStatusHtml = `<div style="margin-top: 8px; padding: 6px 10px; background: #d4edda; color: #155724; border-radius: 6px; font-size: 12px; font-weight: 600;">
                        <i class="fas fa-check-circle"></i> AI: Pekerjaan Valid
                    </div>`;
                } else {
                    aiStatusHtml = `<div style="margin-top: 8px; padding: 6px 10px; background: #f8d7da; color: #721c24; border-radius: 6px; font-size: 12px; font-weight: 600;">
                        <i class="fas fa-times-circle"></i> AI: Ditolak / Tidak Valid
                    </div>`;
                }
            }
            
            const aiDescHtml = aiDesc ? `<div style="margin-top: 8px; padding: 8px; background: #e3f2fd; border-radius: 6px; font-size: 12px; color: #1565c0; line-height: 1.4;">
                <strong><i class="fas fa-robot"></i> Deskripsi AI:</strong><br>
                ${aiDesc}
            </div>` : '';
            
            const card = document.createElement('div');
            card.className = 'photo-card';
            card.innerHTML = `
                <img src="${imgPath}" onclick="window.open('${imgPath}', '_blank')" alt="${typeLabel}" onerror="this.src='../../assets/img/no-schedule.svg'">
                <div class="photo-info">
                    <div class="photo-tag">${typeLabel}</div>
                    <div>${new Date(ph.uploaded_at).toLocaleString('id-ID')}</div>
                    ${aiDescHtml}
                    ${aiStatusHtml}
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

// Close on outside click
const detailModalEl = document.getElementById('detailModal');
if (detailModalEl) {
    detailModalEl.addEventListener('click', function(e) {
        if (e.target === this) closeDetailModal();
    });
}
</script>

<!-- MODAL HTML STRUCTURE -->
<div id="detailModal" class="modal-overlay">
  <div class="modal-wrapper">
    <div class="modal-header">
      <div style="display:flex; flex-direction:column;">
          <div id="modalTitle" class="modal-title-text">Detail Laporan</div>
          <div id="modalStatusLabel" style="margin-top:4px; font-size:12px;"></div>
      </div>
      <button class="modal-close" onclick="closeDetailModal()">&times;</button>
    </div>
    
    <div class="modal-body">
      <div id="modalDescription" style="padding: 0 10px;"></div>
      <div id="modalPhotos" class="photo-grid">
        <!-- JS will populate this -->
      </div>
    </div>

    <!-- MODAL FOOTER FOR ACTIONS -->
    <div class="modal-footer" style="padding: 15px 20px; background: #f8f9fa; border-top: 1px solid #eee; text-align: right; display: flex; justify-content: flex-end; gap: 10px;">
        <!-- Re-analyze AI Button -->
        <form method="POST" style="display:inline;" id="formReanalyze">
            <input type="hidden" name="action" value="reanalyze_ai">
            <input type="hidden" name="report_id" id="inputReportIdReanalyze" value="">
            <button type="submit" id="btnReanalyze" class="btn-reanalyze" style="background: #007bff; color: white; border: none; padding: 10px 16px; border-radius: 6px; font-size: 13px; font-weight: 600; cursor: pointer; display: inline-flex; align-items: center; gap: 6px;">
                <i class="fas fa-robot"></i> Re-analyze AI
            </button>
        </form>
        <form method="POST" style="display:inline;" id="formApprove">
            <input type="hidden" name="action" value="validate_report">
            <input type="hidden" name="report_id" id="inputReportId" value="">
            <button type="submit" id="btnValidate" class="btn-validate">
                <i class="fas fa-check-double"></i> Approve / Terima
            </button>
        </form>
        <form method="POST" style="display:inline;" id="formReject">
            <input type="hidden" name="action" value="reject_report">
            <input type="hidden" name="report_id" id="inputReportIdReject" value="">
            <button type="submit" id="btnReject" class="btn-reject">
                <i class="fas fa-times"></i> Tolak
            </button>
        </form>
        <button type="button" class="btn-cancel" onclick="closeDetailModal()" style="padding: 10px 20px; border:1px solid #ddd; background:white; border-radius:6px; cursor:pointer;">
            Tutup
        </button>
    </div>
  </div>
</div>

<style>
/* Additional Styles for Modal Actions */
.status-badge {
    padding: 2px 8px;
    border-radius: 12px;
    font-size: 11px;
    font-weight: 600;
}
.status-badge.success { background: #d4edda; color: #155724; }
.status-badge.pending { background: #fff3cd; color: #856404; }

.btn-validate {
    background: #28a745;
    color: white;
    border: none;
    padding: 10px 20px;
    border-radius: 6px;
    font-size: 14px;
    font-weight: 600;
    cursor: pointer;
    transition: background 0.2s;
    display: inline-flex; 
    align-items: center; 
    gap: 8px;
}
.btn-validate:hover {
    background: #218838;
}

.btn-reject {
    background: #dc3545;
    color: white;
    border: none;
    padding: 10px 20px;
    border-radius: 6px;
    font-size: 14px;
    font-weight: 600;
    cursor: pointer;
    transition: background 0.2s;
    display: inline-flex; 
    align-items: center; 
    gap: 8px;
}
.btn-reject:hover {
    background: #c82333;
}

.status-badge.rejected { background: #f8d7da; color: #721c24; }
</style>

<script>
// Handle highlight parameter - auto-scroll and open modal for specific report
document.addEventListener('DOMContentLoaded', function() {
    const urlParams = new URLSearchParams(window.location.search);
    const highlightId = urlParams.get('highlight');
    
    if (highlightId) {
        // Find the row with this report ID
        const detailBtn = document.querySelector(`[data-report-id="${highlightId}"]`);
        if (detailBtn) {
            // Scroll to the row
            detailBtn.scrollIntoView({ behavior: 'smooth', block: 'center' });
            
            // Add highlight effect to parent row
            const row = detailBtn.closest('tr');
            if (row) {
                row.style.backgroundColor = '#e3f2fd';
                row.style.animation = 'highlightPulse 1s ease-in-out 2';
            }
            
            // Auto-click the detail button after short delay
            setTimeout(() => {
                detailBtn.click();
            }, 800);
        }
    }
});
</script>

<style>
@keyframes highlightPulse {
    0%, 100% { background-color: #e3f2fd; }
    50% { background-color: #bbdefb; }
}
</style>

</body>
</html>
