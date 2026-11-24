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

$id_mitra   = (int)($_SESSION['id_mitra'] ?? $_SESSION['id_user'] ?? $_SESSION['id'] ?? 0);
$nama_mitra = $_SESSION['nama'] ?? 'Mitra KosLink';

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

$cols_keluhan = getTableColumns($conn, 'keluhan');
$pk_keluhan   = in_array('id_keluhan', $cols_keluhan, true) ? 'id_keluhan' : (in_array('id', $cols_keluhan, true) ? 'id' : 'id_komplain');

// Pastikan kolom catatan_mitra tersedia jika belum ada
if (!empty($cols_keluhan) && !in_array('catatan_mitra', $cols_keluhan, true)) {
    @mysqli_query($conn, "ALTER TABLE `keluhan` ADD COLUMN `catatan_mitra` TEXT DEFAULT NULL");
    $cols_keluhan[] = 'catatan_mitra';
}

// Proses Update Status & Catatan Tanggapan Mitra
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_status'])) {
    $id_k_target   = (int)($_POST['id_keluhan'] ?? 0);
    $status_baru   = mysqli_real_escape_string($conn, trim($_POST['status_keluhan'] ?? 'Menunggu Respon Mitra'));
    $catatan_mitra = mysqli_real_escape_string($conn, trim($_POST['catatan_mitra'] ?? ''));

    if ($id_k_target > 0) {
        $set_parts = [];
        if (in_array('status', $cols_keluhan, true)) {
            $set_parts[] = "`status` = '$status_baru'";
        }
        if (in_array('status_keluhan', $cols_keluhan, true)) {
            $set_parts[] = "`status_keluhan` = '$status_baru'";
        }
        if (isset($_POST['catatan_mitra']) && in_array('catatan_mitra', $cols_keluhan, true)) {
            $set_parts[] = "`catatan_mitra` = " . ($catatan_mitra !== '' ? "'$catatan_mitra'" : "NULL");
        }

        if (!empty($set_parts)) {
            @mysqli_query($conn, "UPDATE `keluhan` SET " . implode(', ', $set_parts) . " WHERE `$pk_keluhan` = $id_k_target");
        }
    }

    header("Location: keluhan.php?updated=1");
    exit;
}

// Ambil data keluhan dari database dengan aman
$daftar_keluhan = [];
$q_keluhan = @mysqli_query($conn, "SELECT * FROM `keluhan` ORDER BY `$pk_keluhan` DESC");
if ($q_keluhan) {
    while ($row = mysqli_fetch_assoc($q_keluhan)) {
        $daftar_keluhan[] = $row;
    }
}
?>
<!DOCTYPE html>
<html lang="id" class="scroll-smooth">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kelola Keluhan &amp; Komplain - KosLink Mitra</title>
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
                        <i class="fa-solid fa-door-open w-4 text-center text-slate-400"></i>
                        <span>Kelola Kamar</span>
                    </a>
                    <a href="penyewa.php" class="flex items-center gap-3 px-3.5 py-2.5 rounded-xl text-slate-600 hover:bg-slate-50 hover:text-blue-600 font-semibold text-sm transition">
                        <i class="fa-solid fa-users w-4 text-center text-slate-400"></i>
                        <span>Daftar &amp; Awasi Penyewa</span>
                    </a>
                    <a href="keluhan.php" class="flex items-center justify-between px-3.5 py-2.5 rounded-xl bg-blue-600 text-white font-semibold text-sm shadow-sm shadow-blue-200 transition">
                        <div class="flex items-center gap-3">
                            <i class="fa-solid fa-headset w-4 text-center"></i>
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
        <header class="bg-white border-b border-slate-200/80 px-6 lg:px-8 py-3.5 flex items-center justify-between gap-4 sticky top-0 z-30">
            <div>
                <h2 class="text-sm font-extrabold text-slate-900">Pusat Keluhan &amp; Komplain Penyewa</h2>
                <p class="text-[11px] text-slate-400">Pantau tiket perbaikan fasilitas dan laporan kendala dari penghuni kos.</p>
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

            <?php if (isset($_GET['updated'])): ?>
            <div class="bg-emerald-50 border border-emerald-200 text-emerald-800 px-5 py-3.5 rounded-2xl text-xs font-bold flex items-center gap-2.5">
                <i class="fa-solid fa-circle-check text-emerald-600 text-base"></i>
                <span>Status dan tanggapan keluhan berhasil diperbarui dan langsung terlihat oleh penyewa.</span>
            </div>
            <?php endif; ?>

            <div class="bg-white border border-slate-200/80 rounded-3xl p-6 shadow-2xs space-y-6">
                <div class="flex items-center justify-between pb-4 border-b border-slate-100">
                    <div class="flex items-center gap-3">
                        <div class="w-9 h-9 rounded-xl bg-blue-50 text-blue-600 flex items-center justify-center text-sm">
                            <i class="fa-solid fa-headset"></i>
                        </div>
                        <div>
                            <h3 class="text-base font-extrabold text-slate-900">Daftar Tiket Keluhan Masuk</h3>
                            <p class="text-xs text-slate-500 mt-0.5">Klik <strong>Lihat Detail</strong> untuk membaca rincian keluhan, foto bukti, jadwal kunjungan, dan mengirim respon.</p>
                        </div>
                    </div>
                    <span class="bg-slate-100 text-slate-700 text-xs font-extrabold px-3 py-1.5 rounded-xl">
                        Total: <?= count($daftar_keluhan) ?> Tiket
                    </span>
                </div>

                <!-- TABEL KELUHAN -->
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-xs">
                        <thead>
                            <tr class="bg-slate-50 text-slate-400 font-bold uppercase tracking-wider border-b border-slate-200">
                                <th class="py-3 px-4">No. Kamar &amp; Penyewa</th>
                                <th class="py-3 px-4">Judul &amp; Rincian Keluhan</th>
                                <th class="py-3 px-4">Urgensi &amp; Waktu</th>
                                <th class="py-3 px-4">Status</th>
                                <th class="py-3 px-4 text-center">Aksi / Tindakan</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 font-medium text-slate-700">
                            <?php if (count($daftar_keluhan) > 0): ?>
                                <?php foreach ($daftar_keluhan as $kel):
                                    $id_tiket   = (int)($kel[$pk_keluhan] ?? 0);
                                    $nama_pen   = $kel['nama_penyewa'] ?? 'Penyewa';
                                    $kamar_pen  = $kel['kamar'] ?? $kel['no_kamar'] ?? 'Kamar 01';
                                    $nama_kos_k = $kel['nama_kos'] ?? '';
                                    $kategori_k = $kel['kategori'] ?? 'Fasilitas Umum';
                                    $judul_k    = !empty($kel['judul']) ? $kel['judul'] : (!empty($kel['judul_keluhan']) ? $kel['judul_keluhan'] : 'Kendala Fasilitas');
                                    $isi_k      = !empty($kel['deskripsi']) ? $kel['deskripsi'] : (!empty($kel['isi_keluhan']) ? $kel['isi_keluhan'] : 'Tidak ada deskripsi tambahan.');
                                    $urgensi_k  = $kel['urgensi'] ?? 'Standar';
                                    $kunjungan_k= $kel['waktu_kunjungan'] ?? 'Fleksibel';
                                    $waktu_k    = $kel['created_at'] ?? date('Y-m-d H:i');
                                    $catatan_k  = $kel['catatan_mitra'] ?? '';
                                    $foto_raw   = trim((string)($kel['foto_bukti'] ?? ''));
                                    $foto_url   = '';
                                    if ($foto_raw !== '') {
                                        $foto_url = preg_match('#^https?://#i', $foto_raw) ? $foto_raw : '../' . ltrim($foto_raw, '/');
                                    }

                                    $st = !empty($kel['status']) ? $kel['status'] : ($kel['status_keluhan'] ?? 'Menunggu Respon Mitra');
                                    $badge_bg = 'bg-amber-50 text-amber-700 border-amber-200';
                                    if ($st === 'Selesai') {
                                        $badge_bg = 'bg-emerald-50 text-emerald-700 border-emerald-200';
                                    } elseif (stripos($st, 'Proses') !== false || stripos($st, 'Diproses') !== false) {
                                        $badge_bg = 'bg-blue-50 text-blue-700 border-blue-200';
                                    }

                                    $urgensi_badge = 'bg-slate-100 text-slate-600';
                                    if (stripos($urgensi_k, 'Darurat') !== false) {
                                        $urgensi_badge = 'bg-rose-50 text-rose-700 border border-rose-200';
                                    } elseif (stripos($urgensi_k, 'Sedang') !== false) {
                                        $urgensi_badge = 'bg-amber-50 text-amber-700 border border-amber-200';
                                    }

                                    $payload_modal = htmlspecialchars(json_encode([
                                        'id'        => $id_tiket,
                                        'kode'      => '#TKT-' . date('Ymd', strtotime($waktu_k)) . '-' . str_pad((string)$id_tiket, 3, '0', STR_PAD_LEFT),
                                        'penyewa'   => $nama_pen,
                                        'kamar'     => $kamar_pen,
                                        'kos'       => $nama_kos_k,
                                        'kategori'  => $kategori_k,
                                        'judul'     => $judul_k,
                                        'deskripsi' => $isi_k,
                                        'urgensi'   => $urgensi_k,
                                        'kunjungan' => $kunjungan_k,
                                        'waktu'     => date('d M Y, H:i', strtotime($waktu_k)) . ' WITA',
                                        'status'    => $st,
                                        'catatan'   => $catatan_k,
                                        'foto'      => $foto_url
                                    ], JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8');
                                ?>
                                <tr class="hover:bg-slate-50/70 transition">
                                    <td class="py-3.5 px-4 align-top">
                                        <p class="font-extrabold text-slate-900"><?= htmlspecialchars($nama_pen) ?></p>
                                        <p class="text-[11px] font-semibold text-blue-600 mt-0.5"><?= htmlspecialchars($kamar_pen) ?></p>
                                        <?php if ($nama_kos_k !== ''): ?>
                                            <p class="text-[10px] text-slate-400"><?= htmlspecialchars($nama_kos_k) ?></p>
                                        <?php endif; ?>
                                    </td>
                                    <td class="py-3.5 px-4 align-top max-w-sm">
                                        <div class="flex flex-wrap items-center gap-1.5 mb-1">
                                            <span class="bg-blue-50 text-blue-700 text-[10px] font-extrabold px-2 py-0.5 rounded-md">
                                                <?= htmlspecialchars($kategori_k) ?>
                                            </span>
                                            <?php if ($foto_url !== ''): ?>
                                                <span class="bg-slate-100 text-slate-600 text-[10px] font-bold px-2 py-0.5 rounded-md inline-flex items-center gap-1">
                                                    <i class="fa-regular fa-image"></i> Ada Foto
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                        <p class="font-extrabold text-slate-900"><?= htmlspecialchars($judul_k) ?></p>
                                        <p class="text-[11px] font-normal text-slate-500 mt-0.5 line-clamp-2">
                                            <?= htmlspecialchars($isi_k) ?>
                                        </p>
                                    </td>
                                    <td class="py-3.5 px-4 align-top">
                                        <span class="inline-block px-2 py-0.5 rounded-md text-[10px] font-extrabold <?= $urgensi_badge ?>">
                                            <?= htmlspecialchars($urgensi_k) ?>
                                        </span>
                                        <p class="text-[11px] text-slate-500 mt-1"><?= htmlspecialchars($waktu_k) ?></p>
                                        <p class="text-[10px] text-slate-400 mt-0.5"><i class="fa-regular fa-clock mr-1"></i><?= htmlspecialchars($kunjungan_k) ?></p>
                                    </td>
                                    <td class="py-3.5 px-4 align-top">
                                        <span class="inline-block px-2.5 py-1 rounded-full text-[10px] font-extrabold border <?= $badge_bg ?>">
                                            <?= htmlspecialchars($st) ?>
                                        </span>
                                    </td>
                                    <td class="py-3.5 px-4 align-top text-center">
                                        <div class="inline-flex flex-col sm:flex-row items-center gap-1.5">
                                            <button type="button"
                                                    onclick='bukaDetailKeluhan(<?= $payload_modal ?>)'
                                                    class="bg-blue-600 hover:bg-blue-700 text-white px-3 py-1.5 rounded-lg text-[11px] font-extrabold shadow-2xs inline-flex items-center gap-1.5 transition">
                                                <i class="fa-regular fa-eye"></i> Lihat Detail
                                            </button>
                                            <form method="POST" class="inline-flex items-center">
                                                <input type="hidden" name="id_keluhan" value="<?= $id_tiket ?>">
                                                <input type="hidden" name="update_status" value="1">
                                                <select name="status_keluhan" onchange="this.form.submit()" class="bg-slate-50 border border-slate-200 rounded-lg px-2 py-1.5 text-[11px] font-bold text-slate-700 cursor-pointer">
                                                    <option value="Menunggu Respon Mitra" <?= $st === 'Menunggu Respon Mitra' || $st === 'Pending' ? 'selected' : '' ?>>Menunggu Respon</option>
                                                    <option value="Diproses Teknisi" <?= $st === 'Diproses Teknisi' || $st === 'Diproses' ? 'selected' : '' ?>>Diproses Teknisi</option>
                                                    <option value="Selesai" <?= $st === 'Selesai' ? 'selected' : '' ?>>Selesai</option>
                                                </select>
                                            </form>
                                        </div>
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

    <!-- MODAL DETAIL KELUHAN & FORM TANGGAPAN MITRA -->
    <div id="modal-detail-keluhan" class="fixed inset-0 bg-slate-900/60 backdrop-blur-xs hidden items-center justify-center z-50 p-4 overflow-y-auto">
        <div class="bg-white rounded-3xl max-w-2xl w-full p-6 sm:p-7 shadow-2xl border border-slate-100 space-y-5 my-auto">
            <!-- Header Modal -->
            <div class="flex items-start justify-between gap-3 border-b border-slate-100 pb-4">
                <div>
                    <div class="flex items-center gap-2">
                        <span id="md-kode" class="font-mono text-[11px] font-extrabold bg-slate-100 text-slate-700 px-2.5 py-0.5 rounded-md">#TKT-000</span>
                        <span id="md-urgensi" class="text-[10px] font-extrabold bg-rose-50 text-rose-700 border border-rose-200 px-2.5 py-0.5 rounded-full">Darurat</span>
                    </div>
                    <h3 id="md-judul" class="text-lg font-extrabold text-slate-900 mt-1.5">Judul Keluhan</h3>
                    <p id="md-waktu" class="text-[11px] text-slate-400 mt-0.5">Dilaporkan pada -</p>
                </div>
                <button type="button" onclick="tutupDetailKeluhan()" class="w-8 h-8 rounded-full bg-slate-100 hover:bg-slate-200 text-slate-500 flex items-center justify-center text-xs transition">
                    <i class="fa-solid fa-xmark"></i>
                </button>
            </div>

            <!-- Informasi Penyewa & Jadwal -->
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 text-xs">
                <div class="p-3.5 rounded-2xl bg-slate-50 border border-slate-200/70">
                    <span class="text-[10px] font-bold text-slate-400 uppercase block">Pelapor &amp; Unit</span>
                    <p id="md-penyewa" class="font-extrabold text-slate-900 mt-0.5">-</p>
                    <p id="md-kamar" class="text-[11px] font-semibold text-blue-600">-</p>
                </div>
                <div class="p-3.5 rounded-2xl bg-slate-50 border border-slate-200/70">
                    <span class="text-[10px] font-bold text-slate-400 uppercase block">Kategori Kerusakan</span>
                    <p id="md-kategori" class="font-extrabold text-slate-900 mt-0.5">-</p>
                </div>
                <div class="p-3.5 rounded-2xl bg-slate-50 border border-slate-200/70">
                    <span class="text-[10px] font-bold text-slate-400 uppercase block">Jadwal Kunjungan</span>
                    <p id="md-kunjungan" class="font-extrabold text-slate-900 mt-0.5">-</p>
                </div>
            </div>

            <!-- Deskripsi Lengkap Keluhan -->
            <div class="space-y-1.5 text-xs">
                <span class="text-[11px] font-extrabold text-slate-700 uppercase tracking-wider block">Uraian Detail Kerusakan dari Penyewa</span>
                <div id="md-deskripsi" class="p-4 rounded-2xl bg-slate-50 border border-slate-200/80 text-slate-700 leading-relaxed whitespace-pre-line">
                    -
                </div>
            </div>

            <!-- Foto Bukti (Jika Penyewa Mengunggah Foto) -->
            <div id="md-foto-wrapper" class="hidden space-y-1.5 text-xs">
                <span class="text-[11px] font-extrabold text-slate-700 uppercase tracking-wider block">Foto Bukti Kerusakan</span>
                <div class="rounded-2xl overflow-hidden border border-slate-200 bg-slate-50 p-2">
                    <a id="md-foto-link" href="#" target="_blank" title="Klik untuk memperbesar foto">
                        <img id="md-foto-img" src="" alt="Bukti Keluhan" class="max-h-60 w-auto mx-auto rounded-xl object-contain">
                    </a>
                </div>
            </div>

            <!-- Form Update Status & Catatan Respon Mitra -->
            <form method="POST" class="border-t border-slate-100 pt-4 space-y-4 text-xs">
                <input type="hidden" name="id_keluhan" id="md-input-id" value="">
                <input type="hidden" name="update_status" value="1">

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div class="space-y-1.5">
                        <label class="font-extrabold text-slate-700 block">Perbarui Status Tiket</label>
                        <select name="status_keluhan" id="md-input-status" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-3.5 py-2.5 font-bold text-slate-800 focus:outline-none focus:ring-2 focus:ring-blue-500">
                            <option value="Menunggu Respon Mitra">Menunggu Respon Mitra</option>
                            <option value="Diproses Teknisi">Diproses Teknisi</option>
                            <option value="Selesai">Selesai</option>
                        </select>
                    </div>
                    <div class="space-y-1.5 sm:col-span-2">
                        <label class="font-extrabold text-slate-700 block">Catatan / Tanggapan untuk Penyewa</label>
                        <textarea name="catatan_mitra" id="md-input-catatan" rows="2" placeholder="Contoh: Teknisi AC akan datang hari ini pukul 15.00 WITA untuk pengecekan freon dan pembersihan." class="w-full bg-slate-50 border border-slate-200 rounded-xl px-3.5 py-2.5 font-medium text-slate-700 focus:outline-none focus:ring-2 focus:ring-blue-500"></textarea>
                    </div>
                </div>

                <div class="flex items-center justify-end gap-2.5 pt-1">
                    <button type="button" onclick="tutupDetailKeluhan()" class="px-4 py-2.5 rounded-xl bg-slate-100 hover:bg-slate-200 text-slate-700 font-bold transition">
                        Tutup
                    </button>
                    <button type="submit" class="px-5 py-2.5 rounded-xl bg-blue-600 hover:bg-blue-700 text-white font-extrabold shadow-sm transition inline-flex items-center gap-2">
                        <i class="fa-solid fa-paper-plane"></i> Simpan Status &amp; Respon
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function bukaDetailKeluhan(data) {
            document.getElementById('md-kode').innerText      = data.kode || '#TKT';
            document.getElementById('md-urgensi').innerText   = data.urgensi || 'Standar';
            document.getElementById('md-judul').innerText     = data.judul || 'Kendala Fasilitas';
            document.getElementById('md-waktu').innerText     = 'Dilaporkan pada ' + (data.waktu || '-');
            document.getElementById('md-penyewa').innerText   = data.penyewa || '-';
            document.getElementById('md-kamar').innerText     = (data.kamar || '-') + (data.kos ? ' • ' + data.kos : '');
            document.getElementById('md-kategori').innerText  = data.kategori || '-';
            document.getElementById('md-kunjungan').innerText = data.kunjungan || '-';
            document.getElementById('md-deskripsi').innerText = data.deskripsi || 'Tidak ada rincian tambahan.';

            document.getElementById('md-input-id').value      = data.id || '';
            document.getElementById('md-input-catatan').value = data.catatan || '';

            const selStatus = document.getElementById('md-input-status');
            if (data.status === 'Pending') {
                selStatus.value = 'Menunggu Respon Mitra';
            } else if (data.status === 'Diproses') {
                selStatus.value = 'Diproses Teknisi';
            } else {
                selStatus.value = data.status || 'Menunggu Respon Mitra';
            }

            const fotoWrap = document.getElementById('md-foto-wrapper');
            const fotoImg  = document.getElementById('md-foto-img');
            const fotoLink = document.getElementById('md-foto-link');
            if (data.foto) {
                fotoImg.src = data.foto;
                fotoLink.href = data.foto;
                fotoWrap.classList.remove('hidden');
            } else {
                fotoImg.src = '';
                fotoWrap.classList.add('hidden');
            }

            const modal = document.getElementById('modal-detail-keluhan');
            modal.classList.remove('hidden');
            modal.classList.add('flex');
        }

        function tutupDetailKeluhan() {
            const modal = document.getElementById('modal-detail-keluhan');
            modal.classList.add('hidden');
            modal.classList.remove('flex');
        }
    </script>

</body>
</html>