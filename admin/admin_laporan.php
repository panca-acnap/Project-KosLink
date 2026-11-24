<?php
require '../koneksi.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Proteksi role Mitra
if (!isset($_SESSION['role']) || $_SESSION['role'] != 'admin') {
    header("Location: ../auth/login.php");
    exit;
}

$id_mitra   = (int)($_SESSION['id_mitra'] ?? $_SESSION['id_user'] ?? $_SESSION['id'] ?? 0);
$nama_mitra = $_SESSION['nama'] ?? 'Mitra KosLink';

function resolveExistingTable($conn, array $candidates) {
    foreach ($candidates as $tbl) {
        $esc = mysqli_real_escape_string($conn, $tbl);
        $q = @mysqli_query($conn, "SHOW TABLES LIKE '$esc'");
        if ($q && mysqli_num_rows($q) > 0) {
            return $tbl;
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

$tabel_prop    = resolveExistingTable($conn, ['Properti_Kos', 'properti_kos', 'Properti', 'properti']);
$tabel_kamar   = resolveExistingTable($conn, ['Kamar', 'kamar']);
$tabel_penyewa = resolveExistingTable($conn, ['penyewa', 'Penyewa']);

$properti_mitra_ids = [];
$properti_nama_map  = [];

if ($id_mitra > 0 && $tabel_prop) {
    $cols_prop      = getTableColumns($conn, $tabel_prop);
    $pk_prop        = in_array('id_properti', $cols_prop, true) ? 'id_properti' : (in_array('id_kos', $cols_prop, true) ? 'id_kos' : (in_array('id', $cols_prop, true) ? 'id' : ''));
    $col_mitra_prop = in_array('id_mitra', $cols_prop, true) ? 'id_mitra' : (in_array('id_user', $cols_prop, true) ? 'id_user' : (in_array('id_pemilik', $cols_prop, true) ? 'id_pemilik' : ''));
    $col_nama_prop  = in_array('nama_kos', $cols_prop, true) ? 'nama_kos' : (in_array('nama_properti', $cols_prop, true) ? 'nama_properti' : '');

    if ($pk_prop !== '' && $col_mitra_prop !== '') {
        $q_prop = @mysqli_query($conn, "SELECT * FROM `$tabel_prop` WHERE `$col_mitra_prop` = $id_mitra");
        if ($q_prop) {
            while ($p = mysqli_fetch_assoc($q_prop)) {
                $pid = (int)$p[$pk_prop];
                $properti_mitra_ids[] = $pid;
                $properti_nama_map[$pid] = ($col_nama_prop !== '' && !empty($p[$col_nama_prop])) ? $p[$col_nama_prop] : ('Properti #' . $pid);
            }
        }
    }
}

$kamar_mitra_ids = [];
$kamar_to_prop   = [];
if (!empty($properti_mitra_ids) && $tabel_kamar) {
    $cols_kamar = getTableColumns($conn, $tabel_kamar);
    $pk_kamar   = in_array('id_kamar', $cols_kamar, true) ? 'id_kamar' : (in_array('id', $cols_kamar, true) ? 'id' : '');
    $fk_prop_k  = in_array('id_properti', $cols_kamar, true) ? 'id_properti' : (in_array('id_kos', $cols_kamar, true) ? 'id_kos' : '');

    if ($pk_kamar !== '' && $fk_prop_k !== '') {
        $in_prop = implode(',', array_map('intval', $properti_mitra_ids));
        $q_km = @mysqli_query($conn, "SELECT `$pk_kamar`, `$fk_prop_k` FROM `$tabel_kamar` WHERE `$fk_prop_k` IN ($in_prop)");
        if ($q_km) {
            while ($km = mysqli_fetch_assoc($q_km)) {
                $kid = (int)$km[$pk_kamar];
                $pid = (int)$km[$fk_prop_k];
                $kamar_mitra_ids[] = $kid;
                $kamar_to_prop[$kid] = $pid;
            }
        }
    }
}

$daftar_laporan   = [];
$total_pendapatan = 0;

// Hanya ambil laporan jika mitra sudah punya properti dan id_mitra valid
if ($id_mitra > 0 && !empty($properti_mitra_ids) && $tabel_penyewa) {
    $cols_p = getTableColumns($conn, $tabel_penyewa);

    $col_mitra_p = null;
    foreach (['id_mitra', 'id_pemilik'] as $c) {
        if (in_array($c, $cols_p, true)) {
            $col_mitra_p = $c;
            break;
        }
    }

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
    if ($col_mitra_p !== null) {
        $where_parts[] = "`$col_mitra_p` = $id_mitra";
    }
    if ($col_prop_p !== null) {
        $in_prop = implode(',', array_map('intval', $properti_mitra_ids));
        $where_parts[] = "`$col_prop_p` IN ($in_prop)";
    }
    if ($col_kamar_p !== null && !empty($kamar_mitra_ids)) {
        $in_kamar = implode(',', array_map('intval', $kamar_mitra_ids));
        $where_parts[] = "`$col_kamar_p` IN ($in_kamar)";
    }

    if (!empty($where_parts)) {
        $where_sql = implode(' OR ', $where_parts);
        $q_p = @mysqli_query($conn, "SELECT * FROM `$tabel_penyewa` WHERE ($where_sql) ORDER BY 1 DESC");
        if ($q_p) {
            while ($row = mysqli_fetch_assoc($q_p)) {
                $nominal = (int)($row['nominal_sewa'] ?? $row['harga_sewa'] ?? $row['nominal'] ?? 0);
                $total_pendapatan += $nominal;

                $nama_unit = $row['no_kamar'] ?? $row['nama_kamar'] ?? 'Kamar';
                $nama_pen  = $row['nama_penyewa'] ?? $row['nama'] ?? 'Penyewa';

                $nama_prop_label = '';
                if ($col_prop_p !== null && !empty($row[$col_prop_p])) {
                    $pid_row = (int)$row[$col_prop_p];
                    $nama_prop_label = $properti_nama_map[$pid_row] ?? '';
                } elseif ($col_kamar_p !== null && !empty($row[$col_kamar_p])) {
                    $kid_row = (int)$row[$col_kamar_p];
                    $pid_row = $kamar_to_prop[$kid_row] ?? 0;
                    $nama_prop_label = $properti_nama_map[$pid_row] ?? '';
                }

                $desc = "Pembayaran Sewa Kos - Unit {$nama_unit} ({$nama_pen})";
                if ($nama_prop_label !== '') {
                    $desc .= " • {$nama_prop_label}";
                }

                $daftar_laporan[] = [
                    'id'        => (int)($row['id_penyewa'] ?? $row['id'] ?? 0),
                    'deskripsi' => $desc,
                    'tanggal'   => $row['created_at'] ?? $row['tanggal_masuk'] ?? date('Y-m-d'),
                    'nominal'   => $nominal,
                    'status'    => $row['status_escrow'] ?? $row['status_bayar'] ?? 'Escrow Hold (Aman)',
                ];
            }
        }
    }
}
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
                    <a href="kelola_penyewa.php" class="flex items-center gap-3 px-3.5 py-2.5 rounded-xl text-slate-600 hover:bg-slate-50 hover:text-blue-600 font-semibold text-sm transition">
                        <i class="fa-solid fa-users w-4 text-center text-slate-400"></i>
                        <span>Daftar &amp; Awasi Penyewa</span>
                    </a>
                    <a href="admin_keluhan.php" class="flex items-center gap-3 px-3.5 py-2.5 rounded-xl text-slate-600 hover:bg-slate-50 hover:text-blue-600 font-semibold text-sm transition">
                        <i class="fa-solid fa-headset w-4 text-center text-slate-400"></i>
                        <span>Kelola Keluhan &amp; Komplain</span>
                    </a>
                    <a href="admin_laporan.php" class="flex items-center gap-3 px-3.5 py-2.5 rounded-xl bg-blue-600 text-white font-semibold text-sm shadow-sm shadow-blue-200 transition">
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
                <p class="text-[11px] text-slate-400">Pantau pencairan dana sewa, status escrow, dan riwayat transaksi bulanan milik properti Anda.</p>
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

            <?php if (empty($properti_mitra_ids)): ?>
            <div class="bg-amber-50/80 border border-amber-200/80 rounded-3xl p-6 flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                <div class="flex items-start gap-3">
                    <div class="w-10 h-10 rounded-2xl bg-amber-100 text-amber-700 flex items-center justify-center shrink-0">
                        <i class="fa-solid fa-building-circle-exclamation"></i>
                    </div>
                    <div>
                        <h3 class="text-sm font-extrabold text-amber-950">Anda Belum Memiliki Properti Kos</h3>
                        <p class="text-xs text-amber-800 mt-0.5">Laporan penghasilan dan transaksi penyewa hanya akan muncul setelah Anda memiliki properti kos dan data sewa yang masuk.</p>
                    </div>
                </div>
                <a href="tambah_kamar.php" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2.5 rounded-xl text-xs font-extrabold shrink-0 inline-flex items-center gap-1.5">
                    <i class="fa-solid fa-plus"></i> Tambah Properti / Kamar
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
                    <p class="text-[11px] text-indigo-600 font-semibold"><i class="fa-solid fa-lock"></i> Diproteksi sistem Escrow KosLink</p>
                </div>

                <div class="bg-white border border-slate-200/80 rounded-3xl p-6 shadow-2xs space-y-2">
                    <div class="flex items-center justify-between text-slate-400">
                        <span class="text-xs font-bold uppercase">Total Transaksi Aktif</span>
                        <div class="w-9 h-9 rounded-xl bg-emerald-50 text-emerald-600 flex items-center justify-center">
                            <i class="fa-solid fa-receipt"></i>
                        </div>
                    </div>
                    <p class="text-2xl font-extrabold text-slate-900"><?= count($daftar_laporan) ?> Transaksi</p>
                    <p class="text-[11px] text-emerald-600 font-semibold"><i class="fa-solid fa-circle-check"></i> Difilter khusus properti milik Anda</p>
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
                            <p class="text-xs text-slate-500 mt-0.5">Menampilkan riwayat sewa yang terkait dengan properti/kamar milik Anda.</p>
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
                                        <?= empty($properti_mitra_ids)
                                            ? 'Belum ada properti kos terdaftar sehingga belum ada laporan transaksi.'
                                            : 'Belum ada riwayat transaksi atau penyewa yang memesan kamar pada properti Anda.' ?>
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