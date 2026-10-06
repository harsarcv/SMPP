-- Optimize Database for 200+ Users
-- Add indexes to improve query performance

-- 1. Users Table
-- Used for filtering by role (dashboard stats) and status (active users)
SET @schema = DATABASE();
SET @exists = (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE table_schema=@schema AND table_name='users' AND index_name='idx_users_role');
SET @sql = IF(@exists=0, 'CREATE INDEX idx_users_role ON users(role)', 'SELECT 1'); PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @exists = (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE table_schema=@schema AND table_name='users' AND index_name='idx_users_status');
SET @sql = IF(@exists=0, 'CREATE INDEX idx_users_status ON users(status)', 'SELECT 1'); PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @exists = (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE table_schema=@schema AND table_name='users' AND index_name='idx_users_last_login');
SET @sql = IF(@exists=0, 'CREATE INDEX idx_users_last_login ON users(last_login)', 'SELECT 1'); PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 2. Reports Table
-- Used for dashboard stats (today's reports) and history logs
SET @exists = (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE table_schema=@schema AND table_name='reports' AND index_name='idx_reports_work_date');
SET @sql = IF(@exists=0, 'CREATE INDEX idx_reports_work_date ON reports(work_date)', 'SELECT 1'); PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @exists = (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE table_schema=@schema AND table_name='reports' AND index_name='idx_reports_created_at');
SET @sql = IF(@exists=0, 'CREATE INDEX idx_reports_created_at ON reports(created_at)', 'SELECT 1'); PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @exists = (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE table_schema=@schema AND table_name='reports' AND index_name='idx_reports_status');
SET @sql = IF(@exists=0, 'CREATE INDEX idx_reports_status ON reports(status)', 'SELECT 1'); PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @exists = (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE table_schema=@schema AND table_name='reports' AND index_name='idx_reports_user_id');
SET @sql = IF(@exists=0, 'CREATE INDEX idx_reports_user_id ON reports(user_id)', 'SELECT 1'); PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @exists = (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE table_schema=@schema AND table_name='reports' AND index_name='idx_reports_zone_id');
SET @sql = IF(@exists=0, 'CREATE INDEX idx_reports_zone_id ON reports(zone_id)', 'SELECT 1'); PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 3. Schedules Table
-- Used for checking today's schedule and filtering
SET @exists = (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE table_schema=@schema AND table_name='schedules' AND index_name='idx_schedules_work_date');
SET @sql = IF(@exists=0, 'CREATE INDEX idx_schedules_work_date ON schedules(work_date)', 'SELECT 1'); PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @exists = (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE table_schema=@schema AND table_name='schedules' AND index_name='idx_schedules_user_id');
SET @sql = IF(@exists=0, 'CREATE INDEX idx_schedules_user_id ON schedules(user_id)', 'SELECT 1'); PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @exists = (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE table_schema=@schema AND table_name='schedules' AND column_name='zone_id');
SET @sql = IF(@exists=0, 'CREATE INDEX idx_schedules_zone_id ON schedules(zone_id)', 'SELECT 1'); PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @exists = (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE table_schema=@schema AND table_name='schedules' AND index_name='idx_schedules_status');
SET @sql = IF(@exists=0, 'CREATE INDEX idx_schedules_status ON schedules(status)', 'SELECT 1'); PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 4. Activity Logs
-- Used for recent updates on dashboard
SET @exists = (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE table_schema=@schema AND table_name='activity_logs' AND column_name='created_at');
SET @sql = IF(@exists=0, 'CREATE INDEX idx_activity_logs_created_at ON activity_logs(created_at)', 'SELECT 1'); PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @exists = (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE table_schema=@schema AND table_name='activity_logs' AND column_name='activity');
SET @sql = IF(@exists=0, 'CREATE INDEX idx_activity_logs_activity ON activity_logs(activity(50))', 'SELECT 1'); PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 5. Zones
-- Used for lookups
SET @exists = (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE table_schema=@schema AND table_name='zones' AND index_name='idx_zones_rw_code');
SET @sql = IF(@exists=0, 'CREATE INDEX idx_zones_rw_code ON zones(rw_code)', 'SELECT 1'); PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 6. Password Resets
-- Used for pending requests
SET @exists = (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE table_schema=@schema AND table_name='password_resets' AND index_name='idx_password_resets_status');
SET @sql = IF(@exists=0, 'CREATE INDEX idx_password_resets_status ON password_resets(status)', 'SELECT 1'); PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @exists = (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE table_schema=@schema AND table_name='password_resets' AND column_name='created_at');
SET @sql = IF(@exists=0, 'CREATE INDEX idx_password_resets_created_at ON password_resets(created_at)', 'SELECT 1'); PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 7. Add Missing AI Columns (Safe Update)
-- Reports: ai_is_valid
SET @exists = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE table_schema=@schema AND table_name='reports' AND column_name='ai_is_valid');
SET @sql = IF(@exists=0, 'ALTER TABLE reports ADD COLUMN ai_is_valid TINYINT(1) DEFAULT 0', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Reports: ai_score
SET @exists = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE table_schema=@schema AND table_name='reports' AND column_name='ai_score');
SET @sql = IF(@exists=0, 'ALTER TABLE reports ADD COLUMN ai_score FLOAT DEFAULT 0', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Reports: reject_reason
SET @exists = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE table_schema=@schema AND table_name='reports' AND column_name='reject_reason');
SET @sql = IF(@exists=0, 'ALTER TABLE reports ADD COLUMN reject_reason TEXT NULL', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Report Photos: ai_description
SET @exists = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE table_schema=@schema AND table_name='report_photos' AND column_name='ai_description');
SET @sql = IF(@exists=0, 'ALTER TABLE report_photos ADD COLUMN ai_description TEXT NULL', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Report Photos: ai_is_valid_work
SET @exists = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE table_schema=@schema AND table_name='report_photos' AND column_name='ai_is_valid_work');
SET @sql = IF(@exists=0, 'ALTER TABLE report_photos ADD COLUMN ai_is_valid_work TINYINT(1) DEFAULT 0', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
