<?php
session_start();
require_once '../../config/database.php';
require_once '../../helpers/session.php';
enforce_session(['supervisor']);

require_once '../../petugas/controllers/PetugasController.php';
$petugasController = new PetugasController();

$userId = $_GET['id'] ?? 0;
if (!$userId) {
    echo "User ID missing.";
    exit;
}

$pdo = Database::getInstance()->getConnection();

// AJAX Handler for Live Location
if (isset($_GET['action']) && $_GET['action'] === 'get_location') {
    header('Content-Type: application/json');
    $stmt = $pdo->prepare("SELECT latitude, longitude, last_login FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    echo json_encode($stmt->fetch(PDO::FETCH_ASSOC));
    exit;
}

$data = $petugasController->getMonitoringData($userId);
if (empty($data['success'])) {
    echo "Error: " . ($data['error'] ?? 'Unknown error');
    exit;
}

$u = $data['user'];
$tasks = $data['tasks'];

// Helpers
function getStatusLabel($status) {
    switch ($status) {
        case 'approved': return 'Disetujui';
        case 'rejected': return 'Ditolak';
        case 'pending': return 'Menunggu Validasi';
        case 'ongoing': return 'Sedang Berjalan';
        case 'upcoming': return 'Belum Mulai';
        case 'missed': return 'Belum Lapor / Terlewat';
        default: return ucfirst($status);
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Monitoring Petugas - <?php echo htmlspecialchars($u['name']); ?></title>
    <link rel="stylesheet" href="../css/dashboard.css">
    <link rel="stylesheet" href="../css/detail_petugas.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
</head>
<body>

<div class="layout" style="display:flex;">
    <?php require_once '../../supervisor/components/sidebar.php'; ?>

    <main class="content" style="flex:1; padding:20px;">
        <header class="header">
            <div class="header-left">
                <a href="data_petugas.php" class="back-link" style="text-decoration:none; color:#666; font-weight:600;"><i class="fas fa-arrow-left"></i> Kembali</a>
                <h2 style="margin-left:15px; display:inline;">Monitoring Petugas</h2>
            </div>
        </header>

        <div class="monitoring-container">
            <!-- Left Column: Profile & Live Status -->
            <div class="left-col">
                <div class="profile-card">
                    <?php 
                        $initials = strtoupper(substr($u['name'], 0, 2));
                        if ($u['photo_path'] && file_exists('../../public/uploads/' . $u['photo_path'])) {
                            echo '<img src="../../public/uploads/'.$u['photo_path'].'" alt="Profile" class="profile-img-large">';
                        } else {
                            // Fallback to Initials Avatar to fix broken UI
                            echo '<div class="profile-avatar-large">'.$initials.'</div>';
                        }
                    ?>
                    <div class="profile-name"><?php echo htmlspecialchars($u['name']); ?></div>
                    <div class="profile-role"><?php echo htmlspecialchars($u['nip']); ?></div>
                    
                    <div class="info-grid">
                        <div class="info-item">
                            <label>Status Akun</label>
                            <?php 
                                $statusColor = match($u['status']) {
                                    'online' => '#22c55e',
                                    'offline' => '#64748b',
                                    'nonaktif' => '#ef4444',
                                    'cuti' => '#f59e0b',
                                    default => '#6b7280'
                                };
                                $statusLabel = match($u['status']) {
                                    'online' => '🟢 Online',
                                    'offline' => '⚫ Offline',
                                    'nonaktif' => '🔴 Nonaktif',
                                    'cuti' => '🟡 Cuti',
                                    default => ucfirst($u['status'])
                                };
                            ?>
                            <span style="color: <?php echo $statusColor; ?>; font-weight:600;">
                                <?php echo $statusLabel; ?>
                            </span>
                        </div>
                        <div class="info-item">
                            <label>Telepon</label>
                            <span><?php echo htmlspecialchars($u['phone']); ?></span>
                        </div>
                    </div>

                    <div class="gps-box">
                        <div class="gps-header">
                            <span><i class="fas fa-map-marker-alt"></i> Lokasi Terkini</span>
                            <span class="live-badge">LIVE</span>
                        </div>
                        <div class="coords-display" id="liveCoords">
                            <?php 
                                echo ($u['latitude'] && $u['longitude']) 
                                ? $u['latitude'] . ', ' . $u['longitude'] 
                                : 'Belum ada data lokasi';
                            ?>
                        </div>
                        <a href="#" id="mapsLink" target="_blank" class="view-report-btn maps-btn">
                            <i class="fas fa-external-link-alt"></i> Buka Google Maps
                        </a>
                    </div>
                </div>

                <style>
                    /* Inline CSS for Profile Avatar Fallback */
                    .profile-avatar-large {
                        width: 100px;
                        height: 100px;
                        background: #e2e8f0;
                        color: #64748b;
                        border-radius: 50%;
                        display: flex;
                        align-items: center;
                        justify-content: center;
                        font-size: 36px;
                        font-weight: bold;
                        margin: 0 auto 15px; /* Centered */
                    }
                    .profile-img-large {
                        width: 100px;
                        height: 100px;
                        object-fit: cover;
                        border-radius: 50%;
                        margin: 0 auto 15px;
                        display: block;
                    }
                    /* Fix Maps Button UI */
                    .maps-btn {
                        width: 100px; 
                        display: block; 
                        text-align: center; 
                        margin-top: 15px; /* Add spacing from coords box */
                        background-color: #0ea5e9; /* Light Blue */
                        color: white !important;
                        padding: 12px;
                        border-radius: 8px;
                        font-weight: 500;
                        transition: background 0.2s;
                    }
                    .maps-btn:hover {
                        background-color: #16262eff;
                    }
                    .coords-display {
                        padding: 12px; 
                        background: #f8fafc; 
                        border: 1px solid #e2e8f0; 
                        border-radius: 8px;
                        text-align: center;
                        font-family: monospace;
                        color: #334155;
                        font-weight: 600;
                    }
                </style>
            </div>

            <!-- Right Column: Tasks -->
            <div class="tasks-column">
                <div class="section-header">
                    <h3><i class="fas fa-clipboard-list"></i> Pekerjaan Hari Ini (<?php echo date('d M Y'); ?>)</h3>
                </div>

                <div class="task-list">
                    <?php if (empty($tasks)): ?>
                        <div style="text-align:center; padding:40px; color:#888;">Belum ada jadwal penugasan hari ini.</div>
                    <?php else: ?>
                        <?php foreach ($tasks as $task): ?>
                            <div class="task-card status-<?php echo $task['display_status']; ?>">
                                <div class="task-header">
                                    <div class="task-time">
                                        <?php echo date('H:i', strtotime($task['shift_start'])) . ' - ' . date('H:i', strtotime($task['shift_end'])); ?>
                                    </div>
                                    <div class="task-status status-<?php echo $task['display_status']; ?>">
                                        <?php echo getStatusLabel($task['display_status']); ?>
                                    </div>
                                </div>
                                <div class="task-zone">
                                    <?php echo htmlspecialchars($task['zone_name']); ?> (RW <?php echo htmlspecialchars($task['rw_code']); ?>)
                                </div>
                                <div class="task-desc">
                                    <?php echo htmlspecialchars($task['description']); ?>
                                </div>
                                <div class="task-footer">
                                    <div style="font-size:12px; color:#666;">
                                        <?php if ($task['report_time']): ?>
                                            <i class="fas fa-clock"></i> Dilaporkan: <?php echo date('H:i', strtotime($task['report_time'])); ?>
                                        <?php endif; ?>
                                    </div>
                                    
                                    <?php if ($task['report_id']): ?>
                                        <!-- Supervisor Link might be different if filter logic is strictly per ID -->
                                        <!-- Usually supervisor needs report_id and maybe petugas_id for filtering -->
                                        <a href="laporan_petugas.php?report_id=<?php echo $task['report_id']; ?>&q=<?php echo urlencode($u['name']); ?>" 
                                           class="view-report-btn">
                                            <i class="fas fa-eye"></i> Lihat Detail Laporan
                                        </a>
                                    <?php else: ?>
                                        <span class="view-report-btn disabled">Belum Ada Laporan</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </main>
</div>

<script>
    // Live Coordinate Updates
    const userId = <?php echo $userId; ?>;
    const coordsDisplay = document.getElementById('liveCoords');
    const mapsLink = document.getElementById('mapsLink');
    const lastUpdate = document.getElementById('lastUpdate');

    function updateCoordinates() {
        fetch(`detail_petugas.php?id=${userId}&action=get_location`)
            .then(response => response.json())
            .then(data => {
                const now = new Date().toLocaleTimeString();
                const now = new Date().toLocaleTimeString();
                
                if (data && data.latitude && data.longitude) {
                    const lat = parseFloat(data.latitude);
                    const lng = parseFloat(data.longitude);
                    
                    coordsDisplay.innerHTML = `${lat}, ${lng}`;
                    mapsLink.href = `https://www.google.com/maps?q=${lat},${lng}`;
                    mapsLink.style.display = 'block';
                    
                    // Visual feedback
                    coordsDisplay.style.borderColor = '#28a745';
                    setTimeout(() => coordsDisplay.style.borderColor = '#ddd', 500);
                }
            })
            .catch(err => console.error('Error fetching GPS:', err));
    }
    
    // Auto refresh coordinates every 5 seconds
    setInterval(updateCoordinates, 5000);
    
    // Initial Map Link setup
    const lat = <?php echo $u['latitude'] ?: '0'; ?>;
    const lng = <?php echo $u['longitude'] ?: '0'; ?>;
    if(lat == 0 || lng == 0) {
        mapsLink.style.display = 'none';
    }

</script>

</body>
</html>
