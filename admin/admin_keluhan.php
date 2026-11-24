<?php
require '../koneksi.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Proteksi role Admin
if (!isset($_SESSION['role']) || strtolower(trim((string)$_SESSION['role'])) !== 'admin') {
    header("Location: ../auth/login.php");
    exit;
}

mysqli_report(MYSQLI_REPORT_OFF);

$id_mitra   = (int)($_SESSION['id_mitra'] ?? $_SESSION['id_user'] ?? $_SESSION['id'] ?? 0);
$nama_mitra = $_SESSION['nama'] ?? 'Admin KosLink';

function resolveExistingTable($conn, array $candidates) {
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

function getTableColumns($conn, $table) {
    $cols = [];
    $q = @mysqli_query($conn, "SHOW COLUMNS FROM `$table`");
    if ($q) {
        while ($r = mysqli_fetch_assoc($q)) {
            $cols[] = $r['Field'];
        }
    }
    return $cols;
}

$tbl_keluhan  = resolveExistingTable($conn, ['keluhan', 'Keluhan', 'komplain', 'Komplain']);
$cols_keluhan = getTableColumns($conn, $tbl_keluhan);

$pk_keluhan = 'id';
foreach (['id', 'id_keluhan', 'id_komplain'] as $cand_pk) {
    if (in_array($cand_pk, $cols_keluhan, true)) {
        $pk_keluhan = $cand_pk;
        break;
    }
}

$col_status = 'status';
foreach (['status', 'status_keluhan', 'status_komplain'] as $cand_st) {
    if (in_array($cand_st, $cols_keluhan, true)) {
        $col_status = $cand_st;
        break;
    }
}

$col_judul = in_array('judul', $cols_keluhan, true) ? 'judul' : (in_array('judul_keluhan', $cols_keluhan, true) ? 'judul_keluhan' : 'judul');
$col_isi   = in_array('deskripsi', $cols_keluhan, true) ? 'deskripsi' : (in_array('isi_keluhan', $cols_keluhan, true) ? 'isi_keluhan' : 'deskripsi');

// Normalisasi nilai status agar sesuai ENUM ('Pending', 'Proses', 'Selesai')
function normalizeStatusKeluhan(string $raw): string {
    $val = strtolower(trim($raw));
    if ($val === 'proses' || $val === 'diproses') {
        return 'Proses';
    }
    if ($val === 'selesai') {
        return 'Selesai';
    }
    return 'Pending';
}

// Proses Update Status Keluhan
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_status'])) {
    $id_K_target = (int)($_POST['id_keluhan'] ?? 0);
    $raw_status  = (string)($_POST['status_keluhan'] ?? $_POST['status'] ?? 'Pending');
    $status_baru = normalizeStatusKeluhan($raw_status);

    if ($id_K_target > 0) {
        $stmt = mysqli_prepare($conn, "UPDATE `$tbl_keluhan` SET `$col_status` = ? WHERE `$pk_keluhan` = ?");
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, 'si', $status_baru, $id_K_target);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
        }
    }

    header("Location: admin_keluhan.php");
    exit;
}

// Ambil data keluhan dari database
$daftar_keluhan = [];
if (!empty($cols_keluhan)) {
    $q_keluhan = @mysqli_query($conn, "SELECT * FROM `$tbl_keluhan` ORDER BY `$pk_keluhan` DESC");
    if ($q_keluhan) {
        while ($row = mysqli_fetch_assoc($q_keluhan)) {
            $daftar_keluhan[] = $row;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id" class="scroll-smooth">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kelola Keluhan &amp; Komplain - KosLink Admin</title>
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
                    <p class="text-[10px] font-bold text-slate-400 tracking-widest uppercase mt-1">PORTAL ADMIN</p>
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
                    <a href="admin_keluhan.php" class="flex items-center justify-between px-3.5 py-2.5 rounded-xl bg-blue-600 text-white font-semibold text-sm shadow-sm shadow-blue-200 transition">
                        <div class="flex items-center gap-3">
                            <i class="fa-solid fa-headset w-4 text-center"></i>
                            <span>Kelola Keluhan &amp; Komplain</span>
                        </div>
                    </a>
                    <a href="admin_laporan.php" class="flex items-center gap-3 px-3.5 py-2.5 rounded-xl text-slate-600 hover:bg-slate-50 hover:text-blue-600 font-semibold text-sm transition">
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
        <header class="bg-white border-b border-slate-200/80 px-6 lg:px-8 py-3.5 flex items-center justify-between gap-4 sticky top-0 z-30">
            <div>
                <h2 class="text-sm font-extrabold text-slate-900">Pusat Keluhan &amp; Komplain Penyewa</h2>
                <p class="text-[11px] text-slate-400">Pantau tiket perbaikan fasilitas dan laporan kendala dari penghuni kos.</p>
            </div>
            <div class="flex items-center gap-3">
                <div class="text-right hidden sm:block">
                    <p class="text-xs font-bold text-slate-900 leading-tight"><?= htmlspecialchars($nama_mitra) ?></p>
                    <p class="text-[11px] text-slate-400 font-medium">Admin Terdaftar</p>
                </div>
            </div>
        </header>

        <!-- ISI HALAMAN -->
        <main class="p-6 lg:p-8 max-w-[1200px] w-full mx-auto space-y-6">

            <div class="bg-white border border-slate-200/80 rounded-3xl p-6 shadow-2xs space-y-6">
                <div class="flex items-center justify-between pb-4 border-b border-slate-100">
                    <div class="flex items-center gap-3">
                        <div class="w-9 h-9 rounded-xl bg-blue-50 text-blue-600 flex items-center justify-center text-sm">
                            <i class="fa-solid fa-headset"></i>
                        </div>
                        <div>
                            <h3 class="text-base font-extrabold text-slate-900">Daftar Tiket Keluhan Masuk</h3>
                            <p class="text-xs text-slate-500 mt-0.5">Segera tanggapi komplain untuk menjaga kepuasan penyewa kos Anda.</p>
                        </div>
                    </div>
                </div>

                <!-- TABEL KELUHAN -->
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-xs">
                        <thead>
                            <tr class="bg-slate-50 text-slate-400 font-bold uppercase tracking-wider border-b border-slate-200">
                                <th class="py-3 px-4">No. Kamar &amp; Penyewa</th>
                                <th class="py-3 px-4">Judul / Topik Keluhan</th>
                                <th class="py-3 px-4">Waktu Laporan</th>
                                <th class="py-3 px-4">Status</th>
                                <th class="py-3 px-4 text-center">Aksi / Tindakan</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 font-medium text-slate-700">
                            <?php if (count($daftar_keluhan) > 0): ?>
                                <?php foreach ($daftar_keluhan as $kel): ?>
                                <?php
                                    $id_row      = (int)($kel[$pk_keluhan] ?? $kel['id'] ?? 0);
                                    $st_selected = normalizeStatusKeluhan((string)($kel[$col_status] ?? 'Pending'));
                                    $badge_bg    = 'bg-amber-50 text-amber-700 border-amber-200';
                                    if ($st_selected === 'Selesai') {
                                        $badge_bg = 'bg-emerald-50 text-emerald-700 border-emerald-200';
                                    } elseif ($st_selected === 'Proses') {
                                        $badge_bg = 'bg-blue-50 text-blue-700 border-blue-200';
                                    }
                                ?>
                                <tr>
                                    <td class="py-3.5 px-4">
                                        <p class="font-extrabold text-slate-900"><?= htmlspecialchars($kel['nama_penyewa'] ?? 'Penyewa') ?></p>
                                        <p class="text-[10px] text-slate-400">Kamar No. <?= htmlspecialchars($kel['no_kamar'] ?? '01') ?></p>
                                    </td>
                                    <td class="py-3.5 px-4 font-bold text-slate-800">
                                        <?= htmlspecialchars($kel[$col_judul] ?? $kel['judul'] ?? 'Kendala Fasilitas') ?>
                                        <p class="text-[11px] font-normal text-slate-500 mt-0.5"><?= htmlspecialchars($kel[$col_isi] ?? $kel['deskripsi'] ?? '-') ?></p>
                                    </td>
                                    <td class="py-3.5 px-4 text-slate-500">
                                        <?= htmlspecialchars($kel['created_at'] ?? date('Y-m-d H:i')) ?>
                                    </td>
                                    <td class="py-3.5 px-4">
                                        <span class="px-2.5 py-1 rounded-full text-[10px] font-extrabold border <?= $badge_bg ?>">
                                            <?= htmlspecialchars($st_selected) ?>
                                        </span>
                                    </td>
                                    <td class="py-3.5 px-4 text-center">
                                        <form method="POST" action="" class="inline-flex items-center gap-1.5">
                                            <input type="hidden" name="id_keluhan" value="<?= $id_row ?>">
                                            <input type="hidden" name="update_status" value="1">
                                            <select name="status_keluhan" onchange="this.form.submit()" class="bg-slate-50 border border-slate-200 rounded-lg px-2 py-1 text-[11px] font-bold text-slate-700 cursor-pointer">
                                                <option value="Pending" <?= $st_selected === 'Pending' ? 'selected' : '' ?>>Pending</option>
                                                <option value="Proses" <?= $st_selected === 'Proses' ? 'selected' : '' ?>>Proses</option>
                                                <option value="Selesai" <?= $st_selected === 'Selesai' ? 'selected' : '' ?>>Selesai</option>
                                            </select>
                                        </form>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="5" class="py-8 text-center text-slate-400 font-semibold">
                                        Belum ada tiket keluhan atau komplain dari penyewa saat ini.
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