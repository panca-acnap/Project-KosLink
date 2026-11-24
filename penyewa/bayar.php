<?php
require '../koneksi.php';
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['role']) || $_SESSION['role'] === 'mitra') {
    header("Location: ../auth/login.php");
    exit;
}

$nama_user   = $_SESSION['nama'] ?? 'pengguna';
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

$data_sewa = null;
$properti_sewa = null;
$kamar_sewa = null;

$cols_penyewa = getTableCols($conn, 'penyewa');
$fk_pengguna  = in_array('id_pengguna', $cols_penyewa, true) ? 'id_pengguna' : 'id_pencari';

$id_sewa_param = isset($_GET['id_sewa']) ? (int)$_GET['id_sewa'] : 0;
if ($id_sewa_param > 0) {
    $q_sewa = @mysqli_query($conn, "SELECT * FROM penyewa WHERE id_penyewa = $id_sewa_param LIMIT 1");
} else {
    $q_sewa = @mysqli_query($conn, "SELECT * FROM penyewa WHERE `$fk_pengguna` = $id_pengguna ORDER BY id_penyewa DESC LIMIT 1");
}

if ($q_sewa && mysqli_num_rows($q_sewa) > 0) {
    $data_sewa = mysqli_fetch_assoc($q_sewa);

    $id_prop = (int)($data_sewa['id_properti'] ?? 0);
    if ($id_prop > 0) {
        $cols_prop = getTableCols($conn, 'properti_kos');
        $pk_prop = in_array('id_properti', $cols_prop, true) ? 'id_properti' : 'id_kos';
        $q_p = @mysqli_query($conn, "SELECT * FROM properti_kos WHERE `$pk_prop` = $id_prop LIMIT 1");
        if ($q_p) {
            $properti_sewa = mysqli_fetch_assoc($q_p);
        }
    }

    $id_kam = (int)($data_sewa['id_kamar'] ?? 0);
    if ($id_kam > 0) {
        $cols_kam = getTableCols($conn, 'kamar');
        $pk_kam = in_array('id_kamar', $cols_kam, true) ? 'id_kamar' : 'id';
        $q_k = @mysqli_query($conn, "SELECT * FROM kamar WHERE `$pk_kam` = $id_kam LIMIT 1");
        if ($q_k) {
            $kamar_sewa = mysqli_fetch_assoc($q_k);
        }
    }
}

$id_sewa_aktif = (int)($data_sewa['id_penyewa'] ?? 0);

// Cek apakah sudah punya kos AKTIF lain (berdasarkan id_pengguna, NIK, atau No. HP)
$sudah_punya_kos_lain = false;
$kos_aktif_lain       = null;
if ($id_sewa_aktif > 0) {
    $cond_lain = [];
    if ($id_pengguna > 0) {
        $cond_lain[] = "id_pengguna = $id_pengguna";
    }
    $nik_s = preg_replace('/[^0-9]/', '', (string)($data_sewa['nik'] ?? $_SESSION['nik'] ?? ''));
    if ($nik_s !== '' && in_array('nik', $cols_penyewa, true)) {
        $nik_esc = mysqli_real_escape_string($conn, $nik_s);
        $cond_lain[] = "nik = '$nik_esc'";
    }
    $hp_s = preg_replace('/[^0-9]/', '', (string)($data_sewa['no_hp'] ?? $_SESSION['no_hp'] ?? ''));
    if ($hp_s !== '') {
        $hp_esc = mysqli_real_escape_string($conn, $hp_s);
        $cond_lain[] = "REPLACE(REPLACE(REPLACE(no_hp, '-', ''), ' ', ''), '+', '') = '$hp_esc'";
    }

    if (!empty($cond_lain)) {
        $where_lain = '(' . implode(' OR ', $cond_lain) . ')';
        $q_lain = @mysqli_query(
            $conn,
            "SELECT id_penyewa, no_kamar FROM penyewa 
             WHERE $where_lain 
               AND id_penyewa != $id_sewa_aktif 
               AND (LOWER(TRIM(COALESCE(status_hunian, ''))) = 'aktif' OR LOWER(TRIM(COALESCE(status_bayar, ''))) IN ('lunas', 'paid', 'terbayar'))
             LIMIT 1"
        );
        if ($q_lain && mysqli_num_rows($q_lain) > 0) {
            $sudah_punya_kos_lain = true;
            $kos_aktif_lain = mysqli_fetch_assoc($q_lain);
        }
    }
}

// Cek apakah tagihan ini sudah LUNAS
$trx_lunas_terakhir = null;
$cols_trx = getTableCols($conn, 'transaksi');
if (!empty($cols_trx) && $id_pengguna > 0 && $id_sewa_aktif > 0) {
    $col_user_trx = in_array('id_pengguna', $cols_trx, true) ? 'id_pengguna' : 'id_penyewa';
    $col_date_trx = in_array('tanggal_transaksi', $cols_trx, true) ? 'tanggal_transaksi' : 'id_transaksi';
    $where_trx = "`$col_user_trx` = $id_pengguna";
    if (in_array('id_penyewa', $cols_trx, true)) {
        $where_trx = "id_penyewa = $id_sewa_aktif";
    }
    $q_trx = @mysqli_query($conn, "SELECT * FROM transaksi WHERE $where_trx ORDER BY `$col_date_trx` DESC");
    if ($q_trx) {
        while ($rt = mysqli_fetch_assoc($q_trx)) {
            $st_t = strtolower(trim((string)($rt['status_pembayaran'] ?? $rt['status'] ?? '')));
            if ($trx_lunas_terakhir === null && in_array($st_t, ['lunas', 'berhasil', 'sukses', 'paid'], true)) {
                $trx_lunas_terakhir = $rt;
            }
        }
    }
}

$status_bayar_db = strtolower(trim((string)($data_sewa['status_bayar'] ?? '')));
$is_sudah_lunas  = in_array($status_bayar_db, ['lunas', 'sudah bayar', 'berhasil', 'paid'], true) || ($trx_lunas_terakhir !== null);

if ($is_sudah_lunas && $id_sewa_aktif > 0 && !in_array($status_bayar_db, ['lunas', 'sudah bayar'], true)) {
    @mysqli_query($conn, "UPDATE penyewa SET status_bayar = 'Lunas', status_escrow = 'Terkunci (Escrow)', status_hunian = 'Aktif' WHERE id_penyewa = $id_sewa_aktif");
}

// Foto kamar
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

$cols_kamar_all = getTableCols($conn, 'kamar');
$col_harga_db   = in_array('harga_bulan', $cols_kamar_all, true) ? 'harga_bulan' : (in_array('harga_bulanan', $cols_kamar_all, true) ? 'harga_bulanan' : 'harga');

$harga_per_bulan = (int)($kamar_sewa[$col_harga_db] ?? 1500000);
$skema_kontrak   = $data_sewa['skema_kontrak'] ?? '1 Bulan';

$multiplier = 1;
if ($skema_kontrak === 'Per 3 Bulan') $multiplier = 3;
elseif ($skema_kontrak === '6 Bulan') $multiplier = 6;
elseif ($skema_kontrak === 'Tahunan') $multiplier = 12;

$biaya_sewa_pokok = $harga_per_bulan * $multiplier;
$biaya_deposit    = 500000;
$grand_total      = $biaya_sewa_pokok + $biaya_deposit;

$nama_kos_db      = $properti_sewa['nama_kos'] ?? $properti_sewa['nama_properti'] ?? 'Kos Udayana Exclusive Residence';
$alamat_kos       = $properti_sewa['alamat'] ?? $properti_sewa['lokasi'] ?? 'Jimbaran, Bali';
$no_kamar_db      = $data_sewa['no_kamar'] ?? 'Kamar 204';
$tanggal_masuk    = $data_sewa['tanggal_masuk'] ?? date('Y-m-d');
$jatuh_tempo      = $data_sewa['jatuh_tempo'] ?? date('Y-m-d', strtotime('+30 days'));
$nominal_terbayar = (int)($trx_lunas_terakhir['total_harga'] ?? $data_sewa['nominal_sewa'] ?? $grand_total);

if ($id_sewa_aktif > 0 && !$is_sudah_lunas) {
    @mysqli_query($conn, "UPDATE penyewa SET nominal_sewa = $grand_total WHERE id_penyewa = $id_sewa_aktif");
}

$nama_user_esc = mysqli_real_escape_string($conn, $nama_user);
$jumlah_aktif = 0;
if (count(getTableCols($conn, 'keluhan')) > 0) {
    $q_cnt = @mysqli_query($conn, "SELECT COUNT(*) AS jml FROM `keluhan` WHERE `nama_penyewa` = '$nama_user_esc' AND `status` != 'Selesai'");
    if ($q_cnt && $r_cnt = mysqli_fetch_assoc($q_cnt)) {
        $jumlah_aktif = (int)$r_cnt['jml'];
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['proses_bayar']) && !$is_sudah_lunas && !$sudah_punya_kos_lain) {
    $metode_pilih = $_POST['metode'] ?? 'qris';
    if (!in_array($metode_pilih, ['qris', 'va', 'kartu'], true)) {
        $metode_pilih = 'qris';
    }
    $auto_debit_25 = isset($_POST['auto_debit_25']) ? 1 : 0;

    if ($id_sewa_aktif > 0) {
        @mysqli_query($conn, "UPDATE penyewa SET status_bayar = 'Menunggu Pembayaran' WHERE id_penyewa = $id_sewa_aktif");
    }

    $_SESSION['auto_debit_25'] = $auto_debit_25;
    header("Location: bayar_gateway.php?id_sewa=" . $id_sewa_aktif . "&metode=" . urlencode($metode_pilih) . "&auto_debit_25=" . $auto_debit_25);
    exit;
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tagihan &amp; Pembayaran - KosLink</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>body { font-family: 'Plus Jakarta Sans', sans-serif; }</style>
</head>
<body class="bg-[#F8FAFC] text-slate-800 min-h-screen flex flex-col">

    <!-- NAVBAR TOP -->
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
                    <a href="dashboard.php" class="text-slate-500 hover:text-slate-900 py-5 transition">Beranda</a>
                    <a href="bayar.php" class="text-blue-600 border-b-2 border-blue-600 py-5">Tagihan &amp; Pembayaran</a>
                    <a href="keluhan.php" class="text-slate-500 hover:text-slate-900 py-5 transition flex items-center gap-1.5">
                        Keluhan &amp; Bantuan
                        <?php if ($jumlah_aktif > 0): ?>
                        <span class="bg-amber-100 text-amber-800 text-[10px] px-2 py-0.5 rounded-full font-extrabold"><?= $jumlah_aktif ?> Aktif</span>
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
                        <p class="text-[10px] <?= $is_sudah_lunas ? 'text-emerald-600 font-bold' : 'text-slate-400 font-medium' ?>">
                            <?= $is_sudah_lunas ? 'Penyewa Aktif' : 'Penyewa Baru' ?> &bull; <?= htmlspecialchars($no_kamar_db) ?>
                        </p>
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

        <?php if ($sudah_punya_kos_lain && !$is_sudah_lunas): ?>
            <div class="bg-rose-50 border border-rose-200 text-rose-800 px-5 py-4 rounded-2xl flex flex-col sm:flex-row sm:items-center justify-between gap-4 text-xs">
                <div class="flex items-start gap-3">
                    <i class="fa-solid fa-ban text-rose-600 text-lg mt-0.5"></i>
                    <div>
                        <p class="font-extrabold text-rose-900">Batas Maksimal 1 Kos Aktif Terpenuhi</p>
                        <p class="mt-0.5 leading-relaxed">
                            Data pribadi / akun Anda saat ini sudah aktif menyewa <strong><?= htmlspecialchars($kos_aktif_lain['no_kamar'] ?? 'kamar kos') ?></strong>. Tagihan ini tidak dapat dilanjutkan karena setiap penyewa maksimal hanya bisa menyewa 1 kos aktif.
                        </p>
                    </div>
                </div>
                <a href="dashboard.php" class="shrink-0 bg-rose-600 hover:bg-rose-700 text-white font-extrabold px-4 py-2.5 rounded-xl transition text-center">
                    Lihat Kos Aktif Saya
                </a>
            </div>
        <?php endif; ?>

        <?php if ($is_sudah_lunas): ?>
        <div class="flex flex-col lg:flex-row lg:items-center justify-between gap-4">
            <div class="space-y-1.5">
                <nav class="text-[11px] text-slate-400 font-medium flex flex-wrap items-center gap-1.5">
                    <a href="dashboard.php" class="hover:text-blue-600">Beranda</a>
                    <span>&gt;</span>
                    <span>Tagihan &amp; Riwayat Pembayaran</span>
                </nav>
                <div class="flex items-center gap-2 text-[10px] font-extrabold uppercase tracking-wider text-emerald-600 pt-1">
                    <span>STATUS HUNIAN AKTIF</span>
                    <span class="text-slate-300">&bull;</span>
                    <span class="bg-emerald-50 border border-emerald-200 px-2 py-0.5 rounded">Kontrak: <?= htmlspecialchars($skema_kontrak) ?></span>
                </div>
                <h1 class="text-2xl sm:text-3xl font-extrabold text-slate-900 tracking-tight">Tagihan &amp; Status Pembayaran Sewa</h1>
            </div>

            <div class="bg-emerald-50 border border-emerald-200 rounded-2xl p-4 flex items-center gap-3.5 text-xs shrink-0">
                <div class="w-10 h-10 rounded-xl bg-emerald-600 text-white flex items-center justify-center text-base shrink-0">
                    <i class="fa-solid fa-circle-check"></i>
                </div>
                <div>
                    <span class="font-extrabold text-emerald-900 block">Tagihan Periode Ini Lunas</span>
                    <p class="text-[11px] text-emerald-800/80 mt-0.5">Jatuh Tempo Berikutnya: <strong><?= date('d M Y', strtotime($jatuh_tempo)) ?></strong></p>
                </div>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-12 gap-7 items-start">
            <div class="lg:col-span-8 space-y-6">
                <div class="bg-white border border-slate-200/80 rounded-3xl p-6 shadow-sm space-y-5 text-xs">
                    <div class="flex items-center justify-between border-b border-slate-100 pb-4">
                        <div class="flex items-center gap-2.5">
                            <div class="w-8 h-8 rounded-xl bg-blue-50 text-blue-600 flex items-center justify-center"><i class="fa-solid fa-house-circle-check"></i></div>
                            <h2 class="text-sm font-extrabold text-slate-900">Hunian Aktif Terdaftar</h2>
                        </div>
                        <span class="bg-emerald-50 text-emerald-700 border border-emerald-200 text-[10px] font-extrabold px-3 py-1 rounded-full">Lunas &amp; Terproteksi Rekber</span>
                    </div>

                    <div class="flex flex-col sm:flex-row items-start sm:items-center gap-4 p-4 rounded-2xl bg-slate-50 border border-slate-200/70">
                        <div class="w-28 h-24 rounded-xl overflow-hidden bg-slate-200 shrink-0">
                            <img src="<?= htmlspecialchars($foto_kamar_url) ?>" alt="Foto <?= htmlspecialchars($no_kamar_db) ?>" class="w-full h-full object-cover">
                        </div>
                        <div class="space-y-1.5 flex-1 w-full">
                            <div class="flex items-start justify-between gap-2">
                                <div>
                                    <h3 class="text-base font-extrabold text-slate-900"><?= htmlspecialchars($nama_kos_db) ?></h3>
                                    <p class="text-slate-500"><?= htmlspecialchars($alamat_kos) ?></p>
                                </div>
                                <span class="font-mono text-[10px] font-bold text-blue-700 bg-blue-50 px-2.5 py-1 rounded-lg border border-blue-200"><?= htmlspecialchars($no_kamar_db) ?></span>
                            </div>
                            <div class="flex flex-wrap items-center gap-4 pt-1 text-slate-600 font-semibold">
                                <span><i class="fa-regular fa-calendar-check text-emerald-600 mr-1"></i> Mulai Sewa: <?= date('d M Y', strtotime($tanggal_masuk)) ?></span>
                                <span><i class="fa-regular fa-clock text-blue-600 mr-1"></i> Tagihan Berikutnya: <?= date('d M Y', strtotime($jatuh_tempo)) ?></span>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="bg-white border border-slate-200/80 rounded-3xl p-6 shadow-sm space-y-4 text-xs">
                    <div class="flex items-center justify-between border-b border-slate-100 pb-3.5">
                        <h2 class="text-sm font-extrabold text-slate-900">Riwayat Pembayaran Anda</h2>
                        <span class="text-[11px] font-bold text-slate-400">Tercatat Otomatis</span>
                    </div>

                    <div class="space-y-3">
                        <div class="p-4 rounded-2xl bg-slate-50 border border-slate-200/70 flex flex-col sm:flex-row sm:items-center justify-between gap-3">
                            <div class="space-y-1">
                                <div class="flex items-center gap-2">
                                    <span class="font-extrabold text-slate-900">Pembayaran Awal Sewa (<?= htmlspecialchars($skema_kontrak) ?>) + Deposit</span>
                                    <span class="px-2 py-0.5 rounded bg-emerald-100 text-emerald-800 text-[10px] font-extrabold">LUNAS</span>
                                </div>
                                <p class="text-[11px] text-slate-500">
                                    Unit: <?= htmlspecialchars($no_kamar_db) ?> &bull; Dana terlindungi Escrow KosLink
                                </p>
                            </div>
                            <div class="text-left sm:text-right">
                                <p class="text-sm font-extrabold text-slate-900">Rp <?= number_format($nominal_terbayar, 0, ',', '.') ?></p>
                                <p class="text-[10px] text-emerald-600 font-bold">Terverifikasi Sistem</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="lg:col-span-4 space-y-5 sticky top-20">
                <div class="bg-white border border-slate-200/80 rounded-3xl p-6 shadow-sm space-y-5 text-xs">
                    <div class="flex items-center justify-between border-b border-slate-100 pb-3.5">
                        <h3 class="text-sm font-extrabold text-slate-900">Ringkasan Tagihan</h3>
                        <span class="px-2.5 py-0.5 rounded-full bg-emerald-50 text-emerald-700 font-extrabold text-[10px]">Tidak Ada Tunggakan</span>
                    </div>

                    <div class="space-y-2.5 text-slate-600">
                        <div class="flex justify-between">
                            <span>Sewa Berjalan (<?= htmlspecialchars($skema_kontrak) ?>)</span>
                            <span class="font-bold text-emerald-600">Lunas</span>
                        </div>
                        <div class="flex justify-between">
                            <span>Deposit Jaminan (Tersimpan)</span>
                            <span class="font-bold text-slate-900">Rp <?= number_format($biaya_deposit, 0, ',', '.') ?></span>
                        </div>
                        <div class="flex justify-between">
                            <span>Tagihan Bulan Berikutnya</span>
                            <span class="font-bold text-slate-900">Rp <?= number_format($harga_per_bulan, 0, ',', '.') ?></span>
                        </div>
                        <div class="flex justify-between pt-2.5 border-t border-slate-100">
                            <span>Jatuh Tempo Berikutnya</span>
                            <span class="font-extrabold text-blue-600"><?= date('d M Y', strtotime($jatuh_tempo)) ?></span>
                        </div>
                    </div>

                    <a href="dashboard.php" class="w-full bg-blue-600 hover:bg-blue-700 text-white text-xs font-extrabold py-3.5 px-4 rounded-xl transition shadow-md flex items-center justify-center gap-2">
                        <i class="fa-solid fa-house-user"></i>
                        <span>Kembali ke Dashboard Penghuni</span>
                    </a>
                </div>
            </div>
        </div>

        <?php else: ?>
        <div class="flex flex-col lg:flex-row lg:items-center justify-between gap-4">
            <div class="space-y-1.5">
                <nav class="text-[11px] text-slate-400 font-medium flex flex-wrap items-center gap-1.5">
                    <a href="dashboard.php" class="hover:text-blue-600">Beranda</a>
                    <span>&gt;</span>
                    <span>Konfirmasi Pembayaran Sewa Pertama</span>
                </nav>
                <div class="flex items-center gap-2 text-[10px] font-extrabold uppercase tracking-wider text-blue-600 pt-1">
                    <span>PEMBAYARAN PERDANA &amp; DEPOSIT</span>
                    <span class="text-slate-300">&bull;</span>
                    <span class="bg-blue-50 px-2 py-0.5 rounded">Kontrak: <?= htmlspecialchars($skema_kontrak) ?></span>
                </div>
                <h1 class="text-2xl sm:text-3xl font-extrabold text-slate-900 tracking-tight">Konfirmasi Tagihan Awal Kamar</h1>
            </div>

            <div class="bg-amber-50/90 border border-amber-200/80 rounded-2xl p-4 flex items-center gap-3.5 text-xs shrink-0">
                <div class="w-10 h-10 rounded-xl bg-amber-100 text-amber-600 flex items-center justify-center text-base shrink-0">
                    <i class="fa-regular fa-clock"></i>
                </div>
                <div>
                    <div class="flex items-center gap-2">
                        <span class="font-extrabold text-amber-900">Menunggu Pembayaran Pertama</span>
                    </div>
                    <p class="text-[11px] text-amber-800/80 mt-0.5">Batas Waktu: <strong>24 Jam</strong></p>
                </div>
            </div>
        </div>

        <?php if(!$data_sewa): ?>
            <div class="bg-amber-50 border border-amber-200 text-amber-800 p-4 rounded-2xl text-xs font-bold">
                <i class="fa-solid fa-triangle-exclamation mr-1"></i> Data pemesanan tidak ditemukan. Silakan pilih kamar terlebih dahulu dari halaman utama.
            </div>
        <?php endif; ?>

        <form method="POST" class="grid grid-cols-1 lg:grid-cols-12 gap-7 items-start">
            
            <!-- KOLOM KIRI -->
            <div class="lg:col-span-8 space-y-6">
                <div class="bg-white border border-slate-200/80 rounded-3xl p-6 shadow-sm space-y-5 text-xs">
                    <div class="flex items-center justify-between border-b border-slate-100 pb-4">
                        <div class="flex items-center gap-2.5">
                            <div class="w-8 h-8 rounded-xl bg-blue-50 text-blue-600 flex items-center justify-center"><i class="fa-solid fa-house-chimney"></i></div>
                            <h2 class="text-sm font-extrabold text-slate-900">Unit Sewa &amp; Rincian Kontrak</h2>
                        </div>
                        <span class="bg-blue-50 text-blue-700 border border-blue-100 text-[10px] font-extrabold px-3 py-1 rounded-full">Kamar Terverifikasi</span>
                    </div>

                    <div class="flex flex-col sm:flex-row items-start sm:items-center gap-4 p-4 rounded-2xl bg-slate-50 border border-slate-200/70">
                        <div class="w-28 h-24 rounded-xl overflow-hidden bg-slate-200 shrink-0">
                            <img src="<?= htmlspecialchars($foto_kamar_url) ?>" alt="Foto <?= htmlspecialchars($no_kamar_db) ?>" class="w-full h-full object-cover">
                        </div>
                        <div class="space-y-1.5 flex-1 w-full">
                            <div class="flex items-start justify-between gap-2">
                                <div>
                                    <h3 class="text-base font-extrabold text-slate-900"><?= htmlspecialchars($nama_kos_db) ?></h3>
                                    <p class="text-slate-500"><?= htmlspecialchars($alamat_kos) ?></p>
                                </div>
                                <span class="font-mono text-[10px] font-bold text-slate-400 bg-white px-2.5 py-1 rounded-lg border border-slate-200"><?= htmlspecialchars($no_kamar_db) ?></span>
                            </div>
                            <div class="flex flex-wrap items-center gap-3 pt-1">
                                <span class="font-bold text-slate-700"><i class="fa-regular fa-calendar text-blue-600 mr-1"></i> Durasi Sewa: <?= htmlspecialchars($skema_kontrak) ?></span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ADD-ON -->
                <div class="bg-white border border-slate-200/80 rounded-3xl p-6 shadow-sm space-y-4 text-xs">
                    <div class="flex items-center justify-between border-b border-slate-100 pb-4">
                        <div class="flex items-center gap-2.5">
                            <div class="w-8 h-8 rounded-xl bg-blue-50 text-blue-600 flex items-center justify-center"><i class="fa-solid fa-circle-plus"></i></div>
                            <h2 class="text-sm font-extrabold text-slate-900">Layanan Tambahan Opsional (Add-on)</h2>
                        </div>
                    </div>

                    <div class="space-y-3">
                        <label id="card-addon-laundry" class="p-4 rounded-2xl border border-slate-200 bg-white hover:border-slate-300 flex items-center justify-between gap-4 cursor-pointer transition">
                            <div class="flex items-center gap-3.5">
                                <input type="checkbox" id="chk-laundry" onchange="hitungTotal()" class="w-4 h-4 accent-blue-600 rounded">
                                <div class="w-9 h-9 rounded-xl bg-blue-100 text-blue-600 flex items-center justify-center shrink-0"><i class="fa-solid fa-shirt"></i></div>
                                <div>
                                    <p class="font-extrabold text-slate-900">Paket Laundry Langganan (15kg / bulan)</p>
                                    <p class="text-[11px] text-slate-500">Antar-jemput kamar, cuci setrika rapi &amp; wangi</p>
                                </div>
                            </div>
                            <div class="text-right shrink-0">
                                <p class="font-extrabold text-blue-600 text-sm">+Rp 100.000</p>
                                <p class="text-[10px] text-slate-400">/bulan</p>
                            </div>
                        </label>
                    </div>
                </div>

                <!-- METODE PEMBAYARAN -->
                <div class="bg-white border border-slate-200/80 rounded-3xl p-6 shadow-sm space-y-4 text-xs">
                    <div class="flex flex-wrap items-center justify-between gap-2 border-b border-slate-100 pb-4">
                        <div class="flex items-center gap-2.5">
                            <div class="w-8 h-8 rounded-xl bg-blue-50 text-blue-600 flex items-center justify-center"><i class="fa-solid fa-money-bill-wave"></i></div>
                            <h2 class="text-sm font-extrabold text-slate-900">Metode Pembayaran</h2>
                        </div>
                        <span class="bg-blue-50/80 text-blue-700 border border-blue-200/80 text-[10px] font-bold px-2.5 py-1 rounded-full inline-flex items-center gap-1.5">
                            <i class="fa-solid fa-shield-halved text-[10px]"></i> Terverifikasi Bank Indonesia
                        </span>
                    </div>

                    <div class="space-y-3">
                        <label id="card-metode-qris" class="p-4 rounded-2xl border-2 border-blue-600 bg-blue-50/30 block space-y-3 cursor-pointer transition">
                            <div class="flex items-start justify-between gap-3">
                                <div class="flex items-start gap-3">
                                    <input type="radio" name="metode" value="qris" checked onchange="pilihMetode()" class="mt-1 accent-blue-600">
                                    <div class="space-y-1">
                                        <div class="flex flex-wrap items-center gap-2">
                                            <p class="font-extrabold text-slate-900">QRIS Instan (Semua Bank &amp; E-Wallet)</p>
                                            <span class="bg-blue-600 text-white text-[10px] font-extrabold px-2 py-0.5 rounded-md">Paling Praktis</span>
                                        </div>
                                        <p class="text-[11px] text-slate-500">BCA, Mandiri, BRI, BNI, GoPay, OVO, ShopeePay, DANA &amp; LinkAja.</p>
                                    </div>
                                </div>
                                <span class="bg-blue-50 text-blue-700 border border-blue-200 text-[10px] font-extrabold px-2.5 py-1 rounded-md shrink-0">Bebas Biaya Admin</span>
                            </div>
                        </label>

                        <label id="card-metode-va" class="p-4 rounded-2xl border border-slate-200 bg-blue-50/20 hover:border-blue-300 block cursor-pointer transition">
                            <div class="flex items-start justify-between gap-3">
                                <div class="flex items-start gap-3">
                                    <input type="radio" name="metode" value="va" onchange="pilihMetode(); hitungTotal();" class="mt-1 accent-blue-600">
                                    <div class="space-y-1">
                                        <p class="font-extrabold text-slate-900">Virtual Account Otomatis</p>
                                        <p class="text-[11px] text-slate-500">BCA VA, Mandiri Bill, BNI VA, BRI BRIVA, Permata Bank.</p>
                                    </div>
                                </div>
                            </div>
                        </label>

                        <label id="card-metode-kartu" class="p-4 rounded-2xl border border-slate-200 bg-blue-50/20 hover:border-blue-300 block cursor-pointer transition">
                            <div class="flex items-start justify-between gap-3">
                                <div class="flex items-start gap-3">
                                    <input type="radio" name="metode" value="kartu" onchange="pilihMetode(); hitungTotal();" class="mt-1 accent-blue-600">
                                    <div class="space-y-1">
                                        <p class="font-extrabold text-slate-900">Kartu Debit &amp; Rekening Bank</p>
                                        <p class="text-[11px] text-slate-500">Debit langsung tiap jatuh tempo sewa kos tanpa denda telat.</p>
                                    </div>
                                </div>
                            </div>
                        </label>
                    </div>
                </div>
            </div>

            <!-- KOLOM KANAN: RINGKASAN TAGIHAN -->
            <div class="lg:col-span-4 space-y-5 sticky top-20">
                <div class="bg-white border border-slate-200/80 rounded-3xl p-6 shadow-sm space-y-5 text-xs">
                    <div class="flex items-center justify-between border-b border-slate-100 pb-3.5">
                        <h3 class="text-sm font-extrabold text-slate-900">Ringkasan Biaya Awal</h3>
                        <span class="text-[11px] text-slate-400 font-semibold">Penyewaan Pertama</span>
                    </div>

                    <div class="space-y-2.5 text-slate-600">
                        <div class="flex justify-between">
                            <span>Sewa Kamar (<?= htmlspecialchars($skema_kontrak) ?>)</span>
                            <span class="font-bold text-slate-900">Rp <?= number_format($biaya_sewa_pokok, 0, ',', '.') ?></span>
                        </div>
                        <div class="flex justify-between">
                            <span>Deposit Jaminan (Refundable)</span>
                            <span class="font-bold text-slate-900">Rp <?= number_format($biaya_deposit, 0, ',', '.') ?></span>
                        </div>
                        <div id="row-laundry" class="hidden justify-between">
                            <span>Paket Laundry (15kg)</span>
                            <span class="font-bold text-slate-900">+Rp 100.000</span>
                        </div>
                        <div id="row-admin-va" class="hidden justify-between">
                            <span>Biaya Penanganan VA</span>
                            <span class="font-bold text-slate-900">+Rp 2.500</span>
                        </div>
                        <div class="flex justify-between pt-2.5 border-t border-slate-100">
                            <span>Biaya Layanan Transaksi</span>
                            <span id="label-biaya-layanan" class="font-bold text-emerald-600">Rp 0 (Bebas Admin)</span>
                        </div>
                    </div>

                    <div class="pt-3 border-t border-slate-200 space-y-1">
                        <div class="flex justify-between items-center">
                            <span class="text-[10px] font-extrabold text-slate-400 uppercase">TOTAL PEMBAYARAN AWAL</span>
                            <span class="text-[10px] font-bold text-slate-400">IDR</span>
                        </div>
                        <div class="flex items-baseline justify-between gap-2">
                            <span id="total-bayar-text" class="text-2xl font-extrabold text-blue-600">Rp <?= number_format($grand_total, 0, ',', '.') ?></span>
                            <span class="text-[10px] text-slate-400 text-right">Termasuk Garansi KosLink</span>
                        </div>
                    </div>

                    <button type="submit" name="proses_bayar" value="1" <?= ($sudah_punya_kos_lain || !$data_sewa) ? 'disabled' : '' ?> class="w-full <?= ($sudah_punya_kos_lain || !$data_sewa) ? 'bg-slate-300 cursor-not-allowed' : 'bg-blue-600 hover:bg-blue-700' ?> text-white text-xs font-extrabold py-3.5 px-4 rounded-xl transition shadow-md flex items-center justify-center gap-2">
                        <span id="btn-bayar-label">Lanjut Pembayaran &bull; Rp <?= number_format($grand_total, 0, ',', '.') ?></span>
                        <i class="fa-solid fa-arrow-right"></i>
                    </button>
                </div>
            </div>

        </form>
        <?php endif; ?>

    </main>

    <script>
        const baseGrandTotal = <?= $grand_total ?>;

        function formatRupiah(angka) {
            return 'Rp ' + angka.toString().replace(/\B(?=(\d{3})+(?!\d))/g, '.');
        }

        function pilihMetode() {
            const selected = document.querySelector('input[name="metode"]:checked')?.value || 'qris';
            const cards = {
                qris: document.getElementById('card-metode-qris'),
                va: document.getElementById('card-metode-va'),
                kartu: document.getElementById('card-metode-kartu')
            };

            Object.keys(cards).forEach((key) => {
                const el = cards[key];
                if (!el) return;
                if (key === selected) {
                    el.classList.remove('border', 'border-slate-200', 'bg-blue-50/20');
                    el.classList.add('border-2', 'border-blue-600', 'bg-blue-50/30');
                } else {
                    el.classList.remove('border-2', 'border-blue-600', 'bg-blue-50/30');
                    el.classList.add('border', 'border-slate-200', 'bg-blue-50/20');
                }
            });
        }

        function hitungTotal() {
            const chkLaundry = document.getElementById('chk-laundry');
            if (!chkLaundry) return;

            const laundry = chkLaundry.checked;
            const metode = document.querySelector('input[name="metode"]:checked')?.value || 'qris';
            const biayaVa = metode === 'va' ? 2500 : 0;

            document.getElementById('row-laundry').style.display = laundry ? 'flex' : 'none';
            document.getElementById('row-admin-va').style.display = biayaVa > 0 ? 'flex' : 'none';

            const labelLayanan = document.getElementById('label-biaya-layanan');
            if (biayaVa > 0) {
                labelLayanan.innerText = 'Rp 2.500 (VA)';
                labelLayanan.className = 'font-bold text-slate-900';
            } else {
                labelLayanan.innerText = 'Rp 0 (Bebas Admin)';
                labelLayanan.className = 'font-bold text-emerald-600';
            }

            let total = baseGrandTotal + (laundry ? 100000 : 0) + biayaVa;
            document.getElementById('total-bayar-text').innerText = formatRupiah(total);
            document.getElementById('btn-bayar-label').innerHTML = 'Lanjut Pembayaran &bull; ' + formatRupiah(total);
        }

        pilihMetode();
        hitungTotal();
    </script>
</body>
</html>