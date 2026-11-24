<?php
require '../koneksi.php';
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['role']) || $_SESSION['role'] === 'mitra') {
    header("Location: ../auth/login.php");
    exit;
}

$nama_user   = $_SESSION['nama'] ?? 'Pengguna KosLink';
$id_pengguna = (int)($_SESSION['id_pengguna'] ?? $_SESSION['id_penyewa'] ?? 0);

function getTableCols($conn, $table) {
    $cols = [];
    $q = @mysqli_query($conn, "SHOW COLUMNS FROM `$table`");
    if ($q) {
        while ($r = mysqli_fetch_assoc($q)) {
            $cols[] = $r['Field'];
        }
    }
    return $cols;
}

// Hapus pengajuan lama (Pending) jika penyewa ingin membatalkannya
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['batal_pengajuan_pending']) && $id_pengguna > 0) {
    $id_batal = (int)($_POST['id_penyewa_batal'] ?? 0);
    if ($id_batal > 0) {
        @mysqli_query(
            $conn,
            "DELETE FROM penyewa 
             WHERE id_penyewa = $id_batal 
               AND id_pengguna = $id_pengguna 
               AND LOWER(TRIM(COALESCE(status_hunian, ''))) IN ('', 'pending', 'menunggu')
               AND LOWER(TRIM(COALESCE(status_bayar, ''))) NOT IN ('lunas', 'paid', 'terbayar')"
        );
    }
    header("Location: dashboard.php");
    exit;
}

$cols_penyewa = getTableCols($conn, 'penyewa');
$fk_pengguna  = in_array('id_pengguna', $cols_penyewa, true) ? 'id_pengguna' : (in_array('id_pencari', $cols_penyewa, true) ? 'id_pencari' : '');

$data_sewa = null;
$properti_sewa = null;
$kamar_sewa = null;

if (count($cols_penyewa) > 0) {
    if ($fk_pengguna !== '' && $id_pengguna > 0) {
        $q_lunas = @mysqli_query(
            $conn,
            "SELECT * FROM penyewa 
             WHERE `$fk_pengguna` = $id_pengguna 
               AND (LOWER(status_bayar) IN ('lunas', 'sudah bayar', 'paid') OR LOWER(status_hunian) = 'aktif')
             ORDER BY id_penyewa DESC LIMIT 1"
        );
        if ($q_lunas && mysqli_num_rows($q_lunas) > 0) {
            $data_sewa = mysqli_fetch_assoc($q_lunas);
        } else {
            $q_sewa = @mysqli_query(
                $conn,
                "SELECT * FROM penyewa 
                 WHERE `$fk_pengguna` = $id_pengguna 
                   AND LOWER(TRIM(COALESCE(status_hunian, ''))) NOT IN ('selesai', 'keluar', 'check-out', 'checkout', 'batal', 'dibatalkan', 'nonaktif')
                 ORDER BY id_penyewa DESC LIMIT 1"
            );
            if ($q_sewa && mysqli_num_rows($q_sewa) > 0) {
                $data_sewa = mysqli_fetch_assoc($q_sewa);
            }
        }
    }
}

if ($data_sewa !== null) {
    $id_prop = (int)($data_sewa['id_properti'] ?? 0);
    if ($id_prop > 0) {
        $cols_prop = getTableCols($conn, 'properti_kos');
        if (count($cols_prop) > 0) {
            $pk_prop = in_array('id_properti', $cols_prop, true) ? 'id_properti' : 'id_kos';
            $q_p = @mysqli_query($conn, "SELECT * FROM properti_kos WHERE `$pk_prop` = $id_prop LIMIT 1");
            if ($q_p) {
                $properti_sewa = mysqli_fetch_assoc($q_p);
            }
        }
    }

    $id_kam = (int)($data_sewa['id_kamar'] ?? 0);
    if ($id_kam > 0) {
        $cols_kam = getTableCols($conn, 'kamar');
        if (count($cols_kam) > 0) {
            $pk_kam = in_array('id_kamar', $cols_kam, true) ? 'id_kamar' : 'id';
            $q_k = @mysqli_query($conn, "SELECT * FROM kamar WHERE `$pk_kam` = $id_kam LIMIT 1");
            if ($q_k) {
                $kamar_sewa = mysqli_fetch_assoc($q_k);
            }
        }
    }
}

$foto_kamar_url = 'https://images.unsplash.com/photo-1522708323590-d24dbb6b0267?w=300&q=80';
$raw_foto = '';
if ($kamar_sewa) {
    $id_kam_cek = (int)($kamar_sewa['id_kamar'] ?? $kamar_sewa['id'] ?? 0);
    if ($id_kam_cek > 0 && count(getTableCols($conn, 'foto_kamar')) > 0) {
        $q_fk = @mysqli_query($conn, "SELECT nama_file FROM foto_kamar WHERE id_kamar = $id_kam_cek ORDER BY id_foto ASC LIMIT 1");
        if ($q_fk && $r_fk = mysqli_fetch_assoc($q_fk)) {
            $raw_foto = trim($r_fk['nama_file'] ?? '');
        }
    }
    if ($raw_foto === '') {
        foreach (['foto_kamar', 'foto', 'gambar'] as $col_f) {
            if (!empty($kamar_sewa[$col_f])) {
                $raw_foto = trim($kamar_sewa[$col_f]);
                break;
            }
        }
    }
}
if ($raw_foto !== '') {
    if (preg_match('#^https?://#i', $raw_foto)) {
        $foto_kamar_url = $raw_foto;
    } else {
        $clean_file = ltrim($raw_foto, '/');
        $foto_kamar_url = (strpos($clean_file, 'uploads/') === 0) ? '../' . $clean_file : '../uploads/' . $clean_file;
    }
}

$sudah_sewa = ($data_sewa !== null);

$nama_kos_db   = $properti_sewa['nama_kos'] ?? $properti_sewa['nama_properti'] ?? 'Kos Pilihan Anda';
$alamat_kos    = $properti_sewa['alamat'] ?? $properti_sewa['lokasi'] ?? 'Lokasi Kos';
$no_kamar_db   = $data_sewa['no_kamar'] ?? ($kamar_sewa['nama_kamar'] ?? 'Kamar Utama');
$skema_kontrak = $data_sewa['skema_kontrak'] ?? '1 Bulan';
$nominal_sewa  = (float)($data_sewa['nominal_sewa'] ?? ($kamar_sewa['harga_bulan'] ?? 0));
$tanggal_masuk = $data_sewa['tanggal_masuk'] ?? date('Y-m-d');
$jatuh_tempo   = $data_sewa['jatuh_tempo'] ?? date('Y-m-d');
$status_bayar  = strtolower(trim((string)($data_sewa['status_bayar'] ?? '')));
$is_lunas      = in_array($status_bayar, ['lunas', 'sudah bayar', 'paid'], true);

$nama_user_esc = mysqli_real_escape_string($conn, $nama_user);
$jumlah_keluhan_aktif = 0;
if (count(getTableCols($conn, 'keluhan')) > 0) {
    $q_cnt = @mysqli_query($conn, "SELECT COUNT(*) AS jml FROM `keluhan` WHERE `nama_penyewa` = '$nama_user_esc' AND `status` != 'Selesai'");
    if ($q_cnt && $r_cnt = mysqli_fetch_assoc($q_cnt)) {
        $jumlah_keluhan_aktif = (int)$r_cnt['jml'];
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard Penghuni - KosLink</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>body { font-family: 'Plus Jakarta Sans', sans-serif; }</style>
</head>
<body class="bg-[#F8FAFC] text-slate-800 min-h-screen flex flex-col">

    <!-- NAVBAR TOP SERAGAM -->
    <header class="bg-white border-b border-slate-200/80 sticky top-0 z-40">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 h-16 flex items-center justify-between">
            <div class="flex items-center gap-8">
                <a href="../index.php" class="flex items-center gap-2.5 shrink-0">
                    <div class="w-8 h-8 bg-blue-600 rounded-lg flex items-center justify-center text-white shadow-sm">
                        <i class="fa-solid fa-building text-sm"></i>
                    </div>
                    <span class="font-extrabold text-slate-900 text-lg tracking-tight">KosLink</span>
                </a>
                <nav class="hidden md:flex items-center gap-6 text-xs font-bold">
                    <a href="dashboard.php" class="text-blue-600 border-b-2 border-blue-600 py-5">Beranda</a>
                    <a href="bayar.php" class="text-slate-500 hover:text-slate-900 py-5 transition">Tagihan &amp; Pembayaran</a>
                    <a href="keluhan.php" class="text-slate-500 hover:text-slate-900 py-5 transition flex items-center gap-1.5">
                        Keluhan &amp; Bantuan
                        <?php if ($jumlah_keluhan_aktif > 0): ?>
                            <span class="bg-amber-100 text-amber-800 text-[10px] px-2 py-0.5 rounded-full font-extrabold"><?= $jumlah_keluhan_aktif ?> Aktif</span>
                        <?php endif; ?>
                    </a>
                </nav>
            </div>
            <div class="flex items-center gap-4">
                <div class="flex items-center gap-2.5 pl-3 border-l border-slate-200">
                    <div class="w-9 h-9 rounded-full bg-blue-600 text-white flex items-center justify-center font-bold text-xs shadow-xs">
                        <i class="fa-regular fa-user"></i>
                    </div>
                    <div class="hidden sm:block text-left">
                        <p class="text-xs font-extrabold text-slate-900 leading-tight"><?= htmlspecialchars($nama_user) ?></p>
                        <p class="text-[10px] text-slate-400 font-medium"><?= $sudah_sewa ? ($is_lunas ? 'Penyewa Aktif (1/1 Kos)' : 'Menunggu Bayar') . ' &bull; ' . htmlspecialchars($no_kamar_db) : 'Pencari Kos (0/1 Kos)' ?></p>
                    </div>
                    <a href="../auth/logout.php" title="Keluar" class="ml-1 text-slate-400 hover:text-rose-600 text-xs transition">
                        <i class="fa-solid fa-right-from-bracket"></i>
                    </a>
                </div>
            </div>
        </div>
    </header>

    <!-- KONTEN UTAMA -->
    <main class="max-w-7xl w-full mx-auto px-4 sm:px-6 lg:px-8 py-7 space-y-6 flex-1">

        <!-- GREETING & QUICK ACTIONS -->
        <div class="flex flex-col lg:flex-row lg:items-center justify-between gap-4">
            <div class="space-y-1.5">
                <div class="flex items-center gap-2 text-xs">
                    <span class="inline-flex items-center gap-1.5 <?= $is_lunas ? 'bg-emerald-50 text-emerald-700 border-emerald-200/80' : 'bg-blue-50 text-blue-700 border-blue-200/80' ?> border font-bold px-3 py-1 rounded-full text-[11px]">
                        <span class="w-1.5 h-1.5 rounded-full <?= $is_lunas ? 'bg-emerald-600' : 'bg-blue-600' ?>"></span>
                        Status: <?= $sudah_sewa ? ($is_lunas ? 'Penghuni Aktif (' . htmlspecialchars($skema_kontrak) . ')' : 'Menunggu Pembayaran') : 'Belum Memiliki Kos' ?>
                    </span>
                    <?php if ($sudah_sewa): ?>
                        <span class="text-slate-300">&bull;</span>
                        <span class="font-bold text-slate-600"><?= htmlspecialchars($no_kamar_db) ?> (Kuota 1/1 Kos)</span>
                    <?php endif; ?>
                </div>
                <h1 class="text-2xl sm:text-3xl font-extrabold text-slate-900 tracking-tight">Halo, <?= htmlspecialchars($nama_user) ?> 👋</h1>
                <p class="text-xs text-slate-500">
                    <?= $sudah_sewa ? 'Kelola sewa kamar, cek fasilitas, dan pantau tagihan bulanan dengan mudah.' : 'Temukan hunian idamanmu sekarang dan mulai pemesanan pertama dengan aman melalui KosLink.' ?>
                </p>
            </div>
            <div class="flex flex-wrap items-center gap-2.5">
                <?php if ($sudah_sewa): ?>
                    <a href="#kos-saya" class="bg-white hover:bg-slate-50 text-slate-700 border border-slate-200 px-4 py-2.5 rounded-xl text-xs font-bold shadow-2xs inline-flex items-center gap-2 transition">
                        <i class="fa-regular fa-building text-blue-600"></i> Lihat Kamar Saya
                    </a>
                    <a href="keluhan.php" class="bg-white hover:bg-slate-50 text-slate-700 border border-slate-200 px-4 py-2.5 rounded-xl text-xs font-bold shadow-2xs inline-flex items-center gap-2 transition">
                        <i class="fa-solid fa-triangle-exclamation text-rose-500"></i> Lapor Keluhan
                    </a>
                <?php else: ?>
                    <a href="../index.php" class="bg-blue-600 hover:bg-blue-700 text-white px-5 py-2.5 rounded-xl text-xs font-bold shadow-sm inline-flex items-center gap-2 transition">
                        <i class="fa-solid fa-magnifying-glass"></i> Cari &amp; Sewa Kos Sekarang
                    </a>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($sudah_sewa): ?>
            <!-- BANNER TAGIHAN SEWA -->
            <div class="bg-white border border-slate-200/80 rounded-3xl p-6 shadow-sm space-y-5">
                <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2 pb-4 border-b border-slate-100 text-xs">
                    <div class="flex items-center gap-2.5 <?= $is_lunas ? 'text-emerald-700' : 'text-amber-700' ?> font-bold">
                        <div class="w-7 h-7 rounded-lg <?= $is_lunas ? 'bg-emerald-50 border-emerald-200 text-emerald-600' : 'bg-amber-50 border-amber-200 text-amber-600' ?> border flex items-center justify-center">
                            <i class="fa-regular fa-calendar-check"></i>
                        </div>
                        <div>
                            <span class="uppercase tracking-wider text-[11px] font-extrabold <?= $is_lunas ? 'text-emerald-800' : 'text-amber-800' ?> block">
                                <?= $is_lunas ? 'TAGIHAN PERIODE INI LUNAS' : 'PEMBAYARAN UNTUK "' . strtoupper(htmlspecialchars($no_kamar_db)) . '" BELUM SELESAI' ?>
                            </span>
                            <span class="text-slate-500 font-medium">Jatuh tempo berikutnya pada <strong class="text-slate-800"><?= htmlspecialchars($jatuh_tempo) ?></strong></span>
                        </div>
                    </div>
                </div>

                <div class="grid grid-cols-1 lg:grid-cols-12 gap-6 items-center">
                    <div class="lg:col-span-5 flex items-center gap-4 p-4 rounded-2xl bg-slate-50/80 border border-slate-200/70">
                        <div class="w-20 h-20 rounded-xl overflow-hidden bg-slate-200 relative shrink-0">
                            <img src="<?= htmlspecialchars($foto_kamar_url) ?>"
                                 onerror="this.src='https://images.unsplash.com/photo-1522708323590-d24dbb6b0267?w=300&q=80'"
                                 alt="<?= htmlspecialchars($no_kamar_db) ?>"
                                 class="w-full h-full object-cover">
                            <span class="absolute bottom-1 left-1 bg-slate-900/80 text-white text-[9px] font-bold px-1.5 py-0.5 rounded"><?= htmlspecialchars($no_kamar_db) ?></span>
                        </div>
                        <div class="space-y-1 text-xs">
                            <span class="text-[10px] font-extrabold text-blue-600 uppercase tracking-wider">Hunian Terdaftar</span>
                            <h3 class="text-sm font-extrabold text-slate-900"><?= htmlspecialchars($nama_kos_db) ?></h3>
                            <p class="text-[11px] text-slate-500"><?= htmlspecialchars($alamat_kos) ?></p>
                            <p class="text-[11px] text-slate-400 pt-0.5"><i class="fa-regular fa-calendar mr-1"></i> Mulai Masuk: <?= htmlspecialchars($tanggal_masuk) ?></p>
                        </div>
                    </div>

                    <div class="lg:col-span-4 space-y-2 text-xs lg:border-l lg:border-slate-100 lg:pl-6">
                        <div class="flex justify-between text-slate-500">
                            <span>Biaya Kontrak / Periode</span>
                            <span class="font-bold text-slate-800">Rp <?= number_format($nominal_sewa, 0, ',', '.') ?></span>
                        </div>
                        <div class="flex justify-between text-slate-500">
                            <span>Status Escrow KosLink</span>
                            <span class="font-bold text-emerald-600"><?= htmlspecialchars($data_sewa['status_escrow'] ?? 'Aman') ?></span>
                        </div>
                        <div class="pt-2.5 border-t border-slate-100 flex justify-between items-baseline">
                            <div>
                                <p class="text-[10px] font-extrabold text-slate-400 uppercase">TOTAL NOMINAL SEWA</p>
                                <p class="text-[10px] text-emerald-600 font-semibold">Bebas biaya admin transfer bank</p>
                            </div>
                            <span class="text-2xl font-extrabold text-blue-600">Rp <?= number_format($nominal_sewa, 0, ',', '.') ?></span>
                        </div>
                    </div>

                    <div class="lg:col-span-3 flex flex-col gap-2.5">
                        <a href="bayar.php?id_sewa=<?= (int)$data_sewa['id_penyewa'] ?>" class="w-full text-center bg-blue-600 hover:bg-blue-700 text-white text-xs font-extrabold py-3.5 px-4 rounded-xl transition shadow-sm flex items-center justify-center gap-2">
                            <i class="fa-solid fa-wallet"></i> <?= $is_lunas ? 'Lihat Riwayat & Tagihan' : 'Lanjutkan Pembayaran Lama' ?>
                        </a>
                        <?php if (!$is_lunas): ?>
                        <form method="POST" onsubmit="return confirm('Hapus pengajuan sewa yang belum dibayar ini agar Anda bisa memilih kamar kos lain?');">
                            <input type="hidden" name="batal_pengajuan_pending" value="1">
                            <input type="hidden" name="id_penyewa_batal" value="<?= (int)$data_sewa['id_penyewa'] ?>">
                            <button type="submit" class="w-full bg-rose-50 hover:bg-rose-100 text-rose-700 border border-rose-200 text-xs font-extrabold py-2.5 px-4 rounded-xl transition flex items-center justify-center gap-1.5">
                                <i class="fa-solid fa-trash-can"></i> Hapus &amp; Pilih Kos Lain
                            </button>
                        </form>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- SECTION TENGAH: KOS SAYA -->
            <div class="grid grid-cols-1 lg:grid-cols-12 gap-6" id="kos-saya">
                <div class="lg:col-span-12 bg-white border border-slate-200/80 rounded-3xl p-6 shadow-sm space-y-5">
                    <div class="flex items-center justify-between border-b border-slate-100 pb-4">
                        <div class="flex items-center gap-2.5">
                            <div class="w-8 h-8 rounded-xl bg-blue-50 text-blue-600 flex items-center justify-center text-xs"><i class="fa-solid fa-house-chimney"></i></div>
                            <h2 class="text-base font-extrabold text-slate-900">Kos Saya yang Sedang Aktif</h2>
                        </div>
                        <span class="text-xs font-extrabold text-blue-600 bg-blue-50 border border-blue-100 px-3 py-1 rounded-full"><?= htmlspecialchars($no_kamar_db) ?></span>
                    </div>

                    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
                        <div>
                            <p class="text-[10px] font-extrabold text-slate-400 uppercase tracking-wider">HUNIAN SAAT INI</p>
                            <h3 class="text-lg font-extrabold text-slate-900 mt-0.5"><?= htmlspecialchars($nama_kos_db) ?></h3>
                            <p class="text-xs text-slate-500 mt-0.5"><i class="fa-solid fa-location-dot text-blue-600 mr-1"></i> <?= htmlspecialchars($alamat_kos) ?></p>
                        </div>
                        <div class="text-left sm:text-right">
                            <span class="inline-flex items-center gap-1.5 bg-emerald-50 text-emerald-700 border border-emerald-200 text-[11px] font-bold px-3 py-1 rounded-full">
                                <span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span> Skema: <?= htmlspecialchars($skema_kontrak) ?>
                            </span>
                        </div>
                    </div>
                </div>
            </div>
        <?php else: ?>
            <div class="bg-white border border-slate-200/80 rounded-3xl p-12 text-center space-y-4 shadow-sm">
                <div class="w-16 h-16 bg-blue-50 text-blue-600 rounded-2xl flex items-center justify-center mx-auto text-2xl">
                    <i class="fa-regular fa-building"></i>
                </div>
                <div class="space-y-1 max-w-md mx-auto">
                    <h3 class="text-lg font-extrabold text-slate-900">Belum Ada Kamar yang Disewa</h3>
                    <p class="text-xs text-slate-500">Anda belum memiliki riwayat pemesanan kos aktif. Jelajahi ribuan pilihan kos terverifikasi kami dan lakukan pemesanan pertamamu.</p>
                </div>
                <div class="pt-2">
                    <a href="../index.php" class="bg-blue-600 hover:bg-blue-700 text-white text-xs font-extrabold px-6 py-3 rounded-xl transition inline-flex items-center gap-2 shadow-md">
                        <i class="fa-solid fa-magnifying-glass"></i> Cari Kos Sekarang
                    </a>
                </div>
            </div>
        <?php endif; ?>

    </main>

    <!-- FOOTER -->
    <footer class="bg-white border-t border-slate-200/80 mt-12 text-xs text-slate-500">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6 flex flex-col sm:flex-row items-center justify-between gap-4">
            <div class="flex items-center gap-2.5">
                <div class="w-7 h-7 bg-blue-600 rounded-lg flex items-center justify-center text-white text-xs"><i class="fa-solid fa-building"></i></div>
                <span class="font-extrabold text-slate-900 text-sm">KosLink</span>
            </div>
            <div class="flex flex-wrap items-center gap-5 font-medium">
                <a href="../index.php" class="hover:text-blue-600">Cari Kos</a>
                <a href="keluhan.php" class="hover:text-blue-600">Keluhan &amp; Bantuan</a>
            </div>
        </div>
        <div class="border-t border-slate-100">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-4 flex flex-col sm:flex-row items-center justify-between gap-2 text-[11px] text-slate-400">
                <p>&copy; 2026 PT KosLink Digital Indonesia. Hak cipta dilindungi undang-undang.</p>
                <p class="inline-flex items-center gap-1.5 text-emerald-600 font-semibold"><span class="w-2 h-2 rounded-full bg-emerald-500"></span> Sistem Berjalan Normal</p>
            </div>
        </div>
    </footer>

</body>
</html>