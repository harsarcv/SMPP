<?php
require_once '../../config/database.php';

class ScheduleController {
    private $pdo;
    
    public function __construct() {
        $this->pdo = Database::getInstance()->getConnection();
    }
    
    /**
     * Membuat jadwal baru
     */
    public function createSchedule($data) {
        try {
            $this->pdo->beginTransaction();
            
            // Validasi data
            $errors = $this->validateScheduleData($data);
            if (!empty($errors)) {
                return ['success' => false, 'errors' => $errors];
            }
            
            // NOTE: Jadwal bisa di-stack (sama waktu, sama RW dibolehkan)
            // Cek conflict hanya untuk informasi, tidak memblokir
            $hasConflict = $this->checkScheduleConflict($data['user_id'], $data['work_date'], $data['start_time'], $data['end_time']);
            // Conflict warning will be returned but schedule is still created
            
            // Cek apakah zona aktif
            if (!$this->isZoneActive($data['zone_id'])) {
                return ['success' => false, 'errors' => ['zone' => 'Zona tidak aktif atau tidak ditemukan']];
            }
            
            // Cek apakah petugas sedang cuti
            $userStatus = $this->getUserStatus($data['user_id']);
            if ($userStatus === 'cuti') {
                return ['success' => false, 'errors' => ['schedule' => 'Petugas sedang cuti, tidak dapat dijadwalkan']];
            }
            
            // Insert jadwal
            $stmt = $this->pdo->prepare("
                INSERT INTO schedules (
                    user_id, 
                    zone_id, 
                    work_date, 
                    shift_start, 
                    shift_end, 
                    shift_type, 
                    day_type,
                    description, 
                    status, 
                    created_by, 
                    created_at
                ) VALUES (
                    :user_id, 
                    :zone_id, 
                    :work_date, 
                    :start_time, 
                    :end_time, 
                    :shift_type, 
                    :day_type,
                    :description, 
                    'active', 
                    :created_by, 
                    NOW()
                )
            ");
            
            $stmt->execute([
                ':user_id' => $data['user_id'],
                ':zone_id' => $data['zone_id'],
                ':work_date' => $data['work_date'],
                ':start_time' => $data['start_time'],
                ':end_time' => $data['end_time'],
                ':shift_type' => $data['shift_type'] ?? 'custom',
                ':day_type' => $data['day_type'] ?? 'workday',
                ':description' => $data['description'] ?? '',
                ':created_by' => $_SESSION['user_id']
            ]);
            
            $scheduleId = (int)$this->pdo->lastInsertId();
            
            // Jika jadwal untuk hari ini, update status petugas
            if ($data['work_date'] == date('Y-m-d')) {
                $this->updateUserStatus($data['user_id'], 'aktif');
            }
            
            // Log aktivitas
            $this->logActivity($_SESSION['user_id'], "Membuat jadwal baru ID: {$scheduleId}");
            
            $this->pdo->commit();
            
            return ['success' => true, 'schedule_id' => $scheduleId];
            
        } catch (Exception $e) {
            $this->pdo->rollBack();
            error_log("Create Schedule Error: " . $e->getMessage());
            return ['success' => false, 'errors' => ['system' => 'Terjadi kesalahan sistem: ' . $e->getMessage()]];
        }
    }
    
    /**
     * Update jadwal
     */
    public function updateSchedule($id, $data) {
        try {
            $this->pdo->beginTransaction();
            
            // Validasi data
            $errors = $this->validateScheduleData($data);
            if (!empty($errors)) {
                return ['success' => false, 'errors' => $errors];
            }
            
            // Cek apakah jadwal ada
            $existing = $this->getScheduleById($id);
            if (!$existing) {
                return ['success' => false, 'errors' => ['schedule' => 'Jadwal tidak ditemukan']];
            }
            
            // NOTE: Jadwal bisa di-stack (sama waktu, sama RW dibolehkan)
            // Tidak memblokir update meskipun ada jadwal lain
            
            // Cek apakah zona aktif
            if (!$this->isZoneActive($data['zone_id'])) {
                return ['success' => false, 'errors' => ['zone' => 'Zona tidak aktif']];
            }
            
            // Update jadwal
            $stmt = $this->pdo->prepare("
                UPDATE schedules 
                SET user_id = :user_id,
                    zone_id = :zone_id,
                    work_date = :work_date,
                    shift_start = :start_time,
                    shift_end = :end_time,
                    shift_type = :shift_type,
                    day_type = :day_type,
                    description = :description,
                    updated_by = :updated_by,
                    updated_at = NOW()
                WHERE id = :id
            ");
            
            $stmt->execute([
                ':user_id' => $data['user_id'],
                ':zone_id' => $data['zone_id'],
                ':work_date' => $data['work_date'],
                ':start_time' => $data['start_time'],
                ':end_time' => $data['end_time'],
                ':shift_type' => $data['shift_type'] ?? 'custom',
                ':day_type' => $data['day_type'] ?? 'workday',
                ':description' => $data['description'] ?? '',
                ':updated_by' => $_SESSION['user_id'],
                ':id' => $id
            ]);
            
            // Jika tanggal berubah, sesuaikan status petugas
            if ($existing['work_date'] != $data['work_date']) {
                if ($existing['work_date'] == date('Y-m-d')) {
                    // Hapus jadwal hari ini, cek apakah masih ada jadwal lain
                    $otherSchedule = $this->hasOtherScheduleToday($data['user_id'], $id);
                    if (!$otherSchedule) {
                        $this->updateUserStatus($data['user_id'], 'nonaktif');
                    }
                }
                
                if ($data['work_date'] == date('Y-m-d')) {
                    $this->updateUserStatus($data['user_id'], 'aktif');
                }
            }
            
            // Log aktivitas
            $this->logActivity($_SESSION['user_id'], "Update jadwal ID: {$id}");
            
            $this->pdo->commit();
            
            return ['success' => true];
            
        } catch (Exception $e) {
            $this->pdo->rollBack();
            error_log("Update Schedule Error: " . $e->getMessage());
            return ['success' => false, 'errors' => ['system' => 'Terjadi kesalahan sistem']];
        }
    }
    
    /**
     * Hapus jadwal
     */
    public function deleteSchedule($id) {
        try {
            $this->pdo->beginTransaction();
            
            // Cek apakah jadwal ada
            $schedule = $this->getScheduleById($id);
            if (!$schedule) {
                return ['success' => false, 'errors' => ['schedule' => 'Jadwal tidak ditemukan']];
            }
            
            // Cek apakah jadwal sudah ada laporan
            if ($this->hasReports($id)) {
                return ['success' => false, 'errors' => ['schedule' => 'Jadwal tidak dapat dihapus karena sudah ada laporan terkait']];
            }
            
            // Hapus jadwal
            $stmt = $this->pdo->prepare("DELETE FROM schedules WHERE id = :id");
            $stmt->execute([':id' => $id]);
            
            // Jika jadwal untuk hari ini, cek apakah masih ada jadwal lain
            if ($schedule['work_date'] == date('Y-m-d')) {
                $otherSchedule = $this->hasOtherScheduleToday($schedule['user_id'], $id);
                if (!$otherSchedule) {
                    $this->updateUserStatus($schedule['user_id'], 'nonaktif');
                }
            }
            
            // Log aktivitas
            $this->logActivity($_SESSION['user_id'], "Hapus jadwal ID: {$id}");
            
            $this->pdo->commit();
            
            return ['success' => true];
            
        } catch (Exception $e) {
            $this->pdo->rollBack();
            error_log("Delete Schedule Error: " . $e->getMessage());
            return ['success' => false, 'errors' => ['system' => 'Terjadi kesalahan sistem']];
        }
    }
    
    /**
     * Nonaktifkan jadwal (soft delete)
     */
    public function deactivateSchedule($id) {
        try {
            $this->pdo->beginTransaction();
            
            // Cek apakah jadwal ada
            $schedule = $this->getScheduleById($id);
            if (!$schedule) {
                return ['success' => false, 'errors' => ['schedule' => 'Jadwal tidak ditemukan']];
            }
            
            // Nonaktifkan jadwal
            $stmt = $this->pdo->prepare("
                UPDATE schedules 
                SET status = 'inactive',
                    updated_by = :updated_by,
                    updated_at = NOW()
                WHERE id = :id
            ");
            
            $stmt->execute([
                ':updated_by' => $_SESSION['user_id'],
                ':id' => $id
            ]);
            
            // Jika jadwal untuk hari ini, update status petugas
            if ($schedule['work_date'] == date('Y-m-d')) {
                $otherSchedule = $this->hasOtherScheduleToday($schedule['user_id'], $id);
                if (!$otherSchedule) {
                    $this->updateUserStatus($schedule['user_id'], 'nonaktif');
                }
            }
            
            // Log aktivitas
            $this->logActivity($_SESSION['user_id'], "Nonaktifkan jadwal ID: {$id}");
            
            $this->pdo->commit();
            
            return ['success' => true];
            
        } catch (Exception $e) {
            $this->pdo->rollBack();
            error_log("Deactivate Schedule Error: " . $e->getMessage());
            return ['success' => false, 'errors' => ['system' => 'Terjadi kesalahan sistem']];
        }
    }
    
    /**
     * Ambil daftar jadwal dengan filter
     */
    public function getSchedules($filter = [], $page = 1, $limit = 20) {
        try {
            $where = ["1=1"];
            $params = [];
            
            // Filter berdasarkan tanggal
            if (!empty($filter['start_date'])) {
                $where[] = "s.work_date >= :start_date";
                $params[':start_date'] = $filter['start_date'];
            }
            
            if (!empty($filter['end_date'])) {
                $where[] = "s.work_date <= :end_date";
                $params[':end_date'] = $filter['end_date'];
            }
            
            if (!empty($filter['work_date'])) {
                $where[] = "s.work_date = :work_date";
                $params[':work_date'] = $filter['work_date'];
            }
            
            // Filter berdasarkan petugas
            if (!empty($filter['user_id'])) {
                $where[] = "s.user_id = :user_id";
                $params[':user_id'] = $filter['user_id'];
            }
            
            // Filter berdasarkan zona/RW
            if (!empty($filter['zone_id'])) {
                $where[] = "s.zone_id = :zone_id";
                $params[':zone_id'] = $filter['zone_id'];
            }
            
            if (!empty($filter['rw'])) {
                $where[] = "z.rw_code = :rw";
                $params[':rw'] = $filter['rw'];
            }
            
            // Filter berdasarkan status
            if (!empty($filter['status'])) {
                $where[] = "s.status = :status";
                $params[':status'] = $filter['status'];
            }
            
            // Filter berdasarkan shift
            if (!empty($filter['shift_type'])) {
                $where[] = "s.shift_type = :shift_type";
                $params[':shift_type'] = $filter['shift_type'];
            }
            
            // Search
            if (!empty($filter['search'])) {
                $where[] = "(u.name LIKE :search1 OR u.nip LIKE :search2 OR z.name LIKE :search3)";
                $params[':search1'] = "%{$filter['search']}%";
                $params[':search2'] = "%{$filter['search']}%";
                $params[':search3'] = "%{$filter['search']}%";
            }
            
            $whereClause = implode(" AND ", $where);
            
            // Query utama
            $sql = "
                SELECT 
                    s.*,
                    u.name as petugas_name,
                    u.nip as petugas_nip,
                    u.phone as petugas_phone,
                    z.name as zone_name,
                    z.rw_code,
                    z.address as zone_address,
                    DATE_FORMAT(s.shift_start, '%H:%i') as start_time_formatted,
                    DATE_FORMAT(s.shift_end, '%H:%i') as end_time_formatted,
                    DATE_FORMAT(s.shift_start, '%H:%i') as start_time,
                    DATE_FORMAT(s.shift_end, '%H:%i') as end_time,
                    DATE_FORMAT(s.work_date, '%d/%m/%Y') as work_date_formatted,
                    CASE 
                        WHEN s.work_date < CURRENT_DATE THEN 'selesai'
                        WHEN s.work_date = CURRENT_DATE THEN 'berjalan'
                        ELSE 'mendatang'
                    END as schedule_status,
                    (SELECT COUNT(*) FROM reports r WHERE r.schedule_id = s.id) as report_count,
                    (SELECT COUNT(*) FROM reports r WHERE r.schedule_id = s.id AND r.work_date = s.work_date) as report_count_today
                FROM schedules s
                JOIN users u ON s.user_id = u.id
                JOIN zones z ON s.zone_id = z.id
                WHERE {$whereClause}
                ORDER BY s.work_date DESC, s.shift_start ASC
                LIMIT :limit OFFSET :offset
            ";
            
            $stmt = $this->pdo->prepare($sql);
            
            foreach ($params as $key => $value) {
                $stmt->bindValue($key, $value);
            }
            
            $offset = ($page - 1) * $limit;
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
            
            $stmt->execute();
            $results = $stmt->fetchAll();
            
            // Hitung total
            $countSql = "
                SELECT COUNT(*)
                FROM schedules s
                JOIN users u ON s.user_id = u.id
                JOIN zones z ON s.zone_id = z.id
                WHERE {$whereClause}
            ";
            
            $countStmt = $this->pdo->prepare($countSql);
            foreach ($params as $key => $value) {
                $countStmt->bindValue($key, $value);
            }
            $countStmt->execute();
            $total = $countStmt->fetchColumn();
            
            return [
                'success' => true,
                'data' => $results,
                'pagination' => [
                    'page' => $page,
                    'limit' => $limit,
                    'total' => $total,
                    'pages' => ceil($total / $limit)
                ]
            ];
            
        } catch (Exception $e) {
            error_log("Get Schedules Error: " . $e->getMessage());
            return ['success' => false, 'error' => 'Gagal mengambil data jadwal'];
        }
    }
    
    /**
     * Ambil jadwal hari ini untuk semua petugas
     */
    public function getTodaySchedules() {
        try {
            $sql = "
                SELECT 
                    s.id,
                    u.name as petugas_name,
                    u.nip as petugas_nip,
                    u.status as petugas_status,
                    z.name as zone_name,
                    z.rw_code,
                    z.address as zone_address,
                    DATE_FORMAT(s.shift_start, '%H:%i') as start_time,
                    DATE_FORMAT(s.shift_end, '%H:%i') as end_time,
                    s.description,
                    s.shift_type,
                    (SELECT COUNT(*) FROM reports r WHERE r.schedule_id = s.id AND r.work_date = CURRENT_DATE) as report_count,
                    CASE 
                        WHEN u.last_location_update > NOW() - INTERVAL 10 MINUTE 
                        THEN 'online' 
                        ELSE 'offline' 
                    END as online_status
                FROM schedules s
                JOIN users u ON s.user_id = u.id
                JOIN zones z ON s.zone_id = z.id
                WHERE s.work_date = CURRENT_DATE
                AND s.status = 'active'
                ORDER BY u.name ASC, s.shift_start ASC
            ";
            
            $stmt = $this->pdo->query($sql);
            $schedules = $stmt->fetchAll();
            
            return ['success' => true, 'schedules' => $schedules];
            
        } catch (Exception $e) {
            error_log("Get Today Schedules Error: " . $e->getMessage());
            return ['success' => false, 'error' => 'Gagal mengambil jadwal hari ini'];
        }
    }
    
    /**
     * Ambil jadwal untuk petugas tertentu
     */
    public function getSchedulesByUser($user_id, $filter = []) {
        try {
            $where = ["s.user_id = :user_id"];
            $params = [':user_id' => $user_id];
            
            if (!empty($filter['start_date'])) {
                $where[] = "s.work_date >= :start_date";
                $params[':start_date'] = $filter['start_date'];
            }
            
            if (!empty($filter['end_date'])) {
                $where[] = "s.work_date <= :end_date";
                $params[':end_date'] = $filter['end_date'];
            }
            
            if (!empty($filter['status'])) {
                $where[] = "s.status = :status";
                $params[':status'] = $filter['status'];
            }
            
            $whereClause = implode(" AND ", $where);
            
            $sql = "
                SELECT 
                    s.*,
                    z.name as zone_name,
                    z.rw_code,
                    z.address as zone_address,
                    DATE_FORMAT(s.work_date, '%d/%m/%Y') as work_date_formatted,
                    DATE_FORMAT(s.shift_start, '%H:%i') as start_time_formatted,
                    DATE_FORMAT(s.shift_end, '%H:%i') as end_time_formatted,
                    DATE_FORMAT(s.shift_start, '%H:%i') as start_time,
                    DATE_FORMAT(s.shift_end, '%H:%i') as end_time,
                    (SELECT COUNT(*) FROM reports r WHERE r.schedule_id = s.id) as report_count
                FROM schedules s
                JOIN zones z ON s.zone_id = z.id
                WHERE {$whereClause}
                ORDER BY s.work_date DESC, s.shift_start ASC
            ";
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            $schedules = $stmt->fetchAll();
            
            return ['success' => true, 'schedules' => $schedules];
            
        } catch (Exception $e) {
            error_log("Get Schedules By User Error: " . $e->getMessage());
            return ['success' => false, 'error' => 'Gagal mengambil jadwal petugas'];
        }
    }
    
    /**
     * Ambil detail jadwal
     */
    public function getScheduleDetail($id) {
        try {
            $sql = "
                SELECT 
                    s.*,
                    u.name as petugas_name,
                    u.nip as petugas_nip,
                    u.phone as petugas_phone,
                    u.status as petugas_status,
                    z.name as zone_name,
                    z.rw_code,
                    z.address as zone_address,
                    DATE_FORMAT(s.work_date, '%d/%m/%Y') as work_date_formatted,
                    DATE_FORMAT(s.shift_start, '%H:%i') as start_time_formatted,
                    DATE_FORMAT(s.shift_end, '%H:%i') as end_time_formatted,
                    DATE_FORMAT(s.shift_start, '%H:%i') as start_time,
                    DATE_FORMAT(s.shift_end, '%H:%i') as end_time,
                    (SELECT COUNT(*) FROM reports r WHERE r.schedule_id = s.id) as total_reports,
                    (SELECT COUNT(*) FROM reports r WHERE r.schedule_id = s.id AND r.work_date = s.work_date) as reports_on_date,
                    uc.name as created_by_name,
                    uu.name as updated_by_name
                FROM schedules s
                JOIN users u ON s.user_id = u.id
                JOIN zones z ON s.zone_id = z.id
                LEFT JOIN users uc ON s.created_by = uc.id
                LEFT JOIN users uu ON s.updated_by = uu.id
                WHERE s.id = :id
            ";
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([':id' => $id]);
            $schedule = $stmt->fetch();
            
            if (!$schedule) {
                return ['success' => false, 'error' => 'Jadwal tidak ditemukan'];
            }
            
            // Ambil laporan terkait
            $reportsSql = "
                SELECT 
                    r.id,
                    r.status,
                    r.gps_result,
                    r.created_at,
                    DATE_FORMAT(r.created_at, '%d/%m/%Y %H:%i') as created_at_formatted
                FROM reports r
                WHERE r.schedule_id = :schedule_id
                ORDER BY r.created_at DESC
                LIMIT 10
            ";
            
            $reportsStmt = $this->pdo->prepare($reportsSql);
            $reportsStmt->execute([':schedule_id' => $id]);
            $reports = $reportsStmt->fetchAll();
            
            return [
                'success' => true,
                'schedule' => $schedule,
                'reports' => $reports
            ];
            
        } catch (Exception $e) {
            error_log("Get Schedule Detail Error: " . $e->getMessage());
            return ['success' => false, 'error' => 'Gagal mengambil detail jadwal'];
        }
    }
    
    /**
     * Ambil statistik jadwal
     */
    public function getScheduleStatistics($period = 'month') {
        try {
            $stats = [];
            $date_condition = '';
            
            switch ($period) {
                case 'today':
                    $date_condition = "WHERE s.work_date = CURRENT_DATE";
                    break;
                case 'week':
                    $date_condition = "WHERE s.work_date >= CURRENT_DATE - INTERVAL 7 DAY";
                    break;
                case 'month':
                    $date_condition = "WHERE s.work_date >= CURRENT_DATE - INTERVAL 30 DAY";
                    break;
                default:
                    $date_condition = "WHERE s.work_date >= CURRENT_DATE - INTERVAL 30 DAY";
            }
            
            // Total jadwal
            $stmt = $this->pdo->query("SELECT COUNT(*) as total FROM schedules s {$date_condition}");
            $stats['total_schedules'] = (int)$stmt->fetchColumn();
            
            // Jadwal aktif hari ini
            $stmt = $this->pdo->query("
                SELECT COUNT(*) as total 
                FROM schedules s 
                WHERE s.work_date = CURRENT_DATE 
                AND s.status = 'active'
            ");
            $stats['active_today'] = (int)$stmt->fetchColumn();
            
            // Jadwal berdasarkan status
            $stmt = $this->pdo->query("
                SELECT s.status, COUNT(*) as count 
                FROM schedules s 
                {$date_condition}
                GROUP BY s.status
                ORDER BY s.status
            ");
            $stats['by_status'] = $stmt->fetchAll();
            
            // Jadwal berdasarkan shift
            $stmt = $this->pdo->query("
                SELECT s.shift_type, COUNT(*) as count 
                FROM schedules s 
                {$date_condition}
                GROUP BY s.shift_type
                ORDER BY s.shift_type
            ");
            $stats['by_shift'] = $stmt->fetchAll();
            
            // Petugas dengan jadwal terbanyak
            $stmt = $this->pdo->prepare("
                SELECT u.name, u.nip, COUNT(*) as schedule_count
                FROM schedules s
                JOIN users u ON s.user_id = u.id
                {$date_condition}
                GROUP BY u.id, u.name, u.nip
                ORDER BY schedule_count DESC
                LIMIT 5
            ");
            $stmt->execute();
            $stats['top_users'] = $stmt->fetchAll();
            
            // Zona dengan jadwal terbanyak
            $stmt = $this->pdo->prepare("
                SELECT z.name, z.rw_code, COUNT(*) as schedule_count
                FROM schedules s
                JOIN zones z ON s.zone_id = z.id
                {$date_condition}
                GROUP BY z.id, z.name, z.rw_code
                ORDER BY schedule_count DESC
                LIMIT 5
            ");
            $stmt->execute();
            $stats['top_zones'] = $stmt->fetchAll();
            
            // Jadwal per hari (7 hari terakhir)
            $stmt = $this->pdo->query("
                SELECT 
                    s.work_date,
                    COUNT(*) as schedule_count,
                    COUNT(DISTINCT s.user_id) as unique_users
                FROM schedules s
                WHERE s.work_date >= CURRENT_DATE - INTERVAL 7 DAY
                GROUP BY s.work_date
                ORDER BY s.work_date ASC
            ");
            $stats['daily_trend'] = $stmt->fetchAll();
            
            return ['success' => true, 'stats' => $stats];
            
        } catch (Exception $e) {
            error_log("Get Schedule Statistics Error: " . $e->getMessage());
            return ['success' => false, 'error' => 'Gagal mengambil statistik jadwal'];
        }
    }
    
    /**
     * Import jadwal dari CSV/Excel
     */
    public function importSchedules($file_path, $created_by) {
        try {
            $this->pdo->beginTransaction();
            
            // Baca file CSV
            $handle = fopen($file_path, 'r');
            if (!$handle) {
                return ['success' => false, 'error' => 'Gagal membuka file'];
            }
            
            $headers = fgetcsv($handle);
            $imported = 0;
            $errors = [];
            $row = 1;
            
            while (($data = fgetcsv($handle)) !== FALSE) {
                $row++;
                
                // Mapping data CSV
                $schedule_data = [
                    'user_nip' => $data[0] ?? '',
                    'zone_code' => $data[1] ?? '',
                    'work_date' => $data[2] ?? '',
                    'start_time' => $data[3] ?? '',
                    'end_time' => $data[4] ?? '',
                    'shift_type' => $data[5] ?? 'custom',
                    'description' => $data[6] ?? ''
                ];
                
                // Validasi data
                $validation = $this->validateImportData($schedule_data);
                if (!$validation['valid']) {
                    $errors[] = "Baris {$row}: " . $validation['error'];
                    continue;
                }
                
                // Cari user_id berdasarkan NIP
                $user = $this->getUserByNip($schedule_data['user_nip']);
                if (!$user) {
                    $errors[] = "Baris {$row}: Petugas dengan NIP {$schedule_data['user_nip']} tidak ditemukan";
                    continue;
                }
                
                // Cari zone_id berdasarkan kode zona
                $zone = $this->getZoneByCode($schedule_data['zone_code']);
                if (!$zone) {
                    $errors[] = "Baris {$row}: Zona dengan kode {$schedule_data['zone_code']} tidak ditemukan";
                    continue;
                }
                
                // Cek konflik jadwal
                if ($this->checkScheduleConflict($user['id'], $schedule_data['work_date'], $schedule_data['start_time'], $schedule_data['end_time'])) {
                    $errors[] = "Baris {$row}: Petugas sudah memiliki jadwal pada waktu tersebut";
                    continue;
                }
                
                // Insert jadwal
                $stmt = $this->pdo->prepare("
                    INSERT INTO schedules (
                        user_id, 
                        zone_id, 
                        work_date, 
                        shift_start, 
                        shift_end, 
                        shift_type, 
                        description, 
                        status, 
                        created_by, 
                        created_at
                    ) VALUES (
                        :user_id, 
                        :zone_id, 
                        :work_date, 
                        :start_time, 
                        :end_time, 
                        :shift_type, 
                        :description, 
                        'active', 
                        :created_by, 
                        NOW()
                    )
                ");
                
                $stmt->execute([
                    ':user_id' => $user['id'],
                    ':zone_id' => $zone['id'],
                    ':work_date' => $schedule_data['work_date'],
                    ':start_time' => $schedule_data['start_time'],
                    ':end_time' => $schedule_data['end_time'],
                    ':shift_type' => $schedule_data['shift_type'],
                    ':description' => $schedule_data['description'],
                    ':created_by' => $created_by
                ]);
                
                $imported++;
            }
            
            fclose($handle);
            
            // Log aktivitas
            $this->logActivity($created_by, "Import {$imported} jadwal dari file");
            
            $this->pdo->commit();
            
            return [
                'success' => true,
                'imported' => $imported,
                'errors' => $errors
            ];
            
        } catch (Exception $e) {
            $this->pdo->rollBack();
            error_log("Import Schedules Error: " . $e->getMessage());
            return ['success' => false, 'error' => 'Terjadi kesalahan sistem'];
        }
    }
    
    /**
     * Export jadwal ke CSV/Excel
     */
    public function exportSchedules($filter = [], $format = 'csv') {
        try {
            $where = ["1=1"];
            $params = [];
            
            // Filter seperti getSchedules
            if (!empty($filter['start_date'])) {
                $where[] = "s.work_date >= :start_date";
                $params[':start_date'] = $filter['start_date'];
            }
            
            if (!empty($filter['end_date'])) {
                $where[] = "s.work_date <= :end_date";
                $params[':end_date'] = $filter['end_date'];
            }
            
            if (!empty($filter['user_id'])) {
                $where[] = "s.user_id = :user_id";
                $params[':user_id'] = $filter['user_id'];
            }
            
            if (!empty($filter['zone_id'])) {
                $where[] = "s.zone_id = :zone_id";
                $params[':zone_id'] = $filter['zone_id'];
            }
            
            if (!empty($filter['status'])) {
                $where[] = "s.status = :status";
                $params[':status'] = $filter['status'];
            }
            
            $whereClause = implode(" AND ", $where);
            
            // Query untuk export
            $sql = "
                SELECT 
                    u.nip,
                    u.name as petugas_nama,
                    z.zone_code,
                    z.rw_code,
                    z.name as zona_nama,
                    s.work_date,
                    DATE_FORMAT(s.shift_start, '%H:%i') as start_time,
                    DATE_FORMAT(s.shift_end, '%H:%i') as end_time,
                    s.shift_type,
                    s.description,
                    s.status,
                    (SELECT COUNT(*) FROM reports r WHERE r.schedule_id = s.id) as jumlah_laporan
                FROM schedules s
                JOIN users u ON s.user_id = u.id
                JOIN zones z ON s.zone_id = z.id
                WHERE {$whereClause}
                ORDER BY s.work_date DESC, s.shift_start ASC
            ";
            
            $stmt = $this->pdo->prepare($sql);
            
            foreach ($params as $key => $value) {
                $stmt->bindValue($key, $value);
            }
            
            $stmt->execute();
            $data = $stmt->fetchAll();
            
            // Generate file berdasarkan format
            $filename = "jadwal_ppsu_" . date('Ymd_His');
            
            if ($format === 'csv') {
                return $this->generateCSV($data, $filename);
            } elseif ($format === 'excel') {
                return $this->generateExcel($data, $filename);
            } else {
                return $this->generateCSV($data, $filename);
            }
            
        } catch (Exception $e) {
            error_log("Export Schedules Error: " . $e->getMessage());
            return ['success' => false, 'error' => 'Gagal mengekspor data'];
        }
    }
    
    /**
     * Generate jadwal otomatis berdasarkan pola
     */
    public function generateAutoSchedule($pattern_data) {
        try {
            $this->pdo->beginTransaction();
            
            // Validasi pola
            $errors = $this->validatePatternData($pattern_data);
            if (!empty($errors)) {
                return ['success' => false, 'errors' => $errors];
            }
            
            $generated = 0;
            $start_date = new DateTime($pattern_data['start_date']);
            $end_date = new DateTime($pattern_data['end_date']);
            
            // Generate untuk setiap hari dalam rentang
            $interval = DateInterval::createFromDateString('1 day');
            $period = new DatePeriod($start_date, $interval, $end_date->modify('+1 day'));
            
            foreach ($period as $date) {
                // Skip weekend jika diatur
                if ($pattern_data['skip_weekend'] && in_array($date->format('N'), [6, 7])) {
                    continue;
                }
                
                // Untuk setiap petugas dalam pola
                foreach ($pattern_data['assignments'] as $assignment) {
                    $user_id = $assignment['user_id'];
                    $zone_id = $assignment['zone_id'];
                    $shift_type = $assignment['shift_type'] ?? 'custom';
                    
                    // Tentukan waktu berdasarkan shift type
                    $times = $this->getShiftTimes($shift_type);
                    
                    // Cek apakah sudah ada jadwal untuk tanggal ini
                    if ($this->checkScheduleConflict($user_id, $date->format('Y-m-d'), $times['start'], $times['end'])) {
                        continue;
                    }
                    
                    // Buat jadwal
                    $stmt = $this->pdo->prepare("
                        INSERT INTO schedules (
                            user_id, 
                            zone_id, 
                            work_date, 
                            shift_start, 
                            shift_end, 
                            shift_type, 
                            description, 
                            status, 
                            created_by, 
                            created_at
                        ) VALUES (
                            :user_id, 
                            :zone_id, 
                            :work_date, 
                            :start_time, 
                            :end_time, 
                            :shift_type, 
                            :description, 
                            'active', 
                            :created_by, 
                            NOW()
                        )
                    ");
                    
                    $stmt->execute([
                        ':user_id' => $user_id,
                        ':zone_id' => $zone_id,
                        ':work_date' => $date->format('Y-m-d'),
                        ':start_time' => $times['start'],
                        ':end_time' => $times['end'],
                        ':shift_type' => $shift_type,
                        ':description' => $pattern_data['description'] ?? '',
                        ':created_by' => $_SESSION['user_id']
                    ]);
                    
                    $generated++;
                }
            }
            
            // Log aktivitas
            $this->logActivity($_SESSION['user_id'], "Generate otomatis {$generated} jadwal");
            
            $this->pdo->commit();
            
            return ['success' => true, 'generated' => $generated];
            
        } catch (Exception $e) {
            $this->pdo->rollBack();
            error_log("Generate Auto Schedule Error: " . $e->getMessage());
            return ['success' => false, 'errors' => ['system' => 'Terjadi kesalahan sistem']];
        }
    }
    
    // =========================================================
    // PRIVATE HELPER METHODS
    // =========================================================
    
    private function validateScheduleData($data) {
        $errors = [];
        
        if (empty($data['user_id'])) {
            $errors['user_id'] = 'Petugas wajib dipilih';
        }
        
        if (empty($data['zone_id'])) {
            $errors['zone_id'] = 'Zona wajib dipilih';
        }
        
        if (empty($data['work_date'])) {
            $errors['work_date'] = 'Tanggal kerja wajib diisi';
        } else {
            // Validasi format tanggal
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $data['work_date'])) {
                $errors['work_date'] = 'Format tanggal tidak valid (YYYY-MM-DD)';
            } else {
                $work_date = DateTime::createFromFormat('Y-m-d', $data['work_date']);
                $today = new DateTime();
                $today->setTime(0, 0, 0);
                
                if ($work_date < $today->modify('-1 day')) {
                    $errors['work_date'] = 'Tanggal tidak boleh lebih dari 1 hari yang lalu';
                }
            }
        }
        
        if (empty($data['start_time'])) {
            $errors['start_time'] = 'Waktu mulai wajib diisi';
        } else {
            if (!preg_match('/^\d{2}:\d{2}$/', $data['start_time'])) {
                $errors['start_time'] = 'Format waktu tidak valid (HH:MM)';
            }
        }
        
        if (empty($data['end_time'])) {
            $errors['end_time'] = 'Waktu selesai wajib diisi';
        } else {
            if (!preg_match('/^\d{2}:\d{2}$/', $data['end_time'])) {
                $errors['end_time'] = 'Format waktu tidak valid (HH:MM)';
            }
        }
        
        if (!empty($data['start_time']) && !empty($data['end_time'])) {
            $start = strtotime($data['start_time']);
            $end = strtotime($data['end_time']);
            
            if ($end <= $start) {
                $errors['end_time'] = 'Waktu selesai harus setelah waktu mulai';
            }
            
            $duration = ($end - $start) / 3600; // Durasi dalam jam
            
            if ($duration < 1) {
                $errors['duration'] = 'Durasi kerja minimal 1 jam';
            }
            
            if ($duration > 12) {
                $errors['duration'] = 'Durasi kerja maksimal 12 jam';
            }
        }
        
        return $errors;
    }
    
    private function validateImportData($data) {
        // Validasi NIP
        if (empty($data['user_nip'])) {
            return ['valid' => false, 'error' => 'NIP petugas kosong'];
        }
        
        // Validasi kode zona
        if (empty($data['zone_code'])) {
            return ['valid' => false, 'error' => 'Kode zona kosong'];
        }
        
        // Validasi tanggal
        if (empty($data['work_date'])) {
            return ['valid' => false, 'error' => 'Tanggal kerja kosong'];
        }
        
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $data['work_date'])) {
            return ['valid' => false, 'error' => 'Format tanggal tidak valid'];
        }
        
        // Validasi waktu
        if (empty($data['start_time']) || empty($data['end_time'])) {
            return ['valid' => false, 'error' => 'Waktu kerja kosong'];
        }
        
        if (!preg_match('/^\d{2}:\d{2}$/', $data['start_time']) || !preg_match('/^\d{2}:\d{2}$/', $data['end_time'])) {
            return ['valid' => false, 'error' => 'Format waktu tidak valid'];
        }
        
        return ['valid' => true];
    }
    
    private function validatePatternData($data) {
        $errors = [];
        
        if (empty($data['start_date'])) {
            $errors['start_date'] = 'Tanggal mulai wajib diisi';
        }
        
        if (empty($data['end_date'])) {
            $errors['end_date'] = 'Tanggal selesai wajib diisi';
        }
        
        if (empty($data['assignments']) || !is_array($data['assignments'])) {
            $errors['assignments'] = 'Penugasan wajib diisi';
        } else {
            foreach ($data['assignments'] as $index => $assignment) {
                if (empty($assignment['user_id'])) {
                    $errors["assignment_{$index}"] = 'Petugas wajib dipilih';
                }
                if (empty($assignment['zone_id'])) {
                    $errors["assignment_{$index}"] = 'Zona wajib dipilih';
                }
            }
        }
        
        return $errors;
    }
    
    private function checkScheduleConflict($user_id, $work_date, $start_time, $end_time, $exclude_id = null) {
        $sql = "
            SELECT COUNT(*) 
            FROM schedules 
            WHERE user_id = :user_id 
            AND work_date = :work_date 
            AND status = 'active'
            AND (
                (shift_start <= :st1 AND shift_end > :st2) OR
                (shift_start < :et1 AND shift_end >= :et2) OR
                (shift_start >= :st3 AND shift_end <= :et3)
            )
        ";
        
        $params = [
            ':user_id' => $user_id,
            ':work_date' => $work_date,
            ':st1' => $start_time,
            ':st2' => $start_time,
            ':st3' => $start_time,
            ':et1' => $end_time,
            ':et2' => $end_time,
            ':et3' => $end_time
        ];
        
        if ($exclude_id) {
            $sql .= " AND id != :exclude_id";
            $params[':exclude_id'] = $exclude_id;
        }
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        
        return $stmt->fetchColumn() > 0;
    }
    
    private function isZoneActive($zone_id) {
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM zones WHERE id = :zone_id");
        $stmt->execute([':zone_id' => $zone_id]);
        return $stmt->fetchColumn() > 0;
    }
    
    private function getUserStatus($user_id) {
        $stmt = $this->pdo->prepare("SELECT status FROM users WHERE id = :user_id");
        $stmt->execute([':user_id' => $user_id]);
        return $stmt->fetchColumn();
    }
    
    private function getScheduleById($id) {
        $stmt = $this->pdo->prepare("SELECT * FROM schedules WHERE id = :id");
        $stmt->execute([':id' => $id]);
        return $stmt->fetch();
    }
    
    private function hasReports($schedule_id) {
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM reports WHERE schedule_id = :schedule_id");
        $stmt->execute([':schedule_id' => $schedule_id]);
        return $stmt->fetchColumn() > 0;
    }
    
    private function hasOtherScheduleToday($user_id, $exclude_id) {
        $stmt = $this->pdo->prepare("
            SELECT COUNT(*) 
            FROM schedules 
            WHERE user_id = :user_id 
            AND work_date = CURRENT_DATE 
            AND id != :exclude_id
            AND status = 'active'
        ");
        
        $stmt->execute([
            ':user_id' => $user_id,
            ':exclude_id' => $exclude_id
        ]);
        
        return $stmt->fetchColumn() > 0;
    }
    
    private function updateUserStatus($user_id, $status) {
        $stmt = $this->pdo->prepare("
            UPDATE users 
            SET status = :status,
                last_status_change = NOW(),
                updated_at = NOW()
            WHERE id = :user_id
        ");
        
        $stmt->execute([
            ':status' => $status,
            ':user_id' => $user_id
        ]);
    }
    
    private function getUserByNip($nip) {
        $stmt = $this->pdo->prepare("SELECT id FROM users WHERE nip = :nip AND role = 'petugas'");
        $stmt->execute([':nip' => $nip]);
        return $stmt->fetch();
    }
    
    private function getZoneByCode($zone_code) {
        $stmt = $this->pdo->prepare("SELECT id FROM zones WHERE zone_code = :zone_code");
        $stmt->execute([':zone_code' => $zone_code]);
        return $stmt->fetch();
    }
    
    private function getShiftTimes($shift_type) {
        $times = [
            'shift1' => ['start' => '08:00', 'end' => '16:00'],
            'shift2' => ['start' => '16:00', 'end' => '24:00'],
            'halfday' => ['start' => '08:00', 'end' => '12:00'],
            'custom' => ['start' => '08:00', 'end' => '17:00']
        ];
        
        return $times[$shift_type] ?? $times['custom'];
    }
    
    private function generateCSV($data, $filename) {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '.csv"');
        
        $output = fopen('php://output', 'w');
        
        // Header
        if (!empty($data)) {
            fputcsv($output, array_keys($data[0]));
        }
        
        // Data
        foreach ($data as $row) {
            fputcsv($output, $row);
        }
        
        fclose($output);
        exit;
    }
    
    private function generateExcel($data, $filename) {
        header('Content-Type: application/vnd.ms-excel');
        header('Content-Disposition: attachment; filename="' . $filename . '.xls"');
        
        echo '<table border="1">';
        
        // Header
        if (!empty($data)) {
            echo '<tr>';
            foreach (array_keys($data[0]) as $header) {
                echo '<th>' . htmlspecialchars($header) . '</th>';
            }
            echo '</tr>';
        }
        
        // Data
        foreach ($data as $row) {
            echo '<tr>';
            foreach ($row as $cell) {
                echo '<td>' . htmlspecialchars($cell) . '</td>';
            }
            echo '</tr>';
        }
        
        echo '</table>';
        exit;
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
}
?>
