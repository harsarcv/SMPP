<?php
session_start();
require_once '../../config/database.php';
require_once '../../helpers/session.php';
enforce_session(['petugas']);

// ========================
// 1. Proteksi akses
// ========================
if (!isset($_SESSION['user_id'])) {
    header('Location: /auth/login.php');
    exit;
}

$role = $_SESSION['role'] ?? '';
if ($role !== 'petugas') {
    // Redirect sesuai role lain
    if ($role === 'admin') {
        header('Location: /admin/views/dashboard.php');
    } elseif ($role === 'supervisor') {
        header('Location: /supervisor/views/dashboard.php');
    } else {
        header('Location: /auth/login.php');
    }
    exit;
}

// ========================
// 2. Helper kecil
// ========================
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

function fmtTime($time)
{
    if (empty($time)) return '';
    // tampil "HH.MM"
    return date('H.i', strtotime($time));
}

// mapping jam ke session enum (pagi/siang/sore/malam)
function sessionFromTime($time)
{
    if (!$time) return 'pagi';
    $h = (int)date('H', strtotime($time));
    if ($h < 11) return 'pagi';
    if ($h < 15) return 'siang';
    if ($h < 19) return 'sore';
    return 'malam';
}

// ========================
// 3. Ambil data user & jadwal hari ini
// ========================
$pdo = Database::getInstance()->getConnection();
require_once '../../petugas/controllers/PetugasController.php';
$petugasController = new PetugasController();

$userId = (int)$_SESSION['user_id'];
$pName  = $_SESSION['name'] ?? '';
$pNip   = $_SESSION['nip'] ?? '';
$pStatus = 'nonaktif';
$lastLocationExists = false;

try {
    $profileRes = $petugasController->getProfileData($userId);
    if (!empty($profileRes['success'])) {
        $u = $profileRes['user'] ?? [];
        $pName   = $u['name']  ?? $pName;
        $pNip    = $u['nip']   ?? $pNip;
        $pStatus = $u['status']?? 'nonaktif';
        
        // DEBUG
        error_log("DEBUG laporan.php: Status user = $pStatus");
    } else {
        session_destroy();
        header('Location: login.php');
        exit;
    }

    // AMBIL JADWAL - GANTI INI
    $jadwalRes = $petugasController->getJadwalHariIni($userId);
    
    if (empty($jadwalRes['success'])) {
        error_log("ERROR laporan.php: Gagal mengambil jadwal - " . ($jadwalRes['error'] ?? 'Unknown'));
        $assignments = [];
    } else {
        $assignments = $jadwalRes['jadwal'] ?? [];
        error_log("DEBUG laporan.php: Jumlah jadwal ditemukan = " . count($assignments));
    }

} catch (Throwable $e) {
    error_log("EXCEPTION laporan.php: " . $e->getMessage());
    $assignments = [];
}

// ========================
// 4. Proses submit laporan
// ========================
$msg = [];
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $result = $petugasController->submitLaporan($userId, $_POST, $_FILES);
    if (!empty($result['success'])) {
        $success = true;
        // Don't show msg here if popup handles it, but let's keep it safe
        // Popup uses JS flag $success
    } else {
        $success = false;
        $errors = $result['errors'] ?? [];
        if (is_array($errors)) {
            $msg = array_values($errors);
        } else {
            $msg = [$result['error'] ?? 'Gagal mengirim laporan'];
        }
    }
}

// ========================
// 5. Label tanggal (untuk tampilan kalau mau dipakai)
// ========================
$dayNames = ['Minggu','Senin','Selasa','Rabu','Kamis','Jumat','Sabtu'];
$dayName = $dayNames[(int)date('w')];
$dateLabel = $dayName . ', ' . date('d/m/Y');

?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Laporan Penugasan | SMPP</title>
  <link rel="stylesheet" href="../css/laporan.css" />
  <link href="https://fonts.googleapis.com/css2?family=Karla:wght@400;500;700;800;900&display=swap" rel="stylesheet">
</head>
<body>

  <!-- POPUP PERINGATAN -->
  <div id="popup-peringatan" class="popup-overlay">
    <div class="popup-box">
      <h2>PERINGATAN!</h2>
      <p>
        Untuk seluruh Petugas PPSU, jika Anda ingin membuat laporan, 
        Anda wajib memberikan foto sebelum tugas dikerjakan 
        dan mengirimkan foto sesudah pekerjaan selesai. Terima kasih.
      </p>
      <button id="btnPeringatan" class="popup-btn">Konfirmasi</button>
    </div>
  </div>

  <!-- POPUP SUKSES -->
  <div id="popup-sukses" class="popup-overlay" style="<?php echo $success ? 'display:flex;' : 'display:none;'; ?>">
    <div class="popup-box">
      <h2>Laporan Sukses</h2>
      <button id="btnSukses" class="popup-btn">Konfirmasi</button>
    </div>
  </div>

  <!-- HALAMAN LAPORAN -->
  <div class="laporan-container">

    <!-- HEADER -->
    <header class="dash-header">
      <div class="header-top" onclick="location.href='profile.php'" role="button" tabindex="0">
        <div class="user-info">
          <h3 class="user-name"><?php echo safeText($pName); ?></h3>
          <p class="user-nip"><?php echo safeText($pNip); ?></p>
        </div>
        <div class="avatar-icon">
          <!-- Ganti inisial dengan icon default sesuai request -->
        <img src="../../assets/icon/icon-avatar.png" alt="Profile" style="width:100%; height:100%; object-fit:cover; border-radius: 50%;">        </div>
      </div>
    </header>

    <section class="judul">
      <div class="judul-content">
        <h2 class="form-title">LAPORAN PENUGASAN</h2>
      </div>
    </section>
    
    <!-- ISI LAPORAN -->
    <section class="laporan-form">

      <?php
        // 4-Status System: Allow online, offline, aktif (backward) - Block nonaktif, cuti
        $canSubmitReport = in_array($pStatus, ['online', 'offline']);
        
        if (!$canSubmitReport) {
            $statusMsg = ($pStatus === 'cuti') 
                ? 'sedang <b>cuti</b>. Laporan tidak dapat dikirim selama cuti.'
                : '<b>nonaktif</b>. Hubungi Admin untuk mengaktifkan akun Anda.';
            echo '<div style="color:#b00020;margin-bottom:10px;font-size:14px;">Status Anda saat ini ' . $statusMsg . '</div>';
        } elseif (empty($assignments)) {
            echo '<div style="color:#b00020;margin-bottom:10px;font-size:14px;">Tidak ada jadwal aktif untuk hari ini.</div>';
        }
        if (!empty($msg)) {
            // Errors will be shown in popup via JS
        }
      ?>

      <form id="laporanForm" method="post" action="" enctype="multipart/form-data">
        <!-- Lokasi sekaligus memilih jadwal -->
        <label>Lokasi :</label>
        <select id="lokasi" name="schedule_id" <?php echo (empty($assignments) || !$canSubmitReport) ? 'disabled' : ''; ?>>
          <option value="">Pilih Lokasi Kerja</option>
          <?php foreach ($assignments as $a): ?>
            <?php
              $lblTime = fmtTime($a['start_time']) . ' - ' . fmtTime($a['end_time']);
              $desc = $a['description'] ?? '-';
            ?>
            <option 
              value="<?php echo (int)$a['id']; ?>"
              data-waktu="<?php echo safeText($lblTime); ?>"
              data-deskripsi="<?php echo safeText($desc); ?>"
            >
              <?php echo safeText($a['zone_name'] . " ($desc)"); ?>
            </option>
          <?php endforeach; ?>
        </select>

        <!-- Waktu hanya display -->
        <label>Waktu :</label>
        <select id="waktu" name="waktu_display" disabled>
          <option>Pilih Waktu Kerja</option>
        </select>
        
        <!-- Deskripsi Pekerjaan Display -->
        <label>Deskripsi Pekerjaan :</label>
        <input type="text" id="deskripsi_display" class="input-readonly" placeholder="Deskripsi tugas..." readonly disabled 
               style="width:100%; padding:12px; margin-bottom:15px; border:1px solid #ddd; border-radius:8px; background:#f9f9f9; color:#555;">

        <label>Sebelum Pekerjaan :</label>
        <div class="upload-box" id="upload-before">
          <img src="../../assets/icon/icon-image.png" alt="upload">
          <p>Tekan Untuk Ambil Gambar</p>
        </div>
        <input type="file" id="photo_before" name="photo_before" accept="image/*" style="display:none" 
               <?php echo (!$canSubmitReport) ? 'disabled' : ''; ?> />

        <label>Setelah Pekerjaan :</label>
        <div class="upload-box" id="upload-after">
          <img src="../../assets/icon/icon-image.png" alt="upload">
          <p>Tekan Untuk Ambil Gambar</p>
        </div>
        <input type="file" id="photo_after" name="photo_after" accept="image/*" style="display:none"
               <?php echo (!$canSubmitReport) ? 'disabled' : ''; ?> />

        <button type="submit" class="btn-kirim"
                <?php echo (empty($assignments) || !$canSubmitReport) ? 'disabled' : ''; ?>>
          Kirim Laporan
        </button>
      </form>
    </section>

    <!-- FOOTER -->
    <footer class="footer-btns">
      <div class="footer-wrapper">
        <button class="circle-btn start-btn" onclick="location.href='dashboard.php'">
          <img src="../../assets/icon/icon-home.png" alt="Dashboard" class="btn-icon">
        </button>
        
        <button class="circle-btn map-btn" onclick="location.href='riwayat.php'">
          <img src="../../assets/icon/icon-riwayat.png" alt="Riwayat" class="btn-icon">
        </button>
      </div>
    </footer>

  </div>

  <script>
    // Popup Peringatan (muncul sekali sampai dikonfirmasi)
    const popupPeringatan = document.getElementById('popup-peringatan');
    const btnPeringatan   = document.getElementById('btnPeringatan');
    const popupSukses     = document.getElementById('popup-sukses');
    const btnSukses       = document.getElementById('btnSukses');

    if (btnPeringatan) {
      btnPeringatan.addEventListener('click', function() {
        popupPeringatan.style.display = 'none';
      });
    }
    if (btnSukses) {
      btnSukses.addEventListener('click', function() {
        popupSukses.style.display = 'none';
        // optional: kembali ke dashboard setelah sukses
        window.location.href = 'dashboard.php';
      });
    }

    // Klik box upload -> trigger input file
    const uploadBefore = document.getElementById('upload-before');
    const uploadAfter  = document.getElementById('upload-after');
    const inputBefore  = document.getElementById('photo_before');
    const inputAfter   = document.getElementById('photo_after');

    // Helper Image Compression
    async function compressAndSet(input, displayEl) {
        if (!input.files || !input.files[0]) return;
        
        const file = input.files[0];
        const originalInfo = `${file.name} (${Math.round(file.size/1024)} KB)`;
        displayEl.innerText = "Memproses (Kompresi)...";
        displayEl.style.color = "#d97706"; // Orange
        
        try {
            const compressed = await new Promise((resolve, reject) => {
                const reader = new FileReader();
                reader.readAsDataURL(file);
                reader.onload = event => {
                    const img = new Image();
                    img.src = event.target.result;
                    img.onload = () => {
                        const MAX_WIDTH = 1200;
                        let width = img.width;
                        let height = img.height;
                        
                        if (width > MAX_WIDTH) {
                            height = Math.round(height * (MAX_WIDTH / width));
                            width = MAX_WIDTH;
                        }
                        
                        const canvas = document.createElement('canvas');
                        canvas.width = width;
                        canvas.height = height;
                        
                        const ctx = canvas.getContext('2d');
                        ctx.drawImage(img, 0, 0, width, height);
                        
                        // Compress to JPEG 0.7
                        canvas.toBlob(blob => {
                            if (!blob) reject("Compression failed");
                            resolve(new File([blob], file.name, { type: 'image/jpeg', lastModified: Date.now() }));
                        }, 'image/jpeg', 0.7);
                    };
                    img.onerror = reject;
                };
                reader.onerror = reject;
            });
            
            // Replace input file
            const dt = new DataTransfer();
            dt.items.add(compressed);
            input.files = dt.files;
            
            displayEl.innerText = compressed.name + " (" + Math.round(compressed.size/1024) + " KB)";
            displayEl.style.color = "#0e5d34"; // Green
            
        } catch (err) {
            console.error(err);
            displayEl.innerText = originalInfo; // Fallback to original name
            displayEl.style.color = "#b00020";
        }
    }

    if (uploadBefore && inputBefore) {
      uploadBefore.addEventListener('click', () => !inputBefore.disabled && inputBefore.click());
      inputBefore.addEventListener('change', () => compressAndSet(inputBefore, uploadBefore.querySelector('p')));
    }

    if (uploadAfter && inputAfter) {
      uploadAfter.addEventListener('click', () => !inputAfter.disabled && inputAfter.click());
      inputAfter.addEventListener('change', () => compressAndSet(inputAfter, uploadAfter.querySelector('p')));
    }

    // Sinkronisasi pilihan lokasi -> tampilkan waktu & deskripsi
    const selectLokasi = document.getElementById('lokasi');
    const selectWaktu  = document.getElementById('waktu');
    const inputDeskripsi = document.getElementById('deskripsi_display');

    if (selectLokasi && selectWaktu) {
      // Trigger change on load if value selected
      if(selectLokasi.value) {
          setTimeout(() => selectLokasi.dispatchEvent(new Event('change')), 100);
      }
      
      selectLokasi.addEventListener('change', function() {
        const opt = this.options[this.selectedIndex];
        const waktu = opt.getAttribute('data-waktu') || 'Pilih Waktu Kerja';
        const desc  = opt.getAttribute('data-deskripsi') || '';

        // reset isi select waktu
        selectWaktu.innerHTML = '';
        const o = document.createElement('option');
        o.textContent = waktu;
        selectWaktu.appendChild(o);
        
        // update deskripsi
        if(inputDeskripsi) {
            inputDeskripsi.value = desc;
        }
      });
    }

    // Trigger Popup Error if exists
    <?php if (!empty($msg)): ?>
    const popupError = document.getElementById('popup-peringatan');
    if (popupError) {
        const pContent = popupError.querySelector('p');
        if (pContent) {
            pContent.innerHTML = '<ul style="text-align:left; margin:0; padding-left:20px; line-height:1.6;">' + 
                <?php foreach ($msg as $m): ?>
                    '<li><?php echo safeText($m); ?></li>' +
                <?php endforeach; ?>
            '</ul>';
        }
        popupError.querySelector('h2').innerText = "DATA BELUM LENGKAP";
        popupError.style.display = 'flex';
    }
    <?php endif; ?>
  </script>

  <script src="../js/live-tracking.js"></script>
</body>
