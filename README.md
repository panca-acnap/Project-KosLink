# 🏢 KosLink — Platform Manajemen & Pemesanan Hunian Kos Berbasis Web

**KosLink** adalah aplikasi web sistem informasi pencarian, pemesanan, dan pengelolaan properti kos dengan pendekatan *User-Centered Design (UCD)* serta perlindungan transaksi berbasis sistem *Escrow* (Rekber). Aplikasi ini menghubungkan pencari kos, mitra pemilik kos, dan administrator dalam satu ekosistem terintegrasi.

---

## ✨ Fitur Utama

### 1. Pencari Kos & Penyewa
* **Eksplorasi & Detail Kamar (`detail_kamar.php`):** Galeri foto kamar interaktif, rincian fasilitas, legalitas properti, kontak langsung WhatsApp mitra, serta pembaruan ketersediaan unit secara *real-time*.
* **Pengajuan Sewa & Validasi Identitas (`sewa.php`):** Pemilihan tanggal *check-in*, skema durasi kontrak (1 Bulan, 3 Bulan, 6 Bulan, Tahunan), kalkulasi biaya otomatis, dan validasi identitas unik (Akun, NIK 16 digit, & Nomor WhatsApp).
* **Kebijakan 1 Penyewa = Maksimal 1 Kos Aktif:** Sistem otomatis mencegah penyewa memiliki lebih dari satu hunian aktif secara bersamaan serta mengelola konflik pengajuan berstatus *Pending* (opsi lanjutkan pembayaran lama atau hapus pengajuan lama).
* **Pembayaran & Rekber Escrow (`penyewa/bayar.php`, `bayar_gateway.php`):** Dukungan simulasi pembayaran QRIS, Virtual Account, dan Kartu Debit dengan dana tertahan aman di *Escrow* KosLink hingga proses *check-in* selesai.
* **Dashboard Penghuni & Keluhan (`penyewa/dashboard.php`, `keluhan.php`):** Pemantauan masa aktif sewa, tanggal jatuh tempo, riwayat transaksi, dan pelaporan kendala fasilitas kamar.

### 2. Mitra Pemilik Kos
* **Manajemen Properti & Unit Kamar:** Pengelolaan data kos, harga sewa bulanan, fasilitas, dan unggah galeri foto kamar.
* **Manajemen Penyewa (`mitra/penyewa.php`):** Pendaftaran penyewa manual maupun otomatis dari sistem *booking*, pemantauan status hunian (`Aktif`, `Pending`, `Keluar`), dan validasi duplikasi NIK/No. HP.
* **Sinkronisasi Stok Otomatis:** Pengurangan dan penambahan sisa unit kamar secara otomatis berdasarkan status pembayaran dan *check-out* penghuni.

### 3. Administrator
* **Verifikasi Properti & Kamar:** Validasi kelayakan properti dan kamar mitra sebelum dipublikasikan ke katalog utama.

---

## 🛠️ Teknologi yang Digunakan

* **Backend:** PHP Native (PHP 8.x)
* **Database:** MySQL / MariaDB (`mysqli`)
* **Frontend:** HTML5, Tailwind CSS (CDN), JavaScript (Vanilla)
* **Ikon & Tipografi:** Font Awesome 6, Google Fonts (*Plus Jakarta Sans*)
* **Web Server Lokal:** XAMPP (Apache & MySQL)

---

## 📁 Struktur Direktori Utama

```text
koslink/
├── auth/               # Autentikasi (login, register, logout)
├── mitra/              # Dashboard & manajemen penyewa/properti milik mitra
├── penyewa/            # Dashboard penyewa, tagihan (bayar.php), gateway, keluhan
├── uploads/            # Penyimpanan file foto kamar & dokumen unggahan
├── index.php           # Halaman utama & katalog pencarian kos
├── detail_kamar.php    # Halaman detail unit kamar & galeri foto
├── sewa.php            # Form pengajuan sewa & validasi kontrak
└── koneksi.php         # Konfigurasi koneksi database MySQL