-- =========================================================
-- DATABASE SCHEMA FOR PPSU APP
-- Complete schema including all enhancements
-- =========================================================

-- =========================================================
-- 1. TABLES
-- =========================================================

-- USERS table
CREATE TABLE IF NOT EXISTS users (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    nip INT(8) UNIQUE,
    name VARCHAR(100) NOT NULL,
    username VARCHAR(50) UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('admin', 'petugas', 'supervisor') NOT NULL DEFAULT 'petugas',
    status ENUM('aktif', 'nonaktif', 'cuti') NOT NULL DEFAULT 'aktif',
    group_id BIGINT NULL,
    supervisor_id BIGINT NULL,
    phone BIGINT,
    photo_path VARCHAR(255) DEFAULT '/default-avatar.png',
    last_login TIMESTAMP NULL,
    last_activity TIMESTAMP NULL,
    last_status_change TIMESTAMP NULL,
    latitude DECIMAL(10, 8),
    longitude DECIMAL(11, 8),
    last_location_update TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- GROUPS table (Supervisor group management)
CREATE TABLE IF NOT EXISTS `groups` (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(100) NOT NULL,
    supervisor_id BIGINT NOT NULL,
    description TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_groups_supervisor (supervisor_id)
) ENGINE=InnoDB;

-- ZONES table (Area kerja)
CREATE TABLE IF NOT EXISTS zones (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    zone_code VARCHAR(32) UNIQUE,
    rw_code VARCHAR(16) UNIQUE,
    name VARCHAR(100) NOT NULL,
    address TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- OFFICES table (Kantor Kelurahan/Kecamatan)
CREATE TABLE IF NOT EXISTS offices (
    id INT PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(100) NOT NULL,
    type ENUM('kelurahan', 'kecamatan', 'posko') NOT NULL,
    address TEXT,
    rw_number VARCHAR(5),
    zone_id BIGINT,
    latitude DECIMAL(10, 8),
    longitude DECIMAL(11, 8),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (zone_id) REFERENCES zones(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- SCHEDULES table
CREATE TABLE IF NOT EXISTS schedules (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    user_id BIGINT NOT NULL,
    zone_id BIGINT NOT NULL,
    work_date DATE NOT NULL,
    shift_start TIME NOT NULL,
    shift_end TIME NOT NULL,
    shift_type VARCHAR(20) DEFAULT 'custom',
    day_type VARCHAR(20) DEFAULT 'workday',
    description TEXT,
    status ENUM('active', 'completed', 'cancelled', 'inactive') DEFAULT 'active',
    created_by BIGINT,
    updated_by BIGINT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_schedule (user_id, work_date, shift_start),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (zone_id) REFERENCES zones(id),
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- REPORTS table
CREATE TABLE IF NOT EXISTS reports (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    user_id BIGINT NOT NULL,
    schedule_id BIGINT,
    zone_id BIGINT NOT NULL,
    work_date DATE NOT NULL,
    session ENUM('pagi', 'siang', 'sore', 'malam') NOT NULL,
    start_time TIMESTAMP NULL,
    end_time TIMESTAMP NULL,
    latitude DECIMAL(10, 8),
    longitude DECIMAL(11, 8),
    browser_latitude DECIMAL(10, 8) NULL,
    browser_longitude DECIMAL(11, 8) NULL,
    address_text TEXT,
    is_in_zone BOOLEAN DEFAULT FALSE,
    distance_from_zone DECIMAL(10, 2),
    nearest_office_id INT,
    distance_from_office DECIMAL(10, 2), 
    description TEXT,
    ai_analysis_result TEXT NULL,
    ai_score DECIMAL(5,2) NULL,
    ai_is_valid BOOLEAN DEFAULT FALSE,
    is_fake_gps BOOLEAN DEFAULT FALSE,
    fake_gps_evidence TEXT NULL,
    gps_result VARCHAR(50) DEFAULT 'valid',
    before_photo_path VARCHAR(255),
    after_photo_path VARCHAR(255),
    status ENUM('pending', 'approved', 'rejected') DEFAULT 'pending',
    rejected_reason TEXT,
    reject_reason TEXT,
    reviewed_by BIGINT,
    reviewed_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (schedule_id) REFERENCES schedules(id) ON DELETE SET NULL,
    FOREIGN KEY (zone_id) REFERENCES zones(id),
    FOREIGN KEY (nearest_office_id) REFERENCES offices(id) ON DELETE SET NULL,
    FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- REPORT_PHOTOS table
CREATE TABLE IF NOT EXISTS report_photos (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    report_id BIGINT NOT NULL,
    photo_path VARCHAR(255),
    file_path VARCHAR(255),
    photo_type ENUM('before', 'after', 'pre', 'post', 'process') NOT NULL,
    sequence_no SMALLINT DEFAULT 1,
    uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    latitude DECIMAL(10, 8),
    longitude DECIMAL(11, 8),
    ai_description TEXT NULL,
    ai_is_valid_work BOOLEAN NULL,
    FOREIGN KEY (report_id) REFERENCES reports(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- REPORT_ROUTE_POINTS table
CREATE TABLE IF NOT EXISTS report_route_points (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    report_id BIGINT NOT NULL,
    seq SMALLINT NOT NULL,
    point_type VARCHAR(20) NOT NULL,
    latitude DECIMAL(10, 8) NOT NULL,
    longitude DECIMAL(11, 8) NOT NULL,
    recorded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (report_id) REFERENCES reports(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- KPI_CONFIGS table
CREATE TABLE IF NOT EXISTS kpi_configs (
    id INT PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(100) NOT NULL,
    weight_presence DECIMAL(5,2) NOT NULL DEFAULT 65.00,
    weight_completion DECIMAL(5,2) NOT NULL DEFAULT 25.00,
    weight_punctuality DECIMAL(5,2) NOT NULL DEFAULT 5.00,
    weight_other DECIMAL(5,2) NOT NULL DEFAULT 5.00,
    min_score_active DECIMAL(5,2) NOT NULL DEFAULT 70.00,
    effective_from DATE NOT NULL,
    effective_to DATE NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    created_by BIGINT,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- PASSWORD_RESETS table
CREATE TABLE IF NOT EXISTS password_resets (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    user_id BIGINT NOT NULL,
    request_token VARCHAR(255) UNIQUE,
    source VARCHAR(20) NOT NULL DEFAULT 'petugas',
    target_role VARCHAR(20) NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'pending',
    requested_by BIGINT,
    approved_by BIGINT,
    completed_by BIGINT,
    approved_at TIMESTAMP NULL,
    completed_at TIMESTAMP NULL,
    expires_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id),
    FOREIGN KEY (requested_by) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (completed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- KPI_SCORES table
CREATE TABLE IF NOT EXISTS kpi_scores (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    user_id BIGINT NOT NULL,
    report_id BIGINT,
    presence_rate DECIMAL(5,2) DEFAULT 0,
    completion_rate DECIMAL(5,2) DEFAULT 0,
    punctuality_rate DECIMAL(5,2) DEFAULT 0,
    score_total DECIMAL(5,2) DEFAULT 0,
    effective_date DATE NOT NULL,
    calculated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    details_json JSON,
    UNIQUE KEY unique_user_date (user_id, effective_date),
    FOREIGN KEY (user_id) REFERENCES users(id),
    FOREIGN KEY (report_id) REFERENCES reports(id)
) ENGINE=InnoDB;

-- ACTIVITY_LOGS table
CREATE TABLE IF NOT EXISTS activity_logs (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    user_id BIGINT,
    activity TEXT NOT NULL,
    ip_address VARCHAR(45),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- =========================================================
-- 2. FOREIGN KEY CONSTRAINTS (after tables are created)
-- =========================================================

-- Add FK for groups table
ALTER TABLE `groups` ADD CONSTRAINT fk_groups_supervisor 
    FOREIGN KEY (supervisor_id) REFERENCES users(id) ON DELETE CASCADE;

-- Add FK for users group/supervisor
ALTER TABLE users ADD CONSTRAINT fk_users_group 
    FOREIGN KEY (group_id) REFERENCES `groups`(id) ON DELETE SET NULL;
ALTER TABLE users ADD CONSTRAINT fk_users_supervisor 
    FOREIGN KEY (supervisor_id) REFERENCES users(id) ON DELETE SET NULL;

-- =========================================================
-- 3. FUNCTIONS UNTUK GEOJSON OPERATIONS
-- =========================================================

-- Function untuk menghitung jarak (Haversine formula) dalam meter
DELIMITER //

CREATE FUNCTION IF NOT EXISTS calculate_distance(
    lng1 DOUBLE, lat1 DOUBLE,
    lng2 DOUBLE, lat2 DOUBLE
)
RETURNS DOUBLE
DETERMINISTIC
BEGIN
    DECLARE earth_radius DOUBLE DEFAULT 6371000; -- meter
    DECLARE dlat DOUBLE;
    DECLARE dlng DOUBLE;
    DECLARE a DOUBLE;
    DECLARE c DOUBLE;
    
    -- Convert to radians
    SET lat1 = RADIANS(lat1);
    SET lng1 = RADIANS(lng1);
    SET lat2 = RADIANS(lat2);
    SET lng2 = RADIANS(lng2);
    
    -- Differences
    SET dlat = lat2 - lat1;
    SET dlng = lng2 - lng1;
    
    -- Haversine formula
    SET a = SIN(dlat/2) * SIN(dlat/2) + 
           COS(lat1) * COS(lat2) * SIN(dlng/2) * SIN(dlng/2);
    SET c = 2 * ATAN2(SQRT(a), SQRT(1-a));
    
    RETURN earth_radius * c;
END //

DELIMITER ;

-- =========================================================
-- 4. INDEXES untuk performa (Optimized for 200+ users)
-- =========================================================

-- Indexes untuk users
CREATE INDEX idx_users_status ON users(status);
CREATE INDEX idx_users_role ON users(role);
CREATE INDEX idx_users_nip ON users(nip);
CREATE INDEX idx_users_group ON users(group_id);
CREATE INDEX idx_users_supervisor ON users(supervisor_id);
CREATE INDEX idx_users_last_login ON users(last_login);

-- Indexes untuk zones
CREATE INDEX idx_zones_rw_code ON zones(rw_code);
CREATE INDEX idx_zones_zone_code ON zones(zone_code);

-- Indexes untuk offices
CREATE INDEX idx_offices_type ON offices(type);
CREATE INDEX idx_offices_rw ON offices(rw_number);
CREATE INDEX idx_offices_zone ON offices(zone_id);

-- Indexes untuk schedules
CREATE INDEX idx_schedules_user_date ON schedules(user_id, work_date);
CREATE INDEX idx_schedules_work_date ON schedules(work_date);
CREATE INDEX idx_schedules_zone ON schedules(zone_id);
CREATE INDEX idx_schedules_status ON schedules(status);

-- Indexes untuk reports
CREATE INDEX idx_reports_user_date ON reports(user_id, work_date);
CREATE INDEX idx_reports_work_date ON reports(work_date);
CREATE INDEX idx_reports_session ON reports(session);
CREATE INDEX idx_reports_status ON reports(status);
CREATE INDEX idx_reports_zone ON reports(zone_id);
CREATE INDEX idx_reports_nearest_office ON reports(nearest_office_id);
CREATE INDEX idx_reports_created ON reports(created_at);
CREATE INDEX idx_reports_ai_score ON reports(ai_score);

-- Indexes untuk report_photos
CREATE INDEX idx_report_photos_report ON report_photos(report_id);
CREATE INDEX idx_report_photos_ai ON report_photos(ai_is_valid_work);

-- Indexes untuk report_route_points
CREATE INDEX idx_report_route_points_report ON report_route_points(report_id);

-- Indexes untuk kpi_scores
CREATE INDEX idx_kpi_scores_user_date ON kpi_scores(user_id, effective_date);
CREATE INDEX idx_kpi_scores_total ON kpi_scores(score_total);

-- Indexes untuk kpi_configs
CREATE INDEX idx_kpi_configs_effective ON kpi_configs(effective_from, effective_to);

-- Indexes untuk password_resets
CREATE INDEX idx_password_resets_status ON password_resets(status);
CREATE INDEX idx_password_resets_token ON password_resets(request_token);
CREATE INDEX idx_password_resets_created ON password_resets(created_at);

-- Indexes untuk activity_logs
CREATE INDEX idx_activity_logs_user ON activity_logs(user_id);
CREATE INDEX idx_activity_logs_created ON activity_logs(created_at);

-- =========================================================
-- 5. DEFAULT KPI CONFIG
-- =========================================================
INSERT INTO kpi_configs (name, weight_presence, weight_completion, weight_punctuality, weight_other, min_score_active, effective_from)
VALUES ('Default Config', 65.00, 25.00, 5.00, 5.00, 70.00, CURRENT_DATE)
ON DUPLICATE KEY UPDATE name = name;

