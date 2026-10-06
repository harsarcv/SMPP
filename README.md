# SMPP — Sistem Monitoring Petugas PPSU


SMPP (Sistem Monitoring Petugas PPSU) adalah aplikasi web enterprise yang dirancang untuk mendigitalisasi, memantau, dan mengoptimalkan seluruh siklus kerja operasional harian petugas Penanganan Prasarana dan Sarana Umum (PPSU) di Kelurahan Sunter Jaya, Kecamatan Tanjung Priok, Jakarta Utara.


Sistem ini mengintegrasikan GPS Live Tracking, Geofencing, deteksi Fake GPS, evaluasi KPI otomatis, serta validasi foto laporan pekerjaan menggunakan Google Gemini Vision AI. Aplikasi dikembangkan sebagai bagian dari riset dan pengabdian masyarakat oleh Kelompok 4 Universitas Gunadarma. fileciteturn0file0L22-L38


## Fitur


- Autentikasi dan manajemen akun pengguna

- Role-Based Access Control (RBAC)

- Manajemen pengguna Admin, Supervisor, dan Petugas

- Penjadwalan kerja harian dan mingguan

- Pengaturan shift kerja

- Impor dan ekspor jadwal CSV/Excel

- Monitoring posisi petugas secara real-time

- GPS Live Tracking

- Geofencing berdasarkan zona kerja

- Validasi lokasi menggunakan koordinat GPS

- Deteksi indikasi Fake GPS

- Pelaporan pekerjaan dengan foto sebelum dan sesudah

- Validasi foto pekerjaan menggunakan Google Gemini Vision AI

- Verifikasi laporan oleh Supervisor dan Admin

- Sistem KPI otomatis berbasis data 30 hari terakhir

- Monitoring skor performa petugas

- Sistem peringatan dan pemblokiran berdasarkan skor KPI

- Riwayat pergerakan GPS

- Audit trail aktivitas pengguna

- Sistem reset password berjenjang

- Ekspor laporan kerja ke Excel/CSV

- Dashboard dan monitoring operasional


Fitur dan alur utama tersebut dijelaskan pada dokumentasi produk, termasuk pelaporan dual-photo, penjadwalan, pemulihan akun, monitoring GPS, dan evaluasi KPI.

## Role & Akses


### Administrator


- Mengelola data pengguna

- Menambah, mengubah, menonaktifkan, dan menghapus akun petugas maupun supervisor

- Membuat jadwal kerja harian dan mingguan

- Menentukan shift kerja

- Mengimpor dan mengekspor jadwal

- Memantau posisi seluruh petugas melalui peta

- Melihat histori pergerakan petugas

- Memvalidasi laporan pekerjaan

- Melakukan Approve atau Reject laporan beserta alasan

- Memantau skor KPI seluruh petugas

- Mengelola konfigurasi bobot KPI

- Melakukan Reset KPI terhadap akun yang terblokir

- Menyetujui pengajuan reset password supervisor

- Mengekspor data laporan kerja


### Supervisor


- Membentuk dan mengelola grup petugas binaan

- Mengalokasikan petugas ke dalam grup

- Memantau lokasi petugas secara real-time

- Mengatur status dinas Supervisor melalui Start/Stop

- Melakukan review awal terhadap laporan petugas

- Memverifikasi bukti pekerjaan sebelum diteruskan ke Admin

- Memproses permohonan reset password petugas


### Petugas Lapangan


- Mengaktifkan dan menonaktifkan status dinas

- Mengirim koordinat GPS secara berkala ketika berstatus Online

- Mengirim laporan pekerjaan sesuai jadwal

- Mengunggah foto sebelum dan sesudah pekerjaan

- Mengirim deskripsi pekerjaan

- Menjalani validasi lokasi GPS

- Melihat riwayat laporan

- Melihat alasan penolakan laporan

- Memantau skor KPI pribadi


Pembagian hak akses tiga role tersebut menggunakan RBAC dan dijelaskan pada dokumentasi arsitektur sistem.


## Status Pengguna


SMPP menggunakan empat status utama:


```text

online

offline

nonaktif

cuti

```


### Online


Petugas sedang aktif bertugas. GPS aktif dan sistem melakukan tracking serta heartbeat lokasi setiap 30 detik. Petugas dapat mengirimkan laporan pekerjaan.


### Offline


Petugas belum memulai dinas atau telah menyelesaikan tugas harian. Tracking GPS dihentikan sementara.


### Nonaktif


Akun dinonaktifkan oleh Administrator atau diblokir oleh sistem berdasarkan kondisi KPI. Petugas tidak dapat login.


### Cuti


Akun berada dalam masa cuti resmi. Login dan pengiriman laporan dikunci sementara hingga masa cuti berakhir. 


## Sistem Pelaporan Pekerjaan


Setiap penugasan menggunakan mekanisme **Dual-Photo Verification**.


```text

Foto Sebelum

     ↓

Pelaksanaan Pekerjaan

     ↓

Foto Sesudah

     ↓

Validasi GPS

     ↓

Analisis Vision AI

     ↓

Review Supervisor

     ↓

Validasi Administrator

     ↓

Approved / Rejected

```


Petugas diwajibkan mengirimkan foto sebelum dan sesudah pekerjaan. Foto sesudah pekerjaan digunakan sebagai input utama untuk proses audit visual oleh Vision AI. 


## Dual-AI Engine


SMPP menggunakan dua komponen AI dengan fungsi yang berbeda.


### Google Gemini Vision AI


Google Gemini 1.5 Flash digunakan untuk memvalidasi kesesuaian foto hasil pekerjaan dengan tugas yang diberikan.


Input analisis terdiri dari:


- Deskripsi tugas

- Foto sesudah pekerjaan

- Data gambar dalam format Base64

- MIME type `image/jpeg` atau `image/png`


Output AI berupa data terstruktur yang mencakup:


```text

is_valid_work

actual_activity

confidence

reason

```


Sistem juga memiliki rule-set untuk menolak laporan apabila aktivitas pada foto tidak sesuai dengan tugas, foto berupa selfie, makanan, ruangan kantor, atau gambar yang tidak jelas.


### Python FastAPI Microservice


Microservice Python digunakan untuk pemrosesan spasial, deteksi Fake GPS, dan kalkulasi KPI.


Endpoint utama:


```text

GET  /health

POST /gps/zone/inside

POST /gps/validate-single

POST /gps/analyze

POST /kpi/calculate

POST /kpi/validate

```


Deteksi anomali GPS menggunakan **Isolation Forest** dari Scikit-Learn, sedangkan validasi wilayah menggunakan Shapely dan pendekatan point-in-polygon.


## GPS Tracking & Deteksi Fake GPS


SMPP menggunakan tiga lapisan validasi lokasi.


### 1. Haversine & Batas Kecepatan


Sistem menghitung jarak antara koordinat sebelumnya dan koordinat terbaru menggunakan formula Haversine.


Indikasi anomali diberikan apabila:


- Kecepatan melebihi 100 km/jam

- Terjadi perpindahan lebih dari 1.000 meter dalam waktu kurang dari 10 detik


### 2. Isolation Forest


Data pergerakan digunakan untuk mendeteksi pola yang tidak normal berdasarkan:


```text

distance

speed

delta_lat

delta_lon

```


Model menggunakan Isolation Forest dengan `contamination=0.1`.


### 3. Geofencing Polygon


Setiap zona kerja memiliki batas polygon. Ketika laporan dikirim, koordinat diperiksa untuk menentukan apakah petugas berada di dalam zona penugasan dan menghitung deviasi dari batas zona.

## Sistem KPI


Penilaian KPI dihitung berdasarkan data rolling 30 hari terakhir.


| Komponen | Bobot | Perhitungan |

|---|---:|---|

| Kehadiran (Presence Rate) | 65% | (Hari Hadir / Hari Terjadwal) × 100% |

| Penyelesaian Tugas (Completion Rate) | 25% | (Laporan Approved / Total Diajukan) × 100% |

| Ketepatan & Akurasi AI | 10% | (Foto Valid AI / Total Foto Diaudit) × 100% |


Rumus total:


```text

Total Skor KPI =

(Skor Kehadiran × 0.65) +

(Skor Penyelesaian × 0.25) +

(Skor Akurasi AI × 0.10)

```


Skor maksimum adalah 100 poin. fileciteturn0file0L256-L285


### Threshold KPI


| Rentang Skor | Status | Konsekuensi |

|---|---|---|

| `>= 70.0` | Aktif / Normal | Akses sistem tetap aktif |

| `50.0 - < 70.0` | Peringatan / Warning | Petugas dan Supervisor menerima peringatan |

| `< 50.0` | Diblokir / Nonaktif | Akun dinonaktifkan dan membutuhkan review serta Reset KPI oleh Admin |


## Teknologi yang Digunakan


### Backend


- **PHP 8.x** — Core backend dengan pendekatan Native Object-Oriented

- **PDO** — Transaksi dan akses database

- **Python 3.14+** — AI microservice

- **FastAPI** — Framework microservice Python

- **Uvicorn** — Application server untuk microservice


### Artificial Intelligence & Machine Learning


- **Google Gemini 1.5 Flash** — Vision AI untuk validasi foto pekerjaan

- **Scikit-Learn** — Machine Learning

- **Isolation Forest** — Deteksi anomali pola GPS


### Spatial & Data Processing


- **Shapely** — Operasi geometri dan point-in-polygon

- **NumPy** — Komputasi numerik

- **Pandas** — Pengolahan data

- **GeoPandas** — Pengolahan data geospasial


### Frontend


- **HTML5**

- **CSS3**

- **JavaScript ES6+**

- **Leaflet.js**

- **OpenStreetMap**

- **GeoJSON**

- **Google Fonts — Inter & Karla**

- **Font Awesome 6**


### Database & Server


- **MySQL 8.x / MariaDB**

- **InnoDB**

- **Apache Web Server**

- **HTTP/HTTPS**

- **.htaccess Rewrite**



## Arsitektur Sistem


SMPP menggunakan arsitektur **Monolith Modular PHP 8.x + AI Python FastAPI Microservice**.


```text

                    ┌──────────────────────┐

                    │      Pengguna        │

                    │ Admin / Supervisor   │

                    │      / Petugas       │

                    └──────────┬───────────┘

                               │

                               ▼

                    ┌──────────────────────┐

                    │   Web Application    │

                    │      PHP 8.x         │

                    │   Native OOP + RBAC  │

                    └───────┬───────┬──────┘

                            │       │

              ┌─────────────┘       └──────────────┐

              ▼                                    ▼

      ┌─────────────────┐                 ┌──────────────────┐

      │ MySQL / MariaDB │                 │ Google Gemini AI │

      │     Database    │                 │   Vision API     │

      └─────────────────┘                 └──────────────────┘

                            │

                            ▼

                  ┌─────────────────────┐

                  │ Python FastAPI      │

                  │ Microservice :8001  │

                  └──────────┬──────────┘

                             │

                  ┌──────────┴──────────┐

                  │ GPS / Spatial / KPI │

                  │ Isolation Forest    │

                  └─────────────────────┘

```


Arsitektur tersebut menggabungkan backend web PHP dengan microservice Python untuk pemrosesan AI, spasial, deteksi anomali GPS, dan kalkulasi KPI.


## Database


Database menggunakan **MySQL 8.x / MariaDB (InnoDB)** dan dirancang secara normal hingga bentuk 3NF.


Tabel utama:


```text

users

groups

zones

offices

schedules

reports

report_photos

report_route_points

kpi_scores

kpi_configs

password_resets

activity_logs

location_history

```


### Fungsi Tabel


- `users` — Data akun Admin, Supervisor, dan Petugas

- `groups` — Pengelompokan petugas binaan

- `zones` — Master zona operasional RW

- `offices` — Data titik kantor dan posko

- `schedules` — Jadwal penugasan petugas

- `reports` — Laporan pekerjaan

- `report_photos` — Foto sebelum dan sesudah pekerjaan serta hasil analisis AI

- `report_route_points` — Titik lintasan rute petugas

- `kpi_scores` — Histori perhitungan KPI

- `kpi_configs` — Konfigurasi bobot KPI

- `password_resets` — Tiket pemulihan password

- `activity_logs` — Audit trail aktivitas pengguna

- `location_history` — Histori koordinat GPS




## Keamanan


SMPP menerapkan beberapa mekanisme keamanan:


- Password hashing menggunakan `password_hash()` dengan algoritma Bcrypt

- PDO Prepared Statements untuk mencegah SQL Injection

- CSRF Token untuk form penting

- `hash_equals()` untuk validasi token CSRF

- Sanitasi output menggunakan `safeText()` / `htmlspecialchars()`

- Session regeneration menggunakan `session_regenerate_id(true)`

- Session timeout setelah 30 menit tidak aktif

- Validasi MIME type file upload

- Randomisasi nama file upload

- Isolasi direktori file yang diunggah
