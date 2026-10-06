<?php
require_once __DIR__ . '/../../config/database.php';

class SupervisorController {
    private $pdo;
    
    public function __construct() {
        $this->pdo = Database::getInstance()->getConnection();
    }
    
    public function getDashboardStats($supervisor_id) {
        try {
            $stats = [];
            
            // Total petugas yang diawasi
            $stmt = $this->pdo->query("
                SELECT COUNT(*) as total 
                FROM users 
                WHERE role = 'petugas' AND status IN ('online', 'offline')
            ");
            $stats['total_petugas'] = (int)$stmt->fetchColumn();
            
            // Laporan hari ini
            $stmt = $this->pdo->query("
                SELECT COUNT(*) as total 
                FROM reports 
                WHERE work_date = CURRENT_DATE
            ");
            $stats['today_reports'] = (int)$stmt->fetchColumn();
            
            // Petugas aktif (online dalam 10 menit terakhir)
            $stmt = $this->pdo->query("
                SELECT COUNT(*) as total 
                FROM users 
                WHERE role = 'petugas' 
                AND status = 'online'
                AND last_location_update IS NOT NULL
                AND last_location_update > NOW() - INTERVAL 10 MINUTE
            ");
            $stats['active_petugas'] = (int)$stmt->fetchColumn();
            
            // Permintaan reset password pending
            $stmt = $this->pdo->prepare("
                SELECT COUNT(*) as total 
                FROM password_resets 
                WHERE status = 'pending'
                AND source = 'petugas'
            ");
            $stmt->execute();
            $stats['pending_resets'] = (int)$stmt->fetchColumn();
            
            return ['success' => true, 'stats' => $stats];
            
        } catch (Exception $e) {
            error_log("Get Dashboard Stats Error: " . $e->getMessage());
            return ['success' => false, 'error' => 'Gagal mengambil statistik'];
        }
    }
    
    public function getResetRequests() {
        try {
            $stmt = $this->pdo->prepare("
                SELECT pr.*,
                       u.name as petugas_name,
                       u.nip as petugas_nip,
                       u.username,
                       DATE_FORMAT(pr.created_at, '%d/%m/%Y %H:%i') as created_at_formatted
                FROM password_resets pr
                JOIN users u ON pr.user_id = u.id
                WHERE pr.source = 'petugas'
                AND pr.created_at >= CURRENT_DATE - INTERVAL 7 DAY
                ORDER BY 
                    CASE WHEN pr.status = 'pending' THEN 0 ELSE 1 END,
                    pr.created_at DESC
            ");
            
            $stmt->execute();
            $requests = $stmt->fetchAll();
            
            return ['success' => true, 'requests' => $requests];
            
        } catch (Exception $e) {
            error_log("Get Reset Requests Error: " . $e->getMessage());
            return ['success' => false, 'error' => 'Gagal mengambil data permintaan reset'];
        }
    }
    
    public function approveResetRequest($request_id, $supervisor_id) {
        try {
            $this->pdo->beginTransaction();
            
            // Get request details
            $stmt = $this->pdo->prepare("
                SELECT pr.*, u.username, u.name
                FROM password_resets pr
                JOIN users u ON pr.user_id = u.id
                WHERE pr.id = :id
                AND pr.status = 'pending'
                FOR UPDATE
            ");
            
            $stmt->execute([':id' => $request_id]);
            $request = $stmt->fetch();
            
            if (!$request) {
                return ['success' => false, 'error' => 'Permintaan tidak ditemukan atau sudah diproses'];
            }
            
            // Update request status to approved (user will set their own password)
            $requestStmt = $this->pdo->prepare("
                UPDATE password_resets 
                SET status = 'approved',
                    approved_by = :approved_by,
                    approved_at = NOW(),
                    expires_at = DATE_ADD(NOW(), INTERVAL 24 HOUR)
                WHERE id = :id
            ");
            
            $requestStmt->execute([
                ':approved_by' => $supervisor_id,
                ':id' => $request_id
            ]);
            
            // Log aktivitas
            $this->logActivity($supervisor_id, "Approve reset password untuk user: {$request['username']}");
            
            $this->pdo->commit();
            
            // Return success - user will now use their token to set new password
            return [
                'success' => true, 
                'message' => 'Permintaan disetujui. Petugas dapat membuat password baru.',
                'username' => $request['username'],
                'name' => $request['name']
            ];
            
        } catch (Exception $e) {
            $this->pdo->rollBack();
            error_log("Approve Reset Request Error: " . $e->getMessage());
            return ['success' => false, 'error' => 'Terjadi kesalahan sistem'];
        }
    }
    
    public function rejectResetRequest($request_id, $supervisor_id) {
        try {
            $this->pdo->beginTransaction();
            
            // Get request details
            $stmt = $this->pdo->prepare("
                SELECT pr.*, u.username, u.name
                FROM password_resets pr
                JOIN users u ON pr.user_id = u.id
                WHERE pr.id = :id
                AND pr.status = 'pending'
                FOR UPDATE
            ");
            
            $stmt->execute([':id' => $request_id]);
            $request = $stmt->fetch();
            
            if (!$request) {
                $this->pdo->rollBack();
                return ['success' => false, 'error' => 'Permintaan tidak ditemukan atau sudah diproses'];
            }
            
            // Update request status to rejected
            $requestStmt = $this->pdo->prepare("
                UPDATE password_resets 
                SET status = 'rejected',
                    approved_by = :rejected_by,
                    approved_at = NOW()
                WHERE id = :id
            ");
            
            $requestStmt->execute([
                ':rejected_by' => $supervisor_id,
                ':id' => $request_id
            ]);
            
            // Log aktivitas
            $this->logActivity($supervisor_id, "Menolak reset password untuk user: {$request['username']}");
            
            $this->pdo->commit();
            
            return [
                'success' => true, 
                'message' => 'Permintaan ditolak.',
                'username' => $request['username'],
                'name' => $request['name']
            ];
            
        } catch (Exception $e) {
            $this->pdo->rollBack();
            error_log("Reject Reset Request Error: " . $e->getMessage());
            return ['success' => false, 'error' => 'Terjadi kesalahan sistem'];
        }
    }
    
    public function getPetugasList($filter = []) {
        try {
            $where = ["u.role = 'petugas'"];
            $params = [];
            
            if (!empty($filter['status'])) {
                $where[] = "u.status = :status";
                $params[':status'] = $filter['status'];
            }
            
            if (!empty($filter['online'])) {
                if ($filter['online'] === 'true') {
                    $where[] = "u.last_location_update IS NOT NULL";
                    $where[] = "u.last_location_update > NOW() - INTERVAL 10 MINUTE";
                } else {
                    $where[] = "(u.last_location_update IS NULL OR u.last_location_update <= NOW() - INTERVAL 10 MINUTE)";
                }
            }
            
            $whereClause = $where ? "WHERE " . implode(" AND ", $where) : "";
            
            $sql = "
                SELECT 
                    u.id,
                    u.nip,
                    u.name,
                    u.phone,
                    u.status,
                    u.last_login,
                    u.last_location_update,
                    CASE 
                        WHEN u.status = 'online'
                         AND u.last_location_update IS NOT NULL
                         AND u.last_location_update > NOW() - INTERVAL '10 minutes'
                        THEN TRUE
                        ELSE FALSE
                    END AS online,
                    (SELECT COUNT(*) FROM reports WHERE user_id = u.id AND work_date = CURRENT_DATE) as today_reports,
                    (SELECT z.rw_code FROM schedules s JOIN zones z ON s.zone_id = z.id 
                     WHERE s.user_id = u.id AND s.work_date = CURRENT_DATE LIMIT 1) as current_rw
                FROM users u
                {$whereClause}
                ORDER BY u.name ASC
            ";
            
            $stmt = $this->pdo->prepare($sql);
            foreach ($params as $key => $value) {
                $stmt->bindValue($key, $value);
            }
            
            $stmt->execute();
            $petugas = $stmt->fetchAll();
            
            return ['success' => true, 'petugas' => $petugas];
            
        } catch (Exception $e) {
            error_log("Get Petugas List Error: " . $e->getMessage());
            return ['success' => false, 'error' => 'Gagal mengambil data petugas'];
        }
    }
    
    public function getJadwalHariIni() {
        try {
            $sql = "
                SELECT 
                    s.id,
                    u.name as petugas_name,
                    u.nip as petugas_nip,
                    z.name as zone_name,
                    z.rw_code,
                    DATE_FORMAT(s.start_time, '%H:%i') as start_time,
                    DATE_FORMAT(s.end_time, '%H:%i') as end_time,
                    s.description,
                    (SELECT COUNT(*) FROM reports r WHERE r.schedule_id = s.id AND r.work_date = CURRENT_DATE) as laporan_count
                FROM schedules s
                JOIN users u ON s.user_id = u.id
                JOIN zones z ON s.zone_id = z.id
                WHERE s.work_date = CURRENT_DATE
                AND s.status = 'active'
                ORDER BY u.name ASC, s.start_time ASC
            ";
            
            $stmt = $this->pdo->query($sql);
            $jadwal = $stmt->fetchAll();
            
            // Group by petugas
            $grouped = [];
            foreach ($jadwal as $item) {
                $petugas_id = $item['petugas_name'] . '_' . $item['petugas_nip'];
                if (!isset($grouped[$petugas_id])) {
                    $grouped[$petugas_id] = [
                        'petugas_name' => $item['petugas_name'],
                        'petugas_nip' => $item['petugas_nip'],
                        'items' => []
                    ];
                }
                $grouped[$petugas_id]['items'][] = $item;
            }
            
            return ['success' => true, 'jadwal' => array_values($grouped)];
            
        } catch (Exception $e) {
            error_log("Get Jadwal Hari Ini Error: " . $e->getMessage());
            return ['success' => false, 'error' => 'Gagal mengambil jadwal'];
        }
    }
    
    public function getLaporanPetugas($filter = []) {
        try {
            $where = ["u.role = 'petugas'"];
            $params = [];
            
            if (!empty($filter['user_id'])) {
                $where[] = "r.user_id = :user_id";
                $params[':user_id'] = $filter['user_id'];
            }
            
            if (!empty($filter['date'])) {
                $where[] = "r.work_date = :date";
                $params[':date'] = $filter['date'];
            } else {
                $where[] = "r.work_date = CURRENT_DATE";
            }
            
            if (!empty($filter['status'])) {
                $where[] = "r.status = :status";
                $params[':status'] = $filter['status'];
            }
            
            $whereClause = $where ? "WHERE " . implode(" AND ", $where) : "";
            
            $sql = "
                SELECT 
                    r.id,
                    u.name as petugas_name,
                    u.nip as petugas_nip,
                    z.name as zone_name,
                    z.rw_code,
                    r.session,
                    r.status,
                    r.gps_result,
                    DATE_FORMAT(r.created_at, '%d/%m/%Y %H:%i') as waktu_laporan,
                    r.before_photo_path,
                    r.after_photo_path
                FROM reports r
                JOIN users u ON r.user_id = u.id
                JOIN zones z ON r.zone_id = z.id
                {$whereClause}
                ORDER BY r.created_at DESC
                LIMIT 50
            ";
            
            $stmt = $this->pdo->prepare($sql);
            foreach ($params as $key => $value) {
                $stmt->bindValue($key, $value);
            }
            
            $stmt->execute();
            $laporan = $stmt->fetchAll();
            
            return ['success' => true, 'laporan' => $laporan];
            
        } catch (Exception $e) {
            error_log("Get Laporan Petugas Error: " . $e->getMessage());
            return ['success' => false, 'error' => 'Gagal mengambil data laporan'];
        }
    }

    public function getPetugasListDynamic($filters = [], $supervisor_id = null) {
        try {
            $where = [];
            $params = [];
            
            // Handle Role Filter (Default: petugas, unless specified)
            if (!empty($filters['role']) && $filters['role'] !== 'all') {
                $where[] = "u.role = :role";
                $params[':role'] = $filters['role'];
            } elseif (empty($filters['role'])) {
                 // Default to petugas if not specified
                 $where[] = "u.role = 'petugas'";
            }

            // IMPORTANT: If supervisor_id is provided, filter to show only petugas in supervisor's group
            if ($supervisor_id) {
                // Get supervisor's group first
                $groupStmt = $this->pdo->prepare("SELECT id FROM `groups` WHERE supervisor_id = :sup_id LIMIT 1");
                $groupStmt->execute([':sup_id' => $supervisor_id]);
                $supervisorGroup = $groupStmt->fetch();
                
                if ($supervisorGroup) {
                    $where[] = "u.group_id = :group_id";
                    $params[':group_id'] = $supervisorGroup['id'];
                } else {
                    // Supervisor has no group, show empty
                    $where[] = "1 = 0";
                }
            }

            if (!empty($filters['status']) && $filters['status'] !== 'all') {
                $where[] = "u.status = :status";
                $params[':status'] = $filters['status'];
            }
            
            if (!empty($filters['search'])) {
                $where[] = "(u.name LIKE :search OR u.nip LIKE :search OR u.username LIKE :search)";
                $params[':search'] = "%{$filters['search']}%";
            }
            
            if (!empty($filters['nip'])) {
                $where[] = "u.nip LIKE :nip";
                $params[':nip'] = "%{$filters['nip']}%";
            }
            
            if (!empty($filters['rw'])) {
                $where[] = "EXISTS (
                    SELECT 1 FROM schedules s 
                    JOIN zones z ON s.zone_id = z.id 
                    WHERE s.user_id = u.id 
                      AND s.work_date = CURRENT_DATE 
                      AND z.rw_code = :rw
                )";
                $params[':rw'] = $filters['rw'];
            }
            
            if (isset($filters['online'])) {
                if ($filters['online'] === 'true') {
                    $where[] = "u.last_location_update IS NOT NULL";
                    $where[] = "u.last_location_update > NOW() - INTERVAL 10 MINUTE";
                } elseif ($filters['online'] === 'false') {
                    $where[] = "(u.last_location_update IS NULL OR u.last_location_update <= NOW() - INTERVAL 10 MINUTE)";
                }
            }
            
            $whereClause = $where ? "WHERE " . implode(" AND ", $where) : "";
            $orderDir = 'ASC';
            if (!empty($filters['sort_dir']) && strtolower($filters['sort_dir']) === 'desc') {
                $orderDir = 'DESC';
            }
            
            $sql = "
                SELECT 
                    u.id, u.nip, u.name, u.username, u.status, u.photo_path, u.phone,
                    u.last_login, u.last_location_update,
                    u.group_id,
                    g.name as group_name,
                    CASE 
                        WHEN u.status = 'online'
                         AND u.last_location_update IS NOT NULL
                         AND u.last_location_update > NOW() - INTERVAL 10 MINUTE
                        THEN TRUE
                        ELSE FALSE
                    END AS online,
                    COALESCE((
                        SELECT z.rw_code 
                        FROM schedules s 
                        JOIN zones z ON s.zone_id = z.id 
                        WHERE s.user_id = u.id 
                        ORDER BY s.work_date DESC 
                        LIMIT 1
                    ), '-') as last_rw
                    ,
                    tz.rw_code as today_rw,
                    tz.name as today_zone,
                    NULL as today_distance_m,
                    NULL as is_outside_zone
                FROM users u
                LEFT JOIN `groups` g ON u.group_id = g.id
                LEFT JOIN (
                    SELECT s3.user_id, z3.rw_code, z3.name
                    FROM schedules s3
                    JOIN zones z3 ON s3.zone_id = z3.id
                    WHERE s3.work_date = CURRENT_DATE
                    AND s3.status = 'active'
                    GROUP BY s3.user_id
                ) tz ON tz.user_id = u.id
                {$whereClause}
                ORDER BY u.name {$orderDir}
            ";
            
            $stmt = $this->pdo->prepare($sql);
            foreach ($params as $key => $value) {
                $stmt->bindValue($key, $value);
            }
            $stmt->execute();
            $rows = $stmt->fetchAll();
            
            return ['success' => true, 'petugas' => $rows];
        } catch (Exception $e) {
            error_log("Get Petugas List Dynamic Error: " . $e->getMessage());
            return ['success' => false, 'error' => 'Gagal mengambil data petugas'];
        }
    }

    
    public function validateLaporan($report_id, $status, $supervisor_id, $notes = '') {
        try {
            $allowed_statuses = ['approved', 'rejected'];
            if (!in_array($status, $allowed_statuses)) {
                return ['success' => false, 'error' => 'Status tidak valid'];
            }
            
            $this->pdo->beginTransaction();
            
            // Update report status
            $stmt = $this->pdo->prepare("
                UPDATE reports 
                SET status = :status,
                    notes = :notes,
                    updated_at = NOW()
                WHERE id = :id
            ");
            
            $stmt->execute([
                ':status' => $status,
                ':notes' => $notes,
                ':id' => $report_id
            ]);
            
            // Log aktivitas
            $this->logActivity($supervisor_id, "Validasi laporan ID: {$report_id} - Status: {$status}");
            
            $this->pdo->commit();
            
            return ['success' => true];
            
        } catch (Exception $e) {
            $this->pdo->rollBack();
            error_log("Validate Laporan Error: " . $e->getMessage());
            return ['success' => false, 'error' => 'Terjadi kesalahan sistem'];
        }
    }
    
    private function generateRandomPassword($length = 8) {
        $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        $password = '';
        for ($i = 0; $i < $length; $i++) {
            $password .= $chars[random_int(0, strlen($chars) - 1)];
        }
        return $password;
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
