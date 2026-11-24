<?php
require '../koneksi.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['role']) || $_SESSION['role'] === 'mitra') {
    header("Location: ../auth/login.php");
    exit;
}

$id_user = (int)($_SESSION['id_pengguna'] ?? $_SESSION['id_penyewa'] ?? 1);
$nama_user = $_SESSION['nama'] ?? 'penyewa KosLink';

$q_hunian = @mysqli_query($conn, "SELECT * FROM penyewa WHERE id_pengguna = $id_user OR nama_penyewa LIKE '%$nama_user%' ORDER BY id_penyewa DESC LIMIT 1");
$hunian = ($q_hunian && mysqli_num_rows($q_hunian) > 0) ? mysqli_fetch_assoc($q_hunian) : null;
$nominal = (int)($hunian['nominal_sewa'] ?? 1500000) + 50000;
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pembayaran Berhasil - KosLink</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>body { font-family: 'Plus Jakarta Sans', sans-serif; }</style>
</head>
<body class="bg-[#F8FAFC] text-slate-800 min-h-screen flex flex-col">

    <!-- Navbar -->
    <header class="bg-white border-b border-slate-200/85 sticky top-0 z-40">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 h-16 flex items-center justify-between">
            <a href="../index.php" class="flex items-center gap-2.5">
                <div class="w-8 h-8 bg-blue-600 rounded-lg flex items-center justify-center text-white shadow-sm">
                    <i class="fa-solid fa-building text-sm"></i>
                </div>
                <span class="font-extrabold text-slate-900 text-lg tracking-tight">KosLink</span>
            </a>
            <div class="flex items-center gap-3 text-xs font-bold">
                <a href="dashboard.php" class="text-blue-600 bg-blue-50 px-3 py-1.5 rounded-lg">Dashboard Saya</a>
            </div>
        </div>
    </header>

    <!-- Konten Utama Sukses -->
    <main class="max-w-5xl w-full mx-auto px-4 sm:px-6 lg:px-8 py-8 space-y-6 flex-1">

        <!-- Stepper Selesai -->
        <div class="bg-white border border-slate-200/80 rounded-3xl p-6 shadow-sm">
            <div class="relative flex items-center justify-between max-w-xl mx-auto">
                <div class="absolute left-0 right-0 top-1/2 -translate-y-1/2 h-1 bg-blue-600 z-0"></div>
                <div class="relative z-10 flex flex-col items-center gap-1.5">
                    <div class="w-10 h-10 rounded-full bg-blue-600 text-white flex items-center justify-center text-xs"><i class="fa-solid fa-check"></i></div>
                    <span class="text-xs font-bold text-slate-600">Data Sewa</span>
                </div>
                <div class="relative z-10 flex flex-col items-center gap-1.5">
                    <div class="w-10 h-10 rounded-full bg-blue-600 text-white flex items-center justify-center text-xs"><i class="fa-solid fa-check"></i></div>
                    <span class="text-xs font-bold text-slate-600">Pembayaran</span>
                </div>
                <div class="relative z-10 flex flex-col items-center gap-1.5">
                    <div class="w-10 h-10 rounded-full bg-blue-600 text-white flex items-center justify-center text-xs font-extrabold shadow-md"><i class="fa-solid fa-star"></i></div>
                    <span class="text-xs font-extrabold text-blue-600">Sukses &amp; E-Kontrak</span>
                </div>
            </div>
        </div>

        <!-- Banner Sukses -->
        <div class="bg-white border border-slate-200/80 rounded-3xl p-8 text-center space-y-4 shadow-sm relative overflow-hidden">
            <div class="w-14 h-14 bg-emerald-100 text-emerald-600 rounded-2xl flex items-center justify-center text-2xl mx-auto shadow-sm">
                <i class="fa-solid fa-circle-check"></i>
            </div>
            <div class="space-y-1">
                <span class="inline-flex items-center gap-1.5 bg-emerald-50 text-emerald-700 border border-emerald-200 text-[10px] font-bold px-3 py-1 rounded-full">
                    <span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span> Status: LUNAS &bull; Terverifikasi Sistem
                </span>
                <h1 class="text-2xl font-extrabold text-slate-900 mt-2">Pembayaran Berhasil! Kamar Resmi Milikmu</h1>
                <p class="text-xs text-slate-500 max-w-lg mx-auto leading-relaxed">
                    Kontrak sewa Anda telah aktif secara legal. E-Receipt resmi dan dokumen Surat Perjanjian Sewa Digital (E-Kontrak) berlegitimasi telah otomatis dikirimkan ke email Anda.
                </p>
            </div>

            <div class="pt-2">
                <span class="bg-slate-100 text-slate-700 text-xs font-mono font-bold px-4 py-2 rounded-xl inline-block border border-slate-200">
                    KODE BOOKING KOSLINK: <strong class="text-blue-600">KL-UDY-9941</strong>
                </span>
            </div>
        </div>

        <!-- Detail E-Receipt & Kontak Pemilik -->
        <div class="grid grid-cols-1 lg:grid-cols-12 gap-6">
            
            <div class="lg:col-span-7 bg-white border border-slate-200/80 rounded-3xl p-6 shadow-sm space-y-4 text-xs">
                <h3 class="font-extrabold text-slate-900 border-b border-slate-100 pb-3 flex items-center gap-2">
                    <i class="fa-solid fa-receipt text-blue-600"></i> E-Receipt Resmi Transaksi
                </h3>

                <div class="space-y-2 text-slate-600">
                    <div class="flex justify-between">
                        <span>ID Transaksi</span>
                        <span class="font-mono font-bold text-slate-900">TRX-KOSLINK-884920412</span>
                    </div>
                    <div class="flex justify-between">
                        <span>Metode Pembayaran</span>
                        <span class="font-bold text-slate-900">QRIS BCA (Auto-Verified)</span>
                    </div>
                    <div class="flex justify-between">
                        <span>Periode Sewa</span>
                        <span class="font-bold text-slate-900">1 Bulan (<?= date('d M Y') ?>)</span>
                    </div>
                    <div class="flex justify-between pt-2 border-t border-slate-100">
                        <span class="font-extrabold text-slate-900">Total Dibayar</span>
                        <span class="text-sm font-extrabold text-blue-600">Rp <?= number_format($nominal, 0, ',', '.') ?></span>
                    </div>
                </div>

                <div class="pt-4 flex items-center gap-3">
                    <a href="dashboard.php" class="flex-1 text-center bg-blue-600 hover:bg-blue-700 text-white text-xs font-extrabold py-3 rounded-xl transition shadow-sm">
                        Kembali ke Dashboard Saya
                    </a>
                </div>
            </div>

            <div class="lg:col-span-5 bg-white border border-slate-200/80 rounded-3xl p-6 shadow-sm space-y-4 text-xs">
                <h3 class="font-extrabold text-slate-900 border-b border-slate-100 pb-3 flex items-center gap-2">
                    <i class="fa-solid fa-user-tie text-blue-600"></i> Kontak Pengelola / Pemilik Kos
                </h3>

                <div class="flex items-center gap-3 p-3.5 rounded-2xl bg-slate-50 border border-slate-200/70">
                    <div class="w-10 h-10 rounded-full bg-blue-100 text-blue-600 flex items-center justify-center font-bold">
                        <i class="fa-solid fa-user"></i>
                    </div>
                    <div>
                        <p class="font-extrabold text-slate-900">Pak Wayan Sudarma</p>
                        <p class="text-[10px] text-slate-500">Pemilik &amp; Manajer Properti</p>
                    </div>
                </div>

                <a href="https://wa.me/6281234567890" target="_blank" class="w-full bg-emerald-600 hover:bg-emerald-700 text-white font-extrabold py-3 rounded-xl transition shadow-sm flex items-center justify-center gap-2">
                    <i class="fa-brands fa-whatsapp text-sm"></i> Chat WhatsApp Pemilik Kos
                </a>
            </div>

        </div>

    </main>
</body>
</html>