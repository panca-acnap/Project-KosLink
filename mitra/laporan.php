<?php
require '../koneksi.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Proteksi role Mitra
if (!isset($_SESSION['role']) || $_SESSION['role'] != 'mitra') {
    header("Location: ../auth/login.php");
    exit;
}

mysqli_report(MYSQLI_REPORT_OFF);

$id_mitra   = (int)($_SESSION['id_mitra'] ?? $_SESSION['id_user'] ?? $_SESSION['id'] ?? 0);
$nama_mitra = $_SESSION['nama'] ?? 'Mitra KosLink';

function resolveExistingTable($conn, array $candidates) {
    foreach ($candidates as $tbl) {
        $esc = mysqli_real_escape_string($conn, $tbl);
        $q = @mysqli_query($conn, "SHOW TABLES LIKE '$esc'");
        if ($q && mysqli_num_rows($q) > 0) {
            $row = mysqli_fetch_row($q);
            return $row[0] ?? $tbl;
        }
    }
    return null;
}

function getTableColumns($conn, $table) {
    $cols = [];
    if (!$table) return $cols;
    $q = @mysqli_query($conn, "SHOW COLUMNS FROM `$table`");
    if ($q) {
        while ($r = mysqli_fetch_assoc($q)) {
            $cols[] = $r['Field'];
        }
    }
    return $cols;
}

function parseRupiahInt($val): int {
    if (is_int($val) || is_float($val)) {
        return (int)$val;
    }
    $clean = preg_replace('/[^0-9\-]/', '', (string)$val);
    return $clean !== '' ? (int)$clean : 0;
}

// Utamakan nama tabel huruf kecil agar konsisten di Linux/InfinityFree
$tabel_prop    = resolveExistingTable($conn, ['properti_kos', 'Properti_Kos', 'properti', 'Properti']);
$tabel_kamar   = resolveExistingTable($conn, ['kamar', 'Kamar']);
$tabel_penyewa = resolveExistingTable($conn, ['penyewa', 'Penyewa']);

// 1. Ambil 1 kos/properti milik mitra yang login (1 mitra = 1 kos/properti)
$properti_aktif      = null;
$properti_aktif_id   = 0;
$nama_properti_aktif = 'Belum Ada Kos';

if ($id_mitra > 0 && $tabel_prop) {
    $cols_prop      = getTableColumns($conn, $tabel_prop);
    $pk_prop        = in_array('id_properti', $cols_prop, true) ? 'id_properti' : (in_array('id_kos', $cols_prop, true) ? 'id_kos' : (in_array('id', $cols_prop, true) ? 'id' : ''));
    $col_mitra_prop = in_array('id_mitra', $cols_prop, true) ? 'id_mitra' : (in_array('id_user', $cols_prop, true) ? 'id_user' : (in_array('id_pemilik', $cols_prop, true) ? 'id_pemilik' : ''));
    $col_nama_prop  = in_array('nama_kos', $cols_prop, true) ? 'nama_kos' : (in_array('nama_properti', $cols_prop, true) ? 'nama_properti' : (in_array('nama', $cols_prop, true) ? 'nama' : ''));

    if ($pk_prop !== '' && $col_mitra_prop !== '') {
        $q_prop = @mysqli_query($conn, "SELECT * FROM `$tabel_prop` WHERE `$col_mitra_prop` = $id_mitra ORDER BY `$pk_prop` ASC LIMIT 1");
        if ($q_prop && ($p = mysqli_fetch_assoc($q_prop))) {
            $properti_aktif      = $p;
            $properti_aktif_id   = (int)($p[$pk_prop] ?? 0);
            $nama_properti_aktif = ($col_nama_prop !== '' && !empty($p[$col_nama_prop]))
                ? (string)$p[$col_nama_prop]
                : ('Kos #' . $properti_aktif_id);
        }
    }
}

// 2. Ambil kamar khusus milik 1 kos/properti aktif tersebut
$kamar_mitra_ids  = [];
$kamar_harga_map  = [];
$kamar_nama_map   = [];
$nama_kamar_mitra = [];

if ($properti_aktif_id > 0 && $tabel_kamar) {
    $cols_kamar = getTableColumns($conn, $tabel_kamar);
    $pk_kamar   = in_array('id_kamar', $cols_kamar, true) ? 'id_kamar' : (in_array('id', $cols_kamar, true) ? 'id' : '');
    $fk_prop_k  = in_array('id_properti', $cols_kamar, true) ? 'id_properti' : (in_array('id_kos', $cols_kamar, true) ? 'id_kos' : '');

    $col_harga_k = null;
    foreach (['harga_bulan', 'harga_bulanan', 'harga_sewa', 'harga', 'tarif_bulan', 'tarif'] as $ch) {
        if (in_array($ch, $cols_kamar, true)) {
            $col_harga_k = $ch;
            break;
        }
    }

    $col_nama_k = null;
    foreach (['nama_kamar', 'nomor_kamar', 'no_kamar', 'tipe_kamar'] as $cn) {
        if (in_array($cn, $cols_kamar, true)) {
            $col_nama_k = $cn;
            break;
        }
    }

    if ($pk_kamar !== '' && $fk_prop_k !== '') {
        $q_km = @mysqli_query($conn, "SELECT * FROM `$tabel_kamar` WHERE `$fk_prop_k` = $properti_aktif_id");
        if ($q_km) {
            while ($km = mysqli_fetch_assoc($q_km)) {
                $kid = (int)($km[$pk_kamar] ?? 0);
                if ($kid <= 0) continue;

                $kamar_mitra_ids[] = $kid;
                $kamar_harga_map[$kid] = $col_harga_k !== null ? parseRupiahInt($km[$col_harga_k] ?? 0) : 0;

                $label_km = ($col_nama_k !== null && !empty($km[$col_nama_k]))
                    ? trim((string)$km[$col_nama_k])
                    : ('Kamar #' . $kid);
                $kamar_nama_map[$kid] = $label_km;
                $nama_kamar_mitra[strtolower($label_km)] = $kid;
            }
        }
    }
}

// 3. Ambil hanya transaksi sewa kamar yang benar-benar sudah disewa pada 1 kos milik mitra
$daftar_laporan   = [];
$total_pendapatan = 0;

if ($id_mitra > 0 && $properti_aktif_id > 0 && $tabel_penyewa) {
    $cols_p = getTableColumns($conn, $tabel_penyewa);

    $col_prop_p = null;
    foreach (['id_properti', 'id_kos', 'properti_id', 'kos_id'] as $c) {
        if (in_array($c, $cols_p, true)) {
            $col_prop_p = $c;
            break;
        }
    }

    $col_kamar_p = null;
    foreach (['id_kamar', 'kamar_id'] as $c) {
        if (in_array($c, $cols_p, true)) {
            $col_kamar_p = $c;
            break;
        }
    }

    $where_parts = [];
    if ($col_prop_p !== null) {
        $where_parts[] = "`$col_prop_p` = $properti_aktif_id";
    }
    if ($col_kamar_p !== null && !empty($kamar_mitra_ids)) {
        $in_kamar = implode(',', array_map('intval', $kamar_mitra_ids));
        $where_parts[] = "`$col_kamar_p` IN ($in_kamar)";
    }
    if (!empty($nama_kamar_mitra)) {
        foreach (['no_kamar', 'nama_kamar', 'nomor_kamar'] as $ck) {
            if (in_array($ck, $cols_p, true)) {
                $escaped_names = array_map(
                    fn($n) => "'" . mysqli_real_escape_string($conn, $n) . "'",
                    array_keys($nama_kamar_mitra)
                );
                $where_parts[] = "LOWER(TRIM(`$ck`)) IN (" . implode(',', $escaped_names) . ")";
            }
        }
    }

    if (!empty($where_parts)) {
        $where_sql = implode(' OR ', $where_parts);
        $pk_p      = in_array('id_penyewa', $cols_p, true) ? 'id_penyewa' : (in_array('id', $cols_p, true) ? 'id' : '1');
        $q_p = @mysqli_query($conn, "SELECT * FROM `$tabel_penyewa` WHERE ($where_sql) ORDER BY `$pk_p` DESC");
        if ($q_p) {
            while ($row = mysqli_fetch_assoc($q_p)) {
                $kid_row = ($col_kamar_p !== null && !empty($row[$col_kamar_p])) ? (int)$row[$col_kamar_p] : 0;

                // Pastikan baris ini memang milik kamar pada kos mitra ini
                $nama_unit_raw = trim((string)($row['no_kamar'] ?? $row['nama_kamar'] ?? $row['nomor_kamar'] ?? ''));
                $cocok_kamar_by_name = ($nama_unit_raw !== '' && isset($nama_kamar_mitra[strtolower($nama_unit_raw)]));
                $cocok_kamar_by_id   = ($kid_row > 0 && in_array($kid_row, $kamar_mitra_ids, true));
                $cocok_kamar_by_prop = ($col_prop_p !== null && (int)($row[$col_prop_p] ?? 0) === $properti_aktif_id);

                if (!$cocok_kamar_by_id && !$cocok_kamar_by_name && !$cocok_kamar_by_prop) {
                    continue;
                }

                // Cek status agar transaksi batal/ditolak tidak dihitung sebagai transaksi sewa aktif
                $st_cek = strtolower(trim((string)($row['status_bayar'] ?? $row['status_hunian'] ?? $row['status_escrow'] ?? '')));
                if (in_array($st_cek, ['batal', 'dibatalkan', 'ditolak', 'gagal', 'expired', 'kadaluarsa'], true)) {
                    continue;
                }

                // Harus ada penyewa atau kamar yang benar-benar tersewa
                $nama_pen = trim((string)($row['nama_penyewa'] ?? $row['nama'] ?? ''));
                $nama_unit = $nama_unit_raw !== ''
                    ? $nama_unit_raw
                    : (($kid_row > 0 && isset($kamar_nama_map[$kid_row])) ? $kamar_nama_map[$kid_row] : '');

                if ($nama_pen === '' && $nama_unit === '') {
                    continue;
                }

                // Hitung nominal sewa transaksi yang sudah terjadi
                $nominal = 0;
                foreach (['nominal_sewa', 'harga_sewa', 'nominal', 'harga_bulan', 'harga_bulanan', 'total_bayar', 'total_harga', 'biaya_sewa', 'tagihan'] as $cnom) {
                    if (isset($row[$cnom]) && parseRupiahInt($row[$cnom]) > 0) {
                        $nominal = parseRupiahInt($row[$cnom]);
                        break;
                    }
                }
                if ($nominal <= 0 && $kid_row > 0 && isset($kamar_harga_map[$kid_row])) {
                    $nominal = (int)$kamar_harga_map[$kid_row];
                }
                if ($nominal <= 0 && $cocok_kamar_by_name) {
                    $mapped_kid = (int)$nama_kamar_mitra[strtolower($nama_unit_raw)];
                    $nominal = (int)($kamar_harga_map[$mapped_kid] ?? 0);
                }

                // Hanya tampilkan jika benar-benar ada transaksi sewa / nominal valid atau penyewa aktif
                $ada_transaksi_nyata = ($nominal > 0) || ($nama_pen !== '' && in_array($st_cek, ['lunas', 'paid', 'terbayar', 'berhasil', 'aktif', 'check-in', 'checkin', 'menghuni', 'ditempati'], true));
                if (!$ada_transaksi_nyata) {
                    continue;
                }

                $total_pendapatan += $nominal;

                $label_penyewa = $nama_pen !== '' ? $nama_pen : 'Penyewa';
                $label_kamar   = $nama_unit !== '' ? $nama_unit : 'Kamar Terbooking';
                $desc = "Pembayaran Sewa Kos - Unit {$label_kamar} ({$label_penyewa}) • {$nama_properti_aktif}";

                $status_bayar = trim((string)($row['status_escrow'] ?? $row['status_bayar'] ?? ''));
                if ($status_bayar === '') {
                    $status_bayar = 'Escrow Hold (Aman)';
                }

                $daftar_laporan[] = [
                    'id'        => (int)($row['id_penyewa'] ?? $row['id'] ?? 0),
                    'deskripsi' => $desc,
                    'tanggal'   => $row['created_at'] ?? $row['tanggal_masuk'] ?? date('Y-m-d'),
                    'nominal'   => $nominal,
                    'status'    => $status_bayar,
                ];
            }
        }
    }
}

$punya_kos   = $properti_aktif_id > 0;
$punya_kamar = !empty($kamar_mitra_ids);
?>
<!DOCTYPE html>
<html lang="id" class="scroll-smooth">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Laporan Penghasilan &amp; Rekber - KosLink Mitra</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Plus Jakarta Sans', sans-serif; }
    </style>
</head>
<body class="bg-[#F8FAFC] text-slate-800 min-h-screen flex">

    <!-- SIDEBAR KIRI -->
    <aside class="w-64 bg-white border-r border-slate-200/80 flex flex-col justify-between fixed inset-y-0 left-0 z-40 hidden lg:flex">
        <div>
            <div class="px-6 py-5 flex items-center gap-3 border-b border-slate-100">
                <div class="w-9 h-9 bg-blue-600 rounded-xl flex items-center justify-center text-white shadow-sm shadow-blue-200">
                    <i class="fa-solid fa-building text-base"></i>
                </div>
                <div>
                    <h1 class="font-extrabold text-slate-900 text-lg leading-none tracking-tight">KosLink</h1>
                    <p class="text-[10px] font-bold text-slate-400 tracking-widest uppercase mt-1">MITRA PEMILIK</p>
                </div>
            </div>

            <div class="px-4 py-6">
                <p class="px-3 text-[10px] font-bold text-slate-400 uppercase tracking-wider mb-3">MENU UTAMA</p>
                <nav class="space-y-1.5">
                    <a href="dashboard.php" class="flex items-center gap-3 px-3.5 py-2.5 rounded-xl text-slate-600 hover:bg-slate-50 hover:text-blue-600 font-semibold text-sm transition">
                        <i class="fa-solid fa-table-cells-large w-4 text-center text-slate-400"></i>
                        <span>Dashboard Overview</span>
                    </a>
                    <a href="kelola_kos.php" class="flex items-center gap-3 px-3.5 py-2.5 rounded-xl text-slate-600 hover:bg-slate-50 hover:text-blue-600 font-semibold text-sm transition">
                        <i class="fa-solid fa-building w-4 text-center text-slate-400"></i>
                        <span>Kelola Kamar</span>
                    </a>
                    <a href="penyewa.php" class="flex items-center gap-3 px-3.5 py-2.5 rounded-xl text-slate-600 hover:bg-slate-50 hover:text-blue-600 font-semibold text-sm transition">
                        <i class="fa-solid fa-users w-4 text-center text-slate-400"></i>
                        <span>Daftar &amp; Awasi Penyewa</span>
                    </a>
                    <a href="keluhan.php" class="flex items-center gap-3 px-3.5 py-2.5 rounded-xl text-slate-600 hover:bg-slate-50 hover:text-blue-600 font-semibold text-sm transition">
                        <i class="fa-solid fa-headset w-4 text-center text-slate-400"></i>
                        <span>Kelola Keluhan &amp; Komplain</span>
                    </a>
                    <a href="laporan.php" class="flex items-center gap-3 px-3.5 py-2.5 rounded-xl bg-blue-600 text-white font-semibold text-sm shadow-sm shadow-blue-200 transition">
                        <i class="fa-solid fa-chart-line w-4 text-center"></i>
                        <span>Laporan Penghasilan &amp; Rekber</span>
                    </a>
                </nav>
            </div>
        </div>

        <div class="p-4 space-y-3">
            <a href="../auth/logout.php" class="flex items-center justify-center gap-2 w-full py-2.5 rounded-xl border border-slate-200 text-slate-600 hover:bg-rose-50 hover:text-rose-600 hover:border-rose-200 text-xs font-bold transition">
                <i class="fa-solid fa-right-from-bracket"></i> Keluar Akun
            </a>
        </div>
    </aside>

    <!-- KONTEN UTAMA -->
    <div class="flex-1 lg:pl-64 flex flex-col min-w-0 pb-24">

        <!-- TOPBAR -->
        <header class="bg-white border-b border-slate-200/80 px-6 lg:px-8 py-3.5 flex items-center justify-between gap-4 sticky top-0 z-30">
            <div>
                <h2 class="text-sm font-extrabold text-slate-900">Laporan Penghasilan &amp; Rekening Bersama</h2>
                <p class="text-[11px] text-slate-400">Pantau pencairan dana sewa, status escrow, dan riwayat transaksi kamar yang sudah disewa (1 mitra = 1 kos).</p>
            </div>
            <div class="flex items-center gap-3">
                <div class="text-right hidden sm:block">
                    <p class="text-xs font-bold text-slate-900 leading-tight"><?= htmlspecialchars($nama_mitra) ?></p>
                    <p class="text-[11px] text-slate-400 font-medium">Mitra Terdaftar</p>
                </div>
            </div>
        </header>

        <!-- ISI HALAMAN -->
        <main class="p-6 lg:p-8 max-w-[1200px] w-full mx-auto space-y-6">

            <?php if (!$punya_kos): ?>
            <div class="bg-amber-50/80 border border-amber-200/80 rounded-3xl p-6 flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                <div class="flex items-start gap-3">
                    <div class="w-10 h-10 rounded-2xl bg-amber-100 text-amber-700 flex items-center justify-center shrink-0">
                        <i class="fa-solid fa-building-circle-exclamation"></i>
                    </div>
                    <div>
                        <h3 class="text-sm font-extrabold text-amber-950">Anda Belum Memiliki Kos / Properti</h3>
                        <p class="text-xs text-amber-800 mt-0.5">Setiap akun mitra mengelola 1 kos/properti. Tambahkan kos dan kamar terlebih dahulu agar laporan transaksi dapat muncul.</p>
                    </div>
                </div>
                <a href="tambah_kamar.php" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2.5 rounded-xl text-xs font-extrabold shrink-0 inline-flex items-center gap-1.5">
                    <i class="fa-solid fa-plus"></i> Tambah Kos / Kamar
                </a>
            </div>
            <?php elseif (empty($daftar_laporan)): ?>
            <div class="bg-blue-50/70 border border-blue-200/80 rounded-3xl p-5 flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                <div class="flex items-start gap-3">
                    <div class="w-10 h-10 rounded-2xl bg-blue-100 text-blue-700 flex items-center justify-center shrink-0">
                        <i class="fa-solid fa-door-open"></i>
                    </div>
                    <div>
                        <h3 class="text-sm font-extrabold text-slate-900">
                            Kos &ldquo;<?= htmlspecialchars($nama_properti_aktif) ?>&rdquo; Aktif — Belum Ada Kamar yang Disewa
                        </h3>
                        <p class="text-xs text-slate-600 mt-0.5">
                            Kamar kosong yang belum memiliki transaksi sewa tidak ditampilkan di riwayat transaksi.
                        </p>
                    </div>
                </div>
                <a href="kelola_kos.php" class="bg-white hover:bg-slate-50 text-slate-700 border border-slate-200 px-4 py-2.5 rounded-xl text-xs font-bold shrink-0 inline-flex items-center gap-1.5">
                    <i class="fa-solid fa-sliders"></i> Kelola Kamar
                </a>
            </div>
            <?php endif; ?>

            <!-- KARTU RINGKASAN PENDAPATAN -->
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                <div class="bg-white border border-slate-200/80 rounded-3xl p-6 shadow-2xs space-y-2">
                    <div class="flex items-center justify-between text-slate-400">
                        <span class="text-xs font-bold uppercase">Total Pendapatan Masuk</span>
                        <div class="w-9 h-9 rounded-xl bg-indigo-50 text-indigo-600 flex items-center justify-center">
                            <i class="fa-solid fa-wallet"></i>
                        </div>
                    </div>
                    <p class="text-2xl font-extrabold text-slate-900">Rp <?= number_format($total_pendapatan, 0, ',', '.') ?></p>
                    <p class="text-[11px] text-indigo-600 font-semibold">
                        <i class="fa-solid fa-lock"></i>
                        <?= !empty($daftar_laporan) ? 'Dari ' . count($daftar_laporan) . ' kamar yang sudah disewa' : 'Belum ada transaksi sewa masuk' ?>
                    </p>
                </div>

                <div class="bg-white border border-slate-200/80 rounded-3xl p-6 shadow-2xs space-y-2">
                    <div class="flex items-center justify-between text-slate-400">
                        <span class="text-xs font-bold uppercase">Kamar Tersewa / Transaksi</span>
                        <div class="w-9 h-9 rounded-xl bg-emerald-50 text-emerald-600 flex items-center justify-center">
                            <i class="fa-solid fa-receipt"></i>
                        </div>
                    </div>
                    <p class="text-2xl font-extrabold text-slate-900"><?= count($daftar_laporan) ?> Transaksi</p>
                    <p class="text-[11px] text-emerald-600 font-semibold">
                        <i class="fa-solid fa-circle-check"></i>
                        <?= $punya_kos ? 'Kos: ' . htmlspecialchars($nama_properti_aktif) : 'Belum ada kos terdaftar' ?>
                    </p>
                </div>
            </div>

            <div class="bg-white border border-slate-200/80 rounded-3xl p-6 shadow-2xs space-y-6">
                <div class="flex items-center justify-between pb-4 border-b border-slate-100">
                    <div class="flex items-center gap-3">
                        <div class="w-9 h-9 rounded-xl bg-indigo-50 text-indigo-600 flex items-center justify-center text-sm">
                            <i class="fa-solid fa-list-check"></i>
                        </div>
                        <div>
                            <h3 class="text-base font-extrabold text-slate-900">Riwayat Transaksi &amp; Pencairan Dana</h3>
                            <p class="text-xs text-slate-500 mt-0.5">Hanya menampilkan transaksi kamar yang sudah disewa pada kos milik Anda.</p>
                        </div>
                    </div>
                </div>

                <!-- TABEL LAPORAN -->
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-xs">
                        <thead>
                            <tr class="bg-slate-50 text-slate-400 font-bold uppercase tracking-wider border-b border-slate-200">
                                <th class="py-3 px-4">Keterangan Transaksi</th>
                                <th class="py-3 px-4">Tanggal Masuk</th>
                                <th class="py-3 px-4">Nominal</th>
                                <th class="py-3 px-4">Status Rekber / Escrow</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 font-medium text-slate-700">
                            <?php if (count($daftar_laporan) > 0): ?>
                                <?php foreach ($daftar_laporan as $lap): ?>
                                <tr>
                                    <td class="py-3.5 px-4 font-bold text-slate-900">
                                        <?= htmlspecialchars($lap['deskripsi']) ?>
                                        <p class="text-[10px] font-normal text-slate-400">Ref ID: #<?= (int)$lap['id'] ?></p>
                                    </td>
                                    <td class="py-3.5 px-4 text-slate-500">
                                        <?= htmlspecialchars($lap['tanggal']) ?>
                                    </td>
                                    <td class="py-3.5 px-4 font-extrabold text-slate-900">
                                        Rp <?= number_format($lap['nominal'], 0, ',', '.') ?>
                                    </td>
                                    <td class="py-3.5 px-4">
                                        <span class="px-2.5 py-1 rounded-full text-[10px] font-extrabold bg-emerald-50 text-emerald-700 border border-emerald-200">
                                            <?= htmlspecialchars($lap['status']) ?>
                                        </span>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="4" class="py-8 text-center text-slate-400 font-semibold">
                                        <?php if (!$punya_kos): ?>
                                            Belum ada kos/properti terdaftar sehingga belum ada laporan transaksi.
                                        <?php elseif (!$punya_kamar): ?>
                                            Kos sudah ada, tetapi belum ada kamar dan belum ada transaksi sewa.
                                        <?php else: ?>
                                            Belum ada transaksi kamar yang disewa pada kos Anda.
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        </main>
    </div>

</body>
</html>