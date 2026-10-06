<?php
session_start();
require_once '../../config/database.php';
require_once '../../helpers/session.php';
enforce_session(['admin']);

if (!isset($_GET['id'])) {
    header('Location: data_supervisor.php');
    exit;
}

$userId = (int)$_GET['id'];
$pdo = Database::getInstance()->getConnection();
require_once '../../admin/controllers/PetugasController.php';
$controller = new PetugasController();

$result = $controller->getPetugasDetail($userId);

if (!$result['success']) {
    // Redirect jika user tidak ditemukan
    header('Location: data_supervisor.php');
    exit;
}

$user = $result['petugas'];

// Helper
function safe($v) { return htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8'); }
function avatarInitials($name) {
    if (!$name) return 'S';
    $parts = explode(' ', $name);
    return strtoupper(substr($parts[0], 0, 1) . (isset($parts[1]) ? substr($parts[1], 0, 1) : ''));
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Detail Supervisor - Admin</title>
    <link rel="stylesheet" href="../css/dashboard.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        .detail-container {
            display: grid;
            grid-template-columns: 350px 1fr;
            gap: 24px;
            padding: 24px;
            align-items: start;
        }
        @media (max-width: 1024px) {
            .detail-container { grid-template-columns: 1fr; }
        }
        
        /* Left Column */
        .profile-card {
            background: white;
            border-radius: 16px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.06);
            overflow: hidden;
            position: sticky;
            top: 24px;
        }
        .profile-header {
            background: linear-gradient(135deg, #0f172a 0%, #334155 100%);
            padding: 40px 20px;
            text-align: center;
            color: white;
        }
        .profile-avatar {
            width: 100px; height: 100px;
            background: rgba(255,255,255,0.2);
            backdrop-filter: blur(10px);
            border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            font-size: 36px; font-weight: 700; color: white;
            margin: 0 auto 16px;
            border: 4px solid rgba(255,255,255,0.3);
        }
        .profile-name { font-size: 20px; font-weight: 700; margin-bottom: 4px; }
        .profile-role { font-size: 14px; opacity: 0.8; margin-bottom: 12px; }
        .status-badge {
            display: inline-block; padding: 4px 12px;
            border-radius: 20px; font-size: 12px; font-weight: 600;
            background: rgba(255,255,255,0.2);
        }
        
        .profile-body { padding: 24px; }
        .info-row {
            display: flex; justify-content: space-between;
            padding: 12px 0; border-bottom: 1px solid #f1f5f9;
            font-size: 14px;
        }
        .info-label { color: #64748b; }
        .info-value { font-weight: 600; color: #1e293b; }
        
        /* GPS Card */
        .gps-card {
            background: #f8fafc; border: 1px solid #e2e8f0;
            border-radius: 12px; padding: 16px;
            margin-top: 20px;
        }
        .gps-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px; }
        .gps-title { font-weight: 700; font-size: 14px; color: #334155; }
        .live-badge { 
            background: #ef4444; color: white; 
            padding: 3px 8px; border-radius: 10px; 
            font-size: 10px; animation: pulse 2s infinite; 
        }
        @keyframes pulse { 0%, 100% { opacity: 1; } 50% { opacity: 0.5; } }
        .gps-coords { font-family: monospace; font-size: 12px; color: #64748b; margin-bottom: 10px; }
        .gps-btn {
            display: block; width: 100%; text-align: center;
            background: #3b82f6; color: white;
            padding: 10px; border-radius: 8px;
            text-decoration: none; font-weight: 600; font-size: 13px;
        }
        .gps-btn:hover { background: #2563eb; }
        .gps-time { font-size: 11px; color: #94a3b8; margin-top: 8px; text-align: center; }
        
        /* Action Buttons */
        .action-row {
            display: grid; grid-template-columns: 1fr; gap: 10px;
            margin-top: 20px;
        }
        .btn-action {
            display: flex; align-items: center; justify-content: center;
            padding: 12px; border-radius: 10px;
            font-size: 14px; font-weight: 600; text-decoration: none;
            transition: all 0.2s;
        }
        .btn-primary { background: #0f172a; color: white; }
        .btn-primary:hover { background: #1e293b; transform: translateY(-2px); }
        
        /* Right Column */
        .section-card {
            background: white; border-radius: 16px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.06);
            padding: 24px; margin-bottom: 20px;
        }
        .section-title {
            font-size: 16px; font-weight: 700; color: #1e293b;
            margin: 0 0 20px 0; display: flex; align-items: center; gap: 10px;
        }
        .section-title i { color: #3b82f6; }
        
    </style>
</head>
<body>
<div class="app">
    <?php require_once '../../admin/components/sidebar.php'; ?>

    <main class="main">
        <header class="header">
            <div class="header-left">
                <a href="data_supervisor.php" class="back-link"><i class="fas fa-arrow-left"></i> Kembali</a>
                <h1 class="page-title">Detail Supervisor</h1>
            </div>
        </header>

        <div class="detail-container">
            <!-- Left Column: Profile -->
            <div>
                <div class="profile-card">
                    <div class="profile-header">
                        <div class="profile-avatar">
                            <?= avatarInitials($user['name']) ?>
                        </div>
                        <div class="profile-name"><?= safe($user['name']) ?></div>
                        <div class="profile-role">Supervisor PPSU</div>
                        <div class="status-badge"><?= ucfirst($user['status']) ?></div>
                    </div>
                    
                    <div class="profile-body">
                        <div class="info-row">
                            <span class="info-label">NIP</span>
                            <span class="info-value"><?= safe($user['nip']) ?></span>
                        </div>
                        <div class="info-row">
                            <span class="info-label">Username</span>
                            <span class="info-value"><?= safe($user['username']) ?></span>
                        </div>
                        <div class="info-row">
                            <span class="info-label">No. HP</span>
                            <span class="info-value"><?= safe($user['phone']) ?></span>
                        </div>
                        <div class="info-row">
                            <span class="info-label">Terdaftar</span>
                            <span class="info-value"><?php echo date('d M Y', strtotime($user['created_at'])); ?></span>
                        </div>
                        
                        <!-- GPS Location -->
                        <div class="gps-card">
                            <div class="gps-header">
                                <span class="gps-title"><i class="fas fa-map-marker-alt"></i> Lokasi GPS</span>
                                <span id="gps-badge-container">
                                <?php 
                                    $lastUpdate = $user['last_location_update'] ? strtotime($user['last_location_update']) : 0;
                                    $timeDiff = time() - $lastUpdate;
                                    $isLive = $timeDiff < 600; // 10 minutes
                                ?>
                                <?php if ($user['latitude'] && $user['longitude'] && $isLive): ?>
                                    <span class="live-badge">LIVE</span>
                                <?php elseif ($user['latitude'] && $user['longitude']): ?>
                                    <span class="live-badge" style="background:#64748b; animation:none;">OFFLINE</span>
                                <?php endif; ?>
                                </span>
                            </div>
                            <div id="gps-data-container">
                            <?php if ($user['latitude'] && $user['longitude']): ?>
                                <div class="gps-coords" id="gps-coords"><?php echo $user['latitude']; ?>, <?php echo $user['longitude']; ?></div>
                                <a href="https://www.google.com/maps?q=<?php echo $user['latitude']; ?>,<?php echo $user['longitude']; ?>" target="_blank" class="gps-btn" id="gps-link">
                                    <i class="fas fa-external-link-alt"></i> Buka Google Maps
                                </a>
                                <!-- Removed Update Time -->
                            <?php else: ?>
                                <div style="color:#94a3b8; font-size:13px; text-align:center;">Belum ada data lokasi</div>
                            <?php endif; ?>
                            </div>
                        </div>
                        
                        <!-- Action Buttons -->
                        <div class="action-row">
                            <a href="edit_petugas.php?id=<?php echo $userId; ?>" class="btn-action btn-primary">
                                <i class="fas fa-edit"></i> Edit Profil
                            </a>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Right Column: Info Group (Placeholder) -->
            <div class="right-column">
                <div class="section-card">
                    <h3 class="section-title"><i class="fas fa-info-circle"></i> Informasi</h3>
                    <p style="color:#64748b;">Halaman ini menampilkan lokasi realtime supervisor. Gunakan menu Data Petugas untuk melihat anggota grup yang dipimpin oleh supervisor ini.</p>
                </div>
                
                 <!-- Recent Activity / Logs could go here -->
            </div>
        </div>
    </main>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const userId = <?php echo $userId; ?>;
    const gpsCoords = document.getElementById('gps-coords');
    const gpsLink = document.getElementById('gps-link');
    const gpsTime = document.getElementById('gps-time');
    const badgeContainer = document.getElementById('gps-badge-container');

    function updateLocation() {
        fetch('../../api/gps/get_location.php?user_id=' + userId)
        .then(response => response.json())
        .then(data => {
            if (data.success && data.location && data.location.latitude) {
                const loc = data.location;
                const lat = parseFloat(loc.latitude).toFixed(8);
                const lng = parseFloat(loc.longitude).toFixed(8);
                const lastUpdate = new Date(loc.last_location_update);
                const now = new Date();
                const diffSeconds = (now - lastUpdate) / 1000;
                
                // Format timestamp
                const dateStr = lastUpdate.getDate().toString().padStart(2, '0') + '/' + 
                              (lastUpdate.getMonth()+1).toString().padStart(2, '0') + '/' + 
                              lastUpdate.getFullYear() + ' ' + 
                              lastUpdate.getHours().toString().padStart(2, '0') + ':' + 
                              lastUpdate.getMinutes().toString().padStart(2, '0');
                
                // Update Coords & Time
                if (gpsCoords) gpsCoords.innerText = `${lat}, ${lng}`;
                if (gpsLink) gpsLink.href = `https://www.google.com/maps?q=${lat},${lng}`;
                // if (gpsTime) gpsTime.innerText = `Update: ${dateStr}`;
                
                // Update Badge
                if (diffSeconds < 600) { // 10 mins
                    badgeContainer.innerHTML = '<span class="live-badge">LIVE</span>';
                } else {
                    badgeContainer.innerHTML = '<span class="live-badge" style="background:#64748b; animation:none;">OFFLINE</span>';
                }
            }
        })
        .catch(err => console.error('Error polling location:', err));
    }

    // Poll every 5 seconds
    setInterval(updateLocation, 5000);
});
</script>

</body>
</html>
