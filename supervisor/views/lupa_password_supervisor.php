<?php
session_start();
require_once '../../config/database.php';
require_once '../../helpers/session.php';
require_once '../../supervisor/controllers/SupervisorController.php';

// Hardening
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'supervisor') {
    header('Location: /auth/login.php');
    exit;
}
require_login();
require_role('supervisor');
require_active_user();

$supName = $_SESSION['name'] ?? 'Supervisor';
$supNip  = $_SESSION['nip']  ?? 'SUPV001';

$resets = [];
$error = '';

try {
    $controller = new SupervisorController();
    
    // Kita gunakan method getResetRequests tapi tanpa limit (atau limit besar)
    // Di controller saat ini query-nya: "... ORDER BY ... created_at DESC" tanpa LIMIT eksplisit di SQL
    // tapi dashboard mungkin hanya menampilkan sebagian. Di sini kita tampilkan semua.
    $resetRes = $controller->getResetRequests();
    if (!empty($resetRes['success'])) {
        $rows = $resetRes['requests'] ?? [];
        foreach ($rows as $r) {
            $st = (string)$r['status'];
            if ($st === 'pending') {
                $statusLabel = 'Menunggu persetujuan supervisor';
            } elseif ($st === 'approved') {
                $statusLabel = 'Sudah di-reset dan dikirim ke petugas';
            } elseif ($st === 'expired') {
                $statusLabel = 'Reset password sudah kadaluarsa';
            } else {
                $statusLabel = ucfirst($st);
            }

            $resets[] = [
                'id'           => (int)$r['id'],
                'name'         => (string)($r['name'] ?? ''),
                'nip'          => (string)($r['nip'] ?? ''),
                'status'       => $st,
                'status_label' => $statusLabel,
                'created_at'   => $r['created_at'],
                'formatted_date' => $r['created_at_formatted'] ?? date('d/m/Y H:i', strtotime($r['created_at']))
            ];
        }
    } else {
        $error = $resetRes['error'] ?? 'Gagal mengambil data.';
    }

} catch (Throwable $e) {
    $error = $e->getMessage();
}

// Helper function
if (!function_exists('safeText')) {
    function safeText($text) {
        return htmlspecialchars($text ?? '', ENT_QUOTES, 'UTF-8');
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Permintaan Reset Password - Supervisor</title>
    <link rel="stylesheet" href="../css/dashboard.css">
    <style>
        .list-container {
            background: #fff;
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            margin-top: 20px;
        }
        .reset-table {
            width: 100%;
            border-collapse: collapse;
        }
        .reset-table th, .reset-table td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #eee;
        }
        .reset-table th {
            font-weight: 700;
            color: #0e5d34;
        }
        .status-badge {
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: 600;
        }
        .status-pending { background: #fff3cd; color: #856404; }
        .status-approved { background: #d4edda; color: #155724; }
        .status-expired { background: #f8d7da; color: #721c24; }
        
        .action-btn {
            background: #0e5d34;
            color: white;
            border: none;
            padding: 6px 12px;
            border-radius: 4px;
            cursor: pointer;
            font-size: 12px;
        }
        .action-btn:disabled {
            background: #ccc;
            cursor: not-allowed;
        }
        .popup-overlay {
            position: fixed; top: 0; left: 0; width: 100%; height: 100%;
            background: rgba(0,0,0,0.5);
            display: none; justify-content: center; align-items: center; z-index: 9999;
        }
        .popup-box {
            background: white; padding: 25px; border-radius: 12px; width: 90%; max-width: 400px;
            text-align: center;
        }
    </style>
</head>
<body>
<div class="layout">

  <?php require_once '../../supervisor/components/sidebar.php'; ?>

    <main class="content">
        <header class="header">
            <div class="header-left">
                <h2>Permintaan Lupa Password</h2>
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

        <div class="list-container">
            <h3>Daftar Permintaan Reset</h3>
            <?php if ($error): ?>
                <div style="color:red; margin-bottom:10px;"><?php echo safeText($error); ?></div>
            <?php endif; ?>
            
            <table class="reset-table">
                <thead>
                    <tr>
                        <th>Waktu</th>
                        <th>Nama</th>
                        <th>NIP</th>
                        <th>Status</th>
                        <th>Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($resets)): ?>
                        <tr><td colspan="5" style="text-align:center;">Tidak ada data.</td></tr>
                    <?php else: ?>
                        <?php foreach ($resets as $r): ?>
                            <tr>
                                <td><?php echo safeText($r['formatted_date']); ?></td>
                                <td><?php echo safeText($r['name']); ?></td>
                                <td><?php echo safeText($r['nip']); ?></td>
                                <td>
                                    <span class="status-badge status-<?php echo safeText($r['status']); ?>">
                                        <?php echo safeText($r['status_label']); ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if ($r['status'] === 'pending'): ?>
                                        <button class="action-btn" onclick="processReset(<?php echo $r['id']; ?>, '<?php echo safeText($r['name']); ?>')">
                                            Proses
                                        </button>
                                    <?php else: ?>
                                        -
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </main>
</div>

<!-- POPUP RESET CONFIRMATION -->
<div class="popup-overlay" id="popupProcess">
    <div class="popup-box">
        <h3>Proses Reset Password</h3>
        <p>Anda akan mereset password untuk <b id="pName"></b>.</p>
        <p>Password baru akan digenerate secara otomatis.</p>
        <div style="margin-top:20px; display:flex; gap:10px; justify-content:center;">
            <button class="action-btn" style="background:#ccc; color:#333;" onclick="closePopup()">Batal</button>
            <button class="action-btn" id="btnConfirm">Ya, Reset</button>
        </div>
    </div>
</div>

<!-- POPUP RESULT -->
<div class="popup-overlay" id="popupResult">
    <div class="popup-box">
        <h3 id="resTitle">Berhasil</h3>
        <p id="resMsg"></p>
        <div id="newPassBox" style="background:#f0f0f0; padding:10px; margin:10px 0; font-weight:bold; font-size:18px; display:none;"></div>
        <button class="action-btn" onclick="location.reload()">Tutup</button>
    </div>
</div>

<script>
let currentId = null;

function processReset(id, name) {
    currentId = id;
    document.getElementById('pName').textContent = name;
    document.getElementById('popupProcess').style.display = 'flex';
}

function closePopup() {
    document.getElementById('popupProcess').style.display = 'none';
}

document.getElementById('btnConfirm').addEventListener('click', function() {
    if(!currentId) return;
    
    // Disable button to prevent double click
    const btn = document.getElementById('btnConfirm');
    btn.disabled = true;
    btn.textContent = 'Memproses...';

    fetch('../actions/approve_reset.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json'
        },
        body: JSON.stringify({ id: currentId })
    })
    .then(response => response.json())
    .then(data => {
        closePopup();
        btn.disabled = false;
        btn.textContent = 'Ya, Setujui';

        if (data.success) {
            document.getElementById('resTitle').textContent = 'Permintaan Disetujui';
            document.getElementById('resMsg').innerHTML = 
                'Permintaan reset password untuk <b>' + (data.name || data.username) + '</b> telah disetujui.<br><br>' +
                'Petugas dapat membuat password baru melalui halaman <b>Cek Status Permintaan</b>.';
            
            // Hide password box since we're not generating passwords anymore
            const box = document.getElementById('newPassBox');
            box.style.display = 'none';
            
            document.getElementById('popupResult').style.display = 'flex';
        } else {
            alert('Gagal: ' + (data.error || 'Terjadi kesalahan'));
        }
    })
    .catch(err => {
        closePopup();
        btn.disabled = false;
        btn.textContent = 'Ya, Setujui';
        alert('Terjadi kesalahan koneksi: ' + err);
    });
});
</script>
</body>
</html>
