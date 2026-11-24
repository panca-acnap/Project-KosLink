<?php
require '../koneksi.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Proteksi role Mitra
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'mitra') {
    header("Location: ../auth/login.php");
    exit;
}

$id_mitra   = (int)($_SESSION['id_mitra'] ?? $_SESSION['id_user'] ?? $_SESSION['id'] ?? 0);
$nama_mitra = $_SESSION['nama'] ?? $_SESSION['nama_lengkap'] ?? 'Mitra KosLink';

function getTableColumns($conn, $table) {
    $cols = [];
    $q = @mysqli_query($conn, "SHOW COLUMNS FROM `$table`");
    if (!$q) {
        $q = @mysqli_query($conn, "SHOW COLUMNS FROM `" . strtolower($table) . "`");
    }
    if ($q) {
        while ($r = mysqli_fetch_assoc($q)) {
            $cols[] = $r['Field'];
        }
    }
    return $cols;
}

function resolveTable($conn, array $candidates) {
    foreach ($candidates as $tbl) {
        $esc = mysqli_real_escape_string($conn, $tbl);
        $q = @mysqli_query($conn, "SHOW TABLES LIKE '$esc'");
        if ($q && mysqli_num_rows($q) > 0) {
            $row = mysqli_fetch_row($q);
            return $row[0] ?? $tbl;
        }
    }
    return $candidates[0];
}

$tabel_penyewa = resolveTable($conn, ['penyewa', 'Penyewa']);
$tabel_prop    = resolveTable($conn, ['Properti_Kos', 'properti_kos', 'Properti', 'properti']);
$tabel_kamar   = resolveTable($conn, ['Kamar', 'kamar']);

$cols_prop     = getTableColumns($conn, $tabel_prop);
$pk_prop       = in_array('id_properti', $cols_prop, true) ? 'id_properti' : (in_array('id_kos', $cols_prop, true) ? 'id_kos' : 'id');
$col_nama_kos  = in_array('nama_kos', $cols_prop, true) ? 'nama_kos' : (in_array('nama_properti', $cols_prop, true) ? 'nama_properti' : 'nama');
$fk_mitra_prop = in_array('id_mitra', $cols_prop, true) ? 'id_mitra' : (in_array('id_pengguna', $cols_prop, true) ? 'id_pengguna' : '');

// Ambil daftar properti milik mitra yang sedang login
$daftar_properti = [];
if ($id_mitra > 0 && $fk_mitra_prop !== '') {
    $q_prop_list = @mysqli_query($conn, "SELECT * FROM `$tabel_prop` WHERE `$fk_mitra_prop` = $id_mitra ORDER BY `$pk_prop` ASC");
    if ($q_prop_list) {
        while ($p = mysqli_fetch_assoc($q_prop_list)) {
            $daftar_properti[] = $p;
        }
    }
}

$allowed_prop_ids = array_map(fn($p) => (int)$p[$pk_prop], $daftar_properti);

$req_prop = isset($_REQUEST['properti']) ? (int)$_REQUEST['properti'] : 0;
if ($req_prop > 0 && in_array($req_prop, $allowed_prop_ids, true)) {
    $selected_properti_id = $req_prop;
} else {
    $selected_properti_id = !empty($allowed_prop_ids) ? $allowed_prop_ids[0] : 0;
}

// Ambil seluruh tipe kamar & hitung sisa unit kosong pada properti aktif
$cols_kamar    = getTableColumns($conn, $tabel_kamar);
$pk_kamar      = in_array('id_kamar', $cols_kamar, true) ? 'id_kamar' : 'id';
$fk_prop_kamar = in_array('id_properti', $cols_kamar, true) ? 'id_properti' : (in_array('id_kos', $cols_kamar, true) ? 'id_kos' : '');
$col_sisa_k    = in_array('sisa_kamar', $cols_kamar, true) ? 'sisa_kamar' : (in_array('jumlah_unit', $cols_kamar, true) ? 'jumlah_unit' : '');

$kamar_ids_prop  = [];
$sisa_unit_kosong = 0;
$jml_tipe_kamar  = 0;

if ($selected_properti_id > 0 && !empty($cols_kamar) && $fk_prop_kamar !== '') {
    $q_k = @mysqli_query($conn, "SELECT * FROM `$tabel_kamar` WHERE `$fk_prop_kamar` = $selected_properti_id");
    if ($q_k) {
        while ($rk = mysqli_fetch_assoc($q_k)) {
            $jml_tipe_kamar++;
            $kamar_ids_prop[] = (int)($rk[$pk_kamar] ?? 0);
            if ($col_sisa_k !== '') {
                $sisa_unit_kosong += max(0, (int)($rk[$col_sisa_k] ?? 0));
            }
        }
    }
}
$kamar_ids_prop = array_filter($kamar_ids_prop, fn($v) => $v > 0);

// Ambil statistik & daftar penyewa aktif secara sinkron (id_properti ATAU id_kamar milik properti)
$total_penghuni    = 0;
$pendapatan_bulan  = 0;
$list_penyewa_rows = [];

$cols_penyewa = getTableColumns($conn, $tabel_penyewa);
if ($selected_properti_id > 0 && !empty($cols_penyewa)) {
    $pk_penyewa       = in_array('id_penyewa', $cols_penyewa, true) ? 'id_penyewa' : 'id';
    $col_status       = in_array('status_hunian', $cols_penyewa, true) ? 'status_hunian' : (in_array('status', $cols_penyewa, true) ? 'status' : '');
    $col_bayar        = in_array('status_bayar', $cols_penyewa, true) ? 'status_bayar' : '';
    $col_harga        = in_array('nominal_sewa', $cols_penyewa, true) ? 'nominal_sewa' : (in_array('harga_sewa', $cols_penyewa, true) ? 'harga_sewa' : (in_array('nominal', $cols_penyewa) ? 'nominal' : ''));
    $fk_prop_penyewa  = in_array('id_properti', $cols_penyewa, true) ? 'id_properti' : (in_array('id_kos', $cols_penyewa, true) ? 'id_kos' : '');
    $fk_kamar_penyewa = in_array('id_kamar', $cols_penyewa, true) ? 'id_kamar' : '';

    $conds = [];
    if ($fk_prop_penyewa !== '') {
        $conds[] = "`$fk_prop_penyewa` = $selected_properti_id";
    }
    if ($fk_kamar_penyewa !== '' && !empty($kamar_ids_prop)) {
        $in_km = implode(',', $kamar_ids_prop);
        $conds[] = "`$fk_kamar_penyewa` IN ($in_km)";
    }

    if (!empty($conds)) {
        $where_p = '(' . implode(' OR ', $conds) . ')';

        // Sinkronkan status_hunian = 'Aktif' jika status_bayar sudah 'Lunas' tetapi status_hunian masih 'Pending'
        if ($col_status !== '' && $col_bayar !== '') {
            @mysqli_query(
                $conn,
                "UPDATE `$tabel_penyewa` 
                 SET `$col_status` = 'Aktif' 
                 WHERE $where_p 
                   AND LOWER(TRIM(COALESCE(`$col_bayar`, ''))) IN ('lunas', 'paid', 'terbayar')
                   AND LOWER(TRIM(COALESCE(`$col_status`, ''))) IN ('', 'pending', 'menunggu')"
            );
        }

        $q_all_p = @mysqli_query($conn, "SELECT * FROM `$tabel_penyewa` WHERE $where_p ORDER BY `$pk_penyewa` DESC");
        if ($q_all_p) {
            while ($row = mysqli_fetch_assoc($q_all_p)) {
                $st_h = $col_status !== '' ? strtolower(trim((string)($row[$col_status] ?? ''))) : 'aktif';
                $st_b = $col_bayar !== '' ? strtolower(trim((string)($row[$col_bayar] ?? ''))) : '';

                $is_keluar = in_array($st_h, ['selesai', 'keluar', 'check-out', 'checkout', 'batal', 'dibatalkan', 'nonaktif'], true);
                $is_aktif  = !$is_keluar && (
                    in_array($st_h, ['aktif', 'check-in', 'checkin', 'menghuni', 'ditempati'], true) ||
                    in_array($st_b, ['lunas', 'paid', 'terbayar', 'berhasil'], true)
                );

                if ($is_aktif) {
                    $total_penghuni++;
                    if ($col_harga !== '') {
                        $pendapatan_bulan += (int)($row[$col_harga] ?? 0);
                    }
                    if (count($list_penyewa_rows) < 5) {
                        $list_penyewa_rows[] = $row;
                    }
                }
            }
        }
    }
}

// Total unit kamar keseluruhan = Sisa unit kosong + Penghuni aktif saat ini
$total_kamar = $sisa_unit_kosong + $total_penghuni;
?>
<!DOCTYPE html>
<html lang="id" class="scroll-smooth">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard Overview - KosLink Mitra</title>
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
                    <a href="dashboard.php" class="flex items-center gap-3 px-3.5 py-2.5 rounded-xl bg-blue-600 text-white font-semibold text-sm shadow-sm shadow-blue-200 transition">
                        <i class="fa-solid fa-table-cells-large w-4 text-center"></i>
                        <span>Dashboard Overview</span>
                    </a>
                    <a href="kelola_kos.php" class="flex items-center gap-3 px-3.5 py-2.5 rounded-xl text-slate-600 hover:bg-slate-50 hover:text-blue-600 font-semibold text-sm transition">
                        <i class="fa-solid fa-door-open w-4 text-center text-slate-400"></i>
                        <span>Kelola Kamar</span>
                    </a>
                    <a href="penyewa.php" class="flex items-center gap-3 px-3.5 py-2.5 rounded-xl text-slate-600 hover:bg-slate-50 hover:text-blue-600 font-semibold text-sm transition">
                        <i class="fa-solid fa-users w-4 text-center text-slate-400"></i>
                        <span>Daftar &amp; Awasi Penyewa</span>
                    </a>
                    <a href="keluhan.php" class="flex items-center justify-between px-3.5 py-2.5 rounded-xl text-slate-600 hover:bg-slate-50 hover:text-blue-600 font-semibold text-sm transition">
                        <div class="flex items-center gap-3">
                            <i class="fa-solid fa-headset w-4 text-center text-slate-400"></i>
                            <span>Kelola Keluhan &amp; Komplain</span>
                        </div>
                    </a>
                    <a href="laporan.php" class="flex items-center gap-3 px-3.5 py-2.5 rounded-xl text-slate-600 hover:bg-slate-50 hover:text-blue-600 font-semibold text-sm transition">
                        <i class="fa-solid fa-chart-line w-4 text-center text-slate-400"></i>
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
        <header class="bg-white border-b border-slate-200/80 px-6 lg:px-8 py-3.5 flex flex-wrap items-center justify-between gap-4 sticky top-0 z-30">
            <div class="flex items-center gap-3 flex-wrap">
                <form method="GET" action="" class="flex items-center bg-slate-50 border border-slate-200 rounded-xl px-3 py-1.5" id="form-pilih-properti">
                    <i class="fa-solid fa-house-chimney text-blue-600 text-xs mr-2.5"></i>
                    <div class="mr-2">
                        <p class="text-[9px] font-bold text-slate-400 uppercase leading-none">PROPERTI AKTIF</p>
                        <select name="properti" onchange="document.getElementById('form-pilih-properti').submit()" class="bg-transparent text-xs font-bold text-slate-800 focus:outline-none cursor-pointer pr-2">
                            <?php if (count($daftar_properti) > 0): ?>
                                <?php foreach ($daftar_properti as $prop): ?>
                                    <option value="<?= (int)$prop[$pk_prop] ?>" <?= (int)$prop[$pk_prop] === $selected_properti_id ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($prop[$col_nama_kos] ?? 'Properti') ?>
                                    </option>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <option value="0">Belum Ada Properti</option>
                            <?php endif; ?>
                        </select>
                    </div>
                </form>

                <span class="inline-flex items-center gap-1.5 bg-emerald-50 text-emerald-700 border border-emerald-200/80 text-[11px] font-bold px-3 py-1.5 rounded-full">
                    <span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span> Aktif Terverifikasi
                </span>
            </div>

            <div class="flex items-center gap-4">
                <button type="button" class="relative w-9 h-9 rounded-full bg-slate-50 border border-slate-200/80 flex items-center justify-center text-slate-600 hover:bg-slate-100 transition">
                    <i class="fa-regular fa-bell text-sm"></i>
                </button>
                <div class="h-6 w-px bg-slate-200"></div>
                <div class="flex items-center gap-3">
                    <div class="text-right hidden sm:block">
                        <p class="text-xs font-bold text-slate-900 leading-tight"><?= htmlspecialchars($nama_mitra) ?></p>
                        <p class="text-[11px] text-slate-400 font-medium">Mitra Terdaftar</p>
                    </div>
                    <div class="w-9 h-9 rounded-full bg-blue-100 text-blue-600 border border-blue-200 flex items-center justify-center font-bold text-sm">
                        <i class="fa-regular fa-user"></i>
                    </div>
                </div>
            </div>
        </header>

        <!-- ISI DASHBOARD -->
        <main class="p-6 lg:p-8 max-w-[1200px] w-full mx-auto space-y-6">
            
            <div class="flex flex-col md:flex-row md:items-center justify-between gap-4">
                <div>
                    <h2 class="text-2xl font-extrabold text-slate-900 tracking-tight">Dashboard Properti Mitra 🏢</h2>
                    <p class="text-xs text-slate-500 mt-1">
                        Kelola ketersediaan kamar, pantau penghuni aktif, dan pencairan dana escrow secara real-time.
                    </p>
                </div>
                <div>
                    <a href="tambah_kamar.php" class="bg-blue-600 hover:bg-blue-700 text-white text-xs font-extrabold px-4 py-2.5 rounded-xl transition shadow-sm inline-flex items-center gap-2">
                        <i class="fa-solid fa-plus"></i> Tambah Kamar Kos
                    </a>
                </div>
            </div>

            <!-- STATISTIK KARTU -->
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-5">
                <div class="bg-white border border-slate-200/80 rounded-3xl p-6 shadow-2xs space-y-2">
                    <div class="flex items-center justify-between text-slate-400">
                        <span class="text-xs font-bold uppercase">Total Unit Kamar</span>
                        <div class="w-9 h-9 rounded-xl bg-blue-50 text-blue-600 flex items-center justify-center">
                            <i class="fa-solid fa-door-open"></i>
                        </div>
                    </div>
                    <p class="text-2xl font-extrabold text-slate-900"><?= $total_kamar ?> Kamar</p>
                    <p class="text-[11px] text-emerald-600 font-semibold">
                        <i class="fa-solid fa-circle-check"></i> <?= $sisa_unit_kosong ?> Unit Tersedia &bull; <?= $jml_tipe_kamar ?> Tipe Kamar
                    </p>
                </div>

                <div class="bg-white border border-slate-200/80 rounded-3xl p-6 shadow-2xs space-y-2">
                    <div class="flex items-center justify-between text-slate-400">
                        <span class="text-xs font-bold uppercase">Penghuni Aktif</span>
                        <div class="w-9 h-9 rounded-xl bg-emerald-50 text-emerald-600 flex items-center justify-center">
                            <i class="fa-solid fa-users"></i>
                        </div>
                    </div>
                    <p class="text-2xl font-extrabold text-slate-900"><?= $total_penghuni ?> Orang</p>
                    <p class="text-[11px] text-slate-500">Masa kontrak sewa berjalan (<?= $total_penghuni ?> / <?= $total_kamar ?> unit terisi)</p>
                </div>

                <div class="bg-white border border-slate-200/80 rounded-3xl p-6 shadow-2xs space-y-2">
                    <div class="flex items-center justify-between text-slate-400">
                        <span class="text-xs font-bold uppercase">Pendapatan Bulan Ini</span>
                        <div class="w-9 h-9 rounded-xl bg-indigo-50 text-indigo-600 flex items-center justify-center">
                            <i class="fa-solid fa-wallet"></i>
                        </div>
                    </div>
                    <p class="text-2xl font-extrabold text-slate-900">Rp <?= number_format($pendapatan_bulan, 0, ',', '.') ?></p>
                    <p class="text-[11px] text-indigo-600 font-semibold"><i class="fa-solid fa-lock"></i> Escrow aman di KosLink</p>
                </div>
            </div>

            <!-- TABEL PENYEWA TERBARU -->
            <div class="bg-white border border-slate-200/80 rounded-3xl p-6 shadow-2xs space-y-4">
                <div class="flex items-center justify-between border-b border-slate-100 pb-4">
                    <h2 class="text-base font-extrabold text-slate-900">Daftar Penyewa Aktif Properti Anda</h2>
                    <a href="penyewa.php" class="text-xs font-bold text-blue-600 hover:underline">Lihat Semua &rarr;</a>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full text-left border-collapse">
                        <thead>
                            <tr class="bg-slate-50 border-b border-slate-200/80 text-[10px] font-extrabold text-slate-400 uppercase tracking-wider">
                                <th class="py-3 px-4">Kamar</th>
                                <th class="py-3 px-4">Nama Penyewa</th>
                                <th class="py-3 px-4">No. WhatsApp</th>
                                <th class="py-3 px-4">Jatuh Tempo</th>
                                <th class="py-3 px-4">Nominal Sewa</th>
                                <th class="py-3 px-4">Status</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 text-xs">
                            <?php if ($selected_properti_id <= 0): ?>
                                <tr><td colspan="6" class="py-6 text-center text-slate-400">Anda belum memiliki properti kos terdaftar. Tambahkan properti terlebih dahulu untuk melihat data penyewa.</td></tr>
                            <?php elseif (!empty($list_penyewa_rows)): ?>
                                <?php foreach ($list_penyewa_rows as $row): ?>
                                    <?php
                                    $kamar_val   = $row['no_kamar'] ?? $row['nama_kamar'] ?? $row['kamar'] ?? 'Unit Kamar';
                                    $nama_val    = $row['nama_penyewa'] ?? $row['nama'] ?? 'Penyewa';
                                    $hp_raw      = $row['no_hp'] ?? $row['whatsapp'] ?? $row['telepon'] ?? '';
                                    $hp_clean    = preg_replace('/[^0-9]/', '', $hp_raw);
                                    $wa_num      = preg_replace('/^0/', '62', $hp_clean);
                                    $tempo_val   = $row['jatuh_tempo'] ?? $row['tanggal_jatuh_tempo'] ?? '-';
                                    $nominal_val = (int)($row['nominal_sewa'] ?? $row['harga_sewa'] ?? $row['nominal'] ?? 0);
                                    $status_val  = $row['status_hunian'] ?? $row['status'] ?? 'Aktif';
                                    ?>
                                    <tr class="hover:bg-slate-50 transition">
                                        <td class="py-3.5 px-4 font-extrabold text-slate-900"><?= htmlspecialchars($kamar_val) ?></td>
                                        <td class="py-3.5 px-4 font-bold text-slate-800"><?= htmlspecialchars($nama_val) ?></td>
                                        <td class="py-3.5 px-4 text-slate-600">
                                            <?php if ($wa_num !== ''): ?>
                                                <a href="https://wa.me/<?= htmlspecialchars($wa_num) ?>" target="_blank" class="text-blue-600 font-bold hover:underline">
                                                    <i class="fa-brands fa-whatsapp"></i> <?= htmlspecialchars($hp_raw) ?>
                                                </a>
                                            <?php else: ?>
                                                -
                                            <?php endif; ?>
                                        </td>
                                        <td class="py-3.5 px-4 text-slate-600"><?= htmlspecialchars($tempo_val) ?></td>
                                        <td class="py-3.5 px-4 font-extrabold text-slate-900">Rp <?= number_format($nominal_val, 0, ',', '.') ?></td>
                                        <td class="py-3.5 px-4"><span class="bg-emerald-50 text-emerald-700 border border-emerald-200 font-bold px-2.5 py-1 rounded-full text-[10px]"><?= htmlspecialchars($status_val) ?></span></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr><td colspan="6" class="py-6 text-center text-slate-400">Belum ada data penyewa pada properti ini.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        </main>
    </div>

</body>
</html>