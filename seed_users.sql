-- ==========================================
-- SEED DATA: 10 Admin, 10 Supervisor, 10 Petugas
-- Password for all users: 123
-- 
-- HASH FORMAT: SHA256 (64 characters hex)
-- SHA256('123') = a665a45920422f9d417e4867efdc4fb8a04a1f3fff1fa07e998e86f7f7a27ae3
--
-- NIP: 8 digit sesuai INT(8)
-- ==========================================

-- ==========================================
-- 10 ADMIN USERS (NIP: 10000001 - 10000010)
-- ==========================================
INSERT INTO users (name, nip, username, password_hash, role, status, phone, created_at) VALUES
('Admin Utama', '10000001', 'admin1', 'a665a45920422f9d417e4867efdc4fb8a04a1f3fff1fa07e998e86f7f7a27ae3', 'admin', 'aktif', '81100000001', NOW()),
('Admin Dua', '10000002', 'admin2', 'a665a45920422f9d417e4867efdc4fb8a04a1f3fff1fa07e998e86f7f7a27ae3', 'admin', 'aktif', '81100000002', NOW()),
('Admin Tiga', '10000003', 'admin3', 'a665a45920422f9d417e4867efdc4fb8a04a1f3fff1fa07e998e86f7f7a27ae3', 'admin', 'aktif', '81100000003', NOW()),
('Admin Empat', '10000004', 'admin4', 'a665a45920422f9d417e4867efdc4fb8a04a1f3fff1fa07e998e86f7f7a27ae3', 'admin', 'aktif', '81100000004', NOW()),
('Admin Lima', '10000005', 'admin5', 'a665a45920422f9d417e4867efdc4fb8a04a1f3fff1fa07e998e86f7f7a27ae3', 'admin', 'aktif', '81100000005', NOW()),
('Admin Enam', '10000006', 'admin6', 'a665a45920422f9d417e4867efdc4fb8a04a1f3fff1fa07e998e86f7f7a27ae3', 'admin', 'aktif', '81100000006', NOW()),
('Admin Tujuh', '10000007', 'admin7', 'a665a45920422f9d417e4867efdc4fb8a04a1f3fff1fa07e998e86f7f7a27ae3', 'admin', 'aktif', '81100000007', NOW()),
('Admin Delapan', '10000008', 'admin8', 'a665a45920422f9d417e4867efdc4fb8a04a1f3fff1fa07e998e86f7f7a27ae3', 'admin', 'aktif', '81100000008', NOW()),
('Admin Sembilan', '10000009', 'admin9', 'a665a45920422f9d417e4867efdc4fb8a04a1f3fff1fa07e998e86f7f7a27ae3', 'admin', 'aktif', '81100000009', NOW()),
('Admin Sepuluh', '10000010', 'admin10', 'a665a45920422f9d417e4867efdc4fb8a04a1f3fff1fa07e998e86f7f7a27ae3', 'admin', 'aktif', '81100000010', NOW());

-- ==========================================
-- 10 SUPERVISOR USERS (NIP: 20000001 - 20000010)
-- ==========================================
INSERT INTO users (name, nip, username, password_hash, role, status, phone, created_at) VALUES
('Supervisor Alfa', '20000001', 'supervisor1', 'a665a45920422f9d417e4867efdc4fb8a04a1f3fff1fa07e998e86f7f7a27ae3', 'supervisor', 'aktif', '82200000001', NOW()),
('Supervisor Beta', '20000002', 'supervisor2', 'a665a45920422f9d417e4867efdc4fb8a04a1f3fff1fa07e998e86f7f7a27ae3', 'supervisor', 'aktif', '82200000002', NOW()),
('Supervisor Charlie', '20000003', 'supervisor3', 'a665a45920422f9d417e4867efdc4fb8a04a1f3fff1fa07e998e86f7f7a27ae3', 'supervisor', 'aktif', '82200000003', NOW()),
('Supervisor Delta', '20000004', 'supervisor4', 'a665a45920422f9d417e4867efdc4fb8a04a1f3fff1fa07e998e86f7f7a27ae3', 'supervisor', 'aktif', '82200000004', NOW()),
('Supervisor Echo', '20000005', 'supervisor5', 'a665a45920422f9d417e4867efdc4fb8a04a1f3fff1fa07e998e86f7f7a27ae3', 'supervisor', 'aktif', '82200000005', NOW()),
('Supervisor Foxtrot', '20000006', 'supervisor6', 'a665a45920422f9d417e4867efdc4fb8a04a1f3fff1fa07e998e86f7f7a27ae3', 'supervisor', 'aktif', '82200000006', NOW()),
('Supervisor Golf', '20000007', 'supervisor7', 'a665a45920422f9d417e4867efdc4fb8a04a1f3fff1fa07e998e86f7f7a27ae3', 'supervisor', 'aktif', '82200000007', NOW()),
('Supervisor Hotel', '20000008', 'supervisor8', 'a665a45920422f9d417e4867efdc4fb8a04a1f3fff1fa07e998e86f7f7a27ae3', 'supervisor', 'aktif', '82200000008', NOW()),
('Supervisor India', '20000009', 'supervisor9', 'a665a45920422f9d417e4867efdc4fb8a04a1f3fff1fa07e998e86f7f7a27ae3', 'supervisor', 'aktif', '82200000009', NOW()),
('Supervisor Juliet', '20000010', 'supervisor10', 'a665a45920422f9d417e4867efdc4fb8a04a1f3fff1fa07e998e86f7f7a27ae3', 'supervisor', 'aktif', '82200000010', NOW());

-- ==========================================
-- 10 PETUGAS USERS (NIP: 30000001 - 30000010)
-- ==========================================
INSERT INTO users (name, nip, username, password_hash, role, status, phone, created_at) VALUES
('Petugas Ahmad', '30000001', 'petugas1', 'a665a45920422f9d417e4867efdc4fb8a04a1f3fff1fa07e998e86f7f7a27ae3', 'petugas', 'aktif', '83300000001', NOW()),
('Petugas Budi', '30000002', 'petugas2', 'a665a45920422f9d417e4867efdc4fb8a04a1f3fff1fa07e998e86f7f7a27ae3', 'petugas', 'aktif', '83300000002', NOW()),
('Petugas Candra', '30000003', 'petugas3', 'a665a45920422f9d417e4867efdc4fb8a04a1f3fff1fa07e998e86f7f7a27ae3', 'petugas', 'aktif', '83300000003', NOW()),
('Petugas Dedi', '30000004', 'petugas4', 'a665a45920422f9d417e4867efdc4fb8a04a1f3fff1fa07e998e86f7f7a27ae3', 'petugas', 'aktif', '83300000004', NOW()),
('Petugas Eko', '30000005', 'petugas5', 'a665a45920422f9d417e4867efdc4fb8a04a1f3fff1fa07e998e86f7f7a27ae3', 'petugas', 'aktif', '83300000005', NOW()),
('Petugas Fajar', '30000006', 'petugas6', 'a665a45920422f9d417e4867efdc4fb8a04a1f3fff1fa07e998e86f7f7a27ae3', 'petugas', 'aktif', '83300000006', NOW()),
('Petugas Gunawan', '30000007', 'petugas7', 'a665a45920422f9d417e4867efdc4fb8a04a1f3fff1fa07e998e86f7f7a27ae3', 'petugas', 'aktif', '83300000007', NOW()),
('Petugas Hendra', '30000008', 'petugas8', 'a665a45920422f9d417e4867efdc4fb8a04a1f3fff1fa07e998e86f7f7a27ae3', 'petugas', 'aktif', '83300000008', NOW()),
('Petugas Irwan', '30000009', 'petugas9', 'a665a45920422f9d417e4867efdc4fb8a04a1f3fff1fa07e998e86f7f7a27ae3', 'petugas', 'aktif', '83300000009', NOW()),
('Petugas Joko', '30000010', 'petugas10', 'a665a45920422f9d417e4867efdc4fb8a04a1f3fff1fa07e998e86f7f7a27ae3', 'petugas', 'aktif', '83300000010', NOW());

-- ==========================================
-- SUMMARY
-- ==========================================
-- Total: 30 users (NIP 8 digit, SHA256 hash)
-- All passwords: 123
-- SHA256 Hash: a665a45920422f9d417e4867efdc4fb8a04a1f3fff1fa07e998e86f7f7a27ae3
-- 
-- Login credentials:
-- Admin: admin1 / 123, ... admin10 / 123
-- Supervisor: supervisor1 / 123, ... supervisor10 / 123
-- Petugas: petugas1 / 123, ... petugas10 / 123
--
-- NOTE: Saat login, aplikasi akan otomatis meng-upgrade hash ke bcrypt