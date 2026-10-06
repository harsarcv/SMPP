<?php
require_once '../../config/database.php';

class PetugasController {
    private $pdo;
    
    public function __construct() {
        $this->pdo = Database::getInstance()->getConnection();
    }
    
    public function addPetugas($data, $files) {
        try {
            $this->pdo->beginTransaction();
            
            // Validasi data
            $errors = $this->validatePetugasData($data, $files);
            if (!empty($errors)) {
                return ['success' => false, 'errors' => $errors];
            }
            
            // Cek duplikasi
            if ($this->checkDuplicate($data['nip'], $data['username'])) {
                return ['success' => false, 'errors' => ['username' => 'NIP atau username sudah digunakan']];
            }
            
            // Upload foto
            $photoPath = $this->uploadPhoto($files['photo']);
            if (!$photoPath) {
                return ['success' => false, 'errors' => ['photo' => 'Gagal mengupload foto']];
            }
            
            // Hash password
            $passwordHash = password_hash($data['password'], PASSWORD_DEFAULT);
            
            // Insert ke database
            // Insert ke database
            $stmt = $this->pdo->prepare("
                INSERT INTO users (nip, name, username, password_hash, role, status, 
                                  phone, photo_path, created_at, updated_at)
                VALUES (:nip, :name, :username, :password, 'petugas', 'offline', 
                        :phone, :photo, NOW(), NOW())
            ");
            
            $stmt->execute([
                ':nip' => $data['nip'],
                ':name' => $data['name'],
                ':username' => $data['username'],
                ':password' => $passwordHash,
                ':phone' => $data['phone'] ?? '',
                ':photo' => $photoPath
            ]);
            
            $userId = $this->pdo->lastInsertId();
            
            // Log aktivitas
            $this->logActivity($_SESSION['user_id'], "Tambah petugas baru: {$data['name']}");
            
            $this->pdo->commit();
            
            return ['success' => true, 'user_id' => $userId];
            
        } catch (Exception $e) {
            $this->pdo->rollBack();
            error_log("Add Petugas Error: " . $e->getMessage());
            return ['success' => false, 'errors' => ['system' => 'Terjadi kesalahan sistem']];
        }
    }
    
    public function updatePetugas($id, $data) {
        try {
            $this->pdo->beginTransaction();
            
            // Validasi data
            $errors = $this->validateUpdateData($data);
            if (!empty($errors)) {
                return ['success' => false, 'errors' => $errors];
            }
            
            // Cek duplikasi username (kecuali untuk user ini)
            if ($this->checkDuplicateUsername($id, $data['username'])) {
                return ['success' => false, 'errors' => ['username' => 'Username sudah digunakan']];
            }
            
            // Siapkan query update
            $updates = [];
            $params = [':id' => $id];
            
            if (!empty($data['name'])) {
                $updates[] = "name = :name";
                $params[':name'] = $data['name'];
            }
            
            if (!empty($data['nip'])) {
                $updates[] = "nip = :nip";
                $params[':nip'] = $data['nip'];
            }
            
            if (!empty($data['username'])) {
                $updates[] = "username = :username";
                $params[':username'] = $data['username'];
            }
            
            if (!empty($data['phone'])) {
                $updates[] = "phone = :phone";
                $params[':phone'] = $data['phone'];
            }
            
            if (!empty($data['status'])) {
                // PROTECTION: Admin users cannot be deactivated
                $userStmt = $this->pdo->prepare("SELECT role FROM users WHERE id = :id");
                $userStmt->execute([':id' => $id]);
                $targetUser = $userStmt->fetch();
                
                if ($targetUser && $targetUser['role'] === 'admin') {
                    // Admin can only be online or offline, not nonaktif or cuti
                    if (in_array($data['status'], ['nonaktif', 'cuti'])) {
                        return ['success' => false, 'errors' => ['status' => 'Admin tidak dapat dinonaktifkan atau di-cuti-kan']];
                    }
                }
                
                $updates[] = "status = :status";
                $params[':status'] = $data['status'];
                $updates[] = "last_status_change = NOW()";
            }
            
            if (!empty($data['password'])) {
                $updates[] = "password_hash = :password";
                $params[':password'] = password_hash($data['password'], PASSWORD_DEFAULT);
            }
            
            // Handle role field
            if (!empty($data['role'])) {
                $validRoles = ['admin', 'supervisor', 'petugas'];
                if (in_array($data['role'], $validRoles)) {
                    $updates[] = "role = :role";
                    $params[':role'] = $data['role'];
                }
            }
            
            $updates[] = "updated_at = NOW()";
            
            if (empty($updates)) {
                return ['success' => false, 'errors' => ['general' => 'Tidak ada data untuk diupdate']];
            }
            
            $sql = "UPDATE users SET " . implode(', ', $updates) . " WHERE id = :id";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            
            // Log aktivitas
            $this->logActivity($_SESSION['user_id'], "Update data petugas ID: {$id}");
            
            $this->pdo->commit();
            
            return ['success' => true];
            
        } catch (Exception $e) {
            $this->pdo->rollBack();
            error_log("Update Petugas Error: " . $e->getMessage());
            return ['success' => false, 'errors' => ['system' => 'Terjadi kesalahan sistem']];
        }
    }
    
    public function deactivatePetugas($id) {
        try {
            $stmt = $this->pdo->prepare("
                UPDATE users 
                SET status = 'nonaktif', updated_at = NOW(), last_status_change = NOW()
                WHERE id = :id AND role = 'petugas'
            ");
            
            $stmt->execute([':id' => $id]);
            
            if ($stmt->rowCount() > 0) {
                $this->logActivity($_SESSION['user_id'], "Nonaktifkan petugas ID: {$id}");
                return ['success' => true];
            }
            
            return ['success' => false, 'errors' => ['general' => 'Petugas tidak ditemukan']];
            
        } catch (Exception $e) {
            error_log("Deactivate Petugas Error: " . $e->getMessage());
            return ['success' => false, 'errors' => ['system' => 'Terjadi kesalahan sistem']];
        }
    }
    
    public function deletePetugas($id) {
        try {
            $stmt = $this->pdo->prepare("DELETE FROM users WHERE id = :id AND role = 'petugas'");
            $stmt->execute([':id' => $id]);
            
            if ($stmt->rowCount() > 0) {
                $this->logActivity($_SESSION['user_id'], "Hapus permanen petugas ID: {$id}");
                return ['success' => true];
            }
            
            return ['success' => false, 'errors' => ['general' => 'Petugas tidak ditemukan']];
            
        } catch (Exception $e) {
            error_log("Delete Petugas Error: " . $e->getMessage());
            return ['success' => false, 'errors' => ['system' => 'Terjadi kesalahan sistem']];
        }
    }
    
    public function getPetugasList($filter = [], $page = 1, $limit = 10) {
        try {
            $targetRole = $filter['target_role'] ?? 'petugas';
            
            if (is_array($targetRole)) {
                $placeholders = [];
                foreach ($targetRole as $k => $r) {
                    $key = ":role_$k";
                    $placeholders[] = $key;
                    $params[$key] = $r;
                }
                $where = ["u.role IN (" . implode(', ', $placeholders) . ")"];
            } else {
                $where = ["u.role = :target_role"];
                $params = [':target_role' => $targetRole];
            }
            
            if (!empty($filter['status'])) {
                $where[] = "u.status = :status";
                $params[':status'] = $filter['status'];
            }
            
            if (!empty($filter['search'])) {
                $where[] = "(u.name LIKE :search OR u.nip LIKE :search OR u.username LIKE :search)";
                $params[':search'] = "%{$filter['search']}%";
            }
            
            if (!empty($filter['rw'])) {
                $where[] = "EXISTS (
                    SELECT 1 FROM schedules s 
                    JOIN zones z ON s.zone_id = z.id 
                    WHERE s.user_id = u.id AND z.rw_code = :rw
                )";
                $params[':rw'] = $filter['rw'];
            }

            if (isset($filter['group_id'])) {
                $where[] = "u.group_id = :group_id";
                $params[':group_id'] = $filter['group_id'];
            }
            
            $whereClause = $where ? "WHERE " . implode(" AND ", $where) : "";
            
            // Query data (MySQL Compatible)
            $sql = "
                SELECT u.*,
                       COALESCE(z.rw_code, '-') as last_rw,
                       COALESCE(z.name, 'Belum ada zona') as last_zone,
                       CASE WHEN u.last_login > NOW() - INTERVAL 30 MINUTE 
                            THEN 'Online' 
                            ELSE 'Offline' 
                       END as online_status,
                       tz.rw_code as today_rw,
                       tz.name as today_zone,
                       NULL as today_distance_m,
                       NULL as is_outside_zone
                FROM users u
                LEFT JOIN (
                    SELECT s1.user_id, s1.zone_id
                    FROM schedules s1
                    WHERE s1.id = (
                        SELECT s2.id 
                        FROM schedules s2 
                        WHERE s2.user_id = s1.user_id 
                        ORDER BY s2.work_date DESC, s2.id DESC 
                        LIMIT 1
                    )
                ) s_latest ON s_latest.user_id = u.id
                LEFT JOIN zones z ON s_latest.zone_id = z.id
                LEFT JOIN (
                    SELECT s3.user_id, z3.rw_code, z3.name
                    FROM schedules s3
                    JOIN zones z3 ON s3.zone_id = z3.id
                    WHERE s3.work_date = CURRENT_DATE
                    AND s3.status = 'active'
                    GROUP BY s3.user_id
                ) tz ON tz.user_id = u.id
                {$whereClause}
                ORDER BY u.status DESC, u.name ASC
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
                FROM users u
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
            error_log("Get Petugas List Error: " . $e->getMessage());
            return ['success' => false, 'error' => 'Gagal mengambil data'];
        }
    }
    
    public function getPetugasDetail($id) {
        try {
            $stmt = $this->pdo->prepare("
                SELECT u.*,
                       k.presence_rate, k.completion_rate, k.punctuality_rate, k.score_total,
                       (SELECT COUNT(*) FROM reports WHERE user_id = u.id) as total_reports,
                       (SELECT COUNT(*) FROM schedules WHERE user_id = u.id AND work_date = CURRENT_DATE) as today_tasks,
                       (SELECT COUNT(*) FROM reports WHERE user_id = u.id AND work_date = CURRENT_DATE) as today_reports
                FROM users u
                LEFT JOIN kpi_scores k ON k.user_id = u.id 
                    AND k.effective_date = (SELECT MAX(effective_date) FROM kpi_scores WHERE user_id = u.id)
                WHERE u.id = :id AND u.role IN ('petugas', 'supervisor')
            ");
            
            $stmt->execute([':id' => $id]);
            $petugas = $stmt->fetch();
            
            if (!$petugas) {
                return ['success' => false, 'error' => 'Petugas tidak ditemukan'];
            }
            
            // Ambil jadwal terbaru
            $scheduleStmt = $this->pdo->prepare("
                SELECT s.*, z.name as zone_name, z.rw_code
                FROM schedules s
                JOIN zones z ON s.zone_id = z.id
                WHERE s.user_id = :id
                ORDER BY s.work_date DESC
                LIMIT 5
            ");
            
            $scheduleStmt->execute([':id' => $id]);
            $schedules = $scheduleStmt->fetchAll();
            
            // Ambil laporan terbaru
            $reportStmt = $this->pdo->prepare("
                SELECT r.*, z.name as zone_name, z.rw_code
                FROM reports r
                JOIN zones z ON r.zone_id = z.id
                WHERE r.user_id = :id
                ORDER BY r.created_at DESC
                LIMIT 5
            ");
            
            $reportStmt->execute([':id' => $id]);
            $reports = $reportStmt->fetchAll();
            
            return [
                'success' => true,
                'petugas' => $petugas,
                'schedules' => $schedules,
                'reports' => $reports
            ];
            
        } catch (Exception $e) {
            error_log("Get Petugas Detail Error: " . $e->getMessage());
            return ['success' => false, 'error' => 'Gagal mengambil detail petugas'];
        }
    }
    
    private function validatePetugasData($data, $files) {
        $errors = [];
        
        if (empty($data['name'])) {
            $errors['name'] = 'Nama wajib diisi';
        } elseif (strlen($data['name']) < 3) {
            $errors['name'] = 'Nama minimal 3 karakter';
        }
        
        if (empty($data['nip'])) {
            $errors['nip'] = 'NIP wajib diisi';
        } elseif (!ctype_digit((string)$data['nip'])) {
            $errors['nip'] = 'NIP harus berupa angka saja';
        }

        if (!empty($data['phone'])) {
            if (!ctype_digit((string)$data['phone'])) {
                $errors['phone'] = 'Nomor HP harus berupa angka saja';
            } elseif (strlen((string)$data['phone']) > 15) {
                $errors['phone'] = 'Nomor HP maksimal 15 angka';
            }
        }
        
        if (empty($data['username'])) {
            $errors['username'] = 'Username wajib diisi';
        } elseif (strlen($data['username']) < 3) {
            $errors['username'] = 'Username minimal 3 karakter';
        }
        
        if (empty($data['password'])) {
            $errors['password'] = 'Password wajib diisi';
        } elseif (strlen($data['password']) < 6) {
            $errors['password'] = 'Password minimal 6 karakter';
        }
        
        if (isset($files['photo']) && $files['photo']['error'] === UPLOAD_ERR_OK) {
            $allowed = ['jpg', 'jpeg', 'png'];
            $ext = strtolower(pathinfo($files['photo']['name'], PATHINFO_EXTENSION));
            
            if (!in_array($ext, $allowed)) {
                $errors['photo'] = 'Format foto harus JPG atau PNG';
            }
            
            if ($files['photo']['size'] > 2 * 1024 * 1024) {
                $errors['photo'] = 'Ukuran foto maksimal 2MB';
            }
        }
        
        return $errors;
    }
    
    private function validateUpdateData($data) {
        $errors = [];
        
        if (!empty($data['name']) && strlen($data['name']) < 3) {
            $errors['name'] = 'Nama minimal 3 karakter';
        }
        
        if (!empty($data['nip']) && !ctype_digit((string)$data['nip'])) {
            $errors['nip'] = 'NIP harus berupa angka saja';
        }

        if (!empty($data['phone'])) {
            if (!ctype_digit((string)$data['phone'])) {
                $errors['phone'] = 'Nomor HP harus berupa angka saja';
            } elseif (strlen((string)$data['phone']) > 15) {
                $errors['phone'] = 'Nomor HP maksimal 15 angka';
            }
        }
        
        if (!empty($data['username']) && strlen($data['username']) < 3) {
            $errors['username'] = 'Username minimal 3 karakter';
        }
        
        if (!empty($data['password']) && strlen($data['password']) < 6) {
            $errors['password'] = 'Password minimal 6 karakter';
        }
        
        return $errors;
    }
    
    private function checkDuplicate($nip, $username) {
        $stmt = $this->pdo->prepare("
            SELECT COUNT(*) 
            FROM users 
            WHERE nip = :nip OR username = :username
        ");
        
        $stmt->execute([
            ':nip' => $nip,
            ':username' => $username
        ]);
        
        return $stmt->fetchColumn() > 0;
    }
    
    private function checkDuplicateUsername($id, $username) {
        $stmt = $this->pdo->prepare("
            SELECT COUNT(*) 
            FROM users 
            WHERE username = :username AND id != :id
        ");
        
        $stmt->execute([
            ':username' => $username,
            ':id' => $id
        ]);
        
        return $stmt->fetchColumn() > 0;
    }
    
    private function uploadPhoto($file) {
        $uploadDir = __DIR__ . '/../../../public/uploads/users/';
        
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0775, true);
        }
        
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $filename = 'petugas_' . time() . '_' . mt_rand(1000, 9999) . '.jpg';
        $destination = $uploadDir . $filename;
        
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
        $targetSize = 700;
        
        $canvas = imagecreatetruecolor($targetSize, $targetSize);
        $white = imagecolorallocate($canvas, 255, 255, 255);
        imagefill($canvas, 0, 0, $white);
        
        $scale = min($targetSize / $origW, $targetSize / $origH);
        $newW = (int)round($origW * $scale);
        $newH = (int)round($origH * $scale);
        
        $offsetX = (int)floor(($targetSize - $newW) / 2);
        $offsetY = (int)floor(($targetSize - $newH) / 2);
        
        imagecopyresampled($canvas, $srcImage, $offsetX, $offsetY, 0, 0, $newW, $newH, $origW, $origH);
        imagedestroy($srcImage);
        
        if (imagejpeg($canvas, $destination, 85)) {
            imagedestroy($canvas);
            return 'users/' . $filename;
        }
        
        imagedestroy($canvas);
        return false;
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
