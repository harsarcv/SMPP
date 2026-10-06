<?php
ob_start();
session_start();
require_once '../../config/database.php';
require_once '../../helpers/session.php';

// Cek role supervisor (basic auth only)
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'supervisor') {
    // For AJAX requests, return JSON error instead of redirect
    if (
        !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && 
        strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest'
    ) {
        ob_end_clean();
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Session expired. Please login again.']);
        exit;
    }
    header('Location: /auth/login.php');
    exit;
}

$supName = $_SESSION['name'] ?? 'Supervisor';
$supNip  = $_SESSION['nip']  ?? '';
$userId  = $_SESSION['user_id'] ?? 0;

// Handler: ubah status kerja via AJAX (MUST be before require_active_user!)
if (
    ($_SERVER['REQUEST_METHOD'] === 'POST' || $_SERVER['REQUEST_METHOD'] === 'GET') &&
    (($_POST['action'] ?? $_GET['action'] ?? '') === 'work_status')
) {
    ob_end_clean();
    header('Content-Type: application/json');
    
    // Use 'start' or 'stop' action instead of direct status
    $workAction = $_POST['work_action'] ?? $_GET['work_action'] ?? '';
    
    // Backward compatibility: convert old 'status' param to action
    if (empty($workAction)) {
        $oldStatus = $_POST['status'] ?? $_GET['status'] ?? '';
        $workAction = ($oldStatus === 'aktif' || $oldStatus === 'online') ? 'start' : 'stop';
    }
    
    if (!in_array($workAction, ['start', 'stop'], true)) {
        echo json_encode(['success' => false, 'message' => 'Aksi tidak valid']);
        exit;
    }
    
    try {
        require_once __DIR__ . '/../controllers/SupervisorWorkController.php';
        $controller = new SupervisorWorkController();
        $result = $controller->updateWorkStatus((int)$userId, $workAction);
        
        if (!empty($result['success'])) {
            echo json_encode([
                'success' => true, 
                'data' => ['status' => $result['status']],
                'message' => $result['message'] ?? ''
            ]);
        } else {
            echo json_encode(['success' => false, 'message' => $result['error'] ?? 'Gagal memperbarui status']);
        }
    } catch (Throwable $e) {
        error_log("Update Status Error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Gagal memperbarui status: ' . $e->getMessage()]);
    }
    exit;
}

// Now check if user is active (for page view only, not for AJAX status update)
require_login();
// Skip require_active_user for supervisors since they control their own status
// require_active_user();

// Get Current Status (4-status system: online, offline, nonaktif, cuti)
$pdo = Database::getInstance()->getConnection();
$stmt = $pdo->prepare("SELECT status FROM users WHERE id = ?");
$stmt->execute([$userId]);
$currentStatus = $stmt->fetchColumn() ?: 'offline';
$isOnline = ($currentStatus === 'online');
$canWork = in_array($currentStatus, ['online', 'offline']);  // Can use Start/Stop button


?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Monitoring Anggota - Supervisor</title>
    <link rel="stylesheet" href="../css/dashboard.css" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        .monitoring-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 20px;
            margin-top: 20px;
        }
        .member-card {
            background: white;
            border-radius: 16px;
            padding: 20px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.05);
            transition: transform 0.2s;
            position: relative;
            overflow: hidden;
        }
        .member-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(0,0,0,0.1);
        }
        .mc-header {
            display: flex;
            align-items: center;
            gap: 15px;
            margin-bottom: 15px;
        }
        .mc-avatar {
            width: 50px;
            height: 50px;
            background: #e2e8f0;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            color: #64748b;
            font-size: 18px;
        }
        .mc-info h4 { margin: 0; font-size: 16px; color: #1e293b; }
        .mc-info p { margin: 4px 0 0; font-size: 12px; color: #94a3b8; }
        
        .mc-status {
            position: absolute;
            top: 20px;
            right: 20px;
            font-size: 11px;
            font-weight: 600;
            padding: 4px 10px;
            border-radius: 20px;
        }
        .status-online { background: #dcfce7; color: #166534; animation: pulse 2s infinite; }
        .status-recent { background: #fef9c3; color: #854d0e; }
        .status-offline { background: #f1f5f9; color: #64748b; }
        
        @keyframes pulse { 0% { opacity: 1; } 50% { opacity: 0.6; } 100% { opacity: 1; } }
        
        .mc-location {
            background: #f8fafc;
            padding: 12px;
            border-radius: 10px;
            font-size: 12px;
            color: #475569;
            margin-bottom: 15px;
        }
        .mc-location i { color: #3b82f6; margin-right: 6px; }
        
        .mc-actions {
            display: flex;
            gap: 10px;
        }
        .btn-map {
            flex: 1;
            background: #3b82f6;
            color: white;
            border: none;
            padding: 10px;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            text-align: center;
            text-decoration: none;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
        }
        .btn-map:hover { 
            background: #2563eb;
            text-align: center;
            padding: 40px;
            color: #94a3b8;
        }
        
        .role-badge {
            font-size: 10px;
            padding: 2px 6px;
            border-radius: 4px;
            margin-left: 5px;
            text-transform: uppercase;
            font-weight: bold;
        }
        .role-supervisor { background: #e0f2fe; color: #0284c7; }
        .role-petugas { background: #f0fdf4; color: #166534; }
    </style>
</head>
    </style>
    <?php if (empty($_SESSION['csrf_token'])) { $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); } ?>
    <meta name="csrf-token" content="<?php echo $_SESSION['csrf_token']; ?>">
</head>
<body>

  <!-- Popup Stop Konfirmasi -->
  <div id="popup-stop" class="popup-overlay" style="display: none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:9999; align-items:center; justify-content:center;">
    <div class="popup-box" style="background:#fff; padding:25px; border-radius:12px; text-align:center; max-width:300px; width:90%;">
      <p class="popup-text" style="margin-bottom:20px; color:#333;">
        Apakah Anda yakin ingin<br>
        <b>Menyelesaikan Pekerjaan</b> hari ini?
      </p>
      <div style="display:flex; gap:10px; justify-content:center;">
        <button id="btnStopCancel" style="border:none; padding:10px 20px; border-radius:8px; background:#e2e8f0; color:#475569; font-weight:600; cursor:pointer;">Batal</button>
        <button id="btnStopConfirm" style="border:none; padding:10px 20px; border-radius:8px; background:#ef4444; color:white; font-weight:600; cursor:pointer;">Ya, Selesai</button>
      </div>
    </div>
  </div>

<div class="layout">
    <?php require_once '../../supervisor/components/sidebar.php'; ?>

    <main class="content">
        <header class="header">
            <div class="header-left">
                <h2>Monitoring Anggota</h2>
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

        <section class="stats">
            <div class="stat-card">
                <div class="num" id="count-online">0</div>
                <div class="label" style="color:#22c55e;">Online</div>
            </div>
            <div class="stat-card">
                <div class="num" id="count-offline">0</div>
                <div class="label" style="color:#64748b;">Offline</div>
            </div>
            <div class="stat-card">
                <div class="num" id="count-total">0</div>
                <div class="label">Total Anggota</div>
            </div>
        </section>

        <div id="member-container" class="monitoring-grid" style="padding-bottom: 80px;">
            <div class="loading">
                <i class="fas fa-circle-notch fa-spin fa-2x"></i>
                <p style="margin-top:10px;">Memuat data lokasi...</p>
            </div>
        </div>

        <!-- FOOTER BTN -->
        <div class="footer-btns" style="position: fixed; bottom: 30px; left: 50%; transform: translateX(-50%); display: flex; gap: 15px; z-index: 1000;">
          <form id="startForm" method="post" onsubmit="return false;" style="display:inline-block;">
            <input type="hidden" name="action" value="work_status" />
            <input type="hidden" name="work_action" value="<?php echo $isOnline ? 'stop' : 'start'; ?>" />
            <button type="button" class="circle-btn start-btn" id="powerButton" 
                    <?php if (!$canWork) echo 'disabled'; ?>
                    style="width: 60px; height: 60px; border-radius: 50%; border: <?php echo $isOnline ? '3px solid #22c55e' : 'none'; ?>; background: white; box-shadow: 0 4px 15px rgba(0,0,0,0.2); cursor: <?php echo $canWork ? 'pointer' : 'not-allowed'; ?>; display: flex; align-items: center; justify-content: center; opacity: <?php echo $canWork ? '1' : '0.5'; ?>;">
              <img src="../../assets/icon/icon-power.png" alt="Power" class="btn-icon" id="powerIcon" style="width: 24px; height: 24px;">
            </button>
          </form>
          
          <!-- Tombol Map removed as per detail page change logic, or keep simple toggle -->
          <button class="circle-btn map-btn" onclick="location.reload()" 
                  style="width: 60px; height: 60px; border-radius: 50%; border: none; background: white; box-shadow: 0 4px 15px rgba(0,0,0,0.2); cursor: pointer; display: flex; align-items: center; justify-content: center;">
             <i class="fas fa-sync-alt" style="font-size: 20px; color: #3b82f6;"></i>
          </button>
        </div>
    </main>
</div>

</script>

<script>
    // START/STOP Logic (4-Status System)
    const startForm = document.getElementById('startForm');
    const powerButton = document.getElementById('powerButton');
    const powerIcon = document.getElementById('powerIcon');
    
    // Current status from PHP
    let currentStatus = '<?php echo $currentStatus; ?>';
    let isOnline = currentStatus === 'online';
    let canWork = <?php echo $canWork ? 'true' : 'false'; ?>;

    powerButton.addEventListener('click', function(e) {
        e.preventDefault();
        if (!canWork) {
            alert('Status Anda tidak memungkinkan untuk memulai pekerjaan. Hubungi Admin.');
            return;
        }
        
        const actionInput = startForm.querySelector('input[name="work_action"]');
        const nextAction = actionInput.value;
        
        if (nextAction === 'stop') {
            // Show confirmation popup before stopping
            document.getElementById('popup-stop').style.display = 'flex';
        } else {
            // Start directly
            processStatusUpdate('start');
        }
    });

    document.getElementById('btnStopCancel').addEventListener('click', function() {
        document.getElementById('popup-stop').style.display = 'none';
    });
    
    document.getElementById('btnStopConfirm').addEventListener('click', function() {
        document.getElementById('popup-stop').style.display = 'none';
        processStatusUpdate('stop');
    });

    async function processStatusUpdate(workAction) {
        const body = new URLSearchParams();
        body.set('action', 'work_status');
        body.set('work_action', workAction);
        
        try {
            const response = await fetch('monitoring.php', {
                method: 'POST',
                headers: { 
                    'Accept': 'application/json',
                    'Content-Type': 'application/x-www-form-urlencoded'
                },
                body: body
            });
            const result = await response.json();
            
            if (result.success) {
                const newStatus = result.data.status;
                updateUIForStatus(newStatus);
                if (result.message) {
                    console.log(result.message);
                }
            } else {
                alert(result.message || 'Gagal mengubah status');
            }
        } catch (error) {
            console.error(error);
            alert('Gagal mengubah status');
        }
    }

    function updateUIForStatus(newStatus) {
        currentStatus = newStatus;
        isOnline = (newStatus === 'online');
        
        const actionInput = startForm.querySelector('input[name="work_action"]');
        
        if (isOnline) {
            powerButton.style.border = "3px solid #22c55e"; // Green border = Online
            actionInput.value = 'stop';
        } else {
            powerButton.style.border = "none";
            actionInput.value = 'start';
        }
    }
    
    // Initial UI State is set via PHP in the HTML
    
    // ... existing JS ...
    function loadMembers() {

        fetch('../../api/gps/get_location.php')
        .then(response => response.json())
        .then(data => {
            if (data.success && data.locations) {
                renderMembers(data.locations);
                updateStats(data.summary);
            }
        })
        .catch(err => console.error('Error fetching locations:', err));
    }

    function renderMembers(locations) {
        const container = document.getElementById('member-container');
        let html = '';
        
        // Merge all arrays
        const all = [...locations.online, ...locations.recent, ...locations.offline];
        
        if (all.length === 0) {
            container.innerHTML = '<div style="grid-column:1/-1; text-align:center; padding:40px; color:#94a3b8;">Belum ada anggota yang aktif membagikan lokasi.</div>';
            return;
        }
        
        all.forEach(u => {
            const initials = u.name.split(' ').map(n=>n[0]).join('').substring(0,2).toUpperCase();
            
            let statusClass = 'status-offline';
            let statusText = 'OFFLINE';
            
            if (u.online_status === 'online') {
                statusClass = 'status-online';
                statusText = 'LIVE';
            } else if (u.online_status === 'recent') {
                statusClass = 'status-recent';
                statusText = 'BARU SAJA';
            }
            
            let lastUpdate = '-';
            if (u.last_location_update) {
                const d = new Date(u.last_location_update);
                lastUpdate = d.getHours().toString().padStart(2,'0') + ':' + d.getMinutes().toString().padStart(2,'0');
            }
            
            html += `
            <div class="member-card">
                <span class="mc-status ${statusClass}">${statusText}</span>
                <div class="mc-header">
                    <div class="mc-avatar">${initials}</div>
                    <div class="mc-info">
                        <h4>${u.name}</h4>
                        <div style="display:flex; align-items:center; gap:8px; margin-top:4px;">
                             <span class="role-badge role-petugas">PTG</span>
                            <p style="margin:0;">${u.nip}</p>
                        </div>
                    </div>
                </div>
                
                <div class="mc-location">
                    <!-- Removed Update Time & Zone Info as per request ("hanya coordinat aja") -->
                    ${u.latitude ? 
                        `<div style="font-family:monospace; color:#64748b;">${parseFloat(u.latitude).toFixed(6)}, ${parseFloat(u.longitude).toFixed(6)}</div>` : 
                        '<div>Belum ada lokasi</div>'}
                </div>
                
                <div class="mc-actions">
                    ${u.latitude ? `
                    <a href="https://www.google.com/maps?q=${u.latitude},${u.longitude}" target="_blank" class="btn-map">
                        <i class="fas fa-map-marked-alt"></i> Pantau
                    </a>` : 
                    `<button disabled class="btn-map" style="background:#e2e8f0; color:#94a3b8; cursor:not-allowed;">
                        <i class="fas fa-map-marker-slash"></i> Lokasi
                    </button>`}
                </div>
            </div>`;
        });
        
        container.innerHTML = html;
    }
    
    function updateStats(summary) {
        document.getElementById('count-online').innerText = summary.online;
        
        // Calculate offline manually properly
        // summary.total includes everyone. summary.online are those active.
        const offline = summary.total - summary.online;
        document.getElementById('count-offline').innerText = offline;
        
        document.getElementById('count-total').innerText = summary.total;
    }

    // Initial load
    loadMembers();

    // Poll every 10 seconds
    setInterval(loadMembers, 10000);
</script>

<!-- Inject Live Tracking for Supervisor too (to track themselves) -->
<script src="../../petugas/js/live-tracking.js"></script>

</body>
</html>
