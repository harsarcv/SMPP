<?php
/**
 * SupervisorWorkController.php
 * Handles Supervisor work status (Start/Stop) functionality.
 * 
 * 4-Status System:
 * - online: Currently working, GPS active
 * - offline: Not working, can start anytime
 * - nonaktif: Deactivated by Admin
 * - cuti: On leave
 */
require_once __DIR__ . '/../../config/database.php';

class SupervisorWorkController {
    private $pdo;
    
    public function __construct() {
        $this->pdo = Database::getInstance()->getConnection();
    }
    
    /**
     * Update work status (Start/Stop)
     * Start: offline -> online
     * Stop: online -> offline
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
            
            // Validate action based on current status
            if ($action === 'start') {
                // Can only start if currently offline
                if ($currentStatus === 'nonaktif') {
                    return ['success' => false, 'error' => 'Akun Anda nonaktif. Hubungi Admin untuk aktivasi.'];
                }
                if ($currentStatus === 'cuti') {
                    return ['success' => false, 'error' => 'Anda sedang cuti. Hubungi Admin untuk mengakhiri cuti.'];
                }
                if ($currentStatus === 'online') {
                    return ['success' => true, 'status' => 'online', 'message' => 'Anda sudah online'];
                }
                // offline -> online
                $newStatus = 'online';
            } elseif ($action === 'stop') {
                // Can only stop if currently online
                if ($currentStatus !== 'online') {
                    return ['success' => true, 'status' => $currentStatus, 'message' => 'Status tidak berubah'];
                }
                // online -> offline
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
            $activity = ($newStatus === 'online') ? 'Memulai pemantauan (Online)' : 'Menghentikan pemantauan (Offline)';
            $this->logActivity($user_id, $activity);
            
            $this->pdo->commit();
            
            return ['success' => true, 'status' => $newStatus];
            
        } catch (Exception $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            error_log("Update Work Status Error: " . $e->getMessage());
            return ['success' => false, 'error' => 'Terjadi kesalahan sistem: ' . $e->getMessage()];
        }
    }

    private function logActivity($userId, $activity) {
        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO activity_logs (user_id, activity, ip_address, created_at)
                VALUES (:user_id, :activity, :ip, NOW())
            ");
            
            $stmt->execute([
                ':user_id' => $userId,
                ':activity' => $activity,
                ':ip' => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'
            ]);
        } catch (Exception $e) {
            error_log("Log Activity Error: " . $e->getMessage());
        }
    }
}
