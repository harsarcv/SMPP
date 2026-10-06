class DashboardIntegration {
    constructor() {
        this.userId = window.userId || null;
        this.watchId = null;
        this.updateInterval = 30000; // 30 detik
        this.isActive = false;

        this.init();
    }

    init() {
        this.initEventListeners();
    }

    async checkDeviceLocation() {
        try {
            if (navigator.permissions && navigator.permissions.query) {
                const status = await navigator.permissions.query({ name: 'geolocation' });
                if (status.state === 'denied') {
                    this.showNotification('Izin lokasi browser ditolak. Aktifkan layanan lokasi di perangkat (Settings) dan berikan izin pada browser.', 'error');
                }
                status.onchange = () => {
                    if (status.state === 'denied') {
                        this.showNotification('Izin lokasi browser ditolak. Aktifkan layanan lokasi di perangkat dan berikan izin pada browser.', 'error');
                    }
                };
            }
        } catch (e) {
            // diamkan, tidak semua browser mendukung permissions API
        }
    }

    initEventListeners() {
        // Event untuk tombol start/stop jika ada
        const startBtn = document.getElementById('startBtn');
        if (startBtn) {
            startBtn.addEventListener('click', () => this.toggleTracking());
        }
    }

    startLocationTracking() {
        if (this.watchId) {
            console.log('Tracking sudah aktif');
            return;
        }

        if (!navigator.geolocation) {
            console.warn('Geolocation tidak didukung oleh browser');
            return;
        }

        const isSecure = window.isSecureContext || location.protocol === 'https:' || ['localhost', '127.0.0.1', '::1'].includes(location.hostname);
        if (!isSecure) {
            this.showNotification('Lokasi membutuhkan HTTPS atau akses via localhost.', 'error');
        }

        this.checkDeviceLocation();

        console.log('Memulai pelacakan lokasi...');
        this.isActive = true;

        // Request high accuracy untuk mobile
        const options = {
            enableHighAccuracy: true,
            timeout: 60000, // Naikkan ke 60 detik untuk fix timeout
            maximumAge: 10000 // Cache 10 detik boleh dipakai
        };

        // Get current position pertama kali
        navigator.geolocation.getCurrentPosition(
            (position) => this.handlePosition(position),
            (error) => this.handleError(error),
            options
        );

        // Watch position untuk update berkala
        this.watchId = navigator.geolocation.watchPosition(
            (position) => this.handlePosition(position),
            (error) => this.handleError(error),
            options
        );

        // Juga update via interval untuk backup
        this.intervalId = setInterval(() => {
            if (this.isActive && navigator.geolocation) {
                // Interval gunakan low accuracy agar tidak berat
                navigator.geolocation.getCurrentPosition(
                    (position) => this.handlePosition(position),
                    (error) => console.log('Interval GPS skip:', error.message),
                    { enableHighAccuracy: false, timeout: 15000, maximumAge: 30000 }
                );
            }
        }, this.updateInterval);

        // Update UI
        this.updateUIStatus(true);
    }

    stopLocationTracking() {
        if (this.watchId) {
            navigator.geolocation.clearWatch(this.watchId);
            this.watchId = null;
        }

        if (this.intervalId) {
            clearInterval(this.intervalId);
            this.intervalId = null;
        }

        this.isActive = false;
        console.log('Pelacakan lokasi dihentikan');

        // Update UI
        this.updateUIStatus(false);

        // Kirim status nonaktif ke server
        this.sendStatusUpdate('nonaktif');
    }

    toggleTracking() {
        if (this.isActive) {
            this.stopLocationTracking();
        } else {
            this.startLocationTracking();
        }
    }

    async handlePosition(position) {
        const { latitude, longitude, accuracy } = position.coords;
        const timestamp = new Date(position.timestamp);

        console.log(`Lokasi: ${latitude}, ${longitude} (Akurasi: ${accuracy}m)`);

        // Simpan ke localStorage untuk cache
        localStorage.setItem('lastLocation', JSON.stringify({
            lat: latitude,
            lng: longitude,
            accuracy: accuracy,
            timestamp: timestamp.toISOString()
        }));

        // Kirim ke server
        await this.sendLocationToServer(latitude, longitude, accuracy);

        // Update UI jika diperlukan
        this.updateLocationDisplay(latitude, longitude);
    }

    handleError(error) {
        console.error('Error geolocation:', error);

        switch (error.code) {
            case error.PERMISSION_DENIED:
                this.showNotification('Izin lokasi ditolak. Aktifkan izin lokasi di pengaturan browser.', 'error');
                if (this.isActive) {
                    this.stopLocationTracking();
                    // Reset UI manual jika perlu
                }
                break;
            case error.POSITION_UNAVAILABLE:
                // Coba fallback low accuracy jika high fail
                if (this.isActive) {
                   console.log('Mencoba fallback location service...');
                   navigator.geolocation.getCurrentPosition(
                        (pos) => this.handlePosition(pos),
                        (err) => console.error('Fallback failed:', err),
                        { enableHighAccuracy: false, timeout: 20000, maximumAge: 60000 }
                   );
                }
                break;
            case error.TIMEOUT:
                console.warn('Waktu permintaan lokasi habis, mencoba ulang dengan akurasi rendah...');
                // Fallback retry dengan low accuracy handling
                if (this.isActive) {
                    navigator.geolocation.getCurrentPosition(
                        (pos) => this.handlePosition(pos),
                        (err) => console.error('Retry timeout failed:', err),
                        { enableHighAccuracy: false, timeout: 30000, maximumAge: 60000 }
                    );
                }
                break;
        }
    }

    async sendLocationToServer(latitude, longitude, accuracy) {
        try {
            // Gunakan path relatif agar fleksibel (berfungsi baik di localhost/PPSU maupun root domain)
            // Asumsi file ini dipanggil dari folder level 2 (misal: petugas/views/)
            const response = await fetch('../../api/gps/update_location.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    latitude: latitude,
                    longitude: longitude,
                    accuracy: accuracy,
                    timestamp: new Date().toISOString()
                })
            });
            const ct = response.headers.get('content-type') || '';
            let data = null;
            if (ct.includes('application/json')) {
                try {
                    data = await response.json();
                } catch (e) {
                    this.showNotification('Gagal memproses respons server', 'error');
                    return;
                }
            } else {
                const text = await response.text();
                console.error('Respons bukan JSON:', text.slice(0, 200));
                this.showNotification('Respons server tidak valid', 'error');
                return;
            }
            if (!response.ok) {
                this.showNotification('Gagal mengirim lokasi ke server', 'error');
                return;
            }
            if (data && data.warning) {
                this.showNotification(data.warning, 'warning');
            }
            if (data && !data.success) {
                console.error('Gagal mengirim lokasi:', data.error);
            }
        } catch (error) {
            console.error('Error mengirim lokasi:', error);
        }
    }

    async sendStatusUpdate(status) {
        try {
            // Gunakan path relatif
            const response = await fetch('../../petugas/views/dashboard.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: new URLSearchParams({
                    action: 'work_status',
                    status: status,
                    csrf_token: document.querySelector('meta[name="csrf-token"]')?.content || ''
                })
            });

            const data = await response.json();

            if (!data.success) {
                console.error('Gagal update status:', data.message);
            }

        } catch (error) {
            console.error('Error update status:', error);
        }
    }

    updateUIStatus(isActive) {
        // Update tombol/tampilan status
        const statusElement = document.getElementById('statusIndicator');
        const buttonElement = document.getElementById('startBtn');
        const iconElement = document.getElementById('statusIcon');

        if (statusElement) {
            statusElement.textContent = isActive ? 'Aktif' : 'Nonaktif';
            statusElement.className = isActive ? 'status-active' : 'status-inactive';
        }

        if (buttonElement) {
            buttonElement.textContent = isActive ? 'Stop' : 'Start';
            buttonElement.className = isActive ? 'btn-stop' : 'btn-start';
        }

        if (iconElement) {
            iconElement.src = isActive ?
                '../../assets/icon/icon-power.png' :
                '../../assets/icon/icon-power.png';
        }
    }

    updateLocationDisplay(lat, lng) {
        const locationElement = document.getElementById('locationDisplay');
        if (locationElement) {
            locationElement.textContent = `${lat.toFixed(6)}, ${lng.toFixed(6)}`;
        }
    }

    showNotification(message, type = 'warning') {
        const overlay = document.getElementById('popup-notifikasi');

        if (overlay) {
            const titleEl = overlay.querySelector('#notif-title');
            const msgEl = overlay.querySelector('#notif-message');
            const closeBtn = overlay.querySelector('#btnNotifClose');
            const box = overlay.querySelector('.popup-box');

            if (titleEl && msgEl && box) {
                const title = type === 'error' ? 'PERINGATAN' :
                    (type === 'success' ? 'SUKSES' : 'NOTIFIKASI');

                titleEl.textContent = title;
                msgEl.textContent = message;

                // Update warna berdasarkan jenis notifikasi
                box.style.background = type === 'error' ? '#ff6b6b' :
                    (type === 'success' ? '#2ab667' : '#FF9470');
                box.style.color = '#fff';

                // Tampilkan overlay
                overlay.style.display = 'flex';

                // Handle close button
                if (closeBtn) {
                    closeBtn.onclick = () => {
                        overlay.style.display = 'none';
                    };
                }

                // Auto close setelah 5 detik
                setTimeout(() => {
                    if (overlay.style.display === 'flex') {
                        overlay.style.display = 'none';
                    }
                }, 5000);

                return; // Keluar dari fungsi karena sudah menampilkan popup
            }
        }

        // Fallback jika popup tidak ditemukan (tampilkan notification sederhana)
        console.log(`[${type.toUpperCase()}] ${message}`);
        alert(message); // Fallback sederhana
    }

    // Helper untuk format waktu
    formatTime(date) {
        return date.toLocaleTimeString('id-ID', {
            hour: '2-digit',
            minute: '2-digit',
            second: '2-digit'
        });
    }

    // Helper untuk format tanggal
    formatDate(date) {
        return date.toLocaleDateString('id-ID', {
            weekday: 'long',
            year: 'numeric',
            month: 'long',
            day: 'numeric'
        });
    }
}

// Inisialisasi saat halaman load
document.addEventListener('DOMContentLoaded', () => {
    if (!window.dashboard) {
        window.dashboard = new DashboardIntegration();
    }

    // Cek jika ada service worker untuk PWA
    /* if ('serviceWorker' in navigator) {
        navigator.serviceWorker.register('/sw.js')
            .then(registration => {
                console.log('ServiceWorker registered dengan scope:', registration.scope);
            })
            .catch(error => {
                console.log('ServiceWorker registration failed:', error);
            });
    } */

    // Handle offline/online status
    window.addEventListener('online', () => {
        document.body.classList.remove('offline');
        dashboard.showNotification('Koneksi internet kembali aktif', 'success');
    });

    window.addEventListener('offline', () => {
        document.body.classList.add('offline');
        dashboard.showNotification('Anda sedang offline', 'error');
    });
});
