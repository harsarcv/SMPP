<?php
// Homepage untuk Presentasi
// Sistem Monitoring Petugas PPSU - Kelompok 4 Universitas Gunadarma
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SMPP | Sistem Monitoring Petugas PPSU</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Inter', sans-serif;
        }
        
        body {
            background: linear-gradient(135deg, #0a1628 0%, #1a365d 50%, #0d2847 100%);
            min-height: 100vh;
            color: #fff;
            overflow-x: hidden;
        }
        
        /* Animated Background */
        .bg-animation {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            z-index: -1;
            overflow: hidden;
        }
        
        .bg-animation::before {
            content: '';
            position: absolute;
            width: 600px;
            height: 600px;
            background: radial-gradient(circle, rgba(59, 130, 246, 0.15) 0%, transparent 70%);
            top: -200px;
            right: -200px;
            animation: float 15s ease-in-out infinite;
        }
        
        .bg-animation::after {
            content: '';
            position: absolute;
            width: 400px;
            height: 400px;
            background: radial-gradient(circle, rgba(34, 197, 94, 0.1) 0%, transparent 70%);
            bottom: -100px;
            left: -100px;
            animation: float 20s ease-in-out infinite reverse;
        }
        
        @keyframes float {
            0%, 100% { transform: translate(0, 0); }
            50% { transform: translate(50px, 50px); }
        }
        
        /* Navigation */
        .navbar {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            padding: 20px 50px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            backdrop-filter: blur(10px);
            background: rgba(10, 22, 40, 0.8);
            z-index: 100;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        }
        
        .logo {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        
        .logo img {
            width: 45px;
            height: 45px;
        }
        
        .logo-text {
            font-size: 24px;
            font-weight: 700;
            background: linear-gradient(135deg, #60a5fa, #34d399);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }
        
        .nav-links {
            display: flex;
            gap: 30px;
            align-items: center;
        }
        
        .nav-links a {
            color: rgba(255, 255, 255, 0.8);
            text-decoration: none;
            font-size: 15px;
            font-weight: 500;
            transition: all 0.3s ease;
        }
        
        .nav-links a:hover {
            color: #60a5fa;
        }
        
        .btn-login {
            background: linear-gradient(135deg, #3b82f6, #2563eb);
            color: white;
            padding: 10px 25px;
            border-radius: 8px;
            font-weight: 600;
            transition: all 0.3s ease;
            box-shadow: 0 4px 15px rgba(59, 130, 246, 0.3);
        }
        
        .btn-login:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(59, 130, 246, 0.4);
        }
        
        /* Hero Section */
        .hero {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 120px 50px 80px;
            text-align: center;
        }
        
        .hero-content {
            max-width: 900px;
        }
        
        .hero-badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: rgba(59, 130, 246, 0.2);
            border: 1px solid rgba(59, 130, 246, 0.3);
            padding: 8px 20px;
            border-radius: 50px;
            font-size: 14px;
            margin-bottom: 25px;
            color: #60a5fa;
        }
        
        .hero h1 {
            font-size: 56px;
            font-weight: 800;
            line-height: 1.2;
            margin-bottom: 20px;
            background: linear-gradient(135deg, #fff 0%, #94a3b8 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }
        
        .hero h1 span {
            background: linear-gradient(135deg, #60a5fa, #34d399);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }
        
        .hero p {
            font-size: 20px;
            color: rgba(255, 255, 255, 0.7);
            line-height: 1.8;
            margin-bottom: 40px;
        }
        
        .hero-buttons {
            display: flex;
            gap: 20px;
            justify-content: center;
            flex-wrap: wrap;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, #3b82f6, #2563eb);
            color: white;
            padding: 15px 35px;
            border-radius: 10px;
            font-size: 16px;
            font-weight: 600;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 10px;
            transition: all 0.3s ease;
            box-shadow: 0 4px 20px rgba(59, 130, 246, 0.3);
        }
        
        .btn-primary:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 30px rgba(59, 130, 246, 0.4);
        }
        
        .btn-secondary {
            background: rgba(255, 255, 255, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.2);
            color: white;
            padding: 15px 35px;
            border-radius: 10px;
            font-size: 16px;
            font-weight: 600;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 10px;
            transition: all 0.3s ease;
        }
        
        .btn-secondary:hover {
            background: rgba(255, 255, 255, 0.15);
            transform: translateY(-3px);
        }
        
        /* Features Section */
        .features {
            padding: 100px 50px;
            background: rgba(0, 0, 0, 0.2);
        }
        
        .section-title {
            text-align: center;
            margin-bottom: 60px;
        }
        
        .section-title h2 {
            font-size: 40px;
            font-weight: 700;
            margin-bottom: 15px;
        }
        
        .section-title p {
            color: rgba(255, 255, 255, 0.6);
            font-size: 18px;
        }
        
        .features-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
            gap: 30px;
            max-width: 1200px;
            margin: 0 auto;
        }
        
        .feature-card {
            background: linear-gradient(135deg, rgba(255, 255, 255, 0.08), rgba(255, 255, 255, 0.02));
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 20px;
            padding: 35px;
            transition: all 0.3s ease;
        }
        
        .feature-card:hover {
            transform: translateY(-5px);
            border-color: rgba(59, 130, 246, 0.3);
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.3);
        }
        
        .feature-icon {
            width: 60px;
            height: 60px;
            border-radius: 15px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 28px;
            margin-bottom: 20px;
        }
        
        .feature-icon.blue { background: linear-gradient(135deg, #3b82f6, #2563eb); }
        .feature-icon.green { background: linear-gradient(135deg, #22c55e, #16a34a); }
        .feature-icon.purple { background: linear-gradient(135deg, #a855f7, #9333ea); }
        .feature-icon.orange { background: linear-gradient(135deg, #f97316, #ea580c); }
        .feature-icon.cyan { background: linear-gradient(135deg, #06b6d4, #0891b2); }
        .feature-icon.pink { background: linear-gradient(135deg, #ec4899, #db2777); }
        
        .feature-card h3 {
            font-size: 22px;
            font-weight: 600;
            margin-bottom: 12px;
        }
        
        .feature-card p {
            color: rgba(255, 255, 255, 0.6);
            line-height: 1.7;
            font-size: 15px;
        }
        
        /* Team Section */
        .team {
            padding: 100px 50px;
        }
        
        .team-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 30px;
            max-width: 1100px;
            margin: 0 auto;
        }
        
        .team-card {
            background: linear-gradient(135deg, rgba(255, 255, 255, 0.1), rgba(255, 255, 255, 0.03));
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 20px;
            padding: 30px;
            text-align: center;
            transition: all 0.3s ease;
        }
        
        .team-card:hover {
            transform: translateY(-5px);
            border-color: rgba(59, 130, 246, 0.3);
        }
        
        .team-avatar {
            width: 100px;
            height: 100px;
            border-radius: 50%;
            background: linear-gradient(135deg, #3b82f6, #8b5cf6);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 40px;
            margin: 0 auto 20px;
            color: white;
        }
        
        .team-card h3 {
            font-size: 20px;
            font-weight: 600;
            margin-bottom: 5px;
        }
        
        .team-card .role {
            color: #60a5fa;
            font-size: 14px;
            font-weight: 500;
            margin-bottom: 10px;
        }
        
        .team-card .npm {
            color: rgba(255, 255, 255, 0.5);
            font-size: 13px;
        }
        
        /* Tech Stack */
        .tech-stack {
            padding: 80px 50px;
            background: rgba(0, 0, 0, 0.2);
        }
        
        .tech-icons {
            display: flex;
            justify-content: center;
            flex-wrap: wrap;
            gap: 40px;
            max-width: 900px;
            margin: 0 auto;
        }
        
        .tech-item {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 10px;
        }
        
        .tech-item i {
            font-size: 50px;
            opacity: 0.8;
        }
        
        .tech-item span {
            font-size: 14px;
            color: rgba(255, 255, 255, 0.6);
        }
        
        /* University Section */
        .university {
            padding: 80px 50px;
            text-align: center;
        }
        
        .university-logo {
            width: 120px;
            height: 120px;
            margin: 0 auto 25px;
            background: white;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 15px;
        }
        
        .university-logo img {
            width: 100%;
            height: 100%;
            object-fit: contain;
        }
        
        .university h3 {
            font-size: 28px;
            font-weight: 700;
            margin-bottom: 10px;
        }
        
        .university p {
            color: rgba(255, 255, 255, 0.6);
            font-size: 16px;
        }
        
        /* Footer */
        footer {
            padding: 40px 50px;
            background: rgba(0, 0, 0, 0.4);
            text-align: center;
            border-top: 1px solid rgba(255, 255, 255, 0.1);
        }
        
        footer p {
            color: rgba(255, 255, 255, 0.5);
            font-size: 14px;
        }
        
        footer .copyright {
            margin-top: 10px;
            color: #60a5fa;
            font-weight: 600;
        }
        
        /* Responsive */
        @media (max-width: 768px) {
            .navbar {
                padding: 15px 20px;
            }
            
            .nav-links {
                display: none;
            }
            
            .hero {
                padding: 100px 20px 60px;
            }
            
            .hero h1 {
                font-size: 36px;
            }
            
            .hero p {
                font-size: 16px;
            }
            
            .features, .team, .tech-stack, .university {
                padding: 60px 20px;
            }
            
            .section-title h2 {
                font-size: 28px;
            }
        }
    </style>
</head>
<body>
    <div class="bg-animation"></div>
    
    <!-- Navigation -->
    <nav class="navbar">
        <div class="logo">
            <img src="assets/icon/icon-kelurahan.png" alt="Logo SMPP" onerror="this.style.display='none'">
            <span class="logo-text">SMPP</span>
        </div>
        <div class="nav-links">
            <a href="#features">Fitur</a>
            <a href="#team">Tim Kami</a>
            <a href="#about">Tentang</a>
            <a href="auth/login.php" class="btn-login">Masuk Aplikasi</a>
        </div>
    </nav>
    
    <!-- Hero Section -->
    <section class="hero">
        <div class="hero-content">
            <div class="hero-badge">
                <i class="fas fa-graduation-cap"></i>
                Proyek Pengabdian Masyarakat - Universitas Gunadarma
            </div>
            <h1>Sistem Monitoring <span>Petugas PPSU</span></h1>
            <p>
                Aplikasi berbasis web untuk memantau kegiatan dan kinerja Petugas Penanganan Prasarana dan Sarana Umum (PPSU) 
                di Kelurahan Sunter Jaya secara real-time dengan teknologi AI untuk validasi laporan kerja.
            </p>
            <div class="hero-buttons">
                <a href="auth/login.php" class="btn-primary">
                    <i class="fas fa-sign-in-alt"></i>
                    Masuk Aplikasi
                </a>
                <a href="#features" class="btn-secondary">
                    <i class="fas fa-info-circle"></i>
                    Pelajari Lebih Lanjut
                </a>
            </div>
        </div>
    </section>
    
    <!-- Features Section -->
    <section class="features" id="features">
        <div class="section-title">
            <h2>Fitur Unggulan</h2>
            <p>Solusi lengkap untuk monitoring petugas PPSU</p>
        </div>
        <div class="features-grid">
            <div class="feature-card">
                <div class="feature-icon blue">
                    <i class="fas fa-map-marker-alt"></i>
                </div>
                <h3>GPS Live Tracking</h3>
                <p>Pantau lokasi petugas secara real-time dengan teknologi GPS. Admin dapat melihat posisi seluruh petugas di peta interaktif.</p>
            </div>
            <div class="feature-card">
                <div class="feature-icon green">
                    <i class="fas fa-robot"></i>
                </div>
                <h3>AI Photo Validation</h3>
                <p>Validasi foto laporan kerja menggunakan Google Gemini AI untuk memastikan keaslian dan kesesuaian dengan tugas yang diberikan.</p>
            </div>
            <div class="feature-card">
                <div class="feature-icon purple">
                    <i class="fas fa-chart-line"></i>
                </div>
                <h3>KPI & Performance</h3>
                <p>Hitung dan pantau Key Performance Indicator (KPI) petugas berdasarkan kehadiran, ketepatan waktu, dan penyelesaian tugas.</p>
            </div>
            <div class="feature-card">
                <div class="feature-icon orange">
                    <i class="fas fa-calendar-alt"></i>
                </div>
                <h3>Jadwal Penugasan</h3>
                <p>Kelola jadwal kerja petugas dengan mudah. Atur shift, zona kerja, dan deskripsi tugas secara terpusat.</p>
            </div>
            <div class="feature-card">
                <div class="feature-icon cyan">
                    <i class="fas fa-file-excel"></i>
                </div>
                <h3>Export Laporan</h3>
                <p>Export laporan ke format Excel dengan foto dan deskripsi AI untuk keperluan dokumentasi dan evaluasi.</p>
            </div>
            <div class="feature-card">
                <div class="feature-icon pink">
                    <i class="fas fa-users-cog"></i>
                </div>
                <h3>Multi-Role Access</h3>
                <p>Tiga level akses: Admin (full control), Supervisor (monitoring grup), dan Petugas (laporan harian).</p>
            </div>
        </div>
    </section>
    
    <!-- Team Section -->
    <section class="team" id="team">
        <div class="section-title">
            <h2>Tim Pengembang</h2>
            <p>Kelompok 4 - Universitas Gunadarma</p>
        </div>
        <div class="team-grid">
            <div class="team-card">
                <div class="team-avatar">
                    <i class="fas fa-user"></i>
                </div>
                <h3>Anggota 1</h3>
                <div class="role">Project Manager</div>
                <div class="npm">NPM: XXXXXXXXXX</div>
            </div>
            <div class="team-card">
                <div class="team-avatar">
                    <i class="fas fa-user"></i>
                </div>
                <h3>Anggota 2</h3>
                <div class="role">Full Stack Developer</div>
                <div class="npm">NPM: XXXXXXXXXX</div>
            </div>
            <div class="team-card">
                <div class="team-avatar">
                    <i class="fas fa-user"></i>
                </div>
                <h3>Anggota 3</h3>
                <div class="role">UI/UX Designer</div>
                <div class="npm">NPM: XXXXXXXXXX</div>
            </div>
            <div class="team-card">
                <div class="team-avatar">
                    <i class="fas fa-user"></i>
                </div>
                <h3>Anggota 4</h3>
                <div class="role">Database Administrator</div>
                <div class="npm">NPM: XXXXXXXXXX</div>
            </div>
        </div>
    </section>
    
    <!-- Tech Stack -->
    <section class="tech-stack">
        <div class="section-title">
            <h2>Teknologi yang Digunakan</h2>
            <p>Modern tech stack untuk performa optimal</p>
        </div>
        <div class="tech-icons">
            <div class="tech-item">
                <i class="fab fa-php" style="color: #777BB4;"></i>
                <span>PHP</span>
            </div>
            <div class="tech-item">
                <i class="fab fa-js" style="color: #F7DF1E;"></i>
                <span>JavaScript</span>
            </div>
            <div class="tech-item">
                <i class="fas fa-database" style="color: #00758F;"></i>
                <span>MySQL</span>
            </div>
            <div class="tech-item">
                <i class="fab fa-html5" style="color: #E34F26;"></i>
                <span>HTML5</span>
            </div>
            <div class="tech-item">
                <i class="fab fa-css3-alt" style="color: #1572B6;"></i>
                <span>CSS3</span>
            </div>
            <div class="tech-item">
                <i class="fas fa-robot" style="color: #4285F4;"></i>
                <span>Gemini AI</span>
            </div>
            <div class="tech-item">
                <i class="fas fa-map-marked-alt" style="color: #34A853;"></i>
                <span>Leaflet Maps</span>
            </div>
        </div>
    </section>
    
    <!-- University Section -->
    <section class="university" id="about">
        <div class="university-logo">
            <img src="https://upload.wikimedia.org/wikipedia/id/thumb/5/5b/Logo_Gunadarma.svg/1200px-Logo_Gunadarma.svg.png" alt="Logo Gunadarma" onerror="this.parentElement.innerHTML='<i class=\'fas fa-university\' style=\'font-size:60px;color:#333;\'></i>'">
        </div>
        <h3>Universitas Gunadarma</h3>
        <p>Program Pengabdian Masyarakat - Fakultas Teknologi Industri</p>
        <p style="margin-top: 20px; color: rgba(255,255,255,0.5);">
            Proyek ini dikembangkan sebagai bentuk pengabdian kepada masyarakat,<br>
            khususnya untuk membantu Kelurahan Sunter Jaya dalam monitoring kinerja petugas PPSU.
        </p>
    </section>
    
    <!-- Footer -->
    <footer>
        <p>Sistem Monitoring Petugas PPSU &copy; <?= date('Y') ?></p>
        <p class="copyright">Kelompok 4 - Universitas Gunadarma</p>
    </footer>

</body>
</html>
