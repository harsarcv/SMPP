<?php
// debug_jadwal.php - File untuk debugging jadwal
session_start();
require_once '../../config/database.php';

// Cek login
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'petugas') {
    die("<h2 style='color: red'>ERROR: Harus login sebagai petugas</h2>");
}

$userId = $_SESSION['user_id'];
$userName = $_SESSION['username'] ?? 'Unknown';
$pdo = Database::getInstance()->getConnection();

echo "<!DOCTYPE html>
<html>
<head>
    <title>Debug Jadwal</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        .section { margin: 20px 0; padding: 15px; border: 1px solid #ddd; border-radius: 5px; }
        .success { background: #d4edda; color: #155724; }
        .warning { background: #fff3cd; color: #856404; }
        .error { background: #f8d7da; color: #721c24; }
        .info { background: #d1ecf1; color: #0c5460; }
        pre { background: #f8f9fa; padding: 10px; border-radius: 3px; overflow: auto; }
        table { border-collapse: collapse; width: 100%; margin: 10px 0; }
        th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
        th { background: #f2f2f2; }
    </style>
</head>
<body>";

echo "<h1>Debug Jadwal untuk User</h1>";
echo "<p><strong>User ID:</strong> $userId</p>";
echo "<p><strong>Nama:</strong> $userName</p>";
echo "<p><strong>Tanggal Sekarang:</strong> " . date('Y-m-d H:i:s') . "</p>";

// 1. Cek data user
echo "<div class='section info'>
    <h2>1. Data User dari Database</h2>";
$userStmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$userStmt->execute([$userId]);
$user = $userStmt->fetch(PDO::FETCH_ASSOC);

if ($user) {
    echo "<table>
        <tr><th>Field</th><th>Value</th></tr>";
    foreach ($user as $key => $value) {
        echo "<tr><td>$key</td><td>" . htmlspecialchars($value ?? 'NULL') . "</td></tr>";
    }
    echo "</table>";
} else {
    echo "<p class='error'>User tidak ditemukan di database!</p>";
}
echo "</div>";

// 2. Cek semua jadwal di database hari ini
echo "<div class='section info'>
    <h2>2. Semua Jadwal Hari Ini di Database</h2>";
    
$allStmt = $pdo->query("
    SELECT 
        s.id, 
        s.user_id,
        u.name as petugas_name,
        s.work_date,
        s.start_time,
        s.end_time,
        s.status,
        z.name as zone_name,
        s.description,
        s.created_at
    FROM schedules s
    LEFT JOIN users u ON s.user_id = u.id
    LEFT JOIN zones z ON s.zone_id = z.id
    WHERE DATE(s.work_date) = CURDATE()
    ORDER BY s.user_id, s.start_time
");

$allJadwal = $allStmt->fetchAll(PDO::FETCH_ASSOC);
$totalAll = count($allJadwal);

echo "<p>Total semua jadwal hari ini: <strong>$totalAll</strong></p>";

if ($totalAll > 0) {
    echo "<table>
        <tr>
            <th>ID</th>
            <th>User ID</th>
            <th>Nama Petugas</th>
            <th>Tanggal</th>
            <th>Waktu</th>
            <th>Status</th>
            <th>Zona</th>
        </tr>";
    
    foreach ($allJadwal as $row) {
        echo "<tr>";
        echo "<td>" . $row['id'] . "</td>";
        echo "<td>" . $row['user_id'] . "</td>";
        echo "<td>" . htmlspecialchars($row['petugas_name'] ?? '') . "</td>";
        echo "<td>" . $row['work_date'] . "</td>";
        echo "<td>" . $row['start_time'] . " - " . $row['end_time'] . "</td>";
        echo "<td>" . $row['status'] . "</td>";
        echo "<td>" . htmlspecialchars($row['zone_name'] ?? '') . "</td>";
        echo "</tr>";
    }
    echo "</table>";
} else {
    echo "<p class='warning'>Tidak ada jadwal sama sekali di database untuk hari ini!</p>";
}
echo "</div>";

// 3. Cek jadwal untuk user ini
echo "<div class='section info'>
    <h2>3. Jadwal untuk User ID: $userId</h2>";

$userStmt = $pdo->prepare("
    SELECT 
        s.*,
        z.name as zone_name,
        z.rw_code
    FROM schedules s
    LEFT JOIN zones z ON s.zone_id = z.id
    WHERE s.user_id = ?
    AND DATE(s.work_date) = CURDATE()
    AND s.status = 'active'
    ORDER BY s.start_time
");

$userStmt->execute([$userId]);
$userJadwal = $userStmt->fetchAll(PDO::FETCH_ASSOC);
$totalUser = count($userJadwal);

echo "<p>Total jadwal untuk user ini: <strong>$totalUser</strong></p>";

if ($totalUser > 0) {
    echo "<table>
        <tr>
            <th>ID</th>
            <th>Tanggal</th>
            <th>Waktu</th>
            <th>Shift</th>
            <th>Zona</th>
            <th>RW</th>
            <th>Deskripsi</th>
            <th>Status</th>
        </tr>";
    
    foreach ($userJadwal as $row) {
        echo "<tr>";
        echo "<td>" . $row['id'] . "</td>";
        echo "<td>" . $row['work_date'] . "</td>";
        echo "<td>" . $row['start_time'] . " - " . $row['end_time'] . "</td>";
        echo "<td>" . $row['shift_type'] . "</td>";
        echo "<td>" . htmlspecialchars($row['zone_name'] ?? '') . "</td>";
        echo "<td>" . $row['rw_code'] . "</td>";
        echo "<td>" . htmlspecialchars(substr($row['description'] ?? '', 0, 50)) . "...</td>";
        echo "<td>" . $row['status'] . "</td>";
        echo "</tr>";
    }
    echo "</table>";
    
    echo "<h3>Detail Query yang Digunakan:</h3>";
    echo "<pre>SELECT 
    s.*,
    z.name as zone_name,
    z.rw_code
FROM schedules s
LEFT JOIN zones z ON s.zone_id = z.id
WHERE s.user_id = $userId
AND DATE(s.work_date) = CURDATE()
AND s.status = 'active'
ORDER BY s.start_time</pre>";
} else {
    echo "<p class='error'>TIDAK ADA JADWAL untuk user $userId hari ini!</p>";
    
    // Cek apakah ada jadwal dengan tanggal berbeda
    $otherDateStmt = $pdo->prepare("
        SELECT COUNT(*) as total, 
               MIN(work_date) as earliest,
               MAX(work_date) as latest
        FROM schedules 
        WHERE user_id = ? 
        AND status = 'active'
    ");
    $otherDateStmt->execute([$userId]);
    $otherDates = $otherDateStmt->fetch(PDO::FETCH_ASSOC);
    
    if ($otherDates['total'] > 0) {
        echo "<p class='warning'>Catatan: User ini memiliki $otherDates[total] jadwal aktif lainnya</p>";
        echo "<p>Tanggal terawal: $otherDates[earliest]</p>";
        echo "<p>Tanggal terakhir: $otherDates[latest]</p>";
    }
}
echo "</div>";

// 4. Test query dari PetugasController
echo "<div class='section info'>
    <h2>4. Test Query dari PetugasController::getJadwalHariIni()</h2>";

$today = date('Y-m-d');
echo "<p>Checking for date: <strong>$today</strong> (User ID: $userId)</p>";

$testStmt = $pdo->prepare("
    SELECT 
        s.id,
        s.user_id,
        s.zone_id,
        s.work_date,
        s.start_time,
        s.end_time,
        s.shift_type,
        s.description,
        s.status as schedule_status,
        z.name as zone_name,
        z.rw_code,
        (SELECT COUNT(*) FROM reports r WHERE r.schedule_id = s.id AND r.work_date = :today_chk) as sudah_dilaporkan
    FROM schedules s
    JOIN zones z ON s.zone_id = z.id
    WHERE s.user_id = :user_id
    AND s.work_date = :today
    AND s.status = 'active'
    ORDER BY s.start_time ASC
");

try {
    $testStmt->execute([
        ':user_id' => $userId,
        ':today' => $today,
        ':today_chk' => $today
    ]);
    $testResults = $testStmt->fetchAll(PDO::FETCH_ASSOC);

    echo "<p>Hasil query test: <strong>" . count($testResults) . "</strong> baris</p>";

    if (count($testResults) > 0) {
        echo "<pre>" . print_r($testResults, true) . "</pre>";
    } else {
        echo "<p class='error'>Query tidak mengembalikan hasil apapun!</p>";
        
        // Diagnosa lebih lanjut
        echo "<h3>Analisis Masalah:</h3>";
        
        // 1. Cek apakah ada jadwal di tabel schedules tanpa JOIN zone
        $check1 = $pdo->prepare("SELECT * FROM schedules WHERE user_id = ? AND work_date = ?");
        $check1->execute([$userId, $today]);
        $res1 = $check1->fetchAll(PDO::FETCH_ASSOC);
        if (count($res1) > 0) {
            echo "<p class='success'>[OK] Jadwal ditemukan di tabel schedules (" . count($res1) . " rows).</p>";
            foreach ($res1 as $r) {
                echo "Found Schedule ID: {$r['id']}, Zone ID: {$r['zone_id']}, Status: {$r['status']}<br>";
                
                // 2. Cek apakah zone_id valid
                $check2 = $pdo->prepare("SELECT * FROM zones WHERE id = ?");
                $check2->execute([$r['zone_id']]);
                $res2 = $check2->fetch(PDO::FETCH_ASSOC);
                if ($res2) {
                    echo "<p class='success'>[OK] Zone ID {$r['zone_id']} ditemukan: {$res2['name']}</p>";
                } else {
                    echo "<p class='error'>[FAIL] Zone ID {$r['zone_id']} TIDAK DITEMUKAN di tabel zones! Ini penyebabnya (JOIN gagal).</p>";
                }
                
                // 3. Cek status
                if ($r['status'] !== 'active') {
                    echo "<p class='error'>[FAIL] Status jadwal bukan 'active' (Found: '{$r['status']}').</p>";
                } else {
                    echo "<p class='success'>[OK] Status jadwal 'active'.</p>";
                }
            }
        } else {
            echo "<p class='error'>[FAIL] Tidak ada baris di tabel schedules untuk User ID $userId dan Tanggal $today.</p>";
            
            // Cek user id lain?
            $check3 = $pdo->prepare("SELECT user_id FROM schedules WHERE work_date = ? LIMIT 5");
            $check3->execute([$today]);
            $res3 = $check3->fetchAll(PDO::FETCH_ASSOC);
            if ($res3) {
                echo "<p>Jadwal hari ini ada untuk User ID: ";
                foreach($res3 as $row) echo $row['user_id'] . ", ";
                echo "</p>";
            } else {
                echo "<p>Tidak ada jadwal sama sekali untuk siapapun hari ini.</p>";
            }
        }
    }
} catch (Exception $e) {
    echo "<p class='error'>Error executing query: " . $e->getMessage() . "</p>";
}
echo "</div>";

// 5. Cek struktur tabel
echo "<div class='section info'>
    <h2>5. Struktur Tabel Schedules</h2>";
$structure = $pdo->query("DESCRIBE schedules")->fetchAll(PDO::FETCH_ASSOC);
echo "<table>
    <tr>
        <th>Field</th>
        <th>Type</th>
        <th>Null</th>
        <th>Key</th>
        <th>Default</th>
        <th>Extra</th>
    </tr>";
foreach ($structure as $field) {
    echo "<tr>";
    echo "<td>" . $field['Field'] . "</td>";
    echo "<td>" . $field['Type'] . "</td>";
    echo "<td>" . $field['Null'] . "</td>";
    echo "<td>" . $field['Key'] . "</td>";
    echo "<td>" . $field['Default'] . "</td>";
    echo "<td>" . $field['Extra'] . "</td>";
    echo "</tr>";
}
echo "</table>";
echo "</div>";

// 6. Cek contoh data di tabel schedules
echo "<div class='section info'>
    <h2>6. 5 Data Terbaru di Tabel Schedules</h2>";
$sample = $pdo->query("
    SELECT s.*, u.name as petugas_name, z.name as zone_name 
    FROM schedules s
    LEFT JOIN users u ON s.user_id = u.id
    LEFT JOIN zones z ON s.zone_id = z.id
    ORDER BY s.created_at DESC 
    LIMIT 5
")->fetchAll(PDO::FETCH_ASSOC);

if (count($sample) > 0) {
    echo "<table>
        <tr>
            <th>ID</th>
            <th>User</th>
            <th>Tanggal</th>
            <th>Waktu</th>
            <th>Zona</th>
            <th>Status</th>
            <th>Dibuat</th>
        </tr>";
    foreach ($sample as $row) {
        echo "<tr>";
        echo "<td>" . $row['id'] . "</td>";
        echo "<td>" . htmlspecialchars($row['petugas_name'] ?? 'User ' . $row['user_id']) . "</td>";
        echo "<td>" . $row['work_date'] . "</td>";
        echo "<td>" . $row['start_time'] . " - " . $row['end_time'] . "</td>";
        echo "<td>" . htmlspecialchars($row['zone_name'] ?? '') . "</td>";
        echo "<td>" . $row['status'] . "</td>";
        echo "<td>" . $row['created_at'] . "</td>";
        echo "</tr>";
    }
    echo "</table>";
}
echo "</div>";

// 7. Informasi server dan database
echo "<div class='section info'>
    <h2>7. Informasi Server & Database</h2>";
    
echo "<p><strong>Tanggal database (CURDATE()):</strong> ";
$dateStmt = $pdo->query("SELECT CURDATE() as db_date, NOW() as db_now");
$dateResult = $dateStmt->fetch(PDO::FETCH_ASSOC);
echo $dateResult['db_date'] . " (NOW: " . $dateResult['db_now'] . ")</p>";

echo "<p><strong>Timezone database:</strong> ";
$tzStmt = $pdo->query("SELECT @@global.time_zone as global_tz, @@session.time_zone as session_tz");
$tzResult = $tzStmt->fetch(PDO::FETCH_ASSOC);
echo "Global: " . $tzResult['global_tz'] . ", Session: " . $tzResult['session_tz'] . "</p>";

echo "<p><strong>Timezone PHP:</strong> " . date_default_timezone_get() . "</p>";

echo "<p><strong>PHP Date:</strong> " . date('Y-m-d H:i:s') . "</p>";

echo "</div>";

// 8. Tombol aksi
echo "<div class='section'>
    <h2>8. Aksi</h2>
    <p>
        <a href='dashboard.php' style='padding: 10px 15px; background: #007bff; color: white; text-decoration: none; border-radius: 3px;'>Kembali ke Dashboard</a>
        <button onclick='location.reload()' style='padding: 10px 15px; background: #28a745; color: white; border: none; border-radius: 3px; cursor: pointer;'>Refresh Halaman</button>
    </p>
</div>";

echo "</body></html>";
?>