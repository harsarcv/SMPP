<aside class="sidebar" aria-label="Main sidebar">
    <div class="brand">
      <div class="brand-title">Admin</div>
      <div class="brand-sub">Kelurahan Sunter Jaya</div>
    </div>

    <nav class="nav">
        <a href="dashboard.php" class="nav-item <?php echo ($activePage === 'dashboard') ? 'active' : ''; ?>">Dashboard</a>
        <a href="monitoring.php" class="nav-item <?php echo ($activePage === 'monitoring') ? 'active' : ''; ?>">Monitoring GPS</a>

        <div class="nav-section">PETUGAS</div>
        <a href="data_petugas.php" class="nav-item <?php echo ($activePage === 'data_petugas') ? 'active' : ''; ?>">Data Petugas</a>
        <a href="tambah_petugas.php" class="nav-item <?php echo ($activePage === 'tambah_petugas') ? 'active' : ''; ?>">Tambah Petugas</a>
        
        <div class="nav-section">JADWAL</div>
        <a href="jadwal_penugasan.php" class="nav-item <?php echo ($activePage === 'jadwal_penugasan') ? 'active' : ''; ?>">Jadwal Penugasan</a>
        <a href="buat_jadwal.php" class="nav-item <?php echo ($activePage === 'buat_jadwal') ? 'active' : ''; ?>">Buat Jadwal</a>

        <div class="nav-section">LAPORAN</div>
        <a href="laporan_petugas.php" class="nav-item <?php echo ($activePage === 'laporan_petugas') ? 'active' : ''; ?>">Laporan Petugas</a>
        <!-- <a href="laporan_map.php" class="nav-item <?php echo ($activePage === 'laporan_map') ? 'active' : ''; ?>">Laporan - Map View</a> -->

        <!--
        <div class="nav-section">ZONA</div>
        <a href="zona_wilayah.php" class="nav-item <?php echo ($activePage === 'zona_wilayah') ? 'active' : ''; ?>">Zona Wilayah</a>
        <a href="tambah_zona.php" class="nav-item <?php echo ($activePage === 'tambah_zona') ? 'active' : ''; ?>">Tambah Zona</a>
        <a href="edit_zona.php" class="nav-item <?php echo ($activePage === 'edit_zona') ? 'active' : ''; ?>">Edit Zona</a>
        -->

        <div class="nav-section">KPI</div>
        <a href="detail_kpi.php" class="nav-item <?php echo ($activePage === 'detail_kpi') ? 'active' : ''; ?>">Detail KPI Petugas</a>

        <div class="nav-section">SUPERVISOR</div>
        <a href="data_supervisor.php" class="nav-item <?php echo ($activePage === 'data_supervisor') ? 'active' : ''; ?>">Data Supervisor</a>
        <a href="reset_password_supervisor.php" class="nav-item <?php echo ($activePage === 'reset_password_supervisor') ? 'active' : ''; ?>">Reset Password Supervisor</a>

        <div class="nav-section">AKUN</div>
        <a href="../../auth/logout.php" class="nav-item">Logout</a>
        
        <!-- Copyright Footer - Inside nav, at bottom -->
        <div style="margin-top: 30px; padding: 15px 10px; background: rgba(0,0,0,0.2); border-top: 1px solid rgba(255,255,255,0.1); text-align: center; border-radius: 0 0 8px 8px;">
            <div style="font-size: 11px; color: rgba(255,255,255,0.5); line-height: 1.5;">
                <i class="fas fa-graduation-cap" style="margin-right: 5px;"></i>
                Kelompok 4<br>
                <span style="color: rgba(255,255,255,0.7); font-weight: 500;">Universitas Gunadarma</span><br>
                <span style="font-size: 10px;">&copy; <?= date('Y') ?></span>
            </div>
        </div>
    </nav>
</aside>
