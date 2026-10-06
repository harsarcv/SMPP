<?php
// Ensure $activePage is set to avoid errors
if (!isset($activePage)) {
    $activePage = '';
}
?>
<aside class="sidebar">
    <div class="sidebar-title">Supervisor</div>

    <nav class="menu">
        <a href="dashboard.php" class="menu-item <?php echo $activePage === 'dashboard' ? 'active' : ''; ?>">Dashboard</a>
        <a href="monitoring.php" class="menu-item <?php echo $activePage === 'monitoring' ? 'active' : ''; ?>">Monitoring Anggota</a>
        <a href="data_petugas.php" class="menu-item <?php echo $activePage === 'data_petugas' ? 'active' : ''; ?>">Data Petugas</a>
        <a href="kelola_group.php" class="menu-item <?php echo $activePage === 'kelola_group' ? 'active' : ''; ?>">Kelola Group</a>
        <a href="laporan_petugas.php" class="menu-item <?php echo $activePage === 'laporan_petugas' ? 'active' : ''; ?>">Laporan Petugas</a>
        <a href="jadwal_penugasan.php" class="menu-item <?php echo $activePage === 'jadwal_penugasan' ? 'active' : ''; ?>">Jadwal Penugasan</a>
        <a href="../../auth/logout.php" class="menu-item">Logout</a>
        
        <!-- Copyright Footer - Inside menu, at bottom -->
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