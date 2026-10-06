-- Migration to 4-Status System (online, offline, nonaktif, cuti)

-- 1. Alter ENUM column to include new statuses (if strictly defined, otherwise this is just for documentation)
-- MODIFY COLUMN status ENUM('online', 'offline', 'nonaktif', 'cuti', 'aktif') DEFAULT 'offline';

-- 2. Migrate existing 'aktif' users to 'offline' (safe default) or 'online'
-- Providing a safe migration where 'aktif' becomes 'offline' so they must clock-in to become 'online'
UPDATE users 
SET status = 'offline' 
WHERE status = 'aktif';

-- 3. Ensure no users have invalid statuses (optional cleanup)
-- UPDATE users SET status = 'nonaktif' WHERE status NOT IN ('online', 'offline', 'nonaktif', 'cuti');
