<?php
require_once '../../config/database.php';

class ReportController {
    private $pdo;
    
    public function __construct() {
        $this->pdo = Database::getInstance()->getConnection();
    }
    
    public function getReportList($filter = [], $page = 1, $limit = 20) {
        try {
            $where = [];
            $params = [];
            
            // Filter berdasarkan tanggal (default 30 hari terakhir)
            if (!empty($filter['start_date'])) {
                $where[] = "r.work_date >= :start_date";
                $params[':start_date'] = $filter['start_date'];
            } else {
                $where[] = "r.work_date >= CURRENT_DATE - INTERVAL 30 DAY";
            }
            
            if (!empty($filter['end_date'])) {
                $where[] = "r.work_date <= :end_date";
                $params[':end_date'] = $filter['end_date'];
            }
            
            if (!empty($filter['user_id'])) {
                $where[] = "r.user_id = :user_id";
                $params[':user_id'] = $filter['user_id'];
            }
            
            if (!empty($filter['zone_id'])) {
                $where[] = "r.zone_id = :zone_id";
                $params[':zone_id'] = $filter['zone_id'];
            }
            
            if (!empty($filter['rw'])) {
                $where[] = "z.rw_code = :rw";
                $params[':rw'] = $filter['rw'];
            }
            
            if (!empty($filter['status'])) {
                $where[] = "r.status = :status";
                $params[':status'] = $filter['status'];
            }
            
            if (!empty($filter['gps_result'])) {
                $where[] = "r.gps_result = :gps_result";
                $params[':gps_result'] = $filter['gps_result'];
            }
            
            if (!empty($filter['search'])) {
                $where[] = "(u.name LIKE :search OR u.nip LIKE :search OR z.name LIKE :search)";
                $params[':search'] = "%{$filter['search']}%";
            }
            
            $whereClause = $where ? "WHERE " . implode(" AND ", $where) : "";
            
            // Query data
            $sql = "
                SELECT r.*,
                       u.name as petugas_name,
                       u.nip as petugas_nip,
                       z.name as zone_name,
                       z.rw_code,
                       DATE_FORMAT(r.created_at, '%d/%m/%Y %H:%i') as created_at_formatted,
                       (SELECT COUNT(*) FROM report_photos WHERE report_id = r.id) as photo_count
                FROM reports r
                JOIN users u ON r.user_id = u.id
                JOIN zones z ON r.zone_id = z.id
                {$whereClause}
                ORDER BY r.created_at DESC
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
            
            // Count total
            $countSql = "
                SELECT COUNT(*) 
                FROM reports r
                JOIN users u ON r.user_id = u.id
                JOIN zones z ON r.zone_id = z.id
                {$whereClause}
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
            error_log("Get Report List Error: " . $e->getMessage());
            return ['success' => false, 'error' => 'Gagal mengambil data laporan'];
        }
    }
    
    public function getReportDetail($id) {
        try {
            // Get report basic info
            $stmt = $this->pdo->prepare("
                SELECT r.*,
                       u.name as petugas_name,
                       u.nip as petugas_nip,
                       u.phone as petugas_phone,
                       z.name as zone_name,
                       z.rw_code,
                       z.address as zone_address,
                       s.start_time, s.end_time
                FROM reports r
                JOIN users u ON r.user_id = u.id
                JOIN zones z ON r.zone_id = z.id
                LEFT JOIN schedules s ON r.schedule_id = s.id
                WHERE r.id = :id
            ");
            
            $stmt->execute([':id' => $id]);
            $report = $stmt->fetch();
            
            if (!$report) {
                return ['success' => false, 'error' => 'Laporan tidak ditemukan'];
            }
            
            // Get photos
            $photoStmt = $this->pdo->prepare("
                SELECT * FROM report_photos 
                WHERE report_id = :report_id 
                ORDER BY sequence_no ASC
            ");
            
            $photoStmt->execute([':report_id' => $id]);
            $photos = $photoStmt->fetchAll();
            
            // Get route points
            $routeStmt = $this->pdo->prepare("
                SELECT * FROM report_route_points 
                WHERE report_id = :report_id 
                ORDER BY seq ASC
            ");
            
            $routeStmt->execute([':report_id' => $id]);
            $route_points = $routeStmt->fetchAll();
            
            // Calculate GPS accuracy if needed
            $gps_analysis = $this->analyzeGPS($report, $route_points);
            
            return [
                'success' => true,
                'report' => $report,
                'photos' => $photos,
                'route_points' => $route_points,
                'gps_analysis' => $gps_analysis
            ];
            
        } catch (Exception $e) {
            error_log("Get Report Detail Error: " . $e->getMessage());
            return ['success' => false, 'error' => 'Gagal mengambil detail laporan'];
        }
    }
    
    public function updateReportStatus($id, $status, $notes = '') {
        try {
            $this->pdo->beginTransaction();
            
            $allowed_statuses = ['pending', 'approved', 'rejected', 'completed'];
            if (!in_array($status, $allowed_statuses)) {
                return ['success' => false, 'error' => 'Status tidak valid'];
            }
            
            // Ambil user_id laporan terlebih dahulu
            $getUserStmt = $this->pdo->prepare("SELECT user_id FROM reports WHERE id = :id");
            $getUserStmt->execute([':id' => $id]);
            $user_id = $getUserStmt->fetchColumn();
            
            if (!$user_id) {
                return ['success' => false, 'error' => 'Laporan tidak ditemukan'];
            }
            
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
                ':id' => $id
            ]);
            
            // Jika status approved, hitung KPI
            if ($status === 'approved') {
                $this->calculateKPI($user_id, $id);
            }
            
            // Log aktivitas
            $this->logActivity($_SESSION['user_id'], "Update status laporan ID: {$id} menjadi {$status}");
            
            $this->pdo->commit();
            
            return ['success' => true];
            
        } catch (Exception $e) {
            $this->pdo->rollBack();
            error_log("Update Report Status Error: " . $e->getMessage());
            return ['success' => false, 'error' => 'Terjadi kesalahan sistem'];
        }
    }
    
    public function getReportStatistics($period = 'today') {
        try {
            $stats = [];
            $date_condition = '';
            
            switch ($period) {
                case 'today':
                    $date_condition = "WHERE work_date = CURRENT_DATE";
                    break;
                case 'week':
                    $date_condition = "WHERE work_date >= CURRENT_DATE - INTERVAL 7 DAY";
                    break;
                case 'month':
                    $date_condition = "WHERE work_date >= CURRENT_DATE - INTERVAL 30 DAY";
                    break;
                default:
                    $date_condition = "WHERE work_date = CURRENT_DATE";
            }
            
            // Total reports
            $stmt = $this->pdo->query("SELECT COUNT(*) as total FROM reports {$date_condition}");
            $stats['total_reports'] = (int)$stmt->fetchColumn();
            
            // Reports by status
            $stmt = $this->pdo->query("
                SELECT status, COUNT(*) as count 
                FROM reports 
                {$date_condition}
                GROUP BY status
                ORDER BY status
            ");
            
            $status_counts = $stmt->fetchAll();
            $stats['by_status'] = $status_counts;
            
            // Reports by GPS result
            $stmt = $this->pdo->query("
                SELECT gps_result, COUNT(*) as count 
                FROM reports 
                {$date_condition}
                GROUP BY gps_result
                ORDER BY gps_result
            ");
            
            $gps_counts = $stmt->fetchAll();
            $stats['by_gps_result'] = $gps_counts;
            
            // Top petugas dengan laporan terbanyak
            $stmt = $this->pdo->prepare("
                SELECT u.name, u.nip, COUNT(*) as report_count
                FROM reports r
                JOIN users u ON r.user_id = u.id
                {$date_condition}
                GROUP BY u.id, u.name, u.nip
                ORDER BY report_count DESC
                LIMIT 5
            ");
            
            $stmt->execute();
            $top_petugas = $stmt->fetchAll();
            $stats['top_petugas'] = $top_petugas;
            
            // Reports by zone
            $stmt = $this->pdo->prepare("
                SELECT z.rw_code, z.name, COUNT(*) as report_count
                FROM reports r
                JOIN zones z ON r.zone_id = z.id
                {$date_condition}
                GROUP BY z.id, z.rw_code, z.name
                ORDER BY report_count DESC
                LIMIT 10
            ");
            
            $stmt->execute();
            $by_zone = $stmt->fetchAll();
            $stats['by_zone'] = $by_zone;
            
            // Average reports per day
            if ($period === 'month') {
                $stmt = $this->pdo->query("
                    SELECT COUNT(*) / 30.0 as avg_per_day
                    FROM reports 
                    WHERE work_date >= CURRENT_DATE - INTERVAL 30 DAY
                ");
                
                $avg = $stmt->fetchColumn();
                $stats['avg_reports_per_day'] = round($avg, 1);
            }
            
            return ['success' => true, 'stats' => $stats];
            
        } catch (Exception $e) {
            error_log("Get Report Statistics Error: " . $e->getMessage());
            return ['success' => false, 'error' => 'Gagal mengambil statistik laporan'];
        }
    }
    
    public function exportReports($filter = [], $format = 'csv') {
        try {
            $where = [];
            $params = [];
            
            // Filter seperti getReportList
            if (!empty($filter['start_date'])) {
                $where[] = "r.work_date >= :start_date";
                $params[':start_date'] = $filter['start_date'];
            }
            
            if (!empty($filter['end_date'])) {
                $where[] = "r.work_date <= :end_date";
                $params[':end_date'] = $filter['end_date'];
            }
            
            if (!empty($filter['user_id'])) {
                $where[] = "r.user_id = :user_id";
                $params[':user_id'] = $filter['user_id'];
            }
            
            if (!empty($filter['zone_id'])) {
                $where[] = "r.zone_id = :zone_id";
                $params[':zone_id'] = $filter['zone_id'];
            }
            
            if (!empty($filter['status'])) {
                $where[] = "r.status = :status";
                $params[':status'] = $filter['status'];
            }
            
            $whereClause = $where ? "WHERE " . implode(" AND ", $where) : "";
            
            // Query untuk export
            $sql = "
                SELECT 
                    r.id as report_id,
                    u.nip as petugas_nip,
                    u.name as petugas_nama,
                    z.rw_code,
                    z.name as zona_nama,
                    r.work_date,
                    r.session,
                    r.status,
                    r.gps_result,
                    DATE_FORMAT(r.created_at, '%d/%m/%Y %H:%i') as waktu_laporan,
                    r.latitude,
                    r.longitude
                FROM reports r
                JOIN users u ON r.user_id = u.id
                JOIN zones z ON r.zone_id = z.id
                {$whereClause}
                ORDER BY r.work_date DESC, r.created_at DESC
            ";
            
            $stmt = $this->pdo->prepare($sql);
            
            foreach ($params as $key => $value) {
                $stmt->bindValue($key, $value);
            }
            
            $stmt->execute();
            $data = $stmt->fetchAll();
            
            // Generate file berdasarkan format
            $filename = "laporan_ppsu_" . date('Ymd_His');
            
            if ($format === 'csv') {
                return $this->generateCSV($data, $filename);
            } elseif ($format === 'excel') {
                return $this->generateExcel($data, $filename);
            } elseif ($format === 'json') {
                return $this->generateJSON($data, $filename);
            } else {
                return $this->generateCSV($data, $filename);
            }
            
        } catch (Exception $e) {
            error_log("Export Reports Error: " . $e->getMessage());
            return ['success' => false, 'error' => 'Gagal mengekspor data'];
        }
    }
    
    private function analyzeGPS($report, $route_points) {
        $analysis = [
            'accuracy' => 'unknown',
            'distance_from_zone' => null,
            'points_consistency' => null
        ];

        // Jika ada koordinat GPS
        if (!empty($report['latitude']) && !empty($report['longitude'])) {
            $lat = (float)$report['latitude'];
            $lng = (float)$report['longitude'];
            
            // Hitung jarak dari centroid zona (jika ada zona geojson)
            if (!empty($report['zone_geojson'])) {
                $zone_data = json_decode($report['zone_geojson'], true);
                // Sederhana: hitung jarak dari titik pertama polygon
                if (isset($zone_data['coordinates'][0][0])) {
                    $zone_lat = $zone_data['coordinates'][0][0][1];
                    $zone_lng = $zone_data['coordinates'][0][0][0];
                    
                    $distance = $this->calculateDistance($lat, $lng, $zone_lat, $zone_lng);
                    $analysis['distance_from_zone'] = round($distance, 2);
                    
                    if ($distance > 1000) {
                        $analysis['accuracy'] = 'suspicious';
                    } else {
                        $analysis['accuracy'] = 'valid';
                    }
                }
            } else {
                 // Skip zone analysis if no geometry available
                 $analysis['accuracy'] = 'valid';
            }
        }
        
        // Analisis konsistensi route points
        if (count($route_points) > 1) {
            $total_distance = 0;
            $points = [];
            
            foreach ($route_points as $point) {
                if (isset($point['latitude'], $point['longitude'])) {
                    $points[] = [
                        'lat' => (float)$point['latitude'],
                        'lng' => (float)$point['longitude'],
                        'time' => strtotime($point['recorded_at'])
                    ];
                }
            }
            
            // Hitung total jarak dan kecepatan
            for ($i = 1; $i < count($points); $i++) {
                $distance = $this->calculateDistance(
                    $points[$i-1]['lat'], $points[$i-1]['lng'],
                    $points[$i]['lat'], $points[$i]['lng']
                );
                
                $time_diff = $points[$i]['time'] - $points[$i-1]['time'];
                $speed = ($time_diff > 0) ? ($distance / $time_diff) * 3.6 : 0; // km/h
                
                $total_distance += $distance;
                
                // Jika kecepatan tidak wajar (lebih dari 20 km/jam untuk jalan kaki)
                if ($speed > 20) {
                    $analysis['accuracy'] = 'suspicious';
                }
            }
            
            $analysis['points_consistency'] = [
                'total_distance' => round($total_distance, 2),
                'point_count' => count($points)
            ];
        }
        
        return $analysis;
    }
    
    private function calculateKPI($user_id, $report_id) {
        try {
            // Ambil konfigurasi KPI terbaru
            $configStmt = $this->pdo->prepare("
                SELECT * FROM kpi_configs 
                WHERE effective_from <= CURRENT_DATE 
                AND (effective_to IS NULL OR effective_to >= CURRENT_DATE)
                ORDER BY effective_from DESC
                LIMIT 1
            ");
            
            $configStmt->execute();
            $config = $configStmt->fetch();
            
            if (!$config) {
                error_log("KPI config not found for user {$user_id}");
                return;
            }
            
            // Hitung metrik untuk periode tertentu (misal: 30 hari terakhir)
            $start_date = date('Y-m-d', strtotime('-30 days'));
            
            // Kehadiran: persentase hari kerja dengan laporan
            // Total hari kerja aktif
            $totalDaysStmt = $this->pdo->prepare("
                SELECT COUNT(DISTINCT work_date) 
                FROM schedules 
                WHERE user_id = :user_id 
                AND work_date BETWEEN :start_date AND CURRENT_DATE
                AND status = 'active'
            ");
            $totalDaysStmt->execute([
                ':user_id' => $user_id,
                ':start_date' => $start_date
            ]);
            $total_days = (int)$totalDaysStmt->fetchColumn();
            
            // Hari yang dilaporkan (approved)
            $reportedDaysStmt = $this->pdo->prepare("
                SELECT COUNT(DISTINCT work_date) 
                FROM reports 
                WHERE user_id = :user_id 
                AND work_date BETWEEN :start_date AND CURRENT_DATE
                AND status = 'approved'
            ");
            $reportedDaysStmt->execute([
                ':user_id' => $user_id,
                ':start_date' => $start_date
            ]);
            $reported_days = (int)$reportedDaysStmt->fetchColumn();
            
            $attendance = [
                'total_days' => $total_days,
                'reported_days' => $reported_days
            ];
            $attendance_rate = ($attendance['total_days'] > 0) 
                ? ($attendance['reported_days'] / $attendance['total_days']) * 100 
                : 0;
            
            // Penyelesaian: persentase tugas yang dilaporkan
            $totalTasksStmt = $this->pdo->prepare("
                SELECT COUNT(*) 
                FROM schedules 
                WHERE user_id = :user_id 
                AND work_date BETWEEN :start_date AND CURRENT_DATE
                AND status = 'active'
            ");
            $totalTasksStmt->execute([
                ':user_id' => $user_id,
                ':start_date' => $start_date
            ]);
            $total_tasks = (int)$totalTasksStmt->fetchColumn();
            
            $completedTasksStmt = $this->pdo->prepare("
                SELECT COUNT(DISTINCT schedule_id) 
                FROM reports 
                WHERE user_id = :user_id 
                AND work_date BETWEEN :start_date AND CURRENT_DATE
                AND status = 'approved'
                AND schedule_id IS NOT NULL
            ");
            $completedTasksStmt->execute([
                ':user_id' => $user_id,
                ':start_date' => $start_date
            ]);
            $completed_tasks = (int)$completedTasksStmt->fetchColumn();
            
            $completion = [
                'total_tasks' => $total_tasks,
                'completed_tasks' => $completed_tasks
            ];
            $completion_rate = ($completion['total_tasks'] > 0) 
                ? ($completion['completed_tasks'] / $completion['total_tasks']) * 100 
                : 0;
            
            // Ketepatan waktu: persentase laporan tepat waktu
            $punctualityStmt = $this->pdo->prepare("
                SELECT 
                    COUNT(*) as total,
                    SUM(CASE WHEN TIMESTAMPDIFF(HOUR, TIMESTAMP(s.work_date, s.start_time), r.created_at) <= 1 THEN 1 ELSE 0 END) as on_time
                FROM schedules s
                JOIN reports r 
                    ON r.schedule_id = s.id 
                    AND r.user_id = s.user_id 
                    AND r.work_date = s.work_date
                WHERE s.user_id = :user_id
                  AND s.work_date BETWEEN :start_date AND CURRENT_DATE
                  AND s.status = 'active'
                  AND r.status = 'approved'
                  AND r.created_at IS NOT NULL
            ");
            
            $punctualityStmt->execute([
                ':user_id' => $user_id,
                ':start_date' => $start_date
            ]);
            
            $punctuality = $punctualityStmt->fetch();
            $punctuality_rate = ($punctuality['total'] > 0) 
                ? ($punctuality['on_time'] / $punctuality['total']) * 100 
                : 0;
            
            // Hitung skor total
            $score_total = (
                ($attendance_rate * $config['weight_presence'] / 100) +
                ($completion_rate * $config['weight_completion'] / 100) +
                ($punctuality_rate * $config['weight_punctuality'] / 100)
            );
            
            // Simpan ke kpi_scores
            $kpiStmt = $this->pdo->prepare("
                INSERT INTO kpi_scores 
                (user_id, report_id, presence_rate, completion_rate, 
                 punctuality_rate, score_total, effective_date, calculated_at)
                VALUES 
                (:user_id, :report_id, :presence, :completion, 
                 :punctuality, :score, CURRENT_DATE, NOW())
                ON DUPLICATE KEY UPDATE
                    presence_rate = VALUES(presence_rate),
                    completion_rate = VALUES(completion_rate),
                    punctuality_rate = VALUES(punctuality_rate),
                    score_total = VALUES(score_total),
                    calculated_at = NOW()
            ");
            
            $kpiStmt->execute([
                ':user_id' => $user_id,
                ':report_id' => $report_id,
                ':presence' => $attendance_rate,
                ':completion' => $completion_rate,
                ':punctuality' => $punctuality_rate,
                ':score' => $score_total
            ]);
            
            // Cek jika skor di bawah minimum, nonaktifkan petugas
            if ($score_total < $config['min_score_active']) {
                $updateStmt = $this->pdo->prepare("
                    UPDATE users 
                    SET status = 'nonaktif', 
                        last_status_change = NOW(),
                        updated_at = NOW()
                    WHERE id = :user_id 
                    AND status = 'aktif'
                ");
                
                $updateStmt->execute([':user_id' => $user_id]);
            }
            
        } catch (Exception $e) {
            error_log("Calculate KPI Error: " . $e->getMessage());
        }
    }
    
    private function calculateDistance($lat1, $lon1, $lat2, $lon2) {
        $earth_radius = 6371000;
        
        $lat1 = deg2rad($lat1);
        $lon1 = deg2rad($lon1);
        $lat2 = deg2rad($lat2);
        $lon2 = deg2rad($lon2);
        
        $dlat = $lat2 - $lat1;
        $dlon = $lon2 - $lon1;
        
        $a = sin($dlat/2) * sin($dlat/2) + 
             cos($lat1) * cos($lat2) * 
             sin($dlon/2) * sin($dlon/2);
        
        $c = 2 * atan2(sqrt($a), sqrt(1-$a));
        
        return $earth_radius * $c;
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
        // Implementasi sederhana untuk Excel
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
    
    private function generateJSON($data, $filename) {
        header('Content-Type: application/json');
        header('Content-Disposition: attachment; filename="' . $filename . '.json"');
        
        echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
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
