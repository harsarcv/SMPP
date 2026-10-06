<?php
require_once '../../config/database.php';
require_once '../../helpers/geometry.php';

class PetugasController {
    private $pdo;
    
    public function __construct() {
        $this->pdo = Database::getInstance()->getConnection();
    }
    
    /**
     * Update work status (4-Status System)
     * @param int $user_id
     * @param string $action - 'start' or 'stop' (or legacy 'aktif'/'nonaktif')
     */
    public function updateWorkStatus($user_id, $action) {
        try {
            // Get current status
            $checkStmt = $this->pdo->prepare("SELECT id, status FROM users WHERE id = :user_id");
            $checkStmt->execute([':user_id' => $user_id]);
            $user = $checkStmt->fetch();

            if (!$user) {
                return ['success' => false, 'error' => 'User tidak ditemukan'];
            }
            
            $currentStatus = $user['status'];
            
            // Handle legacy 'aktif'/'nonaktif' params (backward compatibility)
            if ($action === 'aktif') $action = 'start';
            if ($action === 'nonaktif') $action = 'stop';
            
            // Validate action based on current status
            if ($action === 'start') {
                if ($currentStatus === 'nonaktif') {
                    return ['success' => false, 'error' => 'Akun Anda nonaktif. Hubungi Admin untuk aktivasi.'];
                }
                if ($currentStatus === 'cuti') {
                    return ['success' => false, 'error' => 'Anda sedang cuti. Hubungi Admin untuk mengakhiri cuti.'];
                }
                if ($currentStatus === 'online') {
                    return ['success' => true, 'status' => 'online', 'message' => 'Anda sudah online'];
                }
                $newStatus = 'online';
            } elseif ($action === 'stop') {
                if ($currentStatus !== 'online') {
                    return ['success' => true, 'status' => $currentStatus, 'message' => 'Status tidak berubah'];
                }
                $newStatus = 'offline';
            } else {
                return ['success' => false, 'error' => 'Aksi tidak valid'];
            }
            
            $this->pdo->beginTransaction();
            
            // Update status
            $stmt = $this->pdo->prepare("
                UPDATE users 
                SET status = :status,
                    last_status_change = NOW(),
                    updated_at = NOW()
                WHERE id = :user_id
            ");
            
            $stmt->execute([
                ':status' => $newStatus,
                ':user_id' => $user_id
            ]);
            
            // If going online, update last login
            if ($newStatus === 'online') {
                $loginStmt = $this->pdo->prepare("UPDATE users SET last_login = NOW() WHERE id = :user_id");
                $loginStmt->execute([':user_id' => $user_id]);
            }
            
            // Log activity
            $activity = ($newStatus === 'online') ? 'Memulai pekerjaan (Online)' : 'Menghentikan pekerjaan (Offline)';
            $this->logActivity($user_id, $activity);
            
            $this->pdo->commit();
            
            return ['success' => true, 'status' => $newStatus];
            
        } catch (Exception $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            error_log("Update Work Status Error: " . $e->getMessage());
            return ['success' => false, 'error' => 'Terjadi kesalahan sistem'];
        }
    }
    
    public function updateLocation($user_id, $latitude, $longitude) {
        try {
            $stmt = $this->pdo->prepare("
                UPDATE users 
                SET latitude = :lat,
                    longitude = :lng,
                    last_location_update = NOW()
                WHERE id = :user_id
            ");
            
            $stmt->execute([
                ':lat' => $latitude,
                ':lng' => $longitude,
                ':user_id' => $user_id
            ]);
            
            // Simpan juga ke route points jika sedang online
            $userStmt = $this->pdo->prepare("SELECT status FROM users WHERE id = :user_id");
            $userStmt->execute([':user_id' => $user_id]);
            $user = $userStmt->fetch();
            
            if ($user['status'] === 'online') {
                $today = date('Y-m-d');
                // Cek apakah ada laporan aktif untuk hari ini
                $reportStmt = $this->pdo->prepare("
                    SELECT id FROM reports 
                    WHERE user_id = :user_id 
                    AND work_date = :today
                    AND status = 'pending'
                    ORDER BY created_at DESC
                    LIMIT 1
                ");
                
                $reportStmt->execute([
                    ':user_id' => $user_id,
                    ':today' => $today
                ]);
                $report = $reportStmt->fetch();
                
                if ($report) {
                    // Simpan route point
                    $routeStmt = $this->pdo->prepare("
                        INSERT INTO report_route_points 
                        (report_id, seq, point_type, latitude, longitude, recorded_at)
                        VALUES (
                            :report_id,
                            COALESCE((SELECT COALESCE(MAX(seq),0) FROM report_route_points WHERE report_id = :report_id2), 0) + 1,
                            'other',
                            :lat,
                            :lng,
                            NOW()
                        )
                    ");
                    
                    $routeStmt->execute([
                        ':report_id' => $report['id'],
                        ':report_id2' => $report['id'],
                        ':lat' => $latitude,
                        ':lng' => $longitude
                    ]);
                }
            }
            
            return ['success' => true];
            
        } catch (Exception $e) {
            error_log("Update Location Error: " . $e->getMessage());
            return ['success' => false, 'error' => 'Gagal update lokasi'];
        }
    }
    
    public function getJadwalHariIni($user_id) {
        try {
            $today = date('Y-m-d');
            $stmt = $this->pdo->prepare("
                SELECT 
                    s.id,
                    z.name as zone_name,
                    z.rw_code,
                    s.description,
                    DATE_FORMAT(s.shift_start, '%H:%i') as start_time,
                    DATE_FORMAT(s.shift_end, '%H:%i') as end_time,
                    (SELECT COUNT(*) FROM reports r WHERE r.schedule_id = s.id AND r.work_date = :today_chk) as sudah_dilaporkan
                FROM schedules s
                JOIN zones z ON s.zone_id = z.id
                WHERE s.user_id = :user_id
                AND s.work_date = :today
                AND NOT EXISTS (
                    SELECT 1 FROM reports r 
                    WHERE r.schedule_id = s.id 
                    AND r.work_date = :today_chk2 
                    AND r.status = 'approved'
                )
                ORDER BY s.shift_start ASC
            ");
            
            $stmt->execute([
                ':user_id' => $user_id,
                ':today' => $today,
                ':today_chk' => $today,
                ':today_chk2' => $today
            ]);
            $jadwal = $stmt->fetchAll();
            
            return ['success' => true, 'jadwal' => $jadwal];
            
        } catch (Exception $e) {
            error_log("Get Jadwal Hari Ini Error: " . $e->getMessage());
            return ['success' => false, 'error' => 'Gagal mengambil jadwal'];
        }
    }
    
    public function submitLaporan($user_id, $data, $files) {
        // Detect POST Content-Length exceeding post_max_size
        if (empty($data) && empty($files) && isset($_SERVER['CONTENT_LENGTH']) && $_SERVER['CONTENT_LENGTH'] > 0) {
            $max = ini_get('post_max_size');
            return ['success' => false, 'error' => "Ukuran file foto terlalu besar (Max: $max). Silakan kompres foto atau upload satu per satu."];
        }

        try {
            // Validasi data
            $errors = $this->validateLaporanData($data, $files);
            if (!empty($errors)) {
                return ['success' => false, 'errors' => $errors];
            }
            
            $today = date('Y-m-d');
            
            // Cek status petugas
            $userStmt = $this->pdo->prepare("SELECT status, latitude, longitude FROM users WHERE id = :user_id");
            $userStmt->execute([':user_id' => $user_id]);
            $user = $userStmt->fetch();
            
            if ($user['status'] !== 'online') {
                return ['success' => false, 'errors' => ['status' => 'Status Anda belum online. Tekan tombol Start terlebih dahulu.']];
            }
            
            if (empty($user['latitude']) || empty($user['longitude'])) {
                return ['success' => false, 'errors' => ['location' => 'Lokasi GPS belum terbaca.']];
            }
            
            // Cek jadwal
            $scheduleStmt = $this->pdo->prepare("
                SELECT s.*, z.id as zone_id, z.name as zone_name
                FROM schedules s
                JOIN zones z ON s.zone_id = z.id
                WHERE s.id = :schedule_id
                AND s.user_id = :user_id
                AND s.work_date = :today
                AND s.status = 'active'
            ");
            
            $scheduleStmt->execute([
                ':schedule_id' => $data['schedule_id'],
                ':user_id' => $user_id,
                ':today' => $today
            ]);
            
            $schedule = $scheduleStmt->fetch();
            
            if (!$schedule) {
                return ['success' => false, 'errors' => ['schedule' => 'Jadwal tidak ditemukan atau sudah tidak aktif']];
            }
            
            // Tentukan sesi kerja
            $session = $this->determineSession($schedule['shift_start']);
            
            // Analisis GPS (PHP)
            $gps_result = $this->analyzeGPS($user['latitude'], $user['longitude'], $schedule['zone_id']);
            
            // ============================================
            // AI-Based Fake GPS Detection
            // ============================================
            require_once __DIR__ . '/../../helpers/AIService.php';
            $aiService = new AIService();
            
            if ($aiService->isAvailable()) {
                // Get previous location from activity_logs or users table
                $prevLocStmt = $this->pdo->prepare("
                    SELECT latitude, longitude, updated_at 
                    FROM users WHERE id = ? AND latitude IS NOT NULL
                ");
                $prevLocStmt->execute([$user_id]);
                $prevLoc = $prevLocStmt->fetch();
                
                if ($prevLoc && $prevLoc['latitude'] && $prevLoc['longitude']) {
                    $timeDiff = time() - strtotime($prevLoc['updated_at']);
                    
                    // Validate GPS with AI
                    $gpsValidation = $aiService->validateGPSLocation(
                        (float)$user['latitude'],
                        (float)$user['longitude'],
                        (float)$prevLoc['latitude'],
                        (float)$prevLoc['longitude'],
                        (float)$timeDiff
                    );
                    
                    // Block if AI detects fake GPS
                    if ($gpsValidation && $gpsValidation['is_suspicious']) {
                        $reasons = implode(', ', $gpsValidation['reasons'] ?? ['Suspicious GPS detected']);
                        return [
                            'success' => false, 
                            'errors' => ['gps' => 'Terdeteksi GPS tidak valid: ' . $reasons]
                        ];
                    }
                }
            }
            // ============================================

            $before_path = $this->uploadPhoto($files['photo_before'], 'before', $user_id);
            $after_path = $this->uploadPhoto($files['photo_after'], 'after', $user_id);
            
            if (!$before_path || !$after_path) {
                return ['success' => false, 'errors' => ['photo' => 'Gagal mengupload foto']];
            }

            // ============================================
            // AI-Based Image Validation using GeminiAI
            // ============================================
            $ai_score = 0;
            $ai_valid = true;
            $ai_description = '';
            
            require_once __DIR__ . '/../../helpers/GeminiAI.php';
            $geminiAI = new GeminiAI();
            
            // Load API key from .env
            $envPath = dirname(dirname(__DIR__)) . '/.env';
            if (file_exists($envPath)) {
                $envContent = file_get_contents($envPath);
                if (preg_match('/GEMINI_API_KEY=(.+)/', $envContent, $matches)) {
                    $apiKey = trim($matches[1]);
                    if (!empty($apiKey)) {
                        $geminiAI->setApiKey($apiKey);
                        
                        // Analyze AFTER photo (the completed work photo)
                        // Pass the description so AI can validate photo matches stated task
                        $afterPhotoPath = dirname(dirname(__DIR__)) . '/public/uploads/' . ltrim($after_path, '/');
                        $taskDescription = $data['description'] ?? '';
                        if (file_exists($afterPhotoPath)) {
                            $aiResult = $geminiAI->analyzeWorkPhoto($afterPhotoPath, $taskDescription);
                            
                            if ($aiResult['success']) {
                                $ai_valid = $aiResult['is_valid_work'] ?? false;
                                $ai_score = (float)($aiResult['confidence'] ?? 0);
                                $ai_description = $aiResult['description'] ?? '';
                                
                                error_log("GeminiAI: Photo validated - Valid: " . ($ai_valid ? 'Yes' : 'No') . ", Score: $ai_score");
                            } else {
                                error_log("GeminiAI: Validation failed - " . ($aiResult['error'] ?? 'Unknown error'));
                                // Don't block submission if AI fails, just log it
                            }
                        }
                    }
                }
            }
            // ============================================

            $this->pdo->beginTransaction();
            
            // Insert laporan
            $stmt = $this->pdo->prepare("
                INSERT INTO reports 
                (user_id, zone_id, schedule_id, work_date, session, 
                 before_photo_path, after_photo_path, latitude, longitude, 
                 gps_result, ai_score, ai_is_valid, status, created_at)
                VALUES 
                (:user_id, :zone_id, :schedule_id, :today_date, :session,
                 :before_path, :after_path, :latitude, :longitude,
                 :gps_result, :ai_score, :ai_is_valid, 'pending', NOW())
            ");
            
            $stmt->execute([
                ':user_id' => $user_id,
                ':zone_id' => $schedule['zone_id'],
                ':schedule_id' => $data['schedule_id'],
                ':today_date' => $today,
                ':session' => $session,
                ':before_path' => $before_path,
                ':after_path' => $after_path,
                ':latitude' => $user['latitude'],
                ':longitude' => $user['longitude'],
                ':gps_result' => $gps_result,
                ':ai_score' => $ai_score,
                ':ai_is_valid' => $ai_valid ? 1 : 0
            ]);
            
            $report_id = (int)$this->pdo->lastInsertId();
            
            // Simpan foto ke report_photos
            $photoStmt = $this->pdo->prepare("
                INSERT INTO report_photos 
                (report_id, photo_type, file_path, sequence_no, uploaded_at, latitude, longitude, ai_description, ai_is_valid_work)
                VALUES 
                (:report_id, :photo_type, :file_path, :sequence_no, NOW(), :latitude, :longitude, :ai_description, :ai_is_valid)
            ");
            
            // Before photo (no AI validation)
            $photoStmt->execute([
                ':report_id' => $report_id,
                ':photo_type' => 'before',
                ':file_path' => $before_path,
                ':sequence_no' => 1,
                ':latitude' => $user['latitude'],
                ':longitude' => $user['longitude'],
                ':ai_description' => null,
                ':ai_is_valid' => null
            ]);
            
            // After photo (with AI validation)
            $photoStmt->execute([
                ':report_id' => $report_id,
                ':photo_type' => 'after',
                ':file_path' => $after_path,
                ':sequence_no' => 2,
                ':latitude' => $user['latitude'],
                ':longitude' => $user['longitude'],
                ':ai_description' => $ai_description,
                ':ai_is_valid' => $ai_valid ? 1 : 0
            ]);
            
            // Simpan start point ke route points
            $routeStmt = $this->pdo->prepare("
                INSERT INTO report_route_points 
                (report_id, seq, point_type, latitude, longitude, recorded_at)
                VALUES 
                (:report_id, 1, 'start', :latitude, :longitude, NOW())
            ");
            
            $routeStmt->execute([
                ':report_id' => $report_id,
                ':latitude' => $user['latitude'],
                ':longitude' => $user['longitude']
            ]);
            
            // Log aktivitas
            $this->logActivity($user_id, "Mengirim laporan pekerjaan di {$schedule['zone_name']}");
            
            $this->pdo->commit();
            
            // KPI will be updated when Admin/Supervisor approves the report
            
            return ['success' => true, 'report_id' => $report_id];
            
        } catch (Exception $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            error_log("Submit Laporan Error: " . $e->getMessage());
            // Debugging: return real error message
            return ['success' => false, 'errors' => ['system' => 'Terjadi kesalahan sistem: ' . $e->getMessage()]];
        }
    }

    public function getJadwalMendatang($user_id) {
        try {
            $today = date('Y-m-d');
            $stmt = $this->pdo->prepare("
                SELECT 
                    s.id,
                    z.name as zone_name,
                    z.rw_code,
                    s.description,
                    s.work_date,
                    DATE_FORMAT(s.shift_start, '%H:%i') as start_time,
                    DATE_FORMAT(s.shift_end, '%H:%i') as end_time
                FROM schedules s
                JOIN zones z ON s.zone_id = z.id
                WHERE s.user_id = :user_id
                AND s.work_date > :today
                AND s.status = 'active'
                ORDER BY s.work_date ASC, s.shift_start ASC
                LIMIT 1
            ");
            
            $stmt->execute([
                ':user_id' => $user_id,
                ':today' => $today
            ]);
            $jadwal = $stmt->fetch();
            
            return ['success' => true, 'jadwal' => $jadwal];
            
        } catch (Exception $e) {
            error_log("Get Jadwal Mendatang Error: " . $e->getMessage());
            return ['success' => false, 'error' => 'Gagal mengambil jadwal mendatang'];
        }
    }
    
    public function getRiwayatLaporan($user_id, $filter = []) {
        try {
            $where = ["r.user_id = :user_id"];
            $params = [':user_id' => $user_id];
            
            // Default: 30 hari terakhir
            $where[] = "r.work_date >= :thirty_days_ago";
            $params[':thirty_days_ago'] = date('Y-m-d', strtotime('-30 days'));
            
            if (!empty($filter['date'])) {
                $where[] = "r.work_date = :date";
                $params[':date'] = $filter['date'];
            }
            
            if (!empty($filter['location'])) {
                $where[] = "z.name LIKE :location";
                $params[':location'] = "%{$filter['location']}%";
            }
            
            $whereClause = "WHERE " . implode(" AND ", $where);
            
            $sql = "
                SELECT 
                    r.id,
                    r.work_date,
                    r.session,
                    r.status,
                    r.gps_result,
                    z.name as lokasi,
                    z.rw_code,
                    r.before_photo_path,
                    r.after_photo_path,
                    DATE_FORMAT(r.created_at, '%d/%m/%Y %H:%i') as waktu_laporan
                FROM reports r
                JOIN zones z ON r.zone_id = z.id
                {$whereClause}
                ORDER BY r.work_date DESC, r.created_at DESC
                LIMIT 50
            ";
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            $riwayat = $stmt->fetchAll();
            
            return ['success' => true, 'riwayat' => $riwayat];
            
        } catch (Exception $e) {
            error_log("Get Riwayat Laporan Error: " . $e->getMessage());
            return ['success' => false, 'error' => 'Gagal mengambil riwayat laporan'];
        }
    }
    
    public function getProfileData($user_id) {
        try {
            // Data user
            $userStmt = $this->pdo->prepare("
                SELECT id, nip, name, username, status, photo_path
                FROM users
                WHERE id = :user_id
            ");
            
            $userStmt->execute([':user_id' => $user_id]);
            $user = $userStmt->fetch();
            
            if (!$user) {
                return ['success' => false, 'error' => 'User tidak ditemukan'];
            }
            
            $today = date('Y-m-d');
            
            // Statistik hari ini
            $statsStmt = $this->pdo->prepare("
                SELECT 
                    (SELECT COUNT(*) FROM schedules WHERE user_id = :user_id AND work_date = :today AND status = 'active') as total_tugas,
                    (SELECT COUNT(*) FROM reports WHERE user_id = :user_id2 AND work_date = :today2) as laporan_hari_ini,
                    (SELECT COUNT(*) FROM reports WHERE user_id = :user_id3 AND status = 'approved') as total_disetujui
            ");
            
            $statsStmt->execute([
                ':user_id' => $user_id,
                ':today' => $today,
                ':user_id2' => $user_id,
                ':today2' => $today,
                ':user_id3' => $user_id
            ]);
            
            $stats = $statsStmt->fetch();
            
            // KPI terbaru
            $kpiStmt = $this->pdo->prepare("
                SELECT presence_rate, completion_rate, punctuality_rate, score_total
                FROM kpi_scores
                WHERE user_id = :user_id
                ORDER BY effective_date DESC
                LIMIT 1
            ");
            
            $kpiStmt->execute([':user_id' => $user_id]);
            $kpi = $kpiStmt->fetch();
            
            return [
                'success' => true,
                'user' => $user,
                'stats' => $stats,
                'kpi' => $kpi ?: [
                    'presence_rate' => 0,
                    'completion_rate' => 0,
                    'punctuality_rate' => 0,
                    'score_total' => 0
                ]
            ];
            
        } catch (Exception $e) {
            error_log("Get Profile Data Error: " . $e->getMessage());
            return ['success' => false, 'error' => 'Gagal mengambil data profil'];
        }
    }
    
    public function requestPasswordReset($user_id) {
        try {
            // Cek apakah sudah ada request pending
            $checkStmt = $this->pdo->prepare("
                SELECT COUNT(*) 
                FROM password_resets 
                WHERE user_id = :user_id 
                AND status = 'pending'
                AND created_at >= CURRENT_DATE - INTERVAL '1 hour'
            ");
            
            $checkStmt->execute([':user_id' => $user_id]);
            
            if ($checkStmt->fetchColumn() > 0) {
                return ['success' => false, 'error' => 'Sudah ada permintaan reset yang sedang diproses'];
            }
            
            // Generate token
            $token = bin2hex(random_bytes(32));
            
            // Insert request
            $stmt = $this->pdo->prepare("
                INSERT INTO password_resets 
                (user_id, request_token, source, status, expires_at, created_at)
                VALUES 
                (:user_id, :token, 'petugas', 'pending', NOW() + INTERVAL '24 hours', NOW())
            ");
            
            $stmt->execute([
                ':user_id' => $user_id,
                ':token' => $token
            ]);
            
            // Log aktivitas
            $this->logActivity($user_id, "Meminta reset password");
            
            return ['success' => true, 'message' => 'Permintaan reset password telah dikirim ke supervisor'];
            
        } catch (Exception $e) {
            error_log("Request Password Reset Error: " . $e->getMessage());
            return ['success' => false, 'error' => 'Gagal mengirim permintaan reset password'];
        }
    }
    
    private function validateLaporanData($data, $files) {
        $errors = [];
        
        if (empty($data['schedule_id'])) {
            $errors['schedule_id'] = 'Pilih lokasi penugasan';
        }
        
        if (!isset($files['photo_before']) || $files['photo_before']['error'] !== UPLOAD_ERR_OK) {
            $err = $files['photo_before']['error'] ?? 4;
            if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) {
                 $errors['photo_before'] = 'Foto Sebelum: Ukuran file melebihi batas server (' . ini_get('upload_max_filesize') . ')';
            } else {
                 $errors['photo_before'] = 'Foto sebelum pekerjaan wajib diupload';
            }
        }
        
        if (!isset($files['photo_after']) || $files['photo_after']['error'] !== UPLOAD_ERR_OK) {
             $err = $files['photo_after']['error'] ?? 4;
             if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) {
                 $errors['photo_after'] = 'Foto Sesudah: Ukuran file melebihi batas server (' . ini_get('upload_max_filesize') . ')';
             } else {
                 $errors['photo_after'] = 'Foto setelah pekerjaan wajib diupload';
             }
        }
        
        // Validasi file
        if (empty($errors)) {
            foreach (['photo_before', 'photo_after'] as $field) {
                $file = $files[$field];
                $allowed = ['jpg', 'jpeg', 'png'];
                $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                
                if (!in_array($ext, $allowed)) {
                    $errors[$field] = 'Format file harus JPG atau PNG';
                }
                
                if ($file['size'] > 5 * 1024 * 1024) {
                    $errors[$field] = 'Ukuran file maksimal 5MB';
                }
            }
        }
        
        return $errors;
    }
    
    private function uploadPhoto($file, $type, $user_id) {
        $uploadDir = __DIR__ . '/../../public/uploads/reports/' . date('Ymd');
        
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0775, true);
        }
        
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $filename = "{$type}_{$user_id}_" . time() . '_' . mt_rand(1000, 9999) . '.jpg';
        $destination = $uploadDir . '/' . $filename;
        
        // Resize dan convert ke JPEG
        $srcImage = null;
        if ($ext === 'jpg' || $ext === 'jpeg') {
            $srcImage = imagecreatefromjpeg($file['tmp_name']);
        } elseif ($ext === 'png') {
            $srcImage = imagecreatefrompng($file['tmp_name']);
        }
        
        if (!$srcImage) {
            return false;
        }
        
        $origW = imagesx($srcImage);
        $origH = imagesy($srcImage);
        
        // Resize maksimal 1200px
        $maxSize = 1200;
        if ($origW > $maxSize || $origH > $maxSize) {
            if ($origW > $origH) {
                $newW = $maxSize;
                $newH = (int)($origH * $maxSize / $origW);
            } else {
                $newH = $maxSize;
                $newW = (int)($origW * $maxSize / $origH);
            }
        } else {
            $newW = $origW;
            $newH = $origH;
        }
        
        $canvas = imagecreatetruecolor($newW, $newH);
        imagecopyresampled($canvas, $srcImage, 0, 0, 0, 0, $newW, $newH, $origW, $origH);
        imagedestroy($srcImage);
        
        if (imagejpeg($canvas, $destination, 85)) {
            imagedestroy($canvas);
            return 'reports/' . date('Ymd') . '/' . $filename;
        }
        
        imagedestroy($canvas);
        return false;
    }
    
    private function determineSession($start_time) {
        $hour = (int)date('H', strtotime($start_time));
        
        if ($hour < 11) return 'pagi';
        if ($hour < 15) return 'siang';
        if ($hour < 19) return 'sore';
        return 'malam';
    }
    
    private function analyzeGPS($latitude, $longitude, $zone_id) {
        // Logika zona dihapus sesuai permintaan
        return 'valid';
    }
    
    private function logActivity($userId, $activity) {
        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO activity_logs (user_id, activity, ip_address)
                VALUES (:user_id, :activity, :ip)
            ");
            
            $stmt->execute([
                ':user_id' => $userId,
                ':activity' => $activity,
                ':ip' => $_SERVER['REMOTE_ADDR']
            ]);
        } catch (Exception $e) {
            error_log("Log Activity Error: " . $e->getMessage());
        }
    }
    
    /**
     * Calculate and save KPI to database using weighted scoring
     * Weights: Kehadiran 65%, Penyelesaian 25%, Ketepatan (AI) 5%
     * Period: 30 days rolling
     */
    public function updateKPI($user_id) {
        try {
            $thirtyDaysAgo = date('Y-m-d', strtotime('-30 days'));
            
            // KPI WEIGHT CONSTANTS
            $WEIGHT_KEHADIRAN = 0.65;   // 65%
            $WEIGHT_PENYELESAIAN = 0.25; // 25%
            $WEIGHT_KETEPATAN = 0.05;    // 5% (AI-based)
            $WEIGHT_OTHER = 0.05;        // 5% (reserved for future use)
            
            // 1. KEHADIRAN (Presence) - 65%
            // Based on scheduled work days vs days with submitted reports
            $totalSchedulesStmt = $this->pdo->prepare("
                SELECT COUNT(DISTINCT work_date) as total 
                FROM schedules 
                WHERE user_id = ? AND status = 'active' AND work_date >= ?
            ");
            $totalSchedulesStmt->execute([$user_id, $thirtyDaysAgo]);
            $totalScheduledDays = (int)$totalSchedulesStmt->fetchColumn();
            
            $daysWithReportsStmt = $this->pdo->prepare("
                SELECT COUNT(DISTINCT work_date) as total 
                FROM reports 
                WHERE user_id = ? AND work_date >= ?
            ");
            $daysWithReportsStmt->execute([$user_id, $thirtyDaysAgo]);
            $daysWithReports = (int)$daysWithReportsStmt->fetchColumn();
            
            $presenceRate = $totalScheduledDays > 0 
                ? min(100, round(($daysWithReports / $totalScheduledDays) * 100, 2)) 
                : 70; // Default 70% if no schedules
            
            // 2. PENYELESAIAN (Completion) - 25%
            // Based on approved reports vs total submitted
            $submittedReportsStmt = $this->pdo->prepare("
                SELECT COUNT(*) as total FROM reports WHERE user_id = ? AND work_date >= ?
            ");
            $submittedReportsStmt->execute([$user_id, $thirtyDaysAgo]);
            $submittedReports = (int)$submittedReportsStmt->fetchColumn();
            
            $approvedReportsStmt = $this->pdo->prepare("
                SELECT COUNT(*) as total FROM reports WHERE user_id = ? AND status = 'approved' AND work_date >= ?
            ");
            $approvedReportsStmt->execute([$user_id, $thirtyDaysAgo]);
            $approvedReports = (int)$approvedReportsStmt->fetchColumn();
            
            $completionRate = $submittedReports > 0 
                ? min(100, round(($approvedReports / $submittedReports) * 100, 2)) 
                : 70; // Default 70%
            
            // 3. KETEPATAN (Accuracy) - 5% (AI-based)
            // Based on AI validation of photos - if AI marks photos as invalid work, ketepatan = 0%
            $aiValidReportsStmt = $this->pdo->prepare("
                SELECT 
                    r.id,
                    r.ai_score,
                    (SELECT COUNT(*) FROM report_photos rp WHERE rp.report_id = r.id AND rp.ai_is_valid_work = 1) as valid_photos,
                    (SELECT COUNT(*) FROM report_photos rp WHERE rp.report_id = r.id AND rp.ai_is_valid_work IS NOT NULL) as total_ai_photos
                FROM reports r
                WHERE r.user_id = ? AND r.work_date >= ? AND r.status = 'approved'
            ");
            $aiValidReportsStmt->execute([$user_id, $thirtyDaysAgo]);
            $aiReports = $aiValidReportsStmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Calculate AI stats
            $totalAiChecked = 0;
            $totalAiValid = 0;
            
            foreach ($aiReports as $report) {
                if ($report['total_ai_photos'] > 0) {
                    $totalAiChecked++;
                    if ($report['valid_photos'] >= $report['total_ai_photos']) {
                        $totalAiValid++;
                    }
                }
            }
            
            // ============================================
            // CALL AI SERVICE FOR CALCULATION
            // ============================================
            require_once __DIR__ . '/../../helpers/AIService.php';
            $aiService = new AIService();
            
            $aiResult = null;
            if ($aiService->isAvailable()) {
                $aiResult = $aiService->calculateKPI(
                    $user_id,
                    $totalScheduledDays,
                    $daysWithReports,
                    $submittedReports,
                    $approvedReports,
                    $totalAiValid,
                    $totalAiChecked
                );
            }
            
            if ($aiResult) {
                // Use AI calculation result
                $presenceRate = $aiResult['presence_rate'];
                $completionRate = $aiResult['completion_rate'];
                $punctualityRate = $aiResult['punctuality_rate']; // This represents Accuracy
                $scoreTotal = $aiResult['score_total'];
            } else {
                // Fallback if AI service is down (using same logic locally)
                $presenceRate = $totalScheduledDays > 0 
                    ? min(100, round(($daysWithReports / $totalScheduledDays) * 100, 2)) 
                    : ($daysWithReports > 0 ? 100 : 70); 

                $completionRate = $submittedReports > 0 
                    ? min(100, round(($approvedReports / $submittedReports) * 100, 2)) 
                    : 100;

                if ($totalAiChecked > 0) {
                    $punctualityRate = min(100, round(($totalAiValid / $totalAiChecked) * 100, 2));
                } else {
                    $punctualityRate = 70;
                }
                
                $scoreTotal = round(
                    ($presenceRate * $WEIGHT_KEHADIRAN) +
                    ($completionRate * $WEIGHT_PENYELESAIAN) +
                    ($punctualityRate * $WEIGHT_KETEPATAN),
                    2
                );
            }
            
            // Ensure total doesn't exceed 100
            $scoreTotal = min(100, $scoreTotal);
            
            // Save to database
            $today = date('Y-m-d');
            $checkStmt = $this->pdo->prepare("SELECT id FROM kpi_scores WHERE user_id = ? LIMIT 1");
            $checkStmt->execute([$user_id]);
            $existingKpi = $checkStmt->fetch();
            
            if ($existingKpi) {
                $updateStmt = $this->pdo->prepare("
                    UPDATE kpi_scores SET 
                        presence_rate = ?, completion_rate = ?, punctuality_rate = ?, 
                        score_total = ?, effective_date = ?, calculated_at = NOW()
                    WHERE user_id = ?
                ");
                $updateStmt->execute([$presenceRate, $completionRate, $punctualityRate, $scoreTotal, $today, $user_id]);
            } else {
                $insertStmt = $this->pdo->prepare("
                    INSERT INTO kpi_scores (user_id, presence_rate, completion_rate, punctuality_rate, score_total, effective_date, calculated_at)
                    VALUES (?, ?, ?, ?, ?, ?, NOW())
                ");
                $insertStmt->execute([$user_id, $presenceRate, $completionRate, $punctualityRate, $scoreTotal, $today]);
            }
            
            return true;
            
        } catch (Exception $e) {
            error_log("Update KPI Error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Get Daily Monitoring Data for a specific Petugas (For Admin/Supervisor View)
     */
    public function getMonitoringData($user_id) {
        try {
            $today = date('Y-m-d');
            
            // 1. Get User Info & Last Location
            $userStmt = $this->pdo->prepare("
                SELECT id, name, nip, phone, photo_path, status, latitude, longitude, last_login 
                FROM users 
                WHERE id = ?
            ");
            $userStmt->execute([$user_id]);
            $user = $userStmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$user) return ['success' => false, 'error' => 'User not found'];
            
            // 2. Get Schedules & Merge with Report Status
            $schedStmt = $this->pdo->prepare("
                SELECT 
                    s.id as schedule_id,
                    s.shift_start,
                    s.shift_end,
                    s.description,
                    z.name as zone_name,
                    z.rw_code,
                    r.id as report_id,
                    r.status as report_status,
                    r.ai_score,
                    r.created_at as report_time
                FROM schedules s
                JOIN zones z ON s.zone_id = z.id
                LEFT JOIN reports r ON s.id = r.schedule_id AND r.work_date = ?
                WHERE s.user_id = ? AND s.work_date = ? AND s.status = 'active'
                ORDER BY s.shift_start ASC
            ");
            $schedStmt->execute([$today, $user_id, $today]);
            $schedules = $schedStmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Process status for display
            foreach ($schedules as &$sch) {
                $now = date('H:i:s');
                
                if ($sch['report_id']) {
                    // Report exists
                    $sch['display_status'] = $sch['report_status']; 
                } else {
                    // No report yet
                    if ($now < $sch['shift_start']) {
                        $sch['display_status'] = 'upcoming'; // Belum mulai
                    } elseif ($now >= $sch['shift_start'] && $now <= $sch['shift_end']) {
                        $sch['display_status'] = 'ongoing'; // Sedang berjalan
                    } else {
                        $sch['display_status'] = 'missed'; // Terlewat/Belum lapor
                    }
                }
            }
            
            return [
                'success' => true,
                'user' => $user,
                'tasks' => $schedules
            ];
            
        } catch (Exception $e) {
            error_log("Monitoring Data Error: " . $e->getMessage());
            return ['success' => false, 'error' => 'Database error'];
        }
    }
}
