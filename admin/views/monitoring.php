<?php
session_start();
require_once '../../config/database.php';
require_once '../../helpers/session.php';

// Cek role admin
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    header('Location: ' . get_base_url() . '/auth/login.php');
    exit;
}

$admName = $_SESSION['name'] ?? 'Admin';
$admNip  = $_SESSION['nip']  ?? '';

?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Monitoring GPS - Admin</title>
    <link rel="stylesheet" href="../css/dashboard.css" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        /* .layout and .content specific styles removed to use dashboard.css defaults */
        
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
        .btn-map:hover { background: #2563eb; }
        
        .loading {
            text-align: center;
            padding: 40px;
            color: #94a3b8;
        }

        .stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 15px;
            margin-bottom: 25px;
        }
        .stat-card {
            background: white;
            padding: 15px;
            border-radius: 12px;
            text-align: center;
            box-shadow: 0 2px 10px rgba(0,0,0,0.03);
        }
        .stat-card .num { font-size: 24px; font-weight: 700; color: #1e293b; margin-bottom: 5px; }
        .stat-card .label { font-size: 13px; color: #64748b; }
    </style>
</head>
<body>

<div class="app">
    <?php $activePage = 'monitoring'; ?>
    <?php require_once '../../admin/components/sidebar.php'; ?>

    <main class="main">
        <header class="header">
            <div class="header-left">
                <h2>Monitoring (All Staff)</h2>
            </div>
            <div class="header-right">
                <div class="profile">
                    <div class="profile-text">
                        <div class="profile-name"><?php echo safeText($admName); ?></div>
                        <div class="profile-id"><?php echo safeText($admNip); ?></div>
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
                <div class="label">Total Terdaftar</div>
            </div>
        </section>

        <div id="member-container" class="monitoring-grid">
            <div class="loading">
                <i class="fas fa-circle-notch fa-spin fa-2x"></i>
                <p style="margin-top:10px;">Memuat data lokasi...</p>
            </div>
        </div>
    </main>
</div>

<script>
    function loadMembers() {
        // Since we are Admin, the API get_location.php will automagically return ALL 'petugas' + 'supervisor'
        // thanks to our recent editing.
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
        
        const all = [...locations.online, ...locations.recent, ...locations.offline];
        
        if (all.length === 0) {
            container.innerHTML = '<div style="grid-column:1/-1; text-align:center; padding:40px; color:#94a3b8;">Belum ada staff yang aktif membagikan lokasi.</div>';
            return;
        }
        
        all.forEach(u => {
            const initials = (u.name || 'User').split(' ').map(n=>n[0]).join('').substring(0,2).toUpperCase();
            
            let statusClass = 'status-offline';
            let statusText = 'OFFLINE';
            
            if (u.online_status === 'online') {
                statusClass = 'status-online';
                statusText = 'LIVE';
            } else if (u.online_status === 'recent') {
                statusClass = 'status-recent';
                statusText = 'BARU SAJA';
            }
            
            // Add Badge for Role
            const roleBadge = u.role === 'supervisor' 
                ? '<span class="role-badge role-supervisor">SPV</span>'
                : '<span class="role-badge role-petugas">PTG</span>';
            
            html += `
            <div class="member-card">
                <span class="mc-status ${statusClass}">${statusText}</span>
                <div class="mc-header">
                    <div class="mc-avatar">${initials}</div>
                    <div class="mc-info">
                        <h4>${u.name}</h4>
                        <div style="display:flex; align-items:center; gap:8px; margin-top:4px;">
                            ${roleBadge}
                            <p style="margin:0;">${u.nip || '-'}</p>
                        </div>
                    </div>
                </div>
                
                <div class="mc-location">
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
        document.getElementById('count-total').innerText = summary.total;
        
        const offline = summary.total - summary.online;
        document.getElementById('count-offline').innerText = offline;
    }

    // Initial load
    loadMembers();

    // Poll every 10 seconds
    setInterval(loadMembers, 10000);
</script>

</body>
</html>
